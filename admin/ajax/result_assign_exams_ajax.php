<?php
// admin/ajax/result_assign_exams_ajax.php
// Result Module > Exam Settings > Assign Exams পেজের (view=add) filter select গুলো
// change হলে (page reload ছাড়াই) এখান থেকে ডাটা টেনে আনা হয়।
// Actions:
//   action=table  (GET class_id, term_id, part_id) -> HTML (Subject list Full Mark ফর্ম)
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
erp_portal_require_role($pdo, 'admin', 'result_assign_exams');
require_once __DIR__ . '/../../config/subject_rank.php'; subject_rank_ensure($pdo);
require_once __DIR__ . '/../../config/permissions.php';

if (!perm_page_allowed($pdo, 'result_assign_exams')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'এই পেজ দেখার অনুমতি নেই।']);
    exit();
}
perm_write_guard($pdo, 'result_assign_exams'); // Read-only ইউজারের সেভ আটকায়

function aev($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$action = $_GET['action'] ?? '';

// -----------------------------------------------------------
// action=table - Class + Exam Term + Exam Part সিলেক্ট হয়ে গেলে ঐ ক্লাসের Subject লিস্ট
// (Full Mark ফর্ম) — result_assign_exams.php এর view=add এর সাথে হুবহু মার্কআপ/লজিক
// -----------------------------------------------------------
if ($action === 'table') {
    header('Content-Type: text/html; charset=utf-8');

    require_once __DIR__ . '/../../config/csrf.php';

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS exam_assign_subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            class_id INT NOT NULL,
            term_id INT NOT NULL,
            part_id INT NOT NULL,
            subject_id INT NOT NULL,
            full_mark_subject DECIMAL(6,2) NOT NULL DEFAULT 100,
            mark_for_term_percent DECIMAL(6,2) NOT NULL DEFAULT 100,
            full_mark_exam DECIMAL(6,2) NOT NULL DEFAULT 100,
            pass_mark_exam DECIMAL(6,2) NOT NULL DEFAULT 33,
            pass_individual TINYINT(1) NOT NULL DEFAULT 1,
            fail_on_absent TINYINT(1) NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_assign (term_id, part_id, subject_id),
            KEY idx_class (class_id), KEY idx_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $sel_class = (int) ($_GET['class_id'] ?? 0);
    $sel_term  = (int) ($_GET['term_id'] ?? 0);
    $sel_part  = (int) ($_GET['part_id'] ?? 0);

    $subjects = [];
    $existing = [];
    if ($sel_class > 0) {
        $sstmt = $pdo->prepare("SELECT id, subject_name, subject_code, full_marks FROM academic_subjects WHERE class_id=? AND status='Active' ORDER BY sort_order ASC, id ASC");
        $sstmt->execute([$sel_class]);
        $subjects = $sstmt->fetchAll();
    }
    if ($sel_class > 0 && $sel_term > 0 && $sel_part > 0) {
        $estmt = $pdo->prepare("SELECT * FROM exam_assign_subjects WHERE class_id=? AND term_id=? AND part_id=?");
        $estmt->execute([$sel_class, $sel_term, $sel_part]);
        foreach ($estmt->fetchAll() as $er) { $existing[(int) $er['subject_id']] = $er; }
    }
    // অন্য Part-এ (একই Class + Term) প্রতিটা Subject-এর যে Full Mark (Exam) আগে সেভ আছে — মোট নম্বর সীমা যাচাইয়ের জন্য
    $others = []; $others_txt = [];
    if ($sel_class > 0 && $sel_term > 0 && $sel_part > 0) {
        $ostmt = $pdo->prepare("SELECT eas.subject_id, eas.full_mark_exam, p.part_name FROM exam_assign_subjects eas LEFT JOIN exam_parts p ON p.id = eas.part_id
                                WHERE eas.class_id=? AND eas.term_id=? AND eas.part_id<>? AND eas.status='Active'");
        $ostmt->execute([$sel_class, $sel_term, $sel_part]);
        foreach ($ostmt->fetchAll() as $orow) {
            $k = (int) $orow['subject_id'];
            $others[$k] = ($others[$k] ?? 0) + (float) $orow['full_mark_exam'];
            $others_txt[$k][] = ($orow['part_name'] ?: 'Part') . ' ' . (float) $orow['full_mark_exam'];
        }
    }
    ?>
    <?php if ($sel_class <= 0 || $sel_term <= 0 || $sel_part <= 0): ?>
        <div class="text-center text-muted py-4">উপরে Class, Exam Term এবং Exam Part সিলেক্ট করুন — তাহলে ঐ ক্লাসের সব Subject লিস্ট এখানে দেখাবে।</div>
    <?php elseif (!$subjects): ?>
        <div class="text-center text-muted py-4">এই ক্লাসের জন্য কোনো Subject পাওয়া যায়নি। প্রথমে Academic Module &gt; Subject থেকে Subject যোগ করুন।</div>
    <?php else: ?>
        <form method="POST" action="index.php?page=result_assign_exams">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
            <input type="hidden" name="term_id" value="<?php echo $sel_term; ?>">
            <input type="hidden" name="part_id" value="<?php echo $sel_part; ?>">

            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Subject</th>
                            <th>Full Mark</th>
                            <th>Mark for Term (%)</th>
                            <th>Full Mark (Exam)</th>
                            <th>Pass Mark (Exam)</th>
                            <th class="text-center">Pass Individual ?</th>
                            <th class="text-center">Fail on Absent ?</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjects as $sub):
                            $sid = (int) $sub['id'];
                            $ex = $existing[$sid] ?? null;
                            $full_mark_subject     = $ex ? $ex['full_mark_subject']     : $sub['full_marks'];
                            $mark_for_term_percent  = $ex ? $ex['mark_for_term_percent'] : 100;
                            $oth_sum = (float) ($others[$sid] ?? 0);
                            $full_mark_exam         = $ex ? $ex['full_mark_exam']        : max(0, (float) $sub['full_marks'] - $oth_sum); // নতুন হলে বাকি নম্বরটুকু ডিফল্ট
                            $pass_mark_exam         = $ex ? $ex['pass_mark_exam']         : round($full_mark_exam * 0.33);
                            $pass_individual        = $ex ? (int) $ex['pass_individual']  : 1;
                            $fail_on_absent         = $ex ? (int) $ex['fail_on_absent']   : 1;
                            $pi_hid = 'pi_' . $sid; $fa_hid = 'fa_' . $sid;
                        ?>
                            <tr>
                                <td>
                                    <input type="hidden" name="subject_id[]" value="<?php echo $sid; ?>">
                                    <?php echo aev($sub['subject_name']); ?>
                                    <?php if ($sub['subject_code']): ?><span class="text-muted small">(<?php echo aev($sub['subject_code']); ?>)</span><?php endif; ?>
                                </td>
                                <td style="max-width:90px;"><input type="number" step="0.01" min="0" name="full_mark_subject[<?php echo $sid; ?>]" class="form-control form-control-sm ae-fm" data-sid="<?php echo $sid; ?>" value="<?php echo aev($full_mark_subject); ?>"></td>
                                <td style="max-width:100px;"><input type="number" step="0.01" name="mark_for_term_percent[<?php echo $sid; ?>]" class="form-control form-control-sm" value="<?php echo aev($mark_for_term_percent); ?>"></td>
                                <td style="max-width:130px;">
                                        <input type="number" step="0.01" min="0" name="full_mark_exam[<?php echo $sid; ?>]" class="form-control form-control-sm ae-fe" data-sid="<?php echo $sid; ?>" data-others="<?php echo aev($oth_sum); ?>" value="<?php echo aev($full_mark_exam); ?>">
                                        <?php if ($oth_sum > 0): ?><div class="small text-muted ae-oth" title="<?php echo aev(implode(', ', $others_txt[$sid] ?? [])); ?>">অন্য Part: <?php echo aev(rtrim(rtrim(number_format($oth_sum, 2, '.', ''), '0'), '.')); ?></div><?php endif; ?>
                                        <div class="small text-danger fw-bold ae-err" style="display:none"></div>
                                    </td>
                                <td style="max-width:100px;"><input type="number" step="0.01" name="pass_mark_exam[<?php echo $sid; ?>]" class="form-control form-control-sm" value="<?php echo aev($pass_mark_exam); ?>"></td>
                                <td class="text-center">
                                    <input type="hidden" name="pass_individual[<?php echo $sid; ?>]" id="<?php echo $pi_hid; ?>" value="<?php echo $pass_individual ? '1' : '0'; ?>">
                                    <button type="button" class="yn-btn3 <?php echo $pass_individual ? 'yn-yes3' : 'yn-no3'; ?>" data-target="<?php echo $pi_hid; ?>" onclick="ynToggle3(this)"><?php echo $pass_individual ? 'Yes' : 'No'; ?></button>
                                </td>
                                <td class="text-center">
                                    <input type="hidden" name="fail_on_absent[<?php echo $sid; ?>]" id="<?php echo $fa_hid; ?>" value="<?php echo $fail_on_absent ? '1' : '0'; ?>">
                                    <button type="button" class="yn-btn3 <?php echo $fail_on_absent ? 'yn-yes3' : 'yn-no3'; ?>" data-target="<?php echo $fa_hid; ?>" onclick="ynToggle3(this)"><?php echo $fail_on_absent ? 'Yes' : 'No'; ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <div id="aeTotalWarn" class="alert alert-danger py-2 mb-3" style="display:none"></div>
                    <button type="submit" id="aeSaveBtn" name="save_assign" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Create</button>
                    <a href="index.php?page=result_assign_exams" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
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
