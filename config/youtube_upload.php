<?php
// config/youtube_upload.php - Live Class রেকর্ডিং অটো YouTube (Unlisted) আপলোড
// -----------------------------------------------------------------
// একবার কোনো স্কুল তাদের YouTube চ্যানেল Connect করলে refresh_token তাদের নিজের
// DB-তে সেভ থাকে, তারপর থেকে cron script ব্যাকগ্রাউন্ডে অটো আপলোড করতে থাকবে।
//
// ⚠️ এই তিনটা কনস্ট্যান্ট একবারই বসাতে হবে (পুরো SaaS-এর জন্য একটাই — প্রতি স্কুলের
// জন্য আলাদা লাগবে না, প্রতিটা স্কুল শুধু নিজের চ্যানেল Authorize করবে):
//   1) Google Cloud Console-এ একটা OAuth Client (Web application) বানিয়ে Client ID/Secret নিন
//   2) সেই Client-এর "Authorized redirect URIs"-এ ঠিক এই একটা URL যোগ করুন:
//        https://<আপনার-মূল-ডোমেইন>/youtube_oauth_callback.php
//      (উদাহরণ: https://yourdomain.com/youtube_oauth_callback.php — নিচের REDIRECT_URI-তেও একই বসান)
//   3) YT_OAUTH_STATE_SECRET-এ যেকোনো লম্বা র‍্যান্ডম স্ট্রিং বসান (এটা কারো সাথে শেয়ার করবেন না)
if (!defined('YT_OAUTH_CLIENT_ID'))     { define('YT_OAUTH_CLIENT_ID', 'YOUR_GOOGLE_OAUTH_CLIENT_ID'); }
if (!defined('YT_OAUTH_CLIENT_SECRET')) { define('YT_OAUTH_CLIENT_SECRET', 'YOUR_GOOGLE_OAUTH_CLIENT_SECRET'); }
if (!defined('YT_OAUTH_REDIRECT_URI'))  { define('YT_OAUTH_REDIRECT_URI', 'https://yourdomain.com/youtube_oauth_callback.php'); }
if (!defined('YT_OAUTH_STATE_SECRET'))  { define('YT_OAUTH_STATE_SECRET', 'CHANGE_THIS_TO_A_LONG_RANDOM_STRING'); }

if (!function_exists('yt_ensure_settings_columns')) {
    /** elearning_live_settings টেবিলে YouTube সংক্রান্ত কলাম self-healing ভাবে যোগ করে */
    function yt_ensure_settings_columns(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        lc_ensure_settings_table($pdo); // config/live_class_100ms.php থেকে
        foreach ([
            'youtube_refresh_token'  => 'TEXT NULL',
            'youtube_channel_title'  => 'VARCHAR(150) NULL',
            'youtube_oauth_pending'  => 'VARCHAR(64) NULL', // চলমান OAuth রিকোয়েস্টের নন্স (replay ঠেকাতে)
        ] as $col => $def) {
            $chk = $pdo->query("SHOW COLUMNS FROM elearning_live_settings LIKE " . $pdo->quote($col))->fetch();
            if (!$chk) { $pdo->exec("ALTER TABLE elearning_live_settings ADD COLUMN `$col` $def"); }
        }
    }
}

if (!function_exists('yt_is_connected')) {
    function yt_is_connected(array $settings): bool
    {
        return !empty($settings['youtube_refresh_token']);
    }
}

/* ============================================================
   OAuth state — school_code সহ HMAC-সাইন করা টোকেন, যাতে callback বুঝতে পারে
   কোন স্কুল থেকে অনুরোধ এসেছিল (কোনো DB লুকআপ ছাড়াই যাচাই করা যায়)
   ============================================================ */
if (!function_exists('yt_oauth_make_state')) {
    function yt_oauth_make_state(string $school_code): string
    {
        $payload = $school_code . '|' . time() . '|' . bin2hex(random_bytes(6));
        $sig = hash_hmac('sha256', $payload, YT_OAUTH_STATE_SECRET);
        return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
    }
}
if (!function_exists('yt_oauth_verify_state')) {
    /** ভ্যালিড হলে school_code রিটার্ন করে, নাহলে null (মেয়াদ ১০ মিনিট) */
    function yt_oauth_verify_state(string $state): ?string
    {
        $decoded = base64_decode(strtr($state, '-_', '+/'), true);
        if (!$decoded) return null;
        $parts = explode('|', $decoded);
        if (count($parts) !== 4) return null;
        [$school_code, $ts, $nonce, $sig] = $parts;
        $expected = hash_hmac('sha256', $school_code . '|' . $ts . '|' . $nonce, YT_OAUTH_STATE_SECRET);
        if (!hash_equals($expected, $sig)) return null;
        if (time() - (int) $ts > 600) return null;
        return $school_code;
    }
}

if (!function_exists('yt_oauth_consent_url')) {
    function yt_oauth_consent_url(string $state): string
    {
        $params = [
            'client_id'     => YT_OAUTH_CLIENT_ID,
            'redirect_uri'  => YT_OAUTH_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/youtube.upload',
            'access_type'   => 'offline',
            'prompt'        => 'consent', // refresh_token নিশ্চিত পাওয়ার জন্য
            'state'         => $state,
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }
}
if (!function_exists('yt_oauth_exchange_code')) {
    function yt_oauth_exchange_code(string $code): array
    {
        $r = lc_http_request('POST', 'https://oauth2.googleapis.com/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'code'          => $code,
                'client_id'     => YT_OAUTH_CLIENT_ID,
                'client_secret' => YT_OAUTH_CLIENT_SECRET,
                'redirect_uri'  => YT_OAUTH_REDIRECT_URI,
                'grant_type'    => 'authorization_code',
            ]));
        if (!$r['ok']) return $r;
        if (empty($r['data']['refresh_token'])) {
            return ['ok' => false, 'error' => $r['data']['error_description'] ?? 'refresh_token পাওয়া যায়নি — আগে থেকে কানেক্ট করা থাকলে Google Account থেকে অ্যাক্সেস রিভোক করে আবার চেষ্টা করুন।'];
        }
        return ['ok' => true, 'refresh_token' => $r['data']['refresh_token']];
    }
}
if (!function_exists('yt_access_token')) {
    function yt_access_token(array $settings): ?string
    {
        if (empty($settings['youtube_refresh_token'])) return null;
        $r = lc_http_request('POST', 'https://oauth2.googleapis.com/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'refresh_token' => $settings['youtube_refresh_token'],
                'client_id'     => YT_OAUTH_CLIENT_ID,
                'client_secret' => YT_OAUTH_CLIENT_SECRET,
                'grant_type'    => 'refresh_token',
            ]));
        if (!$r['ok'] || empty($r['data']['access_token'])) return null;
        return $r['data']['access_token'];
    }
}
if (!function_exists('yt_fetch_channel_title')) {
    function yt_fetch_channel_title(string $access_token): ?string
    {
        $r = lc_http_request('GET', 'https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true',
            ['Authorization: Bearer ' . $access_token]);
        if (!$r['ok']) return null;
        return $r['data']['items'][0]['snippet']['title'] ?? null;
    }
}

/**
 * লোকাল ফাইল থেকে YouTube-এ resumable upload করে (Unlisted হিসেবে)।
 * শেয়ার্ড হোস্টিং-এ সহজ রাখতে পুরো ফাইলটা একবারেই PUT করা হচ্ছে (chunked না) —
 * এটা cron script থেকে চলে বলে টাইমআউট বন্ধ রাখা হয়েছে, কোনো সমস্যা হওয়ার কথা না।
 */
if (!function_exists('yt_upload_video')) {
    function yt_upload_video(array $settings, string $file_path, string $title, string $description): array
    {
        $access_token = yt_access_token($settings);
        if (!$access_token) { return ['ok' => false, 'error' => 'YouTube চ্যানেল কানেক্ট করা নেই বা টোকেন রিফ্রেশ করা যায়নি।']; }
        if (!is_file($file_path)) { return ['ok' => false, 'error' => 'ভিডিও ফাইল পাওয়া যায়নি: ' . $file_path]; }

        $metadata = [
            'snippet' => ['title' => mb_substr($title, 0, 95), 'description' => mb_substr($description, 0, 4900)],
            'status'  => ['privacyStatus' => 'unlisted'],
        ];

        $ch = curl_init('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/json; charset=UTF-8',
                'X-Upload-Content-Type: video/mp4',
                'X-Upload-Content-Length: ' . filesize($file_path),
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $init_res = curl_exec($ch);
        $init_info = curl_getinfo($ch);
        curl_close($ch);

        if ($init_res === false || $init_info['http_code'] >= 300) {
            return ['ok' => false, 'error' => 'YouTube upload session শুরু করা যায়নি (HTTP ' . ($init_info['http_code'] ?? 0) . ')।'];
        }
        if (!preg_match('/^Location:\s*(\S+)/mi', $init_res, $m)) {
            return ['ok' => false, 'error' => 'YouTube থেকে upload URL পাওয়া যায়নি।'];
        }
        $upload_url = trim($m[1]);

        $ch2 = curl_init($upload_url);
        $fh = fopen($file_path, 'r');
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => filesize($file_path),
            CURLOPT_HTTPHEADER     => ['Content-Type: video/mp4'],
            CURLOPT_TIMEOUT        => 0,
        ]);
        $upload_res = curl_exec($ch2);
        $upload_info = curl_getinfo($ch2);
        curl_close($ch2);
        fclose($fh);

        if ($upload_res === false || $upload_info['http_code'] >= 300) {
            return ['ok' => false, 'error' => 'YouTube-এ ভিডিও আপলোড ব্যর্থ (HTTP ' . ($upload_info['http_code'] ?? 0) . ')।'];
        }
        $data = json_decode($upload_res, true);
        if (empty($data['id'])) { return ['ok' => false, 'error' => 'আপলোড শেষ হলেও ভিডিও আইডি পাওয়া যায়নি।']; }
        return ['ok' => true, 'video_id' => $data['id']];
    }
}
