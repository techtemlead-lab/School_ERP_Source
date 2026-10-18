<?php
// admin/canteen_menu.php - Canteen > Items (আগে "Menu Items" ছিল, লেবেল বদলে "Item" করা হয়েছে,
// inventory_items.php-এর মতোই Add Item/Category/Sub Category/Unit ট্যাব + Item List)
require_once __DIR__ . '/canteen_common.php';
canteen_require_manage();

$msg = $_SESSION['canteen_msg'] ?? '';
$err = $_SESSION['canteen_err'] ?? '';
unset($_SESSION['canteen_msg'], $_SESSION['canteen_err']);

$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

function canteen_menu_upload(): array
{
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null];
    }
    $file = $_FILES['photo'];
    if ($file['error'] !== UPLOAD_ERR_OK) { return ['ok' => false, 'path' => null, 'error' => 'ফাইল আপলোডে সমস্যা হয়েছে।']; }
    if ($file['size'] > 2 * 1024 * 1024) { return ['ok' => false, 'path' => null, 'error' => 'ফাইলের সাইজ 2MB এর বেশি হওয়া যাবে না।']; }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    if (!isset($allowed_mimes[$ext])) { return ['ok' => false, 'path' => null, 'error' => 'শুধু jpg/png/webp ফাইল আপলোড করা যাবে।']; }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== $allowed_mimes[$ext]) { return ['ok' => false, 'path' => null, 'error' => 'ফাইলের প্রকৃত কনটেন্ট এক্সটেনশনের সাথে মিলছে না।']; }

    $dir = tenant_upload_abs(__DIR__ . '/..', 'canteen_menu');
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    $rand = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $rand)) { return ['ok' => false, 'path' => null, 'error' => 'ফাইল সেভ করা যায়নি।']; }
    return ['ok' => true, 'path' => tenant_upload_rel('canteen_menu') . '/' . $rand];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['canteen_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=canteen_menu');
        exit();
    }

    if (isset($_POST['add_category'])) {
        $name = trim($_POST['category_name'] ?? '');
        if ($name !== '') {
            $pdo->prepare("INSERT INTO canteen_categories (name, branch_id) VALUES (?, ?)")->execute([$name, branch_id_for_insert()]);
            sec_log_activity($pdo, 'Canteen', 'Create', "নতুন Canteen Category যোগ করা হয়েছে: {$name}");
            $_SESSION['canteen_msg'] = 'Category যোগ করা হয়েছে।';
        }
        header('Location: index.php?page=canteen_menu');
        exit();

    } elseif (isset($_POST['add_subcategory'])) {
        $category_id = (int) ($_POST['sub_category_id'] ?? 0);
        $name = trim($_POST['subcategory_name'] ?? '');
        if ($category_id > 0 && $name !== '' && canteen_master_id_in_scope($pdo, 'canteen_categories', $category_id)) {
            $pdo->prepare("INSERT INTO canteen_subcategories (category_id, name, branch_id) VALUES (?, ?, ?)")->execute([$category_id, $name, branch_id_for_insert()]);
            sec_log_activity($pdo, 'Canteen', 'Create', "নতুন Canteen Sub Category যোগ করা হয়েছে: {$name}");
            $_SESSION['canteen_msg'] = 'Sub Category যোগ করা হয়েছে।';
        } else {
            $_SESSION['canteen_err'] = 'Category বেছে নিন এবং Sub Category-র নাম দিন!';
        }
        header('Location: index.php?page=canteen_menu');
        exit();

    } elseif (isset($_POST['add_unit'])) {
        $name = trim($_POST['unit_name'] ?? '');
        if ($name !== '') {
            $pdo->prepare("INSERT INTO canteen_units (name, branch_id) VALUES (?, ?)")->execute([$name, branch_id_for_insert()]);
            sec_log_activity($pdo, 'Canteen', 'Create', "নতুন Canteen Unit যোগ করা হয়েছে: {$name}");
            $_SESSION['canteen_msg'] = 'Unit যোগ করা হয়েছে।';
        }
        header('Location: index.php?page=canteen_menu');
        exit();

    } elseif (isset($_POST['save_item'])) {
        $id             = (int) ($_POST['id'] ?? 0);
        $category_id    = (int) ($_POST['category_id'] ?? 0) ?: null;
        $subcategory_id = (int) ($_POST['subcategory_id'] ?? 0) ?: null;
        $unit_id        = (int) ($_POST['unit_id'] ?? 0) ?: null;
        $item_code      = trim($_POST['item_code'] ?? '');
        $name           = trim($_POST['name'] ?? '');
        $price          = (float) ($_POST['price'] ?? 0);
        $description    = trim($_POST['description'] ?? '');
        $status         = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        $category_id    = canteen_master_id_in_scope($pdo, 'canteen_categories', $category_id);
        $subcategory_id = canteen_master_id_in_scope($pdo, 'canteen_subcategories', $subcategory_id);
        $unit_id        = canteen_master_id_in_scope($pdo, 'canteen_units', $unit_id);

        if ($id > 0 && !canteen_scoped_row($pdo, 'canteen_menu_items', $id, 'id')) {
            $_SESSION['canteen_err'] = 'Item পাওয়া যায়নি (অথবা অন্য branch-এর)।';
            header('Location: index.php?page=canteen_menu');
            exit();
        }
        if ($name === '' || $price <= 0) {
            $_SESSION['canteen_err'] = 'Name এবং সঠিক Price আবশ্যক।';
            header('Location: index.php?page=canteen_menu' . ($id ? "&edit=$id" : ''));
            exit();
        }

        $photo = canteen_menu_upload();
        if (!$photo['ok']) {
            $_SESSION['canteen_err'] = $photo['error'];
            header('Location: index.php?page=canteen_menu' . ($id ? "&edit=$id" : ''));
            exit();
        }

        if ($id > 0) {
            $pdo->prepare(
                "UPDATE canteen_menu_items SET category_id=?, subcategory_id=?, unit_id=?, item_code=?, name=?, price=?, description=?, status=?, photo_path=COALESCE(?, photo_path) WHERE id=?"
            )->execute([$category_id, $subcategory_id, $unit_id, $item_code ?: null, $name, $price, $description ?: null, $status, $photo['path'], $id]);
            sec_log_activity($pdo, 'Canteen', 'Update', "Canteen Item আপডেট হয়েছে: {$name}");
            $_SESSION['canteen_msg'] = 'Item আপডেট হয়েছে।';
            header("Location: index.php?page=canteen_menu&edit=$id");
        } else {
            $pdo->prepare(
                "INSERT INTO canteen_menu_items (category_id, subcategory_id, unit_id, item_code, name, price, description, status, photo_path, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([$category_id, $subcategory_id, $unit_id, $item_code ?: null, $name, $price, $description ?: null, $status, $photo['path'], branch_id_for_insert()]);
            sec_log_activity($pdo, 'Canteen', 'Create', "নতুন Canteen Item যোগ হয়েছে: {$name}");
            $_SESSION['canteen_msg'] = 'নতুন Item যোগ হয়েছে।';
            header('Location: index.php?page=canteen_menu');
        }
        exit();

    } elseif (isset($_POST['delete_item'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $it = canteen_scoped_row($pdo, 'canteen_menu_items', $id, 'id, name');
        if (!$it) {
            $_SESSION['canteen_err'] = 'Item পাওয়া যায়নি (অথবা অন্য branch-এর)।';
        } else {
            $c = $pdo->prepare("SELECT COUNT(*) FROM canteen_sale_items WHERE menu_item_id = ?");
            $c->execute([$id]);
            if ((int) $c->fetchColumn() > 0) {
                // বিক্রির হিস্ট্রি আছে — মুছলে ইনভয়েস/রিপোর্টের নাম হারাবে, তাই শুধু Inactive
                $pdo->prepare("UPDATE canteen_menu_items SET status='Inactive' WHERE id=?")->execute([$id]);
                sec_log_activity($pdo, 'Canteen', 'Update', "Canteen Item বিক্রিতে ব্যবহৃত, তাই Inactive করা হয়েছে: {$it['name']}");
                $_SESSION['canteen_msg'] = 'এই Item বিক্রি হয়েছে, তাই মুছে না ফেলে Inactive করা হয়েছে।';
            } else {
                $pdo->prepare("DELETE FROM canteen_recipe_items WHERE menu_item_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM canteen_menu_items WHERE id = ?")->execute([$id]);
                sec_log_activity($pdo, 'Canteen', 'Delete', "Canteen Item মুছে ফেলা হয়েছে: {$it['name']}");
                $_SESSION['canteen_msg'] = 'Item মুছে ফেলা হয়েছে।';
            }
        }
        header('Location: index.php?page=canteen_menu');
        exit();

    } elseif (isset($_POST['add_recipe_line'])) {
        $menu_item_id    = (int) ($_POST['menu_item_id'] ?? 0);
        $raw_material_id = (int) ($_POST['raw_material_id'] ?? 0);
        $qty_required    = (float) ($_POST['qty_required'] ?? 0);
        if ($menu_item_id > 0 && $raw_material_id > 0 && $qty_required > 0
            && canteen_scoped_row($pdo, 'canteen_menu_items', $menu_item_id, 'id')
            && canteen_scoped_row($pdo, 'canteen_raw_materials', $raw_material_id, 'id')) {
            $dup = $pdo->prepare("SELECT id FROM canteen_recipe_items WHERE menu_item_id = ? AND raw_material_id = ?");
            $dup->execute([$menu_item_id, $raw_material_id]);
            if ($dup_id = $dup->fetchColumn()) {
                $pdo->prepare("UPDATE canteen_recipe_items SET qty_required = ? WHERE id = ?")->execute([$qty_required, $dup_id]);
                $_SESSION['canteen_msg'] = 'Recipe-তে আগে থেকেই থাকা কাঁচামালের পরিমাণ আপডেট হয়েছে।';
                header("Location: index.php?page=canteen_menu&edit=$menu_item_id");
                exit();
            }
            $pdo->prepare("INSERT INTO canteen_recipe_items (menu_item_id, raw_material_id, qty_required) VALUES (?, ?, ?)")
                ->execute([$menu_item_id, $raw_material_id, $qty_required]);
            $_SESSION['canteen_msg'] = 'Recipe-তে কাঁচামাল যোগ করা হয়েছে।';
        }
        header("Location: index.php?page=canteen_menu&edit=$menu_item_id");
        exit();

    } elseif (isset($_POST['delete_recipe_line'])) {
        $line_id      = (int) ($_POST['line_id'] ?? 0);
        $menu_item_id = (int) ($_POST['menu_item_id'] ?? 0);
        // শুধু নিজের branch-এর menu item-এর recipe লাইন মোছা যাবে
        if (canteen_scoped_row($pdo, 'canteen_menu_items', $menu_item_id, 'id')) {
            $pdo->prepare("DELETE FROM canteen_recipe_items WHERE id = ? AND menu_item_id = ?")->execute([$line_id, $menu_item_id]);
        }
        header("Location: index.php?page=canteen_menu&edit=$menu_item_id");
        exit();
    }
}

$m_w = []; $m_p = []; canteen_branch_or_shared($m_w, $m_p);
$m_sql = $m_w ? (' WHERE ' . implode(' AND ', $m_w)) : '';
$st = $pdo->prepare("SELECT * FROM canteen_categories$m_sql ORDER BY name"); $st->execute($m_p); $categories = $st->fetchAll();
$m_w2 = []; $m_p2 = []; canteen_branch_or_shared($m_w2, $m_p2, 's.branch_id');
$st = $pdo->prepare("SELECT s.*, c.name AS category_name FROM canteen_subcategories s LEFT JOIN canteen_categories c ON c.id = s.category_id" . ($m_w2 ? ' WHERE ' . implode(' AND ', $m_w2) : '') . " ORDER BY c.name, s.name");
$st->execute($m_p2); $subcategories = $st->fetchAll();
$st = $pdo->prepare("SELECT * FROM canteen_units$m_sql ORDER BY name"); $st->execute($m_p); $units = $st->fetchAll();
$crm2_where = ["status='Active'"]; $crm2_params = [];
branch_apply_filter($crm2_where, $crm2_params);
$crm2_stmt = $pdo->prepare("SELECT * FROM canteen_raw_materials WHERE " . implode(' AND ', $crm2_where) . " ORDER BY name ASC");
$crm2_stmt->execute($crm2_params);
$materials = $crm2_stmt->fetchAll();

$cm_where = []; $cm_params = [];
branch_apply_filter($cm_where, $cm_params, 'i.branch_id');
$cm_where_sql = $cm_where ? (' WHERE ' . implode(' AND ', $cm_where)) : '';
$cm_stmt = $pdo->prepare(
    "SELECT i.*, c.name AS category_name, sc.name AS subcategory_name, u.name AS unit_name
     FROM canteen_menu_items i
     LEFT JOIN canteen_categories c ON c.id = i.category_id
     LEFT JOIN canteen_subcategories sc ON sc.id = i.subcategory_id
     LEFT JOIN canteen_units u ON u.id = i.unit_id
     $cm_where_sql
     ORDER BY i.name"
);
$cm_stmt->execute($cm_params);
$all_items = $cm_stmt->fetchAll();

$edit_item = null;
$recipe_lines = [];
if ($edit_id > 0) {
    $edit_item = canteen_scoped_row($pdo, 'canteen_menu_items', $edit_id) ?: null;

    if ($edit_item) {
        $rstmt = $pdo->prepare(
            "SELECT r.*, m.name AS material_name, u.name AS unit_name
             FROM canteen_recipe_items r
             JOIN canteen_raw_materials m ON m.id = r.raw_material_id
             LEFT JOIN canteen_units u ON u.id = m.unit_id
             WHERE r.menu_item_id = ? ORDER BY m.name ASC"
        );
        $rstmt->execute([$edit_id]);
        $recipe_lines = $rstmt->fetchAll();
    }
}
?>

<div class="card-custom">

<?php if ($edit_item): ?>
    <!-- ===================== Edit Item + Recipe ===================== -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold text-dark mb-0"><i class="fa-solid fa-utensils"></i> Edit Item</h4>
        <a href="index.php?page=canteen_menu" class="btn btn-outline-secondary fw-bold"><i class="fa-solid fa-arrow-left"></i> Item List-এ ফিরে যাও</a>
    </div>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?php echo cv($err); ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="alert alert-success py-2"><?php echo cv($msg); ?></div><?php endif; ?>

    <form method="POST" action="index.php?page=canteen_menu" enctype="multipart/form-data" class="row g-2 mb-4">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo (int) $edit_item['id']; ?>">
        <div class="col-md-4"><input type="text" name="name" class="form-control form-control-sm" placeholder="Item Name" value="<?php echo cv($edit_item['name']); ?>" required></div>
        <div class="col-md-4"><input type="text" name="item_code" class="form-control form-control-sm" placeholder="Item Code (optional)" value="<?php echo cv($edit_item['item_code']); ?>"></div>
        <div class="col-md-4">
            <select name="category_id" class="form-select form-select-sm">
                <option value="">Category বেছে নিন</option>
                <?php foreach ($categories as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo (int) $edit_item['category_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo cv($c['name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <select name="subcategory_id" class="form-select form-select-sm">
                <option value="">Sub Category বেছে নিন</option>
                <?php foreach ($subcategories as $sc): ?><option value="<?php echo (int) $sc['id']; ?>" <?php echo (int) $edit_item['subcategory_id'] === (int) $sc['id'] ? 'selected' : ''; ?>><?php echo cv($sc['name']); ?> (<?php echo cv($sc['category_name']); ?>)</option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <select name="unit_id" class="form-select form-select-sm">
                <option value="">Unit বেছে নিন</option>
                <?php foreach ($units as $u): ?><option value="<?php echo (int) $u['id']; ?>" <?php echo (int) $edit_item['unit_id'] === (int) $u['id'] ? 'selected' : ''; ?>><?php echo cv($u['name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4"><input type="number" step="0.01" min="0.01" name="price" class="form-control form-control-sm" placeholder="Price" value="<?php echo cv($edit_item['price']); ?>" required></div>
        <div class="col-md-4">
            <select name="status" class="form-select form-select-sm">
                <option value="Active" <?php echo $edit_item['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                <option value="Inactive" <?php echo $edit_item['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>
        <div class="col-md-8"><input type="text" name="description" class="form-control form-control-sm" placeholder="Description (optional)" value="<?php echo cv($edit_item['description']); ?>"></div>
        <div class="col-md-4">
            <input type="file" name="photo" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp">
            <?php if (!empty($edit_item['photo_path'])): ?><small class="text-muted">বর্তমান: <a href="../<?php echo cv($edit_item['photo_path']); ?>" target="_blank">দেখুন</a></small><?php endif; ?>
        </div>
        <div class="col-12"><button type="submit" name="save_item" class="btn btn-success btn-sm px-4">Update Item</button></div>
    </form>

    <hr class="mb-4">
    <h5 class="fw-bold mb-3"><i class="fa-solid fa-flask"></i> Recipe — <?php echo cv($edit_item['name']); ?> ১ ইউনিট বানাতে যা যা লাগে</h5>

    <form method="POST" action="index.php?page=canteen_menu" class="row g-2 mb-3 align-items-end">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="menu_item_id" value="<?php echo (int) $edit_item['id']; ?>">
        <div class="col-md-5">
            <label class="form-label small fw-bold">Raw Material</label>
            <select name="raw_material_id" class="form-select" required>
                <option value="">-- বেছে নিন --</option>
                <?php foreach ($materials as $m): ?><option value="<?php echo (int) $m['id']; ?>"><?php echo cv($m['name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">প্রয়োজনীয় পরিমাণ</label>
            <input type="number" step="0.001" min="0.001" name="qty_required" class="form-control" required>
        </div>
        <div class="col-md-3"><button type="submit" name="add_recipe_line" class="btn btn-outline-primary">+ Recipe-তে যোগ করো</button></div>
    </form>

    <div class="table-responsive">
    <table class="table table-bordered align-middle">
        <thead class="table-dark"><tr><th>Raw Material</th><th>প্রয়োজনীয় পরিমাণ</th><th>Unit</th><th class="text-end">Action</th></tr></thead>
        <tbody>
            <?php if ($recipe_lines): foreach ($recipe_lines as $rl): $csrf = cv(csrf_token()); ?>
                <tr>
                    <td><?php echo cv($rl['material_name']); ?></td>
                    <td><?php echo number_format((float) $rl['qty_required'], 3); ?></td>
                    <td><?php echo cv($rl['unit_name'] ?: '-'); ?></td>
                    <td class="text-end">
                        <form method="POST" action="index.php?page=canteen_menu" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="line_id" value="<?php echo (int) $rl['id']; ?>">
                            <input type="hidden" name="menu_item_id" value="<?php echo (int) $edit_item['id']; ?>">
                            <button type="submit" name="delete_recipe_line" class="btn btn-outline-danger btn-sm">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="4" class="text-center text-muted py-3">এখনো কোনো Recipe সেট করা হয়নি — এই আইটেম বিক্রি হলে কোনো কাঁচামাল স্টক থেকে বিয়োগ হবে না।</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

<?php else: ?>
    <!-- ===================== Add Item / Category / Sub Category / Unit ===================== -->
    <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-utensils"></i> Canteen &raquo; Items</h4>
    <p class="text-muted mb-4" style="font-size:13px;">Add Item, Category, Sub Category ও Unit ম্যানেজ করুন।</p>

    <?php if ($msg): ?><div class="alert alert-success py-2"><?php echo cv($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?php echo cv($err); ?></div><?php endif; ?>

    <div id="itemsTabGroup">
        <div class="inv-tab-boxes">
            <button type="button" class="inv-tab-box active" onclick="invTabSwitch('itemsTabGroup','paneAddItem',this)">Add Item</button>
            <button type="button" class="inv-tab-box" onclick="invTabSwitch('itemsTabGroup','paneCategory',this)">Category</button>
            <button type="button" class="inv-tab-box" onclick="invTabSwitch('itemsTabGroup','paneSubCategory',this)">Sub Category</button>
            <button type="button" class="inv-tab-box" onclick="invTabSwitch('itemsTabGroup','paneUnit',this)">Unit</button>
            <button type="button" class="inv-tab-box" onclick="invTabSwitch('itemsTabGroup','paneItemList',this)">Item List</button>
        </div>

        <!-- Add Item -->
        <div id="paneAddItem" class="inv-tab-pane card p-3 mb-4">
            <form method="POST" action="index.php?page=canteen_menu" enctype="multipart/form-data" class="row g-2">
                <?php echo csrf_field(); ?>
                <div class="col-md-4"><input type="text" name="name" class="form-control form-control-sm" placeholder="Item Name" required></div>
                <div class="col-md-4"><input type="text" name="item_code" class="form-control form-control-sm" placeholder="Item Code (optional)"></div>
                <div class="col-md-4">
                    <select name="category_id" id="itemCategorySelect" class="form-select form-select-sm" onchange="invFilterSubcategory()">
                        <option value="">Category বেছে নিন</option>
                        <?php foreach ($categories as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo cv($c['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <select name="subcategory_id" id="itemSubcategorySelect" class="form-select form-select-sm">
                        <option value="">Sub Category বেছে নিন</option>
                        <?php foreach ($subcategories as $sc): ?><option value="<?php echo (int) $sc['id']; ?>" data-cat="<?php echo (int) $sc['category_id']; ?>"><?php echo cv($sc['name']); ?> (<?php echo cv($sc['category_name']); ?>)</option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <select name="unit_id" class="form-select form-select-sm">
                        <option value="">Unit বেছে নিন</option>
                        <?php foreach ($units as $u): ?><option value="<?php echo (int) $u['id']; ?>"><?php echo cv($u['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4"><input type="number" step="0.01" min="0.01" name="price" class="form-control form-control-sm" placeholder="Price" required></div>
                <div class="col-md-4"><input type="text" name="description" class="form-control form-control-sm" placeholder="Description (optional)"></div>
                <div class="col-md-4"><input type="file" name="photo" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp"></div>
                <div class="col-md-4"><button type="submit" name="save_item" class="btn btn-success btn-sm w-100">Add Item</button></div>
            </form>
        </div>

        <!-- Category -->
        <div id="paneCategory" class="inv-tab-pane card p-3 mb-4 d-none">
            <form method="POST" action="index.php?page=canteen_menu" class="row g-2 mb-3">
                <?php echo csrf_field(); ?>
                <div class="col-md-8"><input type="text" name="category_name" class="form-control form-control-sm" placeholder="যেমন: Snacks, Drinks" required></div>
                <div class="col-md-4"><button type="submit" name="add_category" class="btn btn-primary btn-sm w-100">Add Category</button></div>
            </form>
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light"><tr><th>#</th><th>Category</th></tr></thead>
                <tbody>
                    <?php if ($categories): foreach ($categories as $c): ?>
                        <tr><td><?php echo (int) $c['id']; ?></td><td><?php echo cv($c['name']); ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="2" class="text-muted">কোনো Category নেই</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Sub Category -->
        <div id="paneSubCategory" class="inv-tab-pane card p-3 mb-4 d-none">
            <form method="POST" action="index.php?page=canteen_menu" class="row g-2 mb-3">
                <?php echo csrf_field(); ?>
                <div class="col-md-4">
                    <select name="sub_category_id" class="form-select form-select-sm" required>
                        <option value="">Parent Category বেছে নিন</option>
                        <?php foreach ($categories as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo cv($c['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4"><input type="text" name="subcategory_name" class="form-control form-control-sm" placeholder="Sub Category Name" required></div>
                <div class="col-md-4"><button type="submit" name="add_subcategory" class="btn btn-primary btn-sm w-100">Add Sub Category</button></div>
            </form>
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light"><tr><th>#</th><th>Sub Category</th><th>Parent Category</th></tr></thead>
                <tbody>
                    <?php if ($subcategories): foreach ($subcategories as $sc): ?>
                        <tr><td><?php echo (int) $sc['id']; ?></td><td><?php echo cv($sc['name']); ?></td><td><?php echo cv($sc['category_name'] ?? '-'); ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="3" class="text-muted">কোনো Sub Category নেই</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Unit -->
        <div id="paneUnit" class="inv-tab-pane card p-3 mb-4 d-none">
            <form method="POST" action="index.php?page=canteen_menu" class="row g-2 mb-3">
                <?php echo csrf_field(); ?>
                <div class="col-md-8"><input type="text" name="unit_name" class="form-control form-control-sm" placeholder="যেমন: Plate, Cup, Pcs" required></div>
                <div class="col-md-4"><button type="submit" name="add_unit" class="btn btn-primary btn-sm w-100">Add Unit</button></div>
            </form>
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light"><tr><th>#</th><th>Unit</th></tr></thead>
                <tbody>
                    <?php if ($units): foreach ($units as $u): ?>
                        <tr><td><?php echo (int) $u['id']; ?></td><td><?php echo cv($u['name']); ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="2" class="text-muted">কোনো Unit নেই</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Item List -->
        <div id="paneItemList" class="inv-tab-pane card p-3 mb-4 d-none">
            <div class="table-responsive">
            <table class="table table-bordered table-striped text-center align-middle mb-0">
                <thead class="table-dark">
                    <tr><th>ID</th><th>Photo</th><th>Code</th><th>Name</th><th>Category</th><th>Sub Category</th><th>Unit</th><th>Price</th><th>Recipe</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php if ($all_items): foreach ($all_items as $it): $csrf = cv(csrf_token());
                        $rc = $pdo->prepare("SELECT COUNT(*) FROM canteen_recipe_items WHERE menu_item_id = ?");
                        $rc->execute([$it['id']]);
                        $recipe_count = (int) $rc->fetchColumn();
                    ?>
                        <tr>
                            <td>#<?php echo (int) $it['id']; ?></td>
                            <td><?php if ($it['photo_path']): ?><img src="../<?php echo cv($it['photo_path']); ?>" style="width:36px;height:36px;object-fit:cover;border-radius:6px;"><?php else: ?>-<?php endif; ?></td>
                            <td><?php echo cv($it['item_code'] ?? ''); ?></td>
                            <td><?php echo cv($it['name']); ?></td>
                            <td><?php echo cv($it['category_name'] ?? '-'); ?></td>
                            <td><?php echo cv($it['subcategory_name'] ?? '-'); ?></td>
                            <td><?php echo cv($it['unit_name'] ?? '-'); ?></td>
                            <td>৳<?php echo number_format((float) $it['price'], 2); ?></td>
                            <td><?php echo $recipe_count > 0 ? "<span class=\"badge bg-info\">{$recipe_count} items</span>" : '<span class="badge bg-secondary">সেট করা হয়নি</span>'; ?></td>
                            <td><?php echo $it['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                            <td class="text-nowrap">
                                <a href="index.php?page=canteen_menu&edit=<?php echo (int) $it['id']; ?>" class="btn btn-outline-primary btn-sm">Edit / Recipe</a>
                                <form method="POST" action="index.php?page=canteen_menu" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $it['id']; ?>">
                                    <button type="submit" name="delete_item" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="11" class="text-muted">কোনো Item নেই</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
<?php endif; ?>

</div>

<style>
.inv-tab-boxes { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
.inv-tab-box { border: 2px solid #0d6efd; background: #fff; color: #0d6efd; font-weight: 600; padding: 8px 22px; border-radius: 8px; cursor: pointer; font-size: 14px; }
.inv-tab-box.active { background: #0d6efd; color: #fff; }
.inv-tab-pane { border: 1px solid #dee2e6; }
</style>
<script>
document.addEventListener("wheel", function (e) {
    if (document.activeElement && document.activeElement.tagName === "INPUT" && document.activeElement.type === "number") { document.activeElement.blur(); }
}, { passive: true });

function invTabSwitch(groupId, targetId, btn) {
    const group = document.getElementById(groupId);
    group.querySelectorAll('.inv-tab-pane').forEach(p => p.classList.add('d-none'));
    document.getElementById(targetId).classList.remove('d-none');
    group.querySelectorAll('.inv-tab-box').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

function invFilterSubcategory() {
    const catSelect = document.getElementById('itemCategorySelect');
    if (!catSelect) return;
    const catId = catSelect.value;
    const subSelect = document.getElementById('itemSubcategorySelect');
    subSelect.querySelectorAll('option[data-cat]').forEach(opt => {
        opt.style.display = (!catId || opt.getAttribute('data-cat') === catId) ? '' : 'none';
    });
    subSelect.value = '';
}
document.addEventListener('DOMContentLoaded', invFilterSubcategory);
</script>
