<?php
/**
 * config/security_helpers.php
 * -----------------------------------------------------------
 * "System & Security" মডিউলের শেয়ার্ড টেবিল/হেল্পার — Active Sessions (কে এখন লগইন
 * অবস্থায় আছে, চাইলে Force Logout করা), Session Timeout Settings (কতক্ষণ নিষ্ক্রিয়
 * থাকলে অটো লগআউট হবে), এবং Activity Log (কে কী করলো তার সংক্ষিপ্ত ইতিহাস)।
 *
 * ডিজাইন নোট: প্রতিটা Login-এ (admin/index.php) একটা active_sessions রো তৈরি হয়, PHP-র
 * নিজের session_id() কে "session_key" হিসেবে ব্যবহার করে। প্রতিটা অথেন্টিকেটেড পেজ লোডে
 * sec_enforce_session() কল হয় (admin/index.php-তে, লগইন চেক করার ঠিক পরে) — এটাই চেক করে
 * এই সেশনটা কেউ Force Logout করে দিয়েছে কিনা, বা Idle Timeout পার হয়ে গেছে কিনা।
 * সত্যিকারের সার্ভার-সাইড PHP session ডেস্ট্রয় করাটা admin/index.php-তেই হয় (এখানে না),
 * কারণ সেটার জন্য session_destroy() + redirect লাগে যা bootstrap ফাইলেই সবচেয়ে
 * স্বাভাবিক জায়গা।
 *
 * এই ফাইলটা login_logs টেবিল/ফাংশন (ix_log_login, ix_client_ip, ix_parse_user_agent —
 * admin/index.php-তে সংজ্ঞায়িত) থেকে সম্পূর্ণ স্বাধীন রাখা হয়েছে, যাতে এই ফাইলটা যেকোনো
 * পেজ থেকে একা require_once করলেও (ix_* ফাংশনগুলো ছাড়াই) কাজ করে।
 */

if (!function_exists('sec_ensure_tables')) {
    function sec_ensure_tables(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS active_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_key VARCHAR(64) NOT NULL,
                username VARCHAR(100) NOT NULL,
                user_type VARCHAR(30) NOT NULL DEFAULT 'Unknown',
                ip_address VARCHAR(45) NULL,
                device_type VARCHAR(20) NULL,
                browser VARCHAR(50) NULL,
                os VARCHAR(50) NULL,
                login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                status VARCHAR(20) NOT NULL DEFAULT 'Active',
                UNIQUE KEY uniq_session_key (session_key),
                KEY idx_username (username), KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS security_settings (
                id INT PRIMARY KEY DEFAULT 1,
                session_timeout_minutes INT NOT NULL DEFAULT 60,
                force_single_session TINYINT(1) NOT NULL DEFAULT 0,
                updated_by VARCHAR(100) NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS activity_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) NOT NULL DEFAULT 'System',
                user_type VARCHAR(30) NULL,
                module VARCHAR(60) NOT NULL,
                action VARCHAR(30) NOT NULL,
                description VARCHAR(255) NULL,
                ip_address VARCHAR(45) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_username (username), KEY idx_module (module),
                KEY idx_action (action), KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

/** ইউজারের আসল IP বের করা — admin/index.php-এর ix_client_ip()-এর মতোই, কিন্তু এই ফাইল
 *  স্বাধীনভাবে ব্যবহার করা যায় বলে এখানে আলাদাভাবে রাখা হয়েছে */
if (!function_exists('sec_client_ip')) {
    function sec_client_ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $val = $_SERVER[$key];
                if ($key === 'HTTP_X_FORWARDED_FOR') { $val = trim(explode(',', $val)[0]); }
                if (filter_var($val, FILTER_VALIDATE_IP)) { return $val; }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }
}

/** User-Agent থেকে সহজ Device/Browser/OS বের করা */
if (!function_exists('sec_parse_user_agent')) {
    function sec_parse_user_agent(string $ua): array
    {
        $device = 'Desktop';
        if (preg_match('/Tablet|iPad/i', $ua)) { $device = 'Tablet'; }
        elseif (preg_match('/Mobile|Android|iPhone/i', $ua)) { $device = 'Mobile'; }

        $browser = 'Unknown';
        if (preg_match('/Edg\//i', $ua)) { $browser = 'Edge'; }
        elseif (preg_match('/OPR\//i', $ua)) { $browser = 'Opera'; }
        elseif (preg_match('/Chrome\//i', $ua) && !preg_match('/Chromium/i', $ua)) { $browser = 'Chrome'; }
        elseif (preg_match('/Firefox\//i', $ua)) { $browser = 'Firefox'; }
        elseif (preg_match('/Safari\//i', $ua) && !preg_match('/Chrome/i', $ua)) { $browser = 'Safari'; }

        $os = 'Unknown';
        if (preg_match('/Windows/i', $ua)) { $os = 'Windows'; }
        elseif (preg_match('/Android/i', $ua)) { $os = 'Android'; }
        elseif (preg_match('/iPhone|iPad|iOS/i', $ua)) { $os = 'iOS'; }
        elseif (preg_match('/Mac OS X/i', $ua)) { $os = 'macOS'; }
        elseif (preg_match('/Linux/i', $ua)) { $os = 'Linux'; }

        return [$device, $browser, $os];
    }
}

/** Session Timeout Settings রো (id=1, না থাকলে ডিফল্ট দিয়ে তৈরি করে) */
if (!function_exists('sec_get_settings')) {
    function sec_get_settings(PDO $pdo): array
    {
        sec_ensure_tables($pdo);
        $row = $pdo->query("SELECT * FROM security_settings WHERE id = 1")->fetch();
        if (!$row) {
            $pdo->exec("INSERT INTO security_settings (id, session_timeout_minutes, force_single_session) VALUES (1, 60, 0)");
            $row = ['id' => 1, 'session_timeout_minutes' => 60, 'force_single_session' => 0, 'updated_by' => null, 'updated_at' => null];
        }
        return $row;
    }
}

/** একটা Activity Log এন্ট্রি লেখা — $module যেমন 'Auth'/'User Management'/'Security', $action যেমন 'Login'/'Create'/'Update'/'Delete' */
if (!function_exists('sec_log_activity')) {
    function sec_log_activity(PDO $pdo, string $module, string $action, string $description = ''): void
    {
        sec_ensure_tables($pdo);
        $username = $_SESSION['admin_user'] ?? 'System';
        $user_type = $_SESSION['user_role'] ?? null;
        $pdo->prepare(
            "INSERT INTO activity_logs (username, user_type, module, action, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$username, $user_type, $module, $action, $description ?: null, sec_client_ip()]);
    }
}

/** কারেন্ট PHP session-এর জন্য ইউনিক কী — session_regenerate_id(true) কল হওয়ার পরের
 *  session_id()-ই আসল, তাই sec_start_session() সবসময় সেটার পরে কল করতে হবে */
if (!function_exists('sec_session_key')) {
    function sec_session_key(): string
    {
        return session_id() ?: 'unknown-session';
    }
}

/** সফল লগইনের পরে কল হয় (admin/index.php) — এই সেশনকে active_sessions-এ Active হিসেবে রেকর্ড করে,
 *  এবং "Force Single Session" চালু থাকলে এই ইউজারের আগের সব সেশন জোরপূর্বক লগআউট করে দেয়। */
if (!function_exists('sec_start_session')) {
    function sec_start_session(PDO $pdo, string $username, string $user_type): void
    {
        sec_ensure_tables($pdo);
        $settings = sec_get_settings($pdo);

        if (!empty($settings['force_single_session'])) {
            $pdo->prepare("UPDATE active_sessions SET status = 'ForceLogout' WHERE username = ? AND status = 'Active'")->execute([$username]);
        }

        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        [$device, $browser, $os] = sec_parse_user_agent($ua);
        $pdo->prepare(
            "INSERT INTO active_sessions (session_key, username, user_type, ip_address, device_type, browser, os, status, login_time, last_activity)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE username = VALUES(username), user_type = VALUES(user_type), ip_address = VALUES(ip_address),
                 device_type = VALUES(device_type), browser = VALUES(browser), os = VALUES(os), status = 'Active',
                 login_time = CURRENT_TIMESTAMP, last_activity = CURRENT_TIMESTAMP"
        )->execute([sec_session_key(), $username, $user_type, sec_client_ip(), $device, $browser, $os]);

        sec_log_activity($pdo, 'Auth', 'Login', $username . ' লগইন করেছেন (' . $user_type . ')');
    }
}

/** Logout Handler-এ কল হয় — এই সেশনকে LoggedOut মার্ক করে */
if (!function_exists('sec_end_session')) {
    function sec_end_session(PDO $pdo): void
    {
        sec_ensure_tables($pdo);
        $username = $_SESSION['admin_user'] ?? null;
        $pdo->prepare("UPDATE active_sessions SET status = 'LoggedOut' WHERE session_key = ? AND status = 'Active'")->execute([sec_session_key()]);
        if ($username) {
            sec_log_activity($pdo, 'Auth', 'Logout', $username . ' লগআউট করেছেন');
        }
    }
}

/**
 * প্রতিটা অথেন্টিকেটেড পেজ লোডে (admin/index.php-তে লগইন চেক করার ঠিক পরেই) কল হয়।
 * রিটার্ন করে: null (সব ঠিক আছে, last_activity আপডেট হয়ে গেছে), 'force_logout'
 * (এডমিন এই সেশনটা জোরপূর্বক লগআউট করে দিয়েছে), বা 'timeout' (নির্ধারিত সময়ের বেশি
 * নিষ্ক্রিয় থাকায় সেশন Expire হয়ে গেছে)। null ছাড়া অন্য কিছু রিটার্ন করলে কলার
 * (admin/index.php) আসল PHP session ডেস্ট্রয় করে লগইন পেজে রিডাইরেক্ট করবে।
 */
if (!function_exists('sec_enforce_session')) {
    function sec_enforce_session(PDO $pdo): ?string
    {
        sec_ensure_tables($pdo);
        $key = sec_session_key();
        // NOTE: idle_seconds টা MySQL-এর নিজের TIMESTAMPDIFF() দিয়ে বের করা হয় (PHP-র time() +
        // strtotime() মিশিয়ে না) — কারণ শেয়ার্ড হোস্টিং-এ MySQL সার্ভারের timezone (SYSTEM/UTC)
        // আর PHP-র date_default_timezone_set('Asia/Dhaka') প্রায়ই আলাদা হয়। দুটো মিশিয়ে হিসাব
        // করলে (time() সবসময় UTC এপক, কিন্তু strtotime() naive string-কে PHP-র টাইমজোনে ধরে নেয়)
        // কয়েক ঘণ্টার একটা ভুল অফসেট চলে আসে, যার ফলে লগইনের ঠিক পরের রিকোয়েস্টেই সেশনটাকে
        // ভুলভাবে "Timeout" ধরে আবার লগইন পেজে ফিরিয়ে দেওয়া হচ্ছিল (fresh login-ও কাজ করছিল না)।
        // MySQL-এর NOW() আর last_activity একই কানেকশন/টাইমজোনে থাকে বলে TIMESTAMPDIFF() সবসময়
        // সঠিক থাকে, PHP আর MySQL সার্ভারের টাইমজোন যা-ই হোক না কেন।
        $stmt = $pdo->prepare(
            "SELECT *, TIMESTAMPDIFF(SECOND, last_activity, NOW()) AS idle_seconds
             FROM active_sessions WHERE session_key = ? LIMIT 1"
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if (!$row) {
            // এই ফিচার চালু হওয়ার আগে থেকে যারা লগইন করা আছে, তাদের কোনো active_sessions
            // রো নেই — জোর করে লগআউট না করে, এখনই একটা নতুন Active রো তৈরি করে দেওয়া হচ্ছে
            $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            [$device, $browser, $os] = sec_parse_user_agent($ua);
            $pdo->prepare(
                "INSERT INTO active_sessions (session_key, username, user_type, ip_address, device_type, browser, os, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')"
            )->execute([$key, $_SESSION['admin_user'] ?? 'Unknown', $_SESSION['user_role'] ?? 'Unknown', sec_client_ip(), $device, $browser, $os]);
            return null;
        }

        if ($row['status'] === 'ForceLogout') {
            return 'force_logout';
        }
        if ($row['status'] !== 'Active') {
            // LoggedOut/Expired হয়ে যাওয়া কোনো সেশন দিয়ে আবার রিকোয়েস্ট এলে (ব্রাউজার
            // ব্যাক বাটন ইত্যাদি) নিরাপদে সেটাকে timeout-এর মতোই ট্রিট করা হচ্ছে
            return 'timeout';
        }

        $settings = sec_get_settings($pdo);
        $timeout_minutes = (int) $settings['session_timeout_minutes'];
        if ($timeout_minutes > 0) {
            $idle_seconds = (int) $row['idle_seconds'];
            if ($idle_seconds < 0) { $idle_seconds = 0; } // ঘড়ি স্কিউ/রেস কন্ডিশনে যেন নেগেটিভ ভ্যালু কখনো ভুলভাবে সমস্যা না করে
            if ($idle_seconds > $timeout_minutes * 60) {
                $pdo->prepare("UPDATE active_sessions SET status = 'Expired' WHERE session_key = ?")->execute([$key]);
                return 'timeout';
            }
        }

        $pdo->prepare("UPDATE active_sessions SET last_activity = CURRENT_TIMESTAMP WHERE session_key = ?")->execute([$key]);
        return null;
    }
}
