<?php
/**
 * config/sms.php
 * -----------------------------------------------------------
 * কেন্দ্রীয় SMS পাঠানোর হেল্পার ফাংশন। GreenWeb SMS (বা GreenWeb-এর
 * মতো যেকোনো HTTP GET/POST ভিত্তিক BD SMS গেটওয়ে) দিয়ে পাঠানোর জন্য
 * বানানো। API URL/Token/Sender ID কোডে হার্ডকোড না করে
 * "SMS Settings > SMS Gateway API Settings" পেজ থেকে (sms_settings
 * টেবিলে) সেভ হয়, এখান থেকে সেটাই রিড হয় — তাই ক্রেডেনশিয়াল বদলাতে
 * কোনো কোড টাচ করতে হবে না।
 */

// -----------------------------------------------------------
// sms_settings টেবিলে API কলামগুলো না থাকলে অটো যোগ করে দেওয়া
// (পুরনো ইনস্টলেশনেও যেন কোনো ম্যানুয়াল SQL ছাড়াই কাজ করে)
// -----------------------------------------------------------
function sms_ensure_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $existing = $pdo->query("SHOW COLUMNS FROM sms_settings")->fetchAll(PDO::FETCH_COLUMN, 0);
    $needed = [
        'api_provider'  => "VARCHAR(50) NULL DEFAULT 'GreenWeb'",
        'api_method'    => "ENUM('GET','POST') NOT NULL DEFAULT 'GET'",
        'api_url'       => "TEXT NULL",
        'api_token'     => "VARCHAR(255) NULL",
        'api_user'      => "VARCHAR(150) NULL",
        'api_sender_id' => "VARCHAR(50) NULL",
        'api_enabled'   => "TINYINT(1) NOT NULL DEFAULT 0",
        'show_video_home'   => "TINYINT(1) NOT NULL DEFAULT 1",
        'show_gallery_home' => "TINYINT(1) NOT NULL DEFAULT 1",
        'show_notices_home' => "TINYINT(1) NOT NULL DEFAULT 1",
        'show_events_home'  => "TINYINT(1) NOT NULL DEFAULT 1",
        // ওয়েবসাইটে Teacher/Staff School/College সেকশন অনুযায়ী আলাদা দেখাবে কি না (ডিফল্ট OFF = All/Teacher/Staff)
        'show_section_filter' => "TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($needed as $col => $def) {
        if (!in_array($col, $existing, true)) {
            $pdo->exec("ALTER TABLE sms_settings ADD COLUMN $col $def");
        }
    }
}

/**
 * DB থেকে সেভ করা SMS গেটওয়ে সেটিংস রিড করা (একবার লোড হলে ক্যাশ থাকে)।
 */
function sms_get_gateway_settings(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    sms_ensure_schema($pdo);
    $row = $pdo->query("SELECT api_provider, api_method, api_url, api_token, api_user, api_sender_id, api_enabled FROM sms_settings WHERE id = 1")->fetch();
    $cache = [
        'provider'  => $row['api_provider'] ?? 'GreenWeb',
        'method'    => strtoupper($row['api_method'] ?? 'GET') === 'POST' ? 'POST' : 'GET',
        'url'       => $row['api_url'] ?? '',
        'token'     => $row['api_token'] ?? '',
        'user'      => $row['api_user'] ?? '',
        'sender_id' => $row['api_sender_id'] ?? '',
        'enabled'   => (bool) ($row['api_enabled'] ?? 0),
    ];
    return $cache;
}

/**
 * একটা নম্বরে SMS পাঠানো — যেকোনো HTTP-ভিত্তিক BD SMS গেটওয়ে সাপোর্ট করে
 * (GreenWeb, BulkSMSBD, Alpha SMS, Elitbuzz, Adn SMS ইত্যাদি), কারণ
 * প্যারামিটার নাম হার্ডকোড না করে URL-টাকে টেমপ্লেট হিসেবে ব্যবহার করা হয়।
 *
 * SMS Settings পেজে API URL এভাবে বসাতে হবে (প্লেসহোল্ডার সহ, আপনার
 * গেটওয়ের ডকুমেন্টেশন থেকে URL কপি করে শুধু আসল ভ্যালুগুলো
 * {token} {user} {sender_id} {phone} {message} দিয়ে রিপ্লেস করুন):
 *
 *   GreenWeb:   https://api.greenweb.com.bd/api.php?token={token}&to={phone}&message={message}
 *   BulkSMSBD:  http://bulksmsbd.net/api/smsapi?api_key={token}&type=text&number={phone}&senderid={sender_id}&message={message}
 *   Alpha SMS:  https://sms.hostmybd.net/smsapi?api_key={token}&sid={sender_id}&msisdn={phone}&sms={message}
 *
 * URL/Body-তে {phone} বা {message} প্লেসহোল্ডার না থাকলে (পুরনো
 * স্টাইলে শুধু বেস URL দেওয়া থাকলে), ব্যাকওয়ার্ড-কম্প্যাটিবিলিটির জন্য
 * GreenWeb-এর ডিফল্ট প্যারামিটার নাম (token/to/message) দিয়ে অটো
 * query string বানিয়ে নেওয়া হয়।
 *
 * রিটার্ন করে ['success' => bool, 'response' => string]
 */
function send_sms(PDO $pdo, string $phone, string $message): array
{
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) === 11 && str_starts_with($phone, '01')) {
        $phone = '88' . $phone; // BD কোড
    }

    if ($phone === '' || $message === '') {
        return ['success' => false, 'response' => 'ফাঁকা নম্বর বা মেসেজ।'];
    }

    $gw = sms_get_gateway_settings($pdo);

    if (!$gw['enabled']) {
        return ['success' => false, 'response' => 'SMS Settings পেজে API Enable করা নেই।'];
    }
    if ($gw['url'] === '') {
        return ['success' => false, 'response' => 'SMS গেটওয়ে এখনো কনফিগার করা হয়নি — SMS Settings পেজ থেকে API URL বসান।'];
    }

    $placeholders = [
        '{token}'     => rawurlencode($gw['token']),
        '{user}'      => rawurlencode($gw['user']),
        '{sender_id}' => rawurlencode($gw['sender_id']),
        '{phone}'     => rawurlencode($phone),
        '{to}'        => rawurlencode($phone),
        '{message}'   => rawurlencode($message),
        '{sms}'       => rawurlencode($message),
    ];

    $has_template = (strpos($gw['url'], '{phone}') !== false || strpos($gw['url'], '{to}') !== false)
        && (strpos($gw['url'], '{message}') !== false || strpos($gw['url'], '{sms}') !== false);

    if ($has_template) {
        // টেমপ্লেট মোড — প্লেসহোল্ডার রিপ্লেস করে সরাসরি ফাইনাল URL
        $final_url = strtr($gw['url'], $placeholders);
        $ch = curl_init($final_url);
    } elseif ($gw['method'] === 'POST') {
        // লিগ্যাসি/জেনেরিক POST মোড — কমন ফিল্ড নাম দিয়ে পাঠানো
        $post_fields = array_filter([
            'token'     => $gw['token'],
            'user'      => $gw['user'],
            'sender_id' => $gw['sender_id'],
            'to'        => $phone,
            'message'   => $message,
        ], fn($v) => $v !== '');
        $ch = curl_init($gw['url']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
    } else {
        // লিগ্যাসি/জেনেরিক GET মোড — GreenWeb-ডিফল্ট ফিল্ড নাম দিয়ে পাঠানো
        $params = http_build_query(array_filter([
            'token'     => $gw['token'],
            'user'      => $gw['user'],
            'sender_id' => $gw['sender_id'],
            'to'        => $phone,
            'message'   => $message,
        ], fn($v) => $v !== ''));
        $ch = curl_init($gw['url'] . (str_contains($gw['url'], '?') ? '&' : '?') . $params);
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['success' => false, 'response' => $err ?: 'SMS API রেসপন্স করেনি।'];
    }

    return ['success' => true, 'response' => (string) $resp];
}

/**
 * SMS টেমপ্লেটের {PLACEHOLDER} গুলো আসল ডেটা দিয়ে রিপ্লেস করা।
 */
function sms_apply_template(string $template, array $data): string
{
    $search  = array_map(fn($k) => '{' . $k . '}', array_keys($data));
    $replace = array_values($data);
    return str_replace($search, $replace, $template);
}
