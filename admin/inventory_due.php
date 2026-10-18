<?php
// admin/inventory_due.php - Inventory & Sales > Due Collection
require_once __DIR__ . '/inventory_common.php';

$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error_msg = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (isset($_POST['collect_due'])) {
        $sale_id = (int) ($_POST['sale_id'] ?? 0);
        $amount  = (float) ($_POST['collect_amount'] ?? 0);

        if ($sale_id > 0 && $amount > 0) {
            $pdo->beginTransaction();
            try {
                // রো লক + branch চেক — ডাবল সাবমিটে overpay ঠেকাতে due এখানেই পড়া হয়
                $due_row = inv_lock_row($pdo, 'sales_invoices', $sale_id, 'due_amount, invoice_no, customer_name');
                $due_now = (float) ($due_row['due_amount'] ?? 0);
                if (!$due_row || $amount > $due_now + 0.001) {
                    $pdo->rollBack();
                    $error_msg = "সঠিক Amount দিন (Due এর বেশি না)।";
                } else {
                    $pdo->prepare("INSERT INTO inv_sale_payments (sale_id, amount) VALUES (?, ?)")->execute([$sale_id, $amount]);
                    $pdo->prepare("UPDATE sales_invoices SET paid_amount = paid_amount + ?, due_amount = GREATEST(0, due_amount - ?) WHERE id = ?")
                        ->execute([$amount, $amount, $sale_id]);
                    $pdo->commit();
                    sec_log_activity($pdo, 'Inventory', 'Update', "Due Payment জমা হয়েছে: Invoice #{$due_row['invoice_no']} ({$due_row['customer_name']}), পরিমাণ: ৳{$amount}");
                    $success_msg = "Due Payment জমা হয়েছে।";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('Collect due failed: ' . $e->getMessage());
                $error_msg = "Payment ব্যর্থ হয়েছে।";
            }
        } else {
            $error_msg = "সঠিক Amount দিন (Due এর বেশি না)।";
        }
    }
}

$idue_where = ['due_amount > 0']; $idue_params = [];
branch_apply_filter($idue_where, $idue_params);
$idue_stmt = $pdo->prepare("SELECT * FROM sales_invoices WHERE " . implode(' AND ', $idue_where) . " ORDER BY id DESC");
$idue_stmt->execute($idue_params);
$dues = $idue_stmt->fetchAll();
?>

<div class="card-custom">
    <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-hand-holding-dollar"></i> Inventory & Sales &raquo; Due Collection</h4>
    <p class="text-muted mb-4" style="font-size:13px;">Customer/Student বাকি টাকা কিস্তিতে জমা দিতে পারবে।</p>

    <?php if ($success_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($success_msg); ?></div><?php endif; ?>
    <?php if ($error_msg): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>

    <div id="dueTabGroup">
        <div class="inv-tab-boxes">
            <button type="button" class="inv-tab-box active" onclick="invTabSwitch('dueTabGroup','paneDueList',this)">Due List</button>
        </div>

        <div id="paneDueList" class="inv-tab-pane card p-3 mb-4">
            <div class="table-responsive">
            <table class="table table-bordered text-center align-middle">
                <thead class="table-dark"><tr><th>Invoice No</th><th>Buyer</th><th>Type</th><th>Total</th><th>Paid</th><th>Due</th><th>Collect Payment</th></tr></thead>
                <tbody>
                    <?php if ($dues): foreach ($dues as $d):
                        $type_badge = $d['buyer_type'] === 'student' ? '<span class="badge bg-info">Student</span>' : '<span class="badge bg-secondary">Walk-in</span>';
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($d['invoice_no']); ?></td>
                            <td><?php echo htmlspecialchars($d['customer_name']); ?></td>
                            <td><?php echo $type_badge; ?></td>
                            <td>৳<?php echo number_format($d['total_amount'],2); ?></td>
                            <td>৳<?php echo number_format($d['paid_amount'],2); ?></td>
                            <td class="text-danger fw-bold">৳<?php echo number_format($d['due_amount'],2); ?></td>
                            <td>
                                <form method="POST" class="d-flex gap-1">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="sale_id" value="<?php echo (int)$d['id']; ?>">
                                    <input type="number" step="0.01" min="0.01" max="<?php echo (float)$d['due_amount']; ?>" name="collect_amount" class="form-control form-control-sm" placeholder="Amount" required>
                                    <button type="submit" name="collect_due" class="btn btn-sm btn-success">জমা</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" class="text-success">কোনো Due নেই!</td></tr>
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
