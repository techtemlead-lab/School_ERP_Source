<?php
// teacher/hr_view_info.php - Human Resource > HR Module > View Information (নিজের তথ্য, শুধু দেখার জন্য)
$stmt = $pdo->prepare(
    "SELECT e.*, et.name AS employee_type_name, dg.title AS designation_name,
            sh.name AS shift_name, gr.grade_name AS grade_name
     FROM employees e
     LEFT JOIN employee_types et ON et.id = e.employee_type_id
     LEFT JOIN designations dg ON dg.id = e.designation_id
     LEFT JOIN employee_shifts sh ON sh.id = e.shift_id
     LEFT JOIN employee_grades gr ON gr.id = e.grade_id
     WHERE e.id = ? LIMIT 1"
);
$stmt->execute([$teacher['id']]);
$t = $stmt->fetch();

function hv_row(string $label, $value): string
{
    return '<div class="col-md-4 mb-3"><div class="text-muted" style="font-size:11.5px;font-weight:700;text-transform:uppercase;">' . htmlspecialchars($label) . '</div>'
         . '<div class="fw-bold text-dark" style="font-size:14px;">' . (($value !== null && $value !== '') ? htmlspecialchars((string) $value) : '—') . '</div></div>';
}
?>

<div class="card-custom">
    <div class="d-flex align-items-center gap-3 mb-4 pb-3 border-bottom">
        <?php if (!empty($t['photo_path'])): ?>
            <img src="../<?php echo tp_e($t['photo_path']); ?>" style="width:90px;height:90px;object-fit:cover;border-radius:10px;">
        <?php else: ?>
            <i class="fa-solid fa-circle-user text-secondary" style="font-size:80px;"></i>
        <?php endif; ?>
        <div>
            <h5 class="fw-bold mb-1"><?php echo tp_e($t['name']); ?></h5>
            <span class="badge bg-primary me-1"><?php echo tp_e($t['designation_name'] ?? '—'); ?></span>
            <span class="badge bg-secondary"><?php echo tp_e($t['employee_type_name'] ?? $t['type']); ?></span>
        </div>
        <div class="ms-auto">
            <a href="index.php?page=hr_update_info" class="btn btn-sm btn-primary"><i class="fa-solid fa-pen-to-square"></i> Update Information</a>
        </div>
    </div>

    <h6 class="fw-bold text-primary mb-3">Basic Information</h6>
    <div class="row">
        <?php
        echo hv_row('Employee ID', $t['employee_id']);
        echo hv_row('Type', $t['type']);
        echo hv_row('Employee Type', $t['employee_type_name']);
        echo hv_row('Designation', $t['designation_name']);
        echo hv_row('Working Shift', $t['shift_name']);
        echo hv_row('Grade', $t['grade_name']);
        echo hv_row('Joining Date', $t['joining_date'] ? date('d M Y', strtotime($t['joining_date'])) : null);
        echo hv_row('Status', $t['status']);
        ?>
    </div>

    <h6 class="fw-bold text-primary mb-3 mt-3">Personal Information</h6>
    <div class="row">
        <?php
        echo hv_row('Mobile', $t['mobile']);
        echo hv_row('Email', $t['email']);
        echo hv_row('Date of Birth', $t['dob'] ? date('d M Y', strtotime($t['dob'])) : null);
        echo hv_row('Gender', $t['gender']);
        echo hv_row('Blood Group', $t['blood_group']);
        echo hv_row('Religion', $t['religion']);
        echo hv_row('Marital Status', $t['marital_status']);
        echo hv_row('National ID', $t['national_id']);
        echo hv_row("Father's Name", $t['fathers_name']);
        echo hv_row("Mother's Name", $t['mothers_name']);
        echo hv_row('Facebook', $t['facebook_link']);
        echo hv_row('Major Subject', $t['major_subject']);
        ?>
    </div>

    <h6 class="fw-bold text-primary mb-3 mt-3">Address</h6>
    <div class="row">
        <?php
        echo hv_row('Present Address', $t['present_address']);
        echo hv_row('Permanent Address', $t['permanent_address']);
        ?>
    </div>
</div>
