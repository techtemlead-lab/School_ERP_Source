<?php
// admin/canteen_purchase.php - Canteen > Purchase (কাঁচামাল কেনা — স্টক বাড়ায়)
require_once __DIR__ . '/canteen_common.php';
canteen_require_manage();

$msg = '';
$err = $_SESSION['canteen_err'] ?? '';
unset($_SESSION['canteen_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $err = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (isset($_POST['save_supplier'])) {
        $sname = trim($_POST['supplier_name_new'] ?? '');
        $sphone = trim($_POST['supplier_phone'] ?? '');
        if ($sname !== '') {
            $pdo->prepare("INSERT INTO canteen_suppliers (name, phone) VALUES (?, ?)")->execute([$sname, $sphone ?: null]);
            sec_log_activity($pdo, 'Canteen', 'Create', "নতুন Canteen Supplier যোগ করা হয়েছে: {$sname}");
            $msg = 'নতুন Supplier যোগ করা হয়েছে।';
        }
    } elseif (isset($_POST['purchase_submit'])) {
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        $paid_amount   = (float) ($_POST['paid_amount'] ?? 0);
        $mat_ids = $_POST['p_material_id'] ?? [];
        $qtys    = $_POST['p_qty'] ?? [];
        $rates   = $_POST['p_rate'] ?? [];

        if ($supplier_name === '') {
            $err = 'Supplier এর নাম দিন!';
        } else {
            $lines = [];
            $total = 0.0;
            foreach ($mat_ids as $i => $mid) {
                $mid  = (int) $mid;
                $qty  = (float) ($qtys[$i] ?? 0);
                $rate = (float) ($rates[$i] ?? 0);
                if ($mid <= 0 || $qty <= 0) { continue; }
                $amount = $qty * $rate;
                $lines[] = ['material_id' => $mid, 'qty' => $qty, 'rate' => $rate, 'amount' => $amount];
                $total += $amount;
            }

            if (!$lines) {
                $err = 'কমপক্ষে একটা কাঁচামাল যোগ করুন!';
            } else {
                $due = max(0, $total - $paid_amount);
                $purchase_no = 'CPUR-' . date('Ymd') . '-' . mt_rand(1000, 9999);

                $pdo->beginTransaction();
                try {
                    $pdo->prepare("INSERT INTO canteen_purchases (purchase_no, supplier_name, total_amount, paid_amount, due_amount, branch_id) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$purchase_no, $supplier_name, $total, $paid_amount, $due, branch_id_for_insert()]);
                    $purchase_id = (int) $pdo->lastInsertId();

                    $insItem  = $pdo->prepare("INSERT INTO canteen_purchase_items (purchase_id, raw_material_id, qty, rate, amount) VALUES (?, ?, ?, ?, ?)");
                    $updStock = $pdo->prepare("UPDATE canteen_raw_materials SET current_stock = current_stock + ? WHERE id = ?");

                    foreach ($lines as $ln) {
                        $insItem->execute([$purchase_id, $ln['material_id'], $ln['qty'], $ln['rate'], $ln['amount']]);
                        $updStock->execute([$ln['qty'], $ln['material_id']]);
                    }

                    $pdo->commit();
                    sec_log_activity($pdo, 'Canteen', 'Create', "নতুন Canteen Purchase করা হয়েছে: {$purchase_no} ({$supplier_name}, ৳{$total})");
                    $_SESSION['canteen_msg'] = "✅ Purchase সম্পন্ন হয়েছে! ({$purchase_no})";
                    header('Location: index.php?page=canteen_purchase');
                    exit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log('Canteen purchase failed: ' . $e->getMessage());
                    $err = 'Purchase ব্যর্থ হয়েছে, আবার চেষ্টা করুন।';
                }
            }
        }
    }
}

$msg = $msg ?: ($_SESSION['canteen_msg'] ?? '');
unset($_SESSION['canteen_msg']);

$cp_where = ["status = 'Active'"]; $cp_params = [];
branch_apply_filter($cp_where, $cp_params);
$cp_stmt = $pdo->prepare("SELECT id, name, unit_cost FROM canteen_raw_materials WHERE " . implode(' AND ', $cp_where) . " ORDER BY name ASC");
$cp_stmt->execute($cp_params);
$materials = $cp_stmt->fetchAll();
$materials_for_js = [];
foreach ($materials as $m) { $materials_for_js[$m['id']] = ['name' => $m['name'], 'rate' => (float) $m['unit_cost']]; }

$suppliers = $pdo->query("SELECT * FROM canteen_suppliers ORDER BY name ASC")->fetchAll();
$cp2_where = []; $cp2_params = [];
branch_apply_filter($cp2_where, $cp2_params);
$cp2_where_sql = $cp2_where ? (' WHERE ' . implode(' AND ', $cp2_where)) : '';
$cp2_stmt = $pdo->prepare("SELECT * FROM canteen_purchases$cp2_where_sql ORDER BY id DESC LIMIT 100");
$cp2_stmt->execute($cp2_params);
$purchases = $cp2_stmt->fetchAll();
?>

<div class="card-custom">
    <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-truck-ramp-box"></i> Canteen &raquo; Purchase</h4>
    <p class="text-muted mb-4" style="font-size:13px;">কাঁচামাল কিনলে এখানে এন্ট্রি দিলে Raw Materials-এর Stock নিজে থেকেই বেড়ে যাবে।</p>

    <?php if ($msg): ?><div class="alert alert-success py-2"><?php echo cv($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?php echo cv($err); ?></div><?php endif; ?>

    <div id="purTabGroup">
        <div class="inv-tab-boxes">
            <button type="button" class="inv-tab-box active" onclick="invTabSwitch('purTabGroup','paneNewPurchase',this)">New Purchase</button>
            <button type="button" class="inv-tab-box" onclick="invTabSwitch('purTabGroup','panePurchaseHistory',this)">Purchase History</button>
            <button type="button" class="inv-tab-box" onclick="invTabSwitch('purTabGroup','paneSuppliers',this)">Suppliers</button>
        </div>

        <div id="paneNewPurchase" class="inv-tab-pane card p-4 bg-light mb-4">
            <form method="POST" action="index.php?page=canteen_purchase" id="purchaseForm">
                <?php echo csrf_field(); ?>
                <div class="mb-3">
                    <label class="form-label fw-bold">Supplier Name <span class="text-danger">*</span></label>
                    <input type="text" name="supplier_name" list="supplierList" class="form-control" required>
                    <datalist id="supplierList">
                        <?php foreach ($suppliers as $s): ?><option value="<?php echo cv($s['name']); ?>"><?php endforeach; ?>
                    </datalist>
                </div>

                <table class="table table-bordered align-middle" id="purCartTable">
                    <thead class="table-secondary"><tr><th>Raw Material</th><th style="width:120px;">Qty</th><th style="width:120px;">Rate</th><th style="width:140px;">Amount</th><th></th></tr></thead>
                    <tbody></tbody>
                </table>
                <button type="button" class="btn btn-outline-primary btn-sm mb-3" onclick="purAddRow()">+ কাঁচামাল যোগ করুন</button>

                <div class="sale-totals-box">
                    <div class="stl-row"><span>Total</span><span>৳<span id="purTotal">0.00</span></span></div>
                    <div class="stl-row">
                        <span>Paid Amount</span>
                        <input type="number" step="0.01" min="0" name="paid_amount" id="purPaidAmount" class="form-control form-control-sm stl-input" value="0" required>
                    </div>
                </div>

                <button type="submit" name="purchase_submit" class="btn btn-primary fw-bold">Confirm Purchase</button>
            </form>
        </div>

        <div id="panePurchaseHistory" class="inv-tab-pane card p-3 mb-4 d-none">
            <div class="table-responsive">
            <table class="table table-bordered text-center align-middle">
                <thead class="table-dark"><tr><th>Purchase No</th><th>Supplier</th><th>Total</th><th>Paid</th><th>Due</th><th>Date</th></tr></thead>
                <tbody>
                    <?php if ($purchases): foreach ($purchases as $p): ?>
                        <tr>
                            <td><strong><?php echo cv($p['purchase_no']); ?></strong></td>
                            <td><?php echo cv($p['supplier_name']); ?></td>
                            <td>৳<?php echo number_format($p['total_amount'], 2); ?></td>
                            <td class="text-success">৳<?php echo number_format($p['paid_amount'], 2); ?></td>
                            <td class="text-danger">৳<?php echo number_format($p['due_amount'], 2); ?></td>
                            <td class="small text-muted"><?php echo cv($p['created_at']); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" class="text-muted">কোনো Purchase নেই</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <div id="paneSuppliers" class="inv-tab-pane card p-3 mb-4 d-none">
            <form method="POST" action="index.php?page=canteen_purchase" class="row g-2 mb-3 align-items-end">
                <?php echo csrf_field(); ?>
                <div class="col-md-4"><label class="form-label small fw-bold">নতুন Supplier</label><input type="text" name="supplier_name_new" class="form-control"></div>
                <div class="col-md-4"><label class="form-label small fw-bold">ফোন</label><input type="tel" name="supplier_phone" class="form-control phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="১১ ডিজিটের মোবাইল নম্বর দিন"></div>
                <div class="col-md-3"><button type="submit" name="save_supplier" class="btn btn-outline-primary">Add Supplier</button></div>
            </form>
            <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-dark"><tr><th>Name</th><th>Phone</th></tr></thead>
                <tbody>
                    <?php if ($suppliers): foreach ($suppliers as $s): ?>
                        <tr><td><?php echo cv($s['name']); ?></td><td><?php echo cv($s['phone']); ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="2" class="text-muted">কোনো Supplier নেই</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<style>
.inv-tab-boxes { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
.inv-tab-box { border: 2px solid #0d6efd; background: #fff; color: #0d6efd; font-weight: 600; padding: 8px 22px; border-radius: 8px; cursor: pointer; font-size: 14px; }
.inv-tab-box.active { background: #0d6efd; color: #fff; }
.inv-tab-pane { border: 1px solid #dee2e6; }
.sale-totals-box { max-width: 340px; margin-left: auto; background: #fff; border: 1px solid #dee2e6; border-radius: 10px; padding: 16px 20px; margin-bottom: 16px; }
.sale-totals-box .stl-row { display: flex; justify-content: space-between; align-items: center; padding: 7px 0; font-size: 14.5px; }
.sale-totals-box .stl-input { width: 130px; text-align: right; }
</style>
<script>
document.addEventListener("wheel", function (e) {
    if (document.activeElement && document.activeElement.tagName === "INPUT" && document.activeElement.type === "number") { document.activeElement.blur(); }
}, { passive: true });

function invTabSwitch(groupId, targetId, btn) {
    const group = document.getElementById(groupId);
    group.querySelectorAll('.inv-tab-pane').forEach(p => p.classList.add('d-none'));
    document.getElementById(targetId).classList.remove('d-none');
    group.querySelectorAll('.inv-tab-box').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

const CANTEEN_MATERIALS = <?php echo json_encode($materials_for_js, JSON_UNESCAPED_UNICODE); ?>;

function purBuildOptions() {
    let html = '<option value="">কাঁচামাল বেছে নিন...</option>';
    for (const id in CANTEEN_MATERIALS) { html += `<option value="${id}">${CANTEEN_MATERIALS[id].name}</option>`; }
    return html;
}

function purAddRow() {
    const tbody = document.querySelector('#purCartTable tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><select name="p_material_id[]" class="form-select form-select-sm" onchange="purRowChanged(this)">${purBuildOptions()}</select></td>
        <td><input type="number" step="0.001" min="0.001" name="p_qty[]" class="form-control form-control-sm" value="1" oninput="purRecalc()"></td>
        <td><input type="number" step="0.01" min="0" name="p_rate[]" class="form-control form-control-sm" value="0" oninput="purRecalc()"></td>
        <td class="p-row-amount">0.00</td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); purRecalc();">✕</button></td>
    `;
    tbody.appendChild(tr);
    purRecalc();
}

function purRowChanged(sel) {
    const tr = sel.closest('tr');
    const id = sel.value;
    const rate = id && CANTEEN_MATERIALS[id] ? CANTEEN_MATERIALS[id].rate : 0;
    tr.querySelector('[name="p_rate[]"]').value = rate.toFixed(2);
    purRecalc();
}

function purRecalc() {
    let total = 0;
    document.querySelectorAll('#purCartTable tbody tr').forEach(tr => {
        const qty  = parseFloat(tr.querySelector('[name="p_qty[]"]').value) || 0;
        const rate = parseFloat(tr.querySelector('[name="p_rate[]"]').value) || 0;
        const amount = qty * rate;
        tr.querySelector('.p-row-amount').textContent = amount.toFixed(2);
        total += amount;
    });
    document.getElementById('purTotal').textContent = total.toFixed(2);
}

document.addEventListener('DOMContentLoaded', purAddRow);

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
