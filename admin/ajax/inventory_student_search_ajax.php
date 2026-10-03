<?php
// admin/ajax/inventory_student_search_ajax.php - Sales (POS) পেজে Student লাইভ সার্চ (Autosuggest)-এর জন্য
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session শেষ হয়ে গেছে, আবার লগইন করুন।']);
    exit();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/branch_scope.php';

$action = $_GET['action'] ?? '';

if ($action === 'search') {
    $q = trim($_GET['q'] ?? '');
    if ($q === '') {
        echo json_encode(['success' => true, 'students' => []]);
        exit();
    }
    $like = '%' . $q . '%';
    $issa_where = ['(name_en LIKE ? OR student_id LIKE ? OR roll_no LIKE ?)'];
    $issa_params = [$like, $like, $like];
    branch_apply_filter($issa_where, $issa_params);
    $stmt = $pdo->prepare(
        "SELECT id, student_id, roll_no, name_en FROM students WHERE " . implode(' AND ', $issa_where) . " ORDER BY name_en LIMIT 15"
    );
    $stmt->execute($issa_params);
    echo json_encode(['success' => true, 'students' => $stmt->fetchAll()]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
