<?php
require_once __DIR__ . '/../config/portal_access.php';
$result_portal_role = erp_portal_controller_role(['admin','teacher']);
erp_portal_require_role($pdo, $result_portal_role, 'result_marks_entry_subject');
// admin/result_marks_entry_subject.php - Result Module > Exam Procedure > Marks Entry (Subject)
// Class + Shift + Section + Exam Term + Exam Part + Subject সিলেক্ট করলে ঐ কম্বিনেশনের
// স্টুডেন্ট লিস্ট আসবে, প্রতিটার জন্য Mark ইনপুট বক্স দিয়ে একসাথে সেভ করা যাবে।
// Full Mark, exam_assign_subjects (Assign Exam পেজে সেট করা) থেকে আসে।
require_once __DIR__ . '/../config/report_card_helpers.php';
require_once __DIR__ . '/../config/exam_att_grid.php';

$me_err = $_SESSION['me_err'] ?? '';
$me_msg = $_SESSION['me_msg'] ?? '';
unset($_SESSION['me_err'], $_SESSION['me_msg']);

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

// teacher_subject_assignments টেবিল না থাকলে অটো তৈরি (Assign Subject to Teacher পেজ থেকেও তৈরি হয়)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS teacher_subject_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        class_id INT NOT NULL,
        subject_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_assign (employee_id, class_id, subject_id),
        KEY idx_employee (employee_id),
        KEY idx_class_subject (class_id, subject_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// routines টেবিল না থাকলে অটো তৈরি (routine_general.php / routine_dynamic.php / routine_view.php একই টেবিল শেয়ার করে)
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

// exam_attendance_subject টেবিল (Result Module > Attendance (Subject) পেজ থেকে তৈরি হয়) এখনো
// তৈরি না হয়ে থাকলে — নিচের মূল লিস্টিং কোয়েরির LEFT JOIN "Base table or view not found"
// ফ্যাটাল এরর দিয়ে এই পুরো Marks Entry (Subject) পেজ ক্র্যাশ করাত। (একই বাগ
// result_students_report_card.php / result_students_merit_list.php / student_admission.php
// এও পাওয়া গিয়েছিল, একই ফিক্স এখানেও।)
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

// এই পেজ Admin ও Teacher Portal দুই জায়গা থেকেই include হয় (teacher/index.php দেখুন)।
// Teacher Portal থেকে এলে শুধু নিজের অ্যাক্সেসযোগ্য Class/Subject-এই সীমাবদ্ধ থাকবে।
// অ্যাক্সেস দুইভাবে আসে (Union): (ক) Class Routine-এ ঐ টিচারকে ঐ Class/Subject-এ বসানো থাকলে (Active),
// (খ) Assign Subject to Teacher পেজ থেকে ম্যানুয়ালি এসাইন করা থাকলে (Routine-এ না থাকা সাবজেক্টের জন্য)।
$is_teacher_ctx = $result_portal_role === 'teacher';
$me_teacher_id  = $is_teacher_ctx ? (int) ($_SESSION['teacher_id'] ?? 0) : 0;

// টিচারের অ্যাক্সেসযোগ্য (class_id, subject_id) পেয়ার — Routine + Manual Assignment মিলিয়ে
function teacher_allowed_class_subjects(PDO $pdo, int $teacher_id): array {
    $stmt = $pdo->prepare(
        "SELECT class_id, subject_id FROM teacher_subject_assignments WHERE employee_id = ?
         UNION
         SELECT class_id, subject_id FROM routines WHERE teacher_id = ? AND status = 'Active' AND class_id IS NOT NULL"
    );
    $stmt->execute([$teacher_id, $teacher_id]);
    return $stmt->fetchAll();
}

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

function mev($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// POST Handler - একসাথে সব স্টুডেন্টের Mark সেভ
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_marks'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['me_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_marks_entry_subject');
        exit();
    }

    $class_id   = (int) ($_POST['class_id'] ?? 0);
    $shift_id   = (int) ($_POST['shift_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $term_id    = (int) ($_POST['term_id'] ?? 0);
    $part_id    = (int) ($_POST['part_id'] ?? 0);
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $full_mark  = 0.0; // সার্ভার-সাইডে DB থেকে নেওয়া হবে (POST-এর মানের ওপর ভরসা নেই)
    $marks      = (array) ($_POST['marks'] ?? []);

    // Teacher Portal থেকে এলে সরাসরি POST করে অন্য Class/Subject-এ মার্ক ঢুকানো ঠেকাতে
    // সার্ভার সাইডেও অ্যাক্সেস চেক করা হচ্ছে (শুধু dropdown হাইড করাই যথেষ্ট না)।
    $t_not_assigned = false;
    if ($is_teacher_ctx) {
        $allowed = teacher_allowed_class_subjects($pdo, $me_teacher_id);
        $t_not_assigned = true;
        foreach ($allowed as $a) {
            if ((int) $a['class_id'] === $class_id && (int) $a['subject_id'] === $subject_id) {
                $t_not_assigned = false;
                break;
            }
        }
    }

    if (!$class_id || !$shift_id || !$section_id || !$term_id || !$part_id || !$subject_id) {
        $_SESSION['me_err'] = 'Class, Shift, Section, Exam Term, Exam Part এবং Subject সিলেক্ট করুন।';
    } elseif ($t_not_assigned) {
        $_SESSION['me_err'] = 'এই Class/Subject আপনাকে এসাইন করা হয়নি, তাই মার্ক সেভ করা যায়নি।';
    } elseif (rc_is_result_locked($pdo, $current_session_id, $class_id, $term_id)) {
        // এই Class+Term-এর রেজাল্ট Admin কর্তৃক Lock করা থাকলে মার্ক সেভ করা যাবে না, যতক্ষণ না
        // Result Module > Exam Settings > Result Lock/Unlock পেজ থেকে আবার Unlock করা হয়
        $_SESSION['me_err'] = 'এই Class-এর এই Term-এর রেজাল্ট Lock করা আছে, তাই মার্ক এন্ট্রি/এডিট করা যাবে না। আগে Result Lock/Unlock পেজ থেকে Unlock করুন।';
    } else {
        $fm_stmt = $pdo->prepare("SELECT full_mark_exam FROM exam_assign_subjects WHERE class_id=? AND term_id=? AND part_id=? AND subject_id=? AND status='Active' ORDER BY id DESC LIMIT 1");
        $fm_stmt->execute([$class_id, $term_id, $part_id, $subject_id]);
        $fm_row = $fm_stmt->fetch();
        if (!$fm_row) {
            $_SESSION['me_err'] = 'এই Class/Term/Part-এ এই Subject এসাইন করা নেই, তাই মার্ক সেভ করা যায়নি।';
            header('Location: index.php?page=result_marks_entry_subject&class_id=' . $class_id . '&shift_id=' . $shift_id . '&section_id=' . $section_id . '&term_id=' . $term_id . '&part_id=' . $part_id . '&subject_id=' . $subject_id . '&group_id=' . (int) ($_POST['group_id'] ?? 0));
            exit();
        }
        $full_mark = (float) $fm_row['full_mark_exam'];
        $stmt = $pdo->prepare(
            "INSERT INTO exam_marks
                (session_id, term_id, part_id, subject_id, class_id, shift_id, section_id, student_id, marks_obtained)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE marks_obtained = VALUES(marks_obtained)"
        );
        $count = 0;
        $bad_range = 0;
        // Absent শিক্ষার্থীর মার্ক সেভ হবে না
        $abs_stmt = $pdo->prepare("SELECT student_id FROM exam_attendance_subject WHERE term_id=? AND part_id=? AND subject_id=? AND status='Absent'");
        $abs_stmt->execute([$term_id, $part_id, $subject_id]);
        $absent_ids = array_flip(array_map('intval', $abs_stmt->fetchAll(PDO::FETCH_COLUMN)));
        // Eligibility + Attendance (Present) গেট — ব্রাউজার এড়িয়ে POST করলেও সার্ভার আটকাবে
        $gate = ea_mark_states($pdo, $class_id, $shift_id, $section_id, $term_id, $part_id, array_map('intval', array_keys($marks)), [$subject_id]);
        $blocked = 0;
        foreach ($marks as $stid => $m) {
            $stid = (int) $stid;
            if (isset($absent_ids[$stid])) { continue; }
            if (($gate['state'][$stid][$subject_id] ?? 'noelig') !== 'open') { $blocked++; continue; }
            $m = trim((string) $m);
            if ($stid <= 0) { continue; }
            if ($m === '') {
                $stmt->execute([$current_session_id, $term_id, $part_id, $subject_id, $class_id, $shift_id, $section_id, $stid, null]);
                $count++;
                continue;
            }
            if (!is_numeric($m) || (float) $m < 0 || (float) $m > $full_mark) {
                $bad_range++;
                continue;
            }
            $stmt->execute([$current_session_id, $term_id, $part_id, $subject_id, $class_id, $shift_id, $section_id, $stid, (float) $m]);
            $count++;
        }
        sec_log_activity($pdo, 'Result', 'Update', "ক্লাস আইডি {$class_id}, সাবজেক্ট আইডি {$subject_id}, টার্ম আইডি {$term_id} এর Marks Entry (Subject) সংরক্ষণ করা হয়েছে");
        $_SESSION['me_msg'] = $count . ' জন শিক্ষার্থীর মার্ক সেভ করা হয়েছে।' . ($bad_range ? ' (' . $bad_range . ' টা মার্ক Full Mark এর বাইরে থাকায় বাদ দেওয়া হয়েছে।)' : '') . ($blocked ? ' (' . $blocked . ' জন Eligible নয় / Attendance (Present) নেই বলে বাদ দেওয়া হয়েছে।)' : '');
        $mes_nm = function (string $sql, int $id) use ($pdo): string { $q = $pdo->prepare($sql); $q->execute([$id]); return (string) ($q->fetchColumn() ?: '-'); };
        $_SESSION['me_msg'] .= ' [Class: ' . $mes_nm('SELECT class_name FROM academic_classes WHERE id=?', $class_id) . ' | Shift: ' . $mes_nm('SELECT shift_name FROM academic_shifts WHERE id=?', $shift_id) . ' | Section: ' . $mes_nm('SELECT section_name FROM academic_sections WHERE id=?', $section_id) . ' | Exam: ' . $mes_nm('SELECT term_name FROM exam_terms WHERE id=?', $term_id) . ' | Part: ' . $mes_nm('SELECT part_name FROM exam_parts WHERE id=?', $part_id) . ' | Subject: ' . $mes_nm('SELECT subject_name FROM academic_subjects WHERE id=?', $subject_id) . ']';
    }

    header('Location: index.php?page=result_marks_entry_subject&class_id=' . $class_id . '&shift_id=' . $shift_id . '&section_id=' . $section_id . '&term_id=' . $term_id . '&part_id=' . $part_id . '&subject_id=' . $subject_id . '&group_id=' . (int) ($_POST['group_id'] ?? 0) . (!empty($_SESSION['me_msg']) ? '&saved=1' : ''));
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
$sel_group   = (int) ($_GET['group_id'] ?? 0);
$just_saved  = !empty($_GET['saved']); // Save এর পর criteria selected থাকবে, শুধু Student List ফাঁকা থাকবে
$mg_groups   = $sel_class > 0 ? ee_groups_of_class($pdo, $sel_class) : [];
if ($mg_groups && !isset($mg_groups[$sel_group])) $sel_group = 0;

if ($is_teacher_ctx) {
    // শুধু সেই Class যেখানে টিচারের অন্তত একটা Subject-এ অ্যাক্সেস আছে (Routine অথবা Manual Assignment থেকে)
    $allowed_pairs = teacher_allowed_class_subjects($pdo, $me_teacher_id);
    $allowed_class_ids = array_values(array_unique(array_map(fn($a) => (int) $a['class_id'], $allowed_pairs)));

    if ($allowed_class_ids) {
        $in_ph = implode(',', array_fill(0, count($allowed_class_ids), '?'));
        $cls_stmt = $pdo->prepare("SELECT id, class_name FROM academic_classes WHERE status='Active' AND id IN ($in_ph) ORDER BY rank ASC, class_name ASC");
        $cls_stmt->execute($allowed_class_ids);
        $classes = $cls_stmt->fetchAll();
    } else {
        $classes = [];
    }
} else {
    $classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status='Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
}
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
    if ($is_teacher_ctx) {
        $allowed_subject_ids = [];
        foreach ($allowed_pairs as $a) {
            if ((int) $a['class_id'] === $sel_class) { $allowed_subject_ids[] = (int) $a['subject_id']; }
        }
        $allowed_subject_ids = array_values(array_unique($allowed_subject_ids));
        if ($allowed_subject_ids) {
            $in_ph = implode(',', array_fill(0, count($allowed_subject_ids), '?'));
            $subj_stmt = $pdo->prepare("SELECT id, subject_name, group_id FROM academic_subjects WHERE class_id=? AND status='Active' AND id IN ($in_ph) ORDER BY sort_order ASC, id ASC");
            $subj_stmt->execute(array_merge([$sel_class], $allowed_subject_ids));
            $subjects_list = $subj_stmt->fetchAll();
        }
    } else {
        $subj_stmt = $pdo->prepare("SELECT id, subject_name, group_id FROM academic_subjects WHERE class_id=? AND status='Active' ORDER BY sort_order ASC, id ASC");
        $subj_stmt->execute([$sel_class]);
        $subjects_list = $subj_stmt->fetchAll();
    }
    if ($mg_groups && $sel_group > 0) { $GS = $sel_group; $subjects_list = array_values(array_filter($subjects_list, function ($r) use ($GS) { $g = (int) ($r['group_id'] ?? 0); return $g === 0 || $g === $GS; })); }
}

// -----------------------------------------------------------
// সিলেক্টেড কম্বিনেশনের জন্য Full Mark (exam_assign_subjects থেকে) + Student লিস্ট + বিদ্যমান Mark
// -----------------------------------------------------------
$all_selected = ($sel_class > 0 && $sel_shift > 0 && $sel_section > 0 && $sel_term > 0 && $sel_part > 0 && $sel_subject > 0);
$need_group = ($all_selected && $mg_groups && $sel_group <= 0);
if ($need_group) $all_selected = false;
$students = [];
$full_mark_exam = 100;
$assign_missing = false;

if ($all_selected && !$just_saved) {
    $assign_stmt = $pdo->prepare("SELECT full_mark_exam FROM exam_assign_subjects WHERE class_id=? AND term_id=? AND part_id=? AND subject_id=? AND status='Active' ORDER BY id DESC LIMIT 1");
    $assign_stmt->execute([$sel_class, $sel_term, $sel_part, $sel_subject]);
    $assign_row = $assign_stmt->fetch();
    if ($assign_row) {
        $full_mark_exam = (float) $assign_row['full_mark_exam'];
    } else {
        $assign_missing = true;
    }

    $mes_where = ['s.class_id=?', 's.shift_id=?', 's.section_id=?', "s.status='Active'"];
    $mes_params = [$sel_term, $sel_part, $sel_subject, $sel_term, $sel_part, $sel_subject, $sel_class, $sel_shift, $sel_section];
    if ($mg_groups) { $mes_where[] = 's.group_id=?'; $mes_params[] = $sel_group; }
    branch_apply_filter($mes_where, $mes_params, 's.branch_id');
    $stu_stmt = $pdo->prepare(
        "SELECT s.id, s.student_id, s.roll_no, s.name_en, s.photo_path,
                em.marks_obtained AS saved_mark,
                ea.status AS attendance_status
         FROM students s
         LEFT JOIN exam_marks em ON em.student_id = s.id AND em.term_id = ? AND em.part_id = ? AND em.subject_id = ?
         LEFT JOIN exam_attendance_subject ea ON ea.student_id = s.id AND ea.term_id = ? AND ea.part_id = ? AND ea.subject_id = ?
         WHERE " . implode(' AND ', $mes_where) . "
         ORDER BY (s.roll_no + 0) ASC, s.roll_no ASC"
    );
    $stu_stmt->execute($mes_params);
    $students = $stu_stmt->fetchAll();
}
?>

<div class="card-custom">
    <?php if ($me_msg): ?><div class="alert alert-success py-2"><?php echo mev($me_msg); ?></div><?php endif; ?>
    <?php if ($me_err): ?><div class="alert alert-danger py-2"><?php echo mev($me_err); ?></div><?php endif; ?>

    <div class="mb-3">
        <span class="badge bg-primary" style="font-size:13px;"><i class="fa-solid fa-calendar-days me-1"></i> Session : <?php echo mev($current_session['session_name'] ?? '—'); ?></span>
        <?php if (!$current_session_id): ?>
            <span class="text-danger small ms-2">চলতি (Current) Session সেট করা নেই — Academic Settings থেকে সেট করুন, তাহলে Exam Term/Part আসবে।</span>
        <?php elseif (!$terms): ?>
            <span class="text-danger small ms-2">এই Session-এ কোনো Active Exam Term নেই।</span>
        <?php endif; ?>
    </div>
    <form method="GET" action="index.php" id="filterForm">
        <input type="hidden" name="page" value="result_marks_entry_subject">

        <div class="row mb-3 g-3">
            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Class <span class="text-danger">*</span></label>
                    <select name="class_id" id="mes_class" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>" <?php echo $sel_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo mev($c['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Shift <span class="text-danger">*</span></label>
                    <select name="shift_id" id="mes_shift" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($shifts as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo $sel_shift === (int) $s['id'] ? 'selected' : ''; ?>><?php echo mev($s['shift_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Section <span class="text-danger">*</span></label>
                    <select name="section_id" id="mes_section" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($sections as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo $sel_section === (int) $s['id'] ? 'selected' : ''; ?>><?php echo mev($s['section_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="mes_section_hint" class="text-danger small text-end" <?php echo ($sel_class > 0 && $sel_shift > 0) ? 'style="display:none;"' : ''; ?>>আগে Class ও Shift সিলেক্ট করুন</div>
            </div>
        </div>

        <div class="row mb-3 g-3" id="mes_group_row" <?php echo $mg_groups ? '' : 'style="display:none;"'; ?>>
            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Group <span class="text-danger">*</span></label>
                    <select name="group_id" id="mes_group" class="form-select">
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
                    <select name="term_id" id="mes_term" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($terms as $t): ?>
                            <option value="<?php echo (int) $t['id']; ?>" <?php echo $sel_term === (int) $t['id'] ? 'selected' : ''; ?>><?php echo mev($t['term_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Exam Part <span class="text-danger">*</span></label>
                    <select name="part_id" id="mes_part" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($parts as $p): ?>
                            <option value="<?php echo (int) $p['id']; ?>" <?php echo $sel_part === (int) $p['id'] ? 'selected' : ''; ?>><?php echo mev($p['part_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-bold text-nowrap mb-0" style="min-width:95px;">Subject <span class="text-danger">*</span></label>
                    <select name="subject_id" id="mes_subject" class="form-select">
                        <option value="0">-- Select --</option>
                        <?php foreach ($subjects_list as $sub): ?>
                            <option value="<?php echo (int) $sub['id']; ?>" <?php echo $sel_subject === (int) $sub['id'] ? 'selected' : ''; ?>><?php echo mev($sub['subject_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="mes_subject_hint" class="text-danger small text-end" <?php echo ($sel_class > 0) ? 'style="display:none;"' : ''; ?>>আগে Class সিলেক্ট করুন</div>
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

    <div id="mes_result_wrap">
    <?php if ($is_teacher_ctx && !$classes): ?>
        <div class="text-center text-muted py-4">এখনো কোনো Class/Subject আপনাকে এসাইন করা হয়নি। অনুগ্রহ করে Admin-এর সাথে যোগাযোগ করুন।</div>
    <?php elseif ($need_group): ?>
        <?php echo ea_group_need_msg('Mark Entry'); ?>
    <?php elseif ($just_saved && $all_selected): ?>
        <div class="text-center text-muted py-4">মার্ক সেভ সম্পন্ন হয়েছে। পরবর্তী Subject/Section নির্বাচন করুন, অথবা একই Subject আবার দেখতে উপরের যেকোনো একটি filter পুনরায় সিলেক্ট করুন।</div>
    <?php elseif (!$all_selected): ?>
        <div class="text-center text-muted py-4">উপরে Class, Shift, Section, Exam Term, Exam Part এবং Subject সিলেক্ট করুন — তাহলে স্টুডেন্ট লিস্ট এখানে দেখাবে।</div>
    <?php elseif ($assign_missing): ?>
        <div class="text-center text-muted py-4">
            এই Term/Part/Subject কম্বিনেশনের জন্য এখনো Full Mark সেট করা হয়নি।
            আগে <a href="index.php?page=result_assign_exams">Assign Exam</a> পেজ থেকে Full Mark সেট করুন।
        </div>
    <?php elseif (!$students): ?>
        <div class="text-center text-muted py-4">এই Class/Shift/Section এ কোনো Active Student পাওয়া যায়নি।</div>
    <?php else: ?>
        <?php mes_render_subject_form($pdo, $sel_class, $sel_shift, $sel_section, $sel_term, $sel_part, $sel_subject, $sel_group, (float) $full_mark_exam, $students); ?>
    <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var elClass   = document.getElementById('mes_class');
    var elGroup   = document.getElementById('mes_group');
    var elGroupRow = document.getElementById('mes_group_row');
    function setGroups(list) {
        elGroup.innerHTML = '<option value="0">-- Select --</option>';
        list.forEach(function (g) { var o = document.createElement('option'); o.value = g.id; o.textContent = g.name; elGroup.appendChild(o); });
        elGroupRow.style.display = list.length ? '' : 'none';
    }
    var elShift   = document.getElementById('mes_shift');
    var elSection = document.getElementById('mes_section');
    var elTerm    = document.getElementById('mes_term');
    var elPart    = document.getElementById('mes_part');
    var elSubject = document.getElementById('mes_subject');
    var elSecHint = document.getElementById('mes_section_hint');
    var elSubHint = document.getElementById('mes_subject_hint');
    var elWrap    = document.getElementById('mes_result_wrap');

    if (!elClass) return; // Teacher Portal-এ কোনো Class assign না থাকলে ফর্মটাই থাকে না

    function mesAjaxUrl(action, extra) {
        var p = new URLSearchParams(extra || {});
        return '<?php echo $is_teacher_ctx ? '../admin/ajax/' : 'ajax/'; ?>result_marks_entry_subject_ajax.php?portal=<?php echo $result_portal_role; ?>&action=' + action + '&' + p.toString();
    }

    function clearTable() {
        elWrap.innerHTML = '<div class="text-center text-muted py-4">উপরে Class, Shift, Section, Exam Term, Exam Part এবং Subject সিলেক্ট করুন — তাহলে স্টুডেন্ট লিস্ট এখানে দেখাবে।</div>';
    }

    // Class/Shift বদলালে Section ড্রপডাউন AJAX দিয়ে রিলোড হবে (পেজ রিলোড ছাড়াই)
    function loadSections() {
        var classId = elClass.value, shiftId = elShift.value;
        elSection.innerHTML = '<option value="0">-- Select --</option>';
        setGroups([]);
        clearTable();

        if (classId == '0' || shiftId == '0') {
            elSecHint.style.display = '';
            return;
        }
        elSecHint.style.display = 'none';

        fetch(mesAjaxUrl('sections', { class_id: classId, shift_id: shiftId }))
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

    // Class বদলালে Subject ড্রপডাউন AJAX দিয়ে রিলোড হবে (পেজ রিলোড ছাড়াই)
    function loadSubjects() {
        var classId = elClass.value;
        elSubject.innerHTML = '<option value="0">-- Select --</option>';
        clearTable();

        if (classId == '0') {
            elSubHint.style.display = '';
            return;
        }
        elSubHint.style.display = 'none';

        fetch(mesAjaxUrl('subjects', { class_id: classId, group_id: elGroup.value }))
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

    // ছয়টা ফিল্ডই সিলেক্ট হলে টেবিল AJAX দিয়ে লোড হবে (পেজ রিলোড ছাড়াই)
    function loadTable() {
        var classId = elClass.value, shiftId = elShift.value, sectionId = elSection.value,
            termId = elTerm.value, partId = elPart.value, subjectId = elSubject.value;
        if (classId == '0' || shiftId == '0' || sectionId == '0' || termId == '0' || partId == '0' || subjectId == '0') {
            clearTable();
            return;
        }

        elWrap.innerHTML = '<div class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin me-2"></i>লোড হচ্ছে...</div>';

        fetch(mesAjaxUrl('table', { class_id: classId, shift_id: shiftId, section_id: sectionId, term_id: termId, part_id: partId, subject_id: subjectId, group_id: elGroup.value }))
            .then(function (r) { return r.text(); })
            .then(function (html) { elWrap.innerHTML = html; })
            .catch(function () {
                elWrap.innerHTML = '<div class="text-center text-danger py-4">ডাটা লোড করতে সমস্যা হয়েছে, আবার চেষ্টা করুন।</div>';
            });
    }

    elClass.addEventListener('change', function () { loadSections(); loadSubjects(); });
    elShift.addEventListener('change', function () { loadSections(); });
    elSection.addEventListener('change', loadTable);
    elTerm.addEventListener('change', loadTable);
    elPart.addEventListener('change', loadTable);
    elSubject.addEventListener('change', loadTable);
    elGroup.addEventListener('change', function () { loadSubjects(); });
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
    // Enter চাপলে নিচের ঘরের Mark input এ যাবে (শেষ ঘরে Enter দিলে Save বাটনে ফোকাস)
    elWrap.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || !e.target || !e.target.matches || !e.target.matches('#marksForm input[type="number"]:not([disabled])')) { return; }
        e.preventDefault();
        var inputs = Array.prototype.slice.call(elWrap.querySelectorAll('#marksForm input[type="number"]:not([disabled])'));
        var idx = inputs.indexOf(e.target);
        if (idx > -1 && idx < inputs.length - 1) { inputs[idx + 1].focus(); inputs[idx + 1].select(); }
        else { var sb = elWrap.querySelector('button[name="save_marks"]'); if (sb) { sb.focus(); } }
    });
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
