<?php
// admin/finance_report_fees_collection.php - Finance Module > Finance Reports > Fees Collection
// একটা Session + ঐচ্ছিক Class + Date Range (Start-End) দিয়ে ফিল্টার করে, প্রতিটা Class-ভিত্তিক
// মোট ফি কালেকশন একটা প্রিন্টযোগ্য রিপোর্ট আকারে দেখায়।

function fcv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function fc_money($n): string { return number_format((float) $n, 2); }

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি (বাকি Finance/Collection পেজগুলোর সাথে শেয়ার্ড)
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

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$current_session = $pdo->query("SELECT id FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();

// -----------------------------------------------------------
// ফিল্টার ক্রম: Report Type (Monthly Summary / Date Wise) → তারিখ → Account Head → Transaction → User
// -----------------------------------------------------------
$searched   = isset($_GET['search']);
$f_session  = (int) ($_GET['f_session'] ?? ($current_session['id'] ?? 0));
$f_class    = (int) ($_GET['f_class'] ?? 0);
$f_mode     = ($_GET['f_mode'] ?? 'date') === 'monthly' ? 'monthly' : 'date';
$f_month    = trim($_GET['f_month'] ?? date('Y-m'));
$f_start    = trim($_GET['f_start'] ?? date('Y-m-d'));
$f_end      = trim($_GET['f_end'] ?? date('Y-m-d'));
$f_head     = (int) ($_GET['f_head'] ?? 0);
$f_txn      = in_array($_GET['f_txn'] ?? '', ['received', 'payment', 'both'], true) ? $_GET['f_txn'] : 'received';
$f_user     = trim($_GET['f_user'] ?? '');

// Class Wise অংশের On/Off — প্রথমে স্থায়ী সেটিং (sms_settings.fcr_show_class_wise, ডিফল্ট On), সার্চ করলে ফর্মের সুইচ প্রাধান্য পায়
$cw_default = true;
try {
    $has_cw = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_settings' AND COLUMN_NAME = 'fcr_show_class_wise'")->fetchColumn();
    if (!$has_cw) { $pdo->exec("ALTER TABLE sms_settings ADD COLUMN fcr_show_class_wise TINYINT(1) NOT NULL DEFAULT 1"); }
    $cwv = $pdo->query("SELECT fcr_show_class_wise FROM sms_settings WHERE id = 1")->fetchColumn();
    if ($cwv !== false && $cwv !== null) { $cw_default = (bool) $cwv; }
} catch (Throwable $e) { $cw_default = true; }
$f_cw = $searched ? (($_GET['f_cw'] ?? '0') === '1') : $cw_default;

if (!preg_match('/^\d{4}-\d{2}$/', $f_month)) { $f_month = date('Y-m'); }
if ($f_mode === 'monthly') {
    $f_start = $f_month . '-01';
    $f_end   = date('Y-m-t', strtotime($f_start));
}
// Start > End হলে অদল-বদল করে নেওয়া, যাতে উল্টো দিলেও রিপোর্ট না ভাঙে
if ($f_start !== '' && $f_end !== '' && strtotime($f_start) > strtotime($f_end)) {
    [$f_start, $f_end] = [$f_end, $f_start];
}

// Account Head ও User ড্রপডাউন
$head_options = [];
try {
    $head_options = $pdo->query("SELECT id, ac_code, ac_head, ac_category FROM finance_account_heads WHERE status = 'Active' ORDER BY ac_category ASC, ac_code ASC")->fetchAll();
} catch (Throwable $e) { $head_options = []; }
$head_names = [];
foreach ($head_options as $ho) { $head_names[(int) $ho['id']] = $ho['ac_head']; }

$user_options = [];
try {
    $user_options = $pdo->query(
        "SELECT collected_by AS u FROM finance_fee_collections WHERE collected_by IS NOT NULL AND collected_by <> ''
         UNION SELECT created_by FROM finance_vouchers WHERE created_by IS NOT NULL AND created_by <> ''
         ORDER BY u ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $user_options = $pdo->query("SELECT DISTINCT collected_by FROM finance_fee_collections WHERE collected_by IS NOT NULL AND collected_by <> '' ORDER BY collected_by ASC")->fetchAll(PDO::FETCH_COLUMN);
}

$rows = [];            // Class-wise (ফি কালেকশন)
$grand_total = 0.0;
$head_rows = [];       // Account Head-wise (Received / Payment)
$tot_recv = 0.0; $tot_pay = 0.0;
$session_name = '';
$want_recv = in_array($f_txn, ['received', 'both'], true);
$want_pay  = in_array($f_txn, ['payment', 'both'], true);

if ($searched && $f_start !== '' && $f_end !== '') {
    $acc = []; // key => ['date'=>, 'head'=>, 'recv'=>, 'pay'=>]
    $add = function (string $date, int $hid, string $kind, float $amt) use (&$acc, $f_mode, $head_names) {
        $d = $f_mode === 'date' ? $date : '';
        $key = $d . '|' . $hid;
        if (!isset($acc[$key])) { $acc[$key] = ['date' => $d, 'head' => $hid > 0 ? ($head_names[$hid] ?? ('#' . $hid)) : 'অনির্ধারিত (Unassigned)', 'recv' => 0.0, 'pay' => 0.0]; }
        $acc[$key][$kind] += $amt;
    };

    if ($want_recv) {
        $where = ['DATE(fc.collected_at) BETWEEN ? AND ?'];
        $params = [$f_start, $f_end];
        if ($f_session > 0) { $where[] = 'fc.session_id = ?'; $params[] = $f_session; }
        if ($f_class > 0) { $where[] = 's.class_id = ?'; $params[] = $f_class; }
        if ($f_head > 0) { $where[] = 'fc.ac_head_id = ?'; $params[] = $f_head; }
        if ($f_user !== '') { $where[] = 'fc.collected_by = ?'; $params[] = $f_user; }
        branch_apply_filter($where, $params, 's.branch_id');
        $where_sql = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT c.id AS class_id, c.class_name, SUM(fc.amount) AS total_amount
             FROM finance_fee_collections fc
             LEFT JOIN students s ON s.id = fc.student_id
             LEFT JOIN academic_classes c ON c.id = s.class_id
             WHERE $where_sql
             GROUP BY c.id, c.class_name
             ORDER BY c.rank ASC, c.class_name ASC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) { $grand_total += (float) $r['total_amount']; }

        $stmt = $pdo->prepare(
            "SELECT DATE(fc.collected_at) AS d, COALESCE(fc.ac_head_id, 0) AS hid, SUM(fc.amount) AS amt
             FROM finance_fee_collections fc
             LEFT JOIN students s ON s.id = fc.student_id
             WHERE $where_sql
             GROUP BY DATE(fc.collected_at), COALESCE(fc.ac_head_id, 0)"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) { $add($r['d'], (int) $r['hid'], 'recv', (float) $r['amt']); }
    }

    // ভাউচার — Receive Voucher (আয়) Received-এ, Payment Voucher (ব্যয়) Payment-এ
    $vtypes = [];
    if ($want_recv) { $vtypes[] = 'Receive'; }
    if ($want_pay)  { $vtypes[] = 'Payment'; }
    if ($vtypes) {
        try {
            // পুরনো ডাটাবেজে branch_id কলাম না থাকলে (ভাউচার পেজ এখনো না খোলা) যোগ করে নেওয়া
            $has_bid = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_vouchers' AND COLUMN_NAME = 'branch_id'")->fetchColumn();
            if (!$has_bid) { $pdo->exec("ALTER TABLE finance_vouchers ADD COLUMN branch_id INT NULL"); }
            $vw = ['v.transaction_date BETWEEN ? AND ?', 'v.voucher_type IN (' . implode(',', array_fill(0, count($vtypes), '?')) . ')'];
            $vp = array_merge([$f_start, $f_end], $vtypes);
            if ($f_head > 0) { $vw[] = 'd.ac_head_id = ?'; $vp[] = $f_head; }
            if ($f_user !== '') { $vw[] = 'v.created_by = ?'; $vp[] = $f_user; }
            branch_apply_filter($vw, $vp, 'v.branch_id');
            $stmt = $pdo->prepare(
                "SELECT v.transaction_date AS d, v.voucher_type AS vt, d.ac_head_id AS hid, SUM(d.amount) AS amt
                 FROM finance_vouchers v
                 JOIN finance_voucher_details d ON d.voucher_id = v.id
                 WHERE " . implode(' AND ', $vw) . "
                 GROUP BY v.transaction_date, v.voucher_type, d.ac_head_id"
            );
            $stmt->execute($vp);
            foreach ($stmt->fetchAll() as $r) { $add($r['d'], (int) $r['hid'], $r['vt'] === 'Payment' ? 'pay' : 'recv', (float) $r['amt']); }
        } catch (Throwable $e) { error_log('fees_collection_report vouchers: ' . $e->getMessage()); }
    }

    uasort($acc, fn($a, $b) => [$a['date'], $a['head']] <=> [$b['date'], $b['head']]);
    $head_rows = array_values($acc);
    foreach ($head_rows as $hr) { $tot_recv += $hr['recv']; $tot_pay += $hr['pay']; }

    foreach ($sessions as $s) { if ((int) $s['id'] === $f_session) { $session_name = $s['session_name']; break; } }
}

$inst = $pdo->query("SELECT institute_name, phone_number, logo_path FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) { $inst = ['institute_name' => 'School Name', 'phone_number' => '', 'logo_path' => null]; }
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
        .fc-filter-card, footer { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; }
        .content-body { padding: 0 !important; margin: 0 !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
        .fc-report-wrap { max-width: 100% !important; box-shadow: none !important; border: none !important; }
        .fc-report-wrap .table-responsive { overflow: visible !important; }
        .fc-report-wrap table { width: 100% !important; font-size: 12px; }
        .fc-report-wrap th, .fc-report-wrap td { padding: 4px 6px !important; }
        .fc-report-wrap a { color: #000 !important; text-decoration: none !important; }
        .fc-report-wrap thead { display: table-header-group; }
        .fc-report-wrap tr { page-break-inside: avoid; }
    }
</style>

<div class="card-custom mb-3 fc-filter-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0 text-secondary">Student's Fee Collection Report</h6>
        <div>
            <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-bs-toggle="collapse" data-bs-target="#fcFilterBody"><i class="fa-solid fa-chevron-up"></i></button>
            <a href="index.php?page=finance_report_fees_collection" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i></a>
        </div>
    </div>

    <div id="fcFilterBody" class="collapse show">
        <form method="GET" action="index.php" class="row g-3" id="fcForm">
            <input type="hidden" name="page" value="finance_report_fees_collection">
            <input type="hidden" name="search" value="1">
            <div class="col-sm-6">
                <label class="form-label fw-bold">Session</label>
                <select name="f_session" class="form-select">
                    <option value="0">All Sessions</option>
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>><?php echo fcv($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6">
                <label class="form-label fw-bold">Class</label>
                <select name="f_class" class="form-select">
                    <option value="0">Select Class</option>
                    <?php foreach ($classes as $cl): ?>
                        <option value="<?php echo (int) $cl['id']; ?>" <?php echo $f_class === (int) $cl['id'] ? 'selected' : ''; ?>><?php echo fcv($cl['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12">
                <label class="form-label fw-bold">Report Type <span class="text-danger">*</span></label>
                <div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="f_mode" id="fcModeMonthly" value="monthly" <?php echo $f_mode === 'monthly' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="fcModeMonthly">Monthly (Summary)</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="f_mode" id="fcModeDate" value="date" <?php echo $f_mode === 'date' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="fcModeDate">Date Wise</label>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 fc-mode-monthly">
                <label class="form-label fw-bold">Month <span class="text-danger">*</span></label>
                <input type="month" name="f_month" class="form-control" value="<?php echo fcv($f_month); ?>">
            </div>
            <div class="col-sm-3 fc-mode-date">
                <label class="form-label fw-bold">Start Date <span class="text-danger">*</span></label>
                <input type="date" name="f_start" class="form-control" value="<?php echo fcv($f_start); ?>">
            </div>
            <div class="col-sm-3 fc-mode-date">
                <label class="form-label fw-bold">End Date <span class="text-danger">*</span></label>
                <input type="date" name="f_end" class="form-control" value="<?php echo fcv($f_end); ?>">
            </div>

            <div class="col-sm-4">
                <label class="form-label fw-bold">Account Head</label>
                <select name="f_head" class="form-select">
                    <option value="0">All Account Heads</option>
                    <?php $lastcat = null; foreach ($head_options as $ho):
                        if ($lastcat !== $ho['ac_category']) { if ($lastcat !== null) echo '</optgroup>'; echo '<optgroup label="' . fcv($ho['ac_category']) . '">'; $lastcat = $ho['ac_category']; } ?>
                        <option value="<?php echo (int) $ho['id']; ?>" <?php echo $f_head === (int) $ho['id'] ? 'selected' : ''; ?>><?php echo fcv($ho['ac_code'] . ' - ' . $ho['ac_head']); ?></option>
                    <?php endforeach; if ($lastcat !== null) echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="col-sm-4">
                <label class="form-label fw-bold">Transaction</label>
                <select name="f_txn" class="form-select">
                    <option value="received" <?php echo $f_txn === 'received' ? 'selected' : ''; ?>>Received</option>
                    <option value="payment" <?php echo $f_txn === 'payment' ? 'selected' : ''; ?>>Payment</option>
                    <option value="both" <?php echo $f_txn === 'both' ? 'selected' : ''; ?>>Both (Received &amp; Payment)</option>
                </select>
            </div>
            <div class="col-sm-4">
                <label class="form-label fw-bold">User</label>
                <select name="f_user" class="form-select">
                    <option value="">All Users</option>
                    <?php foreach ($user_options as $uo): ?>
                        <option value="<?php echo fcv($uo); ?>" <?php echo $f_user === $uo ? 'selected' : ''; ?>><?php echo fcv($uo); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 d-flex align-items-center">
                <input type="hidden" name="f_cw" value="0">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" name="f_cw" value="1" id="fcCwSwitch" <?php echo $f_cw ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold" for="fcCwSwitch">Class Wise Fee Collection দেখান</label>
                </div>
            </div>
            <div class="col-sm-6 text-end">
                <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            </div>
        </form>
        <script>
        (function () {
            var f = document.getElementById('fcForm');
            function sync() {
                var m = f.querySelector('input[name=f_mode]:checked').value;
                f.querySelectorAll('.fc-mode-monthly').forEach(function (e) { e.style.display = m === 'monthly' ? '' : 'none'; });
                f.querySelectorAll('.fc-mode-date').forEach(function (e) { e.style.display = m === 'date' ? '' : 'none'; });
                f.querySelector('input[name=f_month]').required = (m === 'monthly');
                f.querySelector('input[name=f_start]').required = (m === 'date');
                f.querySelector('input[name=f_end]').required = (m === 'date');
            }
            f.querySelectorAll('input[name=f_mode]').forEach(function (r) { r.addEventListener('change', sync); });
            sync();
        })();
        </script>
    </div>
</div>

<?php if ($searched): ?>
<div class="card-custom">
    <div class="d-flex justify-content-end align-items-center gap-3 mb-3 no-print">
        <?php if ($want_recv): ?>
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="fcCwLive" <?php echo $f_cw ? 'checked' : ''; ?> onchange="document.getElementById('fcClassWise').style.display = this.checked ? '' : 'none';">
            <label class="form-check-label small fw-bold" for="fcCwLive">Class Wise</label>
        </div>
        <?php endif; ?>
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>

    <div class="fc-report-wrap" style="max-width:900px;margin:0 auto;">
        <div class="text-center mb-3">
            <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo fcv($inst['logo_path']); ?>" style="max-width:60px;max-height:60px;"><br><?php endif; ?>
            <h4 class="fw-bold text-primary mb-1"><?php echo fcv($inst['institute_name']); ?></h4>
            <?php if ($branch && !empty($branch['address'])): ?>
                <div class="small text-muted">(<?php echo fcv($branch['address']); ?><?php echo $branch['city'] ? ', ' . fcv($branch['city']) : ''; ?><?php echo $branch['zip_code'] ? '-' . fcv($branch['zip_code']) : ''; ?>)</div>
            <?php endif; ?>
            <?php if (!empty($inst['phone_number']) || !empty($branch['phone'])): ?>
                <div class="small">Phone: <?php echo fcv($inst['phone_number'] ?: $branch['phone']); ?></div>
            <?php endif; ?>
            <?php if (!empty($branch['email']) || !empty($inst['email'] ?? '')): ?>
                <div class="small text-primary"><?php echo fcv($branch['email'] ?? ''); ?></div>
            <?php endif; ?>
            <h5 class="text-secondary mt-3 mb-1">Fee Collection Report<?php echo $session_name !== '' ? ' - ' . fcv($session_name) : ''; ?></h5>
            <div class="fw-bold small">
                <?php if ($f_mode === 'monthly'): ?>
                    Monthly Summary : <?php echo fcv(date('F Y', strtotime($f_start))); ?>
                <?php else: ?>
                    Date From <?php echo fcv(date('d M Y', strtotime($f_start))); ?> To <?php echo fcv(date('d M Y', strtotime($f_end))); ?>
                <?php endif; ?>
                &middot; <?php echo $f_txn === 'both' ? 'Received &amp; Payment' : ($f_txn === 'payment' ? 'Payment' : 'Received'); ?>
                <?php if ($f_head > 0 && isset($head_names[$f_head])): ?> &middot; Head : <?php echo fcv($head_names[$f_head]); ?><?php endif; ?>
                <?php if ($f_user !== ''): ?> &middot; User : <?php echo fcv($f_user); ?><?php endif; ?>
            </div>
        </div>

        <?php if ($want_recv): ?>
        <div id="fcClassWise" style="<?php echo $f_cw ? '' : 'display:none;'; ?>">
        <h6 class="fw-bold text-secondary">Class Wise Fee Collection</h6>
        <div class="table-responsive mb-4">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:60px;">Srl.</th>
                        <th>Class</th>
                        <th style="width:160px;" class="text-end">Total Collection</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): $srl = 1; foreach ($rows as $r): ?>
                        <tr>
                            <td class="text-primary fw-bold"><?php echo $srl++; ?></td>
                            <td><?php echo fcv($r['class_name'] ?: '—'); ?></td>
                            <td class="text-end"><?php echo fc_money($r['total_amount']); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">এই সময়ের মধ্যে কোনো ফি কালেকশন পাওয়া যায়নি।</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($rows): ?>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="text-end fw-bold">Total</td>
                            <td class="text-end fw-bold"><?php echo fc_money($grand_total); ?></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
        </div>
        <?php endif; ?>

        <h6 class="fw-bold text-secondary">Account Head Wise <?php echo $f_mode === 'monthly' ? 'Summary' : '(Date Wise)'; ?></h6>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:60px;">Srl.</th>
                        <?php if ($f_mode === 'date'): ?><th style="width:120px;">Date</th><?php endif; ?>
                        <th>Account Head</th>
                        <?php if ($want_recv): ?><th style="width:130px;" class="text-end">Received</th><?php endif; ?>
                        <?php if ($want_pay): ?><th style="width:130px;" class="text-end">Payment</th><?php endif; ?>
                        <?php if ($f_txn === 'both'): ?><th style="width:130px;" class="text-end">Balance</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($head_rows): $srl = 1; foreach ($head_rows as $hr): ?>
                        <tr>
                            <td class="text-primary fw-bold"><?php echo $srl++; ?></td>
                            <?php if ($f_mode === 'date'): ?><td><?php echo fcv(date('d M Y', strtotime($hr['date']))); ?></td><?php endif; ?>
                            <td><?php echo fcv($hr['head']); ?></td>
                            <?php if ($want_recv): ?><td class="text-end"><?php echo fc_money($hr['recv']); ?></td><?php endif; ?>
                            <?php if ($want_pay): ?><td class="text-end"><?php echo fc_money($hr['pay']); ?></td><?php endif; ?>
                            <?php if ($f_txn === 'both'): ?><td class="text-end"><?php echo fc_money($hr['recv'] - $hr['pay']); ?></td><?php endif; ?>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">এই সময়ের মধ্যে কোনো লেনদেন পাওয়া যায়নি।</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($head_rows): ?>
                    <tfoot>
                        <tr>
                            <td colspan="<?php echo $f_mode === 'date' ? 3 : 2; ?>" class="text-end fw-bold">Total</td>
                            <?php if ($want_recv): ?><td class="text-end fw-bold"><?php echo fc_money($tot_recv); ?></td><?php endif; ?>
                            <?php if ($want_pay): ?><td class="text-end fw-bold"><?php echo fc_money($tot_pay); ?></td><?php endif; ?>
                            <?php if ($f_txn === 'both'): ?><td class="text-end fw-bold"><?php echo fc_money($tot_recv - $tot_pay); ?></td><?php endif; ?>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
        </div>
    </div>
</div>
<?php endif; ?>
