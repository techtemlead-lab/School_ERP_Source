<?php
// admin/payroll_employee_payscale.php - Human Resource > Payroll Module > Employee Payscale (স্ক্রিনশট অনুযায়ী)
$ps_err = $_SESSION['ps_err'] ?? '';
$ps_msg = $_SESSION['ps_msg'] ?? '';
unset($_SESSION['ps_err'], $_SESSION['ps_msg']);

// ---------------------------------------------------------------
// টেবিলগুলো না থাকলে অটো তৈরি
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS payscale_grades (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
// প্রথমবার টেবিল খালি থাকলে কিছু ডিফল্ট গ্রেড সিড করা
if ((int) $pdo->query("SELECT COUNT(*) FROM payscale_grades")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO payscale_grades (name) VALUES ('Grade-1'),('Grade-2'),('Grade-3'),('Grade-4'),('Grade-5')");
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS payroll_payscales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NOT NULL,
        employee_type_id INT NOT NULL,
        grade_id INT NOT NULL,
        basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS payroll_payscale_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        payscale_id INT NOT NULL,
        payroll_head_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        UNIQUE KEY uniq_payscale_head (payscale_id, payroll_head_id),
        CONSTRAINT fk_ppi_payscale FOREIGN KEY (payscale_id) REFERENCES payroll_payscales(id) ON DELETE CASCADE,
        CONSTRAINT fk_ppi_head FOREIGN KEY (payroll_head_id) REFERENCES payroll_heads(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ps_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=payroll_employee_payscale');
        exit();
    }

    if (isset($_POST['save_payscale'])) {
        $id               = (int) ($_POST['id'] ?? 0);
        $title            = trim($_POST['title'] ?? '');
        $employee_type_id = (int) ($_POST['employee_type_id'] ?? 0);
        $grade_id         = (int) ($_POST['grade_id'] ?? 0);
        $basic_salary     = (float) ($_POST['basic_salary'] ?? 0);
        $status           = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
        $amounts          = $_POST['amount'] ?? []; // [payroll_head_id => amount]

        if ($title === '' || $employee_type_id <= 0 || $grade_id <= 0) {
            $_SESSION['ps_err'] = 'Pay Scale Title, Employee Type এবং Grade অবশ্যই দিতে হবে।';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE payroll_payscales SET title=?, employee_type_id=?, grade_id=?, basic_salary=?, status=? WHERE id=?");
                $stmt->execute([$title, $employee_type_id, $grade_id, $basic_salary, $status, $id]);
                $_SESSION['ps_msg'] = 'Payscale আপডেট করা হয়েছে।';
                sec_log_activity($pdo, 'Payroll', 'Update', "Payscale আপডেট করা হয়েছে: {$title} (Basic ৳" . number_format($basic_salary, 2) . ")");
            } else {
                $stmt = $pdo->prepare("INSERT INTO payroll_payscales (title, employee_type_id, grade_id, basic_salary, status) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$title, $employee_type_id, $grade_id, $basic_salary, $status]);
                $id = (int) $pdo->lastInsertId();
                $_SESSION['ps_msg'] = 'নতুন Payscale যোগ করা হয়েছে।';
                sec_log_activity($pdo, 'Payroll', 'Create', "নতুন Payscale যোগ করা হয়েছে: {$title} (Basic ৳" . number_format($basic_salary, 2) . ")");
            }

            // Chart Information — প্রতিটা payroll head-এর amount আপসার্ট করা
            if ($id > 0 && is_array($amounts)) {
                $up = $pdo->prepare("INSERT INTO payroll_payscale_items (payscale_id, payroll_head_id, amount) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)");
                foreach ($amounts as $head_id => $amt) {
                    $head_id = (int) $head_id;
                    $amt = (float) $amt;
                    if ($head_id > 0) {
                        $up->execute([$id, $head_id, $amt]);
                    }
                }
            }
        }
    } elseif (isset($_POST['delete_payscale'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_row = $pdo->prepare("SELECT title FROM payroll_payscales WHERE id = ?");
        $del_row->execute([$id]);
        $del_title = $del_row->fetchColumn();
        $pdo->prepare("DELETE FROM payroll_payscales WHERE id = ?")->execute([$id]);
        $_SESSION['ps_msg'] = 'Payscale মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Payroll', 'Delete', "Payscale মুছে ফেলা হয়েছে: " . ($del_title ?: "#$id"));
    }

    header('Location: index.php?page=payroll_employee_payscale');
    exit();
}

$view = $_GET['view'] ?? 'list';
function psv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    // ------------------- ADD / EDIT FORM VIEW -------------------
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM payroll_payscales WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $p = $edit_row ?: [
        'id' => 0, 'title' => '', 'employee_type_id' => 0, 'grade_id' => 0,
        'basic_salary' => '', 'status' => 'Active',
    ];

    $employee_types = $pdo->query("SELECT id, name FROM employee_types WHERE status = 'Active' ORDER BY name ASC")->fetchAll();
    $grades = $pdo->query("SELECT id, name FROM payscale_grades WHERE status = 'Active' ORDER BY id ASC")->fetchAll();
    $payroll_heads = $pdo->query("SELECT id, ac_code, ac_head, category FROM payroll_heads ORDER BY ac_code ASC")->fetchAll();

    // এডিটের সময় আগের সেভ করা amount গুলো লোড করা
    $existing_amounts = [];
    if ((int) $p['id'] > 0) {
        $ai = $pdo->prepare("SELECT payroll_head_id, amount FROM payroll_payscale_items WHERE payscale_id = ?");
        $ai->execute([$p['id']]);
        foreach ($ai->fetchAll() as $r) { $existing_amounts[(int) $r['payroll_head_id']] = $r['amount']; }
    }
    ?>
    <div class="card-custom">
        <?php if ($ps_err): ?><div class="alert alert-danger py-2"><?php echo psv($ps_err); ?></div><?php endif; ?>

        <div class="bg-warning bg-opacity-75 p-2 mb-3" style="width:40px;border-radius:4px;">
            <i class="fa-solid fa-bars text-white"></i>
        </div>

        <form method="POST" action="index.php?page=payroll_employee_payscale" id="payscaleForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">

            <!-- Tabs -->
            <ul class="nav nav-tabs border-0 mb-0" id="psTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active d-flex align-items-center gap-2 fw-bold" id="tab-general-btn" type="button" data-tab="general">
                        <i class="fa-solid fa-user"></i> General Information
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link d-flex align-items-center gap-2 fw-bold text-primary" id="tab-chart-btn" type="button" data-tab="chart">
                        <i class="fa-solid fa-file-lines"></i> Chart Information
                    </button>
                </li>
            </ul>

            <div class="border p-4" id="ps-pane-general">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label text-end">Pay Scale Title <span class="text-danger">*</span></label>
                    <div class="col-sm-6">
                        <input type="text" name="title" class="form-control <?php echo ($ps_err && trim($p['title']) === '') ? 'is-invalid' : ''; ?>" value="<?php echo psv($p['title']); ?>" placeholder="e.g. Senior Teacher Scale" required>
                    </div>
                </div>

                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label text-end">Employee Type <span class="text-danger">*</span></label>
                    <div class="col-sm-6">
                        <select name="employee_type_id" class="form-select" required>
                            <option value="">Select Employee type</option>
                            <?php foreach ($employee_types as $et): ?>
                                <option value="<?php echo (int) $et['id']; ?>" <?php echo ((int) $p['employee_type_id'] === (int) $et['id']) ? 'selected' : ''; ?>><?php echo psv($et['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$employee_types): ?><small class="text-danger">প্রথমে "Employee Type" পেজ থেকে অন্তত একটা টাইপ যোগ করুন।</small><?php endif; ?>
                    </div>
                </div>

                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label text-end">Grade <span class="text-danger">*</span></label>
                    <div class="col-sm-6">
                        <select name="grade_id" class="form-select" required>
                            <option value="">Select Employee Grade</option>
                            <?php foreach ($grades as $g): ?>
                                <option value="<?php echo (int) $g['id']; ?>" <?php echo ((int) $p['grade_id'] === (int) $g['id']) ? 'selected' : ''; ?>><?php echo psv($g['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label text-end">Basic Salary <span class="text-danger">*</span></label>
                    <div class="col-sm-6">
                        <input type="number" step="0.01" min="0" name="basic_salary" class="form-control" value="<?php echo psv($p['basic_salary']); ?>" placeholder="0.00" required>
                    </div>
                </div>

                <div class="row mb-1 align-items-center">
                    <label class="col-sm-3 col-form-label text-end">Status <span class="text-danger">*</span></label>
                    <div class="col-sm-6">
                        <select name="status" class="form-select">
                            <option value="Active" <?php echo $p['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo $p['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="border p-4 d-none" id="ps-pane-chart">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Fee Type</th>
                                <th>Head Type</th>
                                <th style="width:200px;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($payroll_heads): foreach ($payroll_heads as $h): ?>
                                <tr>
                                    <td><?php echo psv($h['ac_code'] . ' ' . $h['ac_head']); ?></td>
                                    <td><?php echo $h['category'] === 'Deduction' ? '<span class="text-danger fw-bold">Deduction</span>' : '<span class="text-success fw-bold">Addition</span>'; ?></td>
                                    <td>
                                        <input type="number" step="0.01" min="0" name="amount[<?php echo (int) $h['id']; ?>]" class="form-control" value="<?php echo psv($existing_amounts[(int) $h['id']] ?? '0.00'); ?>">
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="3" class="text-center text-muted py-3">কোনো Payroll Head পাওয়া যায়নি। আগে "Payroll Head" পেজ থেকে হেড যোগ করুন।</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    <button type="submit" name="save_payscale" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=payroll_employee_payscale" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

    <script>
    (function () {
        var btns = document.querySelectorAll('#psTabs .nav-link');
        var panes = { general: document.getElementById('ps-pane-general'), chart: document.getElementById('ps-pane-chart') };
        btns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                btns.forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                Object.keys(panes).forEach(function (k) { panes[k].classList.add('d-none'); });
                panes[btn.getAttribute('data-tab')].classList.remove('d-none');
            });
        });
    })();
    </script>

<?php else: ?>
    <?php
    // ------------------- LIST VIEW -------------------
    $f_title  = trim($_GET['f_title'] ?? '');
    $f_status = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_title !== '')     { $where[] = 'p.title LIKE ?'; $params[] = "%$f_title%"; }
    if ($f_status !== 'All') { $where[] = 'p.status = ?'; $params[] = $f_status; }

    $sql = "SELECT p.*, et.name AS employee_type_name, g.name AS grade_name
            FROM payroll_payscales p
            LEFT JOIN employee_types et ON et.id = p.employee_type_id
            LEFT JOIN payscale_grades g ON g.id = p.grade_id";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY p.title ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($ps_msg): ?><div class="alert alert-success py-2"><?php echo psv($ps_msg); ?></div><?php endif; ?>
        <?php if ($ps_err): ?><div class="alert alert-danger py-2"><?php echo psv($ps_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=payroll_employee_payscale&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
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
            <input type="hidden" name="page" value="payroll_employee_payscale">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>Pay Scale Title</th>
                            <th>Employee Type</th>
                            <th>Grade</th>
                            <th>Basic Salary</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <tr>
                            <th></th>
                            <th><input type="text" name="f_title" class="form-control form-control-sm" value="<?php echo psv($f_title); ?>"></th>
                            <th></th>
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
                                <td><?php echo psv($row['title']); ?></td>
                                <td><?php echo psv($row['employee_type_name'] ?? '—'); ?></td>
                                <td><?php echo psv($row['grade_name'] ?? '—'); ?></td>
                                <td><?php echo number_format((float) $row['basic_salary'], 2); ?></td>
                                <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=payroll_employee_payscale&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                    <a href="index.php?page=payroll_employee_payscale&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=payroll_employee_payscale', 'delete_payscale', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
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
