<?php
// admin/result_exam_fee_collection.php - Result Module > Exam Procedure > Exam Fee Online Payments
// Student পোর্টাল থেকে জমা দেওয়া bKash/Nagad Transaction ID এখানে Approve/Reject করা যায়।
// Approve করলে সরাসরি Finance Module-এর finance_fee_collections টেবিলে একটা Paid এন্ট্রি
// বসে যায় — তাই Finance-এর সব Dues/Ledger রিপোর্টেও এটা তখনই যোগ হয়ে যাবে।
// ম্যানুয়ালি অফিসে ফি জমা নিলে সরাসরি Finance > Fees Collection পেজ থেকেই করতে হবে।
require_once __DIR__ . '/../config/exam_fee_helpers.php';
ef_ensure_tables($pdo);
require_once __DIR__ . '/finance_due_report_common.php';
dr_ensure_tables($pdo);

function efcv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$efc_msg = $_SESSION['efc_msg'] ?? '';
$efc_err = $_SESSION['efc_err'] ?? '';
unset($_SESSION['efc_msg'], $_SESSION['efc_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_action'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['efc_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $claim_id = (int) ($_POST['claim_id'] ?? 0);
        $admin_name = $_SESSION['admin_user'] ?? 'Admin';

        $c_stmt = $pdo->prepare("SELECT * FROM exam_fee_online_claims WHERE id = ? AND status = 'Pending' LIMIT 1");
        $c_stmt->execute([$claim_id]);
        $claim = $c_stmt->fetch();
        if ($claim && !branch_student_ok($pdo, (int) $claim['student_id'])) { $claim = false; }

        if (!$claim) {
            $_SESSION['efc_err'] = 'এই সাবমিশন আর Pending নেই।';
        } elseif ($_POST['claim_action'] === 'approve') {
            // ১) Finance Module এর আসল টেবিলে Paid এন্ট্রি বসানো হচ্ছে —
            //    যাতে Finance এর Dues/Ledger/Collection রিপোর্টে এটা স্বাভাবিকভাবেই হিসাবে আসে
            $ins = $pdo->prepare(
                "INSERT INTO finance_fee_collections (student_id, session_id, period_type, month, ac_head_id, amount, payment_method, trx_id, collected_by, status)
                 VALUES (?, ?, 'Monthly', ?, ?, ?, ?, ?, ?, 'Paid')"
            );
            $ins->execute([
                $claim['student_id'], $claim['session_id'], $claim['month'], $claim['ac_head_id'],
                $claim['amount'], $claim['method'], $claim['trx_id'], $admin_name,
            ]);
            // ২) Claim টা Approved হিসেবে মার্ক
            $pdo->prepare("UPDATE exam_fee_online_claims SET status = 'Approved', verified_by = ?, verified_at = NOW() WHERE id = ?")
                ->execute([$admin_name, $claim_id]);
            sec_log_activity($pdo, 'Result', 'Update', "Exam Fee Online Payment Approve করা হয়েছে: Student ID {$claim['student_id']}, Trx ID {$claim['trx_id']}");
            $_SESSION['efc_msg'] = 'Payment Approve করা হয়েছে — Finance-এ Paid এন্ট্রি যোগ হয়েছে, Student এখন Admit Card/Seat Plan দেখতে পারবে।';
        } elseif ($_POST['claim_action'] === 'reject') {
            $pdo->prepare("UPDATE exam_fee_online_claims SET status = 'Rejected', verified_by = ?, verified_at = NOW() WHERE id = ?")
                ->execute([$admin_name, $claim_id]);
            sec_log_activity($pdo, 'Result', 'Update', "Exam Fee Online Payment Reject করা হয়েছে: Student ID {$claim['student_id']}, Trx ID {$claim['trx_id']}");
            $_SESSION['efc_msg'] = 'Payment Reject করা হয়েছে।';
        }
    }
    header('Location: index.php?page=result_exam_fee_collection');
    exit();
}

$pending_stmt = $pdo->query(
    "SELECT exam_fee_online_claims.*, students.name_en, students.student_id AS student_code,
            exam_terms.term_name, finance_account_heads.ac_head
     FROM exam_fee_online_claims
     LEFT JOIN students ON students.id = exam_fee_online_claims.student_id
     LEFT JOIN exam_terms ON exam_terms.id = exam_fee_online_claims.term_id
     LEFT JOIN finance_account_heads ON finance_account_heads.id = exam_fee_online_claims.ac_head_id
     WHERE exam_fee_online_claims.status = 'Pending'" . branch_sql_inline('students.branch_id') . "
     ORDER BY exam_fee_online_claims.submitted_at DESC"
);
$pending_list = $pending_stmt->fetchAll();

$recent_stmt = $pdo->query(
    "SELECT exam_fee_online_claims.*, students.name_en, students.student_id AS student_code, exam_terms.term_name
     FROM exam_fee_online_claims
     LEFT JOIN students ON students.id = exam_fee_online_claims.student_id
     LEFT JOIN exam_terms ON exam_terms.id = exam_fee_online_claims.term_id
     WHERE exam_fee_online_claims.status != 'Pending'" . branch_sql_inline('students.branch_id') . "
     ORDER BY exam_fee_online_claims.verified_at DESC LIMIT 20"
);
$recent_list = $recent_stmt->fetchAll();
?>
<div class="card-custom">
    <?php if ($efc_msg): ?><div class="alert alert-success py-2"><?php echo efcv($efc_msg); ?></div><?php endif; ?>
    <?php if ($efc_err): ?><div class="alert alert-danger py-2"><?php echo efcv($efc_err); ?></div><?php endif; ?>

    <h6 class="fw-bold mb-3"><i class="fa-solid fa-hourglass-half me-2 text-warning"></i>Pending Online Payment Submissions (bKash/Nagad)</h6>
    <?php if (!$pending_list): ?>
        <p class="text-muted mb-0">এখন কোনো পেন্ডিং সাবমিশন নেই।</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle" style="font-size:13px;">
                <thead class="table-light"><tr><th>Student</th><th>Term</th><th>Head</th><th>Method</th><th>Trx ID</th><th>Amount</th><th>Submitted</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($pending_list as $p): ?>
                        <tr>
                            <td><?php echo efcv($p['name_en']); ?> <span class="text-muted">(<?php echo efcv($p['student_code']); ?>)</span></td>
                            <td><?php echo efcv($p['term_name']); ?></td>
                            <td><?php echo efcv($p['ac_head']); ?></td>
                            <td><?php echo efcv($p['method']); ?></td>
                            <td><?php echo efcv($p['trx_id']); ?></td>
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

<div class="card-custom">
    <h6 class="fw-bold mb-3"><i class="fa-solid fa-clock-rotate-left me-2 text-secondary"></i>Recent Approved / Rejected</h6>
    <?php if (!$recent_list): ?>
        <p class="text-muted mb-0">এখনো কোনো Approve/Reject হয়নি।</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle" style="font-size:13px;">
                <thead class="table-light"><tr><th>Student</th><th>Term</th><th>Trx ID</th><th>Status</th><th>By</th><th>Date</th></tr></thead>
                <tbody>
                    <?php foreach ($recent_list as $r): ?>
                        <tr>
                            <td><?php echo efcv($r['name_en']); ?> <span class="text-muted">(<?php echo efcv($r['student_code']); ?>)</span></td>
                            <td><?php echo efcv($r['term_name']); ?></td>
                            <td><?php echo efcv($r['trx_id']); ?></td>
                            <td><?php echo $r['status'] === 'Approved' ? '<span class="badge bg-success">Approved</span>' : '<span class="badge bg-danger">Rejected</span>'; ?></td>
                            <td><?php echo efcv($r['verified_by']); ?></td>
                            <td><?php echo $r['verified_at'] ? date('d M Y h:i A', strtotime($r['verified_at'])) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
