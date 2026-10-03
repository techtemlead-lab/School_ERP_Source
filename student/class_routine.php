<?php
// student/class_routine.php - নিজের Class/Shift/Section এর সাপ্তাহিক রুটিন
$week_days = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

$routine_rows = [];
$routine_table_ready = true;
try {
    $stmt = $pdo->prepare(
        "SELECT routines.*, academic_subjects.subject_name, employees.name AS teacher_name
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

function crt_time($t) { return $t ? date('h:i A', strtotime($t)) : ''; }
?>
<div class="card-custom">
    <h6 class="fw-bold mb-3" style="color:#0f766e;"><i class="fa-solid fa-table-cells-large me-2"></i>My Class Routine</h6>

    <?php if (!$routine_table_ready || !$routine_rows): ?>
        <div class="text-center py-4">
            <i class="fa-solid fa-table-cells-large" style="font-size:40px;color:#14b8a6;"></i>
            <p class="text-muted mt-2 mb-0">এখনো আপনার ক্লাসের রুটিন সেট করা হয়নি।</p>
        </div>
    <?php else: ?>
        <?php foreach ($week_days as $day): if (empty($routine_rows[$day])) { continue; } ?>
            <h6 class="fw-bold mt-3 mb-2" style="font-size:13px;color:#334155;"><?php echo $day; ?></h6>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle" style="font-size:12.5px;">
                    <thead class="table-light"><tr><th>Time</th><th>Subject</th><th>Teacher</th></tr></thead>
                    <tbody>
                        <?php foreach ($routine_rows[$day] as $r): ?>
                            <tr>
                                <td><?php echo crt_time($r['start_time']); ?> - <?php echo crt_time($r['end_time']); ?></td>
                                <td><?php echo sp_e($r['subject_name'] ?: '—'); ?></td>
                                <td><?php echo sp_e($r['teacher_name'] ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
