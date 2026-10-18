<?php
// admin/inventory_purchase.php - Inventory & Sales > Purchase
require_once __DIR__ . '/inventory_common.php';

$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error_msg = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (isset($_POST['add_supplier'])) {
        $name = trim($_POST['supplier_new_name'] ?? '');
        $phone = trim($_POST['supplier_phone'] ?? '');
        $address = trim($_POST['supplier_address'] ?? '');
        if ($name !== '') {
            $pdo->prepare("INSERT INTO inv_suppliers (name, phone, address) VALUES (?, ?, ?)")->execute([$name, $phone ?: null, $address ?: null]);
            sec_log_activity($pdo, 'Inventory', 'Create', "নতুন Supplier যোগ করা হয়েছে: {$name}");
            $success_msg = "Supplier যোগ করা হয়েছে।";
        } else {
            $error_msg = "Supplier-এর নাম দিন!";
        }
    } elseif (isset($_POST['purchase_submit'])) {
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        $item_ids = $_POST['p_item_id'] ?? [];
        $qtys     = $_POST['p_qty'] ?? [];
        $rates    = $_POST['p_rate'] ?? [];
        $paid_amount = (float) ($_POST['p_paid_amount'] ?? 0);

        $lines = [];
        $total = 0.0;
        // একই Item একাধিক লাইনে থাকলে qty যোগ হবে; একই Item-এর শেষ rate ধরা হবে
        $merged = [];
        foreach ($item_ids as $i => $iid) {
            $iid = (int) $iid;
            $qty = (int) ($qtys[$i] ?? 0);
            $rate = (float) ($rates[$i] ?? 0);
            if ($iid > 0 && $qty > 0 && $rate >= 0) {
                if (!isset($merged[$iid])) { $merged[$iid] = ['qty' => 0, 'amount' => 0.0]; }
                $merged[$iid]['qty'] += $qty;
                $merged[$iid]['amount'] += $qty * $rate;
            }
        }
        foreach ($merged as $iid => $m) {
            $lines[] = ['item_id' => $iid, 'qty' => $m['qty'], 'rate' => $m['amount'] / $m['qty'], 'amount' => $m['amount']];
            $total += $m['amount'];
        }

        if ($supplier_name === '' || !$lines) {
            $error_msg = "Supplier নাম এবং কমপক্ষে একটা Item লাইন দিতে হবে!";
        } elseif ($paid_amount < 0 || $paid_amount > $total + 0.001) {
            $error_msg = "Paid Amount ০ থেকে মোট বিলের (৳" . number_format($total, 2) . ") মধ্যে হতে হবে!";
        } else {
            $due = max(0, $total - $paid_amount);

            $pdo->beginTransaction();
            try {
                $purchase_no = inv_unique_no($pdo, 'PUR', 'inv_purchases', 'purchase_no');
                $ins = $pdo->prepare("INSERT INTO inv_purchases (purchase_no, supplier_name, total_amount, paid_amount, due_amount, branch_id) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->execute([$purchase_no, $supplier_name, $total, $paid_amount, $due, branch_id_for_insert()]);
                $purchase_id = (int) $pdo->lastInsertId();

                $insItem = $pdo->prepare("INSERT INTO inv_purchase_items (purchase_id, item_id, qty, rate, amount) VALUES (?, ?, ?, ?, ?)");
                // Stock বাড়ানো + Cost Price = weighted average (পুরনো stock × পুরনো cost + নতুন qty × rate) / মোট qty
                $updStock = $pdo->prepare("UPDATE inventory_items SET stock_qty = ?, cost_price = ? WHERE id = ?");

                foreach ($lines as $ln) {
                    $itemRow = inv_lock_row($pdo, 'inventory_items', $ln['item_id'], 'stock_qty, cost_price, status');
                    if (!$itemRow) { throw new RuntimeException('Item পাওয়া যায়নি (অথবা অন্য branch-এর)।'); }
                    if (($itemRow['status'] ?? 'Active') !== 'Active') { throw new RuntimeException('Inactive Item-এ Purchase করা যাবে না।'); }
                    $old_stock = max(0, (int) $itemRow['stock_qty']);
                    $new_stock = (int) $itemRow['stock_qty'] + $ln['qty'];
                    $base = $old_stock + $ln['qty'];
                    $new_cost = $base > 0 ? (($old_stock * (float) $itemRow['cost_price']) + $ln['amount']) / $base : $ln['rate'];
                    $insItem->execute([$purchase_id, $ln['item_id'], $ln['qty'], $ln['rate'], $ln['amount']]);
                    $updStock->execute([$new_stock, round($new_cost, 2), $ln['item_id']]);
                }

                if ($paid_amount > 0) {
                    $pdo->prepare("INSERT INTO inv_supplier_payments (purchase_id, amount, note) VALUES (?, ?, 'Initial')")->execute([$purchase_id, $paid_amount]);
                }

                $pdo->commit();
                sec_log_activity($pdo, 'Inventory', 'Create', "নতুন Purchase তৈরি করা হয়েছে: {$purchase_no} (Supplier: {$supplier_name}), মোট: ৳{$total}");
                $success_msg = "Purchase সম্পন্ন হয়েছে! Purchase No: $purchase_no";
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error_msg = $e->getMessage();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('Purchase failed: ' . $e->getMessage());
                $error_msg = "Purchase ব্যর্থ হয়েছে, আবার চেষ্টা করুন।";
            }
        }
    }
}

$ip_where = []; $ip_params = [];
branch_apply_filter($ip_where, $ip_params);
$ip_where_sql = $ip_where ? (' WHERE ' . implode(' AND ', $ip_where)) : '';
$ip_where[] = "status = 'Active'";
$ip_where_sql = ' WHERE ' . implode(' AND ', $ip_where);
$ii_stmt = $pdo->prepare("SELECT id, name, stock_qty FROM inventory_items$ip_where_sql ORDER BY name");
$ii_stmt->execute($ip_params);
$all_items = $ii_stmt->fetchAll();
$items_for_js = [];
foreach ($all_items as $it) { $items_for_js[$it['id']] = ['name' => $it['name'], 'stock' => (int) $it['stock_qty']]; }

$ip2_where = []; $ip2_params = [];
branch_apply_filter($ip2_where, $ip2_params);
$ip2_where_sql = $ip2_where ? (' WHERE ' . implode(' AND ', $ip2_where)) : '';
[$hp_page, $hp_per, $hp_off] = inv_page_params(50);
$cnt = $pdo->prepare("SELECT COUNT(*) FROM inv_purchases$ip2_where_sql");
$cnt->execute($ip2_params);
$purch_total = (int) $cnt->fetchColumn();
$purch_stmt = $pdo->prepare("SELECT * FROM inv_purchases$ip2_where_sql ORDER BY id DESC LIMIT $hp_per OFFSET $hp_off");
$purch_stmt->execute($ip2_params);
$purchases = $purch_stmt->fetchAll();

// Supplier List (master) — Autosuggest এখানে থেকেই নেওয়া হবে
$suppliers = $pdo->query("SELECT * FROM inv_suppliers ORDER BY name")->fetchAll();
?>

<div class="card-custom">
    <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-truck"></i> Inventory & Sales &raquo; Purchase</h4>
    <p class="text-muted mb-4" style="font-size:13px;">Supplier থেকে Stock In (একাধিক Item এক Purchase-এ)।</p>

    <?php if ($success_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($success_msg); ?></div><?php endif; ?>
    <?php if ($error_msg): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>

    <div id="purchaseTabGroup">
        <div class="inv-tab-boxes">
            <button type="button" class="inv-tab-box active" onclick="invTabSwitch('purchaseTabGroup','paneNewPurchase',this)">New Purchase</button>
            <button type="button" class="inv-tab-box" onclick="invTabSwitch('purchaseTabGroup','paneSupplier',this)">Supplier</button>
            <button type="button" class="inv-tab-box" onclick="invTabSwitch('purchaseTabGroup','panePurchaseHistory',this)">Purchase History</button>
        </div>

        <div id="paneNewPurchase" class="inv-tab-pane card p-4 bg-light mb-4">
            <h5 class="fw-bold mb-3">নতুন Purchase</h5>
            <form method="POST" id="purchaseForm">
                <?php echo csrf_field(); ?>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <input type="text" name="supplier_name" class="form-control" placeholder="Supplier Name" list="supplierNameList" autocomplete="off" required>
                        <datalist id="supplierNameList">
                            <?php foreach ($suppliers as $sup): ?><option value="<?php echo htmlspecialchars($sup['name']); ?>"><?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="col-md-6"><input type="number" step="0.01" min="0" name="p_paid_amount" class="form-control" placeholder="মোট Paid Amount" value="0" required></div>
                </div>

                <table class="table table-bordered align-middle" id="purchaseCartTable">
                    <thead class="table-secondary"><tr><th>Item</th><th style="width:110px;">Qty</th><th style="width:140px;">Rate</th><th style="width:140px;">Amount</th><th></th></tr></thead>
                    <tbody></tbody>
                </table>
                <button type="button" class="btn btn-outline-primary btn-sm mb-3" onclick="invAddPurchaseRow()">+ Item যোগ করুন</button>
                <div class="fw-bold mb-3">Total: ৳<span id="purchaseGrandTotal">0.00</span></div>

                <button type="submit" name="purchase_submit" class="btn btn-success">Purchase সম্পন্ন করুন</button>
            </form>
        </div>

        <div id="paneSupplier" class="inv-tab-pane card p-3 mb-4 d-none">
            <h5 class="fw-bold mb-3">Add Supplier</h5>
            <form method="POST" class="row g-2 mb-3">
                <?php echo csrf_field(); ?>
                <div class="col-md-4"><input type="text" name="supplier_new_name" class="form-control form-control-sm" placeholder="Supplier Name" required></div>
                <div class="col-md-3"><input type="tel" name="supplier_phone" class="form-control form-control-sm phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="১১ ডিজিটের মোবাইল নম্বর দিন" placeholder="Phone (optional)"></div>
                <div class="col-md-3"><input type="text" name="supplier_address" class="form-control form-control-sm" placeholder="Address (optional)"></div>
                <div class="col-md-2"><button type="submit" name="add_supplier" class="btn btn-primary btn-sm w-100">Add Supplier</button></div>
            </form>
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light"><tr><th>#</th><th>Name</th><th>Phone</th><th>Address</th></tr></thead>
                <tbody>
                    <?php if ($suppliers): foreach ($suppliers as $sup): ?>
                        <tr>
                            <td><?php echo (int)$sup['id']; ?></td>
                            <td><?php echo htmlspecialchars($sup['name']); ?></td>
                            <td><?php echo htmlspecialchars($sup['phone'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($sup['address'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="4" class="text-muted">কোনো Supplier নেই</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="panePurchaseHistory" class="inv-tab-pane card p-3 mb-4 d-none">
            <h5 class="fw-bold mb-3">Purchase History</h5>
            <div class="table-responsive">
            <table class="table table-bordered text-center align-middle">
                <thead class="table-dark"><tr><th>Purchase No</th><th>Supplier</th><th>Total</th><th>Paid</th><th>Due</th><th>Date</th></tr></thead>
                <tbody>
                    <?php if ($purchases): foreach ($purchases as $p): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($p['purchase_no']); ?></strong></td>
                            <td><?php echo htmlspecialchars($p['supplier_name']); ?></td>
                            <td>৳<?php echo number_format($p['total_amount'],2); ?></td>
                            <td class="text-success">৳<?php echo number_format($p['paid_amount'],2); ?></td>
                            <td class="text-danger">৳<?php echo number_format($p['due_amount'],2); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars($p['created_at']); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" class="text-muted">কোনো Purchase নেই</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
            <?php echo inv_pager_html($purch_total, $hp_page, $hp_per); ?>
        </div>
    </div>
</div>
<script>
// পেজিনেশন লিংকে (?pg=) এলে History ট্যাব নিজে খুলে যাবে
document.addEventListener('DOMContentLoaded', function () {
    if (new URLSearchParams(location.search).has('pg')) {
        const btn = document.querySelector('#purchaseTabGroup .inv-tab-box:nth-child(3)');
        if (btn) { btn.click(); }
    }
});
</script>


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

const INV_ITEMS = <?php echo json_encode($items_for_js, JSON_UNESCAPED_UNICODE); ?>;

function invBuildItemOptions() {
    let html = '<option value="">Item বেছে নিন...</option>';
    for (const id in INV_ITEMS) { html += `<option value="${id}">${INV_ITEMS[id].name} (Stock: ${INV_ITEMS[id].stock})</option>`; }
    return html;
}

function invAddPurchaseRow() {
    const tbody = document.querySelector('#purchaseCartTable tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><select name="p_item_id[]" class="form-select form-select-sm">${invBuildItemOptions()}</select></td>
        <td><input type="number" min="1" name="p_qty[]" class="form-control form-control-sm" value="1" oninput="invRecalcPurchase()"></td>
        <td><input type="number" step="0.01" min="0" name="p_rate[]" class="form-control form-control-sm" value="0" oninput="invRecalcPurchase()"></td>
        <td class="p-row-amount">0.00</td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); invRecalcPurchase();">✕</button></td>
    `;
    tbody.appendChild(tr);
    invRecalcPurchase();
}

function invRecalcPurchase() {
    let total = 0;
    document.querySelectorAll('#purchaseCartTable tbody tr').forEach(tr => {
        const qty = parseFloat(tr.querySelector('[name="p_qty[]"]').value) || 0;
        const rate = parseFloat(tr.querySelector('[name="p_rate[]"]').value) || 0;
        const amount = qty * rate;
        tr.querySelector('.p-row-amount').textContent = amount.toFixed(2);
        total += amount;
    });
    document.getElementById('purchaseGrandTotal').textContent = total.toFixed(2);
}

document.addEventListener('DOMContentLoaded', invAddPurchaseRow);

// ফোন নম্বর ফিল্ড (.phone-11 ক্লাস): টাইপ করার সাথে সাথেই সংখ্যা ছাড়া অন্য কিছু নেয় না, ১১ ডিজিটের বেশি হলে কেটে দেয়
document.querySelectorAll('.phone-11').forEach(function (input) {
    // প্রতিটা ফিল্ডের ঠিক পরে একটা এরর মেসেজ বসানো হচ্ছে (আগে থেকে না থাকলে)
    let errEl = input.nextElementSibling;
    if (!errEl || !errEl.classList.contains('phone11-err')) {
        errEl = document.createElement('div');
        errEl.className = 'small text-danger mt-1 d-none phone11-err';
        errEl.textContent = 'মোবাইল নম্বর অবশ্যই ১১ ডিজিটের হতে হবে।';
        input.insertAdjacentElement('afterend', errEl);
    }

    function checkPhone11() {
        let digits = input.value.replace(/\D/g, '');
        if (digits.length > 11) digits = digits.slice(0, 11);
        input.value = digits;
        // খালি রাখা যাবে (আবশ্যক না হলে) — কিন্তু কিছু লিখলে অবশ্যই ১১ ডিজিট হতে হবে,
        // ১১ ডিজিট হয়ে গেলেই মেসেজ সাথে সাথে (রিলোড ছাড়া) সরে যাবে
        const invalid = digits.length > 0 && digits.length !== 11;
        errEl.classList.toggle('d-none', !invalid);
        input.classList.toggle('is-invalid', invalid);
    }
    input.addEventListener('input', checkPhone11);
    input.addEventListener('blur', checkPhone11);
});
</script>
