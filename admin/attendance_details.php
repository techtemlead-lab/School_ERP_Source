<?php
// admin/attendance_details.php - Attendance Module > Student Attendance > Attendance Details (স্ক্রিনশট অনুযায়ী)
// একজন নির্দিষ্ট স্টুডেন্টের এক মাসের দিনভিত্তিক Attend/Absent + IN/OUT/Middle Punches +
// Green/Orange/Red (লেট মার্কিং, academic_shifts এর green_limit/orange_limit/red_limit অনুযায়ী) রিপোর্ট।

function ad_esc($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

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
$f_student = (int) ($_GET['student_id'] ?? 0);
$f_month   = (int) ($_GET['f_month'] ?? date('n'));
$f_year    = (int) ($_GET['f_year'] ?? date('Y'));
if ($f_month < 1 || $f_month > 12) { $f_month = (int) date('n'); }
if ($f_year < 2000 || $f_year > 2100) { $f_year = (int) date('Y'); }

$sessions_list = $pdo->query("SELECT id, session_name FROM academic_sessions WHERE status = 'Active' ORDER BY id DESC")->fetchAll();
$classes_list  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts_list   = $pdo->query("SELECT id, shift_name, start_time, green_limit, orange_limit, red_limit FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections_list = $pdo->query("SELECT id, section_name, class_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();

// Student ড্রপডাউনের লিস্ট (উপরের ক্লাস/শিফট/সেকশন ফিল্টার দিয়ে সংকুচিত হবে)
$stu_sql = "SELECT id, student_id, roll_no, name_en FROM students WHERE is_current = 1";
$stu_params = [];
if ($f_class > 0)   { $stu_sql .= " AND class_id = ?";   $stu_params[] = $f_class; }
if ($f_shift > 0)   { $stu_sql .= " AND shift_id = ?";   $stu_params[] = $f_shift; }
if ($f_section > 0) { $stu_sql .= " AND section_id = ?"; $stu_params[] = $f_section; }
if ($f_session > 0) { $stu_sql .= " AND session_id = ?"; $stu_params[] = $f_session; }
$ad_bf_where = []; $ad_bf_params = [];
branch_apply_filter($ad_bf_where, $ad_bf_params, 'branch_id');
if ($ad_bf_where) { $stu_sql .= ' AND ' . implode(' AND ', $ad_bf_where); $stu_params = array_merge($stu_params, $ad_bf_params); }
$stu_sql .= " ORDER BY (roll_no + 0) ASC, roll_no ASC";
$stu_stmt = $pdo->prepare($stu_sql);
$stu_stmt->execute($stu_params);
$students_list = $stu_stmt->fetchAll();

$years_range = range((int) date('Y') + 1, 2023);

// -----------------------------------------------------------
// রিপোর্ট বানানো (স্টুডেন্ট সিলেক্ট করা থাকলেই)
// -----------------------------------------------------------
$report_student = null;
$report_rows = [];
$summary = ['weekend' => 0, 'holidays' => 0, 'attend' => 0, 'absent' => 0, 'green' => 0, 'orange' => 0, 'red' => 0];

if ($f_student > 0) {
    $stmt = $pdo->prepare(
        "SELECT s.*, ash.start_time, ash.green_limit, ash.orange_limit, ash.red_limit
         FROM students s LEFT JOIN academic_shifts ash ON ash.id = s.shift_id
         WHERE s.id = ?"
    );
    $stmt->execute([$f_student]);
    $report_student = $stmt->fetch();

    $branch = $pdo->query("SELECT * FROM branches ORDER BY id ASC LIMIT 1")->fetch();
    $weekly_holidays = array_map('trim', explode(',', $branch['weekly_holidays'] ?? 'Friday'));

    if ($report_student) {
        $today = new DateTime();
        $is_current_month = ((int) $today->format('n') === $f_month && (int) $today->format('Y') === $f_year);
        $days_in_month = (int) date('t', mktime(0, 0, 0, $f_month, 1, $f_year));
        $end_day = $is_current_month ? max(0, (int) $today->format('j') - 1) : $days_in_month;
        // মাসটা ভবিষ্যতে হলে (এখনো শুরুই হয়নি) কোনো দিন দেখানোর দরকার নেই
        if (mktime(0, 0, 0, $f_month, 1, $f_year) > $today->getTimestamp()) { $end_day = 0; }

        for ($d = 1; $d <= $end_day; $d++) {
            $date_str = sprintf('%04d-%02d-%02d', $f_year, $f_month, $d);
            $day_name = date('l', strtotime($date_str));
            $is_weekend = in_array($day_name, $weekly_holidays, true);

            // ডিভাইস পাঞ্চ লগ
            $punches = [];
            try {
                $pstmt = $pdo->prepare(
                    "SELECT punch_type, punch_time FROM device_attendance_logs
                     WHERE person_type = 'student' AND (person_uid = ? OR person_uid = ?) AND DATE(punch_time) = ?
                     ORDER BY punch_time ASC"
                );
                $pstmt->execute([$report_student['rfid_card_no'], $report_student['student_id'], $date_str]);
                $punches = $pstmt->fetchAll();
            } catch (PDOException $e) { /* device_attendance_logs না থাকলেও রিপোর্ট চলবে */ }

            // ম্যানুয়াল অ্যাটেন্ডেন্স
            $manual_status = null;
            if ($student_daily_table_ready) {
                $mstmt = $pdo->prepare("SELECT status FROM student_daily_attendance WHERE student_id = ? AND attendance_date = ?");
                $mstmt->execute([$f_student, $date_str]);
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

            // Late-marking (G/Y/R) — শুধু ডিভাইস IN পাঞ্চ থাকলে, শিফট সেট করা থাকলে হিসাব হবে
            $mark = null;
            if ($in_time && !empty($report_student['start_time'])) {
                $shift_start = strtotime($date_str . ' ' . $report_student['start_time']);
                $minutes_late = max(0, (int) round((strtotime($in_time) - $shift_start) / 60));
                $green_limit  = (int) ($report_student['green_limit'] ?? 0);
                $orange_limit = (int) ($report_student['orange_limit'] ?? 0);
                if ($minutes_late <= $green_limit) { $mark = 'G'; }
                elseif ($minutes_late <= $orange_limit) { $mark = 'Y'; }
                else { $mark = 'R'; }
            }

            if ($is_weekend) { $summary['weekend']++; }
            if ($attended) { $summary['attend']++; } else { $summary['absent']++; }
            if ($mark === 'G') { $summary['green']++; }
            if ($mark === 'Y') { $summary['orange']++; }
            if ($mark === 'R') { $summary['red']++; }

            $report_rows[] = [
                'date' => $date_str, 'day' => $day_name, 'is_weekend' => $is_weekend,
                'attended' => $attended, 'in' => $in_time, 'out' => $out_time,
                'middle' => $middle, 'mark' => $mark,
            ];
        }
    }
}

$display_month_year = ($months[$f_month] ?? '') . ' ' . $f_year;
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
    .ad-report-header { text-align: center; margin-bottom: 18px; }
    .ad-report-header h4 { color: #1e3a8a; font-weight: 800; margin-bottom: 4px; }
    .mark-dot { width: 16px; height: 16px; border-radius: 4px; display: inline-block; }
    .mark-G { background: #10b981; }
    .mark-Y { background: #f59e0b; }
    .mark-R { background: #ef4444; }
</style>

<!-- Filter Bar -->
<div class="card-custom no-print" id="filterCard">
    <div class="d-flex justify-content-end gap-2 mb-3">
        <button type="button" class="btn btn-sm btn-light border" title="Collapse" onclick="toggleFilterBox()"><i class="fa-solid fa-chevron-up" id="filterChevron"></i></button>
        <a href="index.php?page=attendance_details" class="btn btn-sm btn-danger" title="Reset"><i class="fa-solid fa-xmark"></i></a>
    </div>

    <form method="GET" id="filterForm">
        <input type="hidden" name="page" value="attendance_details">
        <div class="row g-3" id="filterFields">
            <div class="col-md-4">
                <label class="form-label fw-bold small">Session</label>
                <select name="session_id" class="form-select" onchange="document.getElementById('filterForm').submit()">
                    <option value="0">-- All --</option>
                    <?php foreach ($sessions_list as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>><?php echo ad_esc($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small">Class</label>
                <select name="f_class" class="form-select" onchange="document.getElementById('filterForm').submit()">
                    <option value="0">-- Select Class --</option>
                    <?php foreach ($classes_list as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo ad_esc($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small">Shift</label>
                <select name="f_shift" class="form-select" onchange="document.getElementById('filterForm').submit()">
                    <option value="0">-- Select Student Shift --</option>
                    <?php foreach ($shifts_list as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_shift === (int) $s['id'] ? 'selected' : ''; ?>><?php echo ad_esc($s['shift_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-bold small">Section</label>
                <select name="f_section" class="form-select" onchange="document.getElementById('filterForm').submit()">
                    <option value="0">-- Select Section --</option>
                    <?php foreach ($sections_list as $sec): if ($f_class && (int) $sec['class_id'] !== $f_class) continue; ?>
                        <option value="<?php echo (int) $sec['id']; ?>" <?php echo $f_section === (int) $sec['id'] ? 'selected' : ''; ?>><?php echo ad_esc($sec['section_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small">Student <span class="text-danger">*</span></label>
                <select name="student_id" class="form-select" required>
                    <option value="0">-- Select Student --</option>
                    <?php foreach ($students_list as $st): ?>
                        <option value="<?php echo (int) $st['id']; ?>" <?php echo $f_student === (int) $st['id'] ? 'selected' : ''; ?>>
                            <?php echo ad_esc($st['student_id']); ?> - <?php echo ad_esc($st['name_en']); ?>
                        </option>
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
            <div class="col-md-8 d-flex align-items-end justify-content-end">
                <button type="submit" class="btn btn-primary fw-bold px-4" style="background-color:#1e3a8a;">Submit</button>
            </div>
        </div>
    </form>
</div>

<?php if ($f_student > 0 && $report_student): ?>
    <div class="d-flex justify-content-end mb-2 no-print">
        <button type="button" class="btn btn-primary fw-bold" style="background-color:#1e3a8a;" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Print
        </button>
    </div>

    <div class="card-custom">
        <?php
        $branch = $branch ?? $pdo->query("SELECT * FROM branches ORDER BY id ASC LIMIT 1")->fetch();
        ?>
        <div class="ad-report-header">
            <h4><?php echo ad_esc($branch['branch_name'] ?? ''); ?></h4>
            <div class="text-muted small">(<?php echo ad_esc(trim(($branch['address'] ?? '') . ', ' . ($branch['city'] ?? '') . '-' . ($branch['zip_code'] ?? ''), ', ')); ?>)</div>
            <div class="text-muted small">Phone: <?php echo ad_esc($branch['phone'] ?? ''); ?></div>
            <?php if (!empty($branch['email'])): ?><div class="small"><?php echo ad_esc($branch['email']); ?></div><?php endif; ?>

            <div class="mt-3 fw-semibold text-secondary">Attendance Details for the Month of <?php echo ad_esc($display_month_year); ?></div>
            <div class="text-secondary">ID : <?php echo ad_esc($report_student['student_id']); ?>, Name : <?php echo ad_esc($report_student['name_en']); ?></div>

            <div class="mt-2">
                <span style="color:#d97706;font-weight:700;">Weekend : <?php echo (int) $summary['weekend']; ?></span>,
                <span style="color:#ec4899;font-weight:700;">Holidays : <?php echo (int) $summary['holidays']; ?></span>,
                <span style="color:#16a34a;font-weight:700;">Attend : <?php echo (int) $summary['attend']; ?></span>,
                <span style="color:#ef4444;font-weight:700;">Absent : <?php echo (int) $summary['absent']; ?></span>,
                <span style="color:#10b981;font-weight:700;">Green : <?php echo (int) $summary['green']; ?></span>,
                <span style="color:#f59e0b;font-weight:700;">Orange : <?php echo (int) $summary['orange']; ?></span>,
                <span style="color:#ef4444;font-weight:700;">Red : <?php echo (int) $summary['red']; ?></span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle text-center m-0" style="font-size:13.5px;">
                <thead class="table-light">
                    <tr>
                        <th>Sl.</th>
                        <th>Date</th>
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
                        <tr class="<?php echo $row['is_weekend'] ? 'table-warning bg-opacity-25' : ''; ?>">
                            <td><?php echo $sl++; ?></td>
                            <td class="text-start">
                                <?php echo date('d, M Y', strtotime($row['date'])); ?><br>
                                <small class="text-muted">(<?php echo ad_esc($row['day']); ?>)</small>
                            </td>
                            <td>
                                <?php if ($row['attended']): ?>
                                    <i class="fa-solid fa-check text-success fw-bold"></i>
                                <?php else: ?>
                                    <i class="fa-solid fa-xmark text-danger fw-bold"></i>
                                <?php endif; ?>
                            </td>
                            <td class="text-success fw-bold"><?php echo $row['in'] ? ad_esc(date('h:i A', strtotime($row['in']))) : ''; ?></td>
                            <td class="text-danger fw-bold"><?php echo $row['out'] ? ad_esc(date('h:i A', strtotime($row['out']))) : ''; ?></td>
                            <td class="small"><?php echo $row['middle'] ? ad_esc(implode(', ', $row['middle'])) : ''; ?></td>
                            <td><?php echo $row['mark'] === 'G' ? '<span class="mark-dot mark-G"></span>' : ''; ?></td>
                            <td><?php echo $row['mark'] === 'Y' ? '<span class="mark-dot mark-Y"></span>' : ''; ?></td>
                            <td><?php echo $row['mark'] === 'R' ? '<span class="mark-dot mark-R"></span>' : ''; ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="9" class="text-muted py-4">এই মাসে দেখানোর মতো কোনো দিন নেই।</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php elseif ($f_student > 0): ?>
    <div class="alert alert-warning">স্টুডেন্ট পাওয়া যায়নি।</div>
<?php else: ?>
    <div class="card-custom text-center text-muted py-4">
        <i class="fa-solid fa-arrow-up"></i> রিপোর্ট দেখতে উপর থেকে একজন <b>Student</b> সিলেক্ট করে Submit চাপুন।
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
