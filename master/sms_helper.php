<?php
// ══════════════════════════════════════════════════════════════
// School SaaS — Master Panel — SMS Helper
// যেকোনো Bangladeshi SMS Gateway (BulkSMSBD, GreenWeb, Alpha SMS,
// ইত্যাদি) generic parameter mapping দিয়ে কনফিগার করা যায়
// (Settings > 📩 SMS Gateway ট্যাব থেকে)। sendSaasSMS() /
// smsSendDueReminder() / smsSendPaymentSuccess() call করলেই হবে।
// ══════════════════════════════════════════════════════════════

function saasSmsEnsureTables($pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS saas_sms_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT DEFAULT NULL,
        recipient VARCHAR(20) NOT NULL,
        message TEXT NOT NULL,
        type VARCHAR(50) DEFAULT 'manual',
        status ENUM('sent','failed','pending') DEFAULT 'pending',
        error_msg VARCHAR(255) DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    ensureSaasSettingsTable($pdo);
    $done = true;
}

function getSaasSmsSetting($pdo, $key, $default = '') {
    try {
        ensureSaasSettingsTable($pdo);
        $s = $pdo->prepare("SELECT setting_value FROM saas_settings WHERE setting_key=?");
        $s->execute(['sms_' . $key]);
        $r = $s->fetchColumn();
        return ($r !== false && $r !== '') ? $r : $default;
    } catch (Exception $e) { return $default; }
}

function saveSaasSmsSettings($pdo, array $values) {
    ensureSaasSettingsTable($pdo);
    foreach ($values as $key => $val) {
        $pdo->prepare("INSERT INTO saas_settings (setting_key, setting_value) VALUES (?,?)
            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
            ->execute(['sms_' . $key, trim($val)]);
    }
}

// ── ফোন নাম্বার নরমালাইজ (01xxxxxxxxx → 8801xxxxxxxxx) ──────────
function saasNormalizePhone($phone) {
    $phone = preg_replace('/\D/', '', (string) $phone);
    if (strlen($phone) === 11 && substr($phone, 0, 2) === '01') return '88' . $phone;
    if (strlen($phone) === 13 && substr($phone, 0, 2) === '88') return $phone;
    return $phone;
}

// ── মূল SMS পাঠানোর ফাংশন — যেকোনো Gateway-এর জন্য জেনেরিক ─────
function sendSaasSMS($pdo, $phone, $message, $type = 'manual', $tenantId = null) {
    saasSmsEnsureTables($pdo);
    $phone = saasNormalizePhone($phone);

    $apiUrl      = getSaasSmsSetting($pdo, 'api_url');
    $apiKey      = getSaasSmsSetting($pdo, 'api_key');
    $senderId    = getSaasSmsSetting($pdo, 'sender_id');
    $phoneParam  = getSaasSmsSetting($pdo, 'param_phone', 'to');
    $msgParam    = getSaasSmsSetting($pdo, 'param_message', 'message');
    $apiKeyParam = getSaasSmsSetting($pdo, 'param_apikey', 'api_key');
    $senderParam = getSaasSmsSetting($pdo, 'param_sender', 'sender_id');
    $method      = strtoupper(getSaasSmsSetting($pdo, 'method', 'GET'));
    $successKw   = strtolower(getSaasSmsSetting($pdo, 'success_keyword', ''));
    $userId      = $_SESSION['master_user_id'] ?? null;
    $status      = 'failed';
    $errorMsg    = null;
    $response    = '';

    if (empty($apiUrl) || empty($apiKey)) {
        $pdo->prepare("INSERT INTO saas_sms_logs (tenant_id,recipient,message,type,status,error_msg,created_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$tenantId, $phone, $message, $type, 'pending', 'Gateway কনফিগার করা নেই', $userId]);
        return ['success' => false, 'error' => 'SMS Gateway কনফিগার করা নেই (Settings > SMS Gateway)'];
    }

    $params = [$apiKeyParam => $apiKey, $phoneParam => $phone, $msgParam => $message];
    if (!empty($senderId) && !empty($senderParam)) $params[$senderParam] = $senderId;

    try {
        $ch = curl_init();
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } else {
            curl_setopt($ch, CURLOPT_URL, $apiUrl . '?' . http_build_query($params));
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $errorMsg = 'cURL Error: ' . $curlErr;
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            if (empty($successKw) || stripos((string) $response, $successKw) !== false) {
                $status = 'sent';
            } else {
                $errorMsg = 'Response: ' . substr((string) $response, 0, 150);
            }
        } else {
            $errorMsg = 'HTTP ' . $httpCode . ': ' . substr((string) $response, 0, 150);
        }
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }

    $pdo->prepare("INSERT INTO saas_sms_logs (tenant_id,recipient,message,type,status,error_msg,created_by) VALUES (?,?,?,?,?,?,?)")
        ->execute([$tenantId, $phone, $message, $type, $status, $errorMsg, $userId]);

    return ['success' => $status === 'sent', 'status' => $status, 'error' => $errorMsg, 'response' => $response];
}

// ── বকেয়া বিল রিমাইন্ডার — subscription_ends_at এর কাছাকাছি tenant-দের জন্য ──
function smsSendDueReminder($pdo, $tenantId) {
    $t = $pdo->prepare("SELECT * FROM tenants WHERE id=?");
    $t->execute([$tenantId]);
    $tenant = $t->fetch();
    if (!$tenant) return ['success' => false, 'error' => 'Tenant পাওয়া যায়নি'];
    if (empty($tenant['contact_phone'])) return ['success' => false, 'error' => 'যোগাযোগের ফোন নম্বর নেই'];

    $msg = "প্রিয় {$tenant['contact_name']}, আপনার \"{$tenant['school_name']}\" প্যানেলের মেয়াদ "
        . ($tenant['subscription_ends_at'] ?: $tenant['trial_ends_at']) . " তারিখে শেষ হচ্ছে। সময়মতো রিনিউ করুন, ধন্যবাদ।";

    return sendSaasSMS($pdo, $tenant['contact_phone'], $msg, 'due_reminder', $tenantId);
}

// ── পেমেন্ট সফল হওয়ার SMS — billing.php থেকে approve হলে অটো কল হয় ──
function smsSendPaymentSuccess($pdo, $tenantId, $amount = 0) {
    $t = $pdo->prepare("SELECT * FROM tenants WHERE id=?");
    $t->execute([$tenantId]);
    $tenant = $t->fetch();
    if (!$tenant) return ['success' => false, 'error' => 'Tenant পাওয়া যায়নি'];
    if (empty($tenant['contact_phone'])) return ['success' => false, 'error' => 'যোগাযোগের ফোন নম্বর নেই'];

    $msg = "প্রিয় {$tenant['contact_name']}, আপনার \"{$tenant['school_name']}\" এর ৳" . number_format($amount, 0)
        . " পেমেন্ট সফলভাবে গৃহীত হয়েছে। নতুন মেয়াদ: {$tenant['subscription_ends_at']}। ধন্যবাদ।";

    return sendSaasSMS($pdo, $tenant['contact_phone'], $msg, 'payment_success', $tenantId);
}
