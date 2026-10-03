<?php
// config/live_class_100ms.php - E-Learning Module > Live Class (100ms integration)
// -----------------------------------------------------------------
// 100ms এ Management Token নিজেরাই JWT বানিয়ে সাইন করতে হয় (HS256) — এখানে সেটাই আছে।
// শুধু 100ms রাখা হয়েছে (Zoom/Daily/Agora না) কারণ এটাই প্রমাণিতভাবে কাজ করেছে —
// ভবিষ্যতে অন্য প্রোভাইডার লাগলে hms_create_room()/hms_generate_token() এর পাশে
// নতুন provider ফাংশন বসিয়ে lc_create_room()/lc_generate_token() ডিসপ্যাচারে যোগ করা যাবে।

if (!function_exists('lc_jwt_base64url')) {
    function lc_jwt_base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
if (!function_exists('lc_jwt_encode_hs256')) {
    function lc_jwt_encode_hs256(array $header, array $payload, string $secret): string
    {
        $segments = [lc_jwt_base64url(json_encode($header)), lc_jwt_base64url(json_encode($payload))];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[] = lc_jwt_base64url($signature);
        return implode('.', $segments);
    }
}
if (!function_exists('lc_http_request')) {
    function lc_http_request(string $method, string $url, array $headers = [], $body = null): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body);
        } elseif ($method === 'POST') {
            $opts[CURLOPT_POSTFIELDS] = '';
        }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false) { return ['ok' => false, 'error' => 'নেটওয়ার্ক এরর (cURL): ' . $err]; }
        $data = json_decode($res, true);
        return ['ok' => true, 'http_code' => $http_code, 'data' => is_array($data) ? $data : ['raw' => $res]];
    }
}

/** settings টেবিল (id=1 fixed row) না থাকলে অটো তৈরি করে দেয় */
if (!function_exists('lc_ensure_settings_table')) {
    function lc_ensure_settings_table(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS elearning_live_settings (
                id INT PRIMARY KEY DEFAULT 1,
                hms_access_key TEXT NULL,
                hms_secret TEXT NULL,
                hms_template_id VARCHAR(150) NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec("INSERT IGNORE INTO elearning_live_settings (id) VALUES (1)");
    }
}
if (!function_exists('lc_get_settings')) {
    function lc_get_settings(PDO $pdo): array
    {
        lc_ensure_settings_table($pdo);
        return $pdo->query("SELECT * FROM elearning_live_settings WHERE id = 1")->fetch() ?: [];
    }
}

/** ওয়েবহুক ভেরিফাই করার জন্য প্রতিটা স্কুলের নিজস্ব সিক্রেট টোকেন (একবার তৈরি হয়ে থাকে) */
if (!function_exists('lc_ensure_webhook_secret')) {
    function lc_ensure_webhook_secret(PDO $pdo): string
    {
        lc_ensure_settings_table($pdo);
        $chk = $pdo->query("SHOW COLUMNS FROM elearning_live_settings LIKE 'hms_webhook_secret'")->fetch();
        if (!$chk) { $pdo->exec("ALTER TABLE elearning_live_settings ADD COLUMN hms_webhook_secret VARCHAR(64) NULL"); }

        $existing = $pdo->query("SELECT hms_webhook_secret FROM elearning_live_settings WHERE id = 1")->fetchColumn();
        if ($existing) { return $existing; }

        $secret = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE elearning_live_settings SET hms_webhook_secret = ? WHERE id = 1")->execute([$secret]);
        return $secret;
    }
}

/* ============================================================
   100ms রেকর্ডিং কন্ট্রোল (হোস্ট পেজ থেকে শুরু/বন্ধ করার জন্য)
   ============================================================ */
if (!function_exists('lc_start_recording')) {
    function lc_start_recording(PDO $pdo, string $room_id): array
    {
        $settings = lc_get_settings($pdo);
        $token = lc_management_token($settings);
        if (!$token) { return ['ok' => false, 'error' => '100ms Access Key/Secret সেট নেই।']; }
        $r = lc_http_request('POST', "https://api.100ms.live/v2/recordings/room/{$room_id}/start",
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            ['resolution' => ['width' => 1280, 'height' => 720]]);
        if (!$r['ok']) return $r;
        if (($r['http_code'] ?? 0) >= 300) { return ['ok' => false, 'error' => $r['data']['message'] ?? 'রেকর্ডিং শুরু করা যায়নি।']; }
        return ['ok' => true];
    }
}
if (!function_exists('lc_stop_recording')) {
    function lc_stop_recording(PDO $pdo, string $room_id): array
    {
        $settings = lc_get_settings($pdo);
        $token = lc_management_token($settings);
        if (!$token) { return ['ok' => false, 'error' => '100ms Access Key/Secret সেট নেই।']; }
        $r = lc_http_request('POST', "https://api.100ms.live/v2/recordings/room/{$room_id}/stop",
            ['Authorization: Bearer ' . $token], null);
        if (!$r['ok']) return $r;
        if (($r['http_code'] ?? 0) >= 300) { return ['ok' => false, 'error' => $r['data']['message'] ?? 'রেকর্ডিং বন্ধ করা যায়নি।']; }
        return ['ok' => true];
    }
}

/** recording_jobs টেবিল — webhook থেকে জমা হয়, cron script প্রসেস করে */
if (!function_exists('lc_ensure_recording_jobs_table')) {
    function lc_ensure_recording_jobs_table(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS elearning_recording_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                live_class_id INT NULL,
                room_id VARCHAR(255) NULL,
                recording_url TEXT NULL,
                status ENUM('pending','downloading','uploading','completed','failed') NOT NULL DEFAULT 'pending',
                youtube_video_id VARCHAR(50) NULL,
                error VARCHAR(500) NULL,
                attempts INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

if (!function_exists('lc_management_token')) {
    function lc_management_token(array $settings): ?string
    {
        $access_key = trim($settings['hms_access_key'] ?? '');
        $secret = trim($settings['hms_secret'] ?? '');
        if ($access_key === '' || $secret === '') { return null; }

        $now = time();
        $payload = [
            'access_key' => $access_key,
            'type'       => 'management',
            'version'    => 2,
            'iat'        => $now,
            'nbf'        => $now,
            'exp'        => $now + 60 * 60 * 2,
            'jti'        => bin2hex(random_bytes(12)),
        ];
        return lc_jwt_encode_hs256(['alg' => 'HS256', 'typ' => 'JWT'], $payload, $secret);
    }
}

/** নতুন ক্লাস সেভ হওয়ার সময় কল হবে — 100ms এ রুম তৈরি করে room_id রিটার্ন করে */
if (!function_exists('lc_create_room')) {
    function lc_create_room(PDO $pdo, array $class): array
    {
        $settings = lc_get_settings($pdo);
        $token = lc_management_token($settings);
        if (!$token) {
            return ['ok' => false, 'error' => '100ms Access Key/Secret সেট করা নেই। Settings → Live Class Setting এ গিয়ে দিন।'];
        }
        $body = [
            'name'        => 'class-' . ($class['id'] ?? 'new') . '-' . bin2hex(random_bytes(3)),
            'description' => mb_substr($class['title'] ?? 'Live Class', 0, 190),
        ];
        if (!empty($settings['hms_template_id'])) { $body['template_id'] = trim($settings['hms_template_id']); }

        $r = lc_http_request('POST', 'https://api.100ms.live/v2/rooms',
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], $body);
        if (!$r['ok']) { return $r; }
        if (($r['http_code'] ?? 0) >= 300 || empty($r['data']['id'])) {
            return ['ok' => false, 'error' => '100ms room তৈরি ব্যর্থ: ' . ($r['data']['message'] ?? json_encode($r['data']))];
        }
        return ['ok' => true, 'room_id' => $r['data']['id']];
    }
}

/** কেউ (Host/Guest) জয়েন করার সময় কল হবে — auth token জেনারেট করে
 *  $role => 'host' | 'guest' (100ms Template-এ এই দুইটা রোল আগে থেকে বানানো থাকতে হবে) */
if (!function_exists('lc_generate_token')) {
    function lc_generate_token(PDO $pdo, array $class, string $user_name, string $role): array
    {
        if (empty($class['room_id'])) {
            return ['ok' => false, 'error' => 'এই ক্লাসের জন্য এখনো কোনো রুম তৈরি হয়নি।'];
        }
        $settings = lc_get_settings($pdo);
        $access_key = trim($settings['hms_access_key'] ?? '');
        $secret = trim($settings['hms_secret'] ?? '');
        if ($access_key === '' || $secret === '') {
            return ['ok' => false, 'error' => '100ms Access Key/Secret সেট নেই।'];
        }
        $now = time();
        $payload = [
            'access_key' => $access_key,
            'room_id'    => $class['room_id'],
            'user_id'    => 'u-' . substr(md5($user_name . microtime()), 0, 12),
            'role'       => $role === 'host' ? 'host' : 'guest',
            'type'       => 'app',
            'version'    => 2,
            'iat'        => $now,
            'nbf'        => $now,
            'exp'        => $now + 60 * 60 * 4,
            'jti'        => bin2hex(random_bytes(12)),
        ];
        $token = lc_jwt_encode_hs256(['alg' => 'HS256', 'typ' => 'JWT'], $payload, $secret);
        return ['ok' => true, 'token' => $token, 'user_name' => $user_name];
    }
}
