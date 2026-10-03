<?php
// admin/admission_fee_claims.php - Admission Module > Fee Claims / Payments
// Public Admission Form থেকে জমা দেওয়া bKash/Nagad Transaction ID (Manual) এখানে Approve/Reject করা যায়।
// Approve করলে সরাসরি Finance Module-এর finance_fee_collections টেবিলে একটা Paid এন্ট্রি বসে যায়
// (period_type='Admission') — তাই Finance-এর Ledger/Cash Book/Trial Balance/Balance Sheet রিপোর্টেও
// এটা তখনই যোগ হয়ে যাবে। Auto (Online Gateway) পেমেন্ট callback থেকেই সাথে সাথে Approved হয়ে যায় —
// সেগুলো এখানে শুধু History-তে দেখা যাবে, আলাদা করে Approve করা লাগবে না।
require_once __DIR__ . '/../config/admission_fee_helpers.php';
af_ensure_tables($pdo);

function afcv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$afc_msg = $_SESSION['afc_msg'] ?? '';
$afc_err = $_SESSION['afc_err'] ?? '';
unset($_SESSION['afc_msg'], $_SESSION['afc_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_action'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['afc_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $claim_id = (int) ($_POST['claim_id'] ?? 0);
        $admin_name = $_SESSION['admin_user'] ?? 'Admin';

        $c_stmt = $pdo->prepare("SELECT * FROM admission_fee_claims WHERE id = ? AND status = 'Pending' LIMIT 1");
        $c_stmt->execute([$claim_id]);
        $claim = $c_stmt->fetch();

        if (!$claim) {
            $_SESSION['afc_err'] = 'এই সাবমিশন আর Pending নেই।';
        } elseif ($_POST['claim_action'] === 'approve') {
            // ১) Finance Module-এর আসল টেবিলে Paid এন্ট্রি বসানো হচ্ছে (period_type='Admission')
            $fc_id = af_post_to_finance($pdo, $claim, $claim['method']);
            // ২) Claim টা Approved হিসেবে মার্ক + student-এর status কে 'Pending' থেকে আটকে রাখা হচ্ছে না —
            //    সেটা এডমিশন টেস্ট/লটারি/রেজাল্ট মডিউল থেকেই নিয়ন্ত্রিত হয়, পেমেন্ট শুধু ফি হিসাবের অংশ
            $pdo->prepare("UPDATE admission_fee_claims SET status = 'Approved', verified_by = ?, verified_at = NOW(), finance_collection_id = ? WHERE id = ?")
                ->execute([$admin_name, $fc_id ?: null, $claim_id]);
            sec_log_activity($pdo, 'Admission', 'Update', "Admission Fee Claim Approve করা হয়েছে: Trx ID {$claim['trx_id']}, Amount {$claim['amount']}");
            $_SESSION['afc_msg'] = $fc_id
                ? 'Payment Approve করা হয়েছে — Finance-এ Paid এন্ট্রি যোগ হয়েছে।'
                : 'Payment Approve করা হয়েছে, তবে কোনো Account Head সেট করা নেই বলে Finance-এ পোস্ট করা যায়নি — আগে Online Payment Settings থেকে Account Head সিলেক্ট করে নিন।';
        } elseif ($_POST['claim_action'] === 'reject') {
            $pdo->prepare("UPDATE admission_fee_claims SET status = 'Rejected', verified_by = ?, verified_at = NOW() WHERE id = ?")
                ->execute([$admin_name, $claim_id]);
            sec_log_activity($pdo, 'Admission', 'Update', "Admission Fee Claim Reject করা হয়েছে: Trx ID {$claim['trx_id']}, Amount {$claim['amount']}");
            $_SESSION['afc_msg'] = 'Payment Reject করা হয়েছে।';
        }
    }
    header('Location: index.php?page=admission_fee_claims');
    exit();
}

$pending_stmt = $pdo->query(
    "SELECT admission_fee_claims.*, students.name_en, students.student_id AS student_code, students.mobile,
            academic_classes.class_name, academic_sessions.session_name
     FROM admission_fee_claims
     LEFT JOIN students ON students.id = admission_fee_claims.student_pk
     LEFT JOIN academic_classes ON academic_classes.id = admission_fee_claims.class_id
     LEFT JOIN academic_sessions ON academic_sessions.id = admission_fee_claims.session_id
     WHERE admission_fee_claims.status = 'Pending' AND admission_fee_claims.method != 'AutoGateway'
     ORDER BY admission_fee_claims.submitted_at DESC"
);
$pending_list = $pending_stmt->fetchAll();

$recent_stmt = $pdo->query(
    "SELECT admission_fee_claims.*, students.name_en, students.student_id AS student_code,
            academic_classes.class_name, academic_sessions.session_name
     FROM admission_fee_claims
     LEFT JOIN students ON students.id = admission_fee_claims.student_pk
     LEFT JOIN academic_classes ON academic_classes.id = admission_fee_claims.class_id
     LEFT JOIN academic_sessions ON academic_sessions.id = admission_fee_claims.session_id
     WHERE admission_fee_claims.status != 'Pending'
     ORDER BY admission_fee_claims.submitted_at DESC LIMIT 30"
);
$recent_list = $recent_stmt->fetchAll();

$auto_pending_stmt = $pdo->query(
    "SELECT admission_fee_claims.*, students.name_en, students.student_id AS student_code,
            academic_classes.class_name, academic_sessions.session_name
     FROM admission_fee_claims
     LEFT JOIN students ON students.id = admission_fee_claims.student_pk
     LEFT JOIN academic_classes ON academic_classes.id = admission_fee_claims.class_id
     LEFT JOIN academic_sessions ON academic_sessions.id = admission_fee_claims.session_id
     WHERE admission_fee_claims.status = 'Pending' AND admission_fee_claims.method = 'AutoGateway'
     ORDER BY admission_fee_claims.submitted_at DESC"
);
$auto_pending_list = $auto_pending_stmt->fetchAll();
?>
<div class="card-custom">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><i class="fa-solid fa-receipt me-2"></i>Admission Fee Claims / Payments</h5>
        <a href="index.php?page=admission_payment_settings" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-gear"></i> Online Payment Settings
        </a>
    </div>
    <?php if ($afc_msg): ?><div class="alert alert-success py-2"><?php echo afcv($afc_msg); ?></div><?php endif; ?>
    <?php if ($afc_err): ?><div class="alert alert-danger py-2"><?php echo afcv($afc_err); ?></div><?php endif; ?>

    <h6 class="fw-bold mb-3"><i class="fa-solid fa-hourglass-half me-2 text-warning"></i>Pending Manual Submissions (bKash/Nagad)</h6>
    <?php if (!$pending_list): ?>
        <p class="text-muted mb-0">এখন কোনো পেন্ডিং Manual সাবমিশন নেই।</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle" style="font-size:13px;">
                <thead class="table-light"><tr><th>Applicant</th><th>Class</th><th>Session</th><th>Method</th><th>Sender No.</th><th>Trx ID</th><th>Amount</th><th>Submitted</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($pending_list as $p): ?>
                        <tr>
                            <td><?php echo afcv($p['name_en']); ?> <span class="text-muted">(<?php echo afcv($p['student_code']); ?>)</span></td>
                            <td><?php echo afcv($p['class_name']); ?></td>
                            <td><?php echo afcv($p['session_name']); ?></td>
                            <td><?php echo afcv($p['method']); ?></td>
                            <td><?php echo afcv($p['sender_number']); ?></td>
                            <td><?php echo afcv($p['trx_id']); ?></td>
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
    <?php endif; ?>
</div>

<?php if ($auto_pending_list): ?>
<div class="card-custom">
    <h6 class="fw-bold mb-3"><i class="fa-solid fa-triangle-exclamation me-2 text-danger"></i>Auto Gateway - Incomplete / Awaiting Callback</h6>
    <p class="text-muted small">এগুলো Online Gateway-তে শুরু হয়েছিল কিন্তু এখনো Callback/Validation সম্পন্ন হয়নি (student পেমেন্ট শেষ করেননি বা নেটওয়ার্ক সমস্যা হয়েছে)। এগুলো স্বয়ংক্রিয়ভাবে Approve হওয়ার কথা — ম্যানুয়ালি Approve করার আগে Trx ID যাচাই করে নিন।</p>
    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle" style="font-size:13px;">
            <thead class="table-light"><tr><th>Applicant</th><th>Class</th><th>Session</th><th>Amount</th><th>Started</th></tr></thead>
            <tbody>
                <?php foreach ($auto_pending_list as $p): ?>
                    <tr>
                        <td><?php echo afcv($p['name_en']); ?> <span class="text-muted">(<?php echo afcv($p['student_code']); ?>)</span></td>
                        <td><?php echo afcv($p['class_name']); ?></td>
                        <td><?php echo afcv($p['session_name']); ?></td>
                        <td><?php echo number_format((float) $p['amount'], 2); ?></td>
                        <td><?php echo date('d M Y h:i A', strtotime($p['submitted_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card-custom">
    <h6 class="fw-bold mb-3"><i class="fa-solid fa-clock-rotate-left me-2 text-secondary"></i>Recent Approved / Rejected / Failed</h6>
    <?php if (!$recent_list): ?>
        <p class="text-muted mb-0">এখনো কোনো Approve/Reject হয়নি।</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle" style="font-size:13px;">
                <thead class="table-light"><tr><th>Applicant</th><th>Class</th><th>Method</th><th>Trx ID</th><th>Amount</th><th>Status</th><th>By</th><th>Date</th></tr></thead>
                <tbody>
                    <?php foreach ($recent_list as $r): ?>
                        <tr>
                            <td><?php echo afcv($r['name_en']); ?> <span class="text-muted">(<?php echo afcv($r['student_code']); ?>)</span></td>
                            <td><?php echo afcv($r['class_name']); ?></td>
                            <td><?php echo afcv($r['method']); ?></td>
                            <td><?php echo afcv($r['trx_id']); ?></td>
                            <td><?php echo number_format((float) $r['amount'], 2); ?></td>
                            <td>
                                <?php
                                $badge = ['Approved' => 'success', 'Rejected' => 'danger', 'Failed' => 'secondary'][$r['status']] ?? 'secondary';
                                echo '<span class="badge bg-' . $badge . '">' . afcv($r['status']) . '</span>';
                                ?>
                            </td>
                            <td><?php echo afcv($r['verified_by']); ?></td>
                            <td><?php echo $r['verified_at'] ? date('d M Y h:i A', strtotime($r['verified_at'])) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
