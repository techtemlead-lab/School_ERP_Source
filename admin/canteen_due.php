<?php
// admin/canteen_due.php - Canteen > Due Collection
require_once __DIR__ . '/canteen_common.php';

$is_super_admin = $_SESSION['is_super_admin'] ?? true;
$user_role      = $_SESSION['user_role'] ?? '';
if (!$is_super_admin && !in_array($user_role, ['Admin', 'Canteen Staff'], true)) {
    $_SESSION['canteen_err'] = 'ক্যানটিন Due Collection ব্যবহারের অনুমতি নেই।';
    header('Location: index.php?page=dashboard');
    exit();
}

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $err = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (isset($_POST['collect_due'])) {
        $sale_id = (int) ($_POST['sale_id'] ?? 0);
        $amount  = (float) ($_POST['collect_amount'] ?? 0);

        $row = $pdo->prepare("SELECT due_amount FROM canteen_sales WHERE id = ?");
        $row->execute([$sale_id]);
        $due_now = (float) ($row->fetchColumn() ?: 0);

        if ($sale_id > 0 && $amount > 0 && $amount <= $due_now) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO canteen_sale_payments (sale_id, amount) VALUES (?, ?)")->execute([$sale_id, $amount]);
                $pdo->prepare("UPDATE canteen_sales SET paid_amount = paid_amount + ?, due_amount = due_amount - ? WHERE id = ?")
                    ->execute([$amount, $amount, $sale_id]);
                $pdo->commit();
                sec_log_activity($pdo, 'Canteen', 'Update', "Canteen Due Payment জমা হয়েছে: Sale #{$sale_id}, ৳{$amount}");
                $msg = 'Due Payment জমা হয়েছে।';
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Canteen collect due failed: ' . $e->getMessage());
                $err = 'Payment ব্যর্থ হয়েছে।';
            }
        } else {
            $err = 'সঠিক Amount দিন (Due এর বেশি না)।';
        }
    }
}

$cdue_where = ['due_amount > 0']; $cdue_params = [];
branch_apply_filter($cdue_where, $cdue_params);
$cdue_stmt = $pdo->prepare("SELECT * FROM canteen_sales WHERE " . implode(' AND ', $cdue_where) . " ORDER BY id DESC");
$cdue_stmt->execute($cdue_params);
$dues = $cdue_stmt->fetchAll();
?>

<div class="card-custom">
    <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-hand-holding-dollar"></i> Canteen &raquo; Due Collection</h4>
    <p class="text-muted mb-4" style="font-size:13px;">এই Due Fee/Finance ledger থেকে সম্পূর্ণ আলাদা — শুধু ক্যানটিনের বাকি।</p>

    <?php if ($msg): ?><div class="alert alert-success py-2"><?php echo cv($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?php echo cv($err); ?></div><?php endif; ?>

    <div class="table-responsive">
    <table class="table table-bordered text-center align-middle">
        <thead class="table-dark"><tr><th>Invoice No</th><th>Buyer</th><th>Type</th><th>Total</th><th>Paid</th><th>Due</th><th>Collect Payment</th></tr></thead>
        <tbody>
            <?php if ($dues): foreach ($dues as $d): $csrf = cv(csrf_token());
                $badge = ['student' => 'bg-info', 'employee' => 'bg-primary', 'walkin' => 'bg-secondary'][$d['buyer_type']] ?? 'bg-secondary';
                $label = ['student' => 'Student', 'employee' => 'Employee', 'walkin' => 'Walk-in'][$d['buyer_type']] ?? 'Walk-in';
            ?>
                <tr>
                    <td><?php echo cv($d['invoice_no']); ?></td>
                    <td><?php echo cv($d['customer_name']); ?></td>
                    <td><span class="badge <?php echo $badge; ?>"><?php echo $label; ?></span></td>
                    <td>৳<?php echo number_format($d['total_amount'], 2); ?></td>
                    <td>৳<?php echo number_format($d['paid_amount'], 2); ?></td>
                    <td class="text-danger fw-bold">৳<?php echo number_format($d['due_amount'], 2); ?></td>
                    <td>
                        <form method="POST" class="d-flex gap-1">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="sale_id" value="<?php echo (int) $d['id']; ?>">
                            <input type="number" step="0.01" min="0.01" max="<?php echo (float) $d['due_amount']; ?>" name="collect_amount" class="form-control form-control-sm" placeholder="Amount" required>
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
