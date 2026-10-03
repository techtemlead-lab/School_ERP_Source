<?php
// admin/finance_fees_collection.php - Finance Module > Students Accounts > Student Fees Collection
// Quick Collection থেকে আলাদা: এখানে একটা নির্দিষ্ট Period (Admission বা একটা মাস) সিলেক্ট
// করলে ডানপাশে লাইভ "Student Dues Preview" দেখা যায়, আর Search চাপলে নিচে হেড-ওয়াইজ Full/Partial
// Amount বসিয়ে (Pay Now) Fee Collect করা যায়।

require_once __DIR__ . '/../config/fee_collection_sms.php';    // fc_send_receipt_sms() — কালেকশনের পর Money Receipt লিংকসহ SMS
require_once __DIR__ . '/../config/fees_period_resolver.php';  // sfc_resolve_period() — ajax/fees_dues_preview_ajax.php-এর সাথে শেয়ার করা

function sfcv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function sfc_money($n): string { return number_format((float) $n, 2); }

$sfc_err = $_SESSION['sfc_err'] ?? '';
$sfc_msg = $_SESSION['sfc_msg'] ?? '';
$sfc_last_folio = $_SESSION['sfc_last_folio'] ?? '';
unset($_SESSION['sfc_err'], $_SESSION['sfc_msg'], $_SESSION['sfc_last_folio']);

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি (Quick Collection পেজের সাথে একই টেবিল শেয়ার করা হয়, যাতে
// দুই পেজের Paid হিসাব সবসময় সিঙ্ক থাকে)
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
// পুরনো ইনস্টলেশনে (Quick Collection দিয়ে আগে তৈরি হলে) কলাম না থাকলে নিরাপদে যোগ করা হয়
$sfc_cols = $pdo->query("SHOW COLUMNS FROM finance_fee_collections")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('ac_head_id', $sfc_cols, true)) { $pdo->exec("ALTER TABLE finance_fee_collections ADD COLUMN ac_head_id INT NULL AFTER month"); }
if (!in_array('folio_no', $sfc_cols, true)) { $pdo->exec("ALTER TABLE finance_fee_collections ADD COLUMN folio_no VARCHAR(50) NULL AFTER trx_id"); }
fc_ensure_receipt_token_column($pdo); // receipt_token কলাম — Money Receipt SMS লিংকের জন্য

// ---------------------------------------------------------------
// Instant Waiver অন/অফ আছে কিনা — Money Receipt Settings পেজের টগল থেকে নিয়ন্ত্রিত হয়
// (finance_money_receipt_settings.php-এর সাথে টেবিল শেয়ার্ড, তাই এখানেও নিরাপদে auto-create/alter)
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
$sfc_mrs_cols = $pdo->query("SHOW COLUMNS FROM finance_money_receipt_settings")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('show_waiver', $sfc_mrs_cols, true)) {
    $pdo->exec("ALTER TABLE finance_money_receipt_settings ADD COLUMN show_waiver TINYINT(1) NOT NULL DEFAULT 1 AFTER show_due");
}
if (!in_array('show_waiver_student', $sfc_mrs_cols, true)) {
    $pdo->exec("ALTER TABLE finance_money_receipt_settings ADD COLUMN show_waiver_student TINYINT(1) NOT NULL DEFAULT 1 AFTER show_waiver");
}
if (!in_array('allow_instant_waiver', $sfc_mrs_cols, true)) {
    $pdo->exec("ALTER TABLE finance_money_receipt_settings ADD COLUMN allow_instant_waiver TINYINT(1) NOT NULL DEFAULT 0 AFTER show_waiver_student");
}
$sfc_allow_instant_waiver = (bool) $pdo->query("SELECT allow_instant_waiver FROM finance_money_receipt_settings WHERE id = 1")->fetchColumn();

// finance_student_waivers টেবিল না থাকলে অটো তৈরি (finance_student_waiver.php-এর সাথে শেয়ার্ড) —
// Instant Waiver এই টেবিলেই সেভ হয়, তাই Waiver Report-এ (finance_report_students_waiver.php) সাথে সাথেই দেখা যায়
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
// পুরনো ইনস্টলেশনে folio_no কলাম না থাকলে যোগ করে দেওয়া — Instant Waiver কোন Folio/Receipt-এর
// সাথে দেওয়া হয়েছিল সেটা মনে রাখতে (manual "Students Wise Waiver" পেজ থেকে দেওয়া হলে NULL থাকবে)
$sfc_sw_cols = $pdo->query("SHOW COLUMNS FROM finance_student_waivers")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('folio_no', $sfc_sw_cols, true)) {
    $pdo->exec("ALTER TABLE finance_student_waivers ADD COLUMN folio_no VARCHAR(50) NULL AFTER waiver_value");
}

$MONTHS = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];
$PERIODS = [0 => 'Admission'] + $MONTHS;

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;
// একটা Student + Period-এর জন্য হেড-ওয়াইজ Dues/Waived/Paid/Payable রিসলভ করার হেল্পার
// sfc_resolve_period() এখন config/fees_period_resolver.php-এ আছে (উপরে require করা) —
// কারণ ajax/fees_dues_preview_ajax.php (লাইভ Dues Summary) থেকেও একই ফাংশন দরকার হয়।

// -----------------------------------------------------------
// POST Handler - Save Collection (Full/Partial, head-wise)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_collection'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sfc_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=finance_fees_collection');
        exit();
    }

    $student_id = (int) ($_POST['student_id'] ?? 0);
    $session_id = (int) ($_POST['session_id'] ?? 0);
    $class_id   = (int) ($_POST['class_id'] ?? 0);
    // একাধিক Period (Admission + একাধিক মাস) একসাথে সিলেক্ট করা যায় — তাই এখন array
    $periods_raw = (array) ($_POST['periods'] ?? []);
    $periods = [];
    foreach ($periods_raw as $pv) {
        $pv = (int) $pv;
        if ($pv >= 0 && $pv <= 12 && !in_array($pv, $periods, true)) { $periods[] = $pv; }
    }
    sort($periods);
    $method     = trim($_POST['payment_method'] ?? 'Cash');
    $folio_no   = trim($_POST['folio_no'] ?? '');
    $pay_now    = (array) ($_POST['pay_now'] ?? []); // [period][ac_head_id] => amount
    // instant_waiver শুধু তখনই প্রসেস হবে যখন Money Receipt Settings থেকে টগলটা চালু আছে —
    // টগল বন্ধ থাকা অবস্থায় কেউ ফর্ম ম্যানুপুলেট করে পাঠালেও যাতে ওয়েভার তৈরি না হয়
    $instant_waiver = $sfc_allow_instant_waiver ? (array) ($_POST['instant_waiver'] ?? []) : []; // [period][ac_head_id] => amount

    $fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC")->fetchAll();

    $periods_qs = implode(',', $periods);
    if (!$student_id || !$session_id || !$periods) {
        $_SESSION['sfc_err'] = 'সঠিক Student/Period পাওয়া যায়নি।';
        header('Location: index.php?page=finance_fees_collection&session_id=' . $session_id . '&class_id=' . $class_id . '&student_id=' . $student_id . '&periods=' . urlencode($periods_qs));
        exit();
    }
    if (!in_array($method, ['Cash', 'Bank'], true)) { $method = 'Cash'; }
    if (!$folio_no) { $folio_no = 'FL' . date('ymd') . strtoupper(substr(uniqid(), -5)); }

    $receipt_token = fc_new_receipt_token(); // এই ফলিওর সব রো (সব Period মিলিয়ে) একই token শেয়ার করবে (Money Receipt SMS লিংক)
    // SMS-এ শুধু Fee Head-এর নাম দেখানো হবে, Account Code (যেমন "001") ছাড়া — SMS ক্যারেক্টার বাঁচাতে
    $sfc_head_name_map = array_column($fee_heads, 'ac_head', 'id');

    $ins = $pdo->prepare(
        "INSERT INTO finance_fee_collections (student_id, session_id, period_type, month, ac_head_id, amount, payment_method, folio_no, receipt_token, collected_by, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Paid')"
    );
    $waiver_ins = $pdo->prepare(
        "INSERT INTO finance_student_waivers (student_id, session_id, class_id, month, ac_head_id, waiver_type, waiver_value, folio_no, status)
         VALUES (?, ?, ?, ?, ?, 'Fixed', ?, ?, 'Active')"
    );
    $count = 0;
    $collected_total = 0.0;
    $waived_total = 0.0;
    $collected_head_names = [];
    $collected_period_names = [];
    foreach ($periods as $period) {
        $items = sfc_resolve_period($pdo, $student_id, $session_id, $class_id, $period, $fee_heads);
        $period_type = $period === 0 ? 'Admission' : 'Monthly';
        $period_had_collection = false;
        foreach ($items as $it) {
            if ($it['ac_head_id'] <= 0) { continue; } // lump-sum সারি বাদ
            $dues_left = $it['dues'];

            // ---- Instant Waiver (ঐচ্ছিক, টগল চালু থাকলেই) — dues-এর বেশি ওয়েভার দেওয়া যাবে না,
            // আর Pay Now ক্ল্যাম্প হওয়ার আগেই dues থেকে বাদ দেওয়া হচ্ছে যাতে দুটো মিলে dues-এর বেশি না হয়ে যায়
            $wamt = (float) ($instant_waiver[$period][$it['ac_head_id']] ?? 0);
            if ($wamt > 0) {
                $wamt = min($wamt, $dues_left);
                if ($wamt > 0) {
                    $waiver_ins->execute([$student_id, $session_id, $class_id, $period, $it['ac_head_id'], $wamt, $folio_no]);
                    $dues_left -= $wamt;
                    $waived_total += $wamt;
                }
            }

            $amt = (float) ($pay_now[$period][$it['ac_head_id']] ?? 0);
            if ($amt <= 0) { continue; }
            $amt = min($amt, $dues_left); // partial/full — বাকি dues-এর বেশি নেওয়া যাবে না (সার্ভার-সাইড ক্ল্যাম্প)
            if ($amt <= 0) { continue; }

            $ins->execute([$student_id, $session_id, $period_type, $period, $it['ac_head_id'], $amt, $method, $folio_no, $receipt_token, $_SESSION['admin_user'] ?? '']);
            $new_id = (int) $pdo->lastInsertId();
            $pdo->prepare("UPDATE finance_fee_collections SET receipt_no = ? WHERE id = ?")
                ->execute(['RC' . date('ym') . '-' . str_pad((string) $new_id, 5, '0', STR_PAD_LEFT), $new_id]);
            $count++;
            $collected_total += $amt;
            $collected_head_names[] = $sfc_head_name_map[$it['ac_head_id']] ?? $it['name'];
            $period_had_collection = true;
        }
        if ($period_had_collection) { $collected_period_names[] = $PERIODS[$period]; }
    }
    if ($waived_total > 0) {
        $sfc_stu_stmt2 = $pdo->prepare("SELECT student_id, name_en FROM students WHERE id = ?");
        $sfc_stu_stmt2->execute([$student_id]);
        $sfc_stu2 = $sfc_stu_stmt2->fetch();
        $sfc_stu_lbl2 = $sfc_stu2 ? ($sfc_stu2['student_id'] . ' - ' . $sfc_stu2['name_en']) : "#{$student_id}";
        sec_log_activity($pdo, 'Finance', 'Create', "Instant Waiver (Fees Collection থেকে): {$sfc_stu_lbl2}, পরিমাণ ৳" . sfc_money($waived_total) . " (Folio: {$folio_no})");
    }

    if ($count > 0) {
        $sfc_stu_stmt = $pdo->prepare("SELECT student_id, name_en FROM students WHERE id = ?");
        $sfc_stu_stmt->execute([$student_id]);
        $sfc_stu = $sfc_stu_stmt->fetch();
        $sfc_stu_lbl = $sfc_stu ? ($sfc_stu['student_id'] . ' - ' . $sfc_stu['name_en']) : "#{$student_id}";
        sec_log_activity($pdo, 'Finance', 'Create', "Student Fees Collection: {$sfc_stu_lbl}, Period: " . implode(', ', $collected_period_names) . ", পরিমাণ ৳" . sfc_money($collected_total) . " (Folio: {$folio_no})");
        fc_send_receipt_sms($pdo, $student_id, $folio_no, $receipt_token, $collected_total, $collected_head_names);
    }

    $sfc_waiver_note = $waived_total > 0 ? ' এবং ৳' . sfc_money($waived_total) . ' Waiver দেওয়া হয়েছে।' : '';
    $_SESSION['sfc_msg'] = $count > 0
        ? $count . ' টা Fee Head-এ (' . implode(', ', $collected_period_names) . ') মোট ' . sfc_money($collected_total) . ' টাকা কালেক্ট করা হয়েছে। (Folio: ' . $folio_no . ')' . $sfc_waiver_note
        : ($waived_total > 0
            ? 'কোনো Amount কালেক্ট হয়নি, তবে ৳' . sfc_money($waived_total) . ' Waiver দেওয়া হয়েছে।'
            : 'কোনো Amount দেওয়া হয়নি — কিছু কালেক্ট হয়নি।');
    if ($count > 0) { $_SESSION['sfc_last_folio'] = $folio_no; }
    header('Location: index.php?page=finance_fees_collection&session_id=' . $session_id . '&class_id=' . $class_id . '&student_id=' . $student_id . '&periods=' . urlencode($periods_qs) . '&do_search=1');
    exit();
}

// -----------------------------------------------------------
// Filters (GET)
// -----------------------------------------------------------
$sessions  = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes   = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC")->fetchAll();

$session_id = (int) ($_GET['session_id'] ?? $current_session_id);
$class_id   = (int) ($_GET['class_id'] ?? 0);
$student_id = (int) ($_GET['student_id'] ?? 0);
// একাধিক Period (Admission + একাধিক মাস) একসাথে সিলেক্ট করার জন্য array —
// ?periods=0,3,4 (comma-separated, filter form থেকে) অথবা ?periods[]=0&periods[]=3 দুটোই সাপোর্ট করে
$periods = [];
if (isset($_GET['periods'])) {
    $periods_raw = is_array($_GET['periods']) ? $_GET['periods'] : explode(',', (string) $_GET['periods']);
    foreach ($periods_raw as $pv) {
        if ($pv === '' || $pv === null) { continue; }
        $pv = (int) $pv;
        if ($pv >= 0 && $pv <= 12 && !in_array($pv, $periods, true)) { $periods[] = $pv; }
    }
    sort($periods);
} elseif (isset($_GET['period']) && $_GET['period'] !== '') {
    // পুরনো লিংক/বুকমার্ক (single period) ব্যাকওয়ার্ড-কম্প্যাটিবিলিটি
    $periods = [(int) $_GET['period']];
}
$txn_date   = trim($_GET['txn_date'] ?? '') ?: date('Y-m-d');
$folio_no_v = trim($_GET['folio_no'] ?? '');
$do_search  = isset($_GET['do_search']);

$students = [];
if ($session_id) {
    $sql = "SELECT id, student_id, roll_no, name_en FROM students WHERE session_id = ? AND status = 'Active'";
    $params = [$session_id];
    if ($class_id) { $sql .= " AND class_id = ?"; $params[] = $class_id; }
    $ffc_where = []; $ffc_params = [];
    branch_apply_filter($ffc_where, $ffc_params);
    if ($ffc_where) { $sql .= ' AND ' . implode(' AND ', $ffc_where); $params = array_merge($params, $ffc_params); }
    $sql .= " ORDER BY CAST(roll_no AS UNSIGNED) ASC, roll_no ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $students = $st->fetchAll();
}

$student = null;
if ($student_id) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch() ?: null;
}

// একাধিক Period সিলেক্ট করা থাকলে প্রতিটার items আলাদা করে রিসলভ করে "period" key সহ একসাথে জমা করা হয়
$items_by_period = []; // [period => items[]]
$items = []; // সব Period মিলিয়ে ফ্ল্যাট লিস্ট (Period label সহ) — Dues Preview/Summary টেবিলে দেখানোর জন্য
$total_dues = 0.0; $total_waived = 0.0; $total_paid = 0.0; $total_payable = 0.0;
if ($student && $periods) {
    foreach ($periods as $period) {
        $p_items = sfc_resolve_period($pdo, (int) $student['id'], $session_id, (int) $student['class_id'], $period, $fee_heads);
        $items_by_period[$period] = $p_items;
        foreach ($p_items as $it) {
            $it['period'] = $period;
            $it['period_name'] = $PERIODS[$period];
            $items[] = $it;
            $total_dues    += $it['dues'];
            $total_waived  += $it['waived'];
            $total_paid    += $it['paid'];
            $total_payable += $it['payable'];
        }
    }
}
?>

<div class="card-custom mb-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="btn btn-sm" style="background-color:#f0ad4e;color:#fff;"><i class="fa-solid fa-bars"></i></span>
        <div>
            <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-bs-toggle="collapse" data-bs-target="#sfcFilterBody"><i class="fa-solid fa-chevron-up"></i></button>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="window.location='index.php?page=finance_fees_collection'"><i class="fa-solid fa-xmark"></i></button>
        </div>
    </div>

    <div id="sfcFilterBody" class="collapse show">
        <form method="GET" action="index.php" id="sfcFilterForm">
            <input type="hidden" name="page" value="finance_fees_collection">
            <input type="hidden" name="periods" id="sfcPeriodsField" value="<?php echo sfcv(implode(',', $periods)); ?>">

            <div class="row">
                <div class="col-lg-7">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Session</label>
                        <div class="col-sm-7">
                            <select name="session_id" class="form-select" onchange="sfcFilterChanged()">
                                <?php foreach ($sessions as $s): ?>
                                    <option value="<?php echo (int) $s['id']; ?>" <?php echo $session_id === (int) $s['id'] ? 'selected' : ''; ?>><?php echo sfcv($s['session_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Class</label>
                        <div class="col-sm-7">
                            <select name="class_id" class="form-select" onchange="sfcFilterChanged()">
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $cl): ?>
                                    <option value="<?php echo (int) $cl['id']; ?>" <?php echo $class_id === (int) $cl['id'] ? 'selected' : ''; ?>><?php echo sfcv($cl['class_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Student <span class="text-danger">*</span></label>
                        <div class="col-sm-7 position-relative">
                            <select name="student_id" id="sfcStudentSelect" class="form-select d-none">
                                <option value="">Select Student</option>
                                <?php foreach ($students as $s): ?>
                                    <option value="<?php echo (int) $s['id']; ?>" <?php echo $student_id === (int) $s['id'] ? 'selected' : ''; ?>>
                                        <?php echo sfcv($s['student_id'] . ' - ' . $s['name_en'] . ' - ' . $s['roll_no']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" id="sfcStudentSearch" class="form-control" autocomplete="off"
                                placeholder="ID, Name বা Roll দিয়ে সার্চ করুন"
                                value="<?php echo $student ? sfcv($student['student_id'] . ' - ' . $student['name_en'] . ' - ' . $student['roll_no']) : ''; ?>">
                            <div id="sfcStudentSuggest" class="list-group position-absolute w-100" style="z-index:1050; max-height:260px; overflow-y:auto; display:none; top:100%;"></div>
                        </div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Transaction Date</label>
                        <div class="col-sm-7">
                            <input type="date" name="txn_date" class="form-control" value="<?php echo sfcv($txn_date); ?>">
                        </div>
                    </div>

                    <div class="row mb-2">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Admission/Monthly</label>
                        <div class="col-sm-7">
                            <div class="small text-muted mb-1">একাধিক মাস একসাথে সিলেক্ট করা যাবে — সব ক্লিক করা শেষে নিচের <strong>Search</strong> বাটনে চাপুন।</div>
                            <div class="row row-cols-2 g-1">
                                <?php foreach ($PERIODS as $p_num => $p_name): $is_sel = in_array($p_num, $periods, true); ?>
                                    <div class="col">
                                        <div class="d-flex align-items-center mb-2">
                                            <button type="button" class="btn btn-sm fw-bold text-white sfc-period-btn" data-period="<?php echo $p_num; ?>"
                                                style="width:44px; background-color:<?php echo $is_sel ? '#1e3a8a' : '#e74c3c'; ?>;"
                                                onclick="sfcTogglePeriod(<?php echo $p_num; ?>, this)"><?php echo $is_sel ? 'Yes' : 'No'; ?></button>
                                            <span class="ms-2"><?php echo sfcv($p_name); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Folio No</label>
                        <div class="col-sm-7">
                            <input type="text" name="folio_no" class="form-control" placeholder="You can left this blank for auto generation" value="<?php echo sfcv($folio_no_v); ?>">
                        </div>
                    </div>
                </div>

                <div class="col-lg-5" id="sfcDuesPanel">
                    <?php if ($student && $periods): ?>
                        <div class="mb-2 fw-bold"><i class="fa-solid fa-circle-info me-1"></i> Student Dues Preview (<?php echo sfcv(implode(', ', array_map(fn($p) => $PERIODS[$p], $periods))); ?>)</div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm align-middle">
                                <thead class="table-light">
                                    <tr><th>Period</th><th>Fees Type</th><th class="text-danger">Dues</th><th class="text-warning">Waived</th><th class="text-success">Paid</th><th class="text-danger">Payable</th></tr>
                                </thead>
                                <tbody>
                                    <?php if ($items): foreach ($items as $it): ?>
                                        <tr>
                                            <td class="text-nowrap"><?php echo sfcv($it['period_name']); ?></td>
                                            <td><?php echo sfcv($it['name']); ?></td>
                                            <td class="fw-bold"><?php echo sfc_money($it['dues']); ?></td>
                                            <td class="text-warning"><?php echo sfc_money($it['waived']); ?></td>
                                            <td class="text-success"><?php echo sfc_money($it['paid']); ?></td>
                                            <td class="fw-bold text-danger"><?php echo sfc_money($it['dues']); // Preview-তে Payable = এখনো যা দিতে হবে (Paid বাদ দিয়ে) ?></td>
                                        </tr>
                                    <?php endforeach; else: ?>
                                        <tr><td colspan="6" class="text-center text-muted">এই Period(গুলো)-এ কোনো Fee সেট করা নেই</td></tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="fw-bold">
                                        <td class="text-end" colspan="2">Total :</td>
                                        <td><?php echo sfc_money($total_dues); ?></td>
                                        <td class="text-warning"><?php echo sfc_money($total_waived); ?></td>
                                        <td class="text-success"><?php echo sfc_money($total_paid); ?></td>
                                        <td class="text-danger"><?php echo sfc_money($total_dues); ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <table class="table table-bordered table-sm mt-2">
                            <thead class="table-light"><tr><th>Father's Name</th><th>Mother's Name</th><th>Guardian's Contact</th></tr></thead>
                            <tbody>
                                <tr>
                                    <td><?php echo sfcv($student['father_name']); ?></td>
                                    <td><?php echo sfcv($student['mother_name']); ?></td>
                                    <td><?php echo sfcv($student['guardian_phone'] ?: '—'); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    <?php elseif ($student_id): ?>
                        <div class="alert alert-info py-2 small">ডানপাশে Dues দেখতে উপরে থেকে একটা বা একাধিক Admission/Month সিলেক্ট করুন।</div>
                    <?php endif; ?>
                </div>
            </div>

            <hr>
            <div class="text-center">
                <button type="submit" name="do_search" value="1" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Search</button>
                <a href="index.php?page=finance_fees_collection" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php if ($sfc_msg): ?>
    <div class="alert alert-success py-2 d-flex justify-content-between align-items-center">
        <span><?php echo sfcv($sfc_msg); ?></span>
        <?php if ($sfc_last_folio): ?>
            <a href="print_money_receipt.php?folio_no=<?php echo urlencode($sfc_last_folio); ?>&back=finance_fees_collection" target="_blank" class="btn btn-sm btn-primary fw-bold"><i class="fa-solid fa-print"></i> Print Money Receipt</a>
        <?php endif; ?>
    </div>
    <?php if ($sfc_last_folio): ?>
        <!-- কালেকশন সেভ হওয়ার সাথে সাথেই অটোমেটিক Money Receipt এর প্রিন্ট ডায়ালগ
             খুলে যাবে — আলাদা করে বাটনে ক্লিক করতে হবে না। নতুন ট্যাব/পপআপ না খুলে
             একটা লুকানো iframe এ রিসিটটা লোড করে প্রিন্ট করা হয়, কারণ পেজ লোডের
             সময় window.open() ব্রাউজারের পপআপ ব্লকারে আটকে যেত। উপরের বাটনটা থেকে
             গেছে যাতে দরকার হলে আবার প্রিন্ট করা যায়। -->
        <iframe id="sfcAutoPrint" src="print_money_receipt.php?folio_no=<?php echo urlencode($sfc_last_folio); ?>&auto=1"
                style="position:fixed; right:0; bottom:0; width:1px; height:1px; border:0; opacity:0;" aria-hidden="true"></iframe>
    <?php endif; ?>
<?php endif; ?>
<?php if ($sfc_err): ?><div class="alert alert-danger py-2"><?php echo sfcv($sfc_err); ?></div><?php endif; ?>

<?php if ($do_search && $student && $periods): ?>

    <div class="alert alert-warning" id="sfcPayWarn">
        <i class="fa-solid fa-circle-info me-1"></i> <strong>Warning!</strong> Student Fees Collection will be applicable for only current students. You can,t proceed with admission fees / specific month. You can collect head wise full / partial amount here.
    </div>

    <form method="POST" action="index.php?page=finance_fees_collection" id="sfcPayForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="student_id" value="<?php echo (int) $student['id']; ?>">
        <input type="hidden" name="session_id" value="<?php echo (int) $session_id; ?>">
        <input type="hidden" name="class_id" value="<?php echo (int) $student['class_id']; ?>">
        <?php foreach ($periods as $p): ?>
            <input type="hidden" name="periods[]" value="<?php echo (int) $p; ?>">
        <?php endforeach; ?>
        <input type="hidden" name="folio_no" value="<?php echo sfcv($folio_no_v); ?>">

        <div class="card-custom mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold"><i class="fa-solid fa-circle-info me-1"></i> Student Fees Collection (<?php echo sfcv(implode(', ', array_map(fn($p) => $PERIODS[$p], $periods))); ?>)</span>
                <button type="button" class="btn btn-sm fw-bold" style="background-color:#f0ad4e;color:#fff;" data-bs-toggle="collapse" data-bs-target="#sfcDuesSummary">Dues Summary</button>
            </div>

            <div id="sfcDuesSummary" class="collapse mb-3">
                <table class="table table-bordered table-sm">
                    <thead class="table-light"><tr><th>Period</th><th>Fees Type</th><th>Dues</th><th>Waived</th><th>Paid</th><th>Payable</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td><?php echo sfcv($it['period_name']); ?></td>
                                <td><?php echo sfcv($it['name']); ?></td>
                                <td><?php echo sfc_money($it['dues']); ?></td>
                                <td><?php echo sfc_money($it['waived']); ?></td>
                                <td><?php echo sfc_money($it['paid']); ?></td>
                                <td><?php echo sfc_money($it['payable']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php foreach ($periods as $p): $p_items = $items_by_period[$p] ?? []; ?>
                <div class="fw-bold mt-3 mb-1" style="background-color:#eef2ff; padding:6px 10px; border-radius:4px;">
                    <?php echo sfcv($PERIODS[$p]); ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0 sfc-items-table">
                        <thead class="table-light">
                            <tr>
                                <th>Fees Type</th>
                                <th class="text-danger">Dues</th>
                                <th class="text-warning">Waived</th>
                                <th class="text-success">Paid</th>
                                <?php if ($sfc_allow_instant_waiver): ?><th class="text-warning">Waiver</th><?php endif; ?>
                                <th class="text-danger">Payable</th>
                                <th class="text-primary">Pay Now</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($p_items as $it): ?>
                                <tr>
                                    <td><?php echo sfcv($it['name']); ?></td>
                                    <td class="fw-bold sfc-dues"><?php echo sfc_money($it['dues']); ?></td>
                                    <td class="text-warning"><?php echo sfc_money($it['waived']); ?></td>
                                    <td class="text-success"><?php echo sfc_money($it['paid']); ?></td>
                                    <?php if ($sfc_allow_instant_waiver): ?>
                                        <td style="width:130px;">
                                            <?php if ($it['ac_head_id'] > 0 && $it['dues'] > 0): ?>
                                                <input type="number" step="0.01" min="0" max="<?php echo $it['dues']; ?>"
                                                    name="instant_waiver[<?php echo (int) $p; ?>][<?php echo (int) $it['ac_head_id']; ?>]" class="form-control form-control-sm sfc-instant-waiver"
                                                    data-max="<?php echo $it['dues']; ?>" value="0" placeholder="Waiver">
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="fw-bold text-danger sfc-payable" data-base="<?php echo $it['payable']; ?>"><?php echo sfc_money($it['payable']); ?></td>
                                    <td style="width:160px;">
                                        <?php if ($it['ac_head_id'] > 0 && $it['dues'] > 0): ?>
                                            <input type="number" step="0.01" min="0" max="<?php echo $it['dues']; ?>"
                                                name="pay_now[<?php echo (int) $p; ?>][<?php echo (int) $it['ac_head_id']; ?>]" class="form-control sfc-pay-now"
                                                data-max="<?php echo $it['dues']; ?>" value="0">
                                        <?php elseif ($it['ac_head_id'] > 0 && round($it['paid'] - $it['payable'], 2) > 0): ?>
                                            <?php // Payable-এর চেয়ে বেশি আগেই Paid (যেমন Collection-এর পরে Waiver দেওয়া হয়েছে) — কেন ঘর নেই সেটা বোঝানো ?>
                                            <span class="badge bg-info text-dark" title="Payable-এর চেয়ে বেশি আগেই Paid — নতুন Collection লাগবে না">Excess <?php echo sfc_money($it['paid'] - $it['payable']); ?></span>
                                        <?php elseif ($it['ac_head_id'] > 0): ?>
                                            <span class="badge bg-success">Fully Paid</span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$p_items): ?>
                                <tr><td colspan="<?php echo $sfc_allow_instant_waiver ? 7 : 6; ?>" class="text-center text-muted py-2">কোনো Fee Item নেই</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>

            <div class="table-responsive mt-2">
                <table class="table table-bordered align-middle mb-0">
                    <tfoot>
                        <tr class="fw-bold">
                            <td class="text-end">Total (সব Period মিলিয়ে) :</td>
                            <td><?php echo sfc_money($total_dues); ?></td>
                            <td class="text-warning"><?php echo sfc_money($total_waived); ?></td>
                            <td class="text-success"><?php echo sfc_money($total_paid); ?></td>
                            <?php if ($sfc_allow_instant_waiver): ?><td class="text-warning" id="sfcWaiverTotal">0</td><?php endif; ?>
                            <td class="text-danger sfc-payable-total" data-base="<?php echo $total_payable; ?>"><?php echo sfc_money($total_payable); ?></td>
                            <td class="text-primary" id="sfcPayNowTotal">0</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="card-custom mb-3">
            <div class="row align-items-center">
                <label class="col-sm-2 col-form-label fw-bold text-end">Payment Type</label>
                <div class="col-sm-6">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="payment_method" id="sfcPayCash" value="Cash" checked>
                        <label class="form-check-label" for="sfcPayCash">Cash</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="payment_method" id="sfcPayBank" value="Bank">
                        <label class="form-check-label" for="sfcPayBank">Bank</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center mb-4">
            <button type="submit" name="save_collection" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Submit</button>
            <a href="index.php?page=finance_fees_collection" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
        </div>
    </form>

<?php elseif ($do_search): ?>
    <div class="alert alert-info">Student এবং Admission/Monthly Period সিলেক্ট করে আবার Search করুন।</div>
<?php endif; ?>

<script>
    // ---- Multi-select Period toggle (2-column Yes/No বাটন) — ক্লিক করলে শুধু টগল হবে,
    // সাথে সাথে ফর্ম সাবমিট হবে না; সব সিলেক্ট শেষে ইউজার "Search" চাপবে ----
    (function () {
        var initial = (document.getElementById('sfcPeriodsField').value || '')
            .split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
        window.sfcSelectedPeriods = initial;
    })();

    function sfcTogglePeriod(p, btn) {
        p = String(p);
        var idx = window.sfcSelectedPeriods.indexOf(p);
        if (idx === -1) {
            window.sfcSelectedPeriods.push(p);
            btn.style.backgroundColor = '#1e3a8a';
            btn.textContent = 'Yes';
        } else {
            window.sfcSelectedPeriods.splice(idx, 1);
            btn.style.backgroundColor = '#e74c3c';
            btn.textContent = 'No';
        }
        document.getElementById('sfcPeriodsField').value = window.sfcSelectedPeriods.join(',');
        sfcLoadDuesPreview(); // ক্লিক করার সাথে সাথেই ডানপাশে Dues Summary আপডেট (পেজ রিলোড ছাড়াই)
    }

    // ---- ডানপাশের "Student Dues Preview" — Student সিলেক্ট করলেই Admission + ১২ মাসের সব ডাটা
    // একবারে এনে ব্রাউজারে ক্যাশ করা হয়। এরপর মাস টগল করলে সার্ভারে যেতে হয় না — সাথে সাথে (০ দেরিতে) দেখায় ----
    var sfcCache = {}; // "studentId|sessionId" => { data, promise }
    var SFC_PERIOD_NAMES = <?php echo json_encode($PERIODS, JSON_UNESCAPED_UNICODE); ?>;

    function sfcEsc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function sfcFmt(n) {
        return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function sfcCurrentIds() {
        var sel = document.getElementById('sfcStudentSelect');
        var sessionSel = document.querySelector('#sfcFilterForm select[name="session_id"]');
        return { studentId: sel ? sel.value : '', sessionId: sessionSel ? sessionSel.value : '' };
    }

    // সব Period-এর ডাটা আনা (একই Student+Session-এর জন্য একবারই; চলমান রিকোয়েস্ট থাকলে সেটাই ফেরত দেয়)
    function sfcFetchAll(studentId, sessionId) {
        var key = studentId + '|' + sessionId;
        if (sfcCache[key]) { return sfcCache[key].promise; }
        var entry = { data: null, promise: null };
        entry.promise = fetch('ajax/fees_dues_preview_ajax.php?all=1&student_id=' + encodeURIComponent(studentId)
                + '&session_id=' + encodeURIComponent(sessionId), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success || !d.by_period) { throw new Error(d.message || 'Dues আনা যায়নি।'); }
                entry.data = d;
                return d;
            })
            .catch(function (err) { delete sfcCache[key]; throw err; });
        sfcCache[key] = entry;
        return entry.promise;
    }

    // ক্যাশ করা ডাটা থেকে বেছে নেওয়া Period-গুলোর টেবিল বানানো (সার্ভার ছাড়াই)
    function sfcBuildFromCache(data, periodsArr) {
        var nums = periodsArr.map(Number).sort(function (a, b) { return a - b; });
        var rows = [], t = { dues: 0, waived: 0, paid: 0, payable: 0 };
        nums.forEach(function (p) {
            (data.by_period[p] || []).forEach(function (r) {
                rows.push({ period_name: r.period_name, name: r.name, dues: sfcFmt(r.dues), waived: sfcFmt(r.waived), paid: sfcFmt(r.paid), payable: sfcFmt(r.payable) });
                t.dues += r.dues; t.waived += r.waived; t.paid += r.paid; t.payable += r.payable;
            });
        });
        return {
            title: nums.map(function (p) { return SFC_PERIOD_NAMES[p]; }).join(', '),
            rows: rows,
            totals: { dues: sfcFmt(t.dues), waived: sfcFmt(t.waived), paid: sfcFmt(t.paid), payable: sfcFmt(t.payable) },
            student: data.student
        };
    }

    function sfcRenderPreview(d) {
        var panel = document.getElementById('sfcDuesPanel');
        var h = '<div class="mb-2 fw-bold"><i class="fa-solid fa-circle-info me-1"></i> Student Dues Preview (' + sfcEsc(d.title) + ')</div>';
        h += '<div class="table-responsive"><table class="table table-bordered table-sm align-middle">';
        h += '<thead class="table-light"><tr><th>Period</th><th>Fees Type</th><th class="text-danger">Dues</th><th class="text-warning">Waived</th><th class="text-success">Paid</th><th class="text-danger">Payable</th></tr></thead><tbody>';
        if (d.rows.length) {
            d.rows.forEach(function (r) {
                h += '<tr><td class="text-nowrap">' + sfcEsc(r.period_name) + '</td>'
                   + '<td>' + sfcEsc(r.name) + '</td>'
                   + '<td class="fw-bold">' + sfcEsc(r.dues) + '</td>'
                   + '<td class="text-warning">' + sfcEsc(r.waived) + '</td>'
                   + '<td class="text-success">' + sfcEsc(r.paid) + '</td>'
                   + '<td class="fw-bold text-danger">' + sfcEsc(r.payable) + '</td></tr>';
            });
        } else {
            h += '<tr><td colspan="6" class="text-center text-muted">এই Period(গুলো)-এ কোনো Fee সেট করা নেই</td></tr>';
        }
        h += '</tbody><tfoot><tr class="fw-bold"><td class="text-end" colspan="2">Total :</td>'
           + '<td>' + sfcEsc(d.totals.dues) + '</td>'
           + '<td class="text-warning">' + sfcEsc(d.totals.waived) + '</td>'
           + '<td class="text-success">' + sfcEsc(d.totals.paid) + '</td>'
           + '<td class="text-danger">' + sfcEsc(d.totals.payable) + '</td></tr></tfoot></table></div>';
        h += '<table class="table table-bordered table-sm mt-2"><thead class="table-light">'
           + '<tr><th>Father\'s Name</th><th>Mother\'s Name</th><th>Guardian\'s Contact</th></tr></thead><tbody><tr>'
           + '<td>' + sfcEsc(d.student.father) + '</td>'
           + '<td>' + sfcEsc(d.student.mother) + '</td>'
           + '<td>' + sfcEsc(d.student.guardian || '—') + '</td>'
           + '</tr></tbody></table>';
        h += '<div class="small text-muted mt-1">নিচে Pay Now বসিয়ে কালেক্ট করতে <strong>Search</strong> বাটনে চাপুন।</div>';
        panel.innerHTML = h;
    }

    function sfcLoadDuesPreview() {
        var panel = document.getElementById('sfcDuesPanel');
        if (!panel) { return; }
        var ids = sfcCurrentIds(), studentId = ids.studentId, sessionId = ids.sessionId;
        var periods = window.sfcSelectedPeriods.slice();

        if (!studentId) {
            panel.innerHTML = '<div class="alert alert-info py-2 small">আগে একটা Student সিলেক্ট করুন।</div>';
            return;
        }
        var key = studentId + '|' + sessionId;
        var cached = sfcCache[key] && sfcCache[key].data;

        if (cached) { // ক্যাশে আছে — সাথে সাথে দেখাও, কোনো লোডিং নেই
            if (!periods.length) {
                panel.innerHTML = '<div class="alert alert-info py-2 small">ডানপাশে Dues দেখতে উপরে থেকে একটা বা একাধিক Admission/Month সিলেক্ট করুন।</div>';
                return;
            }
            sfcRenderPreview(sfcBuildFromCache(cached, periods));
            return;
        }

        // ক্যাশে নেই — একবার আনো (মাস সিলেক্ট না থাকলেও আগেভাগে আনা হয়, যাতে ক্লিকের সময় রেডি থাকে)
        if (!periods.length) {
            panel.innerHTML = '<div class="alert alert-info py-2 small">ডানপাশে Dues দেখতে উপরে থেকে একটা বা একাধিক Admission/Month সিলেক্ট করুন।</div>';
        } else {
            panel.innerHTML = '<div class="text-muted small py-2"><i class="fa-solid fa-spinner fa-spin me-1"></i> Dues লোড হচ্ছে...</div>';
        }
        sfcFetchAll(studentId, sessionId).then(function () {
            var now = sfcCurrentIds();
            if (now.studentId + '|' + now.sessionId === key) { sfcLoadDuesPreview(); } // এর মধ্যে Student বদলে গেলে এটা বাদ
        }).catch(function (err) {
            var now = sfcCurrentIds();
            if (now.studentId + '|' + now.sessionId !== key) { return; }
            panel.innerHTML = '<div class="alert alert-warning py-2 small">' + sfcEsc(err.message || 'Dues আনা যায়নি — আবার চেষ্টা করুন।') + '</div>';
        });
    }

    // ---- Session / Class বদলালে পেজ রিলোড ছাড়াই Student তালিকা বদলানো ----
    var sfcStudentsReq = 0;
    function sfcFilterChanged() {
        var sessionSel = document.querySelector('#sfcFilterForm select[name="session_id"]');
        var classSel = document.querySelector('#sfcFilterForm select[name="class_id"]');
        var searchInput = document.getElementById('sfcStudentSearch');
        // আগের Search-এর পেমেন্ট ফর্ম পুরনো Student-এর — ফিল্টার বদলালে সরিয়ে দেওয়া হয় (আবার Search চাপলে আসবে)
        ['sfcPayForm', 'sfcPayWarn'].forEach(function (id) { var el = document.getElementById(id); if (el) { el.style.display = 'none'; } });
        if (searchInput) { searchInput.value = ''; searchInput.placeholder = 'Student লোড হচ্ছে...'; }
        var hidden = document.getElementById('sfcStudentSelect');
        if (hidden) { hidden.value = ''; }
        sfcLoadDuesPreview();

        var reqId = ++sfcStudentsReq;
        fetch('ajax/fees_students_ajax.php?session_id=' + encodeURIComponent(sessionSel ? sessionSel.value : '')
                + '&class_id=' + encodeURIComponent(classSel ? classSel.value : ''), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (reqId !== sfcStudentsReq) { return; } // এর মধ্যে আবার বদলানো হয়েছে — পুরনো উত্তর বাদ
                if (!d.success) { throw new Error(d.message || 'Student তালিকা আনা যায়নি।'); }
                window.sfcSetStudents(d.students);
                if (searchInput) { searchInput.placeholder = 'ID, Name বা Roll দিয়ে সার্চ করুন'; }
            })
            .catch(function (err) {
                if (reqId !== sfcStudentsReq) { return; }
                if (searchInput) { searchInput.placeholder = 'Student আনা যায়নি — Class আবার সিলেক্ট করুন'; }
            });
    }

    // Pay Now / Waiver ঘরে ক্লিক/ফোকাস করলে ভেতরের "0" সিলেক্ট হয়ে যায় — যাতে আলাদা করে
    // মুছতে না হয়ে সরাসরি টাইপ করলেই সেটা রিপ্লেস হয়ে যায়
    document.addEventListener('focus', function (e) {
        if (e.target.matches && e.target.matches('.sfc-pay-now, .sfc-instant-waiver')) {
            e.target.select();
        }
    }, true);

    function sfcRecalcTotals() {
        let total = 0;
        document.querySelectorAll('.sfc-pay-now').forEach(inp => { total += parseFloat(inp.value || '0') || 0; });
        const totalEl = document.getElementById('sfcPayNowTotal');
        if (totalEl) { totalEl.textContent = total.toFixed(2); }
        let wtotal = 0;
        document.querySelectorAll('.sfc-instant-waiver').forEach(inp => { wtotal += parseFloat(inp.value || '0') || 0; });
        const wtotalEl = document.getElementById('sfcWaiverTotal');
        if (wtotalEl) { wtotalEl.textContent = wtotal.toFixed(2); }
        // Waiver দেওয়ার পর Total Payable কত থাকবে সেটাও লাইভ দেখানো হচ্ছে (base payable - সব Waiver)
        const payableTotalEl = document.querySelector('.sfc-payable-total');
        if (payableTotalEl) {
            const basePayableTotal = parseFloat(payableTotalEl.dataset.base || '0');
            payableTotalEl.textContent = Math.max(0, basePayableTotal - wtotal).toFixed(2);
        }
    }

    document.addEventListener('input', function (e) {
        // Waiver ঘরে টাইপ করলে dues থেকে সেই অংশ বাদ দিয়ে Pay Now-এর max/value আপডেট হয়,
        // যাতে Waiver + Pay Now মিলিয়ে dues-এর বেশি না হয়ে যায়; আর Payable ঘরটাও লাইভ আপডেট হয়
        if (e.target.classList.contains('sfc-instant-waiver')) {
            const dues = parseFloat(e.target.dataset.max || '0');
            let wval = parseFloat(e.target.value || '0');
            if (isNaN(wval) || wval < 0) { wval = 0; }
            if (wval > dues) { wval = dues; e.target.value = wval.toFixed(2); }
            const row = e.target.closest('tr');
            const payInp = row ? row.querySelector('.sfc-pay-now') : null;
            if (payInp) {
                const remaining = Math.max(0, dues - wval);
                payInp.dataset.max = remaining;
                if (parseFloat(payInp.value || '0') > remaining) { payInp.value = remaining.toFixed(2); }
            }
            const payableCell = row ? row.querySelector('.sfc-payable') : null;
            if (payableCell) {
                const basePayable = parseFloat(payableCell.dataset.base || '0');
                payableCell.textContent = Math.max(0, basePayable - wval).toFixed(2);
            }
            sfcRecalcTotals();
            return;
        }
        if (!e.target.classList.contains('sfc-pay-now')) { return; }
        const max = parseFloat(e.target.dataset.max || '0');
        let val = parseFloat(e.target.value || '0');
        if (isNaN(val) || val < 0) { val = 0; }
        if (val > max) { val = max; e.target.value = val.toFixed(2); }
        sfcRecalcTotals();
    });

    // ---- Student search overlay: ID/Name/Roll দিয়ে সার্চ করে সিলেক্ট করলে হিডেন select
    // এ ভ্যালু বসে এবং ফিল্টার ফর্ম অটো-সাবমিট হয় (আগে সরাসরি dropdown-এ যেমন হতো) ----
    (function () {
        var studentsData = <?php echo json_encode(array_map(function ($s) {
            return [
                'id' => (int) $s['id'],
                'label' => $s['student_id'] . ' - ' . $s['name_en'] . ' - ' . $s['roll_no'],
            ];
        }, $students), JSON_UNESCAPED_UNICODE); ?>;

        var searchInput = document.getElementById('sfcStudentSearch');
        var suggestBox  = document.getElementById('sfcStudentSuggest');
        var hiddenSelect = document.getElementById('sfcStudentSelect');
        if (!searchInput || !suggestBox || !hiddenSelect) { return; }

        // Class/Session বদলালে sfcFilterChanged() নতুন তালিকা এখানে পাঠায় — সাজেশন লিস্ট আর হিডেন select দুটোই বদলে যায়
        window.sfcSetStudents = function (list) {
            studentsData = list;
            hiddenSelect.innerHTML = '';
            var first = document.createElement('option'); first.value = ''; first.textContent = 'Select Student'; hiddenSelect.appendChild(first);
            list.forEach(function (s) {
                var o = document.createElement('option'); o.value = s.id; o.textContent = s.label; hiddenSelect.appendChild(o);
            });
            hiddenSelect.value = '';
            searchInput.value = '';
            suggestBox.style.display = 'none';
        };

        function renderSuggestions(list) {
            suggestBox.innerHTML = '';
            if (!list.length) {
                suggestBox.innerHTML = '<div class="list-group-item py-1 text-muted small">কোনো Student পাওয়া যায়নি</div>';
                suggestBox.style.display = 'block';
                return;
            }
            list.slice(0, 200).forEach(function (s) {
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action py-1';
                item.textContent = s.label;
                item.addEventListener('click', function () {
                    hiddenSelect.value = s.id;
                    searchInput.value = s.label;
                    suggestBox.style.display = 'none';
                    // Student বদলালে ডানপাশের Dues Summary সাথে সাথেই আপডেট হবে
                    // (পেজ রিলোড ছাড়াই — মাস সিলেক্ট করা থাকলে সেগুলোও থেকে যাবে)
                    sfcLoadDuesPreview();
                });
                suggestBox.appendChild(item);
            });
            suggestBox.style.display = 'block';
        }

        // ক্লিক/ফোকাস করলেই পুরো Student লিস্ট দেখাবে (খালি থাকলেও), আর লিখলে সেটাই ফিল্টার হবে
        function showList() {
            var q = searchInput.value.trim().toLowerCase();
            if (!q) { renderSuggestions(studentsData); return; }
            renderSuggestions(studentsData.filter(function (s) {
                return s.label.toLowerCase().indexOf(q) !== -1;
            }));
        }

        searchInput.addEventListener('input', function () {
            hiddenSelect.value = ''; // টাইপ করা শুরু করলে আগের সিলেকশন ক্লিয়ার
            showList();
        });
        searchInput.addEventListener('focus', showList);
        searchInput.addEventListener('click', showList);

        document.addEventListener('click', function (e) {
            if (e.target !== searchInput && !suggestBox.contains(e.target)) { suggestBox.style.display = 'none'; }
        });

        // পেজ খোলার সময়ই Student সিলেক্ট করা থাকলে (Search-এর পর) — সব Period-এর ডাটা পেছনে এনে রাখা,
        // যাতে এরপর মাস টগল করলে সাথে সাথে দেখায়
        if (hiddenSelect.value) { var ids = sfcCurrentIds(); sfcFetchAll(ids.studentId, ids.sessionId).catch(function () {}); }
    })();
</script>
