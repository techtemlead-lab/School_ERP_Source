<?php
// admin/finance_accounts_ledger.php - Finance Module > Finance Reports > Accounts Ledger
// একটা নির্দিষ্ট Month+Year, অথবা পুরো বছর (All Months = মাসের হেডিং + ডেট-ওয়াইজ ডিটেইলস; Yearly Summary = ১২ মাসের সামারি), অথবা Start-End Date রেঞ্জ + ঐচ্ছিক A/C Head + ঐচ্ছিক Cash/Bank
// দিয়ে ফিল্টার করে "A/C Head" সোর্স (Student Fee Collections) অথবা "Voucher" সোর্স
// (Receive/Payment Voucher) থেকে একটা প্রিন্টযোগ্য লেজার রিপোর্ট দেখায়।

function alv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function al_money($n): string { return number_format((float) $n, 2); }

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
$searched    = isset($_GET['search']);
$this_year   = (int) date('Y');
$f_month     = (int) ($_GET['f_month'] ?? (int) date('n'));
$f_year      = (int) ($_GET['f_year'] ?? $this_year);
$f_head      = (int) ($_GET['f_head'] ?? 0);
$f_start     = trim($_GET['f_start'] ?? '');
$f_end       = trim($_GET['f_end'] ?? '');
$f_pay_type  = trim($_GET['f_pay_type'] ?? ''); // '', 'Cash', 'Bank'
$f_source    = ($_GET['f_source'] ?? 'ac_head') === 'voucher' ? 'voucher' : 'ac_head';

$use_range = ($f_start !== '' && $f_end !== '');
if ($use_range && strtotime($f_start) > strtotime($f_end)) { [$f_start, $f_end] = [$f_end, $f_start]; }

$rows = [];
$grand_receive = 0.0;
$grand_payment = 0.0;
$report_title = '';

if ($searched) {
    if ($use_range) {
        $date_from = $f_start;
        $date_to   = $f_end;
        $report_title = 'Accounts Ledger from ' . date('d M Y', strtotime($f_start)) . ' to ' . date('d M Y', strtotime($f_end));
    } else {
        if ($f_month < 0 || $f_month > 13) { $f_month = (int) date('n'); }
        if ($f_month === 0 || $f_month === 13) {
            // 0 = All Months (প্রতি মাসের হেডিংয়ের নিচে ডেট-ওয়াইজ ডিটেইলস), 13 = Yearly Summary (শুধু ১২ মাসের সামারি)
            $date_from = sprintf('%04d-01-01', $f_year);
            $date_to   = sprintf('%04d-12-31', $f_year);
            $report_title = ($f_month === 13 ? 'Accounts Ledger Summary for the year ' : 'Accounts Ledger for the year ') . $f_year;
        } else {
            $date_from = sprintf('%04d-%02d-01', $f_year, $f_month);
            $date_to   = date('Y-m-t', strtotime($date_from));
            $report_title = 'Accounts Ledger for the month of ' . ($MONTHS[$f_month] ?? '') . ' ' . $f_year;
        }
    }

    if ($f_source === 'voucher') {
        $where  = ['DATE(v.transaction_date) BETWEEN ? AND ?'];
        $params = [$date_from, $date_to];
        if ($f_head > 0) { $where[] = 'vd.ac_head_id = ?'; $params[] = $f_head; }
        if ($f_pay_type !== '') { $where[] = 'v.payment_type = ?'; $params[] = $f_pay_type; }
        branch_apply_filter($where, $params, 'v.branch_id');
        $where_sql = implode(' AND ', $where);

        $sql = "SELECT v.transaction_date, v.voucher_no, v.voucher_type, vd.amount, vd.remarks,
                       fh.ac_code, fh.ac_head
                FROM finance_voucher_details vd
                JOIN finance_vouchers v ON v.id = vd.voucher_id
                LEFT JOIN finance_account_heads fh ON fh.id = vd.ac_head_id
                WHERE $where_sql
                ORDER BY v.transaction_date ASC, v.id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        foreach ($stmt->fetchAll() as $r) {
            $is_receive = $r['voucher_type'] === 'Receive';
            $rows[] = [
                'date'       => $r['transaction_date'],
                'particulars'=> trim(($r['ac_code'] ?? '') . ' - ' . ($r['ac_head'] ?? '—')),
                'receive'    => $is_receive ? (float) $r['amount'] : 0.0,
                'payment'    => $is_receive ? 0.0 : (float) $r['amount'],
                'ref_no'     => $r['voucher_no'],
                'remarks'    => $r['remarks'],
            ];
        }
    } else {
        $where  = ["fc.status = 'Paid'", 'DATE(fc.collected_at) BETWEEN ? AND ?'];
        $params = [$date_from, $date_to];
        if ($f_head > 0) { $where[] = 'fc.ac_head_id = ?'; $params[] = $f_head; }
        if ($f_pay_type === 'Cash') { $where[] = "fc.payment_method = 'Cash'"; }
        elseif ($f_pay_type === 'Bank') { $where[] = "fc.payment_method <> 'Cash'"; }
        branch_apply_filter($where, $params, 's.branch_id');
        $where_sql = implode(' AND ', $where);

        $sql = "SELECT fc.collected_at, fc.amount, fc.receipt_no, fc.folio_no, s.name_en,
                       fh.ac_code, fh.ac_head
                FROM finance_fee_collections fc
                LEFT JOIN finance_account_heads fh ON fh.id = fc.ac_head_id
                LEFT JOIN students s ON s.id = fc.student_id
                WHERE $where_sql
                ORDER BY fc.collected_at ASC, fc.id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                'date'        => $r['collected_at'],
                'particulars' => trim(($r['ac_code'] ?? '') . ' - ' . ($r['ac_head'] ?? '—')),
                'receive'     => (float) $r['amount'],
                'payment'     => 0.0,
                'ref_no'      => $r['receipt_no'] ?: $r['folio_no'],
                'remarks'     => $r['name_en'] ?? '',
            ];
        }
    }

    foreach ($rows as $r) { $grand_receive += $r['receive']; $grand_payment += $r['payment']; }

    // View: 13 = শুধু ১২ মাসের সামারি; 0 = মাস-ভিত্তিক হেডিং + নিচে ডেট-ওয়াইজ ডিটেইলস; নির্দিষ্ট মাস/তারিখ রেঞ্জ = শুধু ডিটেইলস
    $view_mode = $use_range ? 'flat' : ($f_month === 13 ? 'summary' : ($f_month === 0 ? 'month' : 'flat'));
    $groups = [];
    if ($view_mode === 'month' || $view_mode === 'summary') {
        for ($m = 1; $m <= 12; $m++) {
            $t = strtotime(sprintf('%04d-%02d-01', $f_year, $m));
            $groups[date('Y-m', $t)] = ['label' => date('M-y', $t), 'rows' => [], 'receive' => 0.0, 'payment' => 0.0];
        }
    } else {
        $groups['all'] = ['label' => '', 'rows' => [], 'receive' => 0.0, 'payment' => 0.0];
    }
    foreach ($rows as $r) {
        $ts = strtotime($r['date']);
        $key = ($view_mode === 'flat') ? 'all' : date('Y-m', $ts);
        if (!isset($groups[$key])) {
            $groups[$key] = ['label' => date('M-y', $ts), 'rows' => [], 'receive' => 0.0, 'payment' => 0.0];
        }
        $groups[$key]['rows'][] = $r;
        $groups[$key]['receive'] += $r['receive'];
        $groups[$key]['payment'] += $r['payment'];
    }
    ksort($groups);
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
        .al-filter-card, footer { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; }
        .content-body { padding: 0 !important; margin: 0 !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
        .al-report-wrap { max-width: 100% !important; box-shadow: none !important; border: none !important; }
        .table-responsive { overflow: visible !important; }
        .card-custom table { width: 100% !important; font-size: 12px; }
        .card-custom table th, .card-custom table td { padding: 4px 6px !important; }
        .card-custom table a { color: #000 !important; text-decoration: none !important; }
        .card-custom table thead { display: table-header-group; }
        .card-custom table tr { page-break-inside: avoid; }
    }
</style>

<div class="card-custom mb-3 al-filter-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0 text-secondary">Accounts Ledger</h6>
        <div>
            <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-bs-toggle="collapse" data-bs-target="#alFilterBody"><i class="fa-solid fa-chevron-up"></i></button>
            <a href="index.php?page=finance_accounts_ledger" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i></a>
        </div>
    </div>

    <div id="alFilterBody" class="collapse show">
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="finance_accounts_ledger">
            <input type="hidden" name="search" value="1">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">Month</label>
                <div class="col-sm-6">
                    <select name="f_month" class="form-select">
                        <option value="0" <?php echo $f_month === 0 ? 'selected' : ''; ?>>All Months (Month wise Details)</option>
                        <option value="13" <?php echo $f_month === 13 ? 'selected' : ''; ?>>Yearly Summary (12 Months)</option>
                        <?php foreach ($MONTHS as $mid => $mname): ?>
                            <option value="<?php echo (int) $mid; ?>" <?php echo $f_month === (int) $mid ? 'selected' : ''; ?>><?php echo alv($mname); ?></option>
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
                            <option value="<?php echo (int) $h['id']; ?>" <?php echo $f_head === (int) $h['id'] ? 'selected' : ''; ?>><?php echo alv(trim($h['ac_code'] . ' ' . $h['ac_head'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="text-center fw-bold text-muted mb-3">OR</div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">Start Date</label>
                <div class="col-sm-6">
                    <input type="text" name="f_start" class="form-control" placeholder="i.e : 2018-07-01" value="<?php echo alv($f_start); ?>">
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">End Date</label>
                <div class="col-sm-6">
                    <input type="text" name="f_end" class="form-control" placeholder="i.e : 2019-06-30" value="<?php echo alv($f_end); ?>">
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end"></label>
                <div class="col-sm-6">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="f_pay_type" id="alPayCash" value="Cash" <?php echo $f_pay_type === 'Cash' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="alPayCash">Cash</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="f_pay_type" id="alPayBank" value="Bank" <?php echo $f_pay_type === 'Bank' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="alPayBank">Bank</label>
                    </div>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end"></label>
                <div class="col-sm-6">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="f_source" id="alSrcHead" value="ac_head" <?php echo $f_source === 'ac_head' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="alSrcHead">A/C Head</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="f_source" id="alSrcVoucher" value="voucher" <?php echo $f_source === 'voucher' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="alSrcVoucher">Voucher</label>
                    </div>
                </div>
            </div>

            <div class="text-center">
                <button type="submit" class="btn btn-primary px-4">Submit</button>
            </div>
        </form>
    </div>
</div>

<?php if ($searched): ?>
<div class="card-custom">
    <div class="d-flex justify-content-end mb-3 no-print">
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>

    <div class="al-report-wrap" style="max-width:1000px;margin:0 auto;">
        <div class="text-center mb-3">
            <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo alv($inst['logo_path']); ?>" style="max-width:60px;max-height:60px;"><br><?php endif; ?>
            <h4 class="fw-bold text-primary mb-1"><?php echo alv($inst['institute_name']); ?></h4>
            <?php if ($branch && !empty($branch['address'])): ?>
                <div class="small text-muted">(<?php echo alv($branch['address']); ?><?php echo $branch['city'] ? ', ' . alv($branch['city']) : ''; ?><?php echo $branch['zip_code'] ? '-' . alv($branch['zip_code']) : ''; ?>)</div>
            <?php endif; ?>
            <?php if (!empty($inst['phone_number']) || !empty($branch['phone'])): ?>
                <div class="small">Phone: <?php echo alv($inst['phone_number'] ?: $branch['phone']); ?></div>
            <?php endif; ?>
            <h5 class="text-secondary mt-3 mb-1"><u><?php echo alv($report_title); ?></u></h5>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px;">Sl.</th>
                        <th><?php echo $view_mode === 'summary' ? 'Month' : 'Transaction Date'; ?></th>
                        <th><?php echo $view_mode === 'summary' ? '' : 'Particulars'; ?></th>
                        <th class="text-end">Receive / Deposit</th>
                        <th class="text-end">Payment / Withdraw</th>
                        <th><?php echo $view_mode === 'summary' ? '' : 'Voucher / Ref No.'; ?></th>
                        <th><?php echo $view_mode === 'summary' ? '' : 'Remarks'; ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($view_mode === 'summary'): $srl = 1; foreach ($groups as $g): ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td colspan="2" class="fw-semibold"><?php echo alv($g['label']); ?></td>
                            <td class="text-end"><?php echo al_money($g['receive']); ?></td>
                            <td class="text-end"><?php echo al_money($g['payment']); ?></td>
                            <td colspan="2" class="text-muted small"><?php echo count($g['rows']); ?> transaction(s)</td>
                        </tr>
                    <?php endforeach; else: $srl = 1; foreach ($groups as $g): ?>
                        <?php if ($view_mode === 'month'): ?>
                        <tr class="table-secondary fw-bold">
                            <td colspan="7"><?php echo alv($g['label']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($g['rows'] as $r): ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo alv(date('Y-m-d', strtotime($r['date']))); ?></td>
                            <td><?php echo alv($r['particulars']); ?></td>
                            <td class="text-end"><?php echo al_money($r['receive']); ?></td>
                            <td class="text-end"><?php echo al_money($r['payment']); ?></td>
                            <td><?php echo alv($r['ref_no']); ?></td>
                            <td><?php echo alv($r['remarks']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ($view_mode === 'month'): ?>
                            <?php if (!$g['rows']): ?>
                            <tr><td colspan="7" class="text-center text-muted small py-1">কোনো লেনদেন নেই</td></tr>
                            <?php else: ?>
                            <tr class="fw-semibold" style="background:#f8f9fa;">
                                <td colspan="3" class="text-end">Month Total :</td>
                                <td class="text-end"><?php echo al_money($g['receive']); ?></td>
                                <td class="text-end"><?php echo al_money($g['payment']); ?></td>
                                <td colspan="2"></td>
                            </tr>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endforeach; endif; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" class="text-center text-muted py-3">এই ফিল্টারে কোনো লেনদেন পাওয়া যায়নি।</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($rows): ?>
                    <tfoot>
                        <tr class="fw-bold table-light">
                            <td colspan="3" class="text-end">Grand Total :</td>
                            <td class="text-end"><?php echo al_money($grand_receive); ?></td>
                            <td class="text-end"><?php echo al_money($grand_payment); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
