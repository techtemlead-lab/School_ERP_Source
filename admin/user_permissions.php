<?php
// admin/user_permissions.php - User Management > User Permission
// শুধুমাত্র Super Admin এই পেজ দেখতে/ব্যবহার করতে পারবে — এখান থেকে একজন
// system_user (Admin/Accountant/HR/Data Entry Operator/Demo ইত্যাদি) কে
// sidebar-এর কোন কোন মেনু/সাবমেনু পেজে এক্সেস দেওয়া হবে তা সিলেক্ট করা যায়।
//
// এক্সেস আসলে বন্ধ হয় admin/index.php-এর রাউটারে (perm_page_allowed চেক) —
// এখানে শুধু কোন পেজ allowed সেটা সেভ করা হয়। ঠিক সেভাবেই sidebar.php-এ
// module-level এ perm_module_allowed() দিয়ে হোল মডিউল hide করা হয়।

$is_super_admin = $_SESSION['is_super_admin'] ?? true; // পুরনো সেশন ব্যাকওয়ার্ড-কম্প্যাটিবিলিটি
if (!$is_super_admin) {
    echo '<div class="card-custom"><div class="alert alert-danger mb-0"><i class="fa-solid fa-lock me-2"></i>শুধুমাত্র <b>Super Admin</b> User Permission অ্যাক্সেস করতে পারবেন।</div></div>';
    return;
}

perm_ensure_tables($pdo);

$up_err = $_SESSION['up_err'] ?? '';
$up_msg = $_SESSION['up_msg'] ?? '';
unset($_SESSION['up_err'], $_SESSION['up_msg']);

function upv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$menu_tree = require __DIR__ . '/inc/menu_permissions_tree.php';

// -----------------------------------------------------------
// সেভ (POST)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_permissions'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['up_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=user_permissions');
        exit();
    }

    $uid = (int) ($_POST['uid'] ?? 0);
    $chk = $pdo->prepare("SELECT id FROM system_users WHERE id = ?");
    $chk->execute([$uid]);
    if (!$chk->fetch()) {
        $_SESSION['up_err'] = 'ইউজার খুঁজে পাওয়া যায়নি।';
        header('Location: index.php?page=user_permissions');
        exit();
    }

    $restricted = isset($_POST['restricted']) ? 1 : 0;
    $keys = $_POST['perm_keys'] ?? [];
    if (!is_array($keys)) $keys = [];

    // whitelist — শুধু menu_permissions_tree.php-তে যা আছে তাই সেভ হবে, POST থেকে
    // মনগড়া key ঢুকিয়ে দেওয়ার সুযোগ নেই
    $valid_keys = [];
    foreach ($menu_tree as $module) {
        foreach ($module['children'] as $child) {
            if ($child['type'] === 'leaf') {
                $valid_keys[$child['key']] = true;
            } else {
                foreach ($child['children'] as $leaf) {
                    $valid_keys[$leaf['key']] = true;
                }
            }
        }
    }
    $keys = array_values(array_intersect($keys, array_keys($valid_keys)));
    $p_add  = array_map('strval', (array) ($_POST['perm_add'] ?? []));
    $p_edit = array_map('strval', (array) ($_POST['perm_edit'] ?? []));
    $p_del  = array_map('strval', (array) ($_POST['perm_del'] ?? []));

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE system_users SET permissions_restricted = ? WHERE id = ?")->execute([$restricted, $uid]);
    $pdo->prepare("DELETE FROM user_menu_permissions WHERE system_user_id = ?")->execute([$uid]);
    if ($restricted && $keys) {
        $ins = $pdo->prepare("INSERT INTO user_menu_permissions (system_user_id, page_key, can_add, can_edit, can_delete) VALUES (?, ?, ?, ?, ?)");
        foreach ($keys as $k) {
            $ins->execute([$uid, $k, in_array($k, $p_add, true) ? 1 : 0, in_array($k, $p_edit, true) ? 1 : 0, in_array($k, $p_del, true) ? 1 : 0]);
        }
    }
    $pdo->commit();

    $_SESSION['up_msg'] = 'পারমিশন সেভ হয়েছে।';
    header('Location: index.php?page=user_permissions&uid=' . $uid);
    exit();
}

// -----------------------------------------------------------
// Role অনুযায়ী সেভ (POST) — একটা Role-এর সব ইউজারের জন্য ডিফল্ট পারমিশন
// -----------------------------------------------------------
$perm_roles = ['Accountant', 'Admin', 'Canteen Staff', 'Data Entry Operator', 'Demo', 'Human Resource', 'Teacher'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_role_permissions'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['up_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=user_permissions&mode=role');
        exit();
    }
    $role = (string) ($_POST['role_type'] ?? '');
    if (!in_array($role, $perm_roles, true)) {
        $_SESSION['up_err'] = 'Role খুঁজে পাওয়া যায়নি।';
        header('Location: index.php?page=user_permissions&mode=role');
        exit();
    }
    $restricted = isset($_POST['restricted']) ? 1 : 0;
    $keys = $_POST['perm_keys'] ?? [];
    if (!is_array($keys)) $keys = [];
    $valid_keys = [];
    foreach ($menu_tree as $module) {
        foreach ($module['children'] as $child) {
            if ($child['type'] === 'leaf') { $valid_keys[$child['key']] = true; }
            else { foreach ($child['children'] as $leaf) { $valid_keys[$leaf['key']] = true; } }
        }
    }
    $keys = array_values(array_intersect($keys, array_keys($valid_keys)));
    $p_add  = array_map('strval', (array) ($_POST['perm_add'] ?? []));
    $p_edit = array_map('strval', (array) ($_POST['perm_edit'] ?? []));
    $p_del  = array_map('strval', (array) ($_POST['perm_del'] ?? []));

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO role_permission_settings (user_type, restricted) VALUES (?, ?) ON DUPLICATE KEY UPDATE restricted = VALUES(restricted)")->execute([$role, $restricted]);
    $pdo->prepare("DELETE FROM role_menu_permissions WHERE user_type = ?")->execute([$role]);
    if ($restricted && $keys) {
        $ins = $pdo->prepare("INSERT INTO role_menu_permissions (user_type, page_key, can_add, can_edit, can_delete) VALUES (?, ?, ?, ?, ?)");
        foreach ($keys as $k) { $ins->execute([$role, $k, in_array($k, $p_add, true) ? 1 : 0, in_array($k, $p_edit, true) ? 1 : 0, in_array($k, $p_del, true) ? 1 : 0]); }
    }
    $pdo->commit();
    if (function_exists('sec_log_activity')) { sec_log_activity($pdo, 'User Management', 'Update', "Role পারমিশন আপডেট: {$role}"); }

    $_SESSION['up_msg'] = $role . ' Role-এর পারমিশন সেভ হয়েছে।';
    header('Location: index.php?page=user_permissions&mode=role&role=' . urlencode($role));
    exit();
}

// -----------------------------------------------------------
// ইউজার লিস্ট + সিলেক্টেড ইউজার
// -----------------------------------------------------------
$users = $pdo->query(
    "SELECT id, username, user_type, status FROM system_users ORDER BY user_type ASC, username ASC"
)->fetchAll();

$selected_uid = (int) ($_GET['uid'] ?? 0);
$selected_user = null;
$allowed_keys = [];
$is_restricted = false;

if ($selected_uid) {
    foreach ($users as $u) {
        if ((int) $u['id'] === $selected_uid) { $selected_user = $u; break; }
    }
}
if ($selected_user) {
    $stmt = $pdo->prepare("SELECT permissions_restricted FROM system_users WHERE id = ?");
    $stmt->execute([$selected_uid]);
    $is_restricted = (bool) $stmt->fetchColumn();

    $stmt2 = $pdo->prepare("SELECT page_key FROM user_menu_permissions WHERE system_user_id = ?");
    $stmt2->execute([$selected_uid]);
    $allowed_keys = $stmt2->fetchAll(PDO::FETCH_COLUMN);
}
$mode = (($_GET['mode'] ?? '') === 'role') ? 'role' : 'user';
$selected_role = (string) ($_GET['role'] ?? '');
if (!in_array($selected_role, $perm_roles, true)) { $selected_role = ''; }
$role_users_count = [];
foreach ($pdo->query("SELECT user_type, COUNT(*) c FROM system_users GROUP BY user_type")->fetchAll() as $rc) { $role_users_count[$rc['user_type']] = (int) $rc['c']; }
$user_role_default = null; // ইউজার মোডে: তার Role-এ পারমিশন চালু থাকলে সেই তালিকা
if ($mode === 'role' && $selected_role !== '') {
    $role_keys_now = perm_role_allowed_keys($pdo, $selected_role);
    $is_restricted = $role_keys_now !== null;
    $allowed_keys = $role_keys_now ?? [];
} elseif ($mode === 'user' && $selected_user) {
    $user_role_default = perm_role_allowed_rows($pdo, (string) $selected_user['user_type']);
}
$allowed_lookup = array_flip($allowed_keys);
$action_lookup = [];
if ($mode === 'role' && $selected_role !== '') {
    $action_lookup = perm_role_allowed_rows($pdo, $selected_role) ?? [];
} elseif ($mode === 'user' && $selected_user) {
    $al = $pdo->prepare("SELECT page_key, can_add, can_edit, can_delete FROM user_menu_permissions WHERE system_user_id = ?");
    $al->execute([$selected_uid]);
    foreach ($al->fetchAll() as $r) { $action_lookup[$r['page_key']] = ['add' => (bool) $r['can_add'], 'edit' => (bool) $r['can_edit'], 'delete' => (bool) $r['can_delete']]; }
}
$show_form = ($mode === 'role') ? ($selected_role !== '') : (bool) $selected_user;
?>
<div class="card-custom">
    <?php if ($up_msg): ?><div class="alert alert-success py-2"><?php echo upv($up_msg); ?></div><?php endif; ?>
    <?php if ($up_err): ?><div class="alert alert-danger py-2"><?php echo upv($up_err); ?></div><?php endif; ?>

    <ul class="nav nav-pills mb-3">
        <li class="nav-item"><a class="nav-link <?php echo $mode === 'user' ? 'active' : ''; ?>" href="index.php?page=user_permissions"><i class="fa-solid fa-user me-1"></i> ইউজার অনুযায়ী</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $mode === 'role' ? 'active' : ''; ?>" href="index.php?page=user_permissions&mode=role"><i class="fa-solid fa-user-shield me-1"></i> Role অনুযায়ী</a></li>
    </ul>

    <?php if ($mode === 'role'): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-5 col-lg-4">
            <label class="form-label fw-bold" style="font-size:13px;">Role সিলেক্ট করুন</label>
            <form method="GET" action="index.php">
                <input type="hidden" name="page" value="user_permissions">
                <input type="hidden" name="mode" value="role">
                <select name="role" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">-- Role সিলেক্ট করুন --</option>
                    <?php foreach ($perm_roles as $pr): ?>
                        <option value="<?php echo upv($pr); ?>" <?php echo $selected_role === $pr ? 'selected' : ''; ?>><?php echo upv($pr); ?> (<?php echo (int) ($role_users_count[$pr] ?? 0); ?> জন ইউজার)</option>
                    <?php endforeach; ?>
                </select>
            </form>
            <div class="text-muted small mt-2">Role-এর পারমিশন ঐ Role-এর সব ইউজারে প্রযোজ্য হবে। কোনো ইউজারের নিজস্ব Restrict (ইউজার অনুযায়ী ট্যাব) চালু থাকলে সেটাই আগে ধরা হবে।</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($mode === 'user'): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-5 col-lg-4">
            <label class="form-label fw-bold" style="font-size:13px;">ইউজার সিলেক্ট করুন</label>
            <form method="GET" action="index.php">
                <input type="hidden" name="page" value="user_permissions">
                <select name="uid" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">-- ইউজার সিলেক্ট করুন --</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo (int) $u['id']; ?>" <?php echo $selected_uid === (int) $u['id'] ? 'selected' : ''; ?>>
                            <?php echo upv($u['user_type']); ?> — <?php echo upv($u['username']); ?><?php echo $u['status'] !== 'Active' ? ' (Inactive)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php if (!$users): ?>
                <div class="text-muted small mt-2">User Management &gt; User List থেকে আগে একটা ইউজার তৈরি করুন।</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($show_form): ?>
        <form method="POST" action="index.php?page=user_permissions<?php echo $mode === 'role' ? '&mode=role&role=' . urlencode($selected_role) : ''; ?>">
            <?php echo csrf_field(); ?>
            <?php if ($mode === 'role'): ?>
                <input type="hidden" name="save_role_permissions" value="1">
                <input type="hidden" name="role_type" value="<?php echo upv($selected_role); ?>">
            <?php else: ?>
                <input type="hidden" name="save_permissions" value="1">
                <input type="hidden" name="uid" value="<?php echo (int) $selected_uid; ?>">
                <?php if ($user_role_default !== null): ?>
                    <div class="alert alert-info py-2 small">
                        এই ইউজারের Role (<b><?php echo upv($selected_user['user_type']); ?></b>)-এ পারমিশন সেট করা আছে — নিচের Restrict <b>অফ</b> থাকলে সেই Role-এর পারমিশনই প্রযোজ্য। শুধু এই ইউজারের জন্য আলাদা করতে Restrict অন করুন।
                        <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="upCopyRole()">Role-এর পারমিশন এখানে কপি করুন</button>
                    </div>
                    <script>var UP_ROLE_ROWS = <?php echo json_encode($user_role_default ? $user_role_default : new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
                    function upCopyRole(){ document.getElementById('upRestrictedToggle').checked = true; document.getElementById('upTreeWrap').classList.remove('opacity-50');
                        document.querySelectorAll('.up-leaf').forEach(function(cb){ var r = UP_ROLE_ROWS[cb.value]; cb.checked = !!r;
                            document.querySelectorAll('input.up-act[value="' + cb.value + '"]').forEach(function(a){ var n = a.name; a.checked = r ? (n === 'perm_add[]' ? r.add : n === 'perm_edit[]' ? r.edit : r['delete']) : true; }); });
                        upUpdateCounts(); }</script>
                <?php endif; ?>
            <?php endif; ?>

            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="upRestrictedToggle" name="restricted" value="1" <?php echo $is_restricted ? 'checked' : ''; ?> onchange="document.getElementById('upTreeWrap').classList.toggle('opacity-50', !this.checked)">
                <label class="form-check-label fw-bold" for="upRestrictedToggle">
                    <?php echo $mode === 'role' ? 'এই Role-এর জন্য Menu Permission সীমাবদ্ধ করুন (Restrict)' : 'এই ইউজারের জন্য Menu Permission সীমাবদ্ধ করুন (Restrict)'; ?>
                </label>
                <div class="text-muted small"><?php echo $mode === 'role' ? 'অফ থাকলে এই Role-এর ইউজারদের কোনো সীমা থাকবে না (আগের মতো সব পেজ)। অন করলে নিচে টিক দেওয়া পেজ ছাড়া বাকি সব মেনু এই Role-এর সব ইউজারের জন্য লুকানো থাকবে। ইউজারের নিজস্ব Restrict থাকলে সেটা আগে প্রযোজ্য।' : ''; ?><?php echo $mode === 'user' ? 'অফ থাকলে এই ইউজার আগের মতোই তার Role অনুযায়ী সব মেনু/পেজ দেখতে ও ব্যবহার করতে পারবে। অন করলে নিচে টিক দেওয়া পেজগুলো ছাড়া বাকি সব মেনু sidebar থেকে লুকানো থাকবে এবং সরাসরি লিংক দিয়েও ঢোকা যাবে না।' : ''; ?></div>
            </div>

            <div id="upTreeWrap" class="<?php echo $is_restricted ? '' : 'opacity-50'; ?>">
                <div class="mb-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="upToggleAll(true)">সব সিলেক্ট</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="upToggleAll(false)">সব আনসিলেক্ট</button>
                    <span class="ms-3 small text-muted">বাঁদিকের টিক = পেজ দেখা (View)। পাশের <b>A</b> = নতুন যোগ (Add), <b>E</b> = এডিট (Edit), <b>D</b> = ডিলিট (Delete)।</span>
                </div>

                <?php
                    // মডিউল রেন্ডার করার ফাংশন — এখন প্রতিটা কলাম আলাদা ভাবে রেন্ডার হবে,
                    // যাতে একটার height অন্যটাকে প্রভাবিত না করে (আগের row/col-md-6 পদ্ধতিতে
                    // দুইটা বক্স একই flex-line-এ থাকায় লম্বাটার সাথে সাথে ছোটটার পাশেও ফাঁকা জায়গা তৈরি হতো)
                    // প্রতিটি পেজের পাশে Add/Edit/Delete টিকবক্স (View = বাঁদিকের মূল টিক)
                    function up_act_boxes($key) {
                        global $action_lookup;
                        $act = $action_lookup[$key] ?? ['add' => true, 'edit' => true, 'delete' => true];
                        $out = '<span class="up-acts ms-2 small" data-key="' . upv($key) . '">';
                        foreach (['add' => ['perm_add[]', 'A', 'Add'], 'edit' => ['perm_edit[]', 'E', 'Edit'], 'delete' => ['perm_del[]', 'D', 'Delete']] as $k => $d) {
                            $out .= '<label class="me-1 text-nowrap" title="' . $d[2] . '"><input type="checkbox" class="up-act" name="' . $d[0] . '" value="' . upv($key) . '"' . (!empty($act[$k]) ? ' checked' : '') . '> ' . $d[1] . '</label>';
                        }
                        return $out . '</span>';
                    }
                    function up_render_module($mi, $module) {
                        ?>
                        <div class="accordion-item mb-2">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#upMod<?php echo $mi; ?>">
                                    <span class="fw-bold"><?php echo upv($module['label']); ?></span>
                                    <span class="badge bg-light text-dark ms-2 up-mod-count" data-mod="<?php echo $mi; ?>"></span>
                                </button>
                            </h2>
                            <div id="upMod<?php echo $mi; ?>" class="accordion-collapse collapse" data-bs-parent="#upAccordion">
                                <div class="accordion-body">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input up-mod-toggle" type="checkbox" id="upModAll<?php echo $mi; ?>" data-mod="<?php echo $mi; ?>" onclick="upToggleModule(<?php echo $mi; ?>, this.checked)">
                                        <label class="form-check-label fw-semibold" for="upModAll<?php echo $mi; ?>">সম্পূর্ণ মডিউল (<?php echo upv($module['label']); ?>)</label>
                                    </div>
                                    <div class="row">
                                        <?php foreach ($module['children'] as $child): ?>
                                            <?php if ($child['type'] === 'leaf'): ?>
                                                <div class="col-6 mb-1">
                                                    <div class="form-check">
                                                        <input class="form-check-input up-leaf up-mod-<?php echo $mi; ?>" type="checkbox" name="perm_keys[]" value="<?php echo upv($child['key']); ?>" id="upk_<?php echo upv($child['key']); ?>" <?php echo isset($allowed_lookup[$child['key']]) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="upk_<?php echo upv($child['key']); ?>"><?php echo upv($child['label']); ?></label><?php echo up_act_boxes($child['key']); ?>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="col-12">
                                                    <div class="small text-muted fw-semibold mt-2 mb-1"><?php echo upv($child['label']); ?></div>
                                                </div>
                                                <?php foreach ($child['children'] as $leaf): ?>
                                                    <div class="col-6 mb-1">
                                                        <div class="form-check">
                                                            <input class="form-check-input up-leaf up-mod-<?php echo $mi; ?>" type="checkbox" name="perm_keys[]" value="<?php echo upv($leaf['key']); ?>" id="upk_<?php echo upv($leaf['key']); ?>" <?php echo isset($allowed_lookup[$leaf['key']]) ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="upk_<?php echo upv($leaf['key']); ?>"><?php echo upv($leaf['label']); ?></label><?php echo up_act_boxes($leaf['key']); ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php
                    }

                    $modules_indexed = [];
                    $mi = 0;
                    foreach ($menu_tree as $modkey => $module) { $mi++; $modules_indexed[] = [$mi, $module]; }
                ?>
                <div class="accordion" id="upAccordion">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <?php foreach ($modules_indexed as $idx => $pair): if ($idx % 2 === 0) up_render_module($pair[0], $pair[1]); endforeach; ?>
                        </div>
                        <div class="col-md-6">
                            <?php foreach ($modules_indexed as $idx => $pair): if ($idx % 2 === 1) up_render_module($pair[0], $pair[1]); endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-success"><i class="fa-solid fa-floppy-disk me-1"></i> পারমিশন সেভ করুন</button>
            </div>
        </form>

        <script>
            function upToggleAll(state) {
                document.querySelectorAll('.up-leaf').forEach(cb => cb.checked = state);
                document.querySelectorAll('.up-mod-toggle').forEach(cb => cb.checked = state);
                upUpdateCounts();
            }
            function upToggleModule(mi, state) {
                document.querySelectorAll('.up-mod-' + mi).forEach(cb => cb.checked = state);
                upUpdateCounts();
            }
            function upUpdateCounts() {
                document.querySelectorAll('.up-mod-count').forEach(badge => {
                    const mi = badge.getAttribute('data-mod');
                    const boxes = document.querySelectorAll('.up-mod-' + mi);
                    const checked = document.querySelectorAll('.up-mod-' + mi + ':checked').length;
                    badge.textContent = checked + '/' + boxes.length;
                    const allToggle = document.getElementById('upModAll' + mi);
                    if (allToggle) allToggle.checked = (checked === boxes.length && boxes.length > 0);
                });
            }
            function upSyncActs() {
                document.querySelectorAll('.up-leaf').forEach(function (cb) {
                    document.querySelectorAll('input.up-act[value="' + cb.value + '"]').forEach(function (a) { a.disabled = !cb.checked; a.parentElement.style.opacity = cb.checked ? '1' : '.35'; });
                });
            }
            document.querySelectorAll('.up-leaf').forEach(cb => cb.addEventListener('change', function () { upUpdateCounts(); upSyncActs(); }));
            document.querySelectorAll('.up-mod-toggle, .btn-outline-secondary').forEach(el => el.addEventListener('click', function () { setTimeout(upSyncActs, 0); }));
            upUpdateCounts(); upSyncActs();
        </script>
    <?php elseif ($mode === 'role'): ?>
        <div class="text-muted">উপরে থেকে একটা Role সিলেক্ট করুন।</div>
    <?php elseif ($users): ?>
        <div class="text-muted">উপরে থেকে একজন ইউজার সিলেক্ট করুন।</div>
    <?php endif; ?>
</div>
