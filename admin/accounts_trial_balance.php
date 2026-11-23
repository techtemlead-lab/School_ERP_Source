<?php
// admin/accounts_trial_balance.php - Accounts Module > Trial Balance
// এটা কোনো হাতে ধরে রাখা রিপোর্ট না — সরাসরি accounts_journal_lines থেকে যোগফল
// বের করা হয়, তাই মোট Debit = মোট Credit না হলে বুঝতে হবে ac_post_journal()-এ কোথাও সমস্যা আছে।
require_once __DIR__ . '/../config/accounts_helpers.php';
require_once __DIR__ . '/../config/table_print.php';
ac_ensure_tables($pdo);

function tb(string $val): string { return htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); }
function tb_money($n): string { return number_format((float) $n, 2); }

$as_of = $_GET['as_of'] ?? date('Y-m-d');

$accounts = $pdo->query("SELECT * FROM accounts_chart WHERE is_group = 0 ORDER BY account_type ASC, code ASC")->fetchAll();

$rows = [];
$total_dr = 0.0;
$total_cr = 0.0;
foreach ($accounts as $a) {
    $bal = ac_account_balance($pdo, (int) $a['id'], $as_of);
    if (abs($bal) < 0.005) {
        continue;
    }
    $normal = ac_normal_side($a['account_type']);
    $dr = 0.0; $cr = 0.0;
    if ($bal >= 0) {
        if ($normal === 'Dr') { $dr = $bal; } else { $cr = $bal; }
    } else {
        if ($normal === 'Dr') { $cr = abs($bal); } else { $dr = abs($bal); }
    }
    $total_dr += $dr;
    $total_cr += $cr;
    $rows[] = ['code' => $a['code'], 'name' => $a['name'], 'type' => $a['account_type'], 'dr' => $dr, 'cr' => $cr];
}
$is_balanced = abs($total_dr - $total_cr) < 0.01;
?>
<?php echo tprint_page_css('portrait', 11); ?>

<div class="card-custom">
    <?php echo tprint_header_html($pdo, 'Trial Balance', 'As of: ' . $as_of); ?>
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <h4 class="fw-bold mb-0 text-dark d-print-none"><i class="fa-solid fa-scale-balanced"></i> Trial Balance</h4>
        <?php echo tprint_controls(['orient' => 'portrait', 'wrap' => false]); ?>
    </div>
    <p class="text-muted small mb-4 no-print"><i class="fa-solid fa-circle-info me-1"></i> নির্দিষ্ট তারিখ পর্যন্ত সব Account-এর ব্যালেন্স। মোট Debit ও মোট Credit সব সময় সমান থাকবে।</p>

    <form method="GET" action="index.php" class="row g-2 mb-4 no-print">
        <input type="hidden" name="page" value="accounts_trial_balance">
        <div class="col-md-3">
            <label class="fw-bold small">তারিখ পর্যন্ত</label>
            <input type="date" name="as_of" class="form-control" value="<?php echo tb($as_of); ?>">
        </div>
        <div class="col-md-1 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">দেখুন</button>
        </div>
    </form>

    <?php if ($is_balanced): ?>
        <div class="alert alert-success py-2 tprint-keep-alert"><i class="fa-solid fa-circle-check"></i> ব্যালেন্সড — মোট Debit = মোট Credit</div>
    <?php else: ?>
        <div class="alert alert-danger py-2 tprint-keep-alert"><i class="fa-solid fa-triangle-exclamation"></i> ব্যালেন্সড না! পার্থক্যঃ <?php echo tb_money(abs($total_dr - $total_cr)); ?></div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle">
            <thead class="table-light">
                <tr><th>Code</th><th>Account</th><th>Type</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo tb($r['code']); ?></td>
                        <td><?php echo tb($r['name']); ?></td>
                        <td><?php echo tb($r['type']); ?></td>
                        <td class="text-end text-nowrap"><?php echo $r['dr'] > 0 ? tb_money($r['dr']) : ''; ?></td>
                        <td class="text-end text-nowrap"><?php echo $r['cr'] > 0 ? tb_money($r['cr']) : ''; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="5" class="text-center text-muted">কোনো ব্যালেন্স নেই</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="table-light fw-bold">
                    <td colspan="3" class="text-end">সর্বমোট</td>
                    <td class="text-end"><?php echo tb_money($total_dr); ?></td>
                    <td class="text-end"><?php echo tb_money($total_cr); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
