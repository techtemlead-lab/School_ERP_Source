<?php
// config/branch_scope.php
// -----------------------------------------------------------
// কেন্দ্রীয় Branch Scoping হেল্পার — যেকোনো মডিউল যেন একই নিয়মে branch
// অনুযায়ী ডাটা ফিল্টার/সেভ করে, সেজন্য সব হেল্পার এই একটাই জায়গায়।
//
// নিয়ম:
// - system_users.branch_id সেট থাকা ইউজার (Super Admin ছাড়া বাকি সবাই) শুধু
//   নিজের branch-এর ডাটা দেখবে/এডিট করবে — session-এ লগইনের সময়ই এটা লক হয়ে যায়।
// - Super Admin ডিফল্টভাবে "All Branch" মোডে থাকে (সব branch-এর ডাটা দেখা যায়),
//   কিন্তু চাইলে branch switcher থেকে একটা নির্দিষ্ট branch সিলেক্ট করে সেই
//   branch-এর view-তেও যেতে পারবে (session-এ active_branch_id হিসেবে থাকবে)।
// - branch_id = NULL থাকা পুরনো (এই ফিচার আসার আগের) রেকর্ডকে "সব branch থেকেই
//   দেখা যাবে" হিসেবে ধরা হয়, যাতে branch ফিচার চালু হওয়ার আগের ডাটা হারিয়ে না যায়।
// -----------------------------------------------------------

function branch_is_super_admin(): bool
{
    return !empty($_SESSION['is_super_admin']);
}

// এই মুহূর্তে যে branch-এ ডাটা সেভ/ফিল্টার হবে তার id। null মানে "All Branch"।
function branch_current_id(): ?int
{
    // Teacher/Student পোর্টাল — এই পোর্টালে লগইন করা ইউজার সবসময় নিজের branch-এই লকড।
    // এটা session-এ না রেখে per-request কনস্ট্যান্ট (teacher/_bootstrap.php ও
    // student/_bootstrap.php এ define করা) হিসেবে রাখা হয়েছে, যাতে একই ব্রাউজারে
    // Admin প্যানেলেও লগইন থাকলে অ্যাডমিনের নিজের branch scope নষ্ট না হয়।
    if (defined('PORTAL_BRANCH_ID')) {
        return (PORTAL_BRANCH_ID === null || PORTAL_BRANCH_ID === '') ? null : (int) PORTAL_BRANCH_ID;
    }

    if (branch_is_super_admin()) {
        return (isset($_SESSION['active_branch_id']) && $_SESSION['active_branch_id'] !== '')
            ? (int) $_SESSION['active_branch_id']
            : null;
    }
    return (isset($_SESSION['branch_id']) && $_SESSION['branch_id'] !== '')
        ? (int) $_SESSION['branch_id']
        : null;
}

// নতুন রেকর্ড INSERT করার সময় কোন branch_id বসবে
function branch_id_for_insert(): ?int
{
    return branch_current_id();
}

// সাধারণ ইউজার (Super Admin না) কি একটা নির্দিষ্ট branch-এ লকড? — Add New এর মতো
// অ্যাকশনে branch dropdown দেখানো দরকার কিনা বোঝার জন্য
function branch_is_locked(): bool
{
    return !branch_is_super_admin() && branch_current_id() !== null;
}

// লিস্ট/রিপোর্ট কোয়েরিতে সরাসরি $where[]/$params[] অ্যারেতে push করার জন্য।
// ব্যবহার: branch_apply_filter($where, $params, 'students.branch_id');
// branch_current_id() null (All Branch) হলে কিছুই যোগ হয় না।
// নোট: strict filter — branch_id NULL থাকা (branch ফিচার আসার আগের) রেকর্ড
// আর কোনো branch-এ দেখাবে না। সেই রেকর্ডগুলো এখন থেকে শুধু All Branch (Super
// Admin) ভিউতে দেখা যাবে, যতক্ষণ না সেগুলোকে কোনো একটা branch-এ এডিট করে বসানো হয়।
function branch_apply_filter(array &$where, array &$params, string $column = 'branch_id'): void
{
    $bid = branch_current_id();
    if ($bid === null) {
        return; // All Branch mode — কোনো ফিল্টার নেই
    }
    // Teacher/Student পোর্টালে (PORTAL_BRANCH_ID সেট থাকলে) branch_id = NULL থাকা পুরনো
    // রেকর্ডগুলোও দেখানো হয় — না হলে branch ফিচার চালু হওয়ার আগে তৈরি হওয়া ছাত্র/ডাটা
    // টিচারের কাছে হঠাৎ "হারিয়ে" যায় (যেমন হোমওয়ার্ক সাবমিশনের লিস্ট ফাঁকা দেখায়)।
    if (defined('PORTAL_BRANCH_ID')) {
        $where[] = "($column = ? OR $column IS NULL)";
        $params[] = $bid;
        return;
    }
    $where[] = "$column = ?";
    $params[] = $bid;
}

// কোনো টেবিলে branch_id কলাম না থাকলে নিরাপদে যোগ করে দেওয়া (self-healing migration)
// টেবিলটাই এখনো তৈরি না হয়ে থাকলে (fresh install, মডিউল কখনো ভিজিট করা হয়নি) চুপচাপ স্কিপ করে
function branch_ensure_column(PDO $pdo, string $table): void
{
    static $checked = [];
    if (isset($checked[$table])) return;
    $checked[$table] = true;

    $tbl_chk = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $tbl_chk->execute([$table]);
    if (!$tbl_chk->fetch()) {
        return; // টেবিল নেই — এই মডিউল এখনো ব্যবহার হয়নি, পরে ভিজিট করলে নিজে থেকেই তৈরি হবে
    }

    $chk = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'branch_id'"
    );
    $chk->execute([$table]);
    if (!$chk->fetch()) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN branch_id INT NULL");
    }
}

// Active branch লিস্ট — dropdown/switcher-এ ব্যবহারের জন্য
function branch_list_active(PDO $pdo): array
{
    return $pdo->query("SELECT id, short_name, branch_name FROM branches WHERE status = 'Active' ORDER BY id ASC")->fetchAll();
}

// হেডারে দেখানোর জন্য — এই মুহূর্তে session যে branch-এ লকড আছে তার আসল নাম।
// branch_current_id() null হলে (Super Admin এর "All Branch" মোড, অথবা branch
// ফিচার আসার আগের legacy user) 'All Branch' রিটার্ন করে।
function branch_current_name(PDO $pdo): string
{
    $bid = branch_current_id();
    if ($bid === null) {
        return 'All Branch';
    }
    static $cache = [];
    if (isset($cache[$bid])) {
        return $cache[$bid];
    }
    $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ? LIMIT 1");
    $stmt->execute([$bid]);
    $name = $stmt->fetchColumn();
    return $cache[$bid] = ($name !== false ? $name : 'All Branch');
}

// একাধিক branch আছে কিনা — না থাকলে UI-তে branch select/switcher লুকিয়ে রাখা যায়
function branch_multi_branch_mode(PDO $pdo): bool
{
    static $count = null;
    if ($count === null) {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM branches WHERE status = 'Active'")->fetchColumn();
    }
    return $count > 1;
}


// ---------------------------------------------------------------------------
// Branch আইসোলেশন হেল্পার — যেসব পেজে $where/$params অ্যারে নেই সেখানে সহজে ব্যবহার করার জন্য
// ---------------------------------------------------------------------------

/** SQL-এ সরাসরি জোড়ার জন্য " AND col = N" (N পূর্ণসংখ্যা, তাই ইনজেকশন-নিরাপদ)। All Branch হলে খালি স্ট্রিং। */
if (!function_exists('branch_sql_inline')) {
    function branch_sql_inline(string $column): string
    {
        $bid = branch_current_id();
        if ($bid === null) { return ''; }
        $bid = (int) $bid;
        return defined('PORTAL_BRANCH_ID') ? " AND ($column = $bid OR $column IS NULL)" : " AND $column = $bid";
    }
}

/** prepared statement-এর জন্য: [' AND col = ?', [params]] */
if (!function_exists('branch_sql_and')) {
    function branch_sql_and(string $column): array
    {
        $w = []; $p = [];
        branch_apply_filter($w, $p, $column);
        return [$w ? ' AND ' . implode(' AND ', $w) : '', $p];
    }
}

/** এই student_pk টা বর্তমান ইউজারের branch-এর কিনা (id দিয়ে সরাসরি অ্যাক্সেস আটকাতে) */
if (!function_exists('branch_student_ok')) {
    function branch_student_ok(PDO $pdo, int $student_pk): bool
    {
        [$f, $p] = branch_sql_and('branch_id');
        $st = $pdo->prepare('SELECT 1 FROM students WHERE id = ?' . $f);
        $st->execute(array_merge([$student_pk], $p));
        return (bool) $st->fetchColumn();
    }
}
