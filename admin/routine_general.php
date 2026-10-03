<?php
// admin/routine_general.php - General Routine (স্ক্রিনশট অনুযায়ী)
$rt_err = $_SESSION['rt_err'] ?? '';
$rt_msg = $_SESSION['rt_msg'] ?? '';
unset($_SESSION['rt_err'], $_SESSION['rt_msg']);

function rtv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function fmt_time_rt($t): string { return $t ? date('h:i A', strtotime($t)) : '-'; }

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি (routine_dynamic.php ও routine_view.php একই টেবিল শেয়ার করে)
// teacher_id -> employees.id (আলাদা কোনো "teachers" টেবিল নেই, employees-ই টিচার পোর্টাল লগইনে ব্যবহৃত হয়)
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
        branch_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_lookup (class_id, shift_id, section_id),
        KEY idx_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// -----------------------------------------------------------
// Save / Update / Delete Routine
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_routine'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['rt_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=routine_general');
        exit();
    }

    $id         = (int) ($_POST['id'] ?? 0);
    $class_id   = (int) ($_POST['class_id'] ?? 0);
    $shift_id   = (int) ($_POST['shift_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $days       = isset($_POST['routine_days']) ? implode(',', (array) $_POST['routine_days']) : '';
    $start_time = trim($_POST['start_time'] ?? '');
    $end_time   = trim($_POST['end_time'] ?? '');
    $has_break  = ($_POST['has_break'] ?? 'NO') === 'YES' ? 1 : 0;
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $teacher_id = (int) ($_POST['teacher_id'] ?? 0);
    $status     = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    if (!$section_id || !$subject_id || !$teacher_id) {
        $_SESSION['rt_err'] = 'Section, Subject, Teacher — এই ফিল্ডগুলো আবশ্যক।';
    } else {
        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE routines SET class_id=?, shift_id=?, section_id=?, routine_days=?, start_time=?, end_time=?, has_break=?, subject_id=?, teacher_id=?, status=? WHERE id=?"
            );
            $stmt->execute([$class_id ?: null, $shift_id ?: null, $section_id, $days ?: null, $start_time ?: null, $end_time ?: null, $has_break, $subject_id, $teacher_id, $status, $id]);
            sec_log_activity($pdo, 'Routine', 'Update', "Section আইডি {$section_id} এর রুটিন আপডেট করা হয়েছে");
            $_SESSION['rt_msg'] = 'রুটিন আপডেট করা হয়েছে।';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO routines (class_id, shift_id, section_id, routine_days, start_time, end_time, has_break, subject_id, teacher_id, status, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$class_id ?: null, $shift_id ?: null, $section_id, $days ?: null, $start_time ?: null, $end_time ?: null, $has_break, $subject_id, $teacher_id, $status, branch_id_for_insert()]);
            sec_log_activity($pdo, 'Routine', 'Create', "Section আইডি {$section_id} এর জন্য নতুন রুটিন যোগ করা হয়েছে");
            $_SESSION['rt_msg'] = 'নতুন রুটিন যোগ করা হয়েছে।';
        }
    }
    header('Location: index.php?page=routine_general');
    exit();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_routine'])) {
    if (csrf_verify($_POST['csrf_token'] ?? null)) {
        $rt_del_id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM routines WHERE id = ?")->execute([$rt_del_id]);
        sec_log_activity($pdo, 'Routine', 'Delete', "রুটিন মুছে ফেলা হয়েছে: আইডি {$rt_del_id}");
        $_SESSION['rt_msg'] = 'রুটিন মুছে ফেলা হয়েছে।';
    }
    header('Location: index.php?page=routine_general');
    exit();
}

$view = $_GET['view'] ?? 'list';

$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections = $pdo->query("SELECT s.id, s.section_name, s.class_id, s.shift_id, c.class_name FROM academic_sections s LEFT JOIN academic_classes c ON c.id = s.class_id WHERE s.status = 'Active' ORDER BY s.section_name ASC")->fetchAll();
$subjects = $pdo->query("SELECT id, subject_name, class_id FROM academic_subjects WHERE status = 'Active' ORDER BY subject_name ASC")->fetchAll();

// একটা Class-এ কোন কোন Shift আছে সেটা academic_sections (class_id + shift_id) থেকে বের করা —
// Class সিলেক্ট না করা পর্যন্ত Shift/Section ফাঁকা/বন্ধ থাকবে
$rt_class_shifts = []; // shift_id => [class_id, class_id, ...]
foreach ($sections as $sec) {
    if ($sec['shift_id']) {
        $rt_class_shifts[(int) $sec['shift_id']][] = (int) $sec['class_id'];
    }
}
$teachers = $pdo->query("SELECT id, name FROM employees WHERE type = 'Teachers' AND status = 'Active' ORDER BY name ASC")->fetchAll();

$week_days = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    $edit_row = null;
    if ($view === 'edit') {
        $eid = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM routines WHERE id = ?");
        $stmt->execute([$eid]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: [
        'id' => 0, 'class_id' => '', 'shift_id' => '', 'section_id' => '', 'routine_days' => '',
        'start_time' => '', 'end_time' => '', 'has_break' => 0, 'subject_id' => '', 'teacher_id' => '', 'status' => 'Active',
    ];
    $selected_days = $d['routine_days'] ? explode(',', $d['routine_days']) : [];
    ?>
    <div class="card-custom">
        <?php if ($rt_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($rt_err); ?></div><?php endif; ?>
        <?php if ($rt_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($rt_msg); ?></div><?php endif; ?>

        <form method="POST" action="index.php?page=routine_general" onsubmit="return validateRtTimesBeforeSubmit()">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Class</label>
                <div class="col-sm-6">
                    <select name="class_id" id="rtClassSelect" class="form-select" onchange="filterRtSections(); filterRtShifts(); filterRtSubjects();">
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo (int) $d['class_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo rtv($c['class_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Shift</label>
                <div class="col-sm-6">
                    <select name="shift_id" id="rtShiftSelect" class="form-select" disabled onchange="filterRtSections();">
                        <option value="">Select Class first</option>
                        <?php foreach ($shifts as $sh): ?><option value="<?php echo (int) $sh['id']; ?>" data-classes="<?php echo rtv(implode(',', $rt_class_shifts[$sh['id']] ?? [])); ?>" <?php echo (int) $d['shift_id'] === (int) $sh['id'] ? 'selected' : ''; ?> hidden><?php echo rtv($sh['shift_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Section <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="section_id" id="rtSectionSelect" class="form-select" required disabled>
                        <option value="">Select Class first</option>
                        <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" data-shift="<?php echo (int) $sec['shift_id']; ?>" <?php echo (int) $d['section_id'] === (int) $sec['id'] ? 'selected' : ''; ?> hidden><?php echo rtv($sec['section_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3 align-items-start">
                <label class="col-sm-3 col-form-label fw-bold text-end">Routine Day</label>
                <div class="col-sm-6">
                    <div class="rt-day-dropdown" style="position:relative;">
                        <input type="text" id="rtDayDisplay" class="form-control" readonly placeholder="Select Routine Days" onclick="toggleRtDayList(event)" style="cursor:pointer;">
                        <div id="rtDayList" style="display:none; position:absolute; z-index:20; background:#fff; border:1px solid #cbd5e1; width:100%; max-height:220px; overflow-y:auto; box-shadow:0 4px 10px rgba(0,0,0,0.1);">
                            <?php foreach ($week_days as $day): $is_sel = in_array($day, $selected_days, true); ?>
                                <div class="rt-day-option <?php echo $is_sel ? 'rt-day-selected' : ''; ?>" data-day="<?php echo $day; ?>" onclick="toggleRtDaySelection(this)" style="padding:8px 12px; cursor:pointer;">
                                    <?php echo $day; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <!-- আসল ফর্ম ভ্যালু এই checkbox গুলো দিয়েই যায় (backend অপরিবর্তিত), শুধু চোখের আড়ালে রাখা হয়েছে -->
                        <div style="display:none;">
                            <?php foreach ($week_days as $day): ?>
                                <input type="checkbox" name="routine_days[]" value="<?php echo $day; ?>" id="rtDay_<?php echo $day; ?>" <?php echo in_array($day, $selected_days, true) ? 'checked' : ''; ?>>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Start Time</label>
                <div class="col-sm-6" style="position:relative;">
                    <div class="input-group">
                        <button type="button" class="btn btn-outline-secondary rt-time-trigger" onclick="openRtTimePicker('start')" tabindex="-1"><i class="fa-solid fa-calendar-days"></i></button>
                        <input type="text" id="startTimeDisplay" class="form-control" placeholder="Type e.g. 10:30 AM, or click the icon" onclick="openRtTimePicker('start')" onblur="parseRtTimeInput('start')">
                    </div>
                    <input type="hidden" name="start_time" id="startTimeValue" value="<?php echo rtv($d['start_time']); ?>">
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">End Time</label>
                <div class="col-sm-6" style="position:relative;">
                    <div class="input-group">
                        <button type="button" class="btn btn-outline-secondary rt-time-trigger" onclick="openRtTimePicker('end')" tabindex="-1"><i class="fa-solid fa-calendar-days"></i></button>
                        <input type="text" id="endTimeDisplay" class="form-control" placeholder="Type e.g. 05:00 PM, or click the icon" onclick="openRtTimePicker('end')" onblur="parseRtTimeInput('end')">
                    </div>
                    <input type="hidden" name="end_time" id="endTimeValue" value="<?php echo rtv($d['end_time']); ?>">
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Break / Tiffin ?</label>
                <div class="col-sm-6 d-flex gap-4 align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="has_break" id="breakYes" value="YES" <?php echo $d['has_break'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="breakYes">YES</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="has_break" id="breakNo" value="NO" <?php echo !$d['has_break'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="breakNo">NO</label>
                    </div>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Subject <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="subject_id" id="rtSubjectSelect" class="form-select" required>
                        <option value="">Select Subject</option>
                        <?php foreach ($subjects as $sub): ?><option value="<?php echo (int) $sub['id']; ?>" data-class="<?php echo (int) $sub['class_id']; ?>" <?php echo (int) $d['subject_id'] === (int) $sub['id'] ? 'selected' : ''; ?>><?php echo rtv($sub['subject_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Teacher <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="teacher_id" class="form-select" required>
                        <option value="">Select Teacher</option>
                        <?php foreach ($teachers as $t): ?><option value="<?php echo (int) $t['id']; ?>" <?php echo (int) $d['teacher_id'] === (int) $t['id'] ? 'selected' : ''; ?>><?php echo rtv($t['name']); ?></option><?php endforeach; ?>
                    </select>
                    <?php if (!$teachers): ?><small class="text-danger">কোনো Teacher নাই — নিচে থেকে একজনকে যোগ করুন।</small><?php endif; ?>
                </div>
            </div>
            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Status</label>
                <div class="col-sm-6">
                    <select name="status" class="form-select">
                        <option value="Active" <?php echo $d['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $d['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_routine" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Save'; ?></button>
                    <a href="index.php?page=routine_general" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>

        <!-- ================= Choose Time Popup (Start/End Time দুটোতেই ব্যবহার হয়) ================= -->
        <div id="rtTimePickerPopup" style="display:none; position:absolute; z-index:50; width:280px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; box-shadow:0 6px 16px rgba(0,0,0,0.15);">
            <div style="background:#e9ecef; padding:8px 12px; font-weight:bold; text-align:center; border-bottom:1px solid #ddd; border-radius:6px 6px 0 0;">Choose Time</div>
            <div style="padding:14px;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <strong>Time</strong>
                    <span id="rtTimeDisplay" style="text-decoration:underline; font-size:16px;">12:00 AM</span>
                </div>
                <div class="mb-2">
                    <label class="d-block small fw-bold mb-1">Hour</label>
                    <input type="range" id="rtHourSlider" min="1" max="12" value="12" class="form-range" oninput="updateRtTimeDisplay()">
                </div>
                <div class="mb-3">
                    <label class="d-block small fw-bold mb-1">Minute</label>
                    <input type="range" id="rtMinuteSlider" min="0" max="59" value="0" class="form-range" oninput="updateRtTimeDisplay()">
                </div>
                <div class="mb-3 text-center">
                    <div class="btn-group" role="group">
                        <button type="button" id="rtAmBtn" class="btn btn-sm btn-outline-dark" onclick="setRtAmPm('AM')" style="width:60px;">AM</button>
                        <button type="button" id="rtPmBtn" class="btn btn-sm btn-outline-dark" onclick="setRtAmPm('PM')" style="width:60px;">PM</button>
                    </div>
                </div>
                <div class="d-flex justify-content-between">
                    <button type="button" class="btn btn-light btn-sm" onclick="setRtTimeNow()">Now</button>
                    <button type="button" class="btn btn-dark btn-sm" onclick="commitRtTime()">Done</button>
                </div>
            </div>
        </div>
    </div>

    <style>
        .rt-day-option:hover { background: #f1f5f9; }
        .rt-day-option.rt-day-selected { background: #22b8cf; color: #fff; }
        .rt-day-option.rt-day-selected:hover { background: #1ba8bd; }
    </style>

    <script>
        // ---------------- Choose Time Popup ----------------
        let rtActiveField = null; // 'start' অথবা 'end'

        function openRtTimePicker(field) {
            rtActiveField = field;
            const popup = document.getElementById('rtTimePickerPopup');
            const anchor = document.getElementById(field + 'TimeDisplay');
            const rect = anchor.getBoundingClientRect();

            popup.style.display = 'block';
            popup.style.top = (rect.bottom + window.scrollY + 4) + 'px';
            popup.style.left = (rect.left + window.scrollX) + 'px';

            // আগে চেষ্টা করা হচ্ছে বক্সে হাতে টাইপ করা টেক্সট থেকে সময় বোঝার (যদি বৈধ হয়),
            // না পারলে hidden value (24-ঘণ্টা ফরম্যাট) থেকে নেওয়া হচ্ছে
            let h24 = 0, m = 0;
            const typed = parseTimeString(anchor.value);
            if (typed) {
                h24 = typed.h; m = typed.m;
            } else {
                const hidden = document.getElementById(field + 'TimeValue');
                if (hidden.value) {
                    const parts = hidden.value.split(':');
                    h24 = parseInt(parts[0], 10) || 0;
                    m = parseInt(parts[1], 10) || 0;
                }
            }
            // 24-ঘণ্টা থেকে 12-ঘণ্টা স্লাইডার + AM/PM অবস্থায় রূপান্তর — যাতে পপআপ খোলার
            // সময়ও কখনো raw 24-ঘণ্টা সংখ্যা (যেমন 23) দেখাতে না হয়, শুধু AM/PM-ই দেখাবে
            const ampm = h24 >= 12 ? 'PM' : 'AM';
            let h12 = h24 % 12;
            if (h12 === 0) h12 = 12;
            document.getElementById('rtHourSlider').value = h12;
            document.getElementById('rtMinuteSlider').value = m;
            setRtAmPm(ampm);
        }

        // টেক্সট থেকে {h, m} বের করে (24-ঘণ্টা ফরম্যাটে), বোঝা না গেলে null রিটার্ন করে
        function parseTimeString(raw) {
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

        // AM/PM বাটন সিলেক্ট করলে বাটনের ভিজ্যুয়াল স্টেট + লাইভ প্রিভিউ আপডেট হয়
        function setRtAmPm(ampm) {
            document.getElementById('rtTimePickerPopup').dataset.ampm = ampm;
            document.getElementById('rtAmBtn').classList.toggle('btn-dark', ampm === 'AM');
            document.getElementById('rtAmBtn').classList.toggle('btn-outline-dark', ampm !== 'AM');
            document.getElementById('rtPmBtn').classList.toggle('btn-dark', ampm === 'PM');
            document.getElementById('rtPmBtn').classList.toggle('btn-outline-dark', ampm !== 'PM');
            updateRtTimeDisplay();
        }

        // লাইভ প্রিভিউ সবসময় 12-ঘণ্টা + AM/PM ফরম্যাটে দেখাবে (কখনো raw 24-ঘণ্টা সংখ্যা
        // দেখাবে না) — যাতে AM/PM ভুল হওয়ার কোনো সুযোগই না থাকে
        function updateRtTimeDisplay() {
            const h = document.getElementById('rtHourSlider').value;
            const m = String(document.getElementById('rtMinuteSlider').value).padStart(2, '0');
            const ampm = document.getElementById('rtTimePickerPopup').dataset.ampm || 'AM';
            document.getElementById('rtTimeDisplay').textContent = h + ':' + m + ' ' + ampm;
        }

        function setRtTimeNow() {
            const now = new Date();
            const h24 = now.getHours();
            const ampm = h24 >= 12 ? 'PM' : 'AM';
            let h12 = h24 % 12;
            if (h12 === 0) h12 = 12;
            document.getElementById('rtHourSlider').value = h12;
            document.getElementById('rtMinuteSlider').value = now.getMinutes();
            setRtAmPm(ampm);
        }

        function formatRt12Hour(h, m) {
            let hour = parseInt(h, 10);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            hour = hour % 12;
            if (hour === 0) hour = 12;
            return hour + ':' + m + ' ' + ampm;
        }

        function commitRtTime() {
            const h12 = parseInt(document.getElementById('rtHourSlider').value, 10);
            const m = String(document.getElementById('rtMinuteSlider').value).padStart(2, '0');
            const ampm = document.getElementById('rtTimePickerPopup').dataset.ampm || 'AM';
            // 12-ঘণ্টা + AM/PM থেকে hidden ফিল্ডের জন্য 24-ঘণ্টা ফরম্যাটে রূপান্তর
            let h24 = h12 % 12;
            if (ampm === 'PM') h24 += 12;
            const hh24 = String(h24).padStart(2, '0');
            document.getElementById(rtActiveField + 'TimeValue').value = hh24 + ':' + m;
            document.getElementById(rtActiveField + 'TimeDisplay').value = h12 + ':' + m + ' ' + ampm;
            document.getElementById('rtTimePickerPopup').style.display = 'none';
        }

        // Start/End Time বক্সে সরাসরি টাইপ করলে (যেমন: "10:30 AM", "10:30", "5.15 pm")
        // এখান থেকে পার্স করে hidden ভ্যালু আর ডিসপ্লে টেক্সট ঠিক করে দেওয়া হয়
        function parseRtTimeInput(field) {
            const input = document.getElementById(field + 'TimeDisplay');
            const hidden = document.getElementById(field + 'TimeValue');
            const raw = input.value.trim();

            if (raw === '') { hidden.value = ''; return; }

            const match = raw.match(/^(\d{1,2})[:.](\d{2})\s*(AM|PM|am|pm)?$/);
            if (!match) {
                // এখনো টাইপ চলছে হতে পারে (যেমন শুধু "10" লেখা) — তাই এখানে এরর দেখানো হয় না,
                // শুধু hidden ভ্যালু ফাঁকা রাখা হচ্ছে (Save করার সময় validateRtTimesBeforeSubmit() ধরবে)
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

            // AM/PM দেওয়া থাকলে 24-ঘণ্টা ফরম্যাটে রূপান্তর
            if (meridiem === 'PM' && hour !== 12) hour += 12;
            if (meridiem === 'AM' && hour === 12) hour = 0;

            const hh = String(hour).padStart(2, '0');
            const mm = String(minute).padStart(2, '0');
            hidden.value = hh + ':' + mm;
            input.value = formatRt12Hour(hh, mm);
        }

        document.addEventListener('click', function (e) {
            const popup = document.getElementById('rtTimePickerPopup');
            if (popup.style.display === 'block' && !popup.contains(e.target) && !e.target.closest('.rt-time-trigger') && !e.target.id.includes('TimeDisplay')) {
                popup.style.display = 'none';
            }
        });

        // Save/Update-এ ক্লিক করার সময়ই যাচাই করা হয় (টাইপ করার সময় না) — যাতে
        // মাঝপথে বিরক্তিকর এরর না আসে, শুধু আসল সাবমিটের সময় একবার বলবে
        function validateRtTimesBeforeSubmit() {
            for (const field of ['start', 'end']) {
                const display = document.getElementById(field + 'TimeDisplay');
                const hidden = document.getElementById(field + 'TimeValue');
                if (display.value.trim() !== '' && hidden.value === '') {
                    alert((field === 'start' ? 'Start Time' : 'End Time') + '-এর ফরম্যাট ঠিক নেই। উদাহরণ: 10:30 AM অথবা 17:30');
                    display.focus();
                    return false;
                }
            }
            return true;
        }

        // এডিটের সময় আগে থেকে সেভ করা Time থাকলে ডিসপ্লে বক্সে ফরম্যাট করে বসানো
        (function initRtTimeDisplays() {
            ['start', 'end'].forEach(field => {
                const hidden = document.getElementById(field + 'TimeValue');
                if (hidden.value) {
                    const [h, m] = hidden.value.split(':');
                    document.getElementById(field + 'TimeDisplay').value = formatRt12Hour(h, m);
                }
            });
        })();

        function toggleRtDayList(e) {
            e.stopPropagation();
            const list = document.getElementById('rtDayList');
            list.style.display = (list.style.display === 'none') ? 'block' : 'none';
        }
        document.addEventListener('click', function (e) {
            const wrap = document.querySelector('.rt-day-dropdown');
            if (wrap && !wrap.contains(e.target)) {
                document.getElementById('rtDayList').style.display = 'none';
            }
        });

        function toggleRtDaySelection(el) {
            const day = el.dataset.day;
            const checkbox = document.getElementById('rtDay_' + day);
            checkbox.checked = !checkbox.checked;
            el.classList.toggle('rt-day-selected', checkbox.checked);
            updateRtDayDisplay();
        }

        function updateRtDayDisplay() {
            const selected = <?php echo json_encode($week_days); ?>.filter(day => document.getElementById('rtDay_' + day)?.checked);
            document.getElementById('rtDayDisplay').value = selected.join(', ');
        }

        function filterRtSections() {
            const classId = document.getElementById('rtClassSelect').value;
            const shiftId = document.getElementById('rtShiftSelect').value;
            const select = document.getElementById('rtSectionSelect');
            select.disabled = !classId;
            Array.from(select.options).forEach(opt => {
                if (!opt.value) { opt.hidden = false; return; }
                const classOk = !!classId && opt.dataset.class === classId;
                const shiftOk = !shiftId || !opt.dataset.shift || opt.dataset.shift === '0' || opt.dataset.shift === shiftId;
                opt.hidden = !(classOk && shiftOk);
            });
            select.options[0].textContent = classId ? 'Select Section' : 'Select Class first';
            if (select.value && Array.from(select.options).find(o => o.value === select.value)?.hidden) {
                select.value = '';
            }
        }
        function filterRtShifts() {
            const classId = document.getElementById('rtClassSelect').value;
            const select = document.getElementById('rtShiftSelect');
            select.disabled = !classId;
            Array.from(select.options).forEach(opt => {
                if (!opt.value) { opt.hidden = false; return; }
                const classesList = (opt.dataset.classes || '').split(',').filter(Boolean);
                opt.hidden = !!classId && classesList.length > 0 && !classesList.includes(classId);
            });
            select.options[0].textContent = classId ? 'Select Shift' : 'Select Class first';
            if (select.value && Array.from(select.options).find(o => o.value === select.value)?.hidden) {
                select.value = '';
            }
        }
        function filterRtSubjects() {
            const classId = document.getElementById('rtClassSelect').value;
            const select = document.getElementById('rtSubjectSelect');
            Array.from(select.options).forEach(opt => {
                if (!opt.value) { opt.hidden = false; return; }
                opt.hidden = classId && opt.dataset.class !== classId;
            });
        }
        filterRtSections();
        filterRtShifts();
        filterRtSubjects();
        updateRtDayDisplay();
    </script>

<?php else: ?>
    <?php
    $f_class = $_GET['f_class'] ?? 'All';
    $f_section = $_GET['f_section'] ?? 'All';
    $f_status = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_class !== 'All')   { $where[] = 'routines.class_id = ?'; $params[] = (int) $f_class; }
    if ($f_section !== 'All') { $where[] = 'routines.section_id = ?'; $params[] = (int) $f_section; }
    if ($f_status !== 'All')  { $where[] = 'routines.status = ?'; $params[] = $f_status; }
    branch_apply_filter($where, $params, 'routines.branch_id');

    $sql = "SELECT routines.*, academic_classes.class_name, academic_shifts.shift_name, academic_sections.section_name,
                   academic_subjects.subject_name, teachers.name AS teacher_name
            FROM routines
            LEFT JOIN academic_classes ON academic_classes.id = routines.class_id
            LEFT JOIN academic_shifts ON academic_shifts.id = routines.shift_id
            LEFT JOIN academic_sections ON academic_sections.id = routines.section_id
            LEFT JOIN academic_subjects ON academic_subjects.id = routines.subject_id
            LEFT JOIN employees teachers ON teachers.id = routines.teacher_id";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY routines.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($rt_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($rt_msg); ?></div><?php endif; ?>
        <?php if ($rt_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($rt_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=routine_general&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
            <span class="text-muted small">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> items.</span>
        </div>

        <form method="GET" action="index.php" class="row g-2 mb-3">
            <input type="hidden" name="page" value="routine_general">
            <div class="col-md-3">
                <select name="f_class" class="form-select" onchange="this.form.submit()">
                    <option value="All">All Classes</option>
                    <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo (string) $f_class === (string) $c['id'] ? 'selected' : ''; ?>><?php echo rtv($c['class_name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="f_section" class="form-select" <?php echo ($f_class && $f_class !== 'All') ? '' : 'disabled'; ?> onchange="this.form.submit()">
                    <option value="All"><?php echo ($f_class && $f_class !== 'All') ? 'All Sections' : 'আগে Class সিলেক্ট করুন'; ?></option>
                    <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" <?php echo (string) $f_section === (string) $sec['id'] ? 'selected' : ''; ?> <?php echo (!$f_class || $f_class === 'All' || (string) $sec['class_id'] !== (string) $f_class) ? 'hidden' : ''; ?>><?php echo rtv($sec['section_name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="f_status" class="form-select" onchange="this.form.submit()">
                    <option value="All">All Status</option>
                    <option value="Active" <?php echo $f_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                    <option value="Inactive" <?php echo $f_status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-dark">
                    <tr><th>Class</th><th>Section</th><th>Day</th><th>Time</th><th>Subject</th><th>Teacher</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                    <?php if ($rows): foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                        <tr>
                            <td><?php echo rtv($row['class_name'] ?? '-'); ?></td>
                            <td><?php echo rtv($row['section_name'] ?? '-'); ?></td>
                            <td class="small"><?php echo rtv(str_replace(',', ', ', $row['routine_days'] ?? '')); ?></td>
                            <td class="small"><?php echo fmt_time_rt($row['start_time']); ?> - <?php echo fmt_time_rt($row['end_time']); ?></td>
                            <td><?php echo rtv($row['subject_name'] ?? '-'); ?></td>
                            <td><?php echo rtv($row['teacher_name'] ?? '-'); ?></td>
                            <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                            <td class="text-end">
                                <a href="index.php?page=routine_general&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen"></i></a>
                                <form method="POST" action="index.php?page=routine_general" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" name="delete_routine" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="8" class="text-center text-muted py-3">No routine found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
