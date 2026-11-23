<?php
// admin/hr_employee_view.php - Human Resource > Employees > View Employee (শুধু দেখার জন্য, এডিট ফর্ম না)
require_once __DIR__ . '/../config/table_print.php';
function hrv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function hrv_or($val): string { $v = trim((string) ($val ?? '')); return $v !== '' ? hrv($v) : '<span class="text-muted fst-italic">(not set)</span>'; }
function hrv_date($val): string { $v = trim((string) ($val ?? '')); return $v !== '' ? date('d M, Y', strtotime($v)) : '<span class="text-muted fst-italic">(not set)</span>'; }

$view_id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT e.*, et.name AS employee_type_name, dg.title AS designation_title, sh.name AS shift_name, gr.grade_name
     FROM employees e
     LEFT JOIN employee_types et ON et.id = e.employee_type_id
     LEFT JOIN designations dg ON dg.id = e.designation_id
     LEFT JOIN employee_shifts sh ON sh.id = e.shift_id
     LEFT JOIN employee_grades gr ON gr.id = e.grade_id
     WHERE e.id = ?"
);
$stmt->execute([$view_id]);
$row = $stmt->fetch();

$edu_rows = [];
$view_ct = []; $view_sa = [];
if ($row && $row['type'] === 'Teachers') {
    try {
        $q = $pdo->prepare("SELECT c.class_name, s.section_name FROM class_teachers ct JOIN academic_classes c ON c.id = ct.class_id LEFT JOIN academic_sections s ON s.id = ct.section_id WHERE ct.employee_id = ? ORDER BY c.rank ASC");
        $q->execute([$view_id]); $view_ct = $q->fetchAll();
        $q = $pdo->prepare("SELECT c.class_name, GROUP_CONCAT(sub.subject_name ORDER BY sub.subject_name SEPARATOR ', ') AS subs FROM teacher_subject_assignments t JOIN academic_classes c ON c.id = t.class_id JOIN academic_subjects sub ON sub.id = t.subject_id WHERE t.employee_id = ? GROUP BY c.id, c.class_name ORDER BY c.rank ASC");
        $q->execute([$view_id]); $view_sa = $q->fetchAll();
    } catch (Throwable $e) { /* টেবিল এখনো তৈরি হয়নি */ }
}
if ($row) {
    $estmt = $pdo->prepare("SELECT institute, degree, country FROM employee_educations WHERE employee_id = ?");
    $estmt->execute([$view_id]);
    $edu_rows = $estmt->fetchAll();
}
?>
<?php echo tprint_page_css('portrait', 12); ?>
<div class="card-custom">
    <?php if (!$row): ?>
        <h5 class="text-muted text-center py-4 mb-0">Employee পাওয়া যায়নি।</h5>
        <div class="text-center"><a href="index.php?page=hr_employee_list" class="btn btn-outline-secondary">Back to List</a></div>
    <?php else: ?>
        <?php echo tprint_header_html($pdo, 'Employee Profile', $row['name'] . ' — ID: ' . $row['employee_id']); ?>
        <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3">
                <img src="<?php echo $row['photo_path'] ? '../' . hrv($row['photo_path']) : 'data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2728%27 height=%2728%27%3E%3Crect width=%2728%27 height=%2728%27 fill=%27%23e2e8f0%27/%3E%3C/svg%3E'; ?>" style="width:70px;height:70px;border-radius:8px;object-fit:cover;border:1px solid #e2e8f0;">
                <div>
                    <h5 class="fw-bold mb-0"><?php echo hrv($row['name']); ?></h5>
                    <span class="text-muted"><?php echo hrv($row['designation_title'] ?: '-'); ?> &nbsp;|&nbsp; ID: <?php echo hrv($row['employee_id']); ?></span><br>
                    <?php echo $row['status'] === 'Active' ? '<span class="badge bg-success mt-1">Active</span>' : '<span class="badge bg-secondary mt-1">Inactive</span>'; ?>
                </div>
            </div>
            <div class="text-nowrap no-print d-flex align-items-center gap-2 flex-wrap">
                <?php echo tprint_controls(['orient' => 'portrait', 'wrap' => false]); ?>
                <a href="index.php?page=hr_employee_add&id=<?php echo (int) $row['id']; ?>" class="btn btn-primary btn-sm fw-bold" style="background-color:#1e3a8a;"><i class="fa-solid fa-pen"></i> Edit</a>
                <a href="index.php?page=hr_employee_list" class="btn btn-outline-secondary btn-sm">Back</a>
            </div>
        </div>

        <!-- ===================== Professional Information ===================== -->
        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3"><i class="fa-solid fa-circle-info"></i> Professional Information</h6>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Type</div><div class="col-sm-6"><?php echo hrv_or($row['type']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Section</div><div class="col-sm-6"><?php echo hrv_or(['School' => 'School Section', 'College' => 'College Section', 'Both' => 'School + College'][$row['section_level'] ?? ''] ?? ''); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Employee Type</div><div class="col-sm-6"><?php echo hrv_or($row['employee_type_name']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Designation</div><div class="col-sm-6"><?php echo hrv_or($row['designation_title']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Working Shift</div><div class="col-sm-6"><?php echo hrv_or($row['shift_name']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Grade</div><div class="col-sm-6"><?php echo hrv_or($row['grade_name']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Rank</div><div class="col-sm-6"><?php echo hrv_or($row['rank']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Employee ID</div><div class="col-sm-6"><?php echo hrv_or($row['employee_id']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Device Serial / MAC</div><div class="col-sm-6"><?php echo hrv_or($row['device_serial']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Tracking ID</div><div class="col-sm-6"><?php echo hrv_or($row['tracking_id']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">RFID Card No</div><div class="col-sm-6"><?php echo hrv_or($row['rfid_card_no']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Joining Date</div><div class="col-sm-6"><?php echo hrv_date($row['joining_date']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Bank Account No</div><div class="col-sm-6"><?php echo hrv_or($row['bank_account_no']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Previous Institute</div><div class="col-sm-6"><?php echo hrv_or($row['previous_institute']); ?></div></div>
        <div class="row mb-4"><div class="col-sm-3 fw-bold text-sm-end">Previous Designation</div><div class="col-sm-6"><?php echo hrv_or($row['previous_designation']); ?></div></div>

        <?php if ($row['type'] === 'Teachers'): ?>
        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3 mt-4"><i class="fa-solid fa-chalkboard-user"></i> Academic Assignment</h6>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Class Teacher</div><div class="col-sm-6"><?php
            if ($view_ct) { foreach ($view_ct as $ct) { echo '<span class="badge bg-primary me-1">' . hrv($ct['class_name']) . ($ct['section_name'] ? ' - ' . hrv($ct['section_name']) : '') . '</span>'; } }
            else { echo hrv_or(''); } ?></div></div>
        <div class="row mb-4"><div class="col-sm-3 fw-bold text-sm-end">Subject Teacher</div><div class="col-sm-6"><?php
            if ($view_sa) { foreach ($view_sa as $sa) { echo '<div><strong>' . hrv($sa['class_name']) . ':</strong> ' . hrv($sa['subs']) . '</div>'; } }
            else { echo hrv_or(''); } ?></div></div>
        <?php endif; ?>

        <!-- ===================== Personal Information ===================== -->
        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3 mt-4"><i class="fa-solid fa-circle-info"></i> Personal Information</h6>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Name</div><div class="col-sm-6"><?php echo hrv_or($row['name']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Mobile</div><div class="col-sm-6"><?php echo hrv_or($row['mobile']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Email</div><div class="col-sm-6"><?php echo hrv_or($row['email']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Date Of Birth</div><div class="col-sm-6"><?php echo hrv_date($row['dob']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">National ID</div><div class="col-sm-6"><?php echo hrv_or($row['national_id']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Fathers Name</div><div class="col-sm-6"><?php echo hrv_or($row['fathers_name']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Mothers Name</div><div class="col-sm-6"><?php echo hrv_or($row['mothers_name']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Marital Status</div><div class="col-sm-6"><?php echo hrv_or($row['marital_status']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Religion</div><div class="col-sm-6"><?php echo hrv_or($row['religion']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Blood Group</div><div class="col-sm-6"><?php echo hrv_or($row['blood_group']); ?></div></div>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Gender</div><div class="col-sm-6"><?php echo hrv_or($row['gender']); ?></div></div>
        <div class="row mb-4"><div class="col-sm-3 fw-bold text-sm-end">Facebook Link</div><div class="col-sm-6"><?php echo $row['facebook_link'] ? '<a href="' . hrv($row['facebook_link']) . '" target="_blank">' . hrv($row['facebook_link']) . '</a>' : hrv_or(''); ?></div></div>

        <!-- ===================== Educational Qualification ===================== -->
        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3 mt-4"><i class="fa-solid fa-circle-info"></i> Educational Qualification</h6>
        <?php if ($edu_rows): ?>
            <div class="table-responsive mb-4">
                <table class="table table-bordered align-middle" style="font-size:13px;">
                    <thead class="table-light"><tr><th>Name of the Institute</th><th>Name of the Degree</th><th>Country Name</th></tr></thead>
                    <tbody>
                        <?php foreach ($edu_rows as $er): ?>
                            <tr>
                                <td><?php echo hrv_or($er['institute']); ?></td>
                                <td><?php echo hrv_or($er['degree']); ?></td>
                                <td><?php echo hrv_or($er['country']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted mb-4">কোনো শিক্ষাগত যোগ্যতা যোগ করা হয়নি।</p>
        <?php endif; ?>

        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Major Subject</div><div class="col-sm-6"><?php echo hrv_or($row['major_subject']); ?></div></div>
        <div class="row mb-4"><div class="col-sm-3 fw-bold text-sm-end">Extra Qualification</div><div class="col-sm-6" style="white-space:pre-wrap;"><?php echo hrv_or($row['extra_qualification']); ?></div></div>

        <!-- ===================== Contact Information ===================== -->
        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3 mt-4"><i class="fa-solid fa-circle-info"></i> Contact Information</h6>
        <div class="row mb-3"><div class="col-sm-3 fw-bold text-sm-end">Present Address</div><div class="col-sm-6" style="white-space:pre-wrap;"><?php echo hrv_or($row['present_address']); ?></div></div>
        <div class="row mb-4"><div class="col-sm-3 fw-bold text-sm-end">Permanent Address</div><div class="col-sm-6" style="white-space:pre-wrap;"><?php echo hrv_or($row['permanent_address']); ?></div></div>

        <hr class="no-print">
        <div class="row no-print">
            <div class="col-sm-6 offset-sm-3">
                <a href="index.php?page=hr_employee_add&id=<?php echo (int) $row['id']; ?>" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><i class="fa-solid fa-pen"></i> Edit</a>
                <a href="index.php?page=hr_employee_list" class="btn btn-outline-secondary px-4 fw-bold ms-2">Back to List</a>
            </div>
        </div>
    <?php endif; ?>
</div>
