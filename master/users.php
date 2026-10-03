<?php
require_once __DIR__ . '/_guard.php';

// শুধু superadmin এই পেজে ঢুকতে পারবে
if (($_SESSION['master_role'] ?? '') !== 'superadmin') {
    $_SESSION['dash_msg'] = '⛔ শুধু Superadmin ইউজার ম্যানেজ করতে পারবে।';
    header('Location: dashboard.php'); exit;
}
ensureUserMenuPermissionsTable($pdo_master);

$menus = [
    'dashboard'      => 'Dashboard',
    'client_list'    => 'স্কুলের তালিকা',
    'create_tenant'  => 'নতুন স্কুল',
    'migrate_tenant' => 'পুরনো স্কুল মাইগ্রেট',
    'trash'          => 'Trash',
    'billing'        => 'Billing',
    'deploy'         => 'Deploy',
    'domain_tracker' => 'Domain Tracker',
    'activity'       => 'Activity Log',
    'sms'            => 'SMS পাঠানো',
];

$error = '';
$success = '';
$editUser = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করো।';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_user') {
            $uid      = (int) ($_POST['user_id'] ?? 0);
            $name     = trim($_POST['name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $role     = in_array($_POST['role'] ?? '', ['superadmin','admin','viewer'], true) ? $_POST['role'] : 'admin';
            $password = $_POST['password'] ?? '';

            if ($name === '' || $username === '') {
                $error = 'নাম ও ইউজারনেম আবশ্যক।';
            } else {
                $chk = $pdo_master->prepare("SELECT id FROM master_users WHERE username=? AND id != ?");
                $chk->execute([$username, $uid]);
                if ($chk->fetch()) {
                    $error = 'এই ইউজারনেম আগে থেকেই আছে।';
                } elseif ($uid) {
                    if ($password !== '') {
                        $pdo_master->prepare("UPDATE master_users SET name=?, username=?, email=?, role=?, password=? WHERE id=?")
                            ->execute([$name, $username, $email, $role, password_hash($password, PASSWORD_DEFAULT), $uid]);
                    } else {
                        $pdo_master->prepare("UPDATE master_users SET name=?, username=?, email=?, role=? WHERE id=?")
                            ->execute([$name, $username, $email, $role, $uid]);
                    }
                    logActivity($pdo_master, null, 'user_updated', $name . ' (' . $username . ')');
                    $success = 'ইউজার আপডেট হয়েছে।';
                } else {
                    if (strlen($password) < 6) {
                        $error = 'নতুন ইউজারের জন্য কমপক্ষে ৬ ক্যারেক্টারের পাসওয়ার্ড দাও।';
                    } else {
                        $pdo_master->prepare("INSERT INTO master_users (name, username, email, role, password, is_active) VALUES (?,?,?,?,?,1)")
                            ->execute([$name, $username, $email, $role, password_hash($password, PASSWORD_DEFAULT)]);
                        logActivity($pdo_master, null, 'user_created', $name . ' (' . $username . ')');
                        $success = 'নতুন ইউজার তৈরি হয়েছে।';
                    }
                }

                // পারমিশন সেভ (admin রোলের জন্য)
                if ($uid || (!$error && $role === 'admin')) {
                    $targetId = $uid ?: (int) $pdo_master->lastInsertId();
                    foreach ($menus as $key => $label) {
                        $canView = isset($_POST["perm_{$key}_view"]) ? 1 : 0;
                        $canAdd  = isset($_POST["perm_{$key}_add"]) ? 1 : 0;
                        $canEdit = isset($_POST["perm_{$key}_edit"]) ? 1 : 0;
                        $pdo_master->prepare("INSERT INTO user_menu_permissions (user_id, menu_key, can_view, can_add, can_edit) VALUES (?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE can_view=VALUES(can_view), can_add=VALUES(can_add), can_edit=VALUES(can_edit)")
                            ->execute([$targetId, $key, $canView, $canAdd, $canEdit]);
                    }
                }
            }
        }

        if ($action === 'toggle_active') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            if ($uid !== (int) $_SESSION['master_user_id']) {
                $pdo_master->prepare("UPDATE master_users SET is_active = 1 - is_active WHERE id=?")->execute([$uid]);
                $success = 'ইউজার স্ট্যাটাস বদলানো হয়েছে।';
            } else {
                $error = 'নিজেকে নিষ্ক্রিয় করা যাবে না।';
            }
        }

        if ($action === 'delete_user') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            if ($uid !== (int) $_SESSION['master_user_id']) {
                $pdo_master->prepare("DELETE FROM master_users WHERE id=?")->execute([$uid]);
                $pdo_master->prepare("DELETE FROM user_menu_permissions WHERE user_id=?")->execute([$uid]);
                logActivity($pdo_master, null, 'user_deleted', 'user #' . $uid);
                $success = 'ইউজার ডিলিট করা হয়েছে।';
            } else {
                $error = 'নিজেকে ডিলিট করা যাবে না।';
            }
        }
    }
}

if (isset($_GET['edit'])) {
    $eid = (int) $_GET['edit'];
    $s = $pdo_master->prepare("SELECT * FROM master_users WHERE id=?");
    $s->execute([$eid]);
    $editUser = $s->fetch();
}

$users = $pdo_master->query("SELECT * FROM master_users ORDER BY id ASC")->fetchAll();

$userPerms = [];
if ($editUser) {
    $p = $pdo_master->prepare("SELECT * FROM user_menu_permissions WHERE user_id=?");
    $p->execute([$editUser['id']]);
    foreach ($p->fetchAll() as $row) $userPerms[$row['menu_key']] = $row;
}

$pageTitle = 'Users';
$pageIcon  = '👥';
$activeKey = 'users';
require_once __DIR__ . '/_layout_top.php';
?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card-custom p-4">
                <h6 class="fw-bold mb-3"><?php echo $editUser ? '✏️ ইউজার এডিট করো' : '➕ নতুন ইউজার'; ?></h6>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_user">
                    <input type="hidden" name="user_id" value="<?php echo $editUser['id'] ?? 0; ?>">

                    <div class="mb-2">
                        <label class="form-label small fw-bold">নাম</label>
                        <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($editUser['name'] ?? ''); ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Username</label>
                        <input type="text" name="username" class="form-control" required value="<?php echo htmlspecialchars($editUser['username'] ?? ''); ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">ইমেইল (ঐচ্ছিক)</label>
                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($editUser['email'] ?? ''); ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">পাসওয়ার্ড <?php echo $editUser ? '(খালি রাখলে বদলাবে না)' : ''; ?></label>
                        <input type="password" name="password" class="form-control" <?php echo $editUser ? '' : 'required minlength=6'; ?>>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">রোল</label>
                        <select name="role" class="form-select" id="roleSelect" onchange="document.getElementById('permBox').style.display=this.value==='admin'?'block':'none';">
                            <?php foreach (['superadmin' => 'Superadmin (সব পারবে)', 'admin' => 'Admin (নির্দিষ্ট মেনু)', 'viewer' => 'Viewer (শুধু দেখতে পারবে)'] as $r => $label): ?>
                            <option value="<?php echo $r; ?>" <?php echo ($editUser['role'] ?? 'admin') === $r ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="permBox" style="display:<?php echo ($editUser['role'] ?? 'admin') === 'admin' ? 'block' : 'none'; ?>;">
                        <label class="form-label small fw-bold">Admin হলে কোন মেনুতে কী পারমিশন:</label>
                        <table class="table table-sm">
                            <thead><tr><th>মেনু</th><th class="text-center">View</th><th class="text-center">Add</th><th class="text-center">Edit</th></tr></thead>
                            <tbody>
                            <?php foreach ($menus as $key => $label): ?>
                                <tr>
                                    <td><?php echo $label; ?></td>
                                    <td class="text-center"><input type="checkbox" name="perm_<?php echo $key; ?>_view" <?php echo (!isset($userPerms[$key]) || $userPerms[$key]['can_view']) ? 'checked' : ''; ?>></td>
                                    <td class="text-center"><input type="checkbox" name="perm_<?php echo $key; ?>_add" <?php echo (!empty($userPerms[$key]['can_add'])) ? 'checked' : ''; ?>></td>
                                    <td class="text-center"><input type="checkbox" name="perm_<?php echo $key; ?>_edit" <?php echo (!empty($userPerms[$key]['can_edit'])) ? 'checked' : ''; ?>></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <button type="submit" class="btn btn-primary fw-bold w-100 mt-2"><?php echo $editUser ? 'আপডেট করো' : 'তৈরি করো'; ?></button>
                    <?php if ($editUser): ?><a href="users.php" class="btn btn-outline-secondary w-100 mt-2">বাতিল</a><?php endif; ?>
                </form>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card-custom p-3">
                <h6 class="fw-bold mb-3">সব ইউজার (<?php echo count($users); ?>)</h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr><th>নাম</th><th>Username</th><th>রোল</th><th>স্ট্যাটাস</th><th>Last Login</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($u['name']); ?></td>
                                <td><?php echo htmlspecialchars($u['username']); ?></td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($u['role']); ?></span></td>
                                <td><span class="badge <?php echo $u['is_active'] ? 'badge-active' : 'badge-cancelled'; ?>"><?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                <td><small><?php echo htmlspecialchars($u['last_login'] ?? '—'); ?></small></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="users.php?edit=<?php echo $u['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-pen"></i></a>
                                        <?php if ((int) $u['id'] !== (int) $_SESSION['master_user_id']): ?>
                                        <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <button class="btn btn-sm btn-outline-warning"><i class="fa fa-power-off"></i></button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('ডিলিট করবে?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
