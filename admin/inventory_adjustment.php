<?php
// admin/inventory_adjustment.php - Inventory & Sales > Stock Adjustment
require_once __DIR__ . '/inventory_common.php';

$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error_msg = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (isset($_POST['stock_adjust'])) {
        $item_id = (int) ($_POST['adj_item_id'] ?? 0);
        $type    = $_POST['adj_type'] ?? '';
        $qty     = (int) ($_POST['adj_qty'] ?? 0);
        $reason  = trim($_POST['adj_reason'] ?? '');

        $valid_types = ['Damage', 'Lost', 'Found', 'Correction'];
        if ($item_id > 0 && $qty > 0 && in_array($type, $valid_types, true)) {
            $pdo->beginTransaction();
            try {
                if (in_array($type, ['Damage', 'Lost'], true)) {
                    $pdo->prepare("UPDATE inventory_items SET stock_qty = GREATEST(0, stock_qty - ?) WHERE id = ?")->execute([$qty, $item_id]);
                } else {
                    $pdo->prepare("UPDATE inventory_items SET stock_qty = stock_qty + ? WHERE id = ?")->execute([$qty, $item_id]);
                }
                $pdo->prepare("INSERT INTO inv_stock_adjustments (item_id, adj_type, qty, reason, branch_id) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$item_id, $type, $qty, $reason ?: null, branch_id_for_insert()]);
                $pdo->commit();
                $adj_item_name = '';
                foreach ($all_items as $ai) { if ((int)$ai['id'] === $item_id) { $adj_item_name = $ai['name']; break; } }
                sec_log_activity($pdo, 'Inventory', 'Update', "Stock Adjustment ({$type}): {$adj_item_name}, পরিমাণ: {$qty}");
                $success_msg = "Stock Adjustment সম্পন্ন হয়েছে।";
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Stock adjust failed: ' . $e->getMessage());
                $error_msg = "Adjustment ব্যর্থ হয়েছে।";
            }
        } else {
            $error_msg = "সঠিকভাবে সব ফিল্ড পূরণ করুন!";
        }
    }
}

$ia_where = []; $ia_params = [];
branch_apply_filter($ia_where, $ia_params, 'i.branch_id');
$ia_where_sql = $ia_where ? (' WHERE ' . implode(' AND ', $ia_where)) : '';

$iai_where = []; $iai_params = [];
branch_apply_filter($iai_where, $iai_params);
$iai_where_sql = $iai_where ? (' WHERE ' . implode(' AND ', $iai_where)) : '';
$iai_stmt = $pdo->prepare("SELECT id, name, stock_qty FROM inventory_items$iai_where_sql ORDER BY name");
$iai_stmt->execute($iai_params);
$all_items = $iai_stmt->fetchAll();

$adj_stmt = $pdo->prepare(
    "SELECT a.*, i.name AS item_name FROM inv_stock_adjustments a LEFT JOIN inventory_items i ON i.id = a.item_id$ia_where_sql ORDER BY a.id DESC LIMIT 100"
);
$adj_stmt->execute($ia_params);
$adjs = $adj_stmt->fetchAll();
?>

<div class="card-custom">
    <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-boxes-stacked"></i> Inventory & Sales &raquo; Stock Adjustment</h4>
    <p class="text-muted mb-4" style="font-size:13px;">নষ্ট/হারানো/পাওয়া গেছে স্টক এন্ট্রি করুন।</p>

    <?php if ($success_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($success_msg); ?></div><?php endif; ?>
    <?php if ($error_msg): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>

    <div id="adjustTabGroup">
        <div class="inv-tab-boxes">
            <button type="button" class="inv-tab-box active" onclick="invTabSwitch('adjustTabGroup','paneNewAdjust',this)">New Adjustment</button>
            <button type="button" class="inv-tab-box" onclick="invTabSwitch('adjustTabGroup','paneAdjustHistory',this)">Adjustment History</button>
        </div>

        <div id="paneNewAdjust" class="inv-tab-pane card p-4 bg-light mb-4">
            <form method="POST" class="row g-2">
                <?php echo csrf_field(); ?>
                <div class="col-md-4">
                    <select name="adj_item_id" class="form-select" required>
                        <option value="">Item বেছে নিন...</option>
                        <?php foreach ($all_items as $i): ?><option value="<?php echo (int)$i['id']; ?>"><?php echo htmlspecialchars($i['name']); ?> (Stock: <?php echo (int)$i['stock_qty']; ?>)</option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="adj_type" class="form-select" required>
                        <option value="Damage">Damage (কমবে)</option>
                        <option value="Lost">Lost (কমবে)</option>
                        <option value="Found">Found (বাড়বে)</option>
                        <option value="Correction">Correction (বাড়বে)</option>
                    </select>
                </div>
                <div class="col-md-2"><input type="number" min="1" name="adj_qty" class="form-control" placeholder="Qty" required></div>
                <div class="col-md-3"><input type="text" name="adj_reason" class="form-control" placeholder="কারণ (optional)"></div>
                <div class="col-12"><button type="submit" name="stock_adjust" class="btn btn-warning">Adjust করুন</button></div>
            </form>
        </div>

        <div id="paneAdjustHistory" class="inv-tab-pane card p-3 mb-4 d-none">
            <h5 class="fw-bold mb-3">Adjustment History</h5>
            <div class="table-responsive">
            <table class="table table-bordered text-center align-middle">
                <thead class="table-dark"><tr><th>Item</th><th>Type</th><th>Qty</th><th>Reason</th><th>Date</th></tr></thead>
                <tbody>
                    <?php if ($adjs): foreach ($adjs as $a): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($a['item_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($a['adj_type']); ?></td>
                            <td><?php echo (int)$a['qty']; ?></td>
                            <td><?php echo htmlspecialchars($a['reason'] ?? '-'); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars($a['created_at']); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5" class="text-muted">কোনো Adjustment নেই</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
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

function invTabSwitch(groupId, targetId, btn) {
    const group = document.getElementById(groupId);
    group.querySelectorAll('.inv-tab-pane').forEach(p => p.classList.add('d-none'));
    document.getElementById(targetId).classList.remove('d-none');
    group.querySelectorAll('.inv-tab-box').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}
</script>
</div>
