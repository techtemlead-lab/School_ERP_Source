<?php
/**
 * config/whatsapp.php
 * -----------------------------------------------------------
 * Meta-র অফিসিয়াল WhatsApp Cloud API (বা এটাকে সরাসরি resell করে এমন যেকোনো
 * BSP, যেমন On Cloud API) দিয়ে WhatsApp টেমপ্লেট/টেক্সট মেসেজ পাঠানোর জন্য
 * কেন্দ্রীয় হেল্পার। Phone Number ID / Access Token কোডে হার্ডকোড না করে
 * "Global Settings > WhatsApp API Settings" পেজ থেকে (whatsapp_settings
 * টেবিলে) সেভ হয়, এখান থেকে সেটাই রিড হয়।
 *
 * WhatsApp-এ ব্যবসা থেকে প্রথম উদ্যোগে (customer এর ২৪ ঘন্টার session window
 * ছাড়া) মেসেজ পাঠাতে হলে Meta-তে আগে থেকে approve করা "Template" ব্যবহার
 * করতে হয় — তাই send_whatsapp_template() ফাংশনটাই মূল, বাল্ক/প্রোমোশনাল
 * SMS-এর WhatsApp ভার্সনের জন্য এটাই ব্যবহার হবে। send_whatsapp_text() শুধু
 * তখনই কাজ করবে যখন কাস্টমার নিজে সম্প্রতি মেসেজ করেছে (session window চলছে)।
 */

function wa_ensure_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS whatsapp_settings (
            id INT PRIMARY KEY,
            api_phone_number_id VARCHAR(50) NULL,
            api_access_token TEXT NULL,
            api_business_account_id VARCHAR(50) NULL,
            api_version VARCHAR(10) NOT NULL DEFAULT 'v20.0',
            api_enabled TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $exists = $pdo->query("SELECT id FROM whatsapp_settings WHERE id = 1")->fetch();
    if (!$exists) {
        $pdo->exec("INSERT INTO whatsapp_settings (id) VALUES (1)");
    }

    // sms_logs টেবিলে 'channel' কলাম না থাকলে যোগ করা হচ্ছে — SMS আর WhatsApp
    // একই লগ টেবিলে থাকবে, Report পেজে Channel দিয়ে আলাদা করা যাবে
    $col_chk = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_logs' AND COLUMN_NAME = 'channel'"
    )->fetch();
    if (!$col_chk) {
        try {
            $pdo->exec("ALTER TABLE sms_logs ADD COLUMN channel VARCHAR(20) NOT NULL DEFAULT 'SMS' AFTER purpose");
        } catch (PDOException $e) {
            error_log('wa_ensure_schema: sms_logs.channel যোগ করা যায়নি — ' . $e->getMessage());
        }
    }
}

/**
 * DB থেকে সেভ করা WhatsApp API সেটিংস রিড করা (ক্যাশসহ)।
 */
function wa_get_gateway_settings(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    wa_ensure_schema($pdo);
    $row = $pdo->query("SELECT api_phone_number_id, api_access_token, api_business_account_id, api_version, api_enabled FROM whatsapp_settings WHERE id = 1")->fetch();
    $cache = [
        'phone_number_id' => $row['api_phone_number_id'] ?? '',
        'access_token'    => $row['api_access_token'] ?? '',
        'business_id'     => $row['api_business_account_id'] ?? '',
        'version'         => $row['api_version'] ?: 'v20.0',
        'enabled'         => (bool) ($row['api_enabled'] ?? 0),
    ];
    return $cache;
}

function wa_normalize_phone(string $phone): string
{
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) === 11 && str_starts_with($phone, '01')) {
        $phone = '88' . $phone; // WhatsApp Cloud API আন্তর্জাতিক ফরম্যাটে (country code সহ) নাম্বার চায়
    }
    return $phone;
}

/**
 * Meta Graph API-তে POST কল করার কমন হেল্পার।
 */
function wa_api_call(array $gw, array $body): array
{
    if (!$gw['enabled']) {
        return ['success' => false, 'response' => 'WhatsApp API Settings পেজে API Enable করা নেই।'];
    }
    if ($gw['phone_number_id'] === '' || $gw['access_token'] === '') {
        return ['success' => false, 'response' => 'WhatsApp এখনো কনফিগার করা হয়নি — Phone Number ID / Access Token বসান।'];
    }

    $url = "https://graph.facebook.com/{$gw['version']}/{$gw['phone_number_id']}/messages";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $gw['access_token'],
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return ['success' => false, 'response' => $err ?: 'WhatsApp API রেসপন্স করেনি।'];
    }

    $decoded = json_decode($resp, true);
    if ($http_code >= 200 && $http_code < 300 && isset($decoded['messages'])) {
        return ['success' => true, 'response' => $resp];
    }
    $err_msg = $decoded['error']['message'] ?? $resp;
    return ['success' => false, 'response' => $err_msg];
}

/**
 * Approved Template দিয়ে WhatsApp মেসেজ পাঠানো (বাল্ক/প্রোমোশনাল মেসেজের জন্য
 * এটাই মূল উপায় — Meta-তে টেমপ্লেট আগে থেকে approve করানো থাকতে হবে)।
 *
 * $params — টেমপ্লেটের বডি ভ্যারিয়েবল {{1}}, {{2}} ইত্যাদির মান, ক্রম অনুযায়ী।
 */
function send_whatsapp_template(PDO $pdo, string $phone, string $template_name, string $lang_code = 'en_US', array $params = []): array
{
    $phone = wa_normalize_phone($phone);
    if ($phone === '' || $template_name === '') {
        return ['success' => false, 'response' => 'ফাঁকা নম্বর বা Template নাম।'];
    }
    $gw = wa_get_gateway_settings($pdo);

    $template = [
        'name'     => $template_name,
        'language' => ['code' => $lang_code],
    ];
    if ($params) {
        $template['components'] = [[
            'type'       => 'body',
            'parameters' => array_map(fn($p) => ['type' => 'text', 'text' => (string) $p], $params),
        ]];
    }

    $body = [
        'messaging_product' => 'whatsapp',
        'to'                => $phone,
        'type'              => 'template',
        'template'          => $template,
    ];
    return wa_api_call($gw, $body);
}

/**
 * সাধারণ টেক্সট মেসেজ (শুধু কাস্টমার সার্ভিস ২৪ ঘণ্টা window চলাকালীন কাজ করবে,
 * অর্থাৎ কাস্টমার নিজে সম্প্রতি মেসেজ করেছে এমন নাম্বারে)।
 */
function send_whatsapp_text(PDO $pdo, string $phone, string $message): array
{
    $phone = wa_normalize_phone($phone);
    if ($phone === '' || $message === '') {
        return ['success' => false, 'response' => 'ফাঁকা নম্বর বা মেসেজ।'];
    }
    $gw = wa_get_gateway_settings($pdo);

    $body = [
        'messaging_product' => 'whatsapp',
        'to'                => $phone,
        'type'              => 'text',
        'text'              => ['body' => $message],
    ];
    return wa_api_call($gw, $body);
}
