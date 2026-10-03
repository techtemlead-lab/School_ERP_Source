<?php
// admin/result_exam_parts.php - Result Module > Exam Settings > Exam Parts (List + Add/Edit)
// স্ক্রিনশট অনুযায়ী ফর্ম (Main Part, Part Name, Part Short Name, Calculation for Main Part,
// Sub Part Full Marks, Rank, Status) + অতিরিক্ত ২টা অপশন যোগ করা হলো:
//   ১) Term Combine Method - এই পার্টের মার্ক Term এর সাথে Percentage / Average আকারে যুক্ত হবে, নাকি কোনোটাই না।
//   ২) Attach Scope - এই পার্ট প্রতিটা Exam Term এ আলাদা আলাদা যুক্ত হবে,
//      নাকি প্রতি টার্মে না গিয়ে সরাসরি Final Term এ সব পার্ট একসাথে যুক্ত হবে।
$ep_err = $_SESSION['ep_err'] ?? '';
$ep_msg = $_SESSION['ep_msg'] ?? '';
unset($_SESSION['ep_err'], $_SESSION['ep_msg']);

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি
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

$calc_methods    = ['Average', 'Percentage', 'Best Count'];
$combine_methods = ['None', 'Percentage', 'Average'];
$attach_scopes   = ['Every Term', 'Final Term Only'];

function epv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ep_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_exam_parts');
        exit();
    }

    if (isset($_POST['save_part'])) {
        $id                  = (int) ($_POST['id'] ?? 0);
        $part_name           = trim($_POST['part_name'] ?? '');
        // Part Short Name এখন ফর্মে নেই — Part Name থেকে স্বয়ংক্রিয়ভাবে তৈরি হয় (প্রথম ২০ অক্ষর)
        $part_short_name     = mb_substr($part_name, 0, 20);
        $calc_method         = in_array($_POST['calc_method'] ?? '', $calc_methods, true) ? $_POST['calc_method'] : 'Average';
        // Sub Part Full Marks এখন ফর্মে নেই — সবসময় NULL থাকবে
        $sub_part_full_marks = null;
        // Main Part এখন ফর্মে নেই — প্রতিটা Part এখন থেকে সবসময় নিজেই একটা Main Part (Sub Part hierarchy বাদ)
        $main_part_val       = null;
        $rank                = (int) ($_POST['rank'] ?? 1);
        $term_combine_method = in_array($_POST['term_combine_method'] ?? '', $combine_methods, true) ? $_POST['term_combine_method'] : 'None';
        $attach_scope        = in_array($_POST['attach_scope'] ?? '', $attach_scopes, true) ? $_POST['attach_scope'] : 'Every Term';
        $status              = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if ($part_name === '' || $rank <= 0 || $current_session_id <= 0) {
            $_SESSION['ep_err'] = 'Part Name, Rank আবশ্যক এবং একটা Current Session সেট করা থাকতে হবে।';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE exam_parts SET main_part_id=?, part_name=?, part_short_name=?, calc_method=?, sub_part_full_marks=?,
                     rank=?, term_combine_method=?, attach_scope=?, status=? WHERE id=?"
                );
                $stmt->execute([$main_part_val, $part_name, $part_short_name, $calc_method, $sub_part_full_marks, $rank, $term_combine_method, $attach_scope, $status, $id]);
                sec_log_activity($pdo, 'Result', 'Update', "Exam Part আপডেট করা হয়েছে: {$part_name}");
                $_SESSION['ep_msg'] = 'Exam Part আপডেট করা হয়েছে।';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO exam_parts (session_id, main_part_id, part_name, part_short_name, calc_method, sub_part_full_marks, rank, term_combine_method, attach_scope, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([$current_session_id, $main_part_val, $part_name, $part_short_name, $calc_method, $sub_part_full_marks, $rank, $term_combine_method, $attach_scope, $status]);
                sec_log_activity($pdo, 'Result', 'Create', "নতুন Exam Part তৈরি করা হয়েছে: {$part_name}");
                $_SESSION['ep_msg'] = 'নতুন Exam Part যোগ করা হয়েছে।';
            }
        }
    } elseif (isset($_POST['delete_part'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM exam_parts WHERE id = ?")->execute([$id]);
        sec_log_activity($pdo, 'Result', 'Delete', "Exam Part মুছে ফেলা হয়েছে: আইডি {$id}");
        $_SESSION['ep_msg'] = 'Exam Part মুছে ফেলা হয়েছে।';
    }

    header('Location: index.php?page=result_exam_parts');
    exit();
}

$view = $_GET['view'] ?? 'list';
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    // ------------------- ADD / EDIT FORM VIEW -------------------
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM exam_parts WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: [
        'id' => 0, 'main_part_id' => null, 'part_name' => '', 'part_short_name' => '',
        'calc_method' => 'Average', 'sub_part_full_marks' => '', 'rank' => 0,
        'term_combine_method' => 'None', 'attach_scope' => 'Every Term', 'status' => 'Active',
    ];
    if (!$edit_row && (int) $d['rank'] === 0) {
        $mx = $pdo->prepare("SELECT COALESCE(MAX(rank),0) FROM exam_parts WHERE session_id = ?");
        $mx->execute([$current_session_id]);
        $d['rank'] = (int) $mx->fetchColumn() + 1;
    }
    ?>
    <div class="card-custom">
        <?php if ($ep_err): ?><div class="alert alert-danger py-2"><?php echo epv($ep_err); ?></div><?php endif; ?>

        <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;">
            <i class="fa-solid fa-file-lines text-white"></i>
        </div>

        <h6 class="text-muted mb-4">Add Exam Part for Session : <?php echo epv($current_session_name); ?></h6>

        <form method="POST" action="index.php?page=result_exam_parts">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Part Name <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="part_name" class="form-control" value="<?php echo epv($d['part_name']); ?>" required></div>
            </div>

            <div class="row mb-3 align-items-start">
                <label class="col-sm-3 col-form-label fw-bold text-end">Calculation for Main Part ?</label>
                <div class="col-sm-6">
                    <?php foreach ($calc_methods as $cm): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="calc_method" id="calc_<?php echo md5($cm); ?>" value="<?php echo epv($cm); ?>" <?php echo $d['calc_method'] === $cm ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="calc_<?php echo md5($cm); ?>"><?php echo epv($cm); ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Rank <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="number" min="1" name="rank" class="form-control" value="<?php echo (int) $d['rank']; ?>" required></div>
            </div>

            <h5 class="section-heading2"><i class="fa-solid fa-link"></i> Term Attachment Settings</h5>

            <div class="row mb-3 align-items-start">
                <label class="col-sm-3 col-form-label fw-bold text-end">Term Combine Method</label>
                <div class="col-sm-8">
                    <?php foreach ($combine_methods as $tm): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="term_combine_method" id="tcm_<?php echo md5($tm); ?>" value="<?php echo epv($tm); ?>" <?php echo $d['term_combine_method'] === $tm ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="tcm_<?php echo md5($tm); ?>">
                                <?php echo epv($tm); ?>
                                <?php if ($tm === 'None'): ?><small class="text-muted">(Term এর সাথে Percentage/Average হিসেবে যুক্ত হবে না, রেজাল্ট মার্ক হিসেবে সরাসরি যোগ হবে)</small>
                                <?php elseif ($tm === 'Percentage'): ?><small class="text-muted">(এই পার্টের মার্ক Term এ Percentage আকারে যুক্ত হবে)</small>
                                <?php else: ?><small class="text-muted">(এই পার্টের মার্ক Term এ Average আকারে যুক্ত হবে)</small>
                                <?php endif; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row mb-4 align-items-start">
                <label class="col-sm-3 col-form-label fw-bold text-end">Attach To <span class="text-danger">*</span></label>
                <div class="col-sm-8">
                    <?php foreach ($attach_scopes as $as): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="attach_scope" id="asc_<?php echo md5($as); ?>" value="<?php echo epv($as); ?>" <?php echo $d['attach_scope'] === $as ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="asc_<?php echo md5($as); ?>">
                                <?php echo epv($as); ?>
                                <?php if ($as === 'Every Term'): ?><small class="text-muted">(এই পার্ট প্রতিটা Exam Term এ আলাদা আলাদাভাবে যুক্ত হবে)</small>
                                <?php else: ?><small class="text-muted">(প্রতি টার্মে না গিয়ে, সরাসরি Final Term এ সব পার্ট একসাথে যুক্ত হবে)</small>
                                <?php endif; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Status</label>
                <div class="col-sm-6">
                    <select name="status" class="form-select">
                        <option value="Active" <?php echo $d['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $d['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_part" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=result_exam_parts" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

<?php else: ?>
    <?php
    // ------------------- LIST VIEW -------------------
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
    <div class="card-custom">
        <?php if ($ep_msg): ?><div class="alert alert-success py-2"><?php echo epv($ep_msg); ?></div><?php endif; ?>
        <?php if ($ep_err): ?><div class="alert alert-danger py-2"><?php echo epv($ep_err); ?></div><?php endif; ?>

        <div id="ep_result_wrap">
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
        </div>
    </div>
<?php endif; ?>

<style>
.section-heading2 { color: #1e3a8a; font-weight: 700; border-bottom: 2px solid #e9ecef; padding-bottom: 8px; margin: 28px 0 18px; }
</style>

<script>
(function () {
    var wrap = document.getElementById('ep_result_wrap');
    if (!wrap) return;

    function currentParams() {
        var nameEl = wrap.querySelector('input[name="f_name"]');
        var statusEl = wrap.querySelector('#ep_f_status');
        return {
            f_name: nameEl ? nameEl.value : '',
            f_status: statusEl ? statusEl.value : 'All'
        };
    }

    function loadList() {
        var params = new URLSearchParams(currentParams());
        wrap.style.opacity = '0.6';
        fetch('ajax/result_exam_parts_ajax.php?' + params.toString())
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
        if (e.target && e.target.id === 'ep_f_status') {
            loadList();
        }
    });
})();
</script>
