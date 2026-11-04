<?php
// admin/payroll_payslip_print.php - Human Resource > Payroll Module > Employee Pay Slip Print
// Finalized Salary Sheet থেকে একজন নির্দিষ্ট Employee-এর জন্য ইনডিভিজুয়াল প্রিন্টযোগ্য Pay Slip
// (Basic + Addition/Deduction হেড-ভিত্তিক ব্রেকডাউন, Net Salary, Payment Status সহ) তৈরি করা হয়।

function pspv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function psp_money($n): string { return number_format((float) $n, 2); }

$months = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];

if (!function_exists('sp_ensure_column')) {
    function sp_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
    {
        $chk = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $chk->execute([$table, $column]);
        if (!$chk->fetch()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }
}
try {
    if ($pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_salary_sheet_items'")->fetchColumn()) {
        sp_ensure_column($pdo, 'payroll_salary_sheet_items', 'payment_status', "ENUM('Unpaid','Paid') NOT NULL DEFAULT 'Unpaid'");
        sp_ensure_column($pdo, 'payroll_salary_sheet_items', 'payment_method', 'VARCHAR(20) NULL');
        sp_ensure_column($pdo, 'payroll_salary_sheet_items', 'payment_date', 'DATE NULL');
        sp_ensure_column($pdo, 'payroll_salary_sheet_items', 'payment_reference', 'VARCHAR(100) NULL');
        sp_ensure_column($pdo, 'payroll_salary_sheet_items', 'payment_remarks', 'VARCHAR(255) NULL');
        sp_ensure_column($pdo, 'payroll_salary_sheet_items', 'paid_by', 'VARCHAR(100) NULL');
        sp_ensure_column($pdo, 'payroll_salary_sheet_items', 'paid_at', 'TIMESTAMP NULL');
    }
} catch (Throwable $e) {
}

$view = $_GET['view'] ?? 'list';
?>

<?php if ($view === 'print'):
    // =====================================================================
    // একজন Employee-এর Pay Slip প্রিন্ট
    // =====================================================================
    $item_id = (int) ($_GET['item_id'] ?? 0);

    $stmt = $pdo->prepare(
        "SELECT si.*, e.employee_id AS emp_code, e.name AS emp_name, e.mobile AS emp_mobile,
                e.bank_account_no, e.photo_path, d.title AS designation_name,
                s.month, s.year, s.working_days, s.status AS sheet_status,
                p.title AS payscale_title, et.name AS employee_type_name
         FROM payroll_salary_sheet_items si
         JOIN payroll_salary_sheets s ON s.id = si.sheet_id
         JOIN employees e ON e.id = si.employee_id
         LEFT JOIN payroll_payscales p ON p.id = s.payscale_id
         LEFT JOIN employee_types et ON et.id = p.employee_type_id
         LEFT JOIN designations d ON d.id = e.designation_id
         WHERE si.id = ?"
    );
    $stmt->execute([$item_id]);
    $slip = $stmt->fetch();

    if (!$slip) {
        echo '<div class="card-custom"><div class="alert alert-danger py-2 mb-0">Pay Slip পাওয়া যায়নি।</div></div>';
        return;
    }

    $breakdown = json_decode((string) ($slip['breakdown_json'] ?? ''), true);
    $additions = [];
    $deductions = [];
    if (is_array($breakdown) && isset($breakdown['additions'])) {
        // নতুন ফরম্যাট: প্রতিটা Head আলাদা করে সেভ করা আছে
        foreach ($breakdown['additions'] ?? [] as $a) { $additions[] = [$a['head'] ?? 'Addition', (float) ($a['amount'] ?? 0)]; }
        foreach ($breakdown['deductions'] ?? [] as $d) { $deductions[] = [$d['head'] ?? 'Deduction', (float) ($d['amount'] ?? 0)]; }
    } else {
        // পুরনো Salary Sheet (আগে জেনারেট করা) — শুধু সমষ্টি জানা আছে, হেড-ভিত্তিক ব্রেকডাউন নেই
        if ((float) $slip['gross_addition'] > 0) { $additions[] = ['Total Addition', (float) $slip['gross_addition']]; }
        if ((float) $slip['gross_deduction'] > 0) { $deductions[] = ['Total Deduction', (float) $slip['gross_deduction']]; }
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
    $csrf = htmlspecialchars(csrf_token());
    ?>
    <style>
    @media print {
        @page { size: A4; margin: 15mm; }
        .no-print { display: none !important; }
        .sidebar, .breadcrumb-bar, .top-navbar { display: none !important; }
        body { padding: 0 !important; margin: 0 !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; }
    }
    .psp-box { border: 1px solid #dee2e6; border-radius: 6px; }
    .psp-box table td { padding: 6px 10px; }
    </style>

    <div class="no-print d-flex justify-content-between align-items-center mb-3">
        <a href="index.php?page=payroll_payslip_print" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print Pay Slip</button>
    </div>

    <div class="card-custom" style="max-width:820px;margin:0 auto;">
        <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo pspv($inst['logo_path']); ?>" style="max-width:45px;max-height:45px;"><?php endif; ?>
                <div>
                    <h6 class="fw-bold text-primary mb-0"><?php echo pspv($inst['institute_name']); ?></h6>
                    <?php if (!empty($inst['phone_number'])): ?><div class="small text-muted">Phone: <?php echo pspv($inst['phone_number']); ?></div><?php endif; ?>
                    <?php if (!empty($inst['email'])): ?><div class="small text-primary"><?php echo pspv($inst['email']); ?></div><?php endif; ?>
                </div>
            </div>
            <?php if ($branch): ?>
                <div class="text-end">
                    <?php if (!empty($branch['branch_name']) && trim($branch['branch_name']) !== trim($inst['institute_name'])): ?>
                        <div class="fw-bold small"><?php echo pspv($branch['branch_name']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($branch['address'])): ?>
                        <div class="small text-muted"><?php echo pspv($branch['address']); ?><?php echo $branch['city'] ? ', ' . pspv($branch['city']) : ''; ?></div>
                    <?php endif; ?>
                    <?php if (!empty($branch['phone']) || !empty($inst['phone_number'])): ?>
                        <div class="small text-muted">Phone: <?php echo pspv($branch['phone'] ?: $inst['phone_number']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <hr class="mb-3">

        <div class="text-center mb-4">
            <h5 class="fw-bold mb-0">Pay Slip</h5>
            <div class="small text-muted">For the month of <?php echo pspv($months[(int) $slip['month']] ?? $slip['month']); ?> <?php echo (int) $slip['year']; ?></div>
        </div>

        <div class="psp-box mb-4">
            <table class="table table-borderless mb-0">
                <tbody>
                    <tr>
                        <td class="text-muted" style="width:150px;">Employee ID</td>
                        <td class="fw-bold"><?php echo pspv($slip['emp_code']); ?></td>
                        <td class="text-muted" style="width:150px;">Name</td>
                        <td class="fw-bold"><?php echo pspv($slip['emp_name']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Designation</td>
                        <td><?php echo pspv($slip['designation_name'] ?? '—'); ?></td>
                        <td class="text-muted">Employee Type</td>
                        <td><?php echo pspv($slip['employee_type_name'] ?? '—'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Payscale</td>
                        <td><?php echo pspv($slip['payscale_title']); ?></td>
                        <td class="text-muted">Working Days</td>
                        <td><?php echo (int) $slip['working_days']; ?> (Absent: <?php echo (int) $slip['absent_days']; ?>)</td>
                    </tr>
                    <?php if (!empty($slip['bank_account_no'])): ?>
                    <tr>
                        <td class="text-muted">Bank A/C No</td>
                        <td colspan="3"><?php echo pspv($slip['bank_account_no']); ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="psp-box h-100">
                    <div class="bg-success bg-opacity-10 fw-bold px-3 py-2 border-bottom">Earnings</div>
                    <table class="table table-borderless mb-0">
                        <tbody>
                            <tr>
                                <td>Basic Salary</td>
                                <td class="text-end"><?php echo psp_money($slip['basic_salary']); ?></td>
                            </tr>
                            <?php foreach ($additions as $a): ?>
                                <tr>
                                    <td><?php echo pspv($a[0]); ?></td>
                                    <td class="text-end"><?php echo psp_money($a[1]); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="border-top fw-bold">
                                <td>Total Earnings</td>
                                <td class="text-end"><?php echo psp_money((float) $slip['basic_salary'] + (float) $slip['gross_addition']); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="col-md-6">
                <div class="psp-box h-100">
                    <div class="bg-danger bg-opacity-10 fw-bold px-3 py-2 border-bottom">Deductions</div>
                    <table class="table table-borderless mb-0">
                        <tbody>
                            <?php if ($deductions): foreach ($deductions as $d): ?>
                                <tr>
                                    <td><?php echo pspv($d[0]); ?></td>
                                    <td class="text-end"><?php echo psp_money($d[1]); ?></td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td class="text-muted">No Deductions</td><td class="text-end">0.00</td></tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr class="border-top fw-bold">
                                <td>Total Deductions</td>
                                <td class="text-end"><?php echo psp_money($slip['gross_deduction']); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="psp-box mb-4 bg-light">
            <div class="d-flex justify-content-between align-items-center px-3 py-3">
                <span class="fw-bold fs-5">Net Salary</span>
                <span class="fw-bold fs-4 text-primary"><?php echo psp_money($slip['net_salary']); ?></span>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-6">
                <span class="text-muted">Payment Status: </span>
                <?php echo $slip['payment_status'] === 'Paid' ? '<span class="badge bg-success">Paid</span>' : '<span class="badge bg-secondary">Unpaid</span>'; ?>
            </div>
            <div class="col-6 text-end">
                <?php if ($slip['payment_status'] === 'Paid'): ?>
                    <span class="text-muted">Paid on <?php echo $slip['payment_date'] ? pspv(date('d M Y', strtotime($slip['payment_date']))) : '—'; ?> via <?php echo pspv($slip['payment_method']); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="row mt-5 pt-4">
            <div class="col-4 text-center">
                <div class="border-top pt-2">Employee Signature</div>
            </div>
            <div class="col-4 text-center">
                <div class="border-top pt-2">Accountant</div>
            </div>
            <div class="col-4 text-center">
                <div class="border-top pt-2">Authorized Signature</div>
            </div>
        </div>
    </div>

<?php else:
    // =====================================================================
    // Finalized Salary Sheet ফিল্টার করে Employee লিস্ট থেকে Pay Slip বাছাই (LIST VIEW)
    // =====================================================================
    $f_year     = (int) ($_GET['f_year'] ?? date('Y'));
    $f_month    = (int) ($_GET['f_month'] ?? date('n'));
    $f_payscale = (int) ($_GET['f_payscale'] ?? 0);
    $f_keyword  = trim($_GET['f_keyword'] ?? '');

    $payscales = $pdo->query("SELECT id, title FROM payroll_payscales ORDER BY title ASC")->fetchAll();

    $where = ["s.status = 'Finalized'", "s.year = ?", "s.month = ?"];
    $params = [$f_year, $f_month];
    if ($f_payscale > 0) { $where[] = "s.payscale_id = ?"; $params[] = $f_payscale; }
    if ($f_keyword !== '') {
        $where[] = "(e.name LIKE ? OR e.employee_id LIKE ?)";
        $params[] = '%' . $f_keyword . '%';
        $params[] = '%' . $f_keyword . '%';
    }

    $sql = "SELECT si.id AS item_id, e.employee_id AS emp_code, e.name AS emp_name, si.net_salary, si.payment_status,
                   p.title AS payscale_title
            FROM payroll_salary_sheet_items si
            JOIN payroll_salary_sheets s ON s.id = si.sheet_id
            JOIN employees e ON e.id = si.employee_id
            LEFT JOIN payroll_payscales p ON p.id = s.payscale_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY e.name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom mb-3">
        <h6 class="fw-bold mb-3"><i class="fa-solid fa-file-invoice"></i> Employee Pay Slip Print — ফিল্টার</h6>
        <form method="GET" action="index.php" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="payroll_payslip_print">
            <div class="col-md-2">
                <label class="form-label fw-bold">Year <span class="text-danger">*</span></label>
                <input type="number" name="f_year" class="form-control" value="<?php echo (int) $f_year; ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Month <span class="text-danger">*</span></label>
                <select name="f_month" class="form-select" required>
                    <?php foreach ($months as $mi => $mn): ?>
                        <option value="<?php echo $mi; ?>" <?php echo $f_month === $mi ? 'selected' : ''; ?>><?php echo $mn; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Payscale</label>
                <select name="f_payscale" class="form-select">
                    <option value="0">All Payscales</option>
                    <?php foreach ($payscales as $ps): ?>
                        <option value="<?php echo (int) $ps['id']; ?>" <?php echo $f_payscale === (int) $ps['id'] ? 'selected' : ''; ?>><?php echo pspv($ps['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Employee Name / ID</label>
                <input type="text" name="f_keyword" class="form-control" placeholder="Search..." value="<?php echo pspv($f_keyword); ?>">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100 fw-bold" style="background-color:#1e3a8a;"><i class="fa-solid fa-magnifying-glass"></i></button>
            </div>
        </form>
    </div>

    <div class="card-custom">
        <h6 class="fw-bold mb-3">
            <?php echo pspv($months[$f_month] ?? $f_month); ?> <?php echo (int) $f_year; ?> — Employee লিস্ট
        </h6>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr><th>Srl.</th><th>Employee ID</th><th>Name</th><th>Payscale</th><th class="text-end">Net Salary</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php if ($rows): $srl = 1; foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo pspv($r['emp_code']); ?></td>
                            <td><?php echo pspv($r['emp_name']); ?></td>
                            <td><?php echo pspv($r['payscale_title']); ?></td>
                            <td class="text-end"><?php echo psp_money($r['net_salary']); ?></td>
                            <td><?php echo $r['payment_status'] === 'Paid' ? '<span class="badge bg-success">Paid</span>' : '<span class="badge bg-secondary">Unpaid</span>'; ?></td>
                            <td>
                                <a href="index.php?page=payroll_payslip_print&view=print&item_id=<?php echo (int) $r['item_id']; ?>" class="btn btn-sm btn-primary" style="background-color:#1e3a8a;" target="_blank">
                                    <i class="fa-solid fa-print"></i> Pay Slip
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" class="text-center text-muted py-3">এই মাসে কোনো Finalized Salary Sheet / Employee পাওয়া যায়নি।</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
