<?php
// admin/payroll_generate_salary_sheet.php - Human Resource > Payroll Module > Generate Salary Sheet
// একটা Payscale নির্বাচন করে সেই Employee Type-এর আন্ডারে সব Active Employee-দের জন্য
// এক মাসের Salary Sheet তৈরি করা হয় (Payroll Head এর Addition/Deduction সমষ্টি করে Net বের করা হয়,
// এবং "Absent Deduction = Yes" হেডগুলো Absent Days অনুযায়ী প্রোরেট করা হয়)।
$gs_err = $_SESSION['gs_err'] ?? '';
$gs_msg = $_SESSION['gs_msg'] ?? '';
unset($_SESSION['gs_err'], $_SESSION['gs_msg']);

function gsv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// টেবিলগুলো না থাকলে অটো তৈরি
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS payroll_salary_sheets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        payscale_id INT NOT NULL,
        month TINYINT NOT NULL,
        year SMALLINT NOT NULL,
        working_days INT NOT NULL DEFAULT 30,
        status ENUM('Draft','Finalized') NOT NULL DEFAULT 'Draft',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_payscale_month_year (payscale_id, month, year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS payroll_salary_sheet_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sheet_id INT NOT NULL,
        employee_id INT NOT NULL,
        absent_days INT NOT NULL DEFAULT 0,
        basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        gross_addition DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        gross_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        net_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        breakdown_json TEXT NULL,
        UNIQUE KEY uniq_sheet_employee (sheet_id, employee_id),
        CONSTRAINT fk_gssi_sheet FOREIGN KEY (sheet_id) REFERENCES payroll_salary_sheets(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$months = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];

// -----------------------------------------------------------
// হেল্পার: একটা payscale + employee-দের জন্য salary calculate করে sheet তৈরি
// -----------------------------------------------------------
function gs_build_sheet(PDO $pdo, int $payscale_id, int $month, int $year, int $working_days, array $employee_ids, array $absent_days_map): array
{
    $existing = $pdo->prepare("SELECT id FROM payroll_salary_sheets WHERE payscale_id = ? AND month = ? AND year = ?");
    $existing->execute([$payscale_id, $month, $year]);
    if ($existing->fetch()) {
        return ['ok' => false, 'error' => 'এই Payscale-এর জন্য এই মাসের Salary Sheet আগে থেকেই তৈরি করা আছে।'];
    }

    $pstmt = $pdo->prepare("SELECT * FROM payroll_payscales WHERE id = ?");
    $pstmt->execute([$payscale_id]);
    $payscale = $pstmt->fetch();
    if (!$payscale) {
        return ['ok' => false, 'error' => 'Payscale পাওয়া যায়নি।'];
    }

    $istmt = $pdo->prepare(
        "SELECT ppi.amount, ph.ac_head, ph.category, ph.absent_deduction
         FROM payroll_payscale_items ppi
         JOIN payroll_heads ph ON ph.id = ppi.payroll_head_id
         WHERE ppi.payscale_id = ?"
    );
    $istmt->execute([$payscale_id]);
    $items = $istmt->fetchAll();

    if (!$employee_ids) {
        return ['ok' => false, 'error' => 'অন্তত একজন Employee নির্বাচন করুন।'];
    }

    $pdo->beginTransaction();
    try {
        $sh = $pdo->prepare("INSERT INTO payroll_salary_sheets (payscale_id, month, year, working_days) VALUES (?, ?, ?, ?)");
        $sh->execute([$payscale_id, $month, $year, $working_days]);
        $sheet_id = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare(
            "INSERT INTO payroll_salary_sheet_items
             (sheet_id, employee_id, absent_days, basic_salary, gross_addition, gross_deduction, net_salary, breakdown_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($employee_ids as $emp_id) {
            $emp_id = (int) $emp_id;
            $absent = max(0, (int) ($absent_days_map[$emp_id] ?? 0));
            $present_ratio = $working_days > 0 ? max(0, ($working_days - $absent) / $working_days) : 1;

            $basic = (float) $payscale['basic_salary'];
            $addition = 0.0;
            $deduction = 0.0;
            $breakdown = ['basic' => $basic, 'additions' => [], 'deductions' => []];

            foreach ($items as $it) {
                $amt = (float) $it['amount'];
                if ($it['absent_deduction'] === 'Yes') {
                    $amt = round($amt * $present_ratio, 2);
                }
                if ($it['category'] === 'Deduction') {
                    $deduction += $amt;
                    $breakdown['deductions'][] = ['head' => $it['ac_head'], 'amount' => $amt];
                } else {
                    $addition += $amt;
                    $breakdown['additions'][] = ['head' => $it['ac_head'], 'amount' => $amt];
                }
            }

            $net = $basic + $addition - $deduction;
            $ins->execute([$sheet_id, $emp_id, $absent, $basic, $addition, $deduction, $net, json_encode($breakdown)]);
        }

        $pdo->commit();
        return ['ok' => true, 'sheet_id' => $sheet_id];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Salary Sheet তৈরি করা যায়নি: ' . $e->getMessage()];
    }
}

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['gs_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=payroll_generate_salary_sheet');
        exit();
    }

    if (isset($_POST['generate_sheet'])) {
        $payscale_id  = (int) ($_POST['payscale_id'] ?? 0);
        $month        = (int) ($_POST['month'] ?? 0);
        $year         = (int) ($_POST['year'] ?? 0);
        $working_days = max(1, (int) ($_POST['working_days'] ?? 30));
        $employee_ids = array_map('intval', $_POST['employee_ids'] ?? []);
        $absent_raw   = $_POST['absent_days'] ?? []; // [employee_id => days]
        $absent_map   = [];
        foreach ($absent_raw as $eid => $d) { $absent_map[(int) $eid] = (int) $d; }

        if ($payscale_id <= 0 || $month < 1 || $month > 12 || $year < 2000) {
            $_SESSION['gs_err'] = 'Payscale, Month, Year সঠিকভাবে নির্বাচন করুন।';
            header('Location: index.php?page=payroll_generate_salary_sheet');
            exit();
        }

        $result = gs_build_sheet($pdo, $payscale_id, $month, $year, $working_days, $employee_ids, $absent_map);
        if (!$result['ok']) {
            $_SESSION['gs_err'] = $result['error'];
            header('Location: index.php?page=payroll_generate_salary_sheet');
            exit();
        }
        $_SESSION['gs_msg'] = 'Salary Sheet তৈরি করা হয়েছে।';
        $ps_title_row = $pdo->prepare("SELECT title FROM payroll_payscales WHERE id = ?");
        $ps_title_row->execute([$payscale_id]);
        $ps_title = $ps_title_row->fetchColumn();
        sec_log_activity($pdo, 'Payroll', 'Create', "Salary Sheet তৈরি করা হয়েছে: " . ($ps_title ?: "Payscale #$payscale_id") . " — {$months[$month]} {$year} (" . count($employee_ids) . " জন Employee)");
        header('Location: index.php?page=payroll_generate_salary_sheet&view=output&id=' . $result['sheet_id']);
        exit();
    }

    if (isset($_POST['finalize_sheet'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE payroll_salary_sheets SET status = 'Finalized' WHERE id = ?")->execute([$id]);
        $_SESSION['gs_msg'] = 'Salary Sheet Finalize করা হয়েছে।';
        $fin_row = $pdo->prepare(
            "SELECT s.month, s.year, p.title FROM payroll_salary_sheets s LEFT JOIN payroll_payscales p ON p.id = s.payscale_id WHERE s.id = ?"
        );
        $fin_row->execute([$id]);
        $fin = $fin_row->fetch();
        sec_log_activity($pdo, 'Payroll', 'Update', "Salary Sheet Finalize করা হয়েছে: " . ($fin['title'] ?? "#$id") . ($fin ? " — {$months[(int) $fin['month']]} {$fin['year']}" : ''));
        header('Location: index.php?page=payroll_generate_salary_sheet&view=output&id=' . $id);
        exit();
    }

    if (isset($_POST['delete_sheet'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT status FROM payroll_salary_sheets WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row && $row['status'] === 'Finalized') {
            $_SESSION['gs_err'] = 'Finalized Salary Sheet ডিলিট করা যাবে না।';
        } else {
            $del_row = $pdo->prepare(
                "SELECT s.month, s.year, p.title FROM payroll_salary_sheets s LEFT JOIN payroll_payscales p ON p.id = s.payscale_id WHERE s.id = ?"
            );
            $del_row->execute([$id]);
            $del_sheet = $del_row->fetch();
            $pdo->prepare("DELETE FROM payroll_salary_sheets WHERE id = ?")->execute([$id]);
            $_SESSION['gs_msg'] = 'Salary Sheet ডিলিট করা হয়েছে।';
            sec_log_activity($pdo, 'Payroll', 'Delete', "Salary Sheet ডিলিট করা হয়েছে: " . ($del_sheet['title'] ?? "#$id") . ($del_sheet ? " — {$months[(int) $del_sheet['month']]} {$del_sheet['year']}" : ''));
        }
        header('Location: index.php?page=payroll_generate_salary_sheet');
        exit();
    }
}

$view = $_GET['view'] ?? 'config';
?>

<?php if ($view === 'select'):
    // =====================================================================
    // ধাপ ২: Payscale অনুযায়ী মিলে যাওয়া Employee লিস্ট, Absent Days দিয়ে জেনারেট
    // =====================================================================
    $payscale_id  = (int) ($_GET['payscale_id'] ?? 0);
    $month        = (int) ($_GET['month'] ?? date('n'));
    $year         = (int) ($_GET['year'] ?? date('Y'));
    $working_days = max(1, (int) ($_GET['working_days'] ?? 30));

    $pstmt = $pdo->prepare(
        "SELECT p.*, et.name AS employee_type_name FROM payroll_payscales p
         LEFT JOIN employee_types et ON et.id = p.employee_type_id WHERE p.id = ?"
    );
    $pstmt->execute([$payscale_id]);
    $payscale = $pstmt->fetch();

    if (!$payscale) {
        $_SESSION['gs_err'] = 'Payscale পাওয়া যায়নি।';
        header('Location: index.php?page=payroll_generate_salary_sheet');
        exit();
    }

    $pf_where = ['employee_type_id = ?', "status = 'Active'", 'is_current = 1'];
    $pf_params = [$payscale['employee_type_id']];
    branch_apply_filter($pf_where, $pf_params, 'branch_id');
    $estmt = $pdo->prepare(
        "SELECT id, employee_id, name, designation_id FROM employees
         WHERE " . implode(' AND ', $pf_where) . " ORDER BY name ASC"
    );
    $estmt->execute($pf_params);
    $employees = $estmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($gs_err): ?><div class="alert alert-danger py-2"><?php echo gsv($gs_err); ?></div><?php endif; ?>

        <div class="alert alert-info small">
            <i class="fa-solid fa-circle-info"></i>
            Payscale: <strong><?php echo gsv($payscale['title']); ?></strong>
            (<?php echo gsv($payscale['employee_type_name'] ?? '—'); ?>) —
            <?php echo gsv($months[$month] ?? $month); ?> <?php echo (int) $year; ?>,
            Working Days: <?php echo (int) $working_days; ?>
        </div>

        <?php if (!$employees): ?>
            <p class="text-muted mb-0">এই Employee Type-এ কোনো Active Employee পাওয়া যায়নি।</p>
        <?php else: ?>
            <form method="POST" action="index.php?page=payroll_generate_salary_sheet">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="payscale_id" value="<?php echo (int) $payscale_id; ?>">
                <input type="hidden" name="month" value="<?php echo (int) $month; ?>">
                <input type="hidden" name="year" value="<?php echo (int) $year; ?>">
                <input type="hidden" name="working_days" value="<?php echo (int) $working_days; ?>">

                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width:40px;"><input type="checkbox" id="gsCheckAll" checked></th>
                                <th>Employee ID</th>
                                <th>Name</th>
                                <th style="width:150px;">Absent Days</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $emp): ?>
                                <tr>
                                    <td><input type="checkbox" class="gs-emp-check" name="employee_ids[]" value="<?php echo (int) $emp['id']; ?>" checked></td>
                                    <td><?php echo gsv($emp['employee_id']); ?></td>
                                    <td><?php echo gsv($emp['name']); ?></td>
                                    <td><input type="number" min="0" max="<?php echo (int) $working_days; ?>" name="absent_days[<?php echo (int) $emp['id']; ?>]" class="form-control form-control-sm" value="0"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between">
                    <a href="index.php?page=payroll_generate_salary_sheet" class="btn btn-secondary px-4">Back</a>
                    <button type="submit" name="generate_sheet" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;">Generate Salary Sheet</button>
                </div>
            </form>
            <script>
                document.getElementById('gsCheckAll').addEventListener('change', function () {
                    document.querySelectorAll('.gs-emp-check').forEach(c => c.checked = this.checked);
                });
            </script>
        <?php endif; ?>
    </div>

<?php elseif ($view === 'output'):
    // =====================================================================
    // জেনারেট হওয়া Salary Sheet দেখা (Print করা যাবে)
    // =====================================================================
    $sheet_id = (int) ($_GET['id'] ?? 0);
    $sstmt = $pdo->prepare(
        "SELECT s.*, p.title AS payscale_title, et.name AS employee_type_name
         FROM payroll_salary_sheets s
         LEFT JOIN payroll_payscales p ON p.id = s.payscale_id
         LEFT JOIN employee_types et ON et.id = p.employee_type_id
         WHERE s.id = ?"
    );
    $sstmt->execute([$sheet_id]);
    $sheet = $sstmt->fetch();

    if (!$sheet) {
        $_SESSION['gs_err'] = 'Salary Sheet পাওয়া যায়নি।';
        header('Location: index.php?page=payroll_generate_salary_sheet');
        exit();
    }

    $istmt = $pdo->prepare(
        "SELECT si.*, e.employee_id AS emp_code, e.name AS emp_name
         FROM payroll_salary_sheet_items si
         JOIN employees e ON e.id = si.employee_id
         WHERE si.sheet_id = ? ORDER BY e.name ASC"
    );
    $istmt->execute([$sheet_id]);
    $items = $istmt->fetchAll();

    $t_basic = $t_add = $t_ded = $t_net = 0.0;
    foreach ($items as $it) {
        $t_basic += (float) $it['basic_salary'];
        $t_add   += (float) $it['gross_addition'];
        $t_ded   += (float) $it['gross_deduction'];
        $t_net   += (float) $it['net_salary'];
    }
    $csrf = htmlspecialchars(csrf_token());

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
        @page { size: A4; margin: 15mm; }
        .no-print { display: none !important; }
        .sidebar, .breadcrumb-bar, .top-navbar { display: none !important; }
        .table-responsive { overflow: visible !important; }
        body { padding: 0 !important; margin: 0 !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; }
    }
    </style>
    <?php require_once __DIR__ . '/employee_attendance_common.php'; ea_print_css('landscape', 9); ?>

    <div class="no-print d-flex justify-content-between align-items-center mb-3">
        <a href="index.php?page=payroll_generate_salary_sheet" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <div class="d-flex gap-2">
            <?php if ($sheet['status'] !== 'Finalized'): ?>
                <form method="POST" action="index.php?page=payroll_generate_salary_sheet" onsubmit="return confirm('Finalize করলে আর এডিট/ডিলিট করা যাবে না। নিশ্চিত?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $sheet_id; ?>">
                    <button type="submit" name="finalize_sheet" class="btn btn-success fw-bold"><i class="fa-solid fa-check"></i> Finalize</button>
                </form>
            <?php endif; ?>
            <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        </div>
    </div>

    <div class="card-custom">
        <?php if ($gs_msg): ?><div class="alert alert-success py-2 no-print"><?php echo gsv($gs_msg); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo gsv($inst['logo_path']); ?>" style="max-width:45px;max-height:45px;"><?php endif; ?>
                <div>
                    <h6 class="fw-bold text-primary mb-0"><?php echo gsv($inst['institute_name']); ?></h6>
                    <?php if (!empty($inst['phone_number'])): ?><div class="small text-muted">Phone: <?php echo gsv($inst['phone_number']); ?></div><?php endif; ?>
                    <?php if (!empty($inst['email'])): ?><div class="small text-primary"><?php echo gsv($inst['email']); ?></div><?php endif; ?>
                </div>
            </div>
            <?php if ($branch): ?>
                <div class="text-end">
                    <?php if (!empty($branch['branch_name']) && trim($branch['branch_name']) !== trim($inst['institute_name'])): ?>
                        <div class="fw-bold small"><?php echo gsv($branch['branch_name']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($branch['address'])): ?>
                        <div class="small text-muted"><?php echo gsv($branch['address']); ?><?php echo $branch['city'] ? ', ' . gsv($branch['city']) : ''; ?><?php echo $branch['zip_code'] ? '-' . gsv($branch['zip_code']) : ''; ?></div>
                    <?php endif; ?>
                    <?php if (!empty($branch['phone'])): ?><div class="small text-muted">Phone: <?php echo gsv($branch['phone']); ?></div><?php endif; ?>
                    <?php if (!empty($branch['email'])): ?><div class="small text-muted"><?php echo gsv($branch['email']); ?></div><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <hr class="mb-3">


        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0">Salary Sheet — <?php echo gsv($months[(int) $sheet['month']] ?? $sheet['month']); ?> <?php echo (int) $sheet['year']; ?></h5>
            <span class="badge <?php echo $sheet['status'] === 'Finalized' ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo gsv($sheet['status']); ?></span>
        </div>
        <p class="text-muted small">Payscale: <?php echo gsv($sheet['payscale_title']); ?> | Employee Type: <?php echo gsv($sheet['employee_type_name'] ?? '—'); ?> | Working Days: <?php echo (int) $sheet['working_days']; ?></p>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Srl.</th><th>Employee ID</th><th>Name</th><th>Absent</th>
                        <th class="text-end">Basic</th><th class="text-end">Addition</th><th class="text-end">Deduction</th><th class="text-end">Net Salary</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $srl = 1; foreach ($items as $it): ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo gsv($it['emp_code']); ?></td>
                            <td><?php echo gsv($it['emp_name']); ?></td>
                            <td><?php echo (int) $it['absent_days']; ?></td>
                            <td class="text-end"><?php echo number_format((float) $it['basic_salary'], 2); ?></td>
                            <td class="text-end"><?php echo number_format((float) $it['gross_addition'], 2); ?></td>
                            <td class="text-end"><?php echo number_format((float) $it['gross_deduction'], 2); ?></td>
                            <td class="text-end fw-bold"><?php echo number_format((float) $it['net_salary'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="4" class="text-end">Total</td>
                        <td class="text-end"><?php echo number_format($t_basic, 2); ?></td>
                        <td class="text-end"><?php echo number_format($t_add, 2); ?></td>
                        <td class="text-end"><?php echo number_format($t_ded, 2); ?></td>
                        <td class="text-end"><?php echo number_format($t_net, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php if ($sheet['status'] !== 'Finalized'): ?>
            <form method="POST" action="index.php?page=payroll_generate_salary_sheet" class="no-print" onsubmit="return confirm('এই Salary Sheet ডিলিট করবেন?');">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="id" value="<?php echo (int) $sheet_id; ?>">
                <button type="submit" name="delete_sheet" class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-trash"></i> Delete Sheet</button>
            </form>
        <?php endif; ?>
    </div>

<?php else: ?>
    <?php
    // =====================================================================
    // ধাপ ১: Payscale + Month/Year + Working Days নির্বাচন (CONFIG VIEW)
    // =====================================================================
    $payscales = $pdo->query(
        "SELECT p.id, p.title, p.basic_salary, et.name AS employee_type_name
         FROM payroll_payscales p LEFT JOIN employee_types et ON et.id = p.employee_type_id
         WHERE p.status = 'Active' ORDER BY p.title ASC"
    )->fetchAll();

    $sheets = $pdo->query(
        "SELECT s.*, p.title AS payscale_title,
                (SELECT COUNT(*) FROM payroll_salary_sheet_items si WHERE si.sheet_id = s.id) AS emp_count,
                (SELECT COALESCE(SUM(net_salary),0) FROM payroll_salary_sheet_items si WHERE si.sheet_id = s.id) AS total_net
         FROM payroll_salary_sheets s
         LEFT JOIN payroll_payscales p ON p.id = s.payscale_id
         ORDER BY s.year DESC, s.month DESC, s.id DESC"
    )->fetchAll();
    ?>
    <div class="card-custom mb-3">
        <?php if ($gs_err): ?><div class="alert alert-danger py-2"><?php echo gsv($gs_err); ?></div><?php endif; ?>
        <?php if ($gs_msg): ?><div class="alert alert-success py-2"><?php echo gsv($gs_msg); ?></div><?php endif; ?>

        <h6 class="fw-bold mb-3"><i class="fa-solid fa-file-invoice-dollar"></i> নতুন Salary Sheet জেনারেট করুন</h6>

        <?php if (!$payscales): ?>
            <p class="text-muted small mb-0">কোনো Active Payscale পাওয়া যায়নি। আগে "Employee Payscale" পেজ থেকে একটা Payscale তৈরি করুন।</p>
        <?php else: ?>
            <form method="GET" action="index.php" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="payroll_generate_salary_sheet">
                <input type="hidden" name="view" value="select">

                <div class="col-md-4">
                    <label class="form-label fw-bold">Payscale <span class="text-danger">*</span></label>
                    <select name="payscale_id" class="form-select" required>
                        <option value="">Select Payscale</option>
                        <?php foreach ($payscales as $ps): ?>
                            <option value="<?php echo (int) $ps['id']; ?>"><?php echo gsv($ps['title']); ?> (<?php echo gsv($ps['employee_type_name'] ?? '—'); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Month <span class="text-danger">*</span></label>
                    <select name="month" class="form-select" required>
                        <?php foreach ($months as $mi => $mn): ?>
                            <option value="<?php echo $mi; ?>" <?php echo $mi === (int) date('n') ? 'selected' : ''; ?>><?php echo $mn; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Year <span class="text-danger">*</span></label>
                    <input type="number" name="year" class="form-control" value="<?php echo (int) date('Y'); ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Working Days</label>
                    <input type="number" name="working_days" class="form-control" value="30" min="1" max="31" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary fw-bold w-100" style="background-color:#1e3a8a;">Next <i class="fa-solid fa-arrow-right"></i></button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <div class="card-custom">
        <h6 class="fw-bold mb-3">পূর্বে তৈরি করা Salary Sheet সমূহ</h6>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr><th>Srl.</th><th>Month/Year</th><th>Payscale</th><th>Employees</th><th>Total Net</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php if ($sheets): $srl = 1; foreach ($sheets as $sh): $csrf = htmlspecialchars(csrf_token()); ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo gsv($months[(int) $sh['month']] ?? $sh['month']); ?> <?php echo (int) $sh['year']; ?></td>
                            <td><?php echo gsv($sh['payscale_title']); ?></td>
                            <td><?php echo (int) $sh['emp_count']; ?></td>
                            <td><?php echo number_format((float) $sh['total_net'], 2); ?></td>
                            <td><?php echo $sh['status'] === 'Finalized' ? '<span class="badge bg-success">Finalized</span>' : '<span class="badge bg-warning text-dark">Draft</span>'; ?></td>
                            <td class="text-nowrap">
                                <a href="index.php?page=payroll_generate_salary_sheet&view=output&id=<?php echo (int) $sh['id']; ?>" class="btn btn-sm btn-outline-primary" title="View"><i class="fa-solid fa-eye"></i></a>
                                <?php if ($sh['status'] !== 'Finalized'): ?>
                                    <form method="POST" action="index.php?page=payroll_generate_salary_sheet" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="id" value="<?php echo (int) $sh['id']; ?>">
                                        <button type="submit" name="delete_sheet" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" class="text-center text-muted py-3">No data found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
