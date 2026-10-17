<?php
/**
 * student/_bootstrap.php
 * -----------------------------------------------------------
 * Student/Guardian পোর্টালের প্রতিটা পেজে কমন require — session,
 * tenant DB কানেকশন ($pdo), CSRF/Captcha হেল্পার এবং প্রয়োজনীয় কলাম
 * (students.password ইত্যাদি) নিরাপদে (idempotent) তৈরি করে দেয়।
 * admin/ ফোল্ডারের কোনো ফাইল পরিবর্তন করা হয়নি — এই ফোল্ডারটাই নতুন।
 */

session_start();
require_once __DIR__ . '/../config/db.php';      // $pdo (PDO) সেট হয় — tenant অনুযায়ী
require_once __DIR__ . '/../config/csrf.php';    // csrf_token() / csrf_field() / csrf_verify()
require_once __DIR__ . '/../config/captcha.php'; // captcha_new() / captcha_verify() / captcha_svg_markup()
// Student Portal-এ admin/ ফোল্ডারের কিছু ফাইল include হয় (finance_due_report_common.php,
// partials/live_class_embed.php ইত্যাদি) — সেগুলো branch_apply_filter()/branch_current_id()
// আর sec_log_activity() ব্যবহার করতে পারে। আগে এগুলো শুধু admin/index.php তে require করা
// হতো, তাই পোর্টাল থেকে খুললে "Call to undefined function" fatal error হওয়ার ঝুঁকি ছিল।
require_once __DIR__ . '/../config/branch_scope.php';      // branch_current_id() / branch_apply_filter() ইত্যাদি
require_once __DIR__ . '/../config/security_helpers.php';  // sec_log_activity() — Activity Log

function sp_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    // টেবিল না থাকলে বা ALTER ব্যর্থ হলে (পুরনো/আংশিক ডাটাবেজ) লগইন পেজ যেন crash না করে
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column))->fetch();
        if (!$chk) {
            try {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            } catch (\Throwable $e) {
                // AFTER <কলাম> নেই — কলামটা শেষে যোগ করার চেষ্টা
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` " . preg_replace('/\s+AFTER\s+\w+\s*$/i', '', $definition));
            }
        }
    } catch (\Throwable $e) {
        error_log('[student-bootstrap] ' . $table . '.' . $column . ': ' . $e->getMessage());
    }
}

// Student/Guardian লগইনের জন্য students টেবিলে দরকারি কলাম না থাকলে যোগ করা হচ্ছে
sp_ensure_column($pdo, 'students', 'password', "VARCHAR(255) NULL");
sp_ensure_column($pdo, 'students', 'must_change_password', "TINYINT(1) NOT NULL DEFAULT 1");
sp_ensure_column($pdo, 'students', 'branch_id', "INT NULL");

/**
 * লগইন করা শিক্ষার্থী কোন branch-এর, সেটা এই রিকোয়েস্টের জন্য লক করে দেয় — এর পরে
 * admin/ থেকে include হওয়া হেল্পারগুলোর branch_current_id()/branch_apply_filter()
 * স্বয়ংক্রিয়ভাবে শুধু ওই branch-এর ডাটাই ধরবে। সেশনে না বসিয়ে কনস্ট্যান্টে রাখা হয়েছে,
 * যাতে একই ব্রাউজারে Admin লগইন থাকলে অ্যাডমিনের branch scope-এ প্রভাব না পড়ে।
 */
function sp_apply_branch_scope(PDO $pdo): void
{
    if (defined('PORTAL_BRANCH_ID') || empty($_SESSION['student_logged']) || empty($_SESSION['student_pk'])) { return; }
    try {
        // একাধিক Branch না থাকলে কোনো branch scope-ই দরকার নেই (পুরনো ডাটাও আগের মতোই দেখা যাবে)
        if (!branch_multi_branch_mode($pdo)) { define('PORTAL_BRANCH_ID', null); return; }
        $stmt = $pdo->prepare("SELECT branch_id FROM students WHERE id = ? LIMIT 1");
        $stmt->execute([(int) $_SESSION['student_pk']]);
        $branch_id = $stmt->fetchColumn();
        define('PORTAL_BRANCH_ID', ($branch_id === false || $branch_id === null || $branch_id === '') ? null : (int) $branch_id);
    } catch (\Throwable $e) {
        define('PORTAL_BRANCH_ID', null);
    }
}
sp_apply_branch_scope($pdo);

/**
 * Student Portal এ লগইন করা আছে কিনা চেক করে, না থাকলে login.php তে পাঠিয়ে দেয়।
 * প্রতিটা প্রোটেক্টেড পেজের শুরুতে কল করতে হবে।
 */
function sp_require_login(): void
{
    if (empty($_SESSION['student_logged'])) {
        header('Location: login.php');
        exit();
    }
}

function sp_e($val): string
{
    return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8');
}

/** ক্লাস/সেকশন/সেশনের নাম বের করার ছোট হেল্পার — একাধিক পেজে দরকার হবে */
function sp_lookup_name(PDO $pdo, string $table, ?int $id): string
{
    if (!$id) { return '—'; }
    static $cache = [];
    $key = $table . ':' . $id;
    if (isset($cache[$key])) { return $cache[$key]; }
    try {
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $name = $row['name'] ?? $row['session_name'] ?? $row['class_name'] ?? $row['section_name'] ?? $row['shift_name'] ?? '—';
        return $cache[$key] = (string) $name;
    } catch (\Throwable $e) {
        return '—';
    }
}
