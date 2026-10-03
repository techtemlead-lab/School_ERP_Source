<?php
// admin/promo_contacts.php - Promotional Module > My Contacts (Group এর মধ্যে মোবাইল নাম্বার যোগ)

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS promo_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_name VARCHAR(150) NOT NULL,
        image_path VARCHAR(255) NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS promo_contacts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        mobile VARCHAR(20) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_group (group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$pc_err = $_SESSION['pc_err'] ?? '';
$pc_msg = $_SESSION['pc_msg'] ?? '';
unset($_SESSION['pc_err'], $_SESSION['pc_msg']);

function pc_esc($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

function pc_normalize_mobile(string $num): ?string
{
    $num = preg_replace('/[^0-9]/', '', $num);
    if ($num === '') return null;
    if (strlen($num) === 11 && str_starts_with($num, '01')) { return $num; }
    if (strlen($num) === 13 && str_starts_with($num, '880')) { return substr($num, 2); }
    if (strlen($num) === 14 && str_starts_with($num, '+880')) { return substr($num, 3); }
    return $num; // অজানা ফরম্যাট হলেও যা আছে তাই রাখা হচ্ছে, ইউজার চাইলে পরে এডিট করবে
}

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['pc_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=promo_contacts');
        exit();
    }

    if (isset($_POST['save_contact'])) {
        $group_id = (int) ($_POST['group_id'] ?? 0);
        $mobile_raw = trim($_POST['mobile'] ?? '');
        $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if ($group_id <= 0) {
            $_SESSION['pc_err'] = 'Group Name সিলেক্ট করা আবশ্যক।';
        } elseif ($mobile_raw === '') {
            $_SESSION['pc_err'] = 'Mobile নাম্বার আবশ্যক।';
        } else {
            // কমা দিয়ে আলাদা করা একাধিক নাম্বার সাপোর্ট করে (একই সাথে নতুন লাইনও)
            $parts = preg_split('/[,\r\n]+/', $mobile_raw);
            $stmt = $pdo->prepare("INSERT INTO promo_contacts (group_id, mobile, status) VALUES (?, ?, ?)");
            $added = 0; $skipped = 0;
            foreach ($parts as $p) {
                $n = pc_normalize_mobile($p);
                if ($n === null) { continue; }
                if (strlen($n) !== 11) { $skipped++; continue; }
                $stmt->execute([$group_id, $n, $status]);
                $added++;
            }
            $_SESSION['pc_msg'] = "{$added} টি Contact যোগ করা হয়েছে।" . ($skipped > 0 ? " {$skipped} টি নাম্বার সঠিক ফরম্যাটে না থাকায় বাদ পড়েছে।" : '');
            if ($added > 0) {
                sec_log_activity($pdo, 'Promotional', 'Create', "{$added} টি Contact যোগ করা হয়েছে (Group ID: {$group_id})");
            }
        }
    } elseif (isset($_POST['delete_contact'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_contact_row = $pdo->prepare("SELECT mobile FROM promo_contacts WHERE id = ?");
        $del_contact_row->execute([$id]);
        $del_contact_mobile = $del_contact_row->fetchColumn();
        $pdo->prepare("DELETE FROM promo_contacts WHERE id = ?")->execute([$id]);
        $_SESSION['pc_msg'] = 'Contact মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Promotional', 'Delete', "Contact মুছে ফেলা হয়েছে: " . ($del_contact_mobile ?: "#$id"));
    }

    header('Location: index.php?page=promo_contacts');
    exit();
}

$groups = $pdo->query("SELECT id, group_name FROM promo_groups WHERE status = 'Active' ORDER BY group_name ASC")->fetchAll();
$view = $_GET['view'] ?? 'list';
?>

<?php if ($view === 'add'): ?>
    <div class="card-custom">
        <?php if ($pc_err): ?><div class="alert alert-danger py-2"><?php echo pc_esc($pc_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <a href="index.php?page=promo_contacts" class="d-inline-block bg-warning bg-opacity-75 p-2 text-decoration-none" style="width:40px;border-radius:4px;" title="তালিকায় ফিরে যান">
                <i class="fa-solid fa-gift text-white"></i>
            </a>
            <a href="index.php?page=promo_contacts" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i></a>
        </div>

        <?php if (!$groups): ?>
            <div class="alert alert-warning">প্রথমে <a href="index.php?page=promo_groups&view=add">একটা Group তৈরি করুন</a>, তারপর এখানে Contact যোগ করা যাবে।</div>
        <?php else: ?>
        <form method="POST" action="index.php?page=promo_contacts">
            <?php echo csrf_field(); ?>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Group Name <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="group_id" class="form-select" required>
                        <option value="">Select Group</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?php echo (int) $g['id']; ?>" <?php echo (string) ($_GET['f_group'] ?? '') === (string) $g['id'] ? 'selected' : ''; ?>><?php echo pc_esc($g['group_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-1 align-items-start">
                <label class="col-sm-3 col-form-label fw-bold text-end">Mobile <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <textarea name="mobile" class="form-control" rows="5" required placeholder="01717xxxxxx, 01625xxxxxx"></textarea>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-sm-6 offset-sm-3"><small class="text-muted fst-italic">Please separate number by ( , ) Ex: 01717xxxxxx, 01625xxxxxx</small></div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Status <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="status" class="form-select">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
            </div>

            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_contact" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;">Create</button>
                    <a href="index.php?page=promo_contacts" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>

<?php else: ?>
    <?php
    $f_group  = (int) ($_GET['f_group'] ?? 0);
    $f_mobile = trim($_GET['f_mobile'] ?? '');
    $f_status = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_group > 0)      { $where[] = 'c.group_id = ?'; $params[] = $f_group; }
    if ($f_mobile !== '')  { $where[] = 'c.mobile LIKE ?'; $params[] = "%$f_mobile%"; }
    if ($f_status !== 'All') { $where[] = 'c.status = ?'; $params[] = $f_status; }

    $sql = "SELECT c.*, g.group_name FROM promo_contacts c LEFT JOIN promo_groups g ON g.id = c.group_id";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY c.id DESC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($pc_msg): ?><div class="alert alert-success py-2"><?php echo pc_esc($pc_msg); ?></div><?php endif; ?>
        <?php if ($pc_err): ?><div class="alert alert-danger py-2"><?php echo pc_esc($pc_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=promo_contacts&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i> Add Contact</a>
            <span class="text-muted small">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> item.</span>
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
            <input type="hidden" name="page" value="promo_contacts">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>Group</th>
                            <th>Mobile</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <tr>
                            <th></th>
                            <th>
                                <select name="f_group" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="0">All</option>
                                    <?php foreach ($groups as $g): ?>
                                        <option value="<?php echo (int) $g['id']; ?>" <?php echo $f_group === (int) $g['id'] ? 'selected' : ''; ?>><?php echo pc_esc($g['group_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th><input type="tel" name="f_mobile" class="form-control form-control-sm phone-11" inputmode="numeric" maxlength="11" value="<?php echo pc_esc($f_mobile); ?>"></th>
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
                                <td><?php echo pc_esc($row['group_name'] ?? '-'); ?></td>
                                <td><?php echo pc_esc($row['mobile']); ?></td>
                                <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=promo_contacts', 'delete_contact', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">কোনো Contact পাওয়া যায়নি</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
<?php endif; ?>
<script>
// ফোন নম্বর ফিল্ড (.phone-11 ক্লাস): টাইপ করার সাথে সাথেই সংখ্যা ছাড়া অন্য কিছু নেয় না,
// ১১ ডিজিটের বেশি হলে কেটে দেয়, ১১ ডিজিট না হওয়া পর্যন্ত নিচে লাল মেসেজ দেখায় (রিলোড ছাড়া),
// ১১ ডিজিট হয়ে গেলেই মেসেজ সাথে সাথে সরে যায়
document.querySelectorAll('.phone-11').forEach(function (input) {
    let errEl = input.nextElementSibling;
    if (!errEl || !errEl.classList.contains('phone11-err')) {
        errEl = document.createElement('div');
        errEl.className = 'small text-danger mt-1 d-none phone11-err';
        errEl.textContent = 'মোবাইল নম্বর অবশ্যই ১১ ডিজিটের হতে হবে।';
        input.insertAdjacentElement('afterend', errEl);
    }
    function checkPhone11() {
        let digits = input.value.replace(/\D/g, '');
        if (digits.length > 11) digits = digits.slice(0, 11);
        input.value = digits;
        const invalid = digits.length > 0 && digits.length !== 11;
        errEl.classList.toggle('d-none', !invalid);
        input.classList.toggle('is-invalid', invalid);
    }
    input.addEventListener('input', checkPhone11);
    input.addEventListener('blur', checkPhone11);
});
</script>
