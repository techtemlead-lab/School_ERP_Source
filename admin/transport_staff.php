<?php
// admin/transport_staff.php - Transport Module > Driver & Helper Management
$ts_err = $_SESSION['ts_err'] ?? '';
$ts_msg = $_SESSION['ts_msg'] ?? '';
unset($_SESSION['ts_err'], $_SESSION['ts_msg']);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS transport_staff (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        role ENUM('Driver','Helper') NOT NULL DEFAULT 'Driver',
        phone VARCHAR(20) NULL,
        license_no VARCHAR(50) NULL,
        license_expiry DATE NULL,
        nid_no VARCHAR(30) NULL,
        address TEXT NULL,
        joining_date DATE NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

function lv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ts_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=transport_staff');
        exit();
    }

    if (isset($_POST['save_staff'])) {
        $id             = (int) ($_POST['id'] ?? 0);
        $name           = trim($_POST['name'] ?? '');
        $role           = ($_POST['role'] ?? 'Driver') === 'Helper' ? 'Helper' : 'Driver';
        $phone          = trim($_POST['phone'] ?? '');
        $license_no     = trim($_POST['license_no'] ?? '');
        $license_expiry = trim($_POST['license_expiry'] ?? '') ?: null;
        $nid_no         = trim($_POST['nid_no'] ?? '');
        $address        = trim($_POST['address'] ?? '');
        $joining_date   = trim($_POST['joining_date'] ?? '') ?: null;
        $status         = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if ($name === '') {
            $_SESSION['ts_err'] = 'নাম খালি রাখা যাবে না।';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE transport_staff SET name=?, role=?, phone=?, license_no=?, license_expiry=?, nid_no=?, address=?, joining_date=?, status=? WHERE id=?");
                $stmt->execute([$name, $role, $phone, $license_no, $license_expiry, $nid_no, $address, $joining_date, $status, $id]);
                $_SESSION['ts_msg'] = 'তথ্য আপডেট করা হয়েছে।';
                sec_log_activity($pdo, 'Transport', 'Update', "ট্রান্সপোর্ট স্টাফ আপডেট করা হয়েছে: {$name} ({$role})");
            } else {
                $stmt = $pdo->prepare("INSERT INTO transport_staff (name, role, phone, license_no, license_expiry, nid_no, address, joining_date, status) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$name, $role, $phone, $license_no, $license_expiry, $nid_no, $address, $joining_date, $status]);
                $_SESSION['ts_msg'] = 'নতুন ' . ($role === 'Driver' ? 'ড্রাইভার' : 'হেলপার') . ' যোগ করা হয়েছে।';
                sec_log_activity($pdo, 'Transport', 'Create', "নতুন ট্রান্সপোর্ট স্টাফ যোগ করা হয়েছে: {$name} ({$role})");
            }
        }
    } elseif (isset($_POST['delete_staff'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $check = $pdo->prepare("SELECT COUNT(*) c FROM transport_vehicles WHERE driver_id = ? OR helper_id = ?");
        $check->execute([$id, $id]);
        if ((int) $check->fetch()['c'] > 0) {
            $_SESSION['ts_err'] = 'এই ব্যক্তি কোনো গাড়ির সাথে যুক্ত আছেন, মুছে ফেলার আগে গাড়ি থেকে সরিয়ে নিন।';
        } else {
            $del_staff_row = $pdo->prepare("SELECT name, role FROM transport_staff WHERE id = ?");
            $del_staff_row->execute([$id]);
            $del_staff = $del_staff_row->fetch();
            $pdo->prepare("DELETE FROM transport_staff WHERE id = ?")->execute([$id]);
            $_SESSION['ts_msg'] = 'মুছে ফেলা হয়েছে।';
            sec_log_activity($pdo, 'Transport', 'Delete', "ট্রান্সপোর্ট স্টাফ মুছে ফেলা হয়েছে: " . ($del_staff ? "{$del_staff['name']} ({$del_staff['role']})" : "#$id"));
        }
    }

    header('Location: index.php?page=transport_staff');
    exit();
}

$view = $_GET['view'] ?? 'list';
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM transport_staff WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: ['id' => 0, 'name' => '', 'role' => 'Driver', 'phone' => '', 'license_no' => '', 'license_expiry' => '', 'nid_no' => '', 'address' => '', 'joining_date' => '', 'status' => 'Active'];
    ?>
    <div class="card-custom">
        <?php if ($ts_err): ?><div class="alert alert-danger py-2"><?php echo lv($ts_err); ?></div><?php endif; ?>

        <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;">
            <i class="fa-solid fa-id-badge text-white"></i>
        </div>

        <form method="POST" action="index.php?page=transport_staff">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row">
                <div class="col-md-6">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Name <span class="text-danger">*</span></label>
                        <div class="col-sm-8"><input type="text" name="name" class="form-control" value="<?php echo lv($d['name']); ?>" required></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Role <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="role" class="form-select">
                                <option value="Driver" <?php echo $d['role'] === 'Driver' ? 'selected' : ''; ?>>Driver</option>
                                <option value="Helper" <?php echo $d['role'] === 'Helper' ? 'selected' : ''; ?>>Helper</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Phone</label>
                        <div class="col-sm-8"><input type="tel" name="phone" class="form-control phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="১১ ডিজিটের মোবাইল নম্বর দিন" value="<?php echo lv($d['phone']); ?>"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">NID No.</label>
                        <div class="col-sm-8"><input type="text" name="nid_no" class="form-control" value="<?php echo lv($d['nid_no']); ?>"></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">License No.</label>
                        <div class="col-sm-8"><input type="text" name="license_no" class="form-control" value="<?php echo lv($d['license_no']); ?>"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">License Expiry</label>
                        <div class="col-sm-8"><input type="date" name="license_expiry" class="form-control" value="<?php echo lv($d['license_expiry']); ?>"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Joining Date</label>
                        <div class="col-sm-8"><input type="date" name="joining_date" class="form-control" value="<?php echo lv($d['joining_date']); ?>"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Status</label>
                        <div class="col-sm-8">
                            <select name="status" class="form-select">
                                <option value="Active" <?php echo $d['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $d['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-2 col-form-label fw-bold text-end">Address</label>
                <div class="col-sm-10"><textarea name="address" class="form-control" rows="2"><?php echo lv($d['address']); ?></textarea></div>
            </div>

            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_staff" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Save'; ?></button>
                    <a href="index.php?page=transport_staff" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

<?php else: ?>
    <?php
    $f_role = $_GET['f_role'] ?? 'All';
    $where = [];
    $params = [];
    if ($f_role !== 'All') { $where[] = 'role = ?'; $params[] = $f_role; }
    $sql = "SELECT * FROM transport_staff";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $today = date('Y-m-d');
    ?>
    <div class="card-custom">
        <?php if ($ts_msg): ?><div class="alert alert-success py-2"><?php echo lv($ts_msg); ?></div><?php endif; ?>
        <?php if ($ts_err): ?><div class="alert alert-danger py-2"><?php echo lv($ts_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=transport_staff&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i> Add Driver/Helper</a>
            <form method="GET" action="index.php" class="d-flex gap-2">
                <input type="hidden" name="page" value="transport_staff">
                <select name="f_role" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="All" <?php echo $f_role === 'All' ? 'selected' : ''; ?>>All Roles</option>
                    <option value="Driver" <?php echo $f_role === 'Driver' ? 'selected' : ''; ?>>Driver</option>
                    <option value="Helper" <?php echo $f_role === 'Helper' ? 'selected' : ''; ?>>Helper</option>
                </select>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr><th>Name</th><th>Role</th><th>Phone</th><th>License</th><th>License Expiry</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php if ($rows): foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token());
                        $expiring = $row['license_expiry'] && strtotime($row['license_expiry']) < strtotime($today . ' + 30 days');
                    ?>
                        <tr>
                            <td class="fw-bold"><?php echo lv($row['name']); ?></td>
                            <td><span class="badge <?php echo $row['role'] === 'Driver' ? 'bg-primary' : 'bg-info text-dark'; ?>"><?php echo $row['role']; ?></span></td>
                            <td><?php echo lv($row['phone']); ?></td>
                            <td><?php echo lv($row['license_no']); ?></td>
                            <td><?php echo lv($row['license_expiry']); ?> <?php echo ($expiring && $row['license_expiry']) ? '<span class="badge bg-warning text-dark">Expiring</span>' : ''; ?></td>
                            <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                            <td class="text-nowrap">
                                <a href="index.php?page=transport_staff&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <form method="POST" action="index.php?page=transport_staff" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" name="delete_staff" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" class="text-center text-muted py-3">No records found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<script>
// ফোন নম্বর ফিল্ড (.phone-11 ক্লাস): টাইপ করার সাথে সাথেই সংখ্যা ছাড়া অন্য কিছু নেয় না, ১১ ডিজিটের বেশি হলে কেটে দেয়
document.querySelectorAll('.phone-11').forEach(function (input) {
    // প্রতিটা ফিল্ডের ঠিক পরে একটা এরর মেসেজ বসানো হচ্ছে (আগে থেকে না থাকলে)
    let errEl = input.nextElementSibling;
    if (!errEl || !errEl.classList.contains('phone11-err')) {
        errEl = document.createElement('div');
        errEl.className = 'small text-danger mt-1 d-none phone11-err';
        errEl.textContent = 'মোবাইল নম্বর অবশ্যই ১১ ডিজিটের হতে হবে।';
        input.insertAdjacentElement('afterend', errEl);
    }

    function checkPhone11() {
        let digits = input.value.replace(/\D/g, '');
        if (digits.length > 11) digits = digits.slice(0, 11);
        input.value = digits;
        // খালি রাখা যাবে (আবশ্যক না হলে) — কিন্তু কিছু লিখলে অবশ্যই ১১ ডিজিট হতে হবে,
        // ১১ ডিজিট হয়ে গেলেই মেসেজ সাথে সাথে (রিলোড ছাড়া) সরে যাবে
        const invalid = digits.length > 0 && digits.length !== 11;
        errEl.classList.toggle('d-none', !invalid);
        input.classList.toggle('is-invalid', invalid);
    }
    input.addEventListener('input', checkPhone11);
    input.addEventListener('blur', checkPhone11);
});
</script>
