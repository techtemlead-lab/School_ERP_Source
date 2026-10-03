<?php
// admin/result_subject_analysis.php - Result Module > Result Reports > Subject-wise Performance Analysis
// Class/Term (+ ঐচ্ছিক Session/Group/Shift/Section/Part) সিলেক্ট করলে প্রতিটা Subject-এর
// Class Average %, Highest, Lowest, Pass/Fail সংখ্যা ও Pass Rate একটা টেবিলে + বার-চার্টে দেখায়,
// যাতে বোঝা যায় কোন সাবজেক্টে ক্লাসের পারফরম্যান্স তুলনামূলক দুর্বল/ভালো।
// হিসাব বাকি Result Reports-এর মতোই config/report_card_helpers.php এর শেয়ার্ড ফাংশন দিয়ে।

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

function sav($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function sa_num($n): string {
    $n = (float) $n;
    return floor($n) == $n ? number_format($n, 0) : number_format($n, 2);
}

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

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

$class_name = $term_name = $part_name = '';
$defs = [];
$subj_stats = [];
$total_students = 0;
$no_subjects_assigned = false;
$grade_bands = [];

if ($searched && $f_class_id && $f_term_id) {
    $one = function (string $sql, int $id) use ($pdo) { $s = $pdo->prepare($sql); $s->execute([$id]); return $s->fetchColumn() ?: ''; };
    $class_name = $one("SELECT class_name FROM academic_classes WHERE id = ?", $f_class_id);
    $term_name  = $one("SELECT term_name FROM exam_terms WHERE id = ?", $f_term_id);
    $part_name  = $f_part_id ? $one("SELECT part_name FROM exam_parts WHERE id = ?", $f_part_id) : '';

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
        $st = $pdo->prepare("SELECT s.id FROM students s WHERE " . implode(' AND ', $where));
        $st->execute($params);
        $students = $st->fetchAll();
        $total_students = count($students);

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

            foreach ($subject_ids as $sid) {
                $subj_stats[$sid] = [
                    'name' => $defs[$sid]['name'], 'full' => 0.0,
                    'sum_pct' => 0.0, 'count' => 0, 'highest' => null, 'lowest' => null,
                    'pass' => 0, 'fail' => 0, 'absent' => 0,
                ];
                foreach ($defs[$sid]['parts'] as $pinfo) { $subj_stats[$sid]['full'] += (float) $pinfo['full_mark_exam']; }
            }

            foreach ($students as $stu) {
                $sid_stu = (int) $stu['id'];
                $res = rc_student_result($defs, $marks_all[$sid_stu] ?? [], $att_all[$sid_stu] ?? [], $grade_bands);

                $i = 0;
                foreach ($defs as $subj_id => $def) {
                    $sr = $res['subject_rows'][$i++];
                    $absent = false;
                    foreach ((array) ($att_all[$sid_stu][$subj_id] ?? []) as $ast) { if ($ast === 'Absent') { $absent = true; } }

                    if ($absent) {
                        $subj_stats[$subj_id]['absent']++;
                        $subj_stats[$subj_id]['fail']++;
                        continue;
                    }

                    $sfull = $subj_stats[$subj_id]['full'];
                    $spct = $sfull > 0 ? ($sr['obtained'] / $sfull) * 100 : 0;
                    $subj_stats[$subj_id]['sum_pct'] += $spct;
                    $subj_stats[$subj_id]['count']++;
                    if ($subj_stats[$subj_id]['highest'] === null || $sr['obtained'] > $subj_stats[$subj_id]['highest']) {
                        $subj_stats[$subj_id]['highest'] = $sr['obtained'];
                    }
                    if ($subj_stats[$subj_id]['lowest'] === null || $sr['obtained'] < $subj_stats[$subj_id]['lowest']) {
                        $subj_stats[$subj_id]['lowest'] = $sr['obtained'];
                    }
                    if ($sr['fail']) { $subj_stats[$subj_id]['fail']++; } else { $subj_stats[$subj_id]['pass']++; }
                }
            }
        }
    }
}

// Weakest থেকে সবচেয়ে ভালো — Class Average % অনুযায়ী সাজানো (সবচেয়ে কম গড় % আগে, যাতে
// কোন সাবজেক্ট নিয়ে বেশি মনোযোগ দরকার সেটা সবার আগে চোখে পড়ে)
$sorted_subjects = $subj_stats;
uasort($sorted_subjects, function ($a, $b) {
    $ap = $a['count'] > 0 ? $a['sum_pct'] / $a['count'] : 0;
    $bp = $b['count'] > 0 ? $b['sum_pct'] / $b['count'] : 0;
    return $ap <=> $bp;
});

// -----------------------------------------------------------
// উপরে Stat Summary Card-এর জন্য এক নজরের হিসাব — Overall Pass Rate (সব
// সাবজেক্ট মিলিয়ে) আর সবচেয়ে দুর্বল সাবজেক্ট (sorted_subjects-এর প্রথমটা)
// -----------------------------------------------------------
$overall_pass = 0; $overall_attempt = 0;
foreach ($subj_stats as $st) { $overall_pass += $st['pass']; $overall_attempt += $st['pass'] + $st['fail']; }
$overall_pass_rate = $overall_attempt > 0 ? ($overall_pass / $overall_attempt) * 100 : 0;

$weakest_name = ''; $weakest_avg = 0;
if ($sorted_subjects) {
    $wk = reset($sorted_subjects);
    $weakest_name = $wk['name'];
    $weakest_avg  = $wk['count'] > 0 ? $wk['sum_pct'] / $wk['count'] : 0;
}
?>
<style>
    .sa-header h4 { color: var(--primary, #0a2540); font-weight: bold; }

    /* ---------- Stat summary cards ---------- */
    .sa-stat-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 20px; }
    .sa-stat-card { border-radius: 10px; padding: 16px 18px; color: #fff; display: flex; align-items: center; gap: 12px; box-shadow: 0 4px 12px rgba(0,0,0,.08); }
    .sa-stat-icon { width: 42px; height: 42px; border-radius: 50%; background: rgba(255,255,255,.2); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
    .sa-stat-card h3 { font-size: 19px; font-weight: 800; margin: 0; line-height: 1.25; color: #fff; }
    .sa-stat-card p { font-size: 11.5px; margin: 0; opacity: .92; font-weight: 600; color: #fff; }
    .sa-stat-navy    { background: linear-gradient(135deg, #0a2540, #123a63); }
    .sa-stat-emerald { background: linear-gradient(135deg, #0d9668, #10b981); }
    .sa-stat-amber   { background: linear-gradient(135deg, #b45309, #d97706); }

    /* ---------- Bar chart ---------- */
    .sa-bar-row { display: flex; align-items: center; gap: 10px; }
    .sa-bar-track { background: #eef1f6; border-radius: 5px; height: 16px; flex: 1 1 auto; overflow: hidden; }
    .sa-bar-fill { height: 100%; border-radius: 5px; }
    .sa-bar-good { background: #10b981; }
    .sa-bar-mid  { background: #f59e0b; }
    .sa-bar-weak { background: #dc2626; }
    .sa-bar-val { font-size: 12.5px; font-weight: 700; width: 54px; flex-shrink: 0; text-align: right; color: #1f2937; }

    /* ---------- Pass rate badge ---------- */
    .sa-badge { display: inline-block; padding: 4px 11px; border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap; }
    .sa-badge-good { background: #dcfce7; color: #15803d; }
    .sa-badge-mid  { background: #fef3c7; color: #b45309; }
    .sa-badge-weak { background: #fee2e2; color: #b91c1c; }

    /* ---------- Weakest subject row highlight ---------- */
    .sa-row-weak td { background: #fff7ed !important; }
    .sa-row-weak td:nth-child(2) { border-left: 4px solid #d97706; }
    .sa-weak-tag { display: inline-block; margin-left: 8px; background: #fed7aa; color: #9a3412; font-size: 10.5px; font-weight: 700; padding: 2px 9px; border-radius: 999px; vertical-align: middle; }

    /* ---------- Scrollable + sticky header ---------- */
    .sa-table-wrap { max-height: 560px; overflow-y: auto; border-radius: 8px; }
    .sa-table th, .sa-table td { vertical-align: middle; }
    .sa-table thead th { position: sticky; top: 0; z-index: 2; background: #eef2f7 !important; }

    /* ---------- Mobile: table -> stacked cards ---------- */
    @media (max-width: 768px) {
        .sa-table thead { display: none; }
        .sa-table, .sa-table tbody, .sa-table tr, .sa-table td { display: block; width: 100%; }
        .sa-table tr { margin-bottom: 14px; border: 1px solid #e2e8f0 !important; border-radius: 10px; padding: 10px 12px; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
        .sa-row-weak td:nth-child(2) { border-left: none !important; }
        .sa-row-weak { border-left: 4px solid #d97706 !important; }
        .sa-table td { border: none !important; padding: 6px 2px; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .sa-table td::before { content: attr(data-label); font-weight: 700; color: #64748b; font-size: 11.5px; flex-shrink: 0; }
        .sa-table td:first-child { display: none; }
        .sa-table td.sa-td-subject { font-size: 16px; }
        .sa-table td.sa-td-subject::before { content: ''; }
        .sa-bar-row { width: 65%; }
    }
</style>

<div class="card-custom">
    <form method="GET" action="index.php" id="saFilterForm">
        <input type="hidden" name="page" value="result_subject_analysis">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-bold">Session <span class="text-danger">*</span></label>
                <select name="session_id" class="form-select">
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session_id === (int) $s['id'] ? 'selected' : ''; ?>><?php echo sav($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
                <select name="class_id" id="saClass" class="form-select" required onchange="saFilterDependents()">
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo sav($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Term <span class="text-danger">*</span></label>
                <select name="term_id" class="form-select" required>
                    <option value="">Select Term</option>
                    <?php foreach ($terms as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php echo $f_term_id === (int) $t['id'] ? 'selected' : ''; ?>><?php echo sav($t['term_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Part</label>
                <select name="part_id" class="form-select">
                    <option value="0">All Parts (Combined)</option>
                    <?php foreach ($parts as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php echo $f_part_id === (int) $p['id'] ? 'selected' : ''; ?>><?php echo sav($p['part_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Shift</label>
                <select name="shift_id" id="saShift" class="form-select" onchange="saFilterDependents()">
                    <option value="0">All Shifts</option>
                    <?php foreach ($shifts as $sh): ?>
                        <option value="<?php echo (int) $sh['id']; ?>" <?php echo $f_shift_id === (int) $sh['id'] ? 'selected' : ''; ?>><?php echo sav($sh['shift_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Section</label>
                <select name="section_id" id="saSection" class="form-select">
                    <option value="0">All Sections</option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" data-shift="<?php echo (int) $sec['shift_id']; ?>" <?php echo $f_section_id === (int) $sec['id'] ? 'selected' : ''; ?>><?php echo sav($sec['section_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Group</label>
                <select name="group_id" id="saGroup" class="form-select">
                    <option value="0">All Groups</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?php echo (int) $g['id']; ?>" data-class="<?php echo (int) $g['class_id']; ?>" <?php echo $f_group_id === (int) $g['id'] ? 'selected' : ''; ?>><?php echo sav($g['group_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <hr>
        <div class="text-end">
            <button type="submit" name="search_result" value="1" class="btn btn-primary fw-bold" style="background-color:#1e3a8a;"><i class="fa-solid fa-magnifying-glass"></i> Analyze</button>
            <a href="index.php?page=result_subject_analysis" class="btn btn-danger fw-bold"><i class="fa-solid fa-xmark"></i> Cancel</a>
        </div>
    </form>
</div>

<?php if (!$terms): ?>
    <div class="alert alert-warning mt-3">
        এই সেশনের জন্য কোনো <strong>Exam Term</strong> সেট করা নেই। আগে
        <a href="index.php?page=result_exam_terms">Exam Terms</a> পেজ থেকে একটা Term যোগ করুন।
    </div>
<?php endif; ?>

<?php if ($searched): ?>
    <?php if ($no_subjects_assigned): ?>
        <div class="alert alert-warning mt-3">
            এই Class/Term-এর জন্য কোনো Subject Assign করা নেই। আগে
            <a href="index.php?page=result_assign_exams">Assign Exam</a> পেজ থেকে Subject Assign করুন।
        </div>
    <?php elseif (empty($grade_bands)): ?>
        <div class="alert alert-warning mt-3">
            এই সেশনের জন্য কোনো <strong>Grade Point</strong> সেট করা নেই। আগে
            <a href="index.php?page=result_grade_points">Grade Points</a> পেজ থেকে সেটআপ করুন।
        </div>
    <?php elseif ($total_students === 0): ?>
        <div class="card-custom mt-3">
            <p class="text-muted text-center m-0">নির্বাচিত ফিল্টারে কোনো শিক্ষার্থী পাওয়া যায়নি।</p>
        </div>
    <?php else: ?>
        <div class="card-custom mt-3">
            <div class="sa-header mb-3">
                <h4><i class="fa-solid fa-chart-column"></i> Subject-wise Performance Analysis</h4>
                <p class="text-muted mb-0">
                    Class: <strong><?php echo sav($class_name); ?></strong> &nbsp;|&nbsp;
                    Term: <strong><?php echo sav($term_name); ?><?php echo $part_name !== '' ? ' (' . sav($part_name) . ')' : ''; ?></strong> &nbsp;|&nbsp;
                    Total Student: <strong><?php echo (int) $total_students; ?></strong>
                </p>
            </div>

            <div class="sa-stat-row">
                <div class="sa-stat-card sa-stat-navy">
                    <div class="sa-stat-icon"><i class="fa-solid fa-user-graduate"></i></div>
                    <div>
                        <h3><?php echo (int) $total_students; ?></h3>
                        <p>Total Students</p>
                    </div>
                </div>
                <div class="sa-stat-card sa-stat-emerald">
                    <div class="sa-stat-icon"><i class="fa-solid fa-check-double"></i></div>
                    <div>
                        <h3><?php echo number_format($overall_pass_rate, 1); ?>%</h3>
                        <p>Overall Pass Rate</p>
                    </div>
                </div>
                <div class="sa-stat-card sa-stat-amber">
                    <div class="sa-stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div>
                        <h3 style="font-size:16px;"><?php echo $weakest_name !== '' ? sav($weakest_name) : '-'; ?></h3>
                        <p>Needs Attention (<?php echo number_format($weakest_avg, 1); ?>%)</p>
                    </div>
                </div>
            </div>

            <div class="sa-table-wrap table-responsive">
                <table class="table table-bordered align-middle sa-table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40px;">Sl.</th>
                            <th>Subject</th>
                            <th style="width:240px;">Class Average %</th>
                            <th>Highest</th>
                            <th>Lowest</th>
                            <th>Pass</th>
                            <th>Fail</th>
                            <th>Absent</th>
                            <th>Pass Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $srl = 1; foreach ($sorted_subjects as $sid => $st): ?>
                            <?php
                                $avg_pct = $st['count'] > 0 ? $st['sum_pct'] / $st['count'] : 0;
                                $attempted = $st['pass'] + $st['fail'];
                                $pass_rate = $attempted > 0 ? ($st['pass'] / $attempted) * 100 : 0;
                                $bar_class = $avg_pct >= 70 ? 'sa-bar-good' : ($avg_pct >= 40 ? 'sa-bar-mid' : 'sa-bar-weak');
                                $badge_class = $pass_rate >= 90 ? 'sa-badge-good' : ($pass_rate >= 75 ? 'sa-badge-mid' : 'sa-badge-weak');
                                $is_weakest = $srl === 1;
                            ?>
                            <tr class="<?php echo $is_weakest ? 'sa-row-weak' : ''; ?>">
                                <td data-label="Sl."><?php echo $srl++; ?></td>
                                <td class="fw-bold sa-td-subject" data-label="Subject">
                                    <?php echo sav($st['name']); ?>
                                    <?php if ($is_weakest): ?><span class="sa-weak-tag">সর্বনিম্ন গড়</span><?php endif; ?>
                                </td>
                                <td data-label="Class Average %">
                                    <div class="sa-bar-row">
                                        <div class="sa-bar-track">
                                            <div class="sa-bar-fill <?php echo $bar_class; ?>" style="width:<?php echo max(4, min(100, round($avg_pct))); ?>%;"></div>
                                        </div>
                                        <span class="sa-bar-val"><?php echo number_format($avg_pct, 1); ?>%</span>
                                    </div>
                                </td>
                                <td data-label="Highest"><?php echo $st['highest'] !== null ? sa_num($st['highest']) : '-'; ?></td>
                                <td data-label="Lowest"><?php echo $st['lowest'] !== null ? sa_num($st['lowest']) : '-'; ?></td>
                                <td class="text-success fw-bold" data-label="Pass"><?php echo (int) $st['pass']; ?></td>
                                <td class="text-danger fw-bold" data-label="Fail"><?php echo (int) $st['fail']; ?></td>
                                <td class="text-muted" data-label="Absent"><?php echo (int) $st['absent']; ?></td>
                                <td data-label="Pass Rate"><span class="sa-badge <?php echo $badge_class; ?>"><?php echo number_format($pass_rate, 1); ?>%</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="alert alert-light border mt-3 mb-0">
                <i class="fa-solid fa-circle-info"></i>
                তালিকাটা সবচেয়ে <strong>কম গড় %</strong> থেকে সবচেয়ে বেশি — অর্থাৎ উপরের সাবজেক্টগুলোতে ক্লাসের পারফরম্যান্স তুলনামূলক দুর্বল, বেশি মনোযোগ দরকার।
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
    function saFilterDependents() {
        const classId = document.getElementById('saClass').value;
        const shiftId = document.getElementById('saShift').value;
        const sec = document.getElementById('saSection');
        Array.from(sec.options).forEach(opt => {
            if (!opt.value || opt.value === '0') { opt.hidden = false; return; }
            const classOk = !classId || opt.dataset.class === classId;
            const shiftOk = !shiftId || shiftId === '0' || !opt.dataset.shift || opt.dataset.shift === '0' || opt.dataset.shift === shiftId;
            opt.hidden = !(classOk && shiftOk);
        });
        if (sec.value && sec.value !== '0' && Array.from(sec.options).find(o => o.value === sec.value)?.hidden) { sec.value = '0'; }

        const grp = document.getElementById('saGroup');
        Array.from(grp.options).forEach(opt => {
            if (!opt.value || opt.value === '0') { opt.hidden = false; return; }
            opt.hidden = !!classId && opt.dataset.class !== classId;
        });
        if (grp.value && grp.value !== '0' && Array.from(grp.options).find(o => o.value === grp.value)?.hidden) { grp.value = '0'; }
    }
    saFilterDependents();
</script>
