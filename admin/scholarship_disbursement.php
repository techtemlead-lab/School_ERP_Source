<?php
// admin/scholarship_disbursement.php - Finance Module > Scholarship & Welfare > Process Disbursement
// একটা Pending Award-এর টাকা কীভাবে বণ্টন হবে সেটা এখানে চূড়ান্ত করা হয়:
// - কতটুকু ছাত্র/ছাত্রীর বকেয়া বেতনের সাথে সমন্বয় হবে (Admission আগে, তারপর মাস অনুক্রমে —
//   sch_waterfall_adjust() স্বয়ংক্রিয়ভাবে হিসাব করে দেয়, Finance Due Report-এর সাথেই মেলানো)
// - বাকি টাকা অভিভাবককে হাতে/মোবাইল ব্যাংকিংয়ে কত দেওয়া হলো
// সেভ করলে সমন্বিত অংশ finance_fee_collections-এ Paid এন্ট্রি হিসেবে বসে যায়, তাই সব Finance
// রিপোর্টে (Due, Ledger, Cash/Bank Book) এটা স্বয়ংক্রিয়ভাবে প্রতিফলিত হয়।
require_once __DIR__ . '/../config/portal_access.php';
erp_portal_controller_role(['admin']);
erp_portal_require_role($pdo, 'admin', 'scholarship_disbursement');
[$sd_scope, $sd_params] = erp_portal_student_scope($pdo, 'admin', 'a.student_id');
require_once __DIR__ . '/../config/scholarship_helpers.php';
require_once __DIR__ . '/finance_due_report_common.php';
sch_ensure_tables($pdo);
dr_ensure_tables($pdo);

function sdv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function sd_money($n): string { return number_format((float) $n, 2); }

$sd_msg = $_SESSION['sd_msg'] ?? '';
$sd_err = $_SESSION['sd_err'] ?? '';
$sd_receipt_id = (int) ($_SESSION['sd_receipt_id'] ?? 0);
unset($_SESSION['sd_msg'], $_SESSION['sd_err'], $_SESSION['sd_receipt_id']);

$fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC")->fetchAll();
$MONTHS = dr_months();

// -----------------------------------------------------------
// POST — চূড়ান্ত Disbursement সেভ
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_disbursement'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sd_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=scholarship_disbursement');
        exit();
    }

    $award_id = (int) ($_POST['award_id'] ?? 0);
    $adjust_amount = (float) ($_POST['adjust_amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? 'Cash');
    $trx_id = trim($_POST['trx_id'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    // Allocate the existing sequential receipt numbers once per school, including
    // simultaneous payments for different students.
    $receipt_lock = 'scholarship_payment_' . substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
    $lock_stmt = $pdo->prepare('SELECT GET_LOCK(?,10)');
    $lock_stmt->execute([$receipt_lock]);
    if ((int) $lock_stmt->fetchColumn() !== 1) {
        $_SESSION['sd_err'] = 'Another scholarship payment is processing. Please try again.';
        header('Location: index.php?page=scholarship_disbursement'); exit;
    }
    $pdo->beginTransaction();
    try {
        $a_stmt = $pdo->prepare(
            "SELECT a.*, t.name AS type_name FROM scholarship_awards a LEFT JOIN scholarship_types t ON t.id = a.scholarship_type_id
             WHERE a.id = ? AND a.status = 'Pending' AND $sd_scope LIMIT 1 FOR UPDATE"
        );
        $a_stmt->execute(array_merge([$award_id], $sd_params));
        $award = $a_stmt->fetch();

        if (!$award) {
            $pdo->rollBack();
            $_SESSION['sd_err'] = 'এই Award আর Pending নেই বা পাওয়া যায়নি।';
            header('Location: index.php?page=scholarship_disbursement');
            exit();
        }

        $student_lock = $pdo->prepare('SELECT id FROM students WHERE id=? FOR UPDATE');
        $student_lock->execute([(int) $award['student_id']]);
        if (!$student_lock->fetchColumn() || !is_finite($adjust_amount)) { throw new RuntimeException('Invalid disbursement.'); }
        $total_amount = (float) $award['amount'];
        if ($adjust_amount < 0) { $adjust_amount = 0; }
        if ($adjust_amount > $total_amount) { $adjust_amount = $total_amount; }

        // adjust_amount পর্যন্ত waterfall প্রয়োগ করে প্রকৃত সমন্বয়যোগ্য লাইন বের করা হচ্ছে
        // (admin যা চেয়েছে তার চেয়ে Due কম থাকলে বাকিটা এমনিতেই Cash হয়ে যাবে)
        $result = $adjust_amount > 0
            ? sch_waterfall_adjust($pdo, (int) $award['student_id'], (int) $award['session_id'], (int) $award['class_id'], $adjust_amount, $fee_heads)
            : ['lines' => [], 'adjusted_total' => 0.0, 'cash_amount' => 0.0];

        $adjusted_total = $result['adjusted_total'];
        $cash_amount = round($total_amount - $adjusted_total, 2);

        $receipt_no = sch_next_receipt_no($pdo, 'scholarship_disbursements', 'SCH');
        $ins = $pdo->prepare(
            "INSERT INTO scholarship_disbursements (award_id, student_id, total_amount, adjusted_amount, cash_amount, payment_method, trx_id, receipt_no, remarks, disbursed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([
            $award_id, $award['student_id'], $total_amount, $adjusted_total, $cash_amount,
            $payment_method, $trx_id ?: null, $receipt_no, $remarks ?: null, $_SESSION['admin_user'] ?? '',
        ]);
        $disbursement_id = (int) $pdo->lastInsertId();

        // Existing fee collections limit payment_method to 30 characters.
        $method_label = substr('Scholarship-' . preg_replace('/[^A-Za-z0-9]/', '', $award['type_name'] ?: 'General'), 0, 30);
        $fc_folio = 'SCH' . date('ymd') . strtoupper(substr(uniqid(), -5));

        foreach ($result['lines'] as $ln) {
            $period_type = $ln['period'] === 0 ? 'Admission' : 'Monthly';
            $fc_ins = $pdo->prepare(
                "INSERT INTO finance_fee_collections (student_id, session_id, period_type, month, ac_head_id, amount, payment_method, folio_no, collected_by, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Paid')"
            );
            $fc_ins->execute([
                $award['student_id'], $award['session_id'], $period_type, $ln['period'], $ln['ac_head_id'],
                $ln['amount'], $method_label, $fc_folio, 'Scholarship Adjustment',
            ]);
            $fc_id = (int) $pdo->lastInsertId();

            $pdo->prepare(
                "INSERT INTO scholarship_disbursement_lines (disbursement_id, ac_head_id, period, amount, finance_collection_id) VALUES (?, ?, ?, ?, ?)"
            )->execute([$disbursement_id, $ln['ac_head_id'], $ln['period'], $ln['amount'], $fc_id]);
        }

        $pdo->prepare("UPDATE scholarship_awards SET status = 'Disbursed' WHERE id = ?")->execute([$award_id]);

        $pdo->commit();
        $_SESSION['sd_receipt_id'] = $disbursement_id;
        $_SESSION['sd_msg'] = 'Disbursement সম্পন্ন হয়েছে — বকেয়ার সাথে সমন্বয় হলো ৳' . sd_money($adjusted_total) . ', অভিভাবককে দেওয়া হলো ৳' . sd_money($cash_amount) . '। Receipt: ' . $receipt_no;
        $sd_stu_row = $pdo->prepare("SELECT name_en, roll_no FROM students WHERE id = ?");
        $sd_stu_row->execute([$award['student_id']]);
        $sd_stu = $sd_stu_row->fetch();
        sec_log_activity($pdo, 'Scholarship', 'Update', "স্কলারশিপ Disbursement সম্পন্ন হয়েছে: {$sd_stu['name_en']} (Roll: {$sd_stu['roll_no']}) - {$award['type_name']} - সমন্বয় ৳" . sd_money($adjusted_total) . ', নগদ ৳' . sd_money($cash_amount) . " - Receipt: {$receipt_no}");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('Scholarship disbursement failed: ' . $e->getMessage());
        $_SESSION['sd_err'] = 'সেভ করতে সমস্যা হয়েছে, আবার চেষ্টা করুন।';
    } finally {
        $release_lock = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release_lock->execute([$receipt_lock]);
    }

    header('Location: index.php?page=scholarship_disbursement');
    exit();
}

// -----------------------------------------------------------
// পেন্ডিং Award নির্বাচন করলে (award_id দিয়ে) preview দেখানো
// -----------------------------------------------------------
$award_id = (int) ($_GET['award_id'] ?? 0);
// Scholarship Application অনুমোদনের পর আসলে ?mode=fee|cash — অ্যাডমিনের বেছে নেওয়া প্রদানের ধরন অনুযায়ী ডিফল্ট ঠিক হয়
$sd_mode = in_array($_GET['mode'] ?? '', ['fee', 'cash'], true) ? $_GET['mode'] : '';
$sd_default_adjust = 0.0;
$preview_award = null;
$due_summary = null;
$waterfall_preview = null;

if ($award_id) {
    $a_stmt = $pdo->prepare(
        "SELECT a.*, t.name AS type_name, t.category, s.session_name, c.class_name,
                st.name_en, st.student_id AS student_code, st.roll_no, st.gender, st.class_id AS stu_class_id
         FROM scholarship_awards a
         LEFT JOIN scholarship_types t ON t.id = a.scholarship_type_id
         LEFT JOIN academic_sessions s ON s.id = a.session_id
         LEFT JOIN academic_classes c ON c.id = a.class_id
         LEFT JOIN students st ON st.id = a.student_id
         WHERE a.id = ? AND a.status = 'Pending' AND $sd_scope LIMIT 1"
    );
    $a_stmt->execute(array_merge([$award_id], $sd_params));
    $preview_award = $a_stmt->fetch();

    if ($preview_award) {
        $current_month = (int) date('n');
        $due_summary = dr_student_summary($pdo, ['id' => $preview_award['student_id'], 'class_id' => $preview_award['class_id']], (int) $preview_award['session_id'], $fee_heads, $current_month);
        // ডিফল্ট প্রিভিউ — পুরো Award Amount দিয়ে waterfall চালিয়ে কত সমন্বয় হবে তা দেখানো (Admin পরে বদলাতে পারবে)
        $waterfall_preview = sch_waterfall_adjust($pdo, (int) $preview_award['student_id'], (int) $preview_award['session_id'], (int) $preview_award['class_id'], (float) $preview_award['amount'], $fee_heads);
        $sd_default_adjust = ($sd_mode === 'cash') ? 0.0 : (float) min($waterfall_preview['adjusted_total'], $preview_award['amount']);
    }
}

$pending_stmt = $pdo->prepare(
    "SELECT a.*, t.name AS type_name, s.session_name, c.class_name, st.name_en, st.student_id AS student_code, st.roll_no, st.gender
     FROM scholarship_awards a
     LEFT JOIN scholarship_types t ON t.id = a.scholarship_type_id
     LEFT JOIN academic_sessions s ON s.id = a.session_id
     LEFT JOIN academic_classes c ON c.id = a.class_id
     LEFT JOIN students st ON st.id = a.student_id
     WHERE a.status = 'Pending' AND $sd_scope ORDER BY a.id DESC"
);
$pending_stmt->execute($sd_params);
$pending_list = $pending_stmt->fetchAll();
?>

<?php if ($sd_msg): ?><div class="alert alert-success py-2"><?php echo sdv($sd_msg); ?>
    <?php if ($sd_receipt_id): ?><a href="../scholarship_receipt_print.php?portal=admin&amp;id=<?php echo $sd_receipt_id; ?>" target="_blank" class="btn btn-sm btn-dark ms-2"><i class="fa-solid fa-print me-1"></i> Money Receipt প্রিন্ট</a><?php endif; ?></div><?php endif; ?>
<?php if ($sd_err): ?><div class="alert alert-danger py-2"><?php echo sdv($sd_err); ?></div><?php endif; ?>

<?php if ($preview_award): ?>
    <div class="card-custom mb-3">
        <h5 class="mb-3"><i class="fa-solid fa-hand-holding-dollar me-2"></i>Disburse — <?php echo sdv($preview_award['name_en']); ?> (<?php echo sdv($preview_award['student_code']); ?>)</h5>
        <div class="row g-2 mb-3">
            <div class="col-md-3"><strong>Scholarship:</strong> <?php echo sdv($preview_award['type_name']); ?></div>
            <div class="col-md-3"><strong>Class/Session:</strong> <?php echo sdv($preview_award['class_name'] . ' / ' . $preview_award['session_name']); ?></div>
            <div class="col-md-3"><strong>Gender:</strong> <?php echo sdv($preview_award['gender']); ?></div>
            <div class="col-md-3"><strong>Award Amount:</strong> ৳<?php echo sd_money($preview_award['amount']); ?></div>
        </div>

        <div class="alert alert-secondary py-2">
            বর্তমান মোট বকেয়া: <strong>৳<?php echo sd_money($due_summary['total_due']); ?></strong> —
            স্বয়ংক্রিয়ভাবে Admission আগে, তারপর মাস অনুক্রমে (যে হেডে Due আছে) সমন্বয় হবে।
        </div>
        <?php if ($sd_mode !== ''): ?>
            <div class="alert alert-info py-2">আবেদন অনুযায়ী প্রদানের ধরন: <strong><?php echo $sd_mode === 'cash' ? 'হাতে নগদ (পুরো টাকা অভিভাবককে)' : 'ফি-র বকেয়ার সাথে সমন্বয়'; ?></strong> — প্রয়োজনে নিচের পরিমাণ বদলাতে পারেন।</div>
        <?php endif; ?>

        <form method="POST" action="index.php?page=scholarship_disbursement" id="sdForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="award_id" value="<?php echo (int) $preview_award['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">বকেয়ার সাথে সমন্বয় (Adjust Against Due)</label>
                <div class="col-sm-6">
                    <input type="number" step="0.01" min="0" max="<?php echo sdv($preview_award['amount']); ?>" name="adjust_amount" id="sdAdjustAmount"
                           class="form-control" value="<?php echo number_format($sd_default_adjust, 2, '.', ''); ?>" onchange="sdRecalc()">
                    <small class="text-muted">সর্বোচ্চ ৳<?php echo sd_money(min($due_summary['total_due'], $preview_award['amount'])); ?> (বকেয়া ও Award Amount যেটা কম)। ০ দিলে পুরো টাকাই অভিভাবককে দেওয়া হবে।</small>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">অভিভাবককে দেওয়া হবে (Cash)</label>
                <div class="col-sm-6">
                    <input type="text" id="sdCashAmount" class="form-control" value="<?php echo sd_money(max(0, $preview_award['amount'] - $sd_default_adjust)); ?>" readonly>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Cash অংশের Payment Method</label>
                <div class="col-sm-6">
                    <select name="payment_method" class="form-select">
                        <option value="Cash">Cash</option>
                        <option value="bKash">bKash</option>
                        <option value="Nagad">Nagad</option>
                        <option value="Bank">Bank</option>
                    </select>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Trx ID (ঐচ্ছিক)</label>
                <div class="col-sm-6">
                    <input type="text" name="trx_id" class="form-control">
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Remarks (ঐচ্ছিক)</label>
                <div class="col-sm-6">
                    <input type="text" name="remarks" class="form-control">
                </div>
            </div>

            <?php if ($waterfall_preview['lines'] && $sd_mode !== 'cash'): ?>
            <div class="row mb-3">
                <label class="col-sm-3 col-form-label fw-bold text-end pt-0">প্রিভিউ — কোথায় সমন্বয় হবে</label>
                <div class="col-sm-9">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light"><tr><th>Period</th><th>Head</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                            <?php foreach ($waterfall_preview['lines'] as $ln): ?>
                                <tr>
                                    <td><?php echo $ln['period'] === 0 ? 'Admission' : sdv($MONTHS[$ln['period']]); ?></td>
                                    <td><?php echo sdv($ln['head_name']); ?></td>
                                    <td class="text-end"><?php echo sd_money($ln['amount']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <small class="text-muted">নোট: "বকেয়ার সাথে সমন্বয়" এমাউন্ট বদলালে এই ব্রেকডাউন সেভ করার পর নতুন করে হিসাব হবে (এই প্রিভিউ পুরো Award Amount ধরে দেখানো)।</small>
                </div>
            </div>
            <?php endif; ?>

            <hr>
            <div class="text-center">
                <button type="submit" name="confirm_disbursement" class="btn btn-primary px-4 fw-bold" onclick="return confirm('নিশ্চিত করতে চান?');">Confirm Disbursement</button>
                <a href="index.php?page=scholarship_disbursement" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div>
        </form>
    </div>

    <script>
    function sdRecalc() {
        const total = <?php echo (float) $preview_award['amount']; ?>;
        const adj = parseFloat(document.getElementById('sdAdjustAmount').value) || 0;
        const cash = Math.max(0, total - adj);
        document.getElementById('sdCashAmount').value = cash.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    </script>
<?php elseif ($award_id): ?>
    <div class="alert alert-warning">এই Award আর Pending নেই বা পাওয়া যায়নি।</div>
<?php endif; ?>

<div class="card-custom">
    <h6 class="fw-bold mb-3"><i class="fa-solid fa-hourglass-half me-2 text-warning"></i>Pending Awards — Disbursement বাকি</h6>
    <?php if (!$pending_list): ?>
        <p class="text-muted mb-0">এখন কোনো Pending Award নেই। <a href="index.php?page=scholarship_award&view=add">নতুন Award এসাইন করুন</a>।</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light"><tr><th>Student</th><th>Scholarship</th><th>Class/Session</th><th>Gender</th><th class="text-end">Amount</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($pending_list as $p): ?>
                        <tr>
                            <td><?php echo sdv($p['student_code'] . ' - ' . $p['name_en'] . ' - ' . $p['roll_no']); ?></td>
                            <td><?php echo sdv($p['type_name']); ?></td>
                            <td><?php echo sdv($p['class_name'] . ' / ' . $p['session_name']); ?></td>
                            <td><?php echo sdv($p['gender']); ?></td>
                            <td class="text-end fw-bold"><?php echo sd_money($p['amount']); ?></td>
                            <td><a href="index.php?page=scholarship_disbursement&award_id=<?php echo (int) $p['id']; ?>" class="btn btn-sm btn-success">Disburse</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
