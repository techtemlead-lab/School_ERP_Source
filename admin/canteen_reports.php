<?php
// admin/canteen_reports.php - Canteen > Reports
require_once __DIR__ . '/canteen_common.php';
canteen_require_manage();

$f_date_from = $_GET['from'] ?? date('Y-m-01');
$f_date_to   = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date_from)) { $f_date_from = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date_to))   { $f_date_to   = date('Y-m-d'); }

// দৈনিক বিক্রি সারাংশ
$cr_bf_val = branch_current_id();
$cr_bf_sql = $cr_bf_val !== null ? ' AND (branch_id = ?)' : '';
$daily = $pdo->prepare(
    "SELECT DATE(created_at) AS d, COUNT(*) AS total_sales, SUM(total_amount - returned_amount) AS total_amount, SUM(paid_amount) AS total_paid, SUM(due_amount) AS total_due
     FROM canteen_sales WHERE DATE(created_at) BETWEEN ? AND ?$cr_bf_sql
     GROUP BY DATE(created_at) ORDER BY d DESC"
);
$daily->execute($cr_bf_val !== null ? [$f_date_from, $f_date_to, $cr_bf_val] : [$f_date_from, $f_date_to]);
$daily_rows = $daily->fetchAll();

// আইটেম-ভিত্তিক বিক্রি
$cr_bf_sql_s = $cr_bf_val !== null ? ' AND (s.branch_id = ?)' : '';
$ret_sub = "(SELECT x.sale_item_id, SUM(x.qty) AS rq, SUM(x.line_amount) AS ra FROM canteen_sale_return_items x GROUP BY x.sale_item_id)";
$item_wise = $pdo->prepare(
    "SELECT COALESCE(mi.name, '(মুছে ফেলা আইটেম)') AS name, SUM(si.qty - COALESCE(r.rq,0)) AS total_qty, SUM(si.amount - COALESCE(r.ra,0)) AS total_amount
     FROM canteen_sale_items si
     JOIN canteen_sales s ON s.id = si.sale_id
     LEFT JOIN $ret_sub r ON r.sale_item_id = si.id
     LEFT JOIN canteen_menu_items mi ON mi.id = si.menu_item_id
     WHERE DATE(s.created_at) BETWEEN ? AND ?$cr_bf_sql_s
     GROUP BY si.menu_item_id ORDER BY total_amount DESC"
);
$item_wise->execute($cr_bf_val !== null ? [$f_date_from, $f_date_to, $cr_bf_val] : [$f_date_from, $f_date_to]);
$item_rows = $item_wise->fetchAll();

// লো-স্টক এলার্ট
$cr_bf_sql_m = $cr_bf_val !== null ? ' AND (m.branch_id = ?)' : '';
$low_stock_stmt = $pdo->prepare(
    "SELECT m.*, u.name AS unit_name FROM canteen_raw_materials m LEFT JOIN canteen_units u ON u.id = m.unit_id
     WHERE m.current_stock <= m.reorder_level AND m.status = 'Active'$cr_bf_sql_m ORDER BY m.name ASC"
);
$low_stock_stmt->execute($cr_bf_val !== null ? [$cr_bf_val] : []);
$low_stock = $low_stock_stmt->fetchAll();

// বাকির সামারি
$due_stmt = $pdo->prepare("SELECT COUNT(*) AS cnt, SUM(due_amount) AS total FROM canteen_sales WHERE due_amount > 0$cr_bf_sql");
$due_stmt->execute($cr_bf_val !== null ? [$cr_bf_val] : []);
$due_summary = $due_stmt->fetch();

// অনুমানিক কাঁচামাল খরচ = বিক্রিত qty × Recipe qty × কাঁচামালের unit_cost (Recipe না থাকলে ০ ধরা হয়)
// যে Return-এ কাঁচামাল স্টকে ফিরেছে, সেই অংশের খরচ বাদ যায়
$cost_ret_sub = "(SELECT x.sale_item_id, SUM(x.qty) AS rq FROM canteen_sale_return_items x JOIN canteen_sale_returns t ON t.id = x.return_id AND t.restored_stock = 1 GROUP BY x.sale_item_id)";
$cost_stmt = $pdo->prepare(
    "SELECT COALESCE(SUM((si.qty - COALESCE(r.rq,0)) * ri.qty_required * rm.unit_cost), 0)
     FROM canteen_sale_items si
     JOIN canteen_sales s ON s.id = si.sale_id
     LEFT JOIN $cost_ret_sub r ON r.sale_item_id = si.id
     JOIN canteen_recipe_items ri ON ri.menu_item_id = si.menu_item_id
     JOIN canteen_raw_materials rm ON rm.id = ri.raw_material_id
     WHERE DATE(s.created_at) BETWEEN ? AND ?$cr_bf_sql_s"
);
$cost_stmt->execute($cr_bf_val !== null ? [$f_date_from, $f_date_to, $cr_bf_val] : [$f_date_from, $f_date_to]);
$period_material_cost = (float) $cost_stmt->fetchColumn();

$pur_stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) AS total, COALESCE(SUM(due_amount),0) AS due FROM canteen_purchases WHERE DATE(created_at) BETWEEN ? AND ?$cr_bf_sql");
$pur_stmt->execute($cr_bf_val !== null ? [$f_date_from, $f_date_to, $cr_bf_val] : [$f_date_from, $f_date_to]);
$pur_summary = $pur_stmt->fetch();
$sd_stmt = $pdo->prepare("SELECT COALESCE(SUM(due_amount),0) FROM canteen_purchases" . ($cr_bf_val !== null ? ' WHERE (branch_id = ?)' : ''));
$sd_stmt->execute($cr_bf_val !== null ? [$cr_bf_val] : []);
$supplier_due_all = (float) $sd_stmt->fetchColumn();

$period_total = array_sum(array_column($daily_rows, 'total_amount'));
$period_paid  = array_sum(array_column($daily_rows, 'total_paid'));
$period_due   = array_sum(array_column($daily_rows, 'total_due'));
?>

<div class="card-custom">
    <h4 class="fw-bold mb-4 text-dark"><i class="fa-solid fa-chart-line"></i> Canteen &raquo; Reports</h4>

    <form method="GET" class="row g-2 mb-4 align-items-end">
        <input type="hidden" name="page" value="canteen_reports">
        <div class="col-md-3"><label class="form-label small fw-bold">From</label><input type="date" name="from" class="form-control" value="<?php echo cv($f_date_from); ?>"></div>
        <div class="col-md-3"><label class="form-label small fw-bold">To</label><input type="date" name="to" class="form-control" value="<?php echo cv($f_date_to); ?>"></div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary fw-bold w-100" style="background-color:#1e3a8a;">Filter</button></div>
    </form>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card p-3 text-center"><div class="small text-muted">মোট বিক্রি (এই সময়ে)</div><div class="fs-4 fw-bold">৳<?php echo number_format($period_total, 2); ?></div></div></div>
        <div class="col-md-3"><div class="card p-3 text-center"><div class="small text-muted">মোট আদায়</div><div class="fs-4 fw-bold text-success">৳<?php echo number_format($period_paid, 2); ?></div></div></div>
        <div class="col-md-3"><div class="card p-3 text-center"><div class="small text-muted">এই সময়ের বাকি</div><div class="fs-4 fw-bold text-danger">৳<?php echo number_format($period_due, 2); ?></div></div></div>
        <div class="col-md-3"><div class="card p-3 text-center"><div class="small text-muted">সর্বমোট বকেয়া (সব সময়)</div><div class="fs-4 fw-bold text-danger">৳<?php echo number_format((float) ($due_summary['total'] ?? 0), 2); ?> (<?php echo (int) ($due_summary['cnt'] ?? 0); ?>টা)</div></div></div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card p-3 text-center"><div class="small text-muted">কাঁচামাল ক্রয় (এই সময়ে)</div><div class="fs-4 fw-bold">৳<?php echo number_format((float) $pur_summary['total'], 2); ?></div></div></div>
        <div class="col-md-3"><div class="card p-3 text-center"><div class="small text-muted">Supplier-এর কাছে বাকি (সব সময়)</div><div class="fs-4 fw-bold text-danger">৳<?php echo number_format($supplier_due_all, 2); ?></div></div></div>
        <div class="col-md-3"><div class="card p-3 text-center"><div class="small text-muted">অনুমানিক কাঁচামাল খরচ</div><div class="fs-4 fw-bold">৳<?php echo number_format($period_material_cost, 2); ?></div></div></div>
        <div class="col-md-3"><div class="card p-3 text-center"><div class="small text-muted">অনুমানিক লাভ (বিক্রি − খরচ)</div><div class="fs-4 fw-bold text-success">৳<?php echo number_format($period_total - $period_material_cost, 2); ?></div></div></div>
    </div>

    <h6 class="fw-bold mb-2">দৈনিক বিক্রি</h6>
    <div class="table-responsive mb-4">
        <table class="table table-bordered text-center align-middle">
            <thead class="table-dark"><tr><th>তারিখ</th><th>মোট Sale সংখ্যা</th><th>মোট</th><th>আদায়</th><th>বাকি</th></tr></thead>
            <tbody>
                <?php if ($daily_rows): foreach ($daily_rows as $r): ?>
                    <tr>
                        <td><?php echo cv($r['d']); ?></td>
                        <td><?php echo (int) $r['total_sales']; ?></td>
                        <td>৳<?php echo number_format((float) $r['total_amount'], 2); ?></td>
                        <td class="text-success">৳<?php echo number_format((float) $r['total_paid'], 2); ?></td>
                        <td class="text-danger">৳<?php echo number_format((float) $r['total_due'], 2); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" class="text-muted">এই সময়ে কোনো বিক্রি নেই</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h6 class="fw-bold mb-2">আইটেম-ভিত্তিক বিক্রি</h6>
    <div class="table-responsive mb-4">
        <table class="table table-bordered text-center align-middle">
            <thead class="table-dark"><tr><th>Item</th><th>মোট বিক্রি (Qty)</th><th>মোট আয়</th></tr></thead>
            <tbody>
                <?php if ($item_rows): foreach ($item_rows as $it): ?>
                    <tr><td><?php echo cv($it['name']); ?></td><td><?php echo (int) $it['total_qty']; ?></td><td>৳<?php echo number_format((float) $it['total_amount'], 2); ?></td></tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="3" class="text-muted">এই সময়ে কোনো বিক্রি নেই</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h6 class="fw-bold mb-2">⚠️ Low Stock Alert</h6>
    <div class="table-responsive">
        <table class="table table-bordered text-center align-middle">
            <thead class="table-dark"><tr><th>Raw Material</th><th>বর্তমান Stock</th><th>Reorder Level</th></tr></thead>
            <tbody>
                <?php if ($low_stock): foreach ($low_stock as $ls): ?>
                    <tr class="table-warning">
                        <td><?php echo cv($ls['name']); ?></td>
                        <td><?php echo number_format((float) $ls['current_stock'], 3); ?> <?php echo cv($ls['unit_name'] ?: ''); ?></td>
                        <td><?php echo number_format((float) $ls['reorder_level'], 3); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="3" class="text-success">সব কাঁচামালের Stock ঠিক আছে</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
