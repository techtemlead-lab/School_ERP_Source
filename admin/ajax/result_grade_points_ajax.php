<?php
// admin/ajax/result_grade_points_ajax.php
// Result Module > Exam Settings > Grade Points পেজের list view এর f_session filter select
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

if (!perm_page_allowed($pdo, 'result_grade_points')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'এই পেজ দেখার অনুমতি নেই।']);
    exit();
}

require_once __DIR__ . '/../../config/csrf.php';

header('Content-Type: text/html; charset=utf-8');

// exam_grade_points টেবিল না থাকলে অটো তৈরি (result_grade_points.php এর সাথে একই স্ট্রাকচার)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_grade_points (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        grade_letter VARCHAR(10) NOT NULL,
        gpa DECIMAL(4,2) NOT NULL DEFAULT 0.00,
        mark_from DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        mark_to DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        remarks VARCHAR(100) NULL,
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

function gpv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$all_sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY session_name DESC")->fetchAll();

// ------------------- LIST VIEW (result_grade_points.php এর মূল ফাইলের সাথে হুবহু লজিক) -------------------
$f_session = $_GET['f_session'] ?? ($current_session_id ?: 'All');
$where = [];
$params = [];
if ($f_session !== 'All' && $f_session !== '') { $where[] = 'gp.session_id = ?'; $params[] = $f_session; }

$sql = "SELECT gp.*, s.session_name FROM exam_grade_points gp LEFT JOIN academic_sessions s ON s.id = gp.session_id";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY gp.mark_to DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="index.php?page=result_grade_points&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
    <span class="text-muted small">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> items.</span>
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
<form method="GET" action="index.php" id="gp_filter_form">
    <input type="hidden" name="page" value="result_grade_points">
    <div class="row mb-3">
        <label class="col-auto col-form-label">Session</label>
        <div class="col-auto">
            <select name="f_session" id="gp_f_session" class="form-select form-select-sm">
                <option value="All" <?php echo $f_session === 'All' ? 'selected' : ''; ?>>All Sessions</option>
                <?php foreach ($all_sessions as $s): ?>
                    <option value="<?php echo (int) $s['id']; ?>" <?php echo ((string) $f_session === (string) $s['id']) ? 'selected' : ''; ?>><?php echo gpv($s['session_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th>Srl.</th>
                    <th>Session</th>
                    <th>Grade Letter</th>
                    <th>GPA</th>
                    <th>Mark Range</th>
                    <th>Remarks</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                    <tr>
                        <td><?php echo $srl++; ?></td>
                        <td><?php echo gpv($row['session_name'] ?? '—'); ?></td>
                        <td><span class="badge bg-primary"><?php echo gpv($row['grade_letter']); ?></span></td>
                        <td><?php echo number_format((float) $row['gpa'], 2); ?></td>
                        <td><?php echo number_format((float) $row['mark_from'], 2) . ' - ' . number_format((float) $row['mark_to'], 2); ?></td>
                        <td><?php echo gpv($row['remarks']); ?></td>
                        <td class="text-nowrap">
                            <a href="index.php?page=result_grade_points&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=result_grade_points', 'delete_grade_point', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No data found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</form>
