<?php
require_once __DIR__ . '/../config/fees_period_resolver.php'; // sfc_pick_fee_setting() — Student-এর Section/Shift/Gender মিলিয়ে সঠিক Fee Setting বাছার শেয়ার্ড হেল্পার
// admin/finance_fees_recollection.php - Finance Module > Students Accounts > Fees Re-collection
// Folio No দিয়ে আগে করা একটা Fee Collection ইনভয়েস খুঁজে বের করে, প্রতিটা লাইন-আইটেমের
// Amount/Payment Method সংশোধন (Correct) অথবা পুরো এন্ট্রি Void (Delete) করা যায়।

require_once __DIR__ . '/../config/fee_collection_sms.php'; // fc_send_receipt_sms() — বাকি Due কালেক্ট করলে Money Receipt লিংকসহ SMS

function frv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function fr_money($n): string { return number_format((float) $n, 2); }

$fr_err = $_SESSION['fr_err'] ?? '';
$fr_msg = $_SESSION['fr_msg'] ?? '';
unset($_SESSION['fr_err'], $_SESSION['fr_msg']);

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি (Quick/Fees Collection পেজের সাথে একই টেবিল শেয়ার করা হয়)
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_fee_collections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        session_id INT NOT NULL,
        period_type ENUM('Admission','Monthly') NOT NULL,
        month TINYINT NOT NULL DEFAULT 0,
        ac_head_id INT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash',
        trx_id VARCHAR(100) NULL,
        folio_no VARCHAR(50) NULL,
        receipt_no VARCHAR(50) NULL,
        collected_by VARCHAR(100) NULL,
        collected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'Paid',
        KEY idx_lookup (student_id, session_id, period_type, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$fr_cols = $pdo->query("SHOW COLUMNS FROM finance_fee_collections")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('ac_head_id', $fr_cols, true)) { $pdo->exec("ALTER TABLE finance_fee_collections ADD COLUMN ac_head_id INT NULL AFTER month"); }
if (!in_array('folio_no', $fr_cols, true)) { $pdo->exec("ALTER TABLE finance_fee_collections ADD COLUMN folio_no VARCHAR(50) NULL AFTER trx_id"); }
fc_ensure_receipt_token_column($pdo); // receipt_token কলাম — Money Receipt SMS লিংকের জন্য

$MONTHS = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];
$PERIODS = [0 => 'Admission'] + $MONTHS;

// -----------------------------------------------------------
// একটা নির্দিষ্ট Collection আইটেমের Payable বের করার হেল্পার (max allowed amount চেক করতে লাগে)
// -----------------------------------------------------------
function fr_resolve_payable(PDO $pdo, int $student_id, int $session_id, int $class_id, int $period, int $ac_head_id): float
{
    $base = 0.0;
    if ($period === 0) {
        $a = $pdo->prepare("SELECT amount FROM finance_student_admission_fees WHERE student_id = ? AND session_id = ? AND ac_head_id = ?");
        $a->execute([$student_id, $session_id, $ac_head_id]);
        $base = (float) $a->fetchColumn();
        if (!$base) {
            $csid = sfc_pick_fee_setting($pdo, 'finance_admission_fee_settings', $student_id, $session_id, $class_id);
            if ($csid) {
                $ci = $pdo->prepare("SELECT amount FROM finance_admission_fee_items WHERE setting_id = ? AND ac_head_id = ?");
                $ci->execute([$csid, $ac_head_id]);
                $base = (float) $ci->fetchColumn();
            }
        }
    } else {
        $m = $pdo->prepare("SELECT amount FROM finance_student_monthly_fees WHERE student_id = ? AND session_id = ? AND month = ? AND ac_head_id = ?");
        $m->execute([$student_id, $session_id, $period, $ac_head_id]);
        $base = (float) $m->fetchColumn();
        if (!$base) {
            $csid = sfc_pick_fee_setting($pdo, 'finance_monthly_fee_settings', $student_id, $session_id, $class_id);
            if ($csid) {
                $ci = $pdo->prepare("SELECT amount FROM finance_monthly_fee_items WHERE setting_id = ? AND month = ? AND ac_head_id = ?");
                $ci->execute([$csid, $period, $ac_head_id]);
                $base = (float) $ci->fetchColumn();
            }
        }
    }

    // period = 0 (Admission)-এর জন্যও ওয়েভার লুকআপ হবে — "if ($period > 0)" গার্ড থাকায়
    // Admission Fee-এর ওয়েভার Recollection-এ আগে কখনো এপ্লাই হতো না
    $waived = 0.0;
    $w = $pdo->prepare("SELECT waiver_type, waiver_value FROM finance_student_waivers WHERE student_id = ? AND session_id = ? AND month = ? AND ac_head_id = ? AND status = 'Active' LIMIT 1");
    $w->execute([$student_id, $session_id, $period, $ac_head_id]);
    $wr = $w->fetch();
    if ($wr) {
        $waived = $wr['waiver_type'] === 'Percentage' ? round($base * (float) $wr['waiver_value'] / 100, 2) : (float) $wr['waiver_value'];
        $waived = min($waived, $base);
    }
    return max(0, round($base - $waived, 2));
}

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['fr_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=finance_fees_recollection');
        exit();
    }

    if (isset($_POST['update_item'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $new_amount = (float) ($_POST['new_amount'] ?? 0);
        $new_method = trim($_POST['new_payment_method'] ?? 'Cash');
        if (!in_array($new_method, ['Cash', 'Bank', 'bKash', 'Nagad'], true)) { $new_method = 'Cash'; }

        $stmt = $pdo->prepare("SELECT * FROM finance_fee_collections WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            $_SESSION['fr_err'] = 'এই Collection এন্ট্রি পাওয়া যায়নি।';
        } elseif ($new_amount <= 0) {
            $_SESSION['fr_err'] = 'সঠিক Amount দিন।';
        } else {
            $stu = $pdo->prepare("SELECT class_id FROM students WHERE id = ?");
            $stu->execute([(int) $row['student_id']]);
            $class_id = (int) $stu->fetchColumn();

            $period = (int) $row['month'];
            $ac_head_id = (int) $row['ac_head_id'];
            $payable = $ac_head_id > 0 ? fr_resolve_payable($pdo, (int) $row['student_id'], (int) $row['session_id'], $class_id, $period, $ac_head_id) : $new_amount;

            $others = $pdo->prepare(
                "SELECT COALESCE(SUM(amount),0) FROM finance_fee_collections
                 WHERE student_id = ? AND session_id = ? AND period_type = ? AND month = ? AND ac_head_id = ? AND id != ?"
            );
            $others->execute([(int) $row['student_id'], (int) $row['session_id'], $row['period_type'], $period, $ac_head_id, $id]);
            $paid_others = (float) $others->fetchColumn();

            $max_allowed = max(0, round($payable - $paid_others, 2));
            if ($ac_head_id > 0 && $new_amount > $max_allowed) { $new_amount = $max_allowed; }

            $pdo->prepare("UPDATE finance_fee_collections SET amount = ?, payment_method = ? WHERE id = ?")
                ->execute([$new_amount, $new_method, $id]);

            $fr_stu = $pdo->prepare("SELECT student_id, name_en FROM students WHERE id = ?");
            $fr_stu->execute([(int) $row['student_id']]);
            $fr_stu_row = $fr_stu->fetch();
            $fr_stu_lbl = $fr_stu_row ? ($fr_stu_row['student_id'] . ' - ' . $fr_stu_row['name_en']) : ('#' . $row['student_id']);
            sec_log_activity($pdo, 'Finance', 'Update', "Fee Collection এন্ট্রি সংশোধন করা হয়েছে: {$fr_stu_lbl}, পূর্বে ৳" . fr_money($row['amount']) . " → নতুন ৳" . fr_money($new_amount) . " (Folio: " . ($_POST['folio_no'] ?? '') . ")");

            $_SESSION['fr_msg'] = 'এন্ট্রি সংশোধন করা হয়েছে। নতুন Amount: ' . fr_money($new_amount);
        }
        header('Location: index.php?page=finance_fees_recollection&folio_no=' . urlencode($_POST['folio_no'] ?? '') . '&do_search=1');
        exit();
    } elseif (isset($_POST['delete_item'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_stmt = $pdo->prepare(
            "SELECT fc.amount, fc.student_id, s.student_id AS student_code, s.name_en AS student_name
             FROM finance_fee_collections fc LEFT JOIN students s ON s.id = fc.student_id WHERE fc.id = ?"
        );
        $del_stmt->execute([$id]);
        $del_row = $del_stmt->fetch();
        $pdo->prepare("DELETE FROM finance_fee_collections WHERE id = ?")->execute([$id]);
        if ($del_row) {
            $del_stu_lbl = $del_row['student_code'] ? ($del_row['student_code'] . ' - ' . $del_row['student_name']) : ('#' . $del_row['student_id']);
            sec_log_activity($pdo, 'Finance', 'Delete', "Fee Collection এন্ট্রি Void করা হয়েছে: {$del_stu_lbl}, পরিমাণ ৳" . fr_money($del_row['amount']) . " (Folio: " . ($_POST['folio_no'] ?? '') . ")");
        }
        $_SESSION['fr_msg'] = 'এন্ট্রি Void করা হয়েছে (মুছে ফেলা হয়েছে)।';
        header('Location: index.php?page=finance_fees_recollection&folio_no=' . urlencode($_POST['folio_no'] ?? '') . '&do_search=1');
        exit();
    } elseif (isset($_POST['collect_due'])) {
        // একই Period+Head-এর বাকি থাকা Due এখান থেকেই সরাসরি কালেক্ট করা — নতুন Folio/আলাদা
        // Money Receipt তৈরি না করে, একই এন্ট্রির amount-এই যোগ হয়ে যায় (যেমন 200+50=250,
        // Due 0), তাই একই পুরনো Folio/Receipt-এই সব টাকা একসাথে দেখা যাবে। এই একই Folio-র
        // Receipt Token দিয়েই (পুরনো এন্ট্রিতে টোকেন না থাকলে নতুন বানিয়ে ওই Folio-র সব
        // রো-তে বসিয়ে দেওয়া হয়) অভিভাবকের কাছে Money Receipt লিংকসহ SMS যায়।
        $id = (int) ($_POST['id'] ?? 0);
        $collect_amount = (float) ($_POST['collect_amount'] ?? 0);
        $collect_method = trim($_POST['collect_payment_method'] ?? 'Cash');
        if (!in_array($collect_method, ['Cash', 'Bank', 'bKash', 'Nagad'], true)) { $collect_method = 'Cash'; }

        $stmt = $pdo->prepare(
            "SELECT fc.*, fh.ac_head FROM finance_fee_collections fc
             LEFT JOIN finance_account_heads fh ON fh.id = fc.ac_head_id
             WHERE fc.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            $_SESSION['fr_err'] = 'এই Collection এন্ট্রি পাওয়া যায়নি।';
        } elseif ($collect_amount <= 0) {
            $_SESSION['fr_err'] = 'সঠিক Amount দিন।';
        } else {
            $student_id = (int) $row['student_id'];
            $session_id = (int) $row['session_id'];
            $period = (int) $row['month'];
            $ac_head_id = (int) $row['ac_head_id'];

            $stu = $pdo->prepare("SELECT class_id FROM students WHERE id = ?");
            $stu->execute([$student_id]);
            $class_id = (int) $stu->fetchColumn();

            $payable = $ac_head_id > 0 ? fr_resolve_payable($pdo, $student_id, $session_id, $class_id, $period, $ac_head_id) : 0.0;
            $paid_total = $pdo->prepare(
                "SELECT COALESCE(SUM(amount),0) FROM finance_fee_collections
                 WHERE student_id = ? AND session_id = ? AND period_type = ? AND month = ? AND ac_head_id = ?"
            );
            $paid_total->execute([$student_id, $session_id, $row['period_type'], $period, $ac_head_id]);
            $due = max(0, round($payable - (float) $paid_total->fetchColumn(), 2));

            if ($due <= 0) {
                $_SESSION['fr_err'] = 'এই Period/Fee Type-এর কোনো Due নেই — কালেক্ট করার প্রয়োজন নেই।';
            } else {
                $collect_amount = min($collect_amount, $due); // Due-এর বেশি নেওয়া যাবে না (সার্ভার-সাইড ক্ল্যাম্প)
                $old_amount = (float) $row['amount'];
                $merged_amount = round($old_amount + $collect_amount, 2);
                $folio_no_this = (string) $row['folio_no'];

                // এই Folio-র (এই একটা রো না — পুরো Folio-র) Receipt Token — পুরনো এন্ট্রি হলে
                // টোকেন না-ও থাকতে পারে (এই ফিচার আসার আগে কালেক্ট করা), তখন নতুন বানিয়ে
                // ঐ Folio-র সবগুলো রো-তেই বসিয়ে দেওয়া হয় যাতে money_receipt.php লিংক কাজ করে
                $token_stmt = $pdo->prepare("SELECT receipt_token FROM finance_fee_collections WHERE folio_no = ? AND receipt_token IS NOT NULL AND receipt_token != '' LIMIT 1");
                $token_stmt->execute([$folio_no_this]);
                $folio_token = $token_stmt->fetchColumn();
                if (!$folio_token) {
                    $folio_token = fc_new_receipt_token();
                    $pdo->prepare("UPDATE finance_fee_collections SET receipt_token = ? WHERE folio_no = ?")->execute([$folio_token, $folio_no_this]);
                }

                // পুরনো এন্ট্রির amount-এই যোগ হয়ে যাচ্ছে (200+50=250) — নতুন এন্ট্রি না, নতুন Folio না
                $pdo->prepare("UPDATE finance_fee_collections SET amount = ? WHERE id = ?")->execute([$merged_amount, $id]);

                $fr_stu = $pdo->prepare("SELECT student_id, name_en FROM students WHERE id = ?");
                $fr_stu->execute([$student_id]);
                $fr_stu_row = $fr_stu->fetch();
                $fr_stu_lbl = $fr_stu_row ? ($fr_stu_row['student_id'] . ' - ' . $fr_stu_row['name_en']) : ('#' . $student_id);
                sec_log_activity($pdo, 'Finance', 'Update', "Due Collection (Re-collection পেজ থেকে, একই Folio-তে যোগ): {$fr_stu_lbl}, {$row['ac_head']}, ৳" . fr_money($old_amount) . " + ৳" . fr_money($collect_amount) . " = ৳" . fr_money($merged_amount) . " (Folio: {$folio_no_this})");

                // SMS-এ এই মুহূর্তে যা কালেক্ট হলো শুধু সেই amount-টাই দেখানো হয় (মোট amount না),
                // যাতে অভিভাবক বুঝতে পারেন এইবার ঠিক কত টাকা জমা দিলেন — লিংকে ক্লিক করলে অবশ্য
                // Money Receipt-এ Folio-র সম্পূর্ণ (মার্জ হওয়া) মোট টাকাই দেখাবে
                fc_send_receipt_sms($pdo, $student_id, $folio_no_this, $folio_token, $collect_amount, [(string) ($row['ac_head'] ?: 'Fees')]);

                $_SESSION['fr_msg'] = 'বাকি ৳' . fr_money($collect_amount) . ' একই Folio-তে (' . $folio_no_this . ') যোগ হয়েছে — এন্ট্রির Amount এখন ৳' . fr_money($merged_amount) . '। অভিভাবককে Money Receipt লিংকসহ SMS পাঠানো হয়েছে (SMS Settings-এ টগল অন থাকলে)।';
            }
        }
        header('Location: index.php?page=finance_fees_recollection&folio_no=' . urlencode($_POST['folio_no'] ?? '') . '&do_search=1');
        exit();
    }
}

// -----------------------------------------------------------
// Search (GET)
// -----------------------------------------------------------
$folio_no = trim($_GET['folio_no'] ?? '');
$do_search = isset($_GET['do_search']);

$rows = [];
if ($do_search && $folio_no !== '') {
    $frc_where = ['fc.folio_no = ?']; $frc_params = [$folio_no];
    branch_apply_filter($frc_where, $frc_params, 's.branch_id');
    $stmt = $pdo->prepare(
        "SELECT fc.*, s.student_id AS student_code, s.name_en AS student_name, s.roll_no, s.class_id,
                c.class_name, ses.session_name, fh.ac_code, fh.ac_head
         FROM finance_fee_collections fc
         LEFT JOIN students s ON s.id = fc.student_id
         LEFT JOIN academic_classes c ON c.id = s.class_id
         LEFT JOIN academic_sessions ses ON ses.id = fc.session_id
         LEFT JOIN finance_account_heads fh ON fh.id = fc.ac_head_id
         WHERE " . implode(' AND ', $frc_where) . "
         ORDER BY fc.id ASC"
    );
    $stmt->execute($frc_params);
    $rows = $stmt->fetchAll();
}
$grand_total = 0.0;
foreach ($rows as $r) { $grand_total += (float) $r['amount']; }

// -----------------------------------------------------------
// প্রতিটা লাইন-আইটেমের Period+Head-এর জন্য বাকি Due — এই ফলিও ছাড়াও ঐ Student-এর
// সবগুলো Folio মিলিয়ে (Payable - সব মিলিয়ে Paid) হিসাব করা হয়, যাতে কিস্তিতে
// একাধিকবার পেমেন্ট করা থাকলেও ডাবল-কাউন্ট না হয়
// -----------------------------------------------------------
$fr_dues = [];
$fr_grand_due = 0.0;
$fr_due_cache = []; // একই Period+Head একাধিক কিস্তিতে (একাধিক রো) পেইড হলেও Due একবারই কাউন্ট হবে
foreach ($rows as $r) {
    $ac_head_id = (int) $r['ac_head_id'];
    if ($ac_head_id <= 0) { $fr_dues[$r['id']] = 0.0; continue; }
    $ckey = $r['student_id'] . '-' . $r['session_id'] . '-' . $r['period_type'] . '-' . $r['month'] . '-' . $ac_head_id;
    if (!isset($fr_due_cache[$ckey])) {
        $payable = fr_resolve_payable($pdo, (int) $r['student_id'], (int) $r['session_id'], (int) $r['class_id'], (int) $r['month'], $ac_head_id);
        $paid_total_stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM finance_fee_collections
             WHERE student_id = ? AND session_id = ? AND period_type = ? AND month = ? AND ac_head_id = ?"
        );
        $paid_total_stmt->execute([(int) $r['student_id'], (int) $r['session_id'], $r['period_type'], (int) $r['month'], $ac_head_id]);
        $fr_due_cache[$ckey] = max(0, round($payable - (float) $paid_total_stmt->fetchColumn(), 2));
        $fr_grand_due += $fr_due_cache[$ckey];
    }
    $fr_dues[$r['id']] = $fr_due_cache[$ckey];
}
?>

<div class="card-custom mb-3">
    <div class="d-flex justify-content-end mb-2">
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="window.location='index.php?page=finance_fees_recollection'"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <form method="GET" action="index.php">
        <input type="hidden" name="page" value="finance_fees_recollection">

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Folio No <span class="text-danger">*</span></label>
            <div class="col-sm-6 position-relative">
                <input type="text" name="folio_no" id="frFolioInput" class="form-control" required autocomplete="off" value="<?php echo frv($folio_no); ?>">
                <div id="frFolioSuggest" class="list-group shadow-sm d-none" style="position:absolute;left:12px;right:12px;top:100%;z-index:1050;max-height:320px;overflow-y:auto;"></div>
            </div>
        </div>

        <hr>
        <div class="text-center">
            <button type="submit" name="do_search" value="1" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Search</button>
            <a href="index.php?page=finance_fees_recollection" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
        </div>
    </form>
</div>

<?php if ($fr_msg): ?><div class="alert alert-success py-2"><?php echo frv($fr_msg); ?></div><?php endif; ?>
<?php if ($fr_err): ?><div class="alert alert-danger py-2"><?php echo frv($fr_err); ?></div><?php endif; ?>

<?php if ($do_search): ?>
    <?php if (!$rows): ?>
        <div class="alert alert-warning">এই Folio No (<strong><?php echo frv($folio_no); ?></strong>) দিয়ে কোনো Invoice পাওয়া যায়নি।</div>
    <?php else: ?>
        <?php $first = $rows[0]; $csrf = htmlspecialchars(csrf_token()); ?>
        <div class="card-custom mb-3">
            <div class="mb-2 d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-secondary">Folio: <?php echo frv($folio_no); ?></span>
                    <span class="badge bg-secondary"><?php echo frv($first['session_name']); ?></span>
                    <span class="badge bg-secondary"><?php echo frv($first['class_name']); ?></span>
                    <span class="badge bg-info text-dark"><?php echo frv($first['student_code'] . ' - ' . $first['student_name'] . ' - ' . $first['roll_no']); ?></span>
                </div>
                <a href="print_money_receipt.php?folio_no=<?php echo urlencode($folio_no); ?>&back=finance_fees_recollection" target="_blank" class="btn btn-sm btn-primary fw-bold"><i class="fa-solid fa-print"></i> Print Money Receipt</a>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Period</th>
                            <th>Fees Type</th>
                            <th>Amount</th>
                            <th>Due</th>
                            <th>Payment Method</th>
                            <th>Receipt No</th>
                            <th>Collected At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): $row_due = $fr_dues[$row['id']] ?? 0.0; ?>
                            <tr>
                                <td><?php echo frv($PERIODS[(int) $row['month']] ?? '—'); ?></td>
                                <td><?php echo frv(trim(($row['ac_code'] ?? '') . ' ' . ($row['ac_head'] ?? '—'))); ?></td>
                                <td class="fw-bold"><?php echo fr_money($row['amount']); ?></td>
                                <td class="<?php echo $row_due > 0 ? 'text-danger fw-bold' : 'text-muted'; ?>"><?php echo $row_due > 0 ? fr_money($row_due) : '—'; ?></td>
                                <td><?php echo frv($row['payment_method']); ?></td>
                                <td><?php echo frv($row['receipt_no'] ?: '—'); ?></td>
                                <td><?php echo frv($row['collected_at']); ?></td>
                                <td class="text-nowrap">
                                    <?php if ($row_due > 0): ?>
                                        <button type="button" class="btn btn-sm btn-success" title="Collect Due" data-bs-toggle="modal" data-bs-target="#frCollectModal<?php echo (int) $row['id']; ?>"><i class="fa-solid fa-money-bill"></i> Collect Due</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" title="Correct" data-bs-toggle="modal" data-bs-target="#frEditModal<?php echo (int) $row['id']; ?>"><i class="fa-solid fa-pen"></i></button>
                                    <form method="POST" action="index.php?page=finance_fees_recollection" class="d-inline" onsubmit="return confirm('এই এন্ট্রি Void (মুছে) করবেন?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                        <input type="hidden" name="folio_no" value="<?php echo frv($folio_no); ?>">
                                        <button type="submit" name="delete_item" class="btn btn-sm btn-outline-danger" title="Void"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="2" class="text-end">Grand Total :</td>
                            <td><?php echo fr_money($grand_total); ?></td>
                            <td class="<?php echo $fr_grand_due > 0 ? 'text-danger' : ''; ?>"><?php echo $fr_grand_due > 0 ? fr_money($fr_grand_due) : '—'; ?></td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <?php foreach ($rows as $row): $row_due = $fr_dues[$row['id']] ?? 0.0; if ($row_due > 0): ?>
            <div class="modal fade" id="frCollectModal<?php echo (int) $row['id']; ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="index.php?page=finance_fees_recollection">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                            <input type="hidden" name="folio_no" value="<?php echo frv($folio_no); ?>">
                            <div class="modal-header">
                                <h6 class="modal-title">Collect Due — <?php echo frv(trim(($row['ac_code'] ?? '') . ' ' . ($row['ac_head'] ?? ''))); ?> (<?php echo frv($PERIODS[(int) $row['month']] ?? ''); ?>)</h6>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Due Amount: <span class="text-danger fw-bold"><?php echo fr_money($row_due); ?></span></label>
                                    <input type="number" step="0.01" min="0.01" max="<?php echo frv($row_due); ?>" name="collect_amount" class="form-control" value="<?php echo frv($row_due); ?>" required>
                                    <div class="form-text">Due-এর বেশি বসালেও অটো Due-তে ক্যাপ হয়ে যাবে। কম বসিয়ে আংশিক (Partial) কালেক্টও করা যাবে।</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Payment Method</label>
                                    <select name="collect_payment_method" class="form-select">
                                        <?php foreach (['Cash', 'Bank', 'bKash', 'Nagad'] as $pm): ?>
                                            <option value="<?php echo $pm; ?>"><?php echo $pm; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="alert alert-info py-2 small mb-0"><i class="fa-solid fa-circle-info"></i> নতুন Folio/Receipt তৈরি হবে না — এই টাকা এই একই এন্ট্রির Amount-এই যোগ হয়ে যাবে (যেমন ২০০+৫০=২৫০), আর সাথে সাথেই অভিভাবকের নাম্বারে (SMS Settings-এ টগল অন থাকলে) এই Folio-র Money Receipt লিংকসহ SMS চলে যাবে।</div>
                            </div>
                            <div class="modal-footer">
                                <button type="submit" name="collect_due" class="btn btn-success fw-bold"><i class="fa-solid fa-money-bill"></i> Collect</button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; endforeach; ?>

        <?php foreach ($rows as $row): ?>
            <div class="modal fade" id="frEditModal<?php echo (int) $row['id']; ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="index.php?page=finance_fees_recollection">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                            <input type="hidden" name="folio_no" value="<?php echo frv($folio_no); ?>">
                            <div class="modal-header">
                                <h6 class="modal-title">Correct Entry — <?php echo frv(trim(($row['ac_code'] ?? '') . ' ' . ($row['ac_head'] ?? ''))); ?> (<?php echo frv($PERIODS[(int) $row['month']] ?? ''); ?>)</h6>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Amount</label>
                                    <input type="number" step="0.01" min="0.01" name="new_amount" class="form-control" value="<?php echo frv($row['amount']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Payment Method</label>
                                    <select name="new_payment_method" class="form-select">
                                        <?php foreach (['Cash', 'Bank', 'bKash', 'Nagad'] as $pm): ?>
                                            <option value="<?php echo $pm; ?>" <?php echo $row['payment_method'] === $pm ? 'selected' : ''; ?>><?php echo $pm; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-text">Payable-এর বেশি Amount দিলে অটো Payable-এ ক্যাপ হয়ে যাবে।</div>
                            </div>
                            <div class="modal-footer">
                                <button type="submit" name="update_item" class="btn btn-primary" style="background-color:#1e3a8a;border-color:#1e3a8a;">Save</button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>

<script>
// Folio No — ২টা অক্ষর লিখলেই সাজেশন (সর্বশেষ Collection করা Folio আগে)
(function () {
    const input = document.getElementById('frFolioInput');
    const box = document.getElementById('frFolioSuggest');
    if (!input || !box) return;
    let timer = null, ctrl = null, items = [], active = -1;

    function esc(t) { const d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function hide() { box.classList.add('d-none'); box.innerHTML = ''; items = []; active = -1; }

    function choose(folio) {
        input.value = folio;
        hide();
        window.location = 'index.php?page=finance_fees_recollection&folio_no=' + encodeURIComponent(folio) + '&do_search=1';
    }

    function render(list) {
        items = list; active = -1;
        if (!list.length) { hide(); return; }
        box.innerHTML = list.map(function (r, i) {
            const who = [r.code, r.name].filter(Boolean).join(' - ');
            const meta = [r.class ? 'Class ' + r.class : '', r.roll_no ? 'Roll ' + r.roll_no : '', '৳' + r.total, r.at || ''].filter(Boolean).join(' · ');
            return '<button type="button" class="list-group-item list-group-item-action py-2" data-i="' + i + '">' +
                   '<div class="fw-bold">' + esc(r.folio_no) + '</div>' +
                   '<div class="small text-muted">' + esc(who) + (who && meta ? ' — ' : '') + esc(meta) + '</div></button>';
        }).join('');
        box.classList.remove('d-none');
    }

    function setActive(n) {
        const btns = box.querySelectorAll('button');
        btns.forEach(function (b) { b.classList.remove('active'); });
        if (n >= 0 && n < btns.length) { btns[n].classList.add('active'); btns[n].scrollIntoView({ block: 'nearest' }); }
        active = n;
    }

    function fetchSuggest() {
        const q = input.value.trim();
        if (q.length < 2) { hide(); return; }
        if (ctrl) ctrl.abort();
        ctrl = new AbortController();
        fetch('ajax/folio_suggest_ajax.php?q=' + encodeURIComponent(q), { signal: ctrl.signal, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) { if (res.success && input.value.trim() === q) render(res.results); })
            .catch(function () {});
    }

    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(fetchSuggest, 200); });
    input.addEventListener('focus', function () { if (input.value.trim().length >= 2 && !items.length) fetchSuggest(); });
    input.addEventListener('keydown', function (e) {
        if (box.classList.contains('d-none') || !items.length) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(Math.min(active + 1, items.length - 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(Math.max(active - 1, 0)); }
        else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); choose(items[active].folio_no); }
        else if (e.key === 'Escape') { hide(); }
    });
    box.addEventListener('mousedown', function (e) {
        const b = e.target.closest('button[data-i]');
        if (b) { e.preventDefault(); choose(items[parseInt(b.dataset.i, 10)].folio_no); }
    });
    document.addEventListener('click', function (e) { if (!box.contains(e.target) && e.target !== input) hide(); });
})();
</script>
