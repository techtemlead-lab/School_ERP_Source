<?php
// admin/database_reset.php - Database Reset (সিলেক্টিভ, ক্যাটাগরি অনুযায়ী)
// শুধুমাত্র Super Admin এই পেজ দেখতে/ব্যবহার করতে পারবে। এখানে যে ক্যাটাগরি(গুলো)
// টিক দেওয়া হবে, শুধু সেই টেবিলগুলোই মুছে যাবে — বাকি সব ডেটা অক্ষত থাকবে।
// `admin` টেবিল (Super Admin লগইন) কখনোই মোছা হয় না, তালিকাতেও দেখানো হয় না।

$is_super_admin = $_SESSION['is_super_admin'] ?? true;
if (!$is_super_admin) {
    echo '<div class="card-custom"><div class="alert alert-danger mb-0"><i class="fa-solid fa-lock me-2"></i>শুধুমাত্র <b>Super Admin</b> Database Reset অ্যাক্সেস করতে পারবেন।</div></div>';
    return;
}

$dr_err = $_SESSION['dr_err'] ?? '';
$dr_msg = $_SESSION['dr_msg'] ?? '';
unset($_SESSION['dr_err'], $_SESSION['dr_msg']);

// -----------------------------------------------------------------
// এই DB-এর সব টেবিলকে (admin বাদে) prefix/নাম অনুযায়ী মডিউল-ক্যাটাগরিতে ভাগ করা
// হচ্ছে — নতুন মডিউল/টেবিল যোগ হলেও এটা অটোমেটিক্যালি ধরে নেবে, আলাদা করে এই
// ফাইল বদলাতে হবে না।
// -----------------------------------------------------------------
function dr_categorize(array $tables): array
{
    $rules = [
        ['label' => 'একাডেমিক সেটআপ', 'sub' => 'Session, Medium, Class, Section, Shift, Subject, Group, Holiday', 'icon' => 'fa-layer-group', 'match' => fn($t) => str_starts_with($t, 'academic_')],
        ['label' => 'ভর্তি (Admission)', 'sub' => 'Admission Settings, Applicants, Seat Plan', 'icon' => 'fa-user-plus', 'match' => fn($t) => str_starts_with($t, 'admission')],
        ['label' => 'স্টুডেন্ট ডেটা', 'sub' => 'Students, Daily Attendance', 'icon' => 'fa-user-graduate', 'match' => fn($t) => $t === 'students' || str_starts_with($t, 'student_')],
        ['label' => 'এইচআর ও পে-রোল', 'sub' => 'Employees, Payroll, Salary, Leave, Designation', 'icon' => 'fa-users-gear', 'match' => fn($t) => str_starts_with($t, 'employee') || str_starts_with($t, 'payroll_') || str_starts_with($t, 'payscale_') || str_starts_with($t, 'leave_') || str_starts_with($t, 'salary_') || $t === 'designations'],
        ['label' => 'পরীক্ষা ও রেজাল্ট', 'sub' => 'Exam Terms, Marks, Grade Points, Routines, Eligibility', 'icon' => 'fa-file-pen', 'match' => fn($t) => str_starts_with($t, 'exam_')],
        ['label' => 'ফিন্যান্স', 'sub' => 'Vouchers, Fee Collection, Waiver, Account Heads', 'icon' => 'fa-money-bill-wave', 'match' => fn($t) => str_starts_with($t, 'finance_')],
        ['label' => 'লাইব্রেরি', 'sub' => 'Books, Categories, Issue Records', 'icon' => 'fa-book', 'match' => fn($t) => str_starts_with($t, 'library_')],
        ['label' => 'পরিবহন (Transport)', 'sub' => 'Vehicles, Routes, Stops, GPS Logs', 'icon' => 'fa-bus', 'match' => fn($t) => str_starts_with($t, 'transport_')],
        ['label' => 'রুটিন ও শিক্ষক এসাইনমেন্ট', 'sub' => 'Class Routine, Subject Assignment', 'icon' => 'fa-calendar-days', 'match' => fn($t) => in_array($t, ['routines', 'teacher_subject_assignments'], true)],
        ['label' => 'আইডি কার্ড ও সার্টিফিকেট', 'sub' => 'ID Card Template/Settings, Transfer Certificate', 'icon' => 'fa-id-card', 'match' => fn($t) => str_starts_with($t, 'id_card_') || str_starts_with($t, 'transfer_certificate')],
        ['label' => 'ইনভেন্টরি ও বিক্রয় (POS)', 'sub' => 'Stock Items, Supplier Ledger, Sales Invoice', 'icon' => 'fa-shop', 'match' => fn($t) => in_array($t, ['inventory_items', 'supplier_ledger', 'sales_invoices'], true)],
        ['label' => 'ওয়েবসাইট কনটেন্ট', 'sub' => 'Notice, Slider, Gallery, News, Career, Events', 'icon' => 'fa-globe', 'match' => fn($t) => str_starts_with($t, 'website_') || in_array($t, ['notices', 'sliders', 'gallery_items', 'message_corner'], true)],
        ['label' => 'এসএমএস লগ', 'sub' => 'পাঠানো SMS-এর হিস্টোরি', 'icon' => 'fa-comment-sms', 'match' => fn($t) => str_starts_with($t, 'sms_') && $t !== 'sms_settings'],
        ['label' => 'ইউজার ম্যানেজমেন্ট', 'sub' => 'User Management > User List-এ তৈরি করা সব User (Super Admin বাদে)', 'icon' => 'fa-user-shield', 'match' => fn($t) => $t === 'system_users'],
        ['label' => 'ইনস্টিটিউট সেটিংস ও ব্র্যান্ডিং', 'sub' => 'Institute Name/Logo, Branch, SMS Gateway Settings — সচরাচর দরকার নাই', 'icon' => 'fa-school', 'match' => fn($t) => in_array($t, ['institute_settings', 'branches', 'sms_settings'], true), 'risky' => true],
    ];

    $grouped = [];
    $used = [];
    foreach ($rules as $rule) {
        $matched = array_values(array_filter($tables, $rule['match']));
        if ($matched) {
            $grouped[] = ['label' => $rule['label'], 'sub' => $rule['sub'], 'icon' => $rule['icon'], 'tables' => $matched, 'risky' => $rule['risky'] ?? false];
            $used = array_merge($used, $matched);
        }
    }
    $rest = array_values(array_diff($tables, $used));
    if ($rest) {
        $grouped[] = ['label' => 'অন্যান্য', 'sub' => 'উপরে তালিকাভুক্ত না এমন বাকি টেবিল', 'icon' => 'fa-ellipsis', 'tables' => $rest, 'risky' => false];
    }
    return $grouped;
}

function dr_split_sql_statements(string $sql): array
{
    $sql = str_replace("\r\n", "\n", $sql);
    $statements = [];
    $buf = '';
    $len = strlen($sql);
    $inQuote = null;
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($inQuote !== null) {
            $buf .= $ch;
            if ($ch === '\\' && $i + 1 < $len) { $buf .= $sql[++$i]; continue; }
            if ($ch === $inQuote) { $inQuote = null; }
            continue;
        }
        if ($ch === "'" || $ch === '"') { $inQuote = $ch; $buf .= $ch; continue; }
        if ($ch === ';') { $statements[] = $buf; $buf = ''; continue; }
        $buf .= $ch;
    }
    if (trim($buf) !== '') { $statements[] = $buf; }

    $clean = [];
    foreach ($statements as $stmt) {
        $lines = array_filter(
            array_map('rtrim', explode("\n", $stmt)),
            fn($l) => trim($l) !== '' && !str_starts_with(trim($l), '--')
        );
        $t = trim(implode("\n", $lines));
        if ($t !== '') { $clean[] = $t; }
    }
    return $clean;
}

// সব টেবিল খুঁজে বের করে (admin বাদে) ক্যাটাগরিতে ভাগ করা হচ্ছে
$all_tables = $pdo->query(
    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME != 'admin'"
)->fetchAll(PDO::FETCH_COLUMN);
$categories = dr_categorize($all_tables);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['dr_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=database_reset');
        exit();
    }

    $selected_labels = $_POST['categories'] ?? [];
    $tables_to_wipe = [];
    foreach ($categories as $cat) {
        if (in_array($cat['label'], $selected_labels, true)) {
            $tables_to_wipe = array_merge($tables_to_wipe, $cat['tables']);
        }
    }
    $tables_to_wipe = array_values(array_intersect($tables_to_wipe, $all_tables)); // শুধু বৈধ, বিদ্যমান টেবিল

    if (empty($tables_to_wipe)) {
        $_SESSION['dr_err'] = 'অন্তত একটা ক্যাটাগরি সিলেক্ট করুন।';
    } elseif (trim($_POST['confirm_text'] ?? '') !== 'RESET') {
        $_SESSION['dr_err'] = 'নিশ্চিতকরণ বক্সে ঠিক "RESET" লিখুন (বড় হাতের অক্ষরে)।';
    } else {
        $admin_hash = $pdo->query("SELECT password FROM admin ORDER BY id ASC LIMIT 1")->fetchColumn();
        if (!$admin_hash || !password_verify((string) ($_POST['confirm_password'] ?? ''), $admin_hash)) {
            $_SESSION['dr_err'] = 'Super Admin পাসওয়ার্ড সঠিক না। রিসেট বাতিল করা হয়েছে।';
            header('Location: index.php?page=database_reset');
            exit();
        }
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tables_to_wipe as $t) {
                $pdo->exec("TRUNCATE TABLE `{$t}`");
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            // schema.sql আবার রান করে যেসব সিঙ্গেল-রো ডিফল্ট টেবিল (institute_settings,
            // branches, sms_settings) মোছা হয়েছে সেগুলোতে আবার ডিফল্ট রো বসিয়ে দেওয়া হচ্ছে —
            // "WHERE NOT EXISTS" শর্তযুক্ত হওয়ায় বাকি অক্ষত টেবিলে কোনো প্রভাব পড়বে না
            $schema_sql = file_get_contents(__DIR__ . '/../sql/schema.sql');
            if ($schema_sql !== false) {
                foreach (dr_split_sql_statements($schema_sql) as $stmt) {
                    try { $pdo->exec($stmt); } catch (PDOException $e) { continue; }
                }
            }

            $count = count($tables_to_wipe);
            $_SESSION['dr_msg'] = "নির্বাচিত ক্যাটাগরির {$count}টি টেবিল রিসেট করা হয়েছে। বাকি সব ডেটা এবং আপনার Super Admin লগইন অক্ষত আছে।";
        } catch (PDOException $e) {
            $_SESSION['dr_err'] = 'রিসেট করতে সমস্যা হয়েছে: ' . $e->getMessage();
        }
    }
    header('Location: index.php?page=database_reset');
    exit();
}

$csrf = htmlspecialchars(csrf_token());
?>
<div class="card-custom">
    <?php if ($dr_msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($dr_msg); ?></div><?php endif; ?>
    <?php if ($dr_err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($dr_err); ?></div><?php endif; ?>

    <div class="d-flex align-items-center gap-2 mb-3">
        <div class="bg-danger bg-opacity-75 p-2" style="width:40px;border-radius:4px;">
            <i class="fa-solid fa-triangle-exclamation text-white"></i>
        </div>
        <div>
            <h5 class="mb-0 fw-bold text-danger">Database Reset</h5>
            <small class="text-muted">যে অংশ দরকার, শুধু সেটাই টিক দিয়ে রিসেট করুন — বাকি সব ডেটা অক্ষত থাকবে।</small>
        </div>
    </div>

    <form method="POST" action="index.php?page=database_reset" onsubmit="return document.getElementById('drSubmitBtn').dataset.armed === '1';">
        <?php echo csrf_field(); ?>

        <div class="d-flex justify-content-between align-items-center mb-2">
            <label class="fw-bold mb-0">কোন কোন ডেটা রিসেট করবেন? <span class="text-danger">*</span></label>
            <div>
                <button type="button" id="drSelectAllBtn" class="btn btn-sm btn-link p-0 text-decoration-none">সব সিলেক্ট করো</button>
                <span class="text-muted mx-1">|</span>
                <button type="button" id="drClearAllBtn" class="btn btn-sm btn-link p-0 text-decoration-none text-muted">ক্লিয়ার করো</button>
            </div>
        </div>

        <div class="dr-cat-grid mb-3">
            <?php foreach ($categories as $cat): $cid = 'drcat_' . md5($cat['label']); ?>
                <label for="<?php echo $cid; ?>" class="dr-cat-card <?php echo $cat['risky'] ? 'dr-cat-risky' : ''; ?>">
                    <input type="checkbox" name="categories[]" id="<?php echo $cid; ?>" value="<?php echo htmlspecialchars($cat['label']); ?>" class="dr-cat-input">
                    <span class="dr-cat-tick"><i class="fa-solid fa-check"></i></span>
                    <span class="dr-cat-icon"><i class="fa-solid <?php echo $cat['icon']; ?>"></i></span>
                    <span class="dr-cat-text">
                        <span class="dr-cat-label"><?php echo htmlspecialchars($cat['label']); ?> <span class="text-muted fw-normal">(<?php echo count($cat['tables']); ?>টি টেবিল)</span></span>
                        <span class="dr-cat-sub"><?php echo htmlspecialchars($cat['sub']); ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>

        <style>
            .dr-cat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px; }
            .dr-cat-card {
                position: relative; display: flex; align-items: flex-start; gap: 10px;
                border: 1.5px solid #e2e5ec; border-radius: 10px; padding: 12px 14px; cursor: pointer;
                background: #fff; transition: all .15s ease;
            }
            .dr-cat-card:hover { border-color: #f87171; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
            .dr-cat-input { position: absolute; opacity: 0; width: 0; height: 0; }
            .dr-cat-icon {
                width: 34px; height: 34px; flex: none; border-radius: 8px; background: #f1f3f9; color: #64748b;
                display: flex; align-items: center; justify-content: center; font-size: 14px; transition: all .15s ease;
            }
            .dr-cat-tick {
                position: absolute; top: 10px; right: 10px; width: 18px; height: 18px; border-radius: 5px;
                border: 1.5px solid #cbd0da; display: flex; align-items: center; justify-content: center;
                font-size: 10px; color: transparent; transition: all .15s ease;
            }
            .dr-cat-text { display: flex; flex-direction: column; gap: 2px; padding-right: 20px; }
            .dr-cat-label { font-weight: 600; font-size: 13.5px; color: #1f2937; }
            .dr-cat-sub { font-size: 11.5px; color: #8a93a3; line-height: 1.35; }
            .dr-cat-input:checked ~ .dr-cat-tick { background: #dc2626; border-color: #dc2626; color: #fff; }
            .dr-cat-input:checked ~ .dr-cat-icon { background: #dc2626; color: #fff; }
            .dr-cat-card:has(.dr-cat-input:checked) { border-color: #dc2626; background: #fff5f5; }
            .dr-cat-risky .dr-cat-label::after { content: ' ⚠️'; }
        </style>

        <div class="alert alert-warning py-2 small mb-4">
            <b>⚠️</b> যা যা টিক দেবেন শুধু সেই ডেটাই স্থায়ীভাবে মুছে যাবে — <b>এই কাজ Undo করা যাবে না</b>।
            আপনার <b>Super Admin লগইন (username/password)</b> সবসময় অক্ষত থাকবে, তালিকায় দেখানোও হয় না।
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">নিশ্চিতকরণ লিখুন <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <input type="text" id="drConfirmText" class="form-control" placeholder='ঠিক "RESET" লিখুন' autocomplete="off">
                <small class="text-muted">বড় হাতের অক্ষরে ঠিক <code>RESET</code> লিখতে হবে।</small>
            </div>
        </div>

        <div class="row mb-4 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Super Admin Password <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <input type="password" name="confirm_password" class="form-control" required autocomplete="current-password">
            </div>
        </div>

        <input type="hidden" name="confirm_text" id="drConfirmTextHidden">

        <div class="row">
            <div class="col-sm-6 offset-sm-3">
                <button type="submit" name="confirm_reset" id="drSubmitBtn" class="btn btn-danger fw-bold px-4" disabled data-armed="0">
                    <i class="fa-solid fa-trash-can me-1"></i> নির্বাচিত ডেটা রিসেট করো
                </button>
            </div>
        </div>
    </form>
</div>

<script>
    (function () {
        var checks = Array.from(document.querySelectorAll('.dr-cat-input'));
        var confirmInput = document.getElementById('drConfirmText');
        var confirmHidden = document.getElementById('drConfirmTextHidden');
        var btn = document.getElementById('drSubmitBtn');

        function refreshBtn() {
            var anyChecked = checks.some(function (c) { return c.checked; });
            var textOk = confirmInput.value === 'RESET';
            var ok = anyChecked && textOk;
            btn.disabled = !ok;
            btn.dataset.armed = ok ? '1' : '0';
        }

        confirmInput.addEventListener('input', function () {
            confirmHidden.value = confirmInput.value;
            refreshBtn();
        });
        checks.forEach(function (c) { c.addEventListener('change', refreshBtn); });

        document.getElementById('drSelectAllBtn').addEventListener('click', function () {
            checks.forEach(function (c) { c.checked = true; });
            refreshBtn();
        });
        document.getElementById('drClearAllBtn').addEventListener('click', function () {
            checks.forEach(function (c) { c.checked = false; });
            refreshBtn();
        });
    })();
</script>
