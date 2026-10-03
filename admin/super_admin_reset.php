<?php
// admin/super_admin_reset.php - User Management > Super Admin Reset
// এই পেজে দুইটা কাজ করা যাবে:
//   ১. system_users টেবিলের সব ইউজার (Demo সহ Admin/Teacher/Student/Accountant/HR সবাই)
//      একবারে রিমুভ করে দেওয়া যাবে — লগইনে শুধু Super Admin-ই থাকবে।
//   ২. Super Admin-এর নিজের Username ও Password সরাসরি সেট/পরিবর্তন করা যাবে —
//      Change Password মডালের মতো "Current Password" লাগবে না, কারণ এই পেজে ঢুকতে
//      হলে এমনিতেই লগইন করা Super Admin সেশন লাগে (session-based auth যথেষ্ট)।
// শুধুমাত্র Super Admin এই পেজ দেখতে/ব্যবহার করতে পারবে।

$is_super_admin = $_SESSION['is_super_admin'] ?? true;
if (!$is_super_admin) {
    echo '<div class="card-custom"><div class="alert alert-danger mb-0"><i class="fa-solid fa-lock me-2"></i>শুধুমাত্র <b>Super Admin</b> এই পেজ অ্যাক্সেস করতে পারবেন।</div></div>';
    return;
}

function sarv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$sar_err = $_SESSION['sar_err'] ?? '';
$sar_msg = $_SESSION['sar_msg'] ?? '';
unset($_SESSION['sar_err'], $_SESSION['sar_msg']);

// -----------------------------------------------------------
// অ্যাকশন ১: সব system_users রিমুভ (Demo সহ সবাই) — Super Admin ছাড়া আর কেউ থাকবে না
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_all_users'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sar_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (trim($_POST['confirm_text'] ?? '') !== 'REMOVE') {
        $_SESSION['sar_err'] = 'নিশ্চিতকরণ বক্সে ঠিক "REMOVE" লিখুন (বড় হাতের অক্ষরে)।';
    } else {
        $deleted = (int) $pdo->query("SELECT COUNT(*) FROM system_users")->fetchColumn();
        $pdo->exec("DELETE FROM system_users");
        $_SESSION['sar_msg'] = "সব ইউজার ({$deleted} জন, Demo সহ) রিমুভ করা হয়েছে। এখন থেকে লগইনে শুধু Super Admin-ই থাকবে।";
        sec_log_activity($pdo, 'Super Admin', 'Delete', "সব System User ({$deleted} জন) রিমুভ করা হয়েছে।");
    }
    header('Location: index.php?page=super_admin_reset');
    exit();
}

// -----------------------------------------------------------
// অ্যাকশন ২: Super Admin Username/Password সরাসরি সেট করা
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_super_admin'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sar_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=super_admin_reset');
        exit();
    }

    $new_username = trim($_POST['new_username'] ?? '');
    $new_password = (string) ($_POST['new_password'] ?? '');
    $retype       = (string) ($_POST['retype_password'] ?? '');

    $row = $pdo->prepare('SELECT * FROM admin WHERE username = ? LIMIT 1');
    $row->execute([$_SESSION['admin_user']]);
    $admin_row = $row->fetch();

    if (!$admin_row) {
        $_SESSION['sar_err'] = 'Super Admin রেকর্ড খুঁজে পাওয়া যায়নি।';
    } elseif ($new_username === '') {
        $_SESSION['sar_err'] = 'Username আবশ্যক।';
    } elseif ($new_password !== '' && strlen($new_password) < 6) {
        $_SESSION['sar_err'] = 'নতুন Password কমপক্ষে ৬ ক্যারেক্টার হতে হবে।';
    } elseif ($new_password !== '' && $new_password !== $retype) {
        $_SESSION['sar_err'] = 'Password এবং Retype Password মিলছে না।';
    } else {
        // Username বদলানো হলে সেটা ইউনিক কিনা যাচাই (নিজের রো বাদে)
        $chk = $pdo->prepare('SELECT id FROM admin WHERE username = ? AND id != ?');
        $chk->execute([$new_username, $admin_row['id']]);
        if ($chk->fetch()) {
            $_SESSION['sar_err'] = 'এই Username ইতিমধ্যে ব্যবহৃত হয়েছে।';
        } else {
            if ($new_password !== '') {
                $hash = password_hash($new_password, PASSWORD_BCRYPT);
                $pdo->prepare('UPDATE admin SET username = ?, password = ? WHERE id = ?')
                    ->execute([$new_username, $hash, $admin_row['id']]);
            } else {
                $pdo->prepare('UPDATE admin SET username = ? WHERE id = ?')
                    ->execute([$new_username, $admin_row['id']]);
            }
            // সেশন আপডেট — এখনই লগআউট করতে হবে না, নতুন Username সাথে সাথে দেখাবে
            $_SESSION['admin_user'] = $new_username;
            $_SESSION['sar_msg'] = 'Super Admin Username/Password আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Super Admin', 'Update', "Super Admin Username/Password আপডেট করা হয়েছে: {$new_username}");
        }
    }
    header('Location: index.php?page=super_admin_reset');
    exit();
}

$total_other_users = (int) $pdo->query("SELECT COUNT(*) FROM system_users")->fetchColumn();
$current_admin = $pdo->prepare('SELECT * FROM admin WHERE username = ? LIMIT 1');
$current_admin->execute([$_SESSION['admin_user']]);
$current_admin = $current_admin->fetch();
?>

<?php if ($sar_msg): ?><div class="alert alert-success py-2"><?php echo sarv($sar_msg); ?></div><?php endif; ?>
<?php if ($sar_err): ?><div class="alert alert-danger py-2"><?php echo sarv($sar_err); ?></div><?php endif; ?>

<!-- Section 1: সব ইউজার রিমুভ -->
<div class="card-custom mb-3">
    <h6 class="fw-bold mb-2"><i class="fa-solid fa-user-slash me-1 text-danger"></i> সব ইউজার রিমুভ করুন (Demo সহ)</h6>
    <p class="text-muted mb-3" style="font-size:13px;">
        এই অ্যাকশনটা <b>system_users</b> টেবিলের সব ইউজার (Admin/Teacher/Student/Accountant/HR/Demo — সবাই) স্থায়ীভাবে মুছে দেবে।
        <b>Super Admin</b> অ্যাকাউন্ট কখনো মোছা হয় না — লগইন করার পর শুধু Super Admin-ই দেখাবে।
        বর্তমানে মোট <span class="badge bg-secondary"><?php echo $total_other_users; ?></span> জন ইউজার আছে।
    </p>
    <?php if ($total_other_users > 0): ?>
        <form method="POST" action="index.php?page=super_admin_reset" onsubmit="return confirm('সব ইউজার স্থায়ীভাবে মুছে যাবে, এটা আর ফেরত আসবে না — নিশ্চিত?');">
            <?php echo csrf_field(); ?>
            <div class="row g-2 align-items-center">
                <div class="col-sm-4">
                    <input type="text" name="confirm_text" class="form-control" placeholder='নিশ্চিত করতে "REMOVE" লিখুন' required>
                </div>
                <div class="col-sm-3">
                    <button type="submit" name="remove_all_users" class="btn btn-danger fw-bold w-100">
                        <i class="fa-solid fa-trash me-1"></i> সব ইউজার রিমুভ করুন
                    </button>
                </div>
            </div>
        </form>
    <?php else: ?>
        <div class="alert alert-info py-2 mb-0">Super Admin ছাড়া বর্তমানে আর কোনো ইউজার নেই।</div>
    <?php endif; ?>
</div>

<!-- Section 2: Super Admin Username/Password সেট -->
<div class="card-custom">
    <h6 class="fw-bold mb-2"><i class="fa-solid fa-user-shield me-1 text-primary"></i> Super Admin Username ও Password সেট করুন</h6>
    <p class="text-muted mb-3" style="font-size:13px;">
        এখানে আপনি সরাসরি আপনার Super Admin লগইনের Username ও Password পরিবর্তন করতে পারবেন — পুরনো পাসওয়ার্ড দিতে হবে না,
        কারণ এই পেজে ঢুকতে হলে এমনিতেই Super Admin হিসেবে লগইন করা লাগে। Password ফাঁকা রাখলে শুধু Username আপডেট হবে।
    </p>
    <form method="POST" action="index.php?page=super_admin_reset">
        <?php echo csrf_field(); ?>
        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">বর্তমান Username</label>
            <div class="col-sm-6">
                <input type="text" class="form-control" value="<?php echo sarv($current_admin['username'] ?? ''); ?>" disabled>
            </div>
        </div>
        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">নতুন Username <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <input type="text" name="new_username" class="form-control" value="<?php echo sarv($current_admin['username'] ?? ''); ?>" required>
            </div>
        </div>
        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">নতুন Password</label>
            <div class="col-sm-6">
                <input type="password" name="new_password" class="form-control" autocomplete="new-password">
                <small class="text-muted">খালি রাখলে বর্তমান Password অপরিবর্তিত থাকবে।</small>
            </div>
        </div>
        <div class="row mb-4 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Retype Password</label>
            <div class="col-sm-6">
                <input type="password" name="retype_password" class="form-control" autocomplete="new-password">
            </div>
        </div>
        <div class="row">
            <div class="col-sm-6 offset-sm-3">
                <button type="submit" name="update_super_admin" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Update
                </button>
            </div>
        </div>
    </form>
</div>
