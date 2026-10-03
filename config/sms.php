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
        // ব্রেকিং নিউজ বার হোমপেজের হিরো/স্লাইডার সেকশনের উপরে না নিচে দেখাবে
        'breaking_news_position' => "VARCHAR(20) NOT NULL DEFAULT 'above_hero'",
        // SMS ব্যালেন্স চেক করার জন্য (গেটওয়ের নিজস্ব ব্যালেন্স API, sending API থেকে আলাদা)
        'balance_api_url'    => "TEXT NULL",
        'sms_balance'        => "BIGINT NOT NULL DEFAULT 0",
        'sms_balance_taka'   => "DECIMAL(12,2) NOT NULL DEFAULT 0",
        'sms_buy'            => "BIGINT NOT NULL DEFAULT 0",
        'balance_checked_at' => "TIMESTAMP NULL DEFAULT NULL",
    ];
    foreach ($needed as $col => $def) {
        if (!in_array($col, $existing, true)) {
            // একটা কলাম যোগ করতে ব্যর্থ হলেও (পারমিশন/রেস-কন্ডিশন ইত্যাদি কারণে)
            // পুরো পেজ যেন ৫০০ এরর দিয়ে ডাউন না হয়ে যায় — শুধু লগ করে চালিয়ে যাওয়া
            try {
                $pdo->exec("ALTER TABLE sms_settings ADD COLUMN $col $def");
            } catch (PDOException $e) {
                error_log('sms_ensure_schema: failed to add column ' . $col . ' — ' . $e->getMessage());
            }
        }
    }
    // পুরনো ইনস্টলেশনে sms_balance/sms_buy ছোট INT হিসেবে তৈরি হয়ে থাকতে পারে —
    // গেটওয়ে রেসপন্সে বড় সংখ্যা (যেমন টোকেনের সংখ্যা ভুলবশত ধরা পড়লে) এলে
    // "Out of range" এরর এড়াতে BIGINT-এ আপগ্রেড করে দেওয়া হচ্ছে
    if (in_array('sms_balance', $existing, true) || in_array('sms_buy', $existing, true)) {
        try { $pdo->exec("ALTER TABLE sms_settings MODIFY COLUMN sms_balance BIGINT NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
        try { $pdo->exec("ALTER TABLE sms_settings MODIFY COLUMN sms_buy BIGINT NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
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

/**
 * গেটওয়ের নিজস্ব "Balance Check" API কল করে বর্তমান SMS ব্যালেন্স বের করা।
 * (এটা sending API থেকে আলাদা — SMS Settings > SMS Gateway Settings ট্যাবে
 * "Balance Check API URL" ফিল্ডে বসাতে হবে, {token}/{user} প্লেসহোল্ডার সাপোর্ট করে)
 *
 * GreenWeb-এর &balance আসলে টাকার ব্যালেন্স দেয়, SMS সংখ্যা না — তাই URL-এ
 * &rate ও দেওয়া থাকলে (প্রতি SMS-এর দাম) টাকা ÷ রেট করে বাকি SMS সংখ্যা বের
 * করা হয়। শুধু &balance দেওয়া থাকলে টাকার অঙ্কটাই sms_balance-এ সেভ হয়।
 * সফল হলে sms_settings টেবিলে ক্যাশ করে রাখে।
 *
 * রিটার্ন করে ['success' => bool, 'balance' => int|null, 'response' => string]
 */
function sms_check_balance(PDO $pdo): array
{
    sms_ensure_schema($pdo);
    $row = $pdo->query("SELECT api_token, api_user, api_sender_id, balance_api_url FROM sms_settings WHERE id = 1")->fetch();
    $url = trim($row['balance_api_url'] ?? '');
    if ($url === '') {
        return ['success' => false, 'balance' => null, 'response' => 'Balance Check API URL সেট করা নেই — SMS Settings > SMS Gateway Settings-এ বসান।'];
    }

    $final_url = strtr($url, [
        '{token}'     => rawurlencode($row['api_token'] ?? ''),
        '{user}'      => rawurlencode($row['api_user'] ?? ''),
        '{sender_id}' => rawurlencode($row['api_sender_id'] ?? ''),
    ]);

    $ch = curl_init($final_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['success' => false, 'balance' => null, 'response' => $err ?: 'Balance API রেসপন্স করেনি।'];
    }

    $decoded = json_decode($resp, true);

    // GreenWeb "everything together" স্টাইলে একাধিক {"action":"balance","response":"111.37"}
    // অবজেক্টের লিস্ট রিটার্ন করে — সেগুলোকে action => value ম্যাপে নরমালাইজ করা
    $map = [];
    if (is_array($decoded)) {
        $items = (isset($decoded[0]) && is_array($decoded[0])) ? $decoded : [$decoded];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            if (isset($item['status']) && strtoupper((string) $item['status']) === 'FAILED') {
                $msg = $item['statusmsg'] ?? $item['status'];
                return ['success' => false, 'balance' => null, 'response' => 'গেটওয়ে এরর দিয়েছেঃ ' . $msg . ' — Token ফিল্ডে অতিরিক্ত { } ব্র্যাকেট বা স্পেস আছে কিনা চেক করুন।'];
            }
            if (isset($item['action']) && isset($item['response'])) {
                $map[strtolower((string) $item['action'])] = $item['response'];
            }
            foreach (['balance', 'rate', 'credit', 'remaining', 'sms_balance'] as $key) {
                if (isset($item[$key])) {
                    $map[$key] = $item[$key];
                }
            }
        }
    }

    $balance_taka = null;
    foreach (['balance', 'credit', 'remaining', 'sms_balance'] as $key) {
        if (isset($map[$key]) && is_numeric($map[$key])) {
            $balance_taka = (float) $map[$key];
            break;
        }
    }
    $rate = isset($map['rate']) && is_numeric($map['rate']) ? (float) $map['rate'] : null;

    // JSON-এ কিছু না মিললে টেক্সট থেকে চেষ্টা (fallback)
    if ($balance_taka === null && preg_match('/balance["\']?\s*[:=]\s*["\']?(-?\d+(\.\d+)?)/i', (string) $resp, $m)) {
        $balance_taka = (float) $m[1];
    }
    if ($balance_taka === null && preg_match('/^\s*-?\d+(\.\d+)?\s*$/', (string) $resp)) {
        $balance_taka = (float) trim((string) $resp);
    }

    if ($balance_taka === null) {
        return ['success' => false, 'balance' => null, 'response' => 'রেসপন্স থেকে ব্যালেন্স সংখ্যা বোঝা যায়নি — রেসপন্সঃ ' . $resp];
    }
    if ($balance_taka < -1000000 || $balance_taka > 100000000) {
        return ['success' => false, 'balance' => null, 'response' => 'রেসপন্স থেকে পাওয়া মান বাস্তবসম্মত মনে হচ্ছে না — রেসপন্সঃ ' . $resp];
    }

    // রেট পাওয়া গেলে টাকা ÷ রেট = বাকি SMS সংখ্যা, নাহলে টাকার অঙ্কটাই sms_balance-এ রাখা
    $sms_count = ($rate !== null && $rate > 0) ? (int) floor($balance_taka / $rate) : (int) round($balance_taka);

    $pdo->prepare("UPDATE sms_settings SET sms_balance = ?, sms_balance_taka = ?, balance_checked_at = NOW() WHERE id = 1")
        ->execute([$sms_count, $balance_taka]);

    $summary = $rate !== null
        ? "টাকা ব্যালেন্সঃ {$balance_taka}, রেটঃ {$rate}/SMS → আনুমানিক {$sms_count} SMS বাকি"
        : "টাকা ব্যালেন্সঃ {$balance_taka} (রেট পাওয়া যায়নি, তাই SMS সংখ্যা হিসাব করা যায়নি — URL-এ &rate যোগ করুন)";

    return ['success' => true, 'balance' => $sms_count, 'response' => $summary];
}
