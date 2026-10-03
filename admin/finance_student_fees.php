<?php
require_once __DIR__ . '/../config/fees_period_resolver.php'; // sfc_pick_fee_setting() — Student-এর Section/Shift/Gender মিলিয়ে সঠিক Fee Setting বাছার শেয়ার্ড হেল্পার
// admin/finance_student_fees.php - Finance Module > Students Accounts > Students Fees Settings
// একজন নির্দিষ্ট Student সিলেক্ট করে তার জন্য আলাদাভাবে Admission ও Monthly (Jan-Dec) Fee
// বসানো যায়। প্রথমবার ফাঁকা থাকলে Class-level Fee Setting (finance_admission_fees /
// finance_monthly_fees) থেকে ডিফল্ট Amount অটো-প্রিফিল হয়ে আসে, Save করলে সেটা ঐ
// Student-এর নিজস্ব (override) Amount হিসেবে সেভ হয়ে যায়।

function sfv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$sf_err = $_SESSION['sf_err'] ?? '';
$sf_msg = $_SESSION['sf_msg'] ?? '';
unset($_SESSION['sf_err'], $_SESSION['sf_msg']);

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি
// ---------------------------------------------------------------
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

$MONTHS = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

// -----------------------------------------------------------
// POST Handler - Save
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_student_fees'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sf_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=finance_student_fees');
        exit();
    }

    $student_id = (int) ($_POST['student_id'] ?? 0);
    $session_id = (int) ($_POST['session_id'] ?? 0);
    $class_id   = (int) ($_POST['class_id'] ?? 0);
    $admission_amounts = (array) ($_POST['admission_amount'] ?? []); // [head_id] => amount
    $monthly_amounts   = (array) ($_POST['monthly_amount'] ?? []);   // [month][head_id] => amount

    if (!$student_id || !$session_id) {
        $_SESSION['sf_err'] = 'Session এবং Student আবশ্যক।';
        header('Location: index.php?page=finance_student_fees&session_id=' . $session_id . '&class_id=' . $class_id . '&student_id=' . $student_id);
        exit();
    }

    // Admission
    $pdo->prepare("DELETE FROM finance_student_admission_fees WHERE student_id = ? AND session_id = ?")
        ->execute([$student_id, $session_id]);
    $insA = $pdo->prepare(
        "INSERT INTO finance_student_admission_fees (student_id, session_id, ac_head_id, amount) VALUES (?, ?, ?, ?)"
    );
    $countA = 0;
    foreach ($admission_amounts as $head_id => $amt) {
        $head_id = (int) $head_id;
        $amt = (float) $amt;
        if ($head_id <= 0 || $amt <= 0) { continue; }
        $insA->execute([$student_id, $session_id, $head_id, $amt]);
        $countA++;
    }

    // Monthly
    $pdo->prepare("DELETE FROM finance_student_monthly_fees WHERE student_id = ? AND session_id = ?")
        ->execute([$student_id, $session_id]);
    $insM = $pdo->prepare(
        "INSERT INTO finance_student_monthly_fees (student_id, session_id, month, ac_head_id, amount) VALUES (?, ?, ?, ?, ?)"
    );
    $countM = 0;
    foreach ($monthly_amounts as $month => $heads) {
        $month = (int) $month;
        if ($month < 1 || $month > 12) { continue; }
        foreach ((array) $heads as $head_id => $amt) {
            $head_id = (int) $head_id;
            $amt = (float) $amt;
            if ($head_id <= 0 || $amt <= 0) { continue; }
            $insM->execute([$student_id, $session_id, $month, $head_id, $amt]);
            $countM++;
        }
    }

    $sf_stu_stmt = $pdo->prepare("SELECT student_id, name_en FROM students WHERE id = ?");
    $sf_stu_stmt->execute([$student_id]);
    $sf_stu = $sf_stu_stmt->fetch();
    $sf_stu_lbl = $sf_stu ? ($sf_stu['student_id'] . ' - ' . $sf_stu['name_en']) : "#{$student_id}";
    sec_log_activity($pdo, 'Finance', 'Update', "Student Fees Setting (নিজস্ব Override) সেভ করা হয়েছে: {$sf_stu_lbl} (Admission: {$countA} item, Monthly: {$countM} entry)");

    $_SESSION['sf_msg'] = 'Student Fees Setting সেভ করা হয়েছে। (Admission: ' . $countA . ' item, Monthly: ' . $countM . ' entry)';
    header('Location: index.php?page=finance_student_fees&session_id=' . $session_id . '&class_id=' . $class_id . '&student_id=' . $student_id);
    exit();
}

// -----------------------------------------------------------
// Filters (GET)
// -----------------------------------------------------------
$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$fee_heads_yearly  = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' AND period_type = 'Yearly' ORDER BY ac_head ASC")->fetchAll();
$fee_heads_monthly = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' AND period_type = 'Monthly' ORDER BY ac_head ASC")->fetchAll();

$session_id = (int) ($_GET['session_id'] ?? $current_session_id);
$class_id   = (int) ($_GET['class_id'] ?? 0);
$student_id = (int) ($_GET['student_id'] ?? 0);

$students = [];
if ($session_id && $class_id) {
    $fsf_where = ['session_id = ?', 'class_id = ?', "status = 'Active'"];
    $fsf_params = [$session_id, $class_id];
    branch_apply_filter($fsf_where, $fsf_params);
    $st = $pdo->prepare(
        "SELECT id, student_id, roll_no, name_en FROM students
         WHERE " . implode(' AND ', $fsf_where) . "
         ORDER BY CAST(roll_no AS UNSIGNED) ASC, roll_no ASC"
    );
    $st->execute($fsf_params);
    $students = $st->fetchAll();
}

$selected_student = null;
if ($student_id) {
    foreach ($students as $s) {
        if ((int) $s['id'] === $student_id) { $selected_student = $s; break; }
    }
    if (!$selected_student) {
        // student dropdown-এ না থাকলেও (ফিল্টার বদলানোর আগে) সরাসরি লুকআপ
        $stmt = $pdo->prepare("SELECT id, student_id, roll_no, name_en FROM students WHERE id = ?");
        $stmt->execute([$student_id]);
        $selected_student = $stmt->fetch() ?: null;
    }
}

// -----------------------------------------------------------
// প্রিফিল: আগে নিজস্ব (student-level) Amount আছে কি না দেখা হয়,
// না থাকলে Class-level Fee Setting থেকে ডিফল্ট Amount আনা হয়
// -----------------------------------------------------------
$admission_saved = []; // [head_id] => amount
$monthly_saved   = []; // [month][head_id] => amount
$has_own_admission = false;
$has_own_monthly = false;

if ($selected_student) {
    $a = $pdo->prepare("SELECT ac_head_id, amount FROM finance_student_admission_fees WHERE student_id = ? AND session_id = ?");
    $a->execute([$selected_student['id'], $session_id]);
    $arows = $a->fetchAll();
    if ($arows) {
        $has_own_admission = true;
        foreach ($arows as $r) { $admission_saved[(int) $r['ac_head_id']] = $r['amount']; }
    }

    $m = $pdo->prepare("SELECT month, ac_head_id, amount FROM finance_student_monthly_fees WHERE student_id = ? AND session_id = ?");
    $m->execute([$selected_student['id'], $session_id]);
    $mrows = $m->fetchAll();
    if ($mrows) {
        $has_own_monthly = true;
        foreach ($mrows as $r) { $monthly_saved[(int) $r['month']][(int) $r['ac_head_id']] = $r['amount']; }
    }

    // Class-level ডিফল্ট (Admission) — Session+Class মিলিয়ে প্রথম Setting
    if (!$has_own_admission) {
        $csid = sfc_pick_fee_setting($pdo, 'finance_admission_fee_settings', (int) $selected_student['id'], $session_id, $class_id);
        if ($csid) {
            $ci = $pdo->prepare("SELECT ac_head_id, amount FROM finance_admission_fee_items WHERE setting_id = ?");
            $ci->execute([$csid]);
            foreach ($ci->fetchAll() as $r) { $admission_saved[(int) $r['ac_head_id']] = $r['amount']; }
        }
    }

    // Class-level ডিফল্ট (Monthly)
    if (!$has_own_monthly) {
        $csid = sfc_pick_fee_setting($pdo, 'finance_monthly_fee_settings', (int) $selected_student['id'], $session_id, $class_id);
        if ($csid) {
            $ci = $pdo->prepare("SELECT month, ac_head_id, amount FROM finance_monthly_fee_items WHERE setting_id = ?");
            $ci->execute([$csid]);
            foreach ($ci->fetchAll() as $r) { $monthly_saved[(int) $r['month']][(int) $r['ac_head_id']] = $r['amount']; }
        }
    }
}
?>

<div class="card-custom mb-3">
    <?php if ($sf_msg): ?><div class="alert alert-success py-2"><?php echo sfv($sf_msg); ?></div><?php endif; ?>
    <?php if ($sf_err): ?><div class="alert alert-danger py-2"><?php echo sfv($sf_err); ?></div><?php endif; ?>

    <div class="d-flex justify-content-end mb-2">
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="window.location='index.php?page=finance_student_fees'"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <form method="GET" action="index.php" id="sfFilterForm">
        <input type="hidden" name="page" value="finance_student_fees">

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Session</label>
            <div class="col-sm-6">
                <select name="session_id" id="sfSession" class="form-select" onchange="document.getElementById('sfFilterForm').submit()">
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $session_id === (int) $s['id'] ? 'selected' : ''; ?>><?php echo sfv($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Class Name</label>
            <div class="col-sm-6">
                <select name="class_id" id="sfClass" class="form-select" onchange="document.getElementById('sfFilterForm').submit()">
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $cl): ?>
                        <option value="<?php echo (int) $cl['id']; ?>" <?php echo $class_id === (int) $cl['id'] ? 'selected' : ''; ?>><?php echo sfv($cl['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Student <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="student_id" id="sfStudent" class="form-select" onchange="document.getElementById('sfFilterForm').submit()" <?php echo (!$class_id) ? 'disabled' : ''; ?>>
                    <option value="">Select Student</option>
                    <?php foreach ($students as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $student_id === (int) $s['id'] ? 'selected' : ''; ?>>
                            <?php echo sfv($s['student_id'] . ' - ' . $s['name_en'] . ' - ' . $s['roll_no']); ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if (!$students && $class_id): ?>
                        <option value="" disabled>এই Class-এ কোনো Active Student পাওয়া যায়নি</option>
                    <?php endif; ?>
                </select>
            </div>
        </div>
    </form>
</div>

<?php if (!$selected_student): ?>
    <div class="alert alert-info">Session, Class ও Student সিলেক্ট করুন — তারপর Fees Setting ফর্মটি এখানে দেখা যাবে।</div>

<?php else: ?>

    <div class="alert alert-warning d-flex justify-content-between align-items-start">
        <div><i class="fa-solid fa-circle-info me-1"></i> <strong>Warning!</strong> Fees Setting (Admission &amp; Monthly) will be applicable for only selected student. Set fees type amount carefully.</div>
        <button type="button" class="btn-close" onclick="this.closest('.alert').remove()"></button>
    </div>

    <form method="POST" action="index.php?page=finance_student_fees" id="sfSaveForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="student_id" value="<?php echo (int) $selected_student['id']; ?>">
        <input type="hidden" name="session_id" value="<?php echo (int) $session_id; ?>">
        <input type="hidden" name="class_id" value="<?php echo (int) $class_id; ?>">

        <!-- Admission Fees -->
        <div class="card-custom mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2" data-bs-toggle="collapse" data-bs-target="#sfAdmissionBody" style="cursor:pointer;">
                <span class="fw-bold"><i class="fa-solid fa-circle-info me-1"></i> Student Fees Setting (Admission)</span>
                <i class="fa-solid fa-chevron-up"></i>
            </div>
            <div id="sfAdmissionBody" class="collapse show">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>Fee Type</th><th style="width:40%;">Amount</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($fee_heads_yearly): foreach ($fee_heads_yearly as $fh): ?>
                                <tr>
                                    <td><?php echo sfv($fh['ac_code'] . ' ' . $fh['ac_head']); ?></td>
                                    <td>
                                        <input type="number" step="0.01" min="0" class="form-control"
                                            name="admission_amount[<?php echo (int) $fh['id']; ?>]"
                                            value="<?php echo sfv($admission_saved[$fh['id']] ?? ''); ?>">
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="2" class="text-center text-muted py-3">
                                    কোনো Fee Head পাওয়া যায়নি। আগে <a href="index.php?page=finance_head&view=add">Accounts Head</a> পেজ থেকে "Academic" টাইপের একটা Head তৈরি করুন।
                                </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Monthly Fees -->
        <div class="card-custom mb-3">
            <div class="mb-2"><span class="fw-bold"><i class="fa-solid fa-circle-info me-1"></i> Student Fees Setting (Monthly)</span></div>

            <div class="row g-3" id="sfMonthGrid">
                <?php foreach ($MONTHS as $m_num => $m_name): ?>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center bg-white">
                                <span class="fw-bold text-primary"><i class="fa-solid fa-calendar-days me-1"></i> <?php echo sfv($m_name); ?></span>
                                <?php if ($m_num === 1): ?>
                                    <span class="form-check mb-0">
                                        <input type="checkbox" class="form-check-input" id="sfCopyJan" checked>
                                        <label class="form-check-label small" for="sfCopyJan">Copy</label>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-borderless m-0">
                                    <thead>
                                        <tr class="border-bottom">
                                            <th class="ps-3">Fee Type</th>
                                            <th style="width:45%;">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($fee_heads_monthly): foreach ($fee_heads_monthly as $fh): ?>
                                            <tr>
                                                <td class="ps-3 align-middle"><?php echo sfv($fh['ac_code'] . ' ' . $fh['ac_head']); ?></td>
                                                <td>
                                                    <input type="number" step="0.01" min="0"
                                                        name="monthly_amount[<?php echo $m_num; ?>][<?php echo (int) $fh['id']; ?>]"
                                                        class="form-control sf-amount" data-head="<?php echo (int) $fh['id']; ?>" data-month="<?php echo $m_num; ?>"
                                                        value="<?php echo sfv($monthly_saved[$m_num][$fh['id']] ?? ''); ?>">
                                                </td>
                                            </tr>
                                        <?php endforeach; else: ?>
                                            <tr><td colspan="2" class="text-center text-muted py-3">কোনো Fee Head নেই</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="text-center mb-4">
            <button type="submit" name="save_student_fees" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Save</button>
            <a href="index.php?page=finance_student_fees" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
        </div>
    </form>

    <script>
        // January-এর যেকোনো Amount বদলালে, "Copy" টিক দেওয়া থাকলে বাকি ১১ মাসের একই Fee Head-এ লাইভ কপি হবে
        document.addEventListener('input', function (e) {
            if (!e.target.classList.contains('sf-amount')) { return; }
            if (e.target.dataset.month !== '1') { return; }
            const copyChk = document.getElementById('sfCopyJan');
            if (!copyChk || !copyChk.checked) { return; }
            const headId = e.target.dataset.head;
            const val = e.target.value;
            document.querySelectorAll('.sf-amount[data-head="' + headId + '"]').forEach(inp => {
                if (inp.dataset.month !== '1') { inp.value = val; }
            });
        });
    </script>

<?php endif; ?>
