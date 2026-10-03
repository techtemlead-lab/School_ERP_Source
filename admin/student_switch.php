<?php
// admin/student_switch.php - Student Switch Process (একই সেশনে সরাসরি Class/Shift/Section বদল)
$sw_err = $_SESSION['sw_err'] ?? '';
$sw_msg = $_SESSION['sw_msg'] ?? '';
unset($_SESSION['sw_err'], $_SESSION['sw_msg']);

$current_session = $pdo->query("SELECT id, session_name FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();

$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();

function swv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// POST — সুইচ কনফার্ম করা (সরাসরি একই রেকর্ড আপডেট, নতুন রো তৈরি হয় না)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_switch'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sw_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=student_switch');
        exit();
    }

    $row_id      = (int) ($_POST['row_id'] ?? 0);
    $new_class   = (int) ($_POST['new_class_id'] ?? 0);
    $new_shift   = (int) ($_POST['new_shift_id'] ?? 0);
    $new_section = (int) ($_POST['new_section_id'] ?? 0);
    $sid_lookup  = trim($_POST['student_id_lookup'] ?? '');

    if (!$row_id || !$new_class || !$new_shift || !$new_section) {
        $_SESSION['sw_err'] = 'Class, Shift, Section — সবগুলো বাছাই করা আবশ্যক।';
        header('Location: index.php?page=student_switch&sid=' . urlencode($sid_lookup));
        exit();
    }

    $sw_stu_row = $pdo->prepare("SELECT name_en, student_id, roll_no FROM students WHERE id = ?");
    $sw_stu_row->execute([$row_id]);
    $sw_stu = $sw_stu_row->fetch();

    $pdo->prepare("UPDATE students SET class_id = ?, shift_id = ?, section_id = ? WHERE id = ?")
        ->execute([$new_class, $new_shift, $new_section, $row_id]);

    $_SESSION['sw_msg'] = 'শিক্ষার্থীকে সফলভাবে সুইচ করা হয়েছে।';
    if ($sw_stu) {
        sec_log_activity($pdo, 'Student', 'Update', "শিক্ষার্থীর Class/Shift/Section সুইচ করা হয়েছে: {$sw_stu['name_en']} ({$sw_stu['student_id']}, Roll: {$sw_stu['roll_no']})");
    }
    header('Location: index.php?page=student_switch');
    exit();
}

// -----------------------------------------------------------
// GET — Student ID দিয়ে খুঁজে বের করা (চলতি সেশনের current রেকর্ড)
// -----------------------------------------------------------
$sid_lookup = trim($_GET['sid'] ?? '');
$found = null;

if ($sid_lookup !== '' && $current_session) {
    $stmt = $pdo->prepare(
        "SELECT students.*, academic_mediums.medium_name, academic_classes.class_name,
                academic_shifts.shift_name, academic_sections.section_name
         FROM students
         LEFT JOIN academic_classes ON academic_classes.id = students.class_id
         LEFT JOIN academic_mediums ON academic_mediums.id = academic_classes.medium_id
         LEFT JOIN academic_shifts ON academic_shifts.id = students.shift_id
         LEFT JOIN academic_sections ON academic_sections.id = students.section_id
         WHERE students.student_id = ? AND students.session_id = ? AND students.is_current = 1
         LIMIT 1"
    );
    $stmt->execute([$sid_lookup, $current_session['id']]);
    $found = $stmt->fetch();

    if (!$found) {
        $sw_err = 'এই Student ID দিয়ে চলতি সেশনে কোনো active শিক্ষার্থী পাওয়া যায়নি।';
    }
}
?>

<div class="card-custom">
    <div class="d-flex justify-content-end mb-2">
        <?php if ($current_session): ?>
            <span class="text-primary fw-bold">{Session : <?php echo swv($current_session['session_name']); ?>}</span>
        <?php endif; ?>
    </div>

    <?php if ($sw_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($sw_msg); ?></div><?php endif; ?>
    <?php if ($sw_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($sw_err); ?></div><?php endif; ?>

    <!-- Student ID দিয়ে খোঁজা -->
    <form method="GET" action="index.php" class="row mb-4 justify-content-center">
        <input type="hidden" name="page" value="student_switch">
        <label class="col-sm-2 col-form-label fw-bold text-end">Student ID <span class="text-danger">*</span></label>
        <div class="col-sm-4">
            <input type="text" name="sid" class="form-control" value="<?php echo swv($sid_lookup); ?>" placeholder="Student ID লিখে Enter চাপুন" required>
        </div>
        <div class="col-sm-2">
            <button type="submit" class="btn btn-outline-primary">Find</button>
        </div>
    </form>

    <?php if ($found): ?>
        <form method="POST" action="index.php?page=student_switch">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="row_id" value="<?php echo (int) $found['id']; ?>">
            <input type="hidden" name="student_id_lookup" value="<?php echo swv($sid_lookup); ?>">

            <div class="row">
                <!-- Switch From -->
                <div class="col-md-6">
                    <h6 class="text-center fw-bold mb-3"><i class="fa-solid fa-circle-arrow-left"></i> Switch From</h6>
                    <div class="row g-0">
                        <div class="col-6">
                            <table class="table table-bordered mb-0">
                                <tr><td class="fw-bold">Medium</td><td><?php echo swv($found['medium_name'] ?? '-'); ?></td></tr>
                                <tr><td class="fw-bold">Class Name</td><td><?php echo swv($found['class_name'] ?? '-'); ?></td></tr>
                                <tr><td class="fw-bold">Shift</td><td><?php echo swv($found['shift_name'] ?? '-'); ?></td></tr>
                                <tr><td class="fw-bold">Section Name</td><td><?php echo swv($found['section_name'] ?? '-'); ?></td></tr>
                            </table>
                        </div>
                        <div class="col-6">
                            <table class="table table-bordered mb-0">
                                <tr><td class="fw-bold">Name</td><td><?php echo swv($found['name_en']); ?></td></tr>
                                <tr><td class="fw-bold">Student ID</td><td><?php echo swv($found['student_id']); ?></td></tr>
                                <tr><td class="fw-bold">Roll</td><td><?php echo swv($found['roll_no']); ?></td></tr>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Switch To -->
                <div class="col-md-6">
                    <h6 class="text-center fw-bold mb-3"><i class="fa-solid fa-circle-arrow-right"></i> Switch To</h6>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Class Name <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="new_class_id" id="switchClassSelect" class="form-select" required onchange="filterSwitchSections()">
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?php echo (int) $c['id']; ?>"><?php echo swv($c['class_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Shift <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="new_shift_id" class="form-select" required>
                                <option value="">Select Shift</option>
                                <?php foreach ($shifts as $sh): ?>
                                    <option value="<?php echo (int) $sh['id']; ?>"><?php echo swv($sh['shift_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Section Name <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="new_section_id" id="switchSectionSelect" class="form-select" required disabled>
                                <option value="">Select Class first</option>
                                <?php foreach ($sections as $sec): ?>
                                    <option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" hidden><?php echo swv($sec['section_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <hr>
            <div class="text-center">
                <button type="submit" name="do_switch" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;" onclick="return confirm('নিশ্চিত? এই শিক্ষার্থীকে সুইচ করা হবে।');">Submit</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
    function filterSwitchSections() {
        const classId = document.getElementById('switchClassSelect').value;
        const select = document.getElementById('switchSectionSelect');
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
    filterSwitchSections();
</script>
