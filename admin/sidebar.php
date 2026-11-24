<?php
// User Permission ফিচার — Super Admin কোনো user-কে restrict করে থাকলে,
// এখান থেকেই সেই মডিউল sidebar-এ hide হয়ে যায় (আসল ব্লক index.php রাউটারে হয়,
// এটা শুধু cosmetic — restricted user URL সরাসরি টাইপ করলেও access_denied.php দেখবে)
$__perm_tree = require __DIR__ . '/inc/menu_permissions_tree.php';
function sb_mod_ok(PDO $pdo, array $tree, string $modkey): bool
{
    return perm_module_allowed($pdo, perm_flatten_module_keys($tree[$modkey]));
}
?>
<div class="sidebar" id="sidebar">
    <div class="sidebar-brand d-flex align-items-center justify-content-between px-3">
        <div class="d-flex align-items-center gap-2">
            <span class="brand-text fw-bold fs-5 text-white">School ERP</span>
        </div>
        <i class="fa-solid fa-bars fs-5 sidebar-toggle-btn text-white" id="toggleSidebar" style="cursor: pointer;"></i>
    </div>
    
    <div class="sidebar-search">
        <input type="text" class="form-control form-control-sm search-input" placeholder="Search for menu items...">
    </div>

    <ul class="sidebar-menu">
        <!-- Dashboard -->
        <li>
            <a href="index.php" class="<?php echo (!isset($_GET['page']) || $_GET['page'] == 'dashboard') ? 'active' : ''; ?>" title="Dashboard">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-house menu-icon"></i> 
                    <span class="menu-text">Dashboard</span>
                </span>
            </a>
        </li>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'global_settings')): ?>
        <!-- Global Settings -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#globalSettingsSubmenu" aria-expanded="false" title="Global Settings">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-gears menu-icon"></i> 
                    <span class="menu-text">Global Settings</span>
                </span> 
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="globalSettingsSubmenu">
                <li><a href="index.php?page=institute">Institute Settings</a></li>
                <li><a href="index.php?page=branch">Branch Settings</a></li>
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#settingsSubmenu" aria-expanded="false" title="Settings">
                        <span>Settings</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="settingsSubmenu">
                        <li><a href="index.php?page=sms_settings&tab=home">Home Page Settings</a></li>
                        <li><a href="index.php?page=sms_settings&tab=sms_toggle">SMS Settings (On/Off)</a></li>
                        <li><a href="index.php?page=sms_settings&tab=content">SMS Template Settings</a></li>
                        <li><a href="index.php?page=sms_settings&tab=api">SMS Gateway Settings</a></li>
                        <li><a href="index.php?page=whatsapp_settings">WhatsApp API Settings</a></li>
                        <li><a href="index.php?page=admission_payment_settings">Payment Gateway Settings</a></li>
                    </ul>
                </li>
                <li><a href="index.php?page=device_settings">Device Settings</a></li>
            </ul>
        </li>
        <?php endif; // perm: global_settings ?>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'academic')): ?>
        <!-- Academic Module -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#academicModuleSubmenu" aria-expanded="false" title="Academic Module">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-book-open menu-icon"></i> 
                    <span class="menu-text">Academic Module</span>
                </span> 
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="academicModuleSubmenu">
                <li><a href="index.php?page=academic_session">All Session</a></li>
                <li><a href="index.php?page=academic_medium">All Medium</a></li>
                <li><a href="index.php?page=academic_shift">All Shift List</a></li>
                <li><a href="index.php?page=academic_class">All Class List</a></li>
                <li><a href="index.php?page=academic_section">All Section List</a></li>
                <li><a href="index.php?page=academic_group">All Group List</a></li>
                <li><a href="index.php?page=academic_subject">All Subject List</a></li>
                <li><a href="index.php?page=academic_calendar">Academic Calendar</a></li>
            </ul>
        </li>
        <?php endif; // perm: academic ?>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'admission')): ?>
        <!-- Admission Module -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#admissionModuleSubmenu" aria-expanded="false" title="Admission Module">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-user-graduate menu-icon"></i> 
                    <span class="menu-text">Admission Module</span>
                </span> 
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="admissionModuleSubmenu">
                <li><a href="index.php?page=student_admission">Student Admission</a></li>
                <li><a href="index.php?page=print_admission_form">Print Admission Form</a></li>
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#admissionTestSubmenu" aria-expanded="false" title="Admission Test / Lottery">
                        <span>Admission Test / Lottery</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="admissionTestSubmenu">
                        <li><a href="index.php?page=admission_settings">Settings</a></li>
                        <li><a href="index.php?page=admission_applicants">Applied Student List</a></li>
                        <li><a href="index.php?page=admission_mark_entry">Mark Entry</a></li>
                        <li><a href="index.php?page=admission_publish">Result / Publish</a></li>
                        <li><a href="index.php?page=admission_seat_plan">Seat Plan</a></li>
                    </ul>
                </li>
                <li><a href="index.php?page=admission_fee_claims">Fee Claims / Payments</a></li>
            </ul>
        </li>
        <?php endif; // perm: admission ?>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'student')): ?>
        <!-- Student Module -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#studentModuleSubmenu" aria-expanded="false" title="Student Module">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-users menu-icon"></i> 
                    <span class="menu-text">Student Module</span>
                </span> 
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="studentModuleSubmenu">
                <li><a href="index.php?page=current_student_list">Current Student List</a></li>
                <li><a href="index.php?page=archive_student_list">Archive Student List</a></li>
                <li><a href="index.php?page=current_student_search">Current Student Search</a></li>
                <li><a href="index.php?page=archive_student_search">Archive Student Search</a></li>
                <li><a href="index.php?page=student_import">Import Students (Excel/CSV/TXT)</a></li>
                <li><a href="index.php?page=student_switch">Student Switch Process</a></li>
                <li><a href="index.php?page=student_branch_transfer">Student Branch Transfer</a></li>
                <li><a href="index.php?page=print_student_id_card">Print Students ID Card</a></li>
                <li><a href="index.php?page=student_biometric_export">Student Biometric Export</a></li>
                <li><a href="index.php?page=student_migration">Student Migration Process</a></li>
                <li><a href="index.php?page=print_student_testimonial">Print Student Testimonial</a></li>
                <li><a href="index.php?page=print_transfer_certificate">Print Student Transfer Certificate</a></li>
                <li><a href="index.php?page=print_certificate">Print Certificate(প্রত্যয়নপত্র)</a></li>
            </ul>
        </li>
        <?php endif; // perm: student ?>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'routine')): ?>
        <!-- Routine Module -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#routineModuleSubmenu" aria-expanded="false" title="Routine Module">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-calendar-days menu-icon"></i> 
                    <span class="menu-text">Routine Module</span>
                </span> 
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="routineModuleSubmenu">
                <li><a href="index.php?page=routine_general">General Routine</a></li>
                <li><a href="index.php?page=routine_dynamic">Dynamic Routine</a></li>
                <li><a href="index.php?page=routine_auto_generate">Smart Routine Creator</a></li>
                <li><a href="index.php?page=routine_view">View / Print Routine</a></li>
            </ul>
        </li>
        <?php endif; // perm: routine ?>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'attendance')): ?>
        <!-- Attendance Module -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#attendanceModuleSubmenu" aria-expanded="false" title="Attendance Module">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-clipboard-user menu-icon"></i> 
                    <span class="menu-text">Attendance Module</span>
                </span> 
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="attendanceModuleSubmenu">
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#studentAttendanceSubmenu" aria-expanded="false" title="Student Attendance">
                        <span>Student Attendance</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="studentAttendanceSubmenu">
                        <li><a href="index.php?page=attendance_today">Todays Attendance</a></li>
                        <li><a href="index.php?page=attendance_manual">Manual Attendance</a></li>
                        <li><a href="index.php?page=attendance_absent_list">Absent Student List</a></li>
                        <li><a href="index.php?page=attendance_details">Attendance Details</a></li>
                        <li><a href="index.php?page=attendance_daily_summary">Daily Attendance Summary</a></li>
                        <li><a href="index.php?page=attendance_monthly_summary">Monthly Attendance Summary</a></li>
                    </ul>
                </li>
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#employeeAttendanceSubmenu" aria-expanded="false" title="Employee Attendance">
                        <span>Employee Attendance</span>
                        <i class="fa-solid fa-angles-right small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="employeeAttendanceSubmenu">
                        <li><a href="index.php?page=employee_attendance_today">Todays Attendance</a></li>
                        <li><a href="index.php?page=employee_attendance_manual">Manual Attendance</a></li>
                        <li><a href="index.php?page=employee_attendance_details">Attendance Details</a></li>
                        <li><a href="index.php?page=employee_attendance_daily_summary">Daily Attendance Summary</a></li>
                        <li><a href="index.php?page=employee_attendance_monthly_summary">Monthly Attendance Summary</a></li>
                    </ul>
                </li>
            </ul>
        </li>
        <?php endif; // perm: attendance ?>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'hr')): ?>
        <!-- Human Resource -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#humanResourceSubmenu" aria-expanded="false" title="Human Resource">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-user-tie menu-icon"></i> 
                    <span class="menu-text">Human Resource</span>
                </span> 
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="humanResourceSubmenu">
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#hrModuleSubmenu" aria-expanded="false" title="HR Module">
                        <span>HR Module</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="hrModuleSubmenu">
                        <li><a href="index.php?page=hr_employee_type">Employee Type</a></li>
                        <li><a href="index.php?page=hr_designation">All Designation</a></li>
                        <li><a href="index.php?page=hr_working_shift">Working Shift</a></li>
                        <li><a href="index.php?page=hr_employee_add">Add Employee</a></li>
                        <li><a href="index.php?page=hr_employee_import">Import Employees (Excel/CSV/TXT)</a></li>
                        <li><a href="index.php?page=hr_employee_list">All Employee List</a></li>
                        <li><a href="index.php?page=hr_employee_search">Employees Search</a></li>
                        <li><a href="index.php?page=hr_employee_id_card">Employees ID Card</a></li>
                        <li><a href="index.php?page=hr_employee_export">Employees Export (Att.)</a></li>
                    </ul>
                </li>
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#leaveModuleSubmenu" aria-expanded="false" title="Leave Module">
                        <span>Leave Module</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="leaveModuleSubmenu">
                        <li><a href="index.php?page=leave_types">Leave Types</a></li>
                        <li><a href="index.php?page=leave_entry">Leave Entry</a></li>
                        <li><a href="index.php?page=leave_manage">Manage Leaves</a></li>
                    </ul>
                </li>
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#payrollModuleSubmenu" aria-expanded="false" title="Payroll Module">
                        <span>Payroll Module</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="payrollModuleSubmenu">
                        <li><a href="index.php?page=payroll_head">Payroll Head</a></li>
                        <li><a href="index.php?page=payroll_employee_payscale">Employee Payscale</a></li>
                        <li><a href="index.php?page=payroll_salary_chart">Employee Salary Chart</a></li>
                        <li><a href="index.php?page=payroll_generate_salary_sheet">Generate Salary Sheet</a></li>
                        <li><a href="index.php?page=payroll_salary_payment">Employee Salary Payment</a></li>
                        <li><a href="index.php?page=payroll_salary_report">Employee Salary Report</a></li>
                        <li><a href="index.php?page=payroll_payslip_print">Employee Pay Slip Print</a></li>
                    </ul>
                </li>
            </ul>
        </li>
        <?php endif; // perm: hr ?>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'result')): ?>
        <!-- Result Module -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#resultModuleSubmenu" aria-expanded="false" title="Result Module">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-award menu-icon"></i> 
                    <span class="menu-text">Result Module</span>
                </span> 
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="resultModuleSubmenu">
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#examSettingsSubmenu" aria-expanded="false" title="Exam Settings">
                        <span>Exam Settings</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="examSettingsSubmenu">
                        <li><a href="index.php?page=result_settings">Master Settings</a></li>
                        <li><a href="index.php?page=result_grade_points">Grade Points</a></li>
                        <li><a href="index.php?page=result_exam_terms">Exam Terms</a></li>
                        <li><a href="index.php?page=result_term_settings">Term Publish Settings</a></li>
                        <li><a href="index.php?page=result_exam_parts">Exam Parts</a></li>
                        <li><a href="index.php?page=result_lock_control">Result Lock/Unlock</a></li>
                        <li><a href="index.php?page=result_assign_exams">Assign Exams</a></li>
                        <li><a href="index.php?page=result_formative_settings">Formative / Class Test</a></li>
                        <li><a href="index.php?page=result_assign_subject_teacher">Assign Subject to Teacher</a></li>
                        <li><a href="index.php?page=result_working_days">Working Days</a></li>
                    </ul>
                </li>
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#examProcedureSubmenu" aria-expanded="false" title="Exam Procedure">
                        <span>Exam Procedure</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="examProcedureSubmenu">
                        <li><a href="index.php?page=result_exam_routine">Exam Routine</a></li>
                        <li><a href="index.php?page=result_exam_eligibility">Exam Eligibility</a></li>
                        <li><a href="index.php?page=result_attendance_subject">Attendance (Subject)</a></li>
                        <li><a href="index.php?page=result_attendance_exam">Attendance (Exam)</a></li>
                        <li><a href="index.php?page=result_exam_seat_plan">Exam Seat Plan</a></li>
                        <li><a href="index.php?page=result_marks_entry_subject">Marks Entry (Subject)</a></li>
                        <li><a href="index.php?page=result_formative_entry">Formative / Class Test Entry</a></li>
                        <li><a href="index.php?page=result_formative_report">Formative / Class Test Report</a></li>
                        <li><a href="index.php?page=result_marks_entry_exam">Marks Entry (Exam)</a></li>
                        <li><a href="index.php?page=result_exam_fee_settings">Exam Fee Gateway Settings</a></li>
                        <li><a href="index.php?page=result_exam_fee_collection">Exam Fee Online Payments</a></li>
                    </ul>
                </li>
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#resultReportsSubmenu" aria-expanded="false" title="Result Reports">
                        <span>Result Reports</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="resultReportsSubmenu">
                        <li><a href="index.php?page=result_download_admit_card">Download Admit Card</a></li>
                        <li><a href="index.php?page=result_admit_card_customize">Customize Admit Card</a></li>
                        <li><a href="index.php?page=result_attendance_blank_sheet">Attendance Blank Sheet</a></li>
                        <li><a href="index.php?page=result_marks_entry_blank_sheet">Marks Entry Blank Sheet</a></li>
                        <li><a href="index.php?page=result_tabulation_sheet">Tabulation Sheet</a></li>
                        <li><a href="index.php?page=result_students_merit_list">Students Merit List</a></li>
                        <li><a href="index.php?page=result_students_report_card">Students Report Card</a></li>
                        <li><a href="index.php?page=result_final_aggregated_result">Final Aggregated Result</a></li>
                        <li><a href="index.php?page=result_subject_analysis">Subject-wise Analysis</a></li>
                        <li><a href="index.php?page=result_student_progress">Student Progress Tracking</a></li>
                        <li><a href="index.php?page=result_section_comparison">Class/Section Comparison</a></li>
                        <li><a href="index.php?page=result_performer_alert">Top/Bottom Performer Alert</a></li>
                        <li><a href="index.php?page=result_marks_pending">Marks Entry Pending Status</a></li>
                        <li><a href="index.php?page=result_bulk_pdf_export">Bulk Result PDF Export</a></li>
                        <li><a href="index.php?page=result_multiyear_cgpa">Multi-Year CGPA</a></li>
                    </ul>
                </li>
            </ul>
        </li>
        <?php endif; // perm: result ?>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'finance')): ?>
        <!-- Finance Module -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#financeModuleSubmenu" aria-expanded="false" title="Finance Module">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-wallet menu-icon"></i> 
                    <span class="menu-text">Finance Module</span>
                </span> 
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="financeModuleSubmenu">
                <li><a href="index.php?page=finance_head">Accounts Head</a></li>
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#studentAccountsSubmenu" aria-expanded="false" title="Student Accounts">
                        <span>Student Accounts</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="studentAccountsSubmenu">
                        <li><a href="index.php?page=finance_admission_fees">Admission Fees</a></li>
                        <li><a href="index.php?page=finance_monthly_fees">Monthly Fees</a></li>
                        <li><a href="index.php?page=finance_student_fees">Students Fees Settings</a></li>
                        <li><a href="index.php?page=finance_student_waiver">Students Wise Waiver</a></li>
                        <li><a href="index.php?page=finance_quick_collection">Student Fees Collection (Quick)</a></li>
                        <li><a href="index.php?page=finance_fees_collection">Student Fees Collection</a></li>
                        <li><a href="index.php?page=finance_online_payments">Online Fee Payments</a></li>
                        <li><a href="index.php?page=finance_fees_recollection">Fees Re-collection</a></li>
                        <li><a href="index.php?page=finance_collection_invoice_list">Money Receipt List</a></li>
                        <li><a href="index.php?page=finance_money_receipt_settings">Money Receipt Settings</a></li>
                    </ul>
                </li>
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#accountsVoucherSubmenu" aria-expanded="false" title="Accounts Voucher">
                        <span>Accounts Voucher</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="accountsVoucherSubmenu">
                        <li><a href="index.php?page=finance_receive_voucher">Receive Voucher (+)</a></li>
                        <li><a href="index.php?page=finance_payment_voucher">Payment Voucher (-)</a></li>
                        <li><a href="index.php?page=finance_vouchers_list">Vouchers List (+/-)</a></li>
                    </ul>
                </li>
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#financeReportsSubmenu" aria-expanded="false" title="Finance Reports">
                        <span>Finance Reports</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="financeReportsSubmenu">
                        <li><a href="index.php?page=finance_daily_collection">Daily Collection</a></li>
                        <li><a href="index.php?page=finance_report_fees_collection">Fees Collection</a></li>
                        <li><a href="index.php?page=finance_students_dues">Students Dues</a></li>
                        <li><a href="index.php?page=finance_students_ledger">Students Ledger</a></li>
                        <li><a href="index.php?page=finance_report_students_waiver">Students Waiver</a></li>
                        <li><a href="index.php?page=finance_accounts_ledger">Accounts Ledger</a></li>
                        <li><a href="index.php?page=finance_trial_balance">Trial Balance</a></li>
                        <li><a href="index.php?page=finance_cash_book">Cash Book</a></li>
                        <li><a href="index.php?page=finance_bank_book">Bank Book</a></li>
                        <li><a href="index.php?page=finance_balance_sheet">Balance Sheet</a></li>
                        <li><a href="index.php?page=finance_payable_receivable">Payable / Receivable</a></li>
                    </ul>
                </li>
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#scholarshipWelfareSubmenu" aria-expanded="false" title="Scholarship &amp; Welfare">
                        <span>Scholarship &amp; Welfare</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="scholarshipWelfareSubmenu">
                        <li><a href="index.php?page=scholarship_applications">Scholarship Applications</a></li>
                        <li><a href="index.php?page=scholarship_types">Scholarship Types</a></li>
                        <li><a href="index.php?page=scholarship_award">Assign Scholarship</a></li>
                        <li><a href="index.php?page=scholarship_disbursement">Process Disbursement</a></li>
                        <li><a href="index.php?page=scholarship_report">Scholarship Report</a></li>
                        <li><a href="index.php?page=poor_fund_aid">Poor Fund / Charity Aid</a></li>
                    </ul>
                </li>
            </ul>
        </li>
        <?php endif; // perm: finance ?>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'question_management')): ?>
        <!-- Question Management -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#questionManagementSubmenu" aria-expanded="false" title="Question Management">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-list-check menu-icon"></i>
                    <span class="menu-text">Question Management</span>
                </span>
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="questionManagementSubmenu">
                <li><a href="index.php?page=result_question_create">Create Question</a></li>
                <li><a href="index.php?page=result_question_list">Question List</a></li>
                <li><a href="index.php?page=teacher_questions">Teacher Questions</a></li>
            </ul>
        </li>
        <?php endif; // perm: question_management ?>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'elearning')): ?>
        <!-- E-Learning Module -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#elearningModuleSubmenu" aria-expanded="false" title="E-Learning Module">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-laptop menu-icon"></i>
                    <span class="menu-text">E-Learning Module</span>
                </span>
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="elearningModuleSubmenu">
                <li><a href="index.php?page=homework">Homework</a></li>
                <li><a href="index.php?page=lesson_plan">Lesson Plan</a></li>
                <li><a href="index.php?page=live_class">Live Class</a></li>
                <li><a href="index.php?page=live_class_settings">Live Class Setting</a></li>
                <li><a href="index.php?page=recorded_class">Recorded Class</a></li>
            </ul>
        </li>
        <?php endif; // perm: elearning ?>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'website')): ?>
        <!-- Website Module -->
        <?php
        // sidebar-এ "Govt Theme Settings" সাব-সেকশনটা শুধু তখনই দেখানো হয় যখন Institute
        // Settings > Website Theme = "Govt Theme-1" সিলেক্ট করা থাকে — অন্য থিমে (E-Learning/
        // Standard) এই অপশনগুলো কোনো কাজে লাগে না, তাই সিলেক্ট করলেই স্বয়ংক্রিয়ভাবে দেখা/লুকানো যায়।
        $sb_is_govt_theme = false;
        try {
            $sb_theme_row = $pdo->query("SELECT website_theme FROM institute_settings WHERE id = 1")->fetch();
            $sb_is_govt_theme = ($sb_theme_row['website_theme'] ?? '') === 'Govt Theme-1';
        } catch (PDOException $e) {
            $sb_is_govt_theme = false;
        }
        ?>
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#websiteModuleSubmenu" aria-expanded="false" title="Website Module">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-globe menu-icon"></i>
                    <span class="menu-text">Website Module</span>
                </span>
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="websiteModuleSubmenu">
                <li><a href="index.php?page=website_slider">Slider List</a></li>
                <li><a href="index.php?page=website_message">Message Corner</a></li>
                <li><a href="index.php?page=website_why_choose">Why Choose Image</a></li>
                <li><a href="index.php?page=website_menu">Menu List</a></li>
                <li><a href="index.php?page=website_content">Content List</a></li>
                <li><a href="index.php?page=website_breaking_news">Breaking News</a></li>
                <li><a href="index.php?page=website_album">Album List</a></li>
                <li><a href="index.php?page=website_gallery">Gallery List</a></li>
                <li><a href="index.php?page=website_news">News List</a></li>
                <li><a href="index.php?page=website_events">Events List</a></li>
                <li><a href="index.php?page=website_facilities">Facilities List</a></li>
                <li><a href="index.php?page=website_notice">Notice List</a></li>
                <li><a href="index.php?page=website_career">Career List</a></li>
                <li><a href="index.php?page=website_board_result">Board Result</a></li>
                <li><a href="index.php?page=website_useful_links">Useful Links</a></li>
                <li><a href="index.php?page=website_social">Social Media</a></li>
                <?php if ($sb_is_govt_theme): ?>
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#govtThemeSubmenu" aria-expanded="false" title="Govt Theme Settings">
                        <span><i class="fa-solid fa-landmark me-1"></i> Govt Theme Settings</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="govtThemeSubmenu">
                        <li><a href="index.php?page=website_govt_apa">এপিএ (APA)</a></li>
                        <li><a href="index.php?page=website_govt_suddhachar">শুদ্ধাচার কৌশল</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; // perm: website ?>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'promotional')): ?>
        <!-- Promotional Module -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#promotionalModuleSubmenu" aria-expanded="false" title="Promotional Module">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-bullhorn menu-icon"></i> 
                    <span class="menu-text">Promotional Module</span>
                </span> 
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="promotionalModuleSubmenu">
                <li><a href="index.php?page=promo_groups">My Groups</a></li>
                <li><a href="index.php?page=promo_contacts">My Contacts</a></li>
                <li><a href="index.php?page=promo_import_contacts">Import Contacts</a></li>
                <li><a href="index.php?page=promo_send_sms">Send Custom SMS</a></li>
                <li><a href="index.php?page=promo_sms_report">SMS Details Report</a></li>
            </ul>
        </li>
        <?php endif; // perm: promotional ?>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'accounts')): ?>
        <!-- Accounts Module (Double-Entry Accounting — Finance Module থেকে আলাদা) -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#accountsModuleSubmenu" aria-expanded="false" title="Accounts Module">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-calculator menu-icon"></i>
                    <span class="menu-text">Accounts Module</span>
                </span>
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="accountsModuleSubmenu">
                <li><a href="index.php?page=accounts_chart">Chart of Accounts</a></li>
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#accountsVouchersSubmenu" aria-expanded="false" title="Vouchers">
                        <span>Vouchers</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="accountsVouchersSubmenu">
                        <li><a href="index.php?page=accounts_journal_voucher">Journal Voucher</a></li>
                        <li><a href="index.php?page=accounts_receipt_voucher">Receipt Voucher</a></li>
                        <li><a href="index.php?page=accounts_payment_voucher">Payment Voucher</a></li>
                        <li><a href="index.php?page=accounts_contra_voucher">Contra Voucher</a></li>
                    </ul>
                </li>
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#accountsReportsSubmenu" aria-expanded="false" title="Reports">
                        <span>Reports</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="accountsReportsSubmenu">
                        <li><a href="index.php?page=accounts_ledger">General Ledger</a></li>
                        <li><a href="index.php?page=accounts_trial_balance">Trial Balance</a></li>
                        <li><a href="index.php?page=accounts_profit_loss">Profit &amp; Loss</a></li>
                        <li><a href="index.php?page=accounts_balance_sheet">Balance Sheet</a></li>
                    </ul>
                </li>
                <li class="has-submenu">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#accountsPayableSubmenu" aria-expanded="false" title="Accounts Payable">
                        <span>Accounts Payable</span>
                        <i class="fa-solid fa-angles-down small"></i>
                    </a>
                    <ul class="collapse submenu submenu-nested" id="accountsPayableSubmenu">
                        <li><a href="index.php?page=accounts_vendors">Vendors</a></li>
                        <li><a href="index.php?page=accounts_vendor_bills">Vendor Bills</a></li>
                    </ul>
                </li>
            </ul>
        </li>
        <?php endif; // perm: accounts ?>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'library')): ?>
        <!-- Library Module -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#libraryModuleSubmenu" aria-expanded="false" title="Library Module">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-book-bookmark menu-icon"></i>
                    <span class="menu-text">Library Module</span>
                </span>
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="libraryModuleSubmenu">
                <li><a href="index.php?page=library_books">Book Management</a></li>
                <li><a href="index.php?page=library_categories">Book Categories</a></li>
                <li><a href="index.php?page=library_issue">Issue Book</a></li>
                <li><a href="index.php?page=library_return">Return Book & Fine</a></li>
                <li><a href="index.php?page=library_reports">Reports</a></li>
                <li><a href="index.php?page=library_settings">Library Settings</a></li>
            </ul>
        </li>
        <?php endif; // perm: library ?>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'transport')): ?>
        <!-- Transport Module -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#transportModuleSubmenu" aria-expanded="false" title="Transport Module">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-bus menu-icon"></i>
                    <span class="menu-text">Transport Module</span>
                </span>
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="transportModuleSubmenu">
                <li><a href="index.php?page=transport_vehicles">Vehicle Management</a></li>
                <li><a href="index.php?page=hr_employee_list&tab=transport">Driver & Helper (HR Module)</a></li>
                <li><a href="index.php?page=transport_routes">Route Management</a></li>
                <li><a href="index.php?page=transport_assign_student">Assign Student</a></li>
                <li><a href="index.php?page=transport_assign_staff">Assign Staff</a></li>
                <li><a href="index.php?page=transport_fees">Fee Collection</a></li>
                <li><a href="index.php?page=transport_live_map"><i class="fa-solid fa-satellite-dish"></i> Live GPS Map</a></li>
                <li><a href="index.php?page=transport_gps_settings">GPS Settings (Traccar)</a></li>
            </ul>
        </li>
        <?php endif; // perm: transport ?>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'inventory')): ?>
        <!-- Inventory & Sales -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#inventoryModuleSubmenu" aria-expanded="false" title="Inventory & Sales">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-shop menu-icon"></i> 
                    <span class="menu-text">Inventory & Sales</span>
                </span> 
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="inventoryModuleSubmenu">
                <li><a href="index.php?page=inventory_items">Items</a></li>
                <li><a href="index.php?page=inventory_purchase">Purchase</a></li>
                <li><a href="index.php?page=inventory_sales">Sales (POS)</a></li>
                <li><a href="index.php?page=inventory_sales_return">Sales Return</a></li>
                <li><a href="index.php?page=inventory_due">Due Collection</a></li>
                <li><a href="index.php?page=inventory_supplier">Supplier Payment</a></li>
                <li><a href="index.php?page=inventory_adjustment">Stock Adjustment</a></li>
                <li><a href="index.php?page=inventory_reports">Reports</a></li>
                <li><a href="index.php?page=inventory_settings">Settings</a></li>
            </ul>
        </li>
        <?php endif; // perm: inventory ?>

        <?php if (sb_mod_ok($pdo, $__perm_tree, 'canteen')): ?>
        <!-- Canteen Module -->
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#canteenModuleSubmenu" aria-expanded="false" title="Canteen Module">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-utensils menu-icon"></i>
                    <span class="menu-text">Canteen Module</span>
                </span>
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="canteenModuleSubmenu">
                <li><a href="index.php?page=canteen_pos">Sales (POS)</a></li>
                <li><a href="index.php?page=canteen_due">Due Collection</a></li>
                <?php if (!empty($_SESSION['is_super_admin']) || ($_SESSION['user_role'] ?? '') === 'Admin'): ?>
                    <li><a href="index.php?page=canteen_menu">Items</a></li>
                    <li><a href="index.php?page=canteen_raw_materials">Raw Materials</a></li>
                    <li><a href="index.php?page=canteen_purchase">Purchase</a></li>
                    <li><a href="index.php?page=canteen_supplier">Supplier Payment</a></li>
                    <li><a href="index.php?page=canteen_stock_adjustment">Stock Adjustment</a></li>
                    <li><a href="index.php?page=canteen_reports">Reports</a></li>
                    <li><a href="index.php?page=canteen_settings">Settings</a></li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; // perm: canteen ?>

        <!-- User Management -->
        <?php
            $um_is_super  = $_SESSION['is_super_admin'] ?? true;
            $um_is_admin  = ($_SESSION['user_role'] ?? '') === 'Admin'; // system_users এর মধ্যে 'Admin' টাইপ (Super Admin না)
            $um_can_view  = $um_is_super || $um_is_admin;
        ?>
        <?php if ($um_can_view): ?>
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#userManagementSubmenu" aria-expanded="false" title="User Management">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-user-shield menu-icon"></i> 
                    <span class="menu-text">User Management</span>
                </span> 
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="userManagementSubmenu">
                <!-- Super Admin এবং 'Admin' টাইপ system_user — দুজনেই এই ৩টা ডিফল্ট অপশন দেখবে -->
                <li><a href="index.php?page=users_list">User List</a></li>
                <li><a href="index.php?page=sms_users_students">SMS User (Students)</a></li>
                <li><a href="index.php?page=sms_users_employees">SMS User (Employees)</a></li>
                <!-- User Permission: শুধু Super Admin দেখবে/ব্যবহার করবে, 'Admin' টাইপ system_user না -->
                <?php if ($um_is_super || $um_is_admin): ?>
                <li><a href="index.php?page=user_permissions">User Permission</a></li>
                <li><a href="index.php?page=user_permissions&mode=role">Role Permission</a></li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>

        <!-- System & Security — শুধু Super Admin: লগইন/সেশন লগ, এই মুহূর্তে কে Active আছে
             (Force Logout করার অপশন সহ), Session Timeout সেটিংস, আর সবার Activity Log -->
        <?php if ($um_is_super): ?>
        <li class="has-submenu">
            <a href="#" data-bs-toggle="collapse" data-bs-target="#systemSecuritySubmenu" aria-expanded="false" title="System &amp; Security">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-shield-halved menu-icon"></i>
                    <span class="menu-text">System &amp; Security</span>
                </span>
                <i class="fa-solid fa-chevron-down small menu-text"></i>
            </a>
            <ul class="collapse submenu" id="systemSecuritySubmenu">
                <li><a href="index.php?page=login_logs">Login / Session Log</a></li>
                <li><a href="index.php?page=security_active_sessions">Active Sessions</a></li>
                <li><a href="index.php?page=security_settings">Session Timeout Settings</a></li>
                <li><a href="index.php?page=security_activity_log">Activity Log</a></li>
            </ul>
        </li>
        <?php endif; ?>

        <!-- Database Reset -->
        <?php if ($_SESSION['is_super_admin'] ?? true): ?>
        <li>
            <a href="index.php?page=database_reset" class="<?php echo (($_GET['page'] ?? '') === 'database_reset') ? 'active' : ''; ?>" title="Database Reset">
                <span class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-triangle-exclamation menu-icon text-danger"></i>
                    <span class="menu-text text-danger">Database Reset</span>
                </span>
            </a>
        </li>
        <?php endif; ?>
    </ul>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebarMenu = document.querySelector('.sidebar-menu');
    if (!sidebarMenu) return;

    // ---------- 1) Bortoman page onujayi active link + submenu open kora ----------
    var currentUrl  = new URL(window.location.href);
    var currentPage = currentUrl.searchParams.get('page') || 'dashboard';
    var currentTab  = currentUrl.searchParams.get('tab');

    var matchedLink = null;
    sidebarMenu.querySelectorAll('a[href]').forEach(function (link) {
        var href = link.getAttribute('href');
        if (!href || href.charAt(0) === '#') return;

        var linkUrl;
        try { linkUrl = new URL(href, window.location.href); } catch (e) { return; }

        var linkPage = linkUrl.searchParams.get('page');
        var linkTab  = linkUrl.searchParams.get('tab');
        if (!linkPage) return;

        if (linkPage === currentPage && (linkTab || null) === (currentTab || null)) {
            matchedLink = link;
        }
    });

    if (matchedLink) {
        matchedLink.classList.add('active');

        // Upore uthe protita parent submenu open rakha o toggle button "expanded" dekhano
        var li = matchedLink.closest('li');
        while (li) {
            var parentUl = li.parentElement && li.parentElement.closest('ul.collapse.submenu');
            if (!parentUl) break;

            parentUl.classList.add('show');

            var parentLi = parentUl.closest('li.has-submenu');
            if (parentLi) {
                var toggleLink = parentLi.querySelector(':scope > a[data-bs-toggle="collapse"]');
                if (toggleLink) {
                    toggleLink.setAttribute('aria-expanded', 'true');
                    toggleLink.classList.add('active-parent');
                }
            }
            li = parentLi;
        }
    }

    // ---------- 2) Ekta module/submenu open korle, sei level er onno gula close hoye jabe ----------
    sidebarMenu.querySelectorAll('ul.collapse.submenu').forEach(function (submenu) {
        submenu.addEventListener('show.bs.collapse', function (evt) {
            if (evt.target !== submenu) return; // nested submenu er bubbling ignore

            var siblingContainer = submenu.parentElement.parentElement; // li -> ul (same level er sob li dhore)
            if (!siblingContainer) return;

            siblingContainer.querySelectorAll(':scope > li.has-submenu > ul.collapse.submenu.show').forEach(function (openSubmenu) {
                if (openSubmenu === submenu) return;
                var instance = bootstrap.Collapse.getOrCreateInstance(openSubmenu, { toggle: false });
                instance.hide();
            });
        });
    });
});
</script>