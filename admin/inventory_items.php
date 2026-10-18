<?php
// admin/inventory_items.php - Inventory & Sales > Items
require_once __DIR__ . '/inventory_common.php';

$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error_msg = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {

        if (isset($_POST['add_category'])) {
            $name = trim($_POST['category_name'] ?? '');
            if ($name !== '') {
                $pdo->prepare("INSERT INTO inv_categories (name, branch_id) VALUES (?, ?)")->execute([$name, branch_id_for_insert()]);
                sec_log_activity($pdo, 'Inventory', 'Create', "নতুন Category যোগ করা হয়েছে: {$name}");
                $success_msg = "ক্যাটাগরি যোগ করা হয়েছে।";
            }
        }

        if (isset($_POST['add_subcategory'])) {
            $category_id = (int) ($_POST['sub_category_id'] ?? 0);
            $name = trim($_POST['subcategory_name'] ?? '');
            if ($category_id > 0 && $name !== '' && inv_master_id_in_scope($pdo, 'inv_categories', $category_id)) {
                $pdo->prepare("INSERT INTO inv_subcategories (category_id, name, branch_id) VALUES (?, ?, ?)")->execute([$category_id, $name, branch_id_for_insert()]);
                sec_log_activity($pdo, 'Inventory', 'Create', "নতুন Sub Category যোগ করা হয়েছে: {$name}");
                $success_msg = "সাব-ক্যাটাগরি যোগ করা হয়েছে।";
            } else {
                $error_msg = "Category বেছে নিন এবং Sub Category-র নাম দিন!";
            }
        }

        if (isset($_POST['add_unit'])) {
            $name = trim($_POST['unit_name'] ?? '');
            if ($name !== '') {
                $pdo->prepare("INSERT INTO inv_units (name, branch_id) VALUES (?, ?)")->execute([$name, branch_id_for_insert()]);
                sec_log_activity($pdo, 'Inventory', 'Create', "নতুন Unit যোগ করা হয়েছে: {$name}");
                $success_msg = "ইউনিট যোগ করা হয়েছে।";
            }
        }

        if (isset($_POST['add_item'])) {
            $name          = trim($_POST['item_name'] ?? '');
            $category_id   = (int) ($_POST['category_id'] ?? 0) ?: null;
            $subcategory_id = (int) ($_POST['subcategory_id'] ?? 0) ?: null;
            $unit_id       = (int) ($_POST['unit_id'] ?? 0) ?: null;
            $item_code     = trim($_POST['item_code'] ?? '');
            $cost_price    = (float) ($_POST['cost_price'] ?? 0);
            $sale_price    = (float) ($_POST['sale_price'] ?? 0);
            $reorder_level = (int) ($_POST['reorder_level'] ?? 0);
            $opening_stock = (int) ($_POST['opening_stock'] ?? 0);

            $category_id    = inv_master_id_in_scope($pdo, 'inv_categories', $category_id);
            $subcategory_id = inv_master_id_in_scope($pdo, 'inv_subcategories', $subcategory_id);
            $unit_id        = inv_master_id_in_scope($pdo, 'inv_units', $unit_id);
            $cost_price = max(0, $cost_price); $sale_price = max(0, $sale_price);
            $reorder_level = max(0, $reorder_level); $opening_stock = max(0, $opening_stock);
            if ($name !== '') {
                $stmt = $pdo->prepare(
                    "INSERT INTO inventory_items (name, stock_qty, category_id, subcategory_id, unit_id, item_code, cost_price, sale_price, reorder_level, status, branch_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?)"
                );
                $stmt->execute([$name, $opening_stock, $category_id, $subcategory_id, $unit_id, $item_code ?: null, $cost_price, $sale_price, $reorder_level, branch_id_for_insert()]);
                if ($opening_stock > 0) {
                    $pdo->prepare("INSERT INTO inv_stock_adjustments (item_id, adj_type, qty, reason, branch_id) VALUES (?, 'Opening', ?, 'Opening Stock', ?)")
                        ->execute([(int) $pdo->lastInsertId(), $opening_stock, branch_id_for_insert()]);
                }
                sec_log_activity($pdo, 'Inventory', 'Create', "নতুন Item যোগ করা হয়েছে: {$name}, Sale Price: ৳{$sale_price}, Opening Stock: {$opening_stock}");
                $success_msg = "নতুন Item যোগ করা হয়েছে।";
            }
        }

        if (isset($_POST['edit_item'])) {
            $eid = (int) ($_POST['item_id'] ?? 0);
            $name = trim($_POST['item_name'] ?? '');
            $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
            $cat = inv_master_id_in_scope($pdo, 'inv_categories', (int) ($_POST['category_id'] ?? 0) ?: null);
            $sub = inv_master_id_in_scope($pdo, 'inv_subcategories', (int) ($_POST['subcategory_id'] ?? 0) ?: null);
            $unit = inv_master_id_in_scope($pdo, 'inv_units', (int) ($_POST['unit_id'] ?? 0) ?: null);
            $code = trim($_POST['item_code'] ?? '');
            $sale_price = (float) ($_POST['sale_price'] ?? 0);
            $cost_price = (float) ($_POST['cost_price'] ?? 0);
            $reorder = max(0, (int) ($_POST['reorder_level'] ?? 0));
            if ($name === '' || $sale_price < 0 || $cost_price < 0) {
                $error_msg = "Item-এর নাম দিন এবং দাম ঋণাত্মক হতে পারবে না!";
            } elseif (!inv_scoped_row($pdo, 'inventory_items', $eid, 'id')) {
                $error_msg = "Item পাওয়া যায়নি!";
            } else {
                $pdo->prepare("UPDATE inventory_items SET name=?, item_code=?, category_id=?, subcategory_id=?, unit_id=?, cost_price=?, sale_price=?, reorder_level=?, status=? WHERE id=?")
                    ->execute([$name, $code ?: null, $cat, $sub, $unit, $cost_price, $sale_price, $reorder, $status, $eid]);
                sec_log_activity($pdo, 'Inventory', 'Update', "Item আপডেট করা হয়েছে: {$name} (#{$eid}), Sale Price: ৳{$sale_price}, Status: {$status}");
                $success_msg = "Item আপডেট হয়েছে। (Stock বদলাতে Stock Adjustment ব্যবহার করুন)";
            }
        }

        if (isset($_POST['delete_item'])) {
            $did = (int) ($_POST['item_id'] ?? 0);
            $row = inv_scoped_row($pdo, 'inventory_items', $did, 'id, name');
            if (!$row) {
                $error_msg = "Item পাওয়া যায়নি!";
            } else {
                $used = 0;
                foreach (['inv_purchase_items', 'inv_sale_items', 'inv_sale_return_items', 'inv_stock_adjustments'] as $t) {
                    $c = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE item_id = ?");
                    $c->execute([$did]);
                    $used += (int) $c->fetchColumn();
                }
                if ($used > 0) {
                    // লেনদেন আছে — হিসাব ঠিক রাখতে delete না করে Inactive করা হয়
                    $pdo->prepare("UPDATE inventory_items SET status='Inactive' WHERE id=?")->execute([$did]);
                    sec_log_activity($pdo, 'Inventory', 'Update', "Item লেনদেনে ব্যবহৃত, তাই Inactive করা হয়েছে: {$row['name']} (#{$did})");
                    $success_msg = "এই Item-এর লেনদেন আছে, তাই মুছে না ফেলে Inactive করা হয়েছে।";
                } else {
                    $pdo->prepare("DELETE FROM inventory_items WHERE id=?")->execute([$did]);
                    sec_log_activity($pdo, 'Inventory', 'Delete', "Item মুছে ফেলা হয়েছে: {$row['name']} (#{$did})");
                    $success_msg = "Item মুছে ফেলা হয়েছে।";
                }
            }
        }
    }
}

$m_w = []; $m_p = []; inv_branch_or_shared($m_w, $m_p);
$m_sql = $m_w ? (' WHERE ' . implode(' AND ', $m_w)) : '';
$st = $pdo->prepare("SELECT * FROM inv_categories$m_sql ORDER BY name"); $st->execute($m_p); $categories = $st->fetchAll();
$m_w2 = []; $m_p2 = []; inv_branch_or_shared($m_w2, $m_p2, 's.branch_id');
$st = $pdo->prepare(
    "SELECT s.*, c.name AS category_name FROM inv_subcategories s LEFT JOIN inv_categories c ON c.id = s.category_id" . ($m_w2 ? ' WHERE ' . implode(' AND ', $m_w2) : '') . " ORDER BY c.name, s.name"
);
$st->execute($m_p2); $subcategories = $st->fetchAll();
$st = $pdo->prepare("SELECT * FROM inv_units$m_sql ORDER BY name"); $st->execute($m_p); $units = $st->fetchAll();
$ii_where = []; $ii_params = [];
branch_apply_filter($ii_where, $ii_params, 'i.branch_id');
$ii_where_sql = $ii_where ? (' WHERE ' . implode(' AND ', $ii_where)) : '';
$ii_stmt = $pdo->prepare(
    "SELECT i.*, c.name AS category_name, sc.name AS subcategory_name, u.name AS unit_name FROM inventory_items i
     LEFT JOIN inv_categories c ON c.id = i.category_id
     LEFT JOIN inv_subcategories sc ON sc.id = i.subcategory_id
     LEFT JOIN inv_units u ON u.id = i.unit_id
     $ii_where_sql
     ORDER BY i.name"
);
$ii_stmt->execute($ii_params);
$all_items = $ii_stmt->fetchAll();
?>

<div class="card-custom">
    <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-shop"></i> Inventory & Sales &raquo; Items</h4>
    <p class="text-muted mb-4" style="font-size:13px;">Add Item, Category, Sub Category ও Unit ম্যানেজ করুন।</p>

    <?php if ($success_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($success_msg); ?></div><?php endif; ?>
    <?php if ($error_msg): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>

    <!-- ============ Tab Boxes: Add Item / Category / Sub Category / Unit ============ -->
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
            <form method="POST" class="row g-2">
                <?php echo csrf_field(); ?>
                <div class="col-md-4"><input type="text" name="item_name" class="form-control form-control-sm" placeholder="Item Name" required></div>
                <div class="col-md-4"><input type="text" name="item_code" class="form-control form-control-sm" placeholder="Item Code (optional)"></div>
                <div class="col-md-4">
                    <select name="category_id" id="itemCategorySelect" class="form-select form-select-sm" onchange="invFilterSubcategory()">
                        <option value="">Category বেছে নিন</option>
                        <?php foreach ($categories as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <select name="subcategory_id" id="itemSubcategorySelect" class="form-select form-select-sm">
                        <option value="">Sub Category বেছে নিন</option>
                        <?php foreach ($subcategories as $sc): ?><option value="<?php echo (int)$sc['id']; ?>" data-cat="<?php echo (int)$sc['category_id']; ?>"><?php echo htmlspecialchars($sc['name']); ?> (<?php echo htmlspecialchars($sc['category_name']); ?>)</option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <select name="unit_id" class="form-select form-select-sm">
                        <option value="">Unit বেছে নিন</option>
                        <?php foreach ($units as $u): ?><option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4"><input type="number" step="0.01" min="0" name="cost_price" class="form-control form-control-sm" placeholder="Cost Price" required></div>
                <div class="col-md-4"><input type="number" step="0.01" min="0" name="sale_price" class="form-control form-control-sm" placeholder="Sale Price" required></div>
                <div class="col-md-4"><input type="number" min="0" name="reorder_level" class="form-control form-control-sm" placeholder="Reorder Level" value="0"></div>
                <div class="col-md-4"><input type="number" min="0" name="opening_stock" class="form-control form-control-sm" placeholder="Opening Stock" value="0"></div>
                <div class="col-md-4"><button type="submit" name="add_item" class="btn btn-success btn-sm w-100">Add Item</button></div>
            </form>
        </div>

        <!-- Category -->
        <div id="paneCategory" class="inv-tab-pane card p-3 mb-4 d-none">
            <form method="POST" class="row g-2 mb-3">
                <?php echo csrf_field(); ?>
                <div class="col-md-8"><input type="text" name="category_name" class="form-control form-control-sm" placeholder="যেমন: Stationery" required></div>
                <div class="col-md-4"><button type="submit" name="add_category" class="btn btn-primary btn-sm w-100">Add Category</button></div>
            </form>
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light"><tr><th>#</th><th>Category</th></tr></thead>
                <tbody>
                    <?php if ($categories): foreach ($categories as $c): ?>
                        <tr><td><?php echo (int)$c['id']; ?></td><td><?php echo htmlspecialchars($c['name']); ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="2" class="text-muted">কোনো Category নেই</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Sub Category -->
        <div id="paneSubCategory" class="inv-tab-pane card p-3 mb-4 d-none">
            <form method="POST" class="row g-2 mb-3">
                <?php echo csrf_field(); ?>
                <div class="col-md-4">
                    <select name="sub_category_id" class="form-select form-select-sm" required>
                        <option value="">Parent Category বেছে নিন</option>
                        <?php foreach ($categories as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4"><input type="text" name="subcategory_name" class="form-control form-control-sm" placeholder="Sub Category Name" required></div>
                <div class="col-md-4"><button type="submit" name="add_subcategory" class="btn btn-primary btn-sm w-100">Add Sub Category</button></div>
            </form>
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light"><tr><th>#</th><th>Sub Category</th><th>Parent Category</th></tr></thead>
                <tbody>
                    <?php if ($subcategories): foreach ($subcategories as $sc): ?>
                        <tr><td><?php echo (int)$sc['id']; ?></td><td><?php echo htmlspecialchars($sc['name']); ?></td><td><?php echo htmlspecialchars($sc['category_name'] ?? '-'); ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="3" class="text-muted">কোনো Sub Category নেই</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Unit -->
        <div id="paneUnit" class="inv-tab-pane card p-3 mb-4 d-none">
            <form method="POST" class="row g-2 mb-3">
                <?php echo csrf_field(); ?>
                <div class="col-md-8"><input type="text" name="unit_name" class="form-control form-control-sm" placeholder="যেমন: Pcs, Kg, Packet" required></div>
                <div class="col-md-4"><button type="submit" name="add_unit" class="btn btn-primary btn-sm w-100">Add Unit</button></div>
            </form>
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light"><tr><th>#</th><th>Unit</th></tr></thead>
                <tbody>
                    <?php if ($units): foreach ($units as $u): ?>
                        <tr><td><?php echo (int)$u['id']; ?></td><td><?php echo htmlspecialchars($u['name']); ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="2" class="text-muted">কোনো Unit নেই</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Edit Item -->
        <div id="paneEditItem" class="inv-tab-pane card p-3 mb-4 d-none">
            <h5 class="fw-bold mb-3">Item Edit <small class="text-muted">(Stock বদলাতে Stock Adjustment ব্যবহার করুন)</small></h5>
            <form method="POST" class="row g-2">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="item_id" id="editItemId">
                <div class="col-md-4"><input type="text" name="item_name" id="editItemName" class="form-control form-control-sm" placeholder="Item Name" required></div>
                <div class="col-md-4"><input type="text" name="item_code" id="editItemCode" class="form-control form-control-sm" placeholder="Item Code"></div>
                <div class="col-md-4">
                    <select name="status" id="editItemStatus" class="form-select form-select-sm"><option value="Active">Active</option><option value="Inactive">Inactive</option></select>
                </div>
                <div class="col-md-4"><select name="category_id" id="editItemCat" class="form-select form-select-sm"><option value="">Category</option><?php foreach ($categories as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><select name="subcategory_id" id="editItemSub" class="form-select form-select-sm"><option value="">Sub Category</option><?php foreach ($subcategories as $sc): ?><option value="<?php echo (int)$sc['id']; ?>"><?php echo htmlspecialchars($sc['name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><select name="unit_id" id="editItemUnit" class="form-select form-select-sm"><option value="">Unit</option><?php foreach ($units as $u): ?><option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><input type="number" step="0.01" min="0" name="cost_price" id="editItemCost" class="form-control form-control-sm" placeholder="Cost Price" required></div>
                <div class="col-md-4"><input type="number" step="0.01" min="0" name="sale_price" id="editItemPrice" class="form-control form-control-sm" placeholder="Sale Price" required></div>
                <div class="col-md-4"><input type="number" min="0" name="reorder_level" id="editItemReorder" class="form-control form-control-sm" placeholder="Reorder Level"></div>
                <div class="col-md-12"><button type="submit" name="edit_item" class="btn btn-primary btn-sm">Update Item</button></div>
            </form>
        </div>

        <!-- Item List -->
        <div id="paneItemList" class="inv-tab-pane card p-3 mb-4 d-none">
            <div class="table-responsive">
            <table class="table table-bordered table-striped text-center align-middle mb-0">
                <thead class="table-dark">
                    <tr><th>ID</th><th>Code</th><th>Name</th><th>Category</th><th>Sub Category</th><th>Unit</th><th>Cost</th><th>Sale Price</th><th>Stock</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php if ($all_items): foreach ($all_items as $it):
                        $low = ((int)$it['reorder_level'] > 0 && (int)$it['stock_qty'] <= (int)$it['reorder_level']);
                    ?>
                        <tr>
                            <td>#<?php echo (int)$it['id']; ?></td>
                            <td><?php echo htmlspecialchars($it['item_code'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($it['name']); ?></td>
                            <td><?php echo htmlspecialchars($it['category_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($it['subcategory_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($it['unit_name'] ?? '-'); ?></td>
                            <td>৳<?php echo number_format((float)$it['cost_price'], 2); ?></td>
                            <td>৳<?php echo number_format((float)$it['sale_price'], 2); ?></td>
                            <td>
                                <span class="badge <?php echo $low ? 'bg-danger' : 'bg-success'; ?> fs-6"><?php echo (int)$it['stock_qty']; ?></span>
                                <?php if ($low): ?><br><span class="badge bg-warning text-dark mt-1">Low Stock</span><?php endif; ?>
                            </td>
                            <td><span class="badge <?php echo ($it['status'] ?? 'Active') === 'Active' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo htmlspecialchars($it['status'] ?? 'Active'); ?></span></td>
                            <td class="text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick='invEditItem(<?php echo json_encode(["id"=>(int)$it["id"],"name"=>$it["name"],"code"=>$it["item_code"],"cat"=>(int)$it["category_id"],"sub"=>(int)$it["subcategory_id"],"unit"=>(int)$it["unit_id"],"cost"=>(float)$it["cost_price"],"price"=>(float)$it["sale_price"],"reorder"=>(int)$it["reorder_level"],"status"=>$it["status"] ?? "Active"], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>)'>Edit</button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('এই Item মুছে ফেলবেন? (লেনদেন থাকলে শুধু Inactive হবে)');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="item_id" value="<?php echo (int)$it['id']; ?>">
                                    <button type="submit" name="delete_item" class="btn btn-sm btn-outline-danger">Delete</button>
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
</div>

<style>
.inv-tab-boxes { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
.inv-tab-box {
    border: 2px solid #0d6efd; background: #fff; color: #0d6efd; font-weight: 600;
    padding: 8px 22px; border-radius: 8px; cursor: pointer; font-size: 14px;
}
.inv-tab-box.active { background: #0d6efd; color: #fff; }
.inv-tab-pane { border: 1px solid #dee2e6; }
</style>
<script>
// Number input-এ মাউস স্ক্রল করলে value change হয়ে যাওয়া বন্ধ করা হচ্ছে (ফোকাস থাকলে scroll করলেই blur হয়ে যাবে)
document.addEventListener("wheel", function (e) {
    if (document.activeElement && document.activeElement.tagName === "INPUT" && document.activeElement.type === "number") {
        document.activeElement.blur();
    }
}, { passive: true });

// টপে থাকা বক্সগুলো ক্লিক করলে নিচের সংশ্লিষ্ট সেকশন দেখাবে, বাকিগুলো লুকাবে
function invTabSwitch(groupId, targetId, btn) {
    const group = document.getElementById(groupId);
    group.querySelectorAll('.inv-tab-pane').forEach(p => p.classList.add('d-none'));
    document.getElementById(targetId).classList.remove('d-none');
    group.querySelectorAll('.inv-tab-box').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

// Category বেছে নিলে শুধু সেই Category-র Sub Category গুলোই দেখাবে
function invFilterSubcategory() {
    const catId = document.getElementById('itemCategorySelect').value;
    const subSelect = document.getElementById('itemSubcategorySelect');
    subSelect.querySelectorAll('option[data-cat]').forEach(opt => {
        opt.style.display = (!catId || opt.getAttribute('data-cat') === catId) ? '' : 'none';
    });
    subSelect.value = '';
}
document.addEventListener('DOMContentLoaded', invFilterSubcategory);

function invEditItem(d) {
    const g = id => document.getElementById(id);
    g('editItemId').value = d.id; g('editItemName').value = d.name; g('editItemCode').value = d.code || '';
    g('editItemCat').value = d.cat || ''; g('editItemSub').value = d.sub || ''; g('editItemUnit').value = d.unit || '';
    g('editItemCost').value = d.cost; g('editItemPrice').value = d.price; g('editItemReorder').value = d.reorder; g('editItemStatus').value = d.status;
    const group = document.getElementById('itemsTabGroup');
    group.querySelectorAll('.inv-tab-pane').forEach(p => p.classList.add('d-none'));
    g('paneEditItem').classList.remove('d-none');
    group.querySelectorAll('.inv-tab-box').forEach(b => b.classList.remove('active'));
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>
