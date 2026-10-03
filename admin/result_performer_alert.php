<?php
// admin/result_performer_alert.php - Result Module > Result Reports > Top/Bottom Performer Alert
// একটা Class-এর সব স্টুডেন্টকে বর্তমান সেশনের সব Active Term-এর ফলাফল বিশ্লেষণ করে ৩ ভাগে ভাগ করে:
//   ১) Top Performer   — সবগুলো Term-এই Pass, সর্বশেষ Term-এ GPA বেশি
//   ২) At-Risk / Weak   — সর্বশেষ Term-এ Fail, অথবা আগের Term থেকে % উল্লেখযোগ্য কমে গেছে
//   ৩) Declining Trend   — Fail না করলেও পরপর Term-এ ধারাবাহিকভাবে % কমছে
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

function pav($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// একটা % কমে যাওয়াকে "উল্লেখযোগ্য পতন" ধরা হবে এই থ্রেশহোল্ডের বেশি হলে
const PA_DECLINE_THRESHOLD = 5.0;
// Top Performer ধরা হবে সর্বশেষ Term-এ এই GPA বা তার বেশি পেলে (এবং কোনো Term-এ Fail না থাকলে)
const PA_TOP_GPA_THRESHOLD = 4.0;

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

$classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();

$searched   = isset($_GET['search_result']);
$f_class_id = (int) ($_GET['class_id'] ?? 0);

$class_name = '';
$top_list = []; $weak_list = []; $declining_list = [];
$no_subjects_assigned = false;
$terms_in_session = [];

if ($searched && $f_class_id && $current_session_id) {
    $one = function (string $sql, int $id) use ($pdo) { $s = $pdo->prepare($sql); $s->execute([$id]); return $s->fetchColumn() ?: ''; };
    $class_name = $one("SELECT class_name FROM academic_classes WHERE id = ?", $f_class_id);

    $t = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
    $t->execute([$current_session_id]);
    $terms_in_session = $t->fetchAll();

    $gs = $pdo->prepare("SELECT grade_letter, gpa, mark_from, mark_to FROM exam_grade_points WHERE session_id = ? ORDER BY mark_from DESC");
    $gs->execute([$current_session_id]);
    $grade_bands = $gs->fetchAll();

    $where = ["status = 'Active'", 'session_id = ?', 'class_id = ?'];
    $params = [$current_session_id, $f_class_id];
    branch_apply_filter($where, $params, 'branch_id');
    $sq = $pdo->prepare("SELECT id, student_id, roll_no, name_en FROM students WHERE " . implode(' AND ', $where) . " ORDER BY (roll_no + 0) ASC, roll_no ASC");
    $sq->execute($params);
    $students = $sq->fetchAll();

    if (!$terms_in_session || !$students) {
        // কিছু করার নেই, নিচে ফাঁকা মেসেজ দেখাবে
    } else {
        foreach ($students as $stu) {
            $stu_id = (int) $stu['id'];
            $per_term = [];

            foreach ($terms_in_session as $trm) {
                $term_id = (int) $trm['id'];
                $defs = rc_get_subject_defs($pdo, $f_class_id, $term_id, 0);
                if (!$defs) { continue; }
                $subject_ids = array_keys($defs);
                $in_subj = implode(',', array_fill(0, count($subject_ids), '?'));

                $mk = $pdo->prepare("SELECT subject_id, part_id, marks_obtained FROM exam_marks WHERE term_id = ? AND student_id = ? AND subject_id IN ($in_subj)");
                $mk->execute(array_merge([$term_id, $stu_id], $subject_ids));
                $marks_map = [];
                foreach ($mk->fetchAll() as $r) { $marks_map[$r['subject_id']][$r['part_id']] = $r['marks_obtained']; }

                $has_any_mark = false;
                foreach ($marks_map as $sub) { if ($sub) { $has_any_mark = true; break; } }
                if (!$has_any_mark) { continue; }

                $at = $pdo->prepare("SELECT subject_id, part_id, status FROM exam_attendance_subject WHERE term_id = ? AND student_id = ? AND subject_id IN ($in_subj)");
                $at->execute(array_merge([$term_id, $stu_id], $subject_ids));
                $att_map = [];
                foreach ($at->fetchAll() as $r) { $att_map[$r['subject_id']][$r['part_id']] = $r['status']; }

                $res = rc_student_result($defs, $marks_map, $att_map, $grade_bands);
                $per_term[] = [
                    'term_name' => $trm['term_name'], 'pct' => $res['percentage'],
                    'gpa' => $res['overall_gpa'], 'fail' => $res['overall_fail'],
                ];
            }

            if (count($per_term) === 0) { continue; }

            $latest = end($per_term);
            $any_fail = false; foreach ($per_term as $pt) { if ($pt['fail']) { $any_fail = true; break; } }

            $row = [
                'id' => $stu_id, 'roll_no' => $stu['roll_no'], 'name' => $stu['name_en'],
                'per_term' => $per_term, 'latest_pct' => $latest['pct'], 'latest_gpa' => $latest['gpa'],
                'latest_fail' => $latest['fail'], 'any_fail' => $any_fail,
            ];

            // সর্বশেষ Term-এ Fail, অথবা আগের Term থেকে % PA_DECLINE_THRESHOLD-এর বেশি কমেছে — At-Risk
            $is_weak = $latest['fail'];
            $decline_amount = 0;
            if (count($per_term) >= 2) {
                $prev = $per_term[count($per_term) - 2];
                $decline_amount = $prev['pct'] - $latest['pct'];
                if ($decline_amount >= PA_DECLINE_THRESHOLD) { $is_weak = true; }
            }
            $row['decline_amount'] = $decline_amount;

            // পরপর প্রতিটা Term-এ % কমছে কিনা (কমপক্ষে ২টা Term দরকার, Fail নেই এমন স্টুডেন্টদের জন্য)
            $is_declining_trend = false;
            if (!$any_fail && count($per_term) >= 2) {
                $is_declining_trend = true;
                for ($i = 1; $i < count($per_term); $i++) {
                    if ($per_term[$i]['pct'] >= $per_term[$i - 1]['pct']) { $is_declining_trend = false; break; }
                }
            }

            $is_top = !$any_fail && $latest['gpa'] >= PA_TOP_GPA_THRESHOLD;

            if ($is_weak) {
                $weak_list[] = $row;
            } elseif ($is_declining_trend) {
                $declining_list[] = $row;
            } elseif ($is_top) {
                $top_list[] = $row;
            }
        }

        usort($weak_list, fn($a, $b) => $a['latest_pct'] <=> $b['latest_pct']);
        usort($declining_list, fn($a, $b) => $b['decline_amount'] <=> $a['decline_amount']);
        usort($top_list, fn($a, $b) => $b['latest_pct'] <=> $a['latest_pct']);
    }
}
?>
<style>
    .pa-header h4 { color: #1e3a8a; font-weight: bold; }
    .pa-section-title { font-weight: bold; font-size: 16px; padding: 8px 12px; border-radius: 6px; margin-bottom: 10px; }
    .pa-title-top { background: #dcfce7; color: #166534; }
    .pa-title-weak { background: #fee2e2; color: #991b1b; }
    .pa-title-decline { background: #fef3c7; color: #92400e; }
    .pa-table th, .pa-table td { vertical-align: middle; text-align: center; }
    .pa-trend-tag { display: inline-block; font-size: 11px; padding: 2px 8px; border-radius: 10px; background: #f1f5f9; color: #334155; margin: 0 2px; }
    .pa-trend-tag.fail { background: #fee2e2; color: #991b1b; }
</style>

<div class="card-custom">
    <form method="GET" action="index.php" id="paFilterForm">
        <input type="hidden" name="page" value="result_performer_alert">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
                <select name="class_id" class="form-select" required>
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo pav($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 d-flex align-items-end">
                <button type="submit" name="search_result" value="1" class="btn btn-primary fw-bold w-100" style="background-color:#1e3a8a;"><i class="fa-solid fa-triangle-exclamation"></i> Analyze Class</button>
            </div>
        </div>
    </form>
</div>

<?php if ($searched): ?>
    <?php if (!$terms_in_session): ?>
        <div class="alert alert-warning mt-3">এই সেশনের জন্য কোনো Exam Term নেই।</div>
    <?php elseif (empty($top_list) && empty($weak_list) && empty($declining_list)): ?>
        <div class="card-custom mt-3"><p class="text-muted text-center m-0">এই Class-এ পর্যাপ্ত মার্ক এন্ট্রি করা ডেটা পাওয়া যায়নি, অথবা কাউকে Top/At-Risk হিসেবে চিহ্নিত করার মতো তথ্য নেই।</p></div>
    <?php else: ?>
        <div class="mt-3">
            <?php if ($weak_list): ?>
                <div class="card-custom mb-3">
                    <div class="pa-section-title pa-title-weak"><i class="fa-solid fa-circle-exclamation"></i> At-Risk / Weak Students (<?php echo count($weak_list); ?>)</div>
                    <div class="table-responsive">
                        <table class="table table-bordered pa-table">
                            <thead class="table-light"><tr><th>Roll</th><th>Name</th><th>Term-wise %</th><th>Latest %</th><th>Reason</th></tr></thead>
                            <tbody>
                                <?php foreach ($weak_list as $r): ?>
                                    <tr>
                                        <td><?php echo pav($r['roll_no']); ?></td>
                                        <td class="fw-bold text-start"><?php echo pav($r['name']); ?></td>
                                        <td>
                                            <?php foreach ($r['per_term'] as $pt): ?>
                                                <span class="pa-trend-tag <?php echo $pt['fail'] ? 'fail' : ''; ?>"><?php echo pav($pt['term_name']); ?>: <?php echo number_format($pt['pct'], 1); ?>%</span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td class="fw-bold text-danger"><?php echo number_format($r['latest_pct'], 1); ?>%</td>
                                        <td class="text-start">
                                            <?php if ($r['latest_fail']): ?>সর্বশেষ Term-এ Fail<?php endif; ?>
                                            <?php if ($r['decline_amount'] >= PA_DECLINE_THRESHOLD): ?>
                                                <?php echo $r['latest_fail'] ? ', ' : ''; ?>আগের Term থেকে <?php echo number_format($r['decline_amount'], 1); ?>% কমেছে
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($declining_list): ?>
                <div class="card-custom mb-3">
                    <div class="pa-section-title pa-title-decline"><i class="fa-solid fa-arrow-trend-down"></i> Declining Trend, তবে এখনো Pass (<?php echo count($declining_list); ?>)</div>
                    <div class="table-responsive">
                        <table class="table table-bordered pa-table">
                            <thead class="table-light"><tr><th>Roll</th><th>Name</th><th>Term-wise %</th><th>Latest %</th></tr></thead>
                            <tbody>
                                <?php foreach ($declining_list as $r): ?>
                                    <tr>
                                        <td><?php echo pav($r['roll_no']); ?></td>
                                        <td class="fw-bold text-start"><?php echo pav($r['name']); ?></td>
                                        <td>
                                            <?php foreach ($r['per_term'] as $pt): ?>
                                                <span class="pa-trend-tag"><?php echo pav($pt['term_name']); ?>: <?php echo number_format($pt['pct'], 1); ?>%</span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td class="fw-bold text-warning"><?php echo number_format($r['latest_pct'], 1); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($top_list): ?>
                <div class="card-custom mb-3">
                    <div class="pa-section-title pa-title-top"><i class="fa-solid fa-trophy"></i> Top Performers (<?php echo count($top_list); ?>)</div>
                    <div class="table-responsive">
                        <table class="table table-bordered pa-table">
                            <thead class="table-light"><tr><th>Roll</th><th>Name</th><th>Term-wise %</th><th>Latest %</th><th>Latest GPA</th></tr></thead>
                            <tbody>
                                <?php foreach ($top_list as $r): ?>
                                    <tr>
                                        <td><?php echo pav($r['roll_no']); ?></td>
                                        <td class="fw-bold text-start"><?php echo pav($r['name']); ?></td>
                                        <td>
                                            <?php foreach ($r['per_term'] as $pt): ?>
                                                <span class="pa-trend-tag"><?php echo pav($pt['term_name']); ?>: <?php echo number_format($pt['pct'], 1); ?>%</span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td class="fw-bold text-success"><?php echo number_format($r['latest_pct'], 1); ?>%</td>
                                        <td class="fw-bold"><?php echo number_format($r['latest_gpa'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
