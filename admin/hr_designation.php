<?php
// admin/hr_designation.php - Human Resource > HR Module > All Designation (স্ক্রিনশট অনুযায়ী)
$dg_err = $_SESSION['dg_err'] ?? '';
$dg_msg = $_SESSION['dg_msg'] ?? '';
unset($_SESSION['dg_err'], $_SESSION['dg_msg']);

// designations টেবিল না থাকলে অটো তৈরি
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS designations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('Teachers','Staffs','Board Of Directors','Academic Committee') NOT NULL DEFAULT 'Teachers',
        title VARCHAR(150) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$designation_types = ['Teachers', 'Staffs', 'Board Of Directors', 'Academic Committee'];

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['dg_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=hr_designation');
        exit();
    }

    if (isset($_POST['save_designation'])) {
        $id     = (int) ($_POST['id'] ?? 0);
        $type   = in_array($_POST['type'] ?? '', $GLOBALS['designation_types'] ?? [], true) ? $_POST['type'] : ($_POST['type'] ?? 'Teachers');
        $type   = in_array($type, ['Teachers', 'Staffs', 'Board Of Directors', 'Academic Committee'], true) ? $type : 'Teachers';
        $title  = trim($_POST['title'] ?? '');
        $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if ($title === '') {
            $_SESSION['dg_err'] = 'Title খালি রাখা যাবে না।';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE designations SET type=?, title=?, status=? WHERE id=?");
                $stmt->execute([$type, $title, $status, $id]);
                $_SESSION['dg_msg'] = 'Designation আপডেট করা হয়েছে।';
                sec_log_activity($pdo, 'HR', 'Update', "Designation আপডেট করা হয়েছে: {$title} ({$type})");
            } else {
                $stmt = $pdo->prepare("INSERT INTO designations (type, title, status) VALUES (?, ?, ?)");
                $stmt->execute([$type, $title, $status]);
                $_SESSION['dg_msg'] = 'নতুন Designation যোগ করা হয়েছে।';
                sec_log_activity($pdo, 'HR', 'Create', "নতুন Designation যোগ করা হয়েছে: {$title} ({$type})");
            }
        }
    } elseif (isset($_POST['delete_designation'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_row = $pdo->prepare("SELECT title, type FROM designations WHERE id = ?");
        $del_row->execute([$id]);
        $del_designation = $del_row->fetch();
        $pdo->prepare("DELETE FROM designations WHERE id = ?")->execute([$id]);
        $_SESSION['dg_msg'] = 'Designation মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'HR', 'Delete', "Designation মুছে ফেলা হয়েছে: " . ($del_designation['title'] ?? "#$id"));
    }

    header('Location: index.php?page=hr_designation');
    exit();
}

$view = $_GET['view'] ?? 'list';
function dgv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    // ------------------- ADD / EDIT FORM VIEW -------------------
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM designations WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: ['id' => 0, 'type' => 'Teachers', 'title' => '', 'status' => 'Active'];
    ?>
    <div class="card-custom">
        <?php if ($dg_err): ?><div class="alert alert-danger py-2"><?php echo dgv($dg_err); ?></div><?php endif; ?>

        <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;">
            <i class="fa-solid fa-file-lines text-white"></i>
        </div>

        <form method="POST" action="index.php?page=hr_designation">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Type <span class="text-danger">*</span></label>
                <div class="col-sm-8 d-flex flex-wrap gap-4 align-items-center">
                    <?php foreach ($designation_types as $t): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="type" id="type_<?php echo dgv(str_replace(' ', '_', $t)); ?>" value="<?php echo dgv($t); ?>" <?php echo $d['type'] === $t ? 'checked' : ''; ?> required>
                            <label class="form-check-label" for="type_<?php echo dgv(str_replace(' ', '_', $t)); ?>"><?php echo dgv($t); ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Title <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="text" name="title" class="form-control <?php echo ($dg_err && trim($d['title']) === '') ? 'is-invalid' : ''; ?>" value="<?php echo dgv($d['title']); ?>" placeholder="e.g. Head Teacher, Assistant Teacher" required>
                    <?php if ($dg_err && trim($d['title']) === ''): ?><div class="invalid-feedback">Title cannot be blank.</div><?php endif; ?>
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
                    <button type="submit" name="save_designation" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=hr_designation" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

<?php else: ?>
    <?php
    // ------------------- LIST VIEW -------------------
    $f_type   = $_GET['f_type'] ?? 'All';
    $f_title  = trim($_GET['f_title'] ?? '');
    $f_status = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_type !== 'All')  { $where[] = 'type = ?'; $params[] = $f_type; }
    if ($f_title !== '')    { $where[] = 'title LIKE ?'; $params[] = "%$f_title%"; }
    if ($f_status !== 'All'){ $where[] = 'status = ?'; $params[] = $f_status; }

    $sql = "SELECT * FROM designations";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY FIELD(type, 'Teachers','Staffs','Board Of Directors','Academic Committee'), title ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($dg_msg): ?><div class="alert alert-success py-2"><?php echo dgv($dg_msg); ?></div><?php endif; ?>
        <?php if ($dg_err): ?><div class="alert alert-danger py-2"><?php echo dgv($dg_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=hr_designation&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
            <span class="text-muted small">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> items.</span>
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
            <input type="hidden" name="page" value="hr_designation">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>Type</th>
                            <th>Title</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <tr>
                            <th></th>
                            <th>
                                <select name="f_type" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All" <?php echo $f_type === 'All' ? 'selected' : ''; ?>>All</option>
                                    <?php foreach ($designation_types as $t): ?>
                                        <option value="<?php echo dgv($t); ?>" <?php echo $f_type === $t ? 'selected' : ''; ?>><?php echo dgv($t); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th><input type="text" name="f_title" class="form-control form-control-sm" value="<?php echo dgv($f_title); ?>"></th>
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
                                <td><?php echo dgv($row['type']); ?></td>
                                <td><?php echo dgv($row['title']); ?></td>
                                <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=hr_designation&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                    <a href="index.php?page=hr_designation&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=hr_designation', 'delete_designation', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
<?php endif; ?>
