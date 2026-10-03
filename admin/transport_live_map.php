<?php
// admin/transport_live_map.php - Transport Module > Live GPS Map
require_once __DIR__ . '/transport_common.php';
$settings = $pdo->query("SELECT poll_interval_sec, school_lat, school_lng FROM transport_gps_settings WHERE id=1")->fetch();
$poll_ms = max(10, (int) ($settings['poll_interval_sec'] ?? 20)) * 1000;
$center_lat = (float) ($settings['school_lat'] ?: 23.8103);
$center_lng = (float) ($settings['school_lng'] ?: 90.4125);
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>

<div class="card-custom mb-0" style="padding:0; overflow:hidden;">
    <div class="row g-0">
        <div class="col-md-9" style="height:70vh;">
            <div id="liveMap" style="width:100%; height:100%;"></div>
        </div>
        <div class="col-md-3 p-3" style="height:70vh; overflow-y:auto; border-left:1px solid #eee;">
            <h6 class="fw-bold mb-3"><i class="fa-solid fa-bus text-primary me-1"></i> Vehicles</h6>
            <div id="vehicleList">
                <p class="text-muted small">লোড হচ্ছে...</p>
            </div>
        </div>
    </div>
</div>

<script>
const map = L.map('liveMap').setView([<?php echo $center_lat; ?>, <?php echo $center_lng; ?>], 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors',
    maxZoom: 19
}).addTo(map);

const markers = {};
const busIcon = L.divIcon({
    html: '<div style="background:#1e3a8a;color:#fff;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,0.3);"><i class="fa-solid fa-bus"></i></div>',
    className: '', iconSize: [32, 32], iconAnchor: [16, 16]
});
const busIconOffline = L.divIcon({
    html: '<div style="background:#9ca3af;color:#fff;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,0.3);"><i class="fa-solid fa-bus"></i></div>',
    className: '', iconSize: [32, 32], iconAnchor: [16, 16]
});

async function refreshPositions() {
    try {
        const res = await fetch('ajax/live_positions.php');
        const data = await res.json();
        if (!data.success) return;

        const listDiv = document.getElementById('vehicleList');
        let listHtml = '';

        data.vehicles.forEach(v => {
            const label = (v.bus_no ? 'Bus-' + v.bus_no : v.vehicle_no) + (v.vehicle_name ? ' (' + v.vehicle_name + ')' : '');
            const online = v.connection_status === 'Online';

            listHtml += `<div class="border rounded p-2 mb-2 small" style="cursor:pointer;" onclick="focusVehicle(${v.vehicle_id})">
                <div class="d-flex justify-content-between">
                    <strong>${label}</strong>
                    <span class="badge ${online ? 'bg-success' : 'bg-secondary'}">${online ? 'Online' : 'Offline'}</span>
                </div>
                <div class="text-muted">${v.driver_name || 'ড্রাইভার নেই'}</div>
                ${v.lat ? `<div class="text-muted">গতি: ${parseFloat(v.speed_kmh).toFixed(0)} km/h</div>` : '<div class="text-muted">অবস্থান পাওয়া যায়নি</div>'}
            </div>`;

            if (v.lat && v.lng) {
                const pos = [parseFloat(v.lat), parseFloat(v.lng)];
                if (markers[v.vehicle_id]) {
                    markers[v.vehicle_id].setLatLng(pos).setIcon(online ? busIcon : busIconOffline);
                } else {
                    markers[v.vehicle_id] = L.marker(pos, { icon: online ? busIcon : busIconOffline }).addTo(map);
                }
                markers[v.vehicle_id].bindPopup(`<strong>${label}</strong><br>${v.driver_name || ''}<br>গতি: ${parseFloat(v.speed_kmh).toFixed(0)} km/h<br>শেষ আপডেট: ${v.device_time || 'N/A'}`);
            }
        });

        listDiv.innerHTML = listHtml || '<p class="text-muted small">কোনো GPS-যুক্ত গাড়ি নেই</p>';
    } catch (e) {
        console.error(e);
    }
}

function focusVehicle(id) {
    if (markers[id]) {
        map.setView(markers[id].getLatLng(), 16);
        markers[id].openPopup();
    }
}

refreshPositions();
setInterval(refreshPositions, <?php echo $poll_ms; ?>);
</script>
