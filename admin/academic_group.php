<?php
// admin/academic_group.php - Group List + Add/Edit (স্ক্রিনশট অনুযায়ী)
$grp_err = $_SESSION['grp_err'] ?? '';
$grp_msg = $_SESSION['grp_msg'] ?? '';
unset($_SESSION['grp_err'], $_SESSION['grp_msg']);

// -----------------------------------------------------------
// লাইভ Duplicate চেক (Group Name লেখার সময় AJAX দিয়ে কল হয়)
// -----------------------------------------------------------
if (isset($_GET['check_dup'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $c_class = (int) ($_GET['class_id'] ?? 0);
    $c_name  = trim($_GET['name'] ?? '');
    $c_id    = (int) ($_GET['id'] ?? 0);
    $dup = false;
    if ($c_class > 0 && $c_name !== '') {
        $q = $pdo->prepare("SELECT COUNT(*) FROM academic_groups WHERE class_id = ? AND LOWER(TRIM(group_name)) = LOWER(?) AND id <> ?");
        $q->execute([$c_class, $c_name, $c_id]);
        $dup = ((int) $q->fetchColumn() > 0);
    }
    echo json_encode(['duplicate' => $dup]);
    exit();
}

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['grp_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=academic_group');
        exit();
    }

    if (isset($_POST['save_group'])) {
        $id         = (int) ($_POST['id'] ?? 0);
        $class_id   = (int) ($_POST['class_id'] ?? 0);
        $group_name = trim($_POST['group_name'] ?? '');
        $status     = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if ($class_id <= 0 || $group_name === '') {
            $_SESSION['grp_err'] = 'Class Name এবং Group Name আবশ্যক।';
        } else {
            // একই Class-এ একই নামের Group আগে থেকে আছে কিনা (ছোট/বড় হাতের অক্ষর ও বাড়তি স্পেস উপেক্ষা করে)
            $dup_stmt = $pdo->prepare("SELECT COUNT(*) FROM academic_groups WHERE class_id = ? AND LOWER(TRIM(group_name)) = LOWER(?) AND id <> ?");
            $dup_stmt->execute([$class_id, $group_name, $id]);
            if ((int) $dup_stmt->fetchColumn() > 0) {
                $_SESSION['grp_err'] = 'এই Class-এ "' . $group_name . '" নামে Group আগে থেকেই আছে (Duplicate)। অনুগ্রহ করে অন্য নাম দিন।';
                $_SESSION['grp_old'] = ['class_id' => $class_id, 'group_name' => $group_name, 'status' => $status];
                header('Location: index.php?page=academic_group&view=' . ($id > 0 ? 'edit&id=' . $id : 'add'));
                exit();
            }
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE academic_groups SET class_id=?, group_name=?, status=? WHERE id=?");
                $stmt->execute([$class_id, $group_name, $status, $id]);
                sec_log_activity($pdo, 'Academic', 'Update', "Group আপডেট করা হয়েছে: {$group_name}");
                $_SESSION['grp_msg'] = 'Group আপডেট করা হয়েছে।';
            } else {
                $stmt = $pdo->prepare("INSERT INTO academic_groups (class_id, group_name, status) VALUES (?, ?, ?)");
                $stmt->execute([$class_id, $group_name, $status]);
                sec_log_activity($pdo, 'Academic', 'Create', "নতুন Group যোগ করা হয়েছে: {$group_name}");
                $_SESSION['grp_msg'] = 'নতুন Group যোগ করা হয়েছে।';
            }
        }
    } elseif (isset($_POST['delete_group'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_stmt = $pdo->prepare("SELECT group_name FROM academic_groups WHERE id = ?");
        $del_stmt->execute([$id]);
        $del_name = $del_stmt->fetchColumn();
        $pdo->prepare("DELETE FROM academic_groups WHERE id = ?")->execute([$id]);
        sec_log_activity($pdo, 'Academic', 'Delete', "Group মুছে ফেলা হয়েছে: " . ($del_name ?: "#$id"));
        $_SESSION['grp_msg'] = 'Group মুছে ফেলা হয়েছে।';
    }

    header('Location: index.php?page=academic_group');
    exit();
}

$view = $_GET['view'] ?? 'list';
$classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' AND has_group = 1 ORDER BY rank ASC, class_name ASC")->fetchAll();

function gv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    // ------------------- ADD / EDIT FORM VIEW -------------------
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM academic_groups WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: ['id' => 0, 'class_id' => '', 'group_name' => '', 'status' => 'Active'];
    if (!empty($_SESSION['grp_old']) && is_array($_SESSION['grp_old'])) {
        $d = array_merge($d, $_SESSION['grp_old']);
    }
    unset($_SESSION['grp_old']);
    ?>
    <div class="card-custom">
        <?php if ($grp_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($grp_err); ?></div><?php endif; ?>

        <a href="index.php?page=academic_group" class="d-inline-block bg-warning bg-opacity-75 p-2 mb-4 text-decoration-none" style="width:40px;border-radius:4px;" title="তালিকায় ফিরে যান (Back to List)">
            <i class="fa-solid fa-file-lines text-white"></i>
        </a>

        <form method="POST" action="index.php?page=academic_group" id="grpForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Class Name <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="class_id" class="form-select" required>
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>" <?php echo (int) $d['class_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo gv($c['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$classes): ?><small class="text-danger">প্রথমে "All Class List" থেকে একটা Class যোগ করুন।</small><?php endif; ?>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Group Name <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="group_name" id="grpName" class="form-control" value="<?php echo gv($d['group_name']); ?>" placeholder="e.g. Science / Arts / Hifz" autocomplete="off" required>
                    <div id="grpDupMsg" class="text-danger small mt-1" style="display:none;"><i class="fa-solid fa-circle-exclamation"></i> এই Class-এ এই নামে Group আগে থেকেই আছে (Duplicate)। অন্য নাম দিন।</div>
                </div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Status <span class="text-danger">*</span></label>
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
                    <button type="submit" name="save_group" id="grpSaveBtn" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=academic_group" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>
    <script>
    (function () {
        var nameEl = document.getElementById('grpName');
        var classEl = document.querySelector('#grpForm select[name="class_id"]');
        var msgEl = document.getElementById('grpDupMsg');
        var btn = document.getElementById('grpSaveBtn');
        var idEl = document.querySelector('#grpForm input[name="id"]');
        var timer = null, seq = 0, isDup = false;

        function setDup(v) {
            isDup = v;
            msgEl.style.display = v ? 'block' : 'none';
            nameEl.classList.toggle('is-invalid', v);
            btn.disabled = v;
        }
        function check() {
            var name = nameEl.value.trim(), cls = classEl.value;
            if (!name || !cls) { setDup(false); return; }
            var my = ++seq;
            var url = 'index.php?page=academic_group&check_dup=1&class_id=' + encodeURIComponent(cls)
                    + '&name=' + encodeURIComponent(name) + '&id=' + encodeURIComponent(idEl.value || 0);
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (my === seq) setDup(!!d.duplicate); })
                .catch(function () { /* নেটওয়ার্ক সমস্যা হলে সার্ভার-সাইড চেকই কাজ করবে */ });
        }
        function schedule() { clearTimeout(timer); timer = setTimeout(check, 300); }

        nameEl.addEventListener('input', schedule);
        classEl.addEventListener('change', check);
        document.getElementById('grpForm').addEventListener('submit', function (e) { if (isDup) e.preventDefault(); });
        check();
    })();
    </script>

<?php else: ?>
    <?php
    // ------------------- LIST VIEW -------------------
    $f_class  = $_GET['f_class'] ?? 'All';
    $f_name   = trim($_GET['f_name'] ?? '');
    $f_status = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_class !== 'All') { $where[] = 'academic_groups.class_id = ?'; $params[] = (int) $f_class; }
    if ($f_name !== '')     { $where[] = 'group_name LIKE ?'; $params[] = "%$f_name%"; }
    if ($f_status !== 'All') { $where[] = 'status = ?'; $params[] = $f_status; }

    $sql = "SELECT academic_groups.*, academic_classes.class_name FROM academic_groups
            LEFT JOIN academic_classes ON academic_classes.id = academic_groups.class_id";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY academic_groups.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($grp_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($grp_msg); ?></div><?php endif; ?>
        <?php if ($grp_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($grp_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=academic_group&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
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
<form method="GET" action="index.php">
            <input type="hidden" name="page" value="academic_group">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>Class Name</th>
                            <th>Group Name</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <tr>
                            <th></th>
                            <th>
                                <select name="f_class" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All">All</option>
                                    <?php foreach ($classes as $c): ?>
                                        <option value="<?php echo (int) $c['id']; ?>" <?php echo (string) $f_class === (string) $c['id'] ? 'selected' : ''; ?>><?php echo gv($c['class_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th><input type="text" name="f_name" class="form-control form-control-sm" value="<?php echo gv($f_name); ?>"></th>
                            <th>
                                <select name="f_status" class="form-select form-select-sm" onchange="this.form.submit()">
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
                                <td><?php echo gv($row['class_name'] ?? '-'); ?></td>
                                <td><?php echo gv($row['group_name']); ?></td>
                                <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=academic_group&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                    <a href="index.php?page=academic_group&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=academic_group', 'delete_group', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
<?php endif; ?>
