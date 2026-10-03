<?php
// admin/print_academic_calendar.php - Academic Calendar এর প্রিন্ট/PDF ভিউ
// এই প্রজেক্টের অন্য print_*.php ফাইলগুলোর (id card, testimonial, transfer certificate) মতোই প্যাটার্ন —
// ব্রাউজারের Print ডায়ালগ থেকে "Save as PDF" করে ডাউনলোড করা যাবে।
function pcv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) { $inst = ['institute_name' => 'School Name', 'logo_path' => null]; }

$current_session = $pdo->query("SELECT session_name FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$session_name = $current_session['session_name'] ?? date('Y');

$year = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) { $year = (int) date('Y'); }

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

$stmt = $pdo->prepare("SELECT * FROM academic_holidays WHERE status='Active' AND start_date <= ? AND end_date >= ? ORDER BY start_date ASC");
$stmt->execute(["$year-12-31", "$year-01-01"]);
$holidays = $stmt->fetchAll();

$type_colors = [
    'General'    => '#1e3a8a',
    'Government' => '#0f766e',
    'Religious'  => '#a16207',
    'Vacation'   => '#7c3aed',
    'Emergency'  => '#dc2626',
];

// প্রতিটা মাসের প্রতিটা দিনের জন্য কোন হলিডে পড়ে (থাকলে) সেটার ম্যাপ বানানো হলো
$day_map = []; // ['2026-01-15' => holiday row]
foreach ($holidays as $h) {
    $t = strtotime($h['start_date']);
    $end = strtotime($h['end_date']);
    while ($t <= $end) {
        $day_map[date('Y-m-d', $t)] = $h;
        $t += 86400;
    }
}

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];
$weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
?>

<style>
    @media print {
        .no-print { display: none !important; }
        .pac-month-card { break-inside: avoid; page-break-inside: avoid; }
    }
    .pac-wrap { background: #fff; max-width: 1100px; margin: 0 auto; }
    .pac-header { text-align: center; margin-bottom: 18px; }
    .pac-header img { height: 60px; margin-bottom: 6px; }
    .pac-header h3 { margin: 4px 0 0; color: #1e3a8a; font-weight: 800; }
    .pac-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
    .pac-month-card { border: 1px solid #ddd; border-radius: 6px; padding: 8px; }
    .pac-month-title { text-align: center; font-weight: 700; color: #1e3a8a; margin-bottom: 6px; font-size: 14px; }
    table.pac-cal { width: 100%; border-collapse: collapse; font-size: 10.5px; }
    table.pac-cal th { padding: 2px; color: #666; font-weight: 600; }
    table.pac-cal td { padding: 2px; text-align: center; height: 22px; vertical-align: middle; }
    .pac-day-off { color: #fff; border-radius: 3px; font-weight: 700; }
    .pac-legend { display: flex; flex-wrap: wrap; gap: 14px; justify-content: center; margin-top: 20px; font-size: 12px; }
    .pac-legend span.sw { display: inline-block; width: 12px; height: 12px; border-radius: 2px; margin-right: 4px; vertical-align: middle; }
    .pac-list { margin-top: 24px; font-size: 12.5px; }
    .pac-list table { width: 100%; border-collapse: collapse; }
    .pac-list th, .pac-list td { border: 1px solid #ddd; padding: 5px 8px; text-align: left; }
    .pac-list th { background: #f1f5f9; }
</style>

<div class="no-print d-flex justify-content-between align-items-center mb-3">
    <form method="GET" action="index.php" class="d-flex align-items-center gap-2">
        <input type="hidden" name="page" value="print_academic_calendar">
        <label class="fw-bold mb-0">Year</label>
        <select name="year" class="form-select" style="max-width:120px;" onchange="this.form.submit()">
            <?php for ($y = (int) date('Y') - 1; $y <= (int) date('Y') + 2; $y++): ?>
                <option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
            <?php endfor; ?>
        </select>
    </form>
    <div>
        <a href="index.php?page=academic_calendar" class="btn btn-secondary fw-bold"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-file-pdf"></i> Download PDF / Print</button>
    </div>
</div>

<div class="pac-wrap">
    <div class="pac-header">
        <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo tenant_upload_rel('institute'); ?>/<?php echo pcv(basename($inst['logo_path'])); ?>" alt="logo"><?php endif; ?>
        <h3><?php echo pcv($inst['institute_name'] ?? 'School'); ?></h3>
        <div class="text-muted">Academic Calendar — <?php echo $year; ?> (Session: <?php echo pcv($session_name); ?>)</div>
    </div>

    <div class="pac-grid">
        <?php for ($m = 1; $m <= 12; $m++):
            $first_ts = mktime(0, 0, 0, $m, 1, $year);
            $days_in_month = (int) date('t', $first_ts);
            $start_wday = (int) date('w', $first_ts); // 0=Sun
        ?>
            <div class="pac-month-card">
                <div class="pac-month-title"><?php echo $months[$m]; ?></div>
                <table class="pac-cal">
                    <thead><tr><?php foreach ($weekdays as $w): ?><th><?php echo $w; ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                        <?php
                        $day = 1;
                        $cell = 0;
                        echo '<tr>';
                        for ($i = 0; $i < $start_wday; $i++) { echo '<td></td>'; $cell++; }
                        while ($day <= $days_in_month) {
                            if ($cell === 7) { echo '</tr><tr>'; $cell = 0; }
                            $ymd = sprintf('%04d-%02d-%02d', $year, $m, $day);
                            $h = $day_map[$ymd] ?? null;
                            if ($h) {
                                $color = $type_colors[$h['holiday_type']] ?? '#1e3a8a';
                                echo '<td><span class="pac-day-off" style="background:' . $color . ';padding:1px 4px;" title="' . pcv($h['holiday_name']) . '">' . $day . '</span></td>';
                            } else {
                                echo '<td>' . $day . '</td>';
                            }
                            $day++; $cell++;
                        }
                        echo '</tr>';
                        ?>
                    </tbody>
                </table>
            </div>
        <?php endfor; ?>
    </div>

    <div class="pac-legend">
        <?php foreach ($type_colors as $type => $color): ?>
            <span><span class="sw" style="background:<?php echo $color; ?>;"></span><?php echo pcv($type); ?></span>
        <?php endforeach; ?>
    </div>

    <div class="pac-list">
        <h5 style="color:#1e3a8a;font-weight:700;">Holiday List — <?php echo $year; ?></h5>
        <table>
            <thead><tr><th>#</th><th>Holiday Name</th><th>Start Date</th><th>End Date</th><th>Days</th><th>Type</th></tr></thead>
            <tbody>
                <?php if ($holidays): $i = 1; foreach ($holidays as $h): $days = (int) ((strtotime($h['end_date']) - strtotime($h['start_date'])) / 86400) + 1; ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo pcv($h['holiday_name']); ?></td>
                        <td><?php echo pcv($h['start_date']); ?></td>
                        <td><?php echo pcv($h['end_date']); ?></td>
                        <td><?php echo $days; ?></td>
                        <td><?php echo pcv($h['holiday_type']); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6" class="text-center text-muted">এই বছরে কোনো Holiday যোগ করা হয়নি।</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
