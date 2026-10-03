<?php
// ajax/public_student_id_ajax.php - পাবলিক Online Admission ফর্মের (admission.php) জন্য
// পরবর্তী Student ID হিসাব করে দেয়। এটা লগইন ছাড়াই যে কেউ কল করতে পারে (পাবলিক ফর্মের অংশ),
// তাই এখানে কোনো session/admin_logged চেক নেই — শুধু read-only হিসাব, কোনো state বদলায় না।
//
// ফরম্যাট backend (admin/admin/ajax/student_id_next_ajax.php) এর সাথে হুবহু একই:
// session short_code + medium_code + class_code + section_code + 0001
//
// এই এন্ডপয়েন্ট থেকে যা রিটার্ন হয়, সেটা শুধু ভিজিটরকে "প্রিভিউ" হিসেবে দেখানো হয় —
// submit_admission.php ফাইনাল সেভের সময় client থেকে আসা কোনো ID বিশ্বাস না করে
// আবার নিজে থেকেই ফ্রেশ হিসাব করে, তাই এই endpoint থেকে কেউ manipulate করলেও আসল
// রেকর্ডে কোনো প্রভাব পড়ে না।

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

function pub_id_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
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
pub_id_ensure_column($pdo, 'admissions', 'session_id', "INT NULL AFTER apply_group");
pub_id_ensure_column($pdo, 'admissions', 'class_id', "INT NULL AFTER session_id");
pub_id_ensure_column($pdo, 'admissions', 'section_id', "INT NULL AFTER class_id");
pub_id_ensure_column($pdo, 'admissions', 'student_id', "VARCHAR(50) NULL UNIQUE AFTER section_id");
pub_id_ensure_column($pdo, 'academic_sections', 'section_code', "VARCHAR(5) NULL AFTER section_name");

function out(array $data): void { echo json_encode($data); exit(); }

$session_id = (int) ($_GET['session_id'] ?? 0);
$class_id   = (int) ($_GET['class_id'] ?? 0);
$section_id = (int) ($_GET['section_id'] ?? 0);

if ($session_id <= 0 || $class_id <= 0 || $section_id <= 0) {
    out(['ok' => false, 'message' => 'সেশন, শ্রেণি ও শাখা — সবগুলো নির্বাচন করুন।']);
}

// শুধু Active সেশন/ক্লাস/সেকশন থেকেই আইডি জেনারেট হবে (ভিজিটর URL ম্যানিপুলেট করে
// অন্য কোনো id পাঠালেও, সেটা Active লিস্টে না থাকলে কোড পাওয়া যাবে না)
$sess_stmt = $pdo->prepare("SELECT short_code FROM academic_sessions WHERE id = ? AND status = 'Active'");
$sess_stmt->execute([$session_id]);
$session_code = $sess_stmt->fetchColumn();

$class_stmt = $pdo->prepare(
    "SELECT ac.class_code, am.medium_code
     FROM academic_classes ac
     LEFT JOIN academic_mediums am ON am.id = ac.medium_id
     WHERE ac.id = ? AND ac.status = 'Active'"
);
$class_stmt->execute([$class_id]);
$class_row = $class_stmt->fetch();
$class_code  = $class_row['class_code'] ?? null;
$medium_code = $class_row['medium_code'] ?? null;

$sec_stmt = $pdo->prepare("SELECT section_code FROM academic_sections WHERE id = ? AND class_id = ? AND status = 'Active'");
$sec_stmt->execute([$section_id, $class_id]);
$section_code = $sec_stmt->fetchColumn();

$missing = !$session_code || !preg_match('/^\d{2}$/', $session_code)
    || !$medium_code || !preg_match('/^\d{2}$/', $medium_code)
    || !$class_code || !preg_match('/^\d{2}$/', $class_code)
    || !$section_code || !preg_match('/^\d{2}$/', $section_code);

if ($missing) {
    out(['ok' => false, 'message' => 'এই সেশন/শ্রেণি/শাখার জন্য আইডি জেনারেট করা যাচ্ছে না, একটু পর আবার চেষ্টা করুন বা আমাদের সাথে যোগাযোগ করুন।']);
}

$prefix = $session_code . $medium_code . $class_code . $section_code;

// ইতিমধ্যে ভর্তি হওয়া students এবং অন্য pending online আবেদন — দুই জায়গাতেই একই prefix-এর
// মধ্যে সবচেয়ে বড় serial খুঁজে +1 করা হয়, যাতে দুইজন ভিজিটর একই আইডি না পায়
$id_stmt = $pdo->prepare("SELECT student_id FROM students WHERE student_id LIKE ?");
$id_stmt->execute([$prefix . '%']);
$existing_ids = $id_stmt->fetchAll(PDO::FETCH_COLUMN);

$adm_stmt = $pdo->prepare("SELECT student_id FROM admissions WHERE student_id LIKE ?");
$adm_stmt->execute([$prefix . '%']);
$existing_ids = array_merge($existing_ids, $adm_stmt->fetchAll(PDO::FETCH_COLUMN));

$max_serial = 0;
$prefix_len = strlen($prefix);
foreach ($existing_ids as $sid) {
    $suffix = substr((string) $sid, $prefix_len);
    if (preg_match('/^\d+$/', $suffix)) {
        $max_serial = max($max_serial, (int) $suffix);
    }
}
$next_serial = $max_serial + 1;
$next_student_id = $prefix . str_pad((string) $next_serial, 4, '0', STR_PAD_LEFT);

out(['ok' => true, 'student_id' => $next_student_id]);
