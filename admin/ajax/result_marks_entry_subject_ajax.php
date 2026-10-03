<?php
// admin/ajax/result_marks_entry_subject_ajax.php
// Result Module > Exam Procedure > Marks Entry (Subject) পেজের filter select গুলো
// change হলে (page reload ছাড়াই) এখান থেকে ডাটা টেনে আনা হয়।
// Actions:
//   action=sections  (GET class_id, shift_id)                                                       -> JSON section list
//   action=subjects  (GET class_id)                                                                  -> JSON subject list (Class বদলালে)
//   action=table      (GET class_id, shift_id, section_id, term_id, part_id, subject_id)              -> HTML (students list for marks entry)
session_start();

if (!isset($_SESSION['admin_logged'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Session শেষ হয়ে গেছে, আবার লগইন করুন।']);
    exit();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/branch_scope.php';
require_once __DIR__ . '/../../config/permissions.php';

if (!perm_page_allowed($pdo, 'result_marks_entry_subject')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'এই পেজ দেখার অনুমতি নেই।']);
    exit();
}

function mev($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// এই পেজ Admin ও Teacher Portal দুই জায়গা থেকেই include হয় (মূল ফাইলের কমেন্ট দেখুন)।
// Teacher Portal থেকে এলে শুধু নিজের অ্যাক্সেসযোগ্য Class/Subject-এই সীমাবদ্ধ থাকবে।
$is_teacher_ctx = !empty($_SESSION['teacher_logged']);
$me_teacher_id  = $is_teacher_ctx ? (int) ($_SESSION['teacher_id'] ?? 0) : 0;

function teacher_allowed_class_subjects(PDO $pdo, int $teacher_id): array {
    $stmt = $pdo->prepare(
        "SELECT class_id, subject_id FROM teacher_subject_assignments WHERE employee_id = ?
         UNION
         SELECT class_id, subject_id FROM routines WHERE teacher_id = ? AND status = 'Active' AND class_id IS NOT NULL"
    );
    $stmt->execute([$teacher_id, $teacher_id]);
    return $stmt->fetchAll();
}

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
    echo json_encode(['success' => true, 'sections' => $result], JSON_UNESCAPED_UNICODE);
    exit();
}

// -----------------------------------------------------------
// action=subjects - Class সিলেক্ট/বদলালে সেই Class-এর Subject লিস্ট (Teacher Portal
// context হলে টিচারের অ্যাক্সেসযোগ্য সাবজেক্টেই সীমাবদ্ধ)
// -----------------------------------------------------------
if ($action === 'subjects') {
    header('Content-Type: application/json; charset=utf-8');

    $class_id = (int) ($_GET['class_id'] ?? 0);

    $subjects_list = [];
    if ($class_id > 0) {
        if ($is_teacher_ctx) {
            $allowed_pairs = teacher_allowed_class_subjects($pdo, $me_teacher_id);
            $allowed_subject_ids = [];
            foreach ($allowed_pairs as $a) {
                if ((int) $a['class_id'] === $class_id) { $allowed_subject_ids[] = (int) $a['subject_id']; }
            }
            $allowed_subject_ids = array_values(array_unique($allowed_subject_ids));
            if ($allowed_subject_ids) {
                $in_ph = implode(',', array_fill(0, count($allowed_subject_ids), '?'));
                $subj_stmt = $pdo->prepare("SELECT id, subject_name FROM academic_subjects WHERE class_id=? AND status='Active' AND id IN ($in_ph) ORDER BY subject_name ASC");
                $subj_stmt->execute(array_merge([$class_id], $allowed_subject_ids));
                $subjects_list = $subj_stmt->fetchAll();
            }
        } else {
            $subj_stmt = $pdo->prepare("SELECT id, subject_name FROM academic_subjects WHERE class_id=? AND status='Active' ORDER BY subject_name ASC");
            $subj_stmt->execute([$class_id]);
            $subjects_list = $subj_stmt->fetchAll();
        }
    }

    $result = [];
    foreach ($subjects_list as $s) {
        $result[] = ['id' => (int) $s['id'], 'name' => $s['subject_name']];
    }
    echo json_encode(['success' => true, 'subjects' => $result], JSON_UNESCAPED_UNICODE);
    exit();
}

// -----------------------------------------------------------
// action=table - Class + Shift + Section + Exam Term + Exam Part + Subject সিলেক্ট হয়ে গেলে
// পুরো "Students List for Marks Entry" অংশটার HTML (result_marks_entry_subject.php
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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS teacher_subject_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            class_id INT NOT NULL,
            subject_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_assign (employee_id, class_id, subject_id),
            KEY idx_employee (employee_id),
            KEY idx_class_subject (class_id, subject_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS routines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NULL,
            shift_id INT NULL,
            section_id INT NOT NULL,
            routine_days VARCHAR(100) NULL,
            start_time TIME NULL,
            end_time TIME NULL,
            has_break TINYINT(1) NOT NULL DEFAULT 0,
            subject_id INT NOT NULL,
            teacher_id INT NOT NULL,
            status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_lookup (class_id, shift_id, section_id),
            KEY idx_teacher (teacher_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS exam_attendance_subject (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            term_id INT NOT NULL,
            part_id INT NOT NULL,
            subject_id INT NOT NULL,
            class_id INT NOT NULL,
            shift_id INT NOT NULL,
            section_id INT NOT NULL,
            student_id INT NOT NULL,
            status ENUM('Present','Absent') NOT NULL DEFAULT 'Absent',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_att (term_id, part_id, subject_id, student_id),
            KEY idx_lookup (class_id, shift_id, section_id, term_id, part_id, subject_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $sel_class   = (int) ($_GET['class_id'] ?? 0);
    $sel_shift   = (int) ($_GET['shift_id'] ?? 0);
    $sel_section = (int) ($_GET['section_id'] ?? 0);
    $sel_term    = (int) ($_GET['term_id'] ?? 0);
    $sel_part    = (int) ($_GET['part_id'] ?? 0);
    $sel_subject = (int) ($_GET['subject_id'] ?? 0);

    $all_selected = ($sel_class > 0 && $sel_shift > 0 && $sel_section > 0 && $sel_term > 0 && $sel_part > 0 && $sel_subject > 0);
    $students = [];
    $full_mark_exam = 100;
    $assign_missing = false;

    if ($all_selected) {
        $assign_stmt = $pdo->prepare("SELECT full_mark_exam FROM exam_assign_subjects WHERE term_id=? AND part_id=? AND subject_id=? LIMIT 1");
        $assign_stmt->execute([$sel_term, $sel_part, $sel_subject]);
        $assign_row = $assign_stmt->fetch();
        if ($assign_row) {
            $full_mark_exam = (float) $assign_row['full_mark_exam'];
        } else {
            $assign_missing = true;
        }

        $mes_where = ['s.class_id=?', 's.shift_id=?', 's.section_id=?', "s.status='Active'"];
        $mes_params = [$sel_term, $sel_part, $sel_subject, $sel_term, $sel_part, $sel_subject, $sel_class, $sel_shift, $sel_section];
        branch_apply_filter($mes_where, $mes_params, 's.branch_id');
        $stu_stmt = $pdo->prepare(
            "SELECT s.id, s.student_id, s.roll_no, s.name_en, s.photo_path,
                    em.marks_obtained AS saved_mark,
                    ea.status AS attendance_status
             FROM students s
             LEFT JOIN exam_marks em ON em.student_id = s.id AND em.term_id = ? AND em.part_id = ? AND em.subject_id = ?
             LEFT JOIN exam_attendance_subject ea ON ea.student_id = s.id AND ea.term_id = ? AND ea.part_id = ? AND ea.subject_id = ?
             WHERE " . implode(' AND ', $mes_where) . "
             ORDER BY (s.roll_no + 0) ASC, s.roll_no ASC"
        );
        $stu_stmt->execute($mes_params);
        $students = $stu_stmt->fetchAll();
    }
    ?>
    <?php if (!$all_selected): ?>
        <div class="text-center text-muted py-4">উপরে Class, Shift, Section, Exam Term, Exam Part এবং Subject সিলেক্ট করুন — তাহলে স্টুডেন্ট লিস্ট এখানে দেখাবে।</div>
    <?php elseif ($assign_missing): ?>
        <div class="text-center text-muted py-4">
            এই Term/Part/Subject কম্বিনেশনের জন্য এখনো Full Mark সেট করা হয়নি।
            আগে <a href="index.php?page=result_assign_exams">Assign Exam</a> পেজ থেকে Full Mark সেট করুন।
        </div>
    <?php elseif (!$students): ?>
        <div class="text-center text-muted py-4">এই Class/Shift/Section এ কোনো Active Student পাওয়া যায়নি।</div>
    <?php else: ?>
        <form method="POST" action="index.php?page=result_marks_entry_subject" id="marksForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
            <input type="hidden" name="shift_id" value="<?php echo $sel_shift; ?>">
            <input type="hidden" name="section_id" value="<?php echo $sel_section; ?>">
            <input type="hidden" name="term_id" value="<?php echo $sel_term; ?>">
            <input type="hidden" name="part_id" value="<?php echo $sel_part; ?>">
            <input type="hidden" name="subject_id" value="<?php echo $sel_subject; ?>">
            <input type="hidden" name="full_mark_exam" value="<?php echo mev($full_mark_exam); ?>">

            <div class="table-responsive">
                <table class="table table-bordered align-middle m-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:50px;">Sl.</th>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th style="width:90px;">Roll</th>
                            <th style="width:150px;" class="text-end">
                                Mark<br>
                                <span class="text-warning fw-bold small">(<?php echo mev(rtrim(rtrim(number_format($full_mark_exam, 2), '0'), '.')); ?>/<?php echo mev(rtrim(rtrim(number_format($full_mark_exam, 2), '0'), '.')); ?>)</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $srl = 1; foreach ($students as $stu):
                            $is_absent = ($stu['attendance_status'] ?? '') === 'Absent';
                        ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td class="text-primary fw-bold"><?php echo mev($stu['student_id']); ?></td>
                                <td>
                                    <?php if (!empty($stu['photo_path'])): ?>
                                        <img src="../<?php echo mev($stu['photo_path']); ?>" style="width:26px;height:26px;object-fit:cover;border-radius:50%;margin-right:6px;">
                                    <?php endif; ?>
                                    <?php echo mev($stu['name_en']); ?>
                                    <?php if ($is_absent): ?><span class="badge bg-danger ms-1">Absent</span><?php endif; ?>
                                </td>
                                <td><?php echo mev($stu['roll_no']); ?></td>
                                <td>
                                    <input type="number" step="0.01" min="0" max="<?php echo mev($full_mark_exam); ?>"
                                           name="marks[<?php echo (int) $stu['id']; ?>]" class="form-control form-control-sm text-end"
                                           value="<?php echo $stu['saved_mark'] !== null ? mev(rtrim(rtrim(number_format((float) $stu['saved_mark'], 2), '0'), '.')) : ''; ?>"
                                           placeholder="0">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-center mt-4">
                <button type="submit" name="save_marks" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Save Marks</button>
            </div>
        </form>
    <?php endif; ?>
    <?php
    exit();
}

// অজানা action
http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'message' => 'Invalid action']);
