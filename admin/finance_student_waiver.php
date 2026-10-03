<?php
// admin/finance_student_waiver.php - Finance Module > Students Accounts > Students Wise Waiver
// একজন নির্দিষ্ট Student-এর নির্দিষ্ট মাসের নির্দিষ্ট Fee Type-এর উপর Percentage বা
// Fixed Amount ওয়েভার (ছাড়) দেওয়ার জন্য এই পেজ।

function swv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$sw_err = $_SESSION['sw_err'] ?? '';
$sw_msg = $_SESSION['sw_msg'] ?? '';
unset($_SESSION['sw_err'], $_SESSION['sw_msg']);

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি
// ---------------------------------------------------------------
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
// পুরনো ইনস্টলেশনে folio_no কলাম না থাকলে যোগ করে দেওয়া (fees collection পেজগুলোর instant
// waiver-এর সাথে শেয়ার্ড — এখান থেকে ম্যানুয়ালি দেওয়া Waiver-এ এটা NULL-ই থাকবে)
$sw_cols = $pdo->query("SHOW COLUMNS FROM finance_student_waivers")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('folio_no', $sw_cols, true)) {
    $pdo->exec("ALTER TABLE finance_student_waivers ADD COLUMN folio_no VARCHAR(50) NULL AFTER waiver_value");
}

$MONTHS = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];
// month = 0 মানে Admission Fee-এর উপর ওয়েভার (one-time, কোনো নির্দিষ্ট মাস না) —
// Fee Collection পেজগুলো Admission-কে period 0 হিসেবেই চেনে, তাই এখানেও একই কনভেনশন
$PERIODS = [0 => 'Admission'] + $MONTHS;

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sw_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=finance_student_waiver');
        exit();
    }

    if (isset($_POST['save_waiver'])) {
        $id           = (int) ($_POST['id'] ?? 0);
        $student_id   = (int) ($_POST['student_id'] ?? 0);
        $session_id   = (int) ($_POST['session_id'] ?? 0);
        $class_id     = (int) ($_POST['class_id'] ?? 0);
        $month        = isset($_POST['month']) && $_POST['month'] !== '' ? (int) $_POST['month'] : -1;
        $ac_head_id   = (int) ($_POST['ac_head_id'] ?? 0);
        $waiver_type  = ($_POST['waiver_type'] ?? 'Fixed') === 'Percentage' ? 'Percentage' : 'Fixed';
        $waiver_value = (float) ($_POST['waiver_value'] ?? 0);
        $status       = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        // month = 0 (Admission) একটা বৈধ মান — তাই এখানে "!$month" দিয়ে চেক করা যাবে না
        // (সেটা 0-কেও ফাঁকা ধরে ভুলভাবে ব্লক করে দিত, ফলে Admission Fee-এর জন্য ওয়েভার
        // তৈরিই করা যেত না)। বদলে সঠিক রেঞ্জ (0-12) যাচাই করা হচ্ছে।
        if (!$session_id || !$class_id || !$student_id || $month < 0 || $month > 12 || !$ac_head_id || $waiver_value <= 0) {
            $_SESSION['sw_err'] = 'Session, Class, Student, Waived Month/Admission, Fees Type ও Waiver আবশ্যক।';
            header('Location: index.php?page=finance_student_waiver&view=' . ($id > 0 ? 'edit&id=' . $id : 'config'));
            exit();
        }
        if ($waiver_type === 'Percentage' && $waiver_value > 100) { $waiver_value = 100; }

        $sw_stu_stmt = $pdo->prepare("SELECT student_id, name_en FROM students WHERE id = ?");
        $sw_stu_stmt->execute([$student_id]);
        $sw_stu = $sw_stu_stmt->fetch();
        $sw_stu_lbl = $sw_stu ? ($sw_stu['student_id'] . ' - ' . $sw_stu['name_en']) : "#{$student_id}";
        $sw_waiver_lbl = $waiver_type === 'Percentage' ? number_format($waiver_value, 2) . '%' : '৳' . number_format($waiver_value, 2);

        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE finance_student_waivers
                 SET student_id=?, session_id=?, class_id=?, month=?, ac_head_id=?, waiver_type=?, waiver_value=?, status=?
                 WHERE id=?"
            );
            $stmt->execute([$student_id, $session_id, $class_id, $month, $ac_head_id, $waiver_type, $waiver_value, $status, $id]);
            sec_log_activity($pdo, 'Finance', 'Update', "Student Waiver আপডেট করা হয়েছে: {$sw_stu_lbl}, Month: {$PERIODS[$month]}, Waiver: {$sw_waiver_lbl}");
            $_SESSION['sw_msg'] = 'Waiver আপডেট করা হয়েছে।';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO finance_student_waivers (student_id, session_id, class_id, month, ac_head_id, waiver_type, waiver_value, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$student_id, $session_id, $class_id, $month, $ac_head_id, $waiver_type, $waiver_value, $status]);
            sec_log_activity($pdo, 'Finance', 'Create', "নতুন Student Waiver তৈরি করা হয়েছে: {$sw_stu_lbl}, Month: {$PERIODS[$month]}, Waiver: {$sw_waiver_lbl}");
            $_SESSION['sw_msg'] = 'নতুন Waiver তৈরি করা হয়েছে।';
        }
        header('Location: index.php?page=finance_student_waiver');
        exit();
    } elseif (isset($_POST['delete_waiver'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $sw_del_stmt = $pdo->prepare(
            "SELECT w.*, s.student_id AS student_code, s.name_en AS student_name
             FROM finance_student_waivers w LEFT JOIN students s ON s.id = w.student_id WHERE w.id = ?"
        );
        $sw_del_stmt->execute([$id]);
        $sw_del_row = $sw_del_stmt->fetch();
        $pdo->prepare("DELETE FROM finance_student_waivers WHERE id = ?")->execute([$id]);
        if ($sw_del_row) {
            $sw_del_stu_lbl = $sw_del_row['student_code'] ? ($sw_del_row['student_code'] . ' - ' . $sw_del_row['student_name']) : ('#' . $sw_del_row['student_id']);
            $sw_del_waiver_lbl = $sw_del_row['waiver_type'] === 'Percentage' ? number_format((float) $sw_del_row['waiver_value'], 2) . '%' : '৳' . number_format((float) $sw_del_row['waiver_value'], 2);
            sec_log_activity($pdo, 'Finance', 'Delete', "Student Waiver মুছে ফেলা হয়েছে: {$sw_del_stu_lbl}, Month: " . ($PERIODS[(int) $sw_del_row['month']] ?? $sw_del_row['month']) . ", Waiver: {$sw_del_waiver_lbl}");
        }
        $_SESSION['sw_msg'] = 'Waiver মুছে ফেলা হয়েছে।';
        header('Location: index.php?page=finance_student_waiver');
        exit();
    }
}

$sessions  = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes   = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC")->fetchAll();
$fsw_where = ["status = 'Active'"]; $fsw_params = [];
branch_apply_filter($fsw_where, $fsw_params);
$fsw_stmt = $pdo->prepare("SELECT id, student_id, roll_no, name_en, session_id, class_id FROM students WHERE " . implode(' AND ', $fsw_where) . " ORDER BY CAST(roll_no AS UNSIGNED) ASC, roll_no ASC");
$fsw_stmt->execute($fsw_params);
$students = $fsw_stmt->fetchAll();

$view = $_GET['view'] ?? 'list';
?>

<?php if ($view === 'config' || $view === 'edit'): ?>
    <?php
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM finance_student_waivers WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $c = $edit_row ?: [
        'id' => 0, 'session_id' => $current_session_id, 'class_id' => 0, 'student_id' => 0,
        'month' => 1, 'ac_head_id' => 0, 'waiver_type' => 'Fixed', 'waiver_value' => '', 'status' => 'Active',
    ];
    ?>
    <div class="card-custom">
        <?php if ($sw_err): ?><div class="alert alert-danger py-2"><?php echo swv($sw_err); ?></div><?php endif; ?>

        <form method="POST" action="index.php?page=finance_student_waiver" id="swForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Session <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="session_id" id="swSession" class="form-select" required onchange="swFilterStudents()">
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo (int) $c['session_id'] === (int) $s['id'] ? 'selected' : ''; ?>><?php echo swv($s['session_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Class <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="class_id" id="swClass" class="form-select" required onchange="swFilterStudents()">
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $cl): ?>
                            <option value="<?php echo (int) $cl['id']; ?>" <?php echo (int) $c['class_id'] === (int) $cl['id'] ? 'selected' : ''; ?>><?php echo swv($cl['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Student <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="student_id" id="swStudent" class="form-select" required>
                        <option value="">Select Student</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" data-session="<?php echo (int) $s['session_id']; ?>" data-class="<?php echo (int) $s['class_id']; ?>" <?php echo (int) $c['student_id'] === (int) $s['id'] ? 'selected' : ''; ?>>
                                <?php echo swv($s['student_id'] . ' - ' . $s['name_en'] . ' - ' . $s['roll_no']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Waived Month / Admission <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="month" class="form-select" required>
                        <?php foreach ($PERIODS as $m_num => $m_name): ?>
                            <option value="<?php echo $m_num; ?>" <?php echo (int) $c['month'] === $m_num ? 'selected' : ''; ?>><?php echo swv($m_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">"Admission" সিলেক্ট করলে এই ওয়েভার Admission Fee-এর উপর প্রযোজ্য হবে (নির্দিষ্ট মাসের ফি না)।</div>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Fees Type <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="ac_head_id" class="form-select" required>
                        <option value="">Select Fee Type</option>
                        <?php foreach ($fee_heads as $fh): ?>
                            <option value="<?php echo (int) $fh['id']; ?>" <?php echo (int) $c['ac_head_id'] === (int) $fh['id'] ? 'selected' : ''; ?>><?php echo swv($fh['ac_code'] . ' ' . $fh['ac_head']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$fee_heads): ?>
                        <div class="form-text text-danger">কোনো Fee Type পাওয়া যায়নি। আগে <a href="index.php?page=finance_head&view=add">Accounts Head</a> পেজ থেকে "Academic" টাইপের Head তৈরি করুন।</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Percent / Fixed ? <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="waiver_type" id="swTypePercent" value="Percentage" <?php echo $c['waiver_type'] === 'Percentage' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="swTypePercent">Percentage</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="waiver_type" id="swTypeFixed" value="Fixed" <?php echo $c['waiver_type'] !== 'Percentage' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="swTypeFixed">Fix</label>
                    </div>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Waiver <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="number" step="0.01" min="0" name="waiver_value" class="form-control" required value="<?php echo swv($c['waiver_value']); ?>">
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Status <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="status" class="form-select" required>
                        <option value="Active" <?php echo $c['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $c['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <hr>
            <div class="text-center">
                <button type="submit" name="save_waiver" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;"><?php echo $c['id'] ? 'Update' : 'Create'; ?></button>
                <a href="index.php?page=finance_student_waiver" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div>
        </form>
    </div>

    <script>
        function swFilterStudents() {
            const sessionId = document.getElementById('swSession').value;
            const classId = document.getElementById('swClass').value;
            const studentSelect = document.getElementById('swStudent');
            const currentVal = studentSelect.value;
            let stillVisible = false;
            Array.from(studentSelect.options).forEach(opt => {
                if (!opt.value) { opt.hidden = false; return; }
                const sessionOk = !sessionId || opt.dataset.session === sessionId;
                const classOk = !classId || opt.dataset.class === classId;
                opt.hidden = !(sessionOk && classOk);
                if (opt.value === currentVal && !opt.hidden) { stillVisible = true; }
            });
            if (!stillVisible) { studentSelect.value = ''; }
        }
        swFilterStudents();
    </script>

<?php else: ?>
    <?php
    $sql = "SELECT w.*, s.session_name, c.class_name, sec.name_en AS student_name, sec.student_id AS student_code, sec.roll_no,
                   fh.ac_code, fh.ac_head
            FROM finance_student_waivers w
            LEFT JOIN academic_sessions s ON s.id = w.session_id
            LEFT JOIN academic_classes c ON c.id = w.class_id
            LEFT JOIN students sec ON sec.id = w.student_id
            LEFT JOIN finance_account_heads fh ON fh.id = w.ac_head_id
            ORDER BY w.id DESC";
    $rows = $pdo->query($sql)->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($sw_msg): ?><div class="alert alert-success py-2"><?php echo swv($sw_msg); ?></div><?php endif; ?>
        <?php if ($sw_err): ?><div class="alert alert-danger py-2"><?php echo swv($sw_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=finance_student_waiver&view=config" class="btn btn-success"><i class="fa-solid fa-plus"></i> Add Waiver</a>
            <span class="text-muted small">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> items.</span>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Srl.</th>
                        <th>Session</th>
                        <th>Class</th>
                        <th>Student</th>
                        <th>Waived Month</th>
                        <th>Fees Type</th>
                        <th>Type</th>
                        <th>Waiver</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo swv($row['session_name']); ?></td>
                            <td><?php echo swv($row['class_name']); ?></td>
                            <td><?php echo swv(($row['student_code'] ?? '') . ' - ' . ($row['student_name'] ?? '—') . ' - ' . ($row['roll_no'] ?? '')); ?></td>
                            <td><?php echo swv($PERIODS[(int) $row['month']] ?? '—'); ?></td>
                            <td><?php echo swv(trim(($row['ac_code'] ?? '') . ' ' . ($row['ac_head'] ?? '—'))); ?></td>
                            <td><?php echo swv($row['waiver_type']); ?></td>
                            <td class="fw-bold"><?php echo $row['waiver_type'] === 'Percentage' ? number_format((float) $row['waiver_value'], 2) . ' %' : number_format((float) $row['waiver_value'], 2); ?></td>
                            <td><span class="badge <?php echo $row['status'] === 'Active' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo swv($row['status']); ?></span></td>
                            <td class="text-nowrap">
                                <a href="index.php?page=finance_student_waiver&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <form method="POST" action="index.php?page=finance_student_waiver" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" name="delete_waiver" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="10" class="text-center text-muted py-3">No data found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
