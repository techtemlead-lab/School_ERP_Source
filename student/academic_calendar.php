<?php
// student/academic_calendar.php - Academic Calendar (Admin-এর academic_holidays টেবিল থেকে,
// শুধু দেখার জন্য — কোনো Add/Edit/Delete option নেই)
function accv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS academic_holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        holiday_name VARCHAR(150) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        holiday_type VARCHAR(30) NOT NULL DEFAULT 'General',
        remarks VARCHAR(255) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

function acc_color(string $type): string
{
    return match ($type) {
        'Government' => '#2563eb',
        'Religious'  => '#7c3aed',
        'Vacation'   => '#d97706',
        'Emergency'  => '#dc2626',
        default      => '#0f766e',
    };
}
function acc_bg(string $type): string
{
    return match ($type) {
        'Government' => '#eff6ff',
        'Religious'  => '#f5f3ff',
        'Vacation'   => '#fffbeb',
        'Emergency'  => '#fef2f2',
        default      => '#ecfdf5',
    };
}

$upcoming = $pdo->query("SELECT * FROM academic_holidays WHERE status = 'Active' AND end_date >= CURDATE() ORDER BY start_date ASC")->fetchAll();
$past     = $pdo->query("SELECT * FROM academic_holidays WHERE status = 'Active' AND end_date < CURDATE() ORDER BY start_date DESC LIMIT 20")->fetchAll();

function acc_range($start, $end): string
{
    $s = date('d M Y', strtotime($start));
    $e = date('d M Y', strtotime($end));
    return $start === $end ? $s : $s . ' — ' . $e;
}
?>
<div class="card-custom">
    <h6 class="fw-bold mb-3" style="color:#0f766e;"><i class="fa-solid fa-calendar-days me-2"></i>Upcoming Events / Holidays</h6>

    <?php if (!$upcoming): ?>
        <div class="text-center py-4">
            <i class="fa-solid fa-calendar-days" style="font-size:40px;color:#14b8a6;"></i>
            <p class="text-muted mt-2 mb-0">আসন্ন কোনো Event/ছুটি নেই।</p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($upcoming as $ev): $days_left = (int) ceil((strtotime($ev['start_date']) - strtotime(date('Y-m-d'))) / 86400); ?>
                <div class="col-md-6">
                    <div class="p-3 rounded h-100" style="background:<?php echo acc_bg($ev['holiday_type']); ?>;border-left:4px solid <?php echo acc_color($ev['holiday_type']); ?>;">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="fw-bold" style="font-size:14px;"><?php echo accv($ev['holiday_name']); ?></span>
                            <span class="badge" style="background:<?php echo acc_color($ev['holiday_type']); ?>;font-size:10px;"><?php echo accv($ev['holiday_type']); ?></span>
                        </div>
                        <div class="text-muted mt-1" style="font-size:12.5px;"><i class="fa-regular fa-calendar me-1"></i><?php echo acc_range($ev['start_date'], $ev['end_date']); ?></div>
                        <?php if (!empty($ev['remarks'])): ?><div class="mt-1" style="font-size:12px;color:#475569;"><?php echo accv($ev['remarks']); ?></div><?php endif; ?>
                        <div class="mt-2" style="font-size:11.5px;font-weight:700;color:<?php echo acc_color($ev['holiday_type']); ?>;">
                            <?php echo $days_left <= 0 ? 'চলছে' : ($days_left . ' দিন বাকি'); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="card-custom">
    <h6 class="fw-bold mb-3" style="color:#64748b;"><i class="fa-solid fa-clock-rotate-left me-2"></i>Past Events / Holidays</h6>

    <?php if (!$past): ?>
        <p class="text-muted text-center py-3 mb-0">কোনো পুরনো রেকর্ড নেই।</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle" style="font-size:13px;">
                <thead class="table-light"><tr><th>Name</th><th>Date</th><th>Type</th></tr></thead>
                <tbody>
                    <?php foreach ($past as $ev): ?>
                        <tr>
                            <td><?php echo accv($ev['holiday_name']); ?></td>
                            <td><?php echo acc_range($ev['start_date'], $ev['end_date']); ?></td>
                            <td><span class="badge" style="background:<?php echo acc_color($ev['holiday_type']); ?>;font-size:10px;"><?php echo accv($ev['holiday_type']); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
