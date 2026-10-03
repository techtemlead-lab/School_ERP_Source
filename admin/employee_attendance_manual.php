<?php
// admin/employee_attendance_manual.php - Attendance Module > Employee Attendance > Manual Attendance
// HR/Admin হাতে করে Type/Designation/Shift-ভিত্তিক Present/Absent মার্ক করবে এখান থেকে
// (ডিভাইস পাঞ্চ ছাড়া, ম্যানুয়ালি)। ডেটা যায় employee_daily_attendance টেবিলে।
// প্যাটার্ন: attendance_manual.php (Student) থেকে হুবহু।

function eam_esc($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// প্রয়োজনীয় টেবিল/কলাম না থাকলে নিজে থেকেই তৈরি/যোগ করে নেবে
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS employee_daily_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        attendance_date DATE NOT NULL,
        check_in_time DATETIME NULL,
        check_out_time DATETIME NULL,
        status ENUM('Present','Absent') NOT NULL DEFAULT 'Present',
        marked_by VARCHAR(100) NULL,
        source VARCHAR(30) NOT NULL DEFAULT 'device',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_employee_date (employee_id, attendance_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$has_shift_col = $pdo->query("SHOW COLUMNS FROM employee_daily_attendance LIKE 'shift_id'")->fetch();
if (!$has_shift_col) { try { $pdo->exec("ALTER TABLE employee_daily_attendance ADD COLUMN shift_id INT NULL AFTER attendance_date"); } catch (Throwable $e) {} }

$eam_msg = $_SESSION['eam_msg'] ?? '';
$eam_err = $_SESSION['eam_err'] ?? '';
unset($_SESSION['eam_msg'], $_SESSION['eam_err']);

// -----------------------------------------------------------
// Save Handler
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['eam_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $p_date  = trim($_POST['attendance_date'] ?? date('Y-m-d'));
        $p_type  = trim($_POST['type'] ?? '');
        $p_desig = (int) ($_POST['designation_id'] ?? 0);
        $p_shift = (int) ($_POST['shift_id'] ?? 0);
        $statuses = (array) ($_POST['status'] ?? []); // [employee_id => 'Present'|'Absent']

        if ($p_type === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $p_date)) {
            $_SESSION['eam_err'] = 'Day ও Type অবশ্যই সিলেক্ট করতে হবে।';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO employee_daily_attendance (employee_id, attendance_date, shift_id, status, marked_by, source)
                 VALUES (?, ?, ?, ?, ?, 'manual')
                 ON DUPLICATE KEY UPDATE status = VALUES(status), shift_id = VALUES(shift_id), marked_by = VALUES(marked_by), source = 'manual'"
            );
            $marked_by = $_SESSION['admin_user'] ?? 'admin';
            $count = 0;
            foreach ($statuses as $eid => $st) {
                $eid = (int) $eid;
                $st  = ($st === 'Present') ? 'Present' : 'Absent';
                if ($eid > 0) {
                    $stmt->execute([$eid, $p_date, $p_shift ?: null, $st, $marked_by]);
                    $count++;
                }
            }
            sec_log_activity($pdo, 'Attendance', 'Update', "কর্মীদের ম্যানুয়াল অ্যাটেন্ডেন্স সেভ করা হয়েছে: {$count} জন ({$p_date}, Type: {$p_type})");
            $_SESSION['eam_msg'] = $count . " জন কর্মীর অ্যাটেন্ডেন্স সেভ করা হয়েছে ({$p_date})।";
        }
    }
    header('Location: index.php?page=employee_attendance_manual&date=' . urlencode($p_date ?? date('Y-m-d')) . '&f_type=' . urlencode($p_type ?? '') . '&f_desig=' . (int) ($p_desig ?? 0) . '&f_shift=' . (int) ($p_shift ?? 0));
    exit();
}

// -----------------------------------------------------------
// Filters
// -----------------------------------------------------------
$f_date  = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) { $f_date = date('Y-m-d'); }
$f_type  = trim($_GET['f_type'] ?? '');
$f_desig = (int) ($_GET['f_desig'] ?? 0);
$f_shift = (int) ($_GET['f_shift'] ?? 0);

$types_list       = ['Teachers', 'Staffs', 'Board Of Directors', 'Academic Committee'];
$designations_list = $pdo->query("SELECT id, title FROM designations WHERE status = 'Active' ORDER BY title ASC")->fetchAll();
$shifts_list        = $pdo->query("SELECT id, name FROM employee_shifts WHERE status = 'Active' ORDER BY name ASC")->fetchAll();

// -----------------------------------------------------------
// Employee List (নির্দিষ্ট Type/Designation/Shift এর বর্তমান কর্মীরা) + সেদিনের আগে থেকে সেভ করা status (থাকলে)
// -----------------------------------------------------------
$employees = [];
if ($f_type !== '') {
    $sql = "SELECT e.id, e.employee_id, e.name, e.mobile, e.photo_path, dg.title AS designation_title,
                   eda.status AS saved_status
            FROM employees e
            LEFT JOIN designations dg ON dg.id = e.designation_id
            LEFT JOIN employee_daily_attendance eda ON eda.employee_id = e.id AND eda.attendance_date = ?
            WHERE e.is_current = 1 AND e.type = ?";
    $params = [$f_date, $f_type];
    if ($f_desig > 0) { $sql .= " AND e.designation_id = ?"; $params[] = $f_desig; }
    if ($f_shift > 0) { $sql .= " AND e.shift_id = ?"; $params[] = $f_shift; }
    $ebf_where = []; $ebf_params = [];
    branch_apply_filter($ebf_where, $ebf_params, 'e.branch_id');
    if ($ebf_where) { $sql .= ' AND ' . implode(' AND ', $ebf_where); $params = array_merge($params, $ebf_params); }
    $sql .= " ORDER BY e.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll();
}
?>
<style>
    .attendance-toggle-btn {
        border: none; color: #fff; font-weight: 600; font-size: 12.5px;
        padding: 5px 16px; border-radius: 4px; cursor: pointer; min-width: 90px;
    }
    .attendance-toggle-btn.status-absent { background: #ef4444; }
    .attendance-toggle-btn.status-present { background: #10b981; }
    .attendance-avatar {
        width: 32px; height: 32px; border-radius: 50%; object-fit: cover;
        margin-right: 8px; border: 1px solid #e2e8f0; background: #f1f5f9;
    }
</style>

<?php if ($eam_msg): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-check-circle me-2"></i> <?php echo eam_esc($eam_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if ($eam_err): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo eam_esc($eam_err); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Filter Bar -->
<div class="card-custom" id="filterCard">
    <div class="d-flex justify-content-end gap-2 mb-3">
        <button type="button" class="btn btn-sm btn-light border" title="Collapse" onclick="toggleFilterBox()"><i class="fa-solid fa-chevron-up" id="filterChevron"></i></button>
        <a href="index.php?page=employee_attendance_manual" class="btn btn-sm btn-danger" title="Reset"><i class="fa-solid fa-xmark"></i></a>
    </div>

    <form method="GET" id="filterForm">
        <input type="hidden" name="page" value="employee_attendance_manual">
        <div class="row g-3" id="filterFields">
            <div class="col-6 col-md-3">
                <label class="form-label fw-bold small">Day <span class="text-danger">*</span></label>
                <input type="date" name="date" class="form-control" value="<?php echo eam_esc($f_date); ?>" max="<?php echo date('Y-m-d'); ?>" onchange="document.getElementById('filterForm').submit()">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-bold small">Type <span class="text-danger">*</span></label>
                <select name="f_type" class="form-select" onchange="document.getElementById('filterForm').submit()" required>
                    <option value="">-- Select --</option>
                    <?php foreach ($types_list as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo $f_type === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-bold small">Designation</label>
                <select name="f_desig" class="form-select" onchange="document.getElementById('filterForm').submit()">
                    <option value="0">-- All --</option>
                    <?php foreach ($designations_list as $d): ?>
                        <option value="<?php echo (int) $d['id']; ?>" <?php echo $f_desig === (int) $d['id'] ? 'selected' : ''; ?>><?php echo eam_esc($d['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-bold small">Shift</label>
                <select name="f_shift" class="form-select" onchange="document.getElementById('filterForm').submit()">
                    <option value="0">-- All --</option>
                    <?php foreach ($shifts_list as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_shift === (int) $s['id'] ? 'selected' : ''; ?>><?php echo eam_esc($s['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>
</div>

<!-- Employee List -->
<div class="card-custom">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h6 class="fw-bold text-dark m-0"><i class="fa-solid fa-user-tie"></i> Employees List for Attendance</h6>
    </div>

    <?php if ($f_type === ''): ?>
        <div class="alert alert-light border text-center text-muted py-4 mb-0">
            <i class="fa-solid fa-arrow-up"></i> অ্যাটেন্ডেন্স নিতে উপরে থেকে আগে <b>Type</b> সিলেক্ট করুন।
        </div>
    <?php else: ?>
        <form method="POST" action="index.php?page=employee_attendance_manual" id="attendanceForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="attendance_date" value="<?php echo eam_esc($f_date); ?>">
            <input type="hidden" name="type" value="<?php echo eam_esc($f_type); ?>">
            <input type="hidden" name="designation_id" value="<?php echo (int) $f_desig; ?>">
            <input type="hidden" name="shift_id" value="<?php echo (int) $f_shift; ?>">

            <div class="table-responsive">
                <table class="table table-bordered align-middle m-0" style="font-size: 13.5px;">
                    <thead class="table-light">
                        <tr>
                            <th style="width:50px;">Sl</th>
                            <th>Employee ID</th>
                            <th>Name</th>
                            <th>Designation</th>
                            <th>Mobile</th>
                            <th style="width:120px;">
                                <button type="button" class="attendance-toggle-btn status-absent" id="allToggleBtn" onclick="toggleAll()">All Absent</button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($employees): $sl = 1; foreach ($employees as $emp): $status = $emp['saved_status'] ?: 'Absent'; ?>
                            <tr>
                                <td><?php echo $sl++; ?></td>
                                <td><?php echo eam_esc($emp['employee_id']); ?></td>
                                <td>
                                    <img src="<?php echo $emp['photo_path'] ? '../' . eam_esc($emp['photo_path']) : 'data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2728%27 height=%2728%27%3E%3Crect width=%2728%27 height=%2728%27 fill=%27%23e2e8f0%27/%3E%3C/svg%3E'; ?>" class="attendance-avatar" alt="">
                                    <?php echo eam_esc($emp['name']); ?>
                                </td>
                                <td><?php echo eam_esc($emp['designation_title']); ?></td>
                                <td><?php echo eam_esc($emp['mobile']); ?></td>
                                <td>
                                    <input type="hidden" name="status[<?php echo (int) $emp['id']; ?>]" class="status-input" value="<?php echo $status; ?>">
                                    <button type="button" class="attendance-toggle-btn row-toggle-btn status-<?php echo strtolower($status); ?>" onclick="toggleRow(this)">
                                        <?php echo $status; ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">এই Type/Designation/Shift-এ কোনো কর্মী পাওয়া যায়নি</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($employees): ?>
                <div class="d-flex justify-content-end mt-3">
                    <button type="submit" name="save_attendance" class="btn btn-success fw-bold px-4">
                        <i class="fa-solid fa-floppy-disk"></i> Save Attendance
                    </button>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>

<script>
function toggleFilterBox() {
    var fields = document.getElementById('filterFields');
    var icon = document.getElementById('filterChevron');
    var collapsed = fields.style.display === 'none';
    fields.style.display = collapsed ? '' : 'none';
    icon.className = collapsed ? 'fa-solid fa-chevron-up' : 'fa-solid fa-chevron-down';
}

function setBtnState(btn, status) {
    var input = btn.closest('tr').querySelector('.status-input');
    input.value = status;
    btn.textContent = status;
    btn.classList.remove('status-present', 'status-absent');
    btn.classList.add(status === 'Present' ? 'status-present' : 'status-absent');
}

function toggleRow(btn) {
    var input = btn.closest('tr').querySelector('.status-input');
    var next = input.value === 'Present' ? 'Absent' : 'Present';
    setBtnState(btn, next);
}

function toggleAll() {
    var allBtn = document.getElementById('allToggleBtn');
    var markPresent = allBtn.textContent.trim() === 'All Present';
    document.querySelectorAll('.row-toggle-btn').forEach(function (btn) {
        setBtnState(btn, markPresent ? 'Present' : 'Absent');
    });
    allBtn.textContent = markPresent ? 'All Absent' : 'All Present';
    allBtn.classList.remove('status-present', 'status-absent');
    allBtn.classList.add(markPresent ? 'status-present' : 'status-absent');
}
</script>
