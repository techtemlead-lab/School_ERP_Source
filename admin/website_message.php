<?php
// admin/website_message.php - Website Module > Message Corner
// হোমপেজে Chairman/Principal-এর ছবি + পদবি + বার্তা দেখানোর জন্য।
// List view + Add/Edit view আলাদা — academic_session.php এর প্যাটার্ন অনুসরণ করে।

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS message_corner (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        designation VARCHAR(150) NOT NULL,
        photo_path VARCHAR(255) NULL,
        message TEXT NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

require_once __DIR__ . '/../config/home_messages.php';
hm_ensure_schema($pdo); // role_key কলাম + পদবি সেটিংস টেবিল self-heal

$msg_flash = $_SESSION['website_msg'] ?? '';
$err_flash = $_SESSION['website_err'] ?? '';
unset($_SESSION['website_msg'], $_SESSION['website_err']);

$view = $_GET['view'] ?? 'list';

function wm_val($val): string { return htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8'); }

function wm_handle_upload(string $field, bool $required): array
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => !$required, 'path' => null, 'error' => $required ? 'ছবি আবশ্যক।' : null];
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'ছবি আপলোডে সমস্যা হয়েছে।'];
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        return ['ok' => false, 'path' => null, 'error' => 'ছবির সাইজ 3MB এর বেশি হওয়া যাবে না।'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    if (!isset($allowed_mimes[$ext])) {
        return ['ok' => false, 'path' => null, 'error' => 'শুধু jpg/png/webp ছবি আপলোড করা যাবে।'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== $allowed_mimes[$ext]) {
        return ['ok' => false, 'path' => null, 'error' => 'ফাইলের প্রকৃত কনটেন্ট এক্সটেনশনের সাথে মিলছে না।'];
    }
    $upload_dir = tenant_upload_abs(__DIR__ . '/..', 'website');
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $random_name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $random_name)) {
        return ['ok' => false, 'path' => null, 'error' => 'ছবি সেভ করা যায়নি।'];
    }
    return ['ok' => true, 'path' => tenant_upload_rel('website') . '/' . $random_name, 'error' => null];
}

function wm_delete_file(?string $relPath): void
{
    if ($relPath) {
        $abs = __DIR__ . '/../' . $relPath;
        if (is_file($abs)) { @unlink($abs); }
    }
}

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['website_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=website_message');
        exit();
    }

    if (isset($_POST['save_message'])) {
        $id          = (int) ($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $message     = trim($_POST['message'] ?? '');
        $sort        = (int) ($_POST['sort_order'] ?? 0);
        $role_key    = $_POST['role_key'] ?? 'other';
        if (!isset(hm_roles()[$role_key])) $role_key = 'other';

        if ($name === '' || $designation === '' || $message === '') {
            $_SESSION['website_err'] = 'নাম, পদবি ও বার্তা — সবগুলো আবশ্যক।';
            header('Location: index.php?page=website_message&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        $img = wm_handle_upload('photo', false);
        if (!$img['ok']) {
            $_SESSION['website_err'] = $img['error'];
            header('Location: index.php?page=website_message&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        if ($id > 0) {
            if ($img['path']) {
                $old_stmt = $pdo->prepare("SELECT photo_path FROM message_corner WHERE id = ?");
                $old_stmt->execute([$id]);
                $old = $old_stmt->fetch();
                $pdo->prepare("UPDATE message_corner SET name=?, designation=?, role_key=?, photo_path=?, message=?, sort_order=? WHERE id=?")
                    ->execute([$name, $designation, $role_key, $img['path'], $message, $sort, $id]);
                if ($old) wm_delete_file($old['photo_path']);
            } else {
                $pdo->prepare("UPDATE message_corner SET name=?, designation=?, role_key=?, message=?, sort_order=? WHERE id=?")
                    ->execute([$name, $designation, $role_key, $message, $sort, $id]);
            }
            $_SESSION['website_msg'] = 'বার্তা আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Update', "ওয়েবসাইট Message Corner আপডেট করা হয়েছে: {$name} ({$designation})");
        } else {
            $pdo->prepare("INSERT INTO message_corner (name, designation, role_key, photo_path, message, sort_order) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$name, $designation, $role_key, $img['path'], $message, $sort]);
            $_SESSION['website_msg'] = 'বার্তা যোগ করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Create', "ওয়েবসাইট Message Corner-এ নতুন বার্তা যুক্ত করা হয়েছে: {$name} ({$designation})");
        }
        header('Location: index.php?page=website_message');
        exit();
    } elseif (isset($_POST['toggle_message'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE message_corner SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $_SESSION['website_msg'] = 'স্ট্যাটাস বদলানো হয়েছে।';
    } elseif (isset($_POST['delete_message'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $row_stmt = $pdo->prepare("SELECT photo_path FROM message_corner WHERE id = ?");
        $row_stmt->execute([$id]);
        $row = $row_stmt->fetch();
        $pdo->prepare("DELETE FROM message_corner WHERE id = ?")->execute([$id]);
        if ($row) wm_delete_file($row['photo_path']);
        $_SESSION['website_msg'] = 'বার্তা মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Website', 'Delete', "ওয়েবসাইট Message Corner থেকে বার্তা ডিলিট করা হয়েছে: #$id");
    }

    header('Location: index.php?page=website_message');
    exit();
}
?>

<div class="card-custom">
    <h4 class="fw-bold mb-4 text-dark"><i class="fa-solid fa-comment-dots"></i> Website Module &gt; Message Corner</h4>


    <!-- সারিওয়ারি Delete বাটন আগে প্রতিটা নিজের ছোট্ট &lt;form&gt;-এ মোড়ানো ছিল, আর সেই ফর্মগুলো
         থাকত নিচের টেবিলটা মোড়ানো GET ফিল্টার ফর্মের ভেতরে — অর্থাৎ ফর্মের ভেতরে ফর্ম। HTML-এ
         নেস্টেড &lt;form&gt; বৈধ না, তাই ব্রাউজার ভেতরের ফর্মটাকে বাতিল করে তার ইনপুট/বাটনগুলো
         বাইরের GET ফিল্টার ফর্মেই বসিয়ে দিত। ফলে Delete-এ ক্লিক করলে আসল POST ডিলিট রিকোয়েস্টের
         বদলে ফিল্টার ফর্মটাই (GET, action=index.php) সাবমিট হয়ে যেত — কিছুই ডিলিট হতো না।
         সমাধানঃ একটামাত্র শেয়ার্ড হিডেন ফর্ম, GET ফিল্টার ফর্মের বাইরে রাখা — প্রতিটা Delete
         বাটন এখন আলাদা ফর্ম না বানিয়ে শুধু এটাকেই JS দিয়ে সাবমিট করে। -->
    <form method="POST" id="wcRowActionForm" style="display:none">
        <input type="hidden" name="csrf_token" id="wcRowActionCsrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" id="wcRowActionId" value="">
    </form>
    <script>
    function wcRowAction(url, field, id, confirmMsg) {
        if (confirmMsg && !window.confirm(confirmMsg)) return false;
        var f = document.getElementById('wcRowActionForm');
        f.setAttribute('action', url);
        document.getElementById('wcRowActionId').value = id;
        var old = f.querySelector('input[data-dyn-field]');
        if (old) old.parentNode.removeChild(old);
        var fld = document.createElement('input');
        fld.type = 'hidden'; fld.name = field; fld.value = '1'; fld.setAttribute('data-dyn-field', '1');
        f.appendChild(fld);
        f.submit();
        return false;
    }
    </script>
    <?php if ($msg_flash): ?>
        <div class="alert alert-success py-2"><?php echo htmlspecialchars($msg_flash); ?></div>
    <?php endif; ?>
    <?php if ($err_flash): ?>
        <div class="alert alert-danger py-2"><?php echo htmlspecialchars($err_flash); ?></div>
    <?php endif; ?>

    <?php if ($view === 'add' || $view === 'edit'):
        $d = ['id' => 0, 'name' => '', 'designation' => '', 'role_key' => 'other', 'photo_path' => '', 'message' => '', 'sort_order' => 0];
        if ($view === 'edit') {
            $stmt = $pdo->prepare("SELECT * FROM message_corner WHERE id = ?");
            $stmt->execute([(int) ($_GET['id'] ?? 0)]);
            $row = $stmt->fetch();
            if ($row) $d = $row;
        }
    ?>
        <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;"><i class="fa-solid fa-comment-dots text-white"></i></div>
        <form method="POST" action="index.php?page=website_message" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">নাম <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="name" class="form-control" placeholder="যেমনঃ জনাব আব্দুল করিম" value="<?php echo wm_val($d['name']); ?>" required></div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">পদবির ধরন <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="role_key" id="wmRoleKey" class="form-select">
                        <?php foreach (hm_roles() as $rk => $rdef): ?>
                            <option value="<?php echo $rk; ?>" data-label="<?php echo wm_val($rdef['bn']); ?>" <?php echo ($d['role_key'] ?? 'other') === $rk ? 'selected' : ''; ?>><?php echo wm_val(hm_role_text($rdef)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">হোমপেজে কোন পদবির বাণী আগে দেখাবে/দেখাবে না — সেটা Settings &gt; Home Page Settings থেকে ঠিক হয়।</small>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">পদবি (লেখা) <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="designation" id="wmDesignation" class="form-control" placeholder="যেমনঃ প্রধান শিক্ষক, অমুক স্কুল" value="<?php echo wm_val($d['designation']); ?>" required></div>
            </div>
            <script>
            // পদবির লেখা ঘর ফাঁকা থাকলে, ধরন বাছাই করলেই লেখাটা নিজে থেকে বসে যাবে (পরে বদলানো যাবে)
            (function () {
                var sel = document.getElementById('wmRoleKey'), inp = document.getElementById('wmDesignation');
                if (!sel || !inp) return;
                var auto = inp.value.trim() === '';
                inp.addEventListener('input', function () { auto = false; });
                sel.addEventListener('change', function () {
                    var opt = sel.options[sel.selectedIndex];
                    if (auto && sel.value !== 'other') inp.value = opt.getAttribute('data-label');
                });
            })();
            </script>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">ছবি <?php echo $view === 'edit' ? '<br><small class="text-muted fw-normal">(পরিবর্তন না করলে খালি রাখুন)</small>' : ''; ?></label>
                <div class="col-sm-6">
                    <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                    <?php if ($view === 'edit' && !empty($d['photo_path'])): ?>
                        <img src="../<?php echo wm_val($d['photo_path']); ?>" class="mt-2" style="width:70px;height:70px;object-fit:cover;border-radius:50%;" alt="">
                    <?php endif; ?>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">বার্তা <span class="text-danger">*</span></label>
                <div class="col-sm-6"><textarea name="message" class="form-control" rows="5" required><?php echo wm_val($d['message']); ?></textarea></div>
            </div>
            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Sort Order</label>
                <div class="col-sm-6"><input type="number" name="sort_order" class="form-control" value="<?php echo (int) $d['sort_order']; ?>"></div>
            </div>
            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_message" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=website_message" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    <?php else:
        $f_name   = trim($_GET['f_name'] ?? '');
        $f_status = $_GET['f_status'] ?? 'All';
        $where = []; $params = [];
        if ($f_name !== '') { $where[] = '(name LIKE ? OR designation LIKE ?)'; $params[] = "%$f_name%"; $params[] = "%$f_name%"; }
        if ($f_status !== 'All') { $where[] = 'is_active = ?'; $params[] = $f_status === 'Active' ? 1 : 0; }
        $sql = "SELECT * FROM message_corner"; if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY sort_order ASC, id ASC";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        $messages = $stmt->fetchAll();
    ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=website_message&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
            <span class="text-muted small">Showing 1-<?php echo count($messages); ?> of <?php echo count($messages); ?> item.</span>
        </div>
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="website_message">
            <div class="table-responsive">
                <table class="table table-bordered align-middle text-center">
                    <thead class="table-light">
                        <tr><th>Srl.</th><th>Photo</th><th>Name</th><th>Designation</th><th>Role</th><th>Order</th><th>Status</th><th>Actions</th></tr>
                        <tr>
                            <th></th><th></th>
                            <th colspan="3"><input type="text" name="f_name" class="form-control form-control-sm" placeholder="নাম/পদবি খুঁজুন" value="<?php echo wm_val($f_name); ?>"></th>
                            <th></th>
                            <th>
                                <select name="f_status" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All" <?php echo $f_status === 'All' ? 'selected' : ''; ?>>All</option>
                                    <option value="Active" <?php echo $f_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Hidden" <?php echo $f_status === 'Hidden' ? 'selected' : ''; ?>>Hidden</option>
                                </select>
                            </th>
                            <th><button type="submit" class="btn btn-sm btn-outline-primary">Filter</button></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($messages): $srl = 1; foreach ($messages as $m): $csrf = htmlspecialchars(csrf_token()); ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td>
                                    <?php if (!empty($m['photo_path'])): ?>
                                        <img src="../<?php echo wm_val($m['photo_path']); ?>" style="width:45px;height:45px;object-fit:cover;border-radius:50%;" alt="">
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td class="text-start fw-bold"><?php echo wm_val($m['name']); ?></td>
                                <td class="text-start small text-muted"><?php echo wm_val($m['designation']); ?></td>
                                <td class="small"><?php $rk = $m['role_key'] ?? 'other'; echo wm_val(hm_roles()[$rk]['bn'] ?? hm_roles()['other']['bn']); ?></td>
                                <td><?php echo (int)$m['sort_order']; ?></td>
                                <td><?php echo $m['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=website_message&view=edit&id=<?php echo (int) $m['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                    <a href="index.php?page=website_message&view=edit&id=<?php echo (int) $m['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=website_message', 'delete_message', <?php echo (int) $m['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="8" class="text-center text-muted py-3">No messages found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    <?php endif; ?>
</div>
