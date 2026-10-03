<?php
// admin/accounts_vendor_bills.php - Accounts Module > Accounts Payable > Vendor Bills
// বিল সেভ হলে অটো-জার্নাল পোস্ট হয়: Dr [Expense/Asset Account]  Cr Accounts Payable (code 2000)
require_once __DIR__ . '/../config/accounts_helpers.php';
ac_ensure_tables($pdo);

$vb_msg = $_SESSION['vb_msg'] ?? '';
$vb_err = $_SESSION['vb_err'] ?? '';
unset($_SESSION['vb_msg'], $_SESSION['vb_err']);

$view      = $_GET['view'] ?? 'list';
$vendor_id = (int) ($_GET['vendor_id'] ?? 0);

function vb(string $val): string { return htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); }
function vb_money($n): string { return number_format((float) $n, 2); }

$ap_stmt = $pdo->query("SELECT id FROM accounts_chart WHERE code = '2000' LIMIT 1");
$ap_account_id = (int) $ap_stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_bill'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['vb_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=accounts_vendor_bills' . ($vendor_id ? "&vendor_id=$vendor_id" : ''));
        exit();
    }

    $v_id      = (int) ($_POST['vendor_id'] ?? 0);
    $bill_no   = trim($_POST['bill_no'] ?? '');
    $bill_date = $_POST['bill_date'] ?? date('Y-m-d');
    $exp_acc   = (int) ($_POST['expense_account_id'] ?? 0);
    $amount    = (float) ($_POST['amount'] ?? 0);
    $desc      = trim($_POST['description'] ?? '');

    if ($v_id <= 0 || $exp_acc <= 0 || $amount <= 0 || $bill_no === '') {
        $_SESSION['vb_err'] = 'ভেন্ডর, বিল নং, Account ও পরিমাণ সব দিতে হবে।';
        header('Location: index.php?page=accounts_vendor_bills&view=add' . ($v_id ? "&vendor_id=$v_id" : ''));
        exit();
    }

    if (!$ap_account_id) {
        $_SESSION['vb_err'] = 'Accounts Payable (code 2000) অ্যাকাউন্ট পাওয়া যায়নি। Chart of Accounts চেক করুন।';
        header('Location: index.php?page=accounts_vendor_bills');
        exit();
    }

    // দ্রষ্টব্যঃ ac_post_journal() নিজেই একটা transaction চালায় (PDO নেস্টেড transaction সাপোর্ট করে না),
    // তাই এখানে বাড়তি beginTransaction/commit না করে বিল ইনসার্টের পর journal পোস্ট করা হয়েছে;
    // journal পোস্ট ব্যর্থ হলে সদ্য-তৈরি বিলটা ম্যানুয়ালি রোলব্যাক (ডিলিট) করে দেওয়া হয়।
    $bill_id = null;
    try {
        $pdo->prepare(
            "INSERT INTO accounts_vendor_bills (vendor_id, bill_no, bill_date, expense_account_id, amount, paid_amount, description, status) VALUES (?, ?, ?, ?, ?, 0, ?, 'Unpaid')"
        )->execute([$v_id, $bill_no, $bill_date, $exp_acc, $amount, $desc]);
        $bill_id = (int) $pdo->lastInsertId();

        $vname = $pdo->prepare("SELECT name FROM accounts_vendors WHERE id = ?");
        $vname->execute([$v_id]);
        $vendor_name = $vname->fetchColumn();

        $by = $_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Admin';
        $narration = "ভেন্ডর বিল #{$bill_no} — {$vendor_name}" . ($desc ? " ({$desc})" : '');
        $lines = [
            ['account_id' => $exp_acc, 'debit' => $amount, 'credit' => 0, 'remarks' => $vendor_name],
            ['account_id' => $ap_account_id, 'debit' => 0, 'credit' => $amount, 'remarks' => $vendor_name],
        ];
        $journal_id = ac_post_journal($pdo, 'Journal', $bill_date, $narration, $lines, (string) $by, 'vendor_bill', $bill_id);

        $pdo->prepare("UPDATE accounts_vendor_bills SET journal_id = ? WHERE id = ?")->execute([$journal_id, $bill_id]);

        $_SESSION['vb_msg'] = 'ভেন্ডর বিল যুক্ত হয়েছে এবং জার্নালে অটো-পোস্ট হয়ে গেছে।';
        sec_log_activity($pdo, 'Accounts', 'Create', "ভেন্ডর বিল যুক্ত হয়েছে: {$bill_no} — {$vendor_name} ({$amount})");
    } catch (Throwable $e) {
        if ($bill_id) {
            $pdo->prepare("DELETE FROM accounts_vendor_bills WHERE id = ?")->execute([$bill_id]);
        }
        $_SESSION['vb_err'] = 'বিল পোস্ট করা যায়নি: ' . $e->getMessage();
    }
    header('Location: index.php?page=accounts_vendor_bills' . ($v_id ? "&vendor_id=$v_id" : ''));
    exit();
}

$vendors = $pdo->query("SELECT id, name FROM accounts_vendors WHERE status = 'Active' ORDER BY name ASC")->fetchAll();
$expense_asset_accounts = $pdo->query(
    "SELECT id, code, name, account_type FROM accounts_chart WHERE account_type IN ('Expense','Asset') AND is_group = 0 ORDER BY account_type ASC, code ASC"
)->fetchAll();

$sql = "SELECT b.*, v.name AS vendor_name FROM accounts_vendor_bills b JOIN accounts_vendors v ON v.id = b.vendor_id";
$params = [];
if ($vendor_id > 0) {
    $sql .= " WHERE b.vendor_id = ?";
    $params[] = $vendor_id;
}
$sql .= " ORDER BY b.id DESC";
$bstmt = $pdo->prepare($sql);
$bstmt->execute($params);
$bills = $bstmt->fetchAll();

$current_vendor_name = '';
if ($vendor_id > 0) {
    $cv = $pdo->prepare("SELECT name FROM accounts_vendors WHERE id = ?");
    $cv->execute([$vendor_id]);
    $current_vendor_name = $cv->fetchColumn();
}
?>

<div class="card-custom">
    <h4 class="fw-bold mb-2 text-dark"><i class="fa-solid fa-file-invoice-dollar"></i> Vendor Bills<?php echo $current_vendor_name ? ' — ' . vb($current_vendor_name) : ''; ?></h4>
    <p class="text-muted small mb-4"><i class="fa-solid fa-circle-info me-1"></i> নতুন বিল সেভ করলে অটোমেটিক জার্নাল পোস্ট হয়ে যায়: Dr [Expense/Asset] &amp; Cr Accounts Payable।</p>

    <?php if ($vb_msg): ?><div class="alert alert-success py-2"><?php echo vb($vb_msg); ?></div><?php endif; ?>
    <?php if ($vb_err): ?><div class="alert alert-danger py-2"><?php echo vb($vb_err); ?></div><?php endif; ?>

    <?php if ($view === 'add'): ?>
        <form method="POST" action="index.php?page=accounts_vendor_bills">
            <?php echo csrf_field(); ?>
            <div class="row mb-3"><label class="col-sm-3 col-form-label fw-bold text-end">ভেন্ডর <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="vendor_id" class="form-select" required>
                        <option value="0">— বাছাই করুন —</option>
                        <?php foreach ($vendors as $v): ?>
                            <option value="<?php echo (int) $v['id']; ?>" <?php echo $vendor_id === (int) $v['id'] ? 'selected' : ''; ?>><?php echo vb($v['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div></div>
            <div class="row mb-3"><label class="col-sm-3 col-form-label fw-bold text-end">বিল নং <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="bill_no" class="form-control" required></div></div>
            <div class="row mb-3"><label class="col-sm-3 col-form-label fw-bold text-end">বিল তারিখ</label>
                <div class="col-sm-6"><input type="date" name="bill_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div></div>
            <div class="row mb-3"><label class="col-sm-3 col-form-label fw-bold text-end">Expense/Asset Account <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="expense_account_id" class="form-select" required>
                        <option value="0">— বাছাই করুন —</option>
                        <?php foreach ($expense_asset_accounts as $a): ?>
                            <option value="<?php echo (int) $a['id']; ?>"><?php echo vb($a['code'] . ' - ' . $a['name'] . ' (' . $a['account_type'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div></div>
            <div class="row mb-3"><label class="col-sm-3 col-form-label fw-bold text-end">পরিমাণ <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="number" step="0.01" name="amount" class="form-control" required></div></div>
            <div class="row mb-3"><label class="col-sm-3 col-form-label fw-bold text-end">বর্ণনা</label>
                <div class="col-sm-6"><input type="text" name="description" class="form-control"></div></div>
            <hr>
            <div class="row"><div class="col-sm-6 offset-sm-3">
                <button type="submit" name="save_bill" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;">Save &amp; Post</button>
                <a href="index.php?page=accounts_vendor_bills<?php echo $vendor_id ? "&vendor_id=$vendor_id" : ''; ?>" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div></div>
        </form>
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=accounts_vendor_bills&view=add<?php echo $vendor_id ? "&vendor_id=$vendor_id" : ''; ?>" class="btn btn-success"><i class="fa-solid fa-plus"></i> নতুন বিল</a>
            <span class="text-muted small">মোট <?php echo count($bills); ?> টা বিল</span>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
                <thead class="table-light"><tr><th>বিল নং</th><th>ভেন্ডর</th><th>তারিখ</th><th>পরিমাণ</th><th>পরিশোধিত</th><th>বাকি</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($bills as $b): $due = (float) $b['amount'] - (float) $b['paid_amount']; ?>
                        <tr>
                            <td><?php echo vb($b['bill_no']); ?></td>
                            <td><?php echo vb($b['vendor_name']); ?></td>
                            <td><?php echo vb($b['bill_date']); ?></td>
                            <td class="text-nowrap"><?php echo vb_money($b['amount']); ?></td>
                            <td class="text-nowrap"><?php echo vb_money($b['paid_amount']); ?></td>
                            <td class="text-nowrap"><?php echo vb_money($due); ?></td>
                            <td>
                                <?php
                                $badge = ['Unpaid' => 'secondary', 'Partial' => 'warning', 'Paid' => 'success'][$b['status']] ?? 'secondary';
                                echo "<span class=\"badge bg-$badge\">" . vb($b['status']) . "</span>";
                                ?>
                            </td>
                            <td>
                                <?php if ($due > 0.005): ?>
                                    <a href="index.php?page=accounts_vendor_payment&bill_id=<?php echo (int) $b['id']; ?>" class="btn btn-sm btn-outline-success">Pay</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$bills): ?><tr><td colspan="8" class="text-center text-muted">কোনো বিল নেই</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
