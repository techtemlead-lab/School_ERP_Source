<?php
// admin/ajax/traccar_test.php - Test Connection বাটনের জন্য
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session শেষ হয়ে গেছে, আবার লগইন করুন।']);
    exit();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/traccar.php';

$settings = $pdo->query("SELECT * FROM transport_gps_settings WHERE id = 1")->fetch();
if (!$settings) {
    echo json_encode(['success' => false, 'message' => 'GPS Settings পাওয়া যায়নি।']);
    exit();
}

$result = traccar_test_connection($settings);

$pdo->prepare("UPDATE transport_gps_settings SET connection_status = ?, last_tested_at = NOW() WHERE id = 1")
    ->execute([$result['success'] ? 'Connected' : 'Failed']);

echo json_encode(['success' => $result['success'], 'message' => $result['message']]);
