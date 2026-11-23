<?php
// admin/accounts_profit_loss.php - Accounts Module > Profit & Loss (Income Statement)
require_once __DIR__ . '/../config/accounts_helpers.php';
require_once __DIR__ . '/../config/table_print.php';
ac_ensure_tables($pdo);

function pl(string $val): string { return htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); }
function pl_money($n): string { return number_format((float) $n, 2); }

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date   = $_GET['to_date'] ?? date('Y-m-d');

function ac_period_movement(PDO $pdo, int $account_id, string $normal, string $from_date, string $to_date): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(l.debit),0) AS d, COALESCE(SUM(l.credit),0) AS c
         FROM accounts_journal_lines l JOIN accounts_journals j ON j.id = l.journal_id
         WHERE l.account_id = ? AND j.journal_date BETWEEN ? AND ?"
    );
    $stmt->execute([$account_id, $from_date, $to_date]);
    $row = $stmt->fetch();
    $d = (float) $row['d'];
    $c = (float) $row['c'];
    return $normal === 'Dr' ? ($d - $c) : ($c - $d);
}

$income_accounts  = $pdo->query("SELECT * FROM accounts_chart WHERE account_type = 'Income' AND is_group = 0 ORDER BY code ASC")->fetchAll();
$expense_accounts = $pdo->query("SELECT * FROM accounts_chart WHERE account_type = 'Expense' AND is_group = 0 ORDER BY code ASC")->fetchAll();

$income_rows = [];
$total_income = 0.0;
foreach ($income_accounts as $a) {
    $amt = ac_period_movement($pdo, (int) $a['id'], 'Cr', $from_date, $to_date);
    if (abs($amt) < 0.005) continue;
    $income_rows[] = ['name' => $a['name'], 'code' => $a['code'], 'amt' => $amt];
    $total_income += $amt;
}

$expense_rows = [];
$total_expense = 0.0;
foreach ($expense_accounts as $a) {
    $amt = ac_period_movement($pdo, (int) $a['id'], 'Dr', $from_date, $to_date);
    if (abs($amt) < 0.005) continue;
    $expense_rows[] = ['name' => $a['name'], 'code' => $a['code'], 'amt' => $amt];
    $total_expense += $amt;
}

$net_profit = $total_income - $total_expense;
?>
<?php echo tprint_page_css('landscape', 11); ?>
<style>
@media print {
    .pl-row { display: flex !important; flex-wrap: nowrap !important; }
    .pl-row > .col-md-6 { width: 50% !important; flex: 0 0 50% !important; }
}
</style>

<div class="card-custom">
    <?php echo tprint_header_html($pdo, 'Profit & Loss Statement', 'Period: ' . $from_date . ' to ' . $to_date); ?>
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <h4 class="fw-bold mb-0 text-dark d-print-none"><i class="fa-solid fa-chart-line"></i> Profit &amp; Loss (আয়-ব্যয় হিসাব)</h4>
        <?php echo tprint_controls(['orient' => 'landscape', 'wrap' => false]); ?>
    </div>
    <p class="text-muted small mb-4 no-print"><i class="fa-solid fa-circle-info me-1"></i> নির্দিষ্ট সময়সীমার মোট আয় বনাম মোট ব্যয় — Journal-এ পোস্ট হওয়া Income/Expense লেনদেন থেকে সরাসরি হিসাব করা।</p>

    <form method="GET" action="index.php" class="row g-2 mb-4 no-print">
        <input type="hidden" name="page" value="accounts_profit_loss">
        <div class="col-md-3">
            <label class="fw-bold small">শুরুর তারিখ</label>
            <input type="date" name="from_date" class="form-control" value="<?php echo pl($from_date); ?>">
        </div>
        <div class="col-md-3">
            <label class="fw-bold small">শেষ তারিখ</label>
            <input type="date" name="to_date" class="form-control" value="<?php echo pl($to_date); ?>">
        </div>
        <div class="col-md-1 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">দেখুন</button>
        </div>
    </form>

    <div class="row pl-row">
        <div class="col-md-6">
            <h6 class="fw-bold text-success">আয় (Income)</h6>
            <table class="table table-bordered table-sm">
                <tbody>
                    <?php foreach ($income_rows as $r): ?>
                        <tr><td><?php echo pl($r['code'] . ' - ' . $r['name']); ?></td><td class="text-end"><?php echo pl_money($r['amt']); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$income_rows): ?><tr><td colspan="2" class="text-center text-muted">কোনো আয় নেই</td></tr><?php endif; ?>
                </tbody>
                <tfoot><tr class="table-light fw-bold"><td>মোট আয়</td><td class="text-end"><?php echo pl_money($total_income); ?></td></tr></tfoot>
            </table>
        </div>
        <div class="col-md-6">
            <h6 class="fw-bold text-danger">ব্যয় (Expense)</h6>
            <table class="table table-bordered table-sm">
                <tbody>
                    <?php foreach ($expense_rows as $r): ?>
                        <tr><td><?php echo pl($r['code'] . ' - ' . $r['name']); ?></td><td class="text-end"><?php echo pl_money($r['amt']); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$expense_rows): ?><tr><td colspan="2" class="text-center text-muted">কোনো ব্যয় নেই</td></tr><?php endif; ?>
                </tbody>
                <tfoot><tr class="table-light fw-bold"><td>মোট ব্যয়</td><td class="text-end"><?php echo pl_money($total_expense); ?></td></tr></tfoot>
            </table>
        </div>
    </div>

    <div class="alert <?php echo $net_profit >= 0 ? 'alert-success' : 'alert-danger'; ?> fw-bold mt-3 tprint-keep-alert">
        <?php echo $net_profit >= 0 ? 'নেট প্রফিট (Net Profit)' : 'নেট লস (Net Loss)'; ?>: <?php echo pl_money(abs($net_profit)); ?>
    </div>
</div>
