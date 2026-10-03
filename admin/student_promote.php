<?php
// admin/student_promote.php - Student Promotion (এক ক্লাস/সেশন থেকে পরের ক্লাস/সেশনে উত্তীর্ণ করা)
// পুরনো রেকর্ড is_current=0 (Archive) হয়ে যায়, নতুন একটা রেকর্ড is_current=1 (Current) হিসেবে তৈরি হয়

// পুরনো ইনস্টলেশনে students.is_current কলাম না থাকলে নিরাপদে যোগ করে দেওয়া হচ্ছে
$prm_ic_col = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'is_current'"
)->fetchColumn();
if (!$prm_ic_col) {
    $pdo->exec("ALTER TABLE students ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 1");
}

// students.promoted_from_id কলামটা মূল schema.sql/full_sync.sql-এ কখনোই ছিল না (শুধু এই পেজ
// আর student_migration.php-এ ব্যবহার হয়) — ফলে কোনো self-heal ছাড়াই এতদিন এই পেজে Promote
// করতে গেলে "Unknown column 'promoted_from_id' in field list" Fatal error দিয়ে পুরো
// প্রমোশনই ব্যর্থ হতো (কোনো তথ্য না হারিয়ে, কিন্তু কাজও না করে)। এখন এখানে নিরাপদে self-heal
// করে যোগ করা হলো, যাতে Student Promotion সব তেনান্টে কাজ করে।
$prm_pf_col = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'promoted_from_id'"
)->fetchColumn();
if (!$prm_pf_col) {
    $pdo->exec("ALTER TABLE students ADD COLUMN promoted_from_id INT NULL DEFAULT NULL AFTER is_current");
}

// students.student_id কলামে মূল schema.sql-এ UNIQUE KEY (uniq_student_id) দেওয়া আছে — কিন্তু
// Promotion-এর ডিজাইন অনুযায়ী একই ছাত্রের প্রতিটা Academic Year-এর জন্য আলাদা রো তৈরি হয়,
// সবগুলোতেই একই student_id (যেমন STU-1001) থাকে (শুধু পুরনোটা is_current=0/Archive হয়ে যায়)।
// ফলে এই UNIQUE constraint-এর কারণে দ্বিতীয় রো ইনসার্ট করতে গেলেই
// "Duplicate entry ... for key uniq_student_id" এরর দিয়ে প্রমোশন সবসময় ব্যর্থ হতো — অর্থাৎ
// Student Promotion ফিচারটা এতদিন আসলে কখনোই কাজ করেনি। এখানে নিরাপদে UNIQUE KEY-টা সাধারণ
// (non-unique) INDEX দিয়ে replace করা হলো — লুকআপ পারফরম্যান্স ঠিক থাকবে, কিন্তু একই student_id
// একাধিক রো-তে থাকতে দেবে। (ডুপ্লিকেট Student ID ঠেকানোর অ্যাপ-লেভেল চেক student_admission.php
// এ is_current=1 শর্তসহ আগে থেকেই আছে, তাই নতুন কোনো ছাত্রকে ভুল করে পুরনো ID দেওয়া যাবে না।)
$prm_idx = $pdo->query(
    "SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND INDEX_NAME = 'uniq_student_id' LIMIT 1"
)->fetchColumn();
if ($prm_idx !== false && (int) $prm_idx === 0) {
    $pdo->exec("ALTER TABLE students DROP INDEX uniq_student_id, ADD INDEX idx_student_id (student_id)");
}

$prm_err = $_SESSION['prm_err'] ?? '';
unset($_SESSION['prm_err']);

$student_id_param = (int) ($_GET['id'] ?? $_POST['old_id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$student_id_param]);
$old = $stmt->fetch();

if (!$old) {
    echo '<div class="card-custom"><div class="alert alert-danger">শিক্ষার্থী পাওয়া যায়নি।</div></div>';
    return;
}

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions WHERE status = 'Active' ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();

function pmv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// POST — প্রমোশন সম্পন্ন করা
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_promote'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['prm_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=student_promote&id=' . $student_id_param);
        exit();
    }

    $new_session_id = (int) ($_POST['new_session_id'] ?? 0);
    $new_class_id   = (int) ($_POST['new_class_id'] ?? 0);
    $new_section_id = (int) ($_POST['new_section_id'] ?? 0);
    $new_shift_id   = (int) ($_POST['new_shift_id'] ?? 0);
    $new_roll_no    = trim($_POST['new_roll_no'] ?? '');

    if (!$new_session_id || !$new_class_id || !$new_section_id || !$new_shift_id || $new_roll_no === '') {
        $_SESSION['prm_err'] = 'নতুন Session, Class, Section, Shift এবং Roll No — সবগুলো আবশ্যক।';
        header('Location: index.php?page=student_promote&id=' . $student_id_param);
        exit();
    }

    // একই Student ID + নতুন Session-এ আগে থেকে কোনো রেকর্ড আছে কিনা (ডুপ্লিকেট প্রমোশন ঠেকাতে)
    $chk = $pdo->prepare("SELECT id FROM students WHERE student_id = ? AND session_id = ?");
    $chk->execute([$old['student_id'], $new_session_id]);
    if ($chk->fetch()) {
        $_SESSION['prm_err'] = 'এই শিক্ষার্থী এই Session-এ ইতিমধ্যে প্রমোট করা হয়ে গেছে।';
        header('Location: index.php?page=student_promote&id=' . $student_id_param);
        exit();
    }

    try {
        $pdo->beginTransaction();

        // পুরনো রেকর্ডকে Archive করা
        $pdo->prepare("UPDATE students SET is_current = 0 WHERE id = ?")->execute([$old['id']]);

        // নতুন রেকর্ড তৈরি — পুরনোর প্রায় সব তথ্য কপি হবে, শুধু Session/Class/Section/Shift/Roll বদলাবে
        $new_data = $old;
        unset($new_data['id'], $new_data['created_at'], $new_data['updated_at']);
        $new_data['session_id']       = $new_session_id;
        $new_data['class_id']         = $new_class_id;
        $new_data['section_id']       = $new_section_id;
        $new_data['shift_id']         = $new_shift_id;
        $new_data['roll_no']          = $new_roll_no;
        $new_data['is_current']       = 1;
        $new_data['promoted_from_id'] = $old['id'];

        $cols = implode(', ', array_keys($new_data));
        $placeholders = implode(', ', array_fill(0, count($new_data), '?'));
        $pdo->prepare("INSERT INTO students ($cols) VALUES ($placeholders)")->execute(array_values($new_data));

        $pdo->commit();
        $_SESSION['stl_msg'] = htmlspecialchars($old['name_en']) . ' কে সফলভাবে প্রমোট করা হয়েছে। পুরনো রেকর্ড এখন Archive Student List-এ পাওয়া যাবে।';
        sec_log_activity($pdo, 'Student', 'Update', "শিক্ষার্থী প্রমোট করা হয়েছে: {$old['name_en']} ({$old['student_id']}) - নতুন Roll: {$new_roll_no}");
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Student promotion failed: ' . $e->getMessage());
        $_SESSION['prm_err'] = 'প্রমোশন ব্যর্থ হয়েছে, আবার চেষ্টা করুন।';
        header('Location: index.php?page=student_promote&id=' . $student_id_param);
        exit();
    }

    header('Location: index.php?page=student_migration');
    exit();
}
?>

<div class="card-custom">
    <h4 class="fw-bold mb-4 text-dark"><i class="fa-solid fa-arrow-up-right-from-square"></i> Student Promotion</h4>

    <?php if ($prm_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($prm_err); ?></div><?php endif; ?>

    <div class="alert alert-secondary py-2">
        <strong><?php echo pmv($old['name_en']); ?></strong> (Student ID: <?php echo pmv($old['student_id']); ?>) —
        বর্তমানে Roll <?php echo pmv($old['roll_no']); ?>। প্রমোট করলে এই রেকর্ডটা <strong>Archive</strong>-এ চলে যাবে
        এবং নিচে দেওয়া তথ্য দিয়ে একটা নতুন <strong>Current</strong> রেকর্ড তৈরি হবে।
    </div>

    <form method="POST" action="index.php?page=student_promote">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="old_id" value="<?php echo (int) $old['id']; ?>">

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">নতুন Session <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="new_session_id" class="form-select" required>
                    <option value="">Select Session</option>
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>"><?php echo pmv($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">নতুন Class <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="new_class_id" id="promoteClassSelect" class="form-select" required onchange="filterPromoteSections()">
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>"><?php echo pmv($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">নতুন Shift <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="new_shift_id" class="form-select" required>
                    <option value="">Select Shift</option>
                    <?php foreach ($shifts as $sh): ?>
                        <option value="<?php echo (int) $sh['id']; ?>"><?php echo pmv($sh['shift_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">নতুন Section <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="new_section_id" id="promoteSectionSelect" class="form-select" required disabled>
                    <option value="">Select Class first</option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" hidden><?php echo pmv($sec['section_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row mb-4 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">নতুন Roll No <span class="text-danger">*</span></label>
            <div class="col-sm-6"><input type="text" name="new_roll_no" class="form-control" required></div>
        </div>

        <hr>
        <div class="text-center">
            <button type="submit" name="do_promote" class="btn btn-success px-5 fw-bold" onclick="return confirm('নিশ্চিত? এই ছাত্রকে প্রমোট করা হবে।');">Promote</button>
            <a href="index.php?page=student_migration" class="btn btn-secondary px-4 ms-2">Cancel</a>
        </div>
    </form>
</div>

<script>
    function filterPromoteSections() {
        const classId = document.getElementById('promoteClassSelect').value;
        const select = document.getElementById('promoteSectionSelect');
        select.disabled = !classId;
        Array.from(select.options).forEach(opt => {
            if (!opt.value) { opt.hidden = false; return; }
            opt.hidden = !classId || opt.dataset.class !== classId;
        });
        select.options[0].textContent = classId ? 'Select Section' : 'Select Class first';
        if (select.value && Array.from(select.options).find(o => o.value === select.value)?.hidden) {
            select.value = '';
        }
    }
    filterPromoteSections();
</script>
