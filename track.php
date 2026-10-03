<?php
// track.php - Parent Tracking Link (Public, No Login)
// admin/transport_assign_student.php থেকে তৈরি হওয়া tracking_token দিয়ে অ্যাক্সেস হয়
require_once __DIR__ . '/config/runtime.php';
erp_session_start();
require_once __DIR__ . '/config/db.php';

$token = trim($_GET['token'] ?? '');
$assign = null;

if ($token !== '') {
    $stmt = $pdo->prepare(
        "SELECT tsa.*, s.name_en, s.name_bn, s.student_id AS stu_code, tr.route_name, tr.vehicle_id, ts.stop_name,
                tv.vehicle_no, tv.bus_no, tv.vehicle_name
         FROM transport_student_assign tsa
         JOIN students s ON s.id = tsa.student_id
         JOIN transport_routes tr ON tr.id = tsa.route_id
         JOIN transport_stops ts ON ts.id = tsa.stop_id
         LEFT JOIN transport_vehicles tv ON tv.id = tr.vehicle_id
         WHERE tsa.tracking_token = ? AND tsa.status = 'Active'"
    );
    $stmt->execute([$token]);
    $assign = $stmt->fetch();
}

function tv($val) { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>বাস ট্র্যাকিং</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<style>
    body { background:#f4f6f9; font-family: 'Segoe UI', Arial, sans-serif; }
    .track-card { max-width: 700px; margin: 20px auto; border-radius: 12px; overflow:hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
    .track-header { background:#1e3a8a; color:#fff; padding: 16px 20px; }
    #trackMap { height: 380px; width: 100%; }
    .status-pill { display:inline-block; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight:600; }
</style>
</head>
<body>

<?php if (!$assign): ?>
    <div class="container">
        <div class="track-card bg-white p-4 text-center">
            <h5 class="text-danger mb-2">লিংকটি সঠিক নয়</h5>
            <p class="text-muted mb-0">এই ট্র্যাকিং লিংকটি খুঁজে পাওয়া যায়নি অথবা মেয়াদ শেষ হয়ে গেছে। স্কুল অফিসের সাথে যোগাযোগ করুন।</p>
        </div>
    </div>
<?php else: ?>
    <div class="container">
        <div class="track-card bg-white">
            <div class="track-header">
                <h5 class="mb-0"><?php echo tv($assign['name_en']); ?> এর স্কুল বাস</h5>
                <div class="small opacity-75">Roll/ID: <?php echo tv($assign['stu_code']); ?> • রুট: <?php echo tv($assign['route_name']); ?> • স্টপেজ: <?php echo tv($assign['stop_name']); ?></div>
            </div>

            <div id="trackMap"></div>

            <div class="p-3">
                <div id="statusBox" class="mb-2">
                    <span class="status-pill bg-secondary text-white">লোড হচ্ছে...</span>
                </div>
                <div class="small text-muted" id="lastUpdateText"></div>
            </div>
        </div>
        <p class="text-center text-muted small mt-2">এই লিংকটি ব্যক্তিগত — অন্য কারো সাথে শেয়ার করবেন না।</p>
    </div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<?php if ($assign): ?>
<script>
const vehicleId = <?php echo (int) ($assign['vehicle_id'] ?? 0); ?>;
const map = L.map('trackMap').setView([23.8103, 90.4125], 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors', maxZoom: 19 }).addTo(map);

const busIcon = L.divIcon({
    html: '<div style="background:#1e3a8a;color:#fff;border-radius:50%;width:34px;height:34px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,0.3);font-size:16px;">🚌</div>',
    className: '', iconSize: [34, 34], iconAnchor: [17, 17]
});
let marker = null;

async function refreshMyBus() {
    if (!vehicleId) return;
    try {
        const res = await fetch('track_position.php?token=<?php echo urlencode($token); ?>');
        const data = await res.json();
        const statusBox = document.getElementById('statusBox');
        const updateText = document.getElementById('lastUpdateText');

        if (!data.success || !data.lat) {
            statusBox.innerHTML = '<span class="status-pill bg-secondary text-white">অবস্থান পাওয়া যায়নি</span>';
            return;
        }

        const online = data.connection_status === 'Online';
        statusBox.innerHTML = online
            ? '<span class="status-pill bg-success text-white">সচল — গতি ' + parseFloat(data.speed_kmh).toFixed(0) + ' km/h</span>'
            : '<span class="status-pill bg-secondary text-white">অফলাইন</span>';
        updateText.textContent = 'শেষ আপডেট: ' + (data.device_time || 'N/A');

        const pos = [parseFloat(data.lat), parseFloat(data.lng)];
        if (marker) { marker.setLatLng(pos); } else { marker = L.marker(pos, { icon: busIcon }).addTo(map); }
        map.setView(pos, 15);
    } catch (e) { console.error(e); }
}

refreshMyBus();
setInterval(refreshMyBus, 15000);
</script>
<?php endif; ?>
</body>
</html>
