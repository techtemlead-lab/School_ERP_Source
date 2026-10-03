<?php
// admin/leave_types.php - Leave Module > Leave Types (স্ক্রিনশট অনুযায়ী)
$lt_err = $_SESSION['lt_err'] ?? '';
$lt_msg = $_SESSION['lt_msg'] ?? '';
unset($_SESSION['lt_err'], $_SESSION['lt_msg']);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS leave_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_type_id INT NULL,
        title VARCHAR(150) NOT NULL,
        short_title VARCHAR(30) NOT NULL,
        leave_days INT NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['lt_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=leave_types');
        exit();
    }

    if (isset($_POST['save_leave_type'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $employee_type_id = (int) ($_POST['employee_type_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $short_title = trim($_POST['short_title'] ?? '');
        $leave_days = (int) ($_POST['leave_days'] ?? 0);
        $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if ($employee_type_id <= 0 || $title === '' || $short_title === '' || $leave_days <= 0) {
            $_SESSION['lt_err'] = 'Employee Type, Title, Short Title, Leave Days — এই ফিল্ডগুলো আবশ্যক।';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE leave_types SET employee_type_id=?, title=?, short_title=?, leave_days=?, status=? WHERE id=?");
                $stmt->execute([$employee_type_id, $title, $short_title, $leave_days, $status, $id]);
                sec_log_activity($pdo, 'Leave', 'Update', "Leave Type আপডেট করা হয়েছে: {$title} ({$short_title}), {$leave_days} দিন/বছর");
                $_SESSION['lt_msg'] = 'Leave Type আপডেট করা হয়েছে।';
            } else {
                $stmt = $pdo->prepare("INSERT INTO leave_types (employee_type_id, title, short_title, leave_days, status) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$employee_type_id, $title, $short_title, $leave_days, $status]);
                sec_log_activity($pdo, 'Leave', 'Create', "নতুন Leave Type যোগ করা হয়েছে: {$title} ({$short_title}), {$leave_days} দিন/বছর");
                $_SESSION['lt_msg'] = 'নতুন Leave Type যোগ করা হয়েছে।';
            }
        }
    } elseif (isset($_POST['delete_leave_type'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_title_stmt = $pdo->prepare("SELECT title FROM leave_types WHERE id = ?");
        $del_title_stmt->execute([$id]);
        $del_title = $del_title_stmt->fetchColumn();
        $pdo->prepare("DELETE FROM leave_types WHERE id = ?")->execute([$id]);
        sec_log_activity($pdo, 'Leave', 'Delete', "Leave Type মুছে ফেলা হয়েছে: " . ($del_title ?: "#$id"));
        $_SESSION['lt_msg'] = 'Leave Type মুছে ফেলা হয়েছে।';
    }

    header('Location: index.php?page=leave_types');
    exit();
}

$view = $_GET['view'] ?? 'list';
function ltv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
$employee_types = $pdo->query("SELECT id, name FROM employee_types WHERE status = 'Active' ORDER BY name ASC")->fetchAll();
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    $edit_row = null;
    if ($view === 'edit') {
        $stmt = $pdo->prepare("SELECT * FROM leave_types WHERE id = ?");
        $stmt->execute([(int) ($_GET['id'] ?? 0)]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: ['id' => 0, 'employee_type_id' => '', 'title' => '', 'short_title' => '', 'leave_days' => '', 'status' => 'Active'];
    ?>
    <div class="card-custom">
        <?php if ($lt_err): ?><div class="alert alert-danger py-2"><?php echo ltv($lt_err); ?></div><?php endif; ?>

        <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;">
            <i class="fa-solid fa-file-lines text-white"></i>
        </div>

        <form method="POST" action="index.php?page=leave_types">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Employee Type <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="employee_type_id" class="form-select" required>
                        <option value="">Select Employee Type</option>
                        <?php foreach ($employee_types as $et): ?>
                            <option value="<?php echo (int) $et['id']; ?>" <?php echo (int) $d['employee_type_id'] === (int) $et['id'] ? 'selected' : ''; ?>><?php echo ltv($et['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Title <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="title" class="form-control" value="<?php echo ltv($d['title']); ?>" placeholder="e.g. Casual Leave" required></div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Short Title <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="short_title" class="form-control" value="<?php echo ltv($d['short_title']); ?>" placeholder="e.g. CL" maxlength="30" required></div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Leave Days <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="number" name="leave_days" min="1" class="form-control" value="<?php echo ltv($d['leave_days']); ?>" placeholder="বছরে মোট কতদিন" required></div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Status</label>
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
                    <button type="submit" name="save_leave_type" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=leave_types" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

<?php else: ?>
    <?php
    $f_etype = (int) ($_GET['f_etype'] ?? 0);
    $f_title = trim($_GET['f_title'] ?? '');
    $f_status = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_etype > 0) { $where[] = 'lt.employee_type_id = ?'; $params[] = $f_etype; }
    if ($f_title !== '') { $where[] = 'lt.title LIKE ?'; $params[] = "%$f_title%"; }
    if ($f_status !== 'All') { $where[] = 'lt.status = ?'; $params[] = $f_status; }

    $sql = "SELECT lt.*, et.name AS employee_type_name FROM leave_types lt LEFT JOIN employee_types et ON et.id = lt.employee_type_id";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY lt.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($lt_msg): ?><div class="alert alert-success py-2"><?php echo ltv($lt_msg); ?></div><?php endif; ?>
        <?php if ($lt_err): ?><div class="alert alert-danger py-2"><?php echo ltv($lt_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=leave_types&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
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
            <input type="hidden" name="page" value="leave_types">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>Employee Type</th>
                            <th>Title</th>
                            <th>Short Title</th>
                            <th>Leave Days</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <tr>
                            <th></th>
                            <th>
                                <select name="f_etype" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="0">All</option>
                                    <?php foreach ($employee_types as $et): ?>
                                        <option value="<?php echo (int) $et['id']; ?>" <?php echo $f_etype === (int) $et['id'] ? 'selected' : ''; ?>><?php echo ltv($et['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th><input type="text" name="f_title" class="form-control form-control-sm" value="<?php echo ltv($f_title); ?>"></th>
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
                        <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td><?php echo ltv($row['employee_type_name'] ?: '-'); ?></td>
                                <td><?php echo ltv($row['title']); ?></td>
                                <td><?php echo ltv($row['short_title']); ?></td>
                                <td><?php echo (int) $row['leave_days']; ?></td>
                                <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=leave_types&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                    <a href="index.php?page=leave_types&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=leave_types', 'delete_leave_type', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">No data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
<?php endif; ?>
