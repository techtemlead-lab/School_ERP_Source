<?php
// admin/canteen_stock_adjustment.php - Canteen > Stock Adjustment (নষ্ট/হিসাব মেলানো)
require_once __DIR__ . '/canteen_common.php';
canteen_require_manage();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $err = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (isset($_POST['adjust_stock'])) {
        $material_id = (int) ($_POST['raw_material_id'] ?? 0);
        $adj_type    = ($_POST['adj_type'] ?? 'remove') === 'add' ? 'add' : 'remove';
        $qty         = (float) ($_POST['qty'] ?? 0);
        $reason      = trim($_POST['reason'] ?? '');

        if ($material_id <= 0 || $qty <= 0) {
            $err = 'কাঁচামাল ও সঠিক পরিমাণ দিন।';
        } else {
            $pdo->beginTransaction();
            try {
                $mat = canteen_lock_row($pdo, 'canteen_raw_materials', $material_id, 'name, current_stock');
                if (!$mat) { throw new RuntimeException('কাঁচামাল পাওয়া যায়নি (অথবা অন্য branch-এর)।'); }
                if ($adj_type === 'remove' && $qty > (float) $mat['current_stock'] + 0.0001) {
                    throw new RuntimeException($mat['name'] . ' এর বর্তমান স্টক ' . (float) $mat['current_stock'] . ' — এর বেশি Remove করা যাবে না।');
                }
                $pdo->prepare("INSERT INTO canteen_stock_adjustments (raw_material_id, adj_type, qty, reason, branch_id) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$material_id, $adj_type, $qty, $reason ?: null, branch_id_for_insert()]);

                if ($adj_type === 'add') {
                    $pdo->prepare("UPDATE canteen_raw_materials SET current_stock = current_stock + ? WHERE id = ?")->execute([$qty, $material_id]);
                } else {
                    $pdo->prepare("UPDATE canteen_raw_materials SET current_stock = current_stock - ? WHERE id = ?")->execute([$qty, $material_id]);
                }
                $pdo->commit();
                sec_log_activity($pdo, 'Canteen', 'Update', "Canteen Stock Adjustment করা হয়েছে: {$mat['name']} ({$adj_type}, {$qty})" . ($reason ? " — {$reason}" : ''));
                $msg = 'Stock Adjustment সফল হয়েছে।';
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $err = $e->getMessage();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('Canteen stock adjustment failed: ' . $e->getMessage());
                $err = 'ব্যর্থ হয়েছে, আবার চেষ্টা করুন।';
            }
        }
    }
}

$csa_where = ["status = 'Active'"]; $csa_params = [];
branch_apply_filter($csa_where, $csa_params);
$csa_stmt = $pdo->prepare("SELECT id, name FROM canteen_raw_materials WHERE " . implode(' AND ', $csa_where) . " ORDER BY name ASC");
$csa_stmt->execute($csa_params);
$materials = $csa_stmt->fetchAll();

$csa2_where = []; $csa2_params = [];
branch_apply_filter($csa2_where, $csa2_params, 'm.branch_id');
$csa2_where_sql = $csa2_where ? (' WHERE ' . implode(' AND ', $csa2_where)) : '';
$csa2_stmt = $pdo->prepare(
    "SELECT a.*, m.name AS material_name, u.name AS unit_name
     FROM canteen_stock_adjustments a
     JOIN canteen_raw_materials m ON m.id = a.raw_material_id
     LEFT JOIN canteen_units u ON u.id = m.unit_id
     $csa2_where_sql
     ORDER BY a.id DESC LIMIT 100"
);
$csa2_stmt->execute($csa2_params);
$history = $csa2_stmt->fetchAll();
?>

<div class="card-custom">
    <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-sliders"></i> Canteen &raquo; Stock Adjustment</h4>
    <p class="text-muted mb-4" style="font-size:13px;">নষ্ট/অপচয়/হিসাবের গরমিল ঠিক করতে ম্যানুয়ালি Stock বাড়ানো বা কমানো।</p>

    <?php if ($msg): ?><div class="alert alert-success py-2"><?php echo cv($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?php echo cv($err); ?></div><?php endif; ?>

    <form method="POST" class="card p-3 bg-light mb-4 row g-2 align-items-end">
        <?php echo csrf_field(); ?>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Raw Material</label>
            <select name="raw_material_id" class="form-select" required>
                <option value="">-- বেছে নিন --</option>
                <?php foreach ($materials as $m): ?><option value="<?php echo (int) $m['id']; ?>"><?php echo cv($m['name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold">Type</label>
            <select name="adj_type" class="form-select">
                <option value="add">Add (বাড়ানো)</option>
                <option value="remove">Remove (কমানো/নষ্ট)</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold">Quantity</label>
            <input type="number" step="0.001" min="0.001" name="qty" class="form-control" required>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Reason</label>
            <input type="text" name="reason" class="form-control" placeholder="যেমন: নষ্ট হয়ে গেছে">
        </div>
        <div class="col-md-2">
            <button type="submit" name="adjust_stock" class="btn btn-primary fw-bold w-100" style="background-color:#1e3a8a;">Submit</button>
        </div>
    </form>

    <h6 class="fw-bold mb-3">Adjustment History</h6>
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-dark"><tr><th>Raw Material</th><th>Type</th><th>Qty</th><th>Reason</th><th>Date</th></tr></thead>
            <tbody>
                <?php if ($history): foreach ($history as $h): ?>
                    <tr>
                        <td><?php echo cv($h['material_name']); ?></td>
                        <td><?php echo $h['adj_type'] === 'add' ? '<span class="badge bg-success">Add</span>' : ($h['adj_type'] === 'opening' ? '<span class="badge bg-secondary">Opening</span>' : '<span class="badge bg-danger">Remove</span>'); ?></td>
                        <td><?php echo number_format((float) $h['qty'], 3); ?> <?php echo cv($h['unit_name'] ?: ''); ?></td>
                        <td><?php echo cv($h['reason'] ?: '-'); ?></td>
                        <td class="small text-muted"><?php echo cv($h['created_at']); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">কোনো Adjustment নেই</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
