<?php
// admin/hr_employee_list.php - Human Resource > Employees > Manage Employees (স্ক্রিনশট অনুযায়ী)
require_once __DIR__ . '/../config/table_print.php';
$el_msg = $_SESSION['el_msg'] ?? '';
$el_err = $_SESSION['el_err'] ?? '';
unset($_SESSION['el_msg'], $_SESSION['el_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_employee'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['el_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $del_row = $pdo->prepare("SELECT name, employee_id FROM employees WHERE id = ?");
        $del_row->execute([$id]);
        $del_emp = $del_row->fetch();
        $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);
        // Teacher এসাইনমেন্ট পরিষ্কার (টেবিল না থাকলে বাদ)
        foreach (['class_teachers', 'teacher_subject_assignments'] as $asg_tbl) {
            try { $pdo->prepare("DELETE FROM $asg_tbl WHERE employee_id = ?")->execute([$id]); } catch (Throwable $e) {}
        }
        $_SESSION['el_msg'] = 'Employee মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'HR', 'Delete', "Employee মুছে ফেলা হয়েছে: " . ($del_emp['name'] ?? "#$id") . (!empty($del_emp['employee_id']) ? " ({$del_emp['employee_id']})" : ''));
    }
    header('Location: index.php?page=hr_employee_list' . (($_GET['tab'] ?? '') === 'transport' ? '&tab=transport' : ''));
    exit();
}

function elv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$employee_types = $pdo->query("SELECT id, name FROM employee_types WHERE status = 'Active' ORDER BY name ASC")->fetchAll();
$designations   = $pdo->query("SELECT id, title FROM designations WHERE status = 'Active' ORDER BY title ASC")->fetchAll();
$shifts         = $pdo->query("SELECT id, name FROM employee_shifts WHERE status = 'Active' ORDER BY name ASC")->fetchAll();
$grades         = $pdo->query("SELECT id, grade_name FROM employee_grades WHERE status = 'Active' ORDER BY grade_name ASC")->fetchAll();

$f_etype  = (int) ($_GET['f_etype'] ?? 0);
$f_desig  = (int) ($_GET['f_desig'] ?? 0);
$f_shift  = (int) ($_GET['f_shift'] ?? 0);
$f_grade  = (int) ($_GET['f_grade'] ?? 0);
$f_name   = trim($_GET['f_name'] ?? '');
$f_empid  = trim($_GET['f_empid'] ?? '');
$f_device = trim($_GET['f_device'] ?? '');

$is_transport_tab = (($_GET['tab'] ?? '') === 'transport'); // Transport Module > Driver & Helper থেকে এলে শুধু Driver/Helper
$tab_qs = $is_transport_tab ? '&tab=transport' : '';

$where = ["e.is_current = 1"];
$params = [];
if ($is_transport_tab) {
    $where[] = "(e.designation_id IN (SELECT id FROM designations WHERE title LIKE '%driver%' OR title LIKE '%helper%')
                 OR e.employee_type_id IN (SELECT id FROM employee_types WHERE name LIKE '%driver%' OR name LIKE '%helper%'))";
}
if ($f_etype > 0) { $where[] = 'e.employee_type_id = ?'; $params[] = $f_etype; }
if ($f_desig > 0) { $where[] = 'e.designation_id = ?';   $params[] = $f_desig; }
if ($f_shift > 0) { $where[] = 'e.shift_id = ?';         $params[] = $f_shift; }
if ($f_grade > 0) { $where[] = 'e.grade_id = ?';         $params[] = $f_grade; }
if ($f_name !== '')   { $where[] = 'e.name LIKE ?';        $params[] = "%$f_name%"; }
if ($f_empid !== '')  { $where[] = 'e.employee_id LIKE ?'; $params[] = "%$f_empid%"; }
if ($f_device !== '') { $where[] = 'e.device_serial LIKE ?'; $params[] = "%$f_device%"; }
branch_apply_filter($where, $params, 'e.branch_id'); // লগইন করা branch অনুযায়ী কর্মচারী ফিল্টার

$sql = "SELECT e.*, et.name AS employee_type_name, dg.title AS designation_title, sh.name AS shift_name, gr.grade_name
        FROM employees e
        LEFT JOIN employee_types et ON et.id = e.employee_type_id
        LEFT JOIN designations dg ON dg.id = e.designation_id
        LEFT JOIN employee_shifts sh ON sh.id = e.shift_id
        LEFT JOIN employee_grades gr ON gr.id = e.grade_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY (e.rank + 0) ASC, e.rank ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$total_male   = 0;
$total_female = 0;
foreach ($rows as $r) {
    if ($r['gender'] === 'Male') { $total_male++; }
    elseif ($r['gender'] === 'Female') { $total_female++; }
}

$el_find = function (array $list, int $id, string $col): string {
    foreach ($list as $it) { if ((int) $it['id'] === $id) { return (string) $it[$col]; } }
    return '';
};
$el_parts = [];
if ($f_etype > 0) { $el_parts[] = 'Type: ' . $el_find($employee_types, $f_etype, 'name'); }
if ($f_desig > 0) { $el_parts[] = 'Designation: ' . $el_find($designations, $f_desig, 'title'); }
if ($f_shift > 0) { $el_parts[] = 'Shift: ' . $el_find($shifts, $f_shift, 'name'); }
if ($f_grade > 0) { $el_parts[] = 'Grade: ' . $el_find($grades, $f_grade, 'grade_name'); }
if ($f_name !== '')   { $el_parts[] = 'Name: ' . $f_name; }
if ($f_empid !== '')  { $el_parts[] = 'ID: ' . $f_empid; }
if ($f_device !== '') { $el_parts[] = 'Device: ' . $f_device; }
$el_parts[] = 'Total: ' . count($rows) . ' (Male: ' . $total_male . ', Female: ' . $total_female . ')';
$el_sub = implode(' | ', $el_parts);
?>
<?php echo tprint_page_css('landscape', 9); ?>
<div class="card-custom">
    <?php echo tprint_header_html($pdo, $is_transport_tab ? 'Driver & Helper List' : 'All Employee List', $el_sub); ?>
    <?php if ($el_msg): ?><div class="alert alert-success py-2"><?php echo elv($el_msg); ?></div><?php endif; ?>
    <?php if ($el_err): ?><div class="alert alert-danger py-2"><?php echo elv($el_err); ?></div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <a href="index.php?page=hr_employee_add" class="btn btn-success no-print"><i class="fa-solid fa-plus"></i></a>
        <div class="d-flex gap-4 flex-wrap">
            <span class="text-primary fw-bold"><i class="fa-solid fa-person"></i> Total Male : <?php echo (int) $total_male; ?></span>
            <span class="text-success fw-bold"><i class="fa-solid fa-person-dress"></i> Total Female : <?php echo (int) $total_female; ?></span>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="text-muted small no-print">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> item<?php echo count($rows) === 1 ? '' : 's'; ?>.</span>
            <?php echo tprint_controls(['orient' => 'landscape', 'wrap' => false, 'font_px' => 9]); ?>
        </div>
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
        <input type="hidden" name="page" value="hr_employee_list">
        <?php if ($is_transport_tab): ?><input type="hidden" name="tab" value="transport"><?php endif; ?>
        <div class="table-responsive">
            <table class="table table-bordered align-middle" style="font-size:13px;">
                <thead class="table-light">
                    <tr>
                        <th>Srl.</th>
                        <th>Rank</th>
                        <th>Section</th>
                        <th>Employee Type</th>
                        <th>Designation</th>
                        <th>Working Shift</th>
                        <th>Grade</th>
                        <th>Name</th>
                        <th>Employee ID</th>
                        <th>Device Serial / MAC</th>
                        <th>Tracking ID</th>
                        <th>RFID Card</th>
                        <th>Mobile</th>
                        <th>Status</th>
                        <th class="no-print">Actions</th>
                    </tr>
                    <tr class="no-print">
                        <th></th>
                        <th></th>
                        <th></th>
                        <th>
                            <select name="f_etype" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="0">All</option>
                                <?php foreach ($employee_types as $et): ?>
                                    <option value="<?php echo (int) $et['id']; ?>" <?php echo $f_etype === (int) $et['id'] ? 'selected' : ''; ?>><?php echo elv($et['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th>
                            <select name="f_desig" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="0">All</option>
                                <?php foreach ($designations as $dg): ?>
                                    <option value="<?php echo (int) $dg['id']; ?>" <?php echo $f_desig === (int) $dg['id'] ? 'selected' : ''; ?>><?php echo elv($dg['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th>
                            <select name="f_shift" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="0">All</option>
                                <?php foreach ($shifts as $sh): ?>
                                    <option value="<?php echo (int) $sh['id']; ?>" <?php echo $f_shift === (int) $sh['id'] ? 'selected' : ''; ?>><?php echo elv($sh['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th>
                            <select name="f_grade" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="0">All</option>
                                <?php foreach ($grades as $g): ?>
                                    <option value="<?php echo (int) $g['id']; ?>" <?php echo $f_grade === (int) $g['id'] ? 'selected' : ''; ?>><?php echo elv($g['grade_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th><input type="text" name="f_name" class="form-control form-control-sm" value="<?php echo elv($f_name); ?>"></th>
                        <th><input type="text" name="f_empid" class="form-control form-control-sm" value="<?php echo elv($f_empid); ?>"></th>
                        <th><input type="text" name="f_device" class="form-control form-control-sm" value="<?php echo elv($f_device); ?>"></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th><button type="submit" class="btn btn-sm btn-outline-primary">Filter</button></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo elv($row['rank']); ?></td>
                            <td><?php echo elv(['School' => 'School', 'College' => 'College', 'Both' => 'School + College'][$row['section_level'] ?? ''] ?? '-'); ?></td>
                            <td><?php echo elv($row['employee_type_name'] ?: '-'); ?></td>
                            <td><?php echo elv($row['designation_title'] ?: '-'); ?></td>
                            <td><?php echo elv($row['shift_name'] ?: '-'); ?></td>
                            <td><?php echo elv($row['grade_name'] ?: '-'); ?></td>
                            <td>
                                <img src="<?php echo $row['photo_path'] ? '../' . elv($row['photo_path']) : 'data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2728%27 height=%2728%27%3E%3Crect width=%2728%27 height=%2728%27 fill=%27%23e2e8f0%27/%3E%3C/svg%3E'; ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;margin-right:6px;border:1px solid #e2e8f0;">
                                <?php echo elv($row['name']); ?>
                            </td>
                            <td><?php echo elv($row['employee_id']); ?></td>
                            <td><?php echo elv($row['device_serial'] ?: '(not set)'); ?></td>
                            <td class="fst-italic text-muted"><?php echo elv($row['tracking_id'] ?: '(not set)'); ?></td>
                            <td class="fst-italic text-muted"><?php echo elv($row['rfid_card_no'] ?: '(not set)'); ?></td>
                            <td><?php echo elv($row['mobile']); ?></td>
                            <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                            <td class="text-nowrap no-print">
                                <a href="index.php?page=hr_employee_view&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View"><i class="fa-solid fa-eye"></i></a>
                                <a href="index.php?page=hr_employee_add&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=hr_employee_list<?php echo $tab_qs; ?>', 'delete_employee', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="15" class="text-center text-muted py-3">No data found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>
