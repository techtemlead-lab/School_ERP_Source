<?php
// student/lesson_plan.php - My Lesson Plan
// স্টুডেন্টের নিজের Class/Section-এর শুধু Approved Lesson Plan দেখায় (Teacher জমা দিয়ে Admin
// অনুমোদন করলে তবেই এখানে আসে) এবং প্রিন্ট করার লিংক দেয়।
// $student, $pdo — index.php থেকে available

require_once __DIR__ . '/../config/runtime.php';
if (($_SERVER['SCRIPT_NAME'] ?? '') !== '/student/index.php' || !isset($pdo)) {
    erp_stop(404, 'Open lesson plans from your student portal.');
}
require_once __DIR__ . '/../config/lesson_plan_helpers.php';
$student = lp_require_role($pdo, 'student');
lp_ensure_tables($pdo);

$slp_subject = (int) ($_GET['subject_id'] ?? 0);

[$slp_scope, $slp_params] = lp_plan_scope($pdo, 'student');
$sql = "SELECT p.*, sub.subject_name, e.name AS teacher_name
        FROM elearning_lesson_plans p
        LEFT JOIN academic_subjects sub ON sub.id = p.subject_id
        LEFT JOIN employees e ON e.id = p.teacher_id
        WHERE $slp_scope";
$params = $slp_params;
if ($slp_subject) { $sql .= " AND p.subject_id = ?"; $params[] = $slp_subject; }
$sql .= " ORDER BY p.lesson_date DESC, p.id DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$slp_list = $stmt->fetchAll();

// সাবজেক্ট ফিল্টারের অপশন — শুধু যেসব সাবজেক্টে এই স্টুডেন্টের জন্য Approved প্ল্যান আছে
$sub_stmt = $pdo->prepare(
    "SELECT DISTINCT sub.id, sub.subject_name
     FROM elearning_lesson_plans p JOIN academic_subjects sub ON sub.id = p.subject_id
     WHERE $slp_scope
     ORDER BY sub.subject_name"
);
$sub_stmt->execute($slp_params);
$slp_subjects = $sub_stmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="fw-bold mb-0"><i class="fa-solid fa-book me-2" style="color:#14b8a6;"></i>My Lesson Plan</h5>
    <?php if ($slp_subjects): ?>
    <form method="GET" class="d-flex gap-2">
        <input type="hidden" name="page" value="lesson_plan">
        <select name="subject_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">সব বিষয়</option>
            <?php foreach ($slp_subjects as $s): ?>
                <option value="<?php echo (int) $s['id']; ?>" <?php echo $slp_subject === (int) $s['id'] ? 'selected' : ''; ?>><?php echo sp_e($s['subject_name']); ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>
</div>

<?php if (!$slp_list): ?>
    <div class="card-custom text-center py-5">
        <div class="mb-3"><i class="fa-solid fa-book" style="font-size: 48px; color: #14b8a6;"></i></div>
        <p class="text-muted mb-0">এই মুহূর্তে আপনার ক্লাসের কোনো অনুমোদিত Lesson Plan নেই।</p>
    </div>
<?php else: ?>
    <div class="accordion" id="slpAccordion">
    <?php foreach ($slp_list as $i => $p): ?>
        <div class="accordion-item mb-2 border rounded">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#slp<?php echo (int) $p['id']; ?>">
                    <span class="me-3 badge bg-dark"><?php echo $p['lesson_date'] ? sp_e(date('d M Y', strtotime($p['lesson_date']))) : '—'; ?></span>
                    <span class="fw-bold me-2"><?php echo sp_e($p['title']); ?></span>
                    <span class="text-muted small">— <?php echo sp_e($p['subject_name']); ?><?php echo $p['teacher_name'] ? ' · ' . sp_e($p['teacher_name']) : ''; ?></span>
                </button>
            </h2>
            <div id="slp<?php echo (int) $p['id']; ?>" class="accordion-collapse collapse" data-bs-parent="#slpAccordion">
                <div class="accordion-body">
                    <?php if ($p['chapter'] || $p['duration_minutes']): ?>
                        <div class="small text-muted mb-3">
                            <?php if ($p['chapter']): ?><b>Chapter:</b> <?php echo sp_e($p['chapter']); ?><?php endif; ?>
                            <?php if ($p['chapter'] && $p['duration_minutes']): ?> · <?php endif; ?>
                            <?php if ($p['duration_minutes']): ?><b>Duration:</b> <?php echo (int) $p['duration_minutes']; ?> min<?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php foreach (lp_text_fields() as $col => $f): $v = trim((string) ($p[$col] ?? '')); if ($v === '') { continue; } ?>
                        <div class="mb-3">
                            <div class="fw-bold small text-uppercase text-secondary"><?php echo sp_e($f[0]); ?></div>
                            <div><?php echo nl2br(sp_e($v)); ?></div>
                        </div>
                    <?php endforeach; ?>
                    <a href="../lesson_plan_print.php?id=<?php echo (int) $p['id']; ?>&amp;portal=student" target="_blank" class="btn btn-sm btn-dark">
                        <i class="fa-solid fa-print me-1"></i> Print
                    </a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
