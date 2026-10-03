<?php
// track_position.php - track.php এর জন্য পাবলিক JSON position endpoint
// টোকেন যাচাই করে শুধু সেই student এর bus এর position রিটার্ন করে, অন্য কিছু না (privacy)
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config/db.php';

$token = trim($_GET['token'] ?? '');
if ($token === '') {
    echo json_encode(['success' => false]);
    exit();
}

$stmt = $pdo->prepare(
    "SELECT tr.vehicle_id
     FROM transport_student_assign tsa
     JOIN transport_routes tr ON tr.id = tsa.route_id
     WHERE tsa.tracking_token = ? AND tsa.status = 'Active'"
);
$stmt->execute([$token]);
$row = $stmt->fetch();

if (!$row || !$row['vehicle_id']) {
    echo json_encode(['success' => false]);
    exit();
}

$live_stmt = $pdo->prepare("SELECT lat, lng, speed_kmh, course_deg, device_time, connection_status FROM transport_vehicle_live WHERE vehicle_id = ?");
$live_stmt->execute([$row['vehicle_id']]);
$live = $live_stmt->fetch();

if (!$live) {
    echo json_encode(['success' => true, 'lat' => null]);
    exit();
}

echo json_encode(['success' => true] + $live);
