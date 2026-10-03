<?php
// admin/attendance_daily_summary.php - Attendance Module > Student Attendance > Attendance Summary (Daily) (স্ক্রিনশট অনুযায়ী)
// নির্দিষ্ট একটা দিনে (Session/Class/Shift/Section দিয়ে ফিল্টার করে) সব স্টুডেন্টের
// Attend/Absent + IN/OUT/Middle Punches + Green/Orange/Red লেট-মার্কিং একসাথে দেখায়।

function ds_esc($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$student_daily_table_ready = true;
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS student_daily_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            class_id INT NULL,
            section_id INT NULL,
            shift_id INT NULL,
            attendance_date DATE NOT NULL,
            status ENUM('Present','Absent') NOT NULL DEFAULT 'Absent',
            marked_by VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_date (student_id, attendance_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (PDOException $e) {
    $student_daily_table_ready = false;
}

// -----------------------------------------------------------
// ফিল্টার
// -----------------------------------------------------------
$f_session = (int) ($_GET['session_id'] ?? 0);
$f_class   = (int) ($_GET['f_class'] ?? 0);
$f_shift   = (int) ($_GET['f_shift'] ?? 0);
$f_section = (int) ($_GET['f_section'] ?? 0);
$f_day     = trim($_GET['f_day'] ?? date('d-m-Y'));
$submitted = isset($_GET['submit']);

// Day ইনপুট dd-mm-yyyy ফরম্যাটে আসবে (স্ক্রিনশটের মতো), DB কোয়েরির জন্য Y-m-d বানানো
$date_obj = DateTime::createFromFormat('d-m-Y', $f_day);
if (!$date_obj) { $date_obj = new DateTime(); $f_day = $date_obj->format('d-m-Y'); }
$date_str = $date_obj->format('Y-m-d');

$sessions_list = $pdo->query("SELECT id, session_name FROM academic_sessions WHERE status = 'Active' ORDER BY id DESC")->fetchAll();
$classes_list  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts_list   = $pdo->query("SELECT id, shift_name, start_time, green_limit, orange_limit, red_limit FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections_list = $pdo->query("SELECT id, section_name, class_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();

// -----------------------------------------------------------
// Report — সিলেক্ট করা দিনে ফিল্টার অনুযায়ী সব স্টুডেন্টের অ্যাটেন্ডেন্স
// -----------------------------------------------------------
$report_rows = [];
if ($submitted) {
    $sql = "SELECT s.id, s.student_id, s.roll_no, s.name_en, s.photo_path, s.rfid_card_no,
                   ac.class_name, ash.shift_name, ash.start_time, ash.green_limit, ash.orange_limit, ash.red_limit,
                   asec.section_name
            FROM students s
            LEFT JOIN academic_classes ac ON ac.id = s.class_id
            LEFT JOIN academic_shifts ash ON ash.id = s.shift_id
            LEFT JOIN academic_sections asec ON asec.id = s.section_id
            WHERE s.is_current = 1";
    $params = [];
    if ($f_session > 0) { $sql .= " AND s.session_id = ?"; $params[] = $f_session; }
    if ($f_class > 0)   { $sql .= " AND s.class_id = ?";   $params[] = $f_class; }
    if ($f_shift > 0)   { $sql .= " AND s.shift_id = ?";   $params[] = $f_shift; }
    if ($f_section > 0) { $sql .= " AND s.section_id = ?"; $params[] = $f_section; }
    $bf_where = []; $bf_params = [];
    branch_apply_filter($bf_where, $bf_params, 's.branch_id');
    if ($bf_where) { $sql .= ' AND ' . implode(' AND ', $bf_where); $params = array_merge($params, $bf_params); }
    $sql .= " ORDER BY ac.rank ASC, (s.roll_no + 0) ASC, s.roll_no ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    foreach ($students as $stu) {
        $punches = [];
        try {
            $pstmt = $pdo->prepare(
                "SELECT punch_type, punch_time FROM device_attendance_logs
                 WHERE person_type = 'student' AND (person_uid = ? OR person_uid = ?) AND DATE(punch_time) = ?
                 ORDER BY punch_time ASC"
            );
            $pstmt->execute([$stu['rfid_card_no'], $stu['student_id'], $date_str]);
            $punches = $pstmt->fetchAll();
        } catch (PDOException $e) { /* device_attendance_logs না থাকলেও রিপোর্ট চলবে */ }

        $manual_status = null;
        if ($student_daily_table_ready) {
            $mstmt = $pdo->prepare("SELECT status FROM student_daily_attendance WHERE student_id = ? AND attendance_date = ?");
            $mstmt->execute([$stu['id'], $date_str]);
            $manual_status = $mstmt->fetch()['status'] ?? null;
        }

        $in_time = null; $out_time = null; $middle = [];
        if ($punches) {
            $in_time = $punches[0]['punch_time'];
            $out_time = end($punches)['punch_time'];
            if (count($punches) > 2) {
                foreach (array_slice($punches, 1, -1) as $mp) {
                    $middle[] = date('h:i A', strtotime($mp['punch_time']));
                }
            }
        }

        $attended = ($in_time !== null) || ($manual_status === 'Present');

        $mark = null;
        if ($in_time && !empty($stu['start_time'])) {
            $shift_start = strtotime($date_str . ' ' . $stu['start_time']);
            $minutes_late = max(0, (int) round((strtotime($in_time) - $shift_start) / 60));
            $green_limit  = (int) ($stu['green_limit'] ?? 0);
            $orange_limit = (int) ($stu['orange_limit'] ?? 0);
            if ($minutes_late <= $green_limit) { $mark = 'G'; }
            elseif ($minutes_late <= $orange_limit) { $mark = 'Y'; }
            else { $mark = 'R'; }
        }

        $report_rows[] = $stu + [
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
        <a href="index.php?page=attendance_daily_summary" class="btn btn-sm btn-danger" title="Reset"><i class="fa-solid fa-xmark"></i></a>
    </div>

    <form method="GET" id="filterForm">
        <input type="hidden" name="page" value="attendance_daily_summary">
        <input type="hidden" name="submit" value="1">
        <div class="row g-3" id="filterFields">
            <div class="col-md-4">
                <label class="form-label fw-bold small">Session</label>
                <select name="session_id" class="form-select">
                    <option value="0">-- All --</option>
                    <?php foreach ($sessions_list as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>><?php echo ds_esc($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small">Class</label>
                <select name="f_class" class="form-select">
                    <option value="0">-- Select Class --</option>
                    <?php foreach ($classes_list as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo ds_esc($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small">Shift</label>
                <select name="f_shift" class="form-select">
                    <option value="0">-- Select Student Shift --</option>
                    <?php foreach ($shifts_list as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_shift === (int) $s['id'] ? 'selected' : ''; ?>><?php echo ds_esc($s['shift_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-bold small">Section</label>
                <select name="f_section" class="form-select">
                    <option value="0">-- Select Section --</option>
                    <?php foreach ($sections_list as $sec): if ($f_class && (int) $sec['class_id'] !== $f_class) continue; ?>
                        <option value="<?php echo (int) $sec['id']; ?>" <?php echo $f_section === (int) $sec['id'] ? 'selected' : ''; ?>><?php echo ds_esc($sec['section_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small">Day</label>
                <input type="text" name="f_day" class="form-control" placeholder="dd-mm-yyyy" value="<?php echo ds_esc($f_day); ?>" onfocus="(this.type='date')" onblur="if(!this.value)this.type='text'">
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
            <h4><?php echo ds_esc($branch['branch_name'] ?? ''); ?></h4>
            <div class="text-muted small">(<?php echo ds_esc(trim(($branch['address'] ?? '') . ', ' . ($branch['city'] ?? '') . '-' . ($branch['zip_code'] ?? ''), ', ')); ?>)</div>
            <div class="text-muted small">Phone: <?php echo ds_esc($branch['phone'] ?? ''); ?></div>

            <div class="mt-3 fw-semibold text-secondary">Daily Attendance Summary (<?php echo ds_esc($display_date); ?>)</div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle text-center m-0" style="font-size:13.5px;">
                <thead class="table-light">
                    <tr>
                        <th>Sl.</th>
                        <th>ID</th>
                        <th class="text-start">Name</th>
                        <th>Class</th>
                        <th>Roll</th>
                        <th>Shift</th>
                        <th>Section</th>
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
                            <td><?php echo ds_esc($row['student_id']); ?></td>
                            <td class="text-start">
                                <img src="<?php echo $row['photo_path'] ? '../' . ds_esc($row['photo_path']) : 'data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2728%27 height=%2728%27%3E%3Crect width=%2728%27 height=%2728%27 fill=%27%23e2e8f0%27/%3E%3C/svg%3E'; ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;margin-right:8px;border:1px solid #e2e8f0;">
                                <?php echo ds_esc($row['name_en']); ?>
                            </td>
                            <td><?php echo ds_esc($row['class_name']); ?></td>
                            <td><?php echo ds_esc($row['roll_no']); ?></td>
                            <td><?php echo ds_esc($row['shift_name']); ?></td>
                            <td><?php echo ds_esc($row['section_name']); ?></td>
                            <td>
                                <?php if ($row['attended']): ?>
                                    <i class="fa-solid fa-check text-success fw-bold"></i>
                                <?php else: ?>
                                    <i class="fa-solid fa-xmark text-danger fw-bold"></i>
                                <?php endif; ?>
                            </td>
                            <td class="text-success fw-bold"><?php echo $row['in'] ? ds_esc(date('h:i A', strtotime($row['in']))) : ''; ?></td>
                            <td class="text-danger fw-bold"><?php echo $row['out'] ? ds_esc(date('h:i A', strtotime($row['out']))) : ''; ?></td>
                            <td class="small"><?php echo $row['middle'] ? ds_esc(implode(', ', $row['middle'])) : ''; ?></td>
                            <td><?php echo $row['mark'] === 'G' ? '<span class="mark-dot mark-G"></span>' : ''; ?></td>
                            <td><?php echo $row['mark'] === 'Y' ? '<span class="mark-dot mark-Y"></span>' : ''; ?></td>
                            <td><?php echo $row['mark'] === 'R' ? '<span class="mark-dot mark-R"></span>' : ''; ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="14" class="text-muted py-4">এই ফিল্টারে কোনো শিক্ষার্থী পাওয়া যায়নি।</td></tr>
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
