<?php
// admin/transport_vehicles.php - Transport Module > Vehicle Management
$tv_err = $_SESSION['tv_err'] ?? '';
$tv_msg = $_SESSION['tv_msg'] ?? '';
unset($_SESSION['tv_err'], $_SESSION['tv_msg']);

require_once __DIR__ . '/transport_common.php';

// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['tv_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=transport_vehicles');
        exit();
    }

    if (isset($_POST['save_vehicle'])) {
        $id                    = (int) ($_POST['id'] ?? 0);
        $vehicle_no            = trim($_POST['vehicle_no'] ?? '');
        $bus_no                = trim($_POST['bus_no'] ?? '');
        $vehicle_name          = trim($_POST['vehicle_name'] ?? '');
        $vehicle_type          = $_POST['vehicle_type'] ?? 'Bus';
        $capacity              = (int) ($_POST['capacity'] ?? 0);
        $driver_id             = (int) ($_POST['driver_id'] ?? 0) ?: null;
        $helper_id             = (int) ($_POST['helper_id'] ?? 0) ?: null;
        $gps_device_identifier = trim($_POST['gps_device_identifier'] ?? '');
        $fitness_expiry        = trim($_POST['fitness_expiry'] ?? '') ?: null;
        $insurance_expiry      = trim($_POST['insurance_expiry'] ?? '') ?: null;
        $tax_token_expiry      = trim($_POST['tax_token_expiry'] ?? '') ?: null;
        $status                = $_POST['status'] ?? 'Active';
        $remarks               = trim($_POST['remarks'] ?? '');

        if ($vehicle_no === '') {
            $_SESSION['tv_err'] = 'Vehicle Number খালি রাখা যাবে না।';
        } else {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare("UPDATE transport_vehicles SET vehicle_no=?, bus_no=?, vehicle_name=?, vehicle_type=?, capacity=?, driver_id=?, helper_id=?, gps_device_identifier=?, fitness_expiry=?, insurance_expiry=?, tax_token_expiry=?, status=?, remarks=? WHERE id=?");
                    $stmt->execute([$vehicle_no, $bus_no ?: null, $vehicle_name, $vehicle_type, $capacity, $driver_id, $helper_id, $gps_device_identifier ?: null, $fitness_expiry, $insurance_expiry, $tax_token_expiry, $status, $remarks, $id]);
                    $_SESSION['tv_msg'] = 'গাড়ির তথ্য আপডেট করা হয়েছে।';
                    sec_log_activity($pdo, 'Transport', 'Update', "গাড়ির তথ্য আপডেট করা হয়েছে: {$vehicle_no} ({$vehicle_name})");
                } else {
                    $stmt = $pdo->prepare("INSERT INTO transport_vehicles (vehicle_no, bus_no, vehicle_name, vehicle_type, capacity, driver_id, helper_id, gps_device_identifier, fitness_expiry, insurance_expiry, tax_token_expiry, status, remarks) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([$vehicle_no, $bus_no ?: null, $vehicle_name, $vehicle_type, $capacity, $driver_id, $helper_id, $gps_device_identifier ?: null, $fitness_expiry, $insurance_expiry, $tax_token_expiry, $status, $remarks]);
                    $_SESSION['tv_msg'] = 'নতুন গাড়ি যোগ করা হয়েছে।';
                    sec_log_activity($pdo, 'Transport', 'Create', "নতুন গাড়ি যোগ করা হয়েছে: {$vehicle_no} ({$vehicle_name})");
                }
            } catch (PDOException $e) {
                $_SESSION['tv_err'] = (str_contains($e->getMessage(), 'uniq_vehicle_no'))
                    ? 'এই Vehicle Number দিয়ে ইতিমধ্যে একটি গাড়ি রেজিস্টার করা আছে।'
                    : 'একটি সমস্যা হয়েছে, আবার চেষ্টা করুন।';
            }
        }
    } elseif (isset($_POST['delete_vehicle'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $check = $pdo->prepare("SELECT COUNT(*) c FROM transport_routes WHERE vehicle_id = ?");
        $check->execute([$id]);
        if ((int) $check->fetch()['c'] > 0) {
            $_SESSION['tv_err'] = 'এই গাড়ি কোনো রুটের সাথে যুক্ত আছে, আগে রুট থেকে সরিয়ে নিন।';
        } else {
            $del_veh_row = $pdo->prepare("SELECT vehicle_no, vehicle_name FROM transport_vehicles WHERE id = ?");
            $del_veh_row->execute([$id]);
            $del_veh = $del_veh_row->fetch();
            $pdo->prepare("DELETE FROM transport_vehicles WHERE id = ?")->execute([$id]);
            $_SESSION['tv_msg'] = 'গাড়ি মুছে ফেলা হয়েছে।';
            sec_log_activity($pdo, 'Transport', 'Delete', "গাড়ি মুছে ফেলা হয়েছে: " . ($del_veh ? "{$del_veh['vehicle_no']} ({$del_veh['vehicle_name']})" : "#$id"));
        }
    }

    header('Location: index.php?page=transport_vehicles');
    exit();
}

$view = $_GET['view'] ?? 'list';
// Driver/Helper আপনার existing HR মডিউলের employees টেবিল থেকে আসে (type='Staffs')
$staff_pool = $pdo->query(
    "SELECT e.id, e.name, e.employee_id, d.title AS designation_title
     FROM employees e LEFT JOIN designations d ON d.id = e.designation_id
     WHERE e.type = 'Staffs' AND e.status = 'Active' AND e.is_current = 1
     ORDER BY e.name ASC"
)->fetchAll();
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM transport_vehicles WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: [
        'id' => 0, 'vehicle_no' => '', 'bus_no' => '', 'vehicle_name' => '', 'vehicle_type' => 'Bus', 'capacity' => 0,
        'driver_id' => '', 'helper_id' => '', 'gps_device_identifier' => '', 'fitness_expiry' => '',
        'insurance_expiry' => '', 'tax_token_expiry' => '', 'status' => 'Active', 'remarks' => '',
    ];
    ?>
    <div class="card-custom">
        <?php if ($tv_err): ?><div class="alert alert-danger py-2"><?php echo lv($tv_err); ?></div><?php endif; ?>

        <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;">
            <i class="fa-solid fa-bus text-white"></i>
        </div>

        <form method="POST" action="index.php?page=transport_vehicles">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row">
                <div class="col-md-6">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Bus No. <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <input type="text" name="bus_no" class="form-control" value="<?php echo lv($d['bus_no']); ?>" placeholder="e.g. 01">
                            <div class="form-text">শুধু চেনার জন্য সংক্ষিপ্ত নম্বর — যেমন "01", "02" (Bus No-01)</div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Nickname</label>
                        <div class="col-sm-8"><input type="text" name="vehicle_name" class="form-control" value="<?php echo lv($d['vehicle_name']); ?>" placeholder="e.g. Jamuna"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Vehicle No. (নম্বর প্লেট) <span class="text-danger">*</span></label>
                        <div class="col-sm-8"><input type="text" name="vehicle_no" class="form-control" value="<?php echo lv($d['vehicle_no']); ?>" placeholder="ঢাকা মেট্রো-গ ১১-২২২২" required></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Type</label>
                        <div class="col-sm-8">
                            <select name="vehicle_type" class="form-select">
                                <?php foreach (['Bus', 'Microbus', 'Car', 'Van', 'Other'] as $t): ?>
                                    <option value="<?php echo $t; ?>" <?php echo $d['vehicle_type'] === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Seat Capacity</label>
                        <div class="col-sm-8"><input type="number" min="0" name="capacity" class="form-control" value="<?php echo lv($d['capacity']); ?>"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Driver</label>
                        <div class="col-sm-8">
                            <select name="driver_id" class="form-select">
                                <option value="">-- Select Driver (HR Employee) --</option>
                                <?php foreach ($staff_pool as $dr): ?>
                                    <option value="<?php echo (int) $dr['id']; ?>" <?php echo (string) $d['driver_id'] === (string) $dr['id'] ? 'selected' : ''; ?>><?php echo lv($dr['name']); ?><?php echo $dr['designation_title'] ? ' — ' . lv($dr['designation_title']) : ''; ?> (<?php echo lv($dr['employee_id']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">তালিকায় না থাকলে আগে HR Module &gt; Add Employee থেকে যোগ করুন (Type: Staffs, Designation: Driver)</div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Helper</label>
                        <div class="col-sm-8">
                            <select name="helper_id" class="form-select">
                                <option value="">-- Select Helper (HR Employee) --</option>
                                <?php foreach ($staff_pool as $h): ?>
                                    <option value="<?php echo (int) $h['id']; ?>" <?php echo (string) $d['helper_id'] === (string) $h['id'] ? 'selected' : ''; ?>><?php echo lv($h['name']); ?><?php echo $h['designation_title'] ? ' — ' . lv($h['designation_title']) : ''; ?> (<?php echo lv($h['employee_id']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">GPS Device ID</label>
                        <div class="col-sm-8">
                            <input type="text" name="gps_device_identifier" class="form-control" value="<?php echo lv($d['gps_device_identifier']); ?>" placeholder="Traccar এর Device Unique ID / IMEI (ঐচ্ছিক)">
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Fitness Expiry</label>
                        <div class="col-sm-8"><input type="date" name="fitness_expiry" class="form-control" value="<?php echo lv($d['fitness_expiry']); ?>"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Insurance Expiry</label>
                        <div class="col-sm-8"><input type="date" name="insurance_expiry" class="form-control" value="<?php echo lv($d['insurance_expiry']); ?>"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Tax Token Expiry</label>
                        <div class="col-sm-8"><input type="date" name="tax_token_expiry" class="form-control" value="<?php echo lv($d['tax_token_expiry']); ?>"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Status</label>
                        <div class="col-sm-8">
                            <select name="status" class="form-select">
                                <option value="Active" <?php echo $d['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Maintenance" <?php echo $d['status'] === 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                <option value="Inactive" <?php echo $d['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-2 col-form-label fw-bold text-end">Remarks</label>
                <div class="col-sm-10"><textarea name="remarks" class="form-control" rows="2"><?php echo lv($d['remarks']); ?></textarea></div>
            </div>

            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_vehicle" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Save Vehicle'; ?></button>
                    <a href="index.php?page=transport_vehicles" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

<?php else: ?>
    <?php
    $stmt = $pdo->query("SELECT tv.*, d.name AS driver_name, h.name AS helper_name,
                                 (SELECT COUNT(*) FROM transport_routes tr WHERE tr.vehicle_id = tv.id) AS route_count
                          FROM transport_vehicles tv
                          LEFT JOIN employees d ON d.id = tv.driver_id
                          LEFT JOIN employees h ON h.id = tv.helper_id
                          ORDER BY tv.id DESC");
    $rows = $stmt->fetchAll();
    $today = date('Y-m-d');
    ?>
    <div class="card-custom">
        <?php if ($tv_msg): ?><div class="alert alert-success py-2"><?php echo lv($tv_msg); ?></div><?php endif; ?>
        <?php if ($tv_err): ?><div class="alert alert-danger py-2"><?php echo lv($tv_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=transport_vehicles&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i> Add Vehicle</a>
            <span class="text-muted small">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> items.</span>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr><th>Bus</th><th>Vehicle No.</th><th>Type</th><th>Driver</th><th>Helper</th><th>GPS Device</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php if ($rows): foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token());
                        $doc_alert = false;
                        foreach (['fitness_expiry', 'insurance_expiry', 'tax_token_expiry'] as $f) {
                            if ($row[$f] && strtotime($row[$f]) < strtotime($today . ' + 30 days')) $doc_alert = true;
                        }
                    ?>
                        <tr>
                            <td class="fw-bold"><?php echo $row['bus_no'] ? 'Bus No-' . lv($row['bus_no']) : '<span class="text-muted">-</span>'; ?><?php if ($row['vehicle_name']): ?><div class="text-muted small"><?php echo lv($row['vehicle_name']); ?></div><?php endif; ?></td>
                            <td><?php echo lv($row['vehicle_no']); ?></td>
                            <td><?php echo lv($row['vehicle_type']); ?></td>
                            <td><?php echo lv($row['driver_name']) ?: '<span class="text-muted">-</span>'; ?></td>
                            <td><?php echo lv($row['helper_name']) ?: '<span class="text-muted">-</span>'; ?></td>
                            <td><?php echo $row['gps_device_identifier'] ? '<span class="badge bg-success">Linked</span>' : '<span class="badge bg-secondary">Not Set</span>'; ?></td>
                            <td>
                                <?php
                                $st_badge = ['Active' => 'bg-success', 'Maintenance' => 'bg-warning text-dark', 'Inactive' => 'bg-secondary'];
                                echo '<span class="badge ' . $st_badge[$row['status']] . '">' . $row['status'] . '</span>';
                                if ($doc_alert) echo ' <span class="badge bg-danger" title="Fitness/Insurance/Tax Token শীঘ্রই শেষ হবে">Doc Alert</span>';
                                ?>
                            </td>
                            <td class="text-nowrap">
                                <a href="index.php?page=transport_vehicles&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <form method="POST" action="index.php?page=transport_vehicles" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" name="delete_vehicle" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="8" class="text-center text-muted py-3">No vehicles found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
