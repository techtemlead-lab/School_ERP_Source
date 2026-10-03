<?php
// admin/result_exam_eligibility.php - Result Module > Exam Procedure > Exam Eligibility
// Class + Shift + Section + Exam Term সিলেক্ট করলে ঐ ক্লাসের স্টুডেন্ট লিস্ট আসবে,
// প্রতিটা সাবজেক্টের জন্য চেকবক্স দিয়ে ঠিক করা যাবে কোন স্টুডেন্ট কোন সাবজেক্টে
// পরীক্ষা দেয়ার জন্য Eligible। কলাম হেডারের রঙ অনুযায়ী সাবজেক্টের ধরন বোঝা যায়:
//   কালো  = Main (Compulsory) subjects
//   গোলাপি = Class wise Optional subjects (group_id ফাঁকা)
//   সোনালি = Student wise Optional subjects (নির্দিষ্ট Group এর সাথে সংযুক্ত)
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
        $stmt = $pdo->prepare(
            "INSERT INTO exam_eligibility (session_id, term_id, class_id, shift_id, section_id, student_id, subject_id, is_eligible)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_eligible = VALUES(is_eligible)"
        );
        $count = 0;
        foreach ($student_ids as $stid) {
            $stid = (int) $stid;
            foreach ($subject_ids as $sid) {
                $sid = (int) $sid;
                $is_elig = isset($elig_map[$stid][$sid]) && $elig_map[$stid][$sid] === '1' ? 1 : 0;
                $stmt->execute([$current_session_id, $term_id, $class_id, $shift_id, $section_id, $stid, $sid, $is_elig]);
                $count++;
            }
        }
        sec_log_activity($pdo, 'Result', 'Update', "ক্লাস আইডি {$class_id}, টার্ম আইডি {$term_id} এর {$count} টি Exam Eligibility এন্ট্রি সংরক্ষণ করা হয়েছে");
        $_SESSION['ee_msg'] = count($student_ids) . ' জন শিক্ষার্থীর Exam Eligibility সেভ করা হয়েছে।';
    }

    header('Location: index.php?page=result_exam_eligibility&class_id=' . $class_id . '&shift_id=' . $shift_id . '&section_id=' . $section_id . '&term_id=' . $term_id);
    exit();
}

// -----------------------------------------------------------
// Filter dropdown ডাটা
// -----------------------------------------------------------
$sel_class   = (int) ($_GET['class_id'] ?? 0);
$sel_shift   = (int) ($_GET['shift_id'] ?? 0);
$sel_section = (int) ($_GET['section_id'] ?? 0);
$sel_term    = (int) ($_GET['term_id'] ?? 0);

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

// -----------------------------------------------------------
// সিলেক্টেড কম্বিনেশনের জন্য Subject + Student লিস্ট + বিদ্যমান এলিজিবিলিটি
// -----------------------------------------------------------
$all_selected = ($sel_class > 0 && $sel_shift > 0 && $sel_section > 0 && $sel_term > 0);
$subjects = [];
$students = [];
$existing = []; // [student_id][subject_id] = is_eligible

if ($all_selected) {
    $sub_stmt = $pdo->prepare(
        "SELECT id, subject_name, subject_type, group_id FROM academic_subjects
         WHERE class_id=? AND status='Active'
         ORDER BY (subject_type='Compulsory') DESC, subject_name ASC"
    );
    $sub_stmt->execute([$sel_class]);
    $subjects = $sub_stmt->fetchAll();

    $ree_where = ['class_id=?', 'shift_id=?', 'section_id=?', "status='Active'"];
    $ree_params = [$sel_class, $sel_shift, $sel_section];
    branch_apply_filter($ree_where, $ree_params);
    $stu_stmt = $pdo->prepare(
        "SELECT id, roll_no, name_en, group_id, religion FROM students
         WHERE " . implode(' AND ', $ree_where) . "
         ORDER BY (roll_no + 0) ASC, roll_no ASC"
    );
    $stu_stmt->execute($ree_params);
    $students = $stu_stmt->fetchAll();

    if ($subjects && $students) {
        $ex_stmt = $pdo->prepare("SELECT student_id, subject_id, is_eligible FROM exam_eligibility WHERE term_id=? AND class_id=? AND shift_id=? AND section_id=?");
        $ex_stmt->execute([$sel_term, $sel_class, $sel_shift, $sel_section]);
        foreach ($ex_stmt->fetchAll() as $row) {
            $existing[(int) $row['student_id']][(int) $row['subject_id']] = (int) $row['is_eligible'];
        }
    }
}

function subject_color_class($sub): string {
    if ($sub['subject_type'] === 'Compulsory') return 'sub-main';
    if ($sub['subject_type'] === 'Optional' && empty($sub['group_id'])) return 'sub-classwise';
    return 'sub-studentwise';
}
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
    </form>
</div>

<div class="card-custom mt-3">
    <div class="d-flex align-items-center gap-2 mb-3">
        <i class="fa-solid fa-user-plus text-primary"></i>
        <h6 class="mb-0">Student List for Exam Eligibility</h6>
    </div>

    <div id="ee_result_wrap">
        <?php if (!$all_selected): ?>
            <div class="text-center text-muted py-4">উপরে Class, Shift, Section এবং Exam Term সিলেক্ট করুন — তাহলে ঐ ক্লাসের সব Student ও Subject এখানে দেখাবে।</div>
        <?php elseif (!$subjects): ?>
            <div class="text-center text-muted py-4">এই ক্লাসের জন্য কোনো Subject পাওয়া যায়নি। প্রথমে Academic Module &gt; Subject থেকে Subject যোগ করুন।</div>
        <?php elseif (!$students): ?>
            <div class="text-center text-muted py-4">এই Class/Shift/Section এ কোনো Active Student পাওয়া যায়নি।</div>
        <?php else: ?>

            <div class="d-flex justify-content-center gap-5 mb-4">
                <div class="elig-legend-item"><span class="sub-th-dot" style="background:#1f2937;"></span><span>(Main subjects)</span></div>
                <div class="elig-legend-item"><span class="sub-th-dot" style="background:#f4a6b8;"></span><span class="text-danger">(Class wise optional subjects)</span></div>
                <div class="elig-legend-item"><span class="sub-th-dot" style="background:#c99a1e;"></span><span style="color:#b8860b;">(Student wise optional subjects)</span></div>
            </div>

            <form method="POST" action="index.php?page=result_exam_eligibility">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
                <input type="hidden" name="shift_id" value="<?php echo $sel_shift; ?>">
                <input type="hidden" name="section_id" value="<?php echo $sel_section; ?>">
                <input type="hidden" name="term_id" value="<?php echo $sel_term; ?>">
                <?php foreach ($subjects as $sub): ?>
                    <input type="hidden" name="grid_subject_id[]" value="<?php echo (int) $sub['id']; ?>">
                <?php endforeach; ?>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center">
                        <thead class="table-light">
                            <tr>
                                <th>Sl.</th>
                                <th class="text-start">Name</th>
                                <th>Roll</th>
                                <th>Group</th>
                                <th>Religion</th>
                                <?php foreach ($subjects as $sub): $cls = subject_color_class($sub); ?>
                                    <th class="<?php echo $cls; ?>">
                                        <span class="sub-th-dot mb-1" style="cursor:pointer;" title="Select/Unselect All" onclick="toggleColumn(<?php echo (int) $sub['id']; ?>, this)"></span>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $srl = 1; foreach ($students as $stu): $stid = (int) $stu['id']; ?>
                                <tr>
                                    <td><?php echo $srl++; ?></td>
                                    <td class="text-start"><?php echo eev($stu['name_en']); ?></td>
                                    <td><?php echo eev($stu['roll_no']); ?></td>
                                    <td><?php echo eev($stu['group_id'] ?: 'N/A'); ?></td>
                                    <td><?php echo eev($stu['religion']); ?></td>
                                    <input type="hidden" name="student_id[]" value="<?php echo $stid; ?>">
                                    <?php foreach ($subjects as $sub):
                                        $sid = (int) $sub['id'];
                                        $cls = subject_color_class($sub);
                                        $checked = array_key_exists($sid, $existing[$stid] ?? []) ? (bool) $existing[$stid][$sid] : true;
                                    ?>
                                        <td class="<?php echo $cls; ?>">
                                            <div class="small sub-name mb-1"><?php echo eev($sub['subject_name']); ?></div>
                                            <input type="checkbox" class="form-check-input elig-cb elig-col-<?php echo $sid; ?>" name="elig[<?php echo $stid; ?>][<?php echo $sid; ?>]" value="1" <?php echo $checked ? 'checked' : ''; ?>>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <hr>
                <div class="text-center">
                    <button type="submit" name="save_eligibility" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Save Eligibility</button>
                </div>
            </form>
        <?php endif; ?>
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
    var elWrap    = document.getElementById('ee_result_wrap');

    function eeAjaxUrl(action, extra) {
        var p = new URLSearchParams(extra || {});
        return 'ajax/result_exam_eligibility_ajax.php?action=' + action + '&' + p.toString();
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
            })
            .catch(function () { /* নেটওয়ার্ক সমস্যা হলে চুপচাপ থেমে যাওয়া, ইউজার আবার সিলেক্ট করলে রিট্রাই হবে */ });
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

        fetch(eeAjaxUrl('table', { class_id: classId, shift_id: shiftId, section_id: sectionId, term_id: termId }))
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
})();
</script>
