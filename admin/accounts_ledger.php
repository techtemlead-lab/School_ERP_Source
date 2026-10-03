<?php
// admin/accounts_ledger.php - Accounts Module > General Ledger (per Account, running balance)
require_once __DIR__ . '/../config/accounts_helpers.php';
ac_ensure_tables($pdo);

function gl(string $val): string { return htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); }
function gl_money($n): string { return number_format((float) $n, 2); }

$accounts = $pdo->query("SELECT id, code, name, account_type, opening_balance, opening_balance_type FROM accounts_chart WHERE is_group = 0 ORDER BY account_type ASC, code ASC")->fetchAll();

$account_id = (int) ($_GET['account_id'] ?? 0);
$from_date  = $_GET['from_date'] ?? date('Y-m-01');
$to_date    = $_GET['to_date'] ?? date('Y-m-d');

// If jumping in from a voucher link, resolve to that voucher's first account
if (!$account_id && (int) ($_GET['voucher_id'] ?? 0) > 0) {
    $vstmt = $pdo->prepare("SELECT account_id FROM accounts_journal_lines WHERE journal_id = ? ORDER BY id ASC LIMIT 1");
    $vstmt->execute([(int) $_GET['voucher_id']]);
    $account_id = (int) $vstmt->fetchColumn();
}

$acc = null;
$entries = [];
$opening_running = 0.0;

if ($account_id > 0) {
    $astmt = $pdo->prepare("SELECT * FROM accounts_chart WHERE id = ?");
    $astmt->execute([$account_id]);
    $acc = $astmt->fetch();

    if ($acc) {
        $normal = ac_normal_side($acc['account_type']);
        $ob = (float) $acc['opening_balance'];
        $ob_signed = ($acc['opening_balance_type'] === $normal) ? $ob : -$ob;

        // balance carried forward before from_date
        $prior = $pdo->prepare(
            "SELECT COALESCE(SUM(l.debit),0) AS d, COALESCE(SUM(l.credit),0) AS c
             FROM accounts_journal_lines l JOIN accounts_journals j ON j.id = l.journal_id
             WHERE l.account_id = ? AND j.journal_date < ?"
        );
        $prior->execute([$account_id, $from_date]);
        $prow = $prior->fetch();
        $prior_signed = ($normal === 'Dr') ? ((float) $prow['d'] - (float) $prow['c']) : ((float) $prow['c'] - (float) $prow['d']);
        $opening_running = $ob_signed + $prior_signed;

        $lstmt = $pdo->prepare(
            "SELECT j.journal_no, j.journal_date, j.journal_type, j.narration, l.debit, l.credit, l.remarks
             FROM accounts_journal_lines l JOIN accounts_journals j ON j.id = l.journal_id
             WHERE l.account_id = ? AND j.journal_date BETWEEN ? AND ?
             ORDER BY j.journal_date ASC, l.id ASC"
        );
        $lstmt->execute([$account_id, $from_date, $to_date]);
        $entries = $lstmt->fetchAll();
    }
}
?>

<div class="card-custom">
    <h4 class="fw-bold mb-2 text-dark"><i class="fa-solid fa-book"></i> General Ledger</h4>
    <p class="text-muted small mb-4"><i class="fa-solid fa-circle-info me-1"></i> নির্দিষ্ট একটা Account-এর সব Journal লেনদেন ও Running Balance দেখুন।</p>

    <form method="GET" action="index.php" class="row g-2 mb-4">
        <input type="hidden" name="page" value="accounts_ledger">
        <div class="col-md-5">
            <label class="fw-bold small">Account</label>
            <select name="account_id" class="form-select" required>
                <option value="0">— Account বাছাই করুন —</option>
                <?php foreach ($accounts as $a): ?>
                    <option value="<?php echo (int) $a['id']; ?>" <?php echo $account_id === (int) $a['id'] ? 'selected' : ''; ?>><?php echo gl($a['code'] . ' - ' . $a['name'] . ' (' . $a['account_type'] . ')'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="fw-bold small">শুরুর তারিখ</label>
            <input type="date" name="from_date" class="form-control" value="<?php echo gl($from_date); ?>">
        </div>
        <div class="col-md-3">
            <label class="fw-bold small">শেষ তারিখ</label>
            <input type="date" name="to_date" class="form-control" value="<?php echo gl($to_date); ?>">
        </div>
        <div class="col-md-1 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">দেখুন</button>
        </div>
    </form>

    <?php if ($acc): $normal = ac_normal_side($acc['account_type']); ?>
        <h6 class="fw-bold text-secondary"><?php echo gl($acc['code'] . ' - ' . $acc['name']); ?> <span class="badge bg-info text-dark"><?php echo gl($acc['account_type']); ?></span></h6>
        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
                <thead class="table-light">
                    <tr><th>তারিখ</th><th>ভাউচার নং</th><th>Type</th><th>বর্ণনা</th><th>Debit</th><th>Credit</th><th>ব্যালেন্স</th></tr>
                </thead>
                <tbody>
                    <tr class="table-light fw-bold">
                        <td colspan="6" class="text-end">Opening Balance</td>
                        <td class="text-nowrap"><?php echo gl_money(abs($opening_running)) . ' ' . ($opening_running >= 0 ? $normal : ($normal === 'Dr' ? 'Cr' : 'Dr')); ?></td>
                    </tr>
                    <?php $running = $opening_running; ?>
                    <?php foreach ($entries as $e):
                        $running += ($normal === 'Dr') ? ((float) $e['debit'] - (float) $e['credit']) : ((float) $e['credit'] - (float) $e['debit']);
                    ?>
                        <tr>
                            <td><?php echo gl($e['journal_date']); ?></td>
                            <td><?php echo gl($e['journal_no']); ?></td>
                            <td><?php echo gl($e['journal_type']); ?></td>
                            <td><?php echo gl($e['narration'] . ($e['remarks'] ? ' — ' . $e['remarks'] : '')); ?></td>
                            <td class="text-nowrap"><?php echo (float) $e['debit'] > 0 ? gl_money($e['debit']) : ''; ?></td>
                            <td class="text-nowrap"><?php echo (float) $e['credit'] > 0 ? gl_money($e['credit']) : ''; ?></td>
                            <td class="text-nowrap fw-bold"><?php echo gl_money(abs($running)) . ' ' . ($running >= 0 ? $normal : ($normal === 'Dr' ? 'Cr' : 'Dr')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$entries): ?>
                        <tr><td colspan="7" class="text-center text-muted">এই সময়সীমায় কোনো লেনদেন নেই</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="text-muted">একটা Account বাছাই করুন।</p>
    <?php endif; ?>
</div>
