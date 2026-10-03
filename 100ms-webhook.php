<?php
/**
 * 100ms-webhook.php - প্রতিটা স্কুল তাদের নিজের 100ms Dashboard → Developer → Webhooks এ
 * এই URL বসাবে (Admin Panel-এর Live Class Setting পেজে সম্পূর্ণ URL-টা দেখানো হয়):
 *   https://<সেই স্কুলের নিজের ডোমেইন>/100ms-webhook.php?token=<hms_webhook_secret>
 *
 * যেহেতু প্রতিটা স্কুল তাদের নিজের ডোমেইন দিয়ে এই URL কল করবে, config/db.php নিজে থেকেই
 * ঠিক সেই স্কুলের DB-তে কানেক্ট করে দেবে (বাকি সব পেজের মতোই) — আলাদা কিছু করা লাগে না।
 *
 * ইচ্ছা করেই হালকা রাখা হয়েছে — শুধু verify + job insert করে সাথে সাথে 200 রেসপন্স দেয়।
 * ভারী কাজ (ডাউনলোড + YouTube আপলোড) cron/process_recorded_uploads.php করে।
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/live_class_100ms.php';

lc_ensure_recording_jobs_table($pdo);
header('Content-Type: application/json');

$expected_token = lc_ensure_webhook_secret($pdo);
$given_token = $_GET['token'] ?? '';
if (!hash_equals($expected_token, (string) $given_token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid token']);
    exit();
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid json']);
    exit();
}

if (($payload['type'] ?? '') !== 'beam.recording.success') {
    // অন্য ইভেন্ট (যেমন failure) — শুধু ack করা, retry এড়াতে
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => true]);
    exit();
}

$data = $payload['data'] ?? [];
$room_id = $data['room_id'] ?? '';
$recording_url = $data['recording_presigned_url'] ?? '';

if ($room_id === '' || $recording_url === '') {
    http_response_code(200); // 100ms কে retry করানো এড়াতে 200, কিন্তু কাজ হবে না
    echo json_encode(['ok' => false, 'error' => 'missing room_id/recording_url']);
    exit();
}

$class_stmt = $pdo->prepare("SELECT id FROM elearning_live_classes WHERE room_id = ? LIMIT 1");
$class_stmt->execute([$room_id]);
$live_class_id = $class_stmt->fetchColumn();

$pdo->prepare(
    "INSERT INTO elearning_recording_jobs (live_class_id, room_id, recording_url, status) VALUES (?, ?, ?, 'pending')"
)->execute([$live_class_id ?: null, $room_id, $recording_url]);

http_response_code(200);
echo json_encode(['ok' => true]);
