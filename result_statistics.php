<?php
// result_statistics.php - পাবলিক পেজ: মেনুবার "ফলাফল > Result Statistics"
// Session + Term (+ ঐচ্ছিক Class) সিলেক্ট করলে সেই টার্মের সব স্টুডেন্টের ফলাফল থেকে
// Pass Rate ও Grade-wise distribution (কতজন A+, A, B... পেয়েছে) দেখায়।
// শুধু "Website Result Publish" অন করা টার্মের পরিসংখ্যান পাবলিকলি দেখানো হয়।
require_once __DIR__ . '/config/runtime.php';
erp_session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/lang.php';

$inst = $pdo->query("SELECT institute_name, logo_path FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) { $inst = ['institute_name' => 'Your School Name', 'logo_path' => '']; }

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY session_name DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$terms    = $pdo->query("SELECT id, term_name, session_id FROM exam_terms WHERE status = 'Active' AND website_result_publish = 1 ORDER BY rank ASC")->fetchAll();
$parts    = $pdo->query("SELECT id, part_name, session_id FROM exam_parts WHERE status = 'Active' ORDER BY rank ASC")->fetchAll();

function stv($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }

// admin/result_students_report_card.php এর মতোই GPA/Grade বের করা (সংক্ষিপ্ত সংস্করণ, শুধু pass/fail + gpa দরকার)
function calc_student_stat(PDO $pdo, array $student, int $term_id, int $part_id, array $grade_bands, array $bands_by_gpa): array
{
    $subj_stmt = $pdo->prepare(
        "SELECT eas.subject_id, eas.full_mark_exam, eas.pass_mark_exam, eas.pass_individual, eas.fail_on_absent
         FROM exam_assign_subjects eas
         WHERE eas.class_id = ? AND eas.term_id = ? AND eas.part_id = ? AND eas.status = 'Active'"
    );
    $subj_stmt->execute([(int) $student['class_id'], $term_id, $part_id]);
    $subject_cols = $subj_stmt->fetchAll();
    if (!$subject_cols) return ['gpa' => 0.00, 'gl' => null, 'fail' => true, 'counted' => false];

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
    foreach ($subject_cols as $sc) {
        $subj_id = $sc['subject_id'];
        $mark = $marks_map[$subj_id] ?? null;
        $att_status = $att_map[$subj_id] ?? 'Present';
        $absent = ($att_status === 'Absent');

        $subj_fail = false;
        if ($absent && (int) $sc['fail_on_absent'] === 1) { $subj_fail = true; }
        elseif ($mark === null) { $subj_fail = true; }
        elseif ((int) $sc['pass_individual'] === 1 && (float) $mark < (float) $sc['pass_mark_exam']) { $subj_fail = true; }

        $mark_val = $absent ? 0 : ($mark !== null ? (float) $mark : 0);
        $subj_percentage = ((float) $sc['full_mark_exam']) > 0 ? ($mark_val / (float) $sc['full_mark_exam']) * 100 : 0;
        $subj_gp = 0.00; $found = false;
        if (!$subj_fail) {
            foreach ($grade_bands as $band) {
                if ($subj_percentage >= (float) $band['mark_from'] && $subj_percentage <= (float) $band['mark_to']) {
                    $subj_gp = (float) $band['gpa']; $found = true; break;
                }
            }
            if (!$found) { $subj_fail = true; }
        }
        if ($subj_fail) { $any_fail = true; }
        $gp_sum += $subj_gp;
    }

    $count = count($subject_cols);
    $overall_gpa = ($count > 0 && !$any_fail) ? round($gp_sum / $count, 2) : 0.00;
    $overall_gl = null;
    if (!$any_fail) {
        foreach ($bands_by_gpa as $band) {
            if ($overall_gpa >= (float) $band['gpa']) { $overall_gl = $band['grade_letter']; break; }
        }
    }
    return ['gpa' => $overall_gpa, 'gl' => $any_fail ? 'F' : $overall_gl, 'fail' => $any_fail, 'counted' => true];
}

$f_session = (int) ($_GET['session_id'] ?? 0);
$f_class   = (int) ($_GET['class_id'] ?? 0);
$f_term    = (int) ($_GET['term_id'] ?? 0);
$f_part    = (int) ($_GET['part_id'] ?? 0);
$searched  = isset($_GET['search_result']);

$errors = [];
$stats = null;

if ($searched) {
    if ($f_session <= 0) $errors['session'] = t('rs_select_session_err');
    if ($f_term <= 0)    $errors['term']    = t('rs_select_term_err');

    if (!$errors) {
        $term_check = $pdo->prepare("SELECT id FROM exam_terms WHERE id = ? AND session_id = ? AND website_result_publish = 1 AND status = 'Active'");
        $term_check->execute([$f_term, $f_session]);
        if (!$term_check->fetch()) {
            $errors['term'] = t('ar_term_not_published');
        } else {
            $where = ["status = 'Active'"];
            $params = [];
            if ($f_class > 0) { $where[] = "class_id = ?"; $params[] = $f_class; }
            $stu_stmt = $pdo->prepare("SELECT * FROM students WHERE " . implode(' AND ', $where));
            $stu_stmt->execute($params);
            $students = $stu_stmt->fetchAll();

            $grade_stmt = $pdo->prepare("SELECT grade_letter, gpa, mark_from, mark_to FROM exam_grade_points WHERE session_id = ? ORDER BY mark_from DESC");
            $grade_stmt->execute([$f_session]);
            $grade_bands = $grade_stmt->fetchAll();
            $bands_by_gpa = $grade_bands;
            usort($bands_by_gpa, fn($a, $b) => (float) $b['gpa'] <=> (float) $a['gpa']);

            $appeared = 0; $passed = 0; $failed = 0;
            $grade_counts = [];
            foreach ($grade_bands as $band) { $grade_counts[$band['grade_letter']] = 0; }
            $grade_counts['F'] = 0;

            foreach ($students as $stu) {
                $r = calc_student_stat($pdo, $stu, $f_term, $f_part, $grade_bands, $bands_by_gpa);
                if (!$r['counted']) continue; // এই ক্লাসে সাবজেক্ট বরাদ্দই করা হয়নি
                $appeared++;
                if ($r['fail']) { $failed++; $grade_counts['F'] = ($grade_counts['F'] ?? 0) + 1; }
                else { $passed++; $grade_counts[$r['gl']] = ($grade_counts[$r['gl']] ?? 0) + 1; }
            }

            $pass_rate = $appeared > 0 ? round(($passed / $appeared) * 100, 2) : 0;
            $max_count = max(array_merge([1], array_values($grade_counts)));

            $stats = compact('appeared', 'passed', 'failed', 'pass_rate', 'grade_counts', 'max_count');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo t('result_statistics_title'); ?> - <?php echo stv($inst['institute_name']); ?></title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    :root { --accent: #10b981; --navy: #1e3a8a; }
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; background: #f4f6f9; color: #1f2937; }
    .rs-banner { background: #5b6478; color: #fff; text-align: center; padding: 28px 10px; font-size: 24px; font-weight: 700; letter-spacing: 1px; }
    .rs-wrap { max-width: 950px; margin: -20px auto 40px; padding: 0 15px; }
    .rs-card { background: #fff; border-radius: 8px; box-shadow: 0 4px 18px rgba(0,0,0,0.08); overflow: hidden; margin-bottom: 22px; }
    .rs-card-head { background: #eef1f6; padding: 14px 20px; font-weight: 700; border-bottom: 1px solid #e2e6ee; }
    .rs-card-body { padding: 24px; }
    .rs-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px 24px; }
    @media (max-width: 700px) { .rs-grid { grid-template-columns: 1fr; } }
    .rs-field label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px; }
    .rs-field label .req { color: #dc2626; }
    .rs-field select { width: 100%; padding: 9px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
    .rs-field .err { color: #dc2626; font-size: 12.5px; margin-top: 5px; }
    .rs-search-btn-row { text-align: center; margin-top: 26px; }
    .btn-rs-search { background: #14b8c4; color: #fff; border: none; padding: 12px 40px; border-radius: 6px; font-weight: 700; font-size: 15px; cursor: pointer; }
    .rs-alert-danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 12px 16px; border-radius: 6px; margin-bottom: 18px; font-size: 14px; }
    .rs-summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
    @media (max-width: 700px) { .rs-summary { grid-template-columns: repeat(2, 1fr); } }
    .rs-stat-box { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; text-align: center; }
    .rs-stat-box .num { font-size: 26px; font-weight: 800; color: var(--navy); }
    .rs-stat-box.pass .num { color: #15803d; }
    .rs-stat-box.fail .num { color: #dc2626; }
    .rs-stat-box .lbl { font-size: 13px; color: #6b7280; margin-top: 4px; font-weight: 600; }
    .rs-bar-row { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
    .rs-bar-row .gl { width: 46px; font-weight: 700; }
    .rs-bar-track { flex: 1; background: #f1f5f9; border-radius: 5px; overflow: hidden; height: 22px; }
    .rs-bar-fill { height: 100%; background: linear-gradient(90deg, #10b981, #14b8c4); border-radius: 5px; }
    .rs-bar-fill.f { background: linear-gradient(90deg, #f87171, #dc2626); }
    .rs-bar-count { width: 40px; text-align: right; font-weight: 700; }
    .rs-back { display: inline-block; margin-top: 4px; color: var(--navy); text-decoration: none; font-weight: 600; font-size: 14px; }
    @media print { .top-bar, .header-brand, .navbar, .rs-banner, .rs-back { display: none !important; } .rs-wrap { margin: 0; max-width: 100%; } }
</style>
</head>
<body>
    <?php $current_page = 'result_statistics'; require __DIR__ . '/site_header.php'; ?>
    <div class="rs-banner"><?php echo t('result_statistics_title'); ?></div>

    <div class="rs-wrap">
        <div class="rs-card">
            <div class="rs-card-head"><i class="fa-solid fa-chart-column"></i> <?php echo t('rs_filter_title'); ?></div>
            <div class="rs-card-body">
                <form method="GET" action="result_statistics.php">
                    <input type="hidden" name="search_result" value="1">
                    <div class="rs-grid">
                        <div class="rs-field">
                            <label><?php echo t('af_session'); ?> <span class="req">*</span></label>
                            <select name="session_id" id="rsSession" onchange="filterRsDependents()">
                                <option value=""><?php echo t('af_select_session'); ?></option>
                                <?php foreach ($sessions as $s): ?>
                                    <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>><?php echo stv($s['session_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!empty($errors['session'])): ?><div class="err"><?php echo stv($errors['session']); ?></div><?php endif; ?>
                        </div>
                        <div class="rs-field">
                            <label><?php echo t('af_class'); ?> <span style="font-weight:400;color:#888;">(<?php echo t('rs_class_hint'); ?>)</span></label>
                            <select name="class_id">
                                <option value=""><?php echo t('rs_all_classes'); ?></option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo stv($c['class_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="rs-field">
                            <label><?php echo t('ar_term_lbl'); ?> <span class="req">*</span></label>
                            <select name="term_id" id="rsTerm">
                                <option value=""><?php echo t('ar_select_exam_term'); ?></option>
                                <?php foreach ($terms as $t): ?>
                                    <option value="<?php echo (int) $t['id']; ?>" data-session="<?php echo (int) $t['session_id']; ?>" <?php echo $f_term === (int) $t['id'] ? 'selected' : ''; ?>><?php echo stv($t['term_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!empty($errors['term'])): ?><div class="err"><?php echo stv($errors['term']); ?></div><?php endif; ?>
                        </div>
                        <div class="rs-field">
                            <label><?php echo t('ar_part_lbl'); ?></label>
                            <select name="part_id" id="rsPart">
                                <option value=""><?php echo t('ar_select_exam_part'); ?></option>
                                <?php foreach ($parts as $p): ?>
                                    <option value="<?php echo (int) $p['id']; ?>" data-session="<?php echo (int) $p['session_id']; ?>" <?php echo $f_part === (int) $p['id'] ? 'selected' : ''; ?>><?php echo stv($p['part_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="rs-search-btn-row">
                        <button type="submit" class="btn-rs-search"><i class="fa-solid fa-magnifying-glass"></i> <?php echo t('rs_show_stats_btn'); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($searched && $stats): ?>
            <div class="rs-card">
                <div class="rs-card-body">
                    <div class="rs-summary">
                        <div class="rs-stat-box"><div class="num"><?php echo (int) $stats['appeared']; ?></div><div class="lbl"><?php echo t('rs_appeared'); ?></div></div>
                        <div class="rs-stat-box pass"><div class="num"><?php echo (int) $stats['passed']; ?></div><div class="lbl"><?php echo t('rs_passed'); ?></div></div>
                        <div class="rs-stat-box fail"><div class="num"><?php echo (int) $stats['failed']; ?></div><div class="lbl"><?php echo t('rs_failed'); ?></div></div>
                        <div class="rs-stat-box"><div class="num"><?php echo stv($stats['pass_rate']); ?>%</div><div class="lbl"><?php echo t('rs_pass_rate'); ?></div></div>
                    </div>
                </div>
            </div>

            <?php if ($stats['appeared'] > 0): ?>
            <div class="rs-card">
                <div class="rs-card-head"><?php echo t('rs_grade_distribution'); ?></div>
                <div class="rs-card-body">
                    <?php foreach ($stats['grade_counts'] as $gl => $cnt): ?>
                        <div class="rs-bar-row">
                            <div class="gl"><?php echo stv($gl); ?></div>
                            <div class="rs-bar-track">
                                <div class="rs-bar-fill<?php echo $gl === 'F' ? ' f' : ''; ?>" style="width: <?php echo $stats['max_count'] > 0 ? round(($cnt / $stats['max_count']) * 100) : 0; ?>%;"></div>
                            </div>
                            <div class="rs-bar-count"><?php echo (int) $cnt; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
                <div class="rs-alert-danger"><?php echo t('rs_no_result_found'); ?></div>
            <?php endif; ?>
        <?php endif; ?>

        <a class="rs-back" href="index.php"><i class="fa-solid fa-arrow-left"></i> <?php echo t('ar_back_home'); ?></a>
    </div>

    <script>
        function filterRsDependents() {
            const sid = document.getElementById('rsSession').value;
            [document.getElementById('rsTerm'), document.getElementById('rsPart')].forEach(sel => {
                Array.from(sel.options).forEach(opt => {
                    if (!opt.value) { opt.hidden = false; return; }
                    opt.hidden = sid && opt.dataset.session !== sid;
                });
            });
        }
        filterRsDependents();
    </script>
</body>
</html>
