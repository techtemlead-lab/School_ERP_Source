<?php
// admin/result_marks_pending.php - Result Module > Result Reports > Marks Entry Pending / Teacher Status
// একটা Term (+ঐচ্ছিক Part) সিলেক্ট করলে সেই Term-এ Assign করা প্রতিটা Class+Subject-এর জন্য
// কতজন Active Student-এর মার্ক এন্ট্রি হয়েছে (কতজনের বাকি) এবং কোন Teacher Assign করা আছে
// (Result Module > Assign Subject Teacher থেকে) — তা একটা তালিকায় দেখায়, যাতে অ্যাডমিন সহজে
// বুঝতে পারেন কোন Teacher এখনো মার্ক দেননি।

require_once __DIR__ . '/../config/report_card_helpers.php';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS teacher_subject_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        class_id INT NOT NULL,
        subject_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_assign (employee_id, class_id, subject_id),
        KEY idx_employee (employee_id),
        KEY idx_class_subject (class_id, subject_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

function mpv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

$terms = []; $parts = [];
if ($current_session_id) {
    $t = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
    $t->execute([$current_session_id]); $terms = $t->fetchAll();
    $p = $pdo->prepare("SELECT id, part_name FROM exam_parts WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
    $p->execute([$current_session_id]); $parts = $p->fetchAll();
}

$searched   = isset($_GET['search_result']);
$f_term_id  = (int) ($_GET['term_id'] ?? 0);
$f_part_id  = (int) ($_GET['part_id'] ?? 0);
$only_pending = isset($_GET['only_pending']);

$term_name = '';
$rows = [];

if ($searched && $f_term_id) {
    $one = function (string $sql, int $id) use ($pdo) { $s = $pdo->prepare($sql); $s->execute([$id]); return $s->fetchColumn() ?: ''; };
    $term_name = $one("SELECT term_name FROM exam_terms WHERE id = ?", $f_term_id);

    // এই Term-এ Assign করা সব Class+Subject(+Part) কম্বিনেশন — একই Class+Subject একাধিক Part-এ
    // থাকতে পারে (Written/MCQ), part সিলেক্ট না করলে (Combined) সবগুলো আলাদা সারি হিসেবে আসবে
    $asg_sql = "SELECT eas.class_id, eas.part_id, eas.subject_id, ac.class_name, asub.subject_name
                FROM exam_assign_subjects eas
                LEFT JOIN academic_classes ac ON ac.id = eas.class_id
                INNER JOIN academic_subjects asub ON asub.id = eas.subject_id
                WHERE eas.term_id = ? AND eas.status = 'Active'";
    $asg_params = [$f_term_id];
    if ($f_part_id) { $asg_sql .= " AND eas.part_id = ?"; $asg_params[] = $f_part_id; }
    $asg_sql .= " ORDER BY ac.rank ASC, asub.subject_name ASC";
    $asg = $pdo->prepare($asg_sql); $asg->execute($asg_params);
    $assignments = $asg->fetchAll();

    $part_name_map = [];
    foreach ($parts as $p) { $part_name_map[(int) $p['id']] = $p['part_name']; }

    foreach ($assignments as $a) {
        $class_id = (int) $a['class_id'];
        $subject_id = (int) $a['subject_id'];
        $part_id = (int) $a['part_id'];

        // এই Class-এর মোট Active Student সংখ্যা
        $where = ["status = 'Active'", 'session_id = ?', 'class_id = ?'];
        $params = [$current_session_id, $class_id];
        branch_apply_filter($where, $params, 'branch_id');
        // ধর্মভিত্তিক Subject হলে শুধু ঐ ধর্মের শিক্ষার্থী গোনা হবে
        require_once __DIR__ . '/../config/subject_religion.php';
        $a_rel = sr_subject_map($pdo, [$subject_id])[$subject_id] ?? '';
        if ($a_rel !== '') {
            $variants = ['Islam' => ['islam','muslim','ইসলাম','মুসলিম'], 'Hinduism' => ['hinduism','hindu','হিন্দু','হিন্দুধর্ম'], 'Buddhism' => ['buddhism','buddhist','বৌদ্ধ'], 'Christianity' => ['christianity','christian','খ্রিস্টান','খ্রীস্টান'], 'Others' => ['others','other','অন্যান্য']][$a_rel] ?? [];
            $where[] = 'LOWER(TRIM(religion)) IN (' . implode(',', array_fill(0, count($variants), '?')) . ')';
            foreach ($variants as $vv) { $params[] = $vv; }
        }
        $sc = $pdo->prepare("SELECT COUNT(*) FROM students WHERE " . implode(' AND ', $where));
        $sc->execute($params);
        $total_students = (int) $sc->fetchColumn();

        if ($total_students === 0) { continue; }

        // এই Class+Subject+Part+Term-এ কতজনের মার্ক এন্ট্রি হয়েছে (exam_marks-এ রো আছে)
        $mc = $pdo->prepare(
            "SELECT COUNT(DISTINCT em.student_id) FROM exam_marks em
             JOIN students s ON s.id = em.student_id AND s.status = 'Active' AND s.session_id = ? AND s.class_id = ?
             WHERE em.term_id = ? AND em.part_id = ? AND em.subject_id = ?"
        );
        $mc->execute([$current_session_id, $class_id, $f_term_id, $part_id, $subject_id]);
        $entered = (int) $mc->fetchColumn();

        // অ্যাসাইন করা Teacher (থাকলে)
        $tq = $pdo->prepare(
            "SELECT e.name FROM teacher_subject_assignments tsa
             JOIN employees e ON e.id = tsa.employee_id
             WHERE tsa.class_id = ? AND tsa.subject_id = ? LIMIT 1"
        );
        $tq->execute([$class_id, $subject_id]);
        $teacher_name = $tq->fetchColumn() ?: null;

        $pending = $total_students - $entered;
        $pct = $total_students > 0 ? ($entered / $total_students) * 100 : 0;

        if ($only_pending && $pending <= 0) { continue; }

        $rows[] = [
            'class_name' => $a['class_name'], 'subject_name' => $a['subject_name'],
            'part_name' => $part_id ? ($part_name_map[$part_id] ?? '') : 'Combined',
            'teacher_name' => $teacher_name, 'total' => $total_students, 'entered' => $entered,
            'pending' => $pending, 'pct' => $pct,
        ];
    }

    // সবচেয়ে বেশি বাকি আছে এমনটা আগে
    usort($rows, fn($a, $b) => $b['pending'] <=> $a['pending']);
}
?>
<style>
    .mp-header h4 { color: #1e3a8a; font-weight: bold; }
    .mp-bar-track { background: #eef1f6; border-radius: 5px; height: 18px; width: 100%; overflow: hidden; }
    .mp-bar-fill { height: 100%; border-radius: 5px; background: #16a34a; }
    .mp-bar-fill.incomplete { background: #f59e0b; }
    .mp-bar-fill.zero { background: #dc2626; }
    .mp-table th, .mp-table td { vertical-align: middle; text-align: center; }
    .mp-no-teacher { color: #dc2626; font-style: italic; }
</style>

<div class="card-custom">
    <form method="GET" action="index.php" id="mpFilterForm">
        <input type="hidden" name="page" value="result_marks_pending">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold">Term <span class="text-danger">*</span></label>
                <select name="term_id" class="form-select" required>
                    <option value="">Select Term</option>
                    <?php foreach ($terms as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php echo $f_term_id === (int) $t['id'] ? 'selected' : ''; ?>><?php echo mpv($t['term_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Part</label>
                <select name="part_id" class="form-select">
                    <option value="0">All Parts</option>
                    <?php foreach ($parts as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php echo $f_part_id === (int) $p['id'] ? 'selected' : ''; ?>><?php echo mpv($p['part_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <div class="form-check mb-2">
                    <input type="checkbox" class="form-check-input" id="mpOnlyPending" name="only_pending" value="1" <?php echo $only_pending ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold" for="mpOnlyPending">শুধু যেগুলো এখনো সম্পূর্ণ হয়নি</label>
                </div>
            </div>
        </div>
        <hr>
        <div class="text-end">
            <button type="submit" name="search_result" value="1" class="btn btn-primary fw-bold" style="background-color:#1e3a8a;"><i class="fa-solid fa-magnifying-glass"></i> Check Status</button>
        </div>
    </form>
</div>

<?php if ($searched): ?>
    <?php if (!$terms): ?>
        <div class="alert alert-warning mt-3">এই সেশনের জন্য কোনো Exam Term নেই।</div>
    <?php elseif (empty($rows)): ?>
        <div class="card-custom mt-3"><p class="text-muted text-center m-0"><?php echo $only_pending ? 'সবগুলো Class/Subject-এর মার্ক এন্ট্রি সম্পূর্ণ — কোনো Pending নেই।' : 'এই Term-এর জন্য কোনো Subject Assign করা নেই, অথবা কোনো Class-এ Active Student নেই।'; ?></p></div>
    <?php else: ?>
        <div class="card-custom mt-3">
            <div class="mp-header mb-3">
                <h4><i class="fa-solid fa-list-check"></i> Marks Entry Status — <?php echo mpv($term_name); ?></h4>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered mp-table">
                    <thead class="table-light">
                        <tr>
                            <th>Class</th>
                            <th>Subject</th>
                            <th>Part</th>
                            <th>Assigned Teacher</th>
                            <th>Entered</th>
                            <th>Pending</th>
                            <th style="width:200px;">Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                                $bar_class = $r['pending'] === 0 ? '' : ($r['entered'] === 0 ? 'zero' : 'incomplete');
                            ?>
                            <tr>
                                <td class="fw-bold"><?php echo mpv($r['class_name']); ?></td>
                                <td><?php echo mpv($r['subject_name']); ?></td>
                                <td><?php echo mpv($r['part_name']); ?></td>
                                <td>
                                    <?php if ($r['teacher_name']): ?>
                                        <?php echo mpv($r['teacher_name']); ?>
                                    <?php else: ?>
                                        <span class="mp-no-teacher"><i class="fa-solid fa-triangle-exclamation"></i> কোনো Teacher Assign করা নেই</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int) $r['entered']; ?> / <?php echo (int) $r['total']; ?></td>
                                <td class="fw-bold <?php echo $r['pending'] > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo (int) $r['pending']; ?></td>
                                <td>
                                    <div class="mp-bar-track">
                                        <div class="mp-bar-fill <?php echo $bar_class; ?>" style="width:<?php echo max(2, round($r['pct'])); ?>%;"></div>
                                    </div>
                                    <div class="small text-muted mt-1"><?php echo number_format($r['pct'], 0); ?>%</div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
