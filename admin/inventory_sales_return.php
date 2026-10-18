<?php
// admin/inventory_sales_return.php - Inventory & Sales > Sales Return
require_once __DIR__ . '/inventory_common.php';

$success_msg = '';
$error_msg   = '';

// ---------- Return সাবমিট ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error_msg = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (isset($_POST['return_submit'])) {
        $sale_id = (int) ($_POST['sale_id'] ?? 0);
        $reason  = trim($_POST['reason'] ?? '');
        $item_ids = $_POST['r_item_id'] ?? [];
        $return_qtys = $_POST['r_return_qty'] ?? [];

        $want = inv_merge_lines($item_ids, $return_qtys);
        if (!$want) {
            $error_msg = "কমপক্ষে একটা Item-এর Return Qty দিন!";
        } else {
            $pdo->beginTransaction();
            try {
                // Invoice লক + branch চেক
                $sale = inv_lock_row($pdo, 'sales_invoices', $sale_id);
                if (!$sale) { throw new RuntimeException("এই Invoice পাওয়া যায়নি!"); }

                // Invoice-এর মোট (ডিসকাউন্টের আগে) — ডিসকাউন্ট আনুপাতিকভাবে ফেরত মূল্য থেকে বাদ যাবে
                $subSt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM inv_sale_items WHERE sale_id = ?");
                $subSt->execute([$sale_id]);
                $sub_total = (float) $subSt->fetchColumn();
                $ratio = $sub_total > 0 ? max(0, ($sub_total - (float) $sale['discount_amount']) / $sub_total) : 1;

                $lines = [];
                $return_total = 0.0;   // নিট (ডিসকাউন্ট বাদে)
                $gross_total  = 0.0;   // গ্রস (সেল রেটে)
                foreach ($want as $iid => $ret_qty) {
                    // একই Item একাধিক লাইনে থাকলেও যোগফল ও গড় rate ধরা হয়
                    $li = $pdo->prepare("SELECT COALESCE(SUM(qty),0) AS qty, COALESCE(SUM(amount),0) AS amount FROM inv_sale_items WHERE sale_id = ? AND item_id = ?");
                    $li->execute([$sale_id, $iid]);
                    $line = $li->fetch();
                    if (!$line || (int) $line['qty'] <= 0) { throw new RuntimeException("এই Invoice-এ এমন Item নেই।"); }

                    $rt = $pdo->prepare("SELECT COALESCE(SUM(sri.qty),0) FROM inv_sale_return_items sri JOIN inv_sale_returns sr ON sr.id = sri.return_id WHERE sr.sale_id = ? AND sri.item_id = ?");
                    $rt->execute([$sale_id, $iid]);
                    $already_returned = (int) $rt->fetchColumn();

                    $max_returnable = (int) $line['qty'] - $already_returned;
                    if ($ret_qty > $max_returnable) {
                        throw new RuntimeException("সর্বোচ্চ $max_returnable পিস ফেরত দেওয়া যাবে (ইতিমধ্যে $already_returned পিস ফেরত হয়ে গেছে)।");
                    }

                    $rate = (float) $line['amount'] / (int) $line['qty'];
                    $gross = $rate * $ret_qty;
                    $lines[] = ['item_id' => $iid, 'qty' => $ret_qty, 'rate' => $rate, 'amount' => $gross];
                    $gross_total += $gross;
                    $return_total += $gross * $ratio;
                }
                $return_total = round($return_total, 2);

                // টাকা ফেরত: নতুন total এর চেয়ে বেশি জমা থাকলে বাড়তিটা ক্যাশ ফেরত যাবে
                $new_total   = max(0, (float) $sale['total_amount'] - $return_total);
                $refund_owed = max(0, (float) $sale['paid_amount'] - $new_total);
                $new_paid    = (float) $sale['paid_amount'] - $refund_owed;
                $new_due     = max(0, $new_total - $new_paid);

                $return_no = inv_unique_no($pdo, 'RET', 'inv_sale_returns', 'return_no');
                $ins = $pdo->prepare("INSERT INTO inv_sale_returns (sale_id, return_no, return_amount, refund_amount, reason) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$sale_id, $return_no, $return_total, $refund_owed, $reason ?: null]);
                $return_id = (int) $pdo->lastInsertId();

                $insItem = $pdo->prepare("INSERT INTO inv_sale_return_items (return_id, item_id, qty, rate, amount) VALUES (?, ?, ?, ?, ?)");
                $updStock = $pdo->prepare("UPDATE inventory_items SET stock_qty = stock_qty + ? WHERE id = ?");
                foreach ($lines as $ln) {
                    $insItem->execute([$return_id, $ln['item_id'], $ln['qty'], $ln['rate'], $ln['amount']]);
                    $updStock->execute([$ln['qty'], $ln['item_id']]);
                }

                $pdo->prepare("UPDATE sales_invoices SET total_amount = ?, paid_amount = ?, due_amount = ? WHERE id = ?")
                    ->execute([$new_total, $new_paid, $new_due, $sale_id]);

                $pdo->commit();
                sec_log_activity($pdo, 'Inventory', 'Create', "Sale Return তৈরি করা হয়েছে: {$return_no} (Invoice: {$sale['invoice_no']}), ফেরত মূল্য: ৳" . number_format($return_total, 2) . ", নগদ ফেরত: ৳" . number_format($refund_owed, 2));

                $success_msg = "Return সম্পন্ন হয়েছে! Return No: $return_no, ফেরত মূল্য: ৳" . number_format($return_total, 2);
                if ($refund_owed > 0) {
                    $success_msg .= " — কাস্টমারকে নগদ ৳" . number_format($refund_owed, 2) . " ফেরত দিতে হবে (রেকর্ড করা হয়েছে)।";
                }
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error_msg = $e->getMessage();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('Sale return failed: ' . $e->getMessage());
                $error_msg = "Return ব্যর্থ হয়েছে, আবার চেষ্টা করুন।";
            }
        }
    }
}

// ---------- Invoice সার্চ ----------
$search_invoice_no = trim($_GET['invoice_no'] ?? '');
$found_sale = null;
$found_lines = [];

if ($search_invoice_no !== '') {
    $isr_where = ['invoice_no = ?']; $isr_params = [$search_invoice_no];
    branch_apply_filter($isr_where, $isr_params);
    $stmt = $pdo->prepare("SELECT * FROM sales_invoices WHERE " . implode(' AND ', $isr_where));
    $stmt->execute($isr_params);
    $found_sale = $stmt->fetch();

    if ($found_sale) {
        $li = $pdo->prepare(
            "SELECT si.item_id, SUM(si.qty) AS qty, SUM(si.amount) / SUM(si.qty) AS rate, i.name AS item_name
             FROM inv_sale_items si LEFT JOIN inventory_items i ON i.id = si.item_id
             WHERE si.sale_id = ? GROUP BY si.item_id, i.name"
        );
        $li->execute([$found_sale['id']]);
        $raw_lines = $li->fetchAll();

        foreach ($raw_lines as $rl) {
            $rt = $pdo->prepare("SELECT COALESCE(SUM(sri.qty),0) FROM inv_sale_return_items sri JOIN inv_sale_returns sr ON sr.id = sri.return_id WHERE sr.sale_id = ? AND sri.item_id = ?");
            $rt->execute([$found_sale['id'], $rl['item_id']]);
            $already_returned = (int) $rt->fetchColumn();
            $rl['already_returned'] = $already_returned;
            $rl['max_returnable'] = (int) $rl['qty'] - $already_returned;
            $found_lines[] = $rl;
        }
    }
}

$rh_where = []; $rh_params = [];
branch_apply_filter($rh_where, $rh_params, 's.branch_id');
$rh_stmt = $pdo->prepare(
    "SELECT r.*, s.invoice_no, s.customer_name FROM inv_sale_returns r
     LEFT JOIN sales_invoices s ON s.id = r.sale_id" . ($rh_where ? ' WHERE ' . implode(' AND ', $rh_where) : '') . " ORDER BY r.id DESC LIMIT 100"
);
$rh_stmt->execute($rh_params);
$returns = $rh_stmt->fetchAll();
?>

<div class="card-custom">
    <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-rotate-left"></i> Inventory & Sales &raquo; Sales Return</h4>
    <p class="text-muted mb-4" style="font-size:13px;">বিক্রি হওয়া Item ফেরত নিন — Stock স্বয়ংক্রিয়ভাবে ফিরে যোগ হবে।</p>

    <?php if ($success_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($success_msg); ?></div><?php endif; ?>
    <?php if ($error_msg): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>

    <div id="returnTabGroup">
        <div class="inv-tab-boxes">
            <button type="button" class="inv-tab-box active" onclick="invTabSwitch('returnTabGroup','paneNewReturn',this)">New Return</button>
            <button type="button" class="inv-tab-box" onclick="invTabSwitch('returnTabGroup','paneReturnHistory',this)">Return History</button>
        </div>

        <div id="paneNewReturn" class="inv-tab-pane card p-4 bg-light mb-4">
            <form method="GET" class="row g-2 mb-3">
                <input type="hidden" name="page" value="inventory_sales_return">
                <div class="col-md-8"><input type="text" name="invoice_no" value="<?php echo htmlspecialchars($search_invoice_no); ?>" class="form-control" placeholder="Invoice No লিখুন (যেমন: INV-20260907-1234)" required></div>
                <div class="col-md-4"><button type="submit" class="btn btn-secondary w-100">Invoice খুঁজুন</button></div>
            </form>

            <?php if ($search_invoice_no !== '' && !$found_sale): ?>
                <p class="text-danger">এই Invoice No দিয়ে কিছু পাওয়া যায়নি।</p>
            <?php endif; ?>

            <?php if ($found_sale): ?>
                <div class="alert alert-info py-2">
                    <strong><?php echo htmlspecialchars($found_sale['customer_name']); ?></strong>
                    (<?php echo $found_sale['buyer_type'] === 'student' ? 'Student' : 'Walk-in'; ?>)
                    &middot; Total: ৳<?php echo number_format($found_sale['total_amount'],2); ?>
                    &middot; Paid: ৳<?php echo number_format($found_sale['paid_amount'],2); ?>
                    &middot; Due: ৳<?php echo number_format($found_sale['due_amount'],2); ?>
                </div>

                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="sale_id" value="<?php echo (int)$found_sale['id']; ?>">
                    <table class="table table-bordered align-middle">
                        <thead class="table-secondary"><tr><th>Item</th><th>বিক্রিত Qty</th><th>আগে ফেরত হয়েছে</th><th>সর্বোচ্চ ফেরতযোগ্য</th><th style="width:130px;">Return Qty</th></tr></thead>
                        <tbody>
                            <?php foreach ($found_lines as $fl): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($fl['item_name'] ?? '-'); ?><input type="hidden" name="r_item_id[]" value="<?php echo (int)$fl['item_id']; ?>"></td>
                                    <td><?php echo (int)$fl['qty']; ?></td>
                                    <td><?php echo (int)$fl['already_returned']; ?></td>
                                    <td><?php echo (int)$fl['max_returnable']; ?></td>
                                    <td><input type="number" min="0" max="<?php echo (int)$fl['max_returnable']; ?>" name="r_return_qty[]" class="form-control form-control-sm" value="0" <?php echo $fl['max_returnable'] <= 0 ? 'disabled' : ''; ?>></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="mb-3"><input type="text" name="reason" class="form-control" placeholder="ফেরতের কারণ (optional)"></div>
                    <button type="submit" name="return_submit" class="btn btn-danger">Return সম্পন্ন করুন</button>
                </form>
            <?php endif; ?>
        </div>

        <div id="paneReturnHistory" class="inv-tab-pane card p-3 mb-4 d-none">
            <h5 class="fw-bold mb-3">Return History</h5>
            <div class="table-responsive">
            <table class="table table-bordered text-center align-middle">
                <thead class="table-dark"><tr><th>Return No</th><th>Invoice</th><th>Customer</th><th>Return Amount</th><th>নগদ ফেরত</th><th>Reason</th><th>Date</th></tr></thead>
                <tbody>
                    <?php if ($returns): foreach ($returns as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['return_no']); ?></td>
                            <td><?php echo htmlspecialchars($r['invoice_no'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($r['customer_name'] ?? '-'); ?></td>
                            <td class="text-danger">৳<?php echo number_format($r['return_amount'],2); ?></td>
                            <td>৳<?php echo number_format($r['refund_amount'] ?? 0,2); ?></td>
                            <td><?php echo htmlspecialchars($r['reason'] ?? '-'); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars($r['created_at']); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" class="text-muted">কোনো Return নেই</td></tr>
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
