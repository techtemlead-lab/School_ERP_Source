<?php
// admin/ajax/result_exam_parts_ajax.php
// Result Module > Exam Settings > Exam Parts পেজের list view এর f_status filter select
// change হলে (page reload ছাড়াই) এখান থেকে টেবিলটা AJAX দিয়ে রিফ্রেশ হয়।
session_start();

if (!isset($_SESSION['admin_logged'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Session শেষ হয়ে গেছে, আবার লগইন করুন।']);
    exit();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/permissions.php';

if (!perm_page_allowed($pdo, 'result_exam_parts')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'এই পেজ দেখার অনুমতি নেই।']);
    exit();
}

require_once __DIR__ . '/../../config/csrf.php';

header('Content-Type: text/html; charset=utf-8');

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি (result_exam_parts.php এর সাথে একই স্ট্রাকচার)
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_parts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        main_part_id INT NULL,
        part_name VARCHAR(100) NOT NULL,
        part_short_name VARCHAR(30) NOT NULL,
        calc_method VARCHAR(20) NOT NULL DEFAULT 'Average',
        sub_part_full_marks DECIMAL(6,2) NULL,
        rank INT NOT NULL DEFAULT 1,
        term_combine_method VARCHAR(20) NOT NULL DEFAULT 'None',
        attach_scope VARCHAR(20) NOT NULL DEFAULT 'Every Term',
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_session (session_id),
        KEY idx_main_part (main_part_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;
$current_session_name = $current_session['session_name'] ?? '—';

function epv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// ------------------- LIST VIEW (result_exam_parts.php এর মূল ফাইলের সাথে হুবহু লজিক) -------------------
$f_name   = trim($_GET['f_name'] ?? '');
$f_status = $_GET['f_status'] ?? 'All';

$where = ['ep.session_id = ?'];
$params = [$current_session_id];
if ($f_name !== '')   { $where[] = 'ep.part_name LIKE ?'; $params[] = "%$f_name%"; }
if ($f_status !== 'All') { $where[] = 'ep.status = ?';    $params[] = $f_status; }

$sql = "SELECT ep.*, mp.part_name AS main_part_name FROM exam_parts ep
        LEFT JOIN exam_parts mp ON mp.id = ep.main_part_id
        WHERE " . implode(' AND ', $where) . " ORDER BY ep.rank ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="index.php?page=result_exam_parts&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
    <span class="text-muted small">Session : <?php echo epv($current_session_name); ?> &nbsp;|&nbsp; Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> item.</span>
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
<form method="GET" action="index.php" id="ep_filter_form">
    <input type="hidden" name="page" value="result_exam_parts">
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th>Srl.</th>
                    <th>Rank</th>
                    <th>Part Name</th>
                    <th>Short Name</th>
                    <th>Main Part</th>
                    <th>Calculation</th>
                    <th>Term Combine</th>
                    <th>Attach To</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
                <tr>
                    <th></th><th></th>
                    <th><input type="text" name="f_name" class="form-control form-control-sm" value="<?php echo epv($f_name); ?>"></th>
                    <th></th><th></th><th></th><th></th><th></th>
                    <th>
                        <select name="f_status" id="ep_f_status" class="form-select form-select-sm">
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
                        <td><?php echo epv($row['part_name']); ?></td>
                        <td><?php echo epv($row['part_short_name']); ?></td>
                        <td><?php echo $row['main_part_name'] ? epv($row['main_part_name']) : '<span class="badge bg-info text-dark">Main Part</span>'; ?></td>
                        <td><?php echo epv($row['calc_method']); ?></td>
                        <td><?php echo $row['term_combine_method'] === 'None' ? '<span class="text-muted">None</span>' : '<span class="badge bg-primary">' . epv($row['term_combine_method']) . '</span>'; ?></td>
                        <td><?php echo $row['attach_scope'] === 'Final Term Only' ? '<span class="badge bg-warning text-dark">Final Term Only</span>' : '<span class="badge bg-secondary">Every Term</span>'; ?></td>
                        <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                        <td class="text-nowrap">
                            <a href="index.php?page=result_exam_parts&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=result_exam_parts', 'delete_part', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="10" class="text-center text-muted py-3">No data found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</form>
