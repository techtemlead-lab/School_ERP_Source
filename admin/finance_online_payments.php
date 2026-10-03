<?php
// admin/finance_online_payments.php - Finance Module > Students Accounts > Online Fee Payments
//
// Student পোর্টালের "My Dues" পেজ থেকে জমা দেওয়া Monthly/Tuition/Session Fee-এর
// bKash/Nagad Transaction ID এখানে Approve/Reject করা যায়। Approve করলে
// mf_post_to_finance() দিয়ে সেই মাসের প্রতিটা Academic Account Head-এর জন্য
// finance_fee_collections টেবিলে আলাদা আলাদা Paid এন্ট্রি বসে যায় (একই Folio No শেয়ার করে) —
// ঠিক Finance > Fees Collection পেজ থেকে ম্যানুয়ালি কালেকশন করলে যেমনটা হয়।
//
// Auto Gateway (SSLCommerz/ShurjoPay/PayStation/EPS) দিয়ে করা পেমেন্টে Admin-এর কিছু করতে
// হয় না — সেগুলো callback-এ যাচাই হয়ে সাথে সাথেই Approved হয়ে যায়; নিচের "Recent" তালিকায়
// সেগুলোও দেখা যাবে।
require_once __DIR__ . '/../config/monthly_fee_helpers.php';
require_once __DIR__ . '/../config/admission_fee_helpers.php';
require_once __DIR__ . '/finance_due_report_common.php';
mf_ensure_tables($pdo);
af_ensure_tables($pdo);
dr_ensure_tables($pdo);

function fopv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$fop_msg = $_SESSION['fop_msg'] ?? '';
$fop_err = $_SESSION['fop_err'] ?? '';
unset($_SESSION['fop_msg'], $_SESSION['fop_err']);

$fop_months = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_action'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['fop_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $claim_id = (int) ($_POST['claim_id'] ?? 0);
        $admin_name = $_SESSION['admin_user'] ?? 'Admin';

        $c_stmt = $pdo->prepare("SELECT * FROM monthly_fee_online_claims WHERE id = ? AND status = 'Pending' LIMIT 1");
        $c_stmt->execute([$claim_id]);
        $claim = $c_stmt->fetch();

        if (!$claim) {
            $_SESSION['fop_err'] = 'এই সাবমিশন আর Pending নেই।';
        } elseif ($_POST['claim_action'] === 'approve') {
            // Finance-এ আসল Paid এন্ট্রি — প্রতিটা Account Head-এর জন্য আলাদা রো, একই Folio No
            $result = mf_post_to_finance($pdo, $claim, $claim['method']);
            if ($result['count'] <= 0) {
                $_SESSION['fop_err'] = 'এই মাসে এখন আর কোনো Due নেই (সম্ভবত ইতিমধ্যে জমা হয়ে গেছে) — তাই নতুন কোনো এন্ট্রি বসানো হয়নি।';
            } else {
                $pdo->prepare(
                    "UPDATE monthly_fee_online_claims SET status = 'Approved', verified_by = ?, verified_at = NOW(), folio_no = ? WHERE id = ?"
                )->execute([$admin_name, $result['folio_no'], $claim_id]);
                sec_log_activity($pdo, 'Finance', 'Update', "Online Fee Payment Approve করা হয়েছে: Student ID {$claim['student_id']}, Trx ID {$claim['trx_id']}");
                $_SESSION['fop_msg'] = 'Payment Approve করা হয়েছে — Finance-এ ' . (int) $result['count'] . ' টি Paid এন্ট্রি যোগ হয়েছে (Folio: ' . $result['folio_no'] . ')।';
            }
        } elseif ($_POST['claim_action'] === 'reject') {
            $pdo->prepare("UPDATE monthly_fee_online_claims SET status = 'Rejected', verified_by = ?, verified_at = NOW() WHERE id = ?")
                ->execute([$admin_name, $claim_id]);
            sec_log_activity($pdo, 'Finance', 'Update', "Online Fee Payment Reject করা হয়েছে: Student ID {$claim['student_id']}, Trx ID {$claim['trx_id']}");
            $_SESSION['fop_msg'] = 'Payment Reject করা হয়েছে।';
        }
    }
    header('Location: index.php?page=finance_online_payments');
    exit();
}

$pending_list = $pdo->query(
    "SELECT monthly_fee_online_claims.*, students.name_en, students.student_id AS student_code,
            academic_classes.class_name
     FROM monthly_fee_online_claims
     LEFT JOIN students ON students.id = monthly_fee_online_claims.student_id
     LEFT JOIN academic_classes ON academic_classes.id = monthly_fee_online_claims.class_id
     WHERE monthly_fee_online_claims.status = 'Pending' AND monthly_fee_online_claims.method <> 'AutoGateway'
     ORDER BY monthly_fee_online_claims.submitted_at DESC"
)->fetchAll();

$recent_list = $pdo->query(
    "SELECT monthly_fee_online_claims.*, students.name_en, students.student_id AS student_code
     FROM monthly_fee_online_claims
     LEFT JOIN students ON students.id = monthly_fee_online_claims.student_id
     WHERE monthly_fee_online_claims.status <> 'Pending'
     ORDER BY monthly_fee_online_claims.verified_at DESC LIMIT 20"
)->fetchAll();

$gw = af_gateway_settings($pdo);
$manual_ready = !empty($gw['manual_enabled']) && (trim((string) ($gw['bkash_number'] ?? '')) !== '' || trim((string) ($gw['nagad_number'] ?? '')) !== '');
$auto_ready = af_auto_gateway_ready($gw);
?>
<div class="card-custom">
    <?php if ($fop_msg): ?><div class="alert alert-success py-2"><?php echo fopv($fop_msg); ?></div><?php endif; ?>
    <?php if ($fop_err): ?><div class="alert alert-danger py-2"><?php echo fopv($fop_err); ?></div><?php endif; ?>

    <div class="alert alert-info small d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <i class="fa-solid fa-circle-info me-1"></i>
            Student পোর্টালের <strong>My Dues</strong> পেজ থেকে অনলাইনে পাঠানো Monthly/Tuition/Session Fee এখানে আসে।
            Manual bKash/Nagad: <strong class="<?php echo $manual_ready ? 'text-success' : 'text-danger'; ?>"><?php echo $manual_ready ? 'চালু' : 'বন্ধ'; ?></strong> ·
            Auto Gateway: <strong class="<?php echo $auto_ready ? 'text-success' : 'text-danger'; ?>"><?php echo $auto_ready ? 'চালু' : 'বন্ধ'; ?></strong>
            (Auto পেমেন্ট নিজে নিজেই Approve হয়ে যায়, এখানে কিছু করতে হয় না)।
        </div>
        <a href="index.php?page=admission_payment_settings" class="btn btn-sm btn-outline-primary fw-bold text-nowrap">Payment Gateway Settings</a>
    </div>

    <h6 class="fw-bold mb-3"><i class="fa-solid fa-hourglass-half me-2 text-warning"></i>Pending Online Payment Submissions (bKash/Nagad)</h6>
    <?php if (!$pending_list): ?>
        <p class="text-muted mb-0">এখন কোনো পেন্ডিং সাবমিশন নেই।</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle" style="font-size:13px;">
                <thead class="table-light"><tr><th>Student</th><th>Class</th><th>Month</th><th>Method</th><th>Sender</th><th>Trx ID</th><th>Amount</th><th>Submitted</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($pending_list as $p): ?>
                        <tr>
                            <td><?php echo fopv($p['name_en']); ?> <span class="text-muted">(<?php echo fopv($p['student_code']); ?>)</span></td>
                            <td><?php echo fopv($p['class_name']); ?></td>
                            <td><?php echo fopv($fop_months[(int) $p['month']] ?? $p['month']); ?></td>
                            <td><?php echo fopv($p['method']); ?></td>
                            <td><?php echo fopv($p['sender_number'] ?: '—'); ?></td>
                            <td><?php echo fopv($p['trx_id']); ?></td>
                            <td><?php echo number_format((float) $p['amount'], 2); ?></td>
                            <td><?php echo date('d M Y h:i A', strtotime($p['submitted_at'])); ?></td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="claim_id" value="<?php echo (int) $p['id']; ?>">
                                    <button type="submit" name="claim_action" value="approve" class="btn btn-success btn-sm">Approve</button>
                                    <button type="submit" name="claim_action" value="reject" class="btn btn-danger btn-sm">Reject</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-muted mt-2 mb-0" style="font-size:12px;">
            Approve করার সময় সেই মুহূর্তের আসল Due অনুযায়ী প্রতিটা Account Head-এ আলাদা এন্ট্রি বসে —
            তাই ইতিমধ্যে অন্যভাবে ফি জমা হয়ে গেলে দুইবার হিসাব হবে না।
        </p>
    <?php endif; ?>
</div>

<div class="card-custom">
    <h6 class="fw-bold mb-3"><i class="fa-solid fa-clock-rotate-left me-2 text-secondary"></i>Recent Approved / Rejected</h6>
    <?php if (!$recent_list): ?>
        <p class="text-muted mb-0">এখনো কোনো Approve/Reject হয়নি।</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle" style="font-size:13px;">
                <thead class="table-light"><tr><th>Student</th><th>Month</th><th>Method</th><th>Trx ID</th><th>Amount</th><th>Folio</th><th>Status</th><th>By</th><th>Date</th></tr></thead>
                <tbody>
                    <?php foreach ($recent_list as $r): ?>
                        <tr>
                            <td><?php echo fopv($r['name_en']); ?> <span class="text-muted">(<?php echo fopv($r['student_code']); ?>)</span></td>
                            <td><?php echo fopv($fop_months[(int) $r['month']] ?? $r['month']); ?></td>
                            <td><?php echo fopv($r['method']); ?></td>
                            <td><?php echo fopv($r['trx_id']); ?></td>
                            <td><?php echo number_format((float) $r['amount'], 2); ?></td>
                            <td><?php echo fopv($r['folio_no'] ?: '—'); ?></td>
                            <td><?php echo $r['status'] === 'Approved' ? '<span class="badge bg-success">Approved</span>' : '<span class="badge bg-danger">Rejected</span>'; ?></td>
                            <td><?php echo fopv($r['verified_by'] ?: '—'); ?></td>
                            <td><?php echo $r['verified_at'] ? date('d M Y h:i A', strtotime($r['verified_at'])) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
