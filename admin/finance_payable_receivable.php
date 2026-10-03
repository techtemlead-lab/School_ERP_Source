<?php
// admin/finance_payable_receivable.php - Finance Module > Finance Reports > Payable / Receivable
// চলতি মাস পর্যন্ত (Session-এর Active শিক্ষার্থীদের) Class-ভিত্তিক মোট Due-কে Receivable হিসেবে,
// এবং (আপাতত কোনো Payable মডিউল না থাকায় খালি) Payable লিস্ট পাশাপাশি দেখায় — কোনো ফিল্টার ফর্ম
// ছাড়াই সরাসরি চলতি মাস/সেশনের হিসাব প্রিন্টযোগ্য আকারে দেখানো হয়।

require_once __DIR__ . '/finance_due_report_common.php';

function prv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function pr_money($n): string { return number_format((float) $n, 2); }

dr_ensure_tables($pdo);
$MONTHS = dr_months();

$fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC")->fetchAll();
$current_session = $pdo->query("SELECT id, session_name FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_month = (int) date('n');

$receivable_rows = [];
$receivable_total = 0.0;
if ($current_session) {
    $receivable_rows = dr_dues_by_class($pdo, (int) $current_session['id'], $fee_heads, $current_month);
    foreach ($receivable_rows as $r) { $receivable_total += $r['due']; }
}

// আপাতত কোনো Payable (Employee Salary Due / Vendor Bill ইত্যাদি) মডিউল তৈরি হয়নি,
// তাই এই লিস্ট খালি থাকবে — ভবিষ্যতে সেই মডিউল বানানো হলে এখানে যোগ করা যাবে।
$payable_rows = [];
$payable_total = 0.0;

$balance = $receivable_total - $payable_total;
$till_label = 'till Month : ' . ($MONTHS[$current_month] ?? '') . ' ' . date('Y');

$inst = $pdo->query("SELECT institute_name, phone_number, logo_path FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) { $inst = ['institute_name' => 'School Name', 'phone_number' => '', 'logo_path' => null]; }
$prb_bid = branch_current_id();
if ($prb_bid !== null) {
    $branch_stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $branch_stmt->execute([$prb_bid]);
    $branch = $branch_stmt->fetch();
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
        .pr-report-wrap { max-width: 100% !important; box-shadow: none !important; border: none !important; }
        .table-responsive { overflow: visible !important; }
        .card-custom table { width: 100% !important; font-size: 12px; }
        .card-custom table th, .card-custom table td { padding: 4px 6px !important; }
        .card-custom table a { color: #000 !important; text-decoration: none !important; }
        .card-custom table thead { display: table-header-group; }
        .card-custom table tr { page-break-inside: avoid; }
    }
</style>

<div class="card-custom mb-3 rp-filter-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0 text-secondary">Accounts Payable / Receivable</h6>
        <div>
            <a href="index.php?page=finance_payable_receivable" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i></a>
        </div>
    </div>
</div>

<div class="d-flex justify-content-end mb-3 no-print">
    <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
</div>

<div class="card-custom">
    <div class="pr-report-wrap" style="max-width:900px;margin:0 auto;">
        <div class="text-center mb-3">
            <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo prv($inst['logo_path']); ?>" style="max-width:60px;max-height:60px;"><br><?php endif; ?>
            <h4 class="fw-bold text-primary mb-1"><?php echo prv($inst['institute_name']); ?></h4>
            <?php if ($branch && !empty($branch['address'])): ?>
                <div class="small text-muted">(<?php echo prv($branch['address']); ?><?php echo $branch['city'] ? ', ' . prv($branch['city']) : ''; ?><?php echo $branch['zip_code'] ? '-' . prv($branch['zip_code']) : ''; ?>)</div>
            <?php endif; ?>
            <?php if (!empty($inst['phone_number']) || !empty($branch['phone'])): ?>
                <div class="small">Phone: <?php echo prv($inst['phone_number'] ?: $branch['phone']); ?></div>
            <?php endif; ?>
            <h5 class="text-secondary mt-3 mb-1"><u>Accounts Payable / Receivable <?php echo prv($till_label); ?></u></h5>
            <?php if ($current_session): ?><div class="small">Session : <?php echo prv($current_session['session_name']); ?></div><?php endif; ?>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <table class="table table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr><th colspan="3" class="text-center">Receivable</th></tr>
                        <tr>
                            <th style="width:40px;">Sl.</th>
                            <th>Particulars</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($receivable_rows): $srl = 1; foreach ($receivable_rows as $r): ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td><?php echo prv($r['class_name']); ?></td>
                                <td class="text-end"><?php echo pr_money($r['due']); ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td class="text-center">-</td><td class="text-center">-</td><td class="text-center">-</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold table-light">
                            <td colspan="2" class="text-end">Total :</td>
                            <td class="text-end"><?php echo pr_money($receivable_total); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="col-md-6">
                <table class="table table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr><th colspan="3" class="text-center">Payable</th></tr>
                        <tr>
                            <th>Month / Year</th>
                            <th>Particulars</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($payable_rows): foreach ($payable_rows as $p): ?>
                            <tr>
                                <td><?php echo prv($p['month_year']); ?></td>
                                <td><?php echo prv($p['particulars']); ?></td>
                                <td class="text-end"><?php echo pr_money($p['amount']); ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td class="text-center">-</td><td class="text-center">-</td><td class="text-center">-</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold table-light">
                            <td colspan="2" class="text-end">Total :</td>
                            <td class="text-end"><?php echo pr_money($payable_total); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="text-end fw-bold mt-3">Balance : <?php echo pr_money($balance); ?></div>
    </div>
</div>
