<?php
// admin/finance_admission_fees.php - Finance Module > Student Accounts > Admission Fees
// প্রথমে Session/Class/Shift/Section/Gender/Applicability সিলেক্ট করে একটা Fee Setting তৈরি হয়,
// তারপর ঐ Setting-এর জন্য Account Head + Amount দিয়ে Fee Particulars যোগ করা যায়।

function fav($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$fa_err = $_SESSION['fa_err'] ?? '';
$fa_msg = $_SESSION['fa_msg'] ?? '';
unset($_SESSION['fa_err'], $_SESSION['fa_msg']);

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_admission_fee_settings (
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
    "CREATE TABLE IF NOT EXISTS finance_admission_fee_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_id INT NOT NULL,
        ac_head_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        KEY idx_setting (setting_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$APPLICABILITY = [
    'New'             => 'For New Students (Only for Admission)',
    'Existing'        => 'For Existing Students Only (Class Promotion / পুরাতন শিক্ষার্থী)',
    'All'             => 'For Existing & New Students (All)',
    'ExceptDiscount'  => 'For Existing & New Students Except (Special Discount)',
];

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['fa_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=finance_admission_fees');
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
        $head_ids      = (array) ($_POST['ac_head_id'] ?? []);
        $amounts       = (array) ($_POST['amount'] ?? []);

        if (!$session_id || !$class_id) {
            $_SESSION['fa_err'] = 'Session এবং Class আবশ্যক।';
            header('Location: index.php?page=finance_admission_fees&view=' . ($id > 0 ? 'edit&id=' . $id : 'config'));
            exit();
        }
        if (!array_key_exists($applicability, $APPLICABILITY)) { $applicability = 'New'; }
        if (!in_array($gender, ['Male', 'Female', ''], true)) { $gender = ''; }

        // একই Class-এর জন্য একাধিক Setting রাখা যাবে (New আলাদা, Existing আলাদা ইত্যাদি) —
        // কিন্তু হুবহু একই Session/Class/Shift/Section/Gender/Applicability এর ডুপ্লিকেট Setting আটকানো হচ্ছে
        $dup_stmt = $pdo->prepare(
            "SELECT id FROM finance_admission_fee_settings
             WHERE session_id = ? AND class_id = ? AND shift_id = ? AND section_id = ? AND gender = ? AND applicability = ? AND id != ?
             LIMIT 1"
        );
        $dup_stmt->execute([$session_id, $class_id, $shift_id, $section_id, $gender, $applicability, $id]);
        if ($dup_stmt->fetch()) {
            $_SESSION['fa_err'] = 'ঠিক এই একই Session/Class/Shift/Section/Gender/Applicability এর জন্য আরেকটা Setting আগে থেকেই আছে। সেটাই Edit করুন, অথবা Applicability পরিবর্তন করে আলাদা Setting তৈরি করুন।';
            header('Location: index.php?page=finance_admission_fees&view=' . ($id > 0 ? 'edit&id=' . $id : 'config'));
            exit();
        }

        // Class সিলেক্ট করার সাথে সাথেই নিচে যে Fee Type + Amount এর ঘরগুলো (স্ক্রিনশট অনুযায়ী)
        // দেখানো হয়, সেগুলো এই একই Save বাটনে Setting-এর সাথে একসাথে সেভ হয়ে যায় —
        // আলাদা করে "Fee Particulars" পেজে গিয়ে আবার সেভ করার দরকার নেই।
        $is_new_setting = !($id > 0);
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE finance_admission_fee_settings
                     SET session_id=?, class_id=?, shift_id=?, section_id=?, gender=?, applicability=?
                     WHERE id=?"
                );
                $stmt->execute([$session_id, $class_id, $shift_id, $section_id, $gender, $applicability, $id]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO finance_admission_fee_settings (session_id, class_id, shift_id, section_id, gender, applicability)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([$session_id, $class_id, $shift_id, $section_id, $gender, $applicability]);
                $id = (int) $pdo->lastInsertId();
            }

            $pdo->prepare("DELETE FROM finance_admission_fee_items WHERE setting_id = ?")->execute([$id]);
            $ins = $pdo->prepare("INSERT INTO finance_admission_fee_items (setting_id, ac_head_id, amount) VALUES (?, ?, ?)");
            $item_count = 0;
            foreach ($head_ids as $idx => $hid) {
                $hid = (int) $hid;
                $raw_amt = trim((string) ($amounts[$idx] ?? ''));
                // ঘর পুরো ফাঁকা রাখলে সেই Fee Type এই Class-এর জন্য প্রযোজ্য না ধরে বাদ দেওয়া হয়।
                // কিন্তু "0" লিখে দিলে সেটা ইচ্ছাকৃত (যেমন Free/Waived Fee) ধরে সেভ হয়ে যাবে।
                if ($hid <= 0 || $raw_amt === '' || !is_numeric($raw_amt) || (float) $raw_amt < 0) { continue; }
                $ins->execute([$id, $hid, (float) $raw_amt]);
                $item_count++;
            }

            $pdo->commit();

            $cls_stmt = $pdo->prepare("SELECT class_name FROM academic_classes WHERE id = ?");
            $cls_stmt->execute([$class_id]);
            $class_lbl = $cls_stmt->fetchColumn() ?: $class_id;
            sec_log_activity($pdo, 'Finance', $is_new_setting ? 'Create' : 'Update', ($is_new_setting ? "নতুন Admission Fee Setting তৈরি করা হয়েছে: " : "Admission Fee Setting আপডেট করা হয়েছে: ") . "Class {$class_lbl} (Setting #{$id}, Total ৳" . number_format((float) array_sum(array_map('floatval', $amounts)), 2) . ")");
        } catch (Throwable $e) {
            $pdo->rollBack();
            $_SESSION['fa_err'] = 'সেভ করা যায়নি, আবার চেষ্টা করুন। (' . $e->getMessage() . ')';
            header('Location: index.php?page=finance_admission_fees&view=' . ($id > 0 ? 'edit&id=' . $id : 'config'));
            exit();
        }

        $_SESSION['fa_msg'] = $item_count > 0
            ? 'Admission Fee Setting সেভ করা হয়েছে (' . $item_count . ' টা Fee Type সহ)।'
            : 'Admission Fee Setting সেভ করা হয়েছে — কোনো Fee Type-এ Amount দেওয়া হয়নি।';
        header('Location: index.php?page=finance_admission_fees');
        exit();
    } elseif (isset($_POST['delete_fee_setting'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_stmt = $pdo->prepare("SELECT fs.*, c.class_name FROM finance_admission_fee_settings fs LEFT JOIN academic_classes c ON c.id = fs.class_id WHERE fs.id = ?");
        $del_stmt->execute([$id]);
        $del_row = $del_stmt->fetch();
        $pdo->prepare("DELETE FROM finance_admission_fee_items WHERE setting_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM finance_admission_fee_settings WHERE id = ?")->execute([$id]);
        sec_log_activity($pdo, 'Finance', 'Delete', "Admission Fee Setting মুছে ফেলা হয়েছে: Class " . ($del_row['class_name'] ?? $id) . " (Setting #{$id})");
        $_SESSION['fa_msg'] = 'Fee Setting মুছে ফেলা হয়েছে।';
        header('Location: index.php?page=finance_admission_fees');
        exit();
    }
}

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
$fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' AND period_type = 'Yearly' ORDER BY ac_head ASC")->fetchAll();

$view = $_GET['view'] ?? 'list';
?>

<?php if ($view === 'config' || $view === 'edit'): ?>
    <?php
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM finance_admission_fee_settings WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $c = $edit_row ?: [
        'id' => 0, 'session_id' => $current_session_id, 'class_id' => 0, 'shift_id' => 0,
        'section_id' => 0, 'gender' => '', 'applicability' => 'New',
    ];

    // এই Setting-এর জন্য আগে থেকে সেভ করা Amount থাকলে সেগুলো প্রি-ফিল করার জন্য lookup (ac_head_id => amount)
    $existing_amounts = [];
    if ($c['id']) {
        $it_stmt = $pdo->prepare("SELECT ac_head_id, amount FROM finance_admission_fee_items WHERE setting_id = ?");
        $it_stmt->execute([$c['id']]);
        foreach ($it_stmt->fetchAll() as $it) { $existing_amounts[(int) $it['ac_head_id']] = $it['amount']; }
    }
    ?>
    <div class="card-custom">
        <?php if ($fa_err): ?><div class="alert alert-danger py-2"><?php echo fav($fa_err); ?></div><?php endif; ?>

        <form method="POST" action="index.php?page=finance_admission_fees">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Session <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="session_id" class="form-select" required>
                        <option value="">Select</option>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo (int) $c['session_id'] === (int) $s['id'] ? 'selected' : ''; ?>><?php echo fav($s['session_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Class <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="class_id" id="faClassSelect" class="form-select" required onchange="filterFaDependents()">
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $cl): ?>
                            <option value="<?php echo (int) $cl['id']; ?>" <?php echo (int) $c['class_id'] === (int) $cl['id'] ? 'selected' : ''; ?>><?php echo fav($cl['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Shift</label>
                <div class="col-sm-6">
                    <select name="shift_id" id="faShiftSelect" class="form-select" onchange="filterFaDependents()">
                        <option value="0">Select Shift</option>
                        <?php foreach ($shifts as $sh): ?>
                            <option value="<?php echo (int) $sh['id']; ?>" <?php echo (int) $c['shift_id'] === (int) $sh['id'] ? 'selected' : ''; ?>><?php echo fav($sh['shift_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Section</label>
                <div class="col-sm-6">
                    <select name="section_id" id="faSectionSelect" class="form-select" disabled>
                        <option value="0">Select Class first</option>
                        <?php foreach ($sections as $sec): ?>
                            <option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" data-shift="<?php echo (int) $sec['shift_id']; ?>" <?php echo (int) $c['section_id'] === (int) $sec['id'] ? 'selected' : ''; ?> hidden><?php echo fav($sec['section_name']); ?></option>
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
                            <input class="form-check-input" type="radio" name="applicability" id="faApp_<?php echo fav($val); ?>" value="<?php echo fav($val); ?>" <?php echo $c['applicability'] === $val ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="faApp_<?php echo fav($val); ?>"><?php echo fav($label); ?></label>
                        </div>
                    <?php endforeach; ?>
                    <small class="text-muted d-block mt-1">
                        <i class="fa-solid fa-circle-info"></i>
                        একই Class-এর জন্য নতুন ও পুরাতন শিক্ষার্থীদের Fee একই হলে শুধু <b>"All"</b> সিলেক্ট করে একটাই Setting করুন।
                        আলাদা আলাদা হলে এই Class-এর জন্য <b>একাধিক Setting</b> তৈরি করতে পারবেন — একটা "New Students" দিয়ে, আরেকটা "Existing Students Only" দিয়ে।
                    </small>
                </div>
            </div>

            <div class="alert alert-warning">
                <strong>Warning!</strong> Fees Setting (Admission) will be applicable for only new/existing students. [ Gender is not mandatory ], Only select Gender when Boy/Girl admission fees are different.
            </div>

            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Fee Type</th>
                            <th style="width:220px;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fee_heads as $fh): ?>
                            <tr>
                                <td>
                                    <?php echo fav($fh['ac_code']); ?> <?php echo fav($fh['ac_head']); ?>
                                    <input type="hidden" name="ac_head_id[]" value="<?php echo (int) $fh['id']; ?>">
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0" name="amount[]" class="form-control" value="<?php echo fav($existing_amounts[$fh['id']] ?? ''); ?>" placeholder="0">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$fee_heads): ?>
                <div class="alert alert-warning">
                    কোনো Fee Head পাওয়া যায়নি। আগে <a href="index.php?page=finance_head&view=add">Accounts Head</a> পেজ থেকে "Academic" টাইপের একটা Head (Has Child = No) তৈরি করুন, তারপর এখানে Amount বসাতে পারবেন।
                </div>
            <?php endif; ?>

            <hr>
            <div class="text-center">
                <button type="submit" name="save_fee_config" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Save</button>
                <a href="index.php?page=finance_admission_fees" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div>
        </form>
    </div>

    <script>
        function filterFaDependents() {
            const classId = document.getElementById('faClassSelect').value;
            const shiftId = document.getElementById('faShiftSelect').value;
            const secSelect = document.getElementById('faSectionSelect');
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
        filterFaDependents();
    </script>

<?php else: ?>
    <?php
    $sql = "SELECT fs.*, s.session_name, c.class_name, sh.shift_name, sec.section_name,
                   (SELECT COALESCE(SUM(amount), 0) FROM finance_admission_fee_items WHERE setting_id = fs.id) AS total_amount,
                   (SELECT COUNT(*) FROM finance_admission_fee_items WHERE setting_id = fs.id) AS item_count
            FROM finance_admission_fee_settings fs
            LEFT JOIN academic_sessions s ON s.id = fs.session_id
            LEFT JOIN academic_classes c ON c.id = fs.class_id
            LEFT JOIN academic_shifts sh ON sh.id = fs.shift_id
            LEFT JOIN academic_sections sec ON sec.id = fs.section_id
            ORDER BY s.session_name DESC, c.rank ASC, sh.shift_name ASC, sec.section_name ASC";
    $rows = $pdo->query($sql)->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($fa_msg): ?><div class="alert alert-success py-2"><?php echo fav($fa_msg); ?></div><?php endif; ?>
        <?php if ($fa_err): ?><div class="alert alert-danger py-2"><?php echo fav($fa_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=finance_admission_fees&view=config" class="btn btn-success"><i class="fa-solid fa-plus"></i> Add Admission Fee Setting</a>
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
                        <th>Total Amount</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo fav($row['session_name']); ?></td>
                            <td><?php echo fav($row['class_name']); ?></td>
                            <td><?php echo fav($row['shift_name'] ?: '—'); ?></td>
                            <td><?php echo fav($row['section_name'] ?: '—'); ?></td>
                            <td><?php echo fav($row['gender'] ?: '—'); ?></td>
                            <td><small><?php echo fav($APPLICABILITY[$row['applicability']] ?? $row['applicability']); ?></small></td>
                            <td class="fw-bold"><?php echo number_format((float) $row['total_amount'], 2); ?> (<?php echo (int) $row['item_count']; ?> items)</td>
                            <td class="text-nowrap">
                                <a href="index.php?page=finance_admission_fees&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <form method="POST" action="index.php?page=finance_admission_fees" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
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
