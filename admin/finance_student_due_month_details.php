<?php
// admin/finance_student_due_month_details.php - একজন Student-এর একটা নির্দিষ্ট Month (বা Admission)
// এর হেড-ওয়াইজ ব্রেকডাউন — Fees Type, Due, Waived, Paid, Payable।

require_once __DIR__ . '/finance_due_report_common.php';

function smdv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function smd_money($n): string { return number_format((float) $n, 2); }

dr_ensure_tables($pdo);
$MONTHS = dr_months();

$student_id = (int) ($_GET['student_id'] ?? 0);
$session_id = (int) ($_GET['session_id'] ?? 0);
$month      = (int) ($_GET['month'] ?? 0);
$month_label = $month === 0 ? 'Admission' : ($MONTHS[$month] ?? '—');

$stmt = $pdo->prepare(
    "SELECT s.*, c.class_name, sh.shift_name, sec.section_name
     FROM students s
     LEFT JOIN academic_classes c ON c.id = s.class_id
     LEFT JOIN academic_shifts sh ON sh.id = s.shift_id
     LEFT JOIN academic_sections sec ON sec.id = s.section_id
     WHERE s.id = ?"
);
$stmt->execute([$student_id]);
$student = $stmt->fetch();
if ($student && !empty($student['branch_id']) && branch_current_id() !== null && (int) $student['branch_id'] !== branch_current_id()) {
    $student = false;
}

$session_row = $pdo->prepare("SELECT session_name FROM academic_sessions WHERE id = ?");
$session_row->execute([$session_id]);
$session_name = $session_row->fetchColumn() ?: '';

$fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC")->fetchAll();

$items = [];
$t_due = $t_waived = $t_paid = $t_payable = 0.0;
if ($student && $session_id > 0) {
    $items = dr_resolve_period($pdo, $student_id, $session_id, (int) $student['class_id'], $month, $fee_heads);
    foreach ($items as $it) {
        $t_due += $it['dues']; $t_waived += $it['waived']; $t_paid += $it['paid']; $t_payable += $it['payable'];
    }
}

$inst = $pdo->query("SELECT institute_name, phone_number, logo_path FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) { $inst = ['institute_name' => '', 'phone_number' => '', 'logo_path' => null]; }
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
    @media print {
        @page { size: A4 portrait; margin: 10mm; }
        html, body { background: #fff !important; }
        .no-print, .sidebar, .sticky-top-area, .top-navbar, .breadcrumb-bar,
        .rp-filter-card, .alert, footer { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; }
        .content-body { padding: 0 !important; margin: 0 !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
        .table-responsive { overflow: visible !important; }
        .card-custom table { width: 100% !important; font-size: 12px; }
        .card-custom table th, .card-custom table td { padding: 4px 6px !important; }
        .card-custom table a { color: #000 !important; text-decoration: none !important; }
        .card-custom table thead { display: table-header-group; }
        .card-custom table tr { page-break-inside: avoid; }
    }
</style>

<div class="no-print mb-3">
    <a href="index.php?page=finance_students_due_details&student_id=<?php echo (int) $student_id; ?>&session_id=<?php echo (int) $session_id; ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
</div>

<?php if (!$student): ?>
    <div class="alert alert-danger">শিক্ষার্থী খুঁজে পাওয়া যায়নি।</div>
<?php else: ?>
<div class="card-custom">
    <div class="d-flex justify-content-end mb-3 no-print">
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>

    <div style="max-width:900px;margin:0 auto;">
        <div class="text-center mb-3">
            <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo smdv($inst['logo_path']); ?>" style="max-width:60px;max-height:60px;"><br><?php endif; ?>
            <h4 class="fw-bold text-primary mb-1"><?php echo smdv($inst['institute_name']); ?></h4>
            <?php if ($branch && !empty($branch['address'])): ?>
                <div class="small text-muted">(<?php echo smdv($branch['address']); ?><?php echo $branch['city'] ? ', ' . smdv($branch['city']) : ''; ?><?php echo $branch['zip_code'] ? '-' . smdv($branch['zip_code']) : ''; ?>)</div>
            <?php endif; ?>
            <?php if (!empty($inst['phone_number']) || !empty($branch['phone'])): ?>
                <div class="small">Phone: <?php echo smdv($inst['phone_number'] ?: $branch['phone']); ?></div>
            <?php endif; ?>
            <h5 class="text-secondary mt-3 mb-1">Student's Due Report of Session - <?php echo smdv($session_name); ?></h5>
            <div class="fw-bold small">Month : <?php echo smdv($month_label); ?></div>
            <div class="small">
                Student ID : <?php echo smdv($student['student_id']); ?>, Student Name : <?php echo smdv($student['name_en']); ?> <?php echo smdv($student['name_bn']); ?>
            </div>
            <div class="small">
                Class : <?php echo smdv($student['class_name']); ?>, Section : <?php echo smdv($student['section_name']); ?>, Shift : <?php echo smdv($student['shift_name']); ?>, Roll : <?php echo smdv($student['roll_no']); ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px;">Sl.</th>
                        <th>Fees Type</th>
                        <th class="text-end">Due</th>
                        <th class="text-end">Waived</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Payable</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($items): $srl = 1; foreach ($items as $it): ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo smdv($it['name']); ?></td>
                            <td class="text-end"><?php echo smd_money($it['dues']); ?></td>
                            <td class="text-end"><?php echo smd_money($it['waived']); ?></td>
                            <td class="text-end"><?php echo smd_money($it['paid']); ?></td>
                            <td class="text-end"><?php echo smd_money($it['payable']); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">এই মাসে কোনো ফি অ্যাসাইন করা নেই।</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($items): ?>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="2" class="text-end">Total :</td>
                            <td class="text-end"><?php echo smd_money($t_due); ?></td>
                            <td class="text-end"><?php echo smd_money($t_waived); ?></td>
                            <td class="text-end"><?php echo smd_money($t_paid); ?></td>
                            <td class="text-end"><?php echo smd_money($t_payable); ?></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
