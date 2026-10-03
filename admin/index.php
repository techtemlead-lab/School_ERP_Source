<?php
// admin/index.php - School ERP Admin Dashboard
require_once __DIR__ . '/../config/runtime.php';
erp_session_start();
ob_start(); // পুরো পেজ output buffer করা হচ্ছে, যাতে HTML প্রিন্ট শুরু হয়ে যাওয়ার পরও
            // ভেতরে include হওয়া পেজ ফাইল (institute.php, admissions.php, website.php)
            // থেকে header('Location: ...') redirect ঠিকভাবে কাজ করে ("headers already
            // sent" এরর ছাড়াই)
require_once __DIR__ . '/../config/db.php';   // $pdo (PDO) এখানে সেট হয়
require_once __DIR__.'/../config/auth_rate_limit.php';
if(isset($_POST['login']))erp_login_limit($pdo,'school-admin');
require_once __DIR__ . '/../config/csrf.php'; // csrf_token() / csrf_field() / csrf_verify()
require_once __DIR__ . '/../config/captcha.php'; // captcha_new() / captcha_verify() / captcha_svg_markup()
require_once __DIR__ . '/../config/branch_scope.php'; // branch_current_id() / branch_apply_filter() ইত্যাদি (Branch-wise ডাটা আলাদা করার হেল্পার)
require_once __DIR__ . '/../config/security_helpers.php'; // System & Security মডিউল — Active Sessions/Session Timeout/Activity Log
require_once __DIR__ . '/../config/permissions.php'; // User Permission ফিচার — perm_page_allowed()/perm_module_allowed()
branch_ensure_column($pdo, 'students'); // পুরনো ডাটাবেজে branch_id কলাম না থাকলে যোগ করা
branch_ensure_column($pdo, 'employees'); // employees টেবিল তৈরি হয়ে থাকলে branch_id কলাম নিশ্চিত করা
branch_ensure_column($pdo, 'notices'); // পাবলিক ওয়েবসাইটের নোটিশ কোন branch-এর তা ট্যাগ করার জন্য
branch_ensure_column($pdo, 'academic_sections'); // Section এখন থেকে branch-wise আলাদা
branch_ensure_column($pdo, 'academic_shifts'); // Shift এখন থেকে branch-wise আলাদা
branch_ensure_column($pdo, 'routines'); // পুরনো routines টেবিলে branch_id কলাম না থাকলে যোগ করা (routine_general/routine_view/routine_dynamic ব্যবহার করে)

// ব্রাউজার ট্যাবের favicon — institute_settings-এ favicon_path আলাদাভাবে আপলোড করা
// থাকলে সেটাই দেখাবে। আলাদা আপলোড না করা থাকলে কিছুই বসবে না (আগে Logo-কে
// fallback হিসেবে ব্যবহার করা হতো, এখন সেটা বন্ধ — ব্যবহারকারীর অনুরোধে)।
$_favicon_tag = '';
try {
    $fav_row = $pdo->query("SELECT favicon_path FROM institute_settings WHERE id = 1")->fetch();
    $fav_src = $fav_row['favicon_path'] ?? null;
    if (!empty($fav_src)) {
        $_favicon_tag = '<link rel="icon" href="../' . htmlspecialchars($fav_src, ENT_QUOTES, 'UTF-8') . '">';
    }
} catch (PDOException $e) {
    // institute_settings টেবিল/কলাম না থাকলে (খুবই পুরনো/আংশিক schema) চুপচাপ বাদ দাও
}

// system_users টেবিল (User Management > User List) — লগইন চেকের আগেই লাগে,
// তাই এখানে self-healing create করে নেওয়া হচ্ছে (users_list.php তেও একই CREATE আছে)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS system_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NULL,
        user_type VARCHAR(30) NOT NULL,
        username VARCHAR(100) NOT NULL UNIQUE,
        email VARCHAR(150) NULL,
        password VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        expires_at DATETIME NULL,
        last_login DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// পুরনো ডাটাবেজে (এই ফিচার আসার আগে তৈরি হওয়া) system_users টেবিলে expires_at
// কলাম না থাকলে যোগ করা হচ্ছে — Demo/Trial User Expiry ফিচারের জন্য দরকার
function ix_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $chk = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $chk->execute([$table, $column]);
    if (!$chk->fetch()) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
ix_ensure_column($pdo, 'system_users', 'expires_at', 'DATETIME NULL AFTER status');

// -----------------------------------------------------------
// Login / Session Log — কে, কখন, কোন Username দিয়ে, কোন ডিভাইস/ব্রাউজার/IP
// থেকে লগইন (বা লগইন চেষ্টা) করেছে তার হিস্ট্রি রাখা হয়
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS login_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL,
        user_type VARCHAR(30) NOT NULL,
        login_status VARCHAR(10) NOT NULL DEFAULT 'Success',
        ip_address VARCHAR(45) NULL,
        device_type VARCHAR(20) NULL,
        browser VARCHAR(50) NULL,
        os VARCHAR(50) NULL,
        user_agent VARCHAR(255) NULL,
        login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_username (username),
        INDEX idx_login_time (login_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// ইউজারের আসল IP বের করা (শেয়ার্ড হোস্টিং/প্রক্সির পেছনে থাকলেও চেষ্টা করে)
function ix_client_ip(): string
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

// User-Agent স্ট্রিং থেকে সহজ Device/Browser/OS বের করা (কোনো এক্সটার্নাল লাইব্রেরি ছাড়াই)
function ix_parse_user_agent(string $ua): array
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

// একটা লগইন (সফল/ব্যর্থ) login_logs টেবিলে রেকর্ড করা
function ix_log_login(PDO $pdo, string $username, string $user_type, string $status): void
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    [$device, $browser, $os] = ix_parse_user_agent($ua);
    $stmt = $pdo->prepare(
        "INSERT INTO login_logs (username, user_type, login_status, ip_address, device_type, browser, os, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$username, $user_type, $status, ix_client_ip(), $device, $browser, $os, substr($ua, 0, 255)]);
}

// সাধারণ লগইন ব্রুট-ফোর্স throttle (সহজ সংস্করণ)
if (empty($_SESSION['login_attempts'])) { $_SESSION['login_attempts'] = 0; }
if (empty($_SESSION['login_locked_until'])) { $_SESSION['login_locked_until'] = 0; }

// অন্তত একটা Branch তৈরি করা থাকলেই লগইন ফর্মে Branch সিলেক্ট দেখানো হবে (এক branch হলেও, যাতে
// শুরু থেকেই স্পষ্ট থাকে — Super Admin ছাড়া বাকি সবার জন্য সিলেকশন করা বাধ্যতামূলক, যদি না তার
// Username-এ User Management থেকে আগে থেকেই একটা নির্দিষ্ট Branch অ্যাসাইন করা থাকে)
$branch_login_list  = branch_list_active($pdo);
$branch_login_needed = !empty($branch_login_list);

// Check Login
if (!isset($_SESSION['admin_logged'])) {
    $login_error = '';
    // sec_enforce_session() (নিচে) কোনো সেশন Force Logout/Timeout করে দিলে এখানে সেই
    // বার্তা দেখানোর জন্য flash করা থাকে — একবার দেখিয়ে সাথে সাথেই মুছে ফেলা হচ্ছে
    if (!empty($_SESSION['login_kick_msg'])) {
        $login_kick_msg = $_SESSION['login_kick_msg'];
        unset($_SESSION['login_kick_msg']);
    } else {
        $login_kick_msg = '';
    }
    if (isset($_POST['login'])) {
        if (time() < $_SESSION['login_locked_until']) {
            $login_error = 'অনেকবার ভুল চেষ্টা করা হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।';
        } elseif (!csrf_verify($_POST['csrf_token'] ?? null)) {
            $login_error = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        } elseif (!captcha_verify('captcha_admin', $_POST['verification_code'] ?? null)) {
            $login_error = 'ভেরিফিকেশন কোড সঠিক নয়!';
        } else {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            // ধাপ ১: Super Admin (admin টেবিল — স্কুল তৈরির সময় SaaS প্যানেল থেকে
            // অটো তৈরি হওয়া একমাত্র Super Admin অ্যাকাউন্ট)
            $stmt = $pdo->prepare('SELECT * FROM admin WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $row = $stmt->fetch();

            // ধাপ ২: Super Admin না মিললে system_users (User Management > User List)
            // টেবিল থেকে Admin/Teacher/Student/Accountant/HR/Data Entry Operator/Demo চেক
            // (status/expiry এখানে ফিল্টার না করে সব রো আনা হচ্ছে, যাতে নিচে Inactive/Expired
            // এর জন্য আলাদা, স্পষ্ট মেসেজ দেখানো যায়)
            $su_row = null;
            if (!$row) {
                $su_stmt = $pdo->prepare("SELECT * FROM system_users WHERE username = ? LIMIT 1");
                $su_stmt->execute([$username]);
                $su_row = $su_stmt->fetch();
            }

            if ($row && password_verify($password, $row['password'])) {
                // Super Admin-কেও এখন Branch সিলেক্ট করে লগইন করতে হবে — username/password
                // সবসময় Main (একমাত্র) Super Admin অ্যাকাউন্টেরই, শুধু কোন branch-এ ঢুকছে
                // সেটা প্রতিবার সিলেক্ট করতে হবে।
                $sa_branch_error = '';
                $sa_active_branch_id = null;
                if ($branch_login_needed) {
                    $sa_posted_branch_id = (int) ($_POST['branch_id'] ?? 0);
                    $sa_valid_branch_ids = array_column($branch_login_list, 'id');
                    if ($sa_posted_branch_id <= 0 || !in_array($sa_posted_branch_id, $sa_valid_branch_ids, true)) {
                        $sa_branch_error = 'অনুগ্রহ করে আপনার Branch সিলেক্ট করুন।';
                    } else {
                        $sa_active_branch_id = $sa_posted_branch_id;
                    }
                }

                if ($sa_branch_error !== '') {
                    ix_log_login($pdo, $row['username'], 'Super Admin', 'Failed');
                    $login_error = $sa_branch_error;
                } else {
                    session_regenerate_id(true); // session fixation প্রতিরোধ
                    $_SESSION['admin_logged']    = true;
                    $_SESSION['admin_user']      = $row['username'];
                    $_SESSION['is_super_admin']  = true;
                    $_SESSION['user_role']       = 'Super Admin';
                    if ($sa_active_branch_id !== null) {
                        $_SESSION['active_branch_id'] = $sa_active_branch_id; // লগইনের সময় সিলেক্ট করা branch-এই লক
                    } else {
                        unset($_SESSION['active_branch_id']); // কোনো branch তৈরিই না থাকলে All Branch মোড
                    }
                    // SaaS: প্রতিটা স্কুলের নিজের DB থেকে institute_name রিড হয় (হার্ডকোড না)
                    $inst_row = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetch();
                    $_SESSION['institute_branch'] = $inst_row['institute_name'] ?? ($GLOBALS['tenant']['school_name'] ?? 'Your School Name');
                    $_SESSION['login_attempts'] = 0;
                    ix_log_login($pdo, $row['username'], 'Super Admin', 'Success');
                    sec_start_session($pdo, $row['username'], 'Super Admin');
                    header("Location: index.php");
                    exit();
                }
            }

            if ($su_row && password_verify($password, $su_row['password'])) {
                // Demo/Trial User Expiry চেক — মেয়াদ পার হয়ে গেলে পাসওয়ার্ড ঠিক থাকলেও লগইন করতে দেওয়া হবে না
                $is_expired = !empty($su_row['expires_at']) && strtotime($su_row['expires_at']) < time();
                if ($is_expired) {
                    ix_log_login($pdo, $su_row['username'], $su_row['user_type'], 'Failed');
                    $login_error = 'এই ইউজার অ্যাকাউন্টের মেয়াদ শেষ হয়ে গেছে। এক্সেস দরকার হলে Administrator-এর সাথে যোগাযোগ করুন।';
                } elseif ($su_row['status'] !== 'Active') {
                    ix_log_login($pdo, $su_row['username'], $su_row['user_type'], 'Failed');
                    $login_error = 'এই ইউজার অ্যাকাউন্টটি বর্তমানে নিষ্ক্রিয় (Inactive)।';
                } else {
                    // -----------------------------------------------------------
                    // Branch Lock — এই ইউজারকে নির্দিষ্ট একটা branch-এ session-level
                    // লক করে দেওয়া হচ্ছে, যাতে লগইন করার পর সে যেন শুধু নিজের
                    // branch-এর ডাটাই দেখতে/এডিট করতে পারে (users_list.php > Edit User
                    // থেকে অ্যাসাইন করা branch_id, সেটা না থাকলে লগইন ফর্মে নিজে সিলেক্ট করা branch)
                    $branch_login_error = '';
                    $resolved_branch_id = null;
                    if ($branch_login_needed) {
                        // এখন থেকে Branch সিলেক্ট করা বাধ্যতামূলক — user-এর একটা assigned
                        // branch_id থাকলেও লগইন ফর্মে সেই branch-ই সিলেক্ট করতে হবে, খালি রেখে
                        // বা অন্য branch সিলেক্ট করে লগইন করা যাবে না।
                        $posted_branch_id = (int) ($_POST['branch_id'] ?? 0);
                        $valid_branch_ids = array_column($branch_login_list, 'id');
                        if ($posted_branch_id <= 0 || !in_array($posted_branch_id, $valid_branch_ids, true)) {
                            $branch_login_error = 'অনুগ্রহ করে আপনার Branch সিলেক্ট করুন।';
                        } elseif (!empty($su_row['branch_id']) && (int) $su_row['branch_id'] !== $posted_branch_id) {
                            $branch_login_error = 'ভুল Branch সিলেক্ট করেছেন।';
                        } else {
                            $resolved_branch_id = !empty($su_row['branch_id']) ? (int) $su_row['branch_id'] : $posted_branch_id;
                        }
                    } elseif (!empty($su_row['branch_id'])) {
                        $resolved_branch_id = (int) $su_row['branch_id'];
                    }

                    if ($branch_login_error !== '') {
                        ix_log_login($pdo, $su_row['username'], $su_row['user_type'], 'Failed');
                        $login_error = $branch_login_error;
                    } else {
                        session_regenerate_id(true); // session fixation প্রতিরোধ
                        $_SESSION['admin_logged']    = true;
                        $_SESSION['admin_user']      = $su_row['username'];
                        $_SESSION['is_super_admin']  = false;
                        $_SESSION['user_role']       = $su_row['user_type'];
                        $_SESSION['system_user_id']  = (int) $su_row['id'];
                        $_SESSION['branch_id']       = $resolved_branch_id; // null = কোনো branch এখনো অ্যাসাইন করা হয়নি এবং সিস্টেমেও একটাই branch
                        $pdo->prepare("UPDATE system_users SET last_login = NOW() WHERE id = ?")->execute([$su_row['id']]);
                        $inst_row = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetch();
                        $_SESSION['institute_branch'] = $inst_row['institute_name'] ?? ($GLOBALS['tenant']['school_name'] ?? 'Your School Name');
                        $_SESSION['login_attempts'] = 0;
                        ix_log_login($pdo, $su_row['username'], $su_row['user_type'], 'Success');
                        sec_start_session($pdo, $su_row['username'], $su_row['user_type']);
                        header("Location: index.php");
                        exit();
                    }
                }
            }

            if ($login_error === '') {
                // ভুল Username/Password — ব্যর্থ চেষ্টাও লগ হবে (username যা টাইপ করা হয়েছে সেটাই, কারণ
                // এই মুহূর্তে জানা যাচ্ছে না এটা আসলে কার অ্যাকাউন্ট)
                ix_log_login($pdo, $username !== '' ? $username : '(empty)', 'Unknown', 'Failed');
                $_SESSION['login_attempts']++;
                if ($_SESSION['login_attempts'] >= 5) {
                    $_SESSION['login_locked_until'] = time() + 60; // ৫ বার ভুলের পর ১ মিনিট লক
                    $_SESSION['login_attempts'] = 0;
                }
                $login_error = 'Invalid Username or Password!';
            }
        }
    }
    ?>
    <?php
    // প্রতিবার লগইন ফর্ম রেন্ডার হওয়ার আগে নতুন ভেরিফিকেশন কোড
    $admin_captcha_code = captcha_new('captcha_admin');
    ?>
    <?php
    // লোগো ইমেজ base64 data URI হিসেবে সরাসরি এমবেড করা হয়েছে — কোনো ফাইল পাথ/আপলোড
    // ঠিকঠাক না থাকলেও লোগো সবসময় দেখা যাবে (আলাদা .png ফাইলের উপর নির্ভরতা নেই)
    $brand_logo_data_uri = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNTYgMjU2Ij48Y2lyY2xlIGN4PSIxMjgiIGN5PSIxMjgiIHI9IjEyMCIgZmlsbD0iIzFlNDA3YyIvPjxwYXRoIGQ9Ik0xMjggNjRsODggNDAtODggNDAtODgtNDB6IiBmaWxsPSIjZmZmIi8+PHBhdGggZD0iTTg0IDEzOHYzNGMwIDEyIDIwIDIyIDQ0IDIyczQ0LTEwIDQ0LTIydi0zNGwtNDQgMjB6IiBmaWxsPSIjZmZmIi8+PC9zdmc+';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - School ERP</title>
        <?php echo $_favicon_tag; ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            html, body { height: 100%; }
            body {
                margin: 0;
                background: radial-gradient(circle at 20% 20%, #16213e 0%, #0f172a 55%, #0a0f1e 100%);
                display: flex; align-items: center; justify-content: center;
                position: relative; overflow: hidden;
                font-family: 'Segoe UI', Tahoma, sans-serif;
            }

            /* ---------- পুরো পেজ জুড়ে বড় Logo Watermark ---------- */
            .edu-bg-logo {
                position: absolute; inset: 0; z-index: 0;
                display: flex; align-items: center; justify-content: center;
                pointer-events: none;
            }
            .edu-bg-logo img {
                width: min(78vw, 720px); height: auto; opacity: 0.16;
                filter: drop-shadow(0 0 50px rgba(56,189,248,0.25));
                animation: bgLogoPulse 8s ease-in-out infinite;
            }
            @keyframes bgLogoPulse {
                0%, 100% { transform: scale(1); opacity: 0.14; }
                50%      { transform: scale(1.03); opacity: 0.20; }
            }

            /* ---------- Animated Educational Icons (logo-র উপর দিয়ে ভাসবে) ---------- */
            .edu-bg { position: absolute; inset: 0; z-index: 1; overflow: hidden; }
            .edu-item {
                position: absolute; opacity: 0; font-size: 56px;
                color: rgba(56, 189, 248, 0.42);
                animation: eduFloat linear infinite;
                filter: drop-shadow(0 0 8px rgba(56,189,248,0.2));
            }
            .edu-item.orange { color: rgba(251, 191, 36, 0.42); }
            .edu-item.green  { color: rgba(52, 211, 153, 0.38); }
            .edu-item.small  { font-size: 40px; }
            .edu-item.large  { font-size: 72px; }

            @keyframes eduFloat {
                0%   { transform: translateY(110vh) rotate(0deg) scale(0.8); opacity: 0; }
                10%  { opacity: 0.9; }
                90%  { opacity: 0.7; }
                100% { transform: translateY(-15vh) rotate(360deg) scale(1.1); opacity: 0; }
            }

            .edu-orb {
                position: absolute; border-radius: 50%; filter: blur(60px);
                background: radial-gradient(circle, rgba(56,189,248,0.25), transparent 70%);
                animation: eduDrift 14s ease-in-out infinite alternate;
                z-index: 1;
            }
            .edu-orb.two { background: radial-gradient(circle, rgba(16,185,129,0.20), transparent 70%); animation-duration: 18s; }
            @keyframes eduDrift {
                0%   { transform: translate(0, 0) scale(1); }
                100% { transform: translate(60px, 40px) scale(1.15); }
            }

            /* ---------- Login Card ---------- */
            .login-card {
                position: relative; z-index: 2;
                background: white; padding: 36px 40px 40px; border-radius: 14px;
                width: 100%; max-width: 400px;
                box-shadow: 0 20px 45px rgba(0,0,0,0.45);
                border-top: 5px solid #10b981;
                animation: cardRise 0.6s ease-out;
            }
            @keyframes cardRise {
                0%   { opacity: 0; transform: translateY(18px); }
                100% { opacity: 1; transform: translateY(0); }
            }
            .login-card .brand-logo { display: block; margin: 0 auto 10px; width: 92px; height: auto; }
            .login-card h3 { color: #0a2540; font-weight: 800; margin-bottom: 22px; text-align: center; }
        </style>
    </head>
    <body>
<?php if (erp_is_demo()) require __DIR__ . '/../demo/banner.php'; ?>

        <!-- পুরো পেজ জুড়ে বড় School ERP Logo (Watermark ব্যাকগ্রাউন্ড) -->
        <div class="edu-bg-logo" aria-hidden="true">
            <img src="<?php echo $brand_logo_data_uri; ?>" alt="">
        </div>

        <!-- Animated Educational Background Icons -->
        <div class="edu-bg" aria-hidden="true">
            <div class="edu-orb" style="width:320px;height:320px;top:-60px;left:-60px;"></div>
            <div class="edu-orb two" style="width:280px;height:280px;bottom:-80px;right:-40px;"></div>

            <i class="edu-item fa-solid fa-graduation-cap large" style="left:8%;  animation-duration:16s; animation-delay:0s;"></i>
            <i class="edu-item orange fa-solid fa-book"          style="left:20%; animation-duration:20s; animation-delay:2s;"></i>
            <i class="edu-item green fa-solid fa-pencil small"   style="left:32%; animation-duration:14s; animation-delay:4s;"></i>
            <i class="edu-item fa-solid fa-book-open large"      style="left:46%; animation-duration:22s; animation-delay:1s;"></i>
            <i class="edu-item orange fa-solid fa-graduation-cap" style="left:60%; animation-duration:18s; animation-delay:3s;"></i>
            <i class="edu-item green fa-solid fa-ruler small"    style="left:72%; animation-duration:19s; animation-delay:5s;"></i>
            <i class="edu-item fa-solid fa-calculator"           style="left:84%; animation-duration:17s; animation-delay:2.5s;"></i>
            <i class="edu-item orange fa-solid fa-lightbulb large" style="left:92%; animation-duration:21s; animation-delay:0.5s;"></i>
            <i class="edu-item green fa-solid fa-book small"     style="left:12%; animation-duration:23s; animation-delay:7s;"></i>
            <i class="edu-item fa-solid fa-pencil"                style="left:54%; animation-duration:15s; animation-delay:8s;"></i>
        </div>

        <div class="login-card">
            <img src="<?php echo $brand_logo_data_uri; ?>" alt="School ERP" class="brand-logo">
            <h3>School ERP</h3>
            <?php if($login_kick_msg): ?><div class="alert alert-warning py-2 text-center" style="font-size:13px;"><?php echo htmlspecialchars($login_kick_msg); ?></div><?php endif; ?>
            <?php if($login_error): ?><div class="alert alert-danger py-2 text-center" style="font-size:13px;"><?php echo htmlspecialchars($login_error); ?></div><?php endif; ?>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <?php if ($branch_login_needed): ?>
                <div class="mb-3">
                    <label class="form-label fw-bold" style="font-size:13px;">Branch</label>
                    <select name="branch_id" class="form-select" required>
                        <option value="">-- Branch সিলেক্ট করুন --</option>
                        <?php foreach ($branch_login_list as $bl): ?>
                            <option value="<?php echo (int) $bl['id']; ?>" <?php echo (isset($_POST['branch_id']) && (string) $_POST['branch_id'] === (string) $bl['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($bl['branch_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label fw-bold" style="font-size:13px;">Username</label>
                    <input type="text" name="username" class="form-control" placeholder="admin" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold" style="font-size:13px;">Password</label>
                    <input type="password" name="password" class="form-control" placeholder="Password" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold" style="font-size:13px;">Verification Code</label>
                    <div id="captchaBox" style="position:relative;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 10px 14px;text-align:center;">
                        <?php echo captcha_svg_markup($admin_captcha_code); ?>
                        <button type="button" id="captchaRefreshBtn" title="নতুন কোড" style="position:absolute;bottom:-10px;right:14px;width:30px;height:30px;border-radius:50%;background:#059669;color:#fff;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.25);display:flex;align-items:center;justify-content:center;cursor:pointer;">
                            <i class="fa-solid fa-rotate" style="font-size:13px;"></i>
                        </button>
                    </div>
                    <style>#captchaBox svg{display:block;width:100%;max-width:260px;height:auto;margin:0 auto;}</style>
                    <input type="text" name="verification_code" class="form-control mt-3" placeholder="উপরের সংখ্যাগুলো লিখুন" autocomplete="off" required>
                </div>
                <button type="submit" name="login" class="btn btn-success w-100 fw-bold py-2">Login</button>
                <p class="text-center text-muted mt-2 mb-0" style="font-size:12px;">কোড দেখা না গেলে রিফ্রেশ বাটনে চাপুন</p>
            </form>
        </div>
    <script>
    document.getElementById('captchaRefreshBtn')?.addEventListener('click', function () {
        const btn = this;
        const icon = btn.querySelector('i');
        icon.classList.add('fa-spin');
        fetch('captcha_refresh.php')
            .then(res => res.json())
            .then(data => {
                document.getElementById('captchaBox').querySelector('svg').outerHTML = data.svg;
            })
            .finally(() => icon.classList.remove('fa-spin'));
    });
    </script>
    </body>
    </html>
    <?php
    exit();
}

// Logout Handler
if (isset($_GET['logout'])) {
    sec_end_session($pdo);
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit();
}

// -----------------------------------------------------------
// System & Security — প্রতিটা অথেন্টিকেটেড রিকোয়েস্টে এই সেশনটা এখনো বৈধ কিনা যাচাই করা
// (কেউ Active Sessions পেজ থেকে Force Logout করে দিয়েছে কিনা, বা Idle Timeout পার হয়ে
// গেছে কিনা)। অবৈধ হলে আসল PHP session ডেস্ট্রয় করে, একটা কারণ-সহ বার্তা flash করে
// লগইন পেজে ফিরিয়ে দেওয়া হয়।
// -----------------------------------------------------------
if (!empty($_SESSION['admin_logged'])) {
    $sec_kick_reason = sec_enforce_session($pdo);
    if ($sec_kick_reason) {
        $sec_kicked_user = $_SESSION['admin_user'] ?? null;
        if ($sec_kicked_user) {
            sec_log_activity(
                $pdo,
                'Auth',
                'Auto Logout',
                $sec_kicked_user . ' - কারণ: ' . ($sec_kick_reason === 'timeout' ? 'Session Timeout (নিষ্ক্রিয়তা)' : 'Force Logout (Administrator)')
            );
        }
        $_SESSION = [];
        session_destroy();
        require_once __DIR__ . '/../config/runtime.php';
erp_session_start();
        $_SESSION['login_kick_msg'] = $sec_kick_reason === 'timeout'
            ? 'আপনার সেশনের মেয়াদ শেষ হয়ে গেছে (নিষ্ক্রিয়তার কারণে) — আবার লগইন করুন।'
            : 'একজন Administrator আপনাকে জোরপূর্বক লগআউট করে দিয়েছেন — আবার লগইন করুন।';
        header('Location: index.php');
        exit();
    }
}

// -----------------------------------------------------------
// Core self-healing migrations — পুরনো ইনস্টলেশনে কিছু কলাম না থাকলে
// প্রতিটা admin পেজ লোড হওয়ার আগেই এখানে নিশ্চিত করে নেওয়া হয়, যাতে
// student_list.php / student_promote.php / admission_applicants.php
// ইত্যাদি "Column not found" ফেটাল এরর না দেয়
// -----------------------------------------------------------
function idx_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $chk = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $chk->execute([$table, $column]);
    if (!$chk->fetch()) { $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition"); }
}
// students টেবিল থাকলে তবেই মাইগ্রেশন চালানো হচ্ছে (একদম নতুন/খালি tenant DB-তে সমস্যা এড়াতে)
$idx_students_exists = $pdo->query(
    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'"
)->fetchColumn();
if ($idx_students_exists) {
    idx_ensure_column($pdo, 'students', 'is_current', "TINYINT(1) NOT NULL DEFAULT 1");
    idx_ensure_column($pdo, 'students', 'test_marks', "DECIMAL(6,2) NULL");
    idx_ensure_column($pdo, 'students', 'admission_result', "VARCHAR(20) NULL");
    idx_ensure_column($pdo, 'students', 'result_published_at', "TIMESTAMP NULL");
}

// Change Password Handler
$pass_msg = '';
$pass_err = '';
if (!erp_is_demo() && isset($_POST['change_password'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $pass_err = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $current_pass = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';
        $username = $_SESSION['admin_user'];

        if (strlen($new_pass) < 6) {
            $pass_err = 'নতুন পাসওয়ার্ড কমপক্ষে ৬ ক্যারেক্টার হতে হবে!';
        } elseif ($new_pass !== $confirm_pass) {
            $pass_err = 'New password and confirm password do not match!';
        } elseif ($_SESSION['is_super_admin'] ?? true) {
            $stmt = $pdo->prepare('SELECT * FROM admin WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $row = $stmt->fetch();

            if ($row && password_verify($current_pass, $row['password'])) {
                $hashed_new_pass = password_hash($new_pass, PASSWORD_DEFAULT);
                $update = $pdo->prepare('UPDATE admin SET password = ? WHERE username = ?');
                $update->execute([$hashed_new_pass, $username]);
                $pass_msg = 'Password changed successfully!';
            } else {
                $pass_err = 'Current password is incorrect!';
            }
        } else {
            // system_users ভিত্তিক লগইন (Admin/Teacher/Student/Accountant/HR/Data Entry Operator)
            $stmt = $pdo->prepare("SELECT * FROM system_users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $row = $stmt->fetch();

            if ($row && password_verify($current_pass, $row['password'])) {
                $hashed_new_pass = password_hash($new_pass, PASSWORD_DEFAULT);
                $update = $pdo->prepare('UPDATE system_users SET password = ? WHERE username = ?');
                $update->execute([$hashed_new_pass, $username]);
                $pass_msg = 'Password changed successfully!';
            } else {
                $pass_err = 'Current password is incorrect!';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School ERP - <?php echo htmlspecialchars($_SESSION['institute_branch'] ?? ($GLOBALS['tenant']['school_name'] ?? 'Your School Name')); ?></title>
    <?php echo $_favicon_tag; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0a2540;
            --accent: #10b981;
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 80px;
        }
        body { background-color: #f1f5f9; font-family: 'Segoe UI', Tahoma, sans-serif; overflow-x: hidden; }
        
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: var(--sidebar-width); height: 100vh;
            background: #0f172a; border-right: 1px solid #1e293b;
            z-index: 1000; display: flex; flex-direction: column;
            overflow-y: auto; overflow-x: hidden; transition: all 0.3s ease-in-out;
        }
        .sidebar.collapsed { width: var(--sidebar-collapsed-width); }
        .sidebar.collapsed .brand-text,
        .sidebar.collapsed .sidebar-search,
        .sidebar.collapsed .menu-text { display: none !important; }
        .sidebar.collapsed .sidebar-menu li a { justify-content: center; padding: 12px 0; }
        .sidebar.collapsed .sidebar-menu li a i.menu-icon {
            font-size: 18px; width: 40px; height: 40px; background: #1e293b;
            color: #94a3b8; border-radius: 50%; display: flex; align-items: center;
            justify-content: center; border: 1px solid #334155; transition: all 0.2s;
        }

        .sidebar-brand {
            background: #090d16; color: white; padding: 15px 20px;
            font-size: 18px; font-weight: 800; white-space: nowrap; height: 60px; border-bottom: 1px solid #1e293b;
        }
        .sidebar-search { padding: 15px; border-bottom: 1px solid #1e293b; }
        .sidebar-search input { font-size: 13px; background: #1e293b; border-color: #334155; color: white; }
        .sidebar-search input::placeholder { color: #94a3b8; }
        
        .sidebar-menu { list-style: none; padding: 10px 0; margin: 0; white-space: nowrap; }
        .sidebar-menu li a {
            color: #cbd5e1; text-decoration: none; display: flex;
            align-items: center; justify-content: space-between; padding: 11px 20px;
            font-size: 13.5px; font-weight: 600; transition: all 0.2s; border-left: 4px solid transparent;
        }
        .sidebar-menu li a:hover, .sidebar-menu li a.active {
            background: #1e293b; color: #38bdf8; border-left-color: #38bdf8;
        }
        .sidebar-menu li a i { width: 25px; color: #94a3b8; }

        .submenu { list-style: none; padding-left: 0; background: #090d16; border-bottom: 1px solid #1e293b; }
        .submenu li a { padding: 8px 20px 8px 35px !important; font-size: 12.5px !important; color: #94a3b8 !important; border-left: none !important; }
        .submenu li a:hover { color: #38bdf8 !important; background: #1e293b; }
        .submenu li a.active { color: #38bdf8 !important; background: #1e293b; border-left: 3px solid #38bdf8 !important; font-weight: 700; }
        .submenu li.has-submenu > a.active-parent { color: #38bdf8 !important; }
        .submenu li.has-submenu > a i { width: auto; font-size: 10px !important; color: #64748b; }
        .submenu-nested { border-bottom: none; }
        .submenu-nested li a { padding-left: 50px !important; font-size: 12px !important; }

        .main-wrapper {
            margin-left: var(--sidebar-width); min-height: 100vh;
            display: flex; flex-direction: column; transition: all 0.3s ease-in-out;
        }
        .main-wrapper.expanded { margin-left: var(--sidebar-collapsed-width); }

        .sticky-top-area { position: sticky; top: 0; z-index: 999; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .top-navbar {
            background: var(--primary); color: white; padding: 0px 20px; height: 60px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .top-navbar .nav-icons { display: flex; gap: 12px; align-items: center; }
        .top-navbar .nav-icons a { color: white; font-size: 16px; text-decoration: none; position: relative; }
        
        .dropdown-menu .dropdown-item { color: #334155 !important; font-weight: 600; }
        .dropdown-menu .dropdown-item:hover { background-color: #f1f5f9 !important; color: #0284c7 !important; }

        .breadcrumb-bar {
            background: #ffffff; padding: 10px 30px; font-size: 13px; color: #64748b;
            border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;
        }
        .breadcrumb-bar b { color: var(--primary); }

        .content-body { padding: 25px 30px; flex: 1; }
        .top-stat-box {
            border-radius: 8px; padding: 18px 15px; color: white;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08); height: 100%;
        }
        .top-stat-box h3 { font-size: 22px; font-weight: 800; margin: 0; line-height: 1.2; }
        .top-stat-box p { font-size: 11.5px; margin: 0; opacity: 0.9; font-weight: 500; }
        .top-stat-icon {
            width: 50px; height: 50px; background: rgba(255, 255, 255, 0.2);
            border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;
        }
        .side-stat-card {
            width: 100%; max-width: 320px; height: 95px; margin-left: auto; margin-right: 0;
            border-radius: 8px; padding: 14px 18px; color: white; display: flex; align-items: center;
            justify-content: space-between; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 15px;
        }
        .side-stat-icon {
            width: 48px; height: 48px; background: rgba(255, 255, 255, 0.2);
            border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;
        }
        .side-stat-info h3 { font-size: 22px; font-weight: 800; margin: 0; line-height: 1.2; }
        .side-stat-info p { font-size: 11.5px; margin: 0; opacity: 0.95; font-weight: 500; }
        .card-custom {
            background: white; border-radius: 10px; border: 1px solid #e2e8f0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02); padding: 25px; margin-bottom: 25px;
        }
        .tab-btn { cursor: pointer; text-decoration: none; transition: 0.2s; }
    </style>
</head>
<body>
<?php if (erp_is_demo()) require __DIR__ . '/../demo/banner.php'; ?>

    <!-- SIDEBAR -->
    <?php include 'sidebar.php'; ?>

    <!-- MAIN WRAPPER -->
    <div class="main-wrapper" id="mainWrapper">
        
        <!-- STICKY TOP AREA -->
        <div class="sticky-top-area">
            <div class="top-navbar">
                <div class="d-flex align-items-center gap-4">
                    <div class="nav-icons d-none d-md-flex" style="gap: 14px;">
                        <a href="#" title="Users"><i class="fa-solid fa-user-plus"></i></a>
                        <a href="#" title="Transactions"><i class="fa-solid fa-coins"></i></a>
                        <a href="#" title="Staffs"><i class="fa-solid fa-user-tie"></i></a>
                    </div>
                </div>

                <div class="nav-icons">
                    <div class="dropdown">
                        <a href="#" class="d-flex align-items-center gap-1 text-decoration-none dropdown-toggle text-white" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fa-solid fa-user-circle fs-5"></i> 
                            <span class="fs-6"><?php echo htmlspecialchars($_SESSION['admin_user']); ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" style="font-size: 13.5px;">
                            <?php if (!erp_is_demo()): ?><li><a class="dropdown-item py-2" href="#" data-bs-toggle="modal" data-bs-target="#changePasswordModal"><i class="fa-solid fa-key text-primary me-2"></i> Change Password</a></li><?php endif; ?>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li><a class="dropdown-item text-danger fw-bold py-2" href="index.php?logout=true"><i class="fa-solid fa-power-off me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- DYNAMIC BREADCRUMB -->
            <div class="breadcrumb-bar">
                <span>
                    Home > School ERP > 
                    <?php 
                    $page = $_GET['page'] ?? 'dashboard';
                    if ($page == 'dashboard') {
                        echo 'Dashboard';
                    } elseif ($page == 'institute') {
                        echo 'Global Settings > Institute Settings';
                    } elseif ($page == 'branch') {
                        echo 'Global Settings > Branch Settings';
                    } elseif ($page == 'sms_settings') {
                        $st = $_GET['tab'] ?? 'home';
                        $settings_tab_labels = [
                            'home' => 'Home Page Settings',
                            'sms_toggle' => 'SMS Settings (On/Off)',
                            'content' => 'SMS Template Settings',
                            'api' => 'SMS Gateway Settings',
                        ];
                        echo 'Global Settings > Settings > ' . ($settings_tab_labels[$st] ?? 'Home Page Settings');
                    } elseif ($page == 'device_settings') {
                        echo 'Global Settings > Device Settings';
                    } elseif ($page == 'routine_general') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Routine Module > General Routine' . ($sv === 'add' ? ' > Add' : ($sv === 'edit' ? ' > Edit' : ''));
                    } elseif ($page == 'routine_dynamic') {
                        echo 'Routine Module > Dynamic Routine';
                    } elseif ($page == 'routine_view') {
                        echo 'Routine Module > View / Print Routine';
                    } elseif ($page == 'routine_auto_generate') {
                        echo 'Routine Module > Smart Routine Creator';
                    } elseif ($page == 'academic') {
                        echo 'Academic Module > Management';
                    } elseif ($page == 'academic_session') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Academic Module > Sessions > ' . ($sv === 'add' ? 'Add Session' : ($sv === 'edit' ? 'Edit Session' : 'Manage Session'));
                    } elseif ($page == 'academic_medium') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Academic Module > Media > ' . ($sv === 'add' ? 'Add Medium' : ($sv === 'edit' ? 'Edit Medium' : 'Manage Medium'));
                    } elseif ($page == 'academic_shift') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Academic Module > Shifts > ' . ($sv === 'add' ? 'Add Shift' : ($sv === 'edit' ? 'Edit Shift' : 'Manage Shift'));
                    } elseif ($page == 'academic_class') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Academic Module > Classes > ' . ($sv === 'add' ? 'Add Class' : ($sv === 'edit' ? 'Edit Class' : 'Manage Classes'));
                    } elseif ($page == 'academic_class_import') {
                        echo 'Academic Module > Classes > Import Class';
                    } elseif ($page == 'academic_section') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Academic Module > Sections > ' . ($sv === 'add' ? 'Add Section' : ($sv === 'edit' ? 'Edit Section' : 'Manage Section'));
                    } elseif ($page == 'academic_group') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Academic Module > Groups > ' . ($sv === 'add' ? 'Add Group' : ($sv === 'edit' ? 'Edit Group' : 'Manage Group'));
                    } elseif ($page == 'academic_subject') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Academic Module > Subjects > ' . ($sv === 'add' ? 'Add Subject' : ($sv === 'edit' ? 'Edit Subject' : 'Manage Subject'));
                    } elseif ($page == 'academic_subject_import') {
                        echo 'Academic Module > Subjects > Import Subject';
                    } elseif ($page == 'academic_calendar') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Academic Module > Academic Calendar > ' . ($sv === 'add' ? 'Add Holiday' : ($sv === 'edit' ? 'Edit Holiday' : 'Manage Holidays'));
                    } elseif ($page == 'print_academic_calendar') {
                        echo 'Academic Module > Academic Calendar > Print / Download PDF';
                    } elseif (strpos($page, 'academic_') === 0) {
                        $labels = [
                            'academic_session' => 'All Session', 'academic_medium' => 'All Medium', 'academic_shift' => 'All Shift List',
                            'academic_class' => 'All Class List', 'academic_section' => 'All Section List',
                            'academic_group' => 'All Group List', 'academic_subject' => 'All Subject List',
                        ];
                        echo 'Academic Module > ' . ($labels[$page] ?? 'Management');
                    } elseif (in_array($page, ['inventory', 'inventory_items', 'inventory_purchase', 'inventory_sales', 'inventory_sales_return', 'inventory_due', 'inventory_supplier', 'inventory_adjustment', 'inventory_reports', 'inventory_settings'], true)) {
                        $inv_labels = [
                            'inventory' => 'Items', 'inventory_items' => 'Items', 'inventory_purchase' => 'Purchase',
                            'inventory_sales' => 'Sales (POS)', 'inventory_sales_return' => 'Sales Return', 'inventory_due' => 'Due Collection',
                            'inventory_supplier' => 'Supplier Payment', 'inventory_adjustment' => 'Stock Adjustment',
                            'inventory_reports' => 'Reports', 'inventory_settings' => 'Settings',
                        ];
                        echo 'Inventory & Sales > ' . ($inv_labels[$page] ?? 'Management');
                    } elseif (in_array($page, ['canteen_raw_materials', 'canteen_purchase', 'canteen_menu', 'canteen_pos', 'canteen_due', 'canteen_stock_adjustment', 'canteen_reports', 'canteen_settings'], true)) {
                        $canteen_labels = [
                            'canteen_raw_materials' => 'Raw Materials', 'canteen_purchase' => 'Purchase', 'canteen_menu' => 'Items',
                            'canteen_pos' => 'Sales (POS)', 'canteen_due' => 'Due Collection',
                            'canteen_stock_adjustment' => 'Stock Adjustment', 'canteen_reports' => 'Reports', 'canteen_settings' => 'Settings',
                        ];
                        echo 'Canteen Module > ' . ($canteen_labels[$page] ?? 'Management');
                    } elseif (in_array($page, ['promo_groups', 'promo_contacts', 'promo_import_contacts', 'promo_send_sms', 'promo_sms_report'], true)) {
                        $promo_labels = [
                            'promo_groups' => 'My Groups', 'promo_contacts' => 'My Contacts',
                            'promo_import_contacts' => 'Import Contacts', 'promo_send_sms' => 'Send Custom SMS',
                            'promo_sms_report' => 'SMS Details Report',
                        ];
                        echo 'Promotional Module > ' . ($promo_labels[$page] ?? 'Management');
                    } elseif ($page == 'whatsapp_settings') {
                        echo 'Global Settings > WhatsApp API Settings';
                    } elseif ($page == 'admissions') {
                        echo 'Student Module > Pending Admission Applications';
                    } elseif ($page == 'current_student_list') {
                        echo "Students Module > Manage Student Information's (Current)";
                    } elseif ($page == 'archive_student_list') {
                        echo "Students Module > Manage Student Information's (Archive)";
                    } elseif ($page == 'student_promote') {
                        echo "Students Module > Student Promotion";
                    } elseif ($page == 'print_transfer_certificate') {
                        echo "Students Module > Student Transfer Certificate";
                    } elseif (in_array($page, ['student_admission', 'print_admission_form', 'current_student_search', 'archive_student_search', 'student_switch', 'student_branch_transfer', 'print_student_id_card', 'student_biometric_export', 'student_migration', 'print_student_testimonial', 'print_certificate'], true)) {
                        $sm_labels = [
                            'student_admission' => 'Student Admission', 'print_admission_form' => 'Print Admission Form',
                            'current_student_search' => 'Current Student Search',
                            'archive_student_search' => 'Archive Student Search', 'student_switch' => 'Student Switch Process',
                            'student_branch_transfer' => 'Student Branch Transfer',
                            'print_student_id_card' => 'Print Students ID Card', 'student_biometric_export' => 'Student Biometric Export',
                            'student_migration' => 'Student Migration Process', 'print_student_testimonial' => 'Print Student Testimonial',
                            'print_certificate' => 'Print Certificate',
                        ];
                        echo 'Student Module > ' . ($sm_labels[$page] ?? '');
                    } elseif ($page == 'attendance_today') {
                        echo 'Students > Student Attendances > Today Students Attendance';
                    } elseif ($page == 'attendance_manual') {
                        echo 'Students > Student Attendance > Add Student Attendance';
                    } elseif ($page == 'attendance_absent_list') {
                        echo 'Students > Student Attendances > Absent Student List';
                    } elseif ($page == 'attendance_details') {
                        echo 'Students > Student Attendance > Attendance Details';
                    } elseif ($page == 'attendance_daily_summary') {
                        echo 'Attendance Module > Student Attendance > Attendance Summary (Daily)';
                    } elseif ($page == 'attendance_monthly_summary') {
                        echo 'Attendance Module > Student Attendance > Attendance Summary (Monthly)';
                    } elseif ($page == 'hr_employee_type') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Human Resource > Employee Types > ' . ($sv === 'add' ? 'Add Employee Type' : ($sv === 'edit' ? 'Edit Employee Type' : 'Manage Employee Type'));
                    } elseif ($page == 'hr_designation') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Human Resource > Designations > ' . ($sv === 'add' ? 'Add Designation' : ($sv === 'edit' ? 'Edit Designation' : 'Manage Designation'));
                    } elseif ($page == 'hr_working_shift') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'HR & Payrolls > Working Shift > ' . ($sv === 'add' ? 'Add Working Shift' : ($sv === 'edit' ? 'Edit Working Shift' : 'Manage Working Shift'));
                    } elseif ($page == 'hr_employee_add') {
                        echo 'Human Resource > HR Module > ' . (($_GET['id'] ?? 0) ? 'Edit Employee' : 'Add Employee');
                    } elseif ($page == 'hr_employee_view') {
                        echo 'Human Resource > Employees > View Employee';
                    } elseif ($page == 'hr_employee_list') {
                        echo (($_GET['tab'] ?? '') === 'transport') ? 'Transport Module > Driver & Helper' : 'Human Resource > Employees > Manage Employees';
                    } elseif ($page == 'hr_employee_search') {
                        echo 'Human Resource > Employee Search';
                    } elseif ($page == 'hr_employee_id_card') {
                        echo 'Human Resource > Employees ID Card';
                    } elseif ($page == 'hr_employee_export') {
                        echo 'Human Resource > HR Module > Employees Export (Att.)';
                    } elseif ($page == 'leave_types') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Leave Module > Leave Types > ' . ($sv === 'add' ? 'Add Leave Type' : ($sv === 'edit' ? 'Edit Leave Type' : 'Manage Leave Type'));
                    } elseif ($page == 'leave_entry') {
                        echo 'Leave Module > Leave Registers > Leave Entry';
                    } elseif ($page == 'leave_manage') {
                        echo 'Leave Module > Leave Registers > Manage Leave Register';
                    } elseif ($page == 'payroll_head') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Human Resource > Payroll Module > Payroll Head' . ($sv === 'add' ? ' > Add Payroll Head' : ($sv === 'edit' ? ' > Edit Payroll Head' : ''));
                    } elseif ($page == 'payroll_employee_payscale') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Human Resource > Payroll Module > Employee Payscale' . ($sv === 'add' ? ' > Add Payscale' : ($sv === 'edit' ? ' > Edit Payscale' : ''));
                    } elseif ($page == 'payroll_salary_chart') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Human Resource > Payroll Module > Employee Salary Chart' . ($sv === 'add' ? ' > Add Employee Salary Chart' : ($sv === 'edit' ? ' > Edit Employee Salary Chart' : ''));
                    } elseif ($page == 'result_settings') {
                        echo 'Result Module > Exam Settings > Master Settings';
                    } elseif ($page == 'result_grade_points') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Result Module > Exam Settings > Grade Points' . ($sv === 'add' ? ' > Add Grade Point' : ($sv === 'edit' ? ' > Edit Grade Point' : ''));
                    } elseif ($page == 'result_exam_terms') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Result Module > Exam Settings > Exam Terms' . ($sv === 'add' ? ' > Add Exam Term' : ($sv === 'edit' ? ' > Edit Exam Term' : ''));
                    } elseif ($page == 'result_term_settings') {
                        echo 'Result Module > Exam Settings > Term Publish Settings';
                    } elseif ($page == 'result_exam_parts') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Result Module > Exam Settings > Exam Parts' . ($sv === 'add' ? ' > Add Exam Part' : ($sv === 'edit' ? ' > Edit Exam Part' : ''));
                    } elseif ($page == 'result_assign_exams') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Result Module > Exam Settings > Assign Exam' . ($sv === 'add' ? ' > Assign Exam' : '');
                    } elseif ($page == 'result_merit_tiebreaker') {
                        echo 'Result Module > Exam Settings > Merit Tie-Breaker';
                    } elseif ($page == 'result_lock_control') {
                        echo 'Result Module > Exam Settings > Result Lock/Unlock';
                    } elseif ($page == 'result_assign_subject_teacher') {
                        echo 'Result Module > Exam Settings > Assign Subject to Teacher';
                    } elseif ($page == 'result_working_days') {
                        $sv = $_GET['view'] ?? 'list';
                        $cur_sess = $pdo->query("SELECT session_name FROM academic_sessions WHERE is_current_session=1 LIMIT 1")->fetch();
                        echo 'Result Module > Exam Settings > ' . ($sv === 'add' ? 'Add Working Days for Session : ' . ($cur_sess['session_name'] ?? '') : 'Working Days');
                    } elseif ($page == 'result_exam_routine') {
                        echo 'Result Module > Exam Procedure > Exam Routine';
                    } elseif ($page == 'result_exam_eligibility') {
                        echo 'Result Module > Exam Procedure > Exam Eligibility';
                    } elseif ($page == 'result_attendance_subject') {
                        echo 'Result Module > Exam Procedure > Attendance (Subject)';
                    } elseif ($page == 'result_attendance_exam') {
                        echo 'Result Module > Exam Procedure > Attendance (Exam)';
                    } elseif ($page == 'result_exam_seat_plan') {
                        echo 'Result Module > Exam Procedure > Exam Seat Plan';
                    } elseif ($page == 'result_marks_entry_subject') {
                        echo 'Result Module > Exam Procedure > Marks Entry (Subject)';
                    } elseif ($page == 'result_marks_entry_exam') {
                        echo 'Result Module > Exam Procedure > Marks Entry (Exam)';
                    } elseif ($page == 'result_download_admit_card') {
                        echo 'Result Module > Result Reports > Download Admit Card';
                    } elseif ($page == 'result_attendance_blank_sheet') {
                        echo 'Result Module > Result Reports > Attendance Blank Sheet';
                    } elseif ($page == 'result_marks_entry_blank_sheet') {
                        echo 'Result Module > Result Reports > Marks Entry Blank Sheet';
                    } elseif ($page == 'result_tabulation_sheet') {
                        echo 'Result Module > Result Reports > Tabulation Sheet';
                    } elseif ($page == 'result_students_merit_list') {
                        echo 'Result Module > Result Reports > Students Merit List';
                    } elseif ($page == 'result_students_report_card') {
                        echo 'Result Module > Result Reports > Students Report Card';
                    } elseif ($page == 'result_final_aggregated_result') {
                        echo 'Result Module > Result Reports > Final Aggregated Result';
                    } elseif ($page == 'result_subject_analysis') {
                        echo 'Result Module > Result Reports > Subject-wise Performance Analysis';
                    } elseif ($page == 'result_student_progress') {
                        echo 'Result Module > Result Reports > Student Progress Tracking';
                    } elseif ($page == 'result_section_comparison') {
                        echo 'Result Module > Result Reports > Class/Section Comparison Report';
                    } elseif ($page == 'result_performer_alert') {
                        echo 'Result Module > Result Reports > Top/Bottom Performer Alert';
                    } elseif ($page == 'result_marks_pending') {
                        echo 'Result Module > Result Reports > Marks Entry Pending Status';
                    } elseif ($page == 'result_bulk_pdf_export') {
                        echo 'Result Module > Result Reports > Bulk Result PDF Export';
                    } elseif ($page == 'result_multiyear_cgpa') {
                        echo 'Result Module > Result Reports > Multi-Year CGPA';
                    } elseif ($page == 'result_question_create') {
                        echo 'Question Management > Create Question';
                    } elseif ($page == 'result_question_list') {
                        echo 'Question Management > Question List';
                    } elseif ($page == 'accounts_chart') {
                        echo 'Accounts Module > Chart of Accounts';
                    } elseif (in_array($page, ['accounts_journal_voucher', 'accounts_receipt_voucher', 'accounts_payment_voucher', 'accounts_contra_voucher'], true)) {
                        $ac_voucher_labels = [
                            'accounts_journal_voucher' => 'Journal Voucher', 'accounts_receipt_voucher' => 'Receipt Voucher',
                            'accounts_payment_voucher' => 'Payment Voucher', 'accounts_contra_voucher' => 'Contra Voucher',
                        ];
                        echo 'Accounts Module > Vouchers > ' . $ac_voucher_labels[$page];
                    } elseif (in_array($page, ['accounts_ledger', 'accounts_trial_balance', 'accounts_profit_loss', 'accounts_balance_sheet'], true)) {
                        $ac_report_labels = [
                            'accounts_ledger' => 'General Ledger', 'accounts_trial_balance' => 'Trial Balance',
                            'accounts_profit_loss' => 'Profit & Loss', 'accounts_balance_sheet' => 'Balance Sheet',
                        ];
                        echo 'Accounts Module > Reports > ' . $ac_report_labels[$page];
                    } elseif (in_array($page, ['accounts_vendors', 'accounts_vendor_bills', 'accounts_vendor_payment'], true)) {
                        $ac_ap_labels = [
                            'accounts_vendors' => 'Vendors', 'accounts_vendor_bills' => 'Vendor Bills',
                            'accounts_vendor_payment' => 'Vendor Payment',
                        ];
                        echo 'Accounts Module > Accounts Payable > ' . $ac_ap_labels[$page];
                    } elseif ($page == 'finance_head') {
                        echo 'Finance Module > Accounts Head';
                    } elseif (in_array($page, ['finance_admission_fees', 'finance_monthly_fees', 'finance_student_fees', 'finance_student_waiver', 'finance_quick_collection', 'finance_fees_collection', 'finance_fees_recollection', 'finance_collection_invoice_list', 'finance_online_payments', 'finance_money_receipt_settings'], true)) {
                        $finance_student_labels = [
                            'finance_admission_fees' => 'Admission Fees', 'finance_monthly_fees' => 'Monthly Fees',
                            'finance_student_fees' => 'Students Fees Settings', 'finance_student_waiver' => 'Students Wise Waiver',
                            'finance_quick_collection' => 'Student Fees Collection (Quick)', 'finance_fees_collection' => 'Student Fees Collection',
                            'finance_fees_recollection' => 'Fees Re-collection', 'finance_collection_invoice_list' => 'Money Receipt List',
                            'finance_online_payments' => 'Online Fee Payments', 'finance_money_receipt_settings' => 'Money Receipt Settings',
                        ];
                        echo 'Finance Module > Students Accounts > ' . $finance_student_labels[$page];
                    } elseif (in_array($page, ['finance_receive_voucher', 'finance_payment_voucher', 'finance_vouchers_list'], true)) {
                        $finance_voucher_labels = [
                            'finance_receive_voucher' => 'Receive Voucher (+)', 'finance_payment_voucher' => 'Payment Voucher (-)',
                            'finance_vouchers_list' => 'Vouchers List (+/-)',
                        ];
                        echo 'Finance Module > Accounts Voucher > ' . $finance_voucher_labels[$page];
                    } elseif ($page == 'finance_voucher_print') {
                        echo 'Finance Module > Accounts Voucher > Print Voucher';
                    } elseif (in_array($page, ['finance_daily_collection', 'finance_report_fees_collection', 'finance_students_dues', 'finance_students_ledger', 'finance_report_students_waiver', 'finance_accounts_ledger', 'finance_trial_balance', 'finance_cash_book', 'finance_bank_book', 'finance_balance_sheet', 'finance_payable_receivable'], true)) {
                        $finance_report_labels = [
                            'finance_daily_collection' => 'Daily Collection', 'finance_report_fees_collection' => 'Fees Collection',
                            'finance_students_dues' => 'Students Dues', 'finance_students_ledger' => 'Students Ledger',
                            'finance_report_students_waiver' => 'Students Waiver', 'finance_accounts_ledger' => 'Accounts Ledger',
                            'finance_trial_balance' => 'Trial Balance', 'finance_cash_book' => 'Cash Book',
                            'finance_bank_book' => 'Bank Book', 'finance_balance_sheet' => 'Balance Sheet',
                            'finance_payable_receivable' => 'Payable / Receivable',
                        ];
                        echo 'Finance Module > Finance Reports > ' . $finance_report_labels[$page];
                    } elseif ($page == 'finance_students_due_details') {
                        echo 'Finance Module > Finance Reports > Students Dues > Student Due Details';
                    } elseif ($page == 'finance_student_due_month_details') {
                        echo 'Finance Module > Finance Reports > Students Dues > Month Details';
                    } elseif (in_array($page, ['scholarship_types', 'scholarship_award', 'scholarship_disbursement', 'scholarship_report', 'poor_fund_aid'], true)) {
                        $scholarship_labels = [
                            'scholarship_types' => 'Scholarship Types', 'scholarship_award' => 'Assign Scholarship',
                            'scholarship_disbursement' => 'Process Disbursement', 'scholarship_report' => 'Scholarship Report',
                            'poor_fund_aid' => 'Poor Fund / Charity Aid',
                        ];
                        echo 'Finance Module > Scholarship & Welfare > ' . $scholarship_labels[$page];
                    } elseif ($page == 'admission_selection') {
                        echo 'Student Module > Admission Test / Lottery Selection';
                    } elseif (in_array($page, ['admission_settings', 'admission_applicants', 'admission_mark_entry', 'admission_publish', 'admission_seat_plan', 'print_admission_slip'], true)) {
                        $adm_labels = [
                            'admission_settings' => 'Settings', 'admission_applicants' => 'Applied Student List',
                            'admission_mark_entry' => 'Mark Entry', 'admission_publish' => 'Result / Publish',
                            'admission_seat_plan' => 'Seat Plan', 'print_admission_slip' => 'Print Admission Slip',
                        ];
                        echo 'Admission Module > Admission Test / Lottery > ' . ($adm_labels[$page] ?? '');
                    } elseif ($page === 'admission_payment_settings') {
                        echo 'Global Settings > Payment Gateway Settings';
                    } elseif ($page === 'admission_fee_claims') {
                        echo 'Admission Module > Fee Claims / Payments';
                    } elseif (in_array($page, ['payroll_generate_salary_sheet', 'payroll_salary_payment', 'payroll_salary_report', 'payroll_payslip_print'], true)) {
                        $payroll_labels = [
                            'payroll_generate_salary_sheet' => 'Generate Salary Sheet',
                            'payroll_salary_payment' => 'Employee Salary Payment', 'payroll_salary_report' => 'Employee Salary Report',
                            'payroll_payslip_print' => 'Employee Pay Slip Print',
                        ];
                        echo 'Human Resource > Payroll Module > ' . ($payroll_labels[$page] ?? '');
                    } elseif ($page == 'library_categories') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Library Module > Book Categories > ' . ($sv === 'add' ? 'Add Category' : ($sv === 'edit' ? 'Edit Category' : 'Manage Category'));
                    } elseif ($page == 'library_books') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Library Module > Book Management > ' . ($sv === 'add' ? 'Add Book' : ($sv === 'edit' ? 'Edit Book' : 'All Books'));
                    } elseif ($page == 'library_issue') {
                        echo 'Library Module > Issue Book';
                    } elseif ($page == 'library_return') {
                        echo 'Library Module > Return Book & Fine Collection';
                    } elseif ($page == 'library_reports') {
                        echo 'Library Module > Reports';
                    } elseif ($page == 'library_settings') {
                        echo 'Library Module > Settings';
                    } elseif ($page == 'transport_vehicles') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Transport Module > Vehicle Management > ' . ($sv === 'add' ? 'Add Vehicle' : ($sv === 'edit' ? 'Edit Vehicle' : 'All Vehicles'));
                    } elseif ($page == 'transport_routes') {
                        $sv = $_GET['view'] ?? 'list';
                        echo 'Transport Module > Route Management > ' . ($sv === 'add' ? 'Add Route' : ($sv === 'edit' ? 'Edit Route' : 'All Routes'));
                    } elseif ($page == 'transport_stops') {
                        echo 'Transport Module > Route Management > Stops';
                    } elseif ($page == 'transport_assign_student') {
                        echo 'Transport Module > Assign Student';
                    } elseif ($page == 'transport_assign_staff') {
                        echo 'Transport Module > Assign Staff';
                    } elseif ($page == 'transport_fees') {
                        echo 'Transport Module > Fee Collection';
                    } elseif ($page == 'transport_gps_settings') {
                        echo 'Transport Module > GPS Settings';
                    } elseif ($page == 'transport_live_map') {
                        echo 'Transport Module > Live GPS Map';
                    } elseif ($page == 'website_notice') {
                        echo 'Website Module > Notice List';
                    } elseif ($page == 'website_slider') {
                        echo 'Website Module > Slider List';
                    } elseif ($page == 'website_message') {
                        echo 'Website Module > Message Corner';
                    } elseif ($page == 'website_gallery') {
                        echo 'Website Module > Gallery List';
                    } elseif ($page == 'website_why_choose') {
                        echo 'Website Module > Why Choose Image';
                    } elseif ($page == 'website_menu') {
                        echo 'Website Module > Menu List';
                    } elseif ($page == 'website_content') {
                        echo 'Website Module > Content List';
                    } elseif ($page == 'website_breaking_news') {
                        echo 'Website Module > Breaking News';
                    } elseif ($page == 'website_album') {
                        echo 'Website Module > Album List';
                    } elseif ($page == 'website_news') {
                        echo 'Website Module > News List';
                    } elseif ($page == 'website_events') {
                        echo 'Website Module > Events List';
                    } elseif ($page == 'website_facilities') {
                        echo 'Website Module > Facilities List';
                    } elseif ($page == 'website_career') {
                        echo 'Website Module > Career List';
                    } elseif ($page == 'website_board_result') {
                        echo 'Website Module > Board Result';
                    } elseif ($page == 'website_useful_links') {
                        echo 'Website Module > Useful Links';
                    } elseif ($page == 'website_social') {
                        echo 'Website Module > Social Media';
                    } elseif ($page == 'website_govt_apa') {
                        echo 'Website Module > Govt Theme Settings > এপিএ (APA)';
                    } elseif ($page == 'website_govt_suddhachar') {
                        echo 'Website Module > Govt Theme Settings > শুদ্ধাচার কৌশল';
                    } elseif ($page == 'student_import') {
                        echo 'Student Module > Import Students';
                    } elseif ($page == 'hr_employee_import') {
                        echo 'HR Module > Import Employees';
                    } elseif ($page == 'homework') {
                        echo 'E-Learning Module > Homework';
                    } elseif ($page == 'live_class') {
                        echo 'E-Learning Module > Live Class';
                    } elseif ($page == 'live_class_host') {
                        echo 'E-Learning Module > Live Class > Host';
                    } elseif ($page == 'live_class_settings') {
                        echo 'E-Learning Module > Live Class Setting';
                    } elseif ($page == 'recorded_class') {
                        echo 'E-Learning Module > Recorded Class';
                    } elseif ($page == 'live_class_youtube_connect') {
                        echo 'E-Learning Module > Live Class > YouTube Connect';
                    } elseif (in_array($page, ['login_logs', 'security_active_sessions', 'security_settings', 'security_activity_log'], true)) {
                        $security_labels = [
                            'login_logs' => 'Login / Session Log', 'security_active_sessions' => 'Active Sessions',
                            'security_settings' => 'Session Timeout Settings', 'security_activity_log' => 'Activity Log',
                        ];
                        echo 'System & Security > ' . $security_labels[$page];
                    } else {
                        echo htmlspecialchars(ucfirst(str_replace('_', ' ', $page)));
                    }
                    ?>
                </span>
                <span>
                    Branch &raquo; 
                    <?php echo htmlspecialchars(branch_current_name($pdo)); ?>
                </span>
            </div>
        </div>

        <!-- CONTENT BODY (ROUTER) -->
        <div class="content-body">
            <?php if($pass_msg): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-check-circle me-2"></i> <?php echo htmlspecialchars($pass_msg); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if($pass_err): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($pass_err); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php
            // Modular file routing — SECURITY: শুধুমাত্র whitelist করা page => file
            // ম্যাপিং থেকে include হবে। $_GET['page'] সরাসরি ফাইল পাথ বানাতে
            // ব্যবহার করা হয় না, তাই Local File Inclusion / path traversal সম্ভব না।
            $page_map = [
                'dashboard'  => 'dashboard.php',
                'institute'  => 'institute.php',
                'academic'   => 'dashboard.php',
                'academic_session' => 'academic_session.php',
                'academic_medium'  => 'academic_medium.php',
                'academic_shift'   => 'academic_shift.php',
                'academic_class'   => 'academic_class.php',
                'academic_class_import' => 'academic_class_import.php',
                'academic_section' => 'academic_section.php',
                'academic_group'   => 'academic_group.php',
                'academic_subject' => 'academic_subject.php',
                'academic_subject_import' => 'academic_subject_import.php',
                'academic_calendar' => 'academic_calendar.php',
                'print_academic_calendar' => 'print_academic_calendar.php',
                'inventory'  => 'inventory_items.php',
                'inventory_items' => 'inventory_items.php',
                'inventory_purchase' => 'inventory_purchase.php',
                'inventory_sales' => 'inventory_sales.php',
                'inventory_due' => 'inventory_due.php',
                'inventory_supplier' => 'inventory_supplier.php',
                'inventory_adjustment' => 'inventory_adjustment.php',
                'inventory_reports' => 'inventory_reports.php',
                'inventory_sales_return' => 'inventory_sales_return.php',
                'inventory_settings' => 'inventory_settings.php',
                'canteen_raw_materials'    => 'canteen_raw_materials.php',
                'canteen_purchase'         => 'canteen_purchase.php',
                'canteen_menu'             => 'canteen_menu.php',
                'canteen_pos'              => 'canteen_pos.php',
                'canteen_due'              => 'canteen_due.php',
                'canteen_stock_adjustment' => 'canteen_stock_adjustment.php',
                'canteen_reports'          => 'canteen_reports.php',
                'canteen_settings'         => 'canteen_settings.php',
                'promo_groups'             => 'promo_groups.php',
                'promo_contacts'           => 'promo_contacts.php',
                'promo_import_contacts'    => 'promo_import_contacts.php',
                'promo_send_sms'           => 'promo_send_sms.php',
                'promo_sms_report'         => 'promo_sms_report.php',
                'whatsapp_settings'        => 'whatsapp_settings.php',
                'admissions' => 'admissions.php',
                'admission_selection' => 'admission_selection.php',
                'admission_settings' => 'admission_settings.php',
                'admission_applicants' => 'admission_applicants.php',
                'admission_mark_entry' => 'admission_mark_entry.php',
                'admission_publish' => 'admission_publish.php',
                'admission_seat_plan' => 'admission_seat_plan.php',
                'print_admission_slip' => 'print_admission_slip.php',
                'admission_payment_settings' => 'admission_payment_settings.php',
                'admission_fee_claims' => 'admission_fee_claims.php',
                'current_student_list' => 'student_list.php',
                'archive_student_list' => 'student_list.php',
                'student_promote' => 'student_promote.php',
                'student_admission'          => 'student_admission.php',
                'print_admission_form'       => 'print_admission_form.php',
                'current_student_search'     => 'student_search.php',
                'archive_student_search'     => 'student_search.php',
                'student_switch'             => 'student_switch.php',
                'student_branch_transfer'    => 'student_branch_transfer.php',
                'print_student_id_card'      => 'print_student_id_card.php',
                'student_biometric_export'   => 'coming_soon.php',
                'student_migration'          => 'student_migration.php',
                'print_student_testimonial'  => 'print_student_testimonial.php',
                'print_transfer_certificate' => 'print_transfer_certificate.php',
                'print_certificate'          => 'print_certificate.php',
                'branch'     => 'branch.php',
                'sms_settings' => 'sms_settings.php',
                'device_settings' => 'device_settings.php',
                'routine_general' => 'routine_general.php',
                'routine_dynamic' => 'routine_dynamic.php',
                'routine_view'    => 'routine_view.php',
                'routine_auto_generate' => 'routine_auto_generate.php',
                'library_categories' => 'library_categories.php',
                'library_books'      => 'library_books.php',
                'library_issue'      => 'library_issue.php',
                'library_return'     => 'library_return.php',
                'library_reports'    => 'library_reports.php',
                'library_settings'   => 'library_settings.php',
                'transport_vehicles'        => 'transport_vehicles.php',
                'transport_routes'          => 'transport_routes.php',
                'transport_stops'           => 'transport_stops.php',
                'transport_assign_student'  => 'transport_assign_student.php',
                'transport_assign_staff'    => 'transport_assign_staff.php',
                'transport_fees'            => 'transport_fees.php',
                'transport_gps_settings'    => 'transport_gps_settings.php',
                'transport_live_map'        => 'transport_live_map.php',
                'website_notice'  => 'website.php',
                'website_slider'  => 'website.php',
                'website_message' => 'website_message.php',
                'website_gallery' => 'website.php',
                'website_why_choose'    => 'website_content.php',
                'website_menu'          => 'website_content.php',
                'website_content'       => 'website_content.php',
                'website_breaking_news' => 'website_content.php',
                'website_album'         => 'website_media.php',
                'website_news'          => 'website_media.php',
                'website_events'        => 'website_media.php',
                'website_facilities'    => 'website_facilities.php',
                'website_career'        => 'website_misc.php',
                'website_board_result'  => 'website_misc.php',
                'website_useful_links'  => 'website_misc.php',
                'website_social'        => 'website_misc.php',
                'website_govt_apa'         => 'website_govt_theme.php',
                'website_govt_suddhachar'  => 'website_govt_theme.php',
                'student_import'  => 'student_import.php',
                'hr_employee_import' => 'hr_employee_import.php',
                'attendance_today'            => 'attendance_today.php',
                'attendance_manual'           => 'attendance_manual.php',
                'attendance_absent_list'      => 'attendance_absent_list.php',
                'attendance_details'          => 'attendance_details.php',
                'attendance_daily_summary'    => 'attendance_daily_summary.php',
                'attendance_monthly_summary'  => 'attendance_monthly_summary.php',
                'hr_employee_type'            => 'hr_employee_type.php',
                'hr_designation'              => 'hr_designation.php',
                'hr_working_shift'            => 'hr_working_shift.php',
                'hr_employee_add'             => 'hr_employee_add.php',
                'hr_employee_view'            => 'hr_employee_view.php',
                'hr_employee_list'            => 'hr_employee_list.php',
                'hr_employee_search'          => 'hr_employee_search.php',
                'hr_employee_id_card'         => 'hr_employee_id_card.php',
                'hr_employee_export'          => 'coming_soon.php',
                'leave_types'                 => 'leave_types.php',
                'leave_entry'                 => 'leave_entry.php',
                'leave_manage'                => 'leave_manage.php',
                'payroll_head'                => 'payroll_head.php',
                'result_settings'             => 'result_settings.php',
                'result_grade_points'         => 'result_grade_points.php',
                'result_exam_terms'           => 'result_exam_terms.php',
                'result_term_settings'        => 'result_term_settings.php',
                'result_exam_parts'           => 'result_exam_parts.php',
                'result_assign_exams'         => 'result_assign_exams.php',
                'result_merit_tiebreaker'     => 'result_merit_tiebreaker.php',
                'result_assign_subject_teacher' => 'result_assign_subject_teacher.php',
                'result_working_days'         => 'result_working_days.php',
                'result_exam_routine'         => 'result_exam_routine.php',
                'result_exam_eligibility'     => 'result_exam_eligibility.php',
                'result_attendance_subject'   => 'result_attendance_subject.php',
                'result_attendance_exam'      => 'result_attendance_exam.php',
                'result_exam_seat_plan'       => 'result_exam_seat_plan.php',
                'result_exam_fee_settings'    => 'result_exam_fee_settings.php',
                'result_exam_fee_collection'  => 'result_exam_fee_collection.php',
                'result_marks_entry_subject'  => 'result_marks_entry_subject.php',
                'result_marks_entry_exam'     => 'result_marks_entry_exam.php',
                'result_download_admit_card'  => 'result_download_admit_card.php',
                'result_attendance_blank_sheet' => 'result_attendance_blank_sheet.php',
                'result_marks_entry_blank_sheet' => 'result_marks_entry_blank_sheet.php',
                'result_tabulation_sheet'     => 'result_tabulation_sheet.php',
                'result_students_merit_list'  => 'result_students_merit_list.php',
                'result_final_aggregated_result' => 'result_final_aggregated_result.php',
                'result_subject_analysis'     => 'result_subject_analysis.php',
                'result_student_progress'     => 'result_student_progress.php',
                'result_section_comparison'   => 'result_section_comparison.php',
                'result_performer_alert'      => 'result_performer_alert.php',
                'result_marks_pending'        => 'result_marks_pending.php',
                'result_bulk_pdf_export'      => 'result_bulk_pdf_export.php',
                'result_multiyear_cgpa'       => 'result_multiyear_cgpa.php',
                'result_lock_control'         => 'result_lock_control.php',
                'result_students_report_card' => 'result_students_report_card.php',
                'result_question_create'      => 'result_question_create.php',
                'result_question_list'        => 'result_question_list.php',
                'accounts_chart'                => 'accounts_chart.php',
                'accounts_journal_voucher'      => 'accounts_journal_voucher.php',
                'accounts_receipt_voucher'      => 'accounts_journal_voucher.php',
                'accounts_payment_voucher'      => 'accounts_journal_voucher.php',
                'accounts_contra_voucher'       => 'accounts_journal_voucher.php',
                'accounts_ledger'               => 'accounts_ledger.php',
                'accounts_trial_balance'        => 'accounts_trial_balance.php',
                'accounts_profit_loss'          => 'accounts_profit_loss.php',
                'accounts_balance_sheet'        => 'accounts_balance_sheet.php',
                'accounts_vendors'              => 'accounts_vendors.php',
                'accounts_vendor_bills'         => 'accounts_vendor_bills.php',
                'accounts_vendor_payment'       => 'accounts_vendor_payment.php',
                'finance_head'                 => 'finance_head.php',
                'finance_admission_fees'       => 'finance_admission_fees.php',
                'finance_monthly_fees'         => 'finance_monthly_fees.php',
                'finance_student_fees'         => 'finance_student_fees.php',
                'finance_student_waiver'       => 'finance_student_waiver.php',
                'finance_quick_collection'     => 'finance_quick_collection.php',
                'finance_fees_collection'      => 'finance_fees_collection.php',
                'finance_fees_recollection'    => 'finance_fees_recollection.php',
                'finance_collection_invoice_list' => 'finance_collection_invoice_list.php',
                'finance_money_receipt_settings' => 'finance_money_receipt_settings.php',
                'finance_online_payments'      => 'finance_online_payments.php',
                'finance_receive_voucher'      => 'finance_receive_voucher.php',
                'finance_payment_voucher'      => 'finance_payment_voucher.php',
                'finance_vouchers_list'        => 'finance_vouchers_list.php',
                'scholarship_types'            => 'scholarship_types.php',
                'scholarship_award'            => 'scholarship_award.php',
                'scholarship_disbursement'     => 'scholarship_disbursement.php',
                'scholarship_report'           => 'scholarship_report.php',
                'poor_fund_aid'                => 'poor_fund_aid.php',
                'finance_voucher_print'        => 'finance_voucher_print.php',
                'finance_daily_collection'     => 'finance_daily_collection.php',
                'finance_report_fees_collection' => 'finance_report_fees_collection.php',
                'finance_students_dues'        => 'finance_students_dues.php',
                'finance_students_due_details' => 'finance_students_due_details.php',
                'finance_student_due_month_details' => 'finance_student_due_month_details.php',
                'finance_students_ledger'      => 'finance_students_ledger.php',
                'finance_report_students_waiver' => 'finance_report_students_waiver.php',
                'finance_accounts_ledger'      => 'finance_accounts_ledger.php',
                'finance_trial_balance'        => 'finance_trial_balance.php',
                'finance_cash_book'            => 'finance_cash_book.php',
                'finance_bank_book'            => 'finance_bank_book.php',
                'finance_balance_sheet'        => 'finance_balance_sheet.php',
                'finance_payable_receivable'   => 'finance_payable_receivable.php',
                'payroll_employee_payscale'   => 'payroll_employee_payscale.php',
                'payroll_salary_chart'        => 'payroll_salary_chart.php',
                'payroll_generate_salary_sheet' => 'payroll_generate_salary_sheet.php',
                'payroll_salary_payment'      => 'payroll_salary_payment.php',
                'payroll_salary_report'       => 'payroll_salary_report.php',
                'payroll_payslip_print'       => 'payroll_payslip_print.php',
                'employee_attendance_today'           => 'employee_attendance_today.php',
                'employee_attendance_manual'          => 'employee_attendance_manual.php',
                'employee_attendance_details'         => 'employee_attendance_details.php',
                'employee_attendance_daily_summary'   => 'employee_attendance_daily_summary.php',
                'employee_attendance_monthly_summary' => 'employee_attendance_monthly_summary.php',
                'users_list' => 'users_list.php',
                'user_permissions' => 'user_permissions.php',
                'sms_users_students' => 'sms_users_students.php',
                'sms_users_employees' => 'sms_users_employees.php',
                'login_logs' => 'login_logs.php',
                'security_active_sessions' => 'security_active_sessions.php',
                'security_settings' => 'security_settings.php',
                'security_activity_log' => 'security_activity_log.php',
                'database_reset' => 'database_reset.php',
                'homework' => 'homework.php',
                'live_class' => 'live_class.php',
                'live_class_host' => 'live_class_host.php',
                'live_class_settings' => 'live_class_settings.php',
                'recorded_class' => 'recorded_class.php',
                'live_class_youtube_connect' => 'live_class_youtube_connect.php',
            ];

            $target_file = $page_map[$page] ?? 'dashboard.php';

            // User Permission চেক — Super Admin সবসময় পাস, আর যে user_type-এর জন্য
            // কোনো restriction সেট করা হয়নি সেও পাস (backward compatible)। শুধু
            // Super Admin যাকে specifically restrict করেছে, তাকেই অনুমতি ছাড়া পেজে
            // ঢুকতে না দেওয়া — URL সরাসরি টাইপ করলেও কাজ করবে, শুধু sidebar hide না।
            if ($page !== 'dashboard' && !perm_page_allowed($pdo, $page)) {
                $target_file = 'access_denied.php';
            }

            // কোনো পেজ ফাইলে (যেমন পুরনো ডাটাবেসের স্কিমা-মিসম্যাচ) Exception/Fatal হলে আগে কনটেন্ট এরিয়া ফাঁকা সাদা
            // থাকত — এখন এরর মেসেজটা পেজেই দেখাবে, যাতে কারণ সাথে সাথে ধরা যায়
            try {
                include $target_file;
            } catch (Throwable $page_ex) {
                error_log('[School ERP page error] ' . $page . ': ' . $page_ex->getMessage());
                echo '<div class="alert alert-danger m-3"><b>পেজটি লোড করতে সমস্যা হয়েছে।</b><br>'
                    . '<code style="white-space:pre-wrap">' . htmlspecialchars($page_ex->getMessage()) . '</code><br>'
                    . '<small class="text-muted">' . htmlspecialchars(basename($page_ex->getFile()) . ':' . $page_ex->getLine()) . '</small></div>';
            }
            ?>
        </div>
    </div>

    <!-- CHANGE PASSWORD MODAL -->
    <div class="modal fade" id="changePasswordModal" <?php if (erp_is_demo()) echo 'style="display:none!important"'; ?> tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title fs-6" id="changePasswordModalLabel"><i class="fa-solid fa-key me-2"></i> Change Password</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold" style="font-size: 13px;">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold" style="font-size: 13px;">New Password</label>
                            <input type="password" name="new_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold" style="font-size: 13px;">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="change_password" <?php if (erp_is_demo()) echo 'disabled title="Disabled in public demo"'; ?> class="btn btn-success btn-sm">Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- সব পিসিতে/ব্রাউজারে একই তারিখ ফরম্যাট (dd/mm/yyyy): <input type="date"> এর দেখানো ফরম্যাট
         ব্রাউজার/OS-এর ভাষা-সেটিং ঠিক করে (কারো পিসিতে mm/dd/yyyy, কারো dd-----yyyy) — সার্ভার/CSS থেকে
         বদলানো যায় না। তাই flatpickr দিয়ে সব date ফিল্ডে একই dd/mm/yyyy দেখানো হয়; সার্ভারে যায় আগের
         মতোই yyyy-mm-dd, তাই PHP কোডে কোনো পরিবর্তন লাগে না। বন্ধ করতে চাইলে ফিল্ডে data-native-date দিন। -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
    <script>
    (function () {
        if (typeof flatpickr === 'undefined') { return; } // CDN না পেলে আগের নেটিভ date ফিল্ডই কাজ করবে
        var valueDesc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

        function enhance(el) {
            if (el._fp || el.hasAttribute('data-native-date') || el.type !== 'date') { return; }
            var busy = false;
            var fp = flatpickr(el, {
                dateFormat: 'Y-m-d',          // সার্ভারে যা যায় (আগের মতোই)
                altInput: true,
                altFormat: 'd/m/Y',           // ইউজার যা দেখে
                altInputClass: el.className,  // Bootstrap form-control স্টাইল অক্ষুণ্ণ
                allowInput: true,
                disableMobile: true,          // মোবাইলেও একই ফরম্যাট
                minDate: el.getAttribute('min') || null,
                maxDate: el.getAttribute('max') || null,
                clickOpens: !el.readOnly,
                onReady: function (sel, str, inst) { if (inst.altInput) { inst.altInput.placeholder = 'dd/mm/yyyy'; } }
            });
            el._fp = fp;
            // JS দিয়ে el.value = '2026-01-05' সেট করলে দেখানো ফিল্ডও যেন আপডেট হয়
            try {
                Object.defineProperty(el, 'value', {
                    configurable: true,
                    get: function () { return valueDesc.get.call(el); },
                    set: function (v) {
                        valueDesc.set.call(el, v);
                        if (busy) { return; }
                        busy = true;
                        try { fp.setDate(v || null, false); } finally { busy = false; }
                    }
                });
            } catch (e) { /* পুরনো ব্রাউজারে বাদ */ }
        }

        function run(root) {
            (root.querySelectorAll ? root.querySelectorAll('input[type="date"]') : []).forEach(enhance);
        }
        run(document);
        // পরে JS দিয়ে যোগ হওয়া (মোডাল/ডাইনামিক) date ফিল্ডও
        new MutationObserver(function (muts) {
            muts.forEach(function (m) {
                m.addedNodes.forEach(function (n) {
                    if (n.nodeType !== 1) { return; }
                    if (n.matches && n.matches('input[type="date"]')) { enhance(n); } else { run(n); }
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    })();
    </script>
    <script>
        document.getElementById('toggleSidebar').addEventListener('click', function () {
            document.getElementById('sidebar').classList.toggle('collapsed');
            document.getElementById('mainWrapper').classList.toggle('expanded');
        });
    </script>
    <script>
        // Mouse Scroll করলে ফোকাসড <input type="number"> এর ভ্যালু যেন ভুলবশত বদলে না যায় —
        // scroll করার সাথে সাথে inputটা blur (ফোকাস সরিয়ে) দেওয়া হচ্ছে, তাতে পেজ স্বাভাবিকভাবে
        // স্ক্রল হবে কিন্তু নাম্বার বদলাবে না। পুরো অ্যাপের সব Number ফিল্ডের জন্য একবারেই কাজ করে,
        // প্রতিটা ইনপুটে আলাদাভাবে কিছু করার দরকার নেই।
        document.addEventListener('wheel', function (e) {
            var el = document.activeElement;
            if (el && el.tagName === 'INPUT' && el.type === 'number') {
                el.blur();
            }
        }, { passive: true });
    </script>
<script>
// যেকোনো পেজে ফিল্টার/সার্চ ফর্ম (GET) Submit করার পর অটো রিপোর্ট/রেজাল্ট কার্ডে স্ক্রল।
// ফর্মের ফিল্ড-নাম URL-এ থাকলে বোঝা যায় ফর্মটাই Submit হয়েছে; তখন ফর্মের কার্ডের ঠিক পরের কার্ডে নামবে।
window.addEventListener('load', function () {
    var params = new URLSearchParams(window.location.search);
    var curPage = params.get('page');
    var keys = [];
    params.forEach(function (v, k) { if (k !== 'page') keys.push(k); });
    if (!curPage || !keys.length) return;

    var body = document.querySelector('.content-body');
    if (!body) return;
    var cards = Array.prototype.slice.call(body.querySelectorAll('.card-custom'));
    var target = null;

    Array.prototype.slice.call(body.querySelectorAll('form')).some(function (form) {
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get') return false;
        var pageInput = form.querySelector('input[name="page"]');
        if (!pageInput || pageInput.value !== curPage) return false;

        var names = Array.prototype.slice.call(form.elements).map(function (el) { return el.name; })
            .filter(function (n) { return n && n !== 'page'; });
        var submitted = names.some(function (n) { return keys.indexOf(n) !== -1; });
        if (!submitted) return false;

        var card = form.closest('.card-custom');
        if (!card) return false;
        var idx = cards.indexOf(card);
        var next = idx > -1 ? cards[idx + 1] : null;
        if (next && !card.contains(next)) { target = next; return true; }
        return false;
    });
    if (!target) return;

    var stickyEl = document.querySelector('.sticky-top-area');
    var offset = (stickyEl ? stickyEl.offsetHeight : 0) + 10;
    var top = target.getBoundingClientRect().top + window.pageYOffset - offset;
    if (top > 0) { window.scrollTo({ top: top, behavior: 'smooth' }); }
});
</script>
<?php
// সাইটজুড়ে Class সিলেক্ট করলে শুধু ওই Class-এর Shift ও Section দেখানো — config/class_scope_filter.php
if (isset($pdo)) { include __DIR__ . '/../config/class_scope_filter.php'; }
?>
</body>
</html>