<?php
// Shared access checks for school portal controllers and standalone downloads/printing.
require_once __DIR__ . '/runtime.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/security_helpers.php';
require_once __DIR__ . '/lesson_plan_helpers.php';

function erp_portal_require_role(PDO $pdo, string $role, string|array $permissions): ?array
{
    if ($role === 'admin') {
        if (empty($_SESSION['admin_logged']) || empty($_SESSION['admin_user'])) {
            erp_stop(403, 'Sign in to the school admin portal first.');
        }
        if (!empty($_SESSION['is_super_admin'])) {
            $q = $pdo->prepare('SELECT id FROM admin WHERE username=?');
            $q->execute([$_SESSION['admin_user']]);
        } else {
            $q = $pdo->prepare("SELECT id FROM system_users WHERE id=? AND username=? AND status='Active' AND (expires_at IS NULL OR expires_at>NOW())");
            $q->execute([(int) ($_SESSION['system_user_id'] ?? 0), $_SESSION['admin_user']]);
        }

        $allowed = false;
        foreach ((array) $permissions as $permission) { $allowed = $allowed || perm_page_allowed($pdo, $permission); }
        if (!$q->fetchColumn() || !$allowed) {
            erp_stop(403, 'You do not have permission to access this feature.');
        }
        if (function_exists('sec_enforce_session') && sec_enforce_session($pdo) !== null) {
            erp_stop(403, 'Your admin session has expired. Sign in again.');
        }
        return null;
    }
    $table = $role === 'teacher' ? 'employees' : 'students';
    $key = $role === 'teacher' ? 'teacher_id' : 'student_pk';
    if (!in_array($role, ['teacher', 'student'], true) || empty($_SESSION[$role . '_logged']) || empty($_SESSION[$key])) {
        erp_stop(403, 'Sign in to the selected school portal first.');
    }
    $q = $pdo->prepare("SELECT * FROM $table WHERE id=? AND status='Active'" . ($role === 'teacher' ? " AND type='Teachers'" : ''));
    $q->execute([(int) $_SESSION[$key]]);
    $row = $q->fetch();
    if (!$row || (!erp_is_demo() && !empty($row['must_change_password']))) {
        erp_stop(403, 'Use an active account and change its initial password before accessing this feature.');
    }
    return $row;
}

function erp_portal_controller_role(array $allowed): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    foreach ($allowed as $role) {
        if (preg_match('~/' . $role . '/index\.php$~', $script)) { return $role; }
    }
    erp_stop(404, 'Open this feature from your school portal.');
}

function erp_portal_link_role(array $allowed): string
{
    $role = (string) ($_GET['portal'] ?? '');
    if ($role === '') {
        $logged = array_filter($allowed, fn($r) => !empty($_SESSION[$r . '_logged']));
        if (count($logged) !== 1) { erp_stop(403, 'Open this link from the selected school portal.'); }
        $role = array_values($logged)[0];
    }
    if (!in_array($role, $allowed, true)) { erp_stop(403, 'Choose a valid school portal.'); }
    return $role;
}

/** A NULL branch never grants an unbound branch admin access to every branch. */
function erp_portal_branch_scope(PDO $pdo, string $role, string $column): array
{
    $branch = lp_role_branch($pdo, $role);
    if ($branch !== null) { return ["$column=?", [$branch]]; }
    return [$role === 'admin' && !empty($_SESSION['is_super_admin']) ? '1=1' : '0=1', []];
}

function erp_portal_student_scope(PDO $pdo, string $role, string $student_column): array
{
    if ($role === 'student') { return ["$student_column=?", [(int) $_SESSION['student_pk']]]; }
    [$branch, $params] = erp_portal_branch_scope($pdo, $role, 'scope_student.branch_id');
    return ["$student_column IN (SELECT scope_student.id FROM students scope_student WHERE $branch)", $params];
}
