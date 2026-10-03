<?php
// admin/ajax/result_exam_routine_ajax.php
// Result Module > Exam Procedure > Exam Routine পেজের filter select গুলো
// change হলে (page reload ছাড়াই) এখান থেকে ডাটা টেনে আনা হয়।
// Actions:
//   action=sections (GET class_id, shift_id)                                     -> JSON section list
//   action=table     (GET class_id, shift_id, section_id, term_id, part_id)       -> HTML (subject/date/time grid)
require_once __DIR__ . '/../../config/runtime.php';
erp_session_start();

if (!isset($_SESSION['admin_logged'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Session শেষ হয়ে গেছে, আবার লগইন করুন।']);
    exit();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/branch_scope.php';
require_once __DIR__ . '/../../config/permissions.php';

if (!perm_page_allowed($pdo, 'result_exam_routine')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'এই পেজ দেখার অনুমতি নেই।']);
    exit();
}

function erv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

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
// action=table - Class + Shift + Section + Exam Term + Exam Part সিলেক্ট হয়ে গেলে
// পুরো Exam Routine টেবিল অংশটার HTML (result_exam_routine.php এর মূল ফাইলের
// সাথে হুবহু মার্কআপ/লজিক)
// -----------------------------------------------------------
if ($action === 'table') {
    header('Content-Type: text/html; charset=utf-8');

    require_once __DIR__ . '/../../config/csrf.php';

    // exam_routines টেবিল এখনো তৈরি না হয়ে থাকতে পারে
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS exam_routines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            class_id INT NOT NULL,
            shift_id INT NOT NULL,
            section_id INT NOT NULL,
            term_id INT NOT NULL,
            part_id INT NOT NULL,
            subject_id INT NOT NULL,
            exam_date DATE NULL,
            start_time TIME NULL,
            end_time TIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_routine (class_id, shift_id, section_id, term_id, part_id, subject_id),
            KEY idx_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
    $current_session_id = $current_session ? (int) $current_session['id'] : 0;
    $current_session_name = $current_session['session_name'] ?? '—';

    $sel_class   = (int) ($_GET['class_id'] ?? 0);
    $sel_shift   = (int) ($_GET['shift_id'] ?? 0);
    $sel_section = (int) ($_GET['section_id'] ?? 0);
    $sel_term    = (int) ($_GET['term_id'] ?? 0);
    $sel_part    = (int) ($_GET['part_id'] ?? 0);

    $all_selected = ($sel_class > 0 && $sel_shift > 0 && $sel_section > 0 && $sel_term > 0 && $sel_part > 0);
    $subjects = [];
    $existing = [];
    if ($all_selected) {
        $sub_stmt = $pdo->prepare("SELECT id, subject_name, subject_code FROM academic_subjects WHERE class_id=? AND status='Active' ORDER BY subject_name ASC");
        $sub_stmt->execute([$sel_class]);
        $subjects = $sub_stmt->fetchAll();

        $ex_stmt = $pdo->prepare("SELECT * FROM exam_routines WHERE class_id=? AND shift_id=? AND section_id=? AND term_id=? AND part_id=?");
        $ex_stmt->execute([$sel_class, $sel_shift, $sel_section, $sel_term, $sel_part]);
        foreach ($ex_stmt->fetchAll() as $er) { $existing[(int) $er['subject_id']] = $er; }
    }

    $institute = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetch();
    $institute_name = $institute['institute_name'] ?? 'School Name';

    $sel_class_name = '';
    if ($sel_class > 0) {
        $cn_stmt = $pdo->prepare("SELECT class_name FROM academic_classes WHERE id=?");
        $cn_stmt->execute([$sel_class]);
        $sel_class_name = (string) ($cn_stmt->fetchColumn() ?: '');
    }
    $sel_term_name = '';
    if ($sel_term > 0) {
        $tn_stmt = $pdo->prepare("SELECT term_name FROM exam_terms WHERE id=?");
        $tn_stmt->execute([$sel_term]);
        $sel_term_name = (string) ($tn_stmt->fetchColumn() ?: '');
    }
    ?>
    <div class="er-print-head">
        <h5 class="mb-0"><?php echo erv($institute_name); ?></h5>
        <div><?php echo erv($sel_class_name); ?> - <?php echo erv($sel_term_name); ?> — Exam Routine</div>
        <div class="small">Session : <?php echo erv($current_session_name); ?></div>
    </div>
    <?php if (!$all_selected): ?>
        <div class="text-center text-muted py-4 no-print">উপরে Class, Shift, Section, Exam Term এবং Exam Part সিলেক্ট করুন — তাহলে ঐ ক্লাসের সব Subject এখানে দেখাবে।</div>
    <?php elseif (!$subjects): ?>
        <div class="text-center text-muted py-4 no-print">এই ক্লাসের জন্য কোনো Subject পাওয়া যায়নি। প্রথমে Academic Module &gt; Subject থেকে Subject যোগ করুন।</div>
    <?php else: ?>
        <form method="POST" action="index.php?page=result_exam_routine" id="routineForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
            <input type="hidden" name="shift_id" value="<?php echo $sel_shift; ?>">
            <input type="hidden" name="section_id" value="<?php echo $sel_section; ?>">
            <input type="hidden" name="term_id" value="<?php echo $sel_term; ?>">
            <input type="hidden" name="part_id" value="<?php echo $sel_part; ?>">

            <div class="table-responsive">
                <table class="table table-bordered align-middle" id="routineTable">
                    <thead class="table-light">
                        <tr>
                            <th>Subjects</th>
                            <th style="width:160px;">Date</th>
                            <th style="width:160px;">Start Time</th>
                            <th style="width:160px;">End Time</th>
                            <th class="no-print" style="width:70px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjects as $sub):
                            $sid = (int) $sub['id'];
                            $ex = $existing[$sid] ?? null;
                            $exam_date  = $ex['exam_date']  ?? '';
                            $start_time = $ex ? substr($ex['start_time'], 0, 5) : '11:00';
                            $end_time   = $ex ? substr($ex['end_time'], 0, 5) : '13:00';
                        ?>
                            <tr>
                                <td>
                                    <input type="hidden" name="subject_id[]" value="<?php echo $sid; ?>">
                                    <?php echo erv($sub['subject_name']); ?>
                                    <?php if ($sub['subject_code']): ?><span class="text-muted small">(<?php echo erv($sub['subject_code']); ?>)</span><?php endif; ?>
                                </td>
                                <td><input type="date" name="exam_date[<?php echo $sid; ?>]" class="form-control form-control-sm" value="<?php echo erv($exam_date); ?>"></td>
                                <td><input type="time" name="start_time[<?php echo $sid; ?>]" class="form-control form-control-sm" value="<?php echo erv($start_time); ?>"></td>
                                <td><input type="time" name="end_time[<?php echo $sid; ?>]" class="form-control form-control-sm" value="<?php echo erv($end_time); ?>"></td>
                                <td class="text-center no-print">
                                    <button type="button" class="btn btn-sm btn-danger rounded-circle" title="Remove" onclick="removeRoutineRow(this)"><i class="fa-solid fa-xmark"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <hr class="no-print">
            <div class="text-center no-print">
                <button type="submit" name="save_routine" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Save Routine</button>
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
