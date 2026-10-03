<?php
// student/live_class_status.php - JS পোলিং এর জন্য ছোট JSON এন্ডপয়েন্ট
// (host ক্লাস "Ended" করলে student side script এটা দেখে অটো leave+redirect করে)
require_once __DIR__ . '/_bootstrap.php';
sp_require_login();

header('Content-Type: application/json');

$stmt0 = $pdo->prepare('SELECT class_id, section_id FROM students WHERE id = ? LIMIT 1');
$stmt0->execute([$_SESSION['student_pk']]);
$student = $stmt0->fetch();
if (!$student) { echo json_encode(['status' => 'Ended']); exit(); }

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT status FROM elearning_live_classes WHERE id = ? AND class_id = ?
     AND (section_id IS NULL OR section_id = ?)"
);
$stmt->execute([$id, $student['class_id'], $student['section_id']]);
$row = $stmt->fetch();

echo json_encode(['status' => $row['status'] ?? 'Ended']);
