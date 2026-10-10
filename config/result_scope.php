<?php
require_once __DIR__ . '/branch_scope.php';
require_once __DIR__ . '/lesson_plan_helpers.php';
/** Never trust student IDs supplied by a grid, import or forged request. */
function result_scope_students(PDO $pdo, array $ids, int $class, int $shift, int $section): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    if (!$ids) { return []; }
    $where = ['class_id=?', 'shift_id=?', 'section_id=?', "status='Active'"];
    $params = [$class, $shift, $section];
    $branch = branch_current_id();
    if (defined('PORTAL_BRANCH_ID')) {
        $branch = lp_role_branch($pdo, !empty($_SESSION['teacher_logged']) ? 'teacher' : 'student');
    }
    if ($branch !== null) { $where[] = 'branch_id=?'; $params[] = $branch; }
    elseif (!branch_is_super_admin()) { return []; }
    $where[] = 'id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $q = $pdo->prepare('SELECT id FROM students WHERE ' . implode(' AND ', $where));
    $q->execute(array_merge($params, $ids));
    return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}
function result_scope_subjects(PDO $pdo, array $ids, int $class): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    if (!$ids) { return []; }
    $q = $pdo->prepare("SELECT id FROM academic_subjects WHERE class_id=? AND status='Active' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
    $q->execute(array_merge([$class], $ids));
    return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}
