<?php
// admin/finance_monthly_fees.php - Finance Module > Student Accounts > Monthly Fees
// Session/Class/Shift/Section/Gender/Applicability সিলেক্ট করে একটা Monthly Fee Setting তৈরি হয়,
// তারপর Jan-Dec এর প্রতিটা মাসের জন্য আলাদা আলাদা Fee Head + Amount বসানো যায় (January থেকে
// "Copy" টিক দেওয়া থাকলে বাকি ১১ মাসেও লাইভ কপি হয়ে যায়)।

function fmv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$fm_err = $_SESSION['fm_err'] ?? '';
$fm_msg = $_SESSION['fm_msg'] ?? '';
unset($_SESSION['fm_err'], $_SESSION['fm_msg']);

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_monthly_fee_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        class_id INT NOT NULL,
        shift_id INT NOT NULL DEFAULT 0,
        section_id INT NOT NULL DEFAULT 0,
        gender VARCHAR(10) NOT NULL DEFAULT '',
        applicability VARCHAR(30) NOT NULL DEFAULT 'New',
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_lookup (session_id, class_id, shift_id, section_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_monthly_fee_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_id INT NOT NULL,
        month TINYINT NOT NULL,
        ac_head_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_item (setting_id, month, ac_head_id),
        KEY idx_setting (setting_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$APPLICABILITY = [
    'New'             => 'For New Students (Only for Admission)',
    'All'             => 'For Existing & New Students (All)',
    'ExceptDiscount'  => 'For Existing & New Students Except (Special Discount)',
];
$MONTHS = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['fm_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=finance_monthly_fees');
        exit();
    }

    if (isset($_POST['save_fee_config'])) {
        $id            = (int) ($_POST['id'] ?? 0);
        $session_id    = (int) ($_POST['session_id'] ?? 0);
        $class_id      = (int) ($_POST['class_id'] ?? 0);
        $shift_id      = (int) ($_POST['shift_id'] ?? 0);
        $section_id    = (int) ($_POST['section_id'] ?? 0);
        $gender        = trim($_POST['gender'] ?? '');
        $applicability = trim($_POST['applicability'] ?? 'New');

        if (!$session_id || !$class_id) {
            $_SESSION['fm_err'] = 'Session এবং Class আবশ্যক।';
            header('Location: index.php?page=finance_monthly_fees&view=' . ($id > 0 ? 'edit&id=' . $id : 'config'));
            exit();
        }
        if (!array_key_exists($applicability, $APPLICABILITY)) { $applicability = 'New'; }
        if (!in_array($gender, ['Male', 'Female', ''], true)) { $gender = ''; }

        // একই Class-এর জন্য একাধিক Setting রাখা যাবে (New আলাদা, All আলাদা ইত্যাদি) —
        // কিন্তু হুবহু একই Session/Class/Shift/Section/Gender/Applicability এর ডুপ্লিকেট Setting আটকানো হচ্ছে
        // (finance_admission_fees.php এর মতোই)
        $mf_dup_stmt = $pdo->prepare(
            "SELECT id FROM finance_monthly_fee_settings
             WHERE session_id = ? AND class_id = ? AND shift_id = ? AND section_id = ? AND gender = ? AND applicability = ? AND id != ?
             LIMIT 1"
        );
        $mf_dup_stmt->execute([$session_id, $class_id, $shift_id, $section_id, $gender, $applicability, $id]);
        if ($mf_dup_stmt->fetch()) {
            $_SESSION['fm_err'] = 'ঠিক এই একই Session/Class/Shift/Section/Gender/Applicability এর জন্য আরেকটা Monthly Fee Setting আগে থেকেই আছে। সেটাই Edit করুন, অথবা Applicability পরিবর্তন করে আলাদা Setting তৈরি করুন।';
            header('Location: index.php?page=finance_monthly_fees&view=' . ($id > 0 ? 'edit&id=' . $id : 'config'));
            exit();
        }

        $mf_is_new = !($id > 0);
        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE finance_monthly_fee_settings
                 SET session_id=?, class_id=?, shift_id=?, section_id=?, gender=?, applicability=?
                 WHERE id=?"
            );
            $stmt->execute([$session_id, $class_id, $shift_id, $section_id, $gender, $applicability, $id]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO finance_monthly_fee_settings (session_id, class_id, shift_id, section_id, gender, applicability)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$session_id, $class_id, $shift_id, $section_id, $gender, $applicability]);
            $id = (int) $pdo->lastInsertId();
        }
        $mf_cls_stmt = $pdo->prepare("SELECT class_name FROM academic_classes WHERE id = ?");
        $mf_cls_stmt->execute([$class_id]);
        $mf_cls_lbl = $mf_cls_stmt->fetchColumn() ?: $class_id;
        sec_log_activity($pdo, 'Finance', $mf_is_new ? 'Create' : 'Update', ($mf_is_new ? "নতুন Monthly Fee Setting তৈরি করা হয়েছে: " : "Monthly Fee Setting আপডেট করা হয়েছে: ") . "Class {$mf_cls_lbl} (Setting #{$id})");
        header('Location: index.php?page=finance_monthly_fees&view=items&id=' . $id);
        exit();
    } elseif (isset($_POST['save_fee_items'])) {
        $setting_id = (int) ($_POST['setting_id'] ?? 0);
        $amount_grid = (array) ($_POST['amount'] ?? []); // [month][head_id] => amount

        if (!$setting_id) {
            $_SESSION['fm_err'] = 'সঠিক Fee Setting পাওয়া যায়নি।';
            header('Location: index.php?page=finance_monthly_fees');
            exit();
        }

        $pdo->prepare("DELETE FROM finance_monthly_fee_items WHERE setting_id = ?")->execute([$setting_id]);
        $ins = $pdo->prepare("INSERT INTO finance_monthly_fee_items (setting_id, month, ac_head_id, amount) VALUES (?, ?, ?, ?)");
        $count = 0;
        foreach ($amount_grid as $month => $heads) {
            $month = (int) $month;
            if ($month < 1 || $month > 12) { continue; }
            foreach ((array) $heads as $head_id => $amt) {
                $head_id = (int) $head_id;
                $amt = (float) $amt;
                if ($head_id <= 0 || $amt <= 0) { continue; }
                $ins->execute([$setting_id, $month, $head_id, $amt]);
                $count++;
            }
        }
        if ($count > 0) {
            $mf_total = 0.0;
            foreach ($amount_grid as $mm => $hh) { foreach ((array) $hh as $aamt) { $mf_total += (float) $aamt; } }
            sec_log_activity($pdo, 'Finance', 'Update', "Monthly Fee Particulars সেভ করা হয়েছে: Setting #{$setting_id}, {$count} টা এন্ট্রি, মোট ৳" . number_format($mf_total, 2));
        }
        $_SESSION['fm_msg'] = $count > 0 ? $count . ' টা Monthly Fee এন্ট্রি সেভ করা হয়েছে।' : 'কোনো Amount দেওয়া হয়নি — Setting সেভ আছে, পরে আবার এসে বসাতে পারবেন।';
        header('Location: index.php?page=finance_monthly_fees');
        exit();
    } elseif (isset($_POST['delete_fee_setting'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $mf_del_stmt = $pdo->prepare("SELECT fs.*, c.class_name FROM finance_monthly_fee_settings fs LEFT JOIN academic_classes c ON c.id = fs.class_id WHERE fs.id = ?");
        $mf_del_stmt->execute([$id]);
        $mf_del_row = $mf_del_stmt->fetch();
        $pdo->prepare("DELETE FROM finance_monthly_fee_items WHERE setting_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM finance_monthly_fee_settings WHERE id = ?")->execute([$id]);
        sec_log_activity($pdo, 'Finance', 'Delete', "Monthly Fee Setting মুছে ফেলা হয়েছে: Class " . ($mf_del_row['class_name'] ?? $id) . " (Setting #{$id})");
        $_SESSION['fm_msg'] = 'Fee Setting মুছে ফেলা হয়েছে।';
        header('Location: index.php?page=finance_monthly_fees');
        exit();
    }
}

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
$fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' AND period_type = 'Monthly' ORDER BY ac_head ASC")->fetchAll();

$view = $_GET['view'] ?? 'list';
?>

<?php if ($view === 'config' || $view === 'edit'): ?>
    <?php
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM finance_monthly_fee_settings WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $c = $edit_row ?: [
        'id' => 0, 'session_id' => $current_session_id, 'class_id' => 0, 'shift_id' => 0,
        'section_id' => 0, 'gender' => '', 'applicability' => 'New',
    ];
    ?>
    <div class="card-custom">
        <?php if ($fm_err): ?><div class="alert alert-danger py-2"><?php echo fmv($fm_err); ?></div><?php endif; ?>

        <form method="POST" action="index.php?page=finance_monthly_fees">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Session <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="session_id" class="form-select" required>
                        <option value="">Select</option>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo (int) $c['session_id'] === (int) $s['id'] ? 'selected' : ''; ?>><?php echo fmv($s['session_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Class <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="class_id" id="fmClassSelect" class="form-select" required onchange="filterFmDependents()">
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $cl): ?>
                            <option value="<?php echo (int) $cl['id']; ?>" <?php echo (int) $c['class_id'] === (int) $cl['id'] ? 'selected' : ''; ?>><?php echo fmv($cl['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Shift</label>
                <div class="col-sm-6">
                    <select name="shift_id" id="fmShiftSelect" class="form-select" onchange="filterFmDependents()">
                        <option value="0">Select Shift</option>
                        <?php foreach ($shifts as $sh): ?>
                            <option value="<?php echo (int) $sh['id']; ?>" <?php echo (int) $c['shift_id'] === (int) $sh['id'] ? 'selected' : ''; ?>><?php echo fmv($sh['shift_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Section</label>
                <div class="col-sm-6">
                    <select name="section_id" id="fmSectionSelect" class="form-select" disabled>
                        <option value="0">Select Class first</option>
                        <?php foreach ($sections as $sec): ?>
                            <option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" data-shift="<?php echo (int) $sec['shift_id']; ?>" <?php echo (int) $c['section_id'] === (int) $sec['id'] ? 'selected' : ''; ?> hidden><?php echo fmv($sec['section_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Gender</label>
                <div class="col-sm-6">
                    <select name="gender" class="form-select">
                        <option value="" <?php echo $c['gender'] === '' ? 'selected' : ''; ?>>Select Gender</option>
                        <option value="Male" <?php echo $c['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo $c['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-sm-3"></div>
                <div class="col-sm-8">
                    <?php foreach ($APPLICABILITY as $val => $label): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="applicability" id="fmApp_<?php echo fmv($val); ?>" value="<?php echo fmv($val); ?>" <?php echo $c['applicability'] === $val ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="fmApp_<?php echo fmv($val); ?>"><?php echo fmv($label); ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="alert alert-warning">
                <strong>Warning!</strong> Fees Setting (Monthly) will be applicable for only new/existing students. [ Gender is not mandatory ], Only select Gender when Boy's &amp; Girl's Monthly fees are different.
            </div>

            <hr>
            <div class="text-center">
                <button type="submit" name="save_fee_config" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Save</button>
                <a href="index.php?page=finance_monthly_fees" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div>
        </form>
    </div>

    <script>
        function filterFmDependents() {
            const classId = document.getElementById('fmClassSelect').value;
            const shiftId = document.getElementById('fmShiftSelect').value;
            const secSelect = document.getElementById('fmSectionSelect');
            secSelect.disabled = !classId;
            Array.from(secSelect.options).forEach(opt => {
                if (!opt.value || opt.value === '0') { opt.hidden = false; return; }
                const classOk = !!classId && opt.dataset.class === classId;
                const shiftOk = !shiftId || shiftId === '0' || !opt.dataset.shift || opt.dataset.shift === '0' || opt.dataset.shift === shiftId;
                opt.hidden = !(classOk && shiftOk);
            });
            secSelect.options[0].textContent = classId ? 'Select Section' : 'Select Class first';
            if (secSelect.value && secSelect.value !== '0' && Array.from(secSelect.options).find(o => o.value === secSelect.value)?.hidden) {
                secSelect.value = '0';
            }
        }
        filterFmDependents();
    </script>

<?php elseif ($view === 'items'): ?>
    <?php
    $setting_id = (int) ($_GET['id'] ?? 0);
    $st_stmt = $pdo->prepare(
        "SELECT fs.*, s.session_name, c.class_name, sh.shift_name, sec.section_name
         FROM finance_monthly_fee_settings fs
         LEFT JOIN academic_sessions s ON s.id = fs.session_id
         LEFT JOIN academic_classes c ON c.id = fs.class_id
         LEFT JOIN academic_shifts sh ON sh.id = fs.shift_id
         LEFT JOIN academic_sections sec ON sec.id = fs.section_id
         WHERE fs.id = ?"
    );
    $st_stmt->execute([$setting_id]);
    $setting = $st_stmt->fetch();

    if (!$setting) {
        header('Location: index.php?page=finance_monthly_fees');
        exit();
    }

    // বিদ্যমান Amount গুলো [month][head_id] => amount ম্যাপে লোড
    $items_stmt = $pdo->prepare("SELECT month, ac_head_id, amount FROM finance_monthly_fee_items WHERE setting_id = ?");
    $items_stmt->execute([$setting_id]);
    $saved_map = [];
    foreach ($items_stmt->fetchAll() as $row) {
        $saved_map[(int) $row['month']][(int) $row['ac_head_id']] = $row['amount'];
    }
    ?>
    <div class="card-custom">
        <?php if ($fm_err): ?><div class="alert alert-danger py-2"><?php echo fmv($fm_err); ?></div><?php endif; ?>

        <div class="mb-3">
            <span class="badge bg-secondary"><?php echo fmv($setting['session_name']); ?></span>
            <span class="badge bg-secondary"><?php echo fmv($setting['class_name']); ?></span>
            <?php if ($setting['shift_name']): ?><span class="badge bg-secondary"><?php echo fmv($setting['shift_name']); ?></span><?php endif; ?>
            <?php if ($setting['section_name']): ?><span class="badge bg-secondary"><?php echo fmv($setting['section_name']); ?></span><?php endif; ?>
            <?php if ($setting['gender']): ?><span class="badge bg-secondary"><?php echo fmv($setting['gender']); ?></span><?php endif; ?>
            <span class="badge bg-info text-dark"><?php echo fmv($APPLICABILITY[$setting['applicability']] ?? $setting['applicability']); ?></span>
            <a href="index.php?page=finance_monthly_fees&view=edit&id=<?php echo (int) $setting['id']; ?>" class="btn btn-sm btn-outline-primary ms-2"><i class="fa-solid fa-pen"></i> Edit Filters</a>
        </div>

        <div class="alert alert-warning py-2">
            <strong>Warning!</strong> Fees Setting (Monthly) will be applicable for only new/existing students. [ Gender is not mandatory ], Only select Gender when Boy's &amp; Girl's Monthly fees are different.
        </div>

        <?php if (!$fee_heads): ?>
            <div class="alert alert-warning">
                কোনো Fee Head পাওয়া যায়নি। আগে <a href="index.php?page=finance_head&view=add">Accounts Head</a> পেজ থেকে "Academic" টাইপের একটা Head (Has Child = No) তৈরি করুন।
            </div>
        <?php endif; ?>

        <form method="POST" action="index.php?page=finance_monthly_fees" id="fmItemsForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="setting_id" value="<?php echo (int) $setting['id']; ?>">

            <div class="row g-3" id="fmMonthGrid">
                <?php foreach ($MONTHS as $m_num => $m_name): ?>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center bg-white">
                                <span class="fw-bold text-primary"><i class="fa-solid fa-calendar-days me-1"></i> <?php echo fmv($m_name); ?></span>
                                <?php if ($m_num === 1): ?>
                                    <span class="form-check mb-0">
                                        <input type="checkbox" class="form-check-input" id="fmCopyJan" checked>
                                        <label class="form-check-label small" for="fmCopyJan">Copy</label>
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
                                        <?php if ($fee_heads): foreach ($fee_heads as $fh): ?>
                                            <tr>
                                                <td class="ps-3 align-middle"><?php echo fmv($fh['ac_code'] . ' ' . $fh['ac_head']); ?></td>
                                                <td>
                                                    <input type="number" step="0.01" min="0"
                                                        name="amount[<?php echo $m_num; ?>][<?php echo (int) $fh['id']; ?>]"
                                                        class="form-control fm-amount" data-head="<?php echo (int) $fh['id']; ?>" data-month="<?php echo $m_num; ?>"
                                                        value="<?php echo fmv($saved_map[$m_num][$fh['id']] ?? ''); ?>">
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

            <hr>
            <div class="text-center">
                <button type="submit" name="save_fee_items" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Save</button>
                <a href="index.php?page=finance_monthly_fees" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div>
        </form>
    </div>

    <script>
        // January-এর যেকোনো Amount বদলালে, "Copy" টিক দেওয়া থাকলে বাকি ১১ মাসের একই Fee Head-এ লাইভ কপি হবে
        document.addEventListener('input', function (e) {
            if (!e.target.classList.contains('fm-amount')) { return; }
            if (e.target.dataset.month !== '1') { return; }
            const copyChk = document.getElementById('fmCopyJan');
            if (!copyChk || !copyChk.checked) { return; }
            const headId = e.target.dataset.head;
            const val = e.target.value;
            document.querySelectorAll('.fm-amount[data-head="' + headId + '"]').forEach(inp => {
                if (inp.dataset.month !== '1') { inp.value = val; }
            });
        });
    </script>

<?php else: ?>
    <?php
    $sql = "SELECT fs.*, s.session_name, c.class_name, sh.shift_name, sec.section_name,
                   (SELECT COALESCE(SUM(amount), 0) FROM finance_monthly_fee_items WHERE setting_id = fs.id) AS total_amount,
                   (SELECT COUNT(*) FROM finance_monthly_fee_items WHERE setting_id = fs.id) AS item_count
            FROM finance_monthly_fee_settings fs
            LEFT JOIN academic_sessions s ON s.id = fs.session_id
            LEFT JOIN academic_classes c ON c.id = fs.class_id
            LEFT JOIN academic_shifts sh ON sh.id = fs.shift_id
            LEFT JOIN academic_sections sec ON sec.id = fs.section_id
            ORDER BY fs.id DESC";
    $rows = $pdo->query($sql)->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($fm_msg): ?><div class="alert alert-success py-2"><?php echo fmv($fm_msg); ?></div><?php endif; ?>
        <?php if ($fm_err): ?><div class="alert alert-danger py-2"><?php echo fmv($fm_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=finance_monthly_fees&view=config" class="btn btn-success"><i class="fa-solid fa-plus"></i> Add Monthly Fee Setting</a>
            <span class="text-muted small">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> items.</span>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Srl.</th>
                        <th>Session</th>
                        <th>Class</th>
                        <th>Shift</th>
                        <th>Section</th>
                        <th>Gender</th>
                        <th>Applicability</th>
                        <th>Total Amount (12 Months)</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo fmv($row['session_name']); ?></td>
                            <td><?php echo fmv($row['class_name']); ?></td>
                            <td><?php echo fmv($row['shift_name'] ?: '—'); ?></td>
                            <td><?php echo fmv($row['section_name'] ?: '—'); ?></td>
                            <td><?php echo fmv($row['gender'] ?: '—'); ?></td>
                            <td><small><?php echo fmv($APPLICABILITY[$row['applicability']] ?? $row['applicability']); ?></small></td>
                            <td class="fw-bold"><?php echo number_format((float) $row['total_amount'], 2); ?> (<?php echo (int) $row['item_count']; ?> entries)</td>
                            <td class="text-nowrap">
                                <a href="index.php?page=finance_monthly_fees&view=items&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Fee Particulars"><i class="fa-solid fa-list"></i></a>
                                <a href="index.php?page=finance_monthly_fees&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <form method="POST" action="index.php?page=finance_monthly_fees" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" name="delete_fee_setting" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="9" class="text-center text-muted py-3">No data found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
