<?php
// student/class_routine.php - নিজের Class/Shift/Section এর সাপ্তাহিক রুটিন
$week_days = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

$routine_rows = [];
$routine_table_ready = true;
try {
    require_once __DIR__ . '/../config/subject_religion.php'; sr_ensure($pdo);
    $stmt = $pdo->prepare(
        "SELECT routines.*, academic_subjects.subject_name, academic_subjects.religion AS subject_religion, employees.name AS teacher_name
         FROM routines
         LEFT JOIN academic_subjects ON academic_subjects.id = routines.subject_id
         LEFT JOIN employees ON employees.id = routines.teacher_id
         WHERE routines.class_id = ? AND routines.section_id = ? AND routines.status = 'Active'
         ORDER BY routines.start_time ASC"
    );
    $stmt->execute([(int) $student['class_id'], (int) $student['section_id']]);
    $all_rows = $stmt->fetchAll();
    foreach ($all_rows as $r) {
        $days = array_filter(array_map('trim', explode(',', (string) $r['routine_days'])));
        foreach ($days as $d) {
            $routine_rows[$d][] = $r;
        }
    }
} catch (\Throwable $e) {
    $routine_table_ready = false;
}

require_once __DIR__ . '/../config/routine_grid.php';
echo rg_css();
function crt_time($t) { return $t ? date('h:i A', strtotime($t)) : ''; }
?>
<div class="card-custom">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <h6 class="fw-bold mb-0" style="color:#0f766e;"><i class="fa-solid fa-table-cells-large me-2"></i>My Class Routine</h6>
        <?php if ($routine_rows): ?><button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button><?php endif; ?>
    </div>

    <?php if (!$routine_table_ready || !$routine_rows): ?>
        <div class="text-center py-4">
            <i class="fa-solid fa-table-cells-large" style="font-size:40px;color:#14b8a6;"></i>
            <p class="text-muted mt-2 mb-0">এখনো আপনার ক্লাসের রুটিন সেট করা হয়নি।</p>
        </div>
    <?php else: ?>
        <?php echo rg_grid_html($week_days, $routine_rows, ['show_teacher' => true, 'highlight_day' => date('l'), 'viewer_religion' => (string) ($student['religion'] ?? '')]); ?>
    <?php endif; ?>
</div>
