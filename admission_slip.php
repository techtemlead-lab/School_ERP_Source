<?php
// admission_slip.php - Register করার পর রিডাইরেক্ট হয়ে আসা প্রিন্টযোগ্য Admission Slip
require_once __DIR__ . '/config/runtime.php';
erp_session_start();
require_once __DIR__ . '/config/db.php';

function asv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// institute_settings এ website কলাম না থাকলে নিরাপদে যোগ করে দেওয়া হচ্ছে
$col_chk = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'institute_settings' AND COLUMN_NAME = 'website'"
)->fetchColumn();
if (!$col_chk) {
    $pdo->exec("ALTER TABLE institute_settings ADD COLUMN website VARCHAR(255) NULL");
}

$token = trim($_GET['token'] ?? '');
$student = null;
if ($token !== '') {
    $stmt = $pdo->prepare(
        "SELECT s.*, c.class_name, sh.shift_name, sec.section_name, ses.session_name, g.group_name, m.medium_name
         FROM students s
         LEFT JOIN academic_classes c ON c.id = s.class_id
         LEFT JOIN academic_shifts sh ON sh.id = s.shift_id
         LEFT JOIN academic_sections sec ON sec.id = s.section_id
         LEFT JOIN academic_sessions ses ON ses.id = s.session_id
         LEFT JOIN academic_groups g ON g.id = s.group_id
         LEFT JOIN academic_mediums m ON m.id = c.medium_id
         WHERE s.admission_slip_token = ?"
    );
    $stmt->execute([$token]);
    $student = $stmt->fetch();
}

$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) { $inst = ['institute_name' => '', 'phone_number' => '', 'logo_path' => null, 'website' => '']; }
$branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admission Slip</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f8; margin: 0; padding: 20px; }
    .as-wrap { max-width: 760px; margin: 0 auto; }
    .as-banner { background: #d7f3e3; color: #1b5e20; padding: 12px 18px; border-radius: 6px; font-size: 14px; margin-bottom: 16px; }
    .as-print-btn-row { text-align: right; margin-bottom: 14px; }
    .as-print-btn { background: #1565c0; color: #fff; border: none; padding: 10px 20px; border-radius: 5px; font-weight: 700; font-size: 14px; cursor: pointer; }
    .as-card { background: #fff; border: 1px solid #e0e4e8; border-radius: 6px; padding: 26px 30px; position: relative; }
    .as-header { text-align: center; }
    .as-logo { position: absolute; left: 24px; top: 24px; max-width: 60px; max-height: 60px; }
    .as-photo { position: absolute; right: 24px; top: 24px; width: 70px; height: 80px; border: 1px solid #ccc; object-fit: cover; background: #eee; }
    .as-photo-placeholder { position: absolute; right: 24px; top: 24px; width: 70px; height: 80px; border: 1px solid #ccc; background: #eee; display: flex; align-items: center; justify-content: center; color: #aaa; font-size: 30px; }
    .as-school-name { color: #0a2540; font-weight: 800; font-size: 21px; margin: 4px 0 2px; }
    .as-school-meta { font-size: 12.5px; color: #555; margin: 2px 0; }
    .as-slip-title { margin-top: 14px; font-weight: 700; text-decoration: underline; font-size: 15px; }
    table.as-table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 14px; }
    table.as-table td { border: 1px solid #d7dde3; padding: 10px 14px; }
    table.as-table td.as-label { background: #f1f6fb; font-weight: 700; width: 25%; }
    .as-sign { text-align: right; margin-top: 40px; padding-top: 6px; border-top: 1px solid #333; width: 220px; margin-left: auto; font-size: 12.5px; color: #333; }
    @media print {
        body { background: #fff; padding: 0; }
        .as-print-btn-row, .as-banner { display: none !important; }
        .as-card { border: none; }
    }
</style>
</head>
<body>
<div class="as-wrap">
<?php if (!$student): ?>
    <div class="as-banner" style="background:#fdecea;color:#b71c1c;">Admission Slip খুঁজে পাওয়া যায়নি। লিংকটা সঠিক কিনা যাচাই করুন।</div>
<?php else: ?>
    <?php if ($student['status'] === 'Pending'): ?>
        <div class="as-banner">Your Admission process is completed &amp; pending for admin approval, please print admission slip for further uses.</div>
    <?php endif; ?>
    <?php if (($_GET['payment'] ?? '') === 'pending'): ?>
        <div class="as-banner" style="background:#fff8e1;color:#8a6d00;">আপনার Admission Fee পেমেন্ট তথ্য জমা হয়েছে — Admin যাচাই করে Approve করার পর তা নিশ্চিত হবে।</div>
    <?php elseif (($_GET['payment'] ?? '') === 'success'): ?>
        <div class="as-banner" style="background:#e8f5e9;color:#1b5e20;">আপনার Admission Fee পেমেন্ট সফল হয়েছে — ধন্যবাদ।</div>
    <?php elseif (($_GET['payment'] ?? '') === 'failed'): ?>
        <div class="as-banner" style="background:#fdecea;color:#b71c1c;">পেমেন্ট সম্পন্ন হয়নি — Admission আবেদন জমা হয়ে গেছে, তবে ফি এখনো বাকি আছে। স্কুলে যোগাযোগ করে ম্যানুয়ালি পরিশোধ করতে পারবেন।</div>
    <?php endif; ?>

    <div class="as-print-btn-row">
        <button type="button" class="as-print-btn" onclick="window.print()">🖶 Print</button>
    </div>

    <div class="as-card">
        <?php if (!empty($inst['logo_path'])): ?><img src="<?php echo asv($inst['logo_path']); ?>" class="as-logo"><?php endif; ?>
        <?php if (!empty($student['photo_path'])): ?>
            <img src="<?php echo asv($student['photo_path']); ?>" class="as-photo">
        <?php else: ?>
            <div class="as-photo-placeholder">&#128100;</div>
        <?php endif; ?>

        <div class="as-header">
            <div class="as-school-name"><?php echo asv($inst['institute_name']); ?></div>
            <?php if ($branch && !empty($branch['address'])): ?>
                <div class="as-school-meta">(<?php echo asv($branch['address']); ?><?php echo $branch['city'] ? ', ' . asv($branch['city']) : ''; ?><?php echo $branch['zip_code'] ? '-' . asv($branch['zip_code']) : ''; ?>)</div>
            <?php endif; ?>
            <?php $phone = $inst['phone_number'] ?: ($branch['phone'] ?? ''); ?>
            <?php if ($phone): ?><div class="as-school-meta">Phone: <?php echo asv($phone); ?></div><?php endif; ?>
            <?php if (!empty($inst['website'])): ?><div class="as-school-meta" style="color:#1565c0;"><?php echo asv($inst['website']); ?></div><?php endif; ?>
            <div class="as-slip-title">Admission Slip for Session : <?php echo asv($student['session_name']); ?></div>
        </div>

        <table class="as-table">
            <tr>
                <td class="as-label">Student ID</td><td><?php echo asv($student['student_id']); ?></td>
                <td class="as-label">Student Name</td><td><?php echo asv($student['name_en']); ?></td>
            </tr>
            <tr>
                <td class="as-label">Medium</td><td><?php echo asv($student['medium_name'] ?: 'N/A'); ?></td>
                <td class="as-label">Shift</td><td><?php echo asv($student['shift_name']); ?></td>
            </tr>
            <tr>
                <td class="as-label">Class</td><td><?php echo asv($student['class_name']); ?></td>
                <td class="as-label">Roll No</td><td><?php echo $student['roll_no'] !== '' ? asv($student['roll_no']) : 'পরে জানানো হবে (মেধাক্রম/লটারি অনুযায়ী)'; ?></td>
            </tr>
            <tr>
                <td class="as-label">Section</td><td><?php echo asv($student['section_name']); ?></td>
                <td class="as-label">Group</td><td><?php echo asv($student['group_name'] ?: 'N/A'); ?></td>
            </tr>
        </table>

        <div class="as-sign">Signature of Authority</div>
    </div>
<?php endif; ?>
</div>
</body>
</html>
