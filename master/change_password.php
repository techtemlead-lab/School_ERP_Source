<?php
require_once __DIR__ . '/_guard.php';

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করো।';
    } else {
        $current = $_POST['current_password'] ?? '';
        $new1 = $_POST['new_password'] ?? '';
        $new2 = $_POST['confirm_password'] ?? '';

        $stmt = $pdo_master->prepare('SELECT * FROM master_users WHERE id = ?');
        $stmt->execute([$_SESSION['master_user_id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current, $row['password'])) {
            $error = 'বর্তমান পাসওয়ার্ড ভুল।';
        } elseif (strlen($new1) < 12) {
            $error = 'নতুন পাসওয়ার্ড কমপক্ষে ১২ ক্যারেক্টার হতে হবে।';
        } elseif ($new1 !== $new2) {
            $error = 'নতুন পাসওয়ার্ড দুইবার একই দাওনি।';
        } else {
            $hash = password_hash($new1, PASSWORD_DEFAULT);
            $upd = $pdo_master->prepare('UPDATE master_users SET password = ? WHERE id = ?');
            $upd->execute([$hash, $row['id']]);
            $success = true;
        }
    }
}
$pageTitle = 'পাসওয়ার্ড বদলাও';
$pageIcon  = '🔒';
$activeKey = 'change_password';
require_once __DIR__ . '/_layout_top.php';
?>
    <div style="max-width:480px;">
        <?php if ($success): ?>
            <div class="alert alert-success">✅ পাসওয়ার্ড সফলভাবে বদলানো হয়েছে!</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card-custom p-4">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="mb-3">
                    <label class="form-label small fw-bold">বর্তমান পাসওয়ার্ড</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">নতুন পাসওয়ার্ড</label>
                    <input type="password" name="new_password" class="form-control" required minlength="12">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">নতুন পাসওয়ার্ড আবার লেখো</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="12">
                </div>
                <button type="submit" class="btn btn-primary fw-bold w-100 py-2">পাসওয়ার্ড আপডেট করো</button>
            </form>
        </div>
    </div>
<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
