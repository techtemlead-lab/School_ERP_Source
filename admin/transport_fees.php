<?php
// admin/transport_fees.php - Transport Module > Fee Collection
$tf_err = $_SESSION['tf_err'] ?? '';
$tf_msg = $_SESSION['tf_msg'] ?? '';
unset($_SESSION['tf_err'], $_SESSION['tf_msg']);

require_once __DIR__ . '/transport_common.php';
require_once __DIR__ . '/../config/sms.php';

$inst = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetch();
$school_name = $inst['institute_name'] ?? 'আমাদের প্রতিষ্ঠান';

// -----------------------------------------------------------
// POST: Generate Invoices / Collect Payment
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['tf_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=transport_fees');
        exit();
    }

    if (isset($_POST['generate_invoices'])) {
        $month_year = trim($_POST['month_year'] ?? date('Y-m'));

        $students = $pdo->query("SELECT tsa.student_id, tsa.route_id, tsa.fee_override, tr.monthly_fee
                                  FROM transport_student_assign tsa
                                  JOIN transport_routes tr ON tr.id = tsa.route_id
                                  WHERE tsa.status = 'Active'")->fetchAll();

        $created = 0; $skipped = 0;
        $ins = $pdo->prepare("INSERT IGNORE INTO transport_fee_invoices (student_id, route_id, month_year, amount) VALUES (?,?,?,?)");
        foreach ($students as $s) {
            $amount = $s['fee_override'] !== null ? (float) $s['fee_override'] : (float) $s['monthly_fee'];
            $ins->execute([$s['student_id'], $s['route_id'], $month_year, $amount]);
            if ($ins->rowCount() > 0) $created++; else $skipped++;
        }
        $_SESSION['tf_msg'] = "$month_year মাসের জন্য $created টি নতুন ইনভয়েস তৈরি হয়েছে" . ($skipped > 0 ? " ($skipped টি আগে থেকেই ছিল, স্কিপ করা হয়েছে)" : '') . "।";
        sec_log_activity($pdo, 'Transport', 'Create', "{$month_year} মাসের ট্রান্সপোর্ট ফি ইনভয়েস তৈরি হয়েছে: {$created} টি নতুন" . ($skipped > 0 ? ", {$skipped} টি স্কিপ" : '') . "।");
        header('Location: index.php?page=transport_fees&tab=collect&month_year=' . urlencode($month_year));
        exit();
    } elseif (isset($_POST['collect_payment'])) {
        $invoice_id     = (int) ($_POST['invoice_id'] ?? 0);
        $pay_amount     = (float) ($_POST['pay_amount'] ?? 0);
        $payment_method = trim($_POST['payment_method'] ?? 'Cash');
        $trx_id         = trim($_POST['trx_id'] ?? '');

        $inv_stmt = $pdo->prepare("SELECT tfi.*, s.name_en, s.name_bn, s.guardian_phone FROM transport_fee_invoices tfi JOIN students s ON s.id = tfi.student_id WHERE tfi.id = ?");
        $inv_stmt->execute([$invoice_id]);
        $inv = $inv_stmt->fetch();

        if (!$inv) {
            $_SESSION['tf_err'] = 'ইনভয়েস খুঁজে পাওয়া যায়নি।';
        } elseif ($pay_amount <= 0) {
            $_SESSION['tf_err'] = 'সঠিক পরিমাণ দিন।';
        } else {
            $new_paid = (float) $inv['paid_amount'] + $pay_amount;
            $new_paid = min($new_paid, (float) $inv['amount']); // amount এর বেশি overpay ঠেকানো
            $new_status = $new_paid >= (float) $inv['amount'] ? 'Paid' : 'Partial';

            $pdo->prepare("UPDATE transport_fee_invoices SET paid_amount=?, status=?, payment_date=CURDATE(), payment_method=?, trx_id=? WHERE id=?")
                ->execute([$new_paid, $new_status, $payment_method, $trx_id, $invoice_id]);

            $_SESSION['tf_msg'] = '৳' . number_format($pay_amount, 2) . ' পেমেন্ট রেকর্ড করা হয়েছে (' . lv($inv['name_en']) . ')।';
            sec_log_activity($pdo, 'Transport', 'Update', "ট্রান্সপোর্ট ফি পেমেন্ট রেকর্ড করা হয়েছে: {$inv['name_en']} - ৳" . number_format($pay_amount, 2) . " ({$payment_method})");

            // পেমেন্ট রিসিট SMS (config/sms.php এর send_sms() reuse — SMS Settings এ Enable করা থাকলেই পাঠাবে)
            if (!empty($inv['guardian_phone'])) {
                $msg = "প্রিয় অভিভাবক, {$inv['name_en']} এর ট্রান্সপোর্ট ফি ৳" . number_format($pay_amount, 2) . " গৃহীত হয়েছে। ধন্যবাদ - {$school_name}";
                send_sms($pdo, $inv['guardian_phone'], $msg);
            }
        }
        header('Location: index.php?page=transport_fees&tab=collect&month_year=' . urlencode($_POST['month_year_ref'] ?? date('Y-m')));
        exit();
    }
}

$tab = $_GET['tab'] ?? 'generate';
$month_year = $_GET['month_year'] ?? date('Y-m');
?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'generate' ? 'active' : ''; ?>" href="index.php?page=transport_fees&tab=generate">Generate Invoices</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'collect' ? 'active' : ''; ?>" href="index.php?page=transport_fees&tab=collect">Collect Payment</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'dues' ? 'active' : ''; ?>" href="index.php?page=transport_fees&tab=dues">Dues Report</a></li>
</ul>

<div class="card-custom">
    <?php if ($tf_msg): ?><div class="alert alert-success py-2"><?php echo lv($tf_msg); ?></div><?php endif; ?>
    <?php if ($tf_err): ?><div class="alert alert-danger py-2"><?php echo lv($tf_err); ?></div><?php endif; ?>

<?php if ($tab === 'generate'): ?>
    <h5 class="fw-normal mb-3">মাসিক ট্রান্সপোর্ট ইনভয়েস তৈরি করুন</h5>
    <p class="text-muted small">যেসব শিক্ষার্থী সক্রিয়ভাবে কোনো রুটে assign আছে, তাদের সবার জন্য নির্বাচিত মাসের ইনভয়েস একসাথে তৈরি হবে। আগে তৈরি হওয়া ইনভয়েস আবার তৈরি হবে না (duplicate skip)।</p>
    <form method="POST" action="index.php?page=transport_fees" class="row g-2 align-items-end" style="max-width:500px;">
        <?php echo csrf_field(); ?>
        <div class="col-md-6">
            <label class="form-label fw-bold">Month</label>
            <input type="month" name="month_year" class="form-control" value="<?php echo date('Y-m'); ?>" required>
        </div>
        <div class="col-md-6">
            <button type="submit" name="generate_invoices" class="btn btn-primary fw-bold w-100" style="background-color:#1e3a8a;"><i class="fa-solid fa-file-invoice"></i> Generate Invoices</button>
        </div>
    </form>

    <?php
    $active_count = (int) $pdo->query("SELECT COUNT(*) c FROM transport_student_assign WHERE status='Active'")->fetch()['c'];
    ?>
    <div class="alert alert-info mt-3 py-2">এই মুহূর্তে <strong><?php echo $active_count; ?></strong> জন শিক্ষার্থী কোনো না কোনো ট্রান্সপোর্ট রুটে সক্রিয় আছে।</div>

<?php elseif ($tab === 'collect'): ?>
    <form method="GET" action="index.php" class="row g-2 mb-3">
        <input type="hidden" name="page" value="transport_fees"><input type="hidden" name="tab" value="collect">
        <div class="col-md-3"><input type="month" name="month_year" class="form-control" value="<?php echo lv($month_year); ?>" onchange="this.form.submit()"></div>
        <div class="col-md-4"><input type="text" name="q" class="form-control" placeholder="Student Name / ID দিয়ে খুঁজুন" value="<?php echo lv($_GET['q'] ?? ''); ?>"></div>
        <div class="col-md-2"><button class="btn btn-outline-primary w-100">Search</button></div>
    </form>
    <?php
    $q = trim($_GET['q'] ?? '');
    $where = ["tfi.month_year = ?"];
    $params = [$month_year];
    if ($q !== '') { $where[] = '(s.name_en LIKE ? OR s.student_id LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
    branch_apply_filter($where, $params, 's.branch_id');
    $sql = "SELECT tfi.*, s.name_en, s.student_id AS stu_code, tr.route_name
            FROM transport_fee_invoices tfi
            JOIN students s ON s.id = tfi.student_id
            JOIN transport_routes tr ON tr.id = tfi.route_id
            WHERE " . implode(' AND ', $where) . " ORDER BY tfi.status ASC, s.name_en ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light"><tr><th>Student</th><th>Route</th><th>Amount</th><th>Paid</th><th>Due</th><th>Status</th><th>Collect</th></tr></thead>
            <tbody>
                <?php if ($rows): foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); $due = (float) $row['amount'] - (float) $row['paid_amount']; ?>
                    <tr>
                        <td><?php echo lv($row['name_en']); ?> <span class="text-muted small">(<?php echo lv($row['stu_code']); ?>)</span></td>
                        <td><?php echo lv($row['route_name']); ?></td>
                        <td>৳<?php echo number_format((float) $row['amount'], 2); ?></td>
                        <td>৳<?php echo number_format((float) $row['paid_amount'], 2); ?></td>
                        <td class="<?php echo $due > 0 ? 'text-danger fw-bold' : ''; ?>">৳<?php echo number_format($due, 2); ?></td>
                        <td>
                            <?php
                            $badge = ['Unpaid' => 'bg-danger', 'Partial' => 'bg-warning text-dark', 'Paid' => 'bg-success'];
                            echo '<span class="badge ' . $badge[$row['status']] . '">' . $row['status'] . '</span>';
                            ?>
                        </td>
                        <td>
                            <?php if ($row['status'] !== 'Paid'): ?>
                            <form method="POST" action="index.php?page=transport_fees" class="d-flex gap-1">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="invoice_id" value="<?php echo (int) $row['id']; ?>">
                                <input type="hidden" name="month_year_ref" value="<?php echo lv($month_year); ?>">
                                <input type="number" step="0.01" min="1" max="<?php echo $due; ?>" name="pay_amount" class="form-control form-control-sm" style="width:90px;" placeholder="৳" value="<?php echo $due; ?>" required>
                                <select name="payment_method" class="form-select form-select-sm" style="width:90px;">
                                    <option>Cash</option><option>bKash</option><option>Nagad</option><option>Bank</option>
                                </select>
                                <button type="submit" name="collect_payment" class="btn btn-sm btn-success">Collect</button>
                            </form>
                            <?php else: ?>
                                <span class="text-muted small">পরিশোধিত</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">এই মাসের কোনো ইনভয়েস নেই — আগে "Generate Invoices" ট্যাব থেকে তৈরি করুন</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab === 'dues'): ?>
    <?php
    $stmt = $pdo->query("SELECT tfi.month_year, s.name_en, s.student_id AS stu_code, tr.route_name,
                                 tfi.amount, tfi.paid_amount, (tfi.amount - tfi.paid_amount) AS due_amt
                          FROM transport_fee_invoices tfi
                          JOIN students s ON s.id = tfi.student_id
                          JOIN transport_routes tr ON tr.id = tfi.route_id
                          WHERE tfi.status != 'Paid' ORDER BY tfi.month_year DESC, s.name_en ASC");
    $rows = $stmt->fetchAll();
    $total_due = array_sum(array_column($rows, 'due_amt'));
    ?>
    <div class="alert alert-danger py-2 mb-3">মোট বাকি: <strong>৳<?php echo number_format($total_due, 2); ?></strong> (<?php echo count($rows); ?> টি ইনভয়েস)</div>
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light"><tr><th>Month</th><th>Student</th><th>Route</th><th>Amount</th><th>Paid</th><th>Due</th></tr></thead>
            <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo lv($r['month_year']); ?></td>
                        <td><?php echo lv($r['name_en']); ?> (<?php echo lv($r['stu_code']); ?>)</td>
                        <td><?php echo lv($r['route_name']); ?></td>
                        <td>৳<?php echo number_format((float) $r['amount'], 2); ?></td>
                        <td>৳<?php echo number_format((float) $r['paid_amount'], 2); ?></td>
                        <td class="fw-bold text-danger">৳<?php echo number_format((float) $r['due_amt'], 2); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">কোনো বকেয়া নেই — চমৎকার!</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>
