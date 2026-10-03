<?php
// student/my_profile.php - My Profile (read-only full details)
$class_name   = sp_lookup_name($pdo, 'academic_classes', $student['class_id'] ?: null);
$section_name = sp_lookup_name($pdo, 'academic_sections', $student['section_id'] ?: null);
$session_name = sp_lookup_name($pdo, 'academic_sessions', $student['session_id'] ?: null);
$shift_name   = sp_lookup_name($pdo, 'academic_shifts', $student['shift_id'] ?: null);

function sp_row($label, $value) {
    echo '<div class="col-md-4 mb-3"><div class="text-muted" style="font-size:11.5px;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
       . '<div class="fw-bold" style="font-size:14px;">' . htmlspecialchars((string) ($value ?: '—'), ENT_QUOTES, 'UTF-8') . '</div></div>';
}
?>
<div class="card-custom">
    <div class="d-flex align-items-center gap-3 mb-4">
        <?php if (!empty($student['photo_path'])): ?>
            <img src="../<?php echo sp_e($student['photo_path']); ?>" style="width:70px;height:70px;border-radius:50%;object-fit:cover;border:3px solid #ccfbf1;">
        <?php else: ?>
            <div style="width:70px;height:70px;border-radius:50%;background:#ccfbf1;color:#0f766e;display:flex;align-items:center;justify-content:center;font-size:26px;">
                <i class="fa-solid fa-user"></i>
            </div>
        <?php endif; ?>
        <div>
            <h5 class="fw-bold mb-1"><?php echo sp_e($student['name_en']); ?></h5>
            <span class="text-muted" style="font-size:13px;"><?php echo sp_e($student['name_bn']); ?></span><br>
            <span class="badge" style="background:#ccfbf1;color:#0f766e;">Student ID: <?php echo sp_e($student['student_id']); ?></span>
        </div>
        <a href="index.php?page=update_profile" class="btn btn-sm ms-auto text-white" style="background:#0f766e;"><i class="fa-solid fa-user-pen me-1"></i>Update Profile</a>
    </div>

    <h6 class="fw-bold text-uppercase mb-3" style="font-size:12px;color:#0f766e;">Academic Information</h6>
    <div class="row">
        <?php
        sp_row('Session', $session_name);
        sp_row('Class', $class_name);
        sp_row('Section', $section_name);
        sp_row('Shift', $shift_name);
        sp_row('Roll No', $student['roll_no']);
        sp_row('Status', $student['status']);
        ?>
    </div>

    <hr>
    <h6 class="fw-bold text-uppercase mb-3" style="font-size:12px;color:#0f766e;">Personal Information</h6>
    <div class="row">
        <?php
        sp_row('Date of Birth', $student['dob']);
        sp_row('Gender', $student['gender']);
        sp_row('Blood Group', $student['blood_group']);
        sp_row('Religion', $student['religion']);
        sp_row('Nationality', $student['nationality']);
        sp_row('Birth Reg. No', $student['birth_reg_no']);
        ?>
    </div>

    <hr>
    <h6 class="fw-bold text-uppercase mb-3" style="font-size:12px;color:#0f766e;">Contact Information</h6>
    <div class="row">
        <?php
        sp_row('Mobile', $student['mobile']);
        sp_row('Email', $student['email']);
        sp_row('Present Address', $student['present_address']);
        sp_row('Permanent Address', $student['permanent_address']);
        ?>
    </div>

    <hr>
    <h6 class="fw-bold text-uppercase mb-3" style="font-size:12px;color:#0f766e;">Guardian Information</h6>
    <div class="row">
        <?php
        sp_row("Father's Name", $student['father_name']);
        sp_row("Father's Phone", $student['father_phone']);
        sp_row("Mother's Name", $student['mother_name']);
        sp_row("Mother's Phone", $student['mother_phone']);
        sp_row('Guardian Name', $student['guardian_name']);
        sp_row('Guardian Phone', $student['guardian_phone']);
        ?>
    </div>
</div>
