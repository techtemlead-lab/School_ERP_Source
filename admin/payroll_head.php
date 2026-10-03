<?php
// admin/payroll_head.php - Human Resource > Payroll Module > Payroll Head (স্ক্রিনশট অনুযায়ী)
$ph_err = $_SESSION['ph_err'] ?? '';
$ph_msg = $_SESSION['ph_msg'] ?? '';
unset($_SESSION['ph_err'], $_SESSION['ph_msg']);

// payroll_heads টেবিল না থাকলে অটো তৈরি (self-referencing parent_id দিয়ে A/C Parents হায়ারার্কি)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS payroll_heads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category ENUM('Addition','Deduction') NOT NULL DEFAULT 'Addition',
        absent_deduction ENUM('Yes','No') NOT NULL DEFAULT 'No',
        parent_id INT NULL DEFAULT NULL,
        ac_code VARCHAR(50) NOT NULL,
        ac_head VARCHAR(150) NOT NULL,
        period_type ENUM('Monthly','Yearly') NOT NULL DEFAULT 'Monthly',
        has_child ENUM('Yes','No') NOT NULL DEFAULT 'No',
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_parent_id (parent_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ph_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=payroll_head');
        exit();
    }

    if (isset($_POST['save_payroll_head'])) {
        $id               = (int) ($_POST['id'] ?? 0);
        $category         = ($_POST['category'] ?? 'Addition') === 'Deduction' ? 'Deduction' : 'Addition';
        $absent_deduction = ($_POST['absent_deduction'] ?? 'No') === 'Yes' ? 'Yes' : 'No';
        $parent_id        = (int) ($_POST['parent_id'] ?? 0);
        $ac_code          = trim($_POST['ac_code'] ?? '');
        $ac_head          = trim($_POST['ac_head'] ?? '');
        $period_type      = ($_POST['period_type'] ?? 'Monthly') === 'Yearly' ? 'Yearly' : 'Monthly';
        $has_child        = ($_POST['has_child'] ?? 'No') === 'Yes' ? 'Yes' : 'No';
        $status           = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        // নিজেকে নিজের Parent হিসেবে সিলেক্ট করা ঠেকানো
        if ($parent_id === $id && $id > 0) {
            $parent_id = 0;
        }

        if ($ac_code === '' || $ac_head === '') {
            $_SESSION['ph_err'] = 'A/C Code এবং A/C Head খালি রাখা যাবে না।';
        } else {
            $parent_val = $parent_id > 0 ? $parent_id : null;
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE payroll_heads SET category=?, absent_deduction=?, parent_id=?, ac_code=?, ac_head=?, period_type=?, has_child=?, status=? WHERE id=?");
                $stmt->execute([$category, $absent_deduction, $parent_val, $ac_code, $ac_head, $period_type, $has_child, $status, $id]);
                $_SESSION['ph_msg'] = 'Payroll Head আপডেট করা হয়েছে।';
                sec_log_activity($pdo, 'Payroll', 'Update', "Payroll Head আপডেট করা হয়েছে: {$ac_code} - {$ac_head} ({$category})");
            } else {
                $stmt = $pdo->prepare("INSERT INTO payroll_heads (category, absent_deduction, parent_id, ac_code, ac_head, period_type, has_child, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$category, $absent_deduction, $parent_val, $ac_code, $ac_head, $period_type, $has_child, $status]);
                $_SESSION['ph_msg'] = 'নতুন Payroll Head যোগ করা হয়েছে।';
                sec_log_activity($pdo, 'Payroll', 'Create', "নতুন Payroll Head যোগ করা হয়েছে: {$ac_code} - {$ac_head} ({$category})");
            }
        }
    } elseif (isset($_POST['delete_payroll_head'])) {
        $id = (int) ($_POST['id'] ?? 0);
        // চাইল্ড থাকলে ডিলিট আটকানো
        $chk = $pdo->prepare("SELECT COUNT(*) FROM payroll_heads WHERE parent_id = ?");
        $chk->execute([$id]);
        if ($chk->fetchColumn() > 0) {
            $_SESSION['ph_err'] = 'এই Head-এর অধীনে Child Head আছে, আগে সেগুলো মুছুন বা Parent পরিবর্তন করুন।';
        } else {
            $del_row = $pdo->prepare("SELECT ac_code, ac_head FROM payroll_heads WHERE id = ?");
            $del_row->execute([$id]);
            $del_head = $del_row->fetch();
            $pdo->prepare("DELETE FROM payroll_heads WHERE id = ?")->execute([$id]);
            $_SESSION['ph_msg'] = 'Payroll Head মুছে ফেলা হয়েছে।';
            sec_log_activity($pdo, 'Payroll', 'Delete', "Payroll Head মুছে ফেলা হয়েছে: " . ($del_head ? "{$del_head['ac_code']} - {$del_head['ac_head']}" : "#$id"));
        }
    }

    header('Location: index.php?page=payroll_head');
    exit();
}

$view = $_GET['view'] ?? 'list';
function phv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    // ------------------- ADD / EDIT FORM VIEW -------------------
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM payroll_heads WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $p = $edit_row ?: [
        'id' => 0, 'category' => 'Addition', 'absent_deduction' => 'No', 'parent_id' => null,
        'ac_code' => '', 'ac_head' => '', 'period_type' => 'Monthly', 'has_child' => 'No', 'status' => 'Active',
    ];

    // A/C Parents ড্রপডাউনের জন্য লিস্ট (এডিটের সময় নিজেকে বাদ দিয়ে)
    if ((int) $p['id'] > 0) {
        $pstmt = $pdo->prepare("SELECT id, ac_code, ac_head FROM payroll_heads WHERE id != ? ORDER BY ac_head ASC");
        $pstmt->execute([$p['id']]);
    } else {
        $pstmt = $pdo->query("SELECT id, ac_code, ac_head FROM payroll_heads ORDER BY ac_head ASC");
    }
    $parent_options = $pstmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($ph_err): ?><div class="alert alert-danger py-2"><?php echo phv($ph_err); ?></div><?php endif; ?>

        <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;">
            <i class="fa-solid fa-bars text-white"></i>
        </div>

        <form method="POST" action="index.php?page=payroll_head">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label text-end">A/C Category</label>
                <div class="col-sm-8 d-flex gap-4 align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="category" id="cat_addition" value="Addition" <?php echo $p['category'] === 'Addition' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="cat_addition">Addition</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="category" id="cat_deduction" value="Deduction" <?php echo $p['category'] === 'Deduction' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="cat_deduction">Deduction</label>
                    </div>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label text-end">Absent Deduction?</label>
                <div class="col-sm-8 d-flex gap-4 align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="absent_deduction" id="ad_yes" value="Yes" <?php echo $p['absent_deduction'] === 'Yes' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="ad_yes">Yes</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="absent_deduction" id="ad_no" value="No" <?php echo $p['absent_deduction'] === 'No' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="ad_no">No</label>
                    </div>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label text-end">A/C Code <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="text" name="ac_code" class="form-control <?php echo ($ph_err && trim($p['ac_code']) === '') ? 'is-invalid' : ''; ?>" value="<?php echo phv($p['ac_code']); ?>" placeholder="e.g. 1001" required>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label text-end">A/C Head <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="text" name="ac_head" class="form-control <?php echo ($ph_err && trim($p['ac_head']) === '') ? 'is-invalid' : ''; ?>" value="<?php echo phv($p['ac_head']); ?>" placeholder="e.g. Basic Salary" required>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label text-end">Yearly / Monthly <span class="text-danger">*</span></label>
                <div class="col-sm-8 d-flex gap-4 align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="period_type" id="pt_monthly" value="Monthly" <?php echo $p['period_type'] === 'Monthly' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="pt_monthly">Monthly</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="period_type" id="pt_yearly" value="Yearly" <?php echo $p['period_type'] === 'Yearly' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="pt_yearly">Yearly</label>
                    </div>
                </div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label text-end">Has Child? <span class="text-danger">*</span></label>
                <div class="col-sm-8 d-flex gap-4 align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="has_child" id="hc_yes" value="Yes" <?php echo $p['has_child'] === 'Yes' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="hc_yes">Yes</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="has_child" id="hc_no" value="No" <?php echo $p['has_child'] === 'No' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="hc_no">No</label>
                    </div>
                </div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label text-end">Status <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="status" class="form-select">
                        <option value="Active" <?php echo $p['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $p['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_payroll_head" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=payroll_head" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

<?php else: ?>
    <?php
    // ------------------- LIST VIEW -------------------
    $f_category = $_GET['f_category'] ?? 'All';
    $f_head     = trim($_GET['f_head'] ?? '');
    $f_status   = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_category !== 'All') { $where[] = 'ph.category = ?'; $params[] = $f_category; }
    if ($f_head !== '')        { $where[] = '(ph.ac_head LIKE ? OR ph.ac_code LIKE ?)'; $params[] = "%$f_head%"; $params[] = "%$f_head%"; }
    if ($f_status !== 'All')   { $where[] = 'ph.status = ?'; $params[] = $f_status; }

    $sql = "SELECT ph.*, parent.ac_head AS parent_head FROM payroll_heads ph LEFT JOIN payroll_heads parent ON parent.id = ph.parent_id";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY ph.ac_code ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($ph_msg): ?><div class="alert alert-success py-2"><?php echo phv($ph_msg); ?></div><?php endif; ?>
        <?php if ($ph_err): ?><div class="alert alert-danger py-2"><?php echo phv($ph_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=payroll_head&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
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
            <input type="hidden" name="page" value="payroll_head">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>Category</th>
                            <th>Absent Ded.</th>
                            <th>Parent</th>
                            <th>A/C Code</th>
                            <th>A/C Head</th>
                            <th>Period</th>
                            <th>Has Child</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <tr>
                            <th></th>
                            <th>
                                <select name="f_category" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All" <?php echo $f_category === 'All' ? 'selected' : ''; ?>>All</option>
                                    <option value="Addition" <?php echo $f_category === 'Addition' ? 'selected' : ''; ?>>Addition</option>
                                    <option value="Deduction" <?php echo $f_category === 'Deduction' ? 'selected' : ''; ?>>Deduction</option>
                                </select>
                            </th>
                            <th></th>
                            <th></th>
                            <th><input type="text" name="f_head" class="form-control form-control-sm" placeholder="Code / Head" value="<?php echo phv($f_head); ?>"></th>
                            <th></th>
                            <th></th>
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
                                <td><?php echo $row['category'] === 'Addition' ? '<span class="badge bg-success">Addition</span>' : '<span class="badge bg-danger">Deduction</span>'; ?></td>
                                <td><?php echo phv($row['absent_deduction']); ?></td>
                                <td><?php echo $row['parent_head'] ? phv($row['parent_head']) : '<span class="text-muted">— Top Level —</span>'; ?></td>
                                <td><?php echo phv($row['ac_code']); ?></td>
                                <td><?php echo phv($row['ac_head']); ?></td>
                                <td><?php echo phv($row['period_type']); ?></td>
                                <td><?php echo phv($row['has_child']); ?></td>
                                <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=payroll_head&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                    <a href="index.php?page=payroll_head&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=payroll_head', 'delete_payroll_head', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
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
