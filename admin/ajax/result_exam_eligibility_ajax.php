<?php
// admin/ajax/result_exam_eligibility_ajax.php
// Result Module > Exam Procedure > Exam Eligibility পেজের filter select গুলো
// change হলে (page reload ছাড়াই) এখান থেকে ডাটা টেনে আনা হয়।
// Actions:
//   action=sections  (GET class_id, shift_id)                       -> JSON section list
//   action=table      (GET class_id, shift_id, section_id, term_id) -> HTML (student+subject grid)
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

if (!perm_page_allowed($pdo, 'result_exam_eligibility')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'এই পেজ দেখার অনুমতি নেই।']);
    exit();
}

function eev($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

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
// action=table - Class + Shift + Section + Exam Term সিলেক্ট হয়ে গেলে
// পুরো Student List for Exam Eligibility অংশটার HTML (result_exam_eligibility.php
// এর মূল ফাইলের সাথে হুবহু মার্কআপ/লজিক)
// -----------------------------------------------------------
if ($action === 'table') {
    header('Content-Type: text/html; charset=utf-8');

    require_once __DIR__ . '/../../config/csrf.php';

    $sel_class   = (int) ($_GET['class_id'] ?? 0);
    $sel_shift   = (int) ($_GET['shift_id'] ?? 0);
    $sel_section = (int) ($_GET['section_id'] ?? 0);
    $sel_term    = (int) ($_GET['term_id'] ?? 0);

    $current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
    $current_session_id = $current_session ? (int) $current_session['id'] : 0;

    $all_selected = ($sel_class > 0 && $sel_shift > 0 && $sel_section > 0 && $sel_term > 0);
    $subjects = [];
    $students = [];
    $existing = [];

    if ($all_selected) {
        $sub_stmt = $pdo->prepare(
            "SELECT id, subject_name, subject_type, group_id FROM academic_subjects
             WHERE class_id=? AND status='Active'
             ORDER BY (subject_type='Compulsory') DESC, subject_name ASC"
        );
        $sub_stmt->execute([$sel_class]);
        $subjects = $sub_stmt->fetchAll();

        $ree_where = ['class_id=?', 'shift_id=?', 'section_id=?', "status='Active'"];
        $ree_params = [$sel_class, $sel_shift, $sel_section];
        branch_apply_filter($ree_where, $ree_params);
        $stu_stmt = $pdo->prepare(
            "SELECT id, roll_no, name_en, group_id, religion FROM students
             WHERE " . implode(' AND ', $ree_where) . "
             ORDER BY (roll_no + 0) ASC, roll_no ASC"
        );
        $stu_stmt->execute($ree_params);
        $students = $stu_stmt->fetchAll();

        if ($subjects && $students) {
            $ex_stmt = $pdo->prepare("SELECT student_id, subject_id, is_eligible FROM exam_eligibility WHERE term_id=? AND class_id=? AND shift_id=? AND section_id=?");
            $ex_stmt->execute([$sel_term, $sel_class, $sel_shift, $sel_section]);
            foreach ($ex_stmt->fetchAll() as $row) {
                $existing[(int) $row['student_id']][(int) $row['subject_id']] = (int) $row['is_eligible'];
            }
        }
    }

    function eeajax_subject_color_class($sub): string {
        if ($sub['subject_type'] === 'Compulsory') return 'sub-main';
        if ($sub['subject_type'] === 'Optional' && empty($sub['group_id'])) return 'sub-classwise';
        return 'sub-studentwise';
    }
    ?>
    <?php if (!$all_selected): ?>
        <div class="text-center text-muted py-4">উপরে Class, Shift, Section এবং Exam Term সিলেক্ট করুন — তাহলে ঐ ক্লাসের সব Student ও Subject এখানে দেখাবে।</div>
    <?php elseif (!$subjects): ?>
        <div class="text-center text-muted py-4">এই ক্লাসের জন্য কোনো Subject পাওয়া যায়নি। প্রথমে Academic Module &gt; Subject থেকে Subject যোগ করুন।</div>
    <?php elseif (!$students): ?>
        <div class="text-center text-muted py-4">এই Class/Shift/Section এ কোনো Active Student পাওয়া যায়নি।</div>
    <?php else: ?>

        <div class="d-flex justify-content-center gap-5 mb-4">
            <div class="elig-legend-item"><span class="sub-th-dot" style="background:#1f2937;"></span><span>(Main subjects)</span></div>
            <div class="elig-legend-item"><span class="sub-th-dot" style="background:#f4a6b8;"></span><span class="text-danger">(Class wise optional subjects)</span></div>
            <div class="elig-legend-item"><span class="sub-th-dot" style="background:#c99a1e;"></span><span style="color:#b8860b;">(Student wise optional subjects)</span></div>
        </div>

        <form method="POST" action="index.php?page=result_exam_eligibility">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
            <input type="hidden" name="shift_id" value="<?php echo $sel_shift; ?>">
            <input type="hidden" name="section_id" value="<?php echo $sel_section; ?>">
            <input type="hidden" name="term_id" value="<?php echo $sel_term; ?>">
            <?php foreach ($subjects as $sub): ?>
                <input type="hidden" name="grid_subject_id[]" value="<?php echo (int) $sub['id']; ?>">
            <?php endforeach; ?>

            <div class="table-responsive">
                <table class="table table-bordered align-middle text-center">
                    <thead class="table-light">
                        <tr>
                            <th>Sl.</th>
                            <th class="text-start">Name</th>
                            <th>Roll</th>
                            <th>Group</th>
                            <th>Religion</th>
                            <?php foreach ($subjects as $sub): $cls = eeajax_subject_color_class($sub); ?>
                                <th class="<?php echo $cls; ?>">
                                    <span class="sub-th-dot mb-1" style="cursor:pointer;" title="Select/Unselect All" onclick="toggleColumn(<?php echo (int) $sub['id']; ?>, this)"></span>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $srl = 1; foreach ($students as $stu): $stid = (int) $stu['id']; ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td class="text-start"><?php echo eev($stu['name_en']); ?></td>
                                <td><?php echo eev($stu['roll_no']); ?></td>
                                <td><?php echo eev($stu['group_id'] ?: 'N/A'); ?></td>
                                <td><?php echo eev($stu['religion']); ?></td>
                                <input type="hidden" name="student_id[]" value="<?php echo $stid; ?>">
                                <?php foreach ($subjects as $sub):
                                    $sid = (int) $sub['id'];
                                    $cls = eeajax_subject_color_class($sub);
                                    $checked = array_key_exists($sid, $existing[$stid] ?? []) ? (bool) $existing[$stid][$sid] : true;
                                ?>
                                    <td class="<?php echo $cls; ?>">
                                        <div class="small sub-name mb-1"><?php echo eev($sub['subject_name']); ?></div>
                                        <input type="checkbox" class="form-check-input elig-cb elig-col-<?php echo $sid; ?>" name="elig[<?php echo $stid; ?>][<?php echo $sid; ?>]" value="1" <?php echo $checked ? 'checked' : ''; ?>>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <hr>
            <div class="text-center">
                <button type="submit" name="save_eligibility" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Save Eligibility</button>
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
