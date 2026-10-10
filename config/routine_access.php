<?php
require_once __DIR__ . '/portal_access.php';
/** Authorize the record and its destination before routine editors mutate it. */
function erp_routine_access(PDO $pdo, string $page): void
{
    erp_portal_controller_role(['admin']);
    erp_portal_require_role($pdo, 'admin', $page);
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? $_GET['delete'] ?? 0);
    if ($id > 0) {
        [$scope, $params] = erp_portal_branch_scope($pdo, 'admin', 'branch_id');
        $q = $pdo->prepare("SELECT id FROM routines WHERE id=? AND $scope");
        $q->execute(array_merge([$id], $params));
        if (!$q->fetchColumn()) { erp_stop(404, 'Routine unavailable.'); }
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_POST['save_routine']) || !csrf_verify($_POST['csrf_token'] ?? null)) { return; }
    $class = (int) ($_POST['class_id'] ?? 0);
    $shift = (int) ($_POST['shift_id'] ?? 0);
    $section = (int) ($_POST['section_id'] ?? 0);
    $break = ($_POST['has_break'] ?? 'NO') === 'YES';
    $teacher = $break ? null : (int) ($_POST['teacher_id'] ?? 0);
    try {
        lp_destination_branch($pdo, 'admin', $class, $section, $teacher);
        $q = $pdo->prepare("SELECT id FROM academic_sections WHERE id=? AND class_id=? AND shift_id=? AND status='Active'");
        $q->execute([$section, $class, $shift]);
        if (!$q->fetchColumn()) { throw new RuntimeException('Invalid section or shift.'); }
        if (!$break) {
            $q = $pdo->prepare("SELECT id FROM academic_subjects WHERE id=? AND class_id=? AND status='Active'");
            $q->execute([(int) ($_POST['subject_id'] ?? 0), $class]);
            if (!$q->fetchColumn()) { throw new RuntimeException('Invalid subject.'); }
        }
        foreach (['start_time', 'end_time'] as $key) {
            $value = (string) ($_POST[$key] ?? '');
            if ($value !== '' && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/', $value)) { throw new RuntimeException('Invalid time.'); }
        }
        if (!empty($_POST['start_time']) && !empty($_POST['end_time']) && $_POST['start_time'] >= $_POST['end_time']) { throw new RuntimeException('End time must follow start time.'); }
    } catch (RuntimeException $e) { erp_stop(422, $e->getMessage()); }
}
