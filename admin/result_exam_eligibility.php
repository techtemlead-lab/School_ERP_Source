<?php
// admin/result_exam_eligibility.php - Result Module > Exam Procedure > Exam Eligibility
// Class + Shift + Section + Exam Term সিলেক্ট করলে ঐ ক্লাসের স্টুডেন্ট লিস্ট আসবে,
// প্রতিটা সাবজেক্টের জন্য চেকবক্স দিয়ে ঠিক করা যাবে কোন স্টুডেন্ট কোন সাবজেক্টে
// পরীক্ষা দেয়ার জন্য Eligible। কলাম হেডারের রঙ অনুযায়ী সাবজেক্টের ধরন বোঝা যায়:
//   কালো  = Main (Compulsory) subjects
//   গোলাপি = Class wise Optional subjects (group_id ফাঁকা)
//   সোনালি = Student wise Optional subjects (নির্দিষ্ট Group এর সাথে সংযুক্ত)
require_once __DIR__ . '/../config/exam_elig_grid.php'; // Group-wise গ্রিড + সেভ
$ee_err = $_SESSION['ee_err'] ?? '';
$ee_msg = $_SESSION['ee_msg'] ?? '';
unset($_SESSION['ee_err'], $_SESSION['ee_msg']);

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
    "CREATE TABLE IF NOT EXISTS exam_eligibility (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        term_id INT NOT NULL,
        class_id INT NOT NULL,
        shift_id INT NOT NULL,
        section_id INT NOT NULL,
        student_id INT NOT NULL,
        subject_id INT NOT NULL,
        is_eligible TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_elig (term_id, student_id, subject_id),
        KEY idx_lookup (class_id, shift_id, section_id, term_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;
$current_session_name = $current_session['session_name'] ?? '—';

function eev($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// POST Handler - একসাথে সব স্টুডেন্টের এলিজিবিলিটি সেভ
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_eligibility'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ee_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_exam_eligibility');
        exit();
    }

    $class_id   = (int) ($_POST['class_id'] ?? 0);
    $shift_id   = (int) ($_POST['shift_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $term_id    = (int) ($_POST['term_id'] ?? 0);
    $student_ids = $_POST['student_id'] ?? [];
    $subject_ids = $_POST['grid_subject_id'] ?? [];
    $elig_map    = (array) ($_POST['elig'] ?? []); // elig[student_id][subject_id] = '1'

    if ($class_id <= 0 || $shift_id <= 0 || $section_id <= 0 || $term_id <= 0 || !$student_ids || !$subject_ids) {
        $_SESSION['ee_err'] = 'Class, Shift, Section এবং Exam Term সিলেক্ট করুন।';
    } else {
        [$n_students, $count] = ee_save($pdo, $current_session_id, $term_id, $class_id, $shift_id, $section_id, (array) $student_ids, (array) $subject_ids, $elig_map);
        sec_log_activity($pdo, 'Result', 'Update', "ক্লাস আইডি {$class_id}, টার্ম আইডি {$term_id} এর {$count} টি Exam Eligibility এন্ট্রি সংরক্ষণ করা হয়েছে");
        $_SESSION['ee_msg'] = $n_students . ' জন শিক্ষার্থীর Exam Eligibility সেভ করা হয়েছে।';
    }

    header('Location: index.php?page=result_exam_eligibility&class_id=' . $class_id . '&shift_id=' . $shift_id . '&section_id=' . $section_id . '&term_id=' . $term_id . '&group_id=' . (int) ($_POST['group_id'] ?? 0));
    exit();
}

// -----------------------------------------------------------
// Filter dropdown ডাটা
// -----------------------------------------------------------
$sel_class   = (int) ($_GET['class_id'] ?? 0);
$sel_shift   = (int) ($_GET['shift_id'] ?? 0);
$sel_section = (int) ($_GET['section_id'] ?? 0);
$sel_term    = (int) ($_GET['term_id'] ?? 0);
$sel_group   = (int) ($_GET['group_id'] ?? 0);
$ee_groups   = $sel_class > 0 ? ee_groups_of_class($pdo, $sel_class) : []; // Group-ওয়ালা Class (Nine/Ten) হলে Group ফিল্টার দেখাবে

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

?>

<style>
.sub-main .sub-th-dot        { background: #1f2937; }
.sub-classwise .sub-th-dot   { background: #f4a6b8; }
.sub-studentwise .sub-th-dot { background: #c99a1e; }
.sub-th-dot { width: 16px; height: 16px; border-radius: 3px; display: inline-block; }
.sub-classwise th, .sub-classwise .sub-name { color: #e0658a; }
.sub-studentwise th, .sub-studentwise .sub-name { color: #b8860b; }
.elig-legend-item { display: flex; flex-direction: column; align-items: center; gap: 6px; font-size: 13px; }
</style>

<div class="card-custom">
    <?php if ($ee_msg): ?><div class="alert alert-success py-2"><?php echo eev($ee_msg); ?></div><?php endif; ?>
    <?php if ($ee_err): ?><div class="alert alert-danger py-2"><?php echo eev($ee_err); ?></div><?php endif; ?>

    <form method="GET" action="index.php" id="filterForm">
        <input type="hidden" name="page" value="result_exam_eligibility">

        <div class="row mb-3 align-items-center">
            <label class="col-sm-2 col-form-label fw-bold text-end">Class <span class="text-danger">*</span></label>
            <div class="col-sm-3">
                <select name="class_id" id="ee_class" class="form-select">
                    <option value="0">-- Select --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $sel_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo eev($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <label class="col-sm-2 col-form-label fw-bold text-end">Shift <span class="text-danger">*</span></label>
            <div class="col-sm-3">
                <select name="shift_id" id="ee_shift" class="form-select">
                    <option value="0">-- Select --</option>
                    <?php foreach ($shifts as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $sel_shift === (int) $s['id'] ? 'selected' : ''; ?>><?php echo eev($s['shift_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-2 col-form-label fw-bold text-end">Section <span class="text-danger">*</span></label>
            <div class="col-sm-3">
                <select name="section_id" id="ee_section" class="form-select">
                    <option value="0">-- Select --</option>
                    <?php foreach ($sections as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $sel_section === (int) $s['id'] ? 'selected' : ''; ?>><?php echo eev($s['section_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <span id="ee_section_hint" class="text-danger small" <?php echo ($sel_class > 0 && $sel_shift > 0) ? 'style="display:none;"' : ''; ?>>আগে Class ও Shift সিলেক্ট করুন</span>
            </div>

            <label class="col-sm-2 col-form-label fw-bold text-end">Exam Term <span class="text-danger">*</span></label>
            <div class="col-sm-3">
                <select name="term_id" id="ee_term" class="form-select">
                    <option value="0">-- Select --</option>
                    <?php foreach ($terms as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php echo $sel_term === (int) $t['id'] ? 'selected' : ''; ?>><?php echo eev($t['term_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-sm-2">
                <button type="submit" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Submit</button>
            </div>
        </div>

        <!-- Group ফিল্টার: শুধু Group-ওয়ালা Class (Nine/Ten ইত্যাদি) হলে দেখায় -->
        <div class="row mb-1 align-items-center" id="ee_group_row" <?php echo $ee_groups ? '' : 'style="display:none;"'; ?>>
            <label class="col-sm-2 col-form-label fw-bold text-end">Group</label>
            <div class="col-sm-3">
                <select name="group_id" id="ee_group" class="form-select">
                    <option value="0">সব Group (All Groups)</option>
                    <?php foreach ($ee_groups as $gid => $gname): ?>
                        <option value="<?php echo (int) $gid; ?>" <?php echo $sel_group === (int) $gid ? 'selected' : ''; ?>><?php echo eev($gname); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-7 small text-muted">প্রতিটা শিক্ষার্থী শুধু নিজের Group-এর (ও কমন) Subject-এ Eligible হবে। Group বাছলে শুধু সেই Group-এর শিক্ষার্থী ও Subject দেখাবে।</div>
        </div>
    </form>
</div>

<div class="card-custom mt-3">
    <div class="d-flex align-items-center gap-2 mb-3">
        <i class="fa-solid fa-user-plus text-primary"></i>
        <h6 class="mb-0">Student List for Exam Eligibility</h6>
    </div>

    <div id="ee_result_wrap">
        <?php ee_render_grid($pdo, $sel_class, $sel_shift, $sel_section, $sel_term, $sel_group); ?>
    </div>
</div>

<script>
function toggleColumn(subjectId, dotEl) {
    var boxes = document.querySelectorAll('.elig-col-' + subjectId);
    if (!boxes.length) return;
    var allChecked = Array.prototype.every.call(boxes, function (b) { return b.checked; });
    boxes.forEach(function (b) { b.checked = !allChecked; });
    dotEl.style.opacity = allChecked ? '0.4' : '1';
}

(function () {
    var elClass   = document.getElementById('ee_class');
    var elShift   = document.getElementById('ee_shift');
    var elSection = document.getElementById('ee_section');
    var elTerm    = document.getElementById('ee_term');
    var elHint    = document.getElementById('ee_section_hint');
    var elGroup   = document.getElementById('ee_group');
    var elGroupRow = document.getElementById('ee_group_row');
    var elWrap    = document.getElementById('ee_result_wrap');

    function eeAjaxUrl(action, extra) {
        var p = new URLSearchParams(extra || {});
        return 'ajax/result_exam_eligibility_ajax.php?action=' + action + '&' + p.toString();
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

        fetch(eeAjaxUrl('sections', { class_id: classId, shift_id: shiftId }))
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

    // Class বদলালে সেই Class-এর Group তালিকা (না থাকলে Group ফিল্টার লুকানো)
    function setGroups(list) {
        if (!elGroup) return;
        elGroup.innerHTML = '<option value="0">সব Group (All Groups)</option>';
        list.forEach(function (g) {
            var o = document.createElement('option'); o.value = g.id; o.textContent = g.name; elGroup.appendChild(o);
        });
        elGroupRow.style.display = list.length ? '' : 'none';
    }

    function clearTable() {
        elWrap.innerHTML = '<div class="text-center text-muted py-4">উপরে Class, Shift, Section এবং Exam Term সিলেক্ট করুন — তাহলে ঐ ক্লাসের সব Student ও Subject এখানে দেখাবে।</div>';
    }

    // চারটা ফিল্ডই সিলেক্ট হলে টেবিল AJAX দিয়ে লোড হবে (পেজ রিলোড ছাড়াই)
    function loadTable() {
        var classId = elClass.value, shiftId = elShift.value, sectionId = elSection.value, termId = elTerm.value;
        if (classId == '0' || shiftId == '0' || sectionId == '0' || termId == '0') {
            clearTable();
            return;
        }

        elWrap.innerHTML = '<div class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin me-2"></i>লোড হচ্ছে...</div>';

        fetch(eeAjaxUrl('table', { class_id: classId, shift_id: shiftId, section_id: sectionId, term_id: termId, group_id: elGroup ? elGroup.value : 0 }))
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
    if (elGroup) elGroup.addEventListener('change', loadTable);
})();
</script>
