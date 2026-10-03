<?php
// teacher/dashboard.php - Teacher Portal > Dashboard
$stmt = $pdo->prepare(
    "SELECT e.*, et.name AS employee_type_name, dg.title AS designation_name
     FROM employees e
     LEFT JOIN employee_types et ON et.id = e.employee_type_id
     LEFT JOIN designations dg ON dg.id = e.designation_id
     WHERE e.id = ? LIMIT 1"
);
$stmt->execute([$teacher['id']]);
$t = $stmt->fetch();
?>

<div class="row g-3 mb-4">
    <div class="col-lg-3 col-md-6">
        <div class="top-stat-box" style="background:#1e3a8a;">
            <div><h3 style="font-size:16px;"><?php echo tp_e($t['employee_id']); ?></h3><p>Employee (Teacher) ID</p></div>
            <div class="top-stat-icon"><i class="fa-solid fa-id-badge"></i></div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="top-stat-box" style="background:#0e7490;">
            <div><h3 style="font-size:15px;"><?php echo tp_e($t['designation_name'] ?? '—'); ?></h3><p>Designation</p></div>
            <div class="top-stat-icon"><i class="fa-solid fa-chalkboard-user"></i></div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="top-stat-box" style="background:#1e293b;">
            <div><h3 style="font-size:15px;"><?php echo tp_e($t['employee_type_name'] ?? $t['type']); ?></h3><p>Type</p></div>
            <div class="top-stat-icon"><i class="fa-solid fa-users-line"></i></div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="top-stat-box" style="background:#10b981;">
            <div><h3 style="font-size:15px;"><?php echo $t['joining_date'] ? date('d M Y', strtotime($t['joining_date'])) : '—'; ?></h3><p>Joining Date</p></div>
            <div class="top-stat-icon"><i class="fa-solid fa-calendar-check"></i></div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-4">
        <div class="card-custom text-center">
            <?php if (!empty($t['photo_path'])): ?>
                <img src="../<?php echo tp_e($t['photo_path']); ?>" style="width:110px;height:110px;object-fit:cover;border-radius:50%;margin:0 auto 15px;border:4px solid #f1f5f9;">
            <?php else: ?>
                <i class="fa-solid fa-circle-user text-secondary" style="font-size:110px;"></i>
            <?php endif; ?>
            <h5 class="fw-bold mb-0"><?php echo tp_e($t['name']); ?></h5>
            <p class="text-muted mb-2"><?php echo tp_e($t['designation_name'] ?? '—'); ?></p>
            <span class="badge bg-success"><?php echo tp_e($t['status']); ?></span>
            <hr>
            <a href="index.php?page=hr_view_info" class="btn btn-sm btn-outline-primary w-100">
                <i class="fa-solid fa-id-card"></i> View Full Profile
            </a>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card-custom">
            <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-compass text-primary"></i> Quick Links</h6>
            <div class="row g-3">
                <div class="col-md-6">
                    <a href="index.php?page=routine_view" class="text-decoration-none">
                        <div class="d-flex align-items-center gap-3 p-3 border rounded-3" style="background:#f8fafc;">
                            <i class="fa-solid fa-table-cells-large fs-4 text-primary"></i>
                            <div>
                                <div class="fw-bold text-dark" style="font-size:13.5px;">View / Print Routine</div>
                                <div class="text-muted" style="font-size:12px;">ক্লাস রুটিন দেখুন</div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-md-6">
                    <a href="index.php?page=hr_update_info" class="text-decoration-none">
                        <div class="d-flex align-items-center gap-3 p-3 border rounded-3" style="background:#f8fafc;">
                            <i class="fa-solid fa-user-pen fs-4 text-primary"></i>
                            <div>
                                <div class="fw-bold text-dark" style="font-size:13.5px;">Update Information</div>
                                <div class="text-muted" style="font-size:12px;">নিজের তথ্য আপডেট করুন</div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-md-6">
                    <a href="index.php?page=result_exam_routine" class="text-decoration-none">
                        <div class="d-flex align-items-center gap-3 p-3 border rounded-3" style="background:#f8fafc;">
                            <i class="fa-solid fa-calendar-days fs-4 text-primary"></i>
                            <div>
                                <div class="fw-bold text-dark" style="font-size:13.5px;">Exam Routine</div>
                                <div class="text-muted" style="font-size:12px;">পরীক্ষার রুটিন দেখুন</div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-md-6">
                    <a href="index.php?page=result_marks_entry_subject" class="text-decoration-none">
                        <div class="d-flex align-items-center gap-3 p-3 border rounded-3" style="background:#f8fafc;">
                            <i class="fa-solid fa-pen-to-square fs-4 text-primary"></i>
                            <div>
                                <div class="fw-bold text-dark" style="font-size:13.5px;">Marks Entry (Subject)</div>
                                <div class="text-muted" style="font-size:12px;">সাবজেক্ট অনুযায়ী নম্বর দিন</div>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
