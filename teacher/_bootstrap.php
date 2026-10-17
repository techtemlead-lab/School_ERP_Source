<?php
/**
 * teacher/_bootstrap.php
 * -----------------------------------------------------------
 * Teacher Portal এর প্রতিটা পেজে কমন require — session, tenant DB
 * কানেকশন ($pdo), CSRF হেল্পার এবং প্রয়োজনীয় কলাম (employees.password)
 * নিরাপদে (idempotent) তৈরি করে দেয়। admin/ ফোল্ডারের কোনো ফাইল
 * পরিবর্তন করা হয়নি — এই ফাইলটাই শুধু নতুন যোগ হয়েছে।
 */

require_once __DIR__ . '/../config/runtime.php';
erp_session_start();
require_once __DIR__ . '/../config/db.php';   // $pdo (PDO) সেট হয় — tenant অনুযায়ী
require_once __DIR__ . '/../config/csrf.php'; // csrf_token() / csrf_field() / csrf_verify()
require_once __DIR__ . '/../config/captcha.php'; // captcha_new() / captcha_verify() / captcha_svg_markup()
// Teacher Portal-এ admin/ ফোল্ডারের কিছু পেজ সরাসরি include করা হয় (routine_view.php,
// homework.php, live_class.php, result_marks_entry_subject.php ইত্যাদি) — সেগুলো
// branch_current_id()/branch_apply_filter() আর sec_log_activity() ব্যবহার করে। আগে এই দুটো
// ফাইল শুধু admin/index.php তে require করা হতো, তাই Teacher Portal থেকে ওই পেজগুলো খুললে
// "Call to undefined function branch_current_id()" ফatal error হতো — এখানে require করায় ঠিক হয়ে গেছে।
require_once __DIR__ . '/../config/branch_scope.php';      // branch_current_id() / branch_apply_filter() ইত্যাদি
require_once __DIR__ . '/../config/security_helpers.php';  // sec_log_activity() — Activity Log

function tp_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $chk = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column))->fetch();
    if (!$chk) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

// Teacher/Employee লগইনের জন্য employees টেবিলে password কলাম না থাকলে যোগ করা হচ্ছে
tp_ensure_column($pdo, 'employees', 'password', "VARCHAR(255) NULL AFTER employee_id");
tp_ensure_column($pdo, 'employees', 'must_change_password', "TINYINT(1) NOT NULL DEFAULT 1 AFTER password");
// admin/ এর যেসব পেজ এখানে include হয় সেগুলো branch_id কলাম ধরে কোয়েরি করে — পুরনো
// ডাটাবেজে কলামগুলো না থাকলে এখানেই যোগ হয়ে যাবে (admin/index.php যেমন করে)
branch_ensure_column($pdo, 'employees');
branch_ensure_column($pdo, 'students');
branch_ensure_column($pdo, 'routines');

/**
 * লগইন করা টিচার কোন branch-এর, সেটা এই রিকোয়েস্টের জন্য লক করে দেয় — এর পরে
 * admin/ এর include করা পেজগুলোর branch_current_id()/branch_apply_filter() স্বয়ংক্রিয়ভাবে
 * শুধু ওই branch-এর ডাটাই দেখাবে। Branch সেট না থাকলে (একক ব্রাঞ্চের স্কুল) আগের মতোই সব দেখাবে।
 * সেশনে না বসিয়ে কনস্ট্যান্টে রাখা হয়েছে, যাতে একই ব্রাউজারে Admin লগইন থাকলে
 * অ্যাডমিনের branch scope-এ কোনো প্রভাব না পড়ে।
 */
function tp_apply_branch_scope(PDO $pdo): void
{
    if (defined('PORTAL_BRANCH_ID') || empty($_SESSION['teacher_logged']) || empty($_SESSION['teacher_id'])) { return; }
    try {
        // একাধিক Branch না থাকলে (সাধারণ একক-ব্রাঞ্চ স্কুল) কোনো branch scope-ই দরকার নেই —
        // সেক্ষেত্রে পুরনো (branch_id = NULL) ডাটাও আগের মতোই সব দেখা যাবে
        if (!branch_multi_branch_mode($pdo)) { define('PORTAL_BRANCH_ID', null); return; }
        $stmt = $pdo->prepare("SELECT branch_id FROM employees WHERE id = ? LIMIT 1");
        $stmt->execute([(int) $_SESSION['teacher_id']]);
        $branch_id = $stmt->fetchColumn();
        define('PORTAL_BRANCH_ID', ($branch_id === false || $branch_id === null || $branch_id === '') ? null : (int) $branch_id);
    } catch (\Throwable $e) {
        define('PORTAL_BRANCH_ID', null);
    }
}
tp_apply_branch_scope($pdo);

/**
 * Teacher Portal এ লগইন করা আছে কিনা চেক করে, না থাকলে login.php তে পাঠিয়ে দেয়।
 * প্রতিটা প্রোটেক্টেড পেজের শুরুতে কল করতে হবে।
 */
function tp_require_login(): void
{
    if (!erp_is_demo() && !empty($_SESSION['teacher_logged']) && !empty($_SESSION['teacher_id'])) {
        global $pdo;
        $q = $pdo->prepare('SELECT must_change_password FROM employees WHERE id=?');
        $q->execute([(int)$_SESSION['teacher_id']]);
        $required = $q->fetchColumn();
        $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($required === false || ($required && $scriptName !== 'index.php')) {
            erp_stop(403, 'Change your initial password from the portal dashboard before using other features.');
        }
    }
    if (empty($_SESSION['teacher_logged'])) {
        header('Location: login.php');
        exit();
    }
}

function tp_e($val): string
{
    return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8');
}
