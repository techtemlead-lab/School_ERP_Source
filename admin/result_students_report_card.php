<?php
// admin/result_students_report_card.php - Result Module > Result Reports > Student Report Card
// Session/Class/Group/Shift/Section/Term/Part/Student ফিল্টার দিয়ে Search Result করলে ঐ
// স্টুডেন্টের একটা প্রিন্টযোগ্য Academic Transcript (ছবি+QR+Grade Scale+Subject-wise GP/GPA) দেখায়।
// GP/GL হিসাব হয় result_students_merit_list.php-এর মতোই: exam_assign_subjects (pass mark/pass
// individual/fail on absent) + exam_grade_points (percentage ব্যান্ড) দিয়ে।
// Result Settings > Master Setting-এর "Progress Report Settings (On/Off)" টগলগুলো এখানে
// প্রয়োগ হয় — exam_result_settings টেবিল থেকে রিড করে শো/হাইড করা হয়।

function rcv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

require_once __DIR__ . '/../config/report_card_helpers.php';

// exam_result_settings টেবিল এখনো তৈরি না হয়ে থাকতে পারে (Result Module > Exam Settings >
// Master Setting পেজ কখনো ভিজিট না করা হলে) — অন্য কোনো Result পেজ থেকে সরাসরি এলেও যেন
// এরর না হয়, তাই এখানেও নিরাপদে auto-create করা হচ্ছে (result_settings.php এর সাথে একই স্ট্রাকচার)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_result_settings (
        id INT PRIMARY KEY DEFAULT 1,
        show_medium_name TINYINT(1) NOT NULL DEFAULT 1,
        show_student_roll_no TINYINT(1) NOT NULL DEFAULT 1,
        show_student_picture_qr TINYINT(1) NOT NULL DEFAULT 0,
        add_watermark_background TINYINT(1) NOT NULL DEFAULT 1,
        show_banner_instead_logo TINYINT(1) NOT NULL DEFAULT 0,
        pass_fail_summary_merit_list TINYINT(1) NOT NULL DEFAULT 1,
        merit_list_with_subjects TINYINT(1) NOT NULL DEFAULT 1,
        show_final_grade_letter_point TINYINT(1) NOT NULL DEFAULT 1,
        show_subject_grade_letter TINYINT(1) NOT NULL DEFAULT 1,
        show_subject_grade_point TINYINT(1) NOT NULL DEFAULT 1,
        show_result_remarks TINYINT(1) NOT NULL DEFAULT 1,
        show_working_days_attend_absent TINYINT(1) NOT NULL DEFAULT 1,
        show_absent_count TINYINT(1) NOT NULL DEFAULT 1,
        write_manual_attendance_count TINYINT(1) NOT NULL DEFAULT 1,
        show_discipline_cleanliness TINYINT(1) NOT NULL DEFAULT 1,
        show_interpretation TINYINT(1) NOT NULL DEFAULT 1,
        show_total_subject_fail_count TINYINT(1) NOT NULL DEFAULT 0,
        show_part_marks_calculation TINYINT(1) NOT NULL DEFAULT 1,
        calculate_merit_position_by_marks TINYINT(1) NOT NULL DEFAULT 0,
        aggregate_result_type VARCHAR(50) NOT NULL DEFAULT 'Terms Wise Percentage',
        report_card_template VARCHAR(30) NOT NULL DEFAULT 'Govt. / MPO',
        left_signature VARCHAR(100) NOT NULL DEFAULT 'Signature of Teacher',
        middle_signature VARCHAR(100) NOT NULL DEFAULT 'Signature of Coordinator',
        right_signature VARCHAR(100) NOT NULL DEFAULT 'Signature of Headmaster',
        body_font_size INT NOT NULL DEFAULT 12,
        body_font_color VARCHAR(10) NOT NULL DEFAULT '#333333',
        institute_name_color VARCHAR(10) NOT NULL DEFAULT '#000080',
        table_header_color VARCHAR(10) NOT NULL DEFAULT '#333333',
        fail_letter_grade_color VARCHAR(10) NOT NULL DEFAULT '#ff0000',
        body_font_style VARCHAR(20) NOT NULL DEFAULT 'Normal',
        gpa_color VARCHAR(10) NOT NULL DEFAULT '#00B1E1',
        remarks_progress_color VARCHAR(10) NOT NULL DEFAULT '#00B1E1',
        table_footer_color VARCHAR(10) NOT NULL DEFAULT '#00B1E1',
        fail_part_marks_color VARCHAR(10) NOT NULL DEFAULT '#ff0000',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec("INSERT IGNORE INTO exam_result_settings (id) VALUES (1)");

// exam_attendance_subject টেবিল (Result Module > Attendance (Subject) পেজ থেকে তৈরি হয়) এখনো
// তৈরি না হয়ে থাকলে — ঐ পেজে কখনো না গিয়ে সরাসরি এখানে (Report Card) এলে নিচের SELECT
// "Base table or view not found" ফ্যাটাল এরর দিয়ে পুরো পেজ ক্র্যাশ করাত (সাদা স্ক্রিন, কোনো
// ইউজার-ফ্রেন্ডলি মেসেজ ছাড়াই)। এখানেও একই স্ট্রাকচারে (result_attendance_subject.php এর
// সাথে হুবহু মিলিয়ে) নিরাপদে auto-create করা হলো, যাতে যেকোনো অর্ডারে পেজ ভিজিট করলেও কাজ করে।
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

// Master Setting-এর টগলগুলো (না থাকলে সেফ ডিফল্ট — সব On)
$rc_settings = $pdo->query("SELECT * FROM exam_result_settings WHERE id = 1")->fetch() ?: [];
// Student/Guardian পোর্টাল (student/report_card.php) এই পেজটাই include করে; তখন ফিল্টার ফর্ম লুকানো থাকে
// আর ছাত্র শুধু নিজের Report Card দেখে (student/report_card.php আগেই $_GET ফিক্স করে দেয়)।
$RC_STUDENT_VIEW = !empty($GLOBALS['SP_RC_STUDENT_VIEW']);
function rcs(array $s, string $key, $default = 1): bool
{
    return isset($s[$key]) ? ((int) $s[$key] === 1) : ((int) $default === 1);
}
$RC_MEDIUM        = rcs($rc_settings, 'show_medium_name');
$RC_ROLL          = rcs($rc_settings, 'show_student_roll_no');
$RC_PHOTO_QR      = rcs($rc_settings, 'show_student_picture_qr');
$RC_WATERMARK     = rcs($rc_settings, 'add_watermark_background', 0);
$RC_BANNER        = rcs($rc_settings, 'show_banner_instead_logo', 0);
$RC_PASS_FAIL_SUM = rcs($rc_settings, 'pass_fail_summary_merit_list');
$RC_FINAL_GL_GP   = rcs($rc_settings, 'show_final_grade_letter_point');
$RC_SUBJ_GL       = rcs($rc_settings, 'show_subject_grade_letter');
$RC_SUBJ_GP       = rcs($rc_settings, 'show_subject_grade_point');
$RC_REMARKS       = rcs($rc_settings, 'show_result_remarks');
$RC_ATTEND        = rcs($rc_settings, 'show_working_days_attend_absent');
$RC_SHOW_ABSENT   = rcs($rc_settings, 'show_absent_count', 1);
$RC_DISCIPLINE    = rcs($rc_settings, 'show_discipline_cleanliness');
$RC_INTERPRET     = rcs($rc_settings, 'show_interpretation');
$RC_FAIL_COUNT    = rcs($rc_settings, 'show_total_subject_fail_count', 0);
$RC_PART_PERCENT  = rcs($rc_settings, 'show_part_marks_calculation');
$RC_RANK_BY_MARKS = rcs($rc_settings, 'calculate_merit_position_by_marks', 1);

// Master Setting > "Report Card Template" — 'Govt. / MPO' (আগের ডিজাইন, অপরিবর্তিত)
// অথবা 'School (Classic)' (নতুন মেরুন-গোল্ড ডিজাইন) — নিচে দুটোর জন্যই আলাদা markup আছে
$rc_template = $rc_settings['report_card_template'] ?? 'Govt. / MPO';

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
$inst_logo = $institute['logo_path'] ?? '';
$inst_banner = $institute['banner_path'] ?? '';
$inst_eiin = $institute['eiin_no'] ?? '';

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name, has_group FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
$groups   = $pdo->query("SELECT id, group_name, class_id FROM academic_groups WHERE status = 'Active' ORDER BY group_name ASC")->fetchAll();
$asr_where = ["status = 'Active'"]; $asr_params = [];
branch_apply_filter($asr_where, $asr_params);
if (function_exists('ct_scope_active') && ct_scope_active()) { ct_scope_apply_where($asr_where); }
$asr_stmt = $pdo->prepare("SELECT id, student_id, roll_no, name_en, class_id, shift_id, section_id FROM students WHERE " . implode(' AND ', $asr_where) . " ORDER BY name_en ASC");
$asr_stmt->execute($asr_params);
$all_students = $asr_stmt->fetchAll();
$terms    = $current_session_id
    ? $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC")
    : null;
if ($terms) { $terms->execute([$current_session_id]); $terms = $terms->fetchAll(); } else { $terms = []; }
$parts = $current_session_id
    ? $pdo->prepare("SELECT id, part_name FROM exam_parts WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC")
    : null;
if ($parts) { $parts->execute([$current_session_id]); $parts = $parts->fetchAll(); } else { $parts = []; }


// Class Teacher স্কোপ (Teacher Portal > My Class): শুধু নিজের Class/Section, শুধু Publish হওয়া Term
if (function_exists('ct_scope_active') && ct_scope_active()) {
    $classes  = ct_scope_filter_classes($classes);
    $sections = ct_scope_filter_sections($sections);
    $terms    = ct_scope_published_terms($pdo, $terms);
    ct_scope_sanitize_get(array_map(fn($t) => (int) $t['id'], $terms));
}

// -----------------------------------------------------------
// GET ফিল্টার
// -----------------------------------------------------------
$f_session_id = (int) ($_GET['session_id'] ?? $current_session_id);
$f_class_id   = (int) ($_GET['class_id'] ?? 0);
$f_group_id   = (int) ($_GET['group_id'] ?? 0);
$f_shift_id   = (int) ($_GET['shift_id'] ?? 0);
$f_section_id = (int) ($_GET['section_id'] ?? 0);
$f_term_id    = (int) ($_GET['term_id'] ?? 0);
$f_part_id    = (int) ($_GET['part_id'] ?? 0);
$f_student_id = (int) ($_GET['student_id'] ?? 0);
$arabic_mode  = array_key_exists($_GET['arabic_mode'] ?? '', rc_arabic_modes()) ? $_GET['arabic_mode'] : 'separate';
$show_merit   = isset($_GET['search_result']) ? isset($_GET['show_merit']) : true;
$searched     = isset($_GET['search_result']);
// Class Teacher স্কোপে অন্য ক্লাসের স্টুডেন্ট আইডি দিলে ব্লক
if ($f_student_id && function_exists('ct_scope_active') && ct_scope_active()
    && !in_array($f_student_id, array_map('intval', array_column($all_students, 'id')), true)) {
    $f_student_id = -1;
}

$reports = [];
$no_subjects_assigned = false;

$grade_bands = [];

if ($searched && $f_class_id && $f_term_id) {
    // Part সিলেক্ট না করলে ($f_part_id = 0) এই সাবজেক্ট-ডেফিনিশনে ঐ Term-এর
    // সবগুলো Active Part একসাথে চলে আসে (Combined), Part সিলেক্ট করলে শুধু ঐ Part-ই।
    $defs = rc_get_subject_defs($pdo, $f_class_id, $f_term_id, $f_part_id);
    $subject_ids = array_keys($defs);

    $grade_stmt = $pdo->prepare("SELECT grade_letter, gpa, mark_from, mark_to, remarks FROM exam_grade_points WHERE session_id = ? ORDER BY mark_from DESC");
    $grade_stmt->execute([$f_session_id]);
    $grade_bands = $grade_stmt->fetchAll();

    if (!$subject_ids) {
        $no_subjects_assigned = true;
    } else {
        // -----------------------------------------------------------
        // টার্গেট স্টুডেন্ট লিস্ট — Student সিলেক্ট করা থাকলে শুধু সেই একজন,
        // নাহলে Class/Shift/Section/Group ফিল্টার মিলিয়ে যত স্টুডেন্ট আছে সবাই।
        // -----------------------------------------------------------
        if ($f_student_id) {
            $target_student_ids = [$f_student_id];
        } else {
            $sql = "SELECT id FROM students WHERE status = 'Active' AND class_id = ?";
            $params = [$f_class_id];
            if ($f_shift_id)   { $sql .= " AND shift_id = ?";   $params[] = $f_shift_id; }
            if ($f_section_id) { $sql .= " AND section_id = ?"; $params[] = $f_section_id; }
            if ($f_group_id)   { $sql .= " AND group_id = ?";   $params[] = $f_group_id; }
            $rc_bf_where = []; $rc_bf_params = [];
            branch_apply_filter($rc_bf_where, $rc_bf_params);
            if ($rc_bf_where) { $sql .= ' AND ' . implode(' AND ', $rc_bf_where); $params = array_merge($params, $rc_bf_params); }
            if (function_exists('ct_scope_active') && ct_scope_active()) { $sql .= ' AND ' . ct_scope_sql(''); }
            $sql .= " ORDER BY roll_no ASC, name_en ASC";
            $tgt = $pdo->prepare($sql);
            $tgt->execute($params);
            $target_student_ids = array_column($tgt->fetchAll(), 'id');
        }

        if ($target_student_ids) {
            $in_subj = implode(',', array_fill(0, count($subject_ids), '?'));
            $in_stu  = implode(',', array_fill(0, count($target_student_ids), '?'));

            $mk_sql = "SELECT student_id, subject_id, part_id, marks_obtained FROM exam_marks WHERE term_id = ? AND subject_id IN ($in_subj) AND student_id IN ($in_stu)";
            $mk_params = array_merge([$f_term_id], $subject_ids, $target_student_ids);
            if ($f_part_id) { $mk_sql .= " AND part_id = ?"; $mk_params[] = $f_part_id; }
            $mk = $pdo->prepare($mk_sql);
            $mk->execute($mk_params);
            $marks_all = [];
            foreach ($mk->fetchAll() as $row) { $marks_all[$row['student_id']][$row['subject_id']][$row['part_id']] = $row['marks_obtained']; }

            $at_sql = "SELECT student_id, subject_id, part_id, status FROM exam_attendance_subject WHERE term_id = ? AND subject_id IN ($in_subj) AND student_id IN ($in_stu)";
            $at_params = array_merge([$f_term_id], $subject_ids, $target_student_ids);
            if ($f_part_id) { $at_sql .= " AND part_id = ?"; $at_params[] = $f_part_id; }
            $at = $pdo->prepare($at_sql);
            $at->execute($at_params);
            $att_all = [];
            foreach ($at->fetchAll() as $row) { $att_all[$row['student_id']][$row['subject_id']][$row['part_id']] = $row['status']; }

            // Monthly + Term + Formative আলাদা ভাগ (Exam Terms-এ Sub টার্ম ওয়েট / Formative ওয়েট দেওয়া থাকলে; Part ফিল্টার না থাকলে)
            $defs_orig = $defs;
            $rc_comp = ($f_part_id === 0) ? rc_term_composition($pdo, (int) $f_term_id, (int) $f_class_id) : null;
            if ($rc_comp) { [$defs, $marks_all, $att_all] = rc_compose_term($pdo, $rc_comp, (int) $f_class_id, $defs_orig, $marks_all, $att_all, $target_student_ids); }

            $stu_info_stmt = $pdo->prepare(
                "SELECT s.*, ac.class_name, ash.shift_name, asec.section_name, ag.group_name, am.medium_name
                 FROM students s
                 LEFT JOIN academic_classes ac ON ac.id = s.class_id
                 LEFT JOIN academic_shifts ash ON ash.id = s.shift_id
                 LEFT JOIN academic_sections asec ON asec.id = s.section_id
                 LEFT JOIN academic_groups ag ON ag.id = s.group_id
                 LEFT JOIN academic_mediums am ON am.id = ac.medium_id
                 WHERE s.id = ? LIMIT 1"
            );

            foreach ($target_student_ids as $sid_target) {
                $stu_info_stmt->execute([$sid_target]);
                $stu_row = $stu_info_stmt->fetch();
                if (!$stu_row) { continue; }

                // শাখা (Group) অনুযায়ী সাবজেক্ট বাদ (rc_excluded_subjects) + আরবি আলাদা ব্লকে/সহ/ছাড়া ($arabic_mode)
                $result = rc_student_result_mode($defs, $marks_all[$sid_target] ?? [], $att_all[$sid_target] ?? [], $grade_bands, $arabic_mode, rc_excluded_subjects($pdo, $defs, (int) $f_term_id, (int) $sid_target));

                // "Show Working Days & Attend Absent" টগলের জন্য — অটো হিসাব, ম্যানুয়াল এন্ট্রির
                // দরকার নেই। student_daily_attendance টেবিল থেকে Present/Absent কাউন্ট করে
                // Working Days = Present + Absent হিসেবে যোগ হয়।
                $present_days = 0; $absent_days = 0; $working_days = 0;
                if ($RC_ATTEND) {
                    try {
                        $att_sum = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM student_daily_attendance WHERE student_id = ? GROUP BY status");
                        $att_sum->execute([$sid_target]);
                        foreach ($att_sum->fetchAll() as $r) {
                            if ($r['status'] === 'Present') { $present_days = (int) $r['cnt']; }
                            if ($r['status'] === 'Absent') { $absent_days = (int) $r['cnt']; }
                        }
                        $working_days = $present_days + $absent_days;
                    } catch (PDOException $e) { /* টেবিল না থাকলে ০ দেখাবে */ }
                }

                // Class Position (Show Merit চেক করা থাকলে) — এই স্টুডেন্টের নিজের
                // Class/Shift/Section/Group-এর সবার মধ্যে Rank
                $class_position = null; $class_total_count = null; $class_pass_count = null; $class_fail_count = null;
                $arabic_position = null;
                if ($show_merit) {
                    $stu_group_id = (int) ($stu_row['group_id'] ?? 0);
                    $cls_stu_stmt = $pdo->prepare(
                        "SELECT id FROM students WHERE status='Active' AND session_id=? AND class_id=? AND shift_id=? AND section_id=?" .
                        ($stu_group_id ? " AND group_id=?" : '') .
                        (branch_current_id() !== null ? " AND (branch_id = ?)" : '')
                    );
                    $cls_params = [$f_session_id, (int) $stu_row['class_id'], (int) $stu_row['shift_id'], (int) $stu_row['section_id']];
                    if ($stu_group_id) { $cls_params[] = $stu_group_id; }
                    if (branch_current_id() !== null) { $cls_params[] = branch_current_id(); }
                    $cls_stu_stmt->execute($cls_params);
                    $cls_stu_ids = array_column($cls_stu_stmt->fetchAll(), 'id');

                    if ($cls_stu_ids) {
                        $in_cls_stu = implode(',', array_fill(0, count($cls_stu_ids), '?'));

                        $mk_cls_sql = "SELECT student_id, subject_id, part_id, marks_obtained FROM exam_marks WHERE term_id = ? AND subject_id IN ($in_subj) AND student_id IN ($in_cls_stu)";
                        $mk_cls_params = array_merge([$f_term_id], $subject_ids, $cls_stu_ids);
                        if ($f_part_id) { $mk_cls_sql .= " AND part_id = ?"; $mk_cls_params[] = $f_part_id; }
                        $mk_cls = $pdo->prepare($mk_cls_sql);
                        $mk_cls->execute($mk_cls_params);
                        $marks_cls = [];
                        foreach ($mk_cls->fetchAll() as $row) { $marks_cls[$row['student_id']][$row['subject_id']][$row['part_id']] = $row['marks_obtained']; }

                        $at_cls_sql = "SELECT student_id, subject_id, part_id, status FROM exam_attendance_subject WHERE term_id = ? AND subject_id IN ($in_subj) AND student_id IN ($in_cls_stu)";
                        $at_cls_params = array_merge([$f_term_id], $subject_ids, $cls_stu_ids);
                        if ($f_part_id) { $at_cls_sql .= " AND part_id = ?"; $at_cls_params[] = $f_part_id; }
                        $at_cls = $pdo->prepare($at_cls_sql);
                        $at_cls->execute($at_cls_params);
                        $att_cls = [];
                        foreach ($at_cls->fetchAll() as $row) { $att_cls[$row['student_id']][$row['subject_id']][$row['part_id']] = $row['status']; }

                        if ($rc_comp) { [, $marks_cls, $att_cls] = rc_compose_term($pdo, $rc_comp, (int) $f_class_id, $defs_orig, $marks_cls, $att_cls, $cls_stu_ids); }

                        $totals = [];
                        foreach ($cls_stu_ids as $csid) {
                            $cres = rc_student_result_mode($defs, $marks_cls[$csid] ?? [], $att_cls[$csid] ?? [], $grade_bands, $arabic_mode, rc_excluded_subjects($pdo, $defs, (int) $f_term_id, (int) $csid));
                            $totals[$csid] = ['total' => $cres['obtained_total'], 'gpa' => $cres['overall_gpa'], 'fail' => $cres['overall_fail'],
                                              'a_total' => $cres['arabic']['obtained_total'] ?? null, 'a_fail' => $cres['arabic']['fail'] ?? false];
                        }
                        $class_total_count = count($totals);
                        $passed = array_filter($totals, fn($t) => !$t['fail']);
                        $class_pass_count = count($passed);
                        $class_fail_count = $class_total_count - $class_pass_count;
                        // Master Setting > "Calculate Merit Position By Marks" — On হলে মোট নম্বর দিয়ে,
                        // Off হলে GPA দিয়ে র‍্যাঙ্ক করা হয়
                        $rank_key = $RC_RANK_BY_MARKS ? 'total' : 'gpa';
                        uasort($passed, fn($a, $b) => $b[$rank_key] <=> $a[$rank_key]);
                        $rank = 1; $prev_val = null; $prev_rank = 1;
                        foreach ($passed as $csid => $t) {
                            if ($prev_val !== null && $t[$rank_key] === $prev_val) { $assigned = $prev_rank; } else { $assigned = $rank; $prev_rank = $rank; }
                            if ($csid === $sid_target) { $class_position = $assigned; }
                            $prev_val = $t[$rank_key];
                            $rank++;
                        }
                        // আরবি ব্লকের নিজস্ব Position (শাখা ধরে, আরবির মোট নম্বর অনুযায়ী; আরবিতে ফেল করলে Position নেই)
                        if (!empty($result['arabic'])) {
                            $ar_pass = array_filter($totals, fn($t) => $t['a_total'] !== null && !$t['a_fail']);
                            uasort($ar_pass, fn($a, $b) => $b['a_total'] <=> $a['a_total']);
                            $ar_rank = 1; $ar_prev = null; $ar_prev_rank = 1;
                            foreach ($ar_pass as $csid => $t) {
                                $ar_assigned = ($ar_prev !== null && $t['a_total'] === $ar_prev) ? $ar_prev_rank : $ar_rank;
                                if ($ar_assigned === $ar_rank) { $ar_prev_rank = $ar_rank; }
                                if ($csid === $sid_target) { $arabic_position = $ar_assigned; }
                                $ar_prev = $t['a_total'];
                                $ar_rank++;
                            }
                        }
                    }
                }

                $reports[] = array_merge($result, [
                    'student' => $stu_row,
                    'present_days' => $present_days, 'absent_days' => $absent_days, 'working_days' => $working_days,
                    'arabic_position' => $arabic_position,
                    'comp' => $rc_comp, 'comp_marks' => $marks_all[$sid_target] ?? [],
                    'class_position' => $class_position, 'class_total_count' => $class_total_count,
                    'class_pass_count' => $class_pass_count, 'class_fail_count' => $class_fail_count,
                ]);
            }
        }
    }
}

$sheet_session_name = '';
if ($searched) {
    $sess_s = $pdo->prepare("SELECT session_name FROM academic_sessions WHERE id = ?");
    $sess_s->execute([$f_session_id]);
    $sheet_session_name = $sess_s->fetchColumn() ?: date('Y');
    $ts = $pdo->prepare("SELECT term_name FROM exam_terms WHERE id = ?"); $ts->execute([$f_term_id]); $term_name = $ts->fetchColumn() ?: '';
} else {
    $term_name = '';
}

?>
<style>
    .rc-wrap { border: 2px solid #333; padding: 24px; max-width: 950px; margin: 0 auto; }
    .rc-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; }
    .rc-center { text-align: center; flex: 1; }
    .rc-center h4 { font-weight: bold; color: #1e3a8a; margin-bottom: 4px; }
    .rc-center .addr { color: #444; font-size: 13px; margin-bottom: 2px; }
    .rc-center .phone { font-size: 13px; margin-bottom: 2px; }
    .rc-center .web { color: #2563eb; font-size: 13px; margin-bottom: 8px; }
    .rc-badge {
        display: inline-block; font-weight: bold; font-size: 14px; border: 1.5px solid #333;
        border-radius: 3px; padding: 5px 20px; margin: 8px 0 14px;
    }
    .rc-photo-block { text-align: center; width: 100px; }
    .rc-photo-block img.rc-photo { width: 90px; height: 90px; object-fit: cover; border: 1px solid #999; }
    .rc-qr { margin-top: 8px; }
    .rc-qr small { display: block; font-size: 11px; margin-top: 2px; }
    .rc-scale table { border-collapse: collapse; font-size: 12px; }
    .rc-scale th, .rc-scale td { border: 1px solid #999; padding: 3px 8px; text-align: center; }
    .rc-info { font-size: 14px; margin: 14px 0; }
    .rc-info .row-line { display: flex; margin-bottom: 4px; }
    .rc-info .lbl { width: 150px; flex-shrink: 0; }
    .rc-info .sep { width: 16px; flex-shrink: 0; }
    .rc-info .val { border-bottom: 1px dotted #666; flex: 1; font-style: italic; }
    .rc-subj-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .rc-subj-table th, .rc-subj-table td { border: 1px solid #333; padding: 8px; font-size: 13px; text-align: center; }
    .rc-subj-table td:nth-child(2) { text-align: left; }
    .rc-subj-fail { color: #dc2626; font-weight: 700; }
    .rc-subj-pass { color: #16a34a; font-weight: 700; }
    .rc-signatures { display: flex; justify-content: space-between; margin-top: 60px; font-size: 13px; }
    .rc-signatures div { border-top: 1px solid #333; padding-top: 4px; width: 28%; text-align: center; }
    .rc-position { text-align: center; margin-top: 10px; font-size: 14px; font-weight: 600; }
    .rc-wrap.rc-watermark { position: relative; }
    .rc-wrap.rc-watermark::before {
        content: ""; position: absolute; inset: 0; z-index: 0;
        background-image: url('<?php echo rcv($inst_logo !== "" ? "../" . $inst_logo : ""); ?>');
        background-repeat: no-repeat; background-position: center; background-size: 45%;
        opacity: 0.08; pointer-events: none;
    }
    .rc-wrap.rc-watermark > * { position: relative; z-index: 1; }
    .rc-banner { width: 100%; max-height: 90px; object-fit: contain; margin-bottom: 6px; }
    .rc-extra-row { display: flex; justify-content: space-between; font-size: 13px; margin-top: 12px; flex-wrap: wrap; gap: 10px; }
    .rc-extra-row .rc-extra-item { border: 1px solid #ccc; border-radius: 4px; padding: 6px 12px; flex: 1; min-width: 150px; }
    .rc-extra-row .rc-extra-item b { display: block; font-size: 11px; color: #555; margin-bottom: 2px; }
    .rc-summary-box { border: 1px solid #333; border-radius: 4px; padding: 8px 14px; margin-top: 12px; font-size: 13px; display: flex; gap: 20px; flex-wrap: wrap; justify-content: center; }
    .rc-remarks { margin-top: 12px; font-size: 13px; border: 1px solid #ccc; border-radius: 4px; padding: 8px 12px; }
    .rc-remarks.rc-pass { border-color: #16a34a; color: #16a34a; }
    .rc-remarks.rc-fail { border-color: #dc2626; color: #dc2626; }
    .rc-blank-box { border: 1px dashed #999; border-radius: 4px; padding: 10px 12px; margin-top: 12px; font-size: 13px; min-height: 34px; }

    /* ---- Print: must stay LAST so it overrides the base rules above ---- */
    @media print {
        @page { size: <?php echo $rc_template === 'School (Classic)' ? 'A4 landscape' : 'A4 portrait'; ?>; margin: 8mm; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        html, body { background: #fff !important; height: auto !important; overflow: visible !important; }
        .no-print, .sidebar, .sticky-top-area, .top-navbar, .breadcrumb-bar, footer, .alert { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; padding: 0 !important; }
        .content-body { padding: 0 !important; margin: 0 !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
        .rc-wrap { page-break-inside: avoid; break-inside: avoid; }
        .rc-wrap + .rc-wrap { page-break-before: always; }
        .sc-sheet { margin: 0 auto !important; page-break-inside: avoid; }
        .rc-wrap { max-width: none !important; width: 100%; box-sizing: border-box; padding: 10px 12px !important; margin: 0 !important; overflow: hidden; }
        .rc-top { gap: 8px; }
        .rc-center h4 { font-size: 18px; margin-bottom: 2px; }
        .rc-center .addr, .rc-center .phone, .rc-center .web { font-size: 11px; margin-bottom: 1px; line-height: 1.25; }
        .rc-center img { max-height: 46px !important; }
        .rc-badge { margin: 3px 0 4px; padding: 3px 12px; font-size: 12px; }
        .rc-photo-block img.rc-photo { width: 70px; height: 70px; }
        .rc-scale table { font-size: 9.5px; line-height: 1.15; }
        .rc-scale th, .rc-scale td { padding: 1px 4px; }
        .rc-info { font-size: 12px; margin: 6px 0; line-height: 1.25; }
        .rc-info .row-line { margin-bottom: 2px; }
        .rc-info .lbl { width: 120px; }
        .rc-subj-table { table-layout: auto; margin-top: 4px; }
        .rc-subj-table th, .rc-subj-table td { padding: 3px 4px; font-size: 11px; line-height: 1.2; width: auto !important; }
        .rc-subj-table thead { display: table-header-group; }
        .rc-subj-table tr { page-break-inside: avoid; break-inside: avoid; }
        .rc-extra-row, .rc-summary-box, .rc-remarks, .rc-blank-box { margin-top: 6px; font-size: 11px; padding: 4px 8px; min-height: 0; gap: 6px; }
        .rc-extra-row { padding: 0; }
        .rc-signatures { margin-top: 28px; font-size: 11px; page-break-inside: avoid; }
        .rc-position { margin-top: 6px; font-size: 12px; }
    }
    /* same compact sizing while JS measures height (beforeprint) */
    body.rc-printing .rc-wrap { width: 194mm; }
    body.rc-printing .rc-wrap { max-width: none !important; width: 100%; box-sizing: border-box; padding: 10px 12px !important; margin: 0 !important; overflow: hidden; }
    body.rc-printing .rc-top { gap: 8px; }
    body.rc-printing .rc-center h4 { font-size: 18px; margin-bottom: 2px; }
    body.rc-printing .rc-center .addr,body.rc-printing .rc-center .phone,body.rc-printing .rc-center .web { font-size: 11px; margin-bottom: 1px; line-height: 1.25; }
    body.rc-printing .rc-center img { max-height: 46px !important; }
    body.rc-printing .rc-badge { margin: 3px 0 4px; padding: 3px 12px; font-size: 12px; }
    body.rc-printing .rc-photo-block img.rc-photo { width: 70px; height: 70px; }
    body.rc-printing .rc-scale table { font-size: 9.5px; line-height: 1.15; }
    body.rc-printing .rc-scale th,body.rc-printing .rc-scale td { padding: 1px 4px; }
    body.rc-printing .rc-info { font-size: 12px; margin: 6px 0; line-height: 1.25; }
    body.rc-printing .rc-info .row-line { margin-bottom: 2px; }
    body.rc-printing .rc-info .lbl { width: 120px; }
    body.rc-printing .rc-subj-table { table-layout: auto; margin-top: 4px; }
    body.rc-printing .rc-subj-table th,body.rc-printing .rc-subj-table td { padding: 3px 4px; font-size: 11px; line-height: 1.2; width: auto !important; }
    body.rc-printing .rc-subj-table thead { display: table-header-group; }
    body.rc-printing .rc-subj-table tr { page-break-inside: avoid; break-inside: avoid; }
    body.rc-printing .rc-extra-row,body.rc-printing .rc-summary-box,body.rc-printing .rc-remarks,body.rc-printing .rc-blank-box { margin-top: 6px; font-size: 11px; padding: 4px 8px; min-height: 0; gap: 6px; }
    body.rc-printing .rc-extra-row { padding: 0; }
    body.rc-printing .rc-signatures { margin-top: 28px; font-size: 11px; page-break-inside: avoid; }
    body.rc-printing .rc-position { margin-top: 6px; font-size: 12px; }
    body.rc-printing .rc-wrap { width: 194mm; }
</style>

<?php if (!$RC_STUDENT_VIEW): ?>
<div class="card-custom no-print">
    <form method="GET" action="index.php" id="rcFilterForm">
        <input type="hidden" name="page" value="result_students_report_card">
        <div class="row">
            <div class="col-md-4">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Session <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <select name="session_id" class="form-select">
                            <?php foreach ($sessions as $s): ?>
                                <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session_id === (int) $s['id'] ? 'selected' : ''; ?>><?php echo rcv($s['session_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Class <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <select name="class_id" id="rcClassSelect" class="form-select" required onchange="filterRcDependents()">
                            <option value="">Select</option>
                            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo rcv($c['class_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Group</label>
                    <div class="col-sm-8">
                        <select name="group_id" id="rcGroupSelect" class="form-select">
                            <option value="0">All Group</option>
                            <?php foreach ($groups as $g): ?><option value="<?php echo (int) $g['id']; ?>" data-class="<?php echo (int) $g['class_id']; ?>" <?php echo $f_group_id === (int) $g['id'] ? 'selected' : ''; ?>><?php echo rcv($g['group_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Shift</label>
                    <div class="col-sm-8">
                        <select name="shift_id" id="rcShiftSelect" class="form-select" onchange="filterRcDependents()">
                            <option value="">Select Shift</option>
                            <?php foreach ($shifts as $sh): ?><option value="<?php echo (int) $sh['id']; ?>" <?php echo $f_shift_id === (int) $sh['id'] ? 'selected' : ''; ?>><?php echo rcv($sh['shift_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Section</label>
                    <div class="col-sm-8">
                        <select name="section_id" id="rcSectionSelect" class="form-select" disabled onchange="filterRcDependents()">
                            <option value="">Select Class first</option>
                            <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" data-shift="<?php echo (int) $sec['shift_id']; ?>" <?php echo $f_section_id === (int) $sec['id'] ? 'selected' : ''; ?> hidden><?php echo rcv($sec['section_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Term <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <select name="term_id" class="form-select" required>
                            <option value="">Select</option>
                            <?php foreach ($terms as $t): ?><option value="<?php echo (int) $t['id']; ?>" <?php echo $f_term_id === (int) $t['id'] ? 'selected' : ''; ?>><?php echo rcv($t['term_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Part</label>
                    <div class="col-sm-8">
                        <select name="part_id" class="form-select">
                            <option value="">All Parts (Combined)</option>
                            <?php foreach ($parts as $p): ?><option value="<?php echo (int) $p['id']; ?>" <?php echo $f_part_id === (int) $p['id'] ? 'selected' : ''; ?>><?php echo rcv($p['part_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Student</label>
                    <div class="col-sm-8">
                        <select name="student_id" id="rcStudentSelect" class="form-select">
                            <option value="">All Students</option>
                            <?php foreach ($all_students as $stu): ?>
                                <option value="<?php echo (int) $stu['id']; ?>" data-class="<?php echo (int) $stu['class_id']; ?>" data-shift="<?php echo (int) $stu['shift_id']; ?>" data-section="<?php echo (int) $stu['section_id']; ?>" <?php echo $f_student_id === (int) $stu['id'] ? 'selected' : ''; ?>>
                                    <?php echo rcv($stu['name_en']); ?> (Roll: <?php echo rcv($stu['roll_no']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="row mb-3 align-items-center">
                    <div class="col-sm-4"></div>
                    <div class="col-sm-8 pt-2">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="rcShowMerit" name="show_merit" value="1" <?php echo $show_merit ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-bold" for="rcShowMerit">Show Merit</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Arabic</label>
                    <div class="col-sm-8">
                        <select name="arabic_mode" class="form-select">
                            <?php foreach (rc_arabic_modes() as $am_key => $am_label): ?>
                                <option value="<?php echo rcv($am_key); ?>" <?php echo $arabic_mode === $am_key ? 'selected' : ''; ?>><?php echo rcv($am_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center mt-2">
            <button type="submit" name="search_result" value="1" class="btn btn-primary fw-bold px-4" style="background-color:#1e3a8a;"><i class="fa-solid fa-magnifying-glass"></i> Search Result</button>
            <a href="index.php?page=result_students_report_card" class="btn btn-danger fw-bold px-4"><i class="fa-solid fa-xmark"></i> Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (!$terms && !$RC_STUDENT_VIEW): ?>
    <div class="alert alert-warning mt-3 no-print">
        এই সেশনের জন্য কোনো <strong>Exam Term</strong> সেট করা নেই। রেজাল্ট দেখার আগে
        <a href="index.php?page=result_exam_terms">Exam Terms</a> পেজ থেকে একটা Term যোগ করুন।
    </div>
<?php endif; ?>

<?php if ($searched): ?>
    <?php if ($RC_STUDENT_VIEW && ($no_subjects_assigned || empty($grade_bands))): ?>
        <div class="alert alert-info mt-3 no-print">এই পরীক্ষার ফলাফল এখনো প্রস্তুত হয়নি। কিছুদিন পর আবার দেখুন।</div>
    <?php elseif ($no_subjects_assigned): ?>
        <div class="alert alert-warning mt-3 no-print">
            এই Class/Term<?php echo $f_part_id ? '/Part' : ''; ?>-এর জন্য কোনো Subject Assign করা নেই। আগে
            <a href="index.php?page=result_assign_exams">Assign Exam</a> পেজ থেকে Subject Assign করুন।
        </div>
    <?php elseif (empty($grade_bands)): ?>
        <div class="alert alert-warning mt-3 no-print">
            এই সেশনের জন্য কোনো <strong>Grade Point</strong> সেট করা নেই, তাই মার্ক যাই হোক না কেন সবাইকে
            <strong>Fail (F)</strong> দেখাবে। আগে
            <a href="index.php?page=result_grade_points">Grade Points</a> পেজ থেকে Grade Point (A+, A, A- ...) সেটআপ করুন।
        </div>
    <?php elseif (!$reports): ?>
        <p class="text-muted text-center mt-3 no-print">
            <?php echo $f_student_id ? 'এই Student খুঁজে পাওয়া যায়নি।' : 'এই ফিল্টারে কোনো Student পাওয়া যায়নি।'; ?>
        </p>
    <?php else: ?>
        <div class="no-print d-flex justify-content-between align-items-center mb-3 mt-3">
            <span class="text-muted"><?php echo count($reports); ?> টি Report Card<?php echo $f_part_id ? '' : ' (সব Part মিলিয়ে Combined Result)'; ?></span>
            <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print <?php echo count($reports) > 1 ? 'All' : ''; ?></button>
        </div>

        <?php foreach ($reports as $rc_idx => $rep): ?>
        <?php
            $student = $rep['student'];
            $subject_rows = $rep['subject_rows'];
            $rc_comp_r = $rep['comp'] ?? null; $rc_cm = $rep['comp_marks'] ?? [];
            $rc_comp_w = [];
            if ($rc_comp_r) { foreach ($rc_comp_r['subs'] as $rc_s) { $rc_comp_w[-(1000 + $rc_s['id'])] = $rc_s['weight']; } $rc_comp_w[-1] = $rc_comp_r['own_w']; $rc_comp_w[-2] = $rc_comp_r['formative_w']; }
            $overall_gpa = $rep['overall_gpa'];
            $overall_gl = $rep['overall_gl'];
            $overall_fail = $rep['overall_fail'];
            $full_total = $rep['full_total'];
            $obtained_total = $rep['obtained_total'];
            $percentage = $rep['percentage'];
            $fail_subject_count = $rep['fail_subject_count'];
            $overall_remarks = $rep['overall_remarks'] ?? '';
            $present_days = $rep['present_days'];
            $absent_days = $rep['absent_days'];
            $working_days = $rep['working_days'];
            $class_position = $rep['class_position'];
            $class_total_count = $rep['class_total_count'];
            $class_pass_count = $rep['class_pass_count'];
            $class_fail_count = $rep['class_fail_count'];
        ?>
        <?php if ($rc_template === 'School (Classic)'): ?>
        <?php include __DIR__ . '/partials/report_card_classic.php'; ?>
        <?php else: ?>
        <div class="rc-wrap<?php echo $RC_WATERMARK ? ' rc-watermark' : ''; ?>"<?php echo ($rc_idx < count($reports) - 1) ? ' style="page-break-after: always;"' : ''; ?>>
            <div class="rc-top">
                <?php if ($RC_PHOTO_QR): ?>
                <div class="rc-photo-block">
                    <?php if (!empty($student['photo_path'])): ?>
                        <img class="rc-photo" src="../<?php echo rcv($student['photo_path']); ?>">
                    <?php else: ?>
                        <div class="rc-photo" style="width:90px;height:90px;border:1px solid #999;display:flex;align-items:center;justify-content:center;font-size:11px;color:#999;">No Photo</div>
                    <?php endif; ?>
                    <div class="rc-qr">
                        <div id="rcQrBox_<?php echo (int) $rc_idx; ?>"></div>
                        <small>(EIIN No<?php echo $inst_eiin !== '' ? ' : ' . rcv($inst_eiin) : ''; ?>)</small>
                    </div>
                </div>
                <?php endif; ?>

                <div class="rc-center">
                    <?php if ($RC_BANNER && !empty($inst_banner)): ?>
                        <img class="rc-banner" src="../<?php echo rcv($inst_banner); ?>">
                    <?php elseif (!empty($inst_logo)): ?>
                        <img src="../<?php echo rcv($inst_logo); ?>" style="max-height:60px;" ><br>
                    <?php endif; ?>
                    <h4><?php echo rcv($inst_display_name); ?></h4>
                    <?php if ($addr_line !== ''): ?><p class="addr">(<?php echo rcv($addr_line); ?>)</p><?php endif; ?>
                    <?php if ($inst_phone !== ''): ?><p class="phone">Phone: <?php echo rcv($inst_phone); ?></p><?php endif; ?>
                    <?php if (!empty($institute['website'])): ?><p class="web"><?php echo htmlspecialchars((string) $institute['website']); ?></p><?php endif; ?>
                    <div class="rc-badge">Academic Transcript : <?php echo rcv($term_name); ?> <?php echo rcv($sheet_session_name); ?></div>
                </div>

                <div class="rc-scale">
                    <table>
                        <thead>
                            <tr><th>Letter<br>Grade</th><th>Class Interval<br>(%)</th><th>Grade<br>Point</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grade_bands as $band): ?>
                                <tr>
                                    <td><?php echo rcv($band['grade_letter']); ?></td>
                                    <td><?php echo rcv(rtrim(rtrim(number_format((float) $band['mark_from'], 2), '0'), '.')); ?>-<?php echo rcv(rtrim(rtrim(number_format((float) $band['mark_to'], 2), '0'), '.')); ?></td>
                                    <td><?php echo rcv(rtrim(rtrim(number_format((float) $band['gpa'], 2), '0'), '.')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr><td>F</td><td>0-<?php echo $grade_bands ? rcv(rtrim(rtrim(number_format(max(0, (float) end($grade_bands)['mark_from'] - 1), 2), '0'), '.')) : '—'; ?></td><td>0</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rc-info">
                <div class="row-line"><div class="lbl">Name of Student</div><div class="sep">:</div><div class="val"><?php echo rcv($student['name_en']); ?></div></div>
                <div class="row-line"><div class="lbl">Father's Name</div><div class="sep">:</div><div class="val"><?php echo rcv($student['father_name']); ?></div></div>
                <div class="row-line"><div class="lbl">Mother's Name</div><div class="sep">:</div><div class="val"><?php echo rcv($student['mother_name']); ?></div></div>
                <div class="row-line"><div class="lbl">Date of Birth</div><div class="sep">:</div><div class="val"><?php echo $student['dob'] ? rcv(date('d/m/Y', strtotime($student['dob']))) : ''; ?></div></div>
                <?php if ($RC_MEDIUM): ?>
                <div class="row-line"><div class="lbl">Medium</div><div class="sep">:</div><div class="val"><?php echo rcv($student['medium_name'] ?? '—'); ?></div></div>
                <?php endif; ?>
                <div class="row-line"><div class="lbl">Class</div><div class="sep">:</div><div class="val"><?php echo rcv($student['class_name']); ?>(<?php echo $student['group_name'] ? rcv($student['group_name']) : 'Combined'; ?>)</div></div>
                <div class="row-line"><div class="lbl">Section</div><div class="sep">:</div><div class="val"><?php echo rcv($student['section_name']); ?> (<?php echo rcv($student['shift_name']); ?> Shift)</div></div>
                <?php if ($RC_ROLL): ?>
                <div class="row-line"><div class="lbl">Roll No.</div><div class="sep">:</div><div class="val"><?php echo rcv($student['roll_no']); ?></div></div>
                <?php endif; ?>
            </div>

            <table class="rc-subj-table">
                <thead>
                    <tr>
                        <th style="width:50px;">Sl.</th>
                        <th>Name of Subjects</th>
                        <?php if ($rc_comp_r): foreach ($rc_comp_r['order'] as $rc_k): ?>
                        <th style="width:90px;"><?php echo rcv($rc_comp_r['labels'][$rc_k]); ?><br><small>(<?php echo rcv(rtrim(rtrim(number_format((float) ($rc_comp_w[$rc_k] ?? 0), 2), '0'), '.')); ?>)</small></th>
                        <?php endforeach; endif; ?>
                        <th style="width:120px;">Marks Obtained</th>
                        <?php if ($RC_SUBJ_GL): ?><th style="width:110px;">Letter Grade</th><?php endif; ?>
                        <?php if ($RC_SUBJ_GP): ?><th style="width:100px;">Grade Point (GP)</th><?php endif; ?>
                        <?php if ($RC_FINAL_GL_GP): ?>
                        <th style="width:130px;">GPA<br><small>(without Additional Subject)</small></th>
                        <th style="width:130px;">Grade Point Average (GPA)</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $rc_gi = 0; $rc_n = count($subject_rows); foreach ($subject_rows as $sr): ?>
                        <tr>
                            <td><?php echo (int) $sr['sl']; ?></td>
                            <td><?php echo rcv($sr['name']); ?></td>
                            <?php if ($rc_comp_r): foreach ($rc_comp_r['order'] as $rc_k): $rc_v = $rc_cm[$sr['subject_id']][$rc_k] ?? null; ?>
                            <td><?php echo $rc_v === null ? '-' : rcv(rtrim(rtrim(number_format((float) $rc_v, 2), '0'), '.')); ?></td>
                            <?php endforeach; endif; ?>
                            <td><?php echo rcv(rtrim(rtrim(number_format($sr['obtained'], 2), '0'), '.')); ?> / <?php echo rcv(rtrim(rtrim(number_format($sr['full'], 2), '0'), '.')); ?></td>
                            <?php if ($RC_SUBJ_GL): ?>
                            <td class="<?php echo $sr['fail'] ? 'rc-subj-fail' : 'rc-subj-pass'; ?>"><?php echo $sr['fail'] ? 'F' : rcv($sr['gl']); ?></td>
                            <?php endif; ?>
                            <?php if ($RC_SUBJ_GP): ?>
                            <td><?php echo number_format($sr['gp'], 2); ?></td>
                            <?php endif; ?>
                            <?php if ($RC_FINAL_GL_GP && $rc_gi === 0): // মোট GPA একবারই — সব সারি জুড়ে একটা মার্জ করা ঘরে ?>
                            <td rowspan="<?php echo max(1, $rc_n); ?>" style="vertical-align:middle;font-weight:700;font-size:16px;"><?php echo $overall_fail ? '0.00' : number_format($overall_gpa, 2); ?></td>
                            <td rowspan="<?php echo max(1, $rc_n); ?>" style="vertical-align:middle;font-weight:700;font-size:16px;"><?php echo $overall_fail ? '0.00' : number_format($overall_gpa, 2); ?><div style="font-size:12px;font-weight:600;"><?php echo $overall_fail ? 'F' : rcv($overall_gl); ?></div></td>
                            <?php endif; ?>
                        </tr>
                    <?php $rc_gi++; endforeach; ?>
                </tbody>
            </table>
            <?php include __DIR__ . '/partials/report_card_arabic_block.php'; ?>

            <div class="rc-extra-row">
                <?php if ($RC_PART_PERCENT): ?>
                    <div class="rc-extra-item"><b>Marks Obtained</b><?php echo rcv(number_format($obtained_total, 2)); ?> / <?php echo rcv(number_format($full_total, 2)); ?> (<?php echo rcv(number_format($percentage, 2)); ?>%)</div>
                <?php endif; ?>
                <?php if ($RC_FINAL_GL_GP): ?>
                    <div class="rc-extra-item"><b>Total GPA</b><?php echo $overall_fail ? '0.00 (F)' : rcv(number_format($overall_gpa, 2)) . ' (' . rcv($overall_gl) . ')'; ?></div>
                <?php endif; ?>
                <?php if ($RC_FAIL_COUNT): ?>
                    <div class="rc-extra-item"><b>Total Failed Subject(s)</b><?php echo (int) $fail_subject_count; ?></div>
                <?php endif; ?>
                <?php if ($RC_ATTEND): ?>
                    <div class="rc-extra-item">
                        <?php if ($RC_SHOW_ABSENT): ?>
                            <b>Working Days / Present / Absent</b>
                            <?php echo (int) $working_days; ?> / <?php echo (int) $present_days; ?> / <?php echo (int) $absent_days; ?>
                        <?php else: ?>
                            <b>Working Days / Present</b>
                            <?php echo (int) $working_days; ?> / <?php echo (int) $present_days; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($RC_DISCIPLINE): ?>
                <div class="rc-blank-box"><b>Discipline & Cleanliness:</b> ______________________</div>
            <?php endif; ?>

            <?php if ($RC_INTERPRET): ?>
                <div class="rc-blank-box"><b>Interpretation:</b> ______________________________________________</div>
            <?php endif; ?>

            <?php if ($RC_REMARKS): ?>
                <?php
                    // Grade Points সেটিংসে অ্যাডমিন এই Grade Letter-এর জন্য নিজের Remarks লিখে
                    // রাখলে (Result Module > Grade Points > Remarks কলাম) সেটাই এখানে অটো
                    // ব্যবহার হয় — না লিখে থাকলে আগের মতোই % ভিত্তিক ডিফল্ট দেখাবে।
                    if ($overall_remarks !== '') {
                        $remark_text = $overall_remarks;
                    } else {
                        $remark_text = 'Needs Improvement';
                        if (!$overall_fail) {
                            if ($percentage >= 80) { $remark_text = 'Excellent'; }
                            elseif ($percentage >= 70) { $remark_text = 'Very Good'; }
                            elseif ($percentage >= 60) { $remark_text = 'Good'; }
                            elseif ($percentage >= 50) { $remark_text = 'Satisfactory'; }
                            else { $remark_text = 'Fair'; }
                        }
                    }
                ?>
                <div class="rc-remarks <?php echo $overall_fail ? 'rc-fail' : 'rc-pass'; ?>">
                    <b>Result:</b> <?php echo $overall_fail ? 'Fail' : 'Pass'; ?> &nbsp; | &nbsp; <b>Remarks:</b> <?php echo rcv($remark_text); ?>
                </div>
            <?php endif; ?>

            <?php if ($RC_PASS_FAIL_SUM && $show_merit && isset($class_total_count)): ?>
                <div class="rc-summary-box">
                    <div>Total Students: <b><?php echo (int) $class_total_count; ?></b></div>
                    <div>Passed: <b><?php echo (int) $class_pass_count; ?></b></div>
                    <div>Failed: <b><?php echo (int) $class_fail_count; ?></b></div>
                </div>
            <?php endif; ?>

            <?php if ($show_merit && $class_position !== null): ?>
                <p class="rc-position">Position in Class : <?php echo (int) $class_position; ?></p>
            <?php endif; ?>

            <div class="rc-signatures">
                <div><?php echo rcv($rc_settings['left_signature'] ?? 'Signature of Teacher'); ?></div>
                <div><?php echo rcv($rc_settings['middle_signature'] ?? 'Signature of Coordinator'); ?></div>
                <div><?php echo rcv($rc_settings['right_signature'] ?? 'Signature of Headmaster'); ?></div>
            </div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>


        <script>
        (function () {
            var PX = 96 / 25.4, availH = (297 - 16) * PX - 6;
            function fit() {
                document.body.classList.add('rc-printing');
                document.querySelectorAll('.rc-wrap').forEach(function (w) {
                    w.style.zoom = 1; w.style.width = '';
                    var h = w.scrollHeight;
                    if (h > availH) {
                        var z = Math.max(0.5, availH / h);
                        w.style.zoom = z;
                        w.style.width = (100 / z) + '%';
                    }
                });
            }
            function reset() {
                document.body.classList.remove('rc-printing');
                document.querySelectorAll('.rc-wrap').forEach(function (w) { w.style.zoom = ''; w.style.width = ''; });
            }
            window.addEventListener('beforeprint', fit);
            window.addEventListener('afterprint', reset);
        })();
        </script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
            window.addEventListener('load', function () {
                if (!window.QRCode) { return; }
                var qrData = <?php echo json_encode(array_map(fn($r) => (string) $r['student']['student_id'], $reports)); ?>;
                qrData.forEach(function (val, idx) {
                    var el = document.getElementById('rcQrBox_' + idx);
                    if (el) { new QRCode(el, { text: val, width: 70, height: 70 }); }
                });
            });
        </script>
    <?php endif; ?>
<?php endif; ?>


<?php if (!$RC_STUDENT_VIEW): ?>
<script>
    function filterRcDependents() {
        const classId = document.getElementById('rcClassSelect').value;
        const shiftId = document.getElementById('rcShiftSelect').value;
        const sectionId = document.getElementById('rcSectionSelect') ? document.getElementById('rcSectionSelect').value : '';

        const grpSelect = document.getElementById('rcGroupSelect');
        Array.from(grpSelect.options).forEach(opt => {
            if (!opt.value || opt.value === '0') { opt.hidden = false; return; }
            opt.hidden = !!classId && opt.dataset.class !== classId;
        });
        if (grpSelect.value && Array.from(grpSelect.options).find(o => o.value === grpSelect.value)?.hidden) {
            grpSelect.value = '0';
        }

        const secSelect = document.getElementById('rcSectionSelect');
        secSelect.disabled = !classId;
        Array.from(secSelect.options).forEach(opt => {
            if (!opt.value) { opt.hidden = false; return; }
            const classOk = !!classId && opt.dataset.class === classId;
            const shiftOk = !shiftId || opt.dataset.shift === shiftId;
            opt.hidden = !(classOk && shiftOk);
        });
        secSelect.options[0].textContent = classId ? 'Select Section' : 'Select Class first';
        if (secSelect.value && Array.from(secSelect.options).find(o => o.value === secSelect.value)?.hidden) {
            secSelect.value = '';
        }

        const stuSelect = document.getElementById('rcStudentSelect');
        Array.from(stuSelect.options).forEach(opt => {
            if (!opt.value) { opt.hidden = false; return; }
            const classOk = !classId || opt.dataset.class === classId;
            const shiftOk = !shiftId || opt.dataset.shift === shiftId;
            const secOk = !sectionId || opt.dataset.section === sectionId;
            opt.hidden = !(classOk && shiftOk && secOk);
        });
    }
    filterRcDependents();
</script>
<?php endif; ?>
