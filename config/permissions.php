<?php
// config/permissions.php
// -----------------------------------------------------------
// Super Admin অন্য system_users (Admin/Accountant/HR/Data Entry Operator/
// Demo ইত্যাদি) কে নির্দিষ্ট মেনু/সাবমেনু পেজে এক্সেস দিতে বা আটকাতে পারে —
// User Management > User Permission পেজ থেকে (শুধু Super Admin দেখতে/ব্যবহার
// করতে পারবে)।
//
// নিয়ম:
// - Super Admin সবসময় সব পেজেই এক্সেস পাবে, এই ফাইলের কোনো চেক তার উপর প্রযোজ্য না।
// - system_users.permissions_restricted = 0 (ডিফল্ট) মানে ঐ ইউজারের জন্য এখনো
//   কোনো restriction সেট করা হয়নি — তাই আগের মতোই সব পেজে এক্সেস থাকবে (backward
//   compatible, পুরনো ইউজার হঠাৎ লক-আউট হয়ে যাবে না)।
// - permissions_restricted = 1 হলে ঐ ইউজার শুধু user_menu_permissions টেবিলে
//   তার জন্য যে page_key গুলো সেভ করা আছে, শুধু সেগুলোতেই ঢুকতে পারবে। কোনো
//   key সেভ করা না থাকলে (restricted কিন্তু ০টা পেজ) সে শুধু Dashboard দেখবে।
// - Role অনুযায়ী পারমিশন (role_menu_permissions + role_permission_settings):
//   Super Admin একটা Role (যেমন Accountant) এর জন্য একবার মেনু/পেজ সিলেক্ট করলে ঐ Role-এর
//   সব ইউজার সেটা পাবে। ইউজারের নিজস্ব Restrict চালু থাকলে সেটাই অগ্রাধিকার পায় (User > Role > ডিফল্ট সব)।
// - এই পুরো সিস্টেম "মডিউল/পেজ" লেভেলে কাজ করে — User Management, System &
//   Security, Database Reset এই তিনটা মডিউলের জন্য প্রযোজ্য না, ওগুলো আগে থেকেই
//   role-based (is_super_admin / user_role==='Admin') ভাবে গার্ড করা আছে।
// -----------------------------------------------------------

function perm_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_menu_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            system_user_id INT NOT NULL,
            page_key VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_page (system_user_id, page_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Role অনুযায়ী পারমিশন (user_type ভিত্তিক)
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS role_menu_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_type VARCHAR(30) NOT NULL,
            page_key VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_role_page (user_type, page_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS role_permission_settings (
            user_type VARCHAR(30) NOT NULL PRIMARY KEY,
            restricted TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // system_users.permissions_restricted কলাম না থাকলে যোগ করা (পুরনো ডাটাবেজ)
    $chk = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_users' AND COLUMN_NAME = 'permissions_restricted'"
    );
    $chk->execute();
    if (!$chk->fetch()) {
        $pdo->exec("ALTER TABLE system_users ADD COLUMN permissions_restricted TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }
}

// লগইন করা ইউজার যে system_users.id এর, সেটা সেশন থেকে বের করে
function perm_current_system_user_id(): ?int
{
    return isset($_SESSION['system_user_id']) ? (int) $_SESSION['system_user_id'] : null;
}

function perm_is_super_admin(): bool
{
    return !empty($_SESSION['is_super_admin']);
}

// একটা Role (user_type) এর পারমিশন: restricted না হলে null (মানে Role-এ কোনো সীমা নেই), হলে allowed page_key-এর তালিকা
function perm_role_allowed_keys(PDO $pdo, string $user_type): ?array
{
    perm_ensure_tables($pdo);
    $st = $pdo->prepare("SELECT restricted FROM role_permission_settings WHERE user_type = ?");
    $st->execute([$user_type]);
    if (!(int) $st->fetchColumn()) { return null; }
    $st2 = $pdo->prepare("SELECT page_key FROM role_menu_permissions WHERE user_type = ?");
    $st2->execute([$user_type]);
    return $st2->fetchAll(PDO::FETCH_COLUMN);
}

// একজন নির্দিষ্ট system_user-এর জন্য restricted mode চালু আছে কিনা, আর থাকলে
// কোন কোন page_key allowed — একবার কুয়েরি করে রিকোয়েস্ট জুড়ে static ক্যাশ রাখা হয়
function perm_load(PDO $pdo, int $system_user_id): array
{
    static $cache = [];
    if (isset($cache[$system_user_id])) return $cache[$system_user_id];

    perm_ensure_tables($pdo);

    $stmt = $pdo->prepare("SELECT permissions_restricted, user_type FROM system_users WHERE id = ?");
    $stmt->execute([$system_user_id]);
    $urow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $restricted = !empty($urow['permissions_restricted']);
    $source = $restricted ? 'user' : 'none';

    $allowed = [];
    if ($restricted) {
        $stmt2 = $pdo->prepare("SELECT page_key FROM user_menu_permissions WHERE system_user_id = ?");
        $stmt2->execute([$system_user_id]);
        $allowed = $stmt2->fetchAll(PDO::FETCH_COLUMN);
    } elseif (!empty($urow['user_type'])) {
        // ইউজারের নিজস্ব Restrict নেই — তার Role-এর পারমিশন থাকলে সেটা প্রযোজ্য
        $allowed = perm_role_allowed_keys($pdo, (string) $urow['user_type']);
        if ($allowed !== null) { $restricted = true; $source = 'role'; } else { $allowed = []; }
    }

    return $cache[$system_user_id] = ['restricted' => $restricted, 'allowed' => $allowed, 'source' => $source];
}

// একটা নির্দিষ্ট পেজ (page_map এর key) এ বর্তমান লগইন করা ইউজারের এক্সেস আছে কিনা
function perm_page_allowed(PDO $pdo, string $page_key): bool
{
    if (perm_is_super_admin()) return true;

    $uid = perm_current_system_user_id();
    if (!$uid) return true; // safety fallback — session ঠিকমতো সেট না হলে ব্লক না করা

    $p = perm_load($pdo, $uid);
    if (!$p['restricted']) return true;

    return in_array($page_key, $p['allowed'], true);
}

// একটা মডিউলে (sidebar-এর একটা top-level menu) কমপক্ষে একটা পেজে এক্সেস আছে
// কিনা — থাকলে মডিউলটা sidebar-এ দেখানো হবে, না থাকলে পুরো মডিউলটাই hide
function perm_module_allowed(PDO $pdo, array $leaf_keys): bool
{
    if (perm_is_super_admin()) return true;

    $uid = perm_current_system_user_id();
    if (!$uid) return true;

    $p = perm_load($pdo, $uid);
    if (!$p['restricted']) return true;

    foreach ($leaf_keys as $key) {
        if (in_array($key, $p['allowed'], true)) return true;
    }
    return false;
}

// menu_permissions_tree.php থেকে একটা মডিউলের সব leaf page_key ফ্ল্যাট করে বের করা
function perm_flatten_module_keys(array $module): array
{
    $keys = [];
    foreach ($module['children'] as $child) {
        if ($child['type'] === 'leaf') {
            $keys[] = $child['key'];
        } else {
            foreach ($child['children'] as $leaf) {
                $keys[] = $leaf['key'];
            }
        }
    }
    return $keys;
}

/**
 * AJAX এন্ডপয়েন্টের জন্য: "Read-only" (শুধু View) পারমিশন লেভেল থাকলে POST (সেভ/মুছা) আটকানোর হুক।
 * এই প্রজেক্টে পেজ-পারমিশন শুধু On/Off (Read-only লেভেল নেই), তাই এখন কিছু আটকায় না — কিন্তু result_*_ajax.php
 * ফাইলগুলো (Skooly থেকে আনা) এই ফাংশন কল করে, তাই থাকা দরকার। ভবিষ্যতে Read/Write লেভেল যোগ হলে শুধু এটা বদলালেই হবে।
 */
if (!function_exists('perm_write_guard')) {
    function perm_write_guard(PDO $pdo, string $page_key): void
    {
        if (function_exists('perm_can_write') && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !perm_can_write($pdo, $page_key)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'ok' => false, 'message' => 'আপনার এই পেজে সেভ/পরিবর্তনের অনুমতি নেই।']);
            exit();
        }
    }
}
