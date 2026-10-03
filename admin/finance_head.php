<?php
// admin/finance_head.php - Finance Module > Accounts Head
// Chart of Accounts (A/C Head) তৈরি/এডিট/লিস্ট — A/C Type, Category (Receipts/Payments),
// Parent A/C (একই Type-এর Has Child = Yes এমন Head থেকে), Code, Head Name, Monthly/Yearly,
// Has Child?, Status — স্ক্রিনশট অনুযায়ী।

function fhv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$fh_err = $_SESSION['fh_err'] ?? '';
$fh_msg = $_SESSION['fh_msg'] ?? '';
unset($_SESSION['fh_err'], $_SESSION['fh_msg']);

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_account_heads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ac_type VARCHAR(30) NOT NULL,
        ac_category VARCHAR(20) NOT NULL DEFAULT 'Receipts',
        parent_id INT NOT NULL DEFAULT 0,
        ac_code VARCHAR(30) NOT NULL,
        ac_head VARCHAR(150) NOT NULL,
        period_type VARCHAR(10) NOT NULL DEFAULT 'Monthly',
        has_child VARCHAR(5) NOT NULL DEFAULT 'No',
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ac_code (ac_code),
        KEY idx_parent (parent_id), KEY idx_type (ac_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$AC_TYPES = ['Academic', 'General', 'Bank'];

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['fh_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=finance_head');
        exit();
    }

    if (isset($_POST['save_ac_head'])) {
        $id          = (int) ($_POST['id'] ?? 0);
        $ac_type     = trim($_POST['ac_type'] ?? '');
        $ac_category = trim($_POST['ac_category'] ?? 'Receipts');
        $parent_id   = (int) ($_POST['parent_id'] ?? 0);
        $ac_code     = trim($_POST['ac_code'] ?? '');
        $ac_head     = trim($_POST['ac_head'] ?? '');
        $period_type = trim($_POST['period_type'] ?? 'Monthly');
        $has_child   = trim($_POST['has_child'] ?? 'No');
        $status      = trim($_POST['status'] ?? 'Active');

        if (!in_array($ac_type, $AC_TYPES, true) || $ac_code === '' || $ac_head === '') {
            $_SESSION['fh_err'] = 'A/C Type, A/C Code এবং A/C Head আবশ্যক।';
        } elseif (!in_array($ac_category, ['Receipts', 'Payments'], true)) {
            $_SESSION['fh_err'] = 'সঠিক A/C Category সিলেক্ট করুন।';
        } elseif (!in_array($period_type, ['Monthly', 'Yearly'], true) || !in_array($has_child, ['Yes', 'No'], true) || !in_array($status, ['Active', 'Inactive'], true)) {
            $_SESSION['fh_err'] = 'ফর্মের ডেটা সঠিক নয়, আবার চেষ্টা করুন।';
        } else {
            $dup_stmt = $pdo->prepare("SELECT id FROM finance_account_heads WHERE ac_code = ? AND id != ? LIMIT 1");
            $dup_stmt->execute([$ac_code, $id]);
            if ($dup_stmt->fetch()) {
                $_SESSION['fh_err'] = 'এই A/C Code ইতিমধ্যে ব্যবহৃত হয়েছে, অন্য একটা কোড দিন।';
            } elseif ($parent_id > 0 && $parent_id === $id) {
                $_SESSION['fh_err'] = 'একটা A/C Head নিজেই নিজের Parent হতে পারে না।';
            } else {
                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        "UPDATE finance_account_heads
                         SET ac_type=?, ac_category=?, parent_id=?, ac_code=?, ac_head=?, period_type=?, has_child=?, status=?
                         WHERE id=?"
                    );
                    $stmt->execute([$ac_type, $ac_category, $parent_id, $ac_code, $ac_head, $period_type, $has_child, $status, $id]);
                    sec_log_activity($pdo, 'Finance', 'Update', "A/C Head আপডেট করা হয়েছে: {$ac_code} - {$ac_head}");
                    $_SESSION['fh_msg'] = 'A/C Head আপডেট করা হয়েছে।';
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO finance_account_heads (ac_type, ac_category, parent_id, ac_code, ac_head, period_type, has_child, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([$ac_type, $ac_category, $parent_id, $ac_code, $ac_head, $period_type, $has_child, $status]);
                    sec_log_activity($pdo, 'Finance', 'Create', "নতুন A/C Head তৈরি করা হয়েছে: {$ac_code} - {$ac_head}");
                    $_SESSION['fh_msg'] = 'নতুন A/C Head তৈরি করা হয়েছে।';
                }
                header('Location: index.php?page=finance_head');
                exit();
            }
        }
        header('Location: index.php?page=finance_head&view=' . ($id > 0 ? 'edit&id=' . $id : 'add'));
        exit();
    } elseif (isset($_POST['delete_ac_head'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $child_stmt = $pdo->prepare("SELECT COUNT(*) FROM finance_account_heads WHERE parent_id = ?");
        $child_stmt->execute([$id]);
        if ((int) $child_stmt->fetchColumn() > 0) {
            $_SESSION['fh_err'] = 'এই A/C Head-এর অধীনে চাইল্ড A/C আছে, আগে সেগুলো মুছুন বা পরিবর্তন করুন।';
        } else {
            $del_stmt = $pdo->prepare("SELECT ac_code, ac_head FROM finance_account_heads WHERE id = ?");
            $del_stmt->execute([$id]);
            $del_row = $del_stmt->fetch();
            $pdo->prepare("DELETE FROM finance_account_heads WHERE id = ?")->execute([$id]);
            if ($del_row) {
                sec_log_activity($pdo, 'Finance', 'Delete', "A/C Head মুছে ফেলা হয়েছে: {$del_row['ac_code']} - {$del_row['ac_head']}");
            }
            $_SESSION['fh_msg'] = 'A/C Head মুছে ফেলা হয়েছে।';
        }
        header('Location: index.php?page=finance_head');
        exit();
    }
}

$view = $_GET['view'] ?? 'list';

// A/C Parent অপশনের জন্য — সব Head (Type + Has Child সহ, JS দিয়ে ফিল্টার হবে)
$all_heads_for_parent = $pdo->query("SELECT id, ac_type, ac_code, ac_head, has_child FROM finance_account_heads WHERE status = 'Active' ORDER BY ac_head ASC")->fetchAll();

// লিস্ট ভিউয়ে দেখানোর জন্য parent নামের ম্যাপ
$parent_name_map = [];
foreach ($all_heads_for_parent as $h) { $parent_name_map[$h['id']] = $h['ac_head'] . ' (' . $h['ac_code'] . ')'; }

// নতুন A/C Head তৈরির সময় Code স্বয়ংক্রিয়ভাবে পরবর্তী নাম্বার বসিয়ে দেওয়া হয় (001, 002, 003 ...)
// — বিদ্যমান কোডগুলোর মধ্যে সবচেয়ে বড় সংখ্যাটার পরেরটা বসে, চাইলে ম্যানুয়ালি পরিবর্তনও করা যায়
$fh_max_code = $pdo->query("SELECT MAX(CAST(ac_code AS UNSIGNED)) FROM finance_account_heads WHERE ac_code REGEXP '^[0-9]+$'")->fetchColumn();
$fh_auto_code = str_pad((string) (((int) $fh_max_code) + 1), 3, '0', STR_PAD_LEFT);
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM finance_account_heads WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $h = $edit_row ?: [
        'id' => 0, 'ac_type' => '', 'ac_category' => 'Receipts', 'parent_id' => 0, 'ac_code' => $fh_auto_code,
        'ac_head' => '', 'period_type' => 'Monthly', 'has_child' => 'No', 'status' => 'Active',
    ];
    ?>
    <div class="card-custom">
        <?php if ($fh_err): ?><div class="alert alert-danger py-2"><?php echo fhv($fh_err); ?></div><?php endif; ?>

        <form method="POST" action="index.php?page=finance_head">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $h['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">A/C Type <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <div class="fh-searchable-select" id="fhAcTypeWrap">
                        <input type="text" class="form-control" id="fhAcTypeSearch" placeholder="Select A/C Type" autocomplete="off" value="<?php echo fhv($h['ac_type']); ?>">
                        <select name="ac_type" id="fhAcType" class="d-none">
                            <option value="">Select A/C Type</option>
                            <?php foreach ($AC_TYPES as $t): ?>
                                <option value="<?php echo fhv($t); ?>" <?php echo $h['ac_type'] === $t ? 'selected' : ''; ?>><?php echo fhv($t); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="fh-dropdown-list" id="fhAcTypeList">
                            <?php foreach ($AC_TYPES as $t): ?>
                                <div class="fh-dropdown-item" data-value="<?php echo fhv($t); ?>"><?php echo fhv($t); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">A/C Category</label>
                <div class="col-sm-6">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="ac_category" id="fhCatReceipts" value="Receipts" <?php echo $h['ac_category'] === 'Receipts' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="fhCatReceipts">Receipts (+)</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="ac_category" id="fhCatPayments" value="Payments" <?php echo $h['ac_category'] === 'Payments' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="fhCatPayments">Payments (-)</label>
                    </div>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">A/C Parents <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="parent_id" id="fhParentSelect" class="form-select" required>
                        <option value="">Select A/C Parent</option>
                        <option value="0" <?php echo (int) $h['parent_id'] === 0 && $view === 'edit' ? 'selected' : ''; ?>>-- Top Level (No Parent) --</option>
                        <?php foreach ($all_heads_for_parent as $ph): ?>
                            <?php if ((int) $ph['id'] === (int) $h['id']) { continue; } ?>
                            <option value="<?php echo (int) $ph['id']; ?>" data-actype="<?php echo fhv($ph['ac_type']); ?>" data-haschild="<?php echo fhv($ph['has_child']); ?>" <?php echo (int) $h['parent_id'] === (int) $ph['id'] ? 'selected' : ''; ?>>
                                <?php echo fhv($ph['ac_head']); ?> (<?php echo fhv($ph['ac_code']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">শুধু নির্বাচিত A/C Type-এর "Has Child = Yes" হেডগুলো এখানে দেখাবে (অথবা Top Level)।</small>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">A/C Code <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="text" name="ac_code" class="form-control" value="<?php echo fhv($h['ac_code']); ?>" required>
                    <?php if ($view !== 'edit'): ?>
                        <small class="text-muted">স্বয়ংক্রিয়ভাবে পরবর্তী কোড বসানো হয়েছে — চাইলে পরিবর্তন করতে পারেন।</small>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">A/C Head <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="text" name="ac_head" class="form-control" value="<?php echo fhv($h['ac_head']); ?>" required>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Yearly / Monthly <span class="text-danger">*</span></label>
                <div class="col-sm-4">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="period_type" id="fhMonthly" value="Monthly" <?php echo $h['period_type'] === 'Monthly' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="fhMonthly">Monthly</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="period_type" id="fhYearly" value="Yearly" <?php echo $h['period_type'] === 'Yearly' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="fhYearly">Yearly</label>
                    </div>
                    <div class="form-text">
                        <b>Monthly</b>: Jan-Dec, প্রতি মাসে আলাদাভাবে কালেক্ট হবে (যেমন: Tuition Fee, Exam Fee) — শুধু <i>Monthly Fee Setting</i>-এ দেখাবে।<br>
                        <b>Yearly</b>: একবারই কালেক্ট হবে (যেমন: Admission Fee, Session Fee, উন্নয়ন Fee) — শুধু <i>Admission Fee Setting</i>-এ দেখাবে।
                    </div>
                </div>
                <label class="col-sm-2 col-form-label fw-bold text-end">Has Child? <span class="text-danger">*</span></label>
                <div class="col-sm-3">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="has_child" id="fhChildYes" value="Yes" <?php echo $h['has_child'] === 'Yes' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="fhChildYes">Yes</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="has_child" id="fhChildNo" value="No" <?php echo $h['has_child'] === 'No' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="fhChildNo">No</label>
                    </div>
                </div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Status <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="status" class="form-select" required>
                        <option value="Active" <?php echo $h['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $h['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <hr>
            <div class="text-center">
                <button type="submit" name="save_ac_head" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                <a href="index.php?page=finance_head" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div>
        </form>
    </div>

    <style>
        .fh-searchable-select { position: relative; }
        .fh-dropdown-list {
            display: none; position: absolute; top: 100%; left: 0; right: 0; z-index: 1000;
            background: #fff; border: 1px solid #ced4da; border-radius: 4px; margin-top: 2px;
            max-height: 200px; overflow-y: auto; box-shadow: 0 4px 10px rgba(0,0,0,0.12);
        }
        .fh-dropdown-list.show { display: block; }
        .fh-dropdown-item { padding: 8px 14px; cursor: pointer; font-size: 14px; }
        .fh-dropdown-item:hover, .fh-dropdown-item.fh-active { background-color: #eef2ff; }
        .fh-dropdown-item.fh-hidden { display: none; }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const wrap = document.getElementById('fhAcTypeWrap');
            const searchInput = document.getElementById('fhAcTypeSearch');
            const hiddenSelect = document.getElementById('fhAcType');
            const list = document.getElementById('fhAcTypeList');
            const items = Array.from(list.querySelectorAll('.fh-dropdown-item'));

            function openList() {
                items.forEach(i => i.classList.remove('fh-hidden'));
                list.classList.add('show');
            }
            function closeList() { list.classList.remove('show'); }

            searchInput.addEventListener('focus', openList);
            searchInput.addEventListener('click', openList);
            searchInput.addEventListener('input', function () {
                const q = searchInput.value.trim().toLowerCase();
                items.forEach(i => {
                    i.classList.toggle('fh-hidden', q !== '' && !i.textContent.toLowerCase().includes(q));
                });
                list.classList.add('show');
                if (searchInput.value.trim() === '') { hiddenSelect.value = ''; }
            });

            items.forEach(item => {
                item.addEventListener('click', function () {
                    searchInput.value = item.textContent.trim();
                    hiddenSelect.value = item.dataset.value;
                    closeList();
                    hiddenSelect.dispatchEvent(new Event('change'));
                });
            });

            document.addEventListener('click', function (e) {
                if (!wrap.contains(e.target)) { closeList(); }
            });

            const parentSelect = document.getElementById('fhParentSelect');
            function filterFhParents() {
                const type = hiddenSelect.value;
                Array.from(parentSelect.options).forEach(opt => {
                    if (!opt.value) { opt.hidden = false; return; }
                    if (opt.value === '0') { opt.hidden = false; return; }
                    const typeOk = !type || opt.dataset.actype === type;
                    const childOk = opt.dataset.haschild === 'Yes';
                    opt.hidden = !(typeOk && childOk);
                });
            }
            hiddenSelect.addEventListener('change', filterFhParents);
            filterFhParents();
        });
    </script>

<?php else: ?>
    <?php
    $f_type = $_GET['f_type'] ?? 'All';
    $where = [];
    $params = [];
    if ($f_type !== 'All' && $f_type !== '') { $where[] = 'ac_type = ?'; $params[] = $f_type; }

    $sql = "SELECT * FROM finance_account_heads";
    if ($where) { $sql .= " WHERE " . implode(' AND ', $where); }
    $sql .= " ORDER BY ac_type ASC, ac_head ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($fh_msg): ?><div class="alert alert-success py-2"><?php echo fhv($fh_msg); ?></div><?php endif; ?>
        <?php if ($fh_err): ?><div class="alert alert-danger py-2"><?php echo fhv($fh_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=finance_head&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i> Add A/C Head</a>
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
            <input type="hidden" name="page" value="finance_head">
            <div class="row mb-3">
                <label class="col-auto col-form-label">A/C Type</label>
                <div class="col-auto">
                    <select name="f_type" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="All" <?php echo $f_type === 'All' ? 'selected' : ''; ?>>All Types</option>
                        <?php foreach ($AC_TYPES as $t): ?>
                            <option value="<?php echo fhv($t); ?>" <?php echo $f_type === $t ? 'selected' : ''; ?>><?php echo fhv($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>A/C Code</th>
                            <th>A/C Head</th>
                            <th>A/C Type</th>
                            <th>Category</th>
                            <th>Parent</th>
                            <th>Period</th>
                            <th>Has Child</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td class="fw-bold text-primary"><?php echo fhv($row['ac_code']); ?></td>
                                <td><?php echo fhv($row['ac_head']); ?></td>
                                <td><span class="badge bg-secondary"><?php echo fhv($row['ac_type']); ?></span></td>
                                <td>
                                    <?php if ($row['ac_category'] === 'Receipts'): ?>
                                        <span class="badge bg-success">Receipts (+)</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Payments (-)</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int) $row['parent_id'] === 0 ? '— Top Level —' : fhv($parent_name_map[$row['parent_id']] ?? '—'); ?></td>
                                <td><?php echo fhv($row['period_type']); ?></td>
                                <td><?php echo fhv($row['has_child']); ?></td>
                                <td>
                                    <?php if ($row['status'] === 'Active'): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=finance_head&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=finance_head', 'delete_ac_head', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
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
<?php endif; ?>
