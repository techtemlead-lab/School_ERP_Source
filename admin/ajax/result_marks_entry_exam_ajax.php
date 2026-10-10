<?php
// admin/ajax/result_marks_entry_exam_ajax.php
// Result Module > Exam Procedure > Marks Entry (Exam) পেজের filter select গুলো
// change হলে (page reload ছাড়াই) এখান থেকে ডাটা টেনে আনা হয়।
// Actions:
//   action=sections  (GET class_id, shift_id)                                            -> JSON section list
//   action=table      (GET class_id, shift_id, section_id, term_id, part_id)              -> HTML (students list for marks entry)
require_once __DIR__ . '/../../config/runtime.php';
erp_session_start();

if (!isset($_SESSION['admin_logged'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Session শেষ হয়ে গেছে, আবার লগইন করুন।']);
    exit();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/portal_access.php';
erp_portal_require_role($pdo, 'admin', 'result_marks_entry_exam');
require_once __DIR__ . '/../../config/subject_rank.php'; subject_rank_ensure($pdo);
require_once __DIR__ . '/../../config/branch_scope.php';
require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../config/exam_att_grid.php';


if (!perm_page_allowed($pdo, 'result_marks_entry_exam')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'এই পেজ দেখার অনুমতি নেই।']);
    exit();
}
perm_write_guard($pdo, 'result_marks_entry_exam'); // Read-only ইউজারের সেভ আটকায়

function mxv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function mxnum($val): string { return rtrim(rtrim(number_format((float) $val, 2), '0'), '.'); }

$action = $_GET['action'] ?? '';

// -----------------------------------------------------------
// action=sections - Class + Shift সিলেক্ট করলে সেই কম্বিনেশনের Section লিস্ট
// -----------------------------------------------------------
if ($action === 'sections') {
    header('Content-Type: application/json; charset=utf-8');

    $class_id = (int) ($_GET['class_id'] ?? 0);
    $shift_id = (int) ($_GET['shift_id'] ?? 0);

    $sections = [];
    if ($class_id > 0 && $shift_id > 0) {
        $stmt = $pdo->prepare("SELECT id, section_name FROM academic_sections WHERE class_id=? AND shift_id=? AND status='Active' ORDER BY section_name ASC");
        $stmt->execute([$class_id, $shift_id]);
        $sections = $stmt->fetchAll();
    }

    $result = [];
    foreach ($sections as $s) {
        $result[] = ['id' => (int) $s['id'], 'name' => $s['section_name']];
    }
    $groups = [];
    foreach (($class_id > 0 ? ee_groups_of_class($pdo, $class_id) : []) as $gid => $gn) { $groups[] = ['id' => (int) $gid, 'name' => $gn]; }
    echo json_encode(['success' => true, 'sections' => $result, 'groups' => $groups], JSON_UNESCAPED_UNICODE);
    exit();
}

// -----------------------------------------------------------
// action=table - Class + Shift + Section + Exam Term + Exam Part সিলেক্ট হয়ে গেলে
// পুরো "Students List for Marks Entry" অংশটার HTML (result_marks_entry_exam.php
// এর মূল ফাইলের সাথে হুবহু মার্কআপ/লজিক)
// -----------------------------------------------------------
if ($action === 'table') {
    header('Content-Type: text/html; charset=utf-8');

    require_once __DIR__ . '/../../config/csrf.php';

    // exam_terms টেবিল এখনো তৈরি না হয়ে থাকতে পারে
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS exam_terms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            term_name VARCHAR(100) NOT NULL,
            rank INT NOT NULL DEFAULT 1,
            is_final_term TINYINT(1) NOT NULL DEFAULT 0,
            is_aggregated_result TINYINT(1) NOT NULL DEFAULT 0,
            admit_card_with_routine TINYINT(1) NOT NULL DEFAULT 0,
            admit_card_with_instruction TINYINT(1) NOT NULL DEFAULT 0,
            exam_routine_with_instruction TINYINT(1) NOT NULL DEFAULT 0,
            website_result_publish TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS exam_marks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            term_id INT NOT NULL,
            part_id INT NOT NULL,
            subject_id INT NOT NULL,
            class_id INT NOT NULL,
            shift_id INT NOT NULL,
            section_id INT NOT NULL,
            student_id INT NOT NULL,
            marks_obtained DECIMAL(6,2) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mark (term_id, part_id, subject_id, student_id),
            KEY idx_lookup (class_id, shift_id, section_id, term_id, part_id, subject_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $sel_class   = (int) ($_GET['class_id'] ?? 0);
    $sel_shift   = (int) ($_GET['shift_id'] ?? 0);
    $sel_section = (int) ($_GET['section_id'] ?? 0);
    $sel_term    = (int) ($_GET['term_id'] ?? 0);
    $sel_part    = (int) ($_GET['part_id'] ?? 0);
    $sel_group   = (int) ($_GET['group_id'] ?? 0);
    $mg_groups   = $sel_class > 0 ? ee_groups_of_class($pdo, $sel_class) : [];
    if ($mg_groups && !isset($mg_groups[$sel_group])) $sel_group = 0;

    $all_selected = ($sel_class > 0 && $sel_shift > 0 && $sel_section > 0 && $sel_term > 0 && $sel_part > 0);
    $need_group = ($all_selected && $mg_groups && $sel_group <= 0);
    if ($need_group) $all_selected = false;
    $subjects_list = [];
    $students = [];
    $marks_map = []; // marks_map[subject_id][student_id] = mark

    if ($all_selected) {
        $subj_stmt = $pdo->prepare("SELECT id, subject_name, full_marks, group_id FROM academic_subjects WHERE class_id=? AND status='Active' ORDER BY sort_order ASC, id ASC");
        $subj_stmt->execute([$sel_class]);
        $subjects_list = $subj_stmt->fetchAll();
        if ($mg_groups) { $GS = $sel_group; $subjects_list = array_values(array_filter($subjects_list, function ($r) use ($GS) { $g = (int) ($r['group_id'] ?? 0); return $g === 0 || $g === $GS; })); }

        $assign_stmt = $pdo->prepare("SELECT subject_id, full_mark_exam FROM exam_assign_subjects WHERE term_id=? AND part_id=? AND class_id=?");
        $assign_stmt->execute([$sel_term, $sel_part, $sel_class]);
        $assigned_full = [];
        foreach ($assign_stmt->fetchAll() as $row) {
            $assigned_full[(int) $row['subject_id']] = (float) $row['full_mark_exam'];
        }
        foreach ($subjects_list as &$sub) {
            $sub['is_assigned'] = isset($assigned_full[(int) $sub['id']]);
            $sub['full_mark']   = $sub['is_assigned'] ? $assigned_full[(int) $sub['id']] : (float) $sub['full_marks'];
        }
        unset($sub);

        $me_where = ['class_id=?', 'shift_id=?', 'section_id=?', "status='Active'"];
        $me_params = [$sel_class, $sel_shift, $sel_section];
        if ($mg_groups) { $me_where[] = 'group_id=?'; $me_params[] = $sel_group; }
        branch_apply_filter($me_where, $me_params);
        $stu_stmt = $pdo->prepare("SELECT id, student_id, roll_no, name_en FROM students WHERE " . implode(' AND ', $me_where) . " ORDER BY (roll_no + 0) ASC, roll_no ASC");
        $stu_stmt->execute($me_params);
        $students = $stu_stmt->fetchAll();

        if ($subjects_list && $students) {
            $subject_ids = array_column($subjects_list, 'id');
            $in_place = implode(',', array_fill(0, count($subject_ids), '?'));
            $mk_stmt = $pdo->prepare(
                "SELECT subject_id, student_id, marks_obtained FROM exam_marks
                 WHERE term_id = ? AND part_id = ? AND subject_id IN ($in_place)"
            );
            $mk_stmt->execute(array_merge([$sel_term, $sel_part], $subject_ids));
            foreach ($mk_stmt->fetchAll() as $row) {
                $marks_map[(int) $row['subject_id']][(int) $row['student_id']] = $row['marks_obtained'];
            }
        }
    }
    ?>
    <?php if ($need_group): ?>
        <?php echo ea_group_need_msg('Mark Entry'); ?>
    <?php elseif (!$all_selected): ?>
        <div class="text-center text-muted py-4">উপরে Class, Shift, Section, Exam Term এবং Exam Part সিলেক্ট করুন — তাহলে স্টুডেন্ট লিস্ট এখানে দেখাবে।</div>
    <?php elseif (!$subjects_list): ?>
        <div class="text-center text-muted py-4">এই Class-এর জন্য কোনো Subject পাওয়া যায়নি। আগে <a href="index.php?page=academic_subject">Subject</a> যোগ করুন।</div>
    <?php elseif (!$students): ?>
        <div class="text-center text-muted py-4">এই Class/Shift/Section এ কোনো Active Student পাওয়া যায়নি।</div>
    <?php else: ?>
        <?php mx_render_exam_form($pdo, $sel_class, $sel_shift, $sel_section, $sel_term, $sel_part, $sel_group, $subjects_list, $students, $marks_map); ?>
    <?php endif; ?>
    <?php
    exit();
}

// অজানা action
http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'message' => 'Invalid action']);
