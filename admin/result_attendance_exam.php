<?php
// admin/result_attendance_exam.php - Result Module > Exam Procedure > Attendance (Exam)
// Attendance (Subject) পেজের মতোই, কিন্তু এখানে একটা Subject না বেছে ক্লাসের সব Subject
// একসাথে কলাম আকারে দেখানো হয় — প্রতি Subject কলামের প্রতিটা স্টুডেন্ট রো তে চেকবক্স দিয়ে
// Present/Absent মার্ক করা যায়। ডাটা একই exam_attendance_subject টেবিলে সেভ হয়, তাই
// Attendance (Subject) পেজ থেকে করা এন্ট্রিও এখানে দেখা যাবে এবং উল্টোটাও।
$ax_err = $_SESSION['ax_err'] ?? '';
$ax_msg = $_SESSION['ax_msg'] ?? '';
unset($_SESSION['ax_err'], $_SESSION['ax_msg']);

// exam_terms টেবিল এখনো তৈরি না হয়ে থাকতে পারে (Result Module > Exam Settings > Exam Terms
// পেজ কখনো ভিজিট না করা হলে) — অন্য কোনো Result পেজ থেকে সরাসরি এলেও যেন এরর না হয়,
// তাই এখানেও নিরাপদে auto-create করা হচ্ছে (result_exam_terms.php এর সাথে একই স্ট্রাকচার)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_terms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        term_name VARCHAR(100) NOT NULL,
        rank INT NOT NULL DEFAULT 1,
        is_final_term TINYINT(1) NOT NULL DEFAULT 0,
        is_aggregated_result TINYINT(1) NOT NULL DEFAULT 0,
        admit_card_with_routine TINYINT(1) NOT NULL DEFAULT 0,
        admit_card_with_instruction TINYINT(1) NOT NULL DEFAULT 0,
        exam_routine_with_instruction TINYINT(1) NOT NULL DEFAULT 0,
        website_result_publish TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি (Attendance (Subject) পেজের মতোই একই টেবিল)
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_attendance_subject (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        term_id INT NOT NULL,
        part_id INT NOT NULL,
        subject_id INT NOT NULL,
        class_id INT NOT NULL,
        shift_id INT NOT NULL,
        section_id INT NOT NULL,
        student_id INT NOT NULL,
        status ENUM('Present','Absent') NOT NULL DEFAULT 'Absent',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_att (term_id, part_id, subject_id, student_id),
        KEY idx_lookup (class_id, shift_id, section_id, term_id, part_id, subject_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

function axv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// POST Handler - একসাথে সব স্টুডেন্ট x সব Subject এর Attendance সেভ
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ax_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_attendance_exam');
        exit();
    }

    $class_id   = (int) ($_POST['class_id'] ?? 0);
    $shift_id   = (int) ($_POST['shift_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $term_id    = (int) ($_POST['term_id'] ?? 0);
    $part_id    = (int) ($_POST['part_id'] ?? 0);
    $student_ids = $_POST['student_id'] ?? [];
    $subject_ids = $_POST['grid_subject_id'] ?? [];
    $present_map = (array) ($_POST['present'] ?? []); // present[student_id][subject_id] = '1'

    if (!$class_id || !$shift_id || !$section_id || !$term_id || !$part_id || !$student_ids || !$subject_ids) {
        $_SESSION['ax_err'] = 'Class, Shift, Section, Exam Term এবং Exam Part সিলেক্ট করুন।';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO exam_attendance_subject
                (session_id, term_id, part_id, subject_id, class_id, shift_id, section_id, student_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status)"
        );
        $count = 0;
        foreach ($student_ids as $stid) {
            $stid = (int) $stid;
            foreach ($subject_ids as $sid) {
                $sid = (int) $sid;
                $status = (isset($present_map[$stid][$sid]) && $present_map[$stid][$sid] === '1') ? 'Present' : 'Absent';
                $stmt->execute([$current_session_id, $term_id, $part_id, $sid, $class_id, $shift_id, $section_id, $stid, $status]);
                $count++;
            }
        }
        sec_log_activity($pdo, 'Result', 'Update', "ক্লাস আইডি {$class_id}, টার্ম আইডি {$term_id}, পার্ট আইডি {$part_id} এর {$count} টি Exam Attendance এন্ট্রি সংরক্ষণ করা হয়েছে");
        $_SESSION['ax_msg'] = count($student_ids) . ' জন শিক্ষার্থীর সব Subject এর Exam Attendance সেভ করা হয়েছে।';
    }

    header('Location: index.php?page=result_attendance_exam&class_id=' . $class_id . '&shift_id=' . $shift_id . '&section_id=' . $section_id . '&term_id=' . $term_id . '&part_id=' . $part_id);
    exit();
}

// -----------------------------------------------------------
// Filter dropdown ডাটা
// -----------------------------------------------------------
$sel_class   = (int) ($_GET['class_id'] ?? 0);
$sel_shift   = (int) ($_GET['shift_id'] ?? 0);
$sel_section = (int) ($_GET['section_id'] ?? 0);
$sel_term    = (int) ($_GET['term_id'] ?? 0);
$sel_part    = (int) ($_GET['part_id'] ?? 0);

$classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status='Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts  = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status='Active' ORDER BY shift_name ASC")->fetchAll();

$sections = [];
if ($sel_class > 0 && $sel_shift > 0) {
    $sec_stmt = $pdo->prepare("SELECT id, section_name FROM academic_sections WHERE class_id=? AND shift_id=? AND status='Active' ORDER BY section_name ASC");
    $sec_stmt->execute([$sel_class, $sel_shift]);
    $sections = $sec_stmt->fetchAll();
}

$terms_stmt = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id=? AND status='Active' ORDER BY rank ASC");
$terms_stmt->execute([$current_session_id]);
$terms = $terms_stmt->fetchAll();

$parts_stmt = $pdo->prepare("SELECT id, part_name FROM exam_parts WHERE session_id=? AND status='Active' ORDER BY rank ASC");
$parts_stmt->execute([$current_session_id]);
$parts = $parts_stmt->fetchAll();

// -----------------------------------------------------------
// সিলেক্টেড কম্বিনেশনের জন্য Subject + Student লিস্ট + বিদ্যমান Attendance
// -----------------------------------------------------------
$all_selected = ($sel_class > 0 && $sel_shift > 0 && $sel_section > 0 && $sel_term > 0 && $sel_part > 0);
$subjects = [];
$students = [];
$existing = []; // [student_id][subject_id] = status

if ($all_selected) {
    $sub_stmt = $pdo->prepare("SELECT id, subject_name FROM academic_subjects WHERE class_id=? AND status='Active' ORDER BY subject_name ASC");
    $sub_stmt->execute([$sel_class]);
    $subjects = $sub_stmt->fetchAll();

    $rae_where = ['class_id=?', 'shift_id=?', 'section_id=?', "status='Active'"];
    $rae_params = [$sel_class, $sel_shift, $sel_section];
    branch_apply_filter($rae_where, $rae_params);
    $stu_stmt = $pdo->prepare(
        "SELECT id, roll_no, name_en FROM students
         WHERE " . implode(' AND ', $rae_where) . "
         ORDER BY (roll_no + 0) ASC, roll_no ASC"
    );
    $stu_stmt->execute($rae_params);
    $students = $stu_stmt->fetchAll();

    if ($subjects && $students) {
        $ex_stmt = $pdo->prepare("SELECT student_id, subject_id, status FROM exam_attendance_subject WHERE term_id=? AND part_id=? AND class_id=? AND shift_id=? AND section_id=?");
        $ex_stmt->execute([$sel_term, $sel_part, $sel_class, $sel_shift, $sel_section]);
        foreach ($ex_stmt->fetchAll() as $row) {
            $existing[(int) $row['student_id']][(int) $row['subject_id']] = $row['status'];
        }
    }
}
?>

<style>
.axcol-all { cursor: pointer; }
</style>

<div class="card-custom">
    <?php if ($ax_msg): ?><div class="alert alert-success py-2"><?php echo axv($ax_msg); ?></div><?php endif; ?>
    <?php if ($ax_err): ?><div class="alert alert-danger py-2"><?php echo axv($ax_err); ?></div><?php endif; ?>

    <form method="GET" action="index.php" id="filterForm">
        <input type="hidden" name="page" value="result_attendance_exam">

        <div class="row mb-3 g-3">
            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Class <span class="text-danger">*</span></label>
                    <select name="class_id" id="rax_class" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>" <?php echo $sel_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo axv($c['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Shift <span class="text-danger">*</span></label>
                    <select name="shift_id" id="rax_shift" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($shifts as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo $sel_shift === (int) $s['id'] ? 'selected' : ''; ?>><?php echo axv($s['shift_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Section <span class="text-danger">*</span></label>
                    <select name="section_id" id="rax_section" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($sections as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo $sel_section === (int) $s['id'] ? 'selected' : ''; ?>><?php echo axv($s['section_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="rax_section_hint" class="text-danger small text-end" <?php echo ($sel_class > 0 && $sel_shift > 0) ? 'style="display:none;"' : ''; ?>>আগে Class ও Shift সিলেক্ট করুন</div>
            </div>
        </div>

        <div class="row g-3 align-items-center">
            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Exam Term <span class="text-danger">*</span></label>
                    <select name="term_id" id="rax_term" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($terms as $t): ?>
                            <option value="<?php echo (int) $t['id']; ?>" <?php echo $sel_term === (int) $t['id'] ? 'selected' : ''; ?>><?php echo axv($t['term_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Exam Part <span class="text-danger">*</span></label>
                    <select name="part_id" id="rax_part" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($parts as $p): ?>
                            <option value="<?php echo (int) $p['id']; ?>" <?php echo $sel_part === (int) $p['id'] ? 'selected' : ''; ?>><?php echo axv($p['part_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="col-md-4 text-end">
                <button type="submit" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Submit</button>
            </div>
        </div>
    </form>
</div>

<div class="card-custom mt-3">
    <div class="d-flex align-items-center gap-2 mb-3">
        <i class="fa-solid fa-user-plus text-primary"></i>
        <h6 class="mb-0">Student List for Exam Attendance</h6>
    </div>

    <div id="rax_result_wrap">
        <?php if (!$all_selected): ?>
            <div class="text-center text-muted py-4">উপরে Class, Shift, Section, Exam Term এবং Exam Part সিলেক্ট করুন — তাহলে স্টুডেন্ট লিস্ট এখানে দেখাবে।</div>
        <?php elseif (!$subjects): ?>
            <div class="text-center text-muted py-4">এই ক্লাসের জন্য কোনো Subject পাওয়া যায়নি।</div>
        <?php elseif (!$students): ?>
            <div class="text-center text-muted py-4">এই Class/Shift/Section এ কোনো Active Student পাওয়া যায়নি।</div>
        <?php else: ?>
            <form method="POST" action="index.php?page=result_attendance_exam">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
                <input type="hidden" name="shift_id" value="<?php echo $sel_shift; ?>">
                <input type="hidden" name="section_id" value="<?php echo $sel_section; ?>">
                <input type="hidden" name="term_id" value="<?php echo $sel_term; ?>">
                <input type="hidden" name="part_id" value="<?php echo $sel_part; ?>">
                <?php foreach ($subjects as $sub): ?>
                    <input type="hidden" name="grid_subject_id[]" value="<?php echo (int) $sub['id']; ?>">
                <?php endforeach; ?>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center">
                        <thead class="table-light">
                            <tr>
                                <th>Sl.</th>
                                <th>Roll</th>
                                <th class="text-start">Name</th>
                                <?php foreach ($subjects as $sub): ?>
                                    <th>
                                        <div class="small text-muted mb-1">All</div>
                                        <input type="checkbox" class="form-check-input axcol-all" data-subject="<?php echo (int) $sub['id']; ?>" onclick="toggleAxColumn(<?php echo (int) $sub['id']; ?>, this)">
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $srl = 1; foreach ($students as $stu): $stid = (int) $stu['id']; ?>
                                <tr>
                                    <td><?php echo $srl++; ?></td>
                                    <td><?php echo axv($stu['roll_no']); ?></td>
                                    <td class="text-start"><?php echo axv($stu['name_en']); ?></td>
                                    <input type="hidden" name="student_id[]" value="<?php echo $stid; ?>">
                                    <?php foreach ($subjects as $sub):
                                        $sid = (int) $sub['id'];
                                        $status = $existing[$stid][$sid] ?? 'Absent';
                                        $checked = $status === 'Present';
                                    ?>
                                        <td>
                                            <div class="small text-muted mb-1"><?php echo axv($sub['subject_name']); ?></div>
                                            <input type="checkbox" class="form-check-input ax-cb ax-col-<?php echo $sid; ?>" name="present[<?php echo $stid; ?>][<?php echo $sid; ?>]" value="1" <?php echo $checked ? 'checked' : ''; ?>>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-center mt-4">
                    <button type="submit" name="save_attendance" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Save Attendance</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleAxColumn(subjectId, headerBox) {
    var boxes = document.querySelectorAll('.ax-col-' + subjectId);
    boxes.forEach(function (b) { b.checked = headerBox.checked; });
}

(function () {
    var elClass   = document.getElementById('rax_class');
    var elShift   = document.getElementById('rax_shift');
    var elSection = document.getElementById('rax_section');
    var elTerm    = document.getElementById('rax_term');
    var elPart    = document.getElementById('rax_part');
    var elHint    = document.getElementById('rax_section_hint');
    var elWrap    = document.getElementById('rax_result_wrap');

    function raxAjaxUrl(action, extra) {
        var p = new URLSearchParams(extra || {});
        return 'ajax/result_attendance_exam_ajax.php?action=' + action + '&' + p.toString();
    }

    function clearTable() {
        elWrap.innerHTML = '<div class="text-center text-muted py-4">উপরে Class, Shift, Section, Exam Term এবং Exam Part সিলেক্ট করুন — তাহলে স্টুডেন্ট লিস্ট এখানে দেখাবে।</div>';
    }

    // Class/Shift বদলালে Section ড্রপডাউন AJAX দিয়ে রিলোড হবে (পেজ রিলোড ছাড়াই)
    function loadSections() {
        var classId = elClass.value, shiftId = elShift.value;
        elSection.innerHTML = '<option value="0">-- Select --</option>';
        clearTable();

        if (classId == '0' || shiftId == '0') {
            elHint.style.display = '';
            return;
        }
        elHint.style.display = 'none';

        fetch(raxAjaxUrl('sections', { class_id: classId, shift_id: shiftId }))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                data.sections.forEach(function (s) {
                    var opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.name;
                    elSection.appendChild(opt);
                });
            })
            .catch(function () { /* নেটওয়ার্ক সমস্যা হলে চুপচাপ থেমে যাওয়া, ইউজার আবার সিলেক্ট করলে রিট্রাই হবে */ });
    }

    // সবগুলো ফিল্ড সিলেক্ট হলে টেবিল AJAX দিয়ে লোড হবে (পেজ রিলোড ছাড়াই)
    function loadTable() {
        var classId = elClass.value, shiftId = elShift.value, sectionId = elSection.value, termId = elTerm.value, partId = elPart.value;
        if (classId == '0' || shiftId == '0' || sectionId == '0' || termId == '0' || partId == '0') {
            clearTable();
            return;
        }

        elWrap.innerHTML = '<div class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin me-2"></i>লোড হচ্ছে...</div>';

        fetch(raxAjaxUrl('table', { class_id: classId, shift_id: shiftId, section_id: sectionId, term_id: termId, part_id: partId }))
            .then(function (r) { return r.text(); })
            .then(function (html) { elWrap.innerHTML = html; })
            .catch(function () {
                elWrap.innerHTML = '<div class="text-center text-danger py-4">ডাটা লোড করতে সমস্যা হয়েছে, আবার চেষ্টা করুন।</div>';
            });
    }

    elClass.addEventListener('change', loadSections);
    elShift.addEventListener('change', loadSections);
    elSection.addEventListener('change', loadTable);
    elTerm.addEventListener('change', loadTable);
    elPart.addEventListener('change', loadTable);
})();
</script>
