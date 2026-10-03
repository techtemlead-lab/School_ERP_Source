<?php
/**
 * admin/zk_agent_list.php
 * ─────────────────────────────────────────────────────────
 * GET /admin/zk_agent_list.php?secret=XXXX
 *
 * ক্লায়েন্ট PC-তে চলা zk_agent.py (অন্য প্রোডাক্টের জন্য বানানো, অপরিবর্তিত রেখেই
 * এখানে reuse করা হয়েছে) এই endpoint কল করে জানে কোন কোন Local/LAN device
 * (connection_type='local', status='Active') sync করতে হবে। Response-এর
 * key নাম (device_sn/local_ip/local_port/device_name) zk_agent.py যা আশা
 * করে ঠিক সেভাবেই মেলানো হয়েছে, যাতে agent script একবিন্দুও বদলাতে না হয়।
 *
 * ডোমেইন দিয়েই tenant রিজলভ হয়ে যায় (config/db.php), তাই আলাদা tenant/API-key
 * সিস্টেম লাগেনি — শুধু per-tenant একটা shared secret, Device Settings পেজ
 * প্রথমবার খুললেই (বা এই ফাইল প্রথম কল হলেই) auto-generate হয়ে যাবে।
 */

require_once __DIR__ . '/../config/runtime.php';
erp_session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../config/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB unavailable']);
    exit;
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS attendance_agent_settings (
        id INT PRIMARY KEY,
        secret VARCHAR(64) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$stored = $pdo->query("SELECT secret FROM attendance_agent_settings WHERE id = 1")->fetchColumn();
if (!$stored) {
    $stored = bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO attendance_agent_settings (id, secret) VALUES (1, ?) ON DUPLICATE KEY UPDATE secret = secret")->execute([$stored]);
    $reread = $pdo->query("SELECT secret FROM attendance_agent_settings WHERE id = 1")->fetchColumn();
    if ($reread) { $stored = $reread; }
}

$secret = $_GET['secret'] ?? '';
if (!$stored || !hash_equals((string) $stored, (string) $secret)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid secret']);
    exit;
}

$devices = $pdo->query(
    "SELECT device_serial AS device_sn, device_name, ip_address AS local_ip, port AS local_port
     FROM attendance_devices
     WHERE connection_type = 'local' AND status = 'Active' AND device_serial IS NOT NULL AND device_serial != ''
     ORDER BY id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['status' => 'ok', 'devices' => $devices]);
