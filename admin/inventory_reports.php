<?php
// admin/inventory_reports.php - Inventory & Sales > Reports
require_once __DIR__ . '/inventory_common.php';

// ---------- Date Filter ----------
$from_date_input = trim($_GET['from_date'] ?? '');
$to_date_input   = trim($_GET['to_date'] ?? '');
$from_date = $from_date_input !== '' ? $from_date_input : '2000-01-01';
$to_date   = $to_date_input !== '' ? $to_date_input : date('Y-m-d');
$from_dt = $from_date . ' 00:00:00';
$to_dt   = $to_date . ' 23:59:59';

// ---------- Sales / Purchase (তারিখ অনুযায়ী ফিল্টার করা) ----------
$ir_bf_sql = ''; $ir_bf_val = branch_current_id();
if ($ir_bf_val !== null) { $ir_bf_sql = ' AND (branch_id = ?)'; }

$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales_invoices WHERE date BETWEEN ? AND ?$ir_bf_sql");
$stmt->execute($ir_bf_val !== null ? [$from_dt, $to_dt, $ir_bf_val] : [$from_dt, $to_dt]);
$r_total_sales = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM inv_purchases WHERE created_at BETWEEN ? AND ?$ir_bf_sql");
$stmt->execute($ir_bf_val !== null ? [$from_dt, $to_dt, $ir_bf_val] : [$from_dt, $to_dt]);
$r_total_purchase = (float) $stmt->fetchColumn();

// বর্তমান মুহূর্তের বকেয়া — এটা তারিখ ফিল্টার দিয়ে বদলায় না, সবসময় "এখন" এর হিসাব
$stmt = $pdo->prepare("SELECT COALESCE(SUM(due_amount),0) FROM sales_invoices" . ($ir_bf_val !== null ? ' WHERE (branch_id = ?)' : ''));
$stmt->execute($ir_bf_val !== null ? [$ir_bf_val] : []);
$r_sales_due = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(due_amount),0) FROM inv_purchases" . ($ir_bf_val !== null ? ' WHERE (branch_id = ?)' : ''));
$stmt->execute($ir_bf_val !== null ? [$ir_bf_val] : []);
$r_supplier_due = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(stock_qty * cost_price),0) FROM inventory_items" . ($ir_bf_val !== null ? ' WHERE (branch_id = ?)' : ''));
$stmt->execute($ir_bf_val !== null ? [$ir_bf_val] : []);
$r_stock_value = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT name, stock_qty, reorder_level FROM inventory_items WHERE reorder_level > 0 AND stock_qty <= reorder_level" . ($ir_bf_val !== null ? ' AND (branch_id = ?)' : ''));
$stmt->execute($ir_bf_val !== null ? [$ir_bf_val] : []);
$r_low_stock = $stmt->fetchAll();

// ---------- Profit (নির্বাচিত তারিখের মধ্যে বিক্রি হওয়া Item-এর Sale Price - Cost Price) ----------
// (i.branch_id দিয়েই স্কোপ হয়ে যায়, যেহেতু আইটেম নিজেই branch-owned)
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(si.amount - (si.qty * i.cost_price)), 0)
     FROM inv_sale_items si
     JOIN inventory_items i ON i.id = si.item_id
     JOIN sales_invoices s ON s.id = si.sale_id
     WHERE s.date BETWEEN ? AND ?" . ($ir_bf_val !== null ? ' AND (i.branch_id = ?)' : '')
);
$stmt->execute($ir_bf_val !== null ? [$from_dt, $to_dt, $ir_bf_val] : [$from_dt, $to_dt]);
$r_gross_profit = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(discount_amount), 0) FROM sales_invoices WHERE date BETWEEN ? AND ?$ir_bf_sql");
$stmt->execute($ir_bf_val !== null ? [$from_dt, $to_dt, $ir_bf_val] : [$from_dt, $to_dt]);
$r_total_discount = (float) $stmt->fetchColumn();

$r_profit = $r_gross_profit - $r_total_discount;

// ---------- Loss (Damage/Lost হিসেবে Stock Adjustment থেকে, Cost Price ভিত্তিক ক্ষতির মূল্য) ----------
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(a.qty * i.cost_price), 0)
     FROM inv_stock_adjustments a
     JOIN inventory_items i ON i.id = a.item_id
     WHERE a.adj_type IN ('Damage','Lost') AND a.created_at BETWEEN ? AND ?" . ($ir_bf_val !== null ? ' AND (i.branch_id = ?)' : '')
);
$stmt->execute($ir_bf_val !== null ? [$from_dt, $to_dt, $ir_bf_val] : [$from_dt, $to_dt]);
$r_loss = (float) $stmt->fetchColumn();

$r_net = $r_profit - $r_loss;

// ---------- Loss Breakdown (Item ভিত্তিক) ----------
$stmt = $pdo->prepare(
    "SELECT i.name, a.adj_type, SUM(a.qty) AS total_qty, SUM(a.qty * i.cost_price) AS total_value
     FROM inv_stock_adjustments a
     JOIN inventory_items i ON i.id = a.item_id
     WHERE a.adj_type IN ('Damage','Lost') AND a.created_at BETWEEN ? AND ?" . ($ir_bf_val !== null ? ' AND (i.branch_id = ?)' : '') . "
     GROUP BY i.id, a.adj_type
     ORDER BY total_value DESC"
);
$stmt->execute($ir_bf_val !== null ? [$from_dt, $to_dt, $ir_bf_val] : [$from_dt, $to_dt]);
$r_loss_breakdown = $stmt->fetchAll();
?>

<div class="card-custom">
    <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-chart-line"></i> Inventory & Sales &raquo; Reports</h4>
    <p class="text-muted mb-4" style="font-size:13px;">সামগ্রিক Sales/Purchase/Profit/Loss/Stock এর সারসংক্ষেপ।</p>

    <!-- Date Filter -->
    <form method="GET" class="row g-2 align-items-end mb-4 no-print">
        <input type="hidden" name="page" value="inventory_reports">
        <div class="col-md-3">
            <label class="form-label small mb-1">From Date</label>
            <input type="date" name="from_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($from_date_input); ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">To Date</label>
            <input type="date" name="to_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($to_date_input); ?>">
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-secondary btn-sm w-100">Filter</button></div>
        <div class="col-md-2"><a href="index.php?page=inventory_reports" class="btn btn-outline-secondary btn-sm w-100">Reset</a></div>
        <div class="col-md-2"><button type="button" class="btn btn-primary btn-sm w-100" onclick="invPrintReport()"><i class="fa-solid fa-print"></i> Print</button></div>
    </form>

    <div id="reportsTabGroup">
        <div class="inv-tab-boxes no-print">
            <button type="button" class="inv-tab-box active" onclick="invTabSwitch('reportsTabGroup','paneSummary',this)">Summary</button>
            <button type="button" class="inv-tab-box" onclick="invTabSwitch('reportsTabGroup','paneProfitLoss',this)">Profit & Loss</button>
            <button type="button" class="inv-tab-box" onclick="invTabSwitch('reportsTabGroup','paneLowStock',this)">Low Stock</button>
        </div>

        <div id="reportsPrintArea">
        <p class="small text-muted print-only-range" style="display:none;">Report Range: <?php echo htmlspecialchars($from_date); ?> থেকে <?php echo htmlspecialchars($to_date); ?></p>

        <div id="paneSummary" class="inv-tab-pane card p-3 mb-4">
            <h6 class="fw-bold">Summary (<?php echo htmlspecialchars($from_date); ?> — <?php echo htmlspecialchars($to_date); ?>)</h6>
            <div class="row g-3">
                <div class="col-md-4"><div class="card p-3 text-center"><div class="small text-muted">Total Sales</div><div class="fw-bold fs-5">৳<?php echo number_format($r_total_sales,2); ?></div></div></div>
                <div class="col-md-4"><div class="card p-3 text-center"><div class="small text-muted">Total Purchase</div><div class="fw-bold fs-5">৳<?php echo number_format($r_total_purchase,2); ?></div></div></div>
                <div class="col-md-4"><div class="card p-3 text-center"><div class="small text-muted">Stock Valuation (Cost ভিত্তিক)</div><div class="fw-bold fs-5">৳<?php echo number_format($r_stock_value,2); ?></div></div></div>
                <div class="col-md-4"><div class="card p-3 text-center"><div class="small text-muted">Total Sales Due (এখন)</div><div class="fw-bold fs-5 text-danger">৳<?php echo number_format($r_sales_due,2); ?></div></div></div>
                <div class="col-md-4"><div class="card p-3 text-center"><div class="small text-muted">Total Supplier Due (এখন)</div><div class="fw-bold fs-5 text-danger">৳<?php echo number_format($r_supplier_due,2); ?></div></div></div>
            </div>
        </div>

        <div id="paneProfitLoss" class="inv-tab-pane card p-3 mb-4 d-none">
            <h6 class="fw-bold">Profit & Loss (<?php echo htmlspecialchars($from_date); ?> — <?php echo htmlspecialchars($to_date); ?>)</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-4"><div class="card p-3 text-center"><div class="small text-muted">Profit (Sales থেকে)</div><div class="fw-bold fs-5 text-success">৳<?php echo number_format($r_profit,2); ?></div></div></div>
                <div class="col-md-4"><div class="card p-3 text-center"><div class="small text-muted">Loss (Damage/Lost থেকে)</div><div class="fw-bold fs-5 text-danger">৳<?php echo number_format($r_loss,2); ?></div></div></div>
                <div class="col-md-4"><div class="card p-3 text-center"><div class="small text-muted">Net (Profit - Loss)</div><div class="fw-bold fs-5 <?php echo $r_net >= 0 ? 'text-success' : 'text-danger'; ?>">৳<?php echo number_format($r_net,2); ?></div></div></div>
            </div>
            <p class="small text-muted mb-2">Profit = বিক্রিত Item-এর (Sale Price - Cost Price) যোগফল, বিলে দেওয়া Discount বাদ দিয়ে। Loss = Stock Adjustment-এ Damage/Lost হিসেবে এন্ট্রি হওয়া Item-এর Cost Price ভিত্তিক মূল্য।</p>

            <h6 class="fw-bold mt-3">Loss Breakdown (Item ভিত্তিক)</h6>
            <table class="table table-bordered text-center align-middle">
                <thead class="table-dark"><tr><th>Item</th><th>Type</th><th>Total Qty</th><th>Value (Cost ভিত্তিক)</th></tr></thead>
                <tbody>
                    <?php if ($r_loss_breakdown): foreach ($r_loss_breakdown as $lb): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($lb['name']); ?></td>
                            <td><?php echo htmlspecialchars($lb['adj_type']); ?></td>
                            <td><?php echo (int)$lb['total_qty']; ?></td>
                            <td class="text-danger fw-bold">৳<?php echo number_format($lb['total_value'],2); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="4" class="text-success">এই সময়সীমায় কোনো Damage/Lost এন্ট্রি নেই!</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="paneLowStock" class="inv-tab-pane card p-3 mb-4 d-none">
            <h6 class="fw-bold">Low Stock Items (বর্তমান)</h6>
            <table class="table table-bordered text-center align-middle">
                <thead class="table-dark"><tr><th>Item</th><th>Current Stock</th><th>Reorder Level</th></tr></thead>
                <tbody>
                    <?php if ($r_low_stock): foreach ($r_low_stock as $ls): ?>
                        <tr><td><?php echo htmlspecialchars($ls['name']); ?></td><td class="text-danger fw-bold"><?php echo (int)$ls['stock_qty']; ?></td><td><?php echo (int)$ls['reorder_level']; ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="3" class="text-success">কোনো Low Stock Item নেই!</td></tr>
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
function invTabSwitch(groupId, targetId, btn) {
    const group = document.getElementById(groupId);
    group.querySelectorAll('.inv-tab-pane').forEach(p => p.classList.add('d-none'));
    document.getElementById(targetId).classList.remove('d-none');
    group.querySelectorAll('.inv-tab-box').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

// Print করার সময় Sidebar/বাটন বাদ দিয়ে শুধু Summary + Profit & Loss + Low Stock — সব একসাথে প্রিন্ট হবে
function invPrintReport() {
    const content = document.getElementById('reportsPrintArea').innerHTML;
    const win = window.open('', '_blank');
    win.document.write(`
        <html>
        <head>
            <title>Inventory & Sales Report</title>
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
            <style>
                body { padding: 24px; font-family: 'Segoe UI', Tahoma, sans-serif; }
                .inv-tab-pane { display: block !important; margin-bottom: 24px; border: 1px solid #dee2e6; padding: 14px; }
                .print-only-range { display: block !important; margin-bottom: 16px; }
                h6 { margin-top: 8px; }
            </style>
        </head>
        <body>
            <h4 class="fw-bold mb-3">Inventory & Sales Report</h4>
            ${content}
        </body>
        </html>
    `);
    win.document.close();
    win.focus();
    setTimeout(function () { win.print(); }, 350);
}
</script>
