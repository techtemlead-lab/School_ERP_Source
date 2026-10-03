<?php
// admin/finance_balance_sheet.php - Finance Module > Finance Reports > Balance Sheet
// একটা নির্দিষ্ট Month+Year (অথবা Start-End Date রেঞ্জ) পর্যন্ত (cut-off তারিখ) হিসাব করে
// Current Liabilities (আপাতত কোনো Payable মডিউল নেই তাই 0.00), Current Assets (Students Dues,
// Class-ভিত্তিক), এবং Profit & Loss (Cash/Bank Received-Payment) — এই তিন সেকশন দেখায়।

require_once __DIR__ . '/finance_due_report_common.php';

function bsv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function bs_money($n): string { return number_format((float) $n, 2); }

dr_ensure_tables($pdo);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_vouchers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        voucher_no VARCHAR(50) NOT NULL,
        voucher_type VARCHAR(20) NOT NULL,
        transaction_date DATE NOT NULL,
        payment_type VARCHAR(10) NOT NULL DEFAULT 'Cash',
        description TEXT NULL,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_voucher_no (voucher_no),
        KEY idx_type (voucher_type), KEY idx_date (transaction_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_voucher_details (
        id INT AUTO_INCREMENT PRIMARY KEY,
        voucher_id INT NOT NULL,
        ac_head_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        remarks VARCHAR(255) NULL,
        KEY idx_voucher (voucher_id), KEY idx_head (ac_head_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$MONTHS = dr_months();
$fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC")->fetchAll();
$current_session = $pdo->query("SELECT id, session_name FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();

// -----------------------------------------------------------
// ফিল্টার
// -----------------------------------------------------------
$searched  = isset($_GET['search']);
$this_year = (int) date('Y');
$f_month   = (int) ($_GET['f_month'] ?? (int) date('n'));
$f_year    = (int) ($_GET['f_year'] ?? $this_year);
$f_start   = trim($_GET['f_start'] ?? '');
$f_end     = trim($_GET['f_end'] ?? '');

$use_range = ($f_start !== '' && $f_end !== '');
if ($use_range && strtotime($f_start) > strtotime($f_end)) { [$f_start, $f_end] = [$f_end, $f_start]; }

$dues_by_class = [];
$total_dues = 0.0;
$cash_received = 0.0;
$cash_payment = 0.0;
$bank_received = 0.0;
$bank_payment = 0.0;
$report_title = '';
$as_on_label = '';

if ($searched) {
    if ($use_range) {
        $date_to = $f_end;
        $report_title = 'Balance Sheet from ' . bsv(date('d M Y', strtotime($f_start))) . ' to ' . bsv(date('d M Y', strtotime($f_end)));
        $as_on_label = 'As on ' . date('d M Y', strtotime($f_end));
    } else {
        if ($f_month < 1 || $f_month > 12) { $f_month = (int) date('n'); }
        $date_to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $f_year, $f_month)));
        $report_title = 'Balance Sheet for the month of ' . ($MONTHS[$f_month] ?? '') . ' ' . $f_year;
        $as_on_label = 'As on ' . ($MONTHS[$f_month] ?? '') . ' ' . $f_year;
    }

    // ---- Current Assets : সেশনের Active শিক্ষার্থীদের Class-ভিত্তিক মোট Due ----
    if ($current_session) {
        $dues_by_class = dr_dues_by_class($pdo, (int) $current_session['id'], $fee_heads, (int) date('n'));
        foreach ($dues_by_class as $d) { $total_dues += $d['due']; }
    }

    // ---- Profit & Loss : date_to পর্যন্ত সব Cash/Bank Received/Payment ----
    $bs_bf = branch_current_id();
    $bs_fc_bf = $bs_bf !== null ? " AND student_id IN (SELECT id FROM students WHERE branch_id = ?)" : "";
    $bs_v_bf  = $bs_bf !== null ? " AND (v.branch_id = ?)" : "";
    $bs_fc_params = $bs_bf !== null ? [$date_to, $bs_bf] : [$date_to];
    $bs_v_params  = $bs_bf !== null ? [$date_to, $bs_bf] : [$date_to];

    $p = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM finance_fee_collections WHERE status = 'Paid' AND payment_method = 'Cash' AND DATE(collected_at) <= ?$bs_fc_bf");
    $p->execute($bs_fc_params);
    $cash_received += (float) $p->fetchColumn();

    $p = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM finance_fee_collections WHERE status = 'Paid' AND payment_method <> 'Cash' AND DATE(collected_at) <= ?$bs_fc_bf");
    $p->execute($bs_fc_params);
    $bank_received += (float) $p->fetchColumn();

    $p = $pdo->prepare("SELECT COALESCE(SUM(vd.amount),0) FROM finance_voucher_details vd JOIN finance_vouchers v ON v.id = vd.voucher_id WHERE v.voucher_type = 'Receive' AND v.payment_type = 'Cash' AND DATE(v.transaction_date) <= ?$bs_v_bf");
    $p->execute($bs_v_params);
    $cash_received += (float) $p->fetchColumn();

    $p = $pdo->prepare("SELECT COALESCE(SUM(vd.amount),0) FROM finance_voucher_details vd JOIN finance_vouchers v ON v.id = vd.voucher_id WHERE v.voucher_type = 'Payment' AND v.payment_type = 'Cash' AND DATE(v.transaction_date) <= ?$bs_v_bf");
    $p->execute($bs_v_params);
    $cash_payment += (float) $p->fetchColumn();

    $p = $pdo->prepare("SELECT COALESCE(SUM(vd.amount),0) FROM finance_voucher_details vd JOIN finance_vouchers v ON v.id = vd.voucher_id WHERE v.voucher_type = 'Receive' AND v.payment_type = 'Bank' AND DATE(v.transaction_date) <= ?$bs_v_bf");
    $p->execute($bs_v_params);
    $bank_received += (float) $p->fetchColumn();

    $p = $pdo->prepare("SELECT COALESCE(SUM(vd.amount),0) FROM finance_voucher_details vd JOIN finance_vouchers v ON v.id = vd.voucher_id WHERE v.voucher_type = 'Payment' AND v.payment_type = 'Bank' AND DATE(v.transaction_date) <= ?$bs_v_bf");
    $p->execute($bs_v_params);
    $bank_payment += (float) $p->fetchColumn();
}

$inst = $pdo->query("SELECT institute_name, phone_number, logo_path FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) { $inst = ['institute_name' => 'School Name', 'phone_number' => '', 'logo_path' => null]; }
$fr_bid = branch_current_id();
if ($fr_bid !== null) {
    $branch_stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $branch_stmt->execute([$fr_bid]);
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
        .bs-report-wrap { max-width: 100% !important; box-shadow: none !important; border: none !important; }
        .table-responsive { overflow: visible !important; }
        .card-custom table { width: 100% !important; font-size: 12px; }
        .card-custom table th, .card-custom table td { padding: 4px 6px !important; }
        .card-custom table a { color: #000 !important; text-decoration: none !important; }
        .card-custom table thead { display: table-header-group; }
        .card-custom table tr { page-break-inside: avoid; }
    }
    .bs-section-head td { background: #f1f3f5; font-weight: 700; text-decoration: underline; }
    .bs-sub-total td { border-top: 1px solid #333 !important; font-weight: 700; }
</style>

<div class="card-custom mb-3 rp-filter-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0 text-secondary">Balance Sheet</h6>
        <div>
            <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-bs-toggle="collapse" data-bs-target="#bsFilterBody"><i class="fa-solid fa-chevron-up"></i></button>
            <a href="index.php?page=finance_balance_sheet" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i></a>
        </div>
    </div>

    <div id="bsFilterBody" class="collapse show">
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="finance_balance_sheet">
            <input type="hidden" name="search" value="1">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">Month</label>
                <div class="col-sm-6">
                    <select name="f_month" class="form-select">
                        <?php foreach ($MONTHS as $mid => $mname): ?>
                            <option value="<?php echo (int) $mid; ?>" <?php echo $f_month === (int) $mid ? 'selected' : ''; ?>><?php echo bsv($mname); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">Year</label>
                <div class="col-sm-6">
                    <select name="f_year" class="form-select">
                        <?php for ($y = $this_year + 1; $y >= $this_year - 6; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $f_year === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <div class="text-center fw-bold text-muted mb-3">OR</div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">Start Date</label>
                <div class="col-sm-6">
                    <input type="text" name="f_start" class="form-control" placeholder="i.e : 2018-07-01" value="<?php echo bsv($f_start); ?>">
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">End Date</label>
                <div class="col-sm-6">
                    <input type="text" name="f_end" class="form-control" placeholder="i.e : 2019-06-30" value="<?php echo bsv($f_end); ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-primary px-4">Submit</button>
        </form>
    </div>
</div>

<?php if ($searched): ?>
<div class="d-flex justify-content-end mb-3 no-print">
    <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
</div>

<div class="card-custom">
    <div class="bs-report-wrap" style="max-width:900px;margin:0 auto;">
        <div class="text-center mb-3">
            <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo bsv($inst['logo_path']); ?>" style="max-width:60px;max-height:60px;"><br><?php endif; ?>
            <h4 class="fw-bold text-primary mb-1"><?php echo bsv($inst['institute_name']); ?></h4>
            <?php if ($branch && !empty($branch['address'])): ?>
                <div class="small text-muted">(<?php echo bsv($branch['address']); ?><?php echo $branch['city'] ? ', ' . bsv($branch['city']) : ''; ?><?php echo $branch['zip_code'] ? '-' . bsv($branch['zip_code']) : ''; ?>)</div>
            <?php endif; ?>
            <?php if (!empty($inst['phone_number']) || !empty($branch['phone'])): ?>
                <div class="small">Phone: <?php echo bsv($inst['phone_number'] ?: $branch['phone']); ?></div>
            <?php endif; ?>
            <h5 class="text-secondary mt-3 mb-1"><u><?php echo bsv($report_title); ?></u></h5>
            <?php if ($current_session): ?><div class="small">Session : <?php echo bsv($current_session['session_name']); ?></div><?php endif; ?>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Particulars</th>
                        <th class="text-end"><?php echo bsv($as_on_label); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="bs-section-head"><td colspan="2">CURRENT LIABILITIES</td></tr>
                    <tr><td></td><td class="text-end fw-bold">0.00</td></tr>

                    <tr class="bs-section-head"><td colspan="2">CURRENT ASSETS</td></tr>
                    <?php if ($dues_by_class): foreach ($dues_by_class as $d): ?>
                        <tr>
                            <td>Dues from Students (<?php echo bsv($d['class_name']); ?>)</td>
                            <td class="text-end"><?php echo bs_money($d['due']); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td class="text-muted">কোনো Due নেই</td><td class="text-end">0.00</td></tr>
                    <?php endif; ?>
                    <tr class="bs-sub-total"><td></td><td class="text-end"><?php echo bs_money($total_dues); ?></td></tr>

                    <tr class="bs-section-head"><td colspan="2">PROFIT &amp; LOSS</td></tr>
                    <tr><td>Total Received (Cash)</td><td class="text-end"><?php echo bs_money($cash_received); ?></td></tr>
                    <tr><td>Total Payment (Cash)</td><td class="text-end"><?php echo bs_money($cash_payment); ?></td></tr>
                    <tr class="bs-sub-total"><td></td><td class="text-end"><?php echo bs_money($cash_received - $cash_payment); ?></td></tr>

                    <tr><td>Total Received / Deposited (Bank)</td><td class="text-end"><?php echo bs_money($bank_received); ?></td></tr>
                    <tr><td>Total Payment / Withdraw (Bank)</td><td class="text-end"><?php echo bs_money($bank_payment); ?></td></tr>
                    <tr class="bs-sub-total"><td></td><td class="text-end"><?php echo bs_money($bank_received - $bank_payment); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
