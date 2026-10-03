<?php
// admin/hr_working_shift.php - HR & Payrolls > Working Shift (স্ক্রিনশট অনুযায়ী)
// এটা employee-দের জন্য আলাদা শিফট টেবিল (academic_shifts স্টুডেন্টদের জন্য, এটা কর্মচারীদের জন্য)
$ws_err = $_SESSION['ws_err'] ?? '';
$ws_msg = $_SESSION['ws_msg'] ?? '';
unset($_SESSION['ws_err'], $_SESSION['ws_msg']);

// employee_shifts টেবিল না থাকলে অটো তৈরি
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS employee_shifts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        green_limit INT NOT NULL DEFAULT 0,
        orange_limit INT NOT NULL DEFAULT 0,
        red_limit INT NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// পুরনো ইনস্টলেশনে (আগের ভার্সনে বানানো টেবিলে) নতুন কলামগুলো না থাকলে অটো যোগ করা হচ্ছে
$ws_existing_cols = $pdo->query("SHOW COLUMNS FROM employee_shifts")->fetchAll(PDO::FETCH_COLUMN, 0);
$ws_needed_cols = [
    'start_time'   => "TIME NOT NULL DEFAULT '09:00:00'",
    'end_time'     => "TIME NOT NULL DEFAULT '17:00:00'",
    'green_limit'  => "INT NOT NULL DEFAULT 0",
    'orange_limit' => "INT NOT NULL DEFAULT 0",
    'red_limit'    => "INT NOT NULL DEFAULT 0",
    'status'       => "ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'",
];
foreach ($ws_needed_cols as $ws_col => $ws_def) {
    if (!in_array($ws_col, $ws_existing_cols, true)) {
        $pdo->exec("ALTER TABLE employee_shifts ADD COLUMN `$ws_col` $ws_def");
    }
}

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ws_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=hr_working_shift');
        exit();
    }

    if (isset($_POST['save_shift'])) {
        $id           = (int) ($_POST['id'] ?? 0);
        $name         = trim($_POST['name'] ?? '');
        $start_time   = trim($_POST['start_time'] ?? '');
        $end_time     = trim($_POST['end_time'] ?? '');
        $green_limit  = (int) ($_POST['green_limit'] ?? 0);
        $orange_limit = (int) ($_POST['orange_limit'] ?? 0);
        $red_limit    = (int) ($_POST['red_limit'] ?? 0);
        $status       = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if ($name === '' || $start_time === '' || $end_time === '') {
            $_SESSION['ws_err'] = 'Name, Start Time, End Time — এই ফিল্ডগুলো আবশ্যক।';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE employee_shifts SET name=?, start_time=?, end_time=?, green_limit=?, orange_limit=?, red_limit=?, status=? WHERE id=?"
                );
                $stmt->execute([$name, $start_time, $end_time, $green_limit, $orange_limit, $red_limit, $status, $id]);
                $_SESSION['ws_msg'] = 'Working Shift আপডেট করা হয়েছে।';
                sec_log_activity($pdo, 'HR', 'Update', "Working Shift আপডেট করা হয়েছে: {$name}");
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO employee_shifts (name, start_time, end_time, green_limit, orange_limit, red_limit, status) VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([$name, $start_time, $end_time, $green_limit, $orange_limit, $red_limit, $status]);
                $_SESSION['ws_msg'] = 'নতুন Working Shift যোগ করা হয়েছে।';
                sec_log_activity($pdo, 'HR', 'Create', "নতুন Working Shift যোগ করা হয়েছে: {$name}");
            }
        }
    } elseif (isset($_POST['delete_shift'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_row = $pdo->prepare("SELECT name FROM employee_shifts WHERE id = ?");
        $del_row->execute([$id]);
        $del_name = $del_row->fetchColumn();
        $pdo->prepare("DELETE FROM employee_shifts WHERE id = ?")->execute([$id]);
        $_SESSION['ws_msg'] = 'Working Shift মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'HR', 'Delete', "Working Shift মুছে ফেলা হয়েছে: " . ($del_name ?: "#$id"));
    }

    header('Location: index.php?page=hr_working_shift');
    exit();
}

$view = $_GET['view'] ?? 'list';
function wsv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function ws_time12($t) { return $t ? date('h:i A', strtotime($t)) : ''; }
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    // ------------------- ADD / EDIT FORM VIEW -------------------
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM employee_shifts WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: ['id' => 0, 'name' => '', 'start_time' => '', 'end_time' => '', 'green_limit' => '', 'orange_limit' => '', 'red_limit' => '', 'status' => 'Active'];
    ?>
    <div class="card-custom">
        <?php if ($ws_err): ?><div class="alert alert-danger py-2"><?php echo wsv($ws_err); ?></div><?php endif; ?>

        <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;">
            <i class="fa-solid fa-file-lines text-white"></i>
        </div>

        <form method="POST" action="index.php?page=hr_working_shift">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Name <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="name" class="form-control" value="<?php echo wsv($d['name']); ?>" placeholder="e.g. Morning, General" required></div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Start Time <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="fa-regular fa-calendar"></i></span>
                        <input type="time" name="start_time" class="form-control" value="<?php echo wsv($d['start_time']); ?>" placeholder="Click here to add shift start time" required>
                    </div>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">End Time <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="fa-regular fa-calendar"></i></span>
                        <input type="time" name="end_time" class="form-control" value="<?php echo wsv($d['end_time']); ?>" placeholder="Click here to add shift ends time" required>
                    </div>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Green Limit <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="number" name="green_limit" min="0" class="form-control" value="<?php echo wsv($d['green_limit']); ?>" placeholder="মিনিটে, যেমনঃ 5" required>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Orange Limit <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="number" name="orange_limit" min="0" class="form-control" value="<?php echo wsv($d['orange_limit']); ?>" placeholder="মিনিটে, যেমনঃ 10" required>
                </div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Red Limit <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="number" name="red_limit" min="0" class="form-control" value="<?php echo wsv($d['red_limit']); ?>" placeholder="মিনিটে, যেমনঃ 15" required>
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
                    <button type="submit" name="save_shift" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=hr_working_shift" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

<?php else: ?>
    <?php
    // ------------------- LIST VIEW -------------------
    $f_name   = trim($_GET['f_name'] ?? '');
    $f_status = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_name !== '')   { $where[] = 'name LIKE ?'; $params[] = "%$f_name%"; }
    if ($f_status !== 'All') { $where[] = 'status = ?'; $params[] = $f_status; }

    $sql = "SELECT * FROM employee_shifts";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY start_time ASC, id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($ws_msg): ?><div class="alert alert-success py-2"><?php echo wsv($ws_msg); ?></div><?php endif; ?>
        <?php if ($ws_err): ?><div class="alert alert-danger py-2"><?php echo wsv($ws_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=hr_working_shift&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
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
            <input type="hidden" name="page" value="hr_working_shift">
            <div class="table-responsive">
                <table class="table table-bordered align-middle text-center">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>Name</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th style="color:#10b981;">Green</th>
                            <th style="color:#f59e0b;">Orange</th>
                            <th style="color:#ef4444;">Red</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <tr>
                            <th></th>
                            <th><input type="text" name="f_name" class="form-control form-control-sm" value="<?php echo wsv($f_name); ?>"></th>
                            <th colspan="4"></th>
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
                                <td class="text-start"><?php echo wsv($row['name']); ?></td>
                                <td><?php echo wsv(ws_time12($row['start_time'])); ?></td>
                                <td><?php echo wsv(ws_time12($row['end_time'])); ?></td>
                                <td><?php echo (int) $row['green_limit']; ?> min</td>
                                <td><?php echo (int) $row['orange_limit']; ?> min</td>
                                <td><?php echo (int) $row['red_limit']; ?> min</td>
                                <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=hr_working_shift&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                    <a href="index.php?page=hr_working_shift&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=hr_working_shift', 'delete_shift', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="9" class="text-center text-muted py-3">No data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
<?php endif; ?>
