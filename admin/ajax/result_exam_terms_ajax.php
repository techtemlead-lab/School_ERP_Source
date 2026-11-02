<?php
// admin/ajax/result_exam_terms_ajax.php
// Result Module > Exam Settings > Exam Terms পেজের list view এর f_final/f_status filter select
// change হলে (page reload ছাড়াই) এখান থেকে টেবিলটা AJAX দিয়ে রিফ্রেশ হয়।
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
erp_portal_require_role($pdo, 'admin', 'result_exam_terms');
require_once __DIR__ . '/../../config/permissions.php';

if (!perm_page_allowed($pdo, 'result_exam_terms')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'এই পেজ দেখার অনুমতি নেই।']);
    exit();
}
perm_write_guard($pdo, 'result_exam_terms'); // Read-only ইউজারের সেভ আটকায়

require_once __DIR__ . '/../../config/csrf.php';

header('Content-Type: text/html; charset=utf-8');

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি (result_exam_terms.php এর সাথে একই স্ট্রাকচার)
// ---------------------------------------------------------------
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

// Main/Sub টার্ম ও ওয়েট কলাম (result_exam_terms.php-এর সাথে একই লজিক)
require_once __DIR__ . '/../../config/report_card_helpers.php';
rc_pct_ensure_term_columns($pdo);
$et_active_model = '';
try { $et_active_model = (string) $pdo->query("SELECT aggregate_result_type FROM exam_result_settings WHERE id = 1")->fetchColumn(); } catch (Throwable $e) { $et_active_model = ''; }
$et_model_on   = rc_pct_is_model($et_active_model);
$et_model_type = $et_model_on ? rc_pct_models()[$et_active_model]['type'] : '';

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;
$current_session_name = $current_session['session_name'] ?? '—';

function etv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// ------------------- LIST VIEW (result_exam_terms.php এর মূল ফাইলের সাথে হুবহু লজিক) -------------------
$f_name   = trim($_GET['f_name'] ?? '');
$f_final  = $_GET['f_final'] ?? 'All';
$f_status = $_GET['f_status'] ?? 'All';

$where = ['session_id = ?'];
$params = [$current_session_id];
if ($f_name !== '')   { $where[] = 'term_name LIKE ?'; $params[] = "%$f_name%"; }
if ($f_final !== 'All')  { $where[] = 'is_final_term = ?'; $params[] = $f_final === 'Yes' ? 1 : 0; }
if ($f_status !== 'All') { $where[] = 'status = ?';        $params[] = $f_status; }

$sql = "SELECT * FROM exam_terms WHERE " . implode(' AND ', $where) . " ORDER BY rank ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$et_names = [];
$nq = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id = ?");
$nq->execute([$current_session_id]);
foreach ($nq->fetchAll() as $nr) { $et_names[(int) $nr['id']] = $nr['term_name']; }
if ($et_model_on) { $rows = rc_term_tree_order($rows); }
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="index.php?page=result_exam_terms&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
    <span class="text-muted small">Session : <?php echo etv($current_session_name); ?> &nbsp;|&nbsp; Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> item.</span>
</div>

    <!-- সারিওয়ারি Delete বাটন আগে প্রতিটা নিজের ছোট্ট &lt;form&gt;-এ মোড়ানো ছিল, আর সেই ফর্মগুলো
         থাকত নিচের টেবিলটা মোড়ানো GET ফিল্টার ফর্মের ভেতরে — অর্থাৎ ফর্মের ভেতরে ফর্ম। HTML-এ
         নেস্টেড &lt;form&gt; বৈধ না, তাই ব্রাউজার ভেতরের ফর্মটাকে বাতিল করে তার ইনপুট/বাটনগুলো
         বাইরের GET ফিল্টার ফর্মেই বসিয়ে দিত। ফলে Delete-এ ক্লিক করলে আসল POST ডিলিট রিকোয়েস্টের
         বদলে ফিল্টার ফর্মটাই (GET, action=index.php) সাবমিট হয়ে যেত — কিছুই ডিলিট হতো না।
         সমাধানঃ একটামাত্র শেয়ার্ড হিডেন ফর্ম, GET ফিল্টার ফর্মের বাইরে রাখা — প্রতিটা Delete
         বাটন এখন আলাদা ফর্ম না বানিয়ে শুধু এটাকেই JS দিয়ে সাবমিট করে। -->
    <form method="POST" id="wcRowActionForm" style="display:none">
        <input type="hidden" name="csrf_token" id="wcRowActionCsrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" id="wcRowActionId" value="">
    </form>
    <script>
    function wcRowAction(url, field, id, confirmMsg) {
        if (confirmMsg && !window.confirm(confirmMsg)) return false;
        var f = document.getElementById('wcRowActionForm');
        f.setAttribute('action', url);
        document.getElementById('wcRowActionId').value = id;
        var old = f.querySelector('input[data-dyn-field]');
        if (old) old.parentNode.removeChild(old);
        var fld = document.createElement('input');
        fld.type = 'hidden'; fld.name = field; fld.value = '1'; fld.setAttribute('data-dyn-field', '1');
        f.appendChild(fld);
        f.submit();
        return false;
    }
    </script>
<form method="GET" action="index.php" id="et_filter_form">
    <input type="hidden" name="page" value="result_exam_terms">
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th>Srl.</th>
                    <th>Rank</th>
                    <th>Term Name</th>
                    <th>Final Term ?</th>
                    <th>Aggregated Result ?</th>
                    <?php if ($et_model_on): ?><th>Type / Weight</th><?php endif; ?>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
                <tr>
                    <th></th>
                    <th></th>
                    <th><input type="text" name="f_name" class="form-control form-control-sm" value="<?php echo etv($f_name); ?>"></th>
                    <th>
                        <select name="f_final" id="et_f_final" class="form-select form-select-sm">
                            <option value="All" <?php echo $f_final === 'All' ? 'selected' : ''; ?>>All</option>
                            <option value="Yes" <?php echo $f_final === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                            <option value="No" <?php echo $f_final === 'No' ? 'selected' : ''; ?>>No</option>
                        </select>
                    </th>
                    <th></th>
                    <?php if ($et_model_on): ?><th></th><?php endif; ?>
                    <th>
                        <select name="f_status" id="et_f_status" class="form-select form-select-sm">
                            <option value="All" <?php echo $f_status === 'All' ? 'selected' : ''; ?>>All</option>
                            <option value="Active" <?php echo $f_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo $f_status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </th>
                    <th><button type="submit" class="btn btn-sm btn-outline-primary">Filter</button></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                    <tr>
                        <td><?php echo $srl++; ?></td>
                        <td><?php echo (int) $row['rank']; ?></td>
                        <td<?php echo !empty($row['depth']) ? ' style="padding-left:28px;"' : ''; ?>><?php echo !empty($row['depth']) ? '<span class="text-muted">↳</span> ' : ''; ?><?php echo etv($row['term_name']); ?></td>
                        <td><?php echo $row['is_final_term'] ? '<span class="badge bg-primary">Yes</span>' : '<span class="text-muted">No</span>'; ?></td>
                        <td><?php echo $row['is_aggregated_result'] ? '<span class="badge bg-primary">Yes</span>' : '<span class="text-muted">No</span>'; ?></td>
                        <?php if ($et_model_on): ?><td><?php echo rc_term_weight_cell_html($row, $et_names, $et_model_type); ?></td><?php endif; ?>
                        <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                        <td class="text-nowrap">
                            <?php if ($et_model_on && empty($row['parent_term_id']) && in_array($et_model_type, ['nested', 'standalone', 'avg_group'], true)): ?>
                                <a href="index.php?page=result_exam_terms&view=add&parent=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-success" title="এই Main টার্মের ভেতরে Sub (মাসিক/ক্লাস টেস্ট) যোগ করুন"><i class="fa-solid fa-plus"></i> Sub</a>
                            <?php endif; ?>
                            <a href="index.php?page=result_exam_terms&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=result_exam_terms', 'delete_term', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="<?php echo $et_model_on ? 8 : 7; ?>" class="text-center text-muted py-3">No data found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</form>
