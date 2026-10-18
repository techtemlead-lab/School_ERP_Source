<?php
// admin/transport_stops.php - Transport Module > Route Stops Management
$tst_err = $_SESSION['tst_err'] ?? '';
$tst_msg = $_SESSION['tst_msg'] ?? '';
unset($_SESSION['tst_err'], $_SESSION['tst_msg']);

require_once __DIR__ . '/transport_common.php';

$route_id = (int) ($_GET['route_id'] ?? 0);
$route = tr_scoped($pdo, 'transport_routes', $route_id);

if (!$route) {
    echo '<div class="alert alert-danger">রুট খুঁজে পাওয়া যায়নি। <a href="index.php?page=transport_routes">রুট লিস্টে ফিরে যান</a></div>';
    return;
}

// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['tst_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=transport_stops&route_id=' . $route_id);
        exit();
    }

    if (isset($_POST['save_stop'])) {
        $id           = (int) ($_POST['id'] ?? 0);
        $stop_name    = trim($_POST['stop_name'] ?? '');
        $stop_order   = (int) ($_POST['stop_order'] ?? 0);
        $lat          = trim($_POST['lat'] ?? '') ?: null;
        $lng          = trim($_POST['lng'] ?? '') ?: null;
        $radius_m     = (int) ($_POST['radius_m'] ?? 100);
        $pickup_time  = trim($_POST['pickup_time'] ?? '') ?: null;
        $drop_time    = trim($_POST['drop_time'] ?? '') ?: null;

        $serr = '';
        if ($stop_name === '') { $serr = 'Stop Name খালি রাখা যাবে না।'; }
        elseif (($lat === null) !== ($lng === null)) { $serr = 'Latitude ও Longitude দুটোই দিতে হবে (অথবা দুটোই খালি রাখুন)।'; }
        elseif ($lat !== null && (!is_numeric($lat) || !is_numeric($lng) || (float) $lat < -90 || (float) $lat > 90 || (float) $lng < -180 || (float) $lng > 180)) { $serr = 'Latitude (-90 থেকে 90) বা Longitude (-180 থেকে 180) সঠিক নয়।'; }
        elseif ($radius_m < 10 || $radius_m > 5000) { $serr = 'Radius ১০ থেকে ৫০০০ মিটারের মধ্যে হতে হবে।'; }
        elseif ($stop_order < 0) { $serr = 'Stop Order ঋণাত্মক হতে পারবে না।'; }
        elseif (($pickup_time !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $pickup_time)) || ($drop_time !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $drop_time))) { $serr = 'Pickup/Drop সময় সঠিক নয়।'; }
        if ($serr !== '') {
            $_SESSION['tst_err'] = $serr;
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE transport_stops SET stop_name=?, stop_order=?, lat=?, lng=?, radius_m=?, pickup_time=?, drop_time=? WHERE id=? AND route_id=?");
                $stmt->execute([$stop_name, $stop_order, $lat, $lng, $radius_m, $pickup_time, $drop_time, $id, $route_id]);
                $_SESSION['tst_msg'] = 'স্টপেজ আপডেট করা হয়েছে।';
                sec_log_activity($pdo, 'Transport', 'Update', "ট্রান্সপোর্ট স্টপেজ আপডেট করা হয়েছে: {$stop_name} (Route: {$route['route_name']})");
            } else {
                if ($stop_order === 0) {
                    $max_stmt = $pdo->prepare("SELECT COALESCE(MAX(stop_order),0) m FROM transport_stops WHERE route_id = ?");
                    $max_stmt->execute([$route_id]);
                    $stop_order = (int) $max_stmt->fetch()['m'] + 1;
                }
                $stmt = $pdo->prepare("INSERT INTO transport_stops (route_id, stop_name, stop_order, lat, lng, radius_m, pickup_time, drop_time) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$route_id, $stop_name, $stop_order, $lat, $lng, $radius_m, $pickup_time, $drop_time]);
                $_SESSION['tst_msg'] = 'নতুন স্টপেজ যোগ করা হয়েছে।';
                sec_log_activity($pdo, 'Transport', 'Create', "নতুন ট্রান্সপোর্ট স্টপেজ যোগ করা হয়েছে: {$stop_name} (Route: {$route['route_name']})");
            }
        }
    } elseif (isset($_POST['delete_stop'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $check = $pdo->prepare("SELECT (SELECT COUNT(*) FROM transport_student_assign WHERE stop_id = ? AND status='Active') + (SELECT COUNT(*) FROM transport_staff_assign WHERE stop_id = ? AND status='Active') AS c");
        $check->execute([$id, $id]);
        if ((int) $check->fetch()['c'] > 0) {
            $_SESSION['tst_err'] = 'এই স্টপেজে সক্রিয় শিক্ষার্থী/স্টাফ যুক্ত আছে, মুছে ফেলা যাবে না।';
        } else {
            $del_stop_row = $pdo->prepare("SELECT stop_name FROM transport_stops WHERE id = ?");
            $del_stop_row->execute([$id]);
            $del_stop_name = $del_stop_row->fetchColumn();
            $pdo->prepare("DELETE FROM transport_stops WHERE id = ? AND route_id = ?")->execute([$id, $route_id]);
            $_SESSION['tst_msg'] = 'স্টপেজ মুছে ফেলা হয়েছে।';
            sec_log_activity($pdo, 'Transport', 'Delete', "ট্রান্সপোর্ট স্টপেজ মুছে ফেলা হয়েছে: " . ($del_stop_name ?: "#$id") . " (Route: {$route['route_name']})");
        }
    }

    header('Location: index.php?page=transport_stops&route_id=' . $route_id);
    exit();
}

$edit_id = (int) ($_GET['edit_id'] ?? 0);
$edit_row = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM transport_stops WHERE id = ? AND route_id = ?");
    $stmt->execute([$edit_id, $route_id]);
    $edit_row = $stmt->fetch();
}
$d = $edit_row ?: ['id' => 0, 'stop_name' => '', 'stop_order' => '', 'lat' => '', 'lng' => '', 'radius_m' => 100, 'pickup_time' => '', 'drop_time' => ''];

$stops = $pdo->prepare("SELECT * FROM transport_stops WHERE route_id = ? ORDER BY stop_order ASC, id ASC");
$stops->execute([$route_id]);
$stop_rows = $stops->fetchAll();
?>

<div class="mb-3">
    <a href="index.php?page=transport_routes" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Routes</a>
    <h5 class="d-inline-block ms-2 mb-0 fw-bold"><?php echo $route['route_no'] ? lv($route['route_no']) . ' — ' : ''; ?><?php echo lv($route['route_name']); ?> — Stops</h5>
</div>

<div class="row">
    <div class="col-md-5">
        <div class="card-custom">
            <?php if ($tst_err): ?><div class="alert alert-danger py-2"><?php echo lv($tst_err); ?></div><?php endif; ?>
            <?php if ($tst_msg): ?><div class="alert alert-success py-2"><?php echo lv($tst_msg); ?></div><?php endif; ?>

            <h6 class="fw-bold mb-3"><?php echo $d['id'] ? 'Edit Stop' : 'Add New Stop'; ?></h6>
            <form method="POST" action="index.php?page=transport_stops&route_id=<?php echo $route_id; ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

                <div class="mb-2">
                    <label class="form-label small fw-bold">Stop Name <span class="text-danger">*</span></label>
                    <input type="text" name="stop_name" class="form-control" value="<?php echo lv($d['stop_name']); ?>" required>
                </div>
                <div class="row">
                    <div class="col-6 mb-2">
                        <label class="form-label small fw-bold">Order</label>
                        <input type="number" name="stop_order" class="form-control" value="<?php echo lv($d['stop_order']); ?>" placeholder="Auto">
                    </div>
                    <div class="col-6 mb-2">
                        <label class="form-label small fw-bold">Geofence Radius (m)</label>
                        <input type="number" name="radius_m" class="form-control" value="<?php echo lv($d['radius_m'] ?: 100); ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-6 mb-2">
                        <label class="form-label small fw-bold">Latitude</label>
                        <input type="text" name="lat" class="form-control" value="<?php echo lv($d['lat']); ?>" placeholder="23.8103">
                    </div>
                    <div class="col-6 mb-2">
                        <label class="form-label small fw-bold">Longitude</label>
                        <input type="text" name="lng" class="form-control" value="<?php echo lv($d['lng']); ?>" placeholder="90.4125">
                    </div>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label small fw-bold">Pickup Time</label>
                        <input type="time" name="pickup_time" class="form-control" value="<?php echo lv($d['pickup_time']); ?>">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label small fw-bold">Drop Time</label>
                        <input type="time" name="drop_time" class="form-control" value="<?php echo lv($d['drop_time']); ?>">
                    </div>
                </div>

                <button type="submit" name="save_stop" class="btn btn-primary fw-bold w-100" style="background-color:#1e3a8a;"><?php echo $d['id'] ? 'Update Stop' : 'Add Stop'; ?></button>
                <?php if ($d['id']): ?><a href="index.php?page=transport_stops&route_id=<?php echo $route_id; ?>" class="btn btn-outline-secondary w-100 mt-2">Cancel Edit</a><?php endif; ?>
            </form>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card-custom">
            <h6 class="fw-bold mb-3">Stop List (<?php echo count($stop_rows); ?>)</h6>
            <div class="table-responsive">
                <table class="table table-bordered align-middle table-sm">
                    <thead class="table-light">
                        <tr><th>#</th><th>Stop Name</th><th>Pickup</th><th>Lat/Lng</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($stop_rows): foreach ($stop_rows as $s): $csrf = htmlspecialchars(csrf_token()); ?>
                            <tr>
                                <td><?php echo (int) $s['stop_order']; ?></td>
                                <td class="fw-bold"><?php echo lv($s['stop_name']); ?></td>
                                <td><?php echo lv($s['pickup_time']); ?></td>
                                <td class="small text-muted"><?php echo $s['lat'] ? lv($s['lat']) . ', ' . lv($s['lng']) : '-'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=transport_stops&route_id=<?php echo $route_id; ?>&edit_id=<?php echo (int) $s['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen"></i></a>
                                    <form method="POST" action="index.php?page=transport_stops&route_id=<?php echo $route_id; ?>" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                                        <button type="submit" name="delete_stop" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">এখনো কোনো স্টপেজ যোগ হয়নি</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
