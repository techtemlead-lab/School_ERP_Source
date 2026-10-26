<?php
// admin/employee_attendance_daily_summary.php - Attendance Module > Employee Attendance > Attendance Summary (Daily)
// নির্দিষ্ট একটা দিনে (Type/Designation/Shift দিয়ে ফিল্টার করে) সব কর্মীর
// Attend/Absent + IN/OUT/Middle Punches + Green/Orange/Red লেট-মার্কিং একসাথে দেখায়।
// প্যাটার্ন: attendance_daily_summary.php (Student) থেকে হুবহু।

require_once __DIR__ . '/employee_attendance_common.php';

function eds_esc($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$employee_daily_table_ready = true;
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS employee_daily_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            shift_id INT NULL,
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
} catch (PDOException $e) {
    $employee_daily_table_ready = false;
}

// -----------------------------------------------------------
// ফিল্টার
// -----------------------------------------------------------
$f_type  = trim($_GET['f_type'] ?? '');
$f_desig = (int) ($_GET['f_desig'] ?? 0);
$f_shift = (int) ($_GET['f_shift'] ?? 0);
$f_day   = trim($_GET['f_day'] ?? date('d-m-Y'));
$submitted = isset($_GET['submit']);

$date_obj = DateTime::createFromFormat('d-m-Y', $f_day);
if (!$date_obj) { $date_obj = new DateTime(); $f_day = $date_obj->format('d-m-Y'); }
$date_str = $date_obj->format('Y-m-d');

$types_list        = ['Teachers', 'Staffs', 'Board Of Directors', 'Academic Committee'];
$designations_list = $pdo->query("SELECT id, title FROM designations WHERE status = 'Active' ORDER BY title ASC")->fetchAll();
$shifts_list       = $pdo->query("SELECT id, name, start_time, green_limit, orange_limit, red_limit FROM employee_shifts WHERE status = 'Active' ORDER BY name ASC")->fetchAll();

// -----------------------------------------------------------
// Report — সিলেক্ট করা দিনে ফিল্টার অনুযায়ী সব কর্মীর অ্যাটেন্ডেন্স
// -----------------------------------------------------------
$report_rows = [];
if ($submitted) {
    $sql = "SELECT e.id, e.employee_id, e.name, e.photo_path, e.rfid_card_no, e.tracking_id, e.device_serial,
                   dg.title AS designation_title, esh.name AS shift_name, esh.start_time, esh.green_limit, esh.orange_limit, esh.red_limit
            FROM employees e
            LEFT JOIN designations dg ON dg.id = e.designation_id
            LEFT JOIN employee_shifts esh ON esh.id = e.shift_id
            WHERE e.is_current = 1";
    $params = [];
    if ($f_type !== '')  { $sql .= " AND e.type = ?";           $params[] = $f_type; }
    if ($f_desig > 0)    { $sql .= " AND e.designation_id = ?"; $params[] = $f_desig; }
    if ($f_shift > 0)    { $sql .= " AND e.shift_id = ?";       $params[] = $f_shift; }
    $ebf_where = []; $ebf_params = [];
    branch_apply_filter($ebf_where, $ebf_params, 'e.branch_id');
    if ($ebf_where) { $sql .= ' AND ' . implode(' AND ', $ebf_where); $params = array_merge($params, $ebf_params); }
    $sql .= " ORDER BY e.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll();

    foreach ($employees as $emp) {
        $by_day = ea_fetch_punches($pdo, $emp, $date_str, date('Y-m-d', strtotime($date_str . ' +1 day')));
        $dt = ea_day_times($by_day[$date_str] ?? []);
        $in_time = $dt['in']; $out_time = $dt['out'];
        $middle = array_map('ea_fmt_time', $dt['middle']);

        $manual_status = null;
        if ($employee_daily_table_ready) {
            $mstmt = $pdo->prepare("SELECT status FROM employee_daily_attendance WHERE employee_id = ? AND attendance_date = ?");
            $mstmt->execute([$emp['id'], $date_str]);
            $manual_status = $mstmt->fetch()['status'] ?? null;
        }

        $attended = ($in_time !== null) || ($manual_status === 'Present');

        $mark = null;
        if ($in_time && !empty($emp['start_time'])) {
            $shift_start = strtotime($date_str . ' ' . $emp['start_time']);
            $minutes_late = max(0, (int) round((strtotime($in_time) - $shift_start) / 60));
            $green_limit  = (int) ($emp['green_limit'] ?? 0);
            $orange_limit = (int) ($emp['orange_limit'] ?? 0);
            if ($minutes_late <= $green_limit) { $mark = 'G'; }
            elseif ($minutes_late <= $orange_limit) { $mark = 'Y'; }
            else { $mark = 'R'; }
        }

        $report_rows[] = $emp + [
            'attended' => $attended, 'in' => $in_time, 'out' => $out_time,
            'middle' => $middle, 'mark' => $mark,
        ];
    }
}

$display_date = date('F j Y', strtotime($date_str));
?>
<style>
    @media print {
        @page { size: A4 portrait; margin: 8mm; }
        html, body { background: #fff !important; }
        .no-print, .sidebar, .sticky-top-area, .top-navbar, .breadcrumb-bar, footer { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; }
        .content-body { padding: 0 !important; margin: 0 !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
        .table-responsive { overflow: visible !important; }
        .card-custom table { width: 100% !important; font-size: 11px !important; }
        .card-custom table th, .card-custom table td { padding: 3px 4px !important; }
        .card-custom table a { color: #000 !important; text-decoration: none !important; }
        .card-custom table thead { display: table-header-group; }
        .card-custom table tr { page-break-inside: avoid; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
    .ds-report-header { text-align: center; margin-bottom: 18px; }
    .ds-report-header h4 { color: #1e3a8a; font-weight: 800; margin-bottom: 4px; }
    .mark-dot { width: 16px; height: 16px; border-radius: 4px; display: inline-block; }
    .mark-G { background: #10b981; }
    .mark-Y { background: #f59e0b; }
    .mark-R { background: #ef4444; }
</style>

<!-- Filter Bar -->
<div class="card-custom no-print" id="filterCard">
    <div class="d-flex justify-content-end gap-2 mb-3">
        <button type="button" class="btn btn-sm btn-light border" title="Collapse" onclick="toggleFilterBox()"><i class="fa-solid fa-chevron-up" id="filterChevron"></i></button>
        <a href="index.php?page=employee_attendance_daily_summary" class="btn btn-sm btn-danger" title="Reset"><i class="fa-solid fa-xmark"></i></a>
    </div>

    <form method="GET" id="filterForm">
        <input type="hidden" name="page" value="employee_attendance_daily_summary">
        <input type="hidden" name="submit" value="1">
        <div class="row g-3" id="filterFields">
            <div class="col-md-4">
                <label class="form-label fw-bold small">Type</label>
                <select name="f_type" class="form-select">
                    <option value="">-- All --</option>
                    <?php foreach ($types_list as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo $f_type === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small">Designation</label>
                <select name="f_desig" class="form-select">
                    <option value="0">-- Select Designation --</option>
                    <?php foreach ($designations_list as $d): ?>
                        <option value="<?php echo (int) $d['id']; ?>" <?php echo $f_desig === (int) $d['id'] ? 'selected' : ''; ?>><?php echo eds_esc($d['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small">Shift</label>
                <select name="f_shift" class="form-select">
                    <option value="0">-- Select Shift --</option>
                    <?php foreach ($shifts_list as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_shift === (int) $s['id'] ? 'selected' : ''; ?>><?php echo eds_esc($s['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small">Day</label>
                <input type="text" name="f_day" class="form-control" placeholder="dd-mm-yyyy" value="<?php echo eds_esc($f_day); ?>" onfocus="(this.type='date')" onblur="if(!this.value)this.type='text'">
            </div>
            <div class="col-md-4 d-flex align-items-end justify-content-end">
                <button type="submit" class="btn btn-primary fw-bold px-4" style="background-color:#1e3a8a;">Submit</button>
            </div>
        </div>
    </form>
</div>

<?php if ($submitted): ?>
    <div class="d-flex justify-content-end mb-2 no-print">
        <button type="button" class="btn btn-primary fw-bold" style="background-color:#1e3a8a;" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Print
        </button>
    </div>

    <div class="card-custom">
        <?php $branch = $pdo->query("SELECT * FROM branches ORDER BY id ASC LIMIT 1")->fetch(); ?>
        <div class="ds-report-header">
            <h4><?php echo eds_esc($branch['branch_name'] ?? ''); ?></h4>
            <div class="text-muted small">(<?php echo eds_esc(trim(($branch['address'] ?? '') . ', ' . ($branch['city'] ?? '') . '-' . ($branch['zip_code'] ?? ''), ', ')); ?>)</div>
            <div class="text-muted small">Phone: <?php echo eds_esc($branch['phone'] ?? ''); ?></div>

            <div class="mt-3 fw-semibold text-secondary">Daily Attendance Summary (<?php echo eds_esc($display_date); ?>)</div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle text-center m-0" style="font-size:13.5px;">
                <thead class="table-light">
                    <tr>
                        <th>Sl.</th>
                        <th>ID</th>
                        <th class="text-start">Name</th>
                        <th>Designation</th>
                        <th>Shift</th>
                        <th>Attend / Absent</th>
                        <th class="text-success">IN</th>
                        <th class="text-danger">OUT</th>
                        <th>Middle Punches</th>
                        <th style="background:#10b981;color:#fff;">G</th>
                        <th style="background:#f59e0b;color:#fff;">Y</th>
                        <th style="background:#ef4444;color:#fff;">R</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($report_rows): $sl = 1; foreach ($report_rows as $row): ?>
                        <tr>
                            <td><?php echo $sl++; ?></td>
                            <td><?php echo eds_esc($row['employee_id']); ?></td>
                            <td class="text-start">
                                <img src="<?php echo $row['photo_path'] ? '../' . eds_esc($row['photo_path']) : 'data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2728%27 height=%2728%27%3E%3Crect width=%2728%27 height=%2728%27 fill=%27%23e2e8f0%27/%3E%3C/svg%3E'; ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;margin-right:8px;border:1px solid #e2e8f0;">
                                <?php echo eds_esc($row['name']); ?>
                            </td>
                            <td><?php echo eds_esc($row['designation_title']); ?></td>
                            <td><?php echo eds_esc($row['shift_name']); ?></td>
                            <td>
                                <?php if ($row['attended']): ?>
                                    <i class="fa-solid fa-check text-success fw-bold"></i>
                                <?php else: ?>
                                    <i class="fa-solid fa-xmark text-danger fw-bold"></i>
                                <?php endif; ?>
                            </td>
                            <td class="text-success fw-bold"><?php echo $row['in'] ? eds_esc(date('h:i A', strtotime($row['in']))) : ''; ?></td>
                            <td class="text-danger fw-bold"><?php echo $row['out'] ? eds_esc(date('h:i A', strtotime($row['out']))) : ''; ?></td>
                            <td class="small"><?php echo $row['middle'] ? eds_esc(implode(', ', $row['middle'])) : ''; ?></td>
                            <td><?php echo $row['mark'] === 'G' ? '<span class="mark-dot mark-G"></span>' : ''; ?></td>
                            <td><?php echo $row['mark'] === 'Y' ? '<span class="mark-dot mark-Y"></span>' : ''; ?></td>
                            <td><?php echo $row['mark'] === 'R' ? '<span class="mark-dot mark-R"></span>' : ''; ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="12" class="text-muted py-4">এই ফিল্টারে কোনো কর্মী পাওয়া যায়নি।</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <div class="card-custom text-center text-muted py-4">
        <i class="fa-solid fa-arrow-up"></i> রিপোর্ট দেখতে উপর থেকে ফিল্টার সিলেক্ট করে Submit চাপুন।
    </div>
<?php endif; ?>

<script>
function toggleFilterBox() {
    var fields = document.getElementById('filterFields');
    var icon = document.getElementById('filterChevron');
    var collapsed = fields.style.display === 'none';
    fields.style.display = collapsed ? '' : 'none';
    icon.className = collapsed ? 'fa-solid fa-chevron-up' : 'fa-solid fa-chevron-down';
}
</script>
