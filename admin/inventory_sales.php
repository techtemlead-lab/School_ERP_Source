<?php
// admin/inventory_sales.php - Inventory & Sales > Sales (POS)
require_once __DIR__ . '/inventory_common.php';

$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error_msg = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (isset($_POST['sale_submit'])) {
        $inv_settings = inv_get_settings($pdo);
        // গুরুত্বপূর্ণ: এই sale sales_invoices/inv_sale_items টেবিলে থাকে — Fee/Finance
        // Ledger এর কোনো টেবিলে এটা কখনো insert হয় না।
        $buyer_type   = ($_POST['buyer_type'] ?? 'walkin') === 'student' ? 'student' : 'walkin';
        $student_pk   = $buyer_type === 'student' ? ((int) ($_POST['student_pk'] ?? 0) ?: null) : null;
        $student_code = $buyer_type === 'student' ? trim($_POST['student_code'] ?? '') : null;
        $customer_name = trim($_POST['customer_name'] ?? '');
        $discount_amount = max(0, (float) ($_POST['discount_amount'] ?? 0));
        $paid_amount  = (float) ($_POST['paid_amount'] ?? 0);

        $item_ids = $_POST['s_item_id'] ?? [];
        $qtys     = $_POST['s_qty'] ?? [];

        if ($buyer_type === 'student' && !$student_pk) {
            $error_msg = "Student বেছে নেওয়া হয়নি!";
        } elseif ($buyer_type === 'walkin' && $customer_name === '') {
            $error_msg = "Customer এর নাম দিন!";
        } else {
            $lines = [];
            $subtotal = 0.0;
            $stock_err = '';
            foreach ($item_ids as $i => $iid) {
                $iid = (int) $iid;
                $qty = (int) ($qtys[$i] ?? 0);
                if ($iid <= 0 || $qty <= 0) { continue; }

                $itemStmt = $pdo->prepare("SELECT name, sale_price, stock_qty FROM inventory_items WHERE id = ?");
                $itemStmt->execute([$iid]);
                $itemRow = $itemStmt->fetch();

                if (!$itemRow) { continue; }
                if ($itemRow['stock_qty'] < $qty) {
                    $stock_err = htmlspecialchars($itemRow['name']) . ' এর Stock যথেষ্ট নেই!';
                    break;
                }
                $rate = (float) $itemRow['sale_price'];
                $amount = $rate * $qty;
                $lines[] = ['item_id' => $iid, 'qty' => $qty, 'rate' => $rate, 'amount' => $amount];
                $subtotal += $amount;
            }

            if ($stock_err) {
                $error_msg = $stock_err;
            } elseif (!$lines) {
                $error_msg = "কমপক্ষে একটা Item যোগ করুন!";
            } elseif (!$inv_settings['allow_due_sale'] && $paid_amount < ($subtotal - $discount_amount)) {
                $error_msg = "Due Sale বন্ধ আছে (Settings থেকে) — সম্পূর্ণ টাকা পরিশোধ করেই Sale সম্পন্ন করতে হবে।";
            } else {
                $discount_amount = min($discount_amount, $subtotal);
                $total = $subtotal - $discount_amount;
                $due = max(0, $total - $paid_amount);
                $invoice_no = 'INV-' . date('Ymd') . '-' . mt_rand(1000, 9999);
                $final_customer_name = $buyer_type === 'student' ? ($_POST['student_display_name'] ?? $customer_name) : $customer_name;

                $pdo->beginTransaction();
                try {
                    $ins = $pdo->prepare(
                        "INSERT INTO sales_invoices (customer_name, invoice_no, total_amount, paid_amount, due_amount, buyer_type, student_pk, student_code, discount_amount, branch_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $ins->execute([$final_customer_name, $invoice_no, $total, $paid_amount, $due, $buyer_type, $student_pk, $student_code, $discount_amount, branch_id_for_insert()]);
                    $sale_id = (int) $pdo->lastInsertId();

                    $insItem = $pdo->prepare("INSERT INTO inv_sale_items (sale_id, item_id, qty, rate, amount) VALUES (?, ?, ?, ?, ?)");
                    $updStock = $pdo->prepare("UPDATE inventory_items SET stock_qty = stock_qty - ? WHERE id = ?");

                    foreach ($lines as $ln) {
                        $insItem->execute([$sale_id, $ln['item_id'], $ln['qty'], $ln['rate'], $ln['amount']]);
                        $updStock->execute([$ln['qty'], $ln['item_id']]);
                    }

                    $pdo->commit();
                    sec_log_activity($pdo, 'Inventory', 'Create', "নতুন Sale তৈরি করা হয়েছে: {$invoice_no} ({$final_customer_name}), মোট: ৳{$total}");

                    // Student-লিংকড Sale হলে ও Setting-এ Enable থাকলে অভিভাবকের নম্বরে SMS
                    if ($buyer_type === 'student' && $inv_settings['send_sms_on_sale'] && $student_pk) {
                        try {
                            require_once __DIR__ . '/../config/sms.php';
                            $gst = $pdo->prepare("SELECT guardian_phone FROM students WHERE id = ?");
                            $gst->execute([$student_pk]);
                            $guardian_phone = $gst->fetchColumn();
                            if ($guardian_phone) {
                                $sms_msg = "প্রিয় অভিভাবক, {$final_customer_name} এর জন্য স্টোর থেকে পণ্য ক্রয় করা হয়েছে। মোট: ৳" . number_format($total, 2) . ", জমা: ৳" . number_format($paid_amount, 2) . ", বাকি: ৳" . number_format($due, 2) . "। ইনভয়েস: $invoice_no";
                                send_sms($pdo, $guardian_phone, $sms_msg);
                            }
                        } catch (\Throwable $e) {
                            error_log('Inventory sale SMS failed: ' . $e->getMessage());
                        }
                    }

                    // Sale সফল হওয়ার পর ফ্রেশ/ফাঁকা "New Sale" ফর্মে ফেরত পাঠানো হচ্ছে (Post-Redirect-Get)
                    // যাতে আগের Student/Cart কোনো কিছুই থেকে না যায়, পরের কাস্টমারের জন্য পেজ পুরো ফাঁকা থাকে
                    header("Location: index.php?page=inventory_sales&last_invoice=$sale_id");
                    exit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log('Sale failed: ' . $e->getMessage());
                    $error_msg = "Sale ব্যর্থ হয়েছে, আবার চেষ্টা করুন।";
                }
            }
        }
    }
}

// এইমাত্র তৈরি হওয়া Invoice (redirect এর পর) — Print বাটন দেখানোর জন্য
$last_invoice = null;
if (!empty($_GET['last_invoice'])) {
    $li = $pdo->prepare("SELECT * FROM sales_invoices WHERE id = ?");
    $li->execute([(int) $_GET['last_invoice']]);
    $last_invoice = $li->fetch() ?: null;
}

$is_where = []; $is_params = [];
branch_apply_filter($is_where, $is_params);
$is_where_sql = $is_where ? (' WHERE ' . implode(' AND ', $is_where)) : '';
$iis_stmt = $pdo->prepare("SELECT id, name, sale_price, stock_qty FROM inventory_items$is_where_sql ORDER BY name");
$iis_stmt->execute($is_params);
$all_items = $iis_stmt->fetchAll();

$is2_where = []; $is2_params = [];
branch_apply_filter($is2_where, $is2_params);
$is2_where_sql = $is2_where ? (' WHERE ' . implode(' AND ', $is2_where)) : '';
$sales_stmt = $pdo->prepare("SELECT * FROM sales_invoices$is2_where_sql ORDER BY id DESC");
$sales_stmt->execute($is2_params);
$sales = $sales_stmt->fetchAll();
$items_for_js = [];
foreach ($all_items as $it) { $items_for_js[$it['id']] = ['name' => $it['name'], 'price' => (float) $it['sale_price'], 'stock' => (int) $it['stock_qty']]; }

$page_settings = inv_get_settings($pdo);
?>

<div class="card-custom">
    <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-cash-register"></i> Inventory & Sales &raquo; Sales (POS)</h4>
    <p class="text-muted mb-4" style="font-size:13px;">Walk-in Customer অথবা Student-কে সরাসরি বিক্রি করুন।</p>

    <?php if ($last_invoice): ?>
        <div class="alert alert-success py-2 d-flex justify-content-between align-items-center">
            <span>✅ Sale সম্পন্ন হয়েছে! Invoice: <strong><?php echo htmlspecialchars($last_invoice['invoice_no']); ?></strong></span>
            <a href="inventory_invoice_print.php?id=<?php echo (int)$last_invoice['id']; ?>" target="_blank" class="btn btn-sm btn-primary"><i class="fa-solid fa-print"></i> Print Invoice</a>
        </div>
    <?php endif; ?>
    <?php if ($success_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($success_msg); ?></div><?php endif; ?>
    <?php if ($error_msg): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>

    <div id="salesTabGroup">
        <div class="inv-tab-boxes">
            <button type="button" class="inv-tab-box active" onclick="invTabSwitch('salesTabGroup','paneNewSale',this)">New Sale</button>
            <button type="button" class="inv-tab-box" onclick="invTabSwitch('salesTabGroup','paneSalesHistory',this)">Sales History</button>
        </div>

        <div id="paneNewSale" class="inv-tab-pane card p-4 bg-light mb-4">
            <h5 class="fw-bold mb-3">নতুন Sale</h5>

            <div class="mb-3">
                <label class="form-label fw-bold d-block">Buyer Type</label>

                <div id="studentSelectedBox" class="alert alert-info py-2 d-flex justify-content-between align-items-center d-none">
                    <span id="studentSelectedText"></span>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="invClearStudent()">Remove</button>
                </div>

                <div id="studentSearchBox" style="position:relative;">
                    <input type="text" id="studentSearchInput" class="form-control mb-1" placeholder="Student এর নাম / ID / Roll লিখুন (Autosuggest)..." autocomplete="off" oninput="invStudentSearch()">
                    <div id="studentSearchResults" class="list-group shadow-sm" style="position:absolute; z-index:1050; width:100%; max-height:240px; overflow-y:auto;"></div>
                </div>

                <div id="walkinBox" class="mt-2">
                    <input type="text" name="customer_name" id="walkinNameInput" form="saleForm" class="form-control" placeholder="Walk-in Customer Name" required>
                </div>
            </div>

            <form method="POST" id="saleForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="buyer_type" id="buyerTypeInput" value="walkin">
                <input type="hidden" name="student_pk" id="studentPkInput" value="">
                <input type="hidden" name="student_code" id="studentCodeInput" value="">
                <input type="hidden" name="student_display_name" id="studentDisplayNameInput" value="">

                <table class="table table-bordered align-middle" id="saleCartTable">
                    <thead class="table-secondary"><tr><th>Item</th><th style="width:110px;">Qty</th><th style="width:120px;">Rate</th><th style="width:140px;">Amount</th><th></th></tr></thead>
                    <tbody></tbody>
                </table>
                <button type="button" class="btn btn-outline-primary btn-sm mb-3" onclick="invAddSaleRow()">+ Item যোগ করুন</button>

                <div class="sale-totals-box">
                    <?php if (!$page_settings['allow_due_sale']): ?>
                        <div class="alert alert-warning py-1 px-2 mb-2" style="font-size:12.5px;">Due Sale বন্ধ আছে — সম্পূর্ণ টাকা পরিশোধ করতে হবে।</div>
                    <?php endif; ?>
                    <div class="stl-row"><span>Subtotal</span><span>৳<span id="saleSubtotal">0.00</span></span></div>
                    <div class="stl-row">
                        <span>Discount</span>
                        <input type="number" step="0.01" min="0" name="discount_amount" id="saleDiscount" class="form-control form-control-sm stl-input" value="0" oninput="invRecalcSale()">
                    </div>
                    <div class="stl-row grand"><span>Grand Total</span><span>৳<span id="saleGrandTotal">0.00</span></span></div>
                    <div class="stl-row">
                        <span>Paid Amount</span>
                        <input type="number" step="0.01" min="0" name="paid_amount" id="salePaidAmount" class="form-control form-control-sm stl-input" value="0" required>
                    </div>
                </div>

                <button type="submit" name="sale_submit" class="btn btn-primary">Confirm Sale & Generate Invoice</button>
            </form>
        </div>

        <div id="paneSalesHistory" class="inv-tab-pane card p-3 mb-4 d-none">
            <h5 class="fw-bold mb-3">Sales Invoices</h5>
            <div class="table-responsive">
            <table class="table table-bordered text-center align-middle">
                <thead class="table-dark"><tr><th>Invoice No</th><th>Buyer</th><th>Type</th><th>Total</th><th>Paid</th><th>Due</th><th>Date</th><th>Print</th></tr></thead>
                <tbody>
                    <?php if ($sales): foreach ($sales as $sl):
                        $type_badge = $sl['buyer_type'] === 'student' ? '<span class="badge bg-info">Student</span>' : '<span class="badge bg-secondary">Walk-in</span>';
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($sl['invoice_no']); ?></strong></td>
                            <td><?php echo htmlspecialchars($sl['customer_name']); ?></td>
                            <td><?php echo $type_badge; ?></td>
                            <td>৳<?php echo number_format($sl['total_amount'],2); ?></td>
                            <td class="text-success">৳<?php echo number_format($sl['paid_amount'],2); ?></td>
                            <td class="text-danger">৳<?php echo number_format($sl['due_amount'],2); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars($sl['date']); ?></td>
                            <td><a href="inventory_invoice_print.php?id=<?php echo (int)$sl['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-print"></i></a></td>
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
.inv-tab-box {
    border: 2px solid #0d6efd; background: #fff; color: #0d6efd; font-weight: 600;
    padding: 8px 22px; border-radius: 8px; cursor: pointer; font-size: 14px;
}
.inv-tab-box.active { background: #0d6efd; color: #fff; }
.inv-tab-pane { border: 1px solid #dee2e6; }
#studentSearchResults:empty { display: none; }
#studentSearchResults .list-group-item { cursor: pointer; }
.sale-totals-box {
    max-width: 340px; margin-left: auto; background: #fff; border: 1px solid #dee2e6;
    border-radius: 10px; padding: 16px 20px; margin-bottom: 16px;
}
.sale-totals-box .stl-row { display: flex; justify-content: space-between; align-items: center; padding: 7px 0; font-size: 14.5px; }
.sale-totals-box .stl-row.grand { font-weight: 800; font-size: 18px; border-top: 2px solid #0a2540; margin-top: 6px; padding-top: 12px; color: #0a2540; }
.sale-totals-box .stl-input { width: 130px; text-align: right; }
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
const ALLOW_DUE_SALE = <?php echo $page_settings['allow_due_sale'] ? 'true' : 'false'; ?>;

function invBuildItemOptions() {
    let html = '<option value="">Item বেছে নিন...</option>';
    for (const id in INV_ITEMS) { html += `<option value="${id}">${INV_ITEMS[id].name} (Stock: ${INV_ITEMS[id].stock})</option>`; }
    return html;
}

function invAddSaleRow() {
    const tbody = document.querySelector('#saleCartTable tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><select name="s_item_id[]" class="form-select form-select-sm" onchange="invSaleItemChanged(this)">${invBuildItemOptions()}</select></td>
        <td><input type="number" min="1" name="s_qty[]" class="form-control form-control-sm" value="1" oninput="invRecalcSale()"></td>
        <td class="s-row-rate">0.00</td>
        <td class="s-row-amount">0.00</td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); invRecalcSale();">✕</button></td>
    `;
    tbody.appendChild(tr);
    invRecalcSale();
}

function invSaleItemChanged(sel) {
    const tr = sel.closest('tr');
    const id = sel.value;
    const rate = id && INV_ITEMS[id] ? INV_ITEMS[id].price : 0;
    tr.querySelector('.s-row-rate').textContent = rate.toFixed(2);
    invRecalcSale();
}

function invRecalcSale() {
    let subtotal = 0;
    document.querySelectorAll('#saleCartTable tbody tr').forEach(tr => {
        const sel = tr.querySelector('[name="s_item_id[]"]');
        const qty = parseFloat(tr.querySelector('[name="s_qty[]"]').value) || 0;
        const rate = sel.value && INV_ITEMS[sel.value] ? INV_ITEMS[sel.value].price : 0;
        const amount = qty * rate;
        tr.querySelector('.s-row-rate').textContent = rate.toFixed(2);
        tr.querySelector('.s-row-amount').textContent = amount.toFixed(2);
        subtotal += amount;
    });
    const discount = parseFloat(document.getElementById('saleDiscount').value) || 0;
    const grandTotal = Math.max(0, subtotal - discount);
    document.getElementById('saleSubtotal').textContent = subtotal.toFixed(2);
    document.getElementById('saleGrandTotal').textContent = grandTotal.toFixed(2);

    if (!ALLOW_DUE_SALE) {
        const paidInput = document.getElementById('salePaidAmount');
        paidInput.value = grandTotal.toFixed(2);
        paidInput.setAttribute('readonly', 'readonly');
    }
}

// ---------- Student Live Search (Autosuggest) ----------
let invStudentSearchTimer = null;

function invStudentSearch() {
    clearTimeout(invStudentSearchTimer);
    const q = document.getElementById('studentSearchInput').value.trim();
    const box = document.getElementById('studentSearchResults');
    if (q.length < 1) { box.innerHTML = ''; return; }

    invStudentSearchTimer = setTimeout(function () {
        fetch('ajax/inventory_student_search_ajax.php?action=search&q=' + encodeURIComponent(q))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                box.innerHTML = '';
                if (data.success && data.students && data.students.length) {
                    data.students.forEach(function (s) {
                        const item = document.createElement('button');
                        item.type = 'button';
                        item.className = 'list-group-item list-group-item-action';
                        item.textContent = s.name_en + ' (ID: ' + s.student_id + ', Roll: ' + s.roll_no + ')';
                        item.onclick = function () { invSelectStudent(s); };
                        box.appendChild(item);
                    });
                } else {
                    const empty = document.createElement('div');
                    empty.className = 'list-group-item text-muted';
                    empty.textContent = 'কোনো Student পাওয়া যায়নি';
                    box.appendChild(empty);
                }
            });
    }, 250);
}

function invSelectStudent(s) {
    document.getElementById('buyerTypeInput').value = 'student';
    document.getElementById('studentPkInput').value = s.id;
    document.getElementById('studentCodeInput').value = s.student_id;
    document.getElementById('studentDisplayNameInput').value = s.name_en;

    document.getElementById('studentSelectedText').innerHTML =
        '<i class="fa-solid fa-user-graduate"></i> Student নির্বাচিত: <strong>' + s.name_en + '</strong> (ID: ' + s.student_id + ', Roll: ' + s.roll_no + ')';
    document.getElementById('studentSelectedBox').classList.remove('d-none');
    document.getElementById('studentSearchBox').classList.add('d-none');
    document.getElementById('walkinBox').classList.add('d-none');

    document.getElementById('studentSearchResults').innerHTML = '';
    document.getElementById('studentSearchInput').value = '';

    const walkinInput = document.getElementById('walkinNameInput');
    walkinInput.removeAttribute('required');
    walkinInput.value = '';
}

function invClearStudent() {
    document.getElementById('buyerTypeInput').value = 'walkin';
    document.getElementById('studentPkInput').value = '';
    document.getElementById('studentCodeInput').value = '';
    document.getElementById('studentDisplayNameInput').value = '';

    document.getElementById('studentSelectedBox').classList.add('d-none');
    document.getElementById('studentSearchBox').classList.remove('d-none');
    document.getElementById('walkinBox').classList.remove('d-none');
    document.getElementById('walkinNameInput').setAttribute('required', 'required');
}

// বাইরে ক্লিক করলে Search Result বক্স বন্ধ হয়ে যাবে
document.addEventListener('click', function (e) {
    const box = document.getElementById('studentSearchResults');
    const input = document.getElementById('studentSearchInput');
    if (box && input && e.target !== input && !box.contains(e.target)) { box.innerHTML = ''; }
});

document.addEventListener('DOMContentLoaded', invAddSaleRow);
</script>
