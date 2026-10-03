<?php
// admin/ajax/result_attendance_subject_ajax.php
// Result Module > Exam Procedure > Attendance (Subject) পেজের filter select গুলো
// change হলে (page reload ছাড়াই) এখান থেকে ডাটা টেনে আনা হয়।
// Actions:
//   action=sections (GET class_id, shift_id)                                                  -> JSON section list
//   action=subjects (GET class_id)                                                             -> JSON subject list
//   action=table     (GET class_id, shift_id, section_id, term_id, part_id, subject_id)        -> HTML (student list)
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

if (!perm_page_allowed($pdo, 'result_attendance_subject')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'এই পেজ দেখার অনুমতি নেই।']);
    exit();
}

function asv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

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
// action=subjects - Class সিলেক্ট করলে সেই ক্লাসের Subject লিস্ট
// -----------------------------------------------------------
if ($action === 'subjects') {
    header('Content-Type: application/json; charset=utf-8');

    $class_id = (int) ($_GET['class_id'] ?? 0);

    $subjects_list = [];
    if ($class_id > 0) {
        $stmt = $pdo->prepare("SELECT id, subject_name FROM academic_subjects WHERE class_id=? AND status='Active' ORDER BY subject_name ASC");
        $stmt->execute([$class_id]);
        $subjects_list = $stmt->fetchAll();
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
// পুরো Student List for Exam Attendance অংশটার HTML (result_attendance_subject.php
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

    $current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
    $current_session_id = $current_session ? (int) $current_session['id'] : 0;

    $sel_class   = (int) ($_GET['class_id'] ?? 0);
    $sel_shift   = (int) ($_GET['shift_id'] ?? 0);
    $sel_section = (int) ($_GET['section_id'] ?? 0);
    $sel_term    = (int) ($_GET['term_id'] ?? 0);
    $sel_part    = (int) ($_GET['part_id'] ?? 0);
    $sel_subject = (int) ($_GET['subject_id'] ?? 0);

    $all_selected = ($sel_class > 0 && $sel_shift > 0 && $sel_section > 0 && $sel_term > 0 && $sel_part > 0 && $sel_subject > 0);
    $students = [];

    if ($all_selected) {
        $ras_where = ['s.class_id=?', 's.shift_id=?', 's.section_id=?', "s.status='Active'"];
        $ras_params = [$sel_term, $sel_part, $sel_subject, $sel_class, $sel_shift, $sel_section];
        branch_apply_filter($ras_where, $ras_params, 's.branch_id');
        $stu_stmt = $pdo->prepare(
            "SELECT s.id, s.student_id, s.roll_no, s.name_en, s.photo_path, ea.status AS saved_status
             FROM students s
             LEFT JOIN exam_attendance_subject ea ON ea.student_id = s.id AND ea.term_id = ? AND ea.part_id = ? AND ea.subject_id = ?
             WHERE " . implode(' AND ', $ras_where) . "
             ORDER BY (s.roll_no + 0) ASC, s.roll_no ASC"
        );
        $stu_stmt->execute($ras_params);
        $students = $stu_stmt->fetchAll();
    }
    ?>
    <?php if (!$all_selected): ?>
        <div class="text-center text-muted py-4">উপরে Class, Shift, Section, Exam Term, Exam Part এবং Subject সিলেক্ট করুন — তাহলে স্টুডেন্ট লিস্ট এখানে দেখাবে।</div>
    <?php elseif (!$students): ?>
        <div class="text-center text-muted py-4">এই Class/Shift/Section এ কোনো Active Student পাওয়া যায়নি।</div>
    <?php else: ?>
        <form method="POST" action="index.php?page=result_attendance_subject" id="attendanceForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
            <input type="hidden" name="shift_id" value="<?php echo $sel_shift; ?>">
            <input type="hidden" name="section_id" value="<?php echo $sel_section; ?>">
            <input type="hidden" name="term_id" value="<?php echo $sel_term; ?>">
            <input type="hidden" name="part_id" value="<?php echo $sel_part; ?>">
            <input type="hidden" name="subject_id" value="<?php echo $sel_subject; ?>">

            <div class="table-responsive">
                <table class="table table-bordered align-middle m-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:50px;">Sl.</th>
                            <th style="width:70px;">Roll No</th>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th style="width:130px;">
                                <button type="button" class="exat-toggle-btn status-absent" id="allToggleBtn" onclick="toggleAll()">All Absent</button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $srl = 1; foreach ($students as $stu): $status = $stu['saved_status'] ?: 'Absent'; ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td><?php echo asv($stu['roll_no']); ?></td>
                                <td class="text-primary fw-bold"><?php echo asv($stu['student_id']); ?></td>
                                <td><?php echo asv($stu['name_en']); ?></td>
                                <td>
                                    <input type="hidden" name="status[<?php echo (int) $stu['id']; ?>]" class="status-input" value="<?php echo $status; ?>">
                                    <button type="button" class="exat-toggle-btn row-toggle-btn status-<?php echo strtolower($status); ?>" onclick="toggleRow(this)"><?php echo $status; ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-center mt-4">
                <button type="submit" name="save_attendance" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Save Attendance</button>
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
