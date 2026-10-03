<?php
// admin/accounts_chart.php - Accounts Module > Chart of Accounts
// আসল Double-Entry Accounting-এর ভিত্তি — প্রতিটা Account একটা নির্দিষ্ট টাইপে থাকে
// (Asset/Liability/Equity/Income/Expense) যা দিয়ে Trial Balance/Balance Sheet/P&L
// স্বয়ংক্রিয়ভাবে ঠিকভাবে হিসাব হয়। প্রথমবার ভিজিট করলেই একটা স্ট্যান্ডার্ড সেট অটো-তৈরি হয়ে যায়
// (ac_seed_default_accounts) — is_system=1 চিহ্নিত অ্যাকাউন্টগুলো ডিলিট করা যায় না।
require_once __DIR__ . '/../config/accounts_helpers.php';
ac_ensure_tables($pdo);

$ac_msg = $_SESSION['ac_msg'] ?? '';
$ac_err = $_SESSION['ac_err'] ?? '';
unset($_SESSION['ac_msg'], $_SESSION['ac_err']);

$view = $_GET['view'] ?? 'list';

function acv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function ac_money($n): string { return number_format((float) $n, 2); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ac_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=accounts_chart');
        exit();
    }

    if (isset($_POST['save_account'])) {
        $id           = (int) ($_POST['id'] ?? 0);
        $code         = trim($_POST['code'] ?? '');
        $name         = trim($_POST['name'] ?? '');
        $account_type = $_POST['account_type'] ?? 'Asset';
        $parent_id    = (int) ($_POST['parent_id'] ?? 0) ?: null;
        $is_group     = isset($_POST['is_group']) ? 1 : 0;
        $ob_amount    = (float) ($_POST['opening_balance'] ?? 0);
        $ob_type      = ($_POST['opening_balance_type'] ?? 'Dr') === 'Cr' ? 'Cr' : 'Dr';

        if (!in_array($account_type, ['Asset', 'Liability', 'Equity', 'Income', 'Expense'], true)) {
            $account_type = 'Asset';
        }

        if ($code === '' || $name === '') {
            $_SESSION['ac_err'] = 'কোড ও নাম আবশ্যক।';
            header('Location: index.php?page=accounts_chart&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }

        if ($id > 0) {
            $pdo->prepare(
                "UPDATE accounts_chart SET code=?, name=?, account_type=?, parent_id=?, is_group=?, opening_balance=?, opening_balance_type=? WHERE id=?"
            )->execute([$code, $name, $account_type, $parent_id, $is_group, $ob_amount, $ob_type, $id]);
            $_SESSION['ac_msg'] = 'Account আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Accounts', 'Update', "Chart of Accounts আপডেট করা হয়েছে: {$code} - {$name}");
        } else {
            $pdo->prepare(
                "INSERT INTO accounts_chart (code, name, account_type, parent_id, is_group, opening_balance, opening_balance_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')"
            )->execute([$code, $name, $account_type, $parent_id, $is_group, $ob_amount, $ob_type]);
            $_SESSION['ac_msg'] = 'নতুন Account যুক্ত করা হয়েছে।';
            sec_log_activity($pdo, 'Accounts', 'Create', "Chart of Accounts-এ নতুন Account যুক্ত হয়েছে: {$code} - {$name} ({$account_type})");
        }
        header('Location: index.php?page=accounts_chart');
        exit();
    } elseif (isset($_POST['delete_account'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $chk = $pdo->prepare("SELECT code, name, is_system FROM accounts_chart WHERE id = ?");
        $chk->execute([$id]);
        $row = $chk->fetch();
        if ($row && $row['is_system']) {
            $_SESSION['ac_err'] = 'এটা একটা সিস্টেম Account, ডিলিট করা যাবে না।';
        } else {
            $used = $pdo->prepare("SELECT COUNT(*) FROM accounts_journal_lines WHERE account_id = ?");
            $used->execute([$id]);
            if ((int) $used->fetchColumn() > 0) {
                $_SESSION['ac_err'] = 'এই Account-এ আগে থেকে জার্নাল এন্ট্রি আছে, ডিলিট করা যাবে না। বদলে Inactive করে দিন।';
            } else {
                $pdo->prepare("DELETE FROM accounts_chart WHERE id = ?")->execute([$id]);
                $_SESSION['ac_msg'] = 'Account মুছে ফেলা হয়েছে।';
                sec_log_activity($pdo, 'Accounts', 'Delete', "Chart of Accounts থেকে Account ডিলিট করা হয়েছে: " . ($row ? "{$row['code']} - {$row['name']}" : "#$id"));
            }
        }
        header('Location: index.php?page=accounts_chart');
        exit();
    } elseif (isset($_POST['toggle_account'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE accounts_chart SET status = IF(status='Active','Inactive','Active') WHERE id = ?")->execute([$id]);
        $_SESSION['ac_msg'] = 'স্ট্যাটাস বদলানো হয়েছে।';
        header('Location: index.php?page=accounts_chart');
        exit();
    }
}

$all_accounts = $pdo->query("SELECT * FROM accounts_chart ORDER BY account_type ASC, code ASC")->fetchAll();
$type_order = ['Asset' => 'সম্পদ (Asset)', 'Liability' => 'দায় (Liability)', 'Equity' => 'ইকুইটি (Equity)', 'Income' => 'আয় (Income)', 'Expense' => 'ব্যয় (Expense)'];
?>

<div class="card-custom">
    <h4 class="fw-bold mb-2 text-dark"><i class="fa-solid fa-sitemap"></i> Accounts Module — Chart of Accounts</h4>
    <p class="text-muted small mb-4">
        <i class="fa-solid fa-circle-info me-1"></i>
        এটা প্রতিষ্ঠানের পূর্ণ Double-Entry অ্যাকাউন্টিং-এর ভিত্তি — Finance Module (Student Fee)-এর
        Account Head থেকে সম্পূর্ণ আলাদা। প্রতিটা Account একটা টাইপে থাকে যা দিয়ে Trial Balance,
        Balance Sheet ও Profit &amp; Loss স্বয়ংক্রিয়ভাবে সঠিক হিসাব হয়।
    </p>

    <?php if ($ac_msg): ?><div class="alert alert-success py-2"><?php echo acv($ac_msg); ?></div><?php endif; ?>
    <?php if ($ac_err): ?><div class="alert alert-danger py-2"><?php echo acv($ac_err); ?></div><?php endif; ?>

    <?php if ($view === 'add' || $view === 'edit'):
        $d = ['id' => 0, 'code' => '', 'name' => '', 'account_type' => 'Asset', 'parent_id' => 0, 'is_group' => 0, 'opening_balance' => 0, 'opening_balance_type' => 'Dr'];
        if ($view === 'edit') {
            $stmt = $pdo->prepare("SELECT * FROM accounts_chart WHERE id = ?");
            $stmt->execute([(int) ($_GET['id'] ?? 0)]);
            $row = $stmt->fetch();
            if ($row) $d = $row;
        }
        $group_accounts = $pdo->query("SELECT id, code, name FROM accounts_chart WHERE is_group = 1 ORDER BY code ASC")->fetchAll();
    ?>
        <form method="POST" action="index.php?page=accounts_chart">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">কোড <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="code" class="form-control" value="<?php echo acv($d['code']); ?>" <?php echo !empty($d['is_system']) ? 'readonly' : ''; ?> required></div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">নাম <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="name" class="form-control" value="<?php echo acv($d['name']); ?>" required></div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Account Type <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="account_type" class="form-select" <?php echo !empty($d['is_system']) ? 'disabled' : ''; ?>>
                        <?php foreach ($type_order as $tk => $tl): ?>
                            <option value="<?php echo $tk; ?>" <?php echo $d['account_type'] === $tk ? 'selected' : ''; ?>><?php echo acv($tl); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($d['is_system'])): ?><input type="hidden" name="account_type" value="<?php echo acv($d['account_type']); ?>"><?php endif; ?>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Parent Group (ঐচ্ছিক)</label>
                <div class="col-sm-6">
                    <select name="parent_id" class="form-select">
                        <option value="0">— কোনো Parent নেই —</option>
                        <?php foreach ($group_accounts as $g): ?>
                            <option value="<?php echo (int) $g['id']; ?>" <?php echo (int) $d['parent_id'] === (int) $g['id'] ? 'selected' : ''; ?>><?php echo acv($g['code'] . ' - ' . $g['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Group Account?</label>
                <div class="col-sm-6 pt-2">
                    <input type="checkbox" name="is_group" id="acIsGroup" class="form-check-input" value="1" <?php echo $d['is_group'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="acIsGroup">এটা একটা হেডার/গ্রুপ (নিজে পোস্টেবল না, শুধু অন্য Account-কে গ্রুপ করার জন্য)</label>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Opening Balance</label>
                <div class="col-sm-3"><input type="number" step="0.01" name="opening_balance" class="form-control" value="<?php echo ac_money($d['opening_balance']); ?>"></div>
                <div class="col-sm-3">
                    <select name="opening_balance_type" class="form-select">
                        <option value="Dr" <?php echo $d['opening_balance_type'] === 'Dr' ? 'selected' : ''; ?>>Debit (Dr)</option>
                        <option value="Cr" <?php echo $d['opening_balance_type'] === 'Cr' ? 'selected' : ''; ?>>Credit (Cr)</option>
                    </select>
                </div>
            </div>
            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_account" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=accounts_chart" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=accounts_chart&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i> Account যুক্ত করুন</a>
            <span class="text-muted small">মোট <?php echo count($all_accounts); ?> টা Account</span>
        </div>
        <?php foreach ($type_order as $tk => $tl):
            $group = array_values(array_filter($all_accounts, fn($a) => $a['account_type'] === $tk));
            if (!$group) continue;
        ?>
            <h6 class="fw-bold text-secondary mt-4 mb-2"><?php echo acv($tl); ?></h6>
            <div class="table-responsive">
                <table class="table table-bordered align-middle table-sm">
                    <thead class="table-light">
                        <tr><th>Code</th><th>Name</th><th>Type</th><th>ব্যালেন্স</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($group as $a): $bal = ac_account_balance($pdo, (int) $a['id']); $csrf = htmlspecialchars(csrf_token()); ?>
                            <tr class="<?php echo $a['is_group'] ? 'table-secondary' : ''; ?>">
                                <td><?php echo acv($a['code']); ?></td>
                                <td class="fw-bold"><?php echo acv($a['name']); ?><?php echo $a['is_group'] ? ' <span class="badge bg-secondary">Group</span>' : ''; ?><?php echo $a['is_system'] ? ' <span class="badge bg-info text-dark">System</span>' : ''; ?></td>
                                <td><?php echo acv($tk); ?></td>
                                <td class="text-nowrap"><?php echo $a['is_group'] ? '—' : ac_money(abs($bal)) . ' ' . ($bal >= 0 ? ac_normal_side($tk) : (ac_normal_side($tk) === 'Dr' ? 'Cr' : 'Dr')); ?></td>
                                <td><?php echo $a['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=accounts_chart&view=edit&id=<?php echo (int) $a['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <form method="POST" action="index.php?page=accounts_chart" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                                        <button type="submit" name="toggle_account" class="btn btn-sm btn-outline-secondary" title="Active/Inactive"><i class="fa-solid fa-toggle-on"></i></button>
                                    </form>
                                    <?php if (!$a['is_system']): ?>
                                    <form method="POST" action="index.php?page=accounts_chart" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                                        <button type="submit" name="delete_account" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
