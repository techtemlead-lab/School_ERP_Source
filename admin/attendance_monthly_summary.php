<?php
// admin/attendance_monthly_summary.php - Attendance Module > Student Attendance > Attendance Summary (Monthly) (স্ক্রিনশট অনুযায়ী)
// Session/Class/Shift/Section দিয়ে ফিল্টার করে সব স্টুডেন্টের একটা নির্দিষ্ট মাসের
// Weekend/Holidays/Attend/Absent + Green/Orange/Red টোটাল কাউন্ট একসাথে দেখায়।

function dms_esc($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

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
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

$f_session = (int) ($_GET['session_id'] ?? 0);
$f_class   = (int) ($_GET['f_class'] ?? 0);
$f_shift   = (int) ($_GET['f_shift'] ?? 0);
$f_section = (int) ($_GET['f_section'] ?? 0);
$f_month   = (int) ($_GET['f_month'] ?? date('n'));
$f_year    = (int) ($_GET['f_year'] ?? date('Y'));
if ($f_month < 1 || $f_month > 12) { $f_month = (int) date('n'); }
if ($f_year < 2000 || $f_year > 2100) { $f_year = (int) date('Y'); }
$submitted = isset($_GET['submit']);

$sessions_list = $pdo->query("SELECT id, session_name FROM academic_sessions WHERE status = 'Active' ORDER BY id DESC")->fetchAll();
$classes_list  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts_list   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections_list = $pdo->query("SELECT id, section_name, class_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
$years_range   = range((int) date('Y') + 1, 2023);

// -----------------------------------------------------------
// Report — সিলেক্ট করা মাসে ফিল্টার অনুযায়ী সব স্টুডেন্টের অ্যাগ্রিগেট কাউন্ট
// -----------------------------------------------------------
$report_rows = [];
if ($submitted) {
    $branch = $pdo->query("SELECT * FROM branches ORDER BY id ASC LIMIT 1")->fetch();
    $weekly_holidays = array_map('trim', explode(',', $branch['weekly_holidays'] ?? 'Friday'));

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

    $today = new DateTime();
    $is_current_month = ((int) $today->format('n') === $f_month && (int) $today->format('Y') === $f_year);
    $days_in_month = (int) date('t', mktime(0, 0, 0, $f_month, 1, $f_year));
    $end_day = $is_current_month ? max(0, (int) $today->format('j') - 1) : $days_in_month;
    if (mktime(0, 0, 0, $f_month, 1, $f_year) > $today->getTimestamp()) { $end_day = 0; }

    foreach ($students as $stu) {
        $summary = ['weekend' => 0, 'holidays' => 0, 'attend' => 0, 'absent' => 0, 'green' => 0, 'orange' => 0, 'red' => 0];

        // ঐ স্টুডেন্টের এই মাসের সব ডিভাইস পাঞ্চ ও ম্যানুয়াল স্ট্যাটাস একসাথে টেনে PHP-তে দিনভিত্তিক গ্রুপ করা
        $punch_by_day = [];
        try {
            $pstmt = $pdo->prepare(
                "SELECT DATE(punch_time) AS d, punch_type, punch_time FROM device_attendance_logs
                 WHERE person_type = 'student' AND (person_uid = ? OR person_uid = ?)
                   AND punch_time >= ? AND punch_time < DATE_ADD(?, INTERVAL 1 MONTH)
                 ORDER BY punch_time ASC"
            );
            $month_start = sprintf('%04d-%02d-01', $f_year, $f_month);
            $pstmt->execute([$stu['rfid_card_no'], $stu['student_id'], $month_start, $month_start]);
            foreach ($pstmt->fetchAll() as $p) { $punch_by_day[$p['d']][] = $p; }
        } catch (PDOException $e) { /* device_attendance_logs না থাকলেও চলবে */ }

        $manual_by_day = [];
        if ($student_daily_table_ready) {
            $mstmt = $pdo->prepare(
                "SELECT attendance_date, status FROM student_daily_attendance
                 WHERE student_id = ? AND attendance_date >= ? AND attendance_date < DATE_ADD(?, INTERVAL 1 MONTH)"
            );
            $month_start = sprintf('%04d-%02d-01', $f_year, $f_month);
            $mstmt->execute([$stu['id'], $month_start, $month_start]);
            foreach ($mstmt->fetchAll() as $m) { $manual_by_day[$m['attendance_date']] = $m['status']; }
        }

        for ($d = 1; $d <= $end_day; $d++) {
            $date_str = sprintf('%04d-%02d-%02d', $f_year, $f_month, $d);
            $day_name = date('l', strtotime($date_str));
            $is_weekend = in_array($day_name, $weekly_holidays, true);

            $day_punches = $punch_by_day[$date_str] ?? [];
            $in_time = $day_punches ? $day_punches[0]['punch_time'] : null;
            $manual_status = $manual_by_day[$date_str] ?? null;
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

            if ($is_weekend) { $summary['weekend']++; }
            if ($attended) { $summary['attend']++; } else { $summary['absent']++; }
            if ($mark === 'G') { $summary['green']++; }
            if ($mark === 'Y') { $summary['orange']++; }
            if ($mark === 'R') { $summary['red']++; }
        }

        $report_rows[] = $stu + ['summary' => $summary];
    }
}

$display_month_year = ($months[$f_month] ?? '') . ' ' . $f_year;
?>
<style>
    @media print {
        @page { size: A4 landscape; margin: 8mm; }
        html, body { background: #fff !important; }
        .no-print, .sidebar, .sticky-top-area, .top-navbar, .breadcrumb-bar, footer { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; }
        .content-body { padding: 0 !important; margin: 0 !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
        .table-responsive { overflow: visible !important; }
        .card-custom table { width: 100% !important; font-size: 9.5px !important; }
        .card-custom table th, .card-custom table td { padding: 2px 3px !important; }
        .card-custom table a { color: #000 !important; text-decoration: none !important; }
        .card-custom table thead { display: table-header-group; }
        .card-custom table tr { page-break-inside: avoid; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
    .dms-report-header { text-align: center; margin-bottom: 18px; }
    .dms-report-header h4 { color: #1e3a8a; font-weight: 800; margin-bottom: 4px; }
</style>

<!-- Filter Bar -->
<div class="card-custom no-print" id="filterCard">
    <div class="d-flex justify-content-end gap-2 mb-3">
        <button type="button" class="btn btn-sm btn-light border" title="Collapse" onclick="toggleFilterBox()"><i class="fa-solid fa-chevron-up" id="filterChevron"></i></button>
        <a href="index.php?page=attendance_monthly_summary" class="btn btn-sm btn-danger" title="Reset"><i class="fa-solid fa-xmark"></i></a>
    </div>

    <form method="GET" id="filterForm">
        <input type="hidden" name="page" value="attendance_monthly_summary">
        <input type="hidden" name="submit" value="1">
        <div class="row g-3" id="filterFields">
            <div class="col-md-4">
                <label class="form-label fw-bold small">Session</label>
                <select name="session_id" class="form-select">
                    <option value="0">-- All --</option>
                    <?php foreach ($sessions_list as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>><?php echo dms_esc($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small">Class</label>
                <select name="f_class" class="form-select">
                    <option value="0">-- Select Class --</option>
                    <?php foreach ($classes_list as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo dms_esc($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small">Shift</label>
                <select name="f_shift" class="form-select">
                    <option value="0">-- Select Student Shift --</option>
                    <?php foreach ($shifts_list as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_shift === (int) $s['id'] ? 'selected' : ''; ?>><?php echo dms_esc($s['shift_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-bold small">Section</label>
                <select name="f_section" class="form-select">
                    <option value="0">-- Select Section --</option>
                    <?php foreach ($sections_list as $sec): if ($f_class && (int) $sec['class_id'] !== $f_class) continue; ?>
                        <option value="<?php echo (int) $sec['id']; ?>" <?php echo $f_section === (int) $sec['id'] ? 'selected' : ''; ?>><?php echo dms_esc($sec['section_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small">Month</label>
                <select name="f_month" class="form-select">
                    <?php foreach ($months as $num => $name): ?>
                        <option value="<?php echo $num; ?>" <?php echo $f_month === $num ? 'selected' : ''; ?>><?php echo $name; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small">Year</label>
                <select name="f_year" class="form-select">
                    <?php foreach ($years_range as $y): ?>
                        <option value="<?php echo $y; ?>" <?php echo $f_year === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-12 d-flex justify-content-end">
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
        <div class="dms-report-header">
            <h4><?php echo dms_esc($branch['branch_name'] ?? ''); ?></h4>
            <div class="text-muted small">(<?php echo dms_esc(trim(($branch['address'] ?? '') . ', ' . ($branch['city'] ?? '') . '-' . ($branch['zip_code'] ?? ''), ', ')); ?>)</div>
            <div class="text-muted small">Phone: <?php echo dms_esc($branch['phone'] ?? ''); ?></div>

            <div class="mt-3 fw-semibold text-secondary">Monthly Attendance Summary for the Month of <?php echo dms_esc($display_month_year); ?></div>
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
                        <th style="color:#d97706;">Weekend</th>
                        <th style="color:#ec4899;">Holidays</th>
                        <th style="color:#16a34a;">Attend</th>
                        <th style="color:#ef4444;">Absent</th>
                        <th style="background:#10b981;color:#fff;">G</th>
                        <th style="background:#f59e0b;color:#fff;">Y</th>
                        <th style="background:#ef4444;color:#fff;">R</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($report_rows): $sl = 1; foreach ($report_rows as $row): $sm = $row['summary']; ?>
                        <tr>
                            <td><?php echo $sl++; ?></td>
                            <td><?php echo dms_esc($row['student_id']); ?></td>
                            <td class="text-start">
                                <img src="<?php echo $row['photo_path'] ? '../' . dms_esc($row['photo_path']) : 'data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2728%27 height=%2728%27%3E%3Crect width=%2728%27 height=%2728%27 fill=%27%23e2e8f0%27/%3E%3C/svg%3E'; ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;margin-right:8px;border:1px solid #e2e8f0;">
                                <?php echo dms_esc($row['name_en']); ?>
                            </td>
                            <td><?php echo dms_esc($row['class_name']); ?></td>
                            <td><?php echo dms_esc($row['roll_no']); ?></td>
                            <td><?php echo dms_esc($row['shift_name']); ?></td>
                            <td><?php echo dms_esc($row['section_name']); ?></td>
                            <td style="color:#d97706;font-weight:700;"><?php echo (int) $sm['weekend']; ?></td>
                            <td style="color:#ec4899;font-weight:700;"><?php echo (int) $sm['holidays']; ?></td>
                            <td style="color:#16a34a;font-weight:700;"><?php echo (int) $sm['attend']; ?></td>
                            <td style="color:#ef4444;font-weight:700;"><?php echo (int) $sm['absent']; ?></td>
                            <td style="color:#10b981;font-weight:700;"><?php echo (int) $sm['green']; ?></td>
                            <td style="color:#f59e0b;font-weight:700;"><?php echo (int) $sm['orange']; ?></td>
                            <td style="color:#ef4444;font-weight:700;"><?php echo (int) $sm['red']; ?></td>
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
