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
                $pdo->prepare("INSERT INTO inv_categories (name) VALUES (?)")->execute([$name]);
                sec_log_activity($pdo, 'Inventory', 'Create', "নতুন Category যোগ করা হয়েছে: {$name}");
                $success_msg = "ক্যাটাগরি যোগ করা হয়েছে।";
            }
        }

        if (isset($_POST['add_subcategory'])) {
            $category_id = (int) ($_POST['sub_category_id'] ?? 0);
            $name = trim($_POST['subcategory_name'] ?? '');
            if ($category_id > 0 && $name !== '') {
                $pdo->prepare("INSERT INTO inv_subcategories (category_id, name) VALUES (?, ?)")->execute([$category_id, $name]);
                sec_log_activity($pdo, 'Inventory', 'Create', "নতুন Sub Category যোগ করা হয়েছে: {$name}");
                $success_msg = "সাব-ক্যাটাগরি যোগ করা হয়েছে।";
            } else {
                $error_msg = "Category বেছে নিন এবং Sub Category-র নাম দিন!";
            }
        }

        if (isset($_POST['add_unit'])) {
            $name = trim($_POST['unit_name'] ?? '');
            if ($name !== '') {
                $pdo->prepare("INSERT INTO inv_units (name) VALUES (?)")->execute([$name]);
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

            if ($name !== '') {
                $stmt = $pdo->prepare(
                    "INSERT INTO inventory_items (name, stock_qty, category_id, subcategory_id, unit_id, item_code, cost_price, sale_price, reorder_level, status, branch_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?)"
                );
                $stmt->execute([$name, $opening_stock, $category_id, $subcategory_id, $unit_id, $item_code ?: null, $cost_price, $sale_price, $reorder_level, branch_id_for_insert()]);
                sec_log_activity($pdo, 'Inventory', 'Create', "নতুন Item যোগ করা হয়েছে: {$name}, Sale Price: ৳{$sale_price}, Opening Stock: {$opening_stock}");
                $success_msg = "নতুন Item যোগ করা হয়েছে।";
            }
        }
    }
}

$categories    = $pdo->query("SELECT * FROM inv_categories ORDER BY name")->fetchAll();
$subcategories = $pdo->query(
    "SELECT s.*, c.name AS category_name FROM inv_subcategories s LEFT JOIN inv_categories c ON c.id = s.category_id ORDER BY c.name, s.name"
)->fetchAll();
$units = $pdo->query("SELECT * FROM inv_units ORDER BY name")->fetchAll();
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

        <!-- Item List -->
        <div id="paneItemList" class="inv-tab-pane card p-3 mb-4 d-none">
            <div class="table-responsive">
            <table class="table table-bordered table-striped text-center align-middle mb-0">
                <thead class="table-dark">
                    <tr><th>ID</th><th>Code</th><th>Name</th><th>Category</th><th>Sub Category</th><th>Unit</th><th>Cost</th><th>Sale Price</th><th>Stock</th></tr>
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
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="9" class="text-muted">কোনো Item নেই</td></tr>
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
</script>
