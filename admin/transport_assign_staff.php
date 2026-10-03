<?php
// admin/transport_assign_staff.php - Transport Module > Assign Staff (Teacher/Employee) to Route/Stop
$tsf_err = $_SESSION['tsf_err'] ?? '';
$tsf_msg = $_SESSION['tsf_msg'] ?? '';
unset($_SESSION['tsf_err'], $_SESSION['tsf_msg']);

require_once __DIR__ . '/transport_common.php';

// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['tsf_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=transport_assign_staff');
        exit();
    }

    if (isset($_POST['assign_staff'])) {
        $employee_pk  = (int) ($_POST['employee_pk'] ?? 0);
        $route_id     = (int) ($_POST['route_id'] ?? 0);
        $stop_id      = (int) ($_POST['stop_id'] ?? 0);
        $fee_override = trim($_POST['fee_override'] ?? '') !== '' ? (float) $_POST['fee_override'] : null;
        $remarks      = trim($_POST['remarks'] ?? '');

        $check = $pdo->prepare("SELECT id FROM transport_staff_assign WHERE employee_id = ? AND status = 'Active'");
        $check->execute([$employee_pk]);
        $existing = $check->fetch();

        if (!$route_id || !$stop_id) {
            $_SESSION['tsf_err'] = 'Route ও Stop নির্বাচন করুন।';
        } elseif ($existing) {
            $_SESSION['tsf_err'] = 'এই স্টাফ ইতিমধ্যে একটি রুটে সক্রিয় আছেন। আগে সেটি Unassign করুন।';
        } else {
            $stmt = $pdo->prepare("INSERT INTO transport_staff_assign (employee_id, route_id, stop_id, fee_override, assigned_date, remarks) VALUES (?,?,?,?,CURDATE(),?)");
            $stmt->execute([$employee_pk, $route_id, $stop_id, $fee_override, $remarks]);
            $_SESSION['tsf_msg'] = 'স্টাফকে ট্রান্সপোর্ট রুটে যুক্ত করা হয়েছে।';
            $tsf_emp_name = $pdo->prepare("SELECT name FROM employees WHERE id = ?");
            $tsf_emp_name->execute([$employee_pk]);
            sec_log_activity($pdo, 'Transport', 'Create', "স্টাফকে ট্রান্সপোর্ট রুটে যুক্ত করা হয়েছে: " . ($tsf_emp_name->fetchColumn() ?: "#$employee_pk"));
        }

        header('Location: index.php?page=transport_assign_staff&employee_pk=' . $employee_pk);
        exit();
    } elseif (isset($_POST['unassign_staff'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $employee_pk = (int) ($_POST['employee_pk'] ?? 0);
        $pdo->prepare("UPDATE transport_staff_assign SET status='Inactive' WHERE id = ?")->execute([$id]);
        $_SESSION['tsf_msg'] = 'স্টাফকে রুট থেকে সরিয়ে নেওয়া হয়েছে।';
        sec_log_activity($pdo, 'Transport', 'Update', "স্টাফকে ট্রান্সপোর্ট রুট থেকে সরিয়ে নেওয়া হয়েছে (Assign #{$id})।");
        header('Location: index.php?page=transport_assign_staff&employee_pk=' . $employee_pk);
        exit();
    }
}

// -----------------------------------------------------------
// Employee Search (HR employees টেবিল থেকে — Teachers/Staffs সব type ই দেখানো হচ্ছে,
// কারণ শিক্ষকও স্কুল বাসে যাতায়াত করতে পারেন)
// -----------------------------------------------------------
$q = trim($_GET['q'] ?? '');
$f_type = $_GET['f_type'] ?? '';

$search_rows = [];
if ($q !== '' || $f_type !== '') {
    $where = ["e.status = 'Active'", "e.is_current = 1"];
    $params = [];
    if ($q !== '') {
        $where[] = '(e.name LIKE ? OR e.employee_id LIKE ? OR e.mobile LIKE ?)';
        $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
    }
    if ($f_type !== '') { $where[] = 'e.type = ?'; $params[] = $f_type; }

    $sql = "SELECT e.id, e.employee_id, e.name, e.type, e.mobile, d.title AS designation_title,
                   (SELECT COUNT(*) FROM transport_staff_assign tfa WHERE tfa.employee_id = e.id AND tfa.status='Active') AS has_transport
            FROM employees e
            LEFT JOIN designations d ON d.id = e.designation_id
            WHERE " . implode(' AND ', $where) . " ORDER BY e.name ASC LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $search_rows = $stmt->fetchAll();
}

// -----------------------------------------------------------
// Selected Employee
// -----------------------------------------------------------
$employee_pk = (int) ($_GET['employee_pk'] ?? 0);
$selected_employee = null;
$active_assign = null;
if ($employee_pk > 0) {
    $stmt = $pdo->prepare("SELECT e.*, d.title AS designation_title FROM employees e LEFT JOIN designations d ON d.id = e.designation_id WHERE e.id = ?");
    $stmt->execute([$employee_pk]);
    $selected_employee = $stmt->fetch();

    $astmt = $pdo->prepare("SELECT tfa.*, tr.route_no, tr.route_name, tr.monthly_fee, ts.stop_name
                             FROM transport_staff_assign tfa
                             JOIN transport_routes tr ON tr.id = tfa.route_id
                             JOIN transport_stops ts ON ts.id = tfa.stop_id
                             WHERE tfa.employee_id = ? AND tfa.status = 'Active'");
    $astmt->execute([$employee_pk]);
    $active_assign = $astmt->fetch();
}

// Staff Route/Mixed — উভয় ধরনের রুটে স্টাফ এসাইন করা যাবে
$routes = $pdo->query("SELECT id, route_no, route_name, monthly_fee, route_category FROM transport_routes WHERE status='Active' AND route_category IN ('Staff','Mixed') ORDER BY route_name ASC")->fetchAll();
?>

<div class="card-custom mb-3">
    <?php if ($tsf_msg): ?><div class="alert alert-success py-2"><?php echo lv($tsf_msg); ?></div><?php endif; ?>
    <?php if ($tsf_err): ?><div class="alert alert-danger py-2"><?php echo lv($tsf_err); ?></div><?php endif; ?>

    <h5 class="fw-normal text-dark mb-3"><i class="fa-solid fa-magnifying-glass text-primary me-1"></i> Staff/Teacher খুঁজুন</h5>
    <form method="GET" action="index.php" class="row g-2">
        <input type="hidden" name="page" value="transport_assign_staff">
        <div class="col-md-5"><input type="text" name="q" class="form-control" placeholder="Employee ID / Name / Mobile" value="<?php echo lv($q); ?>"></div>
        <div class="col-md-4">
            <select name="f_type" class="form-select">
                <option value="">All Types</option>
                <?php foreach (['Teachers', 'Staffs', 'Board Of Directors', 'Academic Committee'] as $t): ?>
                    <option value="<?php echo $t; ?>" <?php echo $f_type === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3"><button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-search"></i> Search</button></div>
    </form>

    <?php if ($search_rows): ?>
        <div class="table-responsive mt-3">
            <table class="table table-bordered align-middle table-sm">
                <thead class="table-light"><tr><th>Employee ID</th><th>Name</th><th>Type/Designation</th><th>Transport</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($search_rows as $e): ?>
                        <tr class="<?php echo $e['id'] == $employee_pk ? 'table-success' : ''; ?>">
                            <td><?php echo lv($e['employee_id']); ?></td>
                            <td><?php echo lv($e['name']); ?></td>
                            <td><?php echo lv($e['type']); ?><?php echo $e['designation_title'] ? ' / ' . lv($e['designation_title']) : ''; ?></td>
                            <td><?php echo $e['has_transport'] ? '<span class="badge bg-success">Assigned</span>' : '<span class="badge bg-secondary">Not Assigned</span>'; ?></td>
                            <td><a href="index.php?page=transport_assign_staff&employee_pk=<?php echo (int) $e['id']; ?>" class="btn btn-sm btn-success">Select <i class="fa-solid fa-arrow-right"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($q !== '' || $f_type !== ''): ?>
        <p class="text-muted mt-3 mb-0">কোনো স্টাফ পাওয়া যায়নি।</p>
    <?php endif; ?>
</div>

<?php if ($selected_employee): ?>
<div class="row">
    <div class="col-md-5">
        <div class="card-custom">
            <h6 class="fw-bold text-primary mb-3"><i class="fa-solid fa-id-badge me-1"></i> Selected Staff</h6>
            <table class="table table-borderless table-sm mb-0">
                <tr><td class="text-muted" style="width:40%;">Employee ID</td><td class="fw-bold"><?php echo lv($selected_employee['employee_id']); ?></td></tr>
                <tr><td class="text-muted">Name</td><td class="fw-bold"><?php echo lv($selected_employee['name']); ?></td></tr>
                <tr><td class="text-muted">Type</td><td><?php echo lv($selected_employee['type']); ?></td></tr>
                <tr><td class="text-muted">Designation</td><td><?php echo lv($selected_employee['designation_title']); ?></td></tr>
                <tr><td class="text-muted">Mobile</td><td><?php echo lv($selected_employee['mobile']); ?></td></tr>
            </table>

            <?php if ($active_assign): ?>
                <hr>
                <div class="alert alert-info py-2 mb-2">
                    <strong>বর্তমান রুট:</strong> <?php echo lv($active_assign['route_no'] ? $active_assign['route_no'] . ' - ' : ''); ?><?php echo lv($active_assign['route_name']); ?><br>
                    <strong>স্টপেজ:</strong> <?php echo lv($active_assign['stop_name']); ?><br>
                    <strong>মাসিক ফি:</strong> ৳<?php echo number_format((float) ($active_assign['fee_override'] ?? $active_assign['monthly_fee']), 2); ?>
                </div>
                <form method="POST" action="index.php?page=transport_assign_staff" onsubmit="return confirm('এই স্টাফকে রুট থেকে সরিয়ে নেবেন?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $active_assign['id']; ?>">
                    <input type="hidden" name="employee_pk" value="<?php echo (int) $selected_employee['id']; ?>">
                    <button type="submit" name="unassign_staff" class="btn btn-outline-danger w-100"><i class="fa-solid fa-xmark"></i> Unassign from Route</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card-custom">
            <h6 class="fw-bold text-success mb-3"><i class="fa-solid fa-route me-1"></i> Assign to Route</h6>

            <?php if ($active_assign): ?>
                <div class="alert alert-warning py-2">এই স্টাফ ইতিমধ্যে একটি রুটে সক্রিয় আছেন। নতুন রুট দিতে হলে আগে Unassign করুন।</div>
            <?php elseif (!$routes): ?>
                <div class="alert alert-warning py-2">এখনো কোনো "Staff" বা "Mixed" ক্যাটাগরির রুট তৈরি করা হয়নি। Route Management এ গিয়ে একটা রুট বানান (Category: Staff Only অথবা Mixed)।</div>
            <?php else: ?>
                <form method="POST" action="index.php?page=transport_assign_staff">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="employee_pk" value="<?php echo (int) $selected_employee['id']; ?>">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Route <span class="text-danger">*</span></label>
                        <select name="route_id" id="assignStaffRouteSelect" class="form-select" required>
                            <option value="">-- Route নির্বাচন করুন --</option>
                            <?php foreach ($routes as $r): ?>
                                <option value="<?php echo (int) $r['id']; ?>">
                                    <?php echo lv($r['route_no'] ? $r['route_no'] . ' — ' : ''); ?><?php echo lv($r['route_name']); ?> (<?php echo $r['route_category']; ?>) — ৳<?php echo number_format((float) $r['monthly_fee'], 2); ?>/month
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Stop <span class="text-danger">*</span></label>
                        <select name="stop_id" id="assignStaffStopSelect" class="form-select" required>
                            <option value="">-- আগে Route নির্বাচন করুন --</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Fee Override (ঐচ্ছিক, ৳)</label>
                        <input type="number" step="0.01" min="0" name="fee_override" class="form-control" placeholder="খালি রাখলে Route এর ডিফল্ট ফি প্রযোজ্য (সাধারণত স্টাফদের জন্য ফ্রি হলে 0 দিন)">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Remarks</label>
                        <input type="text" name="remarks" class="form-control">
                    </div>

                    <button type="submit" name="assign_staff" class="btn btn-success px-4 fw-bold"><i class="fa-solid fa-check"></i> Assign to Route</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const staffRouteStopsData = <?php
    $stops_all = $pdo->query("SELECT id, route_id, stop_name FROM transport_stops ORDER BY stop_order ASC")->fetchAll();
    $by_route = [];
    foreach ($stops_all as $st) { $by_route[$st['route_id']][] = ['id' => $st['id'], 'name' => $st['stop_name']]; }
    echo json_encode($by_route, JSON_UNESCAPED_UNICODE);
?>;

document.getElementById('assignStaffRouteSelect')?.addEventListener('change', function () {
    const stopSelect = document.getElementById('assignStaffStopSelect');
    const stops = staffRouteStopsData[this.value] || [];
    stopSelect.innerHTML = '<option value="">-- Stop নির্বাচন করুন --</option>';
    stops.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = s.name;
        stopSelect.appendChild(opt);
    });
    if (stops.length === 0) stopSelect.innerHTML = '<option value="">এই রুটে কোনো স্টপেজ নেই</option>';
});
</script>
<?php endif; ?>
