<?php
// admin/canteen_raw_materials.php - Canteen > Raw Materials
require_once __DIR__ . '/canteen_common.php';
canteen_require_manage();

$msg = $_SESSION['canteen_msg'] ?? '';
$err = $_SESSION['canteen_err'] ?? '';
unset($_SESSION['canteen_msg'], $_SESSION['canteen_err']);

$edit_id  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$add_mode = isset($_GET['mode']) && $_GET['mode'] === 'add';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['canteen_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=canteen_raw_materials');
        exit();
    }

    if (isset($_POST['save_material'])) {
        $id            = (int) ($_POST['id'] ?? 0);
        $name          = trim($_POST['name'] ?? '');
        $unit_id       = (int) ($_POST['unit_id'] ?? 0) ?: null;
        $reorder_level = (float) ($_POST['reorder_level'] ?? 0);
        $unit_cost     = (float) ($_POST['unit_cost'] ?? 0);
        $status        = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if ($name === '') {
            $_SESSION['canteen_err'] = 'নাম আবশ্যক।';
        } else {
            if ($id > 0) {
                $pdo->prepare("UPDATE canteen_raw_materials SET name=?, unit_id=?, reorder_level=?, unit_cost=?, status=? WHERE id=?")
                    ->execute([$name, $unit_id, $reorder_level, $unit_cost, $status, $id]);
                sec_log_activity($pdo, 'Canteen', 'Update', "কাঁচামালের তথ্য আপডেট হয়েছে: {$name}");
                $_SESSION['canteen_msg'] = 'কাঁচামালের তথ্য আপডেট হয়েছে।';
            } else {
                $opening_stock = max(0, (float) ($_POST['opening_stock'] ?? 0));
                $pdo->prepare("INSERT INTO canteen_raw_materials (name, unit_id, current_stock, reorder_level, unit_cost, status, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$name, $unit_id, $opening_stock, $reorder_level, $unit_cost, $status, branch_id_for_insert()]);
                sec_log_activity($pdo, 'Canteen', 'Create', "নতুন কাঁচামাল যোগ করা হয়েছে: {$name}");
                $_SESSION['canteen_msg'] = 'নতুন কাঁচামাল যোগ করা হয়েছে।';
            }
        }
    } elseif (isset($_POST['delete_material'])) {
        $id = (int) ($_POST['id'] ?? 0);
        // Recipe-তে ব্যবহৃত থাকলে ডিলিট আটকানো হচ্ছে, নাহলে পুরনো Menu Item-এর recipe ভেঙে যাবে
        $inUse = $pdo->prepare("SELECT COUNT(*) FROM canteen_recipe_items WHERE raw_material_id = ?");
        $inUse->execute([$id]);
        if ((int) $inUse->fetchColumn() > 0) {
            $_SESSION['canteen_err'] = 'এই কাঁচামাল কোনো Menu Item-এর Recipe-তে ব্যবহৃত হচ্ছে, তাই ডিলিট করা যাবে না — আগে Recipe থেকে সরান।';
        } else {
            $del_mat_stmt = $pdo->prepare("SELECT name FROM canteen_raw_materials WHERE id = ?");
            $del_mat_stmt->execute([$id]);
            $del_mat_name = $del_mat_stmt->fetchColumn();
            $pdo->prepare("DELETE FROM canteen_raw_materials WHERE id = ?")->execute([$id]);
            sec_log_activity($pdo, 'Canteen', 'Delete', "কাঁচামাল মুছে ফেলা হয়েছে: " . ($del_mat_name ?: "#$id"));
            $_SESSION['canteen_msg'] = 'কাঁচামাল মুছে ফেলা হয়েছে।';
        }
    } elseif (isset($_POST['save_unit'])) {
        $uname = trim($_POST['unit_name'] ?? '');
        if ($uname !== '') {
            $pdo->prepare("INSERT INTO canteen_units (name) VALUES (?)")->execute([$uname]);
            sec_log_activity($pdo, 'Canteen', 'Create', "নতুন Canteen Unit যোগ করা হয়েছে: {$uname}");
            $_SESSION['canteen_msg'] = 'নতুন Unit যোগ করা হয়েছে।';
        }
    }

    header('Location: index.php?page=canteen_raw_materials');
    exit();
}

$units     = $pdo->query("SELECT * FROM canteen_units ORDER BY name ASC")->fetchAll();
$crm_where = []; $crm_params = [];
branch_apply_filter($crm_where, $crm_params, 'm.branch_id');
$crm_where_sql = $crm_where ? (' WHERE ' . implode(' AND ', $crm_where)) : '';
$crm_stmt = $pdo->prepare(
    "SELECT m.*, u.name AS unit_name FROM canteen_raw_materials m LEFT JOIN canteen_units u ON u.id = m.unit_id$crm_where_sql ORDER BY m.name ASC"
);
$crm_stmt->execute($crm_params);
$materials = $crm_stmt->fetchAll();

$edit_material = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM canteen_raw_materials WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_material = $stmt->fetch();
}
$form = $edit_material ?: ['id' => 0, 'name' => '', 'unit_id' => '', 'current_stock' => 0, 'reorder_level' => 0, 'unit_cost' => 0, 'status' => 'Active'];
$show_form = $edit_material || $add_mode;
?>

<div class="card-custom">

<?php if ($show_form): ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold text-dark mb-0"><i class="fa-solid fa-carrot"></i> <?php echo $edit_material ? 'Edit Raw Material' : 'Add Raw Material'; ?></h4>
        <a href="index.php?page=canteen_raw_materials" class="btn btn-outline-secondary fw-bold"><i class="fa-solid fa-arrow-left"></i> লিস্টে ফিরে যাও</a>
    </div>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?php echo cv($err); ?></div><?php endif; ?>

    <div class="row">
        <div class="col-lg-6">
            <form method="POST" action="index.php?page=canteen_raw_materials">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">

                <div class="mb-3"><label class="form-label fw-bold">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?php echo cv($form['name']); ?>" required></div>

                <div class="mb-3"><label class="form-label fw-bold">Unit</label>
                    <select name="unit_id" class="form-select">
                        <option value="">-- বেছে নিন --</option>
                        <?php foreach ($units as $u): ?>
                            <option value="<?php echo (int) $u['id']; ?>" <?php echo (int) $form['unit_id'] === (int) $u['id'] ? 'selected' : ''; ?>><?php echo cv($u['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (!$edit_material): ?>
                    <div class="mb-3"><label class="form-label fw-bold">Opening Stock</label>
                        <input type="number" step="0.001" min="0" name="opening_stock" class="form-control" value="0"></div>
                <?php else: ?>
                    <div class="mb-3"><label class="form-label fw-bold">বর্তমান Stock</label>
                        <input type="text" class="form-control" value="<?php echo cv($form['current_stock']); ?>" disabled>
                        <small class="text-muted">Stock বদলাতে Purchase বা Stock Adjustment পেজ ব্যবহার করো।</small></div>
                <?php endif; ?>

                <div class="mb-3"><label class="form-label fw-bold">Reorder Level (এর নিচে নামলে Low Stock দেখাবে)</label>
                    <input type="number" step="0.001" min="0" name="reorder_level" class="form-control" value="<?php echo cv($form['reorder_level']); ?>"></div>

                <div class="mb-3"><label class="form-label fw-bold">Unit Cost (আনুমানিক)</label>
                    <input type="number" step="0.01" min="0" name="unit_cost" class="form-control" value="<?php echo cv($form['unit_cost']); ?>"></div>

                <div class="mb-4"><label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="Active" <?php echo $form['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $form['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select></div>

                <button type="submit" name="save_material" class="btn btn-primary fw-bold px-4" style="background-color:#1e3a8a;"><?php echo $edit_material ? 'Update' : 'Add'; ?></button>
                <a href="index.php?page=canteen_raw_materials" class="btn btn-secondary px-4 ms-2">বাতিল</a>
            </form>
        </div>
        <div class="col-lg-5 offset-lg-1">
            <div class="card p-3 bg-light">
                <h6 class="fw-bold mb-2">নতুন Unit যোগ করুন</h6>
                <form method="POST" action="index.php?page=canteen_raw_materials" class="d-flex gap-2">
                    <?php echo csrf_field(); ?>
                    <input type="text" name="unit_name" class="form-control" placeholder="যেমন: dozen, packet">
                    <button type="submit" name="save_unit" class="btn btn-outline-primary">Add</button>
                </form>
            </div>
        </div>
    </div>

<?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold text-dark mb-0"><i class="fa-solid fa-carrot"></i> Canteen &raquo; Raw Materials</h4>
        <a href="index.php?page=canteen_raw_materials&mode=add" class="btn btn-primary fw-bold" style="background-color:#1e3a8a;"><i class="fa-solid fa-plus"></i> Add Raw Material</a>
    </div>
    <?php if ($msg): ?><div class="alert alert-success py-2"><?php echo cv($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?php echo cv($err); ?></div><?php endif; ?>

    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-dark"><tr><th>Name</th><th>Unit</th><th>Stock</th><th>Reorder Level</th><th>Unit Cost</th><th>Status</th><th class="text-end">Action</th></tr></thead>
            <tbody>
                <?php if ($materials): foreach ($materials as $m): $csrf = cv(csrf_token());
                    $low = (float) $m['current_stock'] <= (float) $m['reorder_level'];
                ?>
                    <tr class="<?php echo $low ? 'table-warning' : ''; ?>">
                        <td><strong><?php echo cv($m['name']); ?></strong><?php if ($low): ?> <span class="badge bg-danger">Low Stock</span><?php endif; ?></td>
                        <td><?php echo cv($m['unit_name'] ?: '-'); ?></td>
                        <td><?php echo number_format((float) $m['current_stock'], 3); ?></td>
                        <td><?php echo number_format((float) $m['reorder_level'], 3); ?></td>
                        <td>৳<?php echo number_format((float) $m['unit_cost'], 2); ?></td>
                        <td><?php echo $m['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                        <td class="text-end text-nowrap">
                            <a href="index.php?page=canteen_raw_materials&edit=<?php echo (int) $m['id']; ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                            <form method="POST" action="index.php?page=canteen_raw_materials" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="id" value="<?php echo (int) $m['id']; ?>">
                                <button type="submit" name="delete_material" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">কোনো কাঁচামাল নেই</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

</div>
