<?php
// admin/result_tabulation_sheet.php - Result Module > Result Reports > Tabulation Sheet
// Class/Term (+ ঐচ্ছিক Session/Group/Shift/Section/Part) সিলেক্ট করলে পুরো ক্লাসের সব শিক্ষার্থীর সব Subject-এর
// মার্ক একটা বড় টেবিলে (রোল অনুযায়ী) দেখায় — সাথে প্রতি Subject-এর Full/Pass Mark, প্রতিটা Subject-এর
// Grade Letter, Total, %, GPA, Grade, Result (Pass/Fail) ও Merit Position। নিচে প্রতি Subject-এর Highest মার্ক,
// Pass/Fail সংখ্যা এবং স্বাক্ষরের ঘর থাকে; A4 Landscape-এ প্রিন্ট হয়।
// হিসাব Students Report Card-এর সাথে হুবহু এক — config/report_card_helpers.php এর শেয়ার্ড ফাংশন দিয়ে
// (Part সিলেক্ট না করলে Term-এর সব Part যোগ হয়ে Combined রেজাল্ট)।

require_once __DIR__ . '/../config/report_card_helpers.php';

// exam_attendance_subject টেবিল এখনো তৈরি না হয়ে থাকলে যেন পেজ ক্র্যাশ না করে
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_attendance_subject (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        term_id INT NOT NULL,
        part_id INT NOT NULL,
        subject_id INT NOT NULL,
        class_id INT NOT NULL,
        shift_id INT NOT NULL,
        section_id INT NOT NULL,
        student_id INT NOT NULL,
        status ENUM('Present','Absent') NOT NULL DEFAULT 'Absent',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_att (term_id, part_id, subject_id, student_id),
        KEY idx_lookup (class_id, shift_id, section_id, term_id, part_id, subject_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

function tsv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function ts_num($n): string {
    $n = (float) $n;
    return floor($n) == $n ? number_format($n, 0) : number_format($n, 2);
}

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

$institute = $pdo->query("SELECT institute_name, logo_path, phone_number FROM institute_settings WHERE id = 1")->fetch();
$ts_bid = branch_current_id();
if ($ts_bid !== null) {
    $bs = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $bs->execute([$ts_bid]);
    $branch = $bs->fetch();
} else {
    $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
}
$addr_line = $branch ? trim(($branch['address'] ?? '') . (!empty($branch['city']) ? ', ' . $branch['city'] : '') . (!empty($branch['zip_code']) ? '-' . $branch['zip_code'] : '')) : '';
$inst_display_name = ($branch['branch_name'] ?? null) ?: ($institute['institute_name'] ?? '');
$inst_phone = $branch['phone'] ?? ($institute['phone_number'] ?? '');

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
$groups   = $pdo->query("SELECT id, group_name, class_id FROM academic_groups WHERE status = 'Active' ORDER BY group_name ASC")->fetchAll();
$terms = []; $parts = [];
if ($current_session_id) {
    $t = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
    $t->execute([$current_session_id]); $terms = $t->fetchAll();
    $p = $pdo->prepare("SELECT id, part_name FROM exam_parts WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
    $p->execute([$current_session_id]); $parts = $p->fetchAll();
}

// part_id => part_name (Rank অনুযায়ী অর্ডার) — সাবজেক্টের Part সাব-কলাম হেডার বানাতে লাগবে
$part_name_map = [];
foreach ($parts as $p) { $part_name_map[(int) $p['id']] = $p['part_name']; }

// একটা সাবজেক্টের Part গুলোকে Exam Parts পেজের Rank অনুযায়ী সাজানো ক্রমে ফেরত দেয়
// ($def['parts'] key = part_id, কিন্তু rc_get_subject_defs() এটা part_id ASC ক্রমে জমা করে —
// সেটা সবসময় Rank ক্রম নাও হতে পারে, তাই এখানে $parts (Rank-ordered) অনুযায়ী re-sort করা হলো)
function ts_ordered_part_ids(array $def_parts, array $parts_rank_order): array
{
    $rank_pos = [];
    foreach ($parts_rank_order as $i => $p) { $rank_pos[(int) $p['id']] = $i; }
    $ids = array_keys($def_parts);
    usort($ids, fn($a, $b) => ($rank_pos[$a] ?? 999) <=> ($rank_pos[$b] ?? 999));
    return $ids;
}

// -----------------------------------------------------------
// ফিল্টার (GET)
// -----------------------------------------------------------
$searched     = isset($_GET['search_result']);
$f_session_id = (int) ($_GET['session_id'] ?? $current_session_id);
$f_class_id   = (int) ($_GET['class_id'] ?? 0);
$f_group_id   = (int) ($_GET['group_id'] ?? 0);
$f_shift_id   = (int) ($_GET['shift_id'] ?? 0);
$f_section_id = (int) ($_GET['section_id'] ?? 0);
$f_term_id    = (int) ($_GET['term_id'] ?? 0);
$f_part_id    = (int) ($_GET['part_id'] ?? 0);
$show_merit   = $searched ? isset($_GET['show_merit']) : true;

$class_name = $shift_name = $section_name = $group_name = $term_name = $part_name = $sheet_session_name = '';
$defs = [];
$rows = [];
$subj_stats = [];
$total_pass = 0; $total_fail = 0;
$no_subjects_assigned = false;

if ($searched && $f_class_id && $f_term_id) {
    $one = function (string $sql, int $id) use ($pdo) { $s = $pdo->prepare($sql); $s->execute([$id]); return $s->fetchColumn() ?: ''; };
    $class_name   = $one("SELECT class_name FROM academic_classes WHERE id = ?", $f_class_id);
    $shift_name   = $f_shift_id ? $one("SELECT shift_name FROM academic_shifts WHERE id = ?", $f_shift_id) : '';
    $section_name = $f_section_id ? $one("SELECT section_name FROM academic_sections WHERE id = ?", $f_section_id) : '';
    $group_name   = $f_group_id ? $one("SELECT group_name FROM academic_groups WHERE id = ?", $f_group_id) : '';
    $term_name    = $one("SELECT term_name FROM exam_terms WHERE id = ?", $f_term_id);
    $part_name    = $f_part_id ? $one("SELECT part_name FROM exam_parts WHERE id = ?", $f_part_id) : '';
    $sheet_session_name = $one("SELECT session_name FROM academic_sessions WHERE id = ?", $f_session_id) ?: date('Y');

    $defs = rc_get_subject_defs($pdo, $f_class_id, $f_term_id, $f_part_id);
    $subject_ids = array_keys($defs);

    $gs = $pdo->prepare("SELECT grade_letter, gpa, mark_from, mark_to FROM exam_grade_points WHERE session_id = ? ORDER BY mark_from DESC");
    $gs->execute([$f_session_id]);
    $grade_bands = $gs->fetchAll();

    if (!$subject_ids) {
        $no_subjects_assigned = true;
    } else {
        $where = ["s.status = 'Active'", 's.session_id = ?', 's.class_id = ?'];
        $params = [$f_session_id, $f_class_id];
        if ($f_shift_id)   { $where[] = 's.shift_id = ?';   $params[] = $f_shift_id; }
        if ($f_section_id) { $where[] = 's.section_id = ?'; $params[] = $f_section_id; }
        if ($f_group_id)   { $where[] = 's.group_id = ?';   $params[] = $f_group_id; }
        branch_apply_filter($where, $params, 's.branch_id');
        $st = $pdo->prepare(
            "SELECT s.id, s.student_id, s.roll_no, s.name_en
             FROM students s WHERE " . implode(' AND ', $where) . "
             ORDER BY (s.roll_no + 0) ASC, s.roll_no ASC, s.name_en ASC"
        );
        $st->execute($params);
        $students = $st->fetchAll();

        if ($students) {
            $stu_ids = array_column($students, 'id');
            $in_subj = implode(',', array_fill(0, count($subject_ids), '?'));
            $in_stu  = implode(',', array_fill(0, count($stu_ids), '?'));

            $mk_sql = "SELECT student_id, subject_id, part_id, marks_obtained FROM exam_marks WHERE term_id = ? AND subject_id IN ($in_subj) AND student_id IN ($in_stu)";
            $mk_params = array_merge([$f_term_id], $subject_ids, $stu_ids);
            if ($f_part_id) { $mk_sql .= " AND part_id = ?"; $mk_params[] = $f_part_id; }
            $mk = $pdo->prepare($mk_sql); $mk->execute($mk_params);
            $marks_all = [];
            foreach ($mk->fetchAll() as $r) { $marks_all[$r['student_id']][$r['subject_id']][$r['part_id']] = $r['marks_obtained']; }

            $at_sql = "SELECT student_id, subject_id, part_id, status FROM exam_attendance_subject WHERE term_id = ? AND subject_id IN ($in_subj) AND student_id IN ($in_stu)";
            $at_params = array_merge([$f_term_id], $subject_ids, $stu_ids);
            if ($f_part_id) { $at_sql .= " AND part_id = ?"; $at_params[] = $f_part_id; }
            $at = $pdo->prepare($at_sql); $at->execute($at_params);
            $att_all = [];
            foreach ($at->fetchAll() as $r) { $att_all[$r['student_id']][$r['subject_id']][$r['part_id']] = $r['status']; }

            foreach ($subject_ids as $sid) { $subj_stats[$sid] = ['highest' => null, 'pass' => 0, 'fail' => 0]; }

            foreach ($students as $stu) {
                $sid_stu = (int) $stu['id'];
                $res = rc_student_result($defs, $marks_all[$sid_stu] ?? [], $att_all[$sid_stu] ?? [], $grade_bands);

                // rc_student_result সাবজেক্ট-রো $defs এর ক্রমেই দেয় — subject_id দিয়ে ম্যাপ করা হলো
                $by_subj = [];
                $i = 0;
                foreach ($defs as $subj_id => $def) {
                    $sr = $res['subject_rows'][$i++];
                    $absent = false;
                    foreach ((array) ($att_all[$sid_stu][$subj_id] ?? []) as $ast) { if ($ast === 'Absent') { $absent = true; } }
                    $sr['absent'] = $absent;
                    $by_subj[$subj_id] = $sr;

                    if (!$absent) {
                        if ($subj_stats[$subj_id]['highest'] === null || $sr['obtained'] > $subj_stats[$subj_id]['highest']) {
                            $subj_stats[$subj_id]['highest'] = $sr['obtained'];
                        }
                    }
                    if ($sr['fail']) { $subj_stats[$subj_id]['fail']++; } else { $subj_stats[$subj_id]['pass']++; }
                }

                if ($res['overall_fail']) { $total_fail++; } else { $total_pass++; }

                $rows[] = [
                    'id' => $sid_stu, 'roll_no' => $stu['roll_no'], 'name' => $stu['name_en'], 'student_code' => $stu['student_id'],
                    'subjects' => $by_subj, 'total' => $res['obtained_total'], 'full_total' => $res['full_total'],
                    'pct' => $res['percentage'], 'gpa' => $res['overall_gpa'], 'gl' => $res['overall_gl'],
                    'fail' => $res['overall_fail'], 'fail_count' => $res['fail_subject_count'], 'merit' => '-',
                    // প্রতি Subject-এর প্রতিটা Part-এর আলাদা মার্ক/Absent — টেবুলেশন শীটে Part ভাঙা কলাম দেখানোর জন্য
                    'part_marks'  => $marks_all[$sid_stu] ?? [],
                    'part_absent' => array_map(
                        fn($by_part) => array_map(fn($st) => $st === 'Absent', $by_part),
                        $att_all[$sid_stu] ?? []
                    ),
                ];
            }

            // Merit: শুধু Pass করা শিক্ষার্থীদের মধ্যে Total মার্ক অনুযায়ী — সমান Total হলে
            // Admin-এর সাজানো Tie-Breaker ক্রাইটেরিয়া (Result Module > Exam Settings > Merit
            // Tie-Breaker) দিয়ে কাটাকাটি হয়, কিছু সেট করা না থাকলে আগের মতোই যৌথ পজিশন থাকবে।
            if ($show_merit) {
                $passed = array_values(array_filter($rows, fn($r) => !$r['fail']));
                $students_for_merit = [];
                foreach ($passed as $p) {
                    $subj_marks = [];
                    foreach ($p['subjects'] as $sid => $sm) { $subj_marks[$sid] = $sm['obtained']; }
                    $students_for_merit[] = ['id' => $p['id'], 'total' => $p['total'], 'subject_marks' => $subj_marks];
                }
                $merit_map = rc_build_merit_map($pdo, $students_for_merit, $f_session_id, $f_class_id);
                foreach ($rows as &$r) { if (isset($merit_map[$r['id']])) { $r['merit'] = $merit_map[$r['id']]; } }
                unset($r);
            }
        }
    }
}

$total_students = count($rows);
$pass_pct = $total_students > 0 ? round(($total_pass / $total_students) * 100) : 0;
$fail_pct = $total_students > 0 ? round(($total_fail / $total_students) * 100) : 0;
?>
<style>
    @media print {
        @page { size: A4 landscape; margin: 8mm; }
        html, body { background: #fff !important; }
        .no-print, .sidebar, .sticky-top-area, .top-navbar, .breadcrumb-bar, .alert, footer { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; }
        .content-body { padding: 0 !important; margin: 0 !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
        .table-responsive { overflow: visible !important; }
        .ts-table { width: 100% !important; font-size: 9px !important; }
        .ts-table th, .ts-table td { padding: 2px 3px !important; }
        .ts-table thead { display: table-header-group; }
        .ts-table tr { page-break-inside: avoid; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
    .ts-header { text-align: center; margin-bottom: 10px; }
    .ts-header h4 { font-weight: bold; color: #1e3a8a; margin-bottom: 4px; }
    .ts-header .addr, .ts-header .phone { color: #444; font-size: 13px; margin-bottom: 2px; }
    .ts-header .meta1 { font-size: 15px; font-weight: 600; margin: 4px 0 2px; }
    .ts-header .meta2 { font-size: 13px; color: #444; margin-bottom: 6px; }
    .ts-header .stats { font-size: 14px; margin-bottom: 8px; }
    .ts-header .stats .pass { color: #16a34a; font-weight: 600; }
    .ts-header .stats .fail { color: #dc2626; font-weight: 600; }
    .ts-badge { display: inline-block; font-weight: bold; font-size: 14px; border: 1.5px solid #333; border-radius: 3px; padding: 4px 18px; margin-bottom: 10px; }
    .ts-table th, .ts-table td { white-space: nowrap; font-size: 12px; text-align: center; vertical-align: middle; }
    .ts-table td.ts-left, .ts-table th.ts-left { text-align: left; }
    .ts-table .ts-sub-th { white-space: normal; min-width: 62px; }
    .ts-table .ts-mini { display: block; font-size: 10px; color: #64748b; font-weight: 400; }
    .ts-mark-pass { color: #1e40af; font-weight: 600; }
    .ts-mark-fail { color: #dc2626; font-weight: 700; }
    .ts-res-pass { color: #16a34a; font-weight: 700; }
    .ts-res-fail { color: #dc2626; font-weight: 700; }
    .ts-sign { display: flex; justify-content: space-around; margin-top: 60px; font-size: 13px; }
    .ts-sign div { border-top: 1px solid #333; padding-top: 4px; min-width: 150px; text-align: center; }
</style>

<div class="card-custom no-print ts-filter-card">
    <form method="GET" action="index.php" id="tsFilterForm">
        <input type="hidden" name="page" value="result_tabulation_sheet">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-bold">Session <span class="text-danger">*</span></label>
                <select name="session_id" class="form-select">
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session_id === (int) $s['id'] ? 'selected' : ''; ?>><?php echo tsv($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
                <select name="class_id" id="tsClass" class="form-select" required onchange="tsFilterDependents()">
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo tsv($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Term <span class="text-danger">*</span></label>
                <select name="term_id" class="form-select" required>
                    <option value="">Select Term</option>
                    <?php foreach ($terms as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php echo $f_term_id === (int) $t['id'] ? 'selected' : ''; ?>><?php echo tsv($t['term_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Part</label>
                <select name="part_id" class="form-select">
                    <option value="0">All Parts (Combined)</option>
                    <?php foreach ($parts as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php echo $f_part_id === (int) $p['id'] ? 'selected' : ''; ?>><?php echo tsv($p['part_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Shift</label>
                <select name="shift_id" id="tsShift" class="form-select" onchange="tsFilterDependents()">
                    <option value="0">All Shifts</option>
                    <?php foreach ($shifts as $sh): ?>
                        <option value="<?php echo (int) $sh['id']; ?>" <?php echo $f_shift_id === (int) $sh['id'] ? 'selected' : ''; ?>><?php echo tsv($sh['shift_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Section</label>
                <select name="section_id" id="tsSection" class="form-select">
                    <option value="0">All Sections</option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" data-shift="<?php echo (int) $sec['shift_id']; ?>" <?php echo $f_section_id === (int) $sec['id'] ? 'selected' : ''; ?>><?php echo tsv($sec['section_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Group</label>
                <select name="group_id" id="tsGroup" class="form-select">
                    <option value="0">All Groups</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?php echo (int) $g['id']; ?>" data-class="<?php echo (int) $g['class_id']; ?>" <?php echo $f_group_id === (int) $g['id'] ? 'selected' : ''; ?>><?php echo tsv($g['group_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="tsShowMerit" name="show_merit" value="1" <?php echo $show_merit ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold" for="tsShowMerit">Show Merit</label>
                </div>
            </div>
        </div>
        <hr>
        <div class="text-end">
            <button type="submit" name="search_result" value="1" class="btn btn-primary fw-bold" style="background-color:#1e3a8a;"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            <a href="index.php?page=result_tabulation_sheet" class="btn btn-danger fw-bold"><i class="fa-solid fa-xmark"></i> Cancel</a>
        </div>
    </form>
</div>

<?php if (!$terms): ?>
    <div class="alert alert-warning mt-3 no-print">
        এই সেশনের জন্য কোনো <strong>Exam Term</strong> সেট করা নেই। আগে
        <a href="index.php?page=result_exam_terms">Exam Terms</a> পেজ থেকে একটা Term যোগ করুন।
    </div>
<?php endif; ?>

<?php if ($searched): ?>
    <?php if ($no_subjects_assigned): ?>
        <div class="alert alert-warning mt-3 no-print">
            এই Class/Term-এর জন্য কোনো Subject Assign করা নেই। আগে
            <a href="index.php?page=result_assign_exams">Assign Exam</a> পেজ থেকে Subject Assign করুন।
        </div>
    <?php elseif (empty($grade_bands)): ?>
        <div class="alert alert-warning mt-3 no-print">
            এই সেশনের জন্য কোনো <strong>Grade Point</strong> সেট করা নেই, তাই মার্ক যাই হোক না কেন সবাইকে
            <strong>Fail (F)</strong> দেখাবে। আগে
            <a href="index.php?page=result_grade_points">Grade Points</a> পেজ থেকে Grade Point (A+, A, A- ...) সেটআপ করুন।
        </div>
    <?php elseif ($rows): ?>
        <div class="card-custom mt-3">
            <div class="d-flex justify-content-end mb-3 no-print">
                <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
            </div>

            <div class="ts-header">
                <h4><?php echo tsv($inst_display_name); ?></h4>
                <?php if ($addr_line !== ''): ?><p class="addr">(<?php echo tsv($addr_line); ?>)</p><?php endif; ?>
                <?php if ($inst_phone !== ''): ?><p class="phone">Phone: <?php echo tsv($inst_phone); ?></p><?php endif; ?>
                <p class="meta1"><?php echo $group_name !== '' ? tsv($group_name) : 'Combined'; ?> (<?php echo tsv($class_name); ?>)</p>
                <p class="meta2">(Shift : <?php echo $shift_name !== '' ? tsv($shift_name) : 'All'; ?>, Section : <?php echo $section_name !== '' ? tsv($section_name) : 'All'; ?>)</p>
                <p class="stats">
                    Total Student : <?php echo (int) $total_students; ?>,
                    <span class="pass">Total Pass : <?php echo (int) $total_pass; ?> (<?php echo $pass_pct; ?>%)</span>,
                    <span class="fail">Total Fail : <?php echo (int) $total_fail; ?> (<?php echo $fail_pct; ?>%)</span>
                </p>
                <div class="ts-badge">
                    Tabulation Sheet : <?php echo tsv($term_name); ?><?php echo $part_name !== '' ? ' (' . tsv($part_name) . ')' : ''; ?> <?php echo tsv($sheet_session_name); ?>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered align-middle m-0 ts-table">
                    <thead class="table-light">
                        <tr>
                            <th rowspan="2" style="width:40px;">Sl.</th>
                            <th rowspan="2" style="width:55px;">Roll</th>
                            <th rowspan="2" class="ts-left">Name</th>
                            <?php foreach ($defs as $subj_id => $def): ?>
                                <?php
                                    $sub_part_ids = ts_ordered_part_ids($def['parts'], $parts);
                                    $sub_colspan  = count($sub_part_ids) + 3; // + Total + GPA + Grade
                                ?>
                                <th class="ts-sub-th" colspan="<?php echo $sub_colspan; ?>"><?php echo tsv($def['name']); ?></th>
                            <?php endforeach; ?>
                            <th rowspan="2">Total<span class="ts-mini">(<?php echo ts_num($rows[0]['full_total']); ?>)</span></th>
                            <th rowspan="2">%</th>
                            <th rowspan="2">GPA</th>
                            <th rowspan="2">Grade</th>
                            <th rowspan="2">Result</th>
                            <?php if ($show_merit): ?><th rowspan="2">Merit</th><?php endif; ?>
                        </tr>
                        <tr>
                            <?php foreach ($defs as $subj_id => $def): ?>
                                <?php $sub_part_ids = ts_ordered_part_ids($def['parts'], $parts); ?>
                                <?php foreach ($sub_part_ids as $pid): $pinfo = $def['parts'][$pid]; ?>
                                    <th class="text-muted" style="font-weight:400;font-size:10px;">
                                        <?php echo tsv($part_name_map[$pid] ?? ('Part ' . $pid)); ?>
                                        <span class="ts-mini">(<?php echo ts_num($pinfo['full_mark_exam']); ?>)</span>
                                    </th>
                                <?php endforeach; ?>
                                <th class="text-muted" style="font-weight:400;font-size:10px;">Total</th>
                                <th class="text-muted" style="font-weight:400;font-size:10px;">GPA</th>
                                <th class="text-muted" style="font-weight:400;font-size:10px;">Grade</th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $srl = 1; foreach ($rows as $r): ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td><?php echo tsv($r['roll_no']); ?></td>
                                <td class="ts-left"><?php echo tsv($r['name']); ?></td>
                                <?php foreach ($defs as $subj_id => $def): $sm = $r['subjects'][$subj_id]; ?>
                                    <?php $sub_part_ids = ts_ordered_part_ids($def['parts'], $parts); ?>
                                    <?php foreach ($sub_part_ids as $pid): ?>
                                        <?php
                                            $part_mark = $r['part_marks'][$subj_id][$pid] ?? null;
                                            $part_absent = $r['part_absent'][$subj_id][$pid] ?? false;
                                        ?>
                                        <td class="<?php echo $sm['fail'] ? 'ts-mark-fail' : 'ts-mark-pass'; ?>">
                                            <?php echo $part_absent ? 'Ab' : ($part_mark !== null ? ts_num($part_mark) : '-'); ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="fw-bold <?php echo $sm['fail'] ? 'ts-mark-fail' : 'ts-mark-pass'; ?>">
                                        <?php echo $sm['absent'] ? 'Ab' : ts_num($sm['obtained']); ?>
                                    </td>
                                    <td class="<?php echo $sm['fail'] ? 'ts-mark-fail' : 'ts-mark-pass'; ?>"><?php echo $sm['fail'] ? '-' : number_format($sm['gp'], 2); ?></td>
                                    <td class="<?php echo $sm['fail'] ? 'ts-mark-fail' : 'ts-mark-pass'; ?>"><?php echo $sm['fail'] ? 'F' : tsv($sm['gl']); ?></td>
                                <?php endforeach; ?>
                                <td class="fw-bold"><?php echo ts_num($r['total']); ?></td>
                                <td><?php echo number_format($r['pct'], 2); ?></td>
                                <td><?php echo number_format($r['gpa'], 2); ?></td>
                                <td class="<?php echo $r['fail'] ? 'ts-res-fail' : 'ts-res-pass'; ?>"><?php echo $r['fail'] ? 'F' : tsv($r['gl']); ?></td>
                                <td class="<?php echo $r['fail'] ? 'ts-res-fail' : 'ts-res-pass'; ?>">
                                    <?php echo $r['fail'] ? 'Fail' . ($r['fail_count'] > 0 ? ' (' . (int) $r['fail_count'] . ')' : '') : 'Pass'; ?>
                                </td>
                                <?php if ($show_merit): ?><td class="fw-bold"><?php echo tsv($r['merit']); ?></td><?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold">
                            <td colspan="3" class="text-end">Highest</td>
                            <?php foreach ($defs as $subj_id => $def): ?>
                                <?php $sub_colspan = count(ts_ordered_part_ids($def['parts'], $parts)) + 3; ?>
                                <td colspan="<?php echo $sub_colspan; ?>"><?php echo $subj_stats[$subj_id]['highest'] !== null ? ts_num($subj_stats[$subj_id]['highest']) : '-'; ?></td>
                            <?php endforeach; ?>
                            <td colspan="<?php echo $show_merit ? 6 : 5; ?>"></td>
                        </tr>
                        <tr>
                            <td colspan="3" class="text-end fw-bold">Pass</td>
                            <?php foreach ($defs as $subj_id => $def): ?>
                                <?php $sub_colspan = count(ts_ordered_part_ids($def['parts'], $parts)) + 3; ?>
                                <td colspan="<?php echo $sub_colspan; ?>" class="ts-res-pass"><?php echo (int) $subj_stats[$subj_id]['pass']; ?></td>
                            <?php endforeach; ?>
                            <td colspan="<?php echo $show_merit ? 6 : 5; ?>"></td>
                        </tr>
                        <tr>
                            <td colspan="3" class="text-end fw-bold">Fail</td>
                            <?php foreach ($defs as $subj_id => $def): ?>
                                <?php $sub_colspan = count(ts_ordered_part_ids($def['parts'], $parts)) + 3; ?>
                                <td colspan="<?php echo $sub_colspan; ?>" class="ts-res-fail"><?php echo (int) $subj_stats[$subj_id]['fail']; ?></td>
                            <?php endforeach; ?>
                            <td colspan="<?php echo $show_merit ? 6 : 5; ?>"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="ts-sign">
                <div>Class Teacher</div>
                <div>Exam Controller</div>
                <div>Head Master / Principal</div>
            </div>
        </div>
    <?php else: ?>
        <div class="card-custom mt-3">
            <p class="text-muted text-center m-0">নির্বাচিত ফিল্টারে কোনো শিক্ষার্থী পাওয়া যায়নি।</p>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
    // Class/Shift বদলালে Section ও Group এর তালিকা সেই অনুযায়ী ছোট করা
    function tsFilterDependents() {
        const classId = document.getElementById('tsClass').value;
        const shiftId = document.getElementById('tsShift').value;
        const sec = document.getElementById('tsSection');
        Array.from(sec.options).forEach(opt => {
            if (!opt.value || opt.value === '0') { opt.hidden = false; return; }
            const classOk = !classId || opt.dataset.class === classId;
            const shiftOk = !shiftId || shiftId === '0' || !opt.dataset.shift || opt.dataset.shift === '0' || opt.dataset.shift === shiftId;
            opt.hidden = !(classOk && shiftOk);
        });
        if (sec.value && sec.value !== '0' && Array.from(sec.options).find(o => o.value === sec.value)?.hidden) { sec.value = '0'; }

        const grp = document.getElementById('tsGroup');
        Array.from(grp.options).forEach(opt => {
            if (!opt.value || opt.value === '0') { opt.hidden = false; return; }
            opt.hidden = !!classId && opt.dataset.class !== classId;
        });
        if (grp.value && grp.value !== '0' && Array.from(grp.options).find(o => o.value === grp.value)?.hidden) { grp.value = '0'; }
    }
    tsFilterDependents();
</script>
