<?php
// admin/finance_trial_balance.php - Finance Module > Finance Reports > Trial Balance
// একটা নির্দিষ্ট Month+Year (অথবা Start-End Date রেঞ্জ) + ঐচ্ছিক A/C Head + ঐচ্ছিক Cash/Bank
// দিয়ে ফিল্টার করে Student Fee Collections + Receive/Payment Voucher — দুটো সোর্স মিলিয়ে
// প্রতিটা A/C Head-এর Receive/Deposit ও Payment/Withdraw টোটাল একটা প্রিন্টযোগ্য Trial Balance আকারে দেখায়।

function tbv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function tb_money($n): string { return number_format((float) $n, 2); }

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি (অন্যান্য Finance পেজের সাথে শেয়ার্ড)
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_fee_collections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        session_id INT NOT NULL,
        period_type ENUM('Admission','Monthly') NOT NULL,
        month TINYINT NOT NULL DEFAULT 0,
        ac_head_id INT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash',
        trx_id VARCHAR(100) NULL,
        folio_no VARCHAR(50) NULL,
        receipt_no VARCHAR(50) NULL,
        collected_by VARCHAR(100) NULL,
        collected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'Paid',
        KEY idx_lookup (student_id, session_id, period_type, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
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

$MONTHS = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

$ac_heads = $pdo->query(
    "SELECT id, ac_code, ac_head FROM finance_account_heads
     WHERE has_child = 'No' AND status = 'Active' ORDER BY ac_code ASC, ac_head ASC"
)->fetchAll();

// -----------------------------------------------------------
// ফিল্টার
// -----------------------------------------------------------
$searched   = isset($_GET['search']);
$this_year  = (int) date('Y');
$f_month    = (int) ($_GET['f_month'] ?? (int) date('n'));
$f_year     = (int) ($_GET['f_year'] ?? $this_year);
$f_head     = (int) ($_GET['f_head'] ?? 0);
$f_start    = trim($_GET['f_start'] ?? '');
$f_end      = trim($_GET['f_end'] ?? '');
$f_pay_type = trim($_GET['f_pay_type'] ?? ''); // '', 'Cash', 'Bank'

$use_range = ($f_start !== '' && $f_end !== '');
if ($use_range && strtotime($f_start) > strtotime($f_end)) { [$f_start, $f_end] = [$f_end, $f_start]; }

$totals = []; // ac_head_id => ['label'=>.., 'receive'=>.., 'payment'=>..]
$report_title = '';
$grand_receive = 0.0;
$grand_payment = 0.0;

if ($searched) {
    if ($use_range) {
        $date_from = $f_start;
        $date_to   = $f_end;
        $report_title = 'Trial Balance from ' . date('d M Y', strtotime($f_start)) . ' to ' . date('d M Y', strtotime($f_end));
    } else {
        if ($f_month < 1 || $f_month > 12) { $f_month = (int) date('n'); }
        $date_from = sprintf('%04d-%02d-01', $f_year, $f_month);
        $date_to   = date('Y-m-t', strtotime($date_from));
        $report_title = 'Trial Balance for the month of ' . ($MONTHS[$f_month] ?? '') . ' ' . $f_year;
    }

    // ---- Fee Collections (সবসময় Receive/Deposit) ----
    $where  = ["fc.status = 'Paid'", 'DATE(fc.collected_at) BETWEEN ? AND ?'];
    $params = [$date_from, $date_to];
    if ($f_head > 0) { $where[] = 'fc.ac_head_id = ?'; $params[] = $f_head; }
    if ($f_pay_type === 'Cash') { $where[] = "fc.payment_method = 'Cash'"; }
    elseif ($f_pay_type === 'Bank') { $where[] = "fc.payment_method <> 'Cash'"; }
    if (branch_current_id() !== null) { $where[] = 'fc.student_id IN (SELECT id FROM students WHERE branch_id = ?)'; $params[] = branch_current_id(); }
    $where_sql = implode(' AND ', $where);

    $sql = "SELECT fc.ac_head_id, fh.ac_code, fh.ac_head, SUM(fc.amount) AS total_amt
            FROM finance_fee_collections fc
            LEFT JOIN finance_account_heads fh ON fh.id = fc.ac_head_id
            WHERE $where_sql
            GROUP BY fc.ac_head_id, fh.ac_code, fh.ac_head";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $hid = (int) $r['ac_head_id'];
        $label = trim(($r['ac_code'] ?? '') . ' - ' . ($r['ac_head'] ?? '—'));
        if (!isset($totals[$hid])) { $totals[$hid] = ['label' => $label, 'receive' => 0.0, 'payment' => 0.0]; }
        $totals[$hid]['receive'] += (float) $r['total_amt'];
    }

    // ---- Vouchers (Receive/Payment উভয়ই) ----
    $where  = ['DATE(v.transaction_date) BETWEEN ? AND ?'];
    $params = [$date_from, $date_to];
    if ($f_head > 0) { $where[] = 'vd.ac_head_id = ?'; $params[] = $f_head; }
    if ($f_pay_type !== '') { $where[] = 'v.payment_type = ?'; $params[] = $f_pay_type; }
    branch_apply_filter($where, $params, 'v.branch_id');
    $where_sql = implode(' AND ', $where);

    $sql = "SELECT vd.ac_head_id, fh.ac_code, fh.ac_head, v.voucher_type, SUM(vd.amount) AS total_amt
            FROM finance_voucher_details vd
            JOIN finance_vouchers v ON v.id = vd.voucher_id
            LEFT JOIN finance_account_heads fh ON fh.id = vd.ac_head_id
            WHERE $where_sql
            GROUP BY vd.ac_head_id, fh.ac_code, fh.ac_head, v.voucher_type";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $hid = (int) $r['ac_head_id'];
        $label = trim(($r['ac_code'] ?? '') . ' - ' . ($r['ac_head'] ?? '—'));
        if (!isset($totals[$hid])) { $totals[$hid] = ['label' => $label, 'receive' => 0.0, 'payment' => 0.0]; }
        if ($r['voucher_type'] === 'Receive') { $totals[$hid]['receive'] += (float) $r['total_amt']; }
        else { $totals[$hid]['payment'] += (float) $r['total_amt']; }
    }

    ksort($totals);
    foreach ($totals as $t) { $grand_receive += $t['receive']; $grand_payment += $t['payment']; }
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
        .tb-filter-card, footer { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; }
        .content-body { padding: 0 !important; margin: 0 !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
        .tb-report-wrap { max-width: 100% !important; box-shadow: none !important; border: none !important; }
        .table-responsive { overflow: visible !important; }
        .card-custom table { width: 100% !important; font-size: 12px; }
        .card-custom table th, .card-custom table td { padding: 4px 6px !important; }
        .card-custom table a { color: #000 !important; text-decoration: none !important; }
        .card-custom table thead { display: table-header-group; }
        .card-custom table tr { page-break-inside: avoid; }
    }
</style>

<div class="card-custom mb-3 tb-filter-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0 text-secondary">Trial Balance</h6>
        <div>
            <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-bs-toggle="collapse" data-bs-target="#tbFilterBody"><i class="fa-solid fa-chevron-up"></i></button>
            <a href="index.php?page=finance_trial_balance" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i></a>
        </div>
    </div>

    <div id="tbFilterBody" class="collapse show">
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="finance_trial_balance">
            <input type="hidden" name="search" value="1">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">Month</label>
                <div class="col-sm-6">
                    <select name="f_month" class="form-select">
                        <?php foreach ($MONTHS as $mid => $mname): ?>
                            <option value="<?php echo (int) $mid; ?>" <?php echo $f_month === (int) $mid ? 'selected' : ''; ?>><?php echo tbv($mname); ?></option>
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

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">A/C Head</label>
                <div class="col-sm-6">
                    <select name="f_head" class="form-select">
                        <option value="0">Select A/C Head</option>
                        <?php foreach ($ac_heads as $h): ?>
                            <option value="<?php echo (int) $h['id']; ?>" <?php echo $f_head === (int) $h['id'] ? 'selected' : ''; ?>><?php echo tbv(trim($h['ac_code'] . ' ' . $h['ac_head'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="text-center fw-bold text-muted mb-3">OR</div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">Start Date</label>
                <div class="col-sm-6">
                    <input type="text" name="f_start" class="form-control" placeholder="i.e : 2018-07-01" value="<?php echo tbv($f_start); ?>">
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">End Date</label>
                <div class="col-sm-6">
                    <input type="text" name="f_end" class="form-control" placeholder="i.e : 2019-06-30" value="<?php echo tbv($f_end); ?>">
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">Payment</label>
                <div class="col-sm-6">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="f_pay_type" id="tbPayCash" value="Cash" <?php echo $f_pay_type === 'Cash' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="tbPayCash">Cash</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="f_pay_type" id="tbPayBank" value="Bank" <?php echo $f_pay_type === 'Bank' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="tbPayBank">Bank</label>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary px-4">Submit</button>
        </form>
    </div>
</div>

<?php if ($searched): ?>
<div class="card-custom">
    <div class="d-flex justify-content-end mb-3 no-print">
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>

    <div class="tb-report-wrap" style="max-width:900px;margin:0 auto;">
        <div class="text-center mb-3">
            <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo tbv($inst['logo_path']); ?>" style="max-width:60px;max-height:60px;"><br><?php endif; ?>
            <h4 class="fw-bold text-primary mb-1"><?php echo tbv($inst['institute_name']); ?></h4>
            <?php if ($branch && !empty($branch['address'])): ?>
                <div class="small text-muted">(<?php echo tbv($branch['address']); ?><?php echo $branch['city'] ? ', ' . tbv($branch['city']) : ''; ?><?php echo $branch['zip_code'] ? '-' . tbv($branch['zip_code']) : ''; ?>)</div>
            <?php endif; ?>
            <?php if (!empty($inst['phone_number']) || !empty($branch['phone'])): ?>
                <div class="small">Phone: <?php echo tbv($inst['phone_number'] ?: $branch['phone']); ?></div>
            <?php endif; ?>
            <h5 class="text-secondary mt-3 mb-1"><u><?php echo tbv($report_title); ?></u></h5>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px;">Sl.</th>
                        <th>Particulars</th>
                        <th class="text-end">Receive / Deposit</th>
                        <th class="text-end">Payment / Withdraw</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($totals): $srl = 1; foreach ($totals as $t): ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo tbv($t['label']); ?></td>
                            <td class="text-end"><?php echo tb_money($t['receive']); ?></td>
                            <td class="text-end"><?php echo tb_money($t['payment']); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">এই ফিল্টারে কোনো লেনদেন পাওয়া যায়নি।</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($totals): ?>
                    <tfoot>
                        <tr class="fw-bold table-light">
                            <td colspan="2" class="text-end">Total :</td>
                            <td class="text-end"><?php echo tb_money($grand_receive); ?></td>
                            <td class="text-end"><?php echo tb_money($grand_payment); ?></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
