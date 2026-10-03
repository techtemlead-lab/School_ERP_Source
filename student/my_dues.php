<?php
// student/my_dues.php - মাসভিত্তিক Fee Dues (Admin panel এর finance_due_report_common.php
// এর dr_student_summary() হেল্পার-ই reuse করা হচ্ছে, যাতে অংক সবজায়গায় একই থাকে)
// Tuition Fee/Session Fee ইত্যাদি প্রতি মাসের সব Academic Account Head একসাথে অনলাইনে
// পরিশোধ করা যায় (config/monthly_fee_helpers.php) — দুইভাবেই:
//   (১) Auto Gateway (SSLCommerz/ShurjoPay/PayStation/EPS) — সাথে সাথেই Due শূন্য
//   (২) Manual bKash/Nagad — Transaction ID জমা দিলে Admin Approve করার পর Due শূন্য
// দুটোর সেটিংসই Global Settings > Payment Gateway Settings পেজ থেকে আসে (af_gateway_settings())
// — Admission Fee/Exam Fee এর সাথে শেয়ার করা একই সেটিংস।
$due_msg = null;
$mf_msg = '';
$mf_err = '';

try {
    require_once __DIR__ . '/../admin/finance_due_report_common.php';
    require_once __DIR__ . '/../config/monthly_fee_helpers.php';
    require_once __DIR__ . '/../config/admission_fee_helpers.php';
    dr_ensure_tables($pdo);
    mf_ensure_tables($pdo);
    af_ensure_tables($pdo);

    $session_id = (int) $student['session_id'];

    // -----------------------------------------------------------
    // Manual bKash/Nagad Transaction ID জমা — শুধু "Pending" claim হিসেবে থাকে,
    // Admin (Finance > Online Fee Payments) Approve করলে তবেই Finance-এ Paid এন্ট্রি হয়
    // -----------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mf_submit_payment'])) {
        if (!csrf_verify($_POST['csrf_token'] ?? null)) {
            $mf_err = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        } else {
            $p_month = (int) ($_POST['month'] ?? 0);
            $method = in_array($_POST['method'] ?? '', ['bKash', 'Nagad'], true) ? $_POST['method'] : 'bKash';
            $trx_id = trim($_POST['trx_id'] ?? '');
            $sender = trim($_POST['sender_number'] ?? '');

            if ($p_month < 1 || $p_month > 12) {
                $mf_err = 'কোন মাসের ফি জমা দিচ্ছেন তা বেছে নিন।';
            } elseif ($trx_id === '') {
                $mf_err = 'Transaction ID লিখুন।';
            } else {
                $info = mf_month_due($pdo, $student, $session_id, $p_month);
                if ($info['due'] <= 0) {
                    $mf_err = 'এই মাসের কোনো Due নেই।';
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO monthly_fee_online_claims (student_id, session_id, class_id, month, amount, method, sender_number, trx_id, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
                         ON DUPLICATE KEY UPDATE class_id = VALUES(class_id), amount = VALUES(amount), method = VALUES(method),
                            sender_number = VALUES(sender_number), trx_id = VALUES(trx_id), status = 'Pending',
                            submitted_at = CURRENT_TIMESTAMP, verified_by = NULL, verified_at = NULL, gateway_val_id = NULL"
                    );
                    $stmt->execute([
                        (int) $student['id'], $session_id, (int) $student['class_id'], $p_month,
                        $info['due'], $method, $sender, $trx_id,
                    ]);
                    $mf_msg = 'আপনার পেমেন্ট তথ্য জমা হয়েছে — স্কুল অফিস যাচাই করে Approve করলে এই মাসের Due শূন্য হয়ে যাবে।';
                }
            }
        }
    }

    $fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC")->fetchAll();
    $current_month = (int) date('n');

    $summary = dr_student_summary($pdo, $student, $session_id, $fee_heads, $current_month);
    $months = dr_months();

    $gw = af_gateway_settings($pdo);
    $auto_ready = af_auto_gateway_ready($gw);
    $auto_provider_label = af_provider_label($gw['auto_provider'] ?? 'sslcommerz');

    // Admin যে হেডগুলো "অনলাইনে নেওয়া হবে" বলে বেছে দিয়েছেন, শুধু সেগুলোর Due-ই অনলাইনে
    // পরিশোধ করা যাবে — তাই প্রতি মাসের মোট Due আর "অনলাইনে দেওয়া যাবে" এমন Due আলাদা।
    $allowed_heads = af_online_head_ids($gw);
    $online_due = [];
    foreach (dr_months() as $m => $_name) {
        $online_due[$m] = (($summary['month_dues'][$m] ?? 0) > 0)
            ? mf_month_due($pdo, $student, $session_id, $m)['due']
            : 0.0;
    }
    $head_filter_on = !empty($allowed_heads);

    $bkash_no = trim((string) ($gw['bkash_number'] ?? ''));
    $nagad_no = trim((string) ($gw['nagad_number'] ?? ''));
    $manual_ready = !empty($gw['manual_enabled']) && ($bkash_no !== '' || $nagad_no !== '');

    // এই সেশনের জমা দেওয়া Manual claim গুলো (মাস => claim) — স্ট্যাটাস দেখানোর জন্য
    $claims = [];
    $cl_stmt = $pdo->prepare("SELECT * FROM monthly_fee_online_claims WHERE student_id = ? AND session_id = ? AND method <> 'AutoGateway'");
    $cl_stmt->execute([(int) $student['id'], $session_id]);
    foreach ($cl_stmt->fetchAll() as $c) { $claims[(int) $c['month']] = $c; }
} catch (\Throwable $e) {
    $due_msg = 'এই মুহূর্তে Dues তথ্য দেখানো যাচ্ছে না। পরে আবার চেষ্টা করুন।';
}

$pay_result = $_GET['payment'] ?? '';
?>
<div class="card-custom">
    <h6 class="fw-bold mb-3" style="color:#0f766e;"><i class="fa-solid fa-money-bill-wave me-2"></i>My Dues (Monthly)</h6>

    <?php if ($pay_result === 'success'): ?>
        <div class="alert alert-success py-2">আপনার পেমেন্ট সফল হয়েছে — নিচের তালিকায় Due আপডেট হয়ে গেছে।</div>
    <?php elseif ($pay_result === 'failed'): ?>
        <div class="alert alert-danger py-2">অনলাইন পেমেন্ট সম্পন্ন হয়নি বা বাতিল হয়ে গেছে। আবার চেষ্টা করুন, অথবা সরাসরি স্কুল অফিসে যোগাযোগ করুন।</div>
    <?php endif; ?>
    <?php if ($mf_msg): ?><div class="alert alert-success py-2"><?php echo sp_e($mf_msg); ?></div><?php endif; ?>
    <?php if ($mf_err): ?><div class="alert alert-danger py-2"><?php echo sp_e($mf_err); ?></div><?php endif; ?>

    <?php if ($due_msg): ?>
        <div class="text-center py-4">
            <i class="fa-solid fa-money-bill-wave" style="font-size:40px;color:#14b8a6;"></i>
            <p class="text-muted mt-2 mb-0"><?php echo sp_e($due_msg); ?></p>
        </div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="p-3 rounded text-center" style="background:#fef2f2;">
                    <div class="fw-bold fs-5 text-danger"><?php echo number_format($summary['total_due'], 2); ?></div>
                    <div class="text-muted" style="font-size:11.5px;">Total Due</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 rounded text-center" style="background:#fff7ed;">
                    <div class="fw-bold fs-5 text-warning"><?php echo number_format($summary['admission_due'], 2); ?></div>
                    <div class="text-muted" style="font-size:11.5px;">Admission Due</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 rounded text-center" style="background:#eff6ff;">
                    <div class="fw-bold fs-5 text-primary"><?php echo number_format($summary['till_current'], 2); ?></div>
                    <div class="text-muted" style="font-size:11.5px;">Due Till This Month</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 rounded text-center" style="background:#ecfdf5;">
                    <div class="fw-bold fs-5 text-success"><?php echo number_format($summary['total_collection'], 2); ?></div>
                    <div class="text-muted" style="font-size:11.5px;">Total Payable (Session)</div>
                </div>
            </div>
        </div>

        <?php if (!$auto_ready && !$manual_ready): ?>
            <div class="alert alert-light border small mb-3">
                <i class="fa-solid fa-circle-info me-1"></i> অনলাইনে Tuition/Session Fee পরিশোধের সুযোগ এখনো চালু নেই — সরাসরি স্কুল অফিসে যোগাযোগ করুন।
            </div>
        <?php endif; ?>

        <?php if ($head_filter_on && ($auto_ready || $manual_ready)): ?>
            <div class="alert alert-light border small mb-3">
                <i class="fa-solid fa-circle-info me-1"></i>
                স্কুলের নির্ধারিত কিছু Fee অনলাইনে নেওয়া হয়, বাকিগুলো অফিসে জমা দিতে হয় — তাই "Pay Online" এ দেখানো এমাউন্ট মোট Due থেকে কম হতে পারে।
            </div>
        <?php endif; ?>

        <h6 class="fw-bold mb-2" style="font-size:13px;color:#334155;">Month-wise Due (Tuition, Session — সব Academic Fee একসাথে)</h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle" style="font-size:13px;">
                <thead class="table-light"><tr><th>Month</th><th>Due Amount</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($months as $m => $name): $due = $summary['month_dues'][$m] ?? 0; $claim = $claims[$m] ?? null; ?>
                        <tr>
                            <td><?php echo $name; ?></td>
                            <td><?php echo number_format($due, 2); ?></td>
                            <td>
                                <?php if ($due <= 0): ?>
                                    <span class="badge bg-success">Paid</span>
                                <?php elseif ($claim && $claim['status'] === 'Pending'): ?>
                                    <span class="badge bg-warning text-dark">যাচাইয়ের অপেক্ষায়</span>
                                <?php elseif ($m <= $current_month): ?>
                                    <span class="badge bg-danger">Due</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Upcoming</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $m_online = $online_due[$m] ?? 0; ?>
                                <?php if ($due > 0 && $auto_ready && $m_online > 0): ?>
                                    <a href="fee_payment_init.php?month=<?php echo $m; ?>" class="btn btn-sm text-white fw-bold" style="background:#0f766e;">
                                        <i class="fa-solid fa-globe me-1"></i> Pay Online<?php if ($head_filter_on && $m_online < $due): ?> (<?php echo number_format($m_online, 2); ?>)<?php endif; ?>
                                    </a>
                                <?php elseif ($due > 0 && $m_online <= 0): ?>
                                    <span class="text-muted" style="font-size:11.5px;">অফিসে জমা দিতে হবে</span>
                                <?php endif; ?>
                                <?php if ($due > 0 && $claim && $claim['status'] === 'Pending'): ?>
                                    <span class="text-muted" style="font-size:11.5px;">Trx: <?php echo sp_e($claim['trx_id']); ?></span>
                                <?php elseif ($due > 0 && $claim && $claim['status'] === 'Rejected'): ?>
                                    <span class="text-danger" style="font-size:11.5px;">আগের Trx Reject হয়েছে</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($auto_ready): ?>
            <p class="text-muted mt-2 mb-0" style="font-size:12px;">"Pay Online" চাপলে <?php echo sp_e($auto_provider_label); ?> এর মাধ্যমে Card/bKash/Nagad/Rocket দিয়ে সেই মাসের সব Fee (Tuition, Session ইত্যাদি) একসাথে পরিশোধ করতে পারবেন — পেমেন্ট সফল হলে সাথে সাথেই Due শূন্য হয়ে যাবে।</p>
        <?php endif; ?>

        <?php if ($manual_ready): ?>
            <hr class="my-3">
            <h6 class="fw-bold mb-2" style="font-size:13px;color:#334155;">
                <i class="fa-solid fa-mobile-screen-button me-1 text-primary"></i>
                <?php echo $auto_ready ? 'অথবা ম্যানুয়ালি bKash/Nagad এ পাঠিয়ে Transaction ID জমা দিন' : 'bKash/Nagad এ পাঠিয়ে Transaction ID জমা দিন'; ?>
            </h6>
            <div class="p-3 rounded mb-3" style="background:#ecfdf5;">
                <?php if ($bkash_no !== ''): ?>
                    <div class="mb-1"><strong>bKash (<?php echo sp_e($gw['bkash_type'] ?? 'Personal'); ?>):</strong> <?php echo sp_e($bkash_no); ?></div>
                <?php endif; ?>
                <?php if ($nagad_no !== ''): ?>
                    <div class="mb-1"><strong>Nagad (<?php echo sp_e($gw['nagad_type'] ?? 'Personal'); ?>):</strong> <?php echo sp_e($nagad_no); ?></div>
                <?php endif; ?>
                <?php if (trim((string) ($gw['manual_instructions'] ?? '')) !== ''): ?>
                    <div class="text-muted mt-2" style="font-size:12.5px;"><?php echo nl2br(sp_e($gw['manual_instructions'])); ?></div>
                <?php endif; ?>
            </div>

            <form method="POST" class="row g-2 align-items-end">
                <?php echo csrf_field(); ?>
                <div class="col-6 col-md-3">
                    <label class="form-label fw-bold" style="font-size:12.5px;">কোন মাসের ফি</label>
                    <select name="month" class="form-select form-select-sm" required>
                        <option value="">— মাস বাছাই করুন —</option>
                        <?php foreach ($months as $m => $name): $m_online = $online_due[$m] ?? 0; ?>
                            <?php if ($m_online > 0): ?>
                                <option value="<?php echo $m; ?>"><?php echo $name; ?> — <?php echo number_format($m_online, 2); ?> ৳</option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-bold" style="font-size:12.5px;">Method</label>
                    <select name="method" class="form-select form-select-sm">
                        <?php if ($bkash_no !== ''): ?><option value="bKash">bKash</option><?php endif; ?>
                        <?php if ($nagad_no !== ''): ?><option value="Nagad">Nagad</option><?php endif; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label fw-bold" style="font-size:12.5px;">যে নাম্বার থেকে পাঠিয়েছেন</label>
                    <input type="text" name="sender_number" class="form-control form-control-sm" placeholder="01XXXXXXXXX">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label fw-bold" style="font-size:12.5px;">Transaction ID</label>
                    <input type="text" name="trx_id" class="form-control form-control-sm" placeholder="যেমন: 8N7A6B5C4D" required>
                </div>
                <div class="col-12 col-md-1">
                    <button type="submit" name="mf_submit_payment" class="btn btn-sm text-white w-100" style="background:#0f766e;">Submit</button>
                </div>
            </form>
            <p class="text-muted mt-2 mb-0" style="font-size:12px;">জমা দেওয়ার পর স্কুল অফিস যাচাই করে Approve করলে সেই মাসের Due শূন্য হয়ে যাবে।</p>
        <?php elseif (!$auto_ready): ?>
            <p class="text-muted mt-2 mb-0" style="font-size:12px;">ফি জমা দিতে সরাসরি স্কুল অফিসে যোগাযোগ করুন।</p>
        <?php else: ?>
            <p class="text-muted mt-1 mb-0" style="font-size:12px;">এছাড়া সরাসরি স্কুল অফিসেও ফি জমা দিতে পারেন।</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
