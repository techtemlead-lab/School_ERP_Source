<?php
// admin/ajax/canteen_person_search_ajax.php - Canteen POS-এ Student + Teacher একসাথে লাইভ সার্চ (Autosuggest)
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
        echo json_encode(['success' => true, 'results' => []]);
        exit();
    }
    $like = '%' . $q . '%';

    $cpsa_st_where = ['(name_en LIKE ? OR student_id LIKE ? OR roll_no LIKE ?)'];
    $cpsa_st_params = [$like, $like, $like];
    branch_apply_filter($cpsa_st_where, $cpsa_st_params);
    $stStmt = $pdo->prepare(
        "SELECT id, student_id AS code, roll_no, name_en AS name FROM students
         WHERE " . implode(' AND ', $cpsa_st_where) . " ORDER BY name_en LIMIT 10"
    );
    $stStmt->execute($cpsa_st_params);
    $students = $stStmt->fetchAll();

    $cpsa_em_where = ['is_current = 1', '(name LIKE ? OR employee_id LIKE ?)'];
    $cpsa_em_params = [$like, $like];
    branch_apply_filter($cpsa_em_where, $cpsa_em_params);
    $emStmt = $pdo->prepare(
        "SELECT id, employee_id AS code, name FROM employees
         WHERE " . implode(' AND ', $cpsa_em_where) . " ORDER BY name LIMIT 10"
    );
    $emStmt->execute($cpsa_em_params);
    $employees = $emStmt->fetchAll();

    $results = [];
    foreach ($students as $s) {
        $results[] = ['type' => 'student', 'id' => $s['id'], 'code' => $s['code'], 'name' => $s['name'], 'roll_no' => $s['roll_no']];
    }
    foreach ($employees as $e) {
        $results[] = ['type' => 'employee', 'id' => $e['id'], 'code' => $e['code'], 'name' => $e['name'], 'roll_no' => null];
    }

    echo json_encode(['success' => true, 'results' => $results]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
