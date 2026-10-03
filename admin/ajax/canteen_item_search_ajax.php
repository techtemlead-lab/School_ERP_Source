<?php
// admin/ajax/canteen_item_search_ajax.php - Canteen POS-এ Menu Item লাইভ সার্চ (Autosuggest)
require_once __DIR__ . '/../../config/runtime.php';
erp_session_start();
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
    $cisa_where = ["status = 'Active'"]; $cisa_params = [];
    if ($q !== '') { $cisa_where[] = 'name LIKE ?'; $cisa_params[] = '%' . $q . '%'; }
    branch_apply_filter($cisa_where, $cisa_params);
    $stmt = $pdo->prepare("SELECT id, name, price FROM canteen_menu_items WHERE " . implode(' AND ', $cisa_where) . " ORDER BY name ASC LIMIT 20");
    $stmt->execute($cisa_params);

    echo json_encode(['success' => true, 'results' => $stmt->fetchAll()]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
