<?php
// student/exam_routine.php - পরীক্ষার রুটিন (Exam Term + Part সিলেক্ট করে দেখা যাবে)
$terms = [];
$parts = [];
$er_table_ready = true;
try {
    $current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
    $session_id = $current_session ? (int) $current_session['id'] : (int) $student['session_id'];

    $t_stmt = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
    $t_stmt->execute([$session_id]);
    $terms = $t_stmt->fetchAll();

    $p_stmt = $pdo->prepare("SELECT id, part_name FROM exam_parts WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
    $p_stmt->execute([$session_id]);
    $parts = $p_stmt->fetchAll();
} catch (\Throwable $e) {
    $er_table_ready = false;
}

$sel_term = (int) ($_GET['term_id'] ?? ($terms[0]['id'] ?? 0));
$sel_part = (int) ($_GET['part_id'] ?? ($parts[0]['id'] ?? 0));

$exam_rows = [];
if ($er_table_ready && $sel_term && $sel_part) {
    try {
        $stmt = $pdo->prepare(
            "SELECT exam_routines.*, academic_subjects.subject_name
             FROM exam_routines
             LEFT JOIN academic_subjects ON academic_subjects.id = exam_routines.subject_id
             WHERE exam_routines.class_id = ? AND exam_routines.section_id = ?
               AND exam_routines.term_id = ? AND exam_routines.part_id = ?
               AND exam_routines.status = 'Active'
             ORDER BY exam_routines.exam_date ASC"
        );
        $stmt->execute([(int) $student['class_id'], (int) $student['section_id'], $sel_term, $sel_part]);
        $exam_rows = $stmt->fetchAll();
        // ধর্মভিত্তিক Subject: ভিন্ন ধর্মের পরীক্ষা নিজের রুটিনে আসবে না
        require_once __DIR__ . '/../config/subject_religion.php';
        $er_rel = sr_subject_map($pdo, array_column($exam_rows, 'subject_id'));
        $exam_rows = array_values(array_filter($exam_rows, function ($x) use ($er_rel, $student) {
            return sr_applicable($er_rel[(int) $x['subject_id']] ?? '', $student['religion'] ?? '');
        }));
    } catch (\Throwable $e) { /* skip */ }
}
?>
<div class="card-custom">
    <h6 class="fw-bold mb-3" style="color:#0f766e;"><i class="fa-solid fa-calendar-days me-2"></i>My Exam Routine</h6>

    <?php if (!$er_table_ready || !$terms || !$parts): ?>
        <div class="text-center py-4">
            <i class="fa-solid fa-calendar-days" style="font-size:40px;color:#14b8a6;"></i>
            <p class="text-muted mt-2 mb-0">এখনো কোনো পরীক্ষার রুটিন সেট করা হয়নি।</p>
        </div>
    <?php else: ?>
        <form method="GET" class="row g-2 align-items-end mb-4">
            <input type="hidden" name="page" value="exam_routine">
            <div class="col-auto">
                <label class="form-label fw-bold" style="font-size:12.5px;">Exam Term</label>
                <select name="term_id" class="form-select form-select-sm">
                    <?php foreach ($terms as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php echo (int) $t['id'] === $sel_term ? 'selected' : ''; ?>><?php echo sp_e($t['term_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label fw-bold" style="font-size:12.5px;">Exam Part</label>
                <select name="part_id" class="form-select form-select-sm">
                    <?php foreach ($parts as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php echo (int) $p['id'] === $sel_part ? 'selected' : ''; ?>><?php echo sp_e($p['part_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm text-white" style="background:#0f766e;">View Routine</button>
            </div>
        </form>

        <?php if (!$exam_rows): ?>
            <p class="text-muted text-center py-4 mb-0">এই টার্ম/পার্টের জন্য এখনো পরীক্ষার রুটিন প্রকাশ করা হয়নি।</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle" style="font-size:13px;">
                    <thead class="table-light"><tr><th>Date</th><th>Subject</th><th>Start Time</th><th>End Time</th></tr></thead>
                    <tbody>
                        <?php foreach ($exam_rows as $r): ?>
                            <tr>
                                <td><?php echo $r['exam_date'] ? date('d M Y', strtotime($r['exam_date'])) : '—'; ?></td>
                                <td><?php echo sp_e($r['subject_name'] ?: '—'); ?></td>
                                <td><?php echo $r['start_time'] ? date('h:i A', strtotime($r['start_time'])) : '—'; ?></td>
                                <td><?php echo $r['end_time'] ? date('h:i A', strtotime($r['end_time'])) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
