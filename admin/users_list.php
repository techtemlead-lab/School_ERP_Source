<?php
// admin/users_list.php - User Management > User List (স্ক্রিনশট অনুযায়ী)
// এখানে Admin/Teacher/Student/Accountant/HR/Data Entry Operator/Demo টাইপের সিস্টেম ইউজার
// তৈরি, লিস্ট, এডিট ও ডিলিট করা যায়। প্রতিটা ইউজার username/password দিয়ে লগইন করবে।
// Super Admin এবং system_users এর 'Admin' টাইপ — দুজনেই এই পেজ দেখতে/ব্যবহার করতে পারবে।
// তবে 'Demo' ইউজার শুধু Super Admin-ই তৈরি করতে পারবে (নিচে ফর্মে আলাদাভাবে হ্যান্ডেল করা হয়েছে)।

// পুরনো সেশনে (এই ফিচার আসার আগে লগইন করা) is_super_admin সেট নাই — সেক্ষেত্রে
// ব্যাকওয়ার্ড-কম্প্যাটিবিলিটির জন্য Super Admin হিসেবেই ধরা হচ্ছে
$is_super_admin = $_SESSION['is_super_admin'] ?? true;
$is_admin_type  = ($_SESSION['user_role'] ?? '') === 'Admin'; // system_users এর 'Admin' টাইপ (Super Admin না)
if (!$is_super_admin && !$is_admin_type) {
    echo '<div class="card-custom"><div class="alert alert-danger mb-0"><i class="fa-solid fa-lock me-2"></i>শুধুমাত্র <b>Super Admin</b> অথবা <b>Admin</b> User Management অ্যাক্সেস করতে পারবেন।</div></div>';
    return;
}

$ul_err = $_SESSION['ul_err'] ?? '';
$ul_msg = $_SESSION['ul_msg'] ?? '';
unset($_SESSION['ul_err'], $_SESSION['ul_msg']);

// -----------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি (self-healing migration)
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS system_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NULL,
        user_type VARCHAR(30) NOT NULL,
        username VARCHAR(100) NOT NULL UNIQUE,
        email VARCHAR(150) NULL,
        password VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        expires_at DATETIME NULL,
        last_login DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// পুরনো ডাটাবেজে expires_at কলাম না থাকলে যোগ করা (Demo/Trial User Expiry ফিচার)
function ul_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $chk = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $chk->execute([$table, $column]);
    if (!$chk->fetch()) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
ul_ensure_column($pdo, 'system_users', 'expires_at', 'DATETIME NULL AFTER status');

function ulv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$user_types = ['Accountant', 'Admin', 'Canteen Staff', 'Data Entry Operator', 'Demo', 'Human Resource', 'Student', 'Teacher'];

// ডিফল্ট Branch — School-এ একাধিক Branch থাকলেও নতুন User তৈরির সময় প্রথম Active
// Branch-টা অটো এসাইন হবে (স্ক্রিনশটে আলাদা Branch ফিল্ড নেই)
$default_branch = $pdo->query("SELECT id, branch_name FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
$default_branch_id = $default_branch ? (int) $default_branch['id'] : null;

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ul_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=users_list');
        exit();
    }

    if (isset($_POST['save_user'])) {
        $id        = (int) ($_POST['id'] ?? 0);
        $user_type = trim($_POST['user_type'] ?? '');
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = (string) ($_POST['password'] ?? '');
        $retype    = (string) ($_POST['retype_password'] ?? '');
        $status    = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
        $expires_at_raw = trim($_POST['expires_at'] ?? ''); // datetime-local ইনপুট থেকে, e.g. 2026-09-01T18:00
        $expires_at = $expires_at_raw !== '' ? date('Y-m-d H:i:s', strtotime($expires_at_raw)) : null;

        if (!in_array($user_type, $user_types, true) || $username === '') {
            $_SESSION['ul_err'] = 'User Type ও Username আবশ্যক।';
        } elseif ($user_type === 'Demo' && !$is_super_admin) {
            // Admin টাইপ ইউজার সরাসরি POST রিকোয়েস্ট craft করে Demo ইউজার বানানোর চেষ্টা করলেও ব্লক করা হচ্ছে
            $_SESSION['ul_err'] = 'শুধুমাত্র Super Admin Demo ইউজার তৈরি/আপডেট করতে পারবেন।';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['ul_err'] = 'সঠিক Email দিন।';
        } elseif ($id <= 0 && $password === '') {
            $_SESSION['ul_err'] = 'নতুন User তৈরির জন্য Password আবশ্যক।';
        } elseif ($password !== '' && $password !== $retype) {
            $_SESSION['ul_err'] = 'Password এবং Retype Password মিলছে না।';
        } elseif ($password !== '' && strlen($password) < 6) {
            $_SESSION['ul_err'] = 'Password কমপক্ষে ৬ ক্যারেক্টার হতে হবে।';
        } elseif ($expires_at_raw !== '' && $expires_at === null) {
            $_SESSION['ul_err'] = 'Expiry তারিখ/সময় সঠিক ফরম্যাটে দিন।';
        } elseif (trim($_POST['branch_id'] ?? '') === '' && branch_list_active($pdo)) {
            // অন্তত একটা Branch তৈরি থাকলে, User-এর জন্য Branch সিলেক্ট করা বাধ্যতামূলক —
            // নাহলে "এই user শুধু তার নিজের branch-এই লগইন করবে" নিয়মটা কখনো কাজ করবে না
            $_SESSION['ul_err'] = 'অনুগ্রহ করে এই User-এর জন্য একটা Branch সিলেক্ট করুন।';
        } else {
            // Username ইউনিক কিনা যাচাই (নিজের id বাদে)
            $chk = $pdo->prepare("SELECT id FROM system_users WHERE username = ? AND id != ?");
            $chk->execute([$username, $id]);
            if ($chk->fetch()) {
                $_SESSION['ul_err'] = 'এই Username ইতিমধ্যে ব্যবহৃত হয়েছে। অন্য একটি Username দিন।';
            } else {
                // ফর্মে Branch সিলেক্ট করা থাকলে সেটাই সেভ হবে। কিছু সিলেক্ট না করলে (বা branch
                // field-ই না থাকলে, single-branch সিস্টেমে) branch_id NULL থাকবে — কোনো ডিফল্ট
                // branch এ silently বসানো হয় না, কারণ তাহলে "login-এ branch সিলেক্ট করতে হবে"
                // এই নিয়মটাই কখনো ট্রিগার হতো না।
                $posted_branch_id = trim($_POST['branch_id'] ?? '');
                $selected_branch_id = $posted_branch_id !== '' ? (int) $posted_branch_id : null;

                if ($id > 0) {
                    if ($password !== '') {
                        $hash = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $pdo->prepare(
                            "UPDATE system_users SET branch_id=?, user_type=?, username=?, email=?, password=?, status=?, expires_at=? WHERE id=?"
                        );
                        $stmt->execute([$selected_branch_id, $user_type, $username, $email ?: null, $hash, $status, $expires_at, $id]);
                    } else {
                        $stmt = $pdo->prepare(
                            "UPDATE system_users SET branch_id=?, user_type=?, username=?, email=?, status=?, expires_at=? WHERE id=?"
                        );
                        $stmt->execute([$selected_branch_id, $user_type, $username, $email ?: null, $status, $expires_at, $id]);
                    }
                    $_SESSION['ul_msg'] = 'User আপডেট করা হয়েছে।';
                    sec_log_activity($pdo, 'User Management', 'Update', "User আপডেট করা হয়েছে: {$username} ({$user_type})");
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare(
                        "INSERT INTO system_users (branch_id, user_type, username, email, password, status, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([$selected_branch_id, $user_type, $username, $email ?: null, $hash, $status, $expires_at]);
                    $_SESSION['ul_msg'] = 'নতুন User তৈরি করা হয়েছে।';
                    sec_log_activity($pdo, 'User Management', 'Create', "নতুন User তৈরি করা হয়েছে: {$username} ({$user_type})");
                }
                header('Location: index.php?page=users_list');
                exit();
            }
        }
        // এরর হলে ফর্মেই ফেরত পাঠানো হবে, ইনপুট করা ডেটা সহ
        $_SESSION['ul_old'] = $_POST;
        $redirect = $id > 0 ? "index.php?page=users_list&view=edit&id={$id}" : "index.php?page=users_list&view=add";
        header("Location: {$redirect}");
        exit();
    } elseif (isset($_POST['toggle_status'])) {
        // এক ক্লিকে Block/Unblock — status Active <-> Inactive টগল করা হয়,
        // পুরো Edit ফর্মে ঢুকতে হয় না। login (admin/index.php) ইতিমধ্যেই
        // status !== 'Active' হলে লগইন ব্লক করে, তাই এটাই আসলে "Block user"।
        $id = (int) ($_POST['id'] ?? 0);
        $target = $pdo->prepare("SELECT username, user_type, status FROM system_users WHERE id = ?");
        $target->execute([$id]);
        $target_row = $target->fetch();

        if (!$target_row) {
            $_SESSION['ul_err'] = 'ইউজার খুঁজে পাওয়া যায়নি।';
        } elseif ($target_row['user_type'] === 'Demo' && !$is_super_admin) {
            $_SESSION['ul_err'] = 'শুধুমাত্র Super Admin Demo ইউজারের স্ট্যাটাস পরিবর্তন করতে পারবেন।';
        } else {
            $new_status = $target_row['status'] === 'Active' ? 'Inactive' : 'Active';
            $pdo->prepare("UPDATE system_users SET status = ? WHERE id = ?")->execute([$new_status, $id]);
            $_SESSION['ul_msg'] = $new_status === 'Inactive'
                ? "User ব্লক করা হয়েছে: {$target_row['username']}"
                : "User আনব্লক করা হয়েছে: {$target_row['username']}";
            sec_log_activity($pdo, 'User Management', 'Status Change', "User স্ট্যাটাস পরিবর্তন: {$target_row['username']} ({$target_row['user_type']}) -> {$new_status}");
        }
    } elseif (isset($_POST['delete_user'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $target = $pdo->prepare("SELECT user_type FROM system_users WHERE id = ?");
        $target->execute([$id]);
        $target_type = $target->fetchColumn();

        if ($target_type === 'Demo' && !$is_super_admin) {
            $_SESSION['ul_err'] = 'শুধুমাত্র Super Admin Demo ইউজার ডিলিট করতে পারবেন।';
        } else {
            $del_username_row = $pdo->prepare("SELECT username FROM system_users WHERE id = ?");
            $del_username_row->execute([$id]);
            $del_username = $del_username_row->fetchColumn();
            $pdo->prepare("DELETE FROM system_users WHERE id = ?")->execute([$id]);
            $_SESSION['ul_msg'] = 'User মুছে ফেলা হয়েছে।';
            sec_log_activity($pdo, 'User Management', 'Delete', "User মুছে ফেলা হয়েছে: " . ($del_username ?: "#$id") . " ($target_type)");
        }
    }

    header('Location: index.php?page=users_list');
    exit();
}

$view = $_GET['view'] ?? 'list';
$old = $_SESSION['ul_old'] ?? null;
unset($_SESSION['ul_old']);
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    // ------------------- ADD / EDIT FORM VIEW -------------------
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM system_users WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();

        // Demo ইউজার শুধু Super Admin-ই দেখতে/এডিট করতে পারবে — Admin টাইপ ইউজারের জন্য ব্লক
        if ($edit_row && $edit_row['user_type'] === 'Demo' && !$is_super_admin) {
            echo '<div class="card-custom"><div class="alert alert-danger mb-0"><i class="fa-solid fa-lock me-2"></i>শুধুমাত্র <b>Super Admin</b> Demo ইউজার দেখতে/এডিট করতে পারবেন।</div></div>';
            return;
        }
    }
    $d = $old ?: ($edit_row ?: ['id' => 0, 'user_type' => '', 'username' => '', 'email' => '', 'status' => 'Active', 'expires_at' => '']);
    $d['id'] = $edit_row['id'] ?? ($old['id'] ?? 0);
    ?>
    <div class="card-custom">
        <?php if ($ul_err): ?><div class="alert alert-danger py-2"><?php echo ulv($ul_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <!-- হলুদ Manage আইকন — ক্লিক করলে নিচের ফর্ম প্যানেল কোলাপ্স/এক্সপ্যান্ড হয় -->
            <button type="button"
                    class="btn bg-warning bg-opacity-75 border-0 p-2"
                    style="width:40px;border-radius:4px;"
                    data-bs-toggle="collapse"
                    data-bs-target="#userFormBody"
                    aria-expanded="true"
                    aria-controls="userFormBody"
                    title="Manage / Collapse">
                <i class="fa-solid fa-file-lines text-white"></i>
            </button>
            <div>
                <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-bs-toggle="collapse" data-bs-target="#userFormBody" title="Collapse"><i class="fa-solid fa-chevron-up"></i></button>
                <a href="index.php?page=users_list" class="btn btn-sm btn-outline-danger" title="Cancel / Close"><i class="fa-solid fa-xmark"></i></a>
            </div>
        </div>

        <div id="userFormBody" class="collapse show">
            <form method="POST" action="index.php?page=users_list">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">User Type <span class="text-danger">*</span></label>
                    <div class="col-sm-6">
                        <select name="user_type" class="form-select" required>
                            <option value="">Select User Type</option>
                            <?php foreach ($user_types as $ut): ?>
                                <?php if ($ut === 'Demo' && !$is_super_admin) continue; // Demo user শুধু Super Admin-ই তৈরি/দেখতে পারবে ?>
                                <option value="<?php echo ulv($ut); ?>" <?php echo ($d['user_type'] ?? '') === $ut ? 'selected' : ''; ?>><?php echo ulv($ut); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php if (!$is_super_admin): ?>
                <div class="row mb-3">
                    <div class="col-sm-6 offset-sm-3">
                        <small class="text-muted"><i class="fa-solid fa-circle-info me-1"></i> Demo/Trial ইউজার শুধুমাত্র Super Admin তৈরি করতে পারবেন।</small>
                    </div>
                </div>
                <?php endif; ?>

                <?php $ul_branches = branch_list_active($pdo); if ($ul_branches): ?>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Branch <span class="text-danger">*</span></label>
                    <div class="col-sm-6">
                        <select name="branch_id" class="form-select" required>
                            <option value="">-- Branch সিলেক্ট করুন --</option>
                            <?php foreach ($ul_branches as $ulb): ?>
                                <option value="<?php echo (int) $ulb['id']; ?>" <?php echo (int) ($d['branch_id'] ?? 0) === (int) $ulb['id'] ? 'selected' : ''; ?>><?php echo ulv($ulb['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">এই ইউজার লগইন করার পর শুধু এই branch-এর ডাটাই দেখতে/এডিট করতে পারবে।</small>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Username <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="text" name="username" class="form-control" value="<?php echo ulv($d['username'] ?? ''); ?>" required></div>
                </div>

                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Email</label>
                    <div class="col-sm-6"><input type="email" name="email" class="form-control" value="<?php echo ulv($d['email'] ?? ''); ?>"></div>
                </div>

                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Password <?php echo $view === 'add' ? '<span class="text-danger">*</span>' : ''; ?></label>
                    <div class="col-sm-6">
                        <input type="password" name="password" class="form-control" autocomplete="new-password" <?php echo $view === 'add' ? 'required' : ''; ?>>
                        <?php if ($view === 'edit'): ?><small class="text-muted">খালি রাখলে বর্তমান Password অপরিবর্তিত থাকবে।</small><?php endif; ?>
                    </div>
                </div>

                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Retype Password <?php echo $view === 'add' ? '<span class="text-danger">*</span>' : ''; ?></label>
                    <div class="col-sm-6"><input type="password" name="retype_password" class="form-control" autocomplete="new-password" <?php echo $view === 'add' ? 'required' : ''; ?>></div>
                </div>

                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Status <span class="text-danger">*</span></label>
                    <div class="col-sm-6">
                        <select name="status" class="form-select">
                            <option value="Active" <?php echo ($d['status'] ?? 'Active') === 'Active' ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo ($d['status'] ?? '') === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Expiry Date/Time</label>
                    <div class="col-sm-6">
                        <input type="datetime-local" name="expires_at" class="form-control"
                               value="<?php echo !empty($d['expires_at']) ? ulv(date('Y-m-d\TH:i', strtotime($d['expires_at']))) : ''; ?>">
                        <small class="text-muted">Demo/Trial User দিলে একটা Expiry সেট করুন — এই সময়ের পর ইউজার আর লগইন করতে পারবে না। খালি রাখলে কখনো মেয়াদ শেষ হবে না।</small>
                    </div>
                </div>

                <hr>
                <div class="row">
                    <div class="col-sm-6 offset-sm-3">
                        <button type="submit" name="save_user" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                        <a href="index.php?page=users_list" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

<?php else: ?>
    <?php
    // ------------------- LIST VIEW -------------------
    $f_branch = $_GET['f_branch'] ?? 'All';
    $f_type   = $_GET['f_type'] ?? 'All';
    $f_uname  = trim($_GET['f_uname'] ?? '');
    $f_email  = trim($_GET['f_email'] ?? '');
    $f_status = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_branch !== 'All') { $where[] = 'system_users.branch_id = ?'; $params[] = (int) $f_branch; }
    if ($f_type !== 'All')   { $where[] = 'system_users.user_type = ?'; $params[] = $f_type; }
    if ($f_uname !== '')     { $where[] = 'system_users.username LIKE ?'; $params[] = "%$f_uname%"; }
    if ($f_email !== '')     { $where[] = 'system_users.email LIKE ?'; $params[] = "%$f_email%"; }
    if ($f_status !== 'All') { $where[] = 'system_users.status = ?'; $params[] = $f_status; }
    if (!$is_super_admin) { $where[] = "system_users.user_type != 'Demo'"; } // Demo ইউজার Admin টাইপের কাছে লিস্টেই দেখাবে না

    $sql = "SELECT system_users.*, branches.branch_name FROM system_users
            LEFT JOIN branches ON branches.id = system_users.branch_id";
    if ($where) { $sql .= " WHERE " . implode(' AND ', $where); }
    $sql .= " ORDER BY system_users.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $branches = $pdo->query("SELECT id, branch_name FROM branches WHERE status = 'Active' ORDER BY branch_name ASC")->fetchAll();
    $csrf = htmlspecialchars(csrf_token());
    ?>
    <style>
        /* Bootstrap-এর .collapse.show ডিফল্টভাবে display:block করে দেয়, যা <tr> ভেঙে
           ফেলে — তাই ফিল্টার রো-এর জন্য table-row ফোর্স করা হচ্ছে */
        #userFilterRow.collapse:not(.show) { display: none; }
        #userFilterRow.collapse.show { display: table-row; }
    </style>
    <div class="card-custom">
        <?php if ($ul_msg): ?><div class="alert alert-success py-2"><?php echo ulv($ul_msg); ?></div><?php endif; ?>
        <?php if ($ul_err): ?><div class="alert alert-danger py-2"><?php echo ulv($ul_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=users_list&view=add" class="btn btn-success" title="Add New User"><i class="fa-solid fa-plus"></i></a>
            <div>
                <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-bs-toggle="collapse" data-bs-target="#userFilterRow" title="Show/Hide Filter"><i class="fa-solid fa-chevron-up"></i></button>
                <a href="index.php?page=users_list" class="btn btn-sm btn-outline-danger" title="Clear Filter"><i class="fa-solid fa-xmark"></i></a>
            </div>
        </div>

        <div class="text-end mb-2"><span class="text-muted small">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> items.</span></div>

        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="users_list">
            <div class="table-responsive">
                <table class="table table-bordered align-middle" style="font-size:13px;">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>Branch</th>
                            <th>User Type</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Last Login</th>
                            <th>Expiry</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <tr class="collapse show" id="userFilterRow">
                            <th></th>
                            <th>
                                <select name="f_branch" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All">All</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?php echo (int) $b['id']; ?>" <?php echo (string) $f_branch === (string) $b['id'] ? 'selected' : ''; ?>><?php echo ulv($b['branch_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th>
                                <select name="f_type" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All">All</option>
                                    <?php foreach ($user_types as $ut): ?>
                                        <?php if ($ut === 'Demo' && !$is_super_admin) continue; ?>
                                        <option value="<?php echo ulv($ut); ?>" <?php echo $f_type === $ut ? 'selected' : ''; ?>><?php echo ulv($ut); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th><input type="text" name="f_uname" class="form-control form-control-sm" value="<?php echo ulv($f_uname); ?>"></th>
                            <th><input type="text" name="f_email" class="form-control form-control-sm" value="<?php echo ulv($f_email); ?>"></th>
                            <th></th>
                            <th></th>
                            <th>
                                <select name="f_status" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All" <?php echo $f_status === 'All' ? 'selected' : ''; ?>>All</option>
                                    <option value="Active" <?php echo $f_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo $f_status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </th>
                            <th><button type="submit" class="btn btn-sm btn-outline-primary">Filter</button></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows): $srl = 1; foreach ($rows as $row): ?>
                            <?php $is_expired = !empty($row['expires_at']) && strtotime($row['expires_at']) < time(); ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td><?php echo ulv($row['branch_name'] ?? '-'); ?></td>
                                <td><?php echo ulv($row['user_type']); ?></td>
                                <td><?php echo ulv($row['username']); ?></td>
                                <td><?php echo $row['email'] ? '<a href="mailto:' . ulv($row['email']) . '">' . ulv($row['email']) . '</a>' : '-'; ?></td>
                                <td><?php echo $row['last_login'] ? ulv(date('Y-m-d H:i:s', strtotime($row['last_login']))) : '<span class="fst-italic text-muted">(not set)</span>'; ?></td>
                                <td>
                                    <?php if (empty($row['expires_at'])): ?>
                                        <span class="text-muted">—</span>
                                    <?php elseif ($is_expired): ?>
                                        <span class="badge bg-danger" title="<?php echo ulv(date('Y-m-d H:i', strtotime($row['expires_at']))); ?>">Expired</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><?php echo ulv(date('Y-m-d H:i', strtotime($row['expires_at']))); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=users_list&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View"><i class="fa-solid fa-eye"></i></a>
                                    <a href="index.php?page=users_list&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <?php if ($row['status'] === 'Active'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-warning" title="Block" onclick="ulToggleStatus(<?php echo (int) $row['id']; ?>, '<?php echo ulv($row['username']); ?>', true)"><i class="fa-solid fa-ban"></i></button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-success" title="Unblock" onclick="ulToggleStatus(<?php echo (int) $row['id']; ?>, '<?php echo ulv($row['username']); ?>', false)"><i class="fa-solid fa-lock-open"></i></button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="ulDeleteRow(<?php echo (int) $row['id']; ?>)"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="9" class="text-center text-muted py-3">No data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <!-- ডিলিটের জন্য আলাদা, ফিল্টার ফর্মের বাইরে একটা শেয়ার্ড হিডেন ফর্ম — সারি-ভিত্তিক
             ফর্ম আগে ফিল্টার ফর্মের ভেতরে নেস্টেড থাকায় ব্রাউজার সেটাকে ইনভ্যালিড HTML
             ধরে নিয়ে বাদ দিয়ে দিচ্ছিল, তাই ডিলিট কাজ করছিল না — সেটার ফিক্স -->
        <form method="POST" action="index.php?page=users_list" id="ulDeleteForm" class="d-none">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" id="ulDeleteId">
            <input type="hidden" name="delete_user" value="1">
        </form>
        <form method="POST" action="index.php?page=users_list" id="ulStatusForm" class="d-none">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" id="ulStatusId">
            <input type="hidden" name="toggle_status" value="1">
        </form>
        <script>
            function ulDeleteRow(id) {
                if (!confirm('এই User মুছে ফেলবেন?')) return;
                document.getElementById('ulDeleteId').value = id;
                document.getElementById('ulDeleteForm').submit();
            }
            function ulToggleStatus(id, username, isBlocking) {
                const msg = isBlocking
                    ? `"${username}" কে ব্লক করবেন? এই ইউজার আর লগইন করতে পারবে না।`
                    : `"${username}" কে আনব্লক করবেন?`;
                if (!confirm(msg)) return;
                document.getElementById('ulStatusId').value = id;
                document.getElementById('ulStatusForm').submit();
            }
        </script>
    </div>
<?php endif; ?>
