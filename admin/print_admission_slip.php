<?php
// admin/print_admission_slip.php - Admission Module > Applied Student List > Print Admission Slip
// Applicant List থেকে "Print Admission Slip" বাটনে ক্লিক করলে এই পেজ ওপেন হয় — public admission_slip.php-এর
// মতোই লেআউট, কিন্তু এটা admin session দিয়ে সরাসরি id ধরে প্রিন্ট করার জন্য (token লাগে না)।
function apsv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// institute_settings এ website কলাম না থাকলে নিরাপদে যোগ করে দেওয়া হচ্ছে
// (পাবলিক admission_slip.php-তে এই চেক আছে, admin কপিতে ছিল না — সেই কারণে crash করতো)
$aps_col_chk = $pdo->prepare(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'institute_settings' AND COLUMN_NAME = 'website'"
);
$aps_col_chk->execute();
if (!$aps_col_chk->fetch()) {
    $pdo->exec("ALTER TABLE institute_settings ADD COLUMN website VARCHAR(255) NULL");
}

$id = (int) ($_GET['id'] ?? 0);
$student = null;
if ($id > 0) {
    $stmt = $pdo->prepare(
        "SELECT s.*, c.class_name, sh.shift_name, sec.section_name, ses.session_name, g.group_name, m.medium_name
         FROM students s
         LEFT JOIN academic_classes c ON c.id = s.class_id
         LEFT JOIN academic_shifts sh ON sh.id = s.shift_id
         LEFT JOIN academic_sections sec ON sec.id = s.section_id
         LEFT JOIN academic_sessions ses ON ses.id = s.session_id
         LEFT JOIN academic_groups g ON g.id = s.group_id
         LEFT JOIN academic_mediums m ON m.id = c.medium_id
         WHERE s.id = ?"
    );
    $stmt->execute([$id]);
    $student = $stmt->fetch();
}

$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) { $inst = ['institute_name' => '', 'phone_number' => '', 'logo_path' => null, 'website' => '']; }
$fr_bid2 = branch_current_id();
if ($fr_bid2 !== null) {
    $branch_stmt2 = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $branch_stmt2->execute([$fr_bid2]);
    $branch = $branch_stmt2->fetch();
} else {
    $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
}
?>
<style>
    @media print { .no-print { display: none !important; } body { background: #fff; } }
    .aps-wrap { max-width: 760px; margin: 0 auto; }
    .aps-card { background: #fff; border: 1px solid #e0e4e8; border-radius: 6px; padding: 26px 30px; position: relative; }
    .aps-header { text-align: center; }
    .aps-logo { position: absolute; left: 24px; top: 24px; max-width: 60px; max-height: 60px; }
    .aps-photo { position: absolute; right: 24px; top: 24px; width: 70px; height: 80px; border: 1px solid #ccc; object-fit: cover; background: #eee; }
    .aps-photo-placeholder { position: absolute; right: 24px; top: 24px; width: 70px; height: 80px; border: 1px solid #ccc; background: #eee; display: flex; align-items: center; justify-content: center; color: #aaa; font-size: 30px; }
    .aps-school-name { color: #0a2540; font-weight: 800; font-size: 21px; margin: 4px 0 2px; }
    .aps-school-meta { font-size: 12.5px; color: #555; margin: 2px 0; }
    .aps-slip-title { margin-top: 14px; font-weight: 700; text-decoration: underline; font-size: 15px; }
    table.aps-table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 14px; }
    table.aps-table td { border: 1px solid #d7dde3; padding: 10px 14px; }
    table.aps-table td.aps-label { background: #f1f6fb; font-weight: 700; width: 25%; }
    .aps-sign { text-align: right; margin-top: 40px; padding-top: 6px; border-top: 1px solid #333; width: 220px; margin-left: auto; font-size: 12.5px; color: #333; }
</style>

<div class="aps-wrap">
<?php if (!$student): ?>
    <div class="alert alert-danger">Applicant খুঁজে পাওয়া যায়নি।</div>
    <a href="index.php?page=admission_applicants" class="btn btn-outline-secondary btn-sm no-print"><i class="fa-solid fa-arrow-left"></i> Applicant List</a>
<?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <a href="index.php?page=admission_applicants" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Applicant List</a>
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>

    <div class="aps-card">
        <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo apsv($inst['logo_path']); ?>" class="aps-logo"><?php endif; ?>
        <?php if (!empty($student['photo_path'])): ?>
            <img src="../<?php echo apsv($student['photo_path']); ?>" class="aps-photo">
        <?php else: ?>
            <div class="aps-photo-placeholder">&#128100;</div>
        <?php endif; ?>

        <div class="aps-header">
            <div class="aps-school-name"><?php echo apsv($inst['institute_name']); ?></div>
            <?php if ($branch && !empty($branch['address'])): ?>
                <div class="aps-school-meta">(<?php echo apsv($branch['address']); ?><?php echo $branch['city'] ? ', ' . apsv($branch['city']) : ''; ?><?php echo $branch['zip_code'] ? '-' . apsv($branch['zip_code']) : ''; ?>)</div>
            <?php endif; ?>
            <?php $phone = $inst['phone_number'] ?: ($branch['phone'] ?? ''); ?>
            <?php if ($phone): ?><div class="aps-school-meta">Phone: <?php echo apsv($phone); ?></div><?php endif; ?>
            <?php if (!empty($inst['website'])): ?><div class="aps-school-meta" style="color:#1565c0;"><?php echo apsv($inst['website']); ?></div><?php endif; ?>
            <div class="aps-slip-title">Admission Slip for Session : <?php echo apsv($student['session_name']); ?></div>
        </div>

        <table class="aps-table">
            <tr>
                <td class="aps-label">Applicant ID</td><td><?php echo apsv($student['student_id']); ?></td>
                <td class="aps-label">Applicant Name</td><td><?php echo apsv($student['name_en']); ?></td>
            </tr>
            <tr>
                <td class="aps-label">Medium</td><td><?php echo apsv($student['medium_name'] ?: 'N/A'); ?></td>
                <td class="aps-label">Shift</td><td><?php echo apsv($student['shift_name']); ?></td>
            </tr>
            <tr>
                <td class="aps-label">Class</td><td><?php echo apsv($student['class_name']); ?></td>
                <td class="aps-label">App. No (Roll)</td><td><?php echo $student['roll_no'] !== '' ? apsv($student['roll_no']) : 'পরে জানানো হবে (মেধাক্রম/লটারি অনুযায়ী)'; ?></td>
            </tr>
            <tr>
                <td class="aps-label">Section</td><td><?php echo apsv($student['section_name']); ?></td>
                <td class="aps-label">Group</td><td><?php echo apsv($student['group_name'] ?: 'N/A'); ?></td>
            </tr>
            <tr>
                <td class="aps-label">Mobile</td><td><?php echo apsv($student['mobile'] ?: $student['guardian_phone']); ?></td>
                <td class="aps-label">Status</td><td><?php echo apsv($student['status']); ?></td>
            </tr>
        </table>

        <div class="aps-sign">Signature of Authority</div>
    </div>
<?php endif; ?>
</div>
