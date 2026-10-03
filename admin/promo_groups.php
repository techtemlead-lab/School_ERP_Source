<?php
// admin/promo_groups.php - Promotional Module > My Groups (SMS Group তৈরি/লিস্ট)
// প্যাটার্ন: academic_session.php (list + view=add/edit) অনুসরণ করা হয়েছে

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS promo_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_name VARCHAR(150) NOT NULL,
        image_path VARCHAR(255) NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$pg_err = $_SESSION['pg_err'] ?? '';
$pg_msg = $_SESSION['pg_msg'] ?? '';
unset($_SESSION['pg_err'], $_SESSION['pg_msg']);

function pg_esc($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// ছবি আপলোড
// -----------------------------------------------------------
function pg_upload_image(): array
{
    if (!isset($_FILES['group_image']) || $_FILES['group_image']['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null];
    }
    $file = $_FILES['group_image'];
    if ($file['error'] !== UPLOAD_ERR_OK) { return ['ok' => false, 'path' => null, 'error' => 'ছবি আপলোডে সমস্যা হয়েছে।']; }
    if ($file['size'] > 2 * 1024 * 1024) { return ['ok' => false, 'path' => null, 'error' => 'ছবির সাইজ 2MB এর বেশি হওয়া যাবে না।']; }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    if (!isset($allowed[$ext])) { return ['ok' => false, 'path' => null, 'error' => 'শুধু jpg/png/webp ছবি আপলোড করা যাবে।']; }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== $allowed[$ext]) { return ['ok' => false, 'path' => null, 'error' => 'ফাইলের প্রকৃত কনটেন্ট এক্সটেনশনের সাথে মিলছে না।']; }

    $dir = tenant_upload_abs(__DIR__ . '/..', 'promo_groups');
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    $rand = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $rand)) { return ['ok' => false, 'path' => null, 'error' => 'ছবি সেভ করা যায়নি।']; }
    return ['ok' => true, 'path' => tenant_upload_rel('promo_groups') . '/' . $rand];
}

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['pg_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=promo_groups');
        exit();
    }

    if (isset($_POST['save_group'])) {
        $id     = (int) ($_POST['id'] ?? 0);
        $name   = trim($_POST['group_name'] ?? '');
        $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if ($name === '') {
            $_SESSION['pg_err'] = 'Group Name আবশ্যক।';
        } else {
            $upload = pg_upload_image();
            if (!$upload['ok']) {
                $_SESSION['pg_err'] = $upload['error'];
            } else {
                if ($id > 0) {
                    if ($upload['path']) {
                        $pdo->prepare("UPDATE promo_groups SET group_name=?, status=?, image_path=? WHERE id=?")
                            ->execute([$name, $status, $upload['path'], $id]);
                    } else {
                        $pdo->prepare("UPDATE promo_groups SET group_name=?, status=? WHERE id=?")
                            ->execute([$name, $status, $id]);
                    }
                    $_SESSION['pg_msg'] = 'Group আপডেট করা হয়েছে।';
                    sec_log_activity($pdo, 'Promotional', 'Update', "Group আপডেট করা হয়েছে: {$name}");
                } else {
                    $pdo->prepare("INSERT INTO promo_groups (group_name, image_path, status) VALUES (?, ?, ?)")
                        ->execute([$name, $upload['path'], $status]);
                    $_SESSION['pg_msg'] = 'নতুন Group যোগ করা হয়েছে।';
                    sec_log_activity($pdo, 'Promotional', 'Create', "নতুন Group তৈরি করা হয়েছে: {$name}");
                }
            }
        }
    } elseif (isset($_POST['delete_group'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_group_row = $pdo->prepare("SELECT group_name FROM promo_groups WHERE id = ?");
        $del_group_row->execute([$id]);
        $del_group_name = $del_group_row->fetchColumn();
        $pdo->prepare("DELETE FROM promo_groups WHERE id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM promo_contacts WHERE group_id = ?")->execute([$id]);
        $_SESSION['pg_msg'] = 'Group এবং তার Contact গুলো মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Promotional', 'Delete', "Group মুছে ফেলা হয়েছে: " . ($del_group_name ?: "#$id"));
    }

    header('Location: index.php?page=promo_groups');
    exit();
}

$view = $_GET['view'] ?? 'list';
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM promo_groups WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: ['id' => 0, 'group_name' => '', 'image_path' => null, 'status' => 'Active'];
    ?>
    <div class="card-custom">
        <?php if ($pg_err): ?><div class="alert alert-danger py-2"><?php echo pg_esc($pg_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <a href="index.php?page=promo_groups" class="d-inline-block bg-warning bg-opacity-75 p-2 text-decoration-none" style="width:40px;border-radius:4px;" title="তালিকায় ফিরে যান">
                <i class="fa-solid fa-gift text-white"></i>
            </a>
            <a href="index.php?page=promo_groups" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i></a>
        </div>

        <form method="POST" action="index.php?page=promo_groups" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Group Name <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="group_name" class="form-control" value="<?php echo pg_esc($d['group_name']); ?>" required></div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Group Image</label>
                <div class="col-sm-6">
                    <input type="file" name="group_image" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                    <?php if (!empty($d['image_path'])): ?>
                        <small class="text-muted">বর্তমান ছবি: <a href="../<?php echo pg_esc($d['image_path']); ?>" target="_blank">দেখুন</a></small>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Status <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="status" class="form-select">
                        <option value="Active" <?php echo $d['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $d['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_group" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=promo_groups" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

<?php else: ?>
    <?php
    $f_name   = trim($_GET['f_name'] ?? '');
    $f_status = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_name !== '')   { $where[] = 'group_name LIKE ?'; $params[] = "%$f_name%"; }
    if ($f_status !== 'All') { $where[] = 'status = ?'; $params[] = $f_status; }

    $sql = "SELECT g.*, (SELECT COUNT(*) FROM promo_contacts c WHERE c.group_id = g.id) AS contact_count FROM promo_groups g";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY g.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($pg_msg): ?><div class="alert alert-success py-2"><?php echo pg_esc($pg_msg); ?></div><?php endif; ?>
        <?php if ($pg_err): ?><div class="alert alert-danger py-2"><?php echo pg_esc($pg_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=promo_groups&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i> Add Group</a>
            <span class="text-muted small">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> item.</span>
        </div>

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
<form method="GET" action="index.php">
            <input type="hidden" name="page" value="promo_groups">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>Image</th>
                            <th>Group Name</th>
                            <th>Contacts</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <tr>
                            <th></th>
                            <th></th>
                            <th><input type="text" name="f_name" class="form-control form-control-sm" value="<?php echo pg_esc($f_name); ?>"></th>
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
                        <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td><?php if ($row['image_path']): ?><img src="../<?php echo pg_esc($row['image_path']); ?>" style="width:36px;height:36px;object-fit:cover;border-radius:6px;"><?php else: ?>-<?php endif; ?></td>
                                <td><?php echo pg_esc($row['group_name']); ?></td>
                                <td><span class="badge bg-info text-dark"><?php echo (int) $row['contact_count']; ?></span></td>
                                <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=promo_groups&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <a href="index.php?page=promo_contacts&f_group=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="My Contacts"><i class="fa-solid fa-address-book"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=promo_groups', 'delete_group', <?php echo (int) $row['id']; ?>, 'এই Group এবং তার সব Contact মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">কোনো Group পাওয়া যায়নি</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
<?php endif; ?>
