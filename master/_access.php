<?php
// ══════════════════════════════════════════════════════════════
// School SaaS — Master Panel — Users / Roles / Access Control /
// Activity Log হেল্পার ফাংশন। _guard.php আর login.php দুই জায়গা
// থেকেই require হয়।
// ══════════════════════════════════════════════════════════════

// ── master_users টেবিল + পুরনো super_admin থেকে অটো-মাইগ্রেশন ──────
// প্রথমবার এই ফাংশন চললে পুরনো super_admin রো (গুলো) সুপারঅ্যাডমিন
// হিসেবে master_users এ কপি হয়ে যাবে — কিছু ম্যানুয়ালি করা লাগবে না।
function ensureMasterUsersTable($pdo) {
    static $done = false;
    if ($done) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS master_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        username VARCHAR(100) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('superadmin','admin','viewer') NOT NULL DEFAULT 'admin',
        email VARCHAR(150) DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_login DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // পুরনো super_admin থেকে migrate — শুধু master_users খালি থাকলে
    try {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM master_users")->fetchColumn();
        if ($count === 0) {
            $old = $pdo->query("SELECT * FROM super_admin")->fetchAll();
            foreach ($old as $o) {
                $pdo->prepare("INSERT IGNORE INTO master_users (name, username, password, role, is_active) VALUES (?,?,?,?,1)")
                    ->execute([$o['username'], $o['username'], $o['password'], 'superadmin']);
            }
        }
    } catch (Exception $e) { /* super_admin টেবিল না থাকলে সমস্যা নেই */ }

    $done = true;
}

function ensureUserMenuPermissionsTable($pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_menu_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        menu_key VARCHAR(100) NOT NULL,
        can_view TINYINT(1) DEFAULT 1,
        can_add  TINYINT(1) DEFAULT 0,
        can_edit TINYINT(1) DEFAULT 0,
        UNIQUE KEY user_menu (user_id, menu_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

// ── মেনু পারমিশন চেক ─────────────────────────────────────────
function masterCanAccess($pdo, $menuKey, $type = 'view') {
    $userId = $_SESSION['master_user_id'] ?? null;
    if (!$userId) return false;

    $role = $pdo->prepare("SELECT role FROM master_users WHERE id=?");
    $role->execute([$userId]);
    $role = $role->fetchColumn();

    if ($role === 'superadmin') return true;
    if ($role === 'viewer' && $type !== 'view') return false;

    ensureUserMenuPermissionsTable($pdo);
    $col = 'can_' . $type;
    $stmt = $pdo->prepare("SELECT $col FROM user_menu_permissions WHERE user_id=? AND menu_key=?");
    $stmt->execute([$userId, $menuKey]);
    $row = $stmt->fetch();

    if (!$row) {
        if ($type === 'view') return true;
        return ($role === 'admin');
    }
    return (bool) $row[$col];
}

function masterRequireAccess($pdo, $menuKey, $type = 'view') {
    if (!masterCanAccess($pdo, $menuKey, $type)) {
        if ($type === 'view') {
            $_SESSION['dash_msg'] = '⛔ এই পেজ দেখার অনুমতি নেই।';
            header('Location: dashboard.php');
            exit;
        }
    }
}

// ── Activity Log ──────────────────────────────────────────────
function ensureActivityLogTable($pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NULL,
        action VARCHAR(100) NOT NULL,
        details VARCHAR(255) DEFAULT '',
        done_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function logActivity($pdo, $tenant_id, $action, $details = '') {
    ensureActivityLogTable($pdo);
    $user_id = $_SESSION['master_user_id'] ?? null;
    $pdo->prepare("INSERT INTO activity_log (tenant_id, action, details, done_by) VALUES (?,?,?,?)")
        ->execute([$tenant_id, $action, $details, $user_id]);
}

// ── Idle Auto-Logout মিনিট সেটিং (settings.php > Security থেকে) ──
function getIdleTimeoutMinutes($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = 5; // ডিফল্ট
    try {
        ensureSaasSettingsTable($pdo);
        $stmt = $pdo->prepare("SELECT setting_value FROM saas_settings WHERE setting_key='idle_timeout_minutes'");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== '') $cache = max(0, (int) $val);
    } catch (Exception $e) { /* ডিফল্ট দিয়েই চলবে */ }
    return $cache;
}

// ── Billing টেবিল অটো-তৈরি (payment_requests + tenants-এ দরকারি কলাম) ──
// billing.php ও invoice_view.php দুই জায়গা থেকেই কল হয় — টেবিল না থাকলে
// আগে পুরো পেজ 500 এরর দিতো, এখন প্রথম visit-এই নিজে থেকে তৈরি হয়ে যাবে।
function ensureBillingTables($pdo) {
    static $done = false;
    if ($done) return;

    $ensureCol = function (PDO $pdo, string $table, string $column, string $definition) {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    };
    $ensureCol($pdo, 'tenants', 'last_payment_at', 'DATE NULL AFTER subscription_ends_at');
    $ensureCol($pdo, 'tenants', 'grace_days', 'INT NOT NULL DEFAULT 3 AFTER last_payment_at');

    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        method ENUM('bKash','Nagad') NOT NULL,
        sender_number VARCHAR(30) NOT NULL,
        transaction_id VARCHAR(50) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        months INT NOT NULL DEFAULT 1,
        note VARCHAR(255) NULL,
        status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
        admin_note VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at TIMESTAMP NULL,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $done = true;
}
