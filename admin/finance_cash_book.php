<?php
// admin/finance_cash_book.php - Finance Module > Finance Reports > Cash Book
// একটা নির্দিষ্ট Month+Year (অথবা Start-End Date রেঞ্জ) দিয়ে ফিল্টার করে, Student Fee Collections
// (Cash) + Receive/Payment Voucher (Cash) — দুই সোর্স মিলিয়ে Opening Balance সহ একটা
// রানিং-ব্যালেন্স ক্যাশ বুক প্রিন্টযোগ্য আকারে দেখায়।

function cbv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function cb_money($n): string { return number_format((float) $n, 2); }

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

$rows = [];
$opening_balance = 0.0;
$grand_deposit = 0.0;
$grand_withdraw = 0.0;
$closing_balance = 0.0;
$report_title = '';
$date_from = '';
$date_to = '';

if ($searched) {
    if ($use_range) {
        $date_from = $f_start;
        $date_to   = $f_end;
        $report_title = 'Cash Book from ' . date('d M Y', strtotime($f_start)) . ' to ' . date('d M Y', strtotime($f_end));
    } else {
        if ($f_month < 1 || $f_month > 12) { $f_month = (int) date('n'); }
        $date_from = sprintf('%04d-%02d-01', $f_year, $f_month);
        $date_to   = date('Y-m-t', strtotime($date_from));
        $report_title = 'Cash Book for the month of ' . ($MONTHS[$f_month] ?? '') . ' ' . $f_year;
    }

    // ---- Opening Balance : date_from এর আগের সব Cash লেনদেন ----
    $cb_bf = branch_current_id();
    $cb_fc_bf = $cb_bf !== null ? " AND student_id IN (SELECT id FROM students WHERE branch_id = ?)" : "";
    $cb_v_bf  = $cb_bf !== null ? " AND (v.branch_id = ?)" : "";

    $p = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM finance_fee_collections WHERE status = 'Paid' AND payment_method = 'Cash' AND DATE(collected_at) < ?$cb_fc_bf");
    $p->execute($cb_bf !== null ? [$date_from, $cb_bf] : [$date_from]);
    $opening_balance += (float) $p->fetchColumn();

    $p = $pdo->prepare("SELECT COALESCE(SUM(vd.amount),0) FROM finance_voucher_details vd JOIN finance_vouchers v ON v.id = vd.voucher_id WHERE v.voucher_type = 'Receive' AND v.payment_type = 'Cash' AND DATE(v.transaction_date) < ?$cb_v_bf");
    $p->execute($cb_bf !== null ? [$date_from, $cb_bf] : [$date_from]);
    $opening_balance += (float) $p->fetchColumn();

    $p = $pdo->prepare("SELECT COALESCE(SUM(vd.amount),0) FROM finance_voucher_details vd JOIN finance_vouchers v ON v.id = vd.voucher_id WHERE v.voucher_type = 'Payment' AND v.payment_type = 'Cash' AND DATE(v.transaction_date) < ?$cb_v_bf");
    $p->execute($cb_bf !== null ? [$date_from, $cb_bf] : [$date_from]);
    $opening_balance -= (float) $p->fetchColumn();

    // ---- চলতি রেঞ্জের লেনদেন ----
    $stmt = $pdo->prepare(
        "SELECT fc.collected_at AS trx_date, fc.amount, fh.ac_code, fh.ac_head
         FROM finance_fee_collections fc
         LEFT JOIN finance_account_heads fh ON fh.id = fc.ac_head_id
         WHERE fc.status = 'Paid' AND fc.payment_method = 'Cash' AND DATE(fc.collected_at) BETWEEN ? AND ?" .
         ($cb_bf !== null ? " AND fc.student_id IN (SELECT id FROM students WHERE branch_id = ?)" : "") . "
         ORDER BY fc.collected_at ASC, fc.id ASC"
    );
    $stmt->execute($cb_bf !== null ? [$date_from, $date_to, $cb_bf] : [$date_from, $date_to]);
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [
            'date' => $r['trx_date'],
            'particulars' => trim(($r['ac_code'] ?? '') . ' - ' . ($r['ac_head'] ?? '—')),
            'deposit' => (float) $r['amount'],
            'withdraw' => 0.0,
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT v.transaction_date AS trx_date, v.voucher_type, vd.amount, fh.ac_code, fh.ac_head
         FROM finance_voucher_details vd
         JOIN finance_vouchers v ON v.id = vd.voucher_id
         LEFT JOIN finance_account_heads fh ON fh.id = vd.ac_head_id
         WHERE v.payment_type = 'Cash' AND DATE(v.transaction_date) BETWEEN ? AND ?$cb_v_bf
         ORDER BY v.transaction_date ASC, v.id ASC"
    );
    $stmt->execute($cb_bf !== null ? [$date_from, $date_to, $cb_bf] : [$date_from, $date_to]);
    foreach ($stmt->fetchAll() as $r) {
        $is_receive = $r['voucher_type'] === 'Receive';
        $rows[] = [
            'date' => $r['trx_date'],
            'particulars' => trim(($r['ac_code'] ?? '') . ' - ' . ($r['ac_head'] ?? '—')),
            'deposit' => $is_receive ? (float) $r['amount'] : 0.0,
            'withdraw' => $is_receive ? 0.0 : (float) $r['amount'],
        ];
    }

    usort($rows, function ($a, $b) { return strtotime($a['date']) <=> strtotime($b['date']); });

    $running = $opening_balance;
    foreach ($rows as &$r) {
        $running += $r['deposit'] - $r['withdraw'];
        $r['balance'] = $running;
        $grand_deposit += $r['deposit'];
        $grand_withdraw += $r['withdraw'];
    }
    unset($r);
    $closing_balance = $opening_balance + $grand_deposit - $grand_withdraw;
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
        .cb-report-wrap { max-width: 100% !important; box-shadow: none !important; border: none !important; }
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
        <h6 class="mb-0 text-secondary">Cash Book</h6>
        <div>
            <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-bs-toggle="collapse" data-bs-target="#cbFilterBody"><i class="fa-solid fa-chevron-up"></i></button>
            <a href="index.php?page=finance_cash_book" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i></a>
        </div>
    </div>

    <div id="cbFilterBody" class="collapse show">
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="finance_cash_book">
            <input type="hidden" name="search" value="1">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">Month</label>
                <div class="col-sm-6">
                    <select name="f_month" class="form-select">
                        <?php foreach ($MONTHS as $mid => $mname): ?>
                            <option value="<?php echo (int) $mid; ?>" <?php echo $f_month === (int) $mid ? 'selected' : ''; ?>><?php echo cbv($mname); ?></option>
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
                    <input type="text" name="f_start" class="form-control" placeholder="i.e : 2018-07-01" value="<?php echo cbv($f_start); ?>">
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">End Date</label>
                <div class="col-sm-6">
                    <input type="text" name="f_end" class="form-control" placeholder="i.e : 2019-06-30" value="<?php echo cbv($f_end); ?>">
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
    <div class="cb-report-wrap" style="max-width:900px;margin:0 auto;">
        <div class="text-center mb-3">
            <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo cbv($inst['logo_path']); ?>" style="max-width:60px;max-height:60px;"><br><?php endif; ?>
            <h4 class="fw-bold text-primary mb-1"><?php echo cbv($inst['institute_name']); ?></h4>
            <?php if ($branch && !empty($branch['address'])): ?>
                <div class="small text-muted">(<?php echo cbv($branch['address']); ?><?php echo $branch['city'] ? ', ' . cbv($branch['city']) : ''; ?><?php echo $branch['zip_code'] ? '-' . cbv($branch['zip_code']) : ''; ?>)</div>
            <?php endif; ?>
            <?php if (!empty($inst['phone_number']) || !empty($branch['phone'])): ?>
                <div class="small">Phone: <?php echo cbv($inst['phone_number'] ?: $branch['phone']); ?></div>
            <?php endif; ?>
            <h5 class="text-secondary mt-3 mb-1"><u><?php echo cbv($report_title); ?></u></h5>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Particulars</th>
                        <th class="text-end">Deposit / Receive</th>
                        <th class="text-end">Withdraw / Payment</th>
                        <th class="text-end">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo cbv(date('d/m/Y', strtotime($date_from . ' -1 day'))); ?></td>
                        <td class="fst-italic fw-bold">Opening Balance</td>
                        <td></td>
                        <td></td>
                        <td class="text-end fw-bold"><?php echo cb_money($opening_balance); ?></td>
                    </tr>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo cbv(date('d/m/Y', strtotime($r['date']))); ?></td>
                            <td><?php echo cbv($r['particulars']); ?></td>
                            <td class="text-end"><?php echo $r['deposit'] > 0 ? cb_money($r['deposit']) : '0.00'; ?></td>
                            <td class="text-end"><?php echo $r['withdraw'] > 0 ? cb_money($r['withdraw']) : '0.00'; ?></td>
                            <td class="text-end"><?php echo cb_money($r['balance']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td colspan="2" class="text-end fst-italic">Grand Total :</td>
                        <td class="text-end"><?php echo cb_money($grand_deposit); ?></td>
                        <td class="text-end"><?php echo cb_money($grand_withdraw); ?></td>
                        <td></td>
                    </tr>
                    <tr class="fw-bold table-light">
                        <td colspan="4" class="text-end fst-italic">Closing Balance :</td>
                        <td class="text-end"><?php echo cb_money($closing_balance); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
