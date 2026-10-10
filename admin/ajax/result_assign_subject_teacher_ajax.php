<?php
// admin/ajax/result_assign_subject_teacher_ajax.php
// Result Module > Exam Settings > Assign Subject to Teacher পেজের Class select
// change হলে (page reload ছাড়াই) এখান থেকে ডাটা টেনে আনা হয়।
// Actions:
//   action=table  (GET employee_pk, class_id) -> HTML (Assign Subjects চেকবক্স ফর্ম)
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
erp_portal_require_role($pdo, 'admin', 'result_assign_subject_teacher');
require_once __DIR__ . '/../../config/subject_rank.php'; subject_rank_ensure($pdo);
require_once __DIR__ . '/../../config/permissions.php';

if (!perm_page_allowed($pdo, 'result_assign_subject_teacher')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'এই পেজ দেখার অনুমতি নেই।']);
    exit();
}
perm_write_guard($pdo, 'result_assign_subject_teacher'); // Read-only ইউজারের সেভ আটকায়

if (!function_exists('lv')) {
    function lv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
}

$action = $_GET['action'] ?? '';

// -----------------------------------------------------------
// action=table - Class সিলেক্ট হয়ে গেলে ঐ ক্লাসের Subject লিস্ট (checkbox form)
// — result_assign_subject_teacher.php এর "Assign Subjects" অংশের সাথে হুবহু মার্কআপ/লজিক
// -----------------------------------------------------------
if ($action === 'table') {
    header('Content-Type: text/html; charset=utf-8');

    require_once __DIR__ . '/../../config/csrf.php';

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

    $employee_pk = (int) ($_GET['employee_pk'] ?? 0);
    $sel_class   = (int) ($_GET['class_id'] ?? 0);

    $selected_teacher = null;
    if ($employee_pk > 0) {
        $stmt = $pdo->prepare("SELECT e.*, d.title AS designation_title FROM employees e LEFT JOIN designations d ON d.id = e.designation_id WHERE e.id = ? AND e.type = 'Teachers'");
        $stmt->execute([$employee_pk]);
        $selected_teacher = $stmt->fetch();
    }

    $class_subjects = [];
    $assigned_subject_ids = [];
    $routine_subject_ids = [];
    if ($selected_teacher && $sel_class > 0) {
        $subj_stmt = $pdo->prepare("SELECT id, subject_name FROM academic_subjects WHERE class_id = ? AND status = 'Active' ORDER BY sort_order ASC, id ASC");
        $subj_stmt->execute([$sel_class]);
        $class_subjects = $subj_stmt->fetchAll();

        $as_stmt = $pdo->prepare("SELECT subject_id FROM teacher_subject_assignments WHERE employee_id = ? AND class_id = ?");
        $as_stmt->execute([$employee_pk, $sel_class]);
        $assigned_subject_ids = array_map('intval', array_column($as_stmt->fetchAll(), 'subject_id'));

        $rs_stmt = $pdo->prepare("SELECT DISTINCT subject_id FROM routines WHERE teacher_id = ? AND class_id = ? AND status = 'Active'");
        $rs_stmt->execute([$employee_pk, $sel_class]);
        $routine_subject_ids = array_map('intval', array_column($rs_stmt->fetchAll(), 'subject_id'));
    }
    ?>
    <?php if ($sel_class <= 0): ?>
        <div class="text-center text-muted py-4">উপরে একটা Class সিলেক্ট করুন — তাহলে সেই Class-এর সাবজেক্ট লিস্ট এখানে দেখাবে।</div>
    <?php elseif (!$class_subjects): ?>
        <div class="text-center text-muted py-4">এই Class-এর জন্য এখনো কোনো Subject তৈরি করা হয়নি। আগে <a href="index.php?page=academic_subject">Subjects</a> পেজ থেকে সাবজেক্ট যোগ করুন।</div>
    <?php else: ?>
        <form method="POST" action="index.php?page=result_assign_subject_teacher">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="employee_pk" value="<?php echo $employee_pk; ?>">
            <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">

            <div class="mb-2">
                <a href="#" class="small me-3" onclick="document.querySelectorAll('.subjChk').forEach(c=>c.checked=true); return false;">সব সিলেক্ট</a>
                <a href="#" class="small" onclick="document.querySelectorAll('.subjChk').forEach(c=>c.checked=false); return false;">সব বাদ দিন</a>
            </div>

            <div class="row">
                <?php foreach ($class_subjects as $sub): ?>
                    <?php $via_routine = in_array((int) $sub['id'], $routine_subject_ids, true); ?>
                    <div class="col-md-6 mb-2">
                        <div class="form-check">
                            <input class="form-check-input subjChk" type="checkbox" name="subject_ids[]" value="<?php echo (int) $sub['id']; ?>" id="subj<?php echo (int) $sub['id']; ?>"
                                <?php echo in_array((int) $sub['id'], $assigned_subject_ids, true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="subj<?php echo (int) $sub['id']; ?>"><?php echo lv($sub['subject_name']); ?></label>
                            <?php if ($via_routine): ?><span class="badge bg-info-subtle text-info-emphasis ms-1">Routine-এ আছে</span><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="text-muted small mt-2">"Routine-এ আছে" ট্যাগওয়ালা সাবজেক্টে টিচারের অ্যাক্সেস এমনিতেই আছে — চেক না করলেও চলবে।</p>

            <button type="submit" name="save_assign" class="btn btn-success px-4 fw-bold mt-3"><i class="fa-solid fa-check"></i> Save Assignment</button>
        </form>
    <?php endif; ?>
    <?php
    exit();
}

// অজানা action
http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'message' => 'Invalid action']);
