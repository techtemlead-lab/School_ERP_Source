<?php
/**
 * admission_payment_init.php
 * -----------------------------------------------------------
 * submit_admission_form.php থেকে Auto পেমেন্ট বেছে নিলে এখানে রিডাইরেক্ট হয়ে আসে।
 * Admin যে গেটওয়ে Active করেছেন (SSLCommerz / ShurjoPay / PayStation / EPS) তার Session/Checkout
 * Init API-তে POST করে Payment পেজের URL নেয়, তারপর ইউজারকে সেই পেজে রিডাইরেক্ট করে।
 * নেটওয়ার্ক/API সমস্যা হলে সুন্দরভাবে এরর দেখিয়ে Admission Slip পেজে ফিরিয়ে নেয়
 * (ভর্তি আবেদন ততক্ষণে জমা হয়ে গেছে, শুধু পেমেন্ট বাকি থাকে)।
 */

session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/admission_fee_helpers.php';
af_ensure_tables($pdo);

$student_pk = (int) ($_GET['student_pk'] ?? 0);
$slip_token = trim($_GET['token'] ?? '');

if (!$student_pk || $slip_token === '') {
    header('Location: admission_form.php');
    exit();
}

$stu_stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND admission_slip_token = ? LIMIT 1");
$stu_stmt->execute([$student_pk, $slip_token]);
$student = $stu_stmt->fetch();

if (!$student) {
    header('Location: admission_form.php');
    exit();
}

// সবচেয়ে সাম্প্রতিক Pending Auto claim খোঁজা (একই ছাত্রের জন্য বারবার এলে আগেরটাই পুনর্ব্যবহার)
$claim_stmt = $pdo->prepare(
    "SELECT * FROM admission_fee_claims WHERE student_pk = ? AND method = 'AutoGateway' AND status = 'Pending' ORDER BY id DESC LIMIT 1"
);
$claim_stmt->execute([$student_pk]);
$claim = $claim_stmt->fetch();

if (!$claim) {
    header('Location: admission_slip.php?token=' . urlencode($slip_token) . '&payment=success');
    exit();
}

$gateway = af_gateway_settings($pdo);
$provider = $gateway['auto_provider'] ?? 'sslcommerz';

if (!af_auto_gateway_ready($gateway)) {
    header('Location: admission_slip.php?token=' . urlencode($slip_token) . '&payment=failed');
    exit();
}

$tran_id = 'ADM' . $claim['id'] . '_' . time();
$pdo->prepare("UPDATE admission_fee_claims SET trx_id = ? WHERE id = ?")->execute([$tran_id, $claim['id']]);

$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$callback_url = $base_url . dirname($_SERVER['SCRIPT_NAME']) . '/admission_payment_callback.php';

function api_curl_post(string $url, array $post_fields, array $extra_headers = [], bool $json = false): array
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
$customer_name = $student['name_en'] ?: 'Applicant';
$customer_phone = $student['mobile'] ?: $student['guardian_phone'] ?: '01700000000';
$customer_email = $student['email'] ?: 'no-reply@example.com';

switch ($provider) {

    // ================= SSLCommerz =================
    case 'sslcommerz':
        $sandbox = !empty($gateway['sslcz_sandbox']);
        $api_url = $sandbox
            ? 'https://sandbox.sslcommerz.com/gwprocess/v4/api.php'
            : 'https://securepay.sslcommerz.com/gwprocess/v4/api.php';
        [$response, $curl_error] = api_curl_post($api_url, [
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
            'shipping_method' => 'NO', 'product_name' => 'Admission Fee',
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
        [$tok_response, $curl_error] = api_curl_post($sp_base . '/api/get_token', [
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
            [$pay_response, $curl_error] = api_curl_post($sp_base . '/api/secret-pay', [
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
        [$response, $curl_error] = api_curl_post($ps_api, [
            'merchantId' => $gateway['paystation_merchant_id'],
            'password' => $gateway['paystation_password'],
            'invoice_number' => $tran_id,
            'payment_amount' => (int) round((float) $claim['amount']),
            'cust_name' => $customer_name, 'cust_phone' => $customer_phone, 'cust_email' => $customer_email,
            'currency' => 'BDT',
            'callback_url' => $callback_url . '?provider=paystation&tran_id=' . urlencode($tran_id),
            'reference' => 'Admission Fee',
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
    // নোট: EPS-এর নির্দিষ্ট এন্ডপয়েন্ট/সিগনেচার স্কিম পাবলিক ডকুমেন্টেশন থেকে সম্পূর্ণভাবে
    // যাচাই করা যায়নি — নিচের কাঠামো EPS-এর সাধারণ কনভেনশন অনুযায়ী লেখা হয়েছে। লাইভে যাওয়ার আগে
    // অবশ্যই Sandbox-এ টেস্ট করুন, প্রয়োজনে integration@eps.com.bd থেকে এক্স্যাক্ট এন্ডপয়েন্ট যাচাই করুন।
    case 'eps':
        $eps_base = !empty($gateway['eps_sandbox']) ? 'https://sandboxpg.eps.com.bd' : 'https://gateway.eps.com.bd';
        $eps_hash = hash('sha512', $gateway['eps_merchant_id'] . $tran_id . $amount . $gateway['eps_hash_key']);
        [$response, $curl_error] = api_curl_post($eps_base . '/v1/EPSEngine/checkout', [
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

// ------------------------------------------------------------
// Gateway-তে পৌঁছানো যায়নি — ভর্তি আবেদন ইতিমধ্যে জমা হয়ে গেছে, শুধু অনলাইন পেমেন্টটা এখন
// সম্ভব হচ্ছে না। Claim টা Failed মার্ক করে ইউজারকে জানানো হচ্ছে — পরে Admin ম্যানুয়ালি অফিসে
// ফি জমা নিয়ে Finance থেকে এন্ট্রি করতে পারবেন।
// ------------------------------------------------------------
$pdo->prepare("UPDATE admission_fee_claims SET status = 'Failed' WHERE id = ?")->execute([$claim['id']]);
error_log("Payment gateway ($provider) init failed: " . $curl_error);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Payment Error</title>
<style>body{font-family:sans-serif;max-width:600px;margin:60px auto;padding:0 20px;color:#333;text-align:center;}
.box{border:1px solid #f3c8c4;background:#fdf0ee;color:#b3261e;border-radius:8px;padding:24px;}
a.btn{display:inline-block;margin-top:18px;background:#17263b;color:#fff;padding:10px 24px;border-radius:5px;text-decoration:none;}</style>
</head>
<body>
<div class="box">
    <h3>অনলাইন পেমেন্ট এই মুহূর্তে সম্ভব হচ্ছে না</h3>
    <p>আপনার ভর্তি আবেদন সফলভাবে জমা হয়ে গেছে, কিন্তু Payment Gateway-তে সংযোগ করা যায়নি। অনুগ্রহ করে কিছুক্ষণ পর আবার চেষ্টা করুন অথবা সরাসরি স্কুলে যোগাযোগ করে ফি পরিশোধ করুন।</p>
    <a class="btn" href="admission_slip.php?token=<?php echo urlencode($slip_token); ?>&payment=failed">Admission Slip দেখুন</a>
</div>
</body>
</html>
