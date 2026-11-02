<?php
// admin/result_final_aggregated_result.php - Result Module > Result Reports > Final Aggregated Result
// একাধিক Exam Term (যেমন Half Yearly + Annual) মিলিয়ে একটা চূড়ান্ত Yearly রেজাল্ট দেখায়।
// Master Settings > Result Templates Settings > "Aggregate Result Type"-এ যেটা সিলেক্ট করা
// আছে (Terms Wise Percentage / Terms Wise Average / Terms & Subjects Wise Average /
// Terms, Subjects & Part Wise Average) — সেই পদ্ধতি অনুযায়ী হিসাব হয় (config/report_card_helpers.php
// এর rc_aggregate_terms_result() ফাংশন)। Exam Terms পেজে যে Term-গুলোতে "Aggregated Result = Yes"
// সেট করা আছে সেগুলো ডিফল্টভাবে টিক দেওয়া থাকে, কিন্তু চাইলে Admin নিজে যেকোনো কম্বিনেশন সিলেক্ট করতে পারবে।

require_once __DIR__ . '/../config/report_card_helpers.php';

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

function farv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function far_num($n): string { $n = (float) $n; return floor($n) == $n ? number_format($n, 0) : number_format($n, 2); }

$settings = $pdo->query("SELECT aggregate_result_type FROM exam_result_settings WHERE id = 1")->fetch();
$aggregate_type = $settings['aggregate_result_type'] ?? 'Terms Wise Percentage';
// শতকরা ওয়েট মডেল (মডেল ১-৪) সিলেক্ট থাকলে Term-গুলো মডেলের কনফিগ থেকে আসে, চেকবক্স থেকে নয়
$is_pct = rc_pct_is_model($aggregate_type);
$is_avg_model = $is_pct && (rc_pct_models()[$aggregate_type]['type'] ?? '') === 'avg_group';
$agg_display = $is_avg_model ? 'গড় মডেল ১ (মাসিক + টার্ম গড়)' : ($is_pct ? ('শতকরা ' . str_replace('Percentage Model ', 'মডেল ', $aggregate_type)) : $aggregate_type);

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

$institute = $pdo->query("SELECT institute_name, logo_path, phone_number FROM institute_settings WHERE id = 1")->fetch();
$far_bid = branch_current_id();
if ($far_bid !== null) {
    $bs = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $bs->execute([$far_bid]);
    $branch = $bs->fetch();
} else {
    $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
}
$inst_display_name = ($branch['branch_name'] ?? null) ?: ($institute['institute_name'] ?? '');

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
$groups   = $pdo->query("SELECT id, group_name, class_id FROM academic_groups WHERE status = 'Active' ORDER BY group_name ASC")->fetchAll();

$terms = [];
if ($current_session_id) {
    $t = $pdo->prepare("SELECT id, term_name, is_aggregated_result FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
    $t->execute([$current_session_id]);
    $terms = $t->fetchAll();
}

// -----------------------------------------------------------
// GET ফিল্টার
// -----------------------------------------------------------
$searched     = isset($_GET['search_result']);
$f_session_id = (int) ($_GET['session_id'] ?? $current_session_id);
$f_class_id   = (int) ($_GET['class_id'] ?? 0);
$f_group_id   = (int) ($_GET['group_id'] ?? 0);
$f_shift_id   = (int) ($_GET['shift_id'] ?? 0);
$f_section_id = (int) ($_GET['section_id'] ?? 0);
$f_term_ids   = array_values(array_filter(array_map('intval', (array) ($_GET['term_ids'] ?? []))));
$show_merit   = $searched ? isset($_GET['show_merit']) : true;

// টার্ম চেকবক্স কখনো সিলেক্ট না করা থাকলে (প্রথমবার পেজ ওপেন করলে) — Exam Terms পেজে
// "Aggregated Result = Yes" সেট করা Term-গুলো ডিফল্টভাবে টিক দেখানো হয়
if (!$searched) {
    $f_term_ids = array_map('intval', array_column(array_filter($terms, fn($t) => (int) $t['is_aggregated_result'] === 1), 'id'));
}

// শতকরা মডেল: ওয়েট কনফিগ থেকে Term নেওয়া হয়। মডেল ৩-এ (প্রতি টার্ম আলাদা রেজাল্ট) যে গ্রুপ সিলেক্ট করা, শুধু তার Term-গুলো
$pct_groups = []; $pct_pick_group_id = 0; $pct_pick_name = ''; $pct_struct = null;
$pct_is_standalone = $is_pct && (rc_pct_models()[$aggregate_type]['type'] ?? '') === 'standalone';
if ($is_pct) {
    // কাঠামো আসে Exam Terms পেজের Main/Sub টার্ম ও ওয়েট থেকে; ভুল থাকলে (যেমন ওয়েটের যোগফল ১০০ নয়) রেজাল্ট বের হয় না, কারণ দেখায়
    $pct_struct = rc_pct_structure($pdo, $f_session_id, $aggregate_type);
    $pct_groups = $pct_struct['errors'] ? [] : $pct_struct['groups'];
    $f_term_ids = rc_pct_config_term_ids($pct_groups);
    if ($pct_is_standalone && $pct_groups) {
        $want = (int) ($_GET['pct_group_id'] ?? 0);
        $picked = null;
        foreach ($pct_groups as $pg) { $picked = $pg; if ($pg['id'] === $want) { break; } }
        $pct_pick_group_id = (int) $picked['id'];
        $pct_pick_name = $picked['name'];
        $f_term_ids = rc_pct_config_term_ids([$picked]);
    }
}

$class_name = $shift_name = $section_name = $group_name = $sheet_session_name = '';
$rows = [];
$subject_columns = []; // subject_id => name (ইউনিয়ন, ডিসপ্লের জন্য)
$no_subjects_assigned = false;
$no_terms_selected = false;
$total_pass = 0; $total_fail = 0;
$grade_bands = [];

if ($searched && $f_class_id) {
    $one = function (string $sql, int $id) use ($pdo) { $s = $pdo->prepare($sql); $s->execute([$id]); return $s->fetchColumn() ?: ''; };
    $class_name   = $one("SELECT class_name FROM academic_classes WHERE id = ?", $f_class_id);
    $shift_name   = $f_shift_id ? $one("SELECT shift_name FROM academic_shifts WHERE id = ?", $f_shift_id) : '';
    $section_name = $f_section_id ? $one("SELECT section_name FROM academic_sections WHERE id = ?", $f_section_id) : '';
    $group_name   = $f_group_id ? $one("SELECT group_name FROM academic_groups WHERE id = ?", $f_group_id) : '';
    $sheet_session_name = $one("SELECT session_name FROM academic_sessions WHERE id = ?", $f_session_id) ?: date('Y');

    $gs = $pdo->prepare("SELECT grade_letter, gpa, mark_from, mark_to FROM exam_grade_points WHERE session_id = ? ORDER BY mark_from DESC");
    $gs->execute([$f_session_id]);
    $grade_bands = $gs->fetchAll();

    if (!$f_term_ids) {
        $no_terms_selected = true;
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

        if (!$students) {
            // students খালি — নিচে "কোনো শিক্ষার্থী পাওয়া যায়নি" দেখাবে
        } else {
            foreach ($students as $stu) {
                $agg = rc_aggregate_terms_result($pdo, (int) $stu['id'], $f_term_ids, $f_class_id, $aggregate_type, $grade_bands, $pct_pick_group_id);

                foreach ($agg['subject_breakdown'] as $sid => $sb) {
                    if (!isset($subject_columns[$sid])) { $subject_columns[$sid] = $sb['name']; }
                }

                if ($agg['final_fail']) { $total_fail++; } else { $total_pass++; }

                $rows[] = [
                    'id' => (int) $stu['id'], 'roll_no' => $stu['roll_no'], 'name' => $stu['name_en'],
                    'subject_breakdown' => $agg['subject_breakdown'],
                    'per_term' => $agg['per_term'],
                    'total' => $agg['final_obtained'], 'full_total' => $agg['final_full'],
                    'pct' => $agg['final_percentage'], 'gpa' => $agg['final_gpa'], 'gl' => $agg['final_gl'],
                    'fail' => $agg['final_fail'], 'merit' => '-',
                ];
            }

            if (!$rows) {
                $no_subjects_assigned = true;
            } elseif ($show_merit) {
                $passed = array_values(array_filter($rows, fn($r) => !$r['fail']));
                $students_for_merit = [];
                foreach ($passed as $p) {
                    $subj_marks = [];
                    foreach ($p['subject_breakdown'] as $sid => $sb) { $subj_marks[$sid] = $sb['avg_obtained']; }
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
        .no-print, .sidebar, .sticky-top-area, .top-navbar, .breadcrumb-bar, .alert, footer { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; }
        .content-body { padding: 0 !important; margin: 0 !important; }
        .card-custom { box-shadow: none !important; border: none !important; }
    }
    .far-header { text-align: center; margin-bottom: 10px; }
    .far-header h4 { font-weight: bold; color: #1e3a8a; margin-bottom: 4px; }
    .far-badge { display: inline-block; font-weight: bold; font-size: 14px; border: 1.5px solid #333; border-radius: 3px; padding: 4px 18px; margin: 6px 0; }
    .far-table th, .far-table td { white-space: nowrap; font-size: 12px; text-align: center; vertical-align: middle; }
    .far-table td.far-left, .far-table th.far-left { text-align: left; }
    .far-res-pass { color: #16a34a; font-weight: 700; }
    .far-res-fail { color: #dc2626; font-weight: 700; }
</style>

<div class="card-custom no-print">
    <form method="GET" action="index.php" id="farFilterForm">
        <input type="hidden" name="page" value="result_final_aggregated_result">
        <div class="alert alert-info py-2 small mb-3">
            বর্তমানে সক্রিয় হিসাব-পদ্ধতি: <strong><?php echo farv($agg_display); ?></strong>
            — বদলাতে <a href="index.php?page=result_settings">Master Settings</a> পেজে "Aggregate Result Type" দেখুন।
        </div>
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-bold">Session <span class="text-danger">*</span></label>
                <select name="session_id" class="form-select">
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session_id === (int) $s['id'] ? 'selected' : ''; ?>><?php echo farv($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
                <select name="class_id" class="form-select" required>
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo farv($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Shift</label>
                <select name="shift_id" class="form-select">
                    <option value="0">All Shifts</option>
                    <?php foreach ($shifts as $sh): ?>
                        <option value="<?php echo (int) $sh['id']; ?>" <?php echo $f_shift_id === (int) $sh['id'] ? 'selected' : ''; ?>><?php echo farv($sh['shift_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Section</label>
                <select name="section_id" class="form-select">
                    <option value="0">All Sections</option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?php echo (int) $sec['id']; ?>" <?php echo $f_section_id === (int) $sec['id'] ? 'selected' : ''; ?>><?php echo farv($sec['section_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Group</label>
                <select name="group_id" class="form-select">
                    <option value="0">All Groups</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?php echo (int) $g['id']; ?>" <?php echo $f_group_id === (int) $g['id'] ? 'selected' : ''; ?>><?php echo farv($g['group_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($is_pct): ?>
            <div class="col-md-9">
                <label class="form-label fw-bold"><?php echo $is_avg_model ? 'গ্রুপ ও পরীক্ষা' : 'পরীক্ষা ও ওয়েট'; ?></label>
                <div class="small text-muted mt-1">
                    <?php echo $is_avg_model ? 'এই মডেলে কোন গ্রুপে কোন কোন পরীক্ষা যুক্ত হবে তা Exam Terms পেজের Main/Sub কাঠামো থেকে আসে।' : 'এই মডেলে কোন কোন পরীক্ষা কত শতাংশে যুক্ত হবে তা Exam Terms পেজের Main/Sub কাঠামো ও ওয়েট থেকে আসে।'; ?>
                    <?php if (!$pct_groups): ?><span class="text-danger fw-bold">কাঠামো/ওয়েট এখনো ঠিক হয়নি — Search করলে কারণ দেখাবে।</span><?php endif; ?>
                </div>
                <?php if ($pct_is_standalone && $pct_groups): ?>
                    <div class="mt-2" style="max-width:320px;">
                        <label class="form-label fw-bold small mb-1">কোন টার্মের (গ্রুপের) রেজাল্ট দেখবেন</label>
                        <select name="pct_group_id" class="form-select form-select-sm">
                            <?php foreach ($pct_groups as $pg): ?>
                                <option value="<?php echo (int) $pg['id']; ?>" <?php echo $pct_pick_group_id === (int) $pg['id'] ? 'selected' : ''; ?>><?php echo farv($pg['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="col-md-9">
                <label class="form-label fw-bold">যে Exam Term-গুলো মিলিয়ে Final Result বের হবে <span class="text-danger">*</span></label>
                <div class="d-flex flex-wrap gap-3 mt-1">
                    <?php foreach ($terms as $t): ?>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="term_ids[]" id="farTerm<?php echo (int) $t['id']; ?>" value="<?php echo (int) $t['id']; ?>" <?php echo in_array((int) $t['id'], $f_term_ids, true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="farTerm<?php echo (int) $t['id']; ?>"><?php echo farv($t['term_name']); ?><?php echo $t['is_aggregated_result'] ? ' <span class="badge bg-primary">Aggregated</span>' : ''; ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="farShowMerit" name="show_merit" value="1" <?php echo $show_merit ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold" for="farShowMerit">Show Merit</label>
                </div>
            </div>
        </div>
        <hr>
        <div class="text-end">
            <button type="submit" name="search_result" value="1" class="btn btn-primary fw-bold" style="background-color:#1e3a8a;"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            <a href="index.php?page=result_final_aggregated_result" class="btn btn-danger fw-bold"><i class="fa-solid fa-xmark"></i> Cancel</a>
        </div>
    </form>
</div>

<?php if ($searched): ?>
    <?php if ($no_terms_selected): ?>
        <?php if ($is_pct): ?>
            <div class="mt-3 no-print">
                <?php echo rc_pct_structure_html($pct_struct, $aggregate_type); ?>
                <div class="small">ওয়েট ঠিক করতে <a href="index.php?page=result_exam_terms">Exam Terms</a> পেজে যান — টার্ম বানানোর সময়ই Main/Sub ও ওয়েট দেওয়া যায়।</div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning mt-3 no-print">অন্তত একটা Exam Term সিলেক্ট করুন।</div>
        <?php endif; ?>
    <?php elseif (empty($grade_bands)): ?>
        <div class="alert alert-warning mt-3 no-print">
            এই সেশনের জন্য কোনো <strong>Grade Point</strong> সেট করা নেই। আগে
            <a href="index.php?page=result_grade_points">Grade Points</a> পেজ থেকে Grade Point সেটআপ করুন।
        </div>
    <?php elseif ($no_subjects_assigned): ?>
        <div class="alert alert-warning mt-3 no-print">
            সিলেক্ট করা Term-গুলোর কোনোটাতেই এই Class-এর জন্য Subject Assign করা নেই।
        </div>
    <?php elseif ($rows): ?>
        <div class="card-custom mt-3">
            <div class="d-flex justify-content-end mb-3 no-print">
                <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
            </div>
            <div class="far-header">
                <h4><?php echo farv($inst_display_name); ?></h4>
                <p><?php echo $group_name !== '' ? farv($group_name) : 'Combined'; ?> (<?php echo farv($class_name); ?>)
                   (Shift : <?php echo $shift_name !== '' ? farv($shift_name) : 'All'; ?>, Section : <?php echo $section_name !== '' ? farv($section_name) : 'All'; ?>)</p>
                <p>Total Student : <?php echo (int) $total_students; ?>,
                   <span style="color:#16a34a;">Pass : <?php echo (int) $total_pass; ?> (<?php echo $pass_pct; ?>%)</span>,
                   <span style="color:#dc2626;">Fail : <?php echo (int) $total_fail; ?> (<?php echo $fail_pct; ?>%)</span>
                </p>
                <div class="far-badge">Final Aggregated Result : <?php echo farv($sheet_session_name); ?> (<?php echo farv($agg_display); ?><?php echo $pct_pick_name !== '' ? ' — ' . farv($pct_pick_name) : ''; ?>)</div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered align-middle m-0 far-table">
                    <thead class="table-light">
                        <tr>
                            <th>Sl.</th>
                            <th>Roll</th>
                            <th class="far-left">Name</th>
                            <?php foreach ($subject_columns as $sid => $sname): ?>
                                <th><?php echo farv($sname); ?> <span class="text-muted small d-block" style="font-weight:400;"><?php echo ($is_pct && !$is_avg_model) ? '(ওয়েটেড)' : '(গড়)'; ?></span></th>
                            <?php endforeach; ?>
                            <th>Total</th>
                            <th>%</th>
                            <th>GPA</th>
                            <th>Grade</th>
                            <th>Result</th>
                            <?php if ($show_merit): ?><th>Merit</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $srl = 1; foreach ($rows as $r): ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td><?php echo farv($r['roll_no']); ?></td>
                                <td class="far-left"><?php echo farv($r['name']); ?></td>
                                <?php foreach ($subject_columns as $sid => $sname): ?>
                                    <td><?php echo isset($r['subject_breakdown'][$sid]) ? far_num($r['subject_breakdown'][$sid]['avg_obtained']) : '-'; ?></td>
                                <?php endforeach; ?>
                                <td class="fw-bold"><?php echo far_num($r['total']); ?> <span class="text-muted small">/<?php echo far_num($r['full_total']); ?></span></td>
                                <td><?php echo number_format($r['pct'], 2); ?></td>
                                <td><?php echo number_format($r['gpa'], 2); ?></td>
                                <td class="<?php echo $r['fail'] ? 'far-res-fail' : 'far-res-pass'; ?>"><?php echo $r['fail'] ? 'F' : farv($r['gl']); ?></td>
                                <td class="<?php echo $r['fail'] ? 'far-res-fail' : 'far-res-pass'; ?>"><?php echo $r['fail'] ? 'Fail' : 'Pass'; ?></td>
                                <?php if ($show_merit): ?><td class="fw-bold"><?php echo farv($r['merit']); ?></td><?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="no-print small text-muted mt-3">
                <strong>প্রতি Term-এর আলাদা ফলাফল (যাচাইয়ের জন্য):</strong>
                <table class="table table-sm table-bordered mt-2">
                    <thead><tr><th>Roll</th><th>Name</th><?php foreach ($f_term_ids as $tid): $tname = array_column($terms, 'term_name', 'id')[$tid] ?? $tid; ?><th><?php echo farv($tname); ?> (Obtained/Full, %)</th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo farv($r['roll_no']); ?></td>
                            <td><?php echo farv($r['name']); ?></td>
                            <?php foreach ($f_term_ids as $tid): $pt = $r['per_term'][$tid] ?? null; ?>
                                <td><?php echo $pt ? (far_num($pt['obtained_total']) . '/' . far_num($pt['full_total']) . ' (' . number_format($pt['percentage'], 1) . '%)') : '—'; ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="card-custom mt-3">
            <p class="text-muted text-center m-0">নির্বাচিত ফিল্টারে কোনো শিক্ষার্থী পাওয়া যায়নি।</p>
        </div>
    <?php endif; ?>
<?php endif; ?>
