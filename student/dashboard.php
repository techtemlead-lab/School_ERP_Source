<?php
// student/dashboard.php - Student Portal Dashboard
$class_name   = sp_lookup_name($pdo, 'academic_classes', $student['class_id'] ?: null);
$section_name = sp_lookup_name($pdo, 'academic_sections', $student['section_id'] ?: null);
$session_name = sp_lookup_name($pdo, 'academic_sessions', $student['session_id'] ?: null);
$today_label  = date('F j, Y');

// -----------------------------------------------------------
// Store / Stationery Due (Inventory Sales মডিউল থেকে) — এটা Fee Ledger থেকে
// সম্পূর্ণ আলাদা রাখা হয়েছে, শুধু sales_invoices টেবিল থেকে পড়ে student portal-এ দেখানো হচ্ছে
// -----------------------------------------------------------
$store_due = 0.0;
try {
    $sd_stmt = $pdo->prepare("SELECT COALESCE(SUM(due_amount), 0) FROM sales_invoices WHERE buyer_type = 'student' AND student_pk = ?");
    $sd_stmt->execute([(int) $student['id']]);
    $store_due = (float) $sd_stmt->fetchColumn();
} catch (\Throwable $e) { /* টেবিল/কলাম না থাকলে চুপচাপ স্কিপ */ }

// আজকের উপস্থিতি (থাকলে দেখানো হবে)
$today_att = null;
try {
    $stmt = $pdo->prepare("SELECT status FROM student_daily_attendance WHERE student_id = ? AND attendance_date = CURDATE()");
    $stmt->execute([$student['id']]);
    $today_att = $stmt->fetchColumn() ?: null;
} catch (\Throwable $e) { /* table না থাকলে চুপচাপ স্কিপ */ }

// -----------------------------------------------------------
// ১) এই মাসের Attendance (Present vs Absent) — গ্রাফের জন্য
// -----------------------------------------------------------
$att_present = 0; $att_absent = 0; $att_has_data = false;
try {
    $stmt = $pdo->prepare(
        "SELECT status, COUNT(*) AS cnt FROM student_daily_attendance
         WHERE student_id = ? AND MONTH(attendance_date) = MONTH(CURDATE()) AND YEAR(attendance_date) = YEAR(CURDATE())
         GROUP BY status"
    );
    $stmt->execute([$student['id']]);
    foreach ($stmt->fetchAll() as $r) {
        if ($r['status'] === 'Present') { $att_present = (int) $r['cnt']; }
        if ($r['status'] === 'Absent')  { $att_absent  = (int) $r['cnt']; }
    }
    $att_has_data = ($att_present + $att_absent) > 0;
} catch (\Throwable $e) { /* table নেই */ }
$att_total = $att_present + $att_absent;
$att_percent = $att_total > 0 ? round(($att_present / $att_total) * 100) : 0;

// -----------------------------------------------------------
// ২) Fee Dues সামারি (Paid vs Due) — Finance Module এর dr_student_summary() রিইউজ
// -----------------------------------------------------------
$dues_paid = 0.0; $dues_due = 0.0; $dues_has_data = false;
try {
    require_once __DIR__ . '/../admin/finance_due_report_common.php';
    dr_ensure_tables($pdo);
    $fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC")->fetchAll();
    $dues_summary = dr_student_summary($pdo, $student, (int) $student['session_id'], $fee_heads, (int) date('n'));
    $dues_due = (float) $dues_summary['total_due'];
    $dues_paid = max(0, (float) $dues_summary['total_collection'] - $dues_due);
    $dues_has_data = ($dues_paid + $dues_due) > 0;
} catch (\Throwable $e) { /* finance table/data নেই */ }

// -----------------------------------------------------------
// ৩) সর্বশেষ প্রকাশিত Result (GPA + Percentage) — সবচেয়ে বেশি rank এর Term যেটাতে মার্ক আছে
// -----------------------------------------------------------
$result_has_data = false;
$result_term_name = '';
$result_gpa = 0.0;
$result_percentage = 0.0;
$result_fail = false;
try {
    require_once __DIR__ . '/../config/report_card_helpers.php';
    $rterm_stmt = $pdo->prepare(
        "SELECT exam_terms.id, exam_terms.term_name FROM exam_terms
         WHERE exam_terms.session_id = ? AND exam_terms.status = 'Active'
           AND EXISTS (SELECT 1 FROM exam_marks WHERE exam_marks.term_id = exam_terms.id AND exam_marks.student_id = ?)
         ORDER BY exam_terms.rank DESC LIMIT 1"
    );
    $rterm_stmt->execute([(int) $student['session_id'], (int) $student['id']]);
    $rterm = $rterm_stmt->fetch();

    if ($rterm) {
        $result_term_name = $rterm['term_name'];
        $r_defs = rc_get_subject_defs($pdo, (int) $student['class_id'], (int) $rterm['id'], 0);
        $r_subject_ids = array_keys($r_defs);

        if ($r_subject_ids) {
            $r_grade_stmt = $pdo->prepare("SELECT grade_letter, gpa, mark_from, mark_to FROM exam_grade_points WHERE session_id = ? ORDER BY mark_from DESC");
            $r_grade_stmt->execute([(int) $student['session_id']]);
            $r_grade_bands = $r_grade_stmt->fetchAll();

            $r_in_subj = implode(',', array_fill(0, count($r_subject_ids), '?'));

            $r_mk = $pdo->prepare("SELECT subject_id, part_id, marks_obtained FROM exam_marks WHERE term_id = ? AND student_id = ? AND subject_id IN ($r_in_subj)");
            $r_mk->execute(array_merge([(int) $rterm['id'], (int) $student['id']], $r_subject_ids));
            $r_marks_map = [];
            foreach ($r_mk->fetchAll() as $row) { $r_marks_map[$row['subject_id']][$row['part_id']] = $row['marks_obtained']; }

            $r_at = $pdo->prepare("SELECT subject_id, part_id, status FROM exam_attendance_subject WHERE term_id = ? AND student_id = ? AND subject_id IN ($r_in_subj)");
            $r_at->execute(array_merge([(int) $rterm['id'], (int) $student['id']], $r_subject_ids));
            $r_att_map = [];
            foreach ($r_at->fetchAll() as $row) { $r_att_map[$row['subject_id']][$row['part_id']] = $row['status']; }

            $r_result = rc_student_result($r_defs, $r_marks_map, $r_att_map, $r_grade_bands);
            $result_gpa = $r_result['overall_fail'] ? 0.0 : $r_result['overall_gpa'];
            $result_percentage = $r_result['percentage'];
            $result_fail = $r_result['overall_fail'];
            $result_has_data = true;
        }
    }
} catch (\Throwable $e) { /* result table/data নেই */ }

// -----------------------------------------------------------
// ৪) সাম্প্রতিক Notice — website.php থেকে পরিচালিত
// -----------------------------------------------------------
$notices = [];
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            notice_date DATE NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $n_stmt = $pdo->query("SELECT id, title, notice_date FROM notices WHERE is_active = 1 ORDER BY notice_date DESC, id DESC LIMIT 6");
    $notices = $n_stmt->fetchAll();
} catch (\Throwable $e) { /* table নেই */ }

// -----------------------------------------------------------
// ৫) আসন্ন Event/Holiday — Academic Calendar (academic_holidays) থেকে
// -----------------------------------------------------------
$events = [];
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_holidays (
            id INT AUTO_INCREMENT PRIMARY KEY,
            holiday_name VARCHAR(150) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            holiday_type VARCHAR(30) NOT NULL DEFAULT 'General',
            remarks VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $e_stmt = $pdo->query(
        "SELECT holiday_name, start_date, end_date, holiday_type FROM academic_holidays
         WHERE status = 'Active' AND end_date >= CURDATE() ORDER BY start_date ASC LIMIT 6"
    );
    $events = $e_stmt->fetchAll();
} catch (\Throwable $e) { /* table নেই */ }

function sp_event_color(string $type): string
{
    return match ($type) {
        'Government' => '#2563eb',
        'Religious'  => '#7c3aed',
        'Vacation'   => '#d97706',
        'Emergency'  => '#dc2626',
        default      => '#0f766e',
    };
}
?>
<div class="card-custom" style="background:linear-gradient(120deg,#0f766e,#0f172a);color:#fff;border:none;">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h4 class="mb-1 fw-bold">Welcome, <?php echo sp_e($student['name_en']); ?> 👋</h4>
            <p class="mb-0 opacity-75" style="font-size:13.5px;">
                <?php echo sp_e($class_name); ?> - <?php echo sp_e($section_name); ?> &middot; Session: <?php echo sp_e($session_name); ?> &middot; Roll: <?php echo sp_e($student['roll_no']); ?>
            </p>
        </div>
        <div class="text-end">
            <div style="font-size:12px;opacity:0.75;">Today</div>
            <div class="fw-bold"><?php echo $today_label; ?></div>
            <?php if ($today_att): ?>
                <span class="badge mt-1 <?php echo $today_att === 'Present' ? 'bg-success' : 'bg-danger'; ?>"><?php echo sp_e($today_att); ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($store_due > 0): ?>
<div class="card-custom" style="background:#fff7ed;border:1px solid #fed7aa;">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <i class="fa-solid fa-basket-shopping" style="color:#c2410c;"></i>
            <span class="fw-bold" style="font-size:14px;color:#7c2d12;">Store / Stationery Due (খাতা-কলম বাবদ বাকি)</span>
        </div>
        <span class="fw-bold" style="color:#c2410c;font-size:15px;">৳<?php echo number_format($store_due, 0); ?></span>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
<div class="col-lg-8">

<style>
    .sp-graph-card {
        display: block; background: white; border-radius: 10px; border: 1px solid #e2e8f0;
        box-shadow: 0 2px 10px rgba(0,0,0,0.02); padding: 20px; height: 100%;
        text-decoration: none !important; color: inherit; transition: all 0.15s ease-in-out; cursor: pointer;
    }
    .sp-graph-card:hover { border-color: #14b8a6; box-shadow: 0 6px 16px rgba(15,118,110,0.14); transform: translateY(-2px); }
    .sp-graph-wrap { position: relative; width: 130px; height: 130px; margin: 6px auto 10px; }
    .sp-graph-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .sp-graph-center .num { font-size: 20px; font-weight: 800; color: #0f172a; line-height: 1; }
    .sp-graph-center .lbl { font-size: 10px; color: #94a3b8; margin-top: 2px; }
    .sp-graph-legend { display: flex; justify-content: center; gap: 14px; font-size: 12px; margin-top: 6px; }
    .sp-graph-legend span.dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 5px; }
</style>

<div class="row g-3">
    <div class="col-md-4">
        <a href="index.php?page=my_attendance" class="sp-graph-card">
            <div class="d-flex align-items-center gap-2 justify-content-center mb-1">
                <i class="fa-solid fa-clipboard-check" style="color:#0f766e;"></i>
                <span class="fw-bold" style="font-size:14px;">Attendance (This Month)</span>
            </div>
            <?php if ($att_has_data): ?>
                <div class="sp-graph-wrap">
                    <canvas id="chartAttendance"></canvas>
                    <div class="sp-graph-center"><div class="num"><?php echo $att_percent; ?>%</div><div class="lbl">Present</div></div>
                </div>
                <div class="sp-graph-legend">
                    <span><span class="dot" style="background:#0f766e;"></span>Present: <?php echo $att_present; ?></span>
                    <span><span class="dot" style="background:#f87171;"></span>Absent: <?php echo $att_absent; ?></span>
                </div>
            <?php else: ?>
                <p class="text-muted text-center mb-0 mt-4" style="font-size:12.5px;">এই মাসে এখনো কোনো Attendance ডাটা নেই।</p>
            <?php endif; ?>
        </a>
    </div>

    <div class="col-md-4">
        <a href="index.php?page=my_dues" class="sp-graph-card">
            <div class="d-flex align-items-center gap-2 justify-content-center mb-1">
                <i class="fa-solid fa-money-bill-wave" style="color:#0f766e;"></i>
                <span class="fw-bold" style="font-size:14px;">Fee Dues</span>
            </div>
            <?php if ($dues_has_data): ?>
                <div class="sp-graph-wrap">
                    <canvas id="chartDues"></canvas>
                    <div class="sp-graph-center"><div class="num"><?php echo $dues_due > 0 ? '৳' . number_format($dues_due, 0) : '✓'; ?></div><div class="lbl"><?php echo $dues_due > 0 ? 'Due' : 'All Paid'; ?></div></div>
                </div>
                <div class="sp-graph-legend">
                    <span><span class="dot" style="background:#0f766e;"></span>Paid: ৳<?php echo number_format($dues_paid, 0); ?></span>
                    <span><span class="dot" style="background:#f87171;"></span>Due: ৳<?php echo number_format($dues_due, 0); ?></span>
                </div>
            <?php else: ?>
                <p class="text-muted text-center mb-0 mt-4" style="font-size:12.5px;">এখনো কোনো ফি নির্ধারণ করা হয়নি।</p>
            <?php endif; ?>
        </a>
    </div>

    <div class="col-md-4">
        <a href="index.php?page=report_card" class="sp-graph-card">
            <div class="d-flex align-items-center gap-2 justify-content-center mb-1">
                <i class="fa-solid fa-file-lines" style="color:#0f766e;"></i>
                <span class="fw-bold" style="font-size:14px;">Result<?php echo $result_term_name ? ' - ' . sp_e($result_term_name) : ''; ?></span>
            </div>
            <?php if ($result_has_data): ?>
                <div class="sp-graph-wrap">
                    <canvas id="chartResult"></canvas>
                    <div class="sp-graph-center"><div class="num"><?php echo $result_fail ? 'F' : number_format($result_gpa, 2); ?></div><div class="lbl">GPA</div></div>
                </div>
                <div class="sp-graph-legend">
                    <span><span class="dot" style="background:<?php echo $result_fail ? '#f87171' : '#0f766e'; ?>;"></span><?php echo $result_fail ? 'Failed' : 'Passed'; ?></span>
                    <span>Marks: <?php echo round($result_percentage); ?>%</span>
                </div>
            <?php else: ?>
                <p class="text-muted text-center mb-0 mt-4" style="font-size:12.5px;">এখনো কোনো Result প্রকাশিত হয়নি।</p>
            <?php endif; ?>
        </a>
    </div>
</div>

<?php if ($att_has_data || $dues_has_data || $result_has_data): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Chart.defaults.font.family = "'Segoe UI', Tahoma, sans-serif";

    <?php if ($att_has_data): ?>
    new Chart(document.getElementById('chartAttendance'), {
        type: 'doughnut',
        data: {
            labels: ['Present', 'Absent'],
            datasets: [{ data: [<?php echo $att_present; ?>, <?php echo $att_absent; ?>], backgroundColor: ['#0f766e', '#f87171'], borderWidth: 0 }]
        },
        options: { cutout: '72%', plugins: { legend: { display: false }, tooltip: { enabled: true } } }
    });
    <?php endif; ?>

    <?php if ($dues_has_data): ?>
    new Chart(document.getElementById('chartDues'), {
        type: 'doughnut',
        data: {
            labels: ['Paid', 'Due'],
            datasets: [{ data: [<?php echo $dues_paid; ?>, <?php echo $dues_due; ?>], backgroundColor: ['#0f766e', '#f87171'], borderWidth: 0 }]
        },
        options: { cutout: '72%', plugins: { legend: { display: false }, tooltip: { enabled: true } } }
    });
    <?php endif; ?>

    <?php if ($result_has_data): ?>
    new Chart(document.getElementById('chartResult'), {
        type: 'doughnut',
        data: {
            labels: ['Obtained', 'Remaining'],
            datasets: [{ data: [<?php echo round($result_percentage, 1); ?>, <?php echo max(0, round(100 - $result_percentage, 1)); ?>], backgroundColor: ['<?php echo $result_fail ? '#f87171' : '#0f766e'; ?>', '#e2e8f0'], borderWidth: 0 }]
        },
        options: { cutout: '72%', plugins: { legend: { display: false }, tooltip: { enabled: true } } }
    });
    <?php endif; ?>
});
</script>
<?php endif; ?>

<?php
// -----------------------------------------------------------------
// ড্যাশবোর্ডের চারটা কার্ড — আগে এগুলো শুধু "No ... assigned yet today" লেখা
// স্ট্যাটিক প্লেসহোল্ডার ছিল (কোনো কুয়েরিই হতো না), তাই হোমওয়ার্ক/লাইভ ক্লাস দেওয়া
// থাকলেও কার্ডে কিছু দেখাত না। এখন নিজের Class/Section অনুযায়ী আসল ডাটা দেখায়।
// টেবিল না থাকলে (মডিউল কখনো ব্যবহার হয়নি) চুপচাপ ফাঁকা লিস্টই দেখাবে।
// -----------------------------------------------------------------
$sd_homework = [];
$sd_live = [];
try {
    $stmt = $pdo->prepare(
        "SELECT h.id, h.title, h.deadline, sub.subject_name, s.id AS submission_id
         FROM elearning_homework h
         LEFT JOIN academic_subjects sub ON sub.id = h.subject_id
         LEFT JOIN elearning_homework_submissions s ON s.homework_id = h.id AND s.student_id = ?
         WHERE h.status = 'Active' AND h.class_id = ?
           AND (h.section_id IS NULL OR h.section_id = ?)
           AND (h.deadline IS NULL OR h.deadline >= NOW())
         ORDER BY h.deadline IS NULL, h.deadline ASC LIMIT 3"
    );
    $stmt->execute([$student['id'], $student['class_id'], $student['section_id']]);
    $sd_homework = $stmt->fetchAll();
} catch (\Throwable $e) { $sd_homework = []; }

try {
    $stmt = $pdo->prepare(
        "SELECT l.id, l.title, l.scheduled_at, l.status, sub.subject_name
         FROM elearning_live_classes l
         LEFT JOIN academic_subjects sub ON sub.id = l.subject_id
         WHERE l.class_id = ? AND (l.section_id IS NULL OR l.section_id = ?)
           AND l.status IN ('Scheduled','Live')
           AND (l.status = 'Live' OR l.scheduled_at >= NOW())
         ORDER BY l.scheduled_at ASC LIMIT 3"
    );
    $stmt->execute([$student['class_id'], $student['section_id']]);
    $sd_live = $stmt->fetchAll();
} catch (\Throwable $e) { $sd_live = []; }
?>

<div class="row g-3">

    <!-- ---------- Live Class ---------- -->
    <div class="col-md-6 col-xl-3">
        <div class="card-custom h-100 mb-0">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-video" style="color:#0f766e;"></i>
                    <span class="fw-bold" style="font-size:14px;">Live Class</span>
                </div>
                <a href="index.php?page=live_class" class="btn btn-sm" style="background:#ccfbf1;color:#0f766e;font-weight:700;font-size:11.5px;">All Live Class</a>
            </div>
            <?php if (!$sd_live): ?>
                <p class="text-muted mb-0" style="font-size:12.5px;"><i class="fa-solid fa-xmark me-1 text-danger"></i>আসন্ন কোনো লাইভ ক্লাস নেই।</p>
            <?php else: ?>
                <?php foreach ($sd_live as $lc): ?>
                    <a href="index.php?page=live_class" class="d-block text-decoration-none text-dark border-bottom py-1" style="font-size:12.5px;">
                        <span class="fw-bold"><?php echo sp_e($lc['title']); ?></span>
                        <?php if ($lc['status'] === 'Live'): ?><span class="badge bg-danger ms-1">Live</span><?php endif; ?>
                        <div class="text-muted" style="font-size:11.5px;">
                            <?php echo sp_e($lc['subject_name'] ?? '—'); ?> ·
                            <?php echo $lc['scheduled_at'] ? date('d M, h:i A', strtotime($lc['scheduled_at'])) : '—'; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ---------- Home Work ---------- -->
    <div class="col-md-6 col-xl-3">
        <div class="card-custom h-100 mb-0">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-laptop" style="color:#0f766e;"></i>
                    <span class="fw-bold" style="font-size:14px;">Home Work</span>
                </div>
                <a href="index.php?page=home_work" class="btn btn-sm" style="background:#ccfbf1;color:#0f766e;font-weight:700;font-size:11.5px;">All Home Work</a>
            </div>
            <?php if (!$sd_homework): ?>
                <p class="text-muted mb-0" style="font-size:12.5px;"><i class="fa-solid fa-xmark me-1 text-danger"></i>জমা দেওয়ার বাকি কোনো হোমওয়ার্ক নেই।</p>
            <?php else: ?>
                <?php foreach ($sd_homework as $hw): ?>
                    <a href="index.php?page=home_work" class="d-block text-decoration-none text-dark border-bottom py-1" style="font-size:12.5px;">
                        <span class="fw-bold"><?php echo sp_e($hw['title']); ?></span>
                        <?php if ($hw['submission_id']): ?>
                            <span class="badge bg-success ms-1">জমা দেওয়া</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark ms-1">বাকি</span>
                        <?php endif; ?>
                        <div class="text-muted" style="font-size:11.5px;">
                            <?php echo sp_e($hw['subject_name'] ?? '—'); ?>
                            <?php if ($hw['deadline']): ?> · <?php echo date('d M, h:i A', strtotime($hw['deadline'])); ?><?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ---------- Assignment (ব্যাকএন্ড এখনো তৈরি হয়নি) ---------- -->
    <div class="col-md-6 col-xl-3">
        <div class="card-custom h-100 mb-0">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-building-columns" style="color:#0f766e;"></i>
                    <span class="fw-bold" style="font-size:14px;">Assignment</span>
                </div>
                <a href="index.php?page=assignment" class="btn btn-sm" style="background:#ccfbf1;color:#0f766e;font-weight:700;font-size:11.5px;">All Assignment</a>
            </div>
            <p class="text-muted mb-0" style="font-size:12.5px;"><i class="fa-solid fa-xmark me-1 text-danger"></i>এখনো কোনো অ্যাসাইনমেন্ট নেই।</p>
        </div>
    </div>

    <!-- ---------- Lesson Plan (ব্যাকএন্ড এখনো তৈরি হয়নি) ---------- -->
    <div class="col-md-6 col-xl-3">
        <div class="card-custom h-100 mb-0">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-book" style="color:#0f766e;"></i>
                    <span class="fw-bold" style="font-size:14px;">Lesson Plan</span>
                </div>
                <a href="index.php?page=lesson_plan" class="btn btn-sm" style="background:#ccfbf1;color:#0f766e;font-weight:700;font-size:11.5px;">All Lesson Plan</a>
            </div>
            <p class="text-muted mb-0" style="font-size:12.5px;"><i class="fa-solid fa-xmark me-1 text-danger"></i>এখনো কোনো লেসন প্ল্যান নেই।</p>
        </div>
    </div>

</div>

<style>
    .sp-clickable-card {
        display: block; background: white; border-radius: 10px; border: 1px solid #e2e8f0;
        box-shadow: 0 2px 10px rgba(0,0,0,0.02); padding: 22px; height: 100%;
        text-decoration: none !important; color: inherit; transition: all 0.15s ease-in-out; cursor: pointer;
    }
    .sp-clickable-card:hover {
        border-color: #14b8a6; box-shadow: 0 6px 16px rgba(15,118,110,0.14); transform: translateY(-2px);
    }
    .sp-clickable-card .sp-cc-arrow { color: #94a3b8; transition: all 0.15s ease-in-out; }
    .sp-clickable-card:hover .sp-cc-arrow { color: #0f766e; transform: translateX(3px); }
</style>

<div class="row g-3">
    <div class="col-md-6">
        <a href="index.php?page=seat_plan" class="sp-clickable-card">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-chair" style="color:#0f766e;"></i>
                    <span class="fw-bold" style="font-size:14px;">My Seat Plan</span>
                </div>
                <i class="fa-solid fa-chevron-right sp-cc-arrow"></i>
            </div>
            <p class="text-muted mb-0" style="font-size:12.5px;">পরীক্ষার সিট প্ল্যান দেখতে/ডাউনলোড করতে।</p>
        </a>
    </div>
    <div class="col-md-6">
        <a href="index.php?page=admit_card" class="sp-clickable-card">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-id-card-clip" style="color:#0f766e;"></i>
                    <span class="fw-bold" style="font-size:14px;">My Admit Card</span>
                </div>
                <i class="fa-solid fa-chevron-right sp-cc-arrow"></i>
            </div>
            <p class="text-muted mb-0" style="font-size:12.5px;">অ্যাডমিট কার্ড দেখতে/ডাউনলোড করতে।</p>
        </a>
    </div>
</div>

</div>
<!-- ============== RIGHT SIDEBAR: NOTICES + EVENTS ============== -->
<div class="col-lg-4">
    <style>
        .sp-widget-list { list-style: none; padding: 0; margin: 0; }
        .sp-widget-list li {
            padding: 11px 0; border-bottom: 1px solid #f1f5f9; display: flex; gap: 10px; align-items: flex-start;
        }
        .sp-widget-list li:last-child { border-bottom: none; }
        .sp-widget-dot { width: 8px; height: 8px; border-radius: 50%; margin-top: 5px; flex-shrink: 0; }
        .sp-widget-title { font-size: 13px; font-weight: 600; color: #1e293b; line-height: 1.4; }
        .sp-widget-date { font-size: 11px; color: #94a3b8; margin-top: 2px; }
        .sp-widget-empty { text-align: center; padding: 24px 10px; color: #94a3b8; font-size: 12.5px; }
    </style>

    <div class="card-custom" style="background:#eef2ff;border:1px solid #c7d2fe;">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="d-flex align-items-center gap-2">
                <i class="fa-solid fa-bullhorn" style="color:#4338ca;"></i>
                <span class="fw-bold" style="font-size:14.5px;color:#312e81;">Notices</span>
            </div>
        </div>
        <?php if ($notices): ?>
            <ul class="sp-widget-list">
                <?php foreach ($notices as $n): ?>
                    <li style="border-bottom-color:#dde2fb;">
                        <span class="sp-widget-dot" style="background:#4338ca;"></span>
                        <div>
                            <div class="sp-widget-title"><a href="../notice_view.php?id=<?php echo (int) $n['id']; ?>" style="color:inherit;"><?php echo sp_e($n['title']); ?></a></div>
                            <div class="sp-widget-date"><i class="fa-regular fa-calendar me-1"></i><?php echo date('d M Y', strtotime($n['notice_date'])); ?></div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="sp-widget-empty"><i class="fa-solid fa-bullhorn mb-2" style="font-size:26px;color:#a5b4fc;"></i><br>এখনো কোনো নোটিশ নেই।</div>
        <?php endif; ?>
    </div>

    <div class="card-custom" style="background:#fff7ed;border:1px solid #fed7aa;">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="d-flex align-items-center gap-2">
                <i class="fa-solid fa-calendar-days" style="color:#c2410c;"></i>
                <span class="fw-bold" style="font-size:14.5px;color:#7c2d12;">Upcoming Events</span>
            </div>
            <a href="index.php?page=academic_calendar" class="text-decoration-none" style="font-size:11.5px;color:#c2410c;font-weight:700;">View All</a>
        </div>
        <?php if ($events): ?>
            <ul class="sp-widget-list">
                <?php foreach ($events as $ev): ?>
                    <li style="border-bottom-color:#fbe4c9;">
                        <span class="sp-widget-dot" style="background:<?php echo sp_event_color($ev['holiday_type']); ?>;"></span>
                        <div>
                            <div class="sp-widget-title"><?php echo sp_e($ev['holiday_name']); ?></div>
                            <div class="sp-widget-date">
                                <i class="fa-regular fa-calendar me-1"></i><?php echo date('d M', strtotime($ev['start_date'])); ?><?php echo $ev['end_date'] !== $ev['start_date'] ? ' - ' . date('d M', strtotime($ev['end_date'])) : ''; ?>
                                &nbsp;&middot;&nbsp;<span style="color:<?php echo sp_event_color($ev['holiday_type']); ?>;"><?php echo sp_e($ev['holiday_type']); ?></span>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="sp-widget-empty"><i class="fa-solid fa-calendar-days mb-2" style="font-size:26px;color:#fdba74;"></i><br>আসন্ন কোনো Event/ছুটি নেই।</div>
        <?php endif; ?>
    </div>
</div>
</div>
