<?php
/**
 * cron/fetch_gps_positions.php
 * -----------------------------------------------------------
 * cPanel Cron Job দিয়ে প্রতি ১ মিনিটে চালানোর জন্য (Cron Expression: every minute):
 *   php /home/আপনার_ইউজার/public_html/cron/fetch_gps_positions.php
 *
 * কাজ:
 *  ১. Traccar থেকে সব ডিভাইসের সর্বশেষ position আনা
 *  ২. transport_gps_logs এ history হিসেবে সেভ (playback এর জন্য)
 *  ৩. transport_vehicle_live আপডেট (Live Map দ্রুত পড়ার জন্য)
 *  ৪. School geofence + Stop geofence চেক করে event লগ ও SMS পাঠানো
 *
 * ⚠️ CLI থেকে চলে বলে session/csrf কিছু লাগবে না — সরাসরি config ফাইল require করা হয়েছে।
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';   // admin/transport_common.php কিছু ফাইলে csrf_field()/csrf_verify() ধরে নেয়, CLI তে না থাকলে fatal error এড়াতে
require_once __DIR__ . '/../config/traccar.php';
require_once __DIR__ . '/../config/sms.php';
require_once __DIR__ . '/../admin/transport_common.php'; // gps_distance_meters(), transport_route_label(), টেবিল অটো-ক্রিয়েশন

function tlog(string $msg): void { echo '[' . date('Y-m-d H:i:s') . "] $msg\n"; }

$settings = $pdo->query("SELECT * FROM transport_gps_settings WHERE id = 1")->fetch();
if (!$settings || empty($settings['base_url'])) {
    tlog('GPS Settings কনফিগার করা নেই, cron থামছে।');
    exit();
}

$devices = traccar_get_devices($settings);
$positions = traccar_get_positions($settings);
if ($devices === null || $positions === null) {
    tlog('Traccar থেকে ডেটা আনা যায়নি (login/API ব্যর্থ)।');
    exit();
}

// uniqueId (IMEI) -> Traccar internal device id
$unique_to_device_id = [];
foreach ($devices as $dev) { $unique_to_device_id[$dev['uniqueId']] = $dev['id']; }

// Traccar internal device id -> position row
$positions_by_device_id = [];
foreach ($positions as $pos) { $positions_by_device_id[$pos['deviceId']] = $pos; }

$vehicles = $pdo->query("SELECT * FROM transport_vehicles WHERE gps_device_identifier IS NOT NULL AND gps_device_identifier != '' AND status = 'Active'")->fetchAll();

$school_lat = $settings['school_lat'] !== null ? (float) $settings['school_lat'] : null;
$school_lng = $settings['school_lng'] !== null ? (float) $settings['school_lng'] : null;
$geofence_radius = (int) $settings['geofence_radius_m'];

$upd_live = $pdo->prepare(
    "INSERT INTO transport_vehicle_live (vehicle_id, lat, lng, speed_kmh, course_deg, ignition, device_time, connection_status)
     VALUES (?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE lat=VALUES(lat), lng=VALUES(lng), speed_kmh=VALUES(speed_kmh), course_deg=VALUES(course_deg),
                             ignition=VALUES(ignition), device_time=VALUES(device_time), connection_status=VALUES(connection_status)"
);
$ins_log = $pdo->prepare("INSERT INTO transport_gps_logs (vehicle_id, lat, lng, speed_kmh, course_deg, ignition, device_time) VALUES (?,?,?,?,?,?,?)");
$ins_event = $pdo->prepare("INSERT INTO transport_events (vehicle_id, event_type, stop_id, event_time, lat, lng, sms_sent) VALUES (?,?,?,?,?,?,?)");
// স্কুল Arrive/Depart অবস্থা শুধু স্কুল-সংক্রান্ত ইভেন্ট থেকে ধরা হয় (মাঝে "Stop Reached" ঢুকলে অবস্থা হারিয়ে বারবার SMS যাওয়া ঠেকাতে)
$last_event_stmt = $pdo->prepare("SELECT event_type FROM transport_events WHERE vehicle_id = ? AND event_type IN ('Arrived School','Departed School') ORDER BY event_time DESC, id DESC LIMIT 1");
// একই স্টপে একই গাড়ির জন্য ২ ঘণ্টার মধ্যে আবার SMS নয় (গাড়ি দাঁড়িয়ে থাকলে ১৫ মিনিট পর পর SMS যেত)
$last_stop_event_stmt = $pdo->prepare("SELECT id FROM transport_events WHERE vehicle_id = ? AND event_type = 'Stop Reached' AND stop_id = ? AND event_time > (NOW() - INTERVAL 2 HOUR) LIMIT 1");

$processed = 0;
foreach ($vehicles as $v) {
    $device_id = $unique_to_device_id[$v['gps_device_identifier']] ?? null;
    if ($device_id === null || !isset($positions_by_device_id[$device_id])) continue;

    $pos = $positions_by_device_id[$device_id];
    $lat = (float) $pos['latitude'];
    $lng = (float) $pos['longitude'];
    $speed_kmh = round(((float) ($pos['speed'] ?? 0)) * 1.852, 2); // Traccar knots -> km/h
    $course = (float) ($pos['course'] ?? 0);
    $ignition = !empty($pos['attributes']['ignition']) ? 1 : 0;
    $device_time = date('Y-m-d H:i:s', strtotime($pos['deviceTime'] ?? 'now'));

    $is_recent = (time() - strtotime($device_time)) < 300; // ৫ মিনিটের মধ্যে ডেটা এলে Online
    $conn_status = $is_recent ? 'Online' : 'Offline';

    $ins_log->execute([$v['id'], $lat, $lng, $speed_kmh, $course, $ignition, $device_time]);
    $upd_live->execute([$v['id'], $lat, $lng, $speed_kmh, $course, $ignition, $device_time, $conn_status]);
    $processed++;

    // ---------------------------------------------------------
    // School geofence (Arrived / Departed)
    // ---------------------------------------------------------
    if ($school_lat !== null && $school_lng !== null) {
        $dist_school = gps_distance_meters($lat, $lng, $school_lat, $school_lng);
        $currently_at_school = $dist_school <= $geofence_radius;

        $last_event_stmt->execute([$v['id']]);
        $last_type = $last_event_stmt->fetch()['event_type'] ?? null;
        $was_at_school = $last_type === 'Arrived School';

        if ($currently_at_school && !$was_at_school) {
            $tpl = transport_sms_template($pdo, 'transport_arrive_school', ['BUS' => transport_bus_label($v)]);
            $sent = $tpl !== null ? transport_notify_route_guardians($pdo, (int) $v['id'], $tpl) : 0;
            $ins_event->execute([$v['id'], 'Arrived School', null, date('Y-m-d H:i:s'), $lat, $lng, $sent > 0 ? 1 : 0]);
            tlog("Vehicle #{$v['id']}: Arrived School event + SMS পাঠানো হয়েছে।");
        } elseif (!$currently_at_school && $was_at_school) {
            $tpl = transport_sms_template($pdo, 'transport_depart_school', ['BUS' => transport_bus_label($v)]);
            $sent = $tpl !== null ? transport_notify_route_guardians($pdo, (int) $v['id'], $tpl) : 0;
            $ins_event->execute([$v['id'], 'Departed School', null, date('Y-m-d H:i:s'), $lat, $lng, $sent > 0 ? 1 : 0]);
            tlog("Vehicle #{$v['id']}: Departed School event + SMS পাঠানো হয়েছে।");
        }
    }

    // ---------------------------------------------------------
    // Stop-level geofence (যেসব রুটের vehicle_id এই গাড়ি)
    // ---------------------------------------------------------
    $stops_stmt = $pdo->prepare("SELECT ts.* FROM transport_stops ts JOIN transport_routes tr ON tr.id = ts.route_id WHERE tr.vehicle_id = ? AND ts.lat IS NOT NULL");
    $stops_stmt->execute([$v['id']]);
    foreach ($stops_stmt->fetchAll() as $stop) {
        $dist_stop = gps_distance_meters($lat, $lng, (float) $stop['lat'], (float) $stop['lng']);
        if ($dist_stop <= (int) $stop['radius_m']) {
            $last_stop_event_stmt->execute([$v['id'], $stop['id']]);
            if (!$last_stop_event_stmt->fetch()) {
                $tpl = transport_sms_template($pdo, 'transport_stop_reached', ['BUS' => transport_bus_label($v), 'STOP' => $stop['stop_name']]);
                $sent = $tpl !== null ? transport_notify_stop_guardians($pdo, (int) $v['id'], (int) $stop['id'], $tpl) : 0;
                $ins_event->execute([$v['id'], 'Stop Reached', $stop['id'], date('Y-m-d H:i:s'), $lat, $lng, $sent > 0 ? 1 : 0]);
                tlog("Vehicle #{$v['id']}: Stop Reached ({$stop['stop_name']}) event + SMS পাঠানো হয়েছে।");
            }
        }
    }
}

// GPS log অনির্দিষ্টকাল জমতে দেওয়া হয় না — ৩০ দিনের পুরনো লগ ও ৯০ দিনের পুরনো ইভেন্ট মোছা হয় (প্রতি রানে সীমিত সংখ্যক)
$pdo->exec("DELETE FROM transport_gps_logs WHERE device_time < (NOW() - INTERVAL 30 DAY) LIMIT 5000");
$pdo->exec("DELETE FROM transport_events WHERE event_time < (NOW() - INTERVAL 90 DAY) LIMIT 2000");

tlog("সম্পন্ন — {$processed} টি গাড়ির পজিশন আপডেট হয়েছে।");

// -----------------------------------------------------------
// SMS Helper ফাংশনসমূহ
// -----------------------------------------------------------
/**
 * SMS Settings > SMS Template Settings-এর টেমপ্লেট থেকে মেসেজ বানায় ({NAME} ইচ্ছাকৃতভাবে বাকি থাকে —
 * প্রতিটা অভিভাবকের জন্য নিচের transport_notify_* ফাংশন বসায়)। ইভেন্টের SMS বন্ধ করা থাকলে null।
 */
function transport_sms_template(PDO $pdo, string $key, array $data): ?string
{
    if (!sms_template_enabled($pdo, $key)) { return null; }
    $data['SCHOOL_NAME'] = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetch()['institute_name'] ?? '';
    return sms_template_render($pdo, $key, $data);
}

function transport_bus_label(array $v): string
{
    $label = $v['bus_no'] ? 'Bus-' . $v['bus_no'] : $v['vehicle_no'];
    if ($v['vehicle_name']) $label .= ' ' . $v['vehicle_name'];
    return $label;
}

/** এই গাড়ির রুটে থাকা সব সক্রিয় শিক্ষার্থীর অভিভাবককে SMS (School Arrive/Depart এর জন্য) */
function transport_notify_route_guardians(PDO $pdo, int $vehicle_id, string $template): int
{
    $stmt = $pdo->prepare(
        "SELECT s.name_en, s.guardian_phone FROM transport_student_assign tsa
         JOIN transport_routes tr ON tr.id = tsa.route_id
         JOIN students s ON s.id = tsa.student_id
         WHERE tr.vehicle_id = ? AND tsa.status = 'Active' AND s.guardian_phone IS NOT NULL AND s.guardian_phone != ''"
    );
    $stmt->execute([$vehicle_id]);
    $sent = 0;
    foreach ($stmt->fetchAll() as $row) {
        $r = send_sms($pdo, $row['guardian_phone'], str_replace('{NAME}', $row['name_en'], $template));
        if (!empty($r['success'])) { $sent++; }
    }
    return $sent;
}

/** নির্দিষ্ট স্টপেজের শিক্ষার্থীদের অভিভাবককে SMS (Stop Reached এর জন্য — শুধু ওই স্টপেজেরটাই, সব রুট না) */
function transport_notify_stop_guardians(PDO $pdo, int $vehicle_id, int $stop_id, string $template): int
{
    $stmt = $pdo->prepare(
        "SELECT s.name_en, s.guardian_phone FROM transport_student_assign tsa
         JOIN students s ON s.id = tsa.student_id
         WHERE tsa.stop_id = ? AND tsa.status = 'Active' AND s.guardian_phone IS NOT NULL AND s.guardian_phone != ''"
    );
    $stmt->execute([$stop_id]);
    $sent = 0;
    foreach ($stmt->fetchAll() as $row) {
        $r = send_sms($pdo, $row['guardian_phone'], str_replace('{NAME}', $row['name_en'], $template));
        if (!empty($r['success'])) { $sent++; }
    }
    return $sent;
}
