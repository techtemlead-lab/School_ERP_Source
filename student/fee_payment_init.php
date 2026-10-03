<?php
/**
 * student/fee_payment_init.php
 * -----------------------------------------------------------
 * Student পোর্টালের My Dues পেজ থেকে কোনো মাসের পাশে "Pay Online" বাটনে ক্লিক করলে এখানে
 * আসে — সেই মাসের Tuition/Session/অন্য সব Academic Account Head এর Due একসাথে যোগ করে
 * একটাই Payment তৈরি হয়। Admin যে গেটওয়ে Active করেছেন (SSLCommerz/ShurjoPay/PayStation/EPS —
 * Admission Fee-এর সাথে শেয়ার করা একই Global গেটওয়ে) তার Checkout Init API-তে POST করে
 * Payment পেজের URL নেয়, তারপর ইউজারকে সেই পেজে রিডাইরেক্ট করে। পেমেন্ট সফল হলে callback
 * ভ্যালিডেশনের পরপরই প্রতিটা Account Head-এ Finance-এ Paid এন্ট্রি বসে যায় (fee_payment_callback.php দেখুন)।
 */

require_once __DIR__ . '/_bootstrap.php';
sp_require_login();
require_once __DIR__ . '/../config/monthly_fee_helpers.php';
require_once __DIR__ . '/../config/admission_fee_helpers.php';
mf_ensure_tables($pdo);
af_ensure_tables($pdo);

$student_pk = (int) ($_SESSION['student_pk'] ?? 0);
$month = (int) ($_GET['month'] ?? 0);

if (!$student_pk || $month < 1 || $month > 12) {
    header('Location: index.php?page=my_dues');
    exit();
}

$stu_stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? LIMIT 1");
$stu_stmt->execute([$student_pk]);
$student = $stu_stmt->fetch();

if (!$student) {
    header('Location: login.php');
    exit();
}

$session_id = (int) $student['session_id'];
$info = mf_month_due($pdo, $student, $session_id, $month);

if ($info['due'] <= 0) {
    header('Location: index.php?page=my_dues&payment=success');
    exit();
}

$gateway = af_gateway_settings($pdo);
$provider = $gateway['auto_provider'] ?? 'sslcommerz';

if (!af_auto_gateway_ready($gateway)) {
    header('Location: index.php?page=my_dues');
    exit();
}

// এই Student+Session+Month এর জন্য একটা Pending claim বসানো/আপডেট করা হচ্ছে
$claim_stmt = $pdo->prepare(
    "INSERT INTO monthly_fee_online_claims (student_id, session_id, class_id, month, amount, method, status)
     VALUES (?, ?, ?, ?, ?, 'AutoGateway', 'Pending')
     ON DUPLICATE KEY UPDATE class_id = VALUES(class_id), amount = VALUES(amount), method = 'AutoGateway',
        sender_number = NULL, gateway_val_id = NULL,
        status = 'Pending', submitted_at = CURRENT_TIMESTAMP, verified_by = NULL, verified_at = NULL"
);
$claim_stmt->execute([$student_pk, $session_id, (int) $student['class_id'], $month, $info['due']]);

$claim_row = $pdo->prepare("SELECT * FROM monthly_fee_online_claims WHERE student_id = ? AND session_id = ? AND month = ? LIMIT 1");
$claim_row->execute([$student_pk, $session_id, $month]);
$claim = $claim_row->fetch();

$tran_id = 'FEE' . $claim['id'] . '_' . time();
$pdo->prepare("UPDATE monthly_fee_online_claims SET trx_id = ? WHERE id = ?")->execute([$tran_id, $claim['id']]);

$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$callback_url = $base_url . dirname($_SERVER['SCRIPT_NAME']) . '/fee_payment_callback.php';

function fp_curl_post(string $url, array $post_fields, array $extra_headers = [], bool $json = false): array
{
    if (!function_exists('curl_init')) {
        return [null, 'cURL extension সার্ভারে ইনস্টল নেই।'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json ? json_encode($post_fields) : http_build_query($post_fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => array_merge($json ? ['Content-Type: application/json'] : [], $extra_headers),
    ]);
    $response = curl_exec($ch);
    $err = $response === false ? curl_error($ch) : null;
    curl_close($ch);
    return [$response, $err];
}

$gateway_page_url = null;
$curl_error = null;
$amount = number_format((float) $claim['amount'], 2, '.', '');
$customer_name = $student['name_en'] ?: 'Student';
$customer_phone = $student['mobile'] ?: $student['guardian_phone'] ?: '01700000000';
$customer_email = $student['email'] ?: 'no-reply@example.com';

switch ($provider) {

    // ================= SSLCommerz =================
    case 'sslcommerz':
        $sandbox = !empty($gateway['sslcz_sandbox']);
        $api_url = $sandbox
            ? 'https://sandbox.sslcommerz.com/gwprocess/v4/api.php'
            : 'https://securepay.sslcommerz.com/gwprocess/v4/api.php';
        [$response, $curl_error] = fp_curl_post($api_url, [
            'store_id' => $gateway['sslcz_store_id'],
            'store_passwd' => $gateway['sslcz_store_password'],
            'total_amount' => $amount,
            'currency' => 'BDT',
            'tran_id' => $tran_id,
            'success_url' => $callback_url . '?provider=sslcommerz&status=success&tran_id=' . urlencode($tran_id),
            'fail_url' => $callback_url . '?provider=sslcommerz&status=fail&tran_id=' . urlencode($tran_id),
            'cancel_url' => $callback_url . '?provider=sslcommerz&status=cancel&tran_id=' . urlencode($tran_id),
            'ipn_url' => $callback_url . '?provider=sslcommerz&status=ipn&tran_id=' . urlencode($tran_id),
            'emi_option' => 0,
            'cus_name' => $customer_name, 'cus_email' => $customer_email,
            'cus_add1' => $student['present_address'] ?: 'N/A', 'cus_city' => 'N/A', 'cus_country' => 'Bangladesh',
            'cus_phone' => $customer_phone,
            'shipping_method' => 'NO', 'product_name' => 'Monthly Fee',
            'product_category' => 'Education', 'product_profile' => 'general',
        ]);
        if ($response) {
            $decoded = json_decode($response, true);
            if (is_array($decoded) && ($decoded['status'] ?? '') === 'SUCCESS' && !empty($decoded['GatewayPageURL'])) {
                $gateway_page_url = $decoded['GatewayPageURL'];
            } else {
                $curl_error = $decoded['failedreason'] ?? 'SSLCommerz API থেকে ভ্যালিড রেসপন্স পাওয়া যায়নি।';
            }
        }
        break;

    // ================= ShurjoPay =================
    case 'shurjopay':
        $sp_base = !empty($gateway['shurjopay_sandbox']) ? 'https://sandbox.shurjopayment.com' : 'https://engine.shurjopayment.com';
        [$tok_response, $curl_error] = fp_curl_post($sp_base . '/api/get_token', [
            'username' => $gateway['shurjopay_username'],
            'password' => $gateway['shurjopay_password'],
        ]);
        $token = null; $store_id = null;
        if ($tok_response) {
            $tok = json_decode($tok_response, true);
            $token = $tok['token'] ?? null;
            $store_id = $tok['store_id'] ?? $gateway['shurjopay_prefix'];
            if (!$token) { $curl_error = 'ShurjoPay Authentication ব্যর্থ — username/password যাচাই করুন।'; }
        }
        if ($token) {
            [$pay_response, $curl_error] = fp_curl_post($sp_base . '/api/secret-pay', [
                'token' => $token, 'store_id' => $store_id, 'prefix' => $gateway['shurjopay_prefix'],
                'amount' => $amount, 'order_id' => $tran_id, 'currency' => 'BDT',
                'customer_name' => $customer_name, 'customer_phone' => $customer_phone, 'customer_email' => $customer_email,
                'customer_address' => $student['present_address'] ?: 'N/A', 'customer_city' => 'Dhaka',
                'customer_state' => 'Dhaka', 'customer_postcode' => '1000', 'customer_country' => 'Bangladesh',
                'return_url' => $callback_url . '?provider=shurjopay&tran_id=' . urlencode($tran_id),
                'cancel_url' => $callback_url . '?provider=shurjopay&status=cancel&tran_id=' . urlencode($tran_id),
                'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ], ['Authorization: Bearer ' . $token]);
            if ($pay_response) {
                $pay = json_decode($pay_response, true);
                $gateway_page_url = $pay['checkout_url'] ?? null;
                if (!$gateway_page_url) { $curl_error = 'ShurjoPay checkout_url পাওয়া যায়নি।'; }
            }
        }
        break;

    // ================= PayStation =================
    case 'paystation':
        $ps_api = 'https://api.paystation.com.bd/initiate-payment';
        [$response, $curl_error] = fp_curl_post($ps_api, [
            'merchantId' => $gateway['paystation_merchant_id'],
            'password' => $gateway['paystation_password'],
            'invoice_number' => $tran_id,
            'payment_amount' => (int) round((float) $claim['amount']),
            'cust_name' => $customer_name, 'cust_phone' => $customer_phone, 'cust_email' => $customer_email,
            'currency' => 'BDT',
            'callback_url' => $callback_url . '?provider=paystation&tran_id=' . urlencode($tran_id),
            'reference' => 'Monthly Fee',
        ]);
        if ($response) {
            $decoded = json_decode($response, true);
            if (is_array($decoded) && ($decoded['status_code'] ?? '') === '200' && !empty($decoded['payment_url'])) {
                $gateway_page_url = $decoded['payment_url'];
            } else {
                $curl_error = 'PayStation API রেসপন্স: ' . ($response ?: 'ভ্যালিড রেসপন্স পাওয়া যায়নি');
            }
        }
        break;

    // ================= EPS =================
    case 'eps':
        $eps_base = !empty($gateway['eps_sandbox']) ? 'https://sandboxpg.eps.com.bd' : 'https://gateway.eps.com.bd';
        $eps_hash = hash('sha512', $gateway['eps_merchant_id'] . $tran_id . $amount . $gateway['eps_hash_key']);
        [$response, $curl_error] = fp_curl_post($eps_base . '/v1/EPSEngine/checkout', [
            'merchantId' => $gateway['eps_merchant_id'],
            'username' => $gateway['eps_username'],
            'password' => $gateway['eps_password'],
            'hash' => $eps_hash,
            'orderID' => $tran_id,
            'amount' => $amount,
            'currency' => 'BDT',
            'successUrl' => $callback_url . '?provider=eps&status=success&tran_id=' . urlencode($tran_id),
            'failUrl' => $callback_url . '?provider=eps&status=fail&tran_id=' . urlencode($tran_id),
            'cancelUrl' => $callback_url . '?provider=eps&status=cancel&tran_id=' . urlencode($tran_id),
            'customerName' => $customer_name, 'customerEmail' => $customer_email, 'customerPhone' => $customer_phone,
            'customerAddress' => $student['present_address'] ?: 'N/A',
        ], [], true);
        if ($response) {
            $decoded = json_decode($response, true);
            $gateway_page_url = $decoded['redirectUrl'] ?? $decoded['GatewayPageURL'] ?? $decoded['url'] ?? null;
            if (!$gateway_page_url) { $curl_error = 'EPS API থেকে redirect URL পাওয়া যায়নি: ' . $response; }
        }
        break;
}

if ($gateway_page_url) {
    header('Location: ' . $gateway_page_url);
    exit();
}

// গেটওয়েতে পৌঁছানো যায়নি — claim Rejected মার্ক করে My Dues পেজে এরর দেখিয়ে ফেরত
$pdo->prepare("UPDATE monthly_fee_online_claims SET status = 'Rejected' WHERE id = ?")->execute([$claim['id']]);
error_log("Monthly fee payment gateway ($provider) init failed: " . $curl_error);
header('Location: index.php?page=my_dues&payment=failed');
exit();
