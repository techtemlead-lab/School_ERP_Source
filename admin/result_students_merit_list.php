<?php
// admin/result_students_merit_list.php - Result Module > Result Reports > Students Merit List
// Session/Class/Group/Shift/Section/Term/Part ফিল্টার দিয়ে Search Result করলে ঐ কম্বিনেশনের
// স্টুডেন্টদের সব Subject-এর মার্ক (exam_marks), Total, GP, GL এবং Merit Position একসাথে দেখায়।
// Grade Point/Letter আসে exam_grade_points থেকে (percentage অনুযায়ী), Pass/Fail নির্ধারণ হয়
// exam_assign_subjects এর pass_mark_exam/pass_individual/fail_on_absent সেটিংস দিয়ে।

require_once __DIR__ . '/../config/sms.php';
require_once __DIR__ . '/../config/report_card_helpers.php';
sms_ensure_schema($pdo);

// SMS পাঠানোর লগ রাখার টেবিল (অন্য পেজে আগে ভিজিট না করা থাকলেও যেন এখানে কাজ করে)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS sms_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NULL,
        phone VARCHAR(20) NOT NULL,
        purpose VARCHAR(50) NOT NULL DEFAULT 'absent',
        message TEXT NULL,
        status ENUM('Sent','Failed') NOT NULL DEFAULT 'Failed',
        response TEXT NULL,
        sent_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// exam_attendance_subject টেবিল (Result Module > Attendance (Subject) পেজ থেকে তৈরি হয়) এখনো
// তৈরি না হয়ে থাকলে নিচের SELECT একই "Base table or view not found" ফ্যাটাল এরর দিয়ে এই
// পেজ (Students Merit List) ক্র্যাশ করাত — result_students_report_card.php এ পাওয়া একই বাগ,
// এখানেও একই ফিক্স (নিরাপদে auto-create) প্রয়োগ করা হলো।
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

function mlv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$ml_err = $_SESSION['ml_err'] ?? '';
$ml_msg = $_SESSION['ml_msg'] ?? '';
unset($_SESSION['ml_err'], $_SESSION['ml_msg']);

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

$institute = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
$fr_bid2 = branch_current_id();
if ($fr_bid2 !== null) {
    $branch_stmt2 = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $branch_stmt2->execute([$fr_bid2]);
    $branch = $branch_stmt2->fetch();
} else {
    $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
}
$addr_line = $branch ? ($branch['address'] . ', ' . $branch['city'] . '-' . $branch['zip_code']) : '';
$inst_display_name = ($branch['branch_name'] ?? null) ?: ($institute['institute_name'] ?? '');
$inst_phone = $branch['phone'] ?? ($institute['phone_number'] ?? '');

// -----------------------------------------------------------
// Send SMS (All checked) হ্যান্ডলার
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_result_sms'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ml_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $ids = array_filter(array_map('intval', (array) ($_POST['student_ids'] ?? [])));
        if (!$ids) {
            $_SESSION['ml_err'] = 'কোনো শিক্ষার্থী সিলেক্ট করা হয়নি।';
        } else {
            $school = $inst_display_name;
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, name_en, guardian_phone FROM students WHERE id IN ($in)");
            $stmt->execute($ids);
            $target_students = $stmt->fetchAll();

            $log_stmt = $pdo->prepare(
                "INSERT INTO sms_logs (student_id, phone, purpose, message, status, response, sent_by) VALUES (?, ?, 'result', ?, ?, ?, ?)"
            );
            $sent_by = $_SESSION['admin_user'] ?? 'admin';
            $sent = 0; $failed = 0;
            $result_map = json_decode($_POST['result_map'] ?? '[]', true) ?: [];

            foreach ($target_students as $stu) {
                if (empty($stu['guardian_phone'])) { $failed++; continue; }
                $r = $result_map[$stu['id']] ?? null;
                $summary = $r ? ('Total: ' . $r['total'] . ', GPA: ' . $r['gp'] . ', Grade: ' . $r['gl'] . ($r['merit'] !== '-' ? ', Merit: ' . $r['merit'] : '')) : 'Result published.';
                // SMS Settings > SMS Template Settings > "Exam Result SMS" টেমপ্লেট থেকে
                $message = sms_template_render($pdo, 'result', [
                    'NAME' => $stu['name_en'], 'SCHOOL_NAME' => $school, 'RESULT_SUMMARY' => $summary,
                    'TOTAL' => $r['total'] ?? '', 'GPA' => $r['gp'] ?? '', 'GRADE' => $r['gl'] ?? '', 'MERIT' => ($r && $r['merit'] !== '-') ? $r['merit'] : '',
                ]);
                $result_data = send_sms($pdo, $stu['guardian_phone'], $message);
                $log_stmt->execute([$stu['id'], $stu['guardian_phone'], $message, $result_data['success'] ? 'Sent' : 'Failed', $result_data['response'], $sent_by]);
                $result_data['success'] ? $sent++ : $failed++;
            }

            if ($sent > 0) {
                sec_log_activity($pdo, 'Result', 'Create', "{$sent} জন অভিভাবককে Merit List থেকে Result SMS পাঠানো হয়েছে");
                $_SESSION['ml_msg'] = "{$sent} জন অভিভাবককে রেজাল্ট SMS পাঠানো হয়েছে।" . ($failed ? " {$failed} জনকে পাঠানো যায়নি।" : '');
            } else {
                $_SESSION['ml_err'] = "কোনো SMS পাঠানো যায়নি। ({$failed} জন ফেইল) — SMS গেটওয়ে কনফিগার করা আছে কিনা চেক করুন।";
            }
        }
    }
    header('Location: index.php?page=result_students_merit_list' . (isset($_GET['class_id']) ? '&' . http_build_query($_GET) : ''));
    exit();
}

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name, has_group FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
$groups   = $pdo->query("SELECT id, group_name, class_id FROM academic_groups WHERE status = 'Active' ORDER BY group_name ASC")->fetchAll();
$subjects_all = $pdo->query("SELECT id, subject_name, class_id FROM academic_subjects WHERE status = 'Active' ORDER BY sort_order ASC, id ASC")->fetchAll();
$terms    = $current_session_id
    ? $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC")
    : null;
if ($terms) { $terms->execute([$current_session_id]); $terms = $terms->fetchAll(); } else { $terms = []; }
$parts = $current_session_id
    ? $pdo->prepare("SELECT id, part_name FROM exam_parts WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC")
    : null;
if ($parts) { $parts->execute([$current_session_id]); $parts = $parts->fetchAll(); } else { $parts = []; }

// -----------------------------------------------------------
// GET ফিল্টার — Search Result চাপলে result রিপোর্ট দেখানো হবে
// -----------------------------------------------------------
$f_session_id = (int) ($_GET['session_id'] ?? $current_session_id);
$f_class_id   = (int) ($_GET['class_id'] ?? 0);
$f_group_id   = (int) ($_GET['group_id'] ?? 0);
$f_shift_id   = (int) ($_GET['shift_id'] ?? 0);
$f_section_id = (int) ($_GET['section_id'] ?? 0);
$f_term_id    = (int) ($_GET['term_id'] ?? 0);
$f_part_id    = (int) ($_GET['part_id'] ?? 0);
$f_subject_id = (int) ($_GET['subject_id'] ?? 0);
$show_merit   = isset($_GET['search_result']) ? isset($_GET['show_merit']) : true;
$searched     = isset($_GET['search_result']);

$class_name = $shift_name = $section_name = $group_name = $term_name = $part_name = $sheet_session_name = '';
$students_report = [];
$subject_cols = [];
$total_pass = 0;
$total_fail = 0;
$grade_bands = [];

if ($searched && $f_class_id && $f_shift_id && $f_section_id && $f_term_id) {
    // লেবেলের জন্য নাম লুকআপ
    $cs = $pdo->prepare("SELECT class_name FROM academic_classes WHERE id = ?"); $cs->execute([$f_class_id]); $class_name = $cs->fetchColumn() ?: '-';
    $ss = $pdo->prepare("SELECT shift_name FROM academic_shifts WHERE id = ?"); $ss->execute([$f_shift_id]); $shift_name = $ss->fetchColumn() ?: '-';
    $secs = $pdo->prepare("SELECT section_name FROM academic_sections WHERE id = ?"); $secs->execute([$f_section_id]); $section_name = $secs->fetchColumn() ?: '-';
    if ($f_group_id) { $gs = $pdo->prepare("SELECT group_name FROM academic_groups WHERE id = ?"); $gs->execute([$f_group_id]); $group_name = $gs->fetchColumn() ?: ''; }
    $ts = $pdo->prepare("SELECT term_name FROM exam_terms WHERE id = ?"); $ts->execute([$f_term_id]); $term_name = $ts->fetchColumn() ?: '';
    if ($f_part_id) { $ps = $pdo->prepare("SELECT part_name FROM exam_parts WHERE id = ?"); $ps->execute([$f_part_id]); $part_name = $ps->fetchColumn() ?: ''; }
    $sess_s = $pdo->prepare("SELECT session_name FROM academic_sessions WHERE id = ?"); $sess_s->execute([$f_session_id]); $sheet_session_name = $sess_s->fetchColumn() ?: date('Y');

    // ঐ Class/Term-এ Assign করা Subject লিস্ট — Tabulation Sheet/Report Card-এর মতোই shared
    // rc_get_subject_defs() ফাংশন দিয়ে (part_id=0 হলে Combined mode: Term-এর সব Part একসাথে যোগ হয়)।
    // আগে এখানে সরাসরি eas.part_id = ? দিয়ে কুয়েরি হতো, যেটা Part সিলেক্ট না করলে (part_id=0) কোনো
    // রো-ই ম্যাচ করত না এবং "কোনো Subject Assign করা নেই" ভুল এরর দেখাত — এই ফিক্সে সেটা ঠিক হলো।
    $defs = rc_get_subject_defs($pdo, $f_class_id, $f_term_id, $f_part_id, $f_group_id);
    $subject_cols = [];
    foreach ($defs as $subj_id => $def) {
        $sfull = 0.0;
        foreach ($def['parts'] as $pinfo) { $sfull += (float) $pinfo['full_mark_exam']; }
        $subject_cols[] = ['subject_id' => $subj_id, 'subject_name' => $def['name'], 'full_mark_exam' => $sfull];
    }

    // Grade Points ব্যান্ড (percentage অনুযায়ী), উঁচু mark_from আগে যাতে সঠিক ব্যান্ডে ম্যাচ হয়
    $grade_stmt = $pdo->prepare("SELECT grade_letter, gpa, mark_from, mark_to FROM exam_grade_points WHERE session_id = ? ORDER BY mark_from DESC");
    $grade_stmt->execute([$f_session_id]);
    $grade_bands = $grade_stmt->fetchAll();

    if ($defs) {
        $where = ["students.status = 'Active'", 'students.session_id = ?', 'students.class_id = ?', 'students.shift_id = ?', 'students.section_id = ?'];
        $params = [$f_session_id, $f_class_id, $f_shift_id, $f_section_id];
        if ($f_group_id) { $where[] = 'students.group_id = ?'; $params[] = $f_group_id; }
        branch_apply_filter($where, $params, 'students.branch_id');

        $stu_sql = "SELECT students.id, students.student_id, students.roll_no, students.name_en, students.guardian_phone
                     FROM students
                     WHERE " . implode(' AND ', $where) . "
                     ORDER BY (students.roll_no + 0) ASC, students.roll_no ASC";
        $stu_stmt = $pdo->prepare($stu_sql);
        $stu_stmt->execute($params);
        $raw_students = $stu_stmt->fetchAll();

        $subject_ids = array_column($subject_cols, 'subject_id');
        $marks_all = [];
        $att_all = [];
        if ($subject_ids && $raw_students) {
            $stu_ids = array_column($raw_students, 'id');
            $in_subj = implode(',', array_fill(0, count($subject_ids), '?'));
            $in_stu  = implode(',', array_fill(0, count($stu_ids), '?'));

            // Combined mode-এ (part_id ফিল্টার না থাকলে) Term-এর সব Part-এর মার্ক আনা হচ্ছে,
            // rc_student_result() নিজেই সব Part যোগ করে সাবজেক্টের Total বের করবে
            $mk_sql = "SELECT student_id, subject_id, part_id, marks_obtained FROM exam_marks WHERE term_id = ? AND subject_id IN ($in_subj) AND student_id IN ($in_stu)";
            $mk_params = array_merge([$f_term_id], $subject_ids, $stu_ids);
            if ($f_part_id) { $mk_sql .= " AND part_id = ?"; $mk_params[] = $f_part_id; }
            $mk = $pdo->prepare($mk_sql); $mk->execute($mk_params);
            foreach ($mk->fetchAll() as $row) { $marks_all[$row['student_id']][$row['subject_id']][$row['part_id']] = $row['marks_obtained']; }

            $at_sql = "SELECT student_id, subject_id, part_id, status FROM exam_attendance_subject WHERE term_id = ? AND subject_id IN ($in_subj) AND student_id IN ($in_stu)";
            $at_params = array_merge([$f_term_id], $subject_ids, $stu_ids);
            if ($f_part_id) { $at_sql .= " AND part_id = ?"; $at_params[] = $f_part_id; }
            $at = $pdo->prepare($at_sql); $at->execute($at_params);
            foreach ($at->fetchAll() as $row) { $att_all[$row['student_id']][$row['subject_id']][$row['part_id']] = $row['status']; }

            // Monthly + Term + Formative ভাগ (Exam Terms-এ ওয়েট দেওয়া থাকলে) — Report Card-এর সাথে একই হিসাব
            $rc_comp = ($f_part_id === 0) ? rc_term_composition($pdo, (int) $f_term_id, (int) $f_class_id) : null;
            if ($rc_comp) {
                [$defs, $marks_all, $att_all] = rc_compose_term($pdo, $rc_comp, (int) $f_class_id, $defs, $marks_all, $att_all, array_map('intval', $stu_ids));
                foreach ($subject_cols as $sc_k => $sc_c) {
                    $sc_f = 0.0;
                    foreach (($defs[$sc_c['subject_id']]['parts'] ?? []) as $sc_p) { $sc_f += (float) $sc_p['full_mark_exam']; }
                    $subject_cols[$sc_k]['full_mark_exam'] = $sc_f;
                }
            }
        }

        foreach ($raw_students as $stu) {
            $sid = $stu['id'];
            // rc_student_result() — Report Card/Tabulation Sheet-এর সাথে হুবহু একই শেয়ার্ড হিসাব
            $res = rc_student_result($defs, $marks_all[$sid] ?? [], $att_all[$sid] ?? [], $grade_bands, rc_excluded_subjects($pdo, $defs, (int) $f_term_id, (int) $sid));

            $subject_marks = [];
            $sr_map = array_column($res['subject_rows'], null, 'subject_id');
            foreach ($defs as $subj_id => $def) {
                $sr = $sr_map[$subj_id] ?? null;
                if ($sr === null) { $subject_marks[$subj_id] = ['mark' => null, 'absent' => false, 'fail' => false]; continue; } // Group-এর নয় / Not Eligible
                $absent = false;
                foreach ((array) ($att_all[$sid][$subj_id] ?? []) as $ast) { if ($ast === 'Absent') { $absent = true; } }
                $subject_marks[$subj_id] = ['mark' => $absent ? null : $sr['obtained'], 'absent' => $absent, 'fail' => $sr['fail']];
            }

            $is_fail = $res['overall_fail'];
            if ($is_fail) { $total_fail++; } else { $total_pass++; }

            $students_report[] = [
                'id' => $sid, 'student_id' => $stu['student_id'], 'roll_no' => $stu['roll_no'],
                'name_en' => $stu['name_en'], 'guardian_phone' => $stu['guardian_phone'],
                'subject_marks' => $subject_marks, 'total' => $res['obtained_total'], 'gp' => $res['overall_gpa'], 'gl' => $res['overall_gl'],
                'is_fail' => $is_fail, 'merit' => '-',
            ];
        }

        // Merit position: শুধু Pass করা স্টুডেন্টদের মধ্যে Total মার্ক অনুযায়ী — সমান Total হলে
        // Admin-এর সাজানো Tie-Breaker ক্রাইটেরিয়া (Result Module > Exam Settings > Merit
        // Tie-Breaker) দিয়ে কাটাকাটি হয়, Tabulation Sheet-এর সাথে হুবহু একই শেয়ার্ড লজিক।
        if ($show_merit) {
            $passed = array_filter($students_report, fn($r) => !$r['is_fail']);
            $students_for_merit = [];
            foreach ($passed as $p) {
                $subj_marks = [];
                foreach ($p['subject_marks'] as $sid => $sm) { $subj_marks[$sid] = $sm['mark'] ?? 0; }
                $students_for_merit[] = ['id' => $p['id'], 'total' => $p['total'], 'subject_marks' => $subj_marks];
            }
            $merit_map = rc_build_merit_map($pdo, $students_for_merit, $f_session_id, $f_class_id);
            foreach ($students_report as &$r) { if (isset($merit_map[$r['id']])) { $r['merit'] = $merit_map[$r['id']]; } }
            unset($r);
        }
    }
}

$total_students = count($students_report);
$total_pass_pct = $total_students > 0 ? round(($total_pass / $total_students) * 100) : 0;
$total_fail_pct = $total_students > 0 ? round(($total_fail / $total_students) * 100) : 0;
$result_map_json = mlv(json_encode(array_reduce($students_report, function ($carry, $r) {
    $carry[$r['id']] = ['total' => $r['total'], 'gp' => number_format($r['gp'], 2), 'gl' => $r['gl'], 'merit' => $r['merit']];
    return $carry;
}, [])));
?>
<?php require_once __DIR__ . '/employee_attendance_common.php'; ea_print_css('landscape'); ?>
    <style>
    
    .mrl-header { text-align: center; margin-bottom: 10px; }
    .mrl-header h4 { font-weight: bold; color: #1e3a8a; margin-bottom: 4px; }
    .mrl-header .addr { color: #444; font-size: 13px; margin-bottom: 2px; }
    .mrl-header .phone { font-size: 13px; margin-bottom: 2px; }
    .mrl-header .web { color: #2563eb; font-size: 13px; margin-bottom: 8px; }
    .mrl-header .meta1 { font-size: 15px; font-weight: 600; margin-bottom: 2px; }
    .mrl-header .meta2 { font-size: 13px; color: #444; margin-bottom: 8px; }
    .mrl-header .stats { font-size: 14px; margin-bottom: 10px; }
    .mrl-header .stats .pass { color: #16a34a; font-weight: 600; }
    .mrl-header .stats .fail { color: #dc2626; font-weight: 600; }
    .mrl-badge {
        display: inline-block; font-weight: bold; font-size: 14px; border: 1.5px solid #333;
        border-radius: 3px; padding: 5px 20px; margin-bottom: 14px;
    }
    .mrl-vert-th { writing-mode: vertical-rl; transform: rotate(180deg); white-space: nowrap; padding: 8px 4px !important; }
    .mrl-mark-pass { color: #1e40af; font-weight: 600; }
    .mrl-mark-fail { color: #dc2626; font-weight: 600; }
    .mrl-gl-fail { color: #dc2626; font-weight: 700; }
    .mrl-gl-pass { color: #16a34a; font-weight: 700; }
</style>

<div class="card-custom no-print">
    <?php if ($ml_err): ?><div class="alert alert-danger py-2"><?php echo mlv($ml_err); ?></div><?php endif; ?>
    <?php if ($ml_msg): ?><div class="alert alert-success py-2"><?php echo mlv($ml_msg); ?></div><?php endif; ?>

    <form method="GET" action="index.php" id="mrlFilterForm">
        <input type="hidden" name="page" value="result_students_merit_list">
        <div class="row">
            <div class="col-md-3">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-5 col-form-label fw-bold text-end">Session <span class="text-danger">*</span></label>
                    <div class="col-sm-7">
                        <select name="session_id" class="form-select">
                            <?php foreach ($sessions as $s): ?>
                                <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session_id === (int) $s['id'] ? 'selected' : ''; ?>><?php echo mlv($s['session_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-5 col-form-label fw-bold text-end">Section <span class="text-danger">*</span></label>
                    <div class="col-sm-7">
                        <select name="section_id" id="mrlSectionSelect" class="form-select" required disabled>
                            <option value="">Select Class first</option>
                            <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" data-shift="<?php echo (int) $sec['shift_id']; ?>" <?php echo $f_section_id === (int) $sec['id'] ? 'selected' : ''; ?> hidden><?php echo mlv($sec['section_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-5 col-form-label fw-bold text-end">Class <span class="text-danger">*</span></label>
                    <div class="col-sm-7">
                        <select name="class_id" id="mrlClassSelect" class="form-select" required onchange="filterMrlDependents()">
                            <option value="">Select</option>
                            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo mlv($c['class_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-5 col-form-label fw-bold text-end">Term <span class="text-danger">*</span></label>
                    <div class="col-sm-7">
                        <select name="term_id" class="form-select" required>
                            <option value="">Select</option>
                            <?php foreach ($terms as $t): ?><option value="<?php echo (int) $t['id']; ?>" <?php echo $f_term_id === (int) $t['id'] ? 'selected' : ''; ?>><?php echo mlv($t['term_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-5 col-form-label fw-bold text-end">Group <span class="text-danger">*</span></label>
                    <div class="col-sm-7">
                        <select name="group_id" id="mrlGroupSelect" class="form-select">
                            <option value="0">All Group</option>
                            <?php foreach ($groups as $g): ?><option value="<?php echo (int) $g['id']; ?>" data-class="<?php echo (int) $g['class_id']; ?>" <?php echo $f_group_id === (int) $g['id'] ? 'selected' : ''; ?>><?php echo mlv($g['group_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-5 col-form-label fw-bold text-end">Part</label>
                    <div class="col-sm-7">
                        <select name="part_id" class="form-select">
                            <option value="">Select Exam Part</option>
                            <?php foreach ($parts as $p): ?><option value="<?php echo (int) $p['id']; ?>" <?php echo $f_part_id === (int) $p['id'] ? 'selected' : ''; ?>><?php echo mlv($p['part_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-5 col-form-label fw-bold text-end">Shift <span class="text-danger">*</span></label>
                    <div class="col-sm-7">
                        <select name="shift_id" id="mrlShiftSelect" class="form-select" required onchange="filterMrlDependents()">
                            <option value="">Select</option>
                            <?php foreach ($shifts as $sh): ?><option value="<?php echo (int) $sh['id']; ?>" <?php echo $f_shift_id === (int) $sh['id'] ? 'selected' : ''; ?>><?php echo mlv($sh['shift_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-5 col-form-label fw-bold text-end">Subject</label>
                    <div class="col-sm-7">
                        <select name="subject_id" id="mrlSubjectSelect" class="form-select">
                            <option value="">Select Subject</option>
                            <?php foreach ($subjects_all as $sub): ?><option value="<?php echo (int) $sub['id']; ?>" data-class="<?php echo (int) $sub['class_id']; ?>" <?php echo $f_subject_id === (int) $sub['id'] ? 'selected' : ''; ?>><?php echo mlv($sub['subject_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <hr>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="mrlShowMerit" name="show_merit" value="1" <?php echo $show_merit ? 'checked' : ''; ?>>
                <label class="form-check-label fw-bold" for="mrlShowMerit">Show Merit</label>
            </div>
            <div>
                <button type="submit" name="search_result" value="1" class="btn btn-primary fw-bold" style="background-color:#1e3a8a;"><i class="fa-solid fa-magnifying-glass"></i> Search Result</button>
                <a href="index.php?page=result_students_merit_list" class="btn btn-danger fw-bold"><i class="fa-solid fa-xmark"></i> Cancel</a>
            </div>
        </div>
    </form>
</div>

<?php if (!$terms): ?>
    <div class="alert alert-warning mt-3 no-print">
        এই সেশনের জন্য কোনো <strong>Exam Term</strong> সেট করা নেই। রেজাল্ট দেখার আগে
        <a href="index.php?page=result_exam_terms">Exam Terms</a> পেজ থেকে একটা Term যোগ করুন।
    </div>
<?php endif; ?>

<?php if ($searched): ?>
    <?php if (!$subject_cols && $f_class_id && $f_term_id): ?>
        <div class="alert alert-warning mt-3 no-print">
            এই Class/Term/Part-এর জন্য কোনো Subject Assign করা নেই। আগে
            <a href="index.php?page=result_assign_exams">Assign Exam</a> পেজ থেকে Subject Assign করুন।
        </div>
    <?php elseif (empty($grade_bands)): ?>
        <div class="alert alert-warning mt-3 no-print">
            এই সেশনের জন্য কোনো <strong>Grade Point</strong> সেট করা নেই, তাই মার্ক যাই হোক না কেন সবাইকে
            <strong>Fail (F)</strong> দেখাবে। আগে
            <a href="index.php?page=result_grade_points">Grade Points</a> পেজ থেকে Grade Point (A+, A, A- ...) সেটআপ করুন।
        </div>
    <?php elseif ($students_report): ?>
        <form method="POST" action="index.php?page=result_students_merit_list" id="mrlSmsForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="result_map" value='<?php echo $result_map_json; ?>'>

            <div class="no-print d-flex justify-content-between align-items-center mb-3 mt-3">
                <span></span>
                <div class="d-flex gap-2">
                    <button type="submit" name="send_result_sms" class="btn btn-primary fw-bold" style="background-color:#1e3a8a;" onclick="return confirm('সিলেক্ট করা অভিভাবকদের রেজাল্ট SMS পাঠানো হবে, নিশ্চিত?');"><i class="fa-solid fa-paper-plane"></i> Send SMS (All)</button>
                    <?php ea_print_controls('landscape', false); ?>
                </div>
            </div>

            <div class="card-custom">
                <div class="mrl-header">
                    <h4><?php echo mlv($inst_display_name); ?></h4>
                    <?php if ($addr_line !== ''): ?><p class="addr">(<?php echo mlv($addr_line); ?>)</p><?php endif; ?>
                    <?php if ($inst_phone !== ''): ?><p class="phone">Phone: <?php echo mlv($inst_phone); ?></p><?php endif; ?>
                    <?php if (!empty($institute['website'])): ?><p class="web"><?php echo htmlspecialchars((string) $institute['website']); ?></p><?php endif; ?>
                    <p class="meta1"><?php echo $group_name !== '' ? mlv($group_name) : 'Combined'; ?> (<?php echo mlv($class_name); ?>)</p>
                    <p class="meta2">(Shift : <?php echo mlv($shift_name); ?>, Section : <?php echo mlv($section_name); ?>)</p>
                    <p class="stats">
                        Total Student : <?php echo (int) $total_students; ?>,
                        <span class="pass">Total Pass : <?php echo (int) $total_pass; ?> (<?php echo $total_pass_pct; ?>%)</span>,
                        <span class="fail">Total Fail : <?php echo (int) $total_fail; ?> (<?php echo $total_fail_pct; ?>%)</span>
                    </p>
                    <div class="mrl-badge">
                        Merit List : <?php echo mlv($term_name); ?><?php echo $part_name !== '' ? ' (' . mlv($part_name) . ')' : ''; ?> <?php echo mlv($sheet_session_name); ?>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle m-0 text-center">
                        <thead class="table-light">
                            <tr>
                                <th class="no-print" style="width:36px;"><input type="checkbox" onclick="document.querySelectorAll('.mrl-stu-chk').forEach(c=>c.checked=this.checked)"></th>
                                <th style="text-align:left;">Name</th>
                                <th style="width:70px;">Roll</th>
                                <?php if ($subject_cols): ?>
                                    <th colspan="<?php echo count($subject_cols); ?>">Subjects</th>
                                <?php endif; ?>
                                <th style="width:80px;">Total</th>
                                <th style="width:60px;">GP</th>
                                <th style="width:60px;">GL</th>
                                <?php if ($show_merit): ?><th style="width:70px;">Merit</th><?php endif; ?>
                            </tr>
                            <tr>
                                <th class="no-print"></th>
                                <th></th>
                                <th></th>
                                <?php foreach ($subject_cols as $sc): ?>
                                    <th class="mrl-vert-th"><?php echo mlv($sc['subject_name']); ?></th>
                                <?php endforeach; ?>
                                <th></th><th></th><th></th>
                                <?php if ($show_merit): ?><th></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students_report as $r): ?>
                                <tr>
                                    <td class="no-print"><input type="checkbox" class="mrl-stu-chk" name="student_ids[]" value="<?php echo (int) $r['id']; ?>"></td>
                                    <td style="text-align:left;"><?php echo mlv($r['name_en']); ?></td>
                                    <td><?php echo mlv($r['roll_no']); ?></td>
                                    <?php foreach ($subject_cols as $sc): ?>
                                        <?php $sm = $r['subject_marks'][$sc['subject_id']] ?? ['mark' => null, 'absent' => false, 'fail' => true]; ?>
                                        <td class="<?php echo $sm['fail'] ? 'mrl-mark-fail' : 'mrl-mark-pass'; ?>">
                                            <?php echo $sm['absent'] ? 'Ab' : number_format((float) ($sm['mark'] ?? 0), 2); ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="fw-bold"><?php echo number_format($r['total'], 2); ?></td>
                                    <td><?php echo number_format($r['gp'], 2); ?></td>
                                    <td class="<?php echo $r['is_fail'] ? 'mrl-gl-fail' : 'mrl-gl-pass'; ?>">
                                        <?php echo $r['is_fail'] ? '(F)' : mlv($r['gl']); ?>
                                    </td>
                                    <?php if ($show_merit): ?><td class="fw-bold"><?php echo mlv($r['merit']); ?></td><?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>
    <?php else: ?>
        <p class="text-muted text-center mt-3 no-print">নির্বাচিত ফিল্টারে কোনো শিক্ষার্থী পাওয়া যায়নি।</p>
    <?php endif; ?>
<?php endif; ?>

<script>
    function filterMrlDependents() {
        const classId = document.getElementById('mrlClassSelect').value;
        const shiftId = document.getElementById('mrlShiftSelect').value;

        const secSelect = document.getElementById('mrlSectionSelect');
        secSelect.disabled = !classId;
        Array.from(secSelect.options).forEach(opt => {
            if (!opt.value) { opt.hidden = false; return; }
            const classOk = !!classId && opt.dataset.class === classId;
            const shiftOk = !shiftId || !opt.dataset.shift || opt.dataset.shift === '0' || opt.dataset.shift === shiftId;
            opt.hidden = !(classOk && shiftOk);
        });
        secSelect.options[0].textContent = classId ? 'Select' : 'Select Class first';
        if (secSelect.value && Array.from(secSelect.options).find(o => o.value === secSelect.value)?.hidden) {
            secSelect.value = '';
        }

        const grpSelect = document.getElementById('mrlGroupSelect');
        Array.from(grpSelect.options).forEach(opt => {
            if (!opt.value || opt.value === '0') { opt.hidden = false; return; }
            opt.hidden = !!classId && opt.dataset.class !== classId;
        });
        if (grpSelect.value && Array.from(grpSelect.options).find(o => o.value === grpSelect.value)?.hidden) {
            grpSelect.value = '0';
        }

        const subSelect = document.getElementById('mrlSubjectSelect');
        Array.from(subSelect.options).forEach(opt => {
            if (!opt.value) { opt.hidden = false; return; }
            opt.hidden = !!classId && opt.dataset.class !== classId;
        });
        if (subSelect.value && Array.from(subSelect.options).find(o => o.value === subSelect.value)?.hidden) {
            subSelect.value = '';
        }
    }
    filterMrlDependents();
</script>
