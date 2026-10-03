<?php
require_once __DIR__ . '/../config/fees_period_resolver.php'; // sfc_pick_fee_setting() — Student-এর Section/Shift/Gender মিলিয়ে সঠিক Fee Setting বাছার শেয়ার্ড হেল্পার
// admin/finance_quick_collection.php - Finance Module > Students Accounts > Student Fees Collection (Quick)
// একজন Student সিলেক্ট করলে তার Admission + Jan-Dec এর প্রতিটা মাসের Payable/Paid/Due
// একনজরে দেখা যায় এবং যেসব মাসে Due আছে সেগুলোর জন্য এক-ক্লিকে (Full Amount, Partial না)
// Fee Collect করা যায়। Payable amount আসে Student Fees Settings (student-level override, না
// থাকলে class-level default) + Student Wise Waiver থেকে (শুধু মাসিক ফির জন্য)।

require_once __DIR__ . '/../config/fee_collection_sms.php'; // fc_send_receipt_sms() — কালেকশনের পর Money Receipt লিংকসহ SMS

function qcv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function qc_money($n): string { return number_format((float) $n, 2); }

$qc_err = $_SESSION['qc_err'] ?? '';
$qc_msg = $_SESSION['qc_msg'] ?? '';
$qc_last_folio = $_SESSION['qc_last_folio'] ?? '';
unset($_SESSION['qc_err'], $_SESSION['qc_msg'], $_SESSION['qc_last_folio']);

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি
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
$sfc_cols = $pdo->query("SHOW COLUMNS FROM finance_fee_collections")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('ac_head_id', $sfc_cols, true)) { $pdo->exec("ALTER TABLE finance_fee_collections ADD COLUMN ac_head_id INT NULL AFTER month"); }
if (!in_array('folio_no', $sfc_cols, true)) { $pdo->exec("ALTER TABLE finance_fee_collections ADD COLUMN folio_no VARCHAR(50) NULL AFTER trx_id"); }
fc_ensure_receipt_token_column($pdo); // receipt_token কলাম — Money Receipt SMS লিংকের জন্য

// ---------------------------------------------------------------
// Instant Waiver অন/অফ আছে কিনা — Money Receipt Settings পেজের টগল থেকে নিয়ন্ত্রিত হয়
// (finance_fees_collection.php-এর সাথে টেবিল শেয়ার্ড, তাই এখানেও নিরাপদে auto-create/alter)
// ---------------------------------------------------------------
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
if ((int) $pdo->query("SELECT COUNT(*) FROM finance_money_receipt_settings")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO finance_money_receipt_settings (id) VALUES (1)");
}
$qc_mrs_cols = $pdo->query("SHOW COLUMNS FROM finance_money_receipt_settings")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('show_waiver', $qc_mrs_cols, true)) {
    $pdo->exec("ALTER TABLE finance_money_receipt_settings ADD COLUMN show_waiver TINYINT(1) NOT NULL DEFAULT 1 AFTER show_due");
}
if (!in_array('show_waiver_student', $qc_mrs_cols, true)) {
    $pdo->exec("ALTER TABLE finance_money_receipt_settings ADD COLUMN show_waiver_student TINYINT(1) NOT NULL DEFAULT 1 AFTER show_waiver");
}
if (!in_array('allow_instant_waiver', $qc_mrs_cols, true)) {
    $pdo->exec("ALTER TABLE finance_money_receipt_settings ADD COLUMN allow_instant_waiver TINYINT(1) NOT NULL DEFAULT 0 AFTER show_waiver_student");
}
$qc_allow_instant_waiver = (bool) $pdo->query("SELECT allow_instant_waiver FROM finance_money_receipt_settings WHERE id = 1")->fetchColumn();

// নিচের qc_resolve_payables() ফাংশন student-level override/waiver পড়ার জন্য আরও ৩টা টেবিল
// ব্যবহার করে — এগুলো সাধারণত Student Fees Settings / Student Wise Waiver পেজে গেলে তৈরি হয়।
// কিন্তু সেই পেজগুলোয় আগে কখনো না গিয়ে সরাসরি এই Quick Collection পেজে এলে (যেমন নতুন
// স্কুলে Admission-এর পরপরই ফি কালেক্ট করতে চাইলে, যেটা খুবই স্বাভাবিক একটা ফ্লো) টেবিল না
// থাকায় "Base table or view not found" ফ্যাটাল এরর দিয়ে পুরো পেজ ক্র্যাশ করত (HTTP 500,
// সাদা স্ক্রিন)। এখানেও একই স্ট্রাকচারে (finance_student_fees.php / finance_student_waiver.php
// এর সাথে হুবহু মিলিয়ে) নিরাপদে auto-create করা হলো।
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_student_admission_fees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        session_id INT NOT NULL,
        ac_head_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_item (student_id, session_id, ac_head_id),
        KEY idx_student (student_id, session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_student_monthly_fees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        session_id INT NOT NULL,
        month TINYINT NOT NULL,
        ac_head_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_item (student_id, session_id, month, ac_head_id),
        KEY idx_student (student_id, session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_student_waivers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        session_id INT NOT NULL,
        class_id INT NOT NULL,
        month TINYINT NOT NULL,
        ac_head_id INT NOT NULL,
        waiver_type ENUM('Percentage','Fixed') NOT NULL DEFAULT 'Fixed',
        waiver_value DECIMAL(10,2) NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_lookup (student_id, session_id, month, ac_head_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
// পুরনো ইনস্টলেশনে folio_no কলাম না থাকলে যোগ করে দেওয়া (finance_fees_collection.php-এর সাথে শেয়ার্ড)
$qc_sw_cols = $pdo->query("SHOW COLUMNS FROM finance_student_waivers")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('folio_no', $qc_sw_cols, true)) {
    $pdo->exec("ALTER TABLE finance_student_waivers ADD COLUMN folio_no VARCHAR(50) NULL AFTER waiver_value");
}

$MONTHS = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

// -----------------------------------------------------------
// Payable রিসলভ করার হেল্পার — student-level override, না থাকলে class-level default,
// (মাসিক ফির উপর active waiver থাকলে সেটা বাদ দিয়ে) [period => amount] + প্রতিটা period-এর
// fee-head ব্রেকডাউন রিটার্ন করে। period 0 = Admission, 1-12 = মাস।
// -----------------------------------------------------------
function qc_resolve_payables(PDO $pdo, int $student_id, int $session_id, int $class_id, array $fee_heads, array $MONTHS): array
{
    $admission_saved = [];
    $monthly_saved   = [];

    $a = $pdo->prepare("SELECT ac_head_id, amount FROM finance_student_admission_fees WHERE student_id = ? AND session_id = ?");
    $a->execute([$student_id, $session_id]);
    $arows = $a->fetchAll();
    if ($arows) {
        foreach ($arows as $r) { $admission_saved[(int) $r['ac_head_id']] = (float) $r['amount']; }
    } else {
        $csid = sfc_pick_fee_setting($pdo, 'finance_admission_fee_settings', $student_id, $session_id, $class_id);
        if ($csid) {
            $ci = $pdo->prepare("SELECT ac_head_id, amount FROM finance_admission_fee_items WHERE setting_id = ?");
            $ci->execute([$csid]);
            foreach ($ci->fetchAll() as $r) { $admission_saved[(int) $r['ac_head_id']] = (float) $r['amount']; }
        }
    }

    $m = $pdo->prepare("SELECT month, ac_head_id, amount FROM finance_student_monthly_fees WHERE student_id = ? AND session_id = ?");
    $m->execute([$student_id, $session_id]);
    $mrows = $m->fetchAll();
    if ($mrows) {
        foreach ($mrows as $r) { $monthly_saved[(int) $r['month']][(int) $r['ac_head_id']] = (float) $r['amount']; }
    } else {
        $csid = sfc_pick_fee_setting($pdo, 'finance_monthly_fee_settings', $student_id, $session_id, $class_id);
        if ($csid) {
            $ci = $pdo->prepare("SELECT month, ac_head_id, amount FROM finance_monthly_fee_items WHERE setting_id = ?");
            $ci->execute([$csid]);
            foreach ($ci->fetchAll() as $r) { $monthly_saved[(int) $r['month']][(int) $r['ac_head_id']] = (float) $r['amount']; }
        }
    }

    // Active waiver ম্যাপ [month][ac_head_id] => ['type'=>..,'value'=>..]
    $waiver_map = [];
    $w = $pdo->prepare("SELECT month, ac_head_id, waiver_type, waiver_value FROM finance_student_waivers WHERE student_id = ? AND session_id = ? AND status = 'Active'");
    $w->execute([$student_id, $session_id]);
    foreach ($w->fetchAll() as $r) {
        $waiver_map[(int) $r['month']][(int) $r['ac_head_id']] = ['type' => $r['waiver_type'], 'value' => (float) $r['waiver_value']];
    }

    $periods = [0 => 'Admission'] + $MONTHS;
    $breakdown = []; // [period] => [ [head_name, base_amt, waiver_amt, net_amt], ... ]
    $payable   = [];  // [period] => net total

    foreach ($periods as $p => $label) {
        $heads_amt = $p === 0 ? $admission_saved : ($monthly_saved[$p] ?? []);
        $total = 0.0;
        $rows = [];
        foreach ($fee_heads as $fh) {
            $hid = (int) $fh['id'];
            if (!isset($heads_amt[$hid]) || $heads_amt[$hid] <= 0) { continue; }
            $base = $heads_amt[$hid];
            $net = $base;
            $wv = 0.0;
            // p=0 (Admission)-কে বাদ দেওয়া হতো আগে — ফলে Admission Fee-এর উপর waiver
            // দেওয়া থাকলেও Quick Collection-এ সেটা কখনো এপ্লাই হতো না
            if (isset($waiver_map[$p][$hid])) {
                $wv = $waiver_map[$p][$hid]['type'] === 'Percentage'
                    ? round($base * $waiver_map[$p][$hid]['value'] / 100, 2)
                    : $waiver_map[$p][$hid]['value'];
                $net = max(0, $base - $wv);
            }
            $rows[] = ['ac_head_id' => $hid, 'name' => trim($fh['ac_code'] . ' ' . $fh['ac_head']), 'base' => $base, 'waiver' => $wv, 'net' => $net];
            $total += $net;
        }
        $breakdown[$p] = $rows;
        $payable[$p] = $total;
    }

    return [$payable, $breakdown];
}

// -----------------------------------------------------------
// POST Handler - Collect Payment (Full amount only, no partial)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['collect_payment'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['qc_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=finance_quick_collection');
        exit();
    }

    $student_id = (int) ($_POST['student_id'] ?? 0);
    $session_id = (int) ($_POST['session_id'] ?? 0);
    $class_id   = (int) ($_POST['class_id'] ?? 0);
    $period     = (int) ($_POST['period'] ?? -1); // 0 = Admission, 1-12 = month
    $method     = trim($_POST['payment_method'] ?? 'Cash');
    $trx_id     = trim($_POST['trx_id'] ?? '');
    // waive_amount শুধু টগল চালু থাকলেই প্রসেস হবে (ফর্ম ম্যানুপুলেট করে পাঠালেও নিরাপদ)
    $waive_amount = $qc_allow_instant_waiver ? (float) ($_POST['waive_amount'] ?? 0) : 0.0;

    $fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC")->fetchAll();

    if (!$student_id || !$session_id || $period < 0 || $period > 12) {
        $_SESSION['qc_err'] = 'সঠিক Student/Period পাওয়া যায়নি।';
        header('Location: index.php?page=finance_quick_collection&session_id=' . $session_id . '&class_id=' . $class_id . '&student_id=' . $student_id);
        exit();
    }
    if (!in_array($method, ['Cash', 'bKash', 'Nagad', 'Bank'], true)) { $method = 'Cash'; }

    [$payable, $breakdown] = qc_resolve_payables($pdo, $student_id, $session_id, $class_id, $fee_heads, $MONTHS);

    $period_type = $period === 0 ? 'Admission' : 'Monthly';
    // প্রতিটা ac_head_id ধরে আলাদাভাবে কত পেইড হয়েছে তা বের করা হচ্ছে (lump-sum না, per-head)
    // যাতে Due Report (finance_due_report_common.php) সঠিকভাবে এই কালেকশন হিসাব করতে পারে —
    // ওই ফাইলটি ac_head_id ভিত্তিক পেইড amount দেখে, NULL ac_head_id সারি বাদ দিয়ে দেয়।
    $p = $pdo->prepare("SELECT ac_head_id, COALESCE(SUM(amount),0) AS paid_amt FROM finance_fee_collections WHERE student_id = ? AND session_id = ? AND period_type = ? AND month = ? GROUP BY ac_head_id");
    $p->execute([$student_id, $session_id, $period_type, $period]);
    $already_paid_map = [];
    foreach ($p->fetchAll() as $r) {
        if ($r['ac_head_id'] === null) { continue; }
        $already_paid_map[(int) $r['ac_head_id']] = (float) $r['paid_amt'];
    }

    // SMS-এ শুধু Fee Head-এর নাম দেখানো হবে, Account Code (যেমন "001") ছাড়া — SMS ক্যারেক্টার বাঁচাতে
    $qc_head_name_map = array_column($fee_heads, 'ac_head', 'id');

    $to_collect = [];
    $due = 0.0;
    foreach ($breakdown[$period] ?? [] as $row) {
        $hid = (int) $row['ac_head_id'];
        $head_due = round($row['net'] - ($already_paid_map[$hid] ?? 0), 2);
        if ($head_due > 0) {
            $to_collect[] = ['ac_head_id' => $hid, 'amount' => $head_due, 'name' => $qc_head_name_map[$hid] ?? $row['name']];
            $due += $head_due;
        }
    }
    $due = round($due, 2);

    if ($due <= 0 || !$to_collect) {
        $_SESSION['qc_err'] = 'এই মাসের কোনো Due নেই — কালেক্ট করার প্রয়োজন নেই।';
        header('Location: index.php?page=finance_quick_collection&session_id=' . $session_id . '&class_id=' . $class_id . '&student_id=' . $student_id);
        exit();
    }

    // ---- Instant Waiver (ঐচ্ছিক, টগল চালু থাকলেই) — মোট Due-এর বেশি ওয়েভার দেওয়া যাবে না,
    // আর head-wise $to_collect লিস্টের উপর ক্রমান্বয়ে বণ্টন করে প্রতিটা head-এর amount কমানো হয় ----
    $waive_amount = max(0.0, min($waive_amount, $due));
    $waived_rows = []; // [ac_head_id => waived_amt]
    if ($waive_amount > 0) {
        $remaining_waiver = $waive_amount;
        foreach ($to_collect as &$tc) {
            if ($remaining_waiver <= 0) { break; }
            $take = min($tc['amount'], $remaining_waiver);
            if ($take <= 0) { continue; }
            $tc['amount'] -= $take;
            $waived_rows[$tc['ac_head_id']] = ($waived_rows[$tc['ac_head_id']] ?? 0) + $take;
            $remaining_waiver -= $take;
        }
        unset($tc);
        $waive_amount -= $remaining_waiver; // আসলে যতটুকু বণ্টন করা গেছে (safety, সাধারণত পুরোটাই বণ্টন হয়ে যায়)
        $to_collect = array_values(array_filter($to_collect, fn($tc) => $tc['amount'] > 0));
        $due = round(array_sum(array_column($to_collect, 'amount')), 2);
    }

    if ($due <= 0 && $waive_amount <= 0) {
        $_SESSION['qc_err'] = 'এই মাসের কোনো Due নেই — কালেক্ট করার প্রয়োজন নেই।';
        header('Location: index.php?page=finance_quick_collection&session_id=' . $session_id . '&class_id=' . $class_id . '&student_id=' . $student_id);
        exit();
    }

    $qc_folio_no = 'FL' . date('ymd') . strtoupper(substr(uniqid(), -5));
    $qc_receipt_token = fc_new_receipt_token(); // এই ফলিওর সব রো একই token শেয়ার করবে (Money Receipt SMS লিংক)

    $ins = $pdo->prepare(
        "INSERT INTO finance_fee_collections (student_id, session_id, period_type, month, ac_head_id, amount, payment_method, trx_id, folio_no, receipt_token, collected_by, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Paid')"
    );
    $qc_collected_head_names = [];
    foreach ($to_collect as $tc) {
        $ins->execute([$student_id, $session_id, $period_type, $period, $tc['ac_head_id'], $tc['amount'], $method, $trx_id ?: null, $qc_folio_no, $qc_receipt_token, $_SESSION['admin_user'] ?? '']);
        $new_id = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE finance_fee_collections SET receipt_no = ? WHERE id = ?")
            ->execute(['RC' . date('ym') . '-' . str_pad((string) $new_id, 5, '0', STR_PAD_LEFT), $new_id]);
        $qc_collected_head_names[] = $tc['name'];
    }

    // Instant Waiver head-wise finance_student_waivers-এ সেভ — এটাই Waiver Report-এ দেখাবে
    if ($waived_rows) {
        $waiver_ins = $pdo->prepare(
            "INSERT INTO finance_student_waivers (student_id, session_id, class_id, month, ac_head_id, waiver_type, waiver_value, folio_no, status)
             VALUES (?, ?, ?, ?, ?, 'Fixed', ?, ?, 'Active')"
        );
        foreach ($waived_rows as $hid => $wamt) {
            $waiver_ins->execute([$student_id, $session_id, $class_id, $period, $hid, $wamt, $qc_folio_no]);
        }
    }

    $qc_stu_stmt = $pdo->prepare("SELECT student_id, name_en FROM students WHERE id = ?");
    $qc_stu_stmt->execute([$student_id]);
    $qc_stu = $qc_stu_stmt->fetch();
    $qc_stu_lbl = $qc_stu ? ($qc_stu['student_id'] . ' - ' . $qc_stu['name_en']) : "#{$student_id}";
    $qc_period_lbl = $period === 0 ? 'Admission' : ($MONTHS[$period] ?? $period);
    $qc_log_parts = [];
    if ($due > 0) { $qc_log_parts[] = 'কালেক্ট ৳' . qc_money($due); }
    if ($waive_amount > 0) { $qc_log_parts[] = 'Waiver ৳' . qc_money($waive_amount); }
    sec_log_activity($pdo, 'Finance', 'Create', "Quick Fee Collection: {$qc_stu_lbl}, Period: {$qc_period_lbl}, " . implode(', ', $qc_log_parts) . " (Folio: {$qc_folio_no})");
    if ($due > 0) { fc_send_receipt_sms($pdo, $student_id, $qc_folio_no, $qc_receipt_token, $due, $qc_collected_head_names); }

    $qc_msg_parts = [];
    if ($due > 0) { $qc_msg_parts[] = qc_money($due) . ' টাকা কালেক্ট করা হয়েছে'; }
    if ($waive_amount > 0) { $qc_msg_parts[] = qc_money($waive_amount) . ' টাকা Waiver দেওয়া হয়েছে'; }
    $_SESSION['qc_msg'] = implode(' এবং ', $qc_msg_parts) . '।';
    if ($due > 0) { $_SESSION['qc_last_folio'] = $qc_folio_no; }
    header('Location: index.php?page=finance_quick_collection&session_id=' . $session_id . '&class_id=' . $class_id . '&student_id=' . $student_id);
    exit();
}

// -----------------------------------------------------------
// Search Filters (GET)
// -----------------------------------------------------------
$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC")->fetchAll();

$session_id = (int) ($_GET['session_id'] ?? $current_session_id);
$class_id   = (int) ($_GET['class_id'] ?? 0);
$student_id = (int) ($_GET['student_id'] ?? 0);

$students = [];
if ($session_id && $class_id) {
    $fqc_where = ['session_id = ?', 'class_id = ?', "status = 'Active'"];
    $fqc_params = [$session_id, $class_id];
    branch_apply_filter($fqc_where, $fqc_params);
    $st = $pdo->prepare(
        "SELECT id, student_id, roll_no, name_en FROM students
         WHERE " . implode(' AND ', $fqc_where) . "
         ORDER BY CAST(roll_no AS UNSIGNED) ASC, roll_no ASC"
    );
    $st->execute($fqc_params);
    $students = $st->fetchAll();
}

$student = null;
if ($student_id) {
    $stmt = $pdo->prepare(
        "SELECT s.*, ses.session_name, c.class_name, sh.shift_name, sec.section_name, med.medium_name
         FROM students s
         LEFT JOIN academic_sessions ses ON ses.id = s.session_id
         LEFT JOIN academic_classes c ON c.id = s.class_id
         LEFT JOIN academic_shifts sh ON sh.id = s.shift_id
         LEFT JOIN academic_sections sec ON sec.id = s.section_id
         LEFT JOIN academic_mediums med ON med.id = c.medium_id
         WHERE s.id = ?"
    );
    $stmt->execute([$student_id]);
    $student = $stmt->fetch() ?: null;
}

$payable = [];
$breakdown = [];
$paid = [];
$due = [];
$total_payable = 0.0;
$total_paid = 0.0;
$total_dues = 0.0;

if ($student) {
    [$payable, $breakdown] = qc_resolve_payables($pdo, (int) $student['id'], $session_id, $class_id, $fee_heads, $MONTHS);

    $pc = $pdo->prepare("SELECT period_type, month, COALESCE(SUM(amount),0) AS paid_amt FROM finance_fee_collections WHERE student_id = ? AND session_id = ? GROUP BY period_type, month");
    $pc->execute([(int) $student['id'], $session_id]);
    foreach ($pc->fetchAll() as $r) {
        $paid[(int) $r['month']] = (float) $r['paid_amt'];
    }

    foreach ([0 => true] + $MONTHS as $p => $x) {
        $pay = $payable[$p] ?? 0;
        $pd  = $paid[$p] ?? 0;
        $due[$p] = max(0, round($pay - $pd, 2));
        $total_payable += $pay;
        $total_dues    += $due[$p];
    }
    $total_paid = array_sum($paid);
}
?>

<div class="card-custom mb-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="btn btn-sm" style="background-color:#f0ad4e;color:#fff;"><i class="fa-solid fa-bars"></i></span>
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="window.location='index.php?page=finance_quick_collection'"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <form method="GET" action="index.php" id="qcFilterForm">
        <input type="hidden" name="page" value="finance_quick_collection">

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Session</label>
            <div class="col-sm-6">
                <select name="session_id" id="qcSession" class="form-select" onchange="document.getElementById('qcFilterForm').submit()">
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $session_id === (int) $s['id'] ? 'selected' : ''; ?>><?php echo qcv($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Class</label>
            <div class="col-sm-6">
                <select name="class_id" id="qcClass" class="form-select" onchange="document.getElementById('qcFilterForm').submit()">
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $cl): ?>
                        <option value="<?php echo (int) $cl['id']; ?>" <?php echo $class_id === (int) $cl['id'] ? 'selected' : ''; ?>><?php echo qcv($cl['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Student ID/Name <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="student_id" id="qcStudent" class="form-select" <?php echo (!$class_id) ? 'disabled' : ''; ?>>
                    <option value="">Select Student</option>
                    <?php foreach ($students as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $student_id === (int) $s['id'] ? 'selected' : ''; ?>>
                            <?php echo qcv($s['student_id'] . ' - ' . $s['name_en'] . ' - ' . $s['roll_no']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <hr>
        <div class="text-center">
            <button type="submit" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Search</button>
            <a href="index.php?page=finance_quick_collection" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
        </div>
    </form>
</div>

<?php if ($qc_msg): ?>
    <div class="alert alert-success py-2 d-flex justify-content-between align-items-center">
        <span><?php echo qcv($qc_msg); ?></span>
        <?php if ($qc_last_folio): ?>
            <a href="print_money_receipt.php?folio_no=<?php echo urlencode($qc_last_folio); ?>&back=finance_quick_collection" target="_blank" class="btn btn-sm btn-primary fw-bold"><i class="fa-solid fa-print"></i> Print Money Receipt</a>
        <?php endif; ?>
    </div>
    <?php if ($qc_last_folio): ?>
        <!-- কালেকশন সেভ হওয়ার সাথে সাথেই অটোমেটিক Money Receipt এর প্রিন্ট ডায়ালগ
             খুলে যায় (লুকানো iframe দিয়ে, যাতে পপআপ ব্লকারে না আটকায়) -->
        <iframe id="qcAutoPrint" src="print_money_receipt.php?folio_no=<?php echo urlencode($qc_last_folio); ?>&auto=1"
                style="position:fixed; right:0; bottom:0; width:1px; height:1px; border:0; opacity:0;" aria-hidden="true"></iframe>
    <?php endif; ?>
<?php endif; ?>
<?php if ($qc_err): ?><div class="alert alert-danger py-2"><?php echo qcv($qc_err); ?></div><?php endif; ?>

<?php if (!$student): ?>
    <div class="alert alert-info">Session, Class ও Student সিলেক্ট করে <strong>Search</strong> চাপুন — তারপর ফি কালেকশন গ্রিডটি এখানে দেখা যাবে।</div>

<?php else: ?>

    <div class="alert alert-warning">
        <i class="fa-solid fa-circle-info me-1"></i> <strong>Warning!</strong> Student Fees Collection will be applicable for only current students. You can't proceed with admission fees / specific month. You can't collect partial amount here.
    </div>

    <div class="card-custom mb-3">
        <div class="mb-2"><span class="fw-bold"><i class="fa-solid fa-circle-info me-1"></i> Student Fees Collection (Quick)</span></div>

        <div class="table-responsive mb-3">
            <table class="table table-bordered align-middle mb-0">
                <tr>
                    <td rowspan="3" style="width:90px;">
                        <img src="<?php echo $student['photo_path'] ? '../' . qcv($student['photo_path']) : 'data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2728%27 height=%2728%27%3E%3Crect width=%2728%27 height=%2728%27 fill=%27%23e2e8f0%27/%3E%3C/svg%3E'; ?>" style="width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0;">
                    </td>
                    <td class="fw-bold text-muted" style="width:120px;">ID</td>
                    <td><?php echo qcv($student['student_id']); ?></td>
                    <td class="fw-bold text-muted" style="width:120px;">Session</td>
                    <td><?php echo qcv($student['session_name']); ?></td>
                    <td class="fw-bold text-muted" style="width:120px;">Medium</td>
                    <td><?php echo qcv($student['medium_name'] ?: 'Combined'); ?></td>
                    <td class="fw-bold text-muted" style="width:90px;">Class</td>
                    <td><?php echo qcv($student['class_name']); ?></td>
                </tr>
                <tr>
                    <td class="fw-bold text-muted">Name</td>
                    <td><?php echo qcv($student['name_en']); ?></td>
                    <td class="fw-bold text-muted">Shift</td>
                    <td><?php echo qcv($student['shift_name'] ?: '—'); ?></td>
                    <td class="fw-bold text-muted">Section</td>
                    <td><?php echo qcv($student['section_name'] ?: '—'); ?></td>
                    <td class="fw-bold text-muted">Roll</td>
                    <td><?php echo qcv($student['roll_no']); ?></td>
                </tr>
                <tr>
                    <td class="fw-bold text-muted">Father's Name</td>
                    <td><?php echo qcv($student['father_name']); ?></td>
                    <td class="fw-bold text-muted">Mother's Name</td>
                    <td><?php echo qcv($student['mother_name']); ?></td>
                    <td class="fw-bold text-muted">Guardian Phone Number</td>
                    <td colspan="3"><?php echo qcv($student['guardian_phone'] ?: '—'); ?></td>
                </tr>
            </table>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered text-center align-middle mb-0" style="min-width:1100px;">
                <thead class="table-light">
                    <tr>
                        <?php foreach ([0 => 'Admission'] + $MONTHS as $p => $label): ?>
                            <th class="text-primary"><?php echo qcv($label); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <?php foreach ([0 => 'Admission'] + $MONTHS as $p => $label): ?>
                            <td>
                                <?php if (($due[$p] ?? 0) <= 0): ?>
                                    <i class="fa-solid fa-check text-success"></i>
                                <?php else: ?>
                                    <input type="checkbox" disabled>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <?php foreach ([0 => 'Admission'] + $MONTHS as $p => $label): ?>
                            <td class="fw-bold <?php echo ($due[$p] ?? 0) > 0 ? 'text-danger' : 'text-success'; ?>">
                                <?php echo qc_money($due[$p] ?? 0); ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <?php foreach ([0 => 'Admission'] + $MONTHS as $p => $label): ?>
                            <td>
                                <?php if (($due[$p] ?? 0) > 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" title="View" data-bs-toggle="modal" data-bs-target="#qcViewModal<?php echo $p; ?>"><i class="fa-solid fa-eye"></i></button>
                                    <button type="button" class="btn btn-sm" style="background-color:#f0ad4e;color:#fff;" title="Collect" data-bs-toggle="modal" data-bs-target="#qcPayModal<?php echo $p; ?>"><i class="fa-solid fa-money-bill"></i></button>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card-custom text-center">
                <div class="text-warning fw-bold"><i class="fa-solid fa-file-invoice-dollar"></i> Total Payable</div>
                <div class="fs-4 fw-bold text-warning"><?php echo qc_money($total_payable); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card-custom text-center">
                <div class="text-success fw-bold"><i class="fa-solid fa-file-invoice-dollar"></i> Total Paid</div>
                <div class="fs-4 fw-bold text-success"><?php echo qc_money($total_paid); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card-custom text-center">
                <div class="text-danger fw-bold"><i class="fa-solid fa-file-invoice-dollar"></i> Total Dues</div>
                <div class="fs-4 fw-bold text-danger"><?php echo qc_money($total_dues); ?></div>
            </div>
        </div>
    </div>

    <?php foreach ([0 => 'Admission'] + $MONTHS as $p => $label): if (($due[$p] ?? 0) <= 0) { continue; } ?>
        <!-- View (Invoice Preview) Modal -->
        <div class="modal fade" id="qcViewModal<?php echo $p; ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title"><?php echo qcv($label); ?> — Fee Breakdown</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <table class="table table-sm table-bordered">
                            <thead><tr><th>Fee Type</th><th>Amount</th><th>Waiver</th><th>Net</th></tr></thead>
                            <tbody>
                                <?php if ($breakdown[$p]): foreach ($breakdown[$p] as $row): ?>
                                    <tr>
                                        <td><?php echo qcv($row['name']); ?></td>
                                        <td><?php echo qc_money($row['base']); ?></td>
                                        <td><?php echo $row['waiver'] > 0 ? '-' . qc_money($row['waiver']) : '—'; ?></td>
                                        <td class="fw-bold"><?php echo qc_money($row['net']); ?></td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="4" class="text-center text-muted">কোনো Fee Item নেই</td></tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="3" class="text-end">Already Paid</th>
                                    <th><?php echo qc_money($paid[$p] ?? 0); ?></th>
                                </tr>
                                <tr>
                                    <th colspan="3" class="text-end text-danger">Due</th>
                                    <th class="text-danger"><?php echo qc_money($due[$p] ?? 0); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Collect Payment Modal -->
        <div class="modal fade" id="qcPayModal<?php echo $p; ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="index.php?page=finance_quick_collection">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="student_id" value="<?php echo (int) $student['id']; ?>">
                        <input type="hidden" name="session_id" value="<?php echo (int) $session_id; ?>">
                        <input type="hidden" name="class_id" value="<?php echo (int) $class_id; ?>">
                        <input type="hidden" name="period" value="<?php echo (int) $p; ?>">
                        <div class="modal-header">
                            <h6 class="modal-title">Collect — <?php echo qcv($label); ?></h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Amount (Full Due, Partial না)</label>
                                <input type="text" class="form-control qc-amount-display" data-due="<?php echo (float) ($due[$p] ?? 0); ?>" value="<?php echo qc_money($due[$p] ?? 0); ?>" readonly>
                            </div>
                            <?php if ($qc_allow_instant_waiver): ?>
                                <div class="mb-3">
                                    <label class="form-label fw-bold text-warning">Waive Amount (ঐচ্ছিক)</label>
                                    <input type="number" step="0.01" min="0" max="<?php echo (float) ($due[$p] ?? 0); ?>"
                                        name="waive_amount" class="form-control qc-waive-amount" data-max="<?php echo (float) ($due[$p] ?? 0); ?>" value="0">
                                    <div class="form-text">এখানে যা লিখবেন সেটা বাদ দিয়ে বাকিটা কালেক্ট হবে — Waiver Report-এ যোগ হয়ে যাবে।</div>
                                </div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Payment Method</label>
                                <select name="payment_method" class="form-select" required>
                                    <option value="Cash">Cash</option>
                                    <option value="bKash">bKash</option>
                                    <option value="Nagad">Nagad</option>
                                    <option value="Bank">Bank</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Trx ID (ঐচ্ছিক)</label>
                                <input type="text" name="trx_id" class="form-control" placeholder="bKash/Nagad/Bank হলে দিন">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" name="collect_payment" class="btn btn-primary" style="background-color:#1e3a8a;border-color:#1e3a8a;">Collect</button>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

<?php endif; ?>

<script>
    function qcFilterStudents() {
        // Session/Class পরিবর্তনে ফর্ম পুরো রিলোড হয়ে যায় (server-side re-query), তাই এখানে কিছু করার নেই।
    }

    // Waive Amount ঘরে টাইপ করলে readonly Amount ঘরটা লাইভ আপডেট হয় (Due - Waiver),
    // আর ক্লিক করলে ভেতরের 0 অটো-সিলেক্ট হয়ে যায় (মুছে টাইপ করতে হয় না)
    document.addEventListener('input', function (e) {
        if (!e.target.classList.contains('qc-waive-amount')) { return; }
        const max = parseFloat(e.target.dataset.max || '0');
        let wval = parseFloat(e.target.value || '0');
        if (isNaN(wval) || wval < 0) { wval = 0; }
        if (wval > max) { wval = max; e.target.value = wval.toFixed(2); }
        const modalBody = e.target.closest('.modal-content');
        const amountEl = modalBody ? modalBody.querySelector('.qc-amount-display') : null;
        if (amountEl) {
            const due = parseFloat(amountEl.dataset.due || '0');
            amountEl.value = Math.max(0, due - wval).toFixed(2);
        }
    });
    document.addEventListener('focus', function (e) {
        if (e.target.matches && e.target.matches('.qc-waive-amount')) { e.target.select(); }
    }, true);
</script>
