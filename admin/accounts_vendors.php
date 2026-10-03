<?php
// admin/accounts_vendors.php - Accounts Module > Accounts Payable > Vendors (Supplier CRUD)
require_once __DIR__ . '/../config/accounts_helpers.php';
ac_ensure_tables($pdo);

$vd_msg = $_SESSION['vd_msg'] ?? '';
$vd_err = $_SESSION['vd_err'] ?? '';
unset($_SESSION['vd_msg'], $_SESSION['vd_err']);

$view = $_GET['view'] ?? 'list';
function vd(string $val): string { return htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); }
function vd_money($n): string { return number_format((float) $n, 2); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['vd_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=accounts_vendors');
        exit();
    }

    if (isset($_POST['save_vendor'])) {
        $id      = (int) ($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $opening = (float) ($_POST['opening_due'] ?? 0);

        if ($name === '') {
            $_SESSION['vd_err'] = 'ভেন্ডরের নাম আবশ্যক।';
            header('Location: index.php?page=accounts_vendors&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE accounts_vendors SET name=?, phone=?, email=?, address=?, opening_due=? WHERE id=?")
                ->execute([$name, $phone, $email, $address, $opening, $id]);
            $_SESSION['vd_msg'] = 'ভেন্ডর তথ্য আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Accounts', 'Update', "ভেন্ডর আপডেট করা হয়েছে: {$name}");
        } else {
            $pdo->prepare("INSERT INTO accounts_vendors (name, phone, email, address, opening_due, status) VALUES (?, ?, ?, ?, ?, 'Active')")
                ->execute([$name, $phone, $email, $address, $opening]);
            $_SESSION['vd_msg'] = 'নতুন ভেন্ডর যুক্ত করা হয়েছে।';
            sec_log_activity($pdo, 'Accounts', 'Create', "নতুন ভেন্ডর যুক্ত হয়েছে: {$name}");
        }
        header('Location: index.php?page=accounts_vendors');
        exit();
    } elseif (isset($_POST['toggle_vendor'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE accounts_vendors SET status = IF(status='Active','Inactive','Active') WHERE id = ?")->execute([$id]);
        $_SESSION['vd_msg'] = 'স্ট্যাটাস বদলানো হয়েছে।';
        header('Location: index.php?page=accounts_vendors');
        exit();
    } elseif (isset($_POST['delete_vendor'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $used = $pdo->prepare("SELECT COUNT(*) FROM accounts_vendor_bills WHERE vendor_id = ?");
        $used->execute([$id]);
        if ((int) $used->fetchColumn() > 0) {
            $_SESSION['vd_err'] = 'এই ভেন্ডরের নামে বিল আছে, ডিলিট করা যাবে না। বদলে Inactive করে দিন।';
        } else {
            $chk = $pdo->prepare("SELECT name FROM accounts_vendors WHERE id = ?");
            $chk->execute([$id]);
            $vname = $chk->fetchColumn();
            $pdo->prepare("DELETE FROM accounts_vendors WHERE id = ?")->execute([$id]);
            $_SESSION['vd_msg'] = 'ভেন্ডর মুছে ফেলা হয়েছে।';
            sec_log_activity($pdo, 'Accounts', 'Delete', "ভেন্ডর ডিলিট করা হয়েছে: " . ($vname ?: "#$id"));
        }
        header('Location: index.php?page=accounts_vendors');
        exit();
    }
}

$vendors = $pdo->query(
    "SELECT v.*,
        (SELECT COALESCE(SUM(amount - paid_amount), 0) FROM accounts_vendor_bills WHERE vendor_id = v.id) AS due
     FROM accounts_vendors v ORDER BY v.name ASC"
)->fetchAll();
?>

<div class="card-custom">
    <h4 class="fw-bold mb-2 text-dark"><i class="fa-solid fa-truck-field"></i> Accounts Module — Vendors / Suppliers</h4>
    <p class="text-muted small mb-4"><i class="fa-solid fa-circle-info me-1"></i> যাদের কাছ থেকে প্রতিষ্ঠান কেনাকাটা/সেবা নেয় তাদের তালিকা — Bill ও Payment এখানের ভেন্ডর দিয়েই হবে।</p>

    <?php if ($vd_msg): ?><div class="alert alert-success py-2"><?php echo vd($vd_msg); ?></div><?php endif; ?>
    <?php if ($vd_err): ?><div class="alert alert-danger py-2"><?php echo vd($vd_err); ?></div><?php endif; ?>

    <?php if ($view === 'add' || $view === 'edit'):
        $d = ['id' => 0, 'name' => '', 'phone' => '', 'email' => '', 'address' => '', 'opening_due' => 0];
        if ($view === 'edit') {
            $stmt = $pdo->prepare("SELECT * FROM accounts_vendors WHERE id = ?");
            $stmt->execute([(int) ($_GET['id'] ?? 0)]);
            $row = $stmt->fetch();
            if ($row) $d = $row;
        }
    ?>
        <form method="POST" action="index.php?page=accounts_vendors">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
            <div class="row mb-3"><label class="col-sm-3 col-form-label fw-bold text-end">ভেন্ডরের নাম <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="name" class="form-control" value="<?php echo vd($d['name']); ?>" required></div></div>
            <div class="row mb-3"><label class="col-sm-3 col-form-label fw-bold text-end">ফোন</label>
                <div class="col-sm-6"><input type="text" name="phone" class="form-control" value="<?php echo vd($d['phone']); ?>"></div></div>
            <div class="row mb-3"><label class="col-sm-3 col-form-label fw-bold text-end">ইমেইল</label>
                <div class="col-sm-6"><input type="email" name="email" class="form-control" value="<?php echo vd($d['email']); ?>"></div></div>
            <div class="row mb-3"><label class="col-sm-3 col-form-label fw-bold text-end">ঠিকানা</label>
                <div class="col-sm-6"><textarea name="address" class="form-control" rows="2"><?php echo vd($d['address']); ?></textarea></div></div>
            <div class="row mb-3"><label class="col-sm-3 col-form-label fw-bold text-end">Opening Due</label>
                <div class="col-sm-6"><input type="number" step="0.01" name="opening_due" class="form-control" value="<?php echo vd_money($d['opening_due']); ?>"></div></div>
            <hr>
            <div class="row"><div class="col-sm-6 offset-sm-3">
                <button type="submit" name="save_vendor" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                <a href="index.php?page=accounts_vendors" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div></div>
        </form>
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=accounts_vendors&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i> নতুন ভেন্ডর</a>
            <span class="text-muted small">মোট <?php echo count($vendors); ?> জন ভেন্ডর</span>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
                <thead class="table-light"><tr><th>নাম</th><th>ফোন</th><th>ইমেইল</th><th>বাকি (Due)</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($vendors as $v): $csrf = htmlspecialchars(csrf_token()); ?>
                        <tr>
                            <td class="fw-bold"><?php echo vd($v['name']); ?></td>
                            <td><?php echo vd($v['phone']); ?></td>
                            <td><?php echo vd($v['email']); ?></td>
                            <td class="text-nowrap"><?php echo vd_money((float) $v['opening_due'] + (float) $v['due']); ?></td>
                            <td><?php echo $v['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                            <td class="text-nowrap">
                                <a href="index.php?page=accounts_vendor_bills&vendor_id=<?php echo (int) $v['id']; ?>" class="btn btn-sm btn-outline-info" title="Bills"><i class="fa-solid fa-file-invoice-dollar"></i></a>
                                <a href="index.php?page=accounts_vendors&view=edit&id=<?php echo (int) $v['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <form method="POST" action="index.php?page=accounts_vendors" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $v['id']; ?>">
                                    <button type="submit" name="toggle_vendor" class="btn btn-sm btn-outline-secondary" title="Active/Inactive"><i class="fa-solid fa-toggle-on"></i></button>
                                </form>
                                <form method="POST" action="index.php?page=accounts_vendors" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $v['id']; ?>">
                                    <button type="submit" name="delete_vendor" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$vendors): ?><tr><td colspan="6" class="text-center text-muted">কোনো ভেন্ডর নেই</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
