<?php
// admin/leave_entry.php - Leave Module > Leave Registers > Leave Entry (স্ক্রিনশট অনুযায়ী)
$le_err = $_SESSION['le_err'] ?? '';
unset($_SESSION['le_err']);

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_leave_entry'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['le_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=leave_entry');
        exit();
    }

    $id = (int) ($_POST['id'] ?? 0);
    $employee_type_id = (int) ($_POST['employee_type_id'] ?? 0);
    $employee_id = (int) ($_POST['employee_id'] ?? 0);
    $leave_type_id = (int) ($_POST['leave_type_id'] ?? 0);
    $from_date = trim($_POST['from_date'] ?? '');
    $to_date = trim($_POST['to_date'] ?? '');
    $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    if ($employee_type_id <= 0 || $employee_id <= 0 || $leave_type_id <= 0 || $from_date === '' || $to_date === '') {
        $_SESSION['le_err'] = 'Employee Type, Employee, Leave Type, From Date, End Date — এই ফিল্ডগুলো আবশ্যক।';
    } elseif (strtotime($to_date) < strtotime($from_date)) {
        $_SESSION['le_err'] = 'End Date অবশ্যই From Date-এর সমান বা তার পরে হতে হবে।';
    } else {
        $total_days = (int) ((strtotime($to_date) - strtotime($from_date)) / 86400) + 1;

        $emp_name_stmt = $pdo->prepare("SELECT name FROM employees WHERE id = ?");
        $emp_name_stmt->execute([$employee_id]);
        $emp_name = $emp_name_stmt->fetchColumn() ?: "#$employee_id";
        $lt_title_stmt = $pdo->prepare("SELECT title FROM leave_types WHERE id = ?");
        $lt_title_stmt->execute([$leave_type_id]);
        $lt_title = $lt_title_stmt->fetchColumn() ?: '';

        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE leave_registers SET employee_type_id=?, employee_id=?, leave_type_id=?, from_date=?, to_date=?, total_days=?, status=? WHERE id=?"
            );
            $stmt->execute([$employee_type_id, $employee_id, $leave_type_id, $from_date, $to_date, $total_days, $status, $id]);
            sec_log_activity($pdo, 'Leave', 'Update', "Leave Entry আপডেট করা হয়েছে: {$emp_name} ({$lt_title}), {$from_date} থেকে {$to_date} ({$total_days} দিন)");
            $_SESSION['lm_msg'] = 'Leave Entry আপডেট করা হয়েছে।';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO leave_registers (employee_type_id, employee_id, leave_type_id, from_date, to_date, total_days, status) VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$employee_type_id, $employee_id, $leave_type_id, $from_date, $to_date, $total_days, $status]);
            sec_log_activity($pdo, 'Leave', 'Create', "নতুন Leave Entry যোগ করা হয়েছে: {$emp_name} ({$lt_title}), {$from_date} থেকে {$to_date} ({$total_days} দিন)");
            $_SESSION['lm_msg'] = 'নতুন Leave Entry যোগ করা হয়েছে।';
        }
        header('Location: index.php?page=leave_manage');
        exit();
    }
    header('Location: index.php?page=leave_entry' . ($id ? '&id=' . $id : ''));
    exit();
}

function lev($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$employee_types = $pdo->query("SELECT id, name FROM employee_types WHERE status = 'Active' ORDER BY name ASC")->fetchAll();

$edit_id = (int) ($_GET['id'] ?? 0);
$edit_row = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM leave_registers WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_row = $stmt->fetch();
}
$preselect_type = (int) ($_GET['preselect_type'] ?? 0);
$d = $edit_row ?: ['id' => 0, 'employee_type_id' => $preselect_type ?: '', 'employee_id' => '', 'leave_type_id' => '', 'from_date' => '', 'to_date' => '', 'total_days' => '', 'status' => 'Active'];

// এডিট বা টাইপ-প্রিসিলেক্টের সময় employee/leave_type ড্রপডাউন প্রি-লোড করতে employee_type অনুযায়ী লিস্ট বানানো
$employees = [];
$leave_types = [];
if ($d['employee_type_id']) {
    $stmt = $pdo->prepare("SELECT id, name, employee_id FROM employees WHERE employee_type_id = ? AND is_current = 1 ORDER BY name ASC");
    $stmt->execute([$d['employee_type_id']]);
    $employees = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, title, leave_days FROM leave_types WHERE employee_type_id = ? AND status = 'Active' ORDER BY title ASC");
    $stmt->execute([$d['employee_type_id']]);
    $leave_types = $stmt->fetchAll();
}
?>
<div class="card-custom">
    <?php if ($le_err): ?><div class="alert alert-danger py-2"><?php echo lev($le_err); ?></div><?php endif; ?>

    <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;">
        <i class="fa-solid fa-file-lines text-white"></i>
    </div>

    <form method="POST" action="index.php?page=leave_entry<?php echo $edit_id ? '&id=' . $edit_id : ''; ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Employee Type <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="employee_type_id" id="employeeTypeSelect" class="form-select" required onchange="reloadForType()">
                    <option value="">Select Employee Type</option>
                    <?php foreach ($employee_types as $et): ?>
                        <option value="<?php echo (int) $et['id']; ?>" <?php echo (int) $d['employee_type_id'] === (int) $et['id'] ? 'selected' : ''; ?>><?php echo lev($et['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Employee <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="employee_id" id="employeeSelect" class="form-select" required>
                    <option value="">Select Employee</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo (int) $emp['id']; ?>" <?php echo (int) $d['employee_id'] === (int) $emp['id'] ? 'selected' : ''; ?>><?php echo lev($emp['employee_id'] . ' - ' . $emp['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($d['employee_type_id'] && !$employees): ?><small class="text-danger">এই Employee Type-এর কোনো Employee পাওয়া যায়নি।</small><?php endif; ?>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Leave Type <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="leave_type_id" id="leaveTypeSelect" class="form-select" required>
                    <option value="">Select Leave Type</option>
                    <?php foreach ($leave_types as $lt): ?>
                        <option value="<?php echo (int) $lt['id']; ?>" <?php echo (int) $d['leave_type_id'] === (int) $lt['id'] ? 'selected' : ''; ?>><?php echo lev($lt['title']); ?> (<?php echo (int) $lt['leave_days']; ?> days/yr)</option>
                    <?php endforeach; ?>
                </select>
                <?php if ($d['employee_type_id'] && !$leave_types): ?><small class="text-danger">এই Employee Type-এর কোনো Leave Type নেই — প্রথমে "Leave Types" পেজ থেকে যোগ করুন।</small><?php endif; ?>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">From Date <span class="text-danger">*</span></label>
            <div class="col-sm-6"><input type="date" name="from_date" id="fromDate" class="form-control" value="<?php echo lev($d['from_date']); ?>" required onchange="calcTotalDays()"></div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">End Date <span class="text-danger">*</span></label>
            <div class="col-sm-6"><input type="date" name="to_date" id="toDate" class="form-control" value="<?php echo lev($d['to_date']); ?>" required onchange="calcTotalDays()"></div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Total Days <span class="text-danger">*</span></label>
            <div class="col-sm-6"><input type="text" id="totalDaysDisplay" class="form-control" value="<?php echo lev($d['total_days']); ?>" readonly style="background:#f1f5f9;"></div>
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
                <button type="submit" name="save_leave_entry" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $edit_id ? 'Update' : 'Create'; ?></button>
                <a href="index.php?page=leave_manage" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div>
        </div>
    </form>
</div>

<script>
function calcTotalDays() {
    const from = document.getElementById('fromDate').value;
    const to = document.getElementById('toDate').value;
    const out = document.getElementById('totalDaysDisplay');
    if (from && to) {
        const diff = (new Date(to) - new Date(from)) / (1000 * 60 * 60 * 24) + 1;
        out.value = diff > 0 ? diff : '';
    } else {
        out.value = '';
    }
}
function reloadForType() {
    const typeId = document.getElementById('employeeTypeSelect').value;
    window.location.href = 'index.php?page=leave_entry' + (typeId ? '&preselect_type=' + typeId : '');
}
</script>
