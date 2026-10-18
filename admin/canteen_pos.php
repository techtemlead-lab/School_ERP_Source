<?php
// admin/canteen_pos.php - Canteen > Sales (POS)
// অ্যাক্সেস: Super Admin/Admin + 'Canteen Staff' role — সবাই এই পেজে বিক্রি করতে পারবে
require_once __DIR__ . '/canteen_common.php';

if (!canteen_can_sell()) {
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
        // নাম/কোড সবসময় DB থেকে নেওয়া হয় (ফর্মের hidden ফিল্ড বিশ্বাস করা হয় না) এবং শুধু নিজের branch-এর ব্যক্তি গ্রহণযোগ্য
        $student_pk    = (int) ($_POST['student_pk'] ?? 0) ?: null;
        $employee_pk   = (int) ($_POST['employee_pk'] ?? 0) ?: null;
        $walkin_name_input = trim($_POST['walkin_name'] ?? '');
        $student_code = $employee_code = null;
        $person_err = '';

        if ($student_pk) {
            $p = canteen_scoped_row($pdo, 'students', $student_pk, 'id, name_en, student_id');
            if (!$p) { $person_err = 'Student পাওয়া যায়নি (অথবা অন্য branch-এর)।'; }
            else { $buyer_type = 'student'; $customer_name = $p['name_en']; $student_code = $p['student_id']; $employee_pk = null; }
        } elseif ($employee_pk) {
            $p = canteen_scoped_row($pdo, 'employees', $employee_pk, 'id, name, employee_id');
            if (!$p) { $person_err = 'Teacher/Staff পাওয়া যায়নি (অথবা অন্য branch-এর)।'; }
            else { $buyer_type = 'employee'; $customer_name = $p['name']; $employee_code = $p['employee_id']; }
        } else {
            $buyer_type = 'walkin';
            $customer_name = $walkin_name_input !== '' ? $walkin_name_input : 'Walk-in Customer';
        }

        $overall_discount = max(0, (float) ($_POST['discount_amount'] ?? 0));
        $paid_amount = (float) ($_POST['paid_amount'] ?? 0);

        $want = canteen_merge_lines($_POST['s_item_id'] ?? [], $_POST['s_qty'] ?? [], $_POST['s_discount'] ?? []);

        if ($person_err !== '') {
            $err = $person_err;
        } elseif (!$want) {
            $err = 'কমপক্ষে একটা আইটেম যোগ করুন!';
        } elseif ($paid_amount < 0) {
            $err = 'জমার পরিমাণ ঋণাত্মক হতে পারে না।';
        } else {
            $invoice_no = null;
            $pdo->beginTransaction();
            try {
                // ১) মেনু আইটেম (নিজের branch + Active) — দাম DB থেকে
                $lines = [];
                foreach ($want as $iid => $w) {
                    $itemRow = canteen_scoped_row($pdo, 'canteen_menu_items', $iid, 'name, price, status');
                    if (!$itemRow) { throw new RuntimeException('আইটেম পাওয়া যায়নি (অথবা অন্য branch-এর)।'); }
                    if (($itemRow['status'] ?? 'Active') !== 'Active') { throw new RuntimeException($itemRow['name'] . ' Inactive — বিক্রি করা যাবে না।'); }
                    $rate = (float) $itemRow['price'];
                    $line_subtotal = $rate * $w['qty'];
                    $line_discount = min($w['disc'], $line_subtotal);
                    $lines[] = ['item_id' => $iid, 'name' => $itemRow['name'], 'qty' => $w['qty'], 'rate' => $rate, 'amount' => $line_subtotal - $line_discount];
                }

                $total = array_sum(array_column($lines, 'amount'));
                $overall_discount = min($overall_discount, $total);
                $grand_total = $total - $overall_discount;
                if ($paid_amount > $grand_total + 0.001) {
                    throw new RuntimeException('জমার পরিমাণ মোট বিলের চেয়ে বেশি হতে পারবে না (মোট: ৳' . number_format($grand_total, 2) . ')।');
                }
                $due = max(0, $grand_total - $paid_amount);
                if ($buyer_type === 'walkin' && $due > 0) {
                    throw new RuntimeException('বাকি (Due) রেখে বিক্রি করতে হলে Student বা Teacher নির্বাচন করা আবশ্যক — Walk-in Customer-এর জন্য সম্পূর্ণ টাকা নিতে হবে।');
                }
                if (!$settings['allow_due_sale'] && $paid_amount < $grand_total) {
                    throw new RuntimeException('Due Sale বন্ধ আছে — সম্পূর্ণ টাকা পরিশোধ করেই বিক্রি সম্পন্ন করতে হবে।');
                }

                // ২) Recipe থেকে মোট কাঁচামাল চাহিদা (একাধিক আইটেম একই কাঁচামাল নিলে যোগ হবে), তারপর লক
                $recipeStmt = $pdo->prepare("SELECT raw_material_id, qty_required FROM canteen_recipe_items WHERE menu_item_id = ?");
                $need = [];
                foreach ($lines as $ln) {
                    $recipeStmt->execute([$ln['item_id']]);
                    foreach ($recipeStmt->fetchAll() as $rec) {
                        $need[(int) $rec['raw_material_id']] = ($need[(int) $rec['raw_material_id']] ?? 0) + (float) $rec['qty_required'] * $ln['qty'];
                    }
                }
                ksort($need); // সবসময় একই ক্রমে লক — deadlock এড়াতে
                $lockMat = $pdo->prepare("SELECT id, name, current_stock, reorder_level FROM canteen_raw_materials WHERE id = ? FOR UPDATE");
                $mats = [];
                foreach ($need as $mid => $qty_need) {
                    $lockMat->execute([$mid]);
                    $m = $lockMat->fetch();
                    if (!$m) { continue; }
                    if (!empty($settings['block_on_low_stock']) && (float) $m['current_stock'] < $qty_need - 0.0001) {
                        throw new RuntimeException($m['name'] . ' এর স্টক যথেষ্ট নেই (আছে: ' . rtrim(rtrim(number_format((float) $m['current_stock'], 3, '.', ''), '0'), '.') . ', লাগবে: ' . rtrim(rtrim(number_format($qty_need, 3, '.', ''), '0'), '.') . ')।');
                    }
                    $mats[$mid] = $m;
                }

                // ৩) সেভ
                $invoice_no = canteen_unique_no($pdo, 'CNT', 'canteen_sales', 'invoice_no');
                $pdo->prepare(
                    "INSERT INTO canteen_sales (invoice_no, buyer_type, student_pk, student_code, employee_pk, employee_code, customer_name, total_amount, discount_amount, paid_amount, due_amount, branch_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([$invoice_no, $buyer_type, $student_pk, $student_code, $employee_pk, $employee_code, $customer_name, $grand_total, $overall_discount, $paid_amount, $due, branch_id_for_insert()]);
                $sale_id = (int) $pdo->lastInsertId();

                $insItem = $pdo->prepare("INSERT INTO canteen_sale_items (sale_id, menu_item_id, qty, rate, amount) VALUES (?, ?, ?, ?, ?)");
                foreach ($lines as $ln) {
                    $insItem->execute([$sale_id, $ln['item_id'], $ln['qty'], $ln['rate'], $ln['amount']]);
                }
                $deductStmt = $pdo->prepare("UPDATE canteen_raw_materials SET current_stock = current_stock - ? WHERE id = ?");
                $low_stock_warnings = [];
                foreach ($mats as $mid => $m) {
                    $deductStmt->execute([$need[$mid], $mid]);
                    $left = (float) $m['current_stock'] - $need[$mid];
                    if ($left <= (float) $m['reorder_level']) {
                        $low_stock_warnings[] = $m['name'] . ($left < 0 ? ' (মাইনাস!)' : '');
                    }
                }
                if ($paid_amount > 0) {
                    $pdo->prepare("INSERT INTO canteen_sale_payments (sale_id, amount, note) VALUES (?, ?, 'Initial')")->execute([$sale_id, $paid_amount]);
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
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $err = $e->getMessage();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('Canteen sale failed: ' . $e->getMessage());
                $err = 'Sale ব্যর্থ হয়েছে, আবার চেষ্টা করুন।';
            }
        }
    } elseif (isset($_POST['sale_return'])) {
        // ---------- Sale Return / Cancel (শুধু Super Admin/Admin) ----------
        if (!canteen_can_manage()) {
            $err = 'Sale Return/Cancel করার অনুমতি নেই (শুধু Admin)।';
        } else {
            try {
                $pdo->beginTransaction();
                $rsale_id = (int) ($_POST['sale_id'] ?? 0);
                $sale = canteen_lock_row($pdo, 'canteen_sales', $rsale_id); // sale row লক: একই sale-এ দুটো return একসাথে চলবে না
                if (!$sale) { throw new RuntimeException('Sale খুঁজে পাওয়া যায়নি (অথবা অন্য branch-এর)।'); }

                $itst = $pdo->prepare("SELECT id, menu_item_id, qty, amount FROM canteen_sale_items WHERE sale_id = ? ORDER BY id");
                $itst->execute([$rsale_id]);
                $sale_items = $itst->fetchAll();
                $rtst = $pdo->prepare("SELECT sale_item_id, SUM(qty) q FROM canteen_sale_return_items WHERE sale_id = ? GROUP BY sale_item_id");
                $rtst->execute([$rsale_id]);
                $already = [];
                foreach ($rtst->fetchAll() as $r) { $already[(int) $r['sale_item_id']] = (int) $r['q']; }

                $cancel_all = !empty($_POST['cancel_all']);
                $req = $_POST['ret_qty'] ?? [];
                $gross = (float) $sale['total_amount'] + (float) $sale['discount_amount'];
                $factor = $gross > 0 ? ((float) $sale['total_amount'] / $gross) : 1.0; // overall discount-এর আনুপাতিক অংশ
                $rlines = []; $R = 0.0; $all_back = true;
                foreach ($sale_items as $si) {
                    $sid = (int) $si['id'];
                    $left = (int) $si['qty'] - ($already[$sid] ?? 0);
                    $q = $cancel_all ? $left : max(0, (int) ($req[$sid] ?? 0));
                    if ($q > $left) { throw new RuntimeException('ফেরতের পরিমাণ বিক্রিত পরিমাণের চেয়ে বেশি হতে পারবে না।'); }
                    if ($q < $left) { $all_back = false; }
                    if ($q <= 0) { continue; }
                    $unit_net = (float) $si['amount'] / max(1, (int) $si['qty']);
                    $line_amount = round($unit_net * $q, 2);
                    $amt = round($line_amount * $factor, 2);
                    $rlines[] = ['sale_item_id' => $sid, 'menu_item_id' => (int) $si['menu_item_id'], 'qty' => $q, 'line_amount' => $line_amount, 'amount' => $amt];
                    $R += $amt;
                }
                if (!$rlines) { throw new RuntimeException('কমপক্ষে একটা আইটেমের ফেরত পরিমাণ দিন।'); }
                $remaining_value = round((float) $sale['total_amount'] - (float) $sale['returned_amount'], 2);
                if ($all_back) { $R = $remaining_value; } // পুরো বাকিটা ফেরত হলে rounding ফাঁক রাখা হবে না
                $R = round(min($R, $remaining_value), 2);

                $due_reduce = round(min((float) $sale['due_amount'], $R), 2);
                $refund = round($R - $due_reduce, 2);
                $restore = !empty($_POST['restore_stock']) ? 1 : 0;
                $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 250);

                $return_no = canteen_unique_no($pdo, 'CRT', 'canteen_sale_returns', 'return_no');
                $pdo->prepare("INSERT INTO canteen_sale_returns (return_no, sale_id, total_return, due_reduced, refund_amount, restored_stock, reason, created_by, branch_id) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$return_no, $rsale_id, $R, $due_reduce, $refund, $restore, $reason ?: null, $_SESSION['admin_user'] ?? '', $sale['branch_id'] ?? branch_id_for_insert()]);
                $return_id = (int) $pdo->lastInsertId();
                $insR = $pdo->prepare("INSERT INTO canteen_sale_return_items (return_id, sale_id, sale_item_id, menu_item_id, qty, line_amount, amount) VALUES (?,?,?,?,?,?,?)");
                foreach ($rlines as $l) { $insR->execute([$return_id, $rsale_id, $l['sale_item_id'], $l['menu_item_id'], $l['qty'], $l['line_amount'], $l['amount']]); }

                $pdo->prepare("UPDATE canteen_sales SET returned_amount = returned_amount + ?, due_amount = due_amount - ?, paid_amount = paid_amount - ? WHERE id = ?")
                    ->execute([$R, $due_reduce, $refund, $rsale_id]);
                if ($refund > 0) {
                    $pdo->prepare("INSERT INTO canteen_sale_payments (sale_id, amount, note) VALUES (?, ?, 'Refund')")->execute([$rsale_id, -$refund]);
                }

                // কাঁচামাল স্টক ফেরত (Recipe অনুযায়ী) — নষ্ট/বানানো হয়ে গেছে হলে টিক খুলে রাখুন
                if ($restore) {
                    $rec = $pdo->prepare("SELECT raw_material_id, qty_required FROM canteen_recipe_items WHERE menu_item_id = ?");
                    $back = [];
                    foreach ($rlines as $l) {
                        $rec->execute([$l['menu_item_id']]);
                        foreach ($rec->fetchAll() as $rr) {
                            $back[(int) $rr['raw_material_id']] = ($back[(int) $rr['raw_material_id']] ?? 0) + (float) $rr['qty_required'] * $l['qty'];
                        }
                    }
                    ksort($back);
                    foreach ($back as $mid => $qty_back) {
                        $m = canteen_lock_row($pdo, 'canteen_raw_materials', $mid, 'id');
                        if (!$m) { continue; }
                        $pdo->prepare("UPDATE canteen_raw_materials SET current_stock = current_stock + ? WHERE id = ?")->execute([$qty_back, $mid]);
                        $pdo->prepare("INSERT INTO canteen_stock_adjustments (raw_material_id, adj_type, qty, reason, branch_id) VALUES (?, 'add', ?, ?, ?)")
                            ->execute([$mid, $qty_back, 'Sale Return ' . $sale['invoice_no'] . ' (' . $return_no . ')', $sale['branch_id'] ?? branch_id_for_insert()]);
                    }
                }
                $pdo->commit();
                sec_log_activity($pdo, 'Canteen', 'Update', "Canteen Sale Return: {$return_no} ← {$sale['invoice_no']}, মূল্য ৳{$R}, Due কমেছে ৳{$due_reduce}, Refund ৳{$refund}");
                $_SESSION['canteen_msg'] = "✅ Return সম্পন্ন ({$return_no}): ৳" . number_format($R, 2) . ($due_reduce > 0 ? " | Due কমেছে ৳" . number_format($due_reduce, 2) : '') . ($refund > 0 ? " | ক্যাশ ফেরত ৳" . number_format($refund, 2) : '');
                header('Location: index.php?page=canteen_pos&pg=' . max(1, (int) ($_GET['pg'] ?? 1)));
                exit();
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $err = $e->getMessage();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('Canteen sale return failed: ' . $e->getMessage());
                $err = 'Return ব্যর্থ হয়েছে, আবার চেষ্টা করুন।';
            }
        }
    }
}

$msg = $msg ?: ($_SESSION['canteen_msg'] ?? '');
unset($_SESSION['canteen_msg']);

$last_invoice = null;
if (!empty($_GET['last_invoice'])) {
    $last_invoice = canteen_scoped_row($pdo, 'canteen_sales', (int) $_GET['last_invoice']) ?: null;
}

$settings = canteen_get_settings($pdo);
$cpos_where = []; $cpos_params = [];
branch_apply_filter($cpos_where, $cpos_params);
$cpos_where_sql = $cpos_where ? (' WHERE ' . implode(' AND ', $cpos_where)) : '';
[$hp_page, $hp_per, $hp_off] = canteen_page_params(50);
$cnt = $pdo->prepare("SELECT COUNT(*) FROM canteen_sales$cpos_where_sql");
$cnt->execute($cpos_params);
$sales_total = (int) $cnt->fetchColumn();
$cpos_stmt = $pdo->prepare("SELECT * FROM canteen_sales$cpos_where_sql ORDER BY id DESC LIMIT $hp_per OFFSET $hp_off");
$cpos_stmt->execute($cpos_params);
$sales = $cpos_stmt->fetchAll();

// Payment history + Return history + আইটেম (প্রতিটি sale-এর ডিটেইল সারির জন্য)
$pay_by_sale = $ret_by_sale = $items_by_sale = [];
if ($sales) {
    $sids = array_map('intval', array_column($sales, 'id'));
    $in = implode(',', $sids);
    foreach ($pdo->query("SELECT sale_id, amount, note, paid_at FROM canteen_sale_payments WHERE sale_id IN ($in) ORDER BY id")->fetchAll() as $r) { $pay_by_sale[(int) $r['sale_id']][] = $r; }
    foreach ($pdo->query("SELECT sale_id, return_no, total_return, due_reduced, refund_amount, restored_stock, reason, created_at FROM canteen_sale_returns WHERE sale_id IN ($in) ORDER BY id")->fetchAll() as $r) { $ret_by_sale[(int) $r['sale_id']][] = $r; }
    foreach ($pdo->query("SELECT si.id, si.sale_id, si.qty, si.rate, mi.name,
                                 COALESCE((SELECT SUM(ri.qty) FROM canteen_sale_return_items ri WHERE ri.sale_item_id = si.id),0) AS ret_qty
                          FROM canteen_sale_items si LEFT JOIN canteen_menu_items mi ON mi.id = si.menu_item_id
                          WHERE si.sale_id IN ($in) ORDER BY si.id")->fetchAll() as $r) { $items_by_sale[(int) $r['sale_id']][] = $r; }
}
$can_return = canteen_can_manage();
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
                <thead class="table-dark"><tr><th>Invoice No</th><th>Buyer</th><th>Type</th><th>Total</th><th>Paid</th><th>Due</th><th>Date</th><th>Print</th><th>Details</th></tr></thead>
                <tbody>
                    <?php if ($sales): foreach ($sales as $sl):
                        $badge = ['student' => 'bg-info', 'employee' => 'bg-primary', 'walkin' => 'bg-secondary'][$sl['buyer_type']] ?? 'bg-secondary';
                        $label = ['student' => 'Student', 'employee' => 'Teacher', 'walkin' => 'Walk-in'][$sl['buyer_type']] ?? 'Walk-in';
                    ?>
                        <tr>
                            <td><strong><?php echo cv($sl['invoice_no']); ?></strong></td>
                            <td><?php echo cv($sl['customer_name']); ?></td>
                            <td><span class="badge <?php echo $badge; ?>"><?php echo $label; ?></span></td>
                            <td>৳<?php echo number_format($sl['total_amount'], 2); ?>
                                <?php if ((float) $sl['returned_amount'] > 0): ?>
                                    <div class="small <?php echo (float) $sl['returned_amount'] >= (float) $sl['total_amount'] - 0.005 ? 'text-danger' : 'text-warning'; ?>">
                                        <?php echo (float) $sl['returned_amount'] >= (float) $sl['total_amount'] - 0.005 ? 'বাতিল (Cancelled)' : 'Return ৳' . number_format($sl['returned_amount'], 2); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-success">৳<?php echo number_format($sl['paid_amount'], 2); ?></td>
                            <td class="text-danger">৳<?php echo number_format($sl['due_amount'], 2); ?></td>
                            <td class="small text-muted"><?php echo cv($sl['created_at']); ?></td>
                            <td><a href="canteen_invoice_print.php?id=<?php echo (int) $sl['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-print"></i></a></td>
                            <td><button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('sd<?php echo (int) $sl['id']; ?>').classList.toggle('d-none')"><i class="fa-solid fa-list"></i> Payments<?php echo $can_return ? ' / Return' : ''; ?></button></td>
                        </tr>
                        <tr id="sd<?php echo (int) $sl['id']; ?>" class="d-none">
                            <td colspan="9" class="text-start bg-light">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="fw-bold mb-1">Payment History</div>
                                        <table class="table table-sm table-bordered mb-2 bg-white">
                                            <thead><tr><th>তারিখ</th><th>ধরন</th><th class="text-end">Amount</th></tr></thead>
                                            <tbody>
                                            <?php foreach (($pay_by_sale[(int) $sl['id']] ?? []) as $pp): $isRef = ((float) $pp['amount'] < 0); ?>
                                                <tr>
                                                    <td class="small"><?php echo cv($pp['paid_at']); ?></td>
                                                    <td><?php echo cv(['Initial' => 'প্রথম জমা', 'Installment' => 'Due আদায়', 'Refund' => 'ফেরত (Refund)'][$pp['note'] ?? ''] ?? ($pp['note'] ?: 'জমা')); ?></td>
                                                    <td class="text-end <?php echo $isRef ? 'text-danger' : 'text-success'; ?>"><?php echo $isRef ? '-' : ''; ?>৳<?php echo number_format(abs((float) $pp['amount']), 2); ?></td>
                                                </tr>
                                            <?php endforeach; if (empty($pay_by_sale[(int) $sl['id']])): ?>
                                                <tr><td colspan="3" class="text-muted text-center">কোনো পেমেন্ট নেই</td></tr>
                                            <?php endif; ?>
                                            </tbody>
                                        </table>
                                        <?php foreach (($ret_by_sale[(int) $sl['id']] ?? []) as $rr): ?>
                                            <div class="small text-danger">↩ <?php echo cv($rr['return_no']); ?> — ৳<?php echo number_format($rr['total_return'], 2); ?>
                                                (Due কমেছে ৳<?php echo number_format($rr['due_reduced'], 2); ?>, Refund ৳<?php echo number_format($rr['refund_amount'], 2); ?>, স্টক <?php echo $rr['restored_stock'] ? 'ফেরত' : 'ফেরত নয়'; ?>)
                                                <?php echo $rr['reason'] ? '— ' . cv($rr['reason']) : ''; ?> <span class="text-muted"><?php echo cv($rr['created_at']); ?></span></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php
                                    $sale_open = (float) $sl['total_amount'] - (float) $sl['returned_amount'] > 0.004;
                                    if ($can_return && $sale_open): ?>
                                    <div class="col-md-6">
                                        <div class="fw-bold mb-1">Sale Return / Cancel</div>
                                        <form method="POST" onsubmit="return confirm('এই Return নিশ্চিত করবেন?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="sale_id" value="<?php echo (int) $sl['id']; ?>">
                                            <table class="table table-sm table-bordered bg-white mb-2">
                                                <thead><tr><th>Item</th><th>বিক্রি</th><th>আগে ফেরত</th><th style="width:90px">এবার ফেরত</th></tr></thead>
                                                <tbody>
                                                <?php foreach (($items_by_sale[(int) $sl['id']] ?? []) as $it): $left = (int) $it['qty'] - (int) $it['ret_qty']; ?>
                                                    <tr>
                                                        <td><?php echo cv($it['name'] ?: '(মুছে ফেলা)'); ?></td>
                                                        <td><?php echo (int) $it['qty']; ?></td>
                                                        <td><?php echo (int) $it['ret_qty']; ?></td>
                                                        <td><input type="number" min="0" max="<?php echo $left; ?>" name="ret_qty[<?php echo (int) $it['id']; ?>]" value="0" class="form-control form-control-sm" <?php echo $left <= 0 ? 'disabled' : ''; ?>></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                            <input type="text" name="reason" class="form-control form-control-sm mb-2" placeholder="কারণ (ঐচ্ছিক)">
                                            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="restore_stock" value="1" checked id="rs<?php echo (int) $sl['id']; ?>"><label class="form-check-label small" for="rs<?php echo (int) $sl['id']; ?>">কাঁচামাল স্টকে ফেরত যাবে (খাবার বানিয়ে ফেলা/নষ্ট হলে টিক তুলে দিন)</label></div>
                                            <input type="hidden" name="sale_return" value="1">
                                            <button type="submit" class="btn btn-sm btn-warning">নির্বাচিত আইটেম Return</button>
                                            <button type="submit" name="cancel_all" value="1" class="btn btn-sm btn-danger">পুরো Sale বাতিল</button>
                                        </form>
                                        <div class="small text-muted mt-1">Due থাকলে আগে Due থেকে কাটা হবে, বাকিটা ক্যাশ Refund হিসেবে রেকর্ড হবে।</div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="9" class="text-muted">কোনো Sale নেই</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
            <?php echo canteen_pager_html($sales_total, $hp_page, $hp_per); ?>
        </div>
    </div>
</div>
<script>
// পেজিনেশন লিংকে (?pg=) এলে History ট্যাব নিজে খুলে যাবে
document.addEventListener('DOMContentLoaded', function () {
    if (new URLSearchParams(location.search).has('pg')) {
        const btn = document.querySelector('#posTabGroup .inv-tab-box:nth-child(2)');
        if (btn) { btn.click(); }
    }
});
</script>

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
