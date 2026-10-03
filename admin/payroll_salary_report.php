<?php
// admin/payroll_salary_report.php - Human Resource > Payroll Module > Employee Salary Report
// Finalized Salary Sheet-গুলো থেকে Employee-ভিত্তিক Salary ডেটা (Basic, Addition, Deduction, Net,
// Payment Status) ফিল্টার করে (Year, Month, Payscale, Employee Type, Payment Status, নাম/আইডি) দেখা যায়।

function srv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function sr_money($n): string { return number_format((float) $n, 2); }

$months = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];

// -----------------------------------------------------------
// ফিল্টার ফর্মের ডেটা (Payscale, Employee Type)
// -----------------------------------------------------------
$payscales = $pdo->query("SELECT id, title FROM payroll_payscales ORDER BY title ASC")->fetchAll();
$employee_types = $pdo->query("SELECT id, name FROM employee_types ORDER BY name ASC")->fetchAll();

// -----------------------------------------------------------
// ফিল্টার ভ্যালু (GET)
// -----------------------------------------------------------
$f_year        = (int) ($_GET['f_year'] ?? date('Y'));
$f_month       = (int) ($_GET['f_month'] ?? 0);      // 0 = All Months
$f_payscale    = (int) ($_GET['f_payscale'] ?? 0);   // 0 = All
$f_emp_type    = (int) ($_GET['f_emp_type'] ?? 0);   // 0 = All
$f_pay_status  = trim($_GET['f_pay_status'] ?? '');  // '' = All, Paid, Unpaid
$f_keyword     = trim($_GET['f_keyword'] ?? '');     // Employee Name / ID search

// -----------------------------------------------------------
// ডাইনামিক WHERE ক্লজ বিল্ড করা
// -----------------------------------------------------------
$where  = ["s.status = 'Finalized'", "s.year = ?"];
$params = [$f_year];

if ($f_month > 0) { $where[] = "s.month = ?"; $params[] = $f_month; }
if ($f_payscale > 0) { $where[] = "s.payscale_id = ?"; $params[] = $f_payscale; }
if ($f_emp_type > 0) { $where[] = "p.employee_type_id = ?"; $params[] = $f_emp_type; }
if ($f_pay_status === 'Paid' || $f_pay_status === 'Unpaid') { $where[] = "si.payment_status = ?"; $params[] = $f_pay_status; }
if ($f_keyword !== '') {
    $where[] = "(e.name LIKE ? OR e.employee_id LIKE ?)";
    $params[] = '%' . $f_keyword . '%';
    $params[] = '%' . $f_keyword . '%';
}

$sql = "SELECT si.*, e.employee_id AS emp_code, e.name AS emp_name, e.mobile AS emp_mobile,
               s.month, s.year, p.title AS payscale_title, et.name AS employee_type_name
        FROM payroll_salary_sheet_items si
        JOIN payroll_salary_sheets s ON s.id = si.sheet_id
        JOIN employees e ON e.id = si.employee_id
        LEFT JOIN payroll_payscales p ON p.id = s.payscale_id
        LEFT JOIN employee_types et ON et.id = p.employee_type_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY s.month ASC, e.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$t_basic = $t_add = $t_ded = $t_net = $t_paid = $t_due = 0.0;
$paid_count = $unpaid_count = 0;
foreach ($rows as $r) {
    $t_basic += (float) $r['basic_salary'];
    $t_add   += (float) $r['gross_addition'];
    $t_ded   += (float) $r['gross_deduction'];
    $t_net   += (float) $r['net_salary'];
    if ($r['payment_status'] === 'Paid') { $t_paid += (float) $r['net_salary']; $paid_count++; }
    else { $t_due += (float) $r['net_salary']; $unpaid_count++; }
}

$inst = $pdo->query("SELECT institute_name, phone_number, email, logo_path FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) { $inst = ['institute_name' => 'School Name', 'phone_number' => '', 'email' => '', 'logo_path' => null]; }
$fr_bid2 = branch_current_id();
if ($fr_bid2 !== null) {
    $branch_stmt2 = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $branch_stmt2->execute([$fr_bid2]);
    $branch = $branch_stmt2->fetch();
} else {
    $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
}
?>
<style>
@media print {
    @page { size: A4 landscape; margin: 12mm; }
    .no-print { display: none !important; }
    .sidebar, .breadcrumb-bar, .top-navbar { display: none !important; }
    .table-responsive { overflow: visible !important; }
    body { padding: 0 !important; margin: 0 !important; }
    .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; }
}
</style>

<div class="card-custom mb-3 no-print">
    <h6 class="fw-bold mb-3"><i class="fa-solid fa-chart-column"></i> Employee Salary Report — ফিল্টার</h6>
    <form method="GET" action="index.php" class="row g-3 align-items-end">
        <input type="hidden" name="page" value="payroll_salary_report">

        <div class="col-md-2">
            <label class="form-label fw-bold">Year <span class="text-danger">*</span></label>
            <input type="number" name="f_year" class="form-control" value="<?php echo (int) $f_year; ?>" required>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-bold">Month</label>
            <select name="f_month" class="form-select">
                <option value="0">All Months</option>
                <?php foreach ($months as $mi => $mn): ?>
                    <option value="<?php echo $mi; ?>" <?php echo $f_month === $mi ? 'selected' : ''; ?>><?php echo $mn; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-bold">Payscale</label>
            <select name="f_payscale" class="form-select">
                <option value="0">All Payscales</option>
                <?php foreach ($payscales as $ps): ?>
                    <option value="<?php echo (int) $ps['id']; ?>" <?php echo $f_payscale === (int) $ps['id'] ? 'selected' : ''; ?>><?php echo srv($ps['title']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-bold">Employee Type</label>
            <select name="f_emp_type" class="form-select">
                <option value="0">All Types</option>
                <?php foreach ($employee_types as $et): ?>
                    <option value="<?php echo (int) $et['id']; ?>" <?php echo $f_emp_type === (int) $et['id'] ? 'selected' : ''; ?>><?php echo srv($et['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-bold">Payment Status</label>
            <select name="f_pay_status" class="form-select">
                <option value="">All</option>
                <option value="Paid" <?php echo $f_pay_status === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                <option value="Unpaid" <?php echo $f_pay_status === 'Unpaid' ? 'selected' : ''; ?>>Unpaid</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-bold">Employee Name / ID</label>
            <input type="text" name="f_keyword" class="form-control" placeholder="Search..." value="<?php echo srv($f_keyword); ?>">
        </div>

        <div class="col-12 text-end">
            <button type="submit" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
        </div>
    </form>
</div>

<div class="card-custom">
    <div class="d-flex justify-content-end mb-3 no-print">
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print Report</button>
    </div>

    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo srv($inst['logo_path']); ?>" style="max-width:45px;max-height:45px;"><?php endif; ?>
            <div>
                <h6 class="fw-bold text-primary mb-0"><?php echo srv($inst['institute_name']); ?></h6>
                <?php if (!empty($inst['phone_number'])): ?><div class="small text-muted">Phone: <?php echo srv($inst['phone_number']); ?></div><?php endif; ?>
                <?php if (!empty($inst['email'])): ?><div class="small text-primary"><?php echo srv($inst['email']); ?></div><?php endif; ?>
            </div>
        </div>
        <?php if ($branch): ?>
            <div class="text-end">
                <?php if (!empty($branch['branch_name']) && trim($branch['branch_name']) !== trim($inst['institute_name'])): ?>
                    <div class="fw-bold small"><?php echo srv($branch['branch_name']); ?></div>
                <?php endif; ?>
                <?php if (!empty($branch['address'])): ?>
                    <div class="small text-muted"><?php echo srv($branch['address']); ?><?php echo $branch['city'] ? ', ' . srv($branch['city']) : ''; ?><?php echo $branch['zip_code'] ? '-' . srv($branch['zip_code']) : ''; ?></div>
                <?php endif; ?>
                <?php if (!empty($branch['phone'])): ?><div class="small text-muted">Phone: <?php echo srv($branch['phone']); ?></div><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <hr class="mb-3">

    <div class="text-center mb-3">
        <h5 class="fw-bold mb-1">Employee Salary Report</h5>
        <div class="small text-muted">
            <?php echo $f_month > 0 ? srv($months[$f_month]) . ' ' : 'January – December '; ?><?php echo (int) $f_year; ?>
            <?php if ($f_payscale > 0): foreach ($payscales as $ps) { if ((int) $ps['id'] === $f_payscale) { echo ' | Payscale: ' . srv($ps['title']); break; } } endif; ?>
            <?php if ($f_emp_type > 0): foreach ($employee_types as $et) { if ((int) $et['id'] === $f_emp_type) { echo ' | Type: ' . srv($et['name']); break; } } endif; ?>
            <?php if ($f_pay_status !== ''): ?> | Status: <?php echo srv($f_pay_status); ?><?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-3 no-print">
        <div class="col-6 col-md-3">
            <div class="border rounded p-2 text-center">
                <div class="small text-muted">Total Records</div>
                <div class="fw-bold fs-5"><?php echo count($rows); ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="border rounded p-2 text-center">
                <div class="small text-muted">Total Net Salary</div>
                <div class="fw-bold fs-5"><?php echo sr_money($t_net); ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="border rounded p-2 text-center bg-success bg-opacity-10">
                <div class="small text-muted">Paid (<?php echo $paid_count; ?>)</div>
                <div class="fw-bold fs-5 text-success"><?php echo sr_money($t_paid); ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="border rounded p-2 text-center bg-danger bg-opacity-10">
                <div class="small text-muted">Due (<?php echo $unpaid_count; ?>)</div>
                <div class="fw-bold fs-5 text-danger"><?php echo sr_money($t_due); ?></div>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered align-middle table-sm">
            <thead class="table-dark">
                <tr>
                    <th>Srl.</th>
                    <th>Month/Year</th>
                    <th>Employee ID</th>
                    <th>Name</th>
                    <th>Payscale</th>
                    <th class="text-end">Basic</th>
                    <th class="text-end">Addition</th>
                    <th class="text-end">Deduction</th>
                    <th class="text-end">Net Salary</th>
                    <th>Status</th>
                    <th>Payment Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): $srl = 1; foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo $srl++; ?></td>
                        <td><?php echo srv($months[(int) $r['month']] ?? $r['month']); ?> <?php echo (int) $r['year']; ?></td>
                        <td><?php echo srv($r['emp_code']); ?></td>
                        <td><?php echo srv($r['emp_name']); ?></td>
                        <td><?php echo srv($r['payscale_title']); ?></td>
                        <td class="text-end"><?php echo sr_money($r['basic_salary']); ?></td>
                        <td class="text-end"><?php echo sr_money($r['gross_addition']); ?></td>
                        <td class="text-end"><?php echo sr_money($r['gross_deduction']); ?></td>
                        <td class="text-end fw-bold"><?php echo sr_money($r['net_salary']); ?></td>
                        <td><?php echo $r['payment_status'] === 'Paid' ? '<span class="badge bg-success">Paid</span>' : '<span class="badge bg-secondary">Unpaid</span>'; ?></td>
                        <td><?php echo $r['payment_date'] ? srv(date('d M Y', strtotime($r['payment_date']))) : '—'; ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="11" class="text-center text-muted py-3">এই ফিল্টারে কোনো ডেটা পাওয়া যায়নি।</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if ($rows): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="5" class="text-end">Total</td>
                    <td class="text-end"><?php echo sr_money($t_basic); ?></td>
                    <td class="text-end"><?php echo sr_money($t_add); ?></td>
                    <td class="text-end"><?php echo sr_money($t_ded); ?></td>
                    <td class="text-end"><?php echo sr_money($t_net); ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
