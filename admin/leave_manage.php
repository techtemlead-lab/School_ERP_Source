<?php
// admin/leave_manage.php - Leave Module > Leave Registers > Manage Leave Register (স্ক্রিনশট অনুযায়ী)
$lm_msg = $_SESSION['lm_msg'] ?? '';
$lm_err = $_SESSION['lm_err'] ?? '';
unset($_SESSION['lm_msg'], $_SESSION['lm_err']);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS leave_registers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_type_id INT NULL,
        employee_id INT NOT NULL,
        leave_type_id INT NULL,
        from_date DATE NOT NULL,
        to_date DATE NOT NULL,
        total_days INT NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
// leave_types টেবিল না থাকলে অটো তৈরি (এই পেজ সরাসরি খুললে "Leave Types" পেজ আগে visit
// না করলেও যাতে crash না করে — leave_types.php-এর মতো একই ডেফিনিশন)
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_leave'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['lm_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $del_stmt = $pdo->prepare(
            "SELECT e.name AS employee_name, lr.from_date, lr.to_date FROM leave_registers lr LEFT JOIN employees e ON e.id = lr.employee_id WHERE lr.id = ?"
        );
        $del_stmt->execute([$id]);
        $del_row = $del_stmt->fetch();
        $pdo->prepare("DELETE FROM leave_registers WHERE id = ?")->execute([$id]);
        sec_log_activity($pdo, 'Leave', 'Delete', "Leave Entry মুছে ফেলা হয়েছে: " . ($del_row['employee_name'] ?? "#$id") . " (" . ($del_row['from_date'] ?? '') . " - " . ($del_row['to_date'] ?? '') . ")");
        $_SESSION['lm_msg'] = 'Leave Entry মুছে ফেলা হয়েছে।';
    }
    header('Location: index.php?page=leave_manage');
    exit();
}

function lmv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$employee_types = $pdo->query("SELECT id, name FROM employee_types WHERE status = 'Active' ORDER BY name ASC")->fetchAll();
$leave_types_all = $pdo->query("SELECT id, title FROM leave_types WHERE status = 'Active' ORDER BY title ASC")->fetchAll();
$employees_all = $pdo->query("SELECT id, name, employee_id FROM employees WHERE is_current = 1 ORDER BY name ASC")->fetchAll();

$f_etype = (int) ($_GET['f_etype'] ?? 0);
$f_emp   = (int) ($_GET['f_emp'] ?? 0);
$f_ltype = (int) ($_GET['f_ltype'] ?? 0);
$f_from  = trim($_GET['f_from'] ?? '');
$f_to    = trim($_GET['f_to'] ?? '');
$f_days  = trim($_GET['f_days'] ?? '');

$where = [];
$params = [];
if ($f_etype > 0) { $where[] = 'lr.employee_type_id = ?'; $params[] = $f_etype; }
if ($f_emp > 0)   { $where[] = 'lr.employee_id = ?';      $params[] = $f_emp; }
if ($f_ltype > 0) { $where[] = 'lr.leave_type_id = ?';    $params[] = $f_ltype; }
if ($f_from !== '') { $where[] = 'lr.from_date >= ?'; $params[] = $f_from; }
if ($f_to !== '')   { $where[] = 'lr.to_date <= ?';   $params[] = $f_to; }
if ($f_days !== '')  { $where[] = 'lr.total_days = ?'; $params[] = (int) $f_days; }

$sql = "SELECT lr.*, et.name AS employee_type_name, e.name AS employee_name, e.employee_id AS employee_code, lt.title AS leave_type_title
        FROM leave_registers lr
        LEFT JOIN employee_types et ON et.id = lr.employee_type_id
        LEFT JOIN employees e ON e.id = lr.employee_id
        LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY lr.from_date DESC, lr.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<div class="card-custom">
    <?php if ($lm_msg): ?><div class="alert alert-success py-2"><?php echo lmv($lm_msg); ?></div><?php endif; ?>
    <?php if ($lm_err): ?><div class="alert alert-danger py-2"><?php echo lmv($lm_err); ?></div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="index.php?page=leave_entry" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
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
        <input type="hidden" name="page" value="leave_manage">
        <div class="table-responsive">
            <table class="table table-bordered align-middle" style="font-size:13px;">
                <thead class="table-light">
                    <tr>
                        <th>Srl.</th>
                        <th>Employee Type</th>
                        <th>Employee</th>
                        <th>Leave Type</th>
                        <th>From Date</th>
                        <th>End Date</th>
                        <th>Total Days</th>
                        <th>Actions</th>
                    </tr>
                    <tr>
                        <th></th>
                        <th>
                            <select name="f_etype" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="0">All</option>
                                <?php foreach ($employee_types as $et): ?>
                                    <option value="<?php echo (int) $et['id']; ?>" <?php echo $f_etype === (int) $et['id'] ? 'selected' : ''; ?>><?php echo lmv($et['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th>
                            <select name="f_emp" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="0">All</option>
                                <?php foreach ($employees_all as $emp): ?>
                                    <option value="<?php echo (int) $emp['id']; ?>" <?php echo $f_emp === (int) $emp['id'] ? 'selected' : ''; ?>><?php echo lmv($emp['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th>
                            <select name="f_ltype" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="0">All</option>
                                <?php foreach ($leave_types_all as $lt): ?>
                                    <option value="<?php echo (int) $lt['id']; ?>" <?php echo $f_ltype === (int) $lt['id'] ? 'selected' : ''; ?>><?php echo lmv($lt['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th><input type="date" name="f_from" class="form-control form-control-sm" value="<?php echo lmv($f_from); ?>"></th>
                        <th><input type="date" name="f_to" class="form-control form-control-sm" value="<?php echo lmv($f_to); ?>"></th>
                        <th><input type="text" name="f_days" class="form-control form-control-sm" value="<?php echo lmv($f_days); ?>"></th>
                        <th><button type="submit" class="btn btn-sm btn-outline-primary">Filter</button></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo lmv($row['employee_type_name'] ?: '-'); ?></td>
                            <td><?php echo lmv(($row['employee_code'] ? $row['employee_code'] . ' - ' : '') . ($row['employee_name'] ?: '-')); ?></td>
                            <td><?php echo lmv($row['leave_type_title'] ?: '-'); ?></td>
                            <td><?php echo lmv(date('d-M-Y', strtotime($row['from_date']))); ?></td>
                            <td><?php echo lmv(date('d-M-Y', strtotime($row['to_date']))); ?></td>
                            <td><?php echo (int) $row['total_days']; ?></td>
                            <td class="text-nowrap">
                                <a href="index.php?page=leave_entry&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                <a href="index.php?page=leave_entry&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=leave_manage', 'delete_leave', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="8" class="text-center text-muted py-3">No result found !</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>
