<?php
// admin/result_section_comparison.php - Result Module > Result Reports > Class/Section Comparison Report
// Class/Term সিলেক্ট করলে সেই Class-এর প্রতিটা Section (A, B, C...) এর মধ্যে Pass Rate, গড় %,
// গড় GPA তুলনা করে দেখায় — কোন Section-এর পারফরম্যান্স তুলনামূলক ভালো/খারাপ বোঝার জন্য।
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

function scv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$terms = []; $parts = [];
if ($current_session_id) {
    $t = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
    $t->execute([$current_session_id]); $terms = $t->fetchAll();
    $p = $pdo->prepare("SELECT id, part_name FROM exam_parts WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
    $p->execute([$current_session_id]); $parts = $p->fetchAll();
}

$searched     = isset($_GET['search_result']);
$f_session_id = (int) ($_GET['session_id'] ?? $current_session_id);
$f_class_id   = (int) ($_GET['class_id'] ?? 0);
$f_term_id    = (int) ($_GET['term_id'] ?? 0);
$f_part_id    = (int) ($_GET['part_id'] ?? 0);

$class_name = $term_name = $part_name = '';
$section_stats = [];
$no_subjects_assigned = false;
$no_sections = false;
$grade_bands = [];
$overall_total = 0; $overall_pass = 0;

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

    // এই ক্লাসে যে Section-গুলোতে সক্রিয় স্টুডেন্ট আছে সেগুলোই তুলনায় আসবে
    $sec_where = ["s.status = 'Active'", 's.session_id = ?', 's.class_id = ?'];
    $sec_params = [$f_session_id, $f_class_id];
    branch_apply_filter($sec_where, $sec_params, 's.branch_id');
    $sec_q = $pdo->prepare(
        "SELECT DISTINCT asec.id, asec.section_name FROM students s
         JOIN academic_sections asec ON asec.id = s.section_id
         WHERE " . implode(' AND ', $sec_where) . " ORDER BY asec.section_name ASC"
    );
    $sec_q->execute($sec_params);
    $class_sections = $sec_q->fetchAll();

    if (!$subject_ids) {
        $no_subjects_assigned = true;
    } elseif (!$class_sections) {
        $no_sections = true;
    } else {
        foreach ($class_sections as $sec) {
            $sec_id = (int) $sec['id'];
            $section_stats[$sec_id] = [
                'name' => $sec['section_name'], 'total' => 0, 'pass' => 0, 'fail' => 0,
                'sum_pct' => 0.0, 'sum_gpa' => 0.0,
            ];

            $where = ["s.status = 'Active'", 's.session_id = ?', 's.class_id = ?', 's.section_id = ?'];
            $params = [$f_session_id, $f_class_id, $sec_id];
            branch_apply_filter($where, $params, 's.branch_id');
            $st = $pdo->prepare("SELECT s.id FROM students s WHERE " . implode(' AND ', $where));
            $st->execute($params);
            $students = $st->fetchAll();
            if (!$students) { continue; }

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

            foreach ($students as $stu) {
                $sid_stu = (int) $stu['id'];
                $res = rc_student_result($defs, $marks_all[$sid_stu] ?? [], $att_all[$sid_stu] ?? [], $grade_bands);

                $section_stats[$sec_id]['total']++;
                $section_stats[$sec_id]['sum_pct'] += $res['percentage'];
                $section_stats[$sec_id]['sum_gpa'] += $res['overall_gpa'];
                if ($res['overall_fail']) { $section_stats[$sec_id]['fail']++; } else { $section_stats[$sec_id]['pass']++; }

                $overall_total++;
                if (!$res['overall_fail']) { $overall_pass++; }
            }
        }
        // যে Section-এ কোনো Active Student নেই সেটা তালিকা থেকে বাদ
        $section_stats = array_filter($section_stats, fn($s) => $s['total'] > 0);
    }
}

// পাস রেট অনুযায়ী সেরা থেকে দুর্বল ক্রমে সাজানো
uasort($section_stats, fn($a, $b) => ($b['pass'] / max(1, $b['total'])) <=> ($a['pass'] / max(1, $a['total'])));
$overall_pass_pct = $overall_total > 0 ? ($overall_pass / $overall_total) * 100 : 0;
?>
<style>
    .sc-header h4 { color: #1e3a8a; font-weight: bold; }
    .sc-bar-track { background: #eef1f6; border-radius: 5px; height: 20px; width: 100%; overflow: hidden; }
    .sc-bar-fill { height: 100%; border-radius: 5px; display: flex; align-items: center; padding-left: 6px; color: #fff; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .sc-bar-good { background: #16a34a; }
    .sc-bar-mid  { background: #f59e0b; }
    .sc-bar-weak { background: #dc2626; }
    .sc-table th, .sc-table td { vertical-align: middle; text-align: center; }
    .sc-rank-badge { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; background: #1e3a8a; color: #fff; font-weight: 700; font-size: 12px; }
</style>

<div class="card-custom">
    <form method="GET" action="index.php" id="scFilterForm">
        <input type="hidden" name="page" value="result_section_comparison">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-bold">Session <span class="text-danger">*</span></label>
                <select name="session_id" class="form-select">
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session_id === (int) $s['id'] ? 'selected' : ''; ?>><?php echo scv($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
                <select name="class_id" class="form-select" required>
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo scv($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Term <span class="text-danger">*</span></label>
                <select name="term_id" class="form-select" required>
                    <option value="">Select Term</option>
                    <?php foreach ($terms as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php echo $f_term_id === (int) $t['id'] ? 'selected' : ''; ?>><?php echo scv($t['term_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Part</label>
                <select name="part_id" class="form-select">
                    <option value="0">All Parts (Combined)</option>
                    <?php foreach ($parts as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php echo $f_part_id === (int) $p['id'] ? 'selected' : ''; ?>><?php echo scv($p['part_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <hr>
        <div class="text-end">
            <button type="submit" name="search_result" value="1" class="btn btn-primary fw-bold" style="background-color:#1e3a8a;"><i class="fa-solid fa-magnifying-glass"></i> Compare</button>
            <a href="index.php?page=result_section_comparison" class="btn btn-danger fw-bold"><i class="fa-solid fa-xmark"></i> Cancel</a>
        </div>
    </form>
</div>

<?php if ($searched): ?>
    <?php if ($no_subjects_assigned): ?>
        <div class="alert alert-warning mt-3">এই Class/Term-এর জন্য কোনো Subject Assign করা নেই। আগে <a href="index.php?page=result_assign_exams">Assign Exam</a> পেজ থেকে Subject Assign করুন।</div>
    <?php elseif (empty($grade_bands)): ?>
        <div class="alert alert-warning mt-3">এই সেশনের জন্য কোনো Grade Point সেট করা নেই। আগে <a href="index.php?page=result_grade_points">Grade Points</a> পেজ থেকে সেটআপ করুন।</div>
    <?php elseif ($no_sections || empty($section_stats)): ?>
        <div class="card-custom mt-3"><p class="text-muted text-center m-0">এই Class-এ একাধিক Active Section/Student পাওয়া যায়নি — তুলনা করার জন্য অন্তত দুইটা Section-এ Active Student থাকা দরকার।</p></div>
    <?php else: ?>
        <div class="card-custom mt-3">
            <div class="sc-header mb-3">
                <h4><i class="fa-solid fa-scale-balanced"></i> Class/Section Comparison Report</h4>
                <p class="text-muted mb-0">
                    Class: <strong><?php echo scv($class_name); ?></strong> &nbsp;|&nbsp;
                    Term: <strong><?php echo scv($term_name); ?><?php echo $part_name !== '' ? ' (' . scv($part_name) . ')' : ''; ?></strong> &nbsp;|&nbsp;
                    Total: <strong><?php echo (int) $overall_total; ?></strong> student(s) across <strong><?php echo count($section_stats); ?></strong> section(s) &nbsp;|&nbsp;
                    Overall Pass Rate: <strong><?php echo number_format($overall_pass_pct, 1); ?>%</strong>
                </p>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered sc-table">
                    <thead class="table-light">
                        <tr>
                            <th style="width:60px;">Rank</th>
                            <th>Section</th>
                            <th>Students</th>
                            <th>Pass</th>
                            <th>Fail</th>
                            <th style="width:220px;">Pass Rate</th>
                            <th>Avg %</th>
                            <th>Avg GPA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rk = 1; foreach ($section_stats as $sec_id => $st): ?>
                            <?php
                                $pass_rate = $st['total'] > 0 ? ($st['pass'] / $st['total']) * 100 : 0;
                                $avg_pct = $st['total'] > 0 ? $st['sum_pct'] / $st['total'] : 0;
                                $avg_gpa = $st['total'] > 0 ? $st['sum_gpa'] / $st['total'] : 0;
                                $bar_class = $pass_rate >= 80 ? 'sc-bar-good' : ($pass_rate >= 50 ? 'sc-bar-mid' : 'sc-bar-weak');
                            ?>
                            <tr>
                                <td><span class="sc-rank-badge"><?php echo $rk++; ?></span></td>
                                <td class="fw-bold"><?php echo scv($st['name']); ?></td>
                                <td><?php echo (int) $st['total']; ?></td>
                                <td class="text-success fw-bold"><?php echo (int) $st['pass']; ?></td>
                                <td class="text-danger fw-bold"><?php echo (int) $st['fail']; ?></td>
                                <td>
                                    <div class="sc-bar-track">
                                        <div class="sc-bar-fill <?php echo $bar_class; ?>" style="width:<?php echo max(4, min(100, round($pass_rate))); ?>%;">
                                            <?php echo number_format($pass_rate, 1); ?>%
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo number_format($avg_pct, 2); ?>%</td>
                                <td><?php echo number_format($avg_gpa, 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
