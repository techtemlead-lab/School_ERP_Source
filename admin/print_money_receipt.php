<?php
// admin/print_money_receipt.php - একটা Fee Collection Folio-র জন্য Money Receipt প্রিন্ট
// এই পেজটা finance_money_receipt_settings টেবিলের সেটিংস (কয়টা কপি, কোন পেপার সাইজ,
// Due দেখাবে কিনা ইত্যাদি) অনুযায়ী রিসিট রেন্ডার করে। Student Fees Collection,
// Quick Collection, Fees Re-collection, আর Money Receipt List — এই চারটা পেজের
// "Print Money Receipt" বাটন এখানেই আসে, folio_no দিয়ে।
//
// ★ গুরুত্বপূর্ণঃ এটা একটা স্বতন্ত্র (standalone) প্রিন্ট পেজ — index.php এর
// sidebar/navbar লেআউটের ভেতরে চলে না (canteen_invoice_print.php / inventory_invoice_print.php
// এর মতোই)। আগে index.php?page=print_money_receipt দিয়ে খুললে পুরো অ্যাডমিন লেআউটটাই
// রিসিটের সাথে প্রিন্ট পেজে ঢুকে যাচ্ছিল বলে প্রিন্ট লেআউট নষ্ট হচ্ছিল। তাই এখন
// সরাসরি এভাবে কল করতে হয়ঃ  print_money_receipt.php?folio_no=XXXX
//
// লেআউটঃ A4 Portrait/Landscape এ সিলেক্ট করা সবগুলো কপি (Office/Bank/Student) একই
// পেজে পাশাপাশি (dotted কাটার লাইন দিয়ে আলাদা করা) প্রিন্ট হয় — Office+Bank+Student
// সিলেক্ট করলে ৩টা কপিই একই পেজে পাশাপাশি চলে আসবে। A5 সিলেক্ট করলে প্রতিটা কপি
// নিজের আলাদা A5 সাইজের পেজে আলাদাভাবে প্রিন্ট হবে (page-break দিয়ে ভাগ করা)।

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';          // $pdo
require_once __DIR__ . '/../config/branch_scope.php'; // branch_current_id()
require_once __DIR__ . '/../config/permissions.php';  // perm_page_allowed()
require_once __DIR__ . '/../config/money_receipt_render.php'; // mr_render_copy() / mr_receipt_css() — money_receipt.php (public) এর সাথেও শেয়ার্ড

if (!isset($_SESSION['admin_logged'])) {
    die('আপনি লগইন করা নেই। প্রথমে লগইন করুন।');
}
if (function_exists('perm_page_allowed') && !perm_page_allowed($pdo, 'print_money_receipt')) {
    die('এই পেজে আপনার অ্যাক্সেস নেই।');
}

// bfcache থেকে পুরনো পেজ না দেখিয়ে সবসময় সার্ভার থেকে টাটকা রিলোড
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// -----------------------------------------------------------
// সেটিংস টেবিল (settings পেজে না গিয়ে সরাসরি এখানে আসলেও যেন কাজ করে)
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

$mr_copy_labels_map = [
    'student'             => ['Student Copy'],
    'office_student'      => ['Office Copy', 'Student Copy'],
    'office_bank_student' => ['Office Copy', 'Bank Copy', 'Student Copy'],
];
$mr_copies = $mr_copy_labels_map[$mr_settings['copy_type']] ?? ['Student Copy'];
$mr_paper = $mr_settings['paper_size'] ?: 'a4_portrait';

// -----------------------------------------------------------
// Folio ডেটা লোড
// -----------------------------------------------------------
$folio_no = trim($_GET['folio_no'] ?? '');
$rows = [];
if ($folio_no !== '') {
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
         WHERE fc.folio_no = ?
         ORDER BY fc.id ASC"
    );
    $stmt->execute([$folio_no]);
    $rows = $stmt->fetchAll();
}

$MONTHS = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];
$PERIODS = [0 => 'Admission'] + $MONTHS;

$first = $rows[0] ?? null;
$total_amount = 0.0;
foreach ($rows as $r) { $total_amount += (float) $r['amount']; }

// -----------------------------------------------------------
// Waiver/ছাড় থাকলে রিসিটে Fee (ছাড়ের আগে), Waiver ও Paid আলাদা দেখানো হয়।
// তিনটা কপিতেই (Office/Bank/Student) হুবহু একই অঙ্ক — Paid সবসময় আসল আদায় করা টাকা।
// Waiver শুধু ওই Fee Head-এর প্রথম Collection লাইনে ধরা হয়, যাতে কিস্তিতে দিলে ডাবল না দেখায়।
// -----------------------------------------------------------
$mr_waiver_on = false;
$mr_row_fin = [];
$total_fee = 0.0;
$total_waiver = 0.0;
$mr_wv_office  = (int) ($mr_settings['show_waiver'] ?? 1) === 1;         // Office/Bank Copy
$mr_wv_student = (int) ($mr_settings['show_waiver_student'] ?? 1) === 1; // Student Copy
if ($first && ($mr_wv_office || $mr_wv_student)) {
    require_once __DIR__ . '/finance_due_report_common.php';
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

// -----------------------------------------------------------
// প্রতিষ্ঠানের তথ্য
// -----------------------------------------------------------
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

// -----------------------------------------------------------
// এই ফলিওতে যে পিরিয়ড(গুলো) কভার হয়েছে, শুধু সেগুলোর বকেয়া (Due) — settings এ
// show_due অন থাকলেই হিসাব হবে। finance_due_report_common.php এর dr_resolve_period()
// রিইউজ করা হচ্ছে যাতে Due Report এর সাথে সংখ্যা সবসময় মিলে যায়।
// -----------------------------------------------------------
$period_due_total = null;
if ($first && (int) $mr_settings['show_due'] === 1) {
    require_once __DIR__ . '/finance_due_report_common.php';
    $fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads")->fetchAll();
    $period_due_total = 0.0;
    foreach ($periods_covered as $p) {
        $items = dr_resolve_period($pdo, (int) $first['student_id'], (int) $first['session_id'], (int) $first['class_id'], $p, $fee_heads);
        $period_due_total += array_sum(array_column($items, 'dues'));
    }
}

$GLOBALS['mr_qr_ids'] = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Money Receipt - <?php echo mr_e($folio_no); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        <?php echo mr_receipt_css($mr_paper); ?>
    </style>
</head>
<body onload="window.print()">
    <div class="no-print-bar">
        <button type="button" class="btn btn-secondary" onclick="mrGoBack()"><i class="fa-solid fa-arrow-left"></i> Back</button>
        <div>
            <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
            <button type="button" class="btn btn-outline-secondary" onclick="mrGoBack()">Close</button>
        </div>
    </div>
    <script>
        // রিসিট সাধারণত নতুন ট্যাবে খোলে — নতুন ট্যাবে history.back() এর কিছু করার
        // থাকে না (আগের পেজ নেই), আবার target="_blank" দিয়ে খোলা ট্যাবে window.close()
        // ও ব্রাউজার ব্লক করে দিতে পারে। তাই তিনটা ধাপে চেষ্টা করা হয়ঃ
        // ১) ট্যাব বন্ধ করা ২) না পারলে আগের পেজে ফেরা ৩) তাও না পারলে যে পেজ থেকে
        // এসেছে (back প্যারামিটার) বা Money Receipt List এ ফেরত পাঠানো।
        function mrGoBack() {
            var fallback = <?php echo json_encode('index.php?page=' . (preg_match('/^[a-z0-9_]+$/', (string) ($_GET['back'] ?? '')) ? $_GET['back'] : 'finance_collection_invoice_list')); ?>;
            try { window.close(); } catch (e) {}
            setTimeout(function () {
                if (window.history.length > 1) { window.history.back(); }
                else { window.location.href = fallback; }
            }, 150);
        }
    </script>

    <?php if (!$first): ?>
        <div class="mr-sheet mr-portrait">
            <div class="alert alert-danger">এই Folio Number (<?php echo mr_e($folio_no); ?>) এর কোনো Money Receipt পাওয়া যায়নি।</div>
        </div>
    <?php else: ?>
        <?php
        $ctx = compact('first', 'rows', 'folio_no', 'total_amount', 'mr_waiver_on', 'mr_wv_office', 'mr_wv_student', 'mr_row_fin', 'total_fee', 'total_waiver', 'period_due_total', 'paid_for_label', 'class_label', 'inst', 'addr_line') + [
            'show_logo' => (int) $mr_settings['show_logo'] === 1,
            'show_signature' => (int) $mr_settings['show_signature'] === 1,
            'footer_note' => $mr_settings['footer_note'],
        ];
        if ($mr_paper === 'a5'):
            // ---- A5: সিলেক্ট করা প্রতিটা কপি (Office/Bank/Student) নিজের আলাদা A5
            // সাইজের পেজে আলাদাভাবে প্রিন্ট হবে — একটার পর একটা page-break দিয়ে ----
            $last_i = count($mr_copies) - 1;
            foreach ($mr_copies as $i => $label):
                $qr_id = 'mrqr-0-' . $i;
                $break_class = $i < $last_i ? ' mr-page-break' : '';
                ?>
                <div class="mr-sheet mr-a5<?php echo $break_class; ?>">
                    <?php echo mr_render_copy($ctx, $label, $qr_id); ?>
                </div>
            <?php endforeach;
        else:
            // ---- A4 Portrait/Landscape: সিলেক্ট করা সবগুলো কপি (Office/Bank/Student)
            // একই পেজে পাশাপাশি, dotted বর্ডার দিয়ে আলাদা ----
            $page_class = $mr_paper === 'a4_landscape' ? 'mr-landscape' : 'mr-portrait';
            ?>
            <div class="mr-sheet <?php echo $page_class; ?>">
                <?php foreach ($mr_copies as $i => $label): $qr_id = 'mrqr-0-' . $i; ?>
                    <?php echo mr_render_copy($ctx, $label, $qr_id); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

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
