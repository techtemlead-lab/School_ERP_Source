<?php
// admin/accounts_vendor_payment.php - Accounts Module > Accounts Payable > Vendor Payment
// পেমেন্ট সেভ হলে অটো-জার্নাল পোস্ট হয়: Dr Accounts Payable (code 2000)  Cr Cash/Bank
require_once __DIR__ . '/../config/accounts_helpers.php';
ac_ensure_tables($pdo);

$vp_msg = $_SESSION['vp_msg'] ?? '';
$vp_err = $_SESSION['vp_err'] ?? '';
unset($_SESSION['vp_msg'], $_SESSION['vp_err']);

function vp(string $val): string { return htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); }
function vp_money($n): string { return number_format((float) $n, 2); }

$ap_stmt = $pdo->query("SELECT id FROM accounts_chart WHERE code = '2000' LIMIT 1");
$ap_account_id = (int) $ap_stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['vp_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=accounts_vendor_payment');
        exit();
    }

    $bill_id     = (int) ($_POST['bill_id'] ?? 0);
    $vendor_id   = (int) ($_POST['vendor_id'] ?? 0);
    $amount      = (float) ($_POST['amount'] ?? 0);
    $pay_date    = $_POST['payment_date'] ?? date('Y-m-d');
    $pay_account = (int) ($_POST['payment_account_id'] ?? 0);

    $bstmt = $pdo->prepare("SELECT b.*, v.name AS vendor_name FROM accounts_vendor_bills b JOIN accounts_vendors v ON v.id = b.vendor_id WHERE b.id = ?");
    $bstmt->execute([$bill_id]);
    $bill = $bstmt->fetch();

    if (!$bill || $amount <= 0 || $pay_account <= 0) {
        $_SESSION['vp_err'] = 'বিল, পরিমাণ ও পেমেন্ট Account সব ঠিক থাকতে হবে।';
        header('Location: index.php?page=accounts_vendor_payment' . ($bill_id ? "&bill_id=$bill_id" : ''));
        exit();
    }

    $due = (float) $bill['amount'] - (float) $bill['paid_amount'];
    if ($amount > $due + 0.005) {
        $_SESSION['vp_err'] = 'পেমেন্ট বিলের বাকি পরিমাণের (' . vp_money($due) . ') বেশি হতে পারবে না।';
        header('Location: index.php?page=accounts_vendor_payment&bill_id=' . $bill_id);
        exit();
    }

    if (!$ap_account_id) {
        $_SESSION['vp_err'] = 'Accounts Payable (code 2000) অ্যাকাউন্ট পাওয়া যায়নি।';
        header('Location: index.php?page=accounts_vendor_payment');
        exit();
    }

    // দ্রষ্টব্যঃ ac_post_journal() নিজেই একটা transaction চালায়, তাই এখানে বাড়তি
    // beginTransaction/commit না করে পেমেন্ট ইনসার্টের পর journal পোস্ট করা হয়েছে;
    // ব্যর্থ হলে সদ্য-তৈরি পেমেন্ট রো ম্যানুয়ালি ডিলিট করে দেওয়া হয়।
    $payment_id = null;
    try {
        $pdo->prepare(
            "INSERT INTO accounts_vendor_payments (vendor_id, bill_id, amount, payment_date, payment_account_id) VALUES (?, ?, ?, ?, ?)"
        )->execute([$vendor_id, $bill_id, $amount, $pay_date, $pay_account]);
        $payment_id = (int) $pdo->lastInsertId();

        $by = $_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Admin';
        $narration = "ভেন্ডর পেমেন্ট — {$bill['vendor_name']} (বিল #{$bill['bill_no']})";
        $lines = [
            ['account_id' => $ap_account_id, 'debit' => $amount, 'credit' => 0, 'remarks' => $bill['vendor_name']],
            ['account_id' => $pay_account, 'debit' => 0, 'credit' => $amount, 'remarks' => $bill['vendor_name']],
        ];
        $journal_id = ac_post_journal($pdo, 'Payment', $pay_date, $narration, $lines, (string) $by, 'vendor_payment', $payment_id);
        $pdo->prepare("UPDATE accounts_vendor_payments SET journal_id = ? WHERE id = ?")->execute([$journal_id, $payment_id]);

        $new_paid = (float) $bill['paid_amount'] + $amount;
        $new_status = $new_paid >= (float) $bill['amount'] - 0.005 ? 'Paid' : ($new_paid > 0 ? 'Partial' : 'Unpaid');
        $pdo->prepare("UPDATE accounts_vendor_bills SET paid_amount = ?, status = ? WHERE id = ?")->execute([$new_paid, $new_status, $bill_id]);

        $_SESSION['vp_msg'] = 'পেমেন্ট সফলভাবে পোস্ট হয়েছে।';
        sec_log_activity($pdo, 'Accounts', 'Create', "ভেন্ডর পেমেন্ট পোস্ট হয়েছে: {$bill['vendor_name']} — বিল #{$bill['bill_no']} — {$amount}");
    } catch (Throwable $e) {
        if ($payment_id) {
            $pdo->prepare("DELETE FROM accounts_vendor_payments WHERE id = ?")->execute([$payment_id]);
        }
        $_SESSION['vp_err'] = 'পেমেন্ট পোস্ট করা যায়নি: ' . $e->getMessage();
    }
    header('Location: index.php?page=accounts_vendor_bills' . ($vendor_id ? "&vendor_id=$vendor_id" : ''));
    exit();
}

$bill_id = (int) ($_GET['bill_id'] ?? 0);
$bill = null;
if ($bill_id > 0) {
    $bstmt = $pdo->prepare("SELECT b.*, v.name AS vendor_name FROM accounts_vendor_bills b JOIN accounts_vendors v ON v.id = b.vendor_id WHERE b.id = ?");
    $bstmt->execute([$bill_id]);
    $bill = $bstmt->fetch();
}

$cash_bank_accounts = $pdo->query(
    "SELECT id, code, name FROM accounts_chart WHERE account_type = 'Asset' AND is_group = 0 AND code IN ('1000','1001') ORDER BY code ASC"
)->fetchAll();
if (!$cash_bank_accounts) {
    $cash_bank_accounts = $pdo->query("SELECT id, code, name FROM accounts_chart WHERE account_type = 'Asset' AND is_group = 0 ORDER BY code ASC")->fetchAll();
}
?>

<div class="card-custom">
    <h4 class="fw-bold mb-2 text-dark"><i class="fa-solid fa-money-check-dollar"></i> Vendor Payment</h4>
    <p class="text-muted small mb-4"><i class="fa-solid fa-circle-info me-1"></i> পেমেন্ট সেভ করলে অটো-জার্নাল পোস্ট হবেঃ Dr Accounts Payable &amp; Cr Cash/Bank।</p>

    <?php if ($vp_msg): ?><div class="alert alert-success py-2"><?php echo vp($vp_msg); ?></div><?php endif; ?>
    <?php if ($vp_err): ?><div class="alert alert-danger py-2"><?php echo vp($vp_err); ?></div><?php endif; ?>

    <?php if (!$bill): ?>
        <p class="text-muted">Vendor Bills লিস্ট থেকে "Pay" বাটনে ক্লিক করে এখানে আসুন।</p>
        <a href="index.php?page=accounts_vendor_bills" class="btn btn-outline-primary">Vendor Bills দেখুন</a>
    <?php else: $due = (float) $bill['amount'] - (float) $bill['paid_amount']; ?>
        <div class="alert alert-secondary">
            <strong>ভেন্ডরঃ</strong> <?php echo vp($bill['vendor_name']); ?> &nbsp;|&nbsp;
            <strong>বিল নংঃ</strong> <?php echo vp($bill['bill_no']); ?> &nbsp;|&nbsp;
            <strong>মোটঃ</strong> <?php echo vp_money($bill['amount']); ?> &nbsp;|&nbsp;
            <strong>পরিশোধিতঃ</strong> <?php echo vp_money($bill['paid_amount']); ?> &nbsp;|&nbsp;
            <strong>বাকিঃ</strong> <?php echo vp_money($due); ?>
        </div>
        <form method="POST" action="index.php?page=accounts_vendor_payment">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="bill_id" value="<?php echo (int) $bill['id']; ?>">
            <input type="hidden" name="vendor_id" value="<?php echo (int) $bill['vendor_id']; ?>">
            <div class="row mb-3"><label class="col-sm-3 col-form-label fw-bold text-end">পরিমাণ <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="number" step="0.01" name="amount" class="form-control" value="<?php echo vp_money($due); ?>" max="<?php echo vp_money($due); ?>" required></div></div>
            <div class="row mb-3"><label class="col-sm-3 col-form-label fw-bold text-end">তারিখ</label>
                <div class="col-sm-6"><input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div></div>
            <div class="row mb-3"><label class="col-sm-3 col-form-label fw-bold text-end">পেমেন্ট Account <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="payment_account_id" class="form-select" required>
                        <?php foreach ($cash_bank_accounts as $a): ?>
                            <option value="<?php echo (int) $a['id']; ?>"><?php echo vp($a['code'] . ' - ' . $a['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div></div>
            <hr>
            <div class="row"><div class="col-sm-6 offset-sm-3">
                <button type="submit" name="save_payment" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;">Pay &amp; Post</button>
                <a href="index.php?page=accounts_vendor_bills&vendor_id=<?php echo (int) $bill['vendor_id']; ?>" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div></div>
        </form>
    <?php endif; ?>
</div>
