<?php
// admin/result_attendance_subject.php - Result Module > Exam Procedure > Attendance (Subject)
// Class + Shift + Section + Exam Term + Exam Part + Subject সিলেক্ট করলে ঐ কম্বিনেশনের
// স্টুডেন্ট লিস্ট আসবে, প্রতিটার Present/Absent টগল বাটন দিয়ে একসাথে সেভ করা যাবে।
// ডিফল্ট সবাই Absent (স্ক্রিনশট অনুযায়ী) — যারা পরীক্ষায় বসেছে শুধু তাদের Present করে দিতে হবে।
$as_err = $_SESSION['as_err'] ?? '';
$as_msg = $_SESSION['as_msg'] ?? '';
unset($_SESSION['as_err'], $_SESSION['as_msg']);

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
// টেবিল না থাকলে অটো তৈরি
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

function asv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// POST Handler - একসাথে সব স্টুডেন্টের Attendance সেভ
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['as_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_attendance_subject');
        exit();
    }

    $class_id   = (int) ($_POST['class_id'] ?? 0);
    $shift_id   = (int) ($_POST['shift_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $term_id    = (int) ($_POST['term_id'] ?? 0);
    $part_id    = (int) ($_POST['part_id'] ?? 0);
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $statuses   = (array) ($_POST['status'] ?? []);

    if (!$class_id || !$shift_id || !$section_id || !$term_id || !$part_id || !$subject_id) {
        $_SESSION['as_err'] = 'Class, Shift, Section, Exam Term, Exam Part এবং Subject সিলেক্ট করুন।';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO exam_attendance_subject
                (session_id, term_id, part_id, subject_id, class_id, shift_id, section_id, student_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status)"
        );
        $count = 0;
        foreach ($statuses as $stid => $st) {
            $stid = (int) $stid;
            $st = ($st === 'Present') ? 'Present' : 'Absent';
            if ($stid > 0) {
                $stmt->execute([$current_session_id, $term_id, $part_id, $subject_id, $class_id, $shift_id, $section_id, $stid, $st]);
                $count++;
            }
        }
        sec_log_activity($pdo, 'Result', 'Update', "ক্লাস আইডি {$class_id}, টার্ম আইডি {$term_id}, সাবজেক্ট আইডি {$subject_id} এর {$count} জন শিক্ষার্থীর Exam Attendance সংরক্ষণ করা হয়েছে");
        $_SESSION['as_msg'] = $count . ' জন শিক্ষার্থীর Exam Attendance সেভ করা হয়েছে।';
    }

    header('Location: index.php?page=result_attendance_subject&class_id=' . $class_id . '&shift_id=' . $shift_id . '&section_id=' . $section_id . '&term_id=' . $term_id . '&part_id=' . $part_id . '&subject_id=' . $subject_id);
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
$sel_subject = (int) ($_GET['subject_id'] ?? 0);

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

$subjects_list = [];
if ($sel_class > 0) {
    $subj_stmt = $pdo->prepare("SELECT id, subject_name FROM academic_subjects WHERE class_id=? AND status='Active' ORDER BY subject_name ASC");
    $subj_stmt->execute([$sel_class]);
    $subjects_list = $subj_stmt->fetchAll();
}

// -----------------------------------------------------------
// সিলেক্টেড কম্বিনেশনের জন্য Student লিস্ট + বিদ্যমান Attendance
// -----------------------------------------------------------
$all_selected = ($sel_class > 0 && $sel_shift > 0 && $sel_section > 0 && $sel_term > 0 && $sel_part > 0 && $sel_subject > 0);
$students = [];

if ($all_selected) {
    $ras_where = ['s.class_id=?', 's.shift_id=?', 's.section_id=?', "s.status='Active'"];
    $ras_params = [$sel_term, $sel_part, $sel_subject, $sel_class, $sel_shift, $sel_section];
    branch_apply_filter($ras_where, $ras_params, 's.branch_id');
    $stu_stmt = $pdo->prepare(
        "SELECT s.id, s.student_id, s.roll_no, s.name_en, s.photo_path, ea.status AS saved_status
         FROM students s
         LEFT JOIN exam_attendance_subject ea ON ea.student_id = s.id AND ea.term_id = ? AND ea.part_id = ? AND ea.subject_id = ?
         WHERE " . implode(' AND ', $ras_where) . "
         ORDER BY (s.roll_no + 0) ASC, s.roll_no ASC"
    );
    $stu_stmt->execute($ras_params);
    $students = $stu_stmt->fetchAll();
}
?>

<style>
.exat-toggle-btn {
    border: none; color: #fff; font-weight: 700; font-size: 12.5px;
    padding: 5px 16px; border-radius: 4px; cursor: pointer; min-width: 90px;
}
.exat-toggle-btn.status-absent { background: #e0533f; }
.exat-toggle-btn.status-present { background: #10b981; }
</style>

<div class="card-custom">
    <?php if ($as_msg): ?><div class="alert alert-success py-2"><?php echo asv($as_msg); ?></div><?php endif; ?>
    <?php if ($as_err): ?><div class="alert alert-danger py-2"><?php echo asv($as_err); ?></div><?php endif; ?>

    <form method="GET" action="index.php" id="filterForm">
        <input type="hidden" name="page" value="result_attendance_subject">

        <div class="row mb-3 g-3">
            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Class <span class="text-danger">*</span></label>
                    <select name="class_id" id="ras_class" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>" <?php echo $sel_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo asv($c['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Shift <span class="text-danger">*</span></label>
                    <select name="shift_id" id="ras_shift" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($shifts as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo $sel_shift === (int) $s['id'] ? 'selected' : ''; ?>><?php echo asv($s['shift_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Section <span class="text-danger">*</span></label>
                    <select name="section_id" id="ras_section" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($sections as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo $sel_section === (int) $s['id'] ? 'selected' : ''; ?>><?php echo asv($s['section_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="ras_section_hint" class="text-danger small text-end" <?php echo ($sel_class > 0 && $sel_shift > 0) ? 'style="display:none;"' : ''; ?>>আগে Class ও Shift সিলেক্ট করুন</div>
            </div>
        </div>

        <div class="row mb-3 g-3">
            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Exam Term <span class="text-danger">*</span></label>
                    <select name="term_id" id="ras_term" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($terms as $t): ?>
                            <option value="<?php echo (int) $t['id']; ?>" <?php echo $sel_term === (int) $t['id'] ? 'selected' : ''; ?>><?php echo asv($t['term_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Exam Part <span class="text-danger">*</span></label>
                    <select name="part_id" id="ras_part" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($parts as $p): ?>
                            <option value="<?php echo (int) $p['id']; ?>" <?php echo $sel_part === (int) $p['id'] ? 'selected' : ''; ?>><?php echo asv($p['part_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Subject <span class="text-danger">*</span></label>
                    <select name="subject_id" id="ras_subject" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($subjects_list as $sub): ?>
                            <option value="<?php echo (int) $sub['id']; ?>" <?php echo $sel_subject === (int) $sub['id'] ? 'selected' : ''; ?>><?php echo asv($sub['subject_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="ras_subject_hint" class="text-danger small text-end" <?php echo ($sel_class > 0) ? 'style="display:none;"' : ''; ?>>আগে Class সিলেক্ট করুন</div>
            </div>
        </div>

        <div class="row">
            <div class="col-sm-3 offset-sm-9 text-end">
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

    <div id="ras_result_wrap">
        <?php if (!$all_selected): ?>
            <div class="text-center text-muted py-4">উপরে Class, Shift, Section, Exam Term, Exam Part এবং Subject সিলেক্ট করুন — তাহলে স্টুডেন্ট লিস্ট এখানে দেখাবে।</div>
        <?php elseif (!$students): ?>
            <div class="text-center text-muted py-4">এই Class/Shift/Section এ কোনো Active Student পাওয়া যায়নি।</div>
        <?php else: ?>
            <form method="POST" action="index.php?page=result_attendance_subject" id="attendanceForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
                <input type="hidden" name="shift_id" value="<?php echo $sel_shift; ?>">
                <input type="hidden" name="section_id" value="<?php echo $sel_section; ?>">
                <input type="hidden" name="term_id" value="<?php echo $sel_term; ?>">
                <input type="hidden" name="part_id" value="<?php echo $sel_part; ?>">
                <input type="hidden" name="subject_id" value="<?php echo $sel_subject; ?>">

                <div class="table-responsive">
                    <table class="table table-bordered align-middle m-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:50px;">Sl.</th>
                                <th style="width:70px;">Roll No</th>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th style="width:130px;">
                                    <button type="button" class="exat-toggle-btn status-absent" id="allToggleBtn" onclick="toggleAll()">All Absent</button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $srl = 1; foreach ($students as $stu): $status = $stu['saved_status'] ?: 'Absent'; ?>
                                <tr>
                                    <td><?php echo $srl++; ?></td>
                                    <td><?php echo asv($stu['roll_no']); ?></td>
                                    <td class="text-primary fw-bold"><?php echo asv($stu['student_id']); ?></td>
                                    <td><?php echo asv($stu['name_en']); ?></td>
                                    <td>
                                        <input type="hidden" name="status[<?php echo (int) $stu['id']; ?>]" class="status-input" value="<?php echo $status; ?>">
                                        <button type="button" class="exat-toggle-btn row-toggle-btn status-<?php echo strtolower($status); ?>" onclick="toggleRow(this)"><?php echo $status; ?></button>
                                    </td>
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
function setBtnState(btn, status) {
    var input = btn.closest('tr').querySelector('.status-input');
    input.value = status;
    btn.textContent = status;
    btn.classList.remove('status-present', 'status-absent');
    btn.classList.add(status === 'Present' ? 'status-present' : 'status-absent');
}

function toggleRow(btn) {
    var input = btn.closest('tr').querySelector('.status-input');
    var next = input.value === 'Present' ? 'Absent' : 'Present';
    setBtnState(btn, next);
}

function toggleAll() {
    var allBtn = document.getElementById('allToggleBtn');
    var markPresent = allBtn.textContent.trim() === 'All Present';
    document.querySelectorAll('.row-toggle-btn').forEach(function (btn) {
        setBtnState(btn, markPresent ? 'Present' : 'Absent');
    });
    allBtn.textContent = markPresent ? 'All Absent' : 'All Present';
    allBtn.classList.remove('status-present', 'status-absent');
    allBtn.classList.add(markPresent ? 'status-present' : 'status-absent');
}

(function () {
    var elClass   = document.getElementById('ras_class');
    var elShift   = document.getElementById('ras_shift');
    var elSection = document.getElementById('ras_section');
    var elTerm    = document.getElementById('ras_term');
    var elPart    = document.getElementById('ras_part');
    var elSubject = document.getElementById('ras_subject');
    var elSectionHint = document.getElementById('ras_section_hint');
    var elSubjectHint = document.getElementById('ras_subject_hint');
    var elWrap    = document.getElementById('ras_result_wrap');

    function rasAjaxUrl(action, extra) {
        var p = new URLSearchParams(extra || {});
        return 'ajax/result_attendance_subject_ajax.php?action=' + action + '&' + p.toString();
    }

    function clearTable() {
        elWrap.innerHTML = '<div class="text-center text-muted py-4">উপরে Class, Shift, Section, Exam Term, Exam Part এবং Subject সিলেক্ট করুন — তাহলে স্টুডেন্ট লিস্ট এখানে দেখাবে।</div>';
    }

    // Class/Shift বদলালে Section ড্রপডাউন AJAX দিয়ে রিলোড হবে (পেজ রিলোড ছাড়াই)
    function loadSections() {
        var classId = elClass.value, shiftId = elShift.value;
        elSection.innerHTML = '<option value="0">-- Select --</option>';
        clearTable();

        if (classId == '0' || shiftId == '0') {
            elSectionHint.style.display = '';
            return;
        }
        elSectionHint.style.display = 'none';

        fetch(rasAjaxUrl('sections', { class_id: classId, shift_id: shiftId }))
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

    // Class বদলালে Subject ড্রপডাউন AJAX দিয়ে রিলোড হবে (পেজ রিলোড ছাড়াই)
    function loadSubjects() {
        var classId = elClass.value;
        elSubject.innerHTML = '<option value="0">-- Select --</option>';
        clearTable();

        if (classId == '0') {
            elSubjectHint.style.display = '';
            return;
        }
        elSubjectHint.style.display = 'none';

        fetch(rasAjaxUrl('subjects', { class_id: classId }))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                data.subjects.forEach(function (s) {
                    var opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.name;
                    elSubject.appendChild(opt);
                });
            })
            .catch(function () { /* নেটওয়ার্ক সমস্যা হলে চুপচাপ থেমে যাওয়া, ইউজার আবার সিলেক্ট করলে রিট্রাই হবে */ });
    }

    // সবগুলো ফিল্ড সিলেক্ট হলে টেবিল AJAX দিয়ে লোড হবে (পেজ রিলোড ছাড়াই)
    function loadTable() {
        var classId = elClass.value, shiftId = elShift.value, sectionId = elSection.value,
            termId = elTerm.value, partId = elPart.value, subjectId = elSubject.value;
        if (classId == '0' || shiftId == '0' || sectionId == '0' || termId == '0' || partId == '0' || subjectId == '0') {
            clearTable();
            return;
        }

        elWrap.innerHTML = '<div class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin me-2"></i>লোড হচ্ছে...</div>';

        fetch(rasAjaxUrl('table', { class_id: classId, shift_id: shiftId, section_id: sectionId, term_id: termId, part_id: partId, subject_id: subjectId }))
            .then(function (r) { return r.text(); })
            .then(function (html) { elWrap.innerHTML = html; })
            .catch(function () {
                elWrap.innerHTML = '<div class="text-center text-danger py-4">ডাটা লোড করতে সমস্যা হয়েছে, আবার চেষ্টা করুন।</div>';
            });
    }

    elClass.addEventListener('change', function () {
        loadSections();
        loadSubjects();
    });
    elShift.addEventListener('change', loadSections);
    elSection.addEventListener('change', loadTable);
    elTerm.addEventListener('change', loadTable);
    elPart.addEventListener('change', loadTable);
    elSubject.addEventListener('change', loadTable);
})();
</script>
