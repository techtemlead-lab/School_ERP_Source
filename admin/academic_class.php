<?php
// admin/academic_class.php - Class List + Add/Edit (স্ক্রিনশট অনুযায়ী)
$cl_err = $_SESSION['cl_err'] ?? '';
$cl_msg = $_SESSION['cl_msg'] ?? '';
$cl_field_errors = $_SESSION['cl_field_errors'] ?? [];
$cl_old = $_SESSION['cl_old'] ?? null;
unset($_SESSION['cl_err'], $_SESSION['cl_msg'], $_SESSION['cl_field_errors'], $_SESSION['cl_old']);

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['cl_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=academic_class');
        exit();
    }

    if (isset($_POST['save_class'])) {
        $id         = (int) ($_POST['id'] ?? 0);
        $medium_id  = (int) ($_POST['medium_id'] ?? 0);
        $class_name = trim($_POST['class_name'] ?? '');
        $rank       = (int) ($_POST['rank'] ?? 0);
        $class_code = trim($_POST['class_code'] ?? '');
        $has_group  = ($_POST['has_group'] ?? 'No') === 'Yes' ? 1 : 0;
        $status     = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        // ফর্মে ফিরে যাওয়ার জন্য টার্গেট ভিউ (Add নাকি Edit)
        $back_url = $id > 0 ? "index.php?page=academic_class&view=edit&id=$id" : "index.php?page=academic_class&view=add";

        $field_errors = [];
        if ($medium_id <= 0) {
            $field_errors['medium_id'] = 'Medium সিলেক্ট করুন।';
        }
        if ($class_name === '') {
            $field_errors['class_name'] = 'Class Name আবশ্যক।';
        }
        if ($class_code === '') {
            $field_errors['class_code'] = 'Class Code আবশ্যক।';
        } elseif (!preg_match('/^\d{2}$/', $class_code)) {
            $field_errors['class_code'] = 'Class Code অবশ্যই ২ ডিজিটের হতে হবে (যেমন: 09, 10)।';
        }

        // Duplicate check — সব Medium মিলিয়ে গ্লোবালি ইউনিক (কারণ Student ID
        // জেনারেট হয় শুধু Class Code দিয়ে, Medium ধরে না — তাই দুই Medium-এ
        // একই Code/Name থাকলে Student ID কলাইড করবে বা কনফিউশন হবে)
        if ($class_name !== '' && !isset($field_errors['class_name'])) {
            $chk = $pdo->prepare("SELECT id FROM academic_classes WHERE LOWER(class_name) = LOWER(?) " . ($id > 0 ? "AND id != ?" : ""));
            $chk->execute($id > 0 ? [$class_name, $id] : [$class_name]);
            if ($chk->fetch()) {
                $field_errors['class_name'] = 'এই নামে একটা Class আগে থেকেই আছে (Duplicate)। অন্য নাম দিন।';
            }
        }
        if ($class_code !== '' && !isset($field_errors['class_code'])) {
            $chk = $pdo->prepare("SELECT id FROM academic_classes WHERE class_code = ? " . ($id > 0 ? "AND id != ?" : ""));
            $chk->execute($id > 0 ? [$class_code, $id] : [$class_code]);
            if ($chk->fetch()) {
                $field_errors['class_code'] = 'এই Code দিয়ে একটা Class আগে থেকেই আছে (Duplicate)। অন্য Code দিন।';
            }
        }

        // Rank Duplicate check — একই Medium-এর ভেতরে একই Rank দুটো Class-এ থাকতে পারবে না
        if ($medium_id > 0) {
            $chk = $pdo->prepare("SELECT class_name FROM academic_classes WHERE medium_id = ? AND rank = ? " . ($id > 0 ? "AND id != ?" : "") . " LIMIT 1");
            $chk->execute($id > 0 ? [$medium_id, $rank, $id] : [$medium_id, $rank]);
            $dup_rank_class = $chk->fetchColumn();
            if ($dup_rank_class !== false) {
                $field_errors['rank'] = 'এই Medium-এ Rank ' . $rank . ' আগে থেকেই "' . $dup_rank_class . '" Class-এর আছে (Duplicate)। অন্য Rank দিন।';
            }
        }

        if ($field_errors) {
            $_SESSION['cl_field_errors'] = $field_errors;
            $_SESSION['cl_old'] = $_POST;
            header("Location: $back_url");
            exit();
        }

        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE academic_classes SET medium_id=?, class_name=?, rank=?, class_code=?, has_group=?, status=? WHERE id=?"
            );
            $stmt->execute([$medium_id, $class_name, $rank, $class_code, $has_group, $status, $id]);
            sec_log_activity($pdo, 'Academic', 'Update', "Class আপডেট করা হয়েছে: {$class_name} ({$class_code})");
            $_SESSION['cl_msg'] = 'Class আপডেট করা হয়েছে।';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO academic_classes (medium_id, class_name, rank, class_code, has_group, status) VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$medium_id, $class_name, $rank, $class_code, $has_group, $status]);
            sec_log_activity($pdo, 'Academic', 'Create', "নতুন Class যোগ করা হয়েছে: {$class_name} ({$class_code})");
            $_SESSION['cl_msg'] = 'নতুন Class যোগ করা হয়েছে।';
        }
    } elseif (isset($_POST['delete_class'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_stmt = $pdo->prepare("SELECT class_name FROM academic_classes WHERE id = ?");
        $del_stmt->execute([$id]);
        $del_name = $del_stmt->fetchColumn();
        $pdo->prepare("DELETE FROM academic_classes WHERE id = ?")->execute([$id]);
        sec_log_activity($pdo, 'Academic', 'Delete', "Class মুছে ফেলা হয়েছে: " . ($del_name ?: "#$id"));
        $_SESSION['cl_msg'] = 'Class মুছে ফেলা হয়েছে।';
    }

    header('Location: index.php?page=academic_class');
    exit();
}

$view = $_GET['view'] ?? 'list';
$mediums = $pdo->query("SELECT id, medium_name FROM academic_mediums WHERE status = 'Active' ORDER BY medium_name ASC")->fetchAll();

// প্রতিটা Medium-এর সর্বশেষ Rank — "Add Class" ফর্মে হিন্ট হিসেবে দেখানোর জন্য (JS দিয়ে)
$last_ranks = [];
foreach ($mediums as $m) {
    $stmt = $pdo->prepare("SELECT MAX(rank) AS max_rank FROM academic_classes WHERE medium_id = ?");
    $stmt->execute([$m['id']]);
    $last_ranks[$m['id']] = (int) ($stmt->fetch()['max_rank'] ?? 0);
}

// লাইভ Duplicate Rank চেকের জন্য: Medium → Rank → Class নাম (Edit-এ নিজের Class বাদ দিতে id-ও)
$rank_map = [];
foreach ($pdo->query("SELECT id, medium_id, rank, class_name FROM academic_classes")->fetchAll() as $rk) {
    $rank_map[(int) $rk['medium_id']][(int) $rk['rank']][] = ['id' => (int) $rk['id'], 'n' => $rk['class_name']];
}

$dup_classes = [];
foreach ($pdo->query("SELECT id, class_name, class_code FROM academic_classes")->fetchAll() as $dc) {
    $dup_classes[] = ['id' => (int) $dc['id'], 'n' => (string) $dc['class_name'], 'c' => (string) ($dc['class_code'] ?? '')];
}

function clv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    // ------------------- ADD / EDIT FORM VIEW -------------------
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM academic_classes WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: ['id' => 0, 'medium_id' => '', 'class_name' => '', 'rank' => '', 'class_code' => '', 'has_group' => 0, 'status' => 'Active'];

    // ভ্যালিডেশন এরর থাকলে ইউজারের আগের ইনপুট দিয়ে ফর্ম রিফিল করা হচ্ছে,
    // যাতে ভুল সংশোধন করতে গিয়ে বাকি ফিল্ড আবার নতুন করে টাইপ করতে না হয়
    if ($cl_old) {
        $d = [
            'id'         => (int) ($cl_old['id'] ?? $d['id']),
            'medium_id'  => $cl_old['medium_id'] ?? $d['medium_id'],
            'class_name' => $cl_old['class_name'] ?? $d['class_name'],
            'rank'       => $cl_old['rank'] ?? $d['rank'],
            'class_code' => $cl_old['class_code'] ?? $d['class_code'],
            'has_group'  => ($cl_old['has_group'] ?? 'No') === 'Yes' ? 1 : 0,
            'status'     => $cl_old['status'] ?? $d['status'],
        ];
    }
    ?>
    <div class="card-custom">
        <?php if ($cl_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($cl_err); ?></div><?php endif; ?>

        <a href="index.php?page=academic_class" class="d-inline-block bg-warning bg-opacity-75 p-2 mb-4 text-decoration-none" style="width:40px;border-radius:4px;" title="তালিকায় ফিরে যান (Back to List)">
            <i class="fa-solid fa-file-lines text-white"></i>
        </a>

        <form method="POST" action="index.php?page=academic_class" id="classForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Medium Name <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="medium_id" id="mediumSelect" class="form-select" required onchange="updateLastRankHint()">
                        <option value="">Select Medium</option>
                        <?php foreach ($mediums as $m): ?>
                            <option value="<?php echo (int) $m['id']; ?>" <?php echo (int) $d['medium_id'] === (int) $m['id'] ? 'selected' : ''; ?>><?php echo clv($m['medium_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$mediums): ?><small class="text-danger">প্রথমে "All Medium" থেকে একটা Medium যোগ করুন।</small><?php endif; ?>
                    <?php if (!empty($cl_field_errors['medium_id'])): ?><div class="small text-danger mt-1"><?php echo htmlspecialchars($cl_field_errors['medium_id']); ?></div><?php endif; ?>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Class Name <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="text" name="class_name" id="classNameInput" autocomplete="off" class="form-control<?php echo !empty($cl_field_errors['class_name']) ? ' is-invalid' : ''; ?>" value="<?php echo clv($d['class_name']); ?>" placeholder="e.g. Class Six" required>
                    <div class="small text-danger mt-1" id="nameDupMsg" style="<?php echo !empty($cl_field_errors['class_name']) ? '' : 'display:none;'; ?>"><?php echo !empty($cl_field_errors['class_name']) ? htmlspecialchars($cl_field_errors['class_name']) : ''; ?></div>
                </div>
            </div>

            <div class="row mb-1 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Rank <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="number" name="rank" id="rankInput" class="form-control<?php echo !empty($cl_field_errors['rank']) ? ' is-invalid' : ''; ?>" value="<?php echo clv($d['rank']); ?>" required>
                    <div class="small text-danger mt-1" id="rankDupMsg" style="<?php echo !empty($cl_field_errors['rank']) ? '' : 'display:none;'; ?>"><?php echo !empty($cl_field_errors['rank']) ? htmlspecialchars($cl_field_errors['rank']) : ''; ?></div>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-sm-6 offset-sm-3 text-end">
                    <small class="text-danger" id="lastRankHint">Last Rank: After Change Medium</small>
                </div>
            </div>

            <div class="row mb-1 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Class Code <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="text" name="class_code" id="classCodeInput" autocomplete="off" class="form-control<?php echo !empty($cl_field_errors['class_code']) ? ' is-invalid' : ''; ?>" value="<?php echo clv($d['class_code']); ?>" maxlength="2" required>
                    <div class="small text-danger mt-1" id="codeDupMsg" style="<?php echo !empty($cl_field_errors['class_code']) ? '' : 'display:none;'; ?>"><?php echo !empty($cl_field_errors['class_code']) ? htmlspecialchars($cl_field_errors['class_code']) : ''; ?></div>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-sm-6 offset-sm-3"><small class="text-primary fst-italic">Class code must be 2 digit. For examples: Class Nine - 09, Class Ten - 10</small></div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Has Group/4th Subject <span class="text-danger">*</span></label>
                <div class="col-sm-6 d-flex gap-4 align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="has_group" id="hgYes" value="Yes" <?php echo $d['has_group'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="hgYes">Yes</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="has_group" id="hgNo" value="No" <?php echo !$d['has_group'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="hgNo">No</label>
                    </div>
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
                    <button type="submit" name="save_class" id="classSaveBtn" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=academic_class" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

    <script>
        const lastRanks = <?php echo json_encode($last_ranks, JSON_NUMERIC_CHECK); ?>;
        function updateLastRankHint() {
            const mediumId = document.getElementById('mediumSelect').value;
            const hint = document.getElementById('lastRankHint');
            if (mediumId && lastRanks.hasOwnProperty(mediumId)) {
                hint.textContent = 'Last Rank: ' + lastRanks[mediumId];
            } else {
                hint.textContent = 'Last Rank: After Change Medium';
            }
        }
        <?php if ($d['medium_id']): ?>updateLastRankHint();<?php endif; ?>

        // লেখার সময়ই Duplicate ধরা (Class Name, Rank, Class Code) — কোনোটা মিললে মেসেজ + Create/Update বন্ধ
        (function () {
            const rankMap = <?php echo json_encode($rank_map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
            const allClasses = <?php echo json_encode($dup_classes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
            const myId = <?php echo (int) $d['id']; ?>;
            const medium = document.getElementById('mediumSelect');
            const rank = document.getElementById('rankInput');
            const nameEl = document.getElementById('classNameInput');
            const codeEl = document.getElementById('classCodeInput');
            const rankMsg = document.getElementById('rankDupMsg');
            const nameMsg = document.getElementById('nameDupMsg');
            const codeMsg = document.getElementById('codeDupMsg');
            const btn = document.getElementById('classSaveBtn');

            function show(input, msgEl, text) {
                if (text) { msgEl.textContent = text; msgEl.style.display = ''; } else { msgEl.style.display = 'none'; }
                input.classList.toggle('is-invalid', !!text);
                return !!text;
            }
            function check() {
                let bad = false;

                // Rank — একই Medium-এর ভেতরে
                const m = medium.value, r = rank.value.trim();
                let rankText = '';
                if (m && r !== '' && rankMap[m] && rankMap[m][parseInt(r, 10)]) {
                    const other = rankMap[m][parseInt(r, 10)].filter(x => x.id !== myId);
                    if (other.length) rankText = 'এই Medium-এ Rank ' + parseInt(r, 10) + ' আগে থেকেই "' + other[0].n + '" Class-এর আছে (Duplicate)। অন্য Rank দিন।';
                }
                bad = show(rank, rankMsg, rankText) || bad;

                // Class Name — সব Medium মিলিয়ে, ছোট/বড় হাতের অক্ষর ও বাড়তি স্পেস উপেক্ষা করে
                const nm = nameEl.value.trim().toLowerCase();
                let nameText = '';
                if (nm) {
                    const hit = allClasses.find(c => c.id !== myId && c.n.trim().toLowerCase() === nm);
                    if (hit) nameText = 'এই নামে একটা Class আগে থেকেই আছে (Duplicate)। অন্য নাম দিন।';
                }
                bad = show(nameEl, nameMsg, nameText) || bad;

                // Class Code — সব Medium মিলিয়ে
                const cd = codeEl.value.trim();
                let codeText = '';
                if (cd) {
                    const hit = allClasses.find(c => c.id !== myId && c.c === cd);
                    if (hit) codeText = 'এই Code দিয়ে একটা Class আগে থেকেই আছে (Duplicate)। অন্য Code দিন।';
                }
                bad = show(codeEl, codeMsg, codeText) || bad;

                btn.disabled = bad;
            }
            [rank, nameEl, codeEl].forEach(e => e.addEventListener('input', check));
            medium.addEventListener('change', check);
            document.getElementById('classForm').addEventListener('submit', function (e) { if (btn.disabled) e.preventDefault(); });
            check();
        })();
    </script>

<?php else: ?>
    <?php
    // ------------------- LIST VIEW -------------------
    $f_medium = $_GET['f_medium'] ?? 'All';
    $f_name   = trim($_GET['f_name'] ?? '');
    $f_rank   = trim($_GET['f_rank'] ?? '');
    $f_code   = trim($_GET['f_code'] ?? '');
    $f_group  = $_GET['f_group'] ?? 'All';
    $f_status = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_medium !== 'All') { $where[] = 'academic_classes.medium_id = ?'; $params[] = (int) $f_medium; }
    if ($f_name !== '')  { $where[] = 'class_name LIKE ?'; $params[] = "%$f_name%"; }
    if ($f_rank !== '')  { $where[] = 'rank = ?'; $params[] = (int) $f_rank; }
    if ($f_code !== '')  { $where[] = 'class_code LIKE ?'; $params[] = "%$f_code%"; }
    if ($f_group !== 'All')  { $where[] = 'has_group = ?'; $params[] = $f_group === 'Yes' ? 1 : 0; }
    if ($f_status !== 'All') { $where[] = 'status = ?'; $params[] = $f_status; }

    $sql = "SELECT academic_classes.*, academic_mediums.medium_name FROM academic_classes
            LEFT JOIN academic_mediums ON academic_mediums.id = academic_classes.medium_id";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY academic_classes.rank ASC, academic_classes.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($cl_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($cl_msg); ?></div><?php endif; ?>
        <?php if ($cl_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($cl_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-start mb-3">
            <a href="index.php?page=academic_class&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
            <div class="text-end">
                <a href="index.php?page=academic_class_import" class="btn btn-outline-primary btn-sm">
                    <i class="fa-solid fa-file-import"></i> Import Class
                </a>
                <div class="text-muted small mt-1">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> items.</div>
            </div>
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
            <input type="hidden" name="page" value="academic_class">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>Medium Name</th>
                            <th>Class Name</th>
                            <th>Rank</th>
                            <th>Class Code</th>
                            <th>Has Group/4th Subject</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <tr>
                            <th></th>
                            <th>
                                <select name="f_medium" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All">All</option>
                                    <?php foreach ($mediums as $m): ?>
                                        <option value="<?php echo (int) $m['id']; ?>" <?php echo (string) $f_medium === (string) $m['id'] ? 'selected' : ''; ?>><?php echo clv($m['medium_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th><input type="text" name="f_name" class="form-control form-control-sm" value="<?php echo clv($f_name); ?>"></th>
                            <th><input type="text" name="f_rank" class="form-control form-control-sm" value="<?php echo clv($f_rank); ?>"></th>
                            <th><input type="text" name="f_code" class="form-control form-control-sm" value="<?php echo clv($f_code); ?>"></th>
                            <th>
                                <select name="f_group" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All" <?php echo $f_group === 'All' ? 'selected' : ''; ?>>All</option>
                                    <option value="Yes" <?php echo $f_group === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                    <option value="No" <?php echo $f_group === 'No' ? 'selected' : ''; ?>>No</option>
                                </select>
                            </th>
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
                                <td><?php echo clv($row['medium_name'] ?? '-'); ?></td>
                                <td><?php echo clv($row['class_name']); ?></td>
                                <td><?php echo (int) $row['rank']; ?></td>
                                <td><?php echo clv($row['class_code']); ?></td>
                                <td><?php echo $row['has_group'] ? 'Yes' : 'No'; ?></td>
                                <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=academic_class&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                    <a href="index.php?page=academic_class&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=academic_class', 'delete_class', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="8" class="text-center text-muted py-3">No data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
<?php endif; ?>
