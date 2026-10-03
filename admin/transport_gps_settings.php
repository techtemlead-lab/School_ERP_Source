<?php
// admin/transport_gps_settings.php - Transport Module > GPS Settings (Traccar Connection)
$tg_err = $_SESSION['tg_err'] ?? '';
$tg_msg = $_SESSION['tg_msg'] ?? '';
unset($_SESSION['tg_err'], $_SESSION['tg_msg']);

require_once __DIR__ . '/transport_common.php';
require_once __DIR__ . '/../config/traccar.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_gps_settings'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['tg_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $base_url          = rtrim(trim($_POST['base_url'] ?? ''), '/');
        $username           = trim($_POST['username'] ?? '');
        $password           = trim($_POST['password'] ?? '');
        $poll_interval_sec  = max(10, (int) ($_POST['poll_interval_sec'] ?? 20));
        $school_lat         = trim($_POST['school_lat'] ?? '') ?: null;
        $school_lng         = trim($_POST['school_lng'] ?? '') ?: null;
        $geofence_radius_m  = max(50, (int) ($_POST['geofence_radius_m'] ?? 200));

        // পাসওয়ার্ড খালি রাখলে আগেরটা বহাল থাকবে (edit করার সময় বারবার পাসওয়ার্ড দিতে না হয়)
        if ($password === '') {
            $stmt = $pdo->prepare("UPDATE transport_gps_settings SET base_url=?, username=?, poll_interval_sec=?, school_lat=?, school_lng=?, geofence_radius_m=?, connection_status='Not Tested' WHERE id=1");
            $stmt->execute([$base_url, $username, $poll_interval_sec, $school_lat, $school_lng, $geofence_radius_m]);
        } else {
            $stmt = $pdo->prepare("UPDATE transport_gps_settings SET base_url=?, username=?, password=?, poll_interval_sec=?, school_lat=?, school_lng=?, geofence_radius_m=?, connection_status='Not Tested' WHERE id=1");
            $stmt->execute([$base_url, $username, $password, $poll_interval_sec, $school_lat, $school_lng, $geofence_radius_m]);
        }
        $_SESSION['tg_msg'] = 'সেটিংস সংরক্ষণ করা হয়েছে। এখন "Test Connection" চাপুন।';
        sec_log_activity($pdo, 'Transport', 'Update', 'GPS Settings আপডেট করা হয়েছে।');
    }
    header('Location: index.php?page=transport_gps_settings');
    exit();
}

$settings = $pdo->query("SELECT * FROM transport_gps_settings WHERE id=1")->fetch();

// vehicles যেগুলোর GPS device ID সেট আছে
$mapped_vehicles = $pdo->query("SELECT vehicle_no, bus_no, vehicle_name, gps_device_identifier FROM transport_vehicles WHERE gps_device_identifier IS NOT NULL AND gps_device_identifier != ''")->fetchAll();
?>

<div class="row">
    <div class="col-md-7">
        <div class="card-custom">
            <?php if ($tg_msg): ?><div class="alert alert-success py-2"><?php echo lv($tg_msg); ?></div><?php endif; ?>
            <?php if ($tg_err): ?><div class="alert alert-danger py-2"><?php echo lv($tg_err); ?></div><?php endif; ?>

            <h5 class="fw-normal text-dark mb-4"><i class="fa-solid fa-satellite-dish text-primary me-1"></i> Traccar Connection Settings</h5>

            <form method="POST" action="index.php?page=transport_gps_settings">
                <?php echo csrf_field(); ?>

                <div class="mb-3">
                    <label class="form-label fw-bold">Traccar Base URL <span class="text-danger">*</span></label>
                    <input type="text" name="base_url" class="form-control" value="<?php echo lv($settings['base_url']); ?>" placeholder="https://track.yourdomain.com" required>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Email</label>
                        <input type="text" name="username" class="form-control" value="<?php echo lv($settings['username']); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Password</label>
                        <input type="password" name="password" class="form-control" placeholder="<?php echo $settings['password'] ? '••••••• (বদলাতে না চাইলে খালি রাখুন)' : ''; ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">Poll Interval (সেকেন্ড)</label>
                        <input type="number" min="10" name="poll_interval_sec" class="form-control" value="<?php echo lv($settings['poll_interval_sec'] ?: 20); ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">School Latitude</label>
                        <input type="text" name="school_lat" class="form-control" value="<?php echo lv($settings['school_lat']); ?>" placeholder="23.8103">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-bold">School Longitude</label>
                        <input type="text" name="school_lng" class="form-control" value="<?php echo lv($settings['school_lng']); ?>" placeholder="90.4125">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Geofence Radius (মিটার)</label>
                    <input type="number" min="50" name="geofence_radius_m" class="form-control" value="<?php echo lv($settings['geofence_radius_m'] ?: 200); ?>" style="max-width:200px;">
                    <div class="form-text">স্কুলের এই radius এর মধ্যে গাড়ি ঢুকলে "Arrived School" ইভেন্ট তৈরি হবে</div>
                </div>

                <button type="submit" name="save_gps_settings" class="btn btn-primary fw-bold px-4" style="background-color:#1e3a8a;">Save Settings</button>
                <button type="button" id="testConnBtn" class="btn btn-outline-secondary fw-bold px-4"><i class="fa-solid fa-plug"></i> Test Connection</button>
            </form>

            <div id="testConnResult" class="mt-3"></div>
        </div>
    </div>

    <div class="col-md-5">
        <div class="card-custom">
            <h6 class="fw-bold mb-3">Connection Status</h6>
            <?php
            $status_badge = ['Connected' => 'bg-success', 'Failed' => 'bg-danger', 'Not Tested' => 'bg-secondary'];
            ?>
            <p><span class="badge <?php echo $status_badge[$settings['connection_status']] ?? 'bg-secondary'; ?>"><?php echo lv($settings['connection_status']); ?></span>
            <?php if ($settings['last_tested_at']): ?><span class="text-muted small ms-2">শেষ টেস্ট: <?php echo lv($settings['last_tested_at']); ?></span><?php endif; ?></p>

            <hr>
            <h6 class="fw-bold mb-2">GPS Device সংযুক্ত গাড়ি (<?php echo count($mapped_vehicles); ?>)</h6>
            <?php if ($mapped_vehicles): ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($mapped_vehicles as $v): ?>
                        <li class="list-group-item px-0 small"><?php echo lv($v['vehicle_no']); ?> <?php echo $v['bus_no'] ? '(Bus-' . lv($v['bus_no']) . ($v['vehicle_name'] ? ' ' . lv($v['vehicle_name']) : '') . ')' : ''; ?> — Device: <code><?php echo lv($v['gps_device_identifier']); ?></code></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted small">এখনো কোনো গাড়িতে GPS Device ID সেট করা হয়নি। Vehicle Management এ গিয়ে প্রতিটা গাড়িতে Traccar এর Device Unique ID (IMEI) বসান।</p>
            <?php endif; ?>

            <hr>
            <div class="alert alert-warning small py-2 mb-0">
                Live Map/History কাজ করার জন্য cPanel এ একটা Cron Job সেট করতে হবে:<br>
                <code>*/1 * * * * php /home/আপনার_ইউজার/public_html/cron/fetch_gps_positions.php</code>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('testConnBtn').addEventListener('click', async function () {
    const btn = this;
    const resultDiv = document.getElementById('testConnResult');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Testing...';
    resultDiv.innerHTML = '';
    try {
        const res = await fetch('ajax/traccar_test.php');
        const data = await res.json();
        resultDiv.innerHTML = `<div class="alert ${data.success ? 'alert-success' : 'alert-danger'} py-2">${data.message}</div>`;
    } catch (e) {
        resultDiv.innerHTML = '<div class="alert alert-danger py-2">সার্ভারে সমস্যা হয়েছে, আবার চেষ্টা করুন।</div>';
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-plug"></i> Test Connection';
});
</script>
