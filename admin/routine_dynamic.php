<?php
// admin/routine_dynamic.php - Dynamic Routine (স্ক্রিনশট অনুযায়ী)
// একই routines টেবিল ব্যবহার করে, তাই General Routine-এ যোগ করা এন্ট্রিও এখানে দেখা যাবে (আর উল্টোটাও)

$rd_err = $_SESSION['rd_err'] ?? '';
$rd_msg = $_SESSION['rd_msg'] ?? '';
unset($_SESSION['rd_err'], $_SESSION['rd_msg']);

function rdv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function fmt_time_rd($t): string { return $t ? date('h:i A', strtotime($t)) : ''; }

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি (routine_general.php ও routine_view.php একই টেবিল শেয়ার করে)
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS routines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NULL,
        shift_id INT NULL,
        section_id INT NOT NULL,
        routine_days VARCHAR(100) NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        has_break TINYINT(1) NOT NULL DEFAULT 0,
        subject_id INT NOT NULL,
        teacher_id INT NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_lookup (class_id, shift_id, section_id),
        KEY idx_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$week_days = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();

// একটা Class-এ কোন কোন Shift আছে সেটা academic_sections (class_id + shift_id) থেকে বের করা —
// Class সিলেক্ট না করা পর্যন্ত Shift/Section ফাঁকা/বন্ধ থাকবে
$rd_class_shifts = []; // shift_id => [class_id, class_id, ...]
foreach ($sections as $sec) {
    if ($sec['shift_id']) {
        $rd_class_shifts[(int) $sec['shift_id']][] = (int) $sec['class_id'];
    }
}
$subjects = $pdo->query("SELECT id, subject_name, class_id FROM academic_subjects WHERE status = 'Active' ORDER BY subject_name ASC")->fetchAll();
$teachers = $pdo->query("SELECT id, name FROM employees WHERE type = 'Teachers' AND status = 'Active' ORDER BY name ASC")->fetchAll();

// -----------------------------------------------------------
// Save (Create/Update — একটা দিনের একটা পিরিয়ড) / Delete
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_routine'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['rd_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=routine_dynamic');
        exit();
    }

    $id         = (int) ($_POST['id'] ?? 0);
    $class_id   = (int) ($_POST['class_id'] ?? 0);
    $shift_id   = (int) ($_POST['shift_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $day        = trim($_POST['day'] ?? '');
    $start_time = trim($_POST['start_time'] ?? '');
    $end_time   = trim($_POST['end_time'] ?? '');
    $has_break  = ($_POST['has_break'] ?? 'NO') === 'YES' ? 1 : 0;
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $teacher_id = (int) ($_POST['teacher_id'] ?? 0);
    $status     = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    $qs = 'class_id=' . $class_id . '&shift_id=' . $shift_id . '&section_id=' . $section_id;

    if (!$class_id || !$shift_id || !$section_id || !in_array($day, $week_days, true) || !$subject_id || !$teacher_id) {
        $_SESSION['rd_err'] = 'Day, Subject, Teacher — এই ফিল্ডগুলো আবশ্যক।';
        header('Location: index.php?page=routine_dynamic&' . $qs);
        exit();
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            "UPDATE routines SET start_time=?, end_time=?, has_break=?, subject_id=?, teacher_id=?, status=? WHERE id=?"
        );
        $stmt->execute([$start_time ?: null, $end_time ?: null, $has_break, $subject_id, $teacher_id, $status, $id]);
        sec_log_activity($pdo, 'Routine', 'Update', "ক্লাস আইডি {$class_id}, দিন {$day} এর রুটিন পিরিয়ড আপডেট করা হয়েছে");
        $_SESSION['rd_msg'] = 'রুটিন আপডেট করা হয়েছে।';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO routines (class_id, shift_id, section_id, routine_days, start_time, end_time, has_break, subject_id, teacher_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$class_id, $shift_id, $section_id, $day, $start_time ?: null, $end_time ?: null, $has_break, $subject_id, $teacher_id, $status]);
        sec_log_activity($pdo, 'Routine', 'Create', "ক্লাস আইডি {$class_id}, দিন {$day} এর নতুন রুটিন পিরিয়ড যোগ করা হয়েছে");
        $_SESSION['rd_msg'] = 'নতুন রুটিন পিরিয়ড যোগ করা হয়েছে।';
    }
    header('Location: index.php?page=routine_dynamic&' . $qs);
    exit();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_routine'])) {
    $qs = 'class_id=' . (int) ($_POST['class_id'] ?? 0) . '&shift_id=' . (int) ($_POST['shift_id'] ?? 0) . '&section_id=' . (int) ($_POST['section_id'] ?? 0);
    if (csrf_verify($_POST['csrf_token'] ?? null)) {
        $rd_del_id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM routines WHERE id = ?")->execute([$rd_del_id]);
        sec_log_activity($pdo, 'Routine', 'Delete', "রুটিন পিরিয়ড মুছে ফেলা হয়েছে: আইডি {$rd_del_id}");
        $_SESSION['rd_msg'] = 'রুটিন মুছে ফেলা হয়েছে।';
    }
    header('Location: index.php?page=routine_dynamic&' . $qs);
    exit();
}

$sel_class   = (int) ($_GET['class_id'] ?? 0);
$sel_shift   = (int) ($_GET['shift_id'] ?? 0);
$sel_section = (int) ($_GET['section_id'] ?? 0);

$day_entries = [];
if ($sel_class && $sel_shift && $sel_section) {
    $stmt = $pdo->prepare(
        "SELECT routines.*, academic_subjects.subject_name, teachers.name AS teacher_name
         FROM routines
         LEFT JOIN academic_subjects ON academic_subjects.id = routines.subject_id
         LEFT JOIN employees teachers ON teachers.id = routines.teacher_id
         WHERE routines.class_id = ? AND (routines.shift_id = ? OR routines.shift_id IS NULL) AND routines.section_id = ?" .
        (branch_current_id() !== null ? " AND (routines.branch_id = ?)" : "")
    );
    $rd_params = [$sel_class, $sel_shift, $sel_section];
    if (branch_current_id() !== null) { $rd_params[] = branch_current_id(); }
    $stmt->execute($rd_params);
    $all_rows = $stmt->fetchAll();

    foreach ($week_days as $day) { $day_entries[$day] = []; }
    foreach ($all_rows as $row) {
        $row_days = explode(',', (string) $row['routine_days']);
        foreach ($row_days as $rd) {
            $rd = trim($rd);
            if (isset($day_entries[$rd])) $day_entries[$rd][] = $row;
        }
    }
}

// -----------------------------------------------------------
// AJAX fragment মোড — Class/Shift/Section বদলালে পুরো পেজ রিলোড না করে
// শুধু টেবিলটাই ফেরত দেওয়া হয় (admin/index.php-এর ob_start() বাফার discard করে)
// -----------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    if (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/html; charset=utf-8');
    render_routine_table($week_days, $day_entries, $sel_class, $sel_shift, $sel_section);
    exit();
}

function render_routine_table(array $week_days, array $day_entries, int $sel_class, int $sel_shift, int $sel_section): void
{
    if (!$sel_class || !$sel_shift || !$sel_section) {
        echo '<p class="text-muted">উপরে Class, Shift ও Section — তিনটাই বাছাই করুন।</p>';
        return;
    }
    ?>
    <table class="table table-bordered align-middle">
        <thead class="table-light"><tr><th style="width:180px;">Day</th><th>Periods</th></tr></thead>
        <tbody>
            <?php foreach ($week_days as $day): ?>
                <tr>
                    <td class="fw-bold"><?php echo $day; ?></td>
                    <td>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <?php foreach ($day_entries[$day] as $entry): ?>
                                <div class="border rounded px-2 py-1 small d-flex align-items-center gap-2" style="background:#f8fafc;">
                                    <span>
                                        <strong><?php echo rdv($entry['subject_name'] ?? '-'); ?></strong> — <?php echo rdv($entry['teacher_name'] ?? '-'); ?>
                                        <?php if ($entry['start_time']): ?><br><span class="text-muted"><?php echo fmt_time_rd($entry['start_time']); ?> - <?php echo fmt_time_rd($entry['end_time']); ?></span><?php endif; ?>
                                        <?php echo $entry['status'] === 'Inactive' ? ' <span class="badge bg-secondary">Inactive</span>' : ''; ?>
                                    </span>
                                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-1" title="Edit"
                                        onclick='openRoutineModal(<?php echo (int) $entry["id"]; ?>, "<?php echo $day; ?>", <?php echo json_encode($entry); ?>)'>
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <form method="POST" action="index.php?page=routine_dynamic" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                        <input type="hidden" name="id" value="<?php echo (int) $entry['id']; ?>">
                                        <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
                                        <input type="hidden" name="shift_id" value="<?php echo $sel_shift; ?>">
                                        <input type="hidden" name="section_id" value="<?php echo $sel_section; ?>">
                                        <button type="submit" name="delete_routine" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                            <button type="button" class="btn btn-sm text-white" style="background:#22b8cf; width:36px; height:36px;" onclick='openRoutineModal(0, "<?php echo $day; ?>", null)'>
                                <i class="fa-solid fa-plus"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}
?>

<div class="card-custom">
    <?php if ($rd_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($rd_msg); ?></div><?php endif; ?>
    <?php if ($rd_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($rd_err); ?></div><?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-4">
            <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
            <select id="rdClassSelect" class="form-select" onchange="filterRdSections(); filterRdShifts(); loadRoutineTable()">
                <option value="">Select Class</option>
                <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $sel_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo rdv($c['class_name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-bold">Shift <span class="text-danger">*</span></label>
            <select id="rdShiftSelect" class="form-select" disabled onchange="filterRdSections(); loadRoutineTable()">
                <option value="">Select Class first</option>
                <?php foreach ($shifts as $sh): ?><option value="<?php echo (int) $sh['id']; ?>" data-classes="<?php echo rdv(implode(',', $rd_class_shifts[$sh['id']] ?? [])); ?>" <?php echo $sel_shift === (int) $sh['id'] ? 'selected' : ''; ?> hidden><?php echo rdv($sh['shift_name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-bold">Section <span class="text-danger">*</span></label>
            <select id="rdSectionSelect" class="form-select" disabled onchange="loadRoutineTable()">
                <option value="">Select Class first</option>
                <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" data-shift="<?php echo (int) $sec['shift_id']; ?>" <?php echo $sel_section === (int) $sec['id'] ? 'selected' : ''; ?> hidden><?php echo rdv($sec['section_name']); ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>

    <div id="rdTableWrap">
        <?php render_routine_table($week_days, $day_entries, $sel_class, $sel_shift, $sel_section); ?>
    </div>
</div>

<style>
    #routineModal .modal-content { border-radius: 10px; overflow: hidden; border: none; box-shadow: 0 8px 30px rgba(0,0,0,0.2); }
    #routineModal .modal-header { border-bottom: 1px solid #e2e8f0; padding: 16px 24px; }
    #routineModal .modal-body { padding: 24px !important; }
    #routineModal .modal-footer { border-top: 1px solid #e2e8f0; padding: 14px 24px; }
    #routineModal .form-label { font-size: 13.5px; color: #334155; margin-bottom: 6px; }
    #routineModal .form-control, #routineModal .form-select { padding: 8px 12px; }
    #routineModal .row > div { padding-left: 10px; padding-right: 10px; }
    #routineModal .row { margin-left: -10px; margin-right: -10px; }
</style>

<!-- ================= Create / Edit Routine Modal ================= -->
<div class="modal fade" id="routineModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="index.php?page=routine_dynamic" id="routineModalForm" onsubmit="return validateRdTimesBeforeSubmit()">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" id="rmId">
                <input type="hidden" name="class_id" id="rmClassId" value="<?php echo $sel_class; ?>">
                <input type="hidden" name="shift_id" id="rmShiftId" value="<?php echo $sel_shift; ?>">
                <input type="hidden" name="section_id" id="rmSectionId" value="<?php echo $sel_section; ?>">
                <input type="hidden" name="day" id="rmDay">

                <div class="modal-header" style="background:#f8fafc;">
                    <h5 class="modal-title fw-bold" id="rmTitle"><i class="fa-solid fa-calendar-plus"></i> Create Routine</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-4">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Start Time</label>
                        <div class="col-sm-8" style="position:relative;">
                            <div class="input-group">
                                <button type="button" class="btn btn-outline-secondary rd-time-trigger" onclick="openRdTimePicker('rmStart')" tabindex="-1"><i class="fa-solid fa-calendar-days"></i></button>
                                <input type="text" id="rmStartDisplay" class="form-control" placeholder="Type e.g. 10:30 AM, or click the icon" onclick="openRdTimePicker('rmStart')" onblur="parseRdTimeInput('rmStart')">
                            </div>
                            <input type="hidden" name="start_time" id="rmStartValue">
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">End Time</label>
                        <div class="col-sm-8" style="position:relative;">
                            <div class="input-group">
                                <button type="button" class="btn btn-outline-secondary rd-time-trigger" onclick="openRdTimePicker('rmEnd')" tabindex="-1"><i class="fa-solid fa-calendar-days"></i></button>
                                <input type="text" id="rmEndDisplay" class="form-control" placeholder="Type e.g. 05:00 PM, or click the icon" onclick="openRdTimePicker('rmEnd')" onblur="parseRdTimeInput('rmEnd')">
                            </div>
                            <input type="hidden" name="end_time" id="rmEndValue">
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Break / Tiffin ?</label>
                        <div class="col-sm-8 d-flex gap-4">
                            <div class="form-check"><input class="form-check-input" type="radio" name="has_break" id="rmBreakYes" value="YES"><label class="form-check-label" for="rmBreakYes">YES</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" name="has_break" id="rmBreakNo" value="NO" checked><label class="form-check-label" for="rmBreakNo">NO</label></div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Subject <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="subject_id" id="rmSubject" class="form-select" required>
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $sub): if ((int) $sub['class_id'] !== $sel_class) continue; ?>
                                    <option value="<?php echo (int) $sub['id']; ?>"><?php echo rdv($sub['subject_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Teacher <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="teacher_id" id="rmTeacher" class="form-select" required>
                                <option value="">Select Teacher</option>
                                <?php foreach ($teachers as $t): ?><option value="<?php echo (int) $t['id']; ?>"><?php echo rdv($t['name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Status <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="status" id="rmStatus" class="form-select">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="background:#f8fafc;">
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="save_routine" class="btn btn-primary" style="background-color:#1e3a8a;">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============ Choose Time popup (মডালের ভেতরের Start/End Time-এর জন্য) ============ -->
    <div id="rdTimePickerPopup" style="display:none; position:absolute; z-index:1060; width:270px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; box-shadow:0 6px 16px rgba(0,0,0,0.2);">
        <div style="background:#e9ecef; padding:8px 12px; font-weight:bold; text-align:center; border-bottom:1px solid #ddd;">Choose Time</div>
        <div style="padding:14px;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <strong>Time</strong>
                <span id="rdTimeDisplay" style="text-decoration:underline; font-size:16px;">00:00:00</span>
            </div>
            <div class="mb-2">
                <label class="d-block small fw-bold mb-1">Hour</label>
                <input type="range" id="rdHourSlider" min="0" max="23" value="0" class="form-range" oninput="updateRdTimeDisplay()">
            </div>
            <div class="mb-3">
                <label class="d-block small fw-bold mb-1">Minute</label>
                <input type="range" id="rdMinuteSlider" min="0" max="59" value="0" class="form-range" oninput="updateRdTimeDisplay()">
            </div>
            <div class="d-flex justify-content-between">
                <button type="button" class="btn btn-light btn-sm" onclick="setRdTimeNow()">Now</button>
                <button type="button" class="btn btn-dark btn-sm" onclick="commitRdTime()">Done</button>
            </div>
        </div>
    </div>
</div>

<script>
    // ---------------- Class বদলালে সেই ক্লাসের Shift/Section-ই শুধু ড্রপডাউনে দেখানো ----------------
    function filterRdSections() {
        const classId = document.getElementById('rdClassSelect').value;
        const shiftId = document.getElementById('rdShiftSelect').value;
        const select = document.getElementById('rdSectionSelect');
        select.disabled = !classId;
        Array.from(select.options).forEach(opt => {
            if (!opt.value) { opt.hidden = false; return; }
            const classOk = !!classId && opt.dataset.class === classId;
            const shiftOk = !shiftId || !opt.dataset.shift || opt.dataset.shift === '0' || opt.dataset.shift === shiftId;
            opt.hidden = !(classOk && shiftOk);
        });
        select.options[0].textContent = classId ? 'Select Section' : 'Select Class first';
        // বর্তমানে সিলেক্ট করা Section নতুন Class/Shift-এর না হলে রিসেট করা
        if (select.selectedOptions[0] && select.selectedOptions[0].hidden) {
            select.value = '';
        }
    }
    function filterRdShifts() {
        const classId = document.getElementById('rdClassSelect').value;
        const select = document.getElementById('rdShiftSelect');
        select.disabled = !classId;
        Array.from(select.options).forEach(opt => {
            if (!opt.value) { opt.hidden = false; return; }
            const classesList = (opt.dataset.classes || '').split(',').filter(Boolean);
            opt.hidden = !!classId && classesList.length > 0 && !classesList.includes(classId);
        });
        select.options[0].textContent = classId ? 'Select Shift' : 'Select Class first';
        if (select.selectedOptions[0] && select.selectedOptions[0].hidden) {
            select.value = '';
        }
    }
    filterRdSections();
    filterRdShifts();

    // ---------------- Class/Shift/Section বদলালে পুরো পেজ রিলোড ছাড়াই টেবিল আপডেট ----------------
    function loadRoutineTable() {
        const classId = document.getElementById('rdClassSelect').value;
        const shiftId = document.getElementById('rdShiftSelect').value;
        const sectionId = document.getElementById('rdSectionSelect').value;

        document.getElementById('rmClassId').value = classId;
        document.getElementById('rmShiftId').value = shiftId;
        document.getElementById('rmSectionId').value = sectionId;

        const newUrl = 'index.php?page=routine_dynamic&class_id=' + classId + '&shift_id=' + shiftId + '&section_id=' + sectionId;
        window.history.replaceState({}, '', newUrl);

        const wrap = document.getElementById('rdTableWrap');
        wrap.style.opacity = '0.5';

        fetch(newUrl + '&ajax=1')
            .then(res => res.text())
            .then(html => {
                wrap.innerHTML = html;
                wrap.style.opacity = '1';
            })
            .catch(() => {
                wrap.style.opacity = '1';
            });
    }

    // ---------------- Modal Open (Add/Edit) ----------------
    function openRoutineModal(id, day, data) {
        document.getElementById('rmId').value = id;
        document.getElementById('rmDay').value = day;
        document.getElementById('rmTitle').innerHTML = id ? '<i class="fa-solid fa-pen"></i> Edit Routine (' + day + ')' : '<i class="fa-solid fa-calendar-plus"></i> Create Routine (' + day + ')';

        if (data) {
            document.getElementById('rmStartValue').value = data.start_time || '';
            document.getElementById('rmEndValue').value = data.end_time || '';
            document.getElementById('rmStartDisplay').value = data.start_time ? formatRd12Hour(...data.start_time.split(':')) : '';
            document.getElementById('rmEndDisplay').value = data.end_time ? formatRd12Hour(...data.end_time.split(':')) : '';
            document.getElementById('rmBreakYes').checked = String(data.has_break) === '1';
            document.getElementById('rmBreakNo').checked = String(data.has_break) !== '1';
            document.getElementById('rmSubject').value = data.subject_id || '';
            document.getElementById('rmTeacher').value = data.teacher_id || '';
            document.getElementById('rmStatus').value = data.status || 'Active';
        } else {
            document.getElementById('routineModalForm').reset();
            document.getElementById('rmStartValue').value = '';
            document.getElementById('rmEndValue').value = '';
            document.getElementById('rmStartDisplay').value = '';
            document.getElementById('rmEndDisplay').value = '';
        }

        new bootstrap.Modal(document.getElementById('routineModal')).show();
    }

    // ---------------- Choose Time Popup (মডালের ভেতরে) ----------------
    let rdActiveField = null;

    function openRdTimePicker(field) {
        rdActiveField = field;
        const popup = document.getElementById('rdTimePickerPopup');
        const anchor = document.getElementById(field + 'Display');
        const rect = anchor.getBoundingClientRect();

        popup.style.display = 'block';
        popup.style.top = (rect.bottom + window.scrollY + 4) + 'px';
        popup.style.left = (rect.left + window.scrollX) + 'px';

        let h = 0, m = 0;
        const typed = parseRdTimeString(anchor.value);
        if (typed) {
            h = typed.h; m = typed.m;
        } else {
            const hidden = document.getElementById(field + 'Value');
            if (hidden.value) {
                const parts = hidden.value.split(':');
                h = parseInt(parts[0], 10) || 0;
                m = parseInt(parts[1], 10) || 0;
            }
        }
        document.getElementById('rdHourSlider').value = h;
        document.getElementById('rdMinuteSlider').value = m;
        updateRdTimeDisplay();
    }

    function parseRdTimeString(raw) {
        raw = (raw || '').trim();
        if (!raw) return null;
        const match = raw.match(/^(\d{1,2})[:.](\d{2})\s*(AM|PM|am|pm)?$/);
        if (!match) return null;

        let hour = parseInt(match[1], 10);
        const minute = parseInt(match[2], 10);
        const meridiem = match[3] ? match[3].toUpperCase() : null;
        if (minute > 59 || hour > 23 || (meridiem && hour > 12) || hour < 0) return null;

        if (meridiem === 'PM' && hour !== 12) hour += 12;
        if (meridiem === 'AM' && hour === 12) hour = 0;
        return { h: hour, m: minute };
    }

    function updateRdTimeDisplay() {
        const h = String(document.getElementById('rdHourSlider').value).padStart(2, '0');
        const m = String(document.getElementById('rdMinuteSlider').value).padStart(2, '0');
        document.getElementById('rdTimeDisplay').textContent = h + ':' + m + ':00';
    }

    function setRdTimeNow() {
        const now = new Date();
        document.getElementById('rdHourSlider').value = now.getHours();
        document.getElementById('rdMinuteSlider').value = now.getMinutes();
        updateRdTimeDisplay();
    }

    function formatRd12Hour(h, m) {
        let hour = parseInt(h, 10);
        const ampm = hour >= 12 ? 'PM' : 'AM';
        hour = hour % 12;
        if (hour === 0) hour = 12;
        return hour + ':' + m + ' ' + ampm;
    }

    function commitRdTime() {
        const h = String(document.getElementById('rdHourSlider').value).padStart(2, '0');
        const m = String(document.getElementById('rdMinuteSlider').value).padStart(2, '0');
        document.getElementById(rdActiveField + 'Value').value = h + ':' + m;
        document.getElementById(rdActiveField + 'Display').value = formatRd12Hour(h, m);
        document.getElementById('rdTimePickerPopup').style.display = 'none';
    }

    function parseRdTimeInput(field) {
        const input = document.getElementById(field + 'Display');
        const hidden = document.getElementById(field + 'Value');
        const raw = input.value.trim();

        if (raw === '') { hidden.value = ''; return; }

        const match = raw.match(/^(\d{1,2})[:.](\d{2})\s*(AM|PM|am|pm)?$/);
        if (!match) {
            hidden.value = '';
            return;
        }

        let hour = parseInt(match[1], 10);
        const minute = parseInt(match[2], 10);
        const meridiem = match[3] ? match[3].toUpperCase() : null;

        if (minute > 59 || hour > 23 || (meridiem && hour > 12) || hour < 0) {
            hidden.value = '';
            return;
        }

        if (meridiem === 'PM' && hour !== 12) hour += 12;
        if (meridiem === 'AM' && hour === 12) hour = 0;

        const hh = String(hour).padStart(2, '0');
        const mm = String(minute).padStart(2, '0');
        hidden.value = hh + ':' + mm;
        input.value = formatRd12Hour(hh, mm);
    }

    function validateRdTimesBeforeSubmit() {
        for (const field of ['rmStart', 'rmEnd']) {
            const display = document.getElementById(field + 'Display');
            const hidden = document.getElementById(field + 'Value');
            if (display.value.trim() !== '' && hidden.value === '') {
                alert((field === 'rmStart' ? 'Start Time' : 'End Time') + '-এর ফরম্যাট ঠিক নেই। উদাহরণ: 10:30 AM অথবা 17:30');
                display.focus();
                return false;
            }
        }
        return true;
    }

    document.addEventListener('click', function (e) {
        const popup = document.getElementById('rdTimePickerPopup');
        if (popup && popup.style.display === 'block' && !popup.contains(e.target) && !e.target.closest('.rd-time-trigger') && !e.target.id.includes('Display')) {
            popup.style.display = 'none';
        }
    });
</script>
