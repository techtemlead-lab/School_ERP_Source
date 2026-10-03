<?php
/**
 * admission_payment_callback.php
 * -----------------------------------------------------------
 * Payment Gateway (SSLCommerz/ShurjoPay/PayStation/EPS) সফল/ব্যর্থ/বাতিল হলে এই URL-এ ইউজারকে
 * (এবং IPN/webhook হলে সার্ভার-টু-সার্ভার) রিডাইরেক্ট/POST করে।
 * ক্লায়েন্ট থেকে আসা status কখনও সরাসরি বিশ্বাস করা হয় না — সফল দাবি করলে সেই গেটওয়ের নিজস্ব
 * Validation/Verification API কল করে amount/tran_id মিলিয়ে নিশ্চিত হওয়ার পরই admission_fee_claims
 * Approved মার্ক করা হয় এবং af_post_to_finance() দিয়ে Finance Module-এ Paid এন্ট্রি বসে।
 */

session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/admission_fee_helpers.php';
af_ensure_tables($pdo);

$provider = $_REQUEST['provider'] ?? '';
$status = $_REQUEST['status'] ?? '';
$tran_id = trim($_REQUEST['tran_id'] ?? '');
$val_id = trim($_REQUEST['val_id'] ?? '');

function acb_redirect_slip(PDO $pdo, ?array $claim, string $payment_status): void
{
    $token = '';
    if ($claim) {
        $s = $pdo->prepare("SELECT admission_slip_token FROM students WHERE id = ?");
        $s->execute([$claim['student_pk']]);
        $token = (string) $s->fetchColumn();
    }
    header('Location: admission_slip.php?token=' . urlencode($token) . '&payment=' . urlencode($payment_status));
    exit();
}

if ($tran_id === '') {
    header('Location: admission_form.php');
    exit();
}

$c_stmt = $pdo->prepare("SELECT * FROM admission_fee_claims WHERE trx_id = ? AND method = 'AutoGateway' LIMIT 1");
$c_stmt->execute([$tran_id]);
$claim = $c_stmt->fetch();

if (!$claim) {
    header('Location: admission_form.php');
    exit();
}

// ইতিমধ্যে Approved হয়ে থাকলে (IPN + browser redirect দুটোই একবার করে আসতে পারে) — দ্বিতীয়বার প্রসেস করা হচ্ছে না
if ($claim['status'] === 'Approved') {
    acb_redirect_slip($pdo, $claim, 'success');
}

if ($status === 'fail' || $status === 'cancel') {
    $pdo->prepare("UPDATE admission_fee_claims SET status = 'Failed' WHERE id = ?")->execute([$claim['id']]);
    acb_redirect_slip($pdo, $claim, 'failed');
}

$gateway = af_gateway_settings($pdo);
$verified = false;
$val_error = null;

function cb_curl_get(string $url, array $extra_headers = []): array
{
    if (!function_exists('curl_init')) { return [null, 'cURL extension নেই।']; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_HTTPHEADER => $extra_headers,
    ]);
    $response = curl_exec($ch);
    $err = $response === false ? curl_error($ch) : null;
    curl_close($ch);
    return [$response, $err];
}

function cb_curl_post(string $url, array $post_fields, array $extra_headers = [], bool $json = false): array
{
    if (!function_exists('curl_init')) { return [null, 'cURL extension নেই।']; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json ? json_encode($post_fields) : http_build_query($post_fields),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => array_merge($json ? ['Content-Type: application/json'] : [], $extra_headers),
    ]);
    $response = curl_exec($ch);
    $err = $response === false ? curl_error($ch) : null;
    curl_close($ch);
    return [$response, $err];
}

switch ($provider) {

    // ================= SSLCommerz =================
    case 'sslcommerz':
        $sandbox = !empty($gateway['sslcz_sandbox']);
        $validation_url = $sandbox
            ? 'https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php'
            : 'https://securepay.sslcommerz.com/validator/api/validationserverAPI.php';
        if ($val_id === '' || empty($gateway['sslcz_store_id']) || empty($gateway['sslcz_store_password'])) {
            $val_error = 'val_id বা Store Credential পাওয়া যায়নি।';
            break;
        }
        $query = http_build_query([
            'val_id' => $val_id, 'store_id' => $gateway['sslcz_store_id'],
            'store_passwd' => $gateway['sslcz_store_password'], 'v' => 1, 'format' => 'json',
        ]);
        [$response, $val_error] = cb_curl_get($validation_url . '?' . $query);
        if ($response) {
            $decoded = json_decode($response, true);
            if (
                is_array($decoded) && in_array($decoded['status'] ?? '', ['VALID', 'VALIDATED'], true)
                && ($decoded['tran_id'] ?? '') === $tran_id
                && abs((float) ($decoded['amount'] ?? 0) - (float) $claim['amount']) < 0.5
            ) {
                $verified = true;
            } else {
                $val_error = 'SSLCommerz Validation ব্যর্থ: ' . json_encode($decoded);
            }
        }
        break;

    // ================= ShurjoPay =================
    case 'shurjopay':
        $sp_base = !empty($gateway['shurjopay_sandbox']) ? 'https://sandbox.shurjopayment.com' : 'https://engine.shurjopayment.com';
        [$tok_response, $val_error] = cb_curl_post($sp_base . '/api/get_token', [
            'username' => $gateway['shurjopay_username'], 'password' => $gateway['shurjopay_password'],
        ]);
        $token = null;
        if ($tok_response) {
            $tok = json_decode($tok_response, true);
            $token = $tok['token'] ?? null;
        }
        if ($token) {
            [$response, $val_error] = cb_curl_post($sp_base . '/api/verification', ['order_id' => $tran_id], ['Authorization: Bearer ' . $token]);
            if ($response) {
                $decoded = json_decode($response, true);
                $row = is_array($decoded) && isset($decoded[0]) ? $decoded[0] : $decoded;
                if (
                    is_array($row) && (string) ($row['sp_code'] ?? '') === '1000'
                    && abs((float) ($row['amount'] ?? 0) - (float) $claim['amount']) < 0.5
                ) {
                    $verified = true;
                } else {
                    $val_error = 'ShurjoPay Verification ব্যর্থ: ' . json_encode($row);
                }
            }
        } else {
            $val_error = 'ShurjoPay token পাওয়া যায়নি।';
        }
        break;

    // ================= PayStation =================
    case 'paystation':
        [$response, $val_error] = cb_curl_post('https://api.paystation.com.bd/transaction-status', [
            'invoice_number' => $tran_id,
        ], ['merchantId: ' . ($gateway['paystation_merchant_id'] ?? '')]);
        if ($response) {
            $decoded = json_decode($response, true);
            $trx_status = $decoded['trx_status'] ?? $decoded['status'] ?? '';
            if (
                is_array($decoded) && strtolower((string) $trx_status) === 'success'
                && abs((float) ($decoded['trx_amount'] ?? $decoded['payment_amount'] ?? 0) - (float) $claim['amount']) < 0.5
            ) {
                $verified = true;
            } else {
                $val_error = 'PayStation Verification ব্যর্থ: ' . json_encode($decoded);
            }
        }
        break;

    // ================= EPS =================
    // নোট: EPS-এর ভেরিফিকেশন এন্ডপয়েন্টও (init.php-এর মতোই) EPS-এর সাধারণ কনভেনশন অনুযায়ী —
    // লাইভে যাওয়ার আগে Sandbox-এ যাচাই করে নিন।
    case 'eps':
        $eps_base = !empty($gateway['eps_sandbox']) ? 'https://sandboxpg.eps.com.bd' : 'https://gateway.eps.com.bd';
        [$response, $val_error] = cb_curl_post($eps_base . '/v1/EPSEngine/verify', [
            'merchantId' => $gateway['eps_merchant_id'], 'username' => $gateway['eps_username'],
            'password' => $gateway['eps_password'], 'orderID' => $tran_id,
        ], [], true);
        if ($response) {
            $decoded = json_decode($response, true);
            $eps_status = strtolower((string) ($decoded['status'] ?? $decoded['transactionStatus'] ?? ''));
            if (
                is_array($decoded) && in_array($eps_status, ['success', 'completed', 'paid'], true)
                && abs((float) ($decoded['amount'] ?? 0) - (float) $claim['amount']) < 0.5
            ) {
                $verified = true;
            } else {
                $val_error = 'EPS Verification ব্যর্থ: ' . json_encode($decoded);
            }
        }
        break;

    default:
        $val_error = 'অজানা Payment Provider।';
}

if (!$verified) {
    error_log("Payment validation failed ($provider) for tran_id $tran_id: " . $val_error);
    if ($status === 'ipn') { echo 'IGNORED'; exit(); }
    acb_redirect_slip($pdo, $claim, 'failed');
}

// ------------------------------------------------------------
// ভ্যালিডেটেড — Claim Approved + Finance-এ Paid এন্ট্রি
// ------------------------------------------------------------
$provider_label = af_provider_label($provider);
$fc_id = af_post_to_finance($pdo, $claim, $provider_label);
$pdo->prepare(
    "UPDATE admission_fee_claims SET status = 'Approved', gateway_val_id = ?, verified_by = ?, verified_at = NOW(), finance_collection_id = ? WHERE id = ?"
)->execute([$val_id ?: $tran_id, $provider_label . ' (Auto)', $fc_id ?: null, $claim['id']]);

if ($status === 'ipn') { echo 'OK'; exit(); }

acb_redirect_slip($pdo, $claim, 'success');
