<?php
// admin/finance_students_due_details.php - একজন নির্দিষ্ট Student-এর Due Report, মাস-ওয়াইজ
// (শুধু যেসব মাসে/Admission-এ Due > 0 আছে সেগুলোই দেখানো হয়), প্রতিটার পাশে Details বাটন
// (ক্লিক করলে Level 3 — সেই মাসের হেড-ওয়াইজ ব্রেকডাউন) দেখা যায়।

require_once __DIR__ . '/finance_due_report_common.php';

function sddv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function sdd_money($n): string { return number_format((float) $n, 2); }

dr_ensure_tables($pdo);
$MONTHS = dr_months();

$student_id = (int) ($_GET['student_id'] ?? 0);
$session_id = (int) ($_GET['session_id'] ?? 0);

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
// Branch-লক থাকা ইউজার URL-এ id বদলে অন্য branch-এর due details দেখতে পারবে না
if ($student && !empty($student['branch_id']) && branch_current_id() !== null && (int) $student['branch_id'] !== branch_current_id()) {
    $student = false;
}

$session_row = $pdo->prepare("SELECT session_name FROM academic_sessions WHERE id = ?");
$session_row->execute([$session_id]);
$session_name = $session_row->fetchColumn() ?: '';

$fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC")->fetchAll();

$rows = [];
$grand_total = 0.0;

if ($student && $session_id > 0) {
    $class_id = (int) $student['class_id'];

    $adm_items = dr_resolve_period($pdo, $student_id, $session_id, $class_id, 0, $fee_heads);
    $adm_due = array_sum(array_column($adm_items, 'dues'));
    if ($adm_due > 0) {
        $rows[] = ['period' => 0, 'label' => 'Admission', 'due' => $adm_due];
        $grand_total += $adm_due;
    }

    foreach ($MONTHS as $m => $mname) {
        $items = dr_resolve_period($pdo, $student_id, $session_id, $class_id, $m, $fee_heads);
        $due = array_sum(array_column($items, 'dues'));
        if ($due > 0) {
            $rows[] = ['period' => $m, 'label' => $mname, 'due' => $due];
            $grand_total += $due;
        }
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
    <a href="index.php?page=finance_students_dues" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
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
            <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo sddv($inst['logo_path']); ?>" style="max-width:60px;max-height:60px;"><br><?php endif; ?>
            <h4 class="fw-bold text-primary mb-1"><?php echo sddv($inst['institute_name']); ?></h4>
            <?php if ($branch && !empty($branch['address'])): ?>
                <div class="small text-muted">(<?php echo sddv($branch['address']); ?><?php echo $branch['city'] ? ', ' . sddv($branch['city']) : ''; ?><?php echo $branch['zip_code'] ? '-' . sddv($branch['zip_code']) : ''; ?>)</div>
            <?php endif; ?>
            <?php if (!empty($inst['phone_number']) || !empty($branch['phone'])): ?>
                <div class="small">Phone: <?php echo sddv($inst['phone_number'] ?: $branch['phone']); ?></div>
            <?php endif; ?>
            <h5 class="text-secondary mt-3 mb-1">Student's Due Report of Session - <?php echo sddv($session_name); ?></h5>
            <div class="small">
                Student ID : <?php echo sddv($student['student_id']); ?>, Student Name : <?php echo sddv($student['name_en']); ?> <?php echo sddv($student['name_bn']); ?>
            </div>
            <div class="small">
                Class : <?php echo sddv($student['class_name']); ?>, Section : <?php echo sddv($student['section_name']); ?>, Shift : <?php echo sddv($student['shift_name']); ?>, Roll : <?php echo sddv($student['roll_no']); ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:60px;">Sl.</th>
                        <th>Month</th>
                        <th style="width:150px;" class="text-end">Due Amount</th>
                        <th style="width:100px;" class="no-print">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): $srl = 1; foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo sddv($r['label']); ?></td>
                            <td class="text-end fw-bold text-danger"><?php echo sdd_money($r['due']); ?></td>
                            <td class="no-print">
                                <a href="index.php?page=finance_student_due_month_details&student_id=<?php echo (int) $student_id; ?>&session_id=<?php echo (int) $session_id; ?>&month=<?php echo (int) $r['period']; ?>" class="btn btn-sm btn-primary">Details</a>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">এই সেশনে কোনো Due নেই — সব ফি পরিশোধ করা হয়েছে।</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($rows): ?>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="2" class="text-end">Total :</td>
                            <td class="text-end"><?php echo sdd_money($grand_total); ?></td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
