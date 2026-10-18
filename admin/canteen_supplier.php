<?php
// admin/canteen_supplier.php - Canteen > Supplier Payment (Purchase-এর বাকি টাকা কিস্তিতে পরিশোধ)
require_once __DIR__ . '/canteen_common.php';
canteen_require_manage();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $err = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (isset($_POST['supplier_pay'])) {
        $purchase_id = (int) ($_POST['purchase_id'] ?? 0);
        $amount = (float) ($_POST['pay_amount'] ?? 0);

        if ($purchase_id > 0 && $amount > 0) {
            $pdo->beginTransaction();
            try {
                // রো লক + branch চেক — ডাবল সাবমিটে overpay ঠেকাতে due এখানেই পড়া হয়
                $row = canteen_lock_row($pdo, 'canteen_purchases', $purchase_id, 'due_amount, purchase_no, supplier_name');
                if (!$row || $amount > (float) $row['due_amount'] + 0.001) {
                    $pdo->rollBack();
                    $err = 'সঠিক Amount দিন (Due এর বেশি না)।';
                } else {
                    $pdo->prepare("INSERT INTO canteen_supplier_payments (purchase_id, amount, note) VALUES (?, ?, 'Installment')")->execute([$purchase_id, $amount]);
                    $pdo->prepare("UPDATE canteen_purchases SET paid_amount = paid_amount + ?, due_amount = GREATEST(0, due_amount - ?) WHERE id = ?")
                        ->execute([$amount, $amount, $purchase_id]);
                    $pdo->commit();
                    sec_log_activity($pdo, 'Canteen', 'Update', "Canteen Supplier Payment: {$row['purchase_no']} ({$row['supplier_name']}), ৳{$amount}");
                    $msg = 'Supplier Payment জমা হয়েছে।';
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('Canteen supplier pay failed: ' . $e->getMessage());
                $err = 'Payment ব্যর্থ হয়েছে।';
            }
        } else {
            $err = 'সঠিক Amount দিন (Due এর বেশি না)।';
        }
    }
}

$sd_where = ['due_amount > 0']; $sd_params = [];
branch_apply_filter($sd_where, $sd_params);
$sd_stmt = $pdo->prepare("SELECT * FROM canteen_purchases WHERE " . implode(' AND ', $sd_where) . " ORDER BY id DESC");
$sd_stmt->execute($sd_params);
$sup_dues = $sd_stmt->fetchAll();

$ph_where = []; $ph_params = [];
branch_apply_filter($ph_where, $ph_params, 'p.branch_id');
$ph_stmt = $pdo->prepare(
    "SELECT sp.*, p.purchase_no, p.supplier_name FROM canteen_supplier_payments sp
     JOIN canteen_purchases p ON p.id = sp.purchase_id" . ($ph_where ? ' WHERE ' . implode(' AND ', $ph_where) : '') . " ORDER BY sp.id DESC LIMIT 100"
);
$ph_stmt->execute($ph_params);
$payments = $ph_stmt->fetchAll();
?>

<div class="card-custom">
    <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-truck-fast"></i> Canteen &raquo; Supplier Payment</h4>
    <p class="text-muted mb-4" style="font-size:13px;">কাঁচামাল Purchase-এর বাকি টাকা Supplier-কে কিস্তিতে পরিশোধ করুন।</p>

    <?php if ($msg): ?><div class="alert alert-success py-2"><?php echo cv($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?php echo cv($err); ?></div><?php endif; ?>

    <h6 class="fw-bold mb-2">Supplier Due List</h6>
    <div class="table-responsive mb-4">
    <table class="table table-bordered text-center align-middle">
        <thead class="table-dark"><tr><th>Purchase No</th><th>Supplier</th><th>Total</th><th>Paid</th><th>Due</th><th>Pay Now</th></tr></thead>
        <tbody>
            <?php if ($sup_dues): foreach ($sup_dues as $s): ?>
                <tr>
                    <td><?php echo cv($s['purchase_no']); ?></td>
                    <td><?php echo cv($s['supplier_name']); ?></td>
                    <td>৳<?php echo number_format($s['total_amount'], 2); ?></td>
                    <td>৳<?php echo number_format($s['paid_amount'], 2); ?></td>
                    <td class="text-danger fw-bold">৳<?php echo number_format($s['due_amount'], 2); ?></td>
                    <td>
                        <form method="POST" class="d-flex gap-1">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="purchase_id" value="<?php echo (int) $s['id']; ?>">
                            <input type="number" step="0.01" min="0.01" max="<?php echo (float) $s['due_amount']; ?>" name="pay_amount" class="form-control form-control-sm" placeholder="Amount" required>
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

    <h6 class="fw-bold mb-2">Payment History (সর্বশেষ ১০০)</h6>
    <div class="table-responsive">
    <table class="table table-bordered text-center align-middle">
        <thead class="table-light"><tr><th>Purchase No</th><th>Supplier</th><th>Amount</th><th>ধরন</th><th>তারিখ</th></tr></thead>
        <tbody>
            <?php if ($payments): foreach ($payments as $pm): ?>
                <tr>
                    <td><?php echo cv($pm['purchase_no']); ?></td>
                    <td><?php echo cv($pm['supplier_name']); ?></td>
                    <td>৳<?php echo number_format((float) $pm['amount'], 2); ?></td>
                    <td><?php echo cv($pm['note'] ?: 'Installment'); ?></td>
                    <td class="small text-muted"><?php echo cv($pm['paid_at']); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5" class="text-muted">কোনো পেমেন্ট নেই</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
