<?php
// admin/poor_fund_aid.php - Finance Module > Scholarship & Welfare > Poor Fund / Charity Aid
// স্কুলের নিজস্ব তহবিল থেকে সরাসরি দরিদ্র/অসহায় ছাত্র-ছাত্রীকে নগদ সাহায্য — এটা কোনো ফি ওয়েভার
// না (ছাত্রের বকেয়া কমায় না), বরং স্কুলের একটা প্রকৃত খরচ (Expense)। তাই এটা Finance-এর
// Payment Voucher (finance_vouchers, voucher_type='Payment') হিসেবে বসে "Poor Fund / Charity
// Aid" নামের একটা Account Head-এ — ফলে Cash/Bank Book, Trial Balance ইত্যাদি রিপোর্টেও
// স্বয়ংক্রিয়ভাবে যুক্ত হয়ে যায়।
require_once __DIR__ . '/../config/scholarship_helpers.php';
sch_ensure_tables($pdo);
branch_ensure_column($pdo, 'finance_vouchers');

function pfv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function pf_money($n): string { return number_format((float) $n, 2); }

function pf_next_voucher_no(PDO $pdo): string
{
    $prefix = 'PF-' . date('Ymd') . '-';
    $stmt = $pdo->prepare("SELECT voucher_no FROM finance_vouchers WHERE voucher_no LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last) {
        $parts = explode('-', $last);
        $next = ((int) end($parts)) + 1;
    }
    return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
}

$pf_msg = $_SESSION['pf_msg'] ?? '';
$pf_err = $_SESSION['pf_err'] ?? '';
unset($_SESSION['pf_msg'], $_SESSION['pf_err']);

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['pf_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=poor_fund_aid');
        exit();
    }

    if (isset($_POST['save_aid'])) {
        $student_id   = (int) ($_POST['student_id'] ?? 0);
        $session_id   = (int) ($_POST['session_id'] ?? 0);
        $amount       = (float) ($_POST['amount'] ?? 0);
        $reason       = trim($_POST['reason'] ?? '');
        $trans_date   = trim($_POST['transaction_date'] ?? date('Y-m-d'));
        $payment_type = ($_POST['payment_type'] ?? 'Cash') === 'Bank' ? 'Bank' : 'Cash';
        $cheque_no    = trim($_POST['cheque_no'] ?? '');

        if (!$student_id || !$session_id || $amount <= 0 || $trans_date === '') {
            $_SESSION['pf_err'] = 'Student, Session, তারিখ ও সঠিক Amount আবশ্যক।';
            header('Location: index.php?page=poor_fund_aid');
            exit();
        }

        $stu_stmt = $pdo->prepare("SELECT student_id, name_en FROM students WHERE id = ?");
        $stu_stmt->execute([$student_id]);
        $stu = $stu_stmt->fetch();
        $party_name = $stu ? ($stu['student_id'] . ' - ' . $stu['name_en']) : 'Student #' . $student_id;

        try {
            $pdo->beginTransaction();

            $ac_head_id = sch_ensure_poor_fund_head($pdo);
            $voucher_no = pf_next_voucher_no($pdo);
            $description = 'Poor Fund / Charity Aid - ' . $party_name . ($reason !== '' ? ' (' . $reason . ')' : '');

            $vin = $pdo->prepare(
                "INSERT INTO finance_vouchers (voucher_no, voucher_type, transaction_date, payment_type, cheque_no, party_name, description, total_amount, created_by, branch_id)
                 VALUES (?, 'Payment', ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $vin->execute([$voucher_no, $trans_date, $payment_type, ($payment_type === 'Bank' && $cheque_no !== '') ? $cheque_no : null, $party_name, $description, $amount, $_SESSION['admin_user'] ?? '', branch_id_for_insert()]);
            $voucher_id = (int) $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO finance_voucher_details (voucher_id, ac_head_id, amount, remarks) VALUES (?, ?, ?, ?)")
                ->execute([$voucher_id, $ac_head_id, $amount, $reason ?: null]);

            $receipt_no = sch_next_receipt_no($pdo, 'poor_fund_aids', 'PF');
            $pdo->prepare(
                "INSERT INTO poor_fund_aids (student_id, session_id, amount, reason, voucher_id, receipt_no, given_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([$student_id, $session_id, $amount, $reason ?: null, $voucher_id, $receipt_no, $_SESSION['admin_user'] ?? '']);

            $pdo->commit();
            $_SESSION['pf_msg'] = 'Poor Fund সাহায্য এন্ট্রি করা হয়েছে — Voucher #' . $voucher_no . ', Receipt #' . $receipt_no . '।';
            sec_log_activity($pdo, 'Poor Fund', 'Create', "Poor Fund সাহায্য দেওয়া হয়েছে: {$party_name} - ৳" . pf_money($amount) . " - Voucher #{$voucher_no}");
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('poor_fund_aid save error: ' . $e->getMessage());
            $_SESSION['pf_err'] = 'সাহায্য এন্ট্রি করতে সমস্যা হয়েছে, আবার চেষ্টা করুন।';
        }
    }
    header('Location: index.php?page=poor_fund_aid');
    exit();
}

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();

$fsw_where = ["status = 'Active'"]; $fsw_params = [];
branch_apply_filter($fsw_where, $fsw_params);
$fsw_stmt = $pdo->prepare("SELECT id, student_id, roll_no, name_en, gender, session_id, class_id FROM students WHERE " . implode(' AND ', $fsw_where) . " ORDER BY CAST(roll_no AS UNSIGNED) ASC, roll_no ASC");
$fsw_stmt->execute($fsw_params);
$students = $fsw_stmt->fetchAll();

$view = $_GET['view'] ?? 'list';

// ---- CSV Export ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $pdo->query(
        "SELECT pf.*, st.name_en, st.student_id AS student_code, st.roll_no, st.gender, c.class_name, s.session_name, v.voucher_no, v.payment_type
         FROM poor_fund_aids pf
         LEFT JOIN students st ON st.id = pf.student_id
         LEFT JOIN academic_classes c ON c.id = st.class_id
         LEFT JOIN academic_sessions s ON s.id = pf.session_id
         LEFT JOIN finance_vouchers v ON v.id = pf.voucher_id
         ORDER BY pf.id DESC"
    )->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="poor_fund_aid_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Student ID', 'Name', 'Roll', 'Gender', 'Class', 'Session', 'Amount', 'Reason', 'Payment Type', 'Voucher No', 'Receipt No', 'Given At']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['student_code'], $r['name_en'], $r['roll_no'], $r['gender'], $r['class_name'], $r['session_name'], $r['amount'], $r['reason'], $r['payment_type'], $r['voucher_no'], $r['receipt_no'], $r['given_at']]);
    }
    fclose($out);
    exit();
}
?>

<?php if ($view === 'add'): ?>
    <div class="card-custom">
        <?php if ($pf_err): ?><div class="alert alert-danger py-2"><?php echo pfv($pf_err); ?></div><?php endif; ?>
        <form method="POST" action="index.php?page=poor_fund_aid" id="pfForm">
            <?php echo csrf_field(); ?>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Session <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="session_id" id="pfSession" class="form-select" required onchange="pfFilterStudents()">
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo $current_session_id === (int) $s['id'] ? 'selected' : ''; ?>><?php echo pfv($s['session_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Class</label>
                <div class="col-sm-6">
                    <select id="pfClass" class="form-select" onchange="pfFilterStudents()">
                        <option value="">সব Class</option>
                        <?php foreach ($classes as $cl): ?>
                            <option value="<?php echo (int) $cl['id']; ?>"><?php echo pfv($cl['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Student <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="student_id" id="pfStudent" class="form-select" required>
                        <option value="">Select Student</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" data-session="<?php echo (int) $s['session_id']; ?>" data-class="<?php echo (int) $s['class_id']; ?>">
                                <?php echo pfv($s['student_id'] . ' - ' . $s['name_en'] . ' - ' . $s['roll_no'] . ' (' . $s['gender'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Amount <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="number" step="0.01" min="0" name="amount" class="form-control" required placeholder="যত টাকা সাহায্য দেওয়া হচ্ছে">
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">তারিখ <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="date" name="transaction_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Payment Type</label>
                <div class="col-sm-6">
                    <select name="payment_type" id="pfPaymentType" class="form-select" onchange="document.getElementById('pfChequeWrap').classList.toggle('d-none', this.value !== 'Bank')">
                        <option value="Cash">Cash</option>
                        <option value="Bank">Bank</option>
                    </select>
                </div>
            </div>
            <div class="row mb-3 align-items-center d-none" id="pfChequeWrap">
                <label class="col-sm-3 col-form-label fw-bold text-end">Cheque/Ref No</label>
                <div class="col-sm-6">
                    <input type="text" name="cheque_no" class="form-control">
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">কারণ (ঐচ্ছিক)</label>
                <div class="col-sm-6">
                    <input type="text" name="reason" class="form-control" placeholder="যেমন: বাবা অসুস্থ, বন্যায় ক্ষতিগ্রস্ত, এতিম ইত্যাদি">
                </div>
            </div>
            <hr>
            <div class="text-center">
                <button type="submit" name="save_aid" class="btn btn-primary px-4 fw-bold">সাহায্য প্রদান করুন</button>
                <a href="index.php?page=poor_fund_aid" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div>
        </form>
    </div>
    <script>
        function pfFilterStudents() {
            const sessionId = document.getElementById('pfSession').value;
            const classId = document.getElementById('pfClass').value;
            const studentSelect = document.getElementById('pfStudent');
            Array.from(studentSelect.options).forEach(opt => {
                if (!opt.value) { opt.hidden = false; return; }
                const sessionOk = !sessionId || opt.dataset.session === sessionId;
                const classOk = !classId || opt.dataset.class === classId;
                opt.hidden = !(sessionOk && classOk);
            });
        }
        pfFilterStudents();
    </script>
<?php else: ?>
    <?php
    $rows = $pdo->query(
        "SELECT pf.*, st.name_en, st.student_id AS student_code, st.roll_no, st.gender, c.class_name, s.session_name, v.voucher_no, v.payment_type
         FROM poor_fund_aids pf
         LEFT JOIN students st ON st.id = pf.student_id
         LEFT JOIN academic_classes c ON c.id = st.class_id
         LEFT JOIN academic_sessions s ON s.id = pf.session_id
         LEFT JOIN finance_vouchers v ON v.id = pf.voucher_id
         ORDER BY pf.id DESC"
    )->fetchAll();

    $gender_breakdown = [];
    $total_given = 0.0;
    foreach ($rows as $r) {
        $total_given += (float) $r['amount'];
        $g = $r['gender'] ?: 'অজানা';
        $gender_breakdown[$g] = ($gender_breakdown[$g] ?? ['count' => 0, 'amount' => 0.0]);
        $gender_breakdown[$g]['count']++;
        $gender_breakdown[$g]['amount'] += (float) $r['amount'];
    }
    ?>
    <div class="card-custom">
        <?php if ($pf_msg): ?><div class="alert alert-success py-2"><?php echo pfv($pf_msg); ?></div><?php endif; ?>
        <?php if ($pf_err): ?><div class="alert alert-danger py-2"><?php echo pfv($pf_err); ?></div><?php endif; ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=poor_fund_aid&view=add" class="btn btn-success"><i class="fa-solid fa-hand-holding-heart"></i> সাহায্য প্রদান করুন</a>
            <a href="index.php?page=poor_fund_aid&export=csv" class="btn btn-outline-success"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
        </div>
    </div>

    <div class="row g-3 my-1">
        <div class="col-md-6">
            <div class="card-custom text-center">
                <div class="text-primary fw-bold">মোট সাহায্য প্রদান</div>
                <div class="fs-4 fw-bold">৳<?php echo pf_money($total_given); ?> (<?php echo count($rows); ?>টি এন্ট্রি)</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card-custom">
                <h6 class="fw-bold mb-2">ছেলে/মেয়ে ব্রেকডাউন</h6>
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light"><tr><th>Gender</th><th class="text-end">সংখ্যা</th><th class="text-end">মোট টাকা</th></tr></thead>
                    <tbody>
                        <?php if ($gender_breakdown): foreach ($gender_breakdown as $g => $d): ?>
                            <tr><td><?php echo pfv($g); ?></td><td class="text-end"><?php echo (int) $d['count']; ?></td><td class="text-end"><?php echo pf_money($d['amount']); ?></td></tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="3" class="text-center text-muted">কোনো রেকর্ড নেই</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card-custom mt-3">
        <h6 class="fw-bold mb-3">সাহায্যের ইতিহাস</h6>
        <div class="table-responsive">
            <table class="table table-bordered align-middle" style="font-size:13px;">
                <thead class="table-light">
                    <tr><th>Student</th><th>Gender</th><th>Class/Session</th><th class="text-end">Amount</th><th>কারণ</th><th>Payment</th><th>Voucher</th><th>Receipt</th><th>তারিখ</th></tr>
                </thead>
                <tbody>
                    <?php if ($rows): foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo pfv(($r['student_code'] ?? '') . ' - ' . ($r['name_en'] ?? '—')); ?></td>
                            <td><?php echo pfv($r['gender']); ?></td>
                            <td><?php echo pfv(($r['class_name'] ?? '') . ' / ' . ($r['session_name'] ?? '')); ?></td>
                            <td class="text-end fw-bold"><?php echo pf_money($r['amount']); ?></td>
                            <td class="text-muted small"><?php echo pfv($r['reason']); ?></td>
                            <td><?php echo pfv($r['payment_type']); ?></td>
                            <td><?php echo pfv($r['voucher_no']); ?></td>
                            <td><?php echo pfv($r['receipt_no']); ?></td>
                            <td><?php echo pfv(date('d M Y', strtotime($r['given_at']))); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="9" class="text-center text-muted py-3">এখনো কোনো Poor Fund সাহায্য দেওয়া হয়নি।</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
