<?php
// admin/ajax/student_id_next_ajax.php - Student Admission ফর্মের জন্য পরবর্তী
// Student ID এবং Roll No হিসাব করে দেয় (শুধু ADD ভিউতে ব্যবহার হয়, Edit-এ না)।
//
// Student ID ফরম্যাট: session short_code + class_code + 0001 (উদাহরণ: 26010001)।
// পরবর্তী serial নির্ধারণ হয় একই prefix-এর students টেবিলে
// এখন পর্যন্ত সবচেয়ে বড় serial খুঁজে +1 করে — তাই admin কোনো ID ম্যানুয়ালি এডিট করে
// সেভ করলেও, পরের ভর্তির সময় সেই edited নাম্বার থেকেই অটো-জেনারেশন শুরু হবে, আলাদা কোনো
// কাউন্টার টেবিল রাখার দরকার নেই।
//
// Roll No: একই Session + Class + Section এর মধ্যে সবচেয়ে বড় বিদ্যমান roll_no (সংখ্যা হিসেবে) থেকে +1।

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Session শেষ হয়ে গেছে, আবার লগইন করুন।']);
    exit();
}

require_once __DIR__ . '/../../config/db.php';

function out(array $data): void { echo json_encode($data); exit(); }

$session_id = (int) ($_GET['session_id'] ?? 0);
$class_id   = (int) ($_GET['class_id'] ?? 0);
$section_id = (int) ($_GET['section_id'] ?? 0);

if ($session_id <= 0 || $class_id <= 0 || $section_id <= 0) {
    out(['ok' => false, 'message' => 'Session, Class, Section — সবগুলো সিলেক্ট করুন।']);
}

// ----------------------------------------------------------------
// প্রয়োজনীয় কোডগুলো টেনে আনা (session short_code, class_code)
// ----------------------------------------------------------------
$sess_stmt = $pdo->prepare("SELECT short_code FROM academic_sessions WHERE id = ?");
$sess_stmt->execute([$session_id]);
$session_code = $sess_stmt->fetchColumn();

$class_stmt = $pdo->prepare("SELECT class_code FROM academic_classes WHERE id = ?");
$class_stmt->execute([$class_id]);
$class_code = $class_stmt->fetchColumn();

$missing = [];
if (!$session_code || !preg_match('/^\d{2}$/', $session_code)) $missing[] = 'Session Short Code (Academic Module > All Session)';
if (!$class_code || !preg_match('/^\d{2}$/', $class_code))     $missing[] = 'Class Code (Academic Module > All Class List)';

if ($missing) {
    out([
        'ok' => false,
        'message' => 'Student ID অটো-জেনারেট করতে নিচের কোড(গুলো) সেট করা নেই বা ২ ডিজিটের না: ' . implode(', ', $missing),
    ]);
}

$prefix = $session_code . $class_code;

// ----------------------------------------------------------------
// Student ID: prefix মিলে এমন সবার মধ্যে সবচেয়ে বড় serial বের করে +1
// ----------------------------------------------------------------
$id_stmt = $pdo->prepare("SELECT student_id FROM students WHERE student_id LIKE ?");
$id_stmt->execute([$prefix . '%']);
$existing_ids = $id_stmt->fetchAll(PDO::FETCH_COLUMN);

$max_serial = 0;
$prefix_len = strlen($prefix);
foreach ($existing_ids as $sid) {
    $suffix = substr($sid, $prefix_len);
    if (preg_match('/^\d+$/', $suffix)) {
        $max_serial = max($max_serial, (int) $suffix);
    }
}
$next_serial = $max_serial + 1;
$next_student_id = $prefix . str_pad((string) $next_serial, 4, '0', STR_PAD_LEFT);

// ----------------------------------------------------------------
// Roll No: একই Session + Class + Section এর মধ্যে সবচেয়ে বড় বিদ্যমান roll_no থেকে +1
// ----------------------------------------------------------------
$roll_stmt = $pdo->prepare(
    "SELECT roll_no FROM students WHERE session_id = ? AND class_id = ? AND section_id = ?"
);
$roll_stmt->execute([$session_id, $class_id, $section_id]);
$existing_rolls = $roll_stmt->fetchAll(PDO::FETCH_COLUMN);

$max_roll = 0;
foreach ($existing_rolls as $r) {
    if (preg_match('/^\d+$/', $r)) {
        $max_roll = max($max_roll, (int) $r);
    }
}
$next_roll = (string) ($max_roll + 1);

out([
    'ok' => true,
    'student_id' => $next_student_id,
    'roll_no' => $next_roll,
]);
