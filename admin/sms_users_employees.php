<?php
// admin/sms_users_employees.php - User Management > SMS User (Employees)
// কর্মচারীদের (Teacher/Staff ইত্যাদি) Login Password রিসেট (ডিফল্ট = Employee ID) করা
// এবং সেই তথ্য employees.mobile নাম্বারে SMS করে পাঠানো যাবে।
// Super Admin এবং system_users এর 'Admin' টাইপ — দুজনেই এই পেজ ব্যবহার করতে পারবে।

require_once __DIR__ . '/../config/sms.php'; // send_sms()

$is_super_admin = $_SESSION['is_super_admin'] ?? true;
$is_admin_type  = ($_SESSION['user_role'] ?? '') === 'Admin';
if (!$is_super_admin && !$is_admin_type) {
    echo '<div class="card-custom"><div class="alert alert-danger mb-0"><i class="fa-solid fa-lock me-2"></i>শুধুমাত্র <b>Super Admin</b> অথবা <b>Admin</b> এই পেজ অ্যাক্সেস করতে পারবেন।</div></div>';
    return;
}

// employees.password / must_change_password কলাম না থাকলে নিরাপদে যোগ করা হচ্ছে
function sse_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $chk = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $chk->execute([$table, $column]);
    if (!$chk->fetch()) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
sse_ensure_column($pdo, 'employees', 'password', 'VARCHAR(255) NULL');
sse_ensure_column($pdo, 'employees', 'must_change_password', 'TINYINT(1) NOT NULL DEFAULT 1');

function sev($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$se_err = $_SESSION['se_err'] ?? '';
$se_msg = $_SESSION['se_msg'] ?? '';
unset($_SESSION['se_err'], $_SESSION['se_msg']);

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['se_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=sms_users_employees');
        exit();
    }

    if (isset($_POST['reset_password'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $reset_emp_name = $pdo->prepare("SELECT name FROM employees WHERE id = ?");
        $reset_emp_name->execute([$id]);
        $reset_emp_name_val = $reset_emp_name->fetchColumn();
        $pdo->prepare("UPDATE employees SET password = NULL, must_change_password = 1 WHERE id = ?")->execute([$id]);
        $_SESSION['se_msg'] = 'পাসওয়ার্ড ডিফল্টে (Employee ID) রিসেট করা হয়েছে। এখন সংশ্লিষ্ট Employee ID দিয়েই প্রথমবার লগইন করা যাবে।';
        sec_log_activity($pdo, 'SMS', 'Update', "Employee পাসওয়ার্ড রিসেট করা হয়েছে: " . ($reset_emp_name_val ?: "#$id"));
    } elseif (isset($_POST['send_sms']) || isset($_POST['send_sms_all'])) {
        if (isset($_POST['send_sms_all'])) {
            $f_type_p = trim($_POST['p_type'] ?? '');
            $f_desig_p = (int) ($_POST['p_designation'] ?? 0);
            $f_search_p = trim($_POST['p_search'] ?? '');

            $where = ["status = 'Active'"];
            $params = [];
            if ($f_type_p !== '') { $where[] = 'type = ?'; $params[] = $f_type_p; }
            if ($f_desig_p > 0)   { $where[] = 'designation_id = ?'; $params[] = $f_desig_p; }
            if ($f_search_p !== '') { $where[] = '(employee_id LIKE ? OR name LIKE ?)'; $params[] = "%$f_search_p%"; $params[] = "%$f_search_p%"; }
            branch_apply_filter($where, $params);

            $stmt = $pdo->prepare("SELECT id FROM employees WHERE " . implode(' AND ', $where) . " LIMIT 500");
            $stmt->execute($params);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } else {
            $ids = $_POST['ids'] ?? [];
            $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [$ids])));
        }

        if (empty($ids)) {
            $_SESSION['se_err'] = isset($_POST['send_sms_all'])
                ? 'পাঠানোর জন্য এই ফিল্টারে কোনো এমপ্লয়ি পাওয়া যায়নি।'
                : 'কমপক্ষে একজন এমপ্লয়ি সিলেক্ট করুন (চেকবক্স টিক দিয়ে)।';
        } else {
            $inst = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetch();
            $inst_name = $inst['institute_name'] ?? ($GLOBALS['tenant']['school_name'] ?? 'School');

            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, employee_id, name, mobile FROM employees WHERE id IN ($in)");
            $stmt->execute($ids);
            $employees = $stmt->fetchAll();

            $sent = 0; $failed = 0;
            foreach ($employees as $e) {
                if (empty($e['mobile'])) { $failed++; continue; }
                $message = "{$inst_name}: প্রিয় {$e['name']}, আপনার লগইন তথ্য — Employee ID: {$e['employee_id']}, Password: {$e['employee_id']}। প্রথমবার লগইন করে পাসওয়ার্ড বদলে নিন।";
                $res = send_sms($pdo, $e['mobile'], $message);
                if ($res['success']) { $sent++; } else { $failed++; }
            }
            $_SESSION['se_msg'] = "{$sent} জনকে SMS পাঠানো হয়েছে।" . ($failed > 0 ? " {$failed} জনের ক্ষেত্রে ব্যর্থ হয়েছে (নম্বর নাই বা গেটওয়ে সমস্যা)।" : '');
            sec_log_activity($pdo, 'SMS', 'Send', "{$sent} জন Employee কে Login SMS পাঠানো হয়েছে" . ($failed > 0 ? ", {$failed} জন ব্যর্থ" : ''));
        }
    }
    header('Location: index.php?page=sms_users_employees');
    exit();
}

// -----------------------------------------------------------
// ফিল্টার + লিস্ট (সবসময় দেখাবে, স্টুডেন্টদের মতো Search চাপা লাগবে না —
// যেহেতু এমপ্লয়ি সংখ্যা তুলনামূলক কম থাকে)
// -----------------------------------------------------------
$designations = $pdo->query("SELECT id, title FROM designations ORDER BY title ASC")->fetchAll();
$f_type   = $_GET['f_type'] ?? '';
$f_desig  = $_GET['f_designation'] ?? '';
$f_search = trim($_GET['f_search'] ?? '');

$where = ["employees.status = 'Active'"];
$params = [];
if ($f_type !== '')   { $where[] = 'employees.type = ?'; $params[] = $f_type; }
if ($f_desig !== '')  { $where[] = 'employees.designation_id = ?'; $params[] = (int) $f_desig; }
if ($f_search !== '') { $where[] = '(employees.employee_id LIKE ? OR employees.name LIKE ?)'; $params[] = "%$f_search%"; $params[] = "%$f_search%"; }
branch_apply_filter($where, $params);

$sql = "SELECT employees.*, designations.title AS designation_title
        FROM employees
        LEFT JOIN designations ON designations.id = employees.designation_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY employees.name ASC LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$inst = $pdo->query("SELECT institute_name, phone_number FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) { $inst = ['institute_name' => 'School Name', 'phone_number' => '']; }
$fr_bid2 = branch_current_id();
if ($fr_bid2 !== null) {
    $branch_stmt2 = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $branch_stmt2->execute([$fr_bid2]);
    $branch = $branch_stmt2->fetch();
} else {
    $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
}
$site_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
?>

<div class="card-custom mb-3">
    <?php if ($se_msg): ?><div class="alert alert-success py-2"><?php echo sev($se_msg); ?></div><?php endif; ?>
    <?php if ($se_err): ?><div class="alert alert-danger py-2"><?php echo sev($se_err); ?></div><?php endif; ?>

    <form method="GET" action="index.php">
        <input type="hidden" name="page" value="sms_users_employees">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-bold">Type</label>
                <select name="f_type" class="form-select">
                    <option value="">All</option>
                    <?php foreach (['Teachers', 'Staffs', 'Board Of Directors', 'Academic Committee'] as $t): ?>
                        <option value="<?php echo sev($t); ?>" <?php echo $f_type === $t ? 'selected' : ''; ?>><?php echo sev($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Designation</label>
                <select name="f_designation" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($designations as $d): ?>
                        <option value="<?php echo (int) $d['id']; ?>" <?php echo (string) $f_desig === (string) $d['id'] ? 'selected' : ''; ?>><?php echo sev($d['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Employee ID / Name</label>
                <input type="text" name="f_search" class="form-control" value="<?php echo sev($f_search); ?>" placeholder="Employee ID অথবা নাম দিয়ে খুঁজুন">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100 fw-bold" style="background-color:#1e3a8a;">Filter</button>
            </div>
        </div>
    </form>
</div>

<div class="card-custom">
    <div class="text-center mb-3 pb-3 border-bottom">
        <h5 class="fw-bold text-primary mb-1"><?php echo sev($inst['institute_name']); ?></h5>
        <?php if ($branch && !empty($branch['address'])): ?>
            <div class="small text-muted">(<?php echo sev($branch['address']); ?><?php echo $branch['city'] ? ', ' . sev($branch['city']) : ''; ?>)</div>
        <?php endif; ?>
        <?php if (!empty($inst['phone_number']) || !empty($branch['phone'])): ?>
            <div class="small">Phone: <?php echo sev($inst['phone_number'] ?: $branch['phone']); ?></div>
        <?php endif; ?>
        <div class="small"><a href="<?php echo sev($site_url); ?>" target="_blank"><?php echo sev($site_url); ?></a></div>
        <h6 class="fw-bold mt-3 mb-0">Employees List for SMS Password</h6>
    </div>

    <!-- Change Password / Send SMS বাটন দুটো আগে "form" attribute দিয়ে টেবিলের ভেতরে বসানো
         নিজস্ব ছোট্ট &lt;form id="seResetFormX"&gt;/&lt;form id="seSmsOneFormX"&gt; কে টার্গেট
         করত। কিন্তু সেই ফর্মগুলো এই বড় smsEmployeesForm-এর ভেতরেই বসানো ছিল — ফর্মের ভেতরে
         ফর্ম, HTML-এ যা বৈধ না। ব্রাউজার নেস্টেড ফর্মটা বাতিল করে দিত, ফলে ওই id-টাই আসলে
         DOM-এ থাকত না, আর বাটনের form="..." রেফারেন্স কোথাও পৌঁছাত না — ক্লিক করলে কিছুই
         হতো না। সমাধানঃ একটামাত্র শেয়ার্ড হিডেন ফর্ম, smsEmployeesForm-এর বাইরে। -->
    <form method="POST" id="seRowActionForm" style="display:none">
        <input type="hidden" name="csrf_token" id="seRowActionCsrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    </form>
    <script>
    function seRowAction(url, fields, confirmMsg) {
        if (confirmMsg && !window.confirm(confirmMsg)) return false;
        var f = document.getElementById('seRowActionForm');
        f.setAttribute('action', url);
        var old = f.querySelectorAll('input[data-dyn-field]');
        old.forEach(function (el) { el.parentNode.removeChild(el); });
        Object.keys(fields).forEach(function (k) {
            var el = document.createElement('input');
            el.type = 'hidden'; el.name = k; el.value = fields[k]; el.setAttribute('data-dyn-field', '1');
            f.appendChild(el);
        });
        f.submit();
        return false;
    }
    </script>

    <form method="POST" action="index.php?page=sms_users_employees" id="smsEmployeesForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="p_type" value="<?php echo sev($f_type); ?>">
        <input type="hidden" name="p_designation" value="<?php echo (int) $f_desig; ?>">
        <input type="hidden" name="p_search" value="<?php echo sev($f_search); ?>">
        <div class="d-flex justify-content-end gap-2 mb-2">
            <button type="submit" name="send_sms" class="btn btn-outline-success btn-sm fw-bold" onclick="return confirm('সিলেক্ট করা এমপ্লয়িদের SMS পাঠানো হবে, নিশ্চিত?');">
                <i class="fa-solid fa-check-double me-1"></i> Send SMS (Selected)
            </button>
            <button type="submit" name="send_sms_all" class="btn btn-success btn-sm fw-bold" onclick="return confirm('এই তালিকার সবাইকে SMS পাঠানো হবে, নিশ্চিত?');">
                <i class="fa-solid fa-paper-plane me-1"></i> Send SMS (All)
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered align-middle" style="font-size:13px;">
                <thead class="table-light">
                    <tr>
                        <th style="width:36px;"><input type="checkbox" onclick="document.querySelectorAll('.se-chk').forEach(c=>c.checked=this.checked)"></th>
                        <th>ID / Username</th>
                        <th>Name</th>
                        <th>Designation</th>
                        <th style="width:230px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): foreach ($rows as $row): ?>
                        <tr>
                            <td><input type="checkbox" class="se-chk" name="ids[]" value="<?php echo (int) $row['id']; ?>" form="smsEmployeesForm"></td>
                            <td><?php echo sev($row['employee_id']); ?></td>
                            <td><?php echo sev($row['name']); ?></td>
                            <td><?php echo sev($row['designation_title'] ?? '-'); ?></td>
                            <td class="text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="return seRowAction('index.php?page=sms_users_employees', {id: <?php echo (int) $row['id']; ?>, reset_password: '1'}, <?php echo htmlspecialchars(json_encode($row['name'] . '-এর পাসওয়ার্ড রিসেট হবে (ডিফল্ট = Employee ID), নিশ্চিত?'), ENT_QUOTES, 'UTF-8'); ?>);">Change Password</button>
                                <button type="button" class="btn btn-sm btn-success" onclick="return seRowAction('index.php?page=sms_users_employees', {'ids[]': <?php echo (int) $row['id']; ?>, send_sms: '1'}, null);">Send SMS</button>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">কোনো এমপ্লয়ি পাওয়া যায়নি</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>
