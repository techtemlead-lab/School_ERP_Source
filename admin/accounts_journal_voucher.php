<?php
// admin/accounts_journal_voucher.php - Accounts Module > Journal / Receipt / Payment / Contra Voucher
// এখানে সবকিছুই ac_post_journal() ফাংশন দিয়ে পোস্ট হয় — তাই সিস্টেম-ওয়াইড সব সময়
// মোট Debit = মোট Credit থাকতে বাধ্য (double-entry integrity)।
require_once __DIR__ . '/../config/accounts_helpers.php';
ac_ensure_tables($pdo);

$jv_msg = $_SESSION['jv_msg'] ?? '';
$jv_err = $_SESSION['jv_err'] ?? '';
unset($_SESSION['jv_msg'], $_SESSION['jv_err']);

$page  = $_GET['page'] ?? 'accounts_journal_voucher';
$vtype_map = [
    'accounts_journal_voucher' => 'Journal',
    'accounts_receipt_voucher' => 'Receipt',
    'accounts_payment_voucher' => 'Payment',
    'accounts_contra_voucher'  => 'Contra',
];
$journal_type = $vtype_map[$page] ?? 'Journal';
$prefix_map   = ['Journal' => 'JV', 'Receipt' => 'RV', 'Payment' => 'PV', 'Contra' => 'CV'];
$title_map    = [
    'Journal' => 'জার্নাল ভাউচার (Journal Voucher)',
    'Receipt' => 'রিসিপ্ট ভাউচার (Receipt) — টাকা প্রাপ্তি',
    'Payment' => 'পেমেন্ট ভাউচার (Payment) — টাকা প্রদান',
    'Contra'  => 'কন্ট্রা ভাউচার (Contra) — নগদ/ব্যাংক এর মধ্যে স্থানান্তর',
];

function jv(string $val): string { return htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); }
function jv_money($n): string { return number_format((float) $n, 2); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_voucher'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['jv_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=' . $page);
        exit();
    }

    $journal_date = $_POST['journal_date'] ?? date('Y-m-d');
    $narration    = trim($_POST['narration'] ?? '');
    $acc_ids      = $_POST['line_account'] ?? [];
    $drs          = $_POST['line_debit'] ?? [];
    $crs          = $_POST['line_credit'] ?? [];
    $remarks      = $_POST['line_remarks'] ?? [];

    $lines = [];
    foreach ($acc_ids as $i => $aid) {
        $aid = (int) $aid;
        $dr  = (float) ($drs[$i] ?? 0);
        $cr  = (float) ($crs[$i] ?? 0);
        if ($aid <= 0 || ($dr <= 0 && $cr <= 0)) {
            continue;
        }
        $lines[] = ['account_id' => $aid, 'debit' => $dr, 'credit' => $cr, 'remarks' => trim($remarks[$i] ?? '')];
    }

    if (count($lines) < 2) {
        $_SESSION['jv_err'] = 'কমপক্ষে ২টা লাইন (একটা Debit, একটা Credit) দিতে হবে।';
        header('Location: index.php?page=' . $page);
        exit();
    }

    try {
        $by = $_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Admin';
        $journal_id = ac_post_journal($pdo, $journal_type, $journal_date, $narration, $lines, (string) $by);
        $jno = $pdo->prepare("SELECT journal_no FROM accounts_journals WHERE id = ?");
        $jno->execute([$journal_id]);
        $jn = $jno->fetchColumn();
        $_SESSION['jv_msg'] = "{$title_map[$journal_type]} সফলভাবে পোস্ট হয়েছে। ভাউচার নং: {$jn}";
        sec_log_activity($pdo, 'Accounts', 'Create', "{$journal_type} Voucher পোস্ট করা হয়েছে: {$jn} — {$narration}");
    } catch (Throwable $e) {
        $_SESSION['jv_err'] = 'পোস্ট করা যায়নি: ' . $e->getMessage();
    }
    header('Location: index.php?page=' . $page);
    exit();
}

$postable_accounts = $pdo->query(
    "SELECT id, code, name, account_type FROM accounts_chart WHERE is_group = 0 AND status = 'Active' ORDER BY account_type ASC, code ASC"
)->fetchAll();

$recent = $pdo->prepare(
    "SELECT id, journal_no, journal_date, narration, (SELECT SUM(debit) FROM accounts_journal_lines WHERE journal_id = accounts_journals.id) AS total
     FROM accounts_journals WHERE journal_type = ? ORDER BY id DESC LIMIT 15"
);
$recent->execute([$journal_type]);
$recent_vouchers = $recent->fetchAll();
$csrf = htmlspecialchars(csrf_token());
?>

<div class="card-custom">
    <h4 class="fw-bold mb-2 text-dark"><i class="fa-solid fa-file-invoice"></i> <?php echo jv($title_map[$journal_type]); ?></h4>
    <p class="text-muted small mb-4">
        <i class="fa-solid fa-circle-info me-1"></i>
        প্রতিটা ভাউচার একটা <strong>Journal Entry</strong> হিসেবে পোস্ট হয় — মোট Debit ও মোট Credit
        সমান না হলে সিস্টেম পোস্ট করতে দেবে না।
    </p>

    <?php if ($jv_msg): ?><div class="alert alert-success py-2"><?php echo jv($jv_msg); ?></div><?php endif; ?>
    <?php if ($jv_err): ?><div class="alert alert-danger py-2"><?php echo jv($jv_err); ?></div><?php endif; ?>

    <form method="POST" action="index.php?page=<?php echo jv($page); ?>" id="jvForm">
        <?php echo csrf_field(); ?>
        <div class="row mb-3">
            <div class="col-md-3">
                <label class="fw-bold small">ভাউচার তারিখ</label>
                <input type="date" name="journal_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="col-md-9">
                <label class="fw-bold small">বর্ণনা (Narration)</label>
                <input type="text" name="narration" class="form-control" placeholder="যেমনঃ অক্টোবর মাসের অফিস ভাড়া প্রদান" required>
            </div>
        </div>

        <table class="table table-bordered align-middle" id="jvLinesTable">
            <thead class="table-light">
                <tr>
                    <th style="width:32%">Account</th>
                    <th style="width:20%">Remarks</th>
                    <th style="width:15%">Debit</th>
                    <th style="width:15%">Credit</th>
                    <th style="width:5%"></th>
                </tr>
            </thead>
            <tbody id="jvLinesBody">
                <?php for ($i = 0; $i < 4; $i++): ?>
                <tr>
                    <td>
                        <select name="line_account[]" class="form-select form-select-sm">
                            <option value="0">— Account বাছাই করুন —</option>
                            <?php foreach ($postable_accounts as $a): ?>
                                <option value="<?php echo (int) $a['id']; ?>"><?php echo jv($a['code'] . ' - ' . $a['name'] . ' (' . $a['account_type'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="text" name="line_remarks[]" class="form-control form-control-sm"></td>
                    <td><input type="number" step="0.01" name="line_debit[]" class="form-control form-control-sm jv-dr" value="0"></td>
                    <td><input type="number" step="0.01" name="line_credit[]" class="form-control form-control-sm jv-cr" value="0"></td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger jv-remove-row"><i class="fa-solid fa-xmark"></i></button></td>
                </tr>
                <?php endfor; ?>
            </tbody>
            <tfoot>
                <tr class="table-light fw-bold">
                    <td colspan="2" class="text-end">মোট (Total)</td>
                    <td id="jvDrTotal">0.00</td>
                    <td id="jvCrTotal">0.00</td>
                    <td></td>
                </tr>
                <tr>
                    <td colspan="5">
                        <span id="jvBalanceMsg" class="fw-bold"></span>
                    </td>
                </tr>
            </tfoot>
        </table>

        <button type="button" id="jvAddRow" class="btn btn-outline-secondary btn-sm mb-3"><i class="fa-solid fa-plus"></i> আরেকটা লাইন যুক্ত করুন</button>
        <br>
        <button type="submit" name="post_voucher" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;" id="jvSubmitBtn">পোস্ট করুন</button>
    </form>
</div>

<div class="card-custom mt-3">
    <h6 class="fw-bold text-secondary mb-3">সাম্প্রতিক <?php echo jv($journal_type); ?> ভাউচার</h6>
    <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle">
            <thead class="table-light">
                <tr><th>ভাউচার নং</th><th>তারিখ</th><th>বর্ণনা</th><th>পরিমাণ</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php if (!$recent_vouchers): ?>
                    <tr><td colspan="5" class="text-center text-muted">কোনো ভাউচার নেই</td></tr>
                <?php endif; ?>
                <?php foreach ($recent_vouchers as $r): ?>
                    <tr>
                        <td><?php echo jv($r['journal_no']); ?></td>
                        <td><?php echo jv($r['journal_date']); ?></td>
                        <td><?php echo jv($r['narration']); ?></td>
                        <td class="text-nowrap"><?php echo jv_money($r['total']); ?></td>
                        <td><a href="index.php?page=accounts_ledger&voucher_id=<?php echo (int) $r['id']; ?>" class="btn btn-sm btn-outline-primary">বিস্তারিত</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const body = document.getElementById('jvLinesBody');
    const drTotalEl = document.getElementById('jvDrTotal');
    const crTotalEl = document.getElementById('jvCrTotal');
    const balMsgEl = document.getElementById('jvBalanceMsg');
    const submitBtn = document.getElementById('jvSubmitBtn');

    function recalc() {
        let dr = 0, cr = 0;
        body.querySelectorAll('tr').forEach(function (row) {
            dr += parseFloat(row.querySelector('.jv-dr').value || 0);
            cr += parseFloat(row.querySelector('.jv-cr').value || 0);
        });
        drTotalEl.textContent = dr.toFixed(2);
        crTotalEl.textContent = cr.toFixed(2);
        if (Math.abs(dr - cr) < 0.01 && dr > 0) {
            balMsgEl.textContent = 'ব্যালেন্সড ✓';
            balMsgEl.className = 'fw-bold text-success';
            submitBtn.disabled = false;
        } else {
            balMsgEl.textContent = 'Debit ও Credit সমান নয় — পার্থক্যঃ ' + Math.abs(dr - cr).toFixed(2);
            balMsgEl.className = 'fw-bold text-danger';
            submitBtn.disabled = true;
        }
    }

    body.addEventListener('input', function (e) {
        if (e.target.classList.contains('jv-dr') || e.target.classList.contains('jv-cr')) {
            recalc();
        }
    });

    body.addEventListener('click', function (e) {
        const btn = e.target.closest('.jv-remove-row');
        if (btn) {
            if (body.querySelectorAll('tr').length > 2) {
                btn.closest('tr').remove();
                recalc();
            }
        }
    });

    document.getElementById('jvAddRow').addEventListener('click', function () {
        const first = body.querySelector('tr');
        const clone = first.cloneNode(true);
        clone.querySelectorAll('input').forEach(function (inp) {
            if (inp.type === 'number') inp.value = 0; else inp.value = '';
        });
        clone.querySelector('select').value = '0';
        body.appendChild(clone);
    });

    recalc();
});
</script>
