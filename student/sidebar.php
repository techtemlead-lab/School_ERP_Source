<?php
$sp_page = $_GET['page'] ?? 'dashboard';
$sp_nav = [
    'dashboard'      => ['icon' => 'fa-house',              'label' => 'Dashboard'],
    'academic_calendar' => ['icon' => 'fa-calendar-days',   'label' => 'Academic Calendar'],
    'my_profile'     => ['icon' => 'fa-id-card',             'label' => 'My Profile'],
    'update_profile' => ['icon' => 'fa-user-pen',            'label' => 'Update Profile'],
    'live_class'     => ['icon' => 'fa-video',               'label' => 'My Live Class'],
    'recorded_class' => ['icon' => 'fa-film',                 'label' => 'My Recorded Class'],
    'home_work'      => ['icon' => 'fa-laptop',               'label' => 'My Home Work'],
    'assignment'     => ['icon' => 'fa-building-columns',     'label' => 'My Assignment'],
    'lesson_plan'    => ['icon' => 'fa-book',                 'label' => 'My Lesson Plan'],
    'my_teacher'     => ['icon' => 'fa-chalkboard-user',      'label' => 'My Teacher'],
    'my_syllabus'    => ['icon' => 'fa-book-open',            'label' => 'My Syllabus'],
    'my_attendance'  => ['icon' => 'fa-clipboard-check',      'label' => 'My Attendance'],
    'class_routine'  => ['icon' => 'fa-table-cells-large',    'label' => 'My Class Routine'],
    'exam_routine'   => ['icon' => 'fa-calendar-days',        'label' => 'My Exam Routine'],
    'seat_plan'      => ['icon' => 'fa-chair',                'label' => 'My Seat Plan'],
    'admit_card'     => ['icon' => 'fa-id-card-clip',         'label' => 'My Admit Card'],
    'report_card'    => ['icon' => 'fa-file-lines',           'label' => 'My Report Card'],
    'my_dues'        => ['icon' => 'fa-money-bill-wave',      'label' => 'My Dues (Monthly)'],
    'scholarship_apply' => ['icon' => 'fa-hand-holding-dollar', 'label' => 'Scholarship Apply'],
];
?>
<div class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="d-flex align-items-center gap-2">
            <i class="fa-solid fa-graduation-cap" style="color:#2dd4bf;font-size:20px;"></i>
            <span class="brand-text">Student Portal</span>
        </div>
    </div>

    <ul class="sidebar-menu">
        <?php foreach ($sp_nav as $key => $item): ?>
            <li>
                <a href="index.php<?php echo $key === 'dashboard' ? '' : '?page=' . $key; ?>"
                   class="<?php echo $sp_page === $key ? 'active' : ''; ?>" title="<?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fa-solid <?php echo $item['icon']; ?> menu-icon"></i>
                    <span class="menu-text"><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                </a>
            </li>
        <?php endforeach; ?>
        <li>
            <a href="#" data-bs-toggle="modal" data-bs-target="#changePasswordModal" title="Change Password">
                <i class="fa-solid fa-key menu-icon"></i>
                <span class="menu-text">Change Password</span>
            </a>
        </li>
        <li>
            <a href="index.php?logout=true" title="Logout" style="color:#f87171;">
                <i class="fa-solid fa-power-off menu-icon" style="color:#f87171;"></i>
                <span class="menu-text">Logout</span>
            </a>
        </li>
    </ul>
</div>
