<?php
/**
 * demo/auto_login.php
 * -----------------------------------------------------------
 * Seamless 1-Click Authenticator for Demo Sandbox.
 * Automatically provisions session state for chosen role and
 * routes directly into the appropriate ERP portal.
 */

session_start();

// Enforce tenant as 'demo'
$_SESSION['tenant_school_code'] = 'demo';
$_GET['school'] = 'demo';

// Require tenant database connection
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security_helpers.php';
require_once __DIR__ . '/../cron/reset_demo_db.php';

// Self-healing reset: If more than 3600 seconds (1 hour) since last reset, auto-refresh DB
$timestamp_file = __DIR__ . '/../cron/demo_last_reset.txt';
$last_reset = file_exists($timestamp_file) ? (int) @file_get_contents($timestamp_file) : 0;
if ((time() - $last_reset) >= 3600) {
    reset_demo_database(true);
}

$role = strtolower(trim($_GET['role'] ?? 'admin'));

switch ($role) {
    case 'teacher':
        // Clear conflicting admin/student sessions
        unset($_SESSION['admin_logged'], $_SESSION['student_logged']);

        // Query active demo teacher
        $t_stmt = $pdo->prepare("SELECT * FROM employees WHERE status = 'Active' ORDER BY id ASC LIMIT 1");
        $t_stmt->execute();
        $t_row = $t_stmt->fetch();

        session_regenerate_id(true);
        $_SESSION['teacher_logged']      = true;
        $_SESSION['teacher_id']          = (int) ($t_row['id'] ?? 1);
        $_SESSION['teacher_employee_id'] = $t_row['employee_id'] ?? 'EMP-01';
        $_SESSION['teacher_name']        = $t_row['name'] ?? 'Dr. Mohammad Rafiqul Islam';
        $_SESSION['teacher_photo']       = $t_row['photo_path'] ?? null;
        $_SESSION['t_login_attempts']    = 0;
        setcookie('tp_seen_first_login_note', '1', time() + 60 * 60 * 24 * 365, '/');

        header('Location: ../teacher/index.php?school=demo');
        exit;

    case 'student':
        // Clear conflicting admin/teacher sessions
        unset($_SESSION['admin_logged'], $_SESSION['teacher_logged']);

        // Query active demo student
        $s_stmt = $pdo->prepare("SELECT * FROM students WHERE status = 'Active' ORDER BY id ASC LIMIT 1");
        $s_stmt->execute();
        $s_row = $s_stmt->fetch();

        session_regenerate_id(true);
        $_SESSION['student_logged']   = true;
        $_SESSION['student_pk']       = (int) ($s_row['id'] ?? 1);
        $_SESSION['student_code']     = $s_row['student_id'] ?? 'STD-26-001';
        $_SESSION['student_name']     = $s_row['name_en'] ?? 'Ayman Zarif';
        $_SESSION['s_login_attempts'] = 0;
        setcookie('sp_seen_first_login_note', '1', time() + 60 * 60 * 24 * 365, '/');

        header('Location: ../student/index.php?school=demo');
        exit;

    case 'accountant':
        // Clear teacher/student sessions
        unset($_SESSION['teacher_logged'], $_SESSION['student_logged']);

        session_regenerate_id(true);
        $_SESSION['admin_logged']     = true;
        $_SESSION['admin_user']       = 'demo_accountant';
        $_SESSION['is_super_admin']   = false;
        $_SESSION['user_role']        = 'Accountant';
        $_SESSION['active_branch_id'] = 1;
        $_SESSION['institute_branch'] = 'Smart Model Academy & College (Live Demo)';
        $_SESSION['login_attempts']   = 0;

        sec_ensure_tables($pdo);
        sec_start_session($pdo, 'demo_accountant', 'Accountant');

        header('Location: ../admin/index.php?school=demo');
        exit;

    case 'website':
        header('Location: ../index.php?school=demo');
        exit;

    case 'admin':
    default:
        // Clear teacher/student sessions
        unset($_SESSION['teacher_logged'], $_SESSION['student_logged']);

        session_regenerate_id(true);
        $_SESSION['admin_logged']     = true;
        $_SESSION['admin_user']       = 'demo_admin';
        $_SESSION['is_super_admin']   = true;
        $_SESSION['user_role']        = 'Super Admin';
        $_SESSION['active_branch_id'] = 1;
        $_SESSION['institute_branch'] = 'Smart Model Academy & College (Live Demo)';
        $_SESSION['login_attempts']   = 0;

        sec_ensure_tables($pdo);
        sec_start_session($pdo, 'demo_admin', 'Super Admin');

        header('Location: ../admin/index.php?school=demo');
        exit;
}
