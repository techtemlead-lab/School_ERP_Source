<?php
// admin/canteen_pos.php - Canteen > Sales (POS)
// অ্যাক্সেস: Super Admin/Admin + 'Canteen Staff' role — সবাই এই পেজে বিক্রি করতে পারবে
require_once __DIR__ . '/canteen_common.php';

$is_super_admin = $_SESSION['is_super_admin'] ?? true;
$user_role      = $_SESSION['user_role'] ?? '';
if (!$is_super_admin && !in_array($user_role, ['Admin', 'Canteen Staff'], true)) {
    $_SESSION['canteen_err'] = 'ক্যানটিন POS ব্যবহারের অনুমতি নেই।';
    header('Location: index.php?page=dashboard');
    exit();
}

$msg = '';
$err = $_SESSION['canteen_err'] ?? '';
unset($_SESSION['canteen_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $err = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (isset($_POST['sale_submit'])) {
        $settings = canteen_get_settings($pdo);

        // ব্যক্তি সিলেক্ট করা থাকলেই buyer_type student/employee হবে, নাহলে walkin
        $student_pk    = (int) ($_POST['student_pk'] ?? 0) ?: null;
        $student_code  = trim($_POST['student_code'] ?? '') ?: null;
        $employee_pk   = (int) ($_POST['employee_pk'] ?? 0) ?: null;
        $employee_code = trim($_POST['employee_code'] ?? '') ?: null;
        $buyer_display_name = trim($_POST['buyer_display_name'] ?? '');
        $walkin_name_input   = trim($_POST['walkin_name'] ?? '');

        if ($student_pk) {
            $buyer_type = 'student';
            $customer_name = $buyer_display_name ?: 'Student';
        } elseif ($employee_pk) {
            $buyer_type = 'employee';
            $customer_name = $buyer_display_name ?: 'Employee';
        } else {
            $buyer_type = 'walkin';
            $student_pk = $student_code = $employee_pk = $employee_code = null;
            $customer_name = $walkin_name_input !== '' ? $walkin_name_input : 'Walk-in Customer';
        }

        $overall_discount = max(0, (float) ($_POST['discount_amount'] ?? 0));
        $paid_amount = (float) ($_POST['paid_amount'] ?? 0);

        $item_ids   = $_POST['s_item_id'] ?? [];
        $qtys       = $_POST['s_qty'] ?? [];
        $discounts  = $_POST['s_discount'] ?? [];

        $lines = [];
        $subtotal = 0.0;
        foreach ($item_ids as $i => $iid) {
            $iid = (int) $iid;
            $qty = (int) ($qtys[$i] ?? 0);
            if ($iid <= 0 || $qty <= 0) { continue; }

            $itemStmt = $pdo->prepare("SELECT name, price FROM canteen_menu_items WHERE id = ?");
            $itemStmt->execute([$iid]);
            $itemRow = $itemStmt->fetch();
            if (!$itemRow) { continue; }

            $rate = (float) $itemRow['price'];
            $line_subtotal = $rate * $qty;
            $line_discount = min(max(0, (float) ($discounts[$i] ?? 0)), $line_subtotal);
            $amount = $line_subtotal - $line_discount;

            $lines[] = ['item_id' => $iid, 'name' => $itemRow['name'], 'qty' => $qty, 'rate' => $rate, 'amount' => $amount];
            $subtotal += $line_subtotal;
        }

        $total = array_sum(array_column($lines, 'amount'));
        $overall_discount = min($overall_discount, $total);
        $grand_total = $total - $overall_discount;
        $due = max(0, $grand_total - $paid_amount);

        if (!$lines) {
            $err = 'কমপক্ষে একটা আইটেম যোগ করুন!';
        } elseif ($buyer_type === 'walkin' && $due > 0) {
            $err = 'বাকি (Due) রেখে বিক্রি করতে হলে Student বা Teacher নির্বাচন করা আবশ্যক — Walk-in Customer-এর জন্য সম্পূর্ণ টাকা নিতে হবে।';
        } elseif (!$settings['allow_due_sale'] && $paid_amount < $grand_total) {
            $err = 'Due Sale বন্ধ আছে — সম্পূর্ণ টাকা পরিশোধ করেই বিক্রি সম্পন্ন করতে হবে।';
        } else {
            $invoice_no = 'CNT-' . date('Ymd') . '-' . mt_rand(1000, 9999);

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "INSERT INTO canteen_sales (invoice_no, buyer_type, student_pk, student_code, employee_pk, employee_code, customer_name, total_amount, discount_amount, paid_amount, due_amount, branch_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([$invoice_no, $buyer_type, $student_pk, $student_code, $employee_pk, $employee_code, $customer_name, $grand_total, $overall_discount, $paid_amount, $due, branch_id_for_insert()]);
                $sale_id = (int) $pdo->lastInsertId();

                $insItem = $pdo->prepare("INSERT INTO canteen_sale_items (sale_id, menu_item_id, qty, rate, amount) VALUES (?, ?, ?, ?, ?)");
                $recipeStmt = $pdo->prepare("SELECT raw_material_id, qty_required FROM canteen_recipe_items WHERE menu_item_id = ?");
                $deductStmt = $pdo->prepare("UPDATE canteen_raw_materials SET current_stock = current_stock - ? WHERE id = ?");

                $low_stock_warnings = [];
                foreach ($lines as $ln) {
                    $insItem->execute([$sale_id, $ln['item_id'], $ln['qty'], $ln['rate'], $ln['amount']]);

                    // Recipe অনুযায়ী কাঁচামাল স্টক থেকে বিয়োগ (হার্ড ব্লক না — সতর্ক করা হবে মাত্র)
                    $recipeStmt->execute([$ln['item_id']]);
                    foreach ($recipeStmt->fetchAll() as $rec) {
                        $need = (float) $rec['qty_required'] * $ln['qty'];
                        $deductStmt->execute([$need, $rec['raw_material_id']]);

                        $chk = $pdo->prepare("SELECT name, current_stock, reorder_level FROM canteen_raw_materials WHERE id = ?");
                        $chk->execute([$rec['raw_material_id']]);
                        $matRow = $chk->fetch();
                        if ($matRow && (float) $matRow['current_stock'] <= (float) $matRow['reorder_level']) {
                            $low_stock_warnings[] = $matRow['name'];
                        }
                    }
                }

                $pdo->commit();

                sec_log_activity($pdo, 'Canteen', 'Create', "নতুন Canteen Sale করা হয়েছে: {$invoice_no} ({$customer_name}, ৳{$grand_total})");

                $ok_msg = "✅ Sale সম্পন্ন হয়েছে! Invoice: {$invoice_no}";
                if ($low_stock_warnings) {
                    $ok_msg .= ' | ⚠️ কম স্টক: ' . implode(', ', array_unique($low_stock_warnings));
                }
                $_SESSION['canteen_msg'] = $ok_msg;
                header("Location: index.php?page=canteen_pos&last_invoice=$sale_id");
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Canteen sale failed: ' . $e->getMessage());
                $err = 'Sale ব্যর্থ হয়েছে, আবার চেষ্টা করুন।';
            }
        }
    }
}

$msg = $msg ?: ($_SESSION['canteen_msg'] ?? '');
unset($_SESSION['canteen_msg']);

$last_invoice = null;
if (!empty($_GET['last_invoice'])) {
    $li = $pdo->prepare("SELECT * FROM canteen_sales WHERE id = ?");
    $li->execute([(int) $_GET['last_invoice']]);
    $last_invoice = $li->fetch() ?: null;
}

$settings = canteen_get_settings($pdo);
$cpos_where = []; $cpos_params = [];
branch_apply_filter($cpos_where, $cpos_params);
$cpos_where_sql = $cpos_where ? (' WHERE ' . implode(' AND ', $cpos_where)) : '';
$cpos_stmt = $pdo->prepare("SELECT * FROM canteen_sales$cpos_where_sql ORDER BY id DESC LIMIT 100");
$cpos_stmt->execute($cpos_params);
$sales = $cpos_stmt->fetchAll();
?>

<div class="card-custom">
    <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-cash-register"></i> Canteen &raquo; Sales (POS)</h4>
    <p class="text-muted mb-4" style="font-size:13px;">Student, Teacher/Staff অথবা Walk-in Customer-কে সরাসরি বিক্রি করুন।</p>

    <?php if ($last_invoice): ?>
        <div class="alert alert-success py-2 d-flex justify-content-between align-items-center">
            <span>✅ Sale সম্পন্ন হয়েছে! Invoice: <strong><?php echo cv($last_invoice['invoice_no']); ?></strong></span>
            <a href="canteen_invoice_print.php?id=<?php echo (int) $last_invoice['id']; ?>" target="_blank" class="btn btn-sm btn-primary"><i class="fa-solid fa-print"></i> Print Invoice</a>
        </div>
    <?php endif; ?>
    <?php if ($msg && !$last_invoice): ?><div class="alert alert-success py-2"><?php echo cv($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?php echo cv($err); ?></div><?php endif; ?>

    <div id="posTabGroup">
        <div class="inv-tab-boxes">
            <button type="button" class="inv-tab-box active" onclick="invTabSwitch('posTabGroup','paneNewSale',this)">New Sale</button>
            <button type="button" class="inv-tab-box" onclick="invTabSwitch('posTabGroup','paneSalesHistory',this)">Sales History</button>
        </div>

        <div id="paneNewSale" class="inv-tab-pane card p-4 bg-light mb-4">

            <div class="mb-3">
                <label class="form-label fw-bold d-block">Buyer (Student / Teacher / Walk-in — একই ঘরে লিখুন)</label>

                <div id="personSearchBox" style="position:relative;">
                    <input type="text" id="personSearchInput" name="walkin_name" form="posSaleForm" class="form-control" placeholder="Student/Teacher-এর নাম বা ID লিখে সাজেশন থেকে বেছে নিন, নাহলে সরাসরি Walk-in নাম লিখুন..." autocomplete="off" oninput="posPersonSearch()">
                    <button type="button" id="personClearBtn" class="btn btn-sm btn-outline-danger d-none" style="position:absolute; right:6px; top:6px;" onclick="posClearPerson()">✕</button>
                    <div id="personSearchResults" class="list-group shadow-sm" style="position:absolute; z-index:1050; width:100%; max-height:260px; overflow-y:auto;"></div>
                </div>
                <div id="personTypeBadge" class="mt-1"></div>
            </div>

            <form method="POST" id="posSaleForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="student_pk" id="studentPkInput" value="">
                <input type="hidden" name="student_code" id="studentCodeInput" value="">
                <input type="hidden" name="employee_pk" id="employeePkInput" value="">
                <input type="hidden" name="employee_code" id="employeeCodeInput" value="">
                <input type="hidden" name="buyer_display_name" id="buyerDisplayNameInput" value="">

                <label class="form-label fw-bold d-block mt-3">Item</label>
                <div style="position:relative;" class="mb-2">
                    <input type="text" id="itemSearchInput" class="form-control" placeholder="Item-এর নাম লিখুন..." autocomplete="off" oninput="posItemSearch()">
                    <div id="itemSearchResults" class="list-group shadow-sm" style="position:absolute; z-index:1050; width:100%; max-height:260px; overflow-y:auto;"></div>
                </div>

                <table class="table table-bordered align-middle" id="saleCartTable">
                    <thead class="table-secondary"><tr><th>Item</th><th style="width:90px;">Qty</th><th style="width:100px;">Rate</th><th style="width:110px;">Discount</th><th style="width:120px;">Amount</th><th></th></tr></thead>
                    <tbody></tbody>
                </table>

                <div class="d-flex justify-content-end">
                <div class="sale-totals-box">
                    <?php if (!$settings['allow_due_sale']): ?>
                        <div class="alert alert-warning py-1 px-2 mb-2" style="font-size:12.5px;">Due Sale বন্ধ আছে — সম্পূর্ণ টাকা পরিশোধ করতে হবে।</div>
                    <?php endif; ?>
                    <div class="stl-row"><span>Subtotal</span><span>৳<span id="saleSubtotal">0.00</span></span></div>
                    <div class="stl-row"><span>Overall Discount</span><input type="number" step="0.01" min="0" name="discount_amount" id="saleDiscount" class="form-control form-control-sm stl-input" value="0" oninput="posRecalc()"></div>
                    <div class="stl-row grand"><span>Grand Total</span><span>৳<span id="saleGrandTotal">0.00</span></span></div>
                    <div class="stl-row"><span>Paid Amount</span><input type="number" step="0.01" min="0" name="paid_amount" id="salePaidAmount" class="form-control form-control-sm stl-input" value="0" required></div>
                </div>
                </div>

                <button type="submit" name="sale_submit" class="btn btn-primary fw-bold" id="posConfirmBtn">Confirm Sale</button>
            </form>
        </div>

        <div id="paneSalesHistory" class="inv-tab-pane card p-3 mb-4 d-none">
            <div class="table-responsive">
            <table class="table table-bordered text-center align-middle">
                <thead class="table-dark"><tr><th>Invoice No</th><th>Buyer</th><th>Type</th><th>Total</th><th>Paid</th><th>Due</th><th>Date</th><th>Print</th></tr></thead>
                <tbody>
                    <?php if ($sales): foreach ($sales as $sl):
                        $badge = ['student' => 'bg-info', 'employee' => 'bg-primary', 'walkin' => 'bg-secondary'][$sl['buyer_type']] ?? 'bg-secondary';
                        $label = ['student' => 'Student', 'employee' => 'Teacher', 'walkin' => 'Walk-in'][$sl['buyer_type']] ?? 'Walk-in';
                    ?>
                        <tr>
                            <td><strong><?php echo cv($sl['invoice_no']); ?></strong></td>
                            <td><?php echo cv($sl['customer_name']); ?></td>
                            <td><span class="badge <?php echo $badge; ?>"><?php echo $label; ?></span></td>
                            <td>৳<?php echo number_format($sl['total_amount'], 2); ?></td>
                            <td class="text-success">৳<?php echo number_format($sl['paid_amount'], 2); ?></td>
                            <td class="text-danger">৳<?php echo number_format($sl['due_amount'], 2); ?></td>
                            <td class="small text-muted"><?php echo cv($sl['created_at']); ?></td>
                            <td><a href="canteen_invoice_print.php?id=<?php echo (int) $sl['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-print"></i></a></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="8" class="text-muted">কোনো Sale নেই</td></tr>
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
#personSearchResults:empty, #itemSearchResults:empty { display: none; }
#personSearchResults .list-group-item, #itemSearchResults .list-group-item { cursor: pointer; }
.sale-totals-box { max-width: 340px; margin-left: auto; background: #fff; border: 1px solid #dee2e6; border-radius: 10px; padding: 16px 20px; margin: 16px 0; }
.sale-totals-box .stl-row { display: flex; justify-content: space-between; align-items: center; padding: 7px 0; font-size: 14.5px; }
.sale-totals-box .stl-row.grand { font-weight: 800; font-size: 18px; border-top: 2px solid #0a2540; margin-top: 6px; padding-top: 12px; color: #0a2540; }
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

const CANTEEN_ALLOW_DUE = <?php echo $settings['allow_due_sale'] ? 'true' : 'false'; ?>;
let posSelectedPersonType = null; // null | 'student' | 'employee'

// ---------- Item Cart ----------
function posAddItemRow(id, name, price) {
    // একই আইটেম আগে থেকে কার্টে থাকলে Qty বাড়িয়ে দাও, নতুন রো বানানোর দরকার নেই
    const existing = document.querySelector(`#saleCartTable tbody tr[data-item-id="${id}"]`);
    if (existing) {
        const qtyInput = existing.querySelector('[name="s_qty[]"]');
        qtyInput.value = (parseInt(qtyInput.value) || 0) + 1;
        posRecalc();
        return;
    }

    const tbody = document.querySelector('#saleCartTable tbody');
    const tr = document.createElement('tr');
    tr.dataset.itemId = id;
    tr.dataset.rate = price;
    tr.innerHTML = `
        <td>${name}<input type="hidden" name="s_item_id[]" value="${id}"></td>
        <td><input type="number" min="1" name="s_qty[]" class="form-control form-control-sm" value="1" oninput="posRecalc()"></td>
        <td class="s-row-rate">${price.toFixed(2)}</td>
        <td><input type="number" step="0.01" min="0" name="s_discount[]" class="form-control form-control-sm" value="0" oninput="posRecalc()"></td>
        <td class="s-row-amount">${price.toFixed(2)}</td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); posRecalc();">✕</button></td>
    `;
    tbody.appendChild(tr);
    posRecalc();
}

function posRecalc() {
    let subtotal = 0, grandBeforeOverall = 0;
    document.querySelectorAll('#saleCartTable tbody tr').forEach(tr => {
        const rate = parseFloat(tr.dataset.rate) || 0;
        const qty = parseFloat(tr.querySelector('[name="s_qty[]"]').value) || 0;
        const disc = parseFloat(tr.querySelector('[name="s_discount[]"]').value) || 0;
        const lineSubtotal = rate * qty;
        const amount = Math.max(0, lineSubtotal - disc);
        tr.querySelector('.s-row-amount').textContent = amount.toFixed(2);
        subtotal += lineSubtotal;
        grandBeforeOverall += amount;
    });
    const overallDiscount = parseFloat(document.getElementById('saleDiscount').value) || 0;
    const grandTotal = Math.max(0, grandBeforeOverall - overallDiscount);
    document.getElementById('saleSubtotal').textContent = subtotal.toFixed(2);
    document.getElementById('saleGrandTotal').textContent = grandTotal.toFixed(2);

    if (!CANTEEN_ALLOW_DUE) {
        const paidInput = document.getElementById('salePaidAmount');
        paidInput.value = grandTotal.toFixed(2);
        paidInput.setAttribute('readonly', 'readonly');
    }
}

// ---------- Item Live Search ----------
let posItemSearchTimer = null;
function posItemSearch() {
    clearTimeout(posItemSearchTimer);
    const q = document.getElementById('itemSearchInput').value.trim();
    const box = document.getElementById('itemSearchResults');

    posItemSearchTimer = setTimeout(function () {
        fetch('ajax/canteen_item_search_ajax.php?action=search&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                box.innerHTML = '';
                if (data.success && data.results && data.results.length) {
                    data.results.forEach(function (it) {
                        const item = document.createElement('button');
                        item.type = 'button';
                        item.className = 'list-group-item list-group-item-action d-flex justify-content-between';
                        item.innerHTML = `<span>${it.name}</span><span class="text-muted">৳${parseFloat(it.price).toFixed(2)}</span>`;
                        item.onclick = function () {
                            posAddItemRow(it.id, it.name, parseFloat(it.price));
                            box.innerHTML = '';
                            document.getElementById('itemSearchInput').value = '';
                        };
                        box.appendChild(item);
                    });
                } else {
                    const empty = document.createElement('div');
                    empty.className = 'list-group-item text-muted';
                    empty.textContent = 'কোনো Item পাওয়া যায়নি';
                    box.appendChild(empty);
                }
            });
    }, 200);
}

// ---------- Person (Student/Teacher) Live Search ----------
let posPersonSearchTimer = null;
function posPersonSearch() {
    clearTimeout(posPersonSearchTimer);
    const q = document.getElementById('personSearchInput').value.trim();
    const box = document.getElementById('personSearchResults');
    if (q.length < 1) { box.innerHTML = ''; return; }

    posPersonSearchTimer = setTimeout(function () {
        fetch('ajax/canteen_person_search_ajax.php?action=search&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                box.innerHTML = '';
                if (data.success && data.results && data.results.length) {
                    data.results.forEach(function (p) {
                        const typeLabel = p.type === 'student' ? 'Student' : 'Teacher/Staff';
                        const item = document.createElement('button');
                        item.type = 'button';
                        item.className = 'list-group-item list-group-item-action';
                        item.textContent = p.name + ' (' + typeLabel + ', ID: ' + p.code + (p.roll_no ? ', Roll: ' + p.roll_no : '') + ')';
                        item.onclick = function () { posSelectPerson(p); };
                        box.appendChild(item);
                    });
                } else {
                    const empty = document.createElement('div');
                    empty.className = 'list-group-item text-muted';
                    empty.textContent = 'কেউ পাওয়া যায়নি';
                    box.appendChild(empty);
                }
            });
    }, 250);
}

function posSelectPerson(p) {
    posSelectedPersonType = p.type;
    if (p.type === 'student') {
        document.getElementById('studentPkInput').value = p.id;
        document.getElementById('studentCodeInput').value = p.code;
        document.getElementById('employeePkInput').value = '';
        document.getElementById('employeeCodeInput').value = '';
    } else {
        document.getElementById('employeePkInput').value = p.id;
        document.getElementById('employeeCodeInput').value = p.code;
        document.getElementById('studentPkInput').value = '';
        document.getElementById('studentCodeInput').value = '';
    }
    document.getElementById('buyerDisplayNameInput').value = p.name;

    const typeLabel = p.type === 'student' ? 'Student' : 'Teacher/Staff';
    const input = document.getElementById('personSearchInput');
    input.value = p.name + ' (' + typeLabel + ', ID: ' + p.code + ')';
    input.setAttribute('readonly', 'readonly');

    const badgeClass = p.type === 'student' ? 'bg-info' : 'bg-primary';
    document.getElementById('personTypeBadge').innerHTML = `<span class="badge ${badgeClass}">${typeLabel} নির্বাচিত</span>`;
    document.getElementById('personClearBtn').classList.remove('d-none');
    document.getElementById('personSearchResults').innerHTML = '';
}

function posClearPerson() {
    posSelectedPersonType = null;
    document.getElementById('studentPkInput').value = '';
    document.getElementById('studentCodeInput').value = '';
    document.getElementById('employeePkInput').value = '';
    document.getElementById('employeeCodeInput').value = '';
    document.getElementById('buyerDisplayNameInput').value = '';

    const input = document.getElementById('personSearchInput');
    input.removeAttribute('readonly');
    input.value = '';
    input.focus();

    document.getElementById('personTypeBadge').innerHTML = '';
    document.getElementById('personClearBtn').classList.add('d-none');
}

document.addEventListener('click', function (e) {
    const pBox = document.getElementById('personSearchResults');
    const pInput = document.getElementById('personSearchInput');
    if (pBox && pInput && e.target !== pInput && !pBox.contains(e.target)) { pBox.innerHTML = ''; }

    const iBox = document.getElementById('itemSearchResults');
    const iInput = document.getElementById('itemSearchInput');
    if (iBox && iInput && e.target !== iInput && !iBox.contains(e.target)) { iBox.innerHTML = ''; }
});

// ক্লায়েন্ট-সাইড সতর্কতা: Walk-in-এ Due রেখে সাবমিট করতে গেলে আগেই জানিয়ে দাও
// (সার্ভারেও একই চেক আছে — এটা শুধু দ্রুত ফিডব্যাকের জন্য)
document.getElementById('posSaleForm').addEventListener('submit', function (e) {
    const grand = parseFloat(document.getElementById('saleGrandTotal').textContent) || 0;
    const paid = parseFloat(document.getElementById('salePaidAmount').value) || 0;
    if (!posSelectedPersonType && paid < grand) {
        e.preventDefault();
        alert('বাকি (Due) রেখে বিক্রি করতে হলে Student বা Teacher নির্বাচন করা আবশ্যক।');
    }
});
</script>
