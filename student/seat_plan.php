<?php
// student/seat_plan.php - Exam Fee (Finance Module driven) পরিশোধ থাকলে Seat Plan দেখাবে/প্রিন্ট করা যাবে
require_once __DIR__ . '/../config/exam_fee_helpers.php';
require_once __DIR__ . '/../config/seat_card_helper.php';
require_once __DIR__ . '/../config/admission_fee_helpers.php';
ef_ensure_tables($pdo);
af_ensure_tables($pdo);

function splv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$terms_stmt = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
$terms_stmt->execute([(int) $student['session_id']]);
$terms = $terms_stmt->fetchAll();

$parts_stmt = $pdo->prepare("SELECT id, part_name FROM exam_parts WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
$parts_stmt->execute([(int) $student['session_id']]);
$parts = $parts_stmt->fetchAll();

$sel_term = (int) ($_GET['term_id'] ?? ($terms[0]['id'] ?? 0));
$sel_part = (int) ($_GET['part_id'] ?? 0);

$pay_msg = '';
$pay_err = '';

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
            $pay_msg = 'আপনার পেমেন্ট তথ্য জমা হয়েছে — স্কুল অফিস যাচাই করার পর Seat Plan এখানে দেখা যাবে।';
        }
        $sel_term = $p_term;
    }
}

$eligibility = $sel_term ? ef_is_eligible($pdo, (int) $student['session_id'], $sel_term, (int) $student['class_id'], (int) $student['id']) : ['eligible' => false, 'amount' => 0, 'claim' => null];
$gateway = ef_gateway_settings($pdo);
// Global Settings > Payment Gateway Settings এ এই Term-এর Account Head "অনলাইনে নেওয়া হবে"
// বলে টিক দেওয়া না থাকলে ম্যানুয়াল পেমেন্টের ফর্মও দেখানো হবে না (অফিসে জমা দিতে হবে)
if (!af_head_online_allowed(af_gateway_settings($pdo), (int) ($eligibility['ac_head_id'] ?? 0))) {
    $gateway['is_enabled'] = 0;
}

$term_name = '';
foreach ($terms as $t) { if ((int) $t['id'] === $sel_term) { $term_name = $t['term_name']; break; } }
$part_name = '';
foreach ($parts as $p) { if ((int) $p['id'] === $sel_part) { $part_name = $p['part_name']; break; } }

// Room তথ্য — Admin Exam Seat Plan জেনারেট করার সময় সেভ হলে এখান থেকে পাওয়া যাবে
$room = '';
if ($eligibility['eligible']) {
    $r_stmt = $pdo->prepare(
        "SELECT room FROM exam_seat_rooms WHERE session_id = ? AND term_id = ? AND part_id = ? AND class_id = ? AND (shift_id = 0 OR shift_id = ?) AND (section_id = 0 OR section_id = ?) ORDER BY (shift_id > 0) DESC, (section_id > 0) DESC LIMIT 1"
    );
    $r_stmt->execute([(int) $student['session_id'], $sel_term, $sel_part, (int) $student['class_id'], (int) $student['shift_id'], (int) $student['section_id']]);
    $room = (string) ($r_stmt->fetchColumn() ?: '');
}

$class_name   = sp_lookup_name($pdo, 'academic_classes', $student['class_id'] ?: null);
$section_name = sp_lookup_name($pdo, 'academic_sections', $student['section_id'] ?: null);
$shift_name   = sp_lookup_name($pdo, 'academic_shifts', $student['shift_id'] ?: null);
$institute = $pdo->query("SELECT institute_name, logo_path FROM institute_settings WHERE id = 1")->fetch();
?>
<?php if (!$terms): ?>
    <div class="card-custom text-center py-5">
        <i class="fa-solid fa-table-cells-large" style="font-size:48px;color:#14b8a6;"></i>
        <p class="text-muted mt-2 mb-0">এখনো কোনো Exam Term তৈরি করা হয়নি।</p>
    </div>
<?php else: ?>

<div class="card-custom no-print">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="page" value="seat_plan">
        <div class="col-auto">
            <label class="form-label fw-bold" style="font-size:12.5px;">Exam Term</label>
            <select name="term_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($terms as $t): ?>
                    <option value="<?php echo (int) $t['id']; ?>" <?php echo (int) $t['id'] === $sel_term ? 'selected' : ''; ?>><?php echo splv($t['term_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($parts): ?>
        <div class="col-auto">
            <label class="form-label fw-bold" style="font-size:12.5px;">Part</label>
            <select name="part_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="0">-- N/A --</option>
                <?php foreach ($parts as $p): ?>
                    <option value="<?php echo (int) $p['id']; ?>" <?php echo (int) $p['id'] === $sel_part ? 'selected' : ''; ?>><?php echo splv($p['part_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php if ($pay_msg): ?><div class="alert alert-success py-2"><?php echo splv($pay_msg); ?></div><?php endif; ?>
<?php if ($pay_err): ?><div class="alert alert-danger py-2"><?php echo splv($pay_err); ?></div><?php endif; ?>

<?php if ($eligibility['eligible']): ?>
    <!-- ================= SEAT PLAN CARD ================= -->
    <?php echo sc_css(); ?>

    <div class="no-print d-flex justify-content-end mb-3">
        <button type="button" class="btn text-white fw-bold" style="background:#0f766e;" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print / Download</button>
    </div>

    <div class="sc-grid">
        <?php echo sc_card([
            'institute' => $institute['institute_name'] ?? '', 'logo' => $institute['logo_path'] ?? '',
            'room' => $room, 'term' => $term_name, 'part' => $part_name, 'year' => date('Y'),
            'name' => $student['name_en'], 'class' => $class_name, 'shift' => $shift_name,
            'section' => $section_name, 'roll' => $student['roll_no'], 'student_id' => $student['student_id'],
            'photo' => $student['photo_path'] ?? '', 'qr_id' => 'scqr0',
        ]); ?>
    </div>

    <?php if ($room === ''): ?>
        <p class="text-muted text-center mt-2" style="font-size:12.5px;">এখনো Room নাম্বার নির্ধারণ করা হয়নি — নির্ধারণ হলে এখানে দেখা যাবে।</p>
    <?php endif; ?>

    <?php echo sc_qr_script(); ?>

<?php else: ?>
    <!-- ================= FEE DUE — PAYMENT GATE ================= -->
    <div class="card-custom">
        <div class="text-center mb-3">
            <i class="fa-solid fa-lock" style="font-size:40px;color:#f59e0b;"></i>
            <h6 class="fw-bold mt-2">এই টার্মের Exam Fee এখনো পরিশোধ হয়নি</h6>
            <p class="text-muted mb-0">Exam Fee (<?php echo splv($eligibility['ac_head'] ?: 'Exam Fee'); ?>): <strong><?php echo number_format((float) $eligibility['amount'], 2); ?> ৳</strong></p>
        </div>

        <?php $claim = $eligibility['claim'] ?? null; ?>
        <?php if ($claim && $claim['status'] === 'Pending'): ?>
            <div class="alert alert-warning text-center py-2">
                আপনার <?php echo splv($claim['method']); ?> পেমেন্ট (Trx ID: <?php echo splv($claim['trx_id']); ?>) যাচাইয়ের অপেক্ষায় আছে। অফিস থেকে যাচাই সম্পন্ন হলে এখানেই Seat Plan দেখা যাবে।
            </div>
        <?php else: ?>
            <?php if ($claim && $claim['status'] === 'Rejected'): ?>
                <div class="alert alert-danger text-center py-2">
                    আপনার আগের পেমেন্ট তথ্য Reject হয়েছে। সঠিক তথ্য দিয়ে আবার জমা দিন, অথবা সরাসরি অফিসে যোগাযোগ করুন।
                </div>
            <?php endif; ?>

            <?php if (!empty($gateway['is_enabled']) && (trim((string) $gateway['bkash_number']) !== '' || trim((string) $gateway['nagad_number']) !== '')): ?>
                <div class="p-3 rounded mb-3" style="background:#ecfdf5;">
                    <?php if (trim((string) $gateway['bkash_number']) !== ''): ?>
                        <div class="mb-1"><strong>bKash (<?php echo splv($gateway['bkash_type']); ?>):</strong> <?php echo splv($gateway['bkash_number']); ?></div>
                    <?php endif; ?>
                    <?php if (trim((string) $gateway['nagad_number']) !== ''): ?>
                        <div class="mb-1"><strong>Nagad (<?php echo splv($gateway['nagad_type']); ?>):</strong> <?php echo splv($gateway['nagad_number']); ?></div>
                    <?php endif; ?>
                    <?php if (trim((string) $gateway['instructions']) !== ''): ?>
                        <div class="text-muted mt-2" style="font-size:12.5px;"><?php echo nl2br(splv($gateway['instructions'])); ?></div>
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
                <p class="text-muted text-center mt-3 mb-0" style="font-size:12px;">জমা দেওয়ার পর অফিস যাচাই করে Approve করলে Seat Plan এখানে দেখা যাবে।</p>
            <?php else: ?>
                <p class="text-center text-muted mb-0">অনুগ্রহ করে সরাসরি স্কুল অফিসে গিয়ে Exam Fee জমা দিন। ফি জমা হলে Seat Plan এখানে দেখা যাবে।</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php endif; ?>
