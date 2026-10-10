<?php
// admin/ajax/result_term_settings_ajax.php
// Result Module > Exam Settings > Term Publish Settings পেজের Session filter select
// change হলে (page reload ছাড়াই) এখান থেকে টার্ম লিস্ট + পাবলিশ সেটিংস টেবিল AJAX দিয়ে রিফ্রেশ হয়।
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
erp_portal_require_role($pdo, 'admin', 'result_term_settings');
require_once __DIR__ . '/../../config/permissions.php';

if (!perm_page_allowed($pdo, 'result_term_settings')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'এই পেজ দেখার অনুমতি নেই।']);
    exit();
}
perm_write_guard($pdo, 'result_term_settings'); // Read-only ইউজারের সেভ আটকায়

require_once __DIR__ . '/../../config/csrf.php';

header('Content-Type: text/html; charset=utf-8');

// exam_terms টেবিল না থাকলে অটো তৈরি (result_term_settings.php এর সাথে একই স্ট্রাকচার)
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

function tsv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$publish_fields = [
    'admit_card_with_routine'       => 'Admit Card With Routine',
    'admit_card_with_instruction'   => 'Admit Card With Instruction',
    'exam_routine_with_instruction' => 'Exam Routine With Instruction',
    'website_result_publish'        => 'Website Result Publish',
];

// সেশন ফিল্টার (result_term_settings.php এর মূল ফাইলের সাথে হুবহু লজিক)
$sessions = $pdo->query("SELECT id, session_name, is_current_session FROM academic_sessions ORDER BY session_name DESC")->fetchAll();
$current_session = $pdo->query("SELECT id, session_name FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$default_session_id = $current_session ? (int) $current_session['id'] : ($sessions[0]['id'] ?? 0);
$f_session = (int) ($_GET['session_id'] ?? $default_session_id);

$stmt = $pdo->prepare("SELECT * FROM exam_terms WHERE session_id = ? ORDER BY rank ASC");
$stmt->execute([$f_session]);
$terms = $stmt->fetchAll();
?>
<form method="GET" action="index.php" class="row mb-4 align-items-center" id="ts_filter_form">
    <input type="hidden" name="page" value="result_term_settings">
    <label class="col-auto col-form-label fw-bold">Session</label>
    <div class="col-auto">
        <select name="session_id" id="ts_session_id" class="form-select">
            <?php foreach ($sessions as $s): ?>
                <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>>
                    <?php echo tsv($s['session_name']); ?><?php echo $s['is_current_session'] ? ' (Current)' : ''; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<?php if (!$terms): ?>
    <div class="text-center text-muted py-4">
        এই সেশনে কোনো Exam Term পাওয়া যায়নি। প্রথমে
        <a href="index.php?page=result_exam_terms&view=add">Exam Term</a> তৈরি করুন।
    </div>
<?php else: ?>
    <form method="POST" action="index.php?page=result_term_settings">
        <?php echo csrf_field(); ?>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Rank</th>
                        <th>Term Name</th>
                        <?php foreach ($publish_fields as $label): ?>
                            <th class="text-center"><?php echo tsv($label); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($terms as $t): $tid = (int) $t['id']; ?>
                        <tr>
                            <td><?php echo (int) $t['rank']; ?></td>
                            <td><?php echo tsv($t['term_name']); ?></td>
                            <input type="hidden" name="term_id[]" value="<?php echo $tid; ?>">
                            <?php foreach ($publish_fields as $key => $label):
                                $is_yes = (int) $t[$key] === 1;
                                $hid = $key . '_' . $tid;
                            ?>
                                <td class="text-center">
                                    <input type="hidden" name="<?php echo $key; ?>[<?php echo $tid; ?>]" id="<?php echo $hid; ?>" value="<?php echo $is_yes ? '1' : '0'; ?>">
                                    <button type="button" class="yn-btn2 <?php echo $is_yes ? 'yn-yes2' : 'yn-no2'; ?>" data-target="<?php echo $hid; ?>" onclick="ynToggle2(this)"><?php echo $is_yes ? 'Yes' : 'No'; ?></button>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <hr>
        <div class="row">
            <div class="col-sm-6 offset-sm-3">
                <button type="submit" name="save_publish_settings" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Update</button>
                <a href="index.php?page=dashboard" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div>
        </div>
    </form>
<?php endif; ?>
