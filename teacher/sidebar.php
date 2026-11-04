<?php
$tp_page = $_GET['page'] ?? 'dashboard';
// লোগো আইকন base64 data URI হিসেবে সরাসরি এমবেড করা — আলাদা ফাইল পাথের উপর
// নির্ভরতা নেই, তাই কখনো broken image দেখাবে না
$tp_brand_icon_data_uri = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNTYgMjU2Ij48Y2lyY2xlIGN4PSIxMjgiIGN5PSIxMjgiIHI9IjEyMCIgZmlsbD0iIzFlNDA3YyIvPjxwYXRoIGQ9Ik0xMjggNjRsODggNDAtODggNDAtODgtNDB6IiBmaWxsPSIjZmZmIi8+PHBhdGggZD0iTTg0IDEzOHYzNGMwIDEyIDIwIDIyIDQ0IDIyczQ0LTEwIDQ0LTIydi0zNGwtNDQgMjB6IiBmaWxsPSIjZmZmIi8+PC9zdmc+';
?>
<div class="sidebar" id="sidebar">
    <div class="sidebar-brand d-flex align-items-center justify-content-between px-3">
        <div class="d-flex align-items-center gap-2">
            <img src="<?php echo $tp_brand_icon_data_uri; ?>" alt="School ERP" style="width:28px;height:auto;flex-shrink:0;">
            <span class="brand-text fw-bold text-white" style="font-size:15px;line-height:1.1;">School ERP</span>
        </div>
        <i class="fa-solid fa-bars fs-5 sidebar-toggle-btn text-white" id="toggleSidebar" style="cursor: pointer;"></i>
    </div>

    <div class="sidebar-search">
        <input type="text" class="form-control form-control-sm search-input" placeholder="Search for menu items...">
    </div>

    <ul class="sidebar-menu">
        <!-- Dashboard -->
        <li>
            <a href="index.php" class="<?php echo ($tp_page === 'dashboard') ? 'active' : ''; ?>" title="Dashboard">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-house menu-icon"></i>
                    <span class="menu-text">Dashboard</span>
                </span>
            </a>
        </li>

        <!-- Routine Module -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#tRoutineSubmenu" aria-expanded="<?php echo $tp_page === 'routine_view' ? 'true' : 'false'; ?>" title="Routine Module">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-table-cells-large menu-icon"></i>
                    <span class="menu-text">Routine Module</span>
                </span>
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu <?php echo $tp_page === 'routine_view' ? 'show' : ''; ?>" id="tRoutineSubmenu">
                <li><a href="index.php?page=routine_view" class="<?php echo $tp_page === 'routine_view' ? 'active' : ''; ?>">My Class Routine</a></li>
            </ul>
        </li>

        <!-- Human Resource -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#tHrSubmenu" aria-expanded="<?php echo in_array($tp_page, ['hr_view_info', 'hr_update_info'], true) ? 'true' : 'false'; ?>" title="Human Resource">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-user-tie menu-icon"></i>
                    <span class="menu-text">Human Resource</span>
                </span>
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu <?php echo in_array($tp_page, ['hr_view_info', 'hr_update_info'], true) ? 'show' : ''; ?>" id="tHrSubmenu">
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#tHrModuleSubmenu" aria-expanded="true" title="HR Module">
                        <span>HR Module</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested show" id="tHrModuleSubmenu">
                        <li><a href="index.php?page=hr_view_info" class="<?php echo $tp_page === 'hr_view_info' ? 'active' : ''; ?>">View Information</a></li>
                        <li><a href="index.php?page=hr_update_info" class="<?php echo $tp_page === 'hr_update_info' ? 'active' : ''; ?>">Update Information</a></li>
                    </ul>
                </li>
            </ul>
        </li>

        <!-- Result Module -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#tResultSubmenu" aria-expanded="<?php echo in_array($tp_page, ['result_exam_routine', 'result_marks_entry_subject', 'result_formative_entry', 'result_formative_report', 'teacher_questions'], true) ? 'true' : 'false'; ?>" title="Result Module">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-graduation-cap menu-icon"></i>
                    <span class="menu-text">Result Module</span>
                </span>
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu <?php echo in_array($tp_page, ['result_exam_routine', 'result_marks_entry_subject', 'result_formative_entry', 'result_formative_report', 'teacher_questions'], true) ? 'show' : ''; ?>" id="tResultSubmenu">
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#tExamProcedureSubmenu" aria-expanded="true" title="Exam Procedure">
                        <span>Exam Procedure</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested show" id="tExamProcedureSubmenu">
                        <li><a href="index.php?page=result_exam_routine" class="<?php echo $tp_page === 'result_exam_routine' ? 'active' : ''; ?>">Exam Routine</a></li>
                        <li><a href="index.php?page=result_marks_entry_subject" class="<?php echo $tp_page === 'result_marks_entry_subject' ? 'active' : ''; ?>">Marks Entry (Subject)</a></li>
                        <li><a href="index.php?page=result_formative_entry" class="<?php echo $tp_page === 'result_formative_entry' ? 'active' : ''; ?>">Formative / Class Test Entry</a></li>
                        <li><a href="index.php?page=result_formative_report" class="<?php echo $tp_page === 'result_formative_report' ? 'active' : ''; ?>">Formative / Class Test Report</a></li>
                        <li><a href="index.php?page=teacher_questions" class="<?php echo $tp_page === 'teacher_questions' ? 'active' : ''; ?>">Question Upload</a></li>
                    </ul>
                </li>
            </ul>
        </li>

        <!-- E-Learning Module -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#tElearningSubmenu" aria-expanded="<?php echo in_array($tp_page, ['homework', 'lesson_plan', 'live_class', 'recorded_class'], true) ? 'true' : 'false'; ?>" title="E-Learning Module">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-laptop menu-icon"></i>
                    <span class="menu-text">E-Learning Module</span>
                </span>
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu <?php echo in_array($tp_page, ['homework', 'lesson_plan', 'live_class', 'recorded_class'], true) ? 'show' : ''; ?>" id="tElearningSubmenu">
                <li><a href="index.php?page=homework" class="<?php echo $tp_page === 'homework' ? 'active' : ''; ?>">Homework</a></li>
                <li><a href="index.php?page=lesson_plan" class="<?php echo $tp_page === 'lesson_plan' ? 'active' : ''; ?>">Lesson Plan</a></li>
                <li><a href="index.php?page=live_class" class="<?php echo $tp_page === 'live_class' ? 'active' : ''; ?>">Live Class</a></li>
                <li><a href="index.php?page=recorded_class" class="<?php echo $tp_page === 'recorded_class' ? 'active' : ''; ?>">Recorded Class</a></li>
            </ul>
        </li>
    </ul>
</div>
