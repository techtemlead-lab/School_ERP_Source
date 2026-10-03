<?php
// admin/ajax/get_next_student_id.php - Session+Class+Section+Shift সিলেক্ট হলে
// পরবর্তী Student ID ও Roll No প্রিভিউ করে দেয় (student_admission.php থেকে AJAX দিয়ে কল হয়)
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/../../config/db.php';

$session_id = (int) ($_GET['session_id'] ?? 0);
$class_id   = (int) ($_GET['class_id'] ?? 0);
$section_id = (int) ($_GET['section_id'] ?? 0);
$shift_id   = (int) ($_GET['shift_id'] ?? 0);

if (!$session_id || !$class_id) {
    echo json_encode(['success' => false, 'message' => 'Session ও Class সিলেক্ট করুন']);
    exit();
}

// Student ID: {Session শেষ ২ ডিজিট}{Class Code ২ ডিজিট}{৩ ডিজিট সিরিয়াল} — submit_admission_form.php এর সাথে একই লজিক
$session_name = $pdo->prepare("SELECT session_name FROM academic_sessions WHERE id = ?");
$session_name->execute([$session_id]);
$session_name = $session_name->fetchColumn() ?: '';
$yy = substr(preg_replace('/[^0-9]/', '', $session_name), -2) ?: date('y');

$class_code = $pdo->prepare("SELECT class_code FROM academic_classes WHERE id = ?");
$class_code->execute([$class_id]);
$class_code = $class_code->fetchColumn() ?: '00';
$class_code = str_pad(substr($class_code, 0, 2), 2, '0', STR_PAD_LEFT);

$prefix = $yy . $class_code;
$last = $pdo->prepare("SELECT student_id FROM students WHERE student_id LIKE ? ORDER BY student_id DESC LIMIT 1");
$last->execute([$prefix . '%']);
$last_id = $last->fetchColumn();
$serial = 1;
if ($last_id && strlen($last_id) >= 3) {
    $serial = ((int) substr($last_id, -3)) + 1;
}
$student_id = $prefix . str_pad((string) $serial, 3, '0', STR_PAD_LEFT);

// Roll No: একই Session+Class+Section+Shift এর মধ্যে পরবর্তী সিরিয়াল
$roll_no = 1;
if ($section_id && $shift_id) {
    $roll_stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM students WHERE session_id = ? AND class_id = ? AND section_id = ? AND shift_id = ?"
    );
    $roll_stmt->execute([$session_id, $class_id, $section_id, $shift_id]);
    $roll_no = (int) $roll_stmt->fetchColumn() + 1;
}

echo json_encode(['success' => true, 'student_id' => $student_id, 'roll_no' => (string) $roll_no]);
