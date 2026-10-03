<?php
// admin/hr_employee_search.php - Human Resource > Employee Search (স্ক্রিনশট অনুযায়ী)
function esv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$employee_types = $pdo->query("SELECT id, name FROM employee_types WHERE status = 'Active' ORDER BY name ASC")->fetchAll();
$designations   = $pdo->query("SELECT id, title FROM designations WHERE status = 'Active' ORDER BY title ASC")->fetchAll();
$shifts         = $pdo->query("SELECT id, name FROM employee_shifts WHERE status = 'Active' ORDER BY name ASC")->fetchAll();
$genders = ['Male', 'Female', 'Other'];
$marital_statuses = ['Single', 'Married', 'Divorced', 'Widowed'];
$blood_groups = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];

$searched = isset($_GET['search']);
$f_etype  = (int) ($_GET['employee_type_id'] ?? 0);
$f_shift  = (int) ($_GET['shift_id'] ?? 0);
$f_desig  = (int) ($_GET['designation_id'] ?? 0);
$f_gender = $_GET['gender'] ?? '';
$f_marital = $_GET['marital_status'] ?? '';
$f_blood  = $_GET['blood_group'] ?? '';
$f_dob    = trim($_GET['dob'] ?? '');
$f_empid  = trim($_GET['employee_id'] ?? '');

$results = [];
if ($searched) {
    $where = ["e.is_current = 1"];
    $params = [];
    if ($f_etype > 0) { $where[] = 'e.employee_type_id = ?'; $params[] = $f_etype; }
    if ($f_shift > 0) { $where[] = 'e.shift_id = ?';         $params[] = $f_shift; }
    if ($f_desig > 0) { $where[] = 'e.designation_id = ?';   $params[] = $f_desig; }
    if ($f_gender !== '')  { $where[] = 'e.gender = ?';         $params[] = $f_gender; }
    if ($f_marital !== '') { $where[] = 'e.marital_status = ?'; $params[] = $f_marital; }
    if ($f_blood !== '')   { $where[] = 'e.blood_group = ?';    $params[] = $f_blood; }
    if ($f_dob !== '')     { $where[] = 'e.dob = ?';             $params[] = $f_dob; }
    if ($f_empid !== '')   { $where[] = 'e.employee_id LIKE ?';  $params[] = "%$f_empid%"; }
    branch_apply_filter($where, $params, 'e.branch_id');

    $sql = "SELECT e.*, et.name AS employee_type_name, dg.title AS designation_title, sh.name AS shift_name
            FROM employees e
            LEFT JOIN employee_types et ON et.id = e.employee_type_id
            LEFT JOIN designations dg ON dg.id = e.designation_id
            LEFT JOIN employee_shifts sh ON sh.id = e.shift_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY e.name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
}
?>
<div class="card-custom">
    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Employee Search</h6>

    <form method="GET" action="index.php">
        <input type="hidden" name="page" value="hr_employee_search">
        <input type="hidden" name="search" value="1">

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label text-end">Employee Type</label>
            <div class="col-sm-6">
                <select name="employee_type_id" class="form-select">
                    <option value="0">Select Employee Type</option>
                    <?php foreach ($employee_types as $et): ?>
                        <option value="<?php echo (int) $et['id']; ?>" <?php echo $f_etype === (int) $et['id'] ? 'selected' : ''; ?>><?php echo esv($et['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label text-end">Working Shift</label>
            <div class="col-sm-6">
                <select name="shift_id" class="form-select">
                    <option value="0">Select Shift</option>
                    <?php foreach ($shifts as $sh): ?>
                        <option value="<?php echo (int) $sh['id']; ?>" <?php echo $f_shift === (int) $sh['id'] ? 'selected' : ''; ?>><?php echo esv($sh['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label text-end">Designation</label>
            <div class="col-sm-6">
                <select name="designation_id" class="form-select">
                    <option value="0">Select Designation</option>
                    <?php foreach ($designations as $dg): ?>
                        <option value="<?php echo (int) $dg['id']; ?>" <?php echo $f_desig === (int) $dg['id'] ? 'selected' : ''; ?>><?php echo esv($dg['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label text-end">Gender</label>
            <div class="col-sm-6">
                <select name="gender" class="form-select">
                    <option value="">Select Gender</option>
                    <?php foreach ($genders as $g): ?>
                        <option value="<?php echo $g; ?>" <?php echo $f_gender === $g ? 'selected' : ''; ?>><?php echo $g; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label text-end">Marital Status</label>
            <div class="col-sm-6">
                <select name="marital_status" class="form-select">
                    <option value="">Select Marital Status</option>
                    <?php foreach ($marital_statuses as $ms): ?>
                        <option value="<?php echo $ms; ?>" <?php echo $f_marital === $ms ? 'selected' : ''; ?>><?php echo $ms; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label text-end">Blood Group</label>
            <div class="col-sm-6">
                <select name="blood_group" class="form-select">
                    <option value="">Select Blood Group</option>
                    <?php foreach ($blood_groups as $bg): ?>
                        <option value="<?php echo $bg; ?>" <?php echo $f_blood === $bg ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label text-end">Date of Birth</label>
            <div class="col-sm-6"><input type="date" name="dob" class="form-control" value="<?php echo esv($f_dob); ?>"></div>
        </div>

        <div class="row mb-4 align-items-center">
            <label class="col-sm-3 col-form-label text-end">Employee ID</label>
            <div class="col-sm-6"><input type="text" name="employee_id" class="form-control" value="<?php echo esv($f_empid); ?>"></div>
        </div>

        <div class="row">
            <div class="col-sm-6 offset-sm-3 text-end">
                <button type="submit" class="btn btn-primary fw-bold px-4" style="background-color:#1e3a8a;">Search</button>
            </div>
        </div>
    </form>
</div>

<?php if ($searched): ?>
    <div class="card-custom">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold text-dark m-0">Search Results</h6>
            <span class="text-muted small">মোট <?php echo count($results); ?> জন পাওয়া গেছে</span>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered align-middle" style="font-size:13px;">
                <thead class="table-light">
                    <tr>
                        <th>Srl.</th>
                        <th>Name</th>
                        <th>Employee ID</th>
                        <th>Employee Type</th>
                        <th>Designation</th>
                        <th>Working Shift</th>
                        <th>Mobile</th>
                        <th>Gender</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($results): $srl = 1; foreach ($results as $row): ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td>
                                <img src="<?php echo $row['photo_path'] ? '../' . esv($row['photo_path']) : 'data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2728%27 height=%2728%27%3E%3Crect width=%2728%27 height=%2728%27 fill=%27%23e2e8f0%27/%3E%3C/svg%3E'; ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;margin-right:6px;border:1px solid #e2e8f0;">
                                <?php echo esv($row['name']); ?>
                            </td>
                            <td><?php echo esv($row['employee_id']); ?></td>
                            <td><?php echo esv($row['employee_type_name'] ?: '-'); ?></td>
                            <td><?php echo esv($row['designation_title'] ?: '-'); ?></td>
                            <td><?php echo esv($row['shift_name'] ?: '-'); ?></td>
                            <td><?php echo esv($row['mobile']); ?></td>
                            <td><?php echo esv($row['gender']); ?></td>
                            <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                            <td>
                                <a href="index.php?page=hr_employee_add&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="10" class="text-center text-muted py-3">এই সার্চে কোনো Employee পাওয়া যায়নি</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
