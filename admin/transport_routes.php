<?php
// admin/transport_routes.php - Transport Module > Route Management
$tr_err = $_SESSION['tr_err'] ?? '';
$tr_msg = $_SESSION['tr_msg'] ?? '';
unset($_SESSION['tr_err'], $_SESSION['tr_msg']);

require_once __DIR__ . '/transport_common.php';

// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['tr_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=transport_routes');
        exit();
    }

    if (isset($_POST['save_route'])) {
        $id             = (int) ($_POST['id'] ?? 0);
        $route_no       = trim($_POST['route_no'] ?? '');
        $route_name     = trim($_POST['route_name'] ?? '');
        $route_category = in_array($_POST['route_category'] ?? '', ['Student', 'Staff', 'Mixed'], true) ? $_POST['route_category'] : 'Student';
        $vehicle_id     = (int) ($_POST['vehicle_id'] ?? 0) ?: null;
        $monthly_fee    = (float) ($_POST['monthly_fee'] ?? 0);
        $status         = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        $errs = [];
        if ($route_name === '') { $errs[] = 'Route Name খালি রাখা যাবে না।'; }
        if ($monthly_fee < 0) { $errs[] = 'Monthly Fee ঋণাত্মক হতে পারবে না।'; }
        if ($vehicle_id && !tr_scoped($pdo, 'transport_vehicles', $vehicle_id)) { $errs[] = 'নির্বাচিত গাড়ি পাওয়া যায়নি।'; }
        if ($route_no !== '') {
            $dq = $pdo->prepare("SELECT id FROM transport_routes WHERE route_no = ? AND id <> ? LIMIT 1");
            $dq->execute([$route_no, $id]);
            if ($dq->fetch()) { $errs[] = 'এই Route No ইতিমধ্যে ব্যবহৃত হয়েছে।'; }
        }
        if ($errs) {
            $_SESSION['tr_err'] = implode(' ', $errs);
        } else {
            if ($id > 0) {
                if (!tr_scoped($pdo, 'transport_routes', $id)) {
                    $_SESSION['tr_err'] = 'রুট খুঁজে পাওয়া যায়নি।';
                } else {
                    $stmt = $pdo->prepare("UPDATE transport_routes SET route_no=?, route_name=?, route_category=?, vehicle_id=?, monthly_fee=?, status=? WHERE id=?");
                    $stmt->execute([$route_no ?: null, $route_name, $route_category, $vehicle_id, $monthly_fee, $status, $id]);
                    $_SESSION['tr_msg'] = 'রুট আপডেট করা হয়েছে।';
                    sec_log_activity($pdo, 'Transport', 'Update', "ট্রান্সপোর্ট রুট আপডেট করা হয়েছে: {$route_name} ({$route_no})");
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO transport_routes (route_no, route_name, route_category, vehicle_id, monthly_fee, status, branch_id) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([$route_no ?: null, $route_name, $route_category, $vehicle_id, $monthly_fee, $status, branch_id_for_insert()]);
                $_SESSION['tr_msg'] = 'নতুন রুট যোগ করা হয়েছে। এখন এই রুটে স্টপেজ যোগ করুন।';
                sec_log_activity($pdo, 'Transport', 'Create', "নতুন ট্রান্সপোর্ট রুট তৈরি করা হয়েছে: {$route_name} ({$route_no})");
            }
        }
    } elseif (isset($_POST['delete_route'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_route = tr_scoped($pdo, 'transport_routes', $id);
        $check1 = $pdo->prepare("SELECT COUNT(*) c FROM transport_student_assign WHERE route_id = ? AND status='Active'");
        $check1->execute([$id]);
        $check2 = $pdo->prepare("SELECT COUNT(*) c FROM transport_staff_assign WHERE route_id = ? AND status='Active'");
        $check2->execute([$id]);
        $check3 = $pdo->prepare("SELECT COUNT(*) c FROM transport_fee_invoices WHERE route_id = ?");
        $check3->execute([$id]);
        if (!$del_route) {
            $_SESSION['tr_err'] = 'রুট খুঁজে পাওয়া যায়নি।';
        } elseif ((int) $check1->fetch()['c'] > 0 || (int) $check2->fetch()['c'] > 0) {
            $_SESSION['tr_err'] = 'এই রুটে এখনো সক্রিয় শিক্ষার্থী/স্টাফ যুক্ত আছে, মুছে ফেলা যাবে না।';
        } elseif ((int) $check3->fetch()['c'] > 0) {
            // ইনভয়েস ইতিহাস আছে: মুছলে হিসাব নষ্ট হবে, তাই Inactive করা হচ্ছে
            $pdo->prepare("UPDATE transport_routes SET status='Inactive' WHERE id = ?")->execute([$id]);
            $_SESSION['tr_msg'] = 'এই রুটের ফি-ইনভয়েস ইতিহাস আছে, তাই মুছে না ফেলে Inactive করা হয়েছে।';
        } else {
            $pdo->prepare("DELETE FROM transport_stops WHERE route_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM transport_routes WHERE id = ?")->execute([$id]);
            $_SESSION['tr_msg'] = 'রুট মুছে ফেলা হয়েছে।';
            sec_log_activity($pdo, 'Transport', 'Delete', "ট্রান্সপোর্ট রুট মুছে ফেলা হয়েছে: {$del_route['route_name']} ({$del_route['route_no']})");
        }
    }

    header('Location: index.php?page=transport_routes');
    exit();
}

$view = $_GET['view'] ?? 'list';
$__vw = ["status != 'Inactive'"]; $__vp = [];
tr_branch_or_shared($__vw, $__vp);
$__vs = $pdo->prepare("SELECT id, vehicle_no, bus_no, vehicle_name FROM transport_vehicles WHERE " . implode(' AND ', $__vw) . " ORDER BY vehicle_no ASC");
$__vs->execute($__vp);
$vehicles = $__vs->fetchAll();
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $edit_row = tr_scoped($pdo, 'transport_routes', $edit_id);
    }
    $d = $edit_row ?: ['id' => 0, 'route_no' => '', 'route_name' => '', 'route_category' => 'Student', 'vehicle_id' => '', 'monthly_fee' => 0, 'status' => 'Active'];
    ?>
    <div class="card-custom">
        <?php if ($tr_err): ?><div class="alert alert-danger py-2"><?php echo lv($tr_err); ?></div><?php endif; ?>

        <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;">
            <i class="fa-solid fa-route text-white"></i>
        </div>

        <form method="POST" action="index.php?page=transport_routes">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Route No.</label>
                <div class="col-sm-6">
                    <input type="text" name="route_no" class="form-control" value="<?php echo lv($d['route_no']); ?>" placeholder="e.g. Route-1">
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Route Name <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="route_name" class="form-control" value="<?php echo lv($d['route_name']); ?>" placeholder="e.g. Mirpur - Rupnagar Route" required></div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Category <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="route_category" class="form-select">
                        <option value="Student" <?php echo $d['route_category'] === 'Student' ? 'selected' : ''; ?>>Student Only</option>
                        <option value="Staff" <?php echo $d['route_category'] === 'Staff' ? 'selected' : ''; ?>>Staff Only</option>
                        <option value="Mixed" <?php echo $d['route_category'] === 'Mixed' ? 'selected' : ''; ?>>Mixed (Student + Staff)</option>
                    </select>
                    <div class="form-text">শিক্ষক/স্টাফদের জন্য আলাদা বাস চাইলে "Staff Only" বেছে আলাদা Route + Vehicle বানান</div>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Assign Vehicle</label>
                <div class="col-sm-6">
                    <select name="vehicle_id" class="form-select">
                        <option value="">-- Select Vehicle --</option>
                        <?php foreach ($vehicles as $v): ?>
                            <option value="<?php echo (int) $v['id']; ?>" <?php echo (string) $d['vehicle_id'] === (string) $v['id'] ? 'selected' : ''; ?>>
                                <?php echo $v['bus_no'] ? 'Bus No-' . lv($v['bus_no']) . ' — ' : ''; ?><?php echo lv($v['vehicle_no']); ?><?php echo $v['vehicle_name'] ? ' (' . lv($v['vehicle_name']) . ')' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Monthly Fee (৳)</label>
                <div class="col-sm-6"><input type="number" step="0.01" min="0" name="monthly_fee" class="form-control" value="<?php echo lv($d['monthly_fee']); ?>"></div>
            </div>
            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Status</label>
                <div class="col-sm-6">
                    <select name="status" class="form-select">
                        <option value="Active" <?php echo $d['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $d['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_route" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create Route'; ?></button>
                    <a href="index.php?page=transport_routes" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

<?php else: ?>
    <?php
    $trl_w = []; $trl_p = [];
    tr_branch_or_shared($trl_w, $trl_p, 'tr.branch_id');
    $stmt = $pdo->prepare("SELECT tr.*, tv.vehicle_no, tv.bus_no, tv.vehicle_name,
                                 (SELECT COUNT(*) FROM transport_stops ts WHERE ts.route_id = tr.id) AS stop_count,
                                 (SELECT COUNT(*) FROM transport_student_assign tsa WHERE tsa.route_id = tr.id AND tsa.status='Active') AS student_count,
                                 (SELECT COUNT(*) FROM transport_staff_assign tfa WHERE tfa.route_id = tr.id AND tfa.status='Active') AS staff_count
                          FROM transport_routes tr
                          LEFT JOIN transport_vehicles tv ON tv.id = tr.vehicle_id
                          " . ($trl_w ? 'WHERE ' . implode(' AND ', $trl_w) : '') . "
                          ORDER BY tr.id DESC");
    $stmt->execute($trl_p);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($tr_msg): ?><div class="alert alert-success py-2"><?php echo lv($tr_msg); ?></div><?php endif; ?>
        <?php if ($tr_err): ?><div class="alert alert-danger py-2"><?php echo lv($tr_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=transport_routes&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i> Add Route</a>
            <span class="text-muted small">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> items.</span>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr><th>Route</th><th>Category</th><th>Vehicle</th><th>Stops</th><th>Students</th><th>Staff</th><th>Monthly Fee</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php if ($rows): foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token());
                        $cat_badge = ['Student' => 'bg-info text-dark', 'Staff' => 'bg-warning text-dark', 'Mixed' => 'bg-primary'];
                    ?>
                        <tr>
                            <td class="fw-bold"><?php echo $row['route_no'] ? lv($row['route_no']) . ' — ' : ''; ?><?php echo lv($row['route_name']); ?></td>
                            <td><span class="badge <?php echo $cat_badge[$row['route_category']] ?? 'bg-secondary'; ?>"><?php echo $row['route_category']; ?></span></td>
                            <td><?php echo $row['vehicle_no'] ? ($row['bus_no'] ? 'Bus No-' . lv($row['bus_no']) . ' ' : '') . lv($row['vehicle_no']) . ($row['vehicle_name'] ? ' (' . lv($row['vehicle_name']) . ')' : '') : '<span class="text-muted">Not Assigned</span>'; ?></td>
                            <td><a href="index.php?page=transport_stops&route_id=<?php echo (int) $row['id']; ?>" class="badge bg-info text-dark text-decoration-none"><?php echo (int) $row['stop_count']; ?> Stops</a></td>
                            <td><span class="badge bg-primary"><?php echo (int) $row['student_count']; ?></span></td>
                            <td><span class="badge bg-secondary"><?php echo (int) $row['staff_count']; ?></span></td>
                            <td>৳<?php echo number_format((float) $row['monthly_fee'], 2); ?></td>
                            <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                            <td class="text-nowrap">
                                <a href="index.php?page=transport_stops&route_id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Manage Stops"><i class="fa-solid fa-map-pin"></i></a>
                                <a href="index.php?page=transport_routes&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <form method="POST" action="index.php?page=transport_routes" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" name="delete_route" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="9" class="text-center text-muted py-3">No routes found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
