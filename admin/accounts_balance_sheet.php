<?php
// admin/accounts_balance_sheet.php - Accounts Module > Balance Sheet
// Assets = Liabilities + Equity + Retained Earnings (এই সিস্টেমে কোনো ম্যানুয়াল "Period Close" নেই,
// তাই Retained Earnings = শুরু থেকে as_of তারিখ পর্যন্ত মোট (Income - Expense))।
require_once __DIR__ . '/../config/accounts_helpers.php';
ac_ensure_tables($pdo);

function bs(string $val): string { return htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); }
function bs_money($n): string { return number_format((float) $n, 2); }

$as_of = $_GET['as_of'] ?? date('Y-m-d');

function ac_type_total(PDO $pdo, string $type, string $as_of): array
{
    $accounts = $pdo->prepare("SELECT * FROM accounts_chart WHERE account_type = ? AND is_group = 0 ORDER BY code ASC");
    $accounts->execute([$type]);
    $rows = [];
    $total = 0.0;
    foreach ($accounts->fetchAll() as $a) {
        $bal = ac_account_balance($pdo, (int) $a['id'], $as_of);
        if (abs($bal) < 0.005) continue;
        $rows[] = ['code' => $a['code'], 'name' => $a['name'], 'amt' => $bal];
        $total += $bal;
    }
    return [$rows, $total];
}

[$asset_rows, $total_assets]      = ac_type_total($pdo, 'Asset', $as_of);
[$liability_rows, $total_liab]    = ac_type_total($pdo, 'Liability', $as_of);
[$equity_rows, $total_equity]     = ac_type_total($pdo, 'Equity', $as_of);

// Retained Earnings = cumulative Income - Expense from the beginning of time up to as_of
$income_total = 0.0;
$expense_total = 0.0;
$istmt = $pdo->query("SELECT id FROM accounts_chart WHERE account_type = 'Income' AND is_group = 0");
foreach ($istmt->fetchAll() as $r) {
    $income_total += ac_account_balance($pdo, (int) $r['id'], $as_of);
}
$estmt = $pdo->query("SELECT id FROM accounts_chart WHERE account_type = 'Expense' AND is_group = 0");
foreach ($estmt->fetchAll() as $r) {
    $expense_total += ac_account_balance($pdo, (int) $r['id'], $as_of);
}
$retained_earnings = $income_total - $expense_total;

$total_equity_side = $total_liab + $total_equity + $retained_earnings;
$is_balanced = abs($total_assets - $total_equity_side) < 0.01;
?>

<div class="card-custom">
    <h4 class="fw-bold mb-2 text-dark"><i class="fa-solid fa-building-columns"></i> Balance Sheet</h4>
    <p class="text-muted small mb-4"><i class="fa-solid fa-circle-info me-1"></i> নির্দিষ্ট তারিখ পর্যন্ত প্রতিষ্ঠানের সম্পদ, দায় ও ইকুইটির অবস্থা।</p>

    <form method="GET" action="index.php" class="row g-2 mb-4">
        <input type="hidden" name="page" value="accounts_balance_sheet">
        <div class="col-md-3">
            <label class="fw-bold small">তারিখ পর্যন্ত</label>
            <input type="date" name="as_of" class="form-control" value="<?php echo bs($as_of); ?>">
        </div>
        <div class="col-md-1 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">দেখুন</button>
        </div>
    </form>

    <?php if ($is_balanced): ?>
        <div class="alert alert-success py-2"><i class="fa-solid fa-circle-check"></i> ব্যালেন্সড — Assets = Liabilities + Equity</div>
    <?php else: ?>
        <div class="alert alert-danger py-2"><i class="fa-solid fa-triangle-exclamation"></i> ব্যালেন্সড না! পার্থক্যঃ <?php echo bs_money(abs($total_assets - $total_equity_side)); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-6">
            <h6 class="fw-bold text-primary">সম্পদ (Assets)</h6>
            <table class="table table-bordered table-sm">
                <tbody>
                    <?php foreach ($asset_rows as $r): ?>
                        <tr><td><?php echo bs($r['code'] . ' - ' . $r['name']); ?></td><td class="text-end"><?php echo bs_money($r['amt']); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$asset_rows): ?><tr><td colspan="2" class="text-center text-muted">কোনো সম্পদ নেই</td></tr><?php endif; ?>
                </tbody>
                <tfoot><tr class="table-light fw-bold"><td>মোট সম্পদ</td><td class="text-end"><?php echo bs_money($total_assets); ?></td></tr></tfoot>
            </table>
        </div>
        <div class="col-md-6">
            <h6 class="fw-bold text-secondary">দায় (Liabilities)</h6>
            <table class="table table-bordered table-sm">
                <tbody>
                    <?php foreach ($liability_rows as $r): ?>
                        <tr><td><?php echo bs($r['code'] . ' - ' . $r['name']); ?></td><td class="text-end"><?php echo bs_money($r['amt']); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$liability_rows): ?><tr><td colspan="2" class="text-center text-muted">কোনো দায় নেই</td></tr><?php endif; ?>
                </tbody>
                <tfoot><tr class="table-light fw-bold"><td>মোট দায়</td><td class="text-end"><?php echo bs_money($total_liab); ?></td></tr></tfoot>
            </table>

            <h6 class="fw-bold text-info mt-3">ইকুইটি (Equity)</h6>
            <table class="table table-bordered table-sm">
                <tbody>
                    <?php foreach ($equity_rows as $r): ?>
                        <tr><td><?php echo bs($r['code'] . ' - ' . $r['name']); ?></td><td class="text-end"><?php echo bs_money($r['amt']); ?></td></tr>
                    <?php endforeach; ?>
                    <tr><td>Retained Earnings (সঞ্চিত মুনাফা/ক্ষতি)</td><td class="text-end"><?php echo bs_money($retained_earnings); ?></td></tr>
                </tbody>
                <tfoot><tr class="table-light fw-bold"><td>মোট ইকুইটি</td><td class="text-end"><?php echo bs_money($total_equity + $retained_earnings); ?></td></tr></tfoot>
            </table>
        </div>
    </div>
</div>
