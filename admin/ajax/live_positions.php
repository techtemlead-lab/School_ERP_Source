<?php
// admin/ajax/live_positions.php - Live Map এর জন্য প্রতিটা গাড়ির সর্বশেষ অবস্থান
// (Traccar কে সরাসরি হিট না করে transport_vehicle_live থেকে পড়া হয় —
//  এই টেবিল cron/fetch_gps_positions.php প্রতি মিনিটে আপডেট করে, তাই Live Map
//  বারবার রিফ্রেশ হলেও Traccar এর ওপর চাপ পড়ে না)
require_once __DIR__ . '/../../config/runtime.php';
erp_session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/branch_scope.php';
require_once __DIR__ . '/../transport_common.php'; // branch_id কলাম নিশ্চিত করে

$lw = []; $lp = [];
tr_branch_or_shared($lw, $lp, 'tv.branch_id');
$lst = $pdo->prepare(
    "SELECT tv.id AS vehicle_id, tv.vehicle_no, tv.bus_no, tv.vehicle_name,
            d.name AS driver_name,
            tvl.lat, tvl.lng, tvl.speed_kmh, tvl.course_deg, tvl.ignition, tvl.device_time, tvl.connection_status
     FROM transport_vehicles tv
     LEFT JOIN transport_vehicle_live tvl ON tvl.vehicle_id = tv.id
     LEFT JOIN employees d ON d.id = tv.driver_id
     WHERE tv.status = 'Active' AND tv.gps_device_identifier IS NOT NULL AND tv.gps_device_identifier != ''" . ($lw ? ' AND ' . implode(' AND ', $lw) : '')
);
$lst->execute($lp);
$rows = $lst->fetchAll();

echo json_encode(['success' => true, 'vehicles' => $rows]);
