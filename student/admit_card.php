<?php
// student/admit_card.php - Exam Fee (Finance Module driven) পরিশোধ থাকলে Admit Card দেখাবে/প্রিন্ট করা যাবে,
// না থাকলে ফি পেমেন্টের গাইডেন্স (Online গেটওয়ে থাকলে সেই ফর্ম, না থাকলে অফিসে যেতে বলা)
require_once __DIR__ . '/../config/exam_fee_helpers.php';
require_once __DIR__ . '/../config/admission_fee_helpers.php';
ef_ensure_tables($pdo);
af_ensure_tables($pdo);

function acv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$terms_stmt = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
$terms_stmt->execute([(int) $student['session_id']]);
$terms = $terms_stmt->fetchAll();

$sel_term = (int) ($_GET['term_id'] ?? ($terms[0]['id'] ?? 0));

$pay_msg = '';
$pay_err = '';

// -----------------------------------------------------------
// Student নিজে bKash/Nagad Transaction ID জমা দিলে — এটা শুধু "Pending" claim হিসেবে
// থাকে, Admin Approve করলে তবেই Finance-এর আসল Paid এন্ট্রি হয়
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $pay_err = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $p_term = (int) ($_POST['term_id'] ?? 0);
        $method = in_array($_POST['method'] ?? '', ['bKash', 'Nagad'], true) ? $_POST['method'] : 'bKash';
        $trx_id = trim($_POST['trx_id'] ?? '');
        $cfg = ef_term_fee_config($pdo, $p_term);

        if ($trx_id === '') {
            $pay_err = 'Transaction ID লিখুন।';
        } elseif ($cfg['fee_month'] <= 0 || $cfg['ac_head_id'] <= 0) {
            $pay_err = 'এই Term-এর জন্য Exam Fee সেট করা নেই।';
        } elseif (!af_head_online_allowed(af_gateway_settings($pdo), (int) $cfg['ac_head_id'])) {
            $pay_err = 'এই ফি অনলাইনে নেওয়া হয় না — সরাসরি স্কুল অফিসে জমা দিন।';
        } else {
            $elig = ef_is_eligible($pdo, (int) $student['session_id'], $p_term, (int) $student['class_id'], (int) $student['id']);
            $stmt = $pdo->prepare(
                "INSERT INTO exam_fee_online_claims (session_id, term_id, student_id, ac_head_id, month, amount, method, trx_id, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
                 ON DUPLICATE KEY UPDATE ac_head_id = VALUES(ac_head_id), month = VALUES(month), amount = VALUES(amount),
                    method = VALUES(method), trx_id = VALUES(trx_id), status = 'Pending', submitted_at = CURRENT_TIMESTAMP,
                    verified_by = NULL, verified_at = NULL"
            );
            $stmt->execute([(int) $student['session_id'], $p_term, $student['id'], $cfg['ac_head_id'], $cfg['fee_month'], $elig['amount'], $method, $trx_id]);
            $pay_msg = 'আপনার পেমেন্ট তথ্য জমা হয়েছে — স্কুল অফিস যাচাই করার পর Admit Card এখানে দেখা যাবে।';
        }
        $sel_term = $p_term;
    }
}

$eligibility = $sel_term ? ef_is_eligible($pdo, (int) $student['session_id'], $sel_term, (int) $student['class_id'], (int) $student['id']) : ['eligible' => false, 'amount' => 0, 'claim' => null];
$gateway = ef_gateway_settings($pdo); // ম্যানুয়াল bKash/Nagad — Global Payment Gateway Settings থেকেই আসে
$auto_gw = af_gateway_settings($pdo); // Auto (অনলাইন গেটওয়ে) — একই Global সেটিংস
$auto_ready = af_auto_gateway_ready($auto_gw);
$auto_provider_label = af_provider_label($auto_gw['auto_provider'] ?? 'sslcommerz');

// Global Settings > Payment Gateway Settings এ এই Term-এর Account Head টা "অনলাইনে নেওয়া হবে"
// বলে টিক দেওয়া না থাকলে অনলাইন পেমেন্টের কোনো অপশনই দেখানো হবে না (অফিসে জমা দিতে হবে)
if (!af_head_online_allowed($auto_gw, (int) ($eligibility['ac_head_id'] ?? 0))) {
    $auto_ready = false;
    $gateway['is_enabled'] = 0;
}

$pay_result = $_GET['payment'] ?? '';
if ($pay_result === 'success' && !$pay_msg) {
    $pay_msg = 'আপনার পেমেন্ট সফল হয়েছে — Admit Card এখন নিচে দেখা যাচ্ছে।';
} elseif ($pay_result === 'failed' && !$pay_err) {
    $pay_err = 'অনলাইন পেমেন্ট সম্পন্ন হয়নি বা বাতিল হয়ে গেছে। আবার চেষ্টা করুন, অথবা ম্যানুয়ালি bKash/Nagad Transaction ID জমা দিন।';
}

$term_name = '';
foreach ($terms as $t) { if ((int) $t['id'] === $sel_term) { $term_name = $t['term_name']; break; } }

$class_name   = sp_lookup_name($pdo, 'academic_classes', $student['class_id'] ?: null);
$section_name = sp_lookup_name($pdo, 'academic_sections', $student['section_id'] ?: null);
$shift_name   = sp_lookup_name($pdo, 'academic_shifts', $student['shift_id'] ?: null);

$institute = $pdo->query("SELECT institute_name, logo_path FROM institute_settings WHERE id = 1")->fetch();
$branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
$addr_line = $branch ? ($branch['address'] . ', ' . $branch['city'] . '-' . $branch['zip_code']) : '';
$inst_display_name = ($branch['branch_name'] ?? null) ?: ($institute['institute_name'] ?? '');
?>
<?php if (!$terms): ?>
    <div class="card-custom text-center py-5">
        <i class="fa-solid fa-id-card" style="font-size:48px;color:#14b8a6;"></i>
        <p class="text-muted mt-2 mb-0">এখনো কোনো Exam Term তৈরি করা হয়নি।</p>
    </div>
<?php else: ?>

<div class="card-custom no-print">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="page" value="admit_card">
        <div class="col-auto">
            <label class="form-label fw-bold" style="font-size:12.5px;">Exam Term</label>
            <select name="term_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($terms as $t): ?>
                    <option value="<?php echo (int) $t['id']; ?>" <?php echo (int) $t['id'] === $sel_term ? 'selected' : ''; ?>><?php echo acv($t['term_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if ($pay_msg): ?><div class="alert alert-success py-2"><?php echo acv($pay_msg); ?></div><?php endif; ?>
<?php if ($pay_err): ?><div class="alert alert-danger py-2"><?php echo acv($pay_err); ?></div><?php endif; ?>

<?php if ($eligibility['eligible']): ?>
    <!-- ================= ADMIT CARD ================= -->
    <style>
        @media print { .no-print { display: none !important; } body { background: #fff; } }
        .admitcard {
            width: 520px; max-width: 100%; border: 1px solid #333; border-radius: 4px; padding: 14px 18px;
            display: inline-block; vertical-align: top; margin: 8px auto; font-family: Arial, sans-serif; background: #fff;
        }
        .admitcard .ac-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
        .admitcard .ac-qr { width: 70px; height: 70px; flex-shrink: 0; }
        .admitcard .ac-photo { width: 65px; height: 78px; object-fit: cover; border: 1px solid #999; border-radius: 3px; background: #eee; flex-shrink: 0; }
        .admitcard .ac-inst { flex: 1; text-align: center; }
        .admitcard .ac-inst h5 { font-size: 16px; font-weight: bold; color: #1e3a8a; margin: 0 0 3px; }
        .admitcard .ac-inst .addr { font-size: 11px; color: #333; margin: 0; }
        .admitcard .ac-term { text-align: center; font-weight: bold; font-size: 14px; margin-top: 8px; }
        .admitcard .ac-badge {
            display: block; text-align: center; font-weight: bold; font-size: 13px; margin: 8px auto 10px;
            border: 1.5px solid #333; border-radius: 3px; padding: 4px 0; width: 45%; letter-spacing: 0.5px;
        }
        .admitcard .ac-info { font-size: 13px; line-height: 2.1; }
        .admitcard .ac-info table { width: 100%; border-collapse: collapse; }
        .admitcard .ac-info td { padding: 1px 4px; vertical-align: top; }
        .admitcard .ac-info td.lbl { width: 70px; color: #333; }
        .admitcard .ac-info td.sep { width: 12px; }
    </style>

    <div class="no-print d-flex justify-content-end mb-3">
        <button type="button" class="btn text-white fw-bold" style="background:#0f766e;" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print / Download</button>
    </div>

    <div class="text-center">
        <div class="admitcard">
            <div class="ac-top">
                <div class="ac-qr" id="acqr0"></div>
                <div class="ac-inst">
                    <h5><?php echo acv($inst_display_name); ?></h5>
                    <?php if ($addr_line !== ''): ?><p class="addr"><?php echo acv($addr_line); ?></p><?php endif; ?>
                </div>
                <?php if (!empty($student['photo_path'])): ?>
                    <img class="ac-photo" src="../<?php echo acv($student['photo_path']); ?>">
                <?php else: ?>
                    <div class="ac-photo d-flex align-items-center justify-content-center text-muted" style="font-size:9px;">Photo</div>
                <?php endif; ?>
            </div>

            <div class="ac-term"><?php echo acv($term_name); ?> - <?php echo date('Y'); ?></div>
            <div class="ac-badge">ADMIT CARD</div>

            <div class="ac-info">
                <table>
                    <tr>
                        <td class="lbl">Name</td><td>: <?php echo acv($student['name_en']); ?></td>
                        <td class="sep"></td>
                        <td class="lbl">Class</td><td>: <?php echo acv($class_name); ?></td>
                    </tr>
                    <tr>
                        <td class="lbl">ID No.</td><td>: <?php echo acv($student['student_id']); ?></td>
                        <td class="sep"></td>
                        <td class="lbl">Roll</td><td>: <?php echo acv($student['roll_no']); ?></td>
                    </tr>
                    <tr>
                        <td class="lbl">Shift</td><td>: <?php echo acv($shift_name); ?></td>
                        <td class="sep"></td>
                        <td class="lbl">Section</td><td>: <?php echo acv($section_name); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        (function () {
            var el = document.getElementById('acqr0');
            if (el && window.QRCode) {
                new QRCode(el, { text: <?php echo json_encode((string) $student['student_id']); ?>, width: 70, height: 70 });
            }
        })();
    </script>

<?php else: ?>
    <!-- ================= FEE DUE — PAYMENT GATE ================= -->
    <div class="card-custom">
        <div class="text-center mb-3">
            <i class="fa-solid fa-lock" style="font-size:40px;color:#f59e0b;"></i>
            <h6 class="fw-bold mt-2">এই টার্মের Exam Fee এখনো পরিশোধ হয়নি</h6>
            <p class="text-muted mb-0">Exam Fee (<?php echo acv($eligibility['ac_head'] ?: 'Exam Fee'); ?>): <strong><?php echo number_format((float) $eligibility['amount'], 2); ?> ৳</strong></p>
        </div>

        <?php $claim = $eligibility['claim'] ?? null; ?>
        <?php if ($claim && $claim['status'] === 'Pending'): ?>
            <div class="alert alert-warning text-center py-2">
                আপনার <?php echo acv($claim['method']); ?> পেমেন্ট (Trx ID: <?php echo acv($claim['trx_id']); ?>) যাচাইয়ের অপেক্ষায় আছে। অফিস থেকে যাচাই সম্পন্ন হলে এখানেই Admit Card দেখা যাবে।
            </div>
        <?php else: ?>
            <?php if ($claim && $claim['status'] === 'Rejected'): ?>
                <div class="alert alert-danger text-center py-2">
                    আপনার আগের পেমেন্ট তথ্য Reject হয়েছে। সঠিক তথ্য দিয়ে আবার জমা দিন, অথবা সরাসরি অফিসে যোগাযোগ করুন।
                </div>
            <?php endif; ?>

            <?php if ($auto_ready): ?>
                <div class="text-center mb-3">
                    <a href="exam_fee_payment_init.php?term_id=<?php echo $sel_term; ?>" class="btn btn-lg fw-bold text-white" style="background:#0f766e;">
                        <i class="fa-solid fa-globe me-1"></i> Pay Online (via <?php echo acv($auto_provider_label); ?>)
                    </a>
                    <p class="text-muted mt-2 mb-0" style="font-size:12.5px;">Card / bKash / Nagad / Rocket দিয়ে সরাসরি পে করুন — পেমেন্ট সফল হলে সাথে সাথেই Admit Card আনলক হয়ে যাবে।</p>
                </div>
                <?php if (!empty($gateway['is_enabled']) && (trim((string) $gateway['bkash_number']) !== '' || trim((string) $gateway['nagad_number']) !== '')): ?>
                    <hr class="my-3">
                    <p class="text-muted text-center mb-2" style="font-size:12.5px;">অথবা ম্যানুয়ালি bKash/Nagad এ পাঠিয়ে Transaction ID জমা দিন —</p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($gateway['is_enabled']) && (trim((string) $gateway['bkash_number']) !== '' || trim((string) $gateway['nagad_number']) !== '')): ?>
                <div class="p-3 rounded mb-3" style="background:#ecfdf5;">
                    <?php if (trim((string) $gateway['bkash_number']) !== ''): ?>
                        <div class="mb-1"><strong>bKash (<?php echo acv($gateway['bkash_type']); ?>):</strong> <?php echo acv($gateway['bkash_number']); ?></div>
                    <?php endif; ?>
                    <?php if (trim((string) $gateway['nagad_number']) !== ''): ?>
                        <div class="mb-1"><strong>Nagad (<?php echo acv($gateway['nagad_type']); ?>):</strong> <?php echo acv($gateway['nagad_number']); ?></div>
                    <?php endif; ?>
                    <?php if (trim((string) $gateway['instructions']) !== ''): ?>
                        <div class="text-muted mt-2" style="font-size:12.5px;"><?php echo nl2br(acv($gateway['instructions'])); ?></div>
                    <?php endif; ?>
                </div>

                <form method="POST" class="row g-2 align-items-end justify-content-center">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="term_id" value="<?php echo $sel_term; ?>">
                    <div class="col-auto">
                        <label class="form-label fw-bold" style="font-size:12.5px;">Method</label>
                        <select name="method" class="form-select form-select-sm">
                            <?php if (trim((string) $gateway['bkash_number']) !== ''): ?><option value="bKash">bKash</option><?php endif; ?>
                            <?php if (trim((string) $gateway['nagad_number']) !== ''): ?><option value="Nagad">Nagad</option><?php endif; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <label class="form-label fw-bold" style="font-size:12.5px;">Transaction ID</label>
                        <input type="text" name="trx_id" class="form-control form-control-sm" placeholder="যেমন: 8N7A6B5C4D" required>
                    </div>
                    <div class="col-auto">
                        <button type="submit" name="submit_payment" class="btn btn-sm text-white" style="background:#0f766e;">Submit Payment</button>
                    </div>
                </form>
                <p class="text-muted text-center mt-3 mb-0" style="font-size:12px;">জমা দেওয়ার পর অফিস যাচাই করে Approve করলে Admit Card এখানে দেখা যাবে।</p>
            <?php else: ?>
                <p class="text-center text-muted mb-0">অনুগ্রহ করে সরাসরি স্কুল অফিসে গিয়ে Exam Fee জমা দিন। ফি জমা হলে Admit Card এখানে দেখা যাবে।</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php endif; ?>
