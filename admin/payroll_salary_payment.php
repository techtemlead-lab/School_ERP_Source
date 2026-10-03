<?php
// admin/payroll_salary_payment.php - Human Resource > Payroll Module > Employee Salary Payment
// Finalized Salary Sheet থেকে employee-দের Net Salary পরিশোধ (Cash/Bank/bKash/Nagad) করা হয়,
// পেমেন্ট রেকর্ড হয় payroll_salary_sheet_items টেবিলে, এবং একটা প্রিন্টযোগ্য Payment Statement দেখা যায়।

$sp_err = $_SESSION['sp_err'] ?? '';
$sp_msg = $_SESSION['sp_msg'] ?? '';
unset($_SESSION['sp_err'], $_SESSION['sp_msg']);

function spv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function sp_money($n): string { return number_format((float) $n, 2); }

$sp_methods = ['Cash', 'Bank', 'bKash', 'Nagad'];
$months = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];

// -----------------------------------------------------------
// payroll_salary_sheet_items টেবিলে পেমেন্ট-সংক্রান্ত কলাম না থাকলে যোগ করা (পুরনো ডাটাবেজের জন্য নিরাপদ)
// -----------------------------------------------------------
function sp_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $chk = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $chk->execute([$table, $column]);
    if (!$chk->fetch()) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
sp_ensure_column($pdo, 'payroll_salary_sheet_items', 'payment_status', "ENUM('Unpaid','Paid') NOT NULL DEFAULT 'Unpaid' AFTER net_salary");
sp_ensure_column($pdo, 'payroll_salary_sheet_items', 'payment_method', "VARCHAR(20) NULL AFTER payment_status");
sp_ensure_column($pdo, 'payroll_salary_sheet_items', 'payment_date', "DATE NULL AFTER payment_method");
sp_ensure_column($pdo, 'payroll_salary_sheet_items', 'payment_reference', "VARCHAR(100) NULL AFTER payment_date");
sp_ensure_column($pdo, 'payroll_salary_sheet_items', 'payment_remarks', "VARCHAR(255) NULL AFTER payment_reference");
sp_ensure_column($pdo, 'payroll_salary_sheet_items', 'paid_by', "VARCHAR(100) NULL AFTER payment_remarks");
sp_ensure_column($pdo, 'payroll_salary_sheet_items', 'paid_at', "TIMESTAMP NULL AFTER paid_by");

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sp_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=payroll_salary_payment');
        exit();
    }

    if (isset($_POST['pay_employees'])) {
        $sheet_id   = (int) ($_POST['sheet_id'] ?? 0);
        $item_ids   = array_map('intval', $_POST['item_ids'] ?? []);
        $method     = trim($_POST['payment_method'] ?? '');
        $pay_date   = trim($_POST['payment_date'] ?? '');
        $reference  = trim($_POST['payment_reference'] ?? '');
        $remarks    = trim($_POST['payment_remarks'] ?? '');

        $sstmt = $pdo->prepare("SELECT * FROM payroll_salary_sheets WHERE id = ?");
        $sstmt->execute([$sheet_id]);
        $sheet = $sstmt->fetch();

        if (!$sheet) {
            $_SESSION['sp_err'] = 'Salary Sheet পাওয়া যায়নি।';
            header('Location: index.php?page=payroll_salary_payment');
            exit();
        } elseif ($sheet['status'] !== 'Finalized') {
            $_SESSION['sp_err'] = 'শুধু Finalized Salary Sheet-এর Payment করা যাবে।';
            header('Location: index.php?page=payroll_salary_payment&view=pay&id=' . $sheet_id);
            exit();
        } elseif (!in_array($method, $sp_methods, true)) {
            $_SESSION['sp_err'] = 'সঠিক Payment Method নির্বাচন করুন।';
            header('Location: index.php?page=payroll_salary_payment&view=pay&id=' . $sheet_id);
            exit();
        } elseif ($pay_date === '') {
            $_SESSION['sp_err'] = 'Payment Date আবশ্যক।';
            header('Location: index.php?page=payroll_salary_payment&view=pay&id=' . $sheet_id);
            exit();
        } elseif (!$item_ids) {
            $_SESSION['sp_err'] = 'অন্তত একজন Employee নির্বাচন করুন।';
            header('Location: index.php?page=payroll_salary_payment&view=pay&id=' . $sheet_id);
            exit();
        } else {
            $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
            $params = array_merge(
                [$method, $pay_date, $reference ?: null, $remarks ?: null, $_SESSION['admin_user'] ?? ''],
                $item_ids,
                [$sheet_id]
            );
            $stmt = $pdo->prepare(
                "UPDATE payroll_salary_sheet_items
                 SET payment_status = 'Paid', payment_method = ?, payment_date = ?, payment_reference = ?, payment_remarks = ?, paid_by = ?, paid_at = NOW()
                 WHERE id IN ($placeholders) AND sheet_id = ? AND payment_status = 'Unpaid'"
            );
            $stmt->execute($params);
            $affected = $stmt->rowCount();
            $_SESSION['sp_msg'] = $affected . ' জন Employee-কে Salary পরিশোধ করা হয়েছে।';
            $paid_sum_stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(net_salary),0) FROM payroll_salary_sheet_items WHERE id IN ($placeholders) AND sheet_id = ?"
            );
            $paid_sum_stmt->execute(array_merge($item_ids, [$sheet_id]));
            $paid_sum = (float) $paid_sum_stmt->fetchColumn();
            sec_log_activity($pdo, 'Payroll', 'Update', "{$affected} জন Employee-কে Salary পরিশোধ করা হয়েছে ({$method}, ৳" . number_format($paid_sum, 2) . ") — {$months[(int) $sheet['month']]} {$sheet['year']}");
            header('Location: index.php?page=payroll_salary_payment&view=pay&id=' . $sheet_id);
            exit();
        }
    }

    if (isset($_POST['unpay_employee'])) {
        $item_id  = (int) ($_POST['item_id'] ?? 0);
        $sheet_id = (int) ($_POST['sheet_id'] ?? 0);
        $emp_row = $pdo->prepare(
            "SELECT e.name, e.employee_id, si.net_salary FROM payroll_salary_sheet_items si JOIN employees e ON e.id = si.employee_id WHERE si.id = ? AND si.sheet_id = ?"
        );
        $emp_row->execute([$item_id, $sheet_id]);
        $emp_info = $emp_row->fetch();
        $stmt = $pdo->prepare(
            "UPDATE payroll_salary_sheet_items
             SET payment_status = 'Unpaid', payment_method = NULL, payment_date = NULL, payment_reference = NULL, payment_remarks = NULL, paid_by = NULL, paid_at = NULL
             WHERE id = ? AND sheet_id = ?"
        );
        $stmt->execute([$item_id, $sheet_id]);
        $_SESSION['sp_msg'] = 'Payment বাতিল করা হয়েছে, এখন এটি আবার Unpaid দেখাবে।';
        sec_log_activity($pdo, 'Payroll', 'Update', "Salary Payment বাতিল করা হয়েছে: " . ($emp_info['name'] ?? "Item #$item_id") . " (৳" . number_format((float) ($emp_info['net_salary'] ?? 0), 2) . ")");
        header('Location: index.php?page=payroll_salary_payment&view=pay&id=' . $sheet_id);
        exit();
    }
}

$view = $_GET['view'] ?? 'list';
?>

<?php if ($view === 'pay'):
    // =====================================================================
    // একটা নির্দিষ্ট Salary Sheet-এর Employee-দের Payment করা
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
        $_SESSION['sp_err'] = 'Salary Sheet পাওয়া যায়নি।';
        header('Location: index.php?page=payroll_salary_payment');
        exit();
    }

    $istmt = $pdo->prepare(
        "SELECT si.*, e.employee_id AS emp_code, e.name AS emp_name, e.mobile AS emp_mobile, e.bank_account_no
         FROM payroll_salary_sheet_items si
         JOIN employees e ON e.id = si.employee_id
         WHERE si.sheet_id = ? ORDER BY e.name ASC"
    );
    $istmt->execute([$sheet_id]);
    $items = $istmt->fetchAll();

    $t_net = $t_paid = $t_due = 0.0;
    $paid_count = $unpaid_count = 0;
    foreach ($items as $it) {
        $t_net += (float) $it['net_salary'];
        if ($it['payment_status'] === 'Paid') {
            $t_paid += (float) $it['net_salary'];
            $paid_count++;
        } else {
            $t_due += (float) $it['net_salary'];
            $unpaid_count++;
        }
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

    <div class="no-print d-flex justify-content-between align-items-center mb-3">
        <a href="index.php?page=payroll_salary_payment" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print Statement</button>
    </div>

    <div class="card-custom mb-3 no-print">
        <?php if ($sp_err): ?><div class="alert alert-danger py-2"><?php echo spv($sp_err); ?></div><?php endif; ?>
        <?php if ($sp_msg): ?><div class="alert alert-success py-2"><?php echo spv($sp_msg); ?></div><?php endif; ?>

        <?php if ($sheet['status'] !== 'Finalized'): ?>
            <div class="alert alert-warning py-2 mb-0">
                <i class="fa-solid fa-triangle-exclamation"></i>
                এই Salary Sheet এখনো Finalize করা হয়নি। Payment করার আগে "Generate Salary Sheet" পেজ থেকে এটি Finalize করুন।
            </div>
        <?php endif; ?>
    </div>

    <div class="card-custom">
        <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo spv($inst['logo_path']); ?>" style="max-width:45px;max-height:45px;"><?php endif; ?>
                <div>
                    <h6 class="fw-bold text-primary mb-0"><?php echo spv($inst['institute_name']); ?></h6>
                    <?php if (!empty($inst['phone_number'])): ?><div class="small text-muted">Phone: <?php echo spv($inst['phone_number']); ?></div><?php endif; ?>
                    <?php if (!empty($inst['email'])): ?><div class="small text-primary"><?php echo spv($inst['email']); ?></div><?php endif; ?>
                </div>
            </div>
            <?php if ($branch): ?>
                <div class="text-end">
                    <?php if (!empty($branch['branch_name']) && trim($branch['branch_name']) !== trim($inst['institute_name'])): ?>
                        <div class="fw-bold small"><?php echo spv($branch['branch_name']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($branch['address'])): ?>
                        <div class="small text-muted"><?php echo spv($branch['address']); ?><?php echo $branch['city'] ? ', ' . spv($branch['city']) : ''; ?><?php echo $branch['zip_code'] ? '-' . spv($branch['zip_code']) : ''; ?></div>
                    <?php endif; ?>
                    <?php if (!empty($branch['phone'])): ?><div class="small text-muted">Phone: <?php echo spv($branch['phone']); ?></div><?php endif; ?>
                    <?php if (!empty($branch['email'])): ?><div class="small text-muted"><?php echo spv($branch['email']); ?></div><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <hr class="mb-3">

        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h5 class="fw-bold mb-0">Salary Payment — <?php echo spv($months[(int) $sheet['month']] ?? $sheet['month']); ?> <?php echo (int) $sheet['year']; ?></h5>
            <span class="badge <?php echo $unpaid_count === 0 ? 'bg-success' : ($paid_count === 0 ? 'bg-danger' : 'bg-warning text-dark'); ?>">
                <?php echo $unpaid_count === 0 ? 'Fully Paid' : ($paid_count === 0 ? 'Not Paid' : 'Partially Paid'); ?>
            </span>
        </div>
        <div class="small text-muted mb-3">
            Payscale: <?php echo spv($sheet['payscale_title']); ?> |
            Employee Type: <?php echo spv($sheet['employee_type_name'] ?? '—'); ?> |
            Working Days: <?php echo (int) $sheet['working_days']; ?>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center">
                    <div class="small text-muted">Total Employees</div>
                    <div class="fw-bold fs-5"><?php echo count($items); ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center">
                    <div class="small text-muted">Total Net Salary</div>
                    <div class="fw-bold fs-5"><?php echo sp_money($t_net); ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center bg-success bg-opacity-10">
                    <div class="small text-muted">Paid (<?php echo $paid_count; ?>)</div>
                    <div class="fw-bold fs-5 text-success"><?php echo sp_money($t_paid); ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center bg-danger bg-opacity-10">
                    <div class="small text-muted">Due (<?php echo $unpaid_count; ?>)</div>
                    <div class="fw-bold fs-5 text-danger"><?php echo sp_money($t_due); ?></div>
                </div>
            </div>
        </div>

        <!-- "Undo Payment" বাটনের নিজস্ব ছোট্ট &lt;form&gt; (spUnpayForm_X) আগে এই বাল্ক-পে ফর্মের
             (spPayForm) ভেতরেই বসানো ছিল — ফর্মের ভেতরে ফর্ম, HTML-এ যা বৈধ না। ব্রাউজার নেস্টেড
             &lt;form&gt;-টা বাতিল করে দিত, ফলে ওই id-টাই আসলে DOM-এ থাকত না — বাটনের form="..."
             রেফারেন্সটাও কোথাও পৌঁছাত না, ক্লিক করলে কিছুই হতো না। সমাধানঃ একটামাত্র শেয়ার্ড
             হিডেন ফর্ম, spPayForm-এর বাইরে — Undo Payment বাটন এখন এটাকেই JS দিয়ে সাবমিট করে। -->
        <form method="POST" id="spRowActionForm" style="display:none">
            <input type="hidden" name="csrf_token" id="spRowActionCsrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        </form>
        <script>
        function spRowAction(url, fields, confirmMsg) {
            if (confirmMsg && !window.confirm(confirmMsg)) return false;
            var f = document.getElementById('spRowActionForm');
            f.setAttribute('action', url);
            var old = f.querySelectorAll('input[data-dyn-field]');
            old.forEach(function (el) { el.parentNode.removeChild(el); });
            Object.keys(fields).forEach(function (k) {
                var el = document.createElement('input');
                el.type = 'hidden'; el.name = k; el.value = fields[k]; el.setAttribute('data-dyn-field', '1');
                f.appendChild(el);
            });
            f.submit();
            return false;
        }
        </script>

        <form method="POST" action="index.php?page=payroll_salary_payment" id="spPayForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="sheet_id" value="<?php echo (int) $sheet_id; ?>">

            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:36px;" class="no-print"><input type="checkbox" id="spCheckAll"></th>
                            <th>Srl.</th>
                            <th>Employee ID</th>
                            <th>Name</th>
                            <th class="text-end">Net Salary</th>
                            <th>Status</th>
                            <th>Method</th>
                            <th>Date</th>
                            <th>Reference</th>
                            <th class="no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($items): $srl = 1; foreach ($items as $it): ?>
                            <tr>
                                <td class="no-print">
                                    <?php if ($it['payment_status'] === 'Unpaid'): ?>
                                        <input type="checkbox" class="sp-emp-check" name="item_ids[]" value="<?php echo (int) $it['id']; ?>">
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $srl++; ?></td>
                                <td><?php echo spv($it['emp_code']); ?></td>
                                <td><?php echo spv($it['emp_name']); ?></td>
                                <td class="text-end fw-bold"><?php echo sp_money($it['net_salary']); ?></td>
                                <td>
                                    <?php echo $it['payment_status'] === 'Paid' ? '<span class="badge bg-success">Paid</span>' : '<span class="badge bg-secondary">Unpaid</span>'; ?>
                                </td>
                                <td><?php echo spv($it['payment_method'] ?: '—'); ?></td>
                                <td><?php echo $it['payment_date'] ? spv(date('d M Y', strtotime($it['payment_date']))) : '—'; ?></td>
                                <td><?php echo spv($it['payment_reference'] ?: '—'); ?></td>
                                <td class="no-print text-nowrap">
                                    <?php if ($it['payment_status'] === 'Paid'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Undo Payment" onclick="return spRowAction('index.php?page=payroll_salary_payment', {item_id: <?php echo (int) $it['id']; ?>, sheet_id: <?php echo (int) $sheet_id; ?>, unpay_employee: '1'}, 'Payment বাতিল করবেন?');"><i class="fa-solid fa-rotate-left"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="10" class="text-center text-muted py-3">No employee found in this sheet</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="4" class="text-end">Total</td>
                            <td class="text-end"><?php echo sp_money($t_net); ?></td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if ($unpaid_count > 0 && $sheet['status'] === 'Finalized'): ?>
            <div class="border rounded p-3 mt-3 no-print bg-light">
                <h6 class="fw-bold mb-3"><i class="fa-solid fa-money-bill-wave"></i> নির্বাচিত Employee-দের Salary পরিশোধ করুন</h6>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Payment Method <span class="text-danger">*</span></label>
                        <select name="payment_method" class="form-select" required>
                            <option value="">Select Method</option>
                            <?php foreach ($sp_methods as $m): ?>
                                <option value="<?php echo spv($m); ?>"><?php echo spv($m); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Reference / Txn ID</label>
                        <input type="text" name="payment_reference" class="form-control" placeholder="e.g. Cheque/Txn No">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Remarks</label>
                        <input type="text" name="payment_remarks" class="form-control" placeholder="Optional">
                    </div>
                </div>
                <div class="text-end mt-3">
                    <button type="submit" name="pay_employees" class="btn btn-success fw-bold px-4" onclick="return spConfirmPay();"><i class="fa-solid fa-check"></i> Pay Selected Employees</button>
                </div>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <script>
        document.getElementById('spCheckAll')?.addEventListener('change', function () {
            document.querySelectorAll('.sp-emp-check').forEach(c => c.checked = this.checked);
        });
        function spConfirmPay() {
            var checked = document.querySelectorAll('.sp-emp-check:checked').length;
            if (checked === 0) { alert('অন্তত একজন Employee নির্বাচন করুন।'); return false; }
            return confirm(checked + ' জন Employee-কে Salary পরিশোধ করবেন?');
        }
    </script>

<?php else:
    // =====================================================================
    // Finalized Salary Sheet-গুলোর তালিকা, Payment Status সহ (LIST VIEW)
    // =====================================================================
    $sheets = $pdo->query(
        "SELECT s.*, p.title AS payscale_title,
                (SELECT COUNT(*) FROM payroll_salary_sheet_items si WHERE si.sheet_id = s.id) AS emp_count,
                (SELECT COUNT(*) FROM payroll_salary_sheet_items si WHERE si.sheet_id = s.id AND si.payment_status = 'Paid') AS paid_count,
                (SELECT COALESCE(SUM(net_salary),0) FROM payroll_salary_sheet_items si WHERE si.sheet_id = s.id) AS total_net,
                (SELECT COALESCE(SUM(net_salary),0) FROM payroll_salary_sheet_items si WHERE si.sheet_id = s.id AND si.payment_status = 'Paid') AS total_paid
         FROM payroll_salary_sheets s
         LEFT JOIN payroll_payscales p ON p.id = s.payscale_id
         WHERE s.status = 'Finalized'
         ORDER BY s.year DESC, s.month DESC, s.id DESC"
    )->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($sp_err): ?><div class="alert alert-danger py-2"><?php echo spv($sp_err); ?></div><?php endif; ?>
        <?php if ($sp_msg): ?><div class="alert alert-success py-2"><?php echo spv($sp_msg); ?></div><?php endif; ?>

        <h6 class="fw-bold mb-3"><i class="fa-solid fa-money-check-dollar"></i> Employee Salary Payment</h6>
        <p class="small text-muted">শুধুমাত্র Finalized Salary Sheet-এর Payment করা যায়। Draft Sheet Finalize করতে "Generate Salary Sheet" পেজে যান।</p>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Srl.</th><th>Month/Year</th><th>Payscale</th><th>Employees</th>
                        <th class="text-end">Total Net</th><th class="text-end">Paid</th><th class="text-end">Due</th>
                        <th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sheets): $srl = 1; foreach ($sheets as $sh):
                        $due = (float) $sh['total_net'] - (float) $sh['total_paid'];
                        if ((int) $sh['paid_count'] === 0) { $badge = '<span class="badge bg-danger">Not Paid</span>'; }
                        elseif ((int) $sh['paid_count'] === (int) $sh['emp_count']) { $badge = '<span class="badge bg-success">Fully Paid</span>'; }
                        else { $badge = '<span class="badge bg-warning text-dark">Partially Paid</span>'; }
                    ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo spv($months[(int) $sh['month']] ?? $sh['month']); ?> <?php echo (int) $sh['year']; ?></td>
                            <td><?php echo spv($sh['payscale_title']); ?></td>
                            <td><?php echo (int) $sh['paid_count']; ?> / <?php echo (int) $sh['emp_count']; ?></td>
                            <td class="text-end"><?php echo sp_money($sh['total_net']); ?></td>
                            <td class="text-end text-success"><?php echo sp_money($sh['total_paid']); ?></td>
                            <td class="text-end text-danger"><?php echo sp_money($due); ?></td>
                            <td><?php echo $badge; ?></td>
                            <td>
                                <a href="index.php?page=payroll_salary_payment&view=pay&id=<?php echo (int) $sh['id']; ?>" class="btn btn-sm btn-primary" style="background-color:#1e3a8a;">
                                    <i class="fa-solid fa-money-bill-wave"></i> Pay / View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="9" class="text-center text-muted py-3">কোনো Finalized Salary Sheet পাওয়া যায়নি।</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
