<?php
// academic_result.php - পাবলিক পেজ: মেনুবার "ফলাফল > একাডেমিক ফলাফল"
// Branch/Session/Class/Shift/Section/Term/Part সিলেক্ট করে Search করলে:
//   - Roll No / Student ID দেওয়া থাকলে বা লিস্ট থেকে "View Result" ক্লিক করলে -> সরাসরি ঐ স্টুডেন্টের ট্রান্সক্রিপ্ট
//   - না দিলে -> ঐ ক্লাস/শিফট/সেকশনের সব স্টুডেন্টের মেরিট লিস্ট (Pass/Fail, GPA)
// শুধুমাত্র যে Exam Term-এর "Website Result Publish" টগল অন করা আছে (Result Module >
// Exam Settings > Term Publish Settings পেজ থেকে), সেই টার্মের ফলাফলই এখানে দেখানো হবে।
require_once __DIR__ . '/config/runtime.php';
erp_session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/subject_rank.php'; subject_rank_ensure($pdo);
require_once __DIR__ . '/config/lang.php';

// রেজাল্ট মডিউলের টেবিলগুলো (exam_terms, exam_parts ইত্যাদি) শুধু তখনই তৈরি হয় যখন
// অ্যাডমিন প্যানেলে সংশ্লিষ্ট পেজ (Result Module > Exam Settings) অন্তত একবার ওপেন করা হয়েছে।
// এখনো না করা থাকলে যেন পাবলিক পেজ ৫০০ এরর না দিয়ে বন্ধুত্বপূর্ণ মেসেজ দেখায়।
$setup_incomplete = false;
try {
    $inst = $pdo->query("SELECT institute_name, logo_path, phone_number, eiin_no FROM institute_settings WHERE id = 1")->fetch();
    if (!$inst) { $inst = ['institute_name' => 'Your School Name', 'logo_path' => '', 'phone_number' => '', 'eiin_no' => '']; }
    $branches = $pdo->query("SELECT id, branch_name, address, city, zip_code, phone FROM branches WHERE status = 'Active' ORDER BY branch_name ASC")->fetchAll();

    $sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY session_name DESC")->fetchAll();
    $classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
    $shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
    $sections = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
    // পাবলিক পেজে শুধু Publish করা টার্ম দেখানো হবে
    $terms = $pdo->query("SELECT id, term_name, session_id FROM exam_terms WHERE status = 'Active' AND website_result_publish = 1 ORDER BY rank ASC")->fetchAll();
    $parts = $pdo->query("SELECT id, part_name, session_id FROM exam_parts WHERE status = 'Active' ORDER BY rank ASC")->fetchAll();
} catch (PDOException $e) {
    error_log('academic_result.php setup check failed: ' . $e->getMessage());
    $setup_incomplete = true;
    $inst = ['institute_name' => 'Your School Name', 'logo_path' => '', 'phone_number' => '', 'eiin_no' => ''];
    $branches = $sessions = $classes = $shifts = $sections = $terms = $parts = [];
}

function arv($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// একজন স্টুডেন্টের সাবজেক্ট-ওয়াইজ মার্কস থেকে GPA/Grade/Pass-Fail বের করা
// (admin/result_students_report_card.php এর লজিক অনুসরণ করে বানানো)
// -----------------------------------------------------------
function calc_student_result(PDO $pdo, array $student, int $term_id, int $part_id, int $session_id): array
{
    $subj_stmt = $pdo->prepare(
        "SELECT eas.subject_id, eas.full_mark_exam, eas.pass_mark_exam, eas.pass_individual, eas.fail_on_absent, asub.subject_name
         FROM exam_assign_subjects eas
         LEFT JOIN academic_subjects asub ON asub.id = eas.subject_id
         WHERE eas.class_id = ? AND eas.term_id = ? AND eas.part_id = ? AND eas.status = 'Active'
         ORDER BY asub.sort_order ASC, asub.id ASC"
    );
    $subj_stmt->execute([(int) $student['class_id'], $term_id, $part_id]);
    $subject_cols = $subj_stmt->fetchAll();
    // ধর্মভিত্তিক Subject (ইসলাম/হিন্দু ধর্ম শিক্ষা ইত্যাদি): ভিন্ন ধর্মের শিক্ষার্থীর জন্য বাদ — GPA ভাগও সেই অনুযায়ী
    require_once __DIR__ . '/config/subject_religion.php';
    if ($subject_cols) {
        $sr_map = sr_subject_map($pdo, array_column($subject_cols, 'subject_id'));
        $stu_r = (string) ($student['religion'] ?? '');
        $subject_cols = array_values(array_filter($subject_cols, function ($c) use ($sr_map, $stu_r) {
            return sr_applicable($sr_map[(int) $c['subject_id']] ?? '', $stu_r);
        }));
    }

    $grade_stmt = $pdo->prepare("SELECT grade_letter, gpa, mark_from, mark_to FROM exam_grade_points WHERE session_id = ? ORDER BY mark_from DESC");
    $grade_stmt->execute([$session_id]);
    $grade_bands = $grade_stmt->fetchAll();
    $bands_by_gpa = $grade_bands;
    usort($bands_by_gpa, fn($a, $b) => (float) $b['gpa'] <=> (float) $a['gpa']);

    $result = ['gpa' => 0.00, 'gl' => 'F', 'fail' => true, 'subject_rows' => [], 'grade_bands' => $grade_bands, 'has_subjects' => (bool) $subject_cols];
    if (!$subject_cols) return $result;

    $subject_ids = array_column($subject_cols, 'subject_id');
    $in_subj = implode(',', array_fill(0, count($subject_ids), '?'));

    $mk = $pdo->prepare("SELECT subject_id, marks_obtained FROM exam_marks WHERE term_id = ? AND part_id = ? AND subject_id IN ($in_subj) AND student_id = ?");
    $mk->execute(array_merge([$term_id, $part_id], $subject_ids, [$student['id']]));
    $marks_map = [];
    foreach ($mk->fetchAll() as $r) { $marks_map[$r['subject_id']] = $r['marks_obtained']; }

    $at = $pdo->prepare("SELECT subject_id, status FROM exam_attendance_subject WHERE term_id = ? AND part_id = ? AND subject_id IN ($in_subj) AND student_id = ?");
    $at->execute(array_merge([$term_id, $part_id], $subject_ids, [$student['id']]));
    $att_map = [];
    foreach ($at->fetchAll() as $r) { $att_map[$r['subject_id']] = $r['status']; }

    $any_fail = false;
    $gp_sum = 0.0;
    $srl = 1;
    foreach ($subject_cols as $sc) {
        $subj_id = $sc['subject_id'];
        $mark = $marks_map[$subj_id] ?? null;
        $att_status = $att_map[$subj_id] ?? 'Present';
        $absent = ($att_status === 'Absent');

        $subj_fail = false;
        if ($absent && (int) $sc['fail_on_absent'] === 1) {
            $subj_fail = true;
        } elseif ($mark === null) {
            $subj_fail = true;
        } elseif ((int) $sc['pass_individual'] === 1 && (float) $mark < (float) $sc['pass_mark_exam']) {
            $subj_fail = true;
        }

        $mark_val = $absent ? 0 : ($mark !== null ? (float) $mark : 0);
        $subj_percentage = ((float) $sc['full_mark_exam']) > 0 ? ($mark_val / (float) $sc['full_mark_exam']) * 100 : 0;
        $subj_gl = 'F'; $subj_gp = 0.00;
        if (!$subj_fail) {
            foreach ($grade_bands as $band) {
                if ($subj_percentage >= (float) $band['mark_from'] && $subj_percentage <= (float) $band['mark_to']) {
                    $subj_gl = $band['grade_letter']; $subj_gp = (float) $band['gpa']; break;
                }
            }
            if ($subj_gl === 'F') { $subj_fail = true; }
        }
        if ($subj_fail) { $any_fail = true; }
        $gp_sum += $subj_gp;

        $result['subject_rows'][] = ['sl' => $srl++, 'name' => $sc['subject_name'], 'gl' => $subj_gl, 'gp' => $subj_gp, 'fail' => $subj_fail, 'mark' => $mark_val];
    }

    $count = count($subject_cols);
    $overall_gpa = ($count > 0 && !$any_fail) ? round($gp_sum / $count, 2) : 0.00;
    $overall_gl = 'F';
    if (!$any_fail) {
        foreach ($bands_by_gpa as $band) {
            if ($overall_gpa >= (float) $band['gpa']) { $overall_gl = $band['grade_letter']; break; }
        }
    }
    $result['gpa'] = $overall_gpa;
    $result['gl']  = $any_fail ? 'F' : $overall_gl;
    $result['fail'] = $any_fail;
    return $result;
}

// -----------------------------------------------------------
// GET ফিল্টার + Search
// -----------------------------------------------------------
$f_branch     = (int) ($_GET['branch_id'] ?? 0);
$f_session    = (int) ($_GET['session_id'] ?? 0);
$f_class      = (int) ($_GET['class_id'] ?? 0);
$f_shift      = (int) ($_GET['shift_id'] ?? 0);
$f_section    = (int) ($_GET['section_id'] ?? 0);
$f_term       = (int) ($_GET['term_id'] ?? 0);
$f_part       = (int) ($_GET['part_id'] ?? 0);
$f_roll       = trim($_GET['roll_no'] ?? '');
$f_student_id = (int) ($_GET['student_id'] ?? 0);
$searched     = isset($_GET['search_result']);

$errors = [];
$results_list = [];
$transcript = null;

if ($searched && !$setup_incomplete) {
    try {
    if ($f_branch <= 0)  $errors['branch']  = 'Branch can not be blank';
    if ($f_session <= 0) $errors['session'] = 'Session can not be blank';
    if ($f_class <= 0)   $errors['class']   = 'Class can not be blank';
    if ($f_term <= 0)    $errors['term']    = 'Term can not be blank';

    if (!$errors) {
        $term_check = $pdo->prepare("SELECT id FROM exam_terms WHERE id = ? AND session_id = ? AND website_result_publish = 1 AND status = 'Active'");
        $term_check->execute([$f_term, $f_session]);
        if (!$term_check->fetch()) {
            $errors['term'] = t('ar_term_not_published');
        } else {
            if ($f_student_id > 0) {
                // মেরিট লিস্ট থেকে "View Result" ক্লিক করলে সরাসরি এই স্টুডেন্ট
                $stu_stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND class_id = ? AND status = 'Active' LIMIT 1");
                $stu_stmt->execute([$f_student_id, $f_class]);
                $stu = $stu_stmt->fetch();
                if ($stu) {
                    $transcript = ['student' => $stu, 'calc' => calc_student_result($pdo, $stu, $f_term, $f_part, $f_session)];
                } else {
                    $errors['general'] = t('ar_student_not_found');
                }
            } else {
                $where = ["class_id = ?", "status = 'Active'"];
                $params = [$f_class];
                if ($f_shift > 0)   { $where[] = "shift_id = ?";   $params[] = $f_shift; }
                if ($f_section > 0) { $where[] = "section_id = ?"; $params[] = $f_section; }
                if ($f_roll !== '') { $where[] = "(roll_no = ? OR student_id = ?)"; $params[] = $f_roll; $params[] = $f_roll; }

                $stu_stmt = $pdo->prepare("SELECT * FROM students WHERE " . implode(' AND ', $where) . " ORDER BY (roll_no + 0) ASC, roll_no ASC");
                $stu_stmt->execute($params);
                $students = $stu_stmt->fetchAll();

                if (!$students) {
                    $errors['general'] = t('ar_no_student_found');
                } elseif ($f_roll !== '' && count($students) === 1) {
                    // Roll No / Student ID দিয়ে সরাসরি একজনের ট্রান্সক্রিপ্ট
                    $stu = $students[0];
                    $transcript = ['student' => $stu, 'calc' => calc_student_result($pdo, $stu, $f_term, $f_part, $f_session)];
                } else {
                    foreach ($students as $stu) {
                        $results_list[] = ['student' => $stu, 'calc' => calc_student_result($pdo, $stu, $f_term, $f_part, $f_session)];
                    }
                }
            }
        }
    }
    } catch (PDOException $e) {
        error_log('academic_result.php search failed: ' . $e->getMessage());
        $errors['general'] = t('ar_load_error');
    }
} elseif ($searched && $setup_incomplete) {
    $errors['general'] = t('ar_setup_incomplete');
}

$sel_branch = $branches[0]['id'] ?? 0;
$qs_base = [
    'branch_id' => $f_branch ?: $sel_branch, 'session_id' => $f_session, 'class_id' => $f_class,
    'shift_id' => $f_shift, 'section_id' => $f_section, 'term_id' => $f_term, 'part_id' => $f_part,
];
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo t('academic_result_title'); ?> - <?php echo arv($inst['institute_name']); ?></title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    :root { --accent: #10b981; --navy: #1e3a8a; }
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; background: #f4f6f9; color: #1f2937; }
    .ar-banner { background: #5b6478; color: #fff; text-align: center; padding: 28px 10px; font-size: 24px; font-weight: 700; letter-spacing: 1px; }
    .ar-wrap { max-width: 1100px; margin: -20px auto 40px; padding: 0 15px; }
    .ar-card { background: #fff; border-radius: 8px; box-shadow: 0 4px 18px rgba(0,0,0,0.08); overflow: hidden; }
    .ar-card-head { background: #eef1f6; padding: 14px 20px; font-weight: 700; border-bottom: 1px solid #e2e6ee; }
    .ar-card-body { padding: 24px; }
    .ar-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px 24px; }
    @media (max-width: 700px) { .ar-grid { grid-template-columns: 1fr; } }
    .ar-field label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px; }
    .ar-field label .req { color: #dc2626; }
    .ar-field select, .ar-field input { width: 100%; padding: 9px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
    .ar-field .err { color: #dc2626; font-size: 12.5px; margin-top: 5px; }
    .ar-search-btn-row { text-align: center; margin-top: 26px; }
    .btn-ar-search { background: #14b8c4; color: #fff; border: none; padding: 12px 40px; border-radius: 6px; font-weight: 700; font-size: 15px; cursor: pointer; }
    .btn-ar-search:hover { opacity: 0.92; }
    .ar-alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 18px; font-size: 14px; }
    .ar-alert-danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    table.ar-list { width: 100%; border-collapse: collapse; margin-top: 10px; }
    table.ar-list th, table.ar-list td { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 14px; }
    table.ar-list th { background: #f8fafc; font-weight: 700; }
    .badge-pass { color: #15803d; font-weight: 700; }
    .badge-fail { color: #dc2626; font-weight: 700; }
    .btn-view { background: var(--navy); color: #fff; padding: 6px 14px; border-radius: 5px; text-decoration: none; font-size: 13px; font-weight: 600; }
    .ar-back { display: inline-block; margin-top: 18px; color: var(--navy); text-decoration: none; font-weight: 600; font-size: 14px; }
    /* Transcript */
    .rc-wrap { border: 1px solid #ccc; padding: 20px; background: #fff; }
    .rc-top { display: flex; gap: 20px; align-items: flex-start; border-bottom: 2px solid #333; padding-bottom: 14px; margin-bottom: 14px; }
    .rc-center { flex: 1; text-align: center; }
    .rc-center h4 { margin: 4px 0; }
    .rc-center p { margin: 2px 0; font-size: 13px; }
    .rc-badge { display: inline-block; margin-top: 6px; background: var(--navy); color: #fff; padding: 4px 14px; border-radius: 4px; font-size: 13px; font-weight: 700; }
    .rc-scale table { border-collapse: collapse; font-size: 11.5px; }
    .rc-scale th, .rc-scale td { border: 1px solid #999; padding: 3px 7px; text-align: center; }
    .rc-info { margin-bottom: 14px; }
    .rc-info .row-line { display: flex; gap: 6px; font-size: 14px; padding: 3px 0; }
    .rc-info .lbl { width: 150px; font-weight: 600; }
    table.rc-subj-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.rc-subj-table th, table.rc-subj-table td { border: 1px solid #ccc; padding: 8px; font-size: 13.5px; text-align: center; }
    table.rc-subj-table td:nth-child(2) { text-align: left; }
    .rc-subj-fail { color: #dc2626; font-weight: 700; }
    .rc-subj-pass { color: #15803d; font-weight: 700; }
    .rc-overall { text-align: center; font-size: 16px; font-weight: 700; margin: 16px 0; }
    .no-print button { margin-top: 16px; background: var(--navy); color: #fff; border: none; padding: 10px 26px; border-radius: 6px; font-weight: 700; cursor: pointer; }
    @media print { .top-bar, .header-brand, .navbar, .ar-banner, .no-print, .ar-back { display: none !important; } .ar-wrap { margin: 0; max-width: 100%; } }
</style>
</head>
<body>
    <?php $current_page = 'academic_result'; require __DIR__ . '/site_header.php'; ?>
    <div class="ar-banner"><?php echo t('academic_result_title'); ?></div>

    <div class="ar-wrap">
        <?php if ($transcript): $stu = $transcript['student']; $calc = $transcript['calc'];
            $cls = $pdo->prepare("SELECT class_name FROM academic_classes WHERE id = ?"); $cls->execute([$stu['class_id']]); $class_name = $cls->fetchColumn();
            $shf = $pdo->prepare("SELECT shift_name FROM academic_shifts WHERE id = ?"); $shf->execute([$stu['shift_id']]); $shift_name = $shf->fetchColumn();
            $sec = $pdo->prepare("SELECT section_name FROM academic_sections WHERE id = ?"); $sec->execute([$stu['section_id']]); $section_name = $sec->fetchColumn();
            $trm = $pdo->prepare("SELECT term_name FROM exam_terms WHERE id = ?"); $trm->execute([$f_term]); $term_name = $trm->fetchColumn();
            $sess = $pdo->prepare("SELECT session_name FROM academic_sessions WHERE id = ?"); $sess->execute([$f_session]); $session_name = $sess->fetchColumn();
        ?>
        <div class="ar-card">
            <div class="ar-card-body">
                <div class="no-print" style="text-align:right;"><button onclick="window.print()"><i class="fa-solid fa-print"></i> <?php echo t('ar_print_btn'); ?></button></div>
                <div class="rc-wrap">
                    <div class="rc-top">
                        <div class="rc-center">
                            <?php if (!empty($inst['logo_path'])): ?><img src="<?php echo arv($inst['logo_path']); ?>" style="max-height:55px;"><br><?php endif; ?>
                            <h4><?php echo arv($inst['institute_name']); ?></h4>
                            <div class="rc-badge"><?php echo t('ar_result_badge'); ?> : <?php echo arv($term_name); ?> — <?php echo arv($session_name); ?></div>
                        </div>
                        <div class="rc-scale">
                            <table>
                                <thead><tr><th><?php echo t('ar_grade_th'); ?></th><th>%</th><th><?php echo t('ar_gp_th'); ?></th></tr></thead>
                                <tbody>
                                <?php foreach ($calc['grade_bands'] as $band): ?>
                                    <tr><td><?php echo arv($band['grade_letter']); ?></td><td><?php echo arv(rtrim(rtrim(number_format((float) $band['mark_from'], 2), '0'), '.')); ?>-<?php echo arv(rtrim(rtrim(number_format((float) $band['mark_to'], 2), '0'), '.')); ?></td><td><?php echo arv(rtrim(rtrim(number_format((float) $band['gpa'], 2), '0'), '.')); ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="rc-info">
                        <div class="row-line"><div class="lbl"><?php echo t('ar_name_lbl'); ?></div>:&nbsp;<?php echo arv($stu['name_en']); ?></div>
                        <div class="row-line"><div class="lbl"><?php echo t('ar_father_name_lbl'); ?></div>:&nbsp;<?php echo arv($stu['father_name']); ?></div>
                        <div class="row-line"><div class="lbl"><?php echo t('ar_class_lbl'); ?></div>:&nbsp;<?php echo arv($class_name); ?> (<?php echo arv($section_name); ?> — <?php echo arv($shift_name); ?> <?php echo t('ar_shift_word'); ?>)</div>
                        <div class="row-line"><div class="lbl"><?php echo t('ar_roll_id_lbl'); ?></div>:&nbsp;<?php echo arv($stu['roll_no']); ?> / <?php echo arv($stu['student_id']); ?></div>
                    </div>
                    <?php if ($calc['has_subjects']): ?>
                    <table class="rc-subj-table">
                        <thead><tr><th><?php echo t('ar_sl_th'); ?></th><th><?php echo t('ar_subject_th'); ?></th><th><?php echo t('ar_grade_th'); ?></th><th><?php echo t('ar_gp_th'); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($calc['subject_rows'] as $sr): ?>
                            <tr>
                                <td><?php echo (int) $sr['sl']; ?></td>
                                <td><?php echo arv($sr['name']); ?></td>
                                <td class="<?php echo $sr['fail'] ? 'rc-subj-fail' : 'rc-subj-pass'; ?>"><?php echo $sr['fail'] ? 'F' : arv($sr['gl']); ?></td>
                                <td><?php echo number_format($sr['gp'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="rc-overall">
                        <?php echo t('ar_result_lbl'); ?>: <span class="<?php echo $calc['fail'] ? 'badge-fail' : 'badge-pass'; ?>"><?php echo $calc['fail'] ? t('ar_fail') : t('ar_pass'); ?></span>
                        &nbsp;&nbsp; GPA: <?php echo $calc['fail'] ? '0.00' : number_format($calc['gpa'], 2); ?>
                        &nbsp;&nbsp; <?php echo t('ar_grade_th'); ?>: <?php echo arv($calc['gl']); ?>
                    </div>
                    <?php else: ?>
                        <p style="text-align:center;color:#b91c1c;"><?php echo t('ar_no_subjects'); ?></p>
                    <?php endif; ?>
                </div>
                <a class="ar-back no-print" href="academic_result.php">← <?php echo t('ar_search_again'); ?></a>
            </div>
        </div>
        <?php else: ?>

        <?php if ($setup_incomplete): ?>
        <div class="ar-card">
            <div class="ar-card-body">
                <div class="ar-alert ar-alert-danger"><?php echo t('ar_setup_msg'); ?></div>
            </div>
        </div>
        <a class="ar-back" href="index.php"><i class="fa-solid fa-arrow-left"></i> <?php echo t('ar_back_home'); ?></a>
        <?php else: ?>

        <div class="ar-card">
            <div class="ar-card-head"><i class="fa-solid fa-magnifying-glass"></i> <?php echo t('ar_search_for_result'); ?></div>
            <div class="ar-card-body">
                <?php if (!empty($errors['general'])): ?><div class="ar-alert ar-alert-danger"><?php echo arv($errors['general']); ?></div><?php endif; ?>
                <form method="GET" action="academic_result.php" id="arForm">
                    <input type="hidden" name="search_result" value="1">
                    <div class="ar-grid">
                        <div class="ar-field">
                            <label><?php echo t('af_branch'); ?> <span class="req">*</span></label>
                            <select name="branch_id">
                                <option value=""><?php echo t('af_select_branch'); ?></option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo (int) $b['id']; ?>" <?php echo $f_branch === (int) $b['id'] ? 'selected' : ''; ?>><?php echo arv($b['branch_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!empty($errors['branch'])): ?><div class="err"><?php echo arv($errors['branch']); ?></div><?php endif; ?>
                        </div>
                        <div class="ar-field">
                            <label><?php echo t('af_session'); ?> <span class="req">*</span></label>
                            <select name="session_id" id="arSession" onchange="filterArDependents()">
                                <option value=""><?php echo t('af_select_session'); ?></option>
                                <?php foreach ($sessions as $s): ?>
                                    <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>><?php echo arv($s['session_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!empty($errors['session'])): ?><div class="err"><?php echo arv($errors['session']); ?></div><?php endif; ?>
                        </div>
                        <div class="ar-field">
                            <label><?php echo t('af_class'); ?> <span class="req">*</span></label>
                            <select name="class_id">
                                <option value=""><?php echo t('af_select_class'); ?></option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo arv($c['class_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!empty($errors['class'])): ?><div class="err"><?php echo arv($errors['class']); ?></div><?php endif; ?>
                        </div>
                        <div class="ar-field">
                            <label><?php echo t('af_shift'); ?></label>
                            <select name="shift_id">
                                <option value=""><?php echo t('af_select_shift'); ?></option>
                                <?php foreach ($shifts as $sh): ?>
                                    <option value="<?php echo (int) $sh['id']; ?>" <?php echo $f_shift === (int) $sh['id'] ? 'selected' : ''; ?>><?php echo arv($sh['shift_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ar-field">
                            <label><?php echo t('af_section'); ?></label>
                            <select name="section_id">
                                <option value=""><?php echo t('af_select_section'); ?></option>
                                <?php foreach ($sections as $sc): ?>
                                    <option value="<?php echo (int) $sc['id']; ?>" data-class="<?php echo (int) $sc['class_id']; ?>" <?php echo $f_section === (int) $sc['id'] ? 'selected' : ''; ?>><?php echo arv($sc['section_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ar-field">
                            <label><?php echo t('ar_term_lbl'); ?> <span class="req">*</span></label>
                            <select name="term_id" id="arTerm">
                                <option value=""><?php echo t('ar_select_exam_term'); ?></option>
                                <?php foreach ($terms as $t): ?>
                                    <option value="<?php echo (int) $t['id']; ?>" data-session="<?php echo (int) $t['session_id']; ?>" <?php echo $f_term === (int) $t['id'] ? 'selected' : ''; ?>><?php echo arv($t['term_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!empty($errors['term'])): ?><div class="err"><?php echo arv($errors['term']); ?></div><?php endif; ?>
                        </div>
                        <div class="ar-field">
                            <label><?php echo t('ar_part_lbl'); ?></label>
                            <select name="part_id" id="arPart">
                                <option value=""><?php echo t('ar_select_exam_part'); ?></option>
                                <?php foreach ($parts as $p): ?>
                                    <option value="<?php echo (int) $p['id']; ?>" data-session="<?php echo (int) $p['session_id']; ?>" <?php echo $f_part === (int) $p['id'] ? 'selected' : ''; ?>><?php echo arv($p['part_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ar-field">
                            <label><?php echo t('ar_roll_id_lbl'); ?> <span style="font-weight:400;color:#888;">(<?php echo t('ar_roll_id_hint'); ?>)</span></label>
                            <input type="text" name="roll_no" value="<?php echo arv($f_roll); ?>" placeholder="<?php echo t('ar_roll_id_ph'); ?>">
                        </div>
                    </div>
                    <div class="ar-search-btn-row">
                        <button type="submit" class="btn-ar-search"><i class="fa-solid fa-magnifying-glass"></i> <?php echo t('ar_search_btn'); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($searched && $results_list): ?>
        <div class="ar-card" style="margin-top:22px;">
            <div class="ar-card-head"><?php echo t('ar_result_list_title'); ?> (<?php echo count($results_list); ?> <?php echo t('ar_students_word'); ?>)</div>
            <div class="ar-card-body" style="overflow-x:auto;">
                <table class="ar-list">
                    <thead><tr><th><?php echo t('ar_roll_no_th'); ?></th><th><?php echo t('ar_name_lbl'); ?></th><th>GPA</th><th><?php echo t('ar_result_lbl'); ?></th><th><?php echo t('ar_action_th'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($results_list as $row): $s = $row['student']; $c = $row['calc']; ?>
                        <tr>
                            <td><?php echo arv($s['roll_no']); ?></td>
                            <td><?php echo arv($s['name_en']); ?></td>
                            <td><?php echo $c['fail'] ? '0.00' : number_format($c['gpa'], 2); ?></td>
                            <td><span class="<?php echo $c['fail'] ? 'badge-fail' : 'badge-pass'; ?>"><?php echo $c['fail'] ? t('ar_fail') : t('ar_pass'); ?></span></td>
                            <td><a class="btn-view" href="academic_result.php?<?php echo http_build_query(array_merge($qs_base, ['student_id' => (int) $s['id'], 'search_result' => 1])); ?>"><?php echo t('ar_view_result_btn'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <a class="ar-back" href="index.php"><i class="fa-solid fa-arrow-left"></i> <?php echo t('ar_back_home'); ?></a>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        function filterArDependents() {
            const sid = document.getElementById('arSession').value;
            [document.getElementById('arTerm'), document.getElementById('arPart')].forEach(sel => {
                Array.from(sel.options).forEach(opt => {
                    if (!opt.value) { opt.hidden = false; return; }
                    opt.hidden = sid && opt.dataset.session !== sid;
                });
            });
        }
        filterArDependents();
    </script>
<?php if (isset($pdo)) { include __DIR__ . '/config/class_scope_filter.php'; } // Class সিলেক্ট করলে শুধু ওই Class-এর Shift/Section ?>
</body>
</html>
