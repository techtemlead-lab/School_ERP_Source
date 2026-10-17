<?php
// admin/inventory_supplier.php - Inventory & Sales > Supplier Payment
require_once __DIR__ . '/inventory_common.php';

$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error_msg = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (isset($_POST['supplier_pay'])) {
        $purchase_id = (int) ($_POST['purchase_id'] ?? 0);
        $amount = (float) ($_POST['pay_amount'] ?? 0);

        if ($purchase_id > 0 && $amount > 0) {
            $pdo->beginTransaction();
            try {
                $due_row = inv_lock_row($pdo, 'inv_purchases', $purchase_id, 'due_amount, purchase_no, supplier_name');
                $due_now = (float) ($due_row['due_amount'] ?? 0);
                if (!$due_row || $amount > $due_now + 0.001) {
                    $pdo->rollBack();
                    $error_msg = "সঠিক Amount দিন (Due এর বেশি না)।";
                } else {
                    $pdo->prepare("INSERT INTO inv_supplier_payments (purchase_id, amount) VALUES (?, ?)")->execute([$purchase_id, $amount]);
                    $pdo->prepare("UPDATE inv_purchases SET paid_amount = paid_amount + ?, due_amount = GREATEST(0, due_amount - ?) WHERE id = ?")
                        ->execute([$amount, $amount, $purchase_id]);
                    $pdo->commit();
                    sec_log_activity($pdo, 'Inventory', 'Update', "Supplier Payment জমা হয়েছে: {$due_row['purchase_no']} ({$due_row['supplier_name']}), পরিমাণ: ৳{$amount}");
                    $success_msg = "Supplier Payment জমা হয়েছে।";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('Supplier pay failed: ' . $e->getMessage());
                $error_msg = "Payment ব্যর্থ হয়েছে।";
            }
        } else {
            $error_msg = "সঠিক Amount দিন (Due এর বেশি না)।";
        }
    }
}

$sd_where = ['due_amount > 0']; $sd_params = [];
branch_apply_filter($sd_where, $sd_params);
$sd_stmt = $pdo->prepare("SELECT * FROM inv_purchases WHERE " . implode(' AND ', $sd_where) . " ORDER BY id DESC");
$sd_stmt->execute($sd_params);
$sup_dues = $sd_stmt->fetchAll();
?>

<div class="card-custom">
    <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-truck-fast"></i> Inventory & Sales &raquo; Supplier Payment</h4>
    <p class="text-muted mb-4" style="font-size:13px;">Supplier-কে কিস্তিতে বাকি টাকা পরিশোধ করুন।</p>

    <?php if ($success_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($success_msg); ?></div><?php endif; ?>
    <?php if ($error_msg): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>

    <div id="supplierTabGroup">
        <div class="inv-tab-boxes">
            <button type="button" class="inv-tab-box active" onclick="invTabSwitch('supplierTabGroup','paneSupplierDue',this)">Supplier Due List</button>
        </div>

        <div id="paneSupplierDue" class="inv-tab-pane card p-3 mb-4">
            <div class="table-responsive">
            <table class="table table-bordered text-center align-middle">
                <thead class="table-dark"><tr><th>Purchase No</th><th>Supplier</th><th>Total</th><th>Paid</th><th>Due</th><th>Pay Now</th></tr></thead>
                <tbody>
                    <?php if ($sup_dues): foreach ($sup_dues as $s): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($s['purchase_no']); ?></td>
                            <td><?php echo htmlspecialchars($s['supplier_name']); ?></td>
                            <td>৳<?php echo number_format($s['total_amount'],2); ?></td>
                            <td>৳<?php echo number_format($s['paid_amount'],2); ?></td>
                            <td class="text-danger fw-bold">৳<?php echo number_format($s['due_amount'],2); ?></td>
                            <td>
                                <form method="POST" class="d-flex gap-1">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="purchase_id" value="<?php echo (int)$s['id']; ?>">
                                    <input type="number" step="0.01" min="0.01" max="<?php echo (float)$s['due_amount']; ?>" name="pay_amount" class="form-control form-control-sm" placeholder="Amount" required>
                                    <button type="submit" name="supplier_pay" class="btn btn-sm btn-success">Pay</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" class="text-success">কোনো Supplier Due নেই!</td></tr>
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

function invTabSwitch(groupId, targetId, btn) {
    const group = document.getElementById(groupId);
    group.querySelectorAll('.inv-tab-pane').forEach(p => p.classList.add('d-none'));
    document.getElementById(targetId).classList.remove('d-none');
    group.querySelectorAll('.inv-tab-box').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}
</script>
