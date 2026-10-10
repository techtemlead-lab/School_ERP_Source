<?php
// admin/result_marks_entry_exam.php - Result Module > Exam Procedure > Marks Entry (Exam)
// Class + Shift + Section + Exam Term + Exam Part সিলেক্ট করলে ঐ Class-এর সব Subject
// একসাথে কলাম আকারে দেখাবে, প্রতিটা স্টুডেন্টের সব সাবজেক্টের মার্ক একই গ্রিডে দেওয়া যাবে।
// যে সাবজেক্টের জন্য এই Term/Part-এ এখনো "Assign Exam" পেজ থেকে Full Mark সেট করা হয়নি,
// সেই কলাম disabled/grey দেখাবে (স্ক্রিনশট অনুযায়ী) — আগে Assign Exam পেজে সেট করতে হবে।
require_once __DIR__ . '/../config/report_card_helpers.php';
require_once __DIR__ . '/../config/exam_att_grid.php';

$mx_err = $_SESSION['mx_err'] ?? '';
$mx_msg = $_SESSION['mx_msg'] ?? '';
unset($_SESSION['mx_err'], $_SESSION['mx_msg']);

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
// টেবিল না থাকলে অটো তৈরি (Marks Entry (Subject) পেজের সাথে একই টেবিল শেয়ার করা হয়)
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_marks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        term_id INT NOT NULL,
        part_id INT NOT NULL,
        subject_id INT NOT NULL,
        class_id INT NOT NULL,
        shift_id INT NOT NULL,
        section_id INT NOT NULL,
        student_id INT NOT NULL,
        marks_obtained DECIMAL(6,2) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_mark (term_id, part_id, subject_id, student_id),
        KEY idx_lookup (class_id, shift_id, section_id, term_id, part_id, subject_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

function mxv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function mxnum($val): string { return rtrim(rtrim(number_format((float) $val, 2), '0'), '.'); }

// -----------------------------------------------------------
// POST Handler - একসাথে সব স্টুডেন্টের সব (assigned) সাবজেক্টের মার্ক সেভ
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_marks'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['mx_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_marks_entry_exam');
        exit();
    }

    $class_id   = (int) ($_POST['class_id'] ?? 0);
    $shift_id   = (int) ($_POST['shift_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $term_id    = (int) ($_POST['term_id'] ?? 0);
    $part_id    = (int) ($_POST['part_id'] ?? 0);
    $marks      = (array) ($_POST['marks'] ?? []); // marks[subject_id][student_id] = value

    if (!$class_id || !$shift_id || !$section_id || !$term_id || !$part_id) {
        $_SESSION['mx_err'] = 'Class, Shift, Section, Exam Term এবং Exam Part সিলেক্ট করুন।';
    } elseif (rc_is_result_locked($pdo, $current_session_id, $class_id, $term_id)) {
        // এই Class+Term-এর রেজাল্ট Admin কর্তৃক Lock করা থাকলে মার্ক সেভ করা যাবে না, যতক্ষণ না
        // Result Module > Exam Settings > Result Lock/Unlock পেজ থেকে আবার Unlock করা হয়
        $_SESSION['mx_err'] = 'এই Class-এর এই Term-এর রেজাল্ট Lock করা আছে, তাই মার্ক এন্ট্রি/এডিট করা যাবে না। আগে Result Lock/Unlock পেজ থেকে Unlock করুন।';
    } else {
        // এই Term/Part-এ এই Class-এর কোন কোন Subject আসলে assign করা আছে (full mark সহ), সার্ভার-সাইডে যাচাই
        $assign_stmt = $pdo->prepare(
            "SELECT subject_id, full_mark_exam FROM exam_assign_subjects WHERE term_id = ? AND part_id = ? AND class_id = ? AND status = 'Active'"
        );
        $assign_stmt->execute([$term_id, $part_id, $class_id]);
        $assigned = [];
        foreach ($assign_stmt->fetchAll() as $row) {
            $assigned[(int) $row['subject_id']] = (float) $row['full_mark_exam'];
        }

        $stmt = $pdo->prepare(
            "INSERT INTO exam_marks
                (session_id, term_id, part_id, subject_id, class_id, shift_id, section_id, student_id, marks_obtained)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE marks_obtained = VALUES(marks_obtained)"
        );

        // Eligibility + Attendance (Present) গেট — ব্রাউজার এড়িয়ে POST করলেও সার্ভার আটকাবে
        $post_stids = [];
        foreach ($marks as $_sj => $_sm) { foreach ((array) $_sm as $_st => $_v) { $post_stids[(int) $_st] = true; } }
        $gate = ea_mark_states($pdo, $class_id, $shift_id, $section_id, $term_id, $part_id, array_keys($post_stids), array_keys($assigned));

        $count = 0;
        $bad_range = 0;
        $blocked = 0;
        foreach ($marks as $subj_id => $stu_marks) {
            $subj_id = (int) $subj_id;
            if (!isset($assigned[$subj_id])) { continue; } // এই সাবজেক্ট assign করা নেই — বাদ
            $full_mark = $assigned[$subj_id];
            foreach ((array) $stu_marks as $stid => $m) {
                $stid = (int) $stid;
                $m = trim((string) $m);
                if ($stid <= 0) { continue; }
                if (($gate['state'][$stid][$subj_id] ?? 'noelig') !== 'open') { $blocked++; continue; } // Eligible নয় / Attendance নেই / Absent
                if ($m === '') {
                    $stmt->execute([$current_session_id, $term_id, $part_id, $subj_id, $class_id, $shift_id, $section_id, $stid, null]);
                    $count++;
                    continue;
                }
                if (!is_numeric($m) || (float) $m < 0 || (float) $m > $full_mark) {
                    $bad_range++;
                    continue;
                }
                $stmt->execute([$current_session_id, $term_id, $part_id, $subj_id, $class_id, $shift_id, $section_id, $stid, (float) $m]);
                $count++;
            }
        }
        sec_log_activity($pdo, 'Result', 'Update', "ক্লাস আইডি {$class_id}, টার্ম আইডি {$term_id}, পার্ট আইডি {$part_id} এর Marks Entry (Exam) সংরক্ষণ করা হয়েছে");
        $_SESSION['mx_msg'] = $count . ' টা মার্ক সেভ করা হয়েছে।' . ($bad_range ? ' (' . $bad_range . ' টা মার্ক Full Mark এর বাইরে থাকায় বাদ দেওয়া হয়েছে।)' : '') . ($blocked ? ' (' . $blocked . ' টা ঘর Eligibility/Attendance (Present) না থাকায় বাদ দেওয়া হয়েছে।)' : '');
    }

    header('Location: index.php?page=result_marks_entry_exam&class_id=' . $class_id . '&shift_id=' . $shift_id . '&section_id=' . $section_id . '&term_id=' . $term_id . '&part_id=' . $part_id . '&group_id=' . (int) ($_POST['group_id'] ?? 0));
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
$sel_group   = (int) ($_GET['group_id'] ?? 0);
$mg_groups   = $sel_class > 0 ? ee_groups_of_class($pdo, $sel_class) : [];
if ($mg_groups && !isset($mg_groups[$sel_group])) $sel_group = 0;

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
// সিলেক্টেড কম্বিনেশনের জন্য: এই Class-এর সব Subject (কলাম) + Student লিস্ট (রো) + বিদ্যমান মার্ক
// -----------------------------------------------------------
$all_selected = ($sel_class > 0 && $sel_shift > 0 && $sel_section > 0 && $sel_term > 0 && $sel_part > 0);
$need_group = ($all_selected && $mg_groups && $sel_group <= 0);
if ($need_group) $all_selected = false;
$subjects_list = [];
$students = [];
$marks_map = []; // marks_map[subject_id][student_id] = mark

if ($all_selected) {
    $subj_stmt = $pdo->prepare("SELECT id, subject_name, full_marks, group_id FROM academic_subjects WHERE class_id=? AND status='Active' ORDER BY sort_order ASC, id ASC");
    $subj_stmt->execute([$sel_class]);
    $subjects_list = $subj_stmt->fetchAll();
    if ($mg_groups) { $GS = $sel_group; $subjects_list = array_values(array_filter($subjects_list, function ($r) use ($GS) { $g = (int) ($r['group_id'] ?? 0); return $g === 0 || $g === $GS; })); }

    $assign_stmt = $pdo->prepare("SELECT subject_id, full_mark_exam FROM exam_assign_subjects WHERE term_id=? AND part_id=? AND class_id=?");
    $assign_stmt->execute([$sel_term, $sel_part, $sel_class]);
    $assigned_full = [];
    foreach ($assign_stmt->fetchAll() as $row) {
        $assigned_full[(int) $row['subject_id']] = (float) $row['full_mark_exam'];
    }
    foreach ($subjects_list as &$sub) {
        $sub['is_assigned'] = isset($assigned_full[(int) $sub['id']]);
        $sub['full_mark']   = $sub['is_assigned'] ? $assigned_full[(int) $sub['id']] : (float) $sub['full_marks'];
    }
    unset($sub);

    $me_where = ['class_id=?', 'shift_id=?', 'section_id=?', "status='Active'"];
    $me_params = [$sel_class, $sel_shift, $sel_section];
    if ($mg_groups) { $me_where[] = 'group_id=?'; $me_params[] = $sel_group; }
    branch_apply_filter($me_where, $me_params);
    $stu_stmt = $pdo->prepare("SELECT id, student_id, roll_no, name_en FROM students WHERE " . implode(' AND ', $me_where) . " ORDER BY (roll_no + 0) ASC, roll_no ASC");
    $stu_stmt->execute($me_params);
    $students = $stu_stmt->fetchAll();

    if ($subjects_list && $students) {
        $subject_ids = array_column($subjects_list, 'id');
        $in_place = implode(',', array_fill(0, count($subject_ids), '?'));
        $mk_stmt = $pdo->prepare(
            "SELECT subject_id, student_id, marks_obtained FROM exam_marks
             WHERE term_id = ? AND part_id = ? AND subject_id IN ($in_place)"
        );
        $mk_stmt->execute(array_merge([$sel_term, $sel_part], $subject_ids));
        foreach ($mk_stmt->fetchAll() as $row) {
            $marks_map[(int) $row['subject_id']][(int) $row['student_id']] = $row['marks_obtained'];
        }
    }
}
?>

<style>
.mxe-input:disabled { background: #eceff3; color: #9aa2ad; }
.mxe-input { width: 90px; }
</style>

<div class="card-custom">
    <?php if ($mx_msg): ?><div class="alert alert-success py-2"><?php echo mxv($mx_msg); ?></div><?php endif; ?>
    <?php if ($mx_err): ?><div class="alert alert-danger py-2"><?php echo mxv($mx_err); ?></div><?php endif; ?>

    <form method="GET" action="index.php" id="filterForm">
        <input type="hidden" name="page" value="result_marks_entry_exam">

        <div class="row mb-3 g-3">
            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Class <span class="text-danger">*</span></label>
                    <select name="class_id" id="mxe_class" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>" <?php echo $sel_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo mxv($c['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Shift <span class="text-danger">*</span></label>
                    <select name="shift_id" id="mxe_shift" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($shifts as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo $sel_shift === (int) $s['id'] ? 'selected' : ''; ?>><?php echo mxv($s['shift_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Section <span class="text-danger">*</span></label>
                    <select name="section_id" id="mxe_section" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($sections as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo $sel_section === (int) $s['id'] ? 'selected' : ''; ?>><?php echo mxv($s['section_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="mxe_section_hint" class="text-danger small text-end" <?php echo ($sel_class > 0 && $sel_shift > 0) ? 'style="display:none;"' : ''; ?>>আগে Class ও Shift সিলেক্ট করুন</div>
            </div>
        </div>

        <div class="row mb-3 g-3" id="mxe_group_row" <?php echo $mg_groups ? '' : 'style="display:none;"'; ?>>
            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Group <span class="text-danger">*</span></label>
                    <select name="group_id" id="mxe_group" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($mg_groups as $gid => $gname): ?>
                            <option value="<?php echo (int) $gid; ?>" <?php echo $sel_group === (int) $gid ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $gname, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="row mb-3 g-3">
            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Exam Term <span class="text-danger">*</span></label>
                    <select name="term_id" id="mxe_term" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($terms as $t): ?>
                            <option value="<?php echo (int) $t['id']; ?>" <?php echo $sel_term === (int) $t['id'] ? 'selected' : ''; ?>><?php echo mxv($t['term_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Exam Part <span class="text-danger">*</span></label>
                    <select name="part_id" id="mxe_part" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($parts as $p): ?>
                            <option value="<?php echo (int) $p['id']; ?>" <?php echo $sel_part === (int) $p['id'] ? 'selected' : ''; ?>><?php echo mxv($p['part_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
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
        <h6 class="mb-0">Students List for Marks Entry</h6>
    </div>

    <div id="mxe_result_wrap">
    <?php if ($need_group): ?>
        <?php echo ea_group_need_msg('Mark Entry'); ?>
    <?php elseif (!$all_selected): ?>
        <div class="text-center text-muted py-4">উপরে Class, Shift, Section, Exam Term এবং Exam Part সিলেক্ট করুন — তাহলে স্টুডেন্ট লিস্ট এখানে দেখাবে।</div>
    <?php elseif (!$subjects_list): ?>
        <div class="text-center text-muted py-4">এই Class-এর জন্য কোনো Subject পাওয়া যায়নি। আগে <a href="index.php?page=academic_subject">Subject</a> যোগ করুন।</div>
    <?php elseif (!$students): ?>
        <div class="text-center text-muted py-4">এই Class/Shift/Section এ কোনো Active Student পাওয়া যায়নি।</div>
    <?php else: ?>
        <?php mx_render_exam_form($pdo, $sel_class, $sel_shift, $sel_section, $sel_term, $sel_part, $sel_group, $subjects_list, $students, $marks_map); ?>
    <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var elClass   = document.getElementById('mxe_class');
    var elShift   = document.getElementById('mxe_shift');
    var elSection = document.getElementById('mxe_section');
    var elTerm    = document.getElementById('mxe_term');
    var elPart    = document.getElementById('mxe_part');
    var elHint    = document.getElementById('mxe_section_hint');
    var elGroup   = document.getElementById('mxe_group');
    var elGroupRow = document.getElementById('mxe_group_row');
    function setGroups(list) {
        elGroup.innerHTML = '<option value="0">-- Select --</option>';
        list.forEach(function (g) { var o = document.createElement('option'); o.value = g.id; o.textContent = g.name; elGroup.appendChild(o); });
        elGroupRow.style.display = list.length ? '' : 'none';
    }
    var elWrap    = document.getElementById('mxe_result_wrap');

    function mxeAjaxUrl(action, extra) {
        var p = new URLSearchParams(extra || {});
        return 'ajax/result_marks_entry_exam_ajax.php?action=' + action + '&' + p.toString();
    }

    function clearTable() {
        elWrap.innerHTML = '<div class="text-center text-muted py-4">উপরে Class, Shift, Section, Exam Term এবং Exam Part সিলেক্ট করুন — তাহলে স্টুডেন্ট লিস্ট এখানে দেখাবে।</div>';
    }

    // Class/Shift বদলালে Section ড্রপডাউন AJAX দিয়ে রিলোড হবে (পেজ রিলোড ছাড়াই)
    function loadSections() {
        var classId = elClass.value, shiftId = elShift.value;
        elSection.innerHTML = '<option value="0">-- Select --</option>';
        setGroups([]);
        clearTable();

        if (classId == '0' || shiftId == '0') {
            elHint.style.display = '';
            return;
        }
        elHint.style.display = 'none';

        fetch(mxeAjaxUrl('sections', { class_id: classId, shift_id: shiftId }))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                data.sections.forEach(function (s) {
                    var opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.name;
                    elSection.appendChild(opt);
                });
                setGroups(data.groups || []);
            })
            .catch(function () { /* নেটওয়ার্ক সমস্যা হলে চুপচাপ থেমে যাওয়া, ইউজার আবার সিলেক্ট করলে রিট্রাই হবে */ });
    }

    // পাঁচটা ফিল্ডই সিলেক্ট হলে টেবিল AJAX দিয়ে লোড হবে (পেজ রিলোড ছাড়াই)
    function loadTable() {
        var classId = elClass.value, shiftId = elShift.value, sectionId = elSection.value, termId = elTerm.value, partId = elPart.value;
        if (classId == '0' || shiftId == '0' || sectionId == '0' || termId == '0' || partId == '0') {
            clearTable();
            return;
        }

        elWrap.innerHTML = '<div class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin me-2"></i>লোড হচ্ছে...</div>';

        fetch(mxeAjaxUrl('table', { class_id: classId, shift_id: shiftId, section_id: sectionId, term_id: termId, part_id: partId, group_id: elGroup.value }))
            .then(function (r) { return r.text(); })
            .then(function (html) { elWrap.innerHTML = html; })
            .catch(function () {
                elWrap.innerHTML = '<div class="text-center text-danger py-4">ডাটা লোড করতে সমস্যা হয়েছে, আবার চেষ্টা করুন।</div>';
            });
    }

    elClass.addEventListener('change', function () { loadSections(); });
    elShift.addEventListener('change', function () { loadSections(); });
    elSection.addEventListener('change', loadTable);
    elTerm.addEventListener('change', loadTable);
    elPart.addEventListener('change', loadTable);
    elGroup.addEventListener('change', loadTable);
    // Full Mark-এর বেশি মার্ক টাইপ করলে সাথে সাথে লাল হবে, এবং Save আটকে যাবে
    function markGuardCheck(inp) {
        var max = parseFloat(inp.getAttribute('max'));
        var v = parseFloat(inp.value);
        var bad = !isNaN(max) && !isNaN(v) && (v > max || v < 0);
        inp.classList.toggle('is-invalid', bad);
        var text = 'Full Mark ' + max + '-এর বেশি দেওয়া যাবে না';
        inp.setCustomValidity(bad ? text : '');
        // ইনপুটের ঠিক নিচে লাল এরর মেসেজ
        var msg = inp.nextElementSibling;
        if (!msg || !msg.classList || !msg.classList.contains('mark-guard-msg')) {
            msg = document.createElement('div');
            msg.className = 'mark-guard-msg';
            msg.style.cssText = 'color:#dc3545;font-size:11px;line-height:1.2;margin-top:3px;text-align:right;display:none;';
            inp.parentNode.insertBefore(msg, inp.nextSibling);
        }
        msg.textContent = bad ? text : '';
        msg.style.display = bad ? 'block' : 'none';
        return !bad;
    }
    elWrap.addEventListener('input', function (e) {
        if (e.target && e.target.matches && e.target.matches('input[type="number"][max]')) { markGuardCheck(e.target); }
    });
    elWrap.addEventListener('submit', function (e) {
        var bad = null;
        e.target.querySelectorAll('input[type="number"][max]').forEach(function (inp) {
            if (!markGuardCheck(inp) && !bad) { bad = inp; }
        });
        if (bad) { e.preventDefault(); alert('কিছু মার্ক Full Mark-এর চেয়ে বেশি (লাল ঘর)। ঠিক করে আবার Save করুন।'); bad.focus(); }
    }, true);
})();
</script>
