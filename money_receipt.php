<?php
/**
 * money_receipt.php - Fee Collection SMS-এ পাঠানো লিংক দিয়ে অভিভাবক পাবলিকলি (লগইন
 * ছাড়াই) যে Money Receipt দেখতে পান — শুধু Student Copy, Office/Bank Copy না।
 *
 * admin/print_money_receipt.php-এর মতোই কিন্তু তিনটা পার্থক্যঃ
 *   ১) folio_no না, একটা আলাদা random token (finance_fee_collections.receipt_token)
 *      দিয়ে খোঁজা হয় — folio_no প্রিন্ট করা কাগজে থাকে বলে সেটা দিয়ে সরাসরি পাবলিক
 *      লিংক বানানো হয় না (config/fee_collection_sms.php দ্রষ্টব্য)।
 *   ২) কোনো admin session লাগে না (admission_slip.php-এর মতোই পাবলিক পেজ)।
 *   ৩) copy_type সেটিং যাই থাকুক না কেন — এখানে সবসময় শুধু "Student Copy" একটাই দেখানো হয়।
 */

session_start();
require_once __DIR__ . '/config/db.php';                     // $pdo (tenant resolve সহ)
require_once __DIR__ . '/config/branch_scope.php';            // branch_current_id()
require_once __DIR__ . '/config/money_receipt_render.php';    // mr_render_copy() / mr_receipt_css() — admin/print_money_receipt.php এর সাথে শেয়ার্ড
require_once __DIR__ . '/config/fee_collection_sms.php';      // fc_ensure_receipt_token_column()

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

fc_ensure_receipt_token_column($pdo);

// -----------------------------------------------------------
// Money Receipt Settings (logo/due/waiver/signature/footer টগল — admin প্রিন্ট পেজের
// সাথে হুবহু একই সেটিংস রিইউজ করা হয়, শুধু copy_type/paper_size এখানে প্রযোজ্য না)
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_money_receipt_settings (
        id INT PRIMARY KEY DEFAULT 1,
        copy_type VARCHAR(20) NOT NULL DEFAULT 'student',
        paper_size VARCHAR(20) NOT NULL DEFAULT 'a4_portrait',
        show_due TINYINT(1) NOT NULL DEFAULT 1,
        show_logo TINYINT(1) NOT NULL DEFAULT 1,
        show_signature TINYINT(1) NOT NULL DEFAULT 1,
        footer_note VARCHAR(255) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$mr_cols = $pdo->query("SHOW COLUMNS FROM finance_money_receipt_settings")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('show_waiver', $mr_cols, true)) {
    $pdo->exec("ALTER TABLE finance_money_receipt_settings ADD COLUMN show_waiver TINYINT(1) NOT NULL DEFAULT 1 AFTER show_due");
}
if (!in_array('show_waiver_student', $mr_cols, true)) {
    $pdo->exec("ALTER TABLE finance_money_receipt_settings ADD COLUMN show_waiver_student TINYINT(1) NOT NULL DEFAULT 1 AFTER show_waiver");
}
if ((int) $pdo->query("SELECT COUNT(*) FROM finance_money_receipt_settings")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO finance_money_receipt_settings (id) VALUES (1)");
}
$mr_settings = $pdo->query("SELECT * FROM finance_money_receipt_settings WHERE id = 1")->fetch();

// -----------------------------------------------------------
// Token দিয়ে ডেটা লোড (folio_no না — receipt_token দিয়ে, নিরাপদ/আন্দাজ করা কঠিন)
// -----------------------------------------------------------
$token = trim($_GET['token'] ?? $_GET['t'] ?? '');
$rows = [];
if ($token !== '') {
    $stmt = $pdo->prepare(
        "SELECT fc.*, s.student_id AS student_code, s.name_en AS student_name, s.roll_no, s.father_name,
                s.guardian_phone, s.class_id, s.session_id AS student_session_id,
                c.class_name, ses.session_name, fh.ac_code, fh.ac_head, sh.shift_name
         FROM finance_fee_collections fc
         LEFT JOIN students s ON s.id = fc.student_id
         LEFT JOIN academic_classes c ON c.id = s.class_id
         LEFT JOIN academic_sessions ses ON ses.id = fc.session_id
         LEFT JOIN finance_account_heads fh ON fh.id = fc.ac_head_id
         LEFT JOIN academic_shifts sh ON sh.id = s.shift_id
         WHERE fc.receipt_token = ?
         ORDER BY fc.id ASC"
    );
    $stmt->execute([$token]);
    $rows = $stmt->fetchAll();
}

$folio_no = $rows ? (string) $rows[0]['folio_no'] : '';

$MONTHS = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];
$PERIODS = [0 => 'Admission'] + $MONTHS;

$first = $rows[0] ?? null;
$total_amount = 0.0;
foreach ($rows as $r) { $total_amount += (float) $r['amount']; }

// -----------------------------------------------------------
// Waiver হিসাব — admin/print_money_receipt.php এর সাথে হুবহু একই লজিক, কিন্তু এখানে
// শুধু "Student Copy" সেটিং (show_waiver_student) প্রযোজ্য
// -----------------------------------------------------------
$mr_waiver_on = false;
$mr_row_fin = [];
$total_fee = 0.0;
$total_waiver = 0.0;
$mr_wv_office  = false; // এই পেজে Office/Bank Copy রেন্ডার হয় না
$mr_wv_student = (int) ($mr_settings['show_waiver_student'] ?? 1) === 1;
if ($first && $mr_wv_student) {
    require_once __DIR__ . '/admin/finance_due_report_common.php';
    $mr_fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads")->fetchAll();
    $mr_period_cache = [];
    $mr_first_stmt = $pdo->prepare("SELECT MIN(id) FROM finance_fee_collections WHERE student_id = ? AND session_id = ? AND month = ? AND ac_head_id = ?");
    foreach ($rows as $r) {
        $hid = (int) $r['ac_head_id'];
        if ($hid <= 0) continue;
        $per = (int) $r['month'];
        $ckey = $r['student_id'] . '-' . $r['session_id'] . '-' . $per;
        if (!isset($mr_period_cache[$ckey])) {
            $items = dr_resolve_period($pdo, (int) $r['student_id'], (int) $r['session_id'], (int) $r['class_id'], $per, $mr_fee_heads);
            $mr_period_cache[$ckey] = [];
            foreach ($items as $it) { $mr_period_cache[$ckey][(int) $it['ac_head_id']] = $it; }
        }
        $it = $mr_period_cache[$ckey][$hid] ?? null;
        if (!$it || (float) $it['waived'] <= 0) continue;
        $mr_first_stmt->execute([(int) $r['student_id'], (int) $r['session_id'], $per, $hid]);
        if ((int) $mr_first_stmt->fetchColumn() === (int) $r['id']) {
            $mr_row_fin[(int) $r['id']] = ['base' => (float) $it['base'], 'waived' => (float) $it['waived']];
            $mr_waiver_on = true;
        }
    }
    if ($mr_waiver_on) {
        foreach ($rows as $r) {
            $rf = $mr_row_fin[(int) $r['id']] ?? null;
            $total_fee += $rf ? $rf['base'] : (float) $r['amount'];
            $total_waiver += $rf ? $rf['waived'] : 0.0;
        }
    }
}

$periods_covered = array_values(array_unique(array_map(fn($r) => (int) $r['month'], $rows)));
sort($periods_covered);
$paid_for_label = implode(', ', array_map(fn($p) => $PERIODS[$p] ?? $p, $periods_covered));

$class_label = $first ? trim(($first['class_name'] ?? '') . (!empty($first['shift_name']) ? ' (' . $first['shift_name'] . ')' : '')) : '';

$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
$branch = null;
if (function_exists('branch_current_id')) {
    $bid = branch_current_id();
    if ($bid !== null) {
        $bstmt = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
        $bstmt->execute([$bid]);
        $branch = $bstmt->fetch();
    } else {
        $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
    }
}
$addr_line = $branch ? trim(($branch['address'] ?? '') . ', ' . ($branch['city'] ?? '') . ' ' . ($branch['zip_code'] ?? ''), ', ') : '';

$period_due_total = null;
if ($first && (int) $mr_settings['show_due'] === 1) {
    require_once __DIR__ . '/admin/finance_due_report_common.php';
    $fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads")->fetchAll();
    $period_due_total = 0.0;
    foreach ($periods_covered as $p) {
        $items = dr_resolve_period($pdo, (int) $first['student_id'], (int) $first['session_id'], (int) $first['class_id'], $p, $fee_heads);
        $period_due_total += array_sum(array_column($items, 'dues'));
    }
}

$GLOBALS['mr_qr_ids'] = [];
$mr_paper = 'a4_portrait'; // পাবলিক ভিউতে সবসময় ফোনে/ব্রাউজারে দেখার জন্য A4 Portrait একটা কপি
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Money Receipt<?php echo $folio_no ? ' - ' . mr_e($folio_no) : ''; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        <?php echo mr_receipt_css($mr_paper); ?>
        /* ফোনে ছোট স্ক্রিনে পুরো A4 শিট না ছড়িয়ে জুম করে দেখানো/স্ক্রল করানোর জন্য */
        @media (max-width: 700px) {
            body { padding: 8px; overflow-x: auto; }
        }
    </style>
</head>
<body>
    <div class="no-print-bar">
        <div></div>
        <div>
            <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save</button>
        </div>
    </div>

    <?php if (!$first): ?>
        <div class="mr-sheet mr-portrait">
            <div class="alert alert-danger">এই লিংকটা সঠিক নয় অথবা মেয়াদ উত্তীর্ণ — কোনো Money Receipt পাওয়া যায়নি।</div>
        </div>
    <?php else: ?>
        <?php
        $ctx = compact('first', 'rows', 'folio_no', 'total_amount', 'mr_waiver_on', 'mr_wv_office', 'mr_wv_student', 'mr_row_fin', 'total_fee', 'total_waiver', 'period_due_total', 'paid_for_label', 'class_label', 'inst', 'addr_line') + [
            'show_logo' => (int) $mr_settings['show_logo'] === 1,
            'show_signature' => (int) $mr_settings['show_signature'] === 1,
            'footer_note' => $mr_settings['footer_note'],
        ];
        ?>
        <div class="mr-sheet mr-portrait">
            <?php echo mr_render_copy($ctx, 'Student Copy', 'mrqr-0-0', ''); ?>
        </div>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
            <?php foreach ($GLOBALS['mr_qr_ids'] as $qr): ?>
                (function () {
                    var el = document.getElementById(<?php echo json_encode($qr['id']); ?>);
                    if (el && window.QRCode) {
                        new QRCode(el, { text: <?php echo json_encode($qr['text']); ?>, width: 46, height: 46 });
                    }
                })();
            <?php endforeach; ?>
        </script>
    <?php endif; ?>
</body>
</html>
