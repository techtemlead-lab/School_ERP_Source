<?php
/**
 * scholarship_receipt_print.php - শিক্ষাবৃত্তি প্রদানের রসিদ (Money Receipt ডিজাইনে)
 *
 * ?id=<disbursement id>  অথবা  ?app=<scholarship application id>
 *  - Admin   : যেকোনো রসিদ (পারমিশন থাকলে)
 *  - Student : শুধু নিজের রসিদ
 */

require_once __DIR__ . '/config/runtime.php';
erp_session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/branch_scope.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/lesson_plan_helpers.php';      // lp_print_css(), lp_e()
require_once __DIR__ . '/config/money_receipt_render.php';     // mr_number_to_words()

require_once __DIR__ . '/config/portal_access.php';
$role = erp_portal_link_role(['admin', 'student']);
erp_portal_require_role($pdo, $role, ['scholarship_applications', 'scholarship_disbursement']);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$id  = (int) ($_GET['id'] ?? 0);
$app = (int) ($_GET['app'] ?? 0);

$sql = "SELECT d.*, a.scholarship_type_id, t.name AS type_name,
               st.name_en, st.student_id AS student_code, st.roll_no, st.class_id,
               c.class_name, ses.session_name, sh.shift_name
        FROM scholarship_disbursements d
        JOIN scholarship_awards a ON a.id = d.award_id
        LEFT JOIN scholarship_types t ON t.id = a.scholarship_type_id
        LEFT JOIN students st ON st.id = d.student_id
        LEFT JOIN academic_classes c ON c.id = a.class_id
        LEFT JOIN academic_sessions ses ON ses.id = a.session_id
        LEFT JOIN academic_shifts sh ON sh.id = st.shift_id";
$params = [];
if ($app > 0) { $sql .= " JOIN scholarship_applications sa ON sa.award_id = d.award_id WHERE sa.id = ?"; $params[] = $app; }
else          { $sql .= " WHERE d.id = ?"; $params[] = $id; }

[$scope, $scope_params] = erp_portal_student_scope($pdo, $role, 'd.student_id');
$sql .= " AND $scope"; $params = array_merge($params, $scope_params);
$d = null; $lines = [];
try {
    $stmt = $pdo->prepare($sql . " LIMIT 1"); $stmt->execute($params); $d = $stmt->fetch();
    if ($d) {
        $l = $pdo->prepare("SELECT l.period, l.amount, h.ac_head FROM scholarship_disbursement_lines l
                            LEFT JOIN finance_account_heads h ON h.id = l.ac_head_id WHERE l.disbursement_id = ? ORDER BY l.period, l.id");
        $l->execute([(int) $d['id']]); $lines = $l->fetchAll();
    }
} catch (\Throwable $e) { $d = null; }

if (!$d) { erp_stop(404, 'Receipt unavailable in this portal.'); }
$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch() ?: [];
$branch = null;
try {
    $record_branch = $pdo->prepare('SELECT branch_id FROM students WHERE id=?'); $record_branch->execute([$d['student_id']]);
        $bid = $record_branch->fetchColumn() ?: null;
    if ($bid !== null) { $b = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1"); $b->execute([$bid]); $branch = $b->fetch(); }
    else { $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch(); }
} catch (\Throwable $e) { $branch = null; }
$addr_line = $branch ? trim(($branch['address'] ?? '') . ', ' . ($branch['city'] ?? '') . ' ' . ($branch['zip_code'] ?? ''), ', ') : '';
$logo = !empty($inst['logo_path']) ? $inst['logo_path'] : '';
$months = [0 => 'Admission', 1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
           7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
$copy = $role === 'admin' ? 'Office Copy' : 'Student Copy';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship Receipt<?php echo $d ? ' - ' . lp_e($d['receipt_no']) : ''; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style><?php echo lp_print_css(); ?>
        .sr-amt, table.lp-items thead th.sr-amt { text-align: right; white-space: nowrap; width: 130px; }
        table.lp-items tbody td.sr-amt { font-weight: 400; }
        .sr-total td { font-weight: 700; border-top: 1px solid #9ca3af; text-align: right; }
        .sr-words { font-size: 12px; margin: 2px 0 10px; }
        .sr-note { border: 1px solid #15803d; background: #f0fdf4; color: #15803d; border-radius: 4px; padding: 7px 10px; font-weight: 700; font-size: 12px; display: flex; justify-content: space-between; }
    </style>
</head>
<body>
<div class="lp-bar"><span></span><button type="button" class="btn btn-primary fw-bold" onclick="window.print()">Print / Save PDF</button></div>
<?php if (!$d): ?>
    <div class="lp-sheet" style="min-height:0;"><div class="alert alert-danger mb-0">রসিদ পাওয়া যায়নি, অথবা এখনো বৃত্তি প্রদান (Disbursement) হয়নি, অথবা আপনার দেখার অনুমতি নেই।</div></div>
<?php else:
    $total = (float) $d['total_amount'];
    $dateTxt = date('d F Y', strtotime($d['disbursed_at']));
    ?>
<div class="lp-sheet">
    <?php if ($logo): ?><img src="<?php echo lp_e($logo); ?>" class="lp-watermark" alt=""><?php endif; ?>
    <div class="lp-stamp" style="color:#15803d;border-color:#15803d;"><b>PAID</b><span><?php echo lp_e(date('d/m/Y', strtotime($d['disbursed_at']))); ?></span></div>
    <div class="lp-body">
        <div class="lp-head">
            <div class="lp-head-logo"><?php if ($logo): ?><img src="<?php echo lp_e($logo); ?>" alt=""><?php endif; ?></div>
            <div class="lp-head-text">
                <h1><?php echo lp_e($inst['institute_name'] ?? 'School Name'); ?></h1>
                <?php if ($addr_line !== ''): ?><div class="lp-inst-line">(<?php echo lp_e($addr_line); ?>)</div><?php endif; ?>
                <?php if (!empty($inst['phone_number'])): ?><div class="lp-inst-line">Phone: <?php echo lp_e($inst['phone_number']); ?></div><?php endif; ?>
            </div>
            <div class="lp-head-qr" id="srqr"></div>
        </div>
        <div class="lp-tagrow"><div class="lp-pill">Scholarship Receipt</div><div class="lp-pill-out"><?php echo lp_e($copy); ?></div></div>

        <table class="lp-grid">
            <tr><td><span class="lp-lbl">Session</span><span class="lp-val"><?php echo lp_e($d['session_name'] ?? '—'); ?></span></td>
                <td><span class="lp-lbl">Class</span><span class="lp-val"><?php echo lp_e(trim(($d['class_name'] ?? '') . (!empty($d['shift_name']) ? ' (' . $d['shift_name'] . ')' : ''))); ?></span></td></tr>
            <tr><td><span class="lp-lbl">Student ID</span><span class="lp-val"><?php echo lp_e($d['student_code']); ?></span></td>
                <td><span class="lp-lbl">Roll</span><span class="lp-val"><?php echo lp_e($d['roll_no']); ?></span></td></tr>
            <tr><td colspan="2"><span class="lp-lbl">Student Name</span><span class="lp-val"><?php echo lp_e($d['name_en']); ?></span></td></tr>
            <tr><td><span class="lp-lbl">Date</span><span class="lp-val"><?php echo lp_e($dateTxt); ?></span></td>
                <td><span class="lp-lbl">Receipt No</span><span class="lp-val"><?php echo lp_e($d['receipt_no']); ?></span></td></tr>
            <tr><td><span class="lp-lbl">Scholarship</span><span class="lp-val"><?php echo lp_e($d['type_name'] ?? 'Scholarship'); ?></span></td>
                <td><span class="lp-lbl">Payment Method</span><span class="lp-val"><?php echo lp_e($d['payment_method'] ?: 'Cash'); ?></span></td></tr>
        </table>

        <table class="lp-items">
            <thead><tr><th>Scholarship Distribution</th><th class="sr-amt">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($lines as $ln): ?>
                <tr><td>Adjusted against due — <?php echo lp_e(($ln['ac_head'] ?: 'Fee') . ' (' . ($months[(int) $ln['period']] ?? $ln['period']) . ')'); ?></td>
                    <td class="sr-amt"><?php echo number_format((float) $ln['amount'], 2); ?></td></tr>
            <?php endforeach; ?>
            <?php if ((float) $d['adjusted_amount'] > 0 && !$lines): ?>
                <tr><td>Adjusted against fee dues</td><td class="sr-amt"><?php echo number_format((float) $d['adjusted_amount'], 2); ?></td></tr>
            <?php endif; ?>
            <?php if ((float) $d['cash_amount'] > 0): ?>
                <tr><td>Paid to guardian in hand (<?php echo lp_e($d['payment_method'] ?: 'Cash'); ?><?php echo !empty($d['trx_id']) ? ' · Trx: ' . lp_e($d['trx_id']) : ''; ?>)</td>
                    <td class="sr-amt"><?php echo number_format((float) $d['cash_amount'], 2); ?></td></tr>
            <?php endif; ?>
                <tr class="sr-total"><td>Total :</td><td class="sr-amt"><?php echo number_format($total, 2); ?></td></tr>
            </tbody>
        </table>
        <div class="sr-words"><b><em>In Words :</em></b> <em><?php echo lp_e(ucfirst(mr_number_to_words((int) round($total)))); ?> Tk. Only</em></div>

        <div class="sr-note"><span>Scholarship disbursed successfully</span><span><?php echo lp_e(date('d M Y', strtotime($d['disbursed_at']))); ?></span></div>
        <?php if (trim((string) ($d['remarks'] ?? '')) !== ''): ?><div style="font-size:11px;margin-top:6px;"><b>Remarks:</b> <?php echo lp_e($d['remarks']); ?></div><?php endif; ?>

        <div class="lp-sign-row">
            <div class="lp-sign"><small>&nbsp;</small><div class="lp-sign-line"></div>Received By (Guardian)</div>
            <div class="lp-sign"><small><?php echo lp_e($d['disbursed_by'] ?? ''); ?></small><div class="lp-sign-line"></div>Accounts Officer</div>
        </div>
        <div class="lp-foot">Printed on <?php echo lp_e(date('d M Y, h:i A')); ?></div>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    window.addEventListener('load', function () {
        var el = document.getElementById('srqr');
        if (el && window.QRCode) { new QRCode(el, { text: <?php echo json_encode($d['receipt_no'] . ' | ' . $d['student_code'] . ' | ' . number_format($total, 2), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, width: 58, height: 58 }); }
    });
</script>
<?php endif; ?>
</body>
</html>
