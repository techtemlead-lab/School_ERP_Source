<?php
// admin/result_multiyear_cgpa.php - Result Module > Result Reports > Multi-Year CGPA
// একজন ছাত্র বছরের পর বছর Promote হতে হতে (Student Promotion/Migration) যতগুলো Academic Year
// পার করেছে, প্রতিটা বছরের চূড়ান্ত ফলাফল (Final Aggregated Result-এর মতোই হিসাব) বের করে
// সবগুলোর গড় করে একটা সামগ্রিক CGPA দেখায় — যেমন Class Six থেকে Class Ten পর্যন্ত পুরো
// একাডেমিক ইতিহাসের একটা সারসংক্ষেপ।
//
// students.promoted_from_id চেইন ধরে পেছনের দিকে হেঁটে প্রতিটা বছরের রেকর্ড বের করা হয় (এই
// কলাম ও এর সাথে জড়িত student_id UNIQUE constraint বাগ এই সেশনেই student_promote.php/
// student_migration.php-এ ফিক্স করা হয়েছে — তার আগে Promotion আসলে কাজই করত না, তাই কোনো
// তেনান্টেই এই চেইন তৈরি হতো না)।

require_once __DIR__ . '/../config/report_card_helpers.php';

function cgv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function cg_num($n): string {
    $n = (float) $n;
    return number_format($n, 2);
}

// students.is_current/promoted_from_id কলাম না থাকলে (পুরনো ইনস্টলেশন) নিরাপদে self-heal
$cg_cols = $pdo->query("SHOW COLUMNS FROM students")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('is_current', $cg_cols, true)) {
    $pdo->exec("ALTER TABLE students ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 1");
}
if (!in_array('promoted_from_id', $cg_cols, true)) {
    $pdo->exec("ALTER TABLE students ADD COLUMN promoted_from_id INT NULL DEFAULT NULL AFTER is_current");
}

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

$classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();

$f_class_id   = (int) ($_GET['class_id'] ?? 0);
$f_student_id = (int) ($_GET['student_id'] ?? 0);
$searched     = isset($_GET['search_result']) && $f_student_id;

$students_in_class = [];
if ($f_class_id && $current_session_id) {
    $where = ["status = 'Active'", 'session_id = ?', 'class_id = ?'];
    $params = [$current_session_id, $f_class_id];
    if (function_exists('branch_apply_filter')) { branch_apply_filter($where, $params, 'branch_id'); }
    $sq = $pdo->prepare("SELECT id, student_id, roll_no, name_en FROM students WHERE " . implode(' AND ', $where) . " ORDER BY (roll_no + 0) ASC, roll_no ASC");
    $sq->execute($params);
    $students_in_class = $sq->fetchAll();
}

// -----------------------------------------------------------------
// promoted_from_id চেইন ধরে পেছনের দিকে হেঁটে ছাত্রের সব বছরের রেকর্ড বের করা (সবচেয়ে পুরনোটা
// আগে আসবে যেন টেবিলে টাইমলাইন ক্রমে দেখা যায়)
// -----------------------------------------------------------------
function cg_get_promotion_chain(PDO $pdo, int $student_row_id): array
{
    $chain = [];
    $current_id = $student_row_id;
    $guard = 0;
    while ($current_id && $guard < 25) { // guard: অসীম লুপ ঠেকানোর নিরাপত্তা
        $stmt = $pdo->prepare("SELECT id, student_id, name_en, session_id, class_id, roll_no, promoted_from_id FROM students WHERE id = ?");
        $stmt->execute([$current_id]);
        $row = $stmt->fetch();
        if (!$row) { break; }
        $chain[] = $row;
        $current_id = $row['promoted_from_id'] ? (int) $row['promoted_from_id'] : 0;
        $guard++;
    }
    return array_reverse($chain);
}

$chain = [];
$student_name = '';
$year_results = [];
$cgpa = null;
$cgpa_years_counted = 0;

if ($searched) {
    $chain = cg_get_promotion_chain($pdo, $f_student_id);
    if ($chain) {
        $student_name = end($chain)['name_en'];

        foreach ($chain as $entry) {
            $yr_session_id = (int) $entry['session_id'];
            $yr_class_id   = (int) $entry['class_id'];
            $yr_student_row_id = (int) $entry['id'];

            $session_row = $pdo->prepare("SELECT session_name FROM academic_sessions WHERE id = ?");
            $session_row->execute([$yr_session_id]);
            $session_name = $session_row->fetchColumn() ?: ('Session #' . $yr_session_id);

            $class_row = $pdo->prepare("SELECT class_name FROM academic_classes WHERE id = ?");
            $class_row->execute([$yr_class_id]);
            $class_name = $class_row->fetchColumn() ?: ('Class #' . $yr_class_id);

            $terms_stmt = $pdo->prepare("SELECT id FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
            $terms_stmt->execute([$yr_session_id]);
            $term_ids = $terms_stmt->fetchAll(PDO::FETCH_COLUMN);

            $gb_stmt = $pdo->prepare("SELECT grade_letter, gpa, mark_from, mark_to, remarks FROM exam_grade_points WHERE session_id = ? ORDER BY mark_from DESC");
            $gb_stmt->execute([$yr_session_id]);
            $grade_bands = $gb_stmt->fetchAll();

            if (!$term_ids || !$grade_bands) {
                $year_results[] = [
                    'session_name' => $session_name, 'class_name' => $class_name,
                    'has_data' => false,
                ];
                continue;
            }

            $agg = rc_aggregate_terms_result($pdo, $yr_student_row_id, $term_ids, $yr_class_id, 'Terms Wise Percentage', $grade_bands);

            if (!$agg['per_term']) {
                $year_results[] = [
                    'session_name' => $session_name, 'class_name' => $class_name,
                    'has_data' => false,
                ];
                continue;
            }

            $year_results[] = [
                'session_name' => $session_name, 'class_name' => $class_name,
                'has_data' => true,
                'final_percentage' => $agg['final_percentage'],
                'final_gl' => $agg['final_gl'],
                'final_gpa' => $agg['final_gpa'],
                'final_fail' => $agg['final_fail'],
            ];
        }

        // CGPA = ডেটা-পাওয়া বছরগুলোর Final GPA-এর সাধারণ গড় (Fail হওয়া বছরও ০ GPA নিয়ে ধরা হয়,
        // যেন একটা খারাপ বছর সামগ্রিক CGPA-তে যথাযথভাবে প্রভাব ফেলে)
        $sum_gpa = 0.0;
        foreach ($year_results as $yr) {
            if ($yr['has_data']) { $sum_gpa += $yr['final_gpa']; $cgpa_years_counted++; }
        }
        if ($cgpa_years_counted > 0) { $cgpa = $sum_gpa / $cgpa_years_counted; }
    }
}
?>
<style>
    .cg-header h4 { color: #1e3a8a; font-weight: bold; }
    .cg-table th, .cg-table td { vertical-align: middle; text-align: center; }
    .cg-cgpa-box { background: linear-gradient(135deg, #1e3a8a, #3b5fc4); color: #fff; border-radius: 10px; padding: 18px 24px; display: inline-block; }
    .cg-cgpa-box .val { font-size: 32px; font-weight: bold; }
</style>

<div class="card-custom">
    <div class="cg-header mb-2"><h4><i class="fa-solid fa-graduation-cap"></i> Multi-Year CGPA</h4></div>
    <p class="text-muted">একজন ছাত্র Promotion-এর মাধ্যমে যতগুলো Academic Year পার করেছে, প্রতিটা বছরের চূড়ান্ত ফলাফল (Final Aggregated Result-এর হিসাব অনুযায়ী) একসাথে দেখায় এবং সবগুলোর গড় করে সামগ্রিক CGPA বের করে।</p>

    <form method="GET" action="index.php" id="cgForm">
        <input type="hidden" name="page" value="result_multiyear_cgpa">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold">Class (বর্তমান Session)</label>
                <select name="class_id" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Select --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo cgv($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Student</label>
                <select name="student_id" class="form-select">
                    <option value="">-- Select --</option>
                    <?php foreach ($students_in_class as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_student_id === (int) $s['id'] ? 'selected' : ''; ?>>
                            <?php echo cgv($s['roll_no'] . ' - ' . $s['name_en']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" name="search_result" value="1" class="btn btn-primary fw-bold w-100">দেখুন</button>
            </div>
        </div>
    </form>

    <?php if ($searched): ?>
        <hr>
        <?php if (!$chain): ?>
            <div class="alert alert-warning">এই ছাত্রের কোনো রেকর্ড পাওয়া যায়নি।</div>
        <?php else: ?>
            <h5 class="fw-bold mb-3"><?php echo cgv($student_name); ?> — একাডেমিক ইতিহাস (<?php echo count($chain); ?> বছর)</h5>

            <div class="table-responsive mb-3">
                <table class="table table-bordered cg-table">
                    <thead class="table-light">
                        <tr><th>Session</th><th>Class</th><th>Percentage</th><th>Grade</th><th>GPA</th><th>Result</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($year_results as $yr): ?>
                            <tr>
                                <td><?php echo cgv($yr['session_name']); ?></td>
                                <td><?php echo cgv($yr['class_name']); ?></td>
                                <?php if ($yr['has_data']): ?>
                                    <td><?php echo cg_num($yr['final_percentage']); ?>%</td>
                                    <td><?php echo cgv($yr['final_gl']); ?></td>
                                    <td><?php echo cg_num($yr['final_gpa']); ?></td>
                                    <td>
                                        <?php if ($yr['final_fail']): ?>
                                            <span class="text-danger fw-bold">Fail</span>
                                        <?php else: ?>
                                            <span class="text-success fw-bold">Pass</span>
                                        <?php endif; ?>
                                    </td>
                                <?php else: ?>
                                    <td colspan="4" class="text-muted">এই বছরের রেজাল্ট ডেটা পাওয়া যায়নি</td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($cgpa !== null): ?>
                <div class="cg-cgpa-box">
                    <div>সামগ্রিক CGPA (<?php echo (int) $cgpa_years_counted; ?> বছরের গড়)</div>
                    <div class="val"><?php echo cg_num($cgpa); ?></div>
                </div>
            <?php else: ?>
                <div class="alert alert-info">কোনো বছরেরই পর্যাপ্ত রেজাল্ট ডেটা না থাকায় CGPA হিসাব করা যায়নি।</div>
            <?php endif; ?>

            <div class="form-text mt-2">নোট: প্রতিটা বছরের হিসাব "Terms Wise Percentage" পদ্ধতিতে করা হয়েছে (Result Settings > Master Setting-এর বর্তমান Aggregate Result Type অনুযায়ী)। কোনো বছরে ছাত্র Fail করলে সেই বছরের GPA ০ ধরেই CGPA-র গড়ে যোগ হয়েছে, যাতে সামগ্রিক চিত্রে সঠিকভাবে প্রতিফলিত হয়।</div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('cgForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            var sid = form.querySelector('select[name="student_id"]');
            if (e.submitter && e.submitter.name === 'search_result' && (!sid || !sid.value)) {
                e.preventDefault();
                alert('আগে একজন Student সিলেক্ট করুন।');
            }
        });
    }
});
</script>
