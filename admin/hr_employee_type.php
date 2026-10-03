<?php
// admin/hr_employee_type.php - Human Resource > HR Module > Employee Type (স্ক্রিনশট অনুযায়ী)
$et_err = $_SESSION['et_err'] ?? '';
$et_msg = $_SESSION['et_msg'] ?? '';
unset($_SESSION['et_err'], $_SESSION['et_msg']);

// employee_types টেবিল না থাকলে অটো তৈরি (ExonHost শেয়ার্ড হোস্টিং-এ আলাদা SQL রান করা লাগবে না)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS employee_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('Full-time','Part-time') NOT NULL DEFAULT 'Full-time',
        name VARCHAR(100) NOT NULL,
        deduct_salary_on_absent TINYINT(1) NOT NULL DEFAULT 0,
        for_absent_days INT NOT NULL DEFAULT 0,
        salary_deduct_days INT NOT NULL DEFAULT 0,
        has_overtime TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
// পুরনো ইনস্টলেশনে (আগের ভার্সনে বানানো টেবিলে) নতুন কলামগুলো না থাকলে অটো যোগ করা হচ্ছে।
// প্রতিটা কলাম আলাদাভাবে চেক করা হচ্ছে এবং AFTER ক্লজ ব্যবহার করা হচ্ছে না, কারণ যে
// কলামের AFTER-এ বসানোর কথা সেটাও অনেক পুরনো টেবিলে না থাকতে পারে (যেমন এই বাগেই হয়েছিল)।
// কলামের নাম কেস-ইনসেনসিটিভভাবে মেলানো (পুরনো টেবিলে `Status` থাকলে `status` ADD করতে গিয়ে Duplicate column এরর হতো)
$et_existing_cols = array_map('strtolower', $pdo->query("SHOW COLUMNS FROM employee_types")->fetchAll(PDO::FETCH_COLUMN, 0));
$et_needed_cols = [
    'type'                    => "ENUM('Full-time','Part-time') NOT NULL DEFAULT 'Full-time'",
    'name'                    => "VARCHAR(100) NOT NULL DEFAULT ''",
    'deduct_salary_on_absent' => "TINYINT(1) NOT NULL DEFAULT 0",
    'for_absent_days'         => "INT NOT NULL DEFAULT 0",
    'salary_deduct_days'      => "INT NOT NULL DEFAULT 0",
    'has_overtime'            => "TINYINT(1) NOT NULL DEFAULT 0",
    'status'                  => "ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'",
];
foreach ($et_needed_cols as $et_col => $et_def) {
    if (!in_array($et_col, $et_existing_cols, true)) {
        $pdo->exec("ALTER TABLE employee_types ADD COLUMN `$et_col` $et_def");
    }
}

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['et_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=hr_employee_type');
        exit();
    }

    if (isset($_POST['save_employee_type'])) {
        $id      = (int) ($_POST['id'] ?? 0);
        $type    = ($_POST['type'] ?? 'Full-time') === 'Part-time' ? 'Part-time' : 'Full-time';
        $name    = trim($_POST['name'] ?? '');
        $deduct  = ($_POST['deduct_salary_on_absent'] ?? 'No') === 'Yes' ? 1 : 0;
        $for_absent_days    = $deduct ? max(0, (int) ($_POST['for_absent_days'] ?? 0)) : 0;
        $salary_deduct_days = $deduct ? max(0, (int) ($_POST['salary_deduct_days'] ?? 0)) : 0;
        $has_ot  = ($_POST['has_overtime'] ?? 'No') === 'Yes' ? 1 : 0;
        $status  = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if ($name === '') {
            $_SESSION['et_err'] = 'Name খালি রাখা যাবে না।';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE employee_types SET type=?, name=?, deduct_salary_on_absent=?, for_absent_days=?, salary_deduct_days=?, has_overtime=?, status=? WHERE id=?"
                );
                $stmt->execute([$type, $name, $deduct, $for_absent_days, $salary_deduct_days, $has_ot, $status, $id]);
                $_SESSION['et_msg'] = 'Employee Type আপডেট করা হয়েছে।';
                sec_log_activity($pdo, 'HR', 'Update', "Employee Type আপডেট করা হয়েছে: {$name} ({$type})");
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO employee_types (type, name, deduct_salary_on_absent, for_absent_days, salary_deduct_days, has_overtime, status) VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([$type, $name, $deduct, $for_absent_days, $salary_deduct_days, $has_ot, $status]);
                $_SESSION['et_msg'] = 'নতুন Employee Type যোগ করা হয়েছে।';
                sec_log_activity($pdo, 'HR', 'Create', "নতুন Employee Type যোগ করা হয়েছে: {$name} ({$type})");
            }
        }
    } elseif (isset($_POST['delete_employee_type'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_row = $pdo->prepare("SELECT name FROM employee_types WHERE id = ?");
        $del_row->execute([$id]);
        $del_name = $del_row->fetchColumn();
        $pdo->prepare("DELETE FROM employee_types WHERE id = ?")->execute([$id]);
        $_SESSION['et_msg'] = 'Employee Type মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'HR', 'Delete', "Employee Type মুছে ফেলা হয়েছে: " . ($del_name ?: "#$id"));
    }

    header('Location: index.php?page=hr_employee_type');
    exit();
}

$view = $_GET['view'] ?? 'list';
function etv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    // ------------------- ADD / EDIT FORM VIEW -------------------
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM employee_types WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: ['id' => 0, 'type' => 'Full-time', 'name' => '', 'deduct_salary_on_absent' => 0, 'for_absent_days' => 0, 'salary_deduct_days' => 0, 'has_overtime' => 0, 'status' => 'Active'];
    ?>
    <div class="card-custom">
        <?php if ($et_err): ?><div class="alert alert-danger py-2"><?php echo etv($et_err); ?></div><?php endif; ?>

        <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;">
            <i class="fa-solid fa-file-lines text-white"></i>
        </div>

        <form method="POST" action="index.php?page=hr_employee_type">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Type <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="type" class="form-select" required>
                        <option value="Full-time" <?php echo $d['type'] === 'Full-time' ? 'selected' : ''; ?>>Full-time</option>
                        <option value="Part-time" <?php echo $d['type'] === 'Part-time' ? 'selected' : ''; ?>>Part-time</option>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Name <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="text" name="name" class="form-control <?php echo ($et_err && trim($d['name']) === '') ? 'is-invalid' : ''; ?>" value="<?php echo etv($d['name']); ?>" placeholder="e.g. Permanent, Contractual" required>
                    <?php if ($et_err && trim($d['name']) === ''): ?><div class="invalid-feedback">Name cannot be blank.</div><?php endif; ?>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Deduct Salary on Absent?</label>
                <div class="col-sm-6 d-flex gap-4 align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="deduct_salary_on_absent" id="dsaYes" value="Yes" <?php echo $d['deduct_salary_on_absent'] ? 'checked' : ''; ?> onchange="toggleDeductFields()">
                        <label class="form-check-label" for="dsaYes">Yes</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="deduct_salary_on_absent" id="dsaNo" value="No" <?php echo !$d['deduct_salary_on_absent'] ? 'checked' : ''; ?> onchange="toggleDeductFields()">
                        <label class="form-check-label" for="dsaNo">No</label>
                    </div>
                </div>
            </div>

            <div id="deductFieldsBox" class="row mb-3" style="<?php echo $d['deduct_salary_on_absent'] ? '' : 'display:none;'; ?>">
                <div class="col-sm-6 offset-sm-3">
                    <div class="border rounded p-3">
                        <div class="row mb-3 align-items-center">
                            <label class="col-sm-5 col-form-label">For Absent Days</label>
                            <div class="col-sm-7">
                                <input type="number" name="for_absent_days" class="form-control" min="0" value="<?php echo (int) $d['for_absent_days']; ?>" placeholder="0">
                            </div>
                        </div>
                        <div class="row align-items-center">
                            <label class="col-sm-5 col-form-label">Salary Deduct Days</label>
                            <div class="col-sm-7">
                                <input type="number" name="salary_deduct_days" class="form-control" min="0" value="<?php echo (int) $d['salary_deduct_days']; ?>" placeholder="0">
                            </div>
                        </div>
                        <div class="small text-muted mt-2">
                            যেমনঃ "For Absent Days" = 3, "Salary Deduct Days" = 1 মানে — কোনো এমপ্লয়ি মাসে ৩ দিন Absent হলে তার বেতন থেকে ১ দিনের বেতন কাটা হবে।
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Has Over Time? <span class="text-danger">*</span></label>
                <div class="col-sm-6 d-flex gap-4 align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="has_overtime" id="hotYes" value="Yes" <?php echo $d['has_overtime'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="hotYes">Yes</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="has_overtime" id="hotNo" value="No" <?php echo !$d['has_overtime'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="hotNo">No</label>
                    </div>
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
                    <button type="submit" name="save_employee_type" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=hr_employee_type" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

    <script>
        function toggleDeductFields() {
            var yesChecked = document.getElementById('dsaYes').checked;
            document.getElementById('deductFieldsBox').style.display = yesChecked ? '' : 'none';
        }
    </script>

<?php else: ?>
    <?php
    // ------------------- LIST VIEW -------------------
    $f_type   = $_GET['f_type'] ?? 'All';
    $f_name   = trim($_GET['f_name'] ?? '');
    $f_deduct = $_GET['f_deduct'] ?? 'All';
    $f_ot     = $_GET['f_ot'] ?? 'All';
    $f_status = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_type !== 'All')   { $where[] = 'type = ?'; $params[] = $f_type; }
    if ($f_name !== '')      { $where[] = 'name LIKE ?'; $params[] = "%$f_name%"; }
    if ($f_deduct !== 'All') { $where[] = 'deduct_salary_on_absent = ?'; $params[] = $f_deduct === 'Yes' ? 1 : 0; }
    if ($f_ot !== 'All')     { $where[] = 'has_overtime = ?'; $params[] = $f_ot === 'Yes' ? 1 : 0; }
    if ($f_status !== 'All') { $where[] = 'status = ?'; $params[] = $f_status; }

    $sql = "SELECT * FROM employee_types";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($et_msg): ?><div class="alert alert-success py-2"><?php echo etv($et_msg); ?></div><?php endif; ?>
        <?php if ($et_err): ?><div class="alert alert-danger py-2"><?php echo etv($et_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=hr_employee_type&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
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
            <input type="hidden" name="page" value="hr_employee_type">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>Type</th>
                            <th>Name</th>
                            <th>Deduct Salary on Absent?</th>
                            <th>Has Over Time?</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <tr>
                            <th></th>
                            <th>
                                <select name="f_type" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All" <?php echo $f_type === 'All' ? 'selected' : ''; ?>>All</option>
                                    <option value="Full-time" <?php echo $f_type === 'Full-time' ? 'selected' : ''; ?>>Full-time</option>
                                    <option value="Part-time" <?php echo $f_type === 'Part-time' ? 'selected' : ''; ?>>Part-time</option>
                                </select>
                            </th>
                            <th><input type="text" name="f_name" class="form-control form-control-sm" value="<?php echo etv($f_name); ?>"></th>
                            <th>
                                <select name="f_deduct" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All" <?php echo $f_deduct === 'All' ? 'selected' : ''; ?>>All</option>
                                    <option value="Yes" <?php echo $f_deduct === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                    <option value="No" <?php echo $f_deduct === 'No' ? 'selected' : ''; ?>>No</option>
                                </select>
                            </th>
                            <th>
                                <select name="f_ot" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All" <?php echo $f_ot === 'All' ? 'selected' : ''; ?>>All</option>
                                    <option value="Yes" <?php echo $f_ot === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                    <option value="No" <?php echo $f_ot === 'No' ? 'selected' : ''; ?>>No</option>
                                </select>
                            </th>
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
                                <td><?php echo etv($row['type']); ?></td>
                                <td><?php echo etv($row['name']); ?></td>
                                <td><?php echo $row['deduct_salary_on_absent'] ? 'Yes' : 'No'; ?></td>
                                <td><?php echo $row['has_overtime'] ? 'Yes' : 'No'; ?></td>
                                <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=hr_employee_type&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                    <a href="index.php?page=hr_employee_type&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=hr_employee_type', 'delete_employee_type', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
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
