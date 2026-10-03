<?php
// student/my_attendance.php - এই মাসের Attendance সামারি + দৈনিক তালিকা
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];
$sel_month = (int) ($_GET['month'] ?? date('n'));
$sel_year  = (int) ($_GET['year'] ?? date('Y'));

$rows = [];
$present_count = 0;
$absent_count  = 0;
$att_table_ready = true;
try {
    $stmt = $pdo->prepare(
        "SELECT attendance_date, status FROM student_daily_attendance
         WHERE student_id = ? AND MONTH(attendance_date) = ? AND YEAR(attendance_date) = ?
         ORDER BY attendance_date ASC"
    );
    $stmt->execute([$student['id'], $sel_month, $sel_year]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        if ($r['status'] === 'Present') { $present_count++; } else { $absent_count++; }
    }
} catch (\Throwable $e) {
    $att_table_ready = false;
}
$total = $present_count + $absent_count;
$percent = $total > 0 ? round(($present_count / $total) * 100, 1) : 0;
?>
<div class="card-custom">
    <form method="GET" class="row g-2 align-items-end mb-4">
        <input type="hidden" name="page" value="my_attendance">
        <div class="col-auto">
            <label class="form-label fw-bold" style="font-size:12.5px;">Month</label>
            <select name="month" class="form-select form-select-sm">
                <?php foreach ($months as $m => $name): ?>
                    <option value="<?php echo $m; ?>" <?php echo $m === $sel_month ? 'selected' : ''; ?>><?php echo $name; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label fw-bold" style="font-size:12.5px;">Year</label>
            <select name="year" class="form-select form-select-sm">
                <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 3; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y === $sel_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm text-white" style="background:#0f766e;">View</button>
        </div>
    </form>

    <?php if (!$att_table_ready): ?>
        <div class="text-center py-4">
            <i class="fa-solid fa-clipboard-check" style="font-size:40px;color:#14b8a6;"></i>
            <p class="text-muted mt-2 mb-0">এখনো কোনো Attendance ডাটা যুক্ত হয়নি।</p>
        </div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="col-4">
                <div class="p-3 rounded text-center" style="background:#ecfdf5;">
                    <div class="fw-bold fs-4 text-success"><?php echo $present_count; ?></div>
                    <div class="text-muted" style="font-size:12px;">Present</div>
                </div>
            </div>
            <div class="col-4">
                <div class="p-3 rounded text-center" style="background:#fef2f2;">
                    <div class="fw-bold fs-4 text-danger"><?php echo $absent_count; ?></div>
                    <div class="text-muted" style="font-size:12px;">Absent</div>
                </div>
            </div>
            <div class="col-4">
                <div class="p-3 rounded text-center" style="background:#ccfbf1;">
                    <div class="fw-bold fs-4" style="color:#0f766e;"><?php echo $percent; ?>%</div>
                    <div class="text-muted" style="font-size:12px;">Attendance Rate</div>
                </div>
            </div>
        </div>

        <?php if (!$rows): ?>
            <p class="text-muted text-center py-4 mb-0">এই মাসে কোনো Attendance রেকর্ড পাওয়া যায়নি।</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle" style="font-size:13px;">
                    <thead class="table-light"><tr><th>Date</th><th>Day</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?php echo date('d M Y', strtotime($r['attendance_date'])); ?></td>
                                <td><?php echo date('l', strtotime($r['attendance_date'])); ?></td>
                                <td><span class="badge <?php echo $r['status'] === 'Present' ? 'bg-success' : 'bg-danger'; ?>"><?php echo sp_e($r['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
