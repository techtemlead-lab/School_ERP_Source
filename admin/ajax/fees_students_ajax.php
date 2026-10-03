<?php
// admin/ajax/fees_students_ajax.php - Student Fees Collection পেজে Session/Class বদলালে
// পেজ রিলোড ছাড়াই সেই Session+Class-এর Active Student তালিকা আনার জন্য।
// Branch scope মেনে ফিল্টার হয় (finance_fees_collection.php-এর মূল কোয়েরির সাথে হুবহু এক)।
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session শেষ হয়ে গেছে, আবার লগইন করুন।']);
    exit();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/branch_scope.php';

$session_id = (int) ($_GET['session_id'] ?? 0);
$class_id   = (int) ($_GET['class_id'] ?? 0);

$students = [];
if ($session_id) {
    $sql = "SELECT id, student_id, roll_no, name_en FROM students WHERE session_id = ? AND status = 'Active'";
    $params = [$session_id];
    if ($class_id) { $sql .= " AND class_id = ?"; $params[] = $class_id; }
    $where = []; $wparams = [];
    branch_apply_filter($where, $wparams);
    if ($where) { $sql .= ' AND ' . implode(' AND ', $where); $params = array_merge($params, $wparams); }
    $sql .= " ORDER BY CAST(roll_no AS UNSIGNED) ASC, roll_no ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    foreach ($st->fetchAll() as $s) {
        $students[] = ['id' => (int) $s['id'], 'label' => $s['student_id'] . ' - ' . $s['name_en'] . ' - ' . $s['roll_no']];
    }
}

echo json_encode(['success' => true, 'students' => $students], JSON_UNESCAPED_UNICODE);
