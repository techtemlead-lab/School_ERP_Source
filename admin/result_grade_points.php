<?php
// admin/result_grade_points.php - Result Module > Exam Settings > Grade Points
$gp_err = $_SESSION['gp_err'] ?? '';
$gp_msg = $_SESSION['gp_msg'] ?? '';
unset($_SESSION['gp_err'], $_SESSION['gp_msg']);

// exam_grade_points টেবিল না থাকলে অটো তৈরি (result_settings.php-তেও একই টেবিল ব্যবহার হয়)
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

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['gp_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_grade_points');
        exit();
    }

    if (isset($_POST['save_grade_point'])) {
        $id           = (int) ($_POST['id'] ?? 0);
        $session_id   = (int) ($_POST['session_id'] ?? $current_session_id);
        $grade_letter = trim($_POST['grade_letter'] ?? '');
        $gpa          = (float) ($_POST['gpa'] ?? 0);
        $mark_from    = (float) ($_POST['mark_from'] ?? 0);
        $mark_to      = (float) ($_POST['mark_to'] ?? 0);
        $remarks      = trim($_POST['remarks'] ?? '');

        if ($grade_letter === '' || $session_id <= 0) {
            $_SESSION['gp_err'] = 'Session এবং Grade Letter অবশ্যই দিতে হবে।';
        } elseif ($mark_to < $mark_from) {
            $_SESSION['gp_err'] = 'Mark To অবশ্যই Mark From-এর চেয়ে বড় বা সমান হতে হবে।';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE exam_grade_points SET session_id=?, grade_letter=?, gpa=?, mark_from=?, mark_to=?, remarks=? WHERE id=?");
                $stmt->execute([$session_id, $grade_letter, $gpa, $mark_from, $mark_to, $remarks, $id]);
                sec_log_activity($pdo, 'Result', 'Update', "Grade Point আপডেট করা হয়েছে: {$grade_letter}");
                $_SESSION['gp_msg'] = 'Grade Point আপডেট করা হয়েছে।';
            } else {
                $stmt = $pdo->prepare("INSERT INTO exam_grade_points (session_id, grade_letter, gpa, mark_from, mark_to, remarks) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$session_id, $grade_letter, $gpa, $mark_from, $mark_to, $remarks]);
                sec_log_activity($pdo, 'Result', 'Create', "নতুন Grade Point তৈরি করা হয়েছে: {$grade_letter}");
                $_SESSION['gp_msg'] = 'নতুন Grade Point যোগ করা হয়েছে।';
            }
        }
    } elseif (isset($_POST['delete_grade_point'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM exam_grade_points WHERE id = ?")->execute([$id]);
        sec_log_activity($pdo, 'Result', 'Delete', "Grade Point মুছে ফেলা হয়েছে: আইডি {$id}");
        $_SESSION['gp_msg'] = 'Grade Point মুছে ফেলা হয়েছে।';
    }

    header('Location: index.php?page=result_grade_points');
    exit();
}

$view = $_GET['view'] ?? 'list';
function gpv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
$all_sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY session_name DESC")->fetchAll();
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM exam_grade_points WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $g = $edit_row ?: ['id' => 0, 'session_id' => $current_session_id, 'grade_letter' => '', 'gpa' => '', 'mark_from' => '', 'mark_to' => '', 'remarks' => ''];
    ?>
    <div class="card-custom">
        <?php if ($gp_err): ?><div class="alert alert-danger py-2"><?php echo gpv($gp_err); ?></div><?php endif; ?>

        <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;">
            <i class="fa-solid fa-bars text-white"></i>
        </div>

        <form method="POST" action="index.php?page=result_grade_points">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $g['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label text-end">Session <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="session_id" class="form-select" required>
                        <option value="">Select Session</option>
                        <?php foreach ($all_sessions as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo ((int) $g['session_id'] === (int) $s['id']) ? 'selected' : ''; ?>><?php echo gpv($s['session_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label text-end">Grade Letter <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="text" name="grade_letter" class="form-control" value="<?php echo gpv($g['grade_letter']); ?>" placeholder="e.g. A+" required>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label text-end">GPA <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="number" step="0.01" min="0" max="5" name="gpa" class="form-control" value="<?php echo gpv($g['gpa']); ?>" placeholder="e.g. 5.00" required>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label text-end">Mark From <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="number" step="0.01" min="0" name="mark_from" class="form-control" value="<?php echo gpv($g['mark_from']); ?>" required>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label text-end">Mark To <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="number" step="0.01" min="0" name="mark_to" class="form-control" value="<?php echo gpv($g['mark_to']); ?>" required>
                </div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label text-end">Remarks</label>
                <div class="col-sm-6">
                    <input type="text" name="remarks" class="form-control" value="<?php echo gpv($g['remarks']); ?>" placeholder="e.g. Excellent">
                </div>
            </div>

            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_grade_point" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=result_grade_points" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

<?php else: ?>
    <?php
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
    <div class="card-custom">
        <?php if ($gp_msg): ?><div class="alert alert-success py-2"><?php echo gpv($gp_msg); ?></div><?php endif; ?>
        <?php if ($gp_err): ?><div class="alert alert-danger py-2"><?php echo gpv($gp_err); ?></div><?php endif; ?>

        <div id="gp_result_wrap">
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
        </div>
    </div>
<?php endif; ?>

<script>
(function () {
    var wrap = document.getElementById('gp_result_wrap');
    if (!wrap) return;

    function loadList() {
        var sessionEl = wrap.querySelector('#gp_f_session');
        var params = new URLSearchParams({ f_session: sessionEl ? sessionEl.value : 'All' });
        wrap.style.opacity = '0.6';
        fetch('ajax/result_grade_points_ajax.php?' + params.toString())
            .then(function (r) {
                if (!r.ok) throw new Error('bad response');
                return r.text();
            })
            .then(function (html) {
                wrap.innerHTML = html;
                wrap.style.opacity = '1';
            })
            .catch(function () {
                wrap.style.opacity = '1';
                wrap.insertAdjacentHTML('afterbegin', '<div class="alert alert-danger py-2">ডাটা লোড করতে সমস্যা হয়েছে, আবার চেষ্টা করুন।</div>');
            });
    }

    // event delegation ব্যবহার করা হয়েছে যাতে AJAX দিয়ে wrap এর ভেতরের select রিপ্লেস হয়ে
    // গেলেও (নতুন select এলিমেন্ট তৈরি হলেও) change ইভেন্ট ধরা পড়ে
    wrap.addEventListener('change', function (e) {
        if (e.target && e.target.id === 'gp_f_session') {
            loadList();
        }
    });
})();
</script>
