<?php
// admin/result_student_progress.php - Result Module > Result Reports > Student Progress Tracking
// একজন নির্দিষ্ট Student-এর Current Session-এর সবকটা Active Exam Term (Half Yearly, Annual...)
// জুড়ে %, GPA, Grade, Pass/Fail ট্রেন্ড দেখায় — টার্ম থেকে টার্মে উন্নতি/অবনতি বোঝার জন্য।
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

function spv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function sp_num($n): string {
    $n = (float) $n;
    return floor($n) == $n ? number_format($n, 0) : number_format($n, 2);
}

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

$classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();

$f_class_id   = (int) ($_GET['class_id'] ?? 0);
$f_student_id = (int) ($_GET['student_id'] ?? 0);
$searched     = isset($_GET['search_result']) && $f_student_id;

// ক্লাস সিলেক্ট করলে সেই ক্লাসের স্টুডেন্ট ড্রপডাউন (Current Session)
$students_in_class = [];
if ($f_class_id && $current_session_id) {
    $where = ["status = 'Active'", 'session_id = ?', 'class_id = ?'];
    $params = [$current_session_id, $f_class_id];
    branch_apply_filter($where, $params, 'branch_id');
    $sq = $pdo->prepare("SELECT id, student_id, roll_no, name_en FROM students WHERE " . implode(' AND ', $where) . " ORDER BY (roll_no + 0) ASC, roll_no ASC");
    $sq->execute($params);
    $students_in_class = $sq->fetchAll();
}

$student = null;
$term_results = [];
$terms_in_session = [];

if ($searched) {
    $stu_q = $pdo->prepare("SELECT id, student_id, roll_no, name_en, class_id, session_id FROM students WHERE id = ?");
    $stu_q->execute([$f_student_id]);
    $student = $stu_q->fetch();

    if ($student) {
        $stu_session_id = (int) $student['session_id'];
        $stu_class_id = (int) $student['class_id'];

        $t = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
        $t->execute([$stu_session_id]);
        $terms_in_session = $t->fetchAll();

        $gs = $pdo->prepare("SELECT grade_letter, gpa, mark_from, mark_to FROM exam_grade_points WHERE session_id = ? ORDER BY mark_from DESC");
        $gs->execute([$stu_session_id]);
        $grade_bands = $gs->fetchAll();

        foreach ($terms_in_session as $trm) {
            $term_id = (int) $trm['id'];
            $defs = rc_split_defs(rc_get_subject_defs($pdo, $stu_class_id, $term_id, 0, rc_student_group_id($pdo, $f_student_id)))[0];
            if (!$defs) {
                $term_results[] = ['term_id' => $term_id, 'term_name' => $trm['term_name'], 'no_data' => true];
                continue;
            }
            $subject_ids = array_keys($defs);
            $in_subj = implode(',', array_fill(0, count($subject_ids), '?'));

            $mk = $pdo->prepare("SELECT subject_id, part_id, marks_obtained FROM exam_marks WHERE term_id = ? AND student_id = ? AND subject_id IN ($in_subj)");
            $mk->execute(array_merge([$term_id, $f_student_id], $subject_ids));
            $marks_map = [];
            foreach ($mk->fetchAll() as $r) { $marks_map[$r['subject_id']][$r['part_id']] = $r['marks_obtained']; }

            $at = $pdo->prepare("SELECT subject_id, part_id, status FROM exam_attendance_subject WHERE term_id = ? AND student_id = ? AND subject_id IN ($in_subj)");
            $at->execute(array_merge([$term_id, $f_student_id], $subject_ids));
            $att_map = [];
            foreach ($at->fetchAll() as $r) { $att_map[$r['subject_id']][$r['part_id']] = $r['status']; }

            $has_any_mark = false;
            foreach ($marks_map as $sub) { if ($sub) { $has_any_mark = true; break; } }

            if (!$has_any_mark) {
                $term_results[] = ['term_id' => $term_id, 'term_name' => $trm['term_name'], 'no_data' => true];
                continue;
            }

            $res = rc_student_result($defs, $marks_map, $att_map, $grade_bands, rc_excluded_subjects($pdo, $defs, $term_id, (int) $f_student_id));
            $term_results[] = [
                'term_id' => $term_id, 'term_name' => $trm['term_name'], 'no_data' => false,
                'total' => $res['obtained_total'], 'full_total' => $res['full_total'],
                'pct' => $res['percentage'], 'gpa' => $res['overall_gpa'], 'gl' => $res['overall_gl'],
                'fail' => $res['overall_fail'], 'fail_count' => $res['fail_subject_count'],
            ];
        }
    }
}

// শুধু ডেটা-আছে এমন টার্মগুলো নিয়ে ট্রেন্ড/গ্রাফ — Chart max % বের করার জন্য
$data_terms = array_values(array_filter($term_results, fn($t) => !$t['no_data']));
?>
<style>
    .sp-header h4 { color: #1e3a8a; font-weight: bold; }
    .sp-chart { display: flex; align-items: flex-end; gap: 24px; height: 220px; padding: 10px 10px 0; border-bottom: 2px solid #cbd5e1; margin-bottom: 10px; }
    .sp-bar-col { display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; flex: 1; max-width: 110px; }
    .sp-bar { width: 46px; border-radius: 6px 6px 0 0; background: linear-gradient(180deg,#3b82f6,#1e3a8a); display: flex; align-items: flex-start; justify-content: center; color: #fff; font-size: 12px; font-weight: 700; padding-top: 4px; }
    .sp-bar.fail { background: linear-gradient(180deg,#f87171,#b91c1c); }
    .sp-bar-label { margin-top: 8px; font-size: 12px; text-align: center; color: #334155; font-weight: 600; }
    .sp-trend-up { color: #16a34a; }
    .sp-trend-down { color: #dc2626; }
    .sp-trend-same { color: #64748b; }
    .sp-table th, .sp-table td { vertical-align: middle; text-align: center; }
</style>

<div class="card-custom">
    <form method="GET" action="index.php" id="spFilterForm">
        <input type="hidden" name="page" value="result_student_progress">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
                <select name="class_id" id="spClass" class="form-select" required onchange="this.form.student_id.value='';this.form.submit();">
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo spv($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label fw-bold">Student <span class="text-danger">*</span></label>
                <select name="student_id" class="form-select" required <?php echo !$f_class_id ? 'disabled' : ''; ?>>
                    <option value="">Select Student</option>
                    <?php foreach ($students_in_class as $stu): ?>
                        <option value="<?php echo (int) $stu['id']; ?>" <?php echo $f_student_id === (int) $stu['id'] ? 'selected' : ''; ?>>
                            <?php echo spv($stu['roll_no']); ?> - <?php echo spv($stu['name_en']); ?> (<?php echo spv($stu['student_id']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" name="search_result" value="1" class="btn btn-primary fw-bold w-100" style="background-color:#1e3a8a;"><i class="fa-solid fa-chart-line"></i> View Progress</button>
            </div>
        </div>
    </form>
</div>

<?php if ($searched): ?>
    <?php if (!$student): ?>
        <div class="card-custom mt-3"><p class="text-muted text-center m-0">এই Student খুঁজে পাওয়া যায়নি।</p></div>
    <?php elseif (!$terms_in_session): ?>
        <div class="alert alert-warning mt-3">এই সেশনের জন্য কোনো Exam Term নেই।</div>
    <?php elseif (count($data_terms) === 0): ?>
        <div class="card-custom mt-3"><p class="text-muted text-center m-0">এই Student-এর কোনো Term-এই এখনো মার্ক এন্ট্রি করা হয়নি।</p></div>
    <?php else: ?>
        <div class="card-custom mt-3">
            <div class="sp-header mb-3">
                <h4><i class="fa-solid fa-chart-line"></i> Student Progress Tracking</h4>
                <p class="text-muted mb-0">
                    Name: <strong><?php echo spv($student['name_en']); ?></strong> &nbsp;|&nbsp;
                    Roll: <strong><?php echo spv($student['roll_no']); ?></strong> &nbsp;|&nbsp;
                    ID: <strong><?php echo spv($student['student_id']); ?></strong>
                </p>
            </div>

            <div class="sp-chart">
                <?php foreach ($data_terms as $dt): ?>
                    <div class="sp-bar-col">
                        <div class="sp-bar <?php echo $dt['fail'] ? 'fail' : ''; ?>" style="height:<?php echo max(6, round($dt['pct'] * 1.8)); ?>px;">
                            <?php echo number_format($dt['pct'], 1); ?>%
                        </div>
                        <div class="sp-bar-label"><?php echo spv($dt['term_name']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered sp-table">
                    <thead class="table-light">
                        <tr>
                            <th>Term</th>
                            <th>Total</th>
                            <th>%</th>
                            <th>Trend</th>
                            <th>GPA</th>
                            <th>Grade</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $prev_pct = null; foreach ($term_results as $tr): ?>
                            <tr>
                                <td class="fw-bold"><?php echo spv($tr['term_name']); ?></td>
                                <?php if ($tr['no_data']): ?>
                                    <td colspan="6" class="text-muted">মার্ক এন্ট্রি করা হয়নি</td>
                                <?php else: ?>
                                    <td><?php echo sp_num($tr['total']); ?> / <?php echo sp_num($tr['full_total']); ?></td>
                                    <td class="fw-bold"><?php echo number_format($tr['pct'], 2); ?>%</td>
                                    <td>
                                        <?php if ($prev_pct === null): ?>
                                            <span class="sp-trend-same">—</span>
                                        <?php elseif ($tr['pct'] > $prev_pct): ?>
                                            <span class="sp-trend-up"><i class="fa-solid fa-arrow-up"></i> +<?php echo number_format($tr['pct'] - $prev_pct, 1); ?>%</span>
                                        <?php elseif ($tr['pct'] < $prev_pct): ?>
                                            <span class="sp-trend-down"><i class="fa-solid fa-arrow-down"></i> -<?php echo number_format($prev_pct - $tr['pct'], 1); ?>%</span>
                                        <?php else: ?>
                                            <span class="sp-trend-same"><i class="fa-solid fa-minus"></i> No Change</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo number_format($tr['gpa'], 2); ?></td>
                                    <td class="<?php echo $tr['fail'] ? 'text-danger' : 'text-success'; ?> fw-bold"><?php echo $tr['fail'] ? 'F' : spv($tr['gl']); ?></td>
                                    <td class="<?php echo $tr['fail'] ? 'text-danger' : 'text-success'; ?> fw-bold">
                                        <?php echo $tr['fail'] ? 'Fail' . ($tr['fail_count'] > 0 ? ' (' . (int) $tr['fail_count'] . ')' : '') : 'Pass'; ?>
                                    </td>
                                    <?php $prev_pct = $tr['pct']; ?>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
