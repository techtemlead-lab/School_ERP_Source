<?php
// config/report_card_helpers.php
// -----------------------------------------------------------------
// GP/GPA হিসাবের শেয়ার্ড লজিক — আগে শুধু admin/result_students_report_card.php তে ছিল,
// এখন admin/student_admission.php (Student Profile > Result ট্যাব) থেকেও একই ফাংশন
// ব্যবহার হয়, যাতে দুই জায়গায় হিসাব কখনো আলাদা না হয়ে যায়।
// -----------------------------------------------------------------

if (!function_exists('rc_get_subject_defs')) {
function rc_get_subject_defs(PDO $pdo, int $class_id, int $term_id, int $part_id): array
{
    require_once __DIR__ . '/subject_rank.php'; subject_rank_ensure($pdo);
    $sql = "SELECT eas.part_id, eas.subject_id, eas.full_mark_exam, eas.pass_mark_exam, eas.pass_individual, eas.fail_on_absent,
                   asub.subject_name, asub.subject_type, asub.optional_count
            FROM exam_assign_subjects eas
            INNER JOIN academic_subjects asub ON asub.id = eas.subject_id
            WHERE eas.class_id = ? AND eas.term_id = ? AND eas.status = 'Active'";
    // INNER JOIN: Subject মুছে ফেলার পরেও exam_assign_subjects-এ পড়ে থাকা "এতিম" রেকর্ড (নামহীন ব্ল্যাংক সাবজেক্ট) রিপোর্ট কার্ডে আসবে না
    $params = [$class_id, $term_id];
    if ($part_id) { $sql .= " AND eas.part_id = ?"; $params[] = $part_id; }
    $sql .= " ORDER BY asub.sort_order ASC, asub.id ASC, eas.part_id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // subject_id => ['subject_id'=>, 'name'=>, 'parts'=>[part_id => row]]
    // Part ফিল্টার না থাকলে (Combined mode) একটা সাবজেক্টের সব Part এখানে জমা হয়,
    // ফলে সব Part-এর মার্ক যোগ হয়ে Term-এর মোট রেজাল্ট বের হয়।
    $defs = [];
    foreach ($stmt->fetchAll() as $r) {
        $sid = (int) $r['subject_id'];
        if (!isset($defs[$sid])) {
            $defs[$sid] = [
                'subject_id' => $sid, 'name' => $r['subject_name'], 'parts' => [],
                'subject_type' => $r['subject_type'] ?? 'Compulsory',
                // Optional subject-এ GP থেকে যত বাদ যাবে (ডিফল্ট ২ — বাংলাদেশের নিয়ম)
                'optional_deduct' => $r['optional_count'] !== null ? (float) $r['optional_count'] : 2.0,
            ];
        }
        $defs[$sid]['parts'][(int) $r['part_id']] = $r;
    }
    return $defs;
}
}

if (!function_exists('rc_calc_subject_totals')) {
function rc_calc_subject_totals(array $parts_def, array $marks_by_part, array $att_by_part): array
{
    $full = 0.0; $obtained = 0.0; $fail = false;
    foreach ($parts_def as $pid => $pinfo) {
        $pfull = (float) $pinfo['full_mark_exam'];
        if ($pfull <= 0) { continue; } // Full Mark 0 মানে এই Part এই সাবজেক্টে নেই (যেমন MCQ 0) — Fail/Total এ ধরা হবে না
        $full += $pfull;
        $mark = $marks_by_part[$pid] ?? null;
        $att_status = $att_by_part[$pid] ?? 'Present';
        $absent = ($att_status === 'Absent');
        if ($absent && (int) $pinfo['fail_on_absent'] === 1) {
            $fail = true;
        } elseif ($mark === null) {
            $fail = true;
        } elseif ((int) $pinfo['pass_individual'] === 1 && (float) $mark < (float) $pinfo['pass_mark_exam']) {
            $fail = true;
        }
        $obtained += $absent ? 0 : ($mark !== null ? (float) $mark : 0);
    }
    return [$full, $obtained, $fail];
}
}

if (!function_exists('rc_apply_optional_bonus')) {
/**
 * Optional (৪র্থ) subject-এর GPA বোনাস মূল GPA-তে যোগ করে — বাংলাদেশের নিয়ম অনুযায়ী
 * (GPA = (Compulsory GP-এর যোগফল + (Optional GP − ২)) ÷ Compulsory subject সংখ্যা, সর্বোচ্চ ৫)।
 * $gpa_add = (Optional GP − deduct) ÷ Compulsory subject সংখ্যা। ফেরত: [gpa, grade_letter, remarks]
 */
function rc_apply_optional_bonus(float $base_gpa, float $base_gl_gpa_add, array $grade_bands, string $base_gl, string $base_remarks): array
{
    if ($base_gl_gpa_add <= 0 || !$grade_bands) { return [$base_gpa, $base_gl, $base_remarks]; }
    $max_gp = 0.0;
    foreach ($grade_bands as $band) { $max_gp = max($max_gp, (float) $band['gpa']); }
    $final = min($max_gp, $base_gpa + $base_gl_gpa_add);
    if ($final <= $base_gpa) { return [$base_gpa, $base_gl, $base_remarks]; }
    $final = round($final, 2);
    // নতুন GPA-র জন্য Grade Letter: যে Grade-এর GPA ≤ final সেগুলোর মধ্যে সর্বোচ্চটা
    $pick = null;
    foreach ($grade_bands as $band) {
        $g = (float) $band['gpa'];
        if ($g <= $final + 0.00001 && ($pick === null || $g > (float) $pick['gpa'])) { $pick = $band; }
    }
    if ($pick === null) { return [$final, $base_gl, $base_remarks]; }
    return [$final, $pick['grade_letter'], trim((string) ($pick['remarks'] ?? ''))];
}
}

if (!function_exists('rc_excluded_subjects')) {
/**
 * এই শিক্ষার্থীর জন্য যেসব Subject রেজাল্টে ধরা হবে না তার তালিকা [subject_id => true]।
 *  ১) Subject-এর Group শিক্ষার্থীর Group-এর সাথে না মিললে (যেমন Humanities-এর স্টুডেন্টের জন্য Science Group-এর Subject)
 *     — Group-ছাড়া কমন/Class-wise Subject সবসময় থাকে।
 *  ২) ধর্মভিত্তিক Subject (academic_subjects.religion) শিক্ষার্থীর ধর্মের সাথে না মিললে।
 *  ৩) Exam Eligibility-তে এই Term-এ এই Subject-এ Eligible = No (is_eligible = 0) সেভ করা থাকলে।
 */
function rc_excluded_subjects(PDO $pdo, array $defs, int $term_id, int $student_id): array
{
    $stu_group = []; $sub_group = []; $elig = [];
    $ex = [];
    if (!$defs || $student_id <= 0) { return $ex; }

    if (!array_key_exists($student_id, $stu_group)) {
        try {
            $q = $pdo->prepare("SELECT group_id FROM students WHERE id = ?");
            $q->execute([$student_id]);
            $stu_group[$student_id] = (int) $q->fetchColumn();
        } catch (Throwable $e) { $stu_group[$student_id] = 0; }
    }
    $missing = [];
    foreach (array_keys($defs) as $sid) { if (!array_key_exists((int) $sid, $sub_group)) { $missing[] = (int) $sid; } }
    if ($missing) {
        try {
            $in = implode(',', $missing);
            foreach ($pdo->query("SELECT id, group_id FROM academic_subjects WHERE id IN ($in)")->fetchAll() as $r) {
                $sub_group[(int) $r['id']] = (int) $r['group_id'];
            }
        } catch (Throwable $e) {}
        foreach ($missing as $m) { if (!array_key_exists($m, $sub_group)) { $sub_group[$m] = 0; } }
    }
    $ek = $term_id . '_' . $student_id;
    if (!isset($elig[$ek])) {
        $elig[$ek] = [];
        try {
            $q = $pdo->prepare("SELECT subject_id FROM exam_eligibility WHERE term_id = ? AND student_id = ? AND is_eligible = 0");
            $q->execute([$term_id, $student_id]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $x) { $elig[$ek][(int) $x] = true; }
        } catch (Throwable $e) {}
    }

    // ৩) ধর্মভিত্তিক Subject: Subject-এর religion ফাঁকা না হলে শুধু ঐ ধর্মের শিক্ষার্থীর জন্য
    require_once __DIR__ . '/subject_religion.php';
    $sub_rel = sr_subject_map($pdo, array_keys($defs));
    $stu_rel = sr_student_religion($pdo, [$student_id])[$student_id] ?? '';

    $tg = $stu_group[$student_id];
    foreach (array_keys($defs) as $sid) {
        $sid = (int) $sid;
        $sg = $sub_group[$sid] ?? 0;
        if ($sg !== 0 && $tg !== 0 && $sg !== $tg) { $ex[$sid] = true; continue; }
        if (!sr_applicable($sub_rel[$sid] ?? '', $stu_rel)) { $ex[$sid] = true; continue; }
        if (!empty($elig[$ek][$sid])) { $ex[$sid] = true; }
    }
    return $ex;
}
}

if (!function_exists('rc_student_result')) {
function rc_student_result(array $defs, array $marks_map, array $att_map, array $grade_bands, array $exclude = []): array
{
    // Compulsory / Continuous Assessment subject-গুলোই Total, % ও মূল GPA নির্ধারণ করে।
    // Optional subject-এর মার্ক Total-এ যোগ হয় না — শুধু (Optional GP − deduct) মূল GPA-তে যোগ হয়,
    // আর Optional-এ ফেল করলে শিক্ষার্থী ফেল ধরা হয় না।
    $full_total = 0.0; $obtained_total = 0.0; $overall_fail = false;
    $subject_rows = []; $srl = 1;
    $comp_count = 0;          // মূল (non-optional) subject সংখ্যা
    $opt_gp_sum = 0.0;        // Σ max(0, Optional GP − deduct)
    $opt_full = 0.0; $opt_obt = 0.0;

    foreach ($defs as $sid => $def) {
        if (!empty($exclude[$sid])) { continue; } // অন্য Group-এর / Not Eligible Subject রেজাল্টে আসবে না
        $is_opt = (($def['subject_type'] ?? 'Compulsory') === 'Optional');

        // Optional subject শিক্ষার্থী নেয়নি (কোনো Part-এ মার্কই এন্ট্রি নেই, Absent-ও না) — সারি থাকে কিন্তু হিসাবে ধরা হয় না
        if ($is_opt) {
            $taken = false;
            foreach ($def['parts'] as $pid => $pinfo) {
                if (($marks_map[$sid][$pid] ?? null) !== null || (($att_map[$sid][$pid] ?? 'Present') === 'Absent')) { $taken = true; break; }
            }
            if (!$taken) {
                $subject_rows[] = [
                    'sl' => $srl++, 'subject_id' => (int) $sid, 'name' => $def['name'], 'gl' => '-', 'gp' => 0.00,
                    'fail' => false, 'absent' => false, 'obtained' => 0.0, 'full' => 0.0,
                    'optional' => true, 'not_taken' => true, 'opt_gp_add' => 0.0,
                ];
                continue;
            }
        }

        [$sfull, $sobt, $sfail] = rc_calc_subject_totals($def['parts'], $marks_map[$sid] ?? [], $att_map[$sid] ?? []);

        $subj_percentage = $sfull > 0 ? ($sobt / $sfull) * 100 : 0;
        $subj_gl = 'F'; $subj_gp = 0.00;
        if (!$sfail) {
            foreach ($grade_bands as $band) {
                if ($subj_percentage >= (float) $band['mark_from'] && $subj_percentage <= (float) $band['mark_to']) {
                    $subj_gl = $band['grade_letter']; $subj_gp = (float) $band['gpa']; break;
                }
            }
            if ($subj_gl === 'F') { $sfail = true; }
        }

        $opt_add = 0.0;
        if ($is_opt) {
            $opt_full += $sfull; $opt_obt += $sobt;
            if (!$sfail) { $opt_add = max(0.0, $subj_gp - (float) ($def['optional_deduct'] ?? 2.0)); }
            $opt_gp_sum += $opt_add;
        } else {
            $full_total += $sfull;
            $obtained_total += $sobt;
            $comp_count++;
            if ($sfail) { $overall_fail = true; }
        }

        $subject_rows[] = [
            'sl' => $srl++, 'subject_id' => (int) $sid, 'name' => $def['name'], 'gl' => $subj_gl, 'gp' => $subj_gp,
            'fail' => $sfail, 'absent' => false, 'obtained' => $sobt, 'full' => $sfull,
            'optional' => $is_opt, 'not_taken' => false, 'opt_gp_add' => $opt_add,
        ];
    }

    $overall_gl = 'F'; $overall_gpa = 0.00; $overall_remarks = '';
    $percentage = $full_total > 0 ? ($obtained_total / $full_total) * 100 : 0;
    $opt_gpa_add = ($comp_count > 0) ? ($opt_gp_sum / $comp_count) : 0.0; // মূল GPA-তে আসলে যত যোগ হবে
    if (!$overall_fail) {
        foreach ($grade_bands as $band) {
            if ($percentage >= (float) $band['mark_from'] && $percentage <= (float) $band['mark_to']) {
                $overall_gl = $band['grade_letter']; $overall_gpa = (float) $band['gpa'];
                $overall_remarks = trim((string) ($band['remarks'] ?? ''));
                break;
            }
        }
        if ($overall_gl === 'F') { $overall_fail = true; }
    }
    if (!$overall_fail) {
        [$overall_gpa, $overall_gl, $overall_remarks] = rc_apply_optional_bonus($overall_gpa, $opt_gpa_add, $grade_bands, $overall_gl, $overall_remarks);
    }

    $fail_subject_count = 0;
    foreach ($subject_rows as $sr) { if ($sr['fail'] && empty($sr['optional'])) { $fail_subject_count++; } }

    return [
        'subject_rows' => $subject_rows,
        'full_total' => $full_total,
        'obtained_total' => $obtained_total,
        'percentage' => $percentage,
        'overall_fail' => $overall_fail,
        'overall_gl' => $overall_gl,
        'overall_gpa' => $overall_gpa,
        // Grade Points সেটিংসে অ্যাডমিন যদি এই Grade Letter-এর জন্য নিজের একটা Remarks লিখে
        // রাখেন (Result Module > Grade Points > Remarks কলাম), সেটা এখানে ফেরত আসে — খালি
        // থাকলে '' রিটার্ন হয়, তখন কলার (Report Card ইত্যাদি) নিজের ডিফল্ট % ভিত্তিক
        // Remark (Excellent/Very Good/...) ব্যবহার করবে।
        'overall_remarks' => $overall_remarks,
        'fail_subject_count' => $fail_subject_count,
        // Optional subject-এর তথ্য (Report Card / Tabulation-এ দেখানোর জন্য)
        'optional_gpa_add' => $opt_gpa_add,
        'optional_obtained' => $opt_obt,
        'optional_full' => $opt_full,
    ];
}
}

// =========================================================================
// Merit Tie-Breaker — Total মার্ক সমান হলে (যেমন ৩ জন প্রথম) কাকে আগে বসানো
// হবে তা ঠিক করার জন্য Admin-এর নিজের সাজানো ক্রাইটেরিয়া (Result Module >
// Exam Settings > Merit Tie-Breaker পেজ থেকে কনফিগার করা হয়)। Tabulation
// Sheet ও Students Merit List — দুই জায়গাতেই এই একই শেয়ার্ড ফাংশন ব্যবহার হয়।
// =========================================================================

if (!function_exists('rc_tiebreaker_ensure_table')) {
function rc_tiebreaker_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS result_merit_tiebreakers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            class_id INT NOT NULL DEFAULT 0,
            criterion_type VARCHAR(20) NOT NULL,
            subject_id INT NULL,
            priority_order INT NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_session_class (session_id, class_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // পুরনো ইনস্টলেশনে (Class-wise ফিচার আসার আগে তৈরি হওয়া টেবিলে) class_id কলাম না
    // থাকলে নিরাপদে যোগ করা হচ্ছে — result_exam_terms-এ fee_month/exam_fee_head_id যেভাবে
    // যোগ করা হয়েছিল সেই একই self-heal প্যাটার্ন
    $chk = $pdo->query("SHOW COLUMNS FROM result_merit_tiebreakers LIKE 'class_id'")->fetch();
    if (!$chk) {
        $pdo->exec("ALTER TABLE result_merit_tiebreakers ADD COLUMN class_id INT NOT NULL DEFAULT 0 AFTER session_id");
    }
}
}

// Result Module > Exam Settings > Master Settings পেজে "Enable Merit Tie-Breaker" Yes/No টগল
// আছে (exam_result_settings.enable_merit_tiebreaker) — টেবিলটা এখনো না থাকলে (Master Settings
// পেজ কখনো ভিজিট করা না থাকলে) ডিফল্ট Enabled ধরা হয়, টেবিল থাকলে কিন্তু কলামটা না থাকলে
// (পুরনো ইনস্টলেশন) নিরাপদে self-heal করে যোগ করা হয় — result_settings.php-এর সাথে একই প্যাটার্ন।
if (!function_exists('rc_merit_tiebreaker_globally_enabled')) {
function rc_merit_tiebreaker_globally_enabled(PDO $pdo): bool
{
    $exists = $pdo->query("SHOW TABLES LIKE 'exam_result_settings'")->fetch();
    if (!$exists) { return true; }

    $cols = $pdo->query("SHOW COLUMNS FROM exam_result_settings")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('enable_merit_tiebreaker', $cols, true)) {
        $pdo->exec("ALTER TABLE exam_result_settings ADD COLUMN enable_merit_tiebreaker TINYINT(1) NOT NULL DEFAULT 1");
        return true;
    }

    $val = $pdo->query("SELECT enable_merit_tiebreaker FROM exam_result_settings WHERE id = 1")->fetchColumn();
    return $val === false ? true : ((int) $val === 1);
}
}

// Session + Class-এর জন্য অ্যাডমিনের সাজানো Tie-Breaker ক্রাইটেরিয়া (Priority ক্রমে) — প্রতিটা
// ক্লাসের জন্য আলাদা ক্রাইটেরিয়া সেট করা যায় (যেমন Class Six-এ Math > Attendance, কিন্তু Class
// Nine-এ হয়তো Physics > English)। খালি হলে অথবা Master Settings-এ টগল বন্ধ থাকলে পুরনো আচরণই
// থাকবে (সমান Total = যৌথ Merit Position)
if (!function_exists('rc_get_tiebreak_criteria')) {
function rc_get_tiebreak_criteria(PDO $pdo, int $session_id, int $class_id): array
{
    rc_tiebreaker_ensure_table($pdo);
    if (!rc_merit_tiebreaker_globally_enabled($pdo)) { return []; }
    $stmt = $pdo->prepare(
        "SELECT rmt.id, rmt.criterion_type, rmt.subject_id, rmt.priority_order, asub.subject_name
         FROM result_merit_tiebreakers rmt
         LEFT JOIN academic_subjects asub ON asub.id = rmt.subject_id
         WHERE rmt.session_id = ? AND rmt.class_id = ? AND rmt.status = 'Active'
         ORDER BY rmt.priority_order ASC"
    );
    $stmt->execute([$session_id, $class_id]);
    return $stmt->fetchAll();
}
}

// প্রতিটা স্টুডেন্টের সামগ্রিক (সব রেকর্ড করা তারিখ মিলিয়ে) উপস্থিতির হার % — Attendance
// ক্রাইটেরিয়ার জন্য দরকার (student_daily_attendance টেবিল, attendance_manual.php-এর সাথে হুবহু মিল)
if (!function_exists('rc_get_attendance_percent_map')) {
function rc_get_attendance_percent_map(PDO $pdo, array $student_ids): array
{
    $student_ids = array_values(array_unique(array_map('intval', $student_ids)));
    if (!$student_ids) { return []; }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS student_daily_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            class_id INT NULL,
            section_id INT NULL,
            shift_id INT NULL,
            attendance_date DATE NOT NULL,
            status ENUM('Present','Absent') NOT NULL DEFAULT 'Absent',
            marked_by VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_date (student_id, attendance_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $in = implode(',', array_fill(0, count($student_ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT student_id,
                SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) AS present_days,
                COUNT(*) AS total_days
         FROM student_daily_attendance WHERE student_id IN ($in) GROUP BY student_id"
    );
    $stmt->execute($student_ids);
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $total = (float) $r['total_days'];
        $map[(int) $r['student_id']] = $total > 0 ? ((float) $r['present_days'] / $total) * 100 : 0.0;
    }
    return $map;
}
}

// দুইজন স্টুডেন্ট Total + সবগুলো Tie-Breaker ক্রাইটেরিয়াতেও হুবহু সমান কিনা — সমান হলে তারা
// একই Merit Position পাবে (Competition ranking: 1,2,2,4 — পুরনো আচরণের সাথে সামঞ্জস্যপূর্ণ)
if (!function_exists('rc_students_fully_tied')) {
function rc_students_fully_tied(array $a, array $b, array $criteria, array $attendance_map, array $dob_map): bool
{
    if ((float) $a['total'] !== (float) $b['total']) { return false; }
    foreach ($criteria as $c) {
        if ($c['criterion_type'] === 'subject') {
            $sid = (int) $c['subject_id'];
            $av = (float) ($a['subject_marks'][$sid] ?? -1);
            $bv = (float) ($b['subject_marks'][$sid] ?? -1);
            if ($av !== $bv) { return false; }
        } elseif ($c['criterion_type'] === 'attendance') {
            $av = (float) ($attendance_map[$a['id']] ?? -1);
            $bv = (float) ($attendance_map[$b['id']] ?? -1);
            if ($av !== $bv) { return false; }
        } elseif ($c['criterion_type'] === 'age') {
            $ad = $dob_map[$a['id']] ?? null; $bd = $dob_map[$b['id']] ?? null;
            if ($ad !== $bd) { return false; }
        }
    }
    return true;
}
}

// Pass করা স্টুডেন্টদের Merit Position বের করা — Total মার্ক অনুযায়ী Descending, সমান Total হলে
// Admin-এর সাজানো ক্রাইটেরিয়া (Subject মার্ক > Attendance % > বয়স, Priority ক্রমে) দিয়ে কাটাকাটি,
// সবগুলোতেই সমান থাকলে তবেই যৌথ Merit Position।
// $students: প্রতিটা এন্ট্রি ['id'=>student_id, 'total'=>obtained_total, 'subject_marks'=>[subject_id=>obtained মার্ক]]
// রিটার্ন করে: [student_id => 'merit position (string)']
if (!function_exists('rc_build_merit_map')) {
function rc_build_merit_map(PDO $pdo, array $students, int $session_id, int $class_id): array
{
    if (!$students) { return []; }
    $criteria = rc_get_tiebreak_criteria($pdo, $session_id, $class_id);

    $attendance_map = [];
    $dob_map = [];
    if ($criteria) {
        $ids = array_column($students, 'id');
        foreach ($criteria as $c) {
            if ($c['criterion_type'] === 'attendance') { $attendance_map = rc_get_attendance_percent_map($pdo, $ids); }
        }
        $need_age = false;
        foreach ($criteria as $c) { if ($c['criterion_type'] === 'age') { $need_age = true; break; } }
        if ($need_age) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, dob FROM students WHERE id IN ($in)");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $r) { $dob_map[(int) $r['id']] = $r['dob']; }
        }
    }

    usort($students, function ($a, $b) use ($criteria, $attendance_map, $dob_map) {
        if ((float) $a['total'] !== (float) $b['total']) { return (float) $b['total'] <=> (float) $a['total']; }
        foreach ($criteria as $c) {
            if ($c['criterion_type'] === 'subject') {
                $sid = (int) $c['subject_id'];
                $av = (float) ($a['subject_marks'][$sid] ?? -1);
                $bv = (float) ($b['subject_marks'][$sid] ?? -1);
                if ($av !== $bv) { return $bv <=> $av; }
            } elseif ($c['criterion_type'] === 'attendance') {
                $av = (float) ($attendance_map[$a['id']] ?? -1);
                $bv = (float) ($attendance_map[$b['id']] ?? -1);
                if ($av !== $bv) { return $bv <=> $av; }
            } elseif ($c['criterion_type'] === 'age') {
                $ad = $dob_map[$a['id']] ?? null; $bd = $dob_map[$b['id']] ?? null;
                // বয়স কম = জন্মতারিখ পরে (বেশি recent) = আগে বসবে
                if ($ad && $bd && $ad !== $bd) { return strtotime($bd) <=> strtotime($ad); }
            }
        }
        return 0;
    });

    $merit_map = [];
    $count = count($students);
    for ($i = 0; $i < $count; $i++) {
        if ($i > 0 && rc_students_fully_tied($students[$i - 1], $students[$i], $criteria, $attendance_map, $dob_map)) {
            $merit_map[$students[$i]['id']] = $merit_map[$students[$i - 1]['id']];
        } else {
            $merit_map[$students[$i]['id']] = (string) ($i + 1);
        }
    }
    return $merit_map;
}
}

// =========================================================================
// Final / Aggregated Result — একাধিক Exam Term (যেমন Half Yearly + Annual)
// মিলিয়ে একটা চূড়ান্ত Yearly রেজাল্ট বের করা। Master Settings > Result Templates
// Settings > "Aggregate Result Type"-এ ৪টা ভিন্ন হিসাব-পদ্ধতি আছে — এই ফাংশন
// প্রতিটা টাইপের জন্য সত্যিকারের আলাদা গণনা করে:
//   Terms Wise Percentage           → প্রতি Term-এর % বের করে, সেই %-গুলোর গড়
//   Terms Wise Average               → প্রতি Term-এর প্রাপ্ত Total মার্কের গড়
//   Terms & Subjects Wise Average    → প্রতি Subject আলাদাভাবে সব Term মিলিয়ে গড় করে যোগ
//   Terms, Subjects & Part Wise Average → প্রতি Subject-এর প্রতিটা Part আলাদাভাবে
//                                          সব Term মিলিয়ে গড় করে, তারপর যোগ
// এই ৪টা হিসাব একই তখনই মিলে যায় যখন সবগুলো Term-এ Subject/Part/Full-Mark
// স্ট্রাকচার হুবহু এক — বাস্তবে Half Yearly ও Annual-এর স্ট্রাকচার আলাদা হলে
// (নতুন Subject যোগ, আলাদা Full Mark, আলাদা Part) ফলাফলও আলাদা হবে।
// =========================================================================

if (!function_exists('rc_aggregate_terms_result')) {
function rc_aggregate_terms_result(PDO $pdo, int $student_id, array $term_ids, int $class_id, string $aggregate_type, array $grade_bands, int $pick_group_id = 0): array
{
    // শতকরা (Percentage) ওয়েট মডেল ১-৪ — নিচে আলাদা ফাংশনে হিসাব হয় (আগের ৪টা গড়-ভিত্তিক পদ্ধতি অপরিবর্তিত)
    if (rc_pct_is_model($aggregate_type)) {
        return rc_aggregate_pct_model($pdo, $student_id, $term_ids, $class_id, $aggregate_type, $grade_bands, $pick_group_id);
    }
    $per_term = [];
    // subject_id => ['name'=>, 'entries'=>[ ['obtained'=>,'full'=>,'parts'=>[part_id=>['obtained'=>,'full'=>,'name'=>]]] , ... ] ]
    $subject_union = [];

    foreach ($term_ids as $term_id) {
        $defs = rc_get_subject_defs($pdo, $class_id, $term_id, 0); // 0 = Combined (সব Part)
        if (!$defs) { continue; }
        $subject_ids = array_keys($defs);
        $in_subj = implode(',', array_fill(0, count($subject_ids), '?'));

        $mk = $pdo->prepare("SELECT subject_id, part_id, marks_obtained FROM exam_marks WHERE term_id = ? AND student_id = ? AND subject_id IN ($in_subj)");
        $mk->execute(array_merge([$term_id, $student_id], $subject_ids));
        $marks_map = [];
        foreach ($mk->fetchAll() as $r) { $marks_map[$r['subject_id']][$r['part_id']] = $r['marks_obtained']; }

        $at = $pdo->prepare("SELECT subject_id, part_id, status FROM exam_attendance_subject WHERE term_id = ? AND student_id = ? AND subject_id IN ($in_subj)");
        $at->execute(array_merge([$term_id, $student_id], $subject_ids));
        $att_map = [];
        foreach ($at->fetchAll() as $r) { $att_map[$r['subject_id']][$r['part_id']] = $r['status']; }

        $res = rc_student_result($defs, $marks_map, $att_map, $grade_bands, rc_excluded_subjects($pdo, $defs, (int) $term_id, (int) $student_id));

        $per_term[$term_id] = [
            'obtained_total' => $res['obtained_total'], 'full_total' => $res['full_total'],
            'percentage' => $res['percentage'], 'fail' => $res['overall_fail'],
            'opt_gpa_add' => $res['optional_gpa_add'] ?? 0.0,
        ];

        $sr_map = array_column($res['subject_rows'], null, 'subject_id');
        foreach ($defs as $sid => $def) {
            $sr = $sr_map[$sid] ?? null;
            if ($sr === null) { continue; } // Group-এর নয় / Not Eligible
            if (!empty($sr['not_taken'])) { continue; } // এই Term-এ Optional subject নেয়নি
            if (!isset($subject_union[$sid])) { $subject_union[$sid] = ['name' => $def['name'], 'entries' => [], 'optional' => !empty($sr['optional'])]; }
            $part_entries = [];
            foreach ($def['parts'] as $pid => $pinfo) {
                $pmark = $marks_map[$sid][$pid] ?? null;
                $pabsent = ($att_map[$sid][$pid] ?? 'Present') === 'Absent';
                $part_entries[$pid] = ['obtained' => $pabsent ? 0 : (float) ($pmark ?? 0), 'full' => (float) $pinfo['full_mark_exam']];
            }
            $subject_union[$sid]['entries'][] = ['obtained' => $sr['obtained'], 'full' => $sr['full'], 'parts' => $part_entries];
        }
    }

    if (!$per_term) {
        return ['per_term' => [], 'subject_breakdown' => [], 'final_obtained' => 0, 'final_full' => 0, 'final_percentage' => 0, 'final_gl' => 'F', 'final_gpa' => 0.0, 'final_fail' => true];
    }

    // সব সময় Subject-ভিত্তিক গড় করে ব্রেকডাউন টেবিল দেখানো হয় (c/d-এর হিসাব, ডিসপ্লের জন্য) —
    // চূড়ান্ত Total/% নিচে aggregate_type অনুযায়ী ভিন্নভাবে বের হবে
    $subject_breakdown = [];
    foreach ($subject_union as $sid => $s) {
        $sum_obt = 0.0; $sum_full = 0.0;
        foreach ($s['entries'] as $e) { $sum_obt += $e['obtained']; $sum_full += $e['full']; }
        $n = count($s['entries']);
        $subject_breakdown[$sid] = [
            'name' => $s['name'],
            'optional' => !empty($s['optional']),
            'avg_obtained' => $n > 0 ? $sum_obt / $n : 0,
            'avg_full' => $n > 0 ? $sum_full / $n : 0,
        ];
    }

    $any_term_fail = false;
    foreach ($per_term as $pt) { if ($pt['fail']) { $any_term_fail = true; break; } }

    $final_obtained = 0.0; $final_full = 0.0; $final_percentage = 0.0;

    if ($aggregate_type === 'Terms Wise Percentage') {
        $sum_pct = 0.0;
        foreach ($per_term as $pt) { $sum_pct += $pt['percentage']; }
        $final_percentage = $sum_pct / count($per_term);
        // ডিসপ্লের জন্য Total/Full-ও দেখানো হয় (Full = Term-গুলোর Full-এর গড়, Obtained = % থেকে reverse হিসাব)
        $sum_full = 0.0;
        foreach ($per_term as $pt) { $sum_full += $pt['full_total']; }
        $final_full = $sum_full / count($per_term);
        $final_obtained = ($final_percentage / 100) * $final_full;
    } elseif ($aggregate_type === 'Terms Wise Average') {
        $sum_obt = 0.0; $sum_full = 0.0;
        foreach ($per_term as $pt) { $sum_obt += $pt['obtained_total']; $sum_full += $pt['full_total']; }
        $final_obtained = $sum_obt / count($per_term);
        $final_full = $sum_full / count($per_term);
        $final_percentage = $final_full > 0 ? ($final_obtained / $final_full) * 100 : 0;
    } elseif ($aggregate_type === 'Terms & Subjects Wise Average') {
        foreach ($subject_breakdown as $sb) { if (!empty($sb['optional'])) continue; $final_obtained += $sb['avg_obtained']; $final_full += $sb['avg_full']; }
        $final_percentage = $final_full > 0 ? ($final_obtained / $final_full) * 100 : 0;
    } else { // 'Terms, Subjects & Part Wise Average'
        foreach ($subject_union as $sid => $s) {
            if (!empty($s['optional'])) continue; // Optional subject-এর মার্ক Total-এ যোগ হয় না
            // প্রতিটা Part আলাদাভাবে যত টার্মে এই Subject+Part পাওয়া গেছে তার গড়
            $part_sums = []; // part_id => ['obt'=>, 'full'=>, 'n'=>]
            foreach ($s['entries'] as $e) {
                foreach ($e['parts'] as $pid => $p) {
                    if (!isset($part_sums[$pid])) { $part_sums[$pid] = ['obt' => 0.0, 'full' => 0.0, 'n' => 0]; }
                    $part_sums[$pid]['obt']  += $p['obtained'];
                    $part_sums[$pid]['full'] += $p['full'];
                    $part_sums[$pid]['n']++;
                }
            }
            foreach ($part_sums as $ps) {
                $final_obtained += $ps['n'] > 0 ? $ps['obt'] / $ps['n'] : 0;
                $final_full     += $ps['n'] > 0 ? $ps['full'] / $ps['n'] : 0;
            }
        }
        $final_percentage = $final_full > 0 ? ($final_obtained / $final_full) * 100 : 0;
    }

    $final_gl = 'F'; $final_gpa = 0.00; $final_fail = $any_term_fail;
    if (!$final_fail) {
        foreach ($grade_bands as $band) {
            if ($final_percentage >= (float) $band['mark_from'] && $final_percentage <= (float) $band['mark_to']) {
                $final_gl = $band['grade_letter']; $final_gpa = (float) $band['gpa']; break;
            }
        }
        if ($final_gl === 'F') { $final_fail = true; }
    }
    if (!$final_fail) {
        // Optional subject-এর GPA বোনাস — Term-গুলোর গড়
        $sum_add = 0.0;
        foreach ($per_term as $pt) { $sum_add += (float) ($pt['opt_gpa_add'] ?? 0); }
        [$final_gpa, $final_gl] = array_slice(rc_apply_optional_bonus($final_gpa, $sum_add / count($per_term), $grade_bands, $final_gl, ''), 0, 2);
    }

    return [
        'per_term' => $per_term,
        'subject_breakdown' => $subject_breakdown,
        'final_obtained' => $final_obtained,
        'final_full' => $final_full,
        'final_percentage' => $final_percentage,
        'final_gl' => $final_gl,
        'final_gpa' => $final_gpa,
        'final_fail' => $final_fail,
    ];
}
}

// =========================================================================
// শতকরা (Percentage) ওয়েট মডেল ১-৪ ও গড় মডেল — Master Settings > Result Templates >
// "Aggregate Result Type"-এর অপশন। ওয়েট/কাঠামো এখন Exam Terms পেজে টার্ম বানানোর সময়ই দেওয়া হয়:
//   * Main টার্ম  = parent_term_id ফাঁকা। ১ম টার্ম, ২য় টার্ম, ফাইনাল ইত্যাদি
//   * Sub টার্ম   = parent_term_id = কোনো Main টার্মের id। মাসিক / ক্লাস টেস্ট
//   * weight_pct  = Main-এর ক্ষেত্রে "মোটে ওয়েট %", Sub-এর ক্ষেত্রে "Main-এর ভেতরে ওয়েট %"
// Main টার্মের নিজের পরীক্ষার ওয়েট = ১০০ − তার সব Sub-এর ওয়েটের যোগফল (অটো)।
//   মডেল ১ (flat)       : সব Main; ফাইনাল% = Σ (ওয়েট × পরীক্ষার%) ÷ ১০০ ; ওয়েটের যোগফল ১০০ (Sub ধরা হয় না)
//   মডেল ২ (nested)     : প্রতিটা Main = একটা গ্রুপ (নিজের পরীক্ষা + Sub-গুলো), গ্রুপের ভেতরের ওয়েট অনুযায়ী মিলিত %;
//                          ফাইনাল% = Σ (Main-এর মোটে ওয়েট × গ্রুপ%) ÷ ১০০ ; Main ওয়েটের যোগফল ১০০
//   মডেল ৩ (standalone) : প্রতিটা Main আলাদা রেজাল্ট (Main + Sub-এর ভেতরের ওয়েট), অন্য Main-এর সাথে যোগ হয় না
//   মডেল ৪ (flat)       : মডেল ১-এর মতোই — সব পরীক্ষা (সাময়িক/মাসিক) Main, প্রত্যেকে সরাসরি মোট ওয়েট পায়
//   গড় মডেল (avg_group): "Aggregated Result = YES" Main + তার Sub-দের নম্বরের সাধারণ গড় = গ্রুপ; গ্রুপগুলোর গড় = ফাইনাল
// অনুপস্থিত / মার্ক এন্ট্রি নেই / সাবজেক্ট অ্যাসাইন নেই = সেই পরীক্ষা ০ ধরা হয় (ওয়েট পুনর্বণ্টন হয় না)।
// =========================================================================

if (!function_exists('rc_pct_models')) {
function rc_pct_models(): array
{
    return [
        'Percentage Model 1' => ['type' => 'flat'],
        'Percentage Model 2' => ['type' => 'nested'],
        'Percentage Model 3' => ['type' => 'standalone'],
        'Percentage Model 4' => ['type' => 'flat'],
        'Average Model 1'    => ['type' => 'avg_group'],
    ];
}
}

// Exam Terms পেজ ইত্যাদিতে দেখানোর ছোট নাম
if (!function_exists('rc_pct_model_label')) {
function rc_pct_model_label(string $key): string
{
    $l = [
        'Percentage Model 1' => 'শতকরা মডেল ১ — টার্ম ওয়েট',
        'Percentage Model 2' => 'শতকরা মডেল ২ — মাসিক + টার্ম মিলিত, তারপর টার্ম ওয়েট',
        'Percentage Model 3' => 'শতকরা মডেল ৩ — প্রতি টার্ম আলাদা রেজাল্ট',
        'Percentage Model 4' => 'শতকরা মডেল ৪ — সব পরীক্ষার সরাসরি ওয়েট',
        'Average Model 1'    => 'গড় মডেল — মাসিক + টার্ম গড়',
    ];
    return $l[$key] ?? $key;
}
}

if (!function_exists('rc_pct_is_model')) {
function rc_pct_is_model(string $t): bool
{
    return isset(rc_pct_models()[$t]);
}
}

// exam_terms-এ Main/Sub কাঠামো ও ওয়েটের কলাম — না থাকলে নিজে যোগ হয় (পুরনো ইনস্টলেশনেও)
if (!function_exists('rc_pct_ensure_term_columns')) {
function rc_pct_ensure_term_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM exam_terms")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('parent_term_id', $cols, true)) {
            $pdo->exec("ALTER TABLE exam_terms ADD COLUMN parent_term_id INT NULL DEFAULT NULL");
        }
        if (!in_array('weight_pct', $cols, true)) {
            $pdo->exec("ALTER TABLE exam_terms ADD COLUMN weight_pct DECIMAL(6,2) NULL DEFAULT NULL");
        }
    } catch (Throwable $e) { /* পরের রিকোয়েস্টে আবার চেষ্টা হবে */ $done = false; }
}
}

if (!function_exists('rc_pct_fmt')) {
function rc_pct_fmt($n): string
{
    return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
}
}

// এই সেশনের Exam Term-এর Main/Sub কাঠামো থেকে মডেলের গ্রুপ-কনফিগ বানানো ও যাচাই।
// ফেরত: ['groups' => [ ['id','name','weight','items'=>[['term_id','weight'],..]],.. ], 'errors' => [..], 'warnings' => [..], 'names' => [id => নাম]]
// errors খালি না হলে কনফিগ ব্যবহারযোগ্য নয় (rc_pct_load_config তখন খালি ফেরত দেয়)।
if (!function_exists('rc_pct_structure')) {
function rc_pct_structure(PDO $pdo, int $session_id, string $model_key): array
{
    $out = ['groups' => [], 'errors' => [], 'warnings' => [], 'names' => []];
    $m = rc_pct_models()[$model_key] ?? null;
    if (!$m || $session_id <= 0) { $out['errors'][] = 'Session বা মডেল ঠিক নেই।'; return $out; }
    $type = $m['type'];
    rc_pct_ensure_term_columns($pdo);

    $q = $pdo->prepare("SELECT id, term_name, rank, parent_term_id, weight_pct, is_aggregated_result FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC, id ASC");
    $q->execute([$session_id]);
    $mains = []; $subs = []; $by_id = [];
    foreach ($q->fetchAll() as $r) { $by_id[(int) $r['id']] = $r; $out['names'][(int) $r['id']] = $r['term_name']; }
    foreach ($by_id as $id => $r) {
        $pid = (int) ($r['parent_term_id'] ?? 0);
        if ($pid > 0 && isset($by_id[$pid]) && (int) ($by_id[$pid]['parent_term_id'] ?? 0) === 0) { $subs[$pid][] = $r; }
        elseif ($pid > 0) { $out['warnings'][] = '"' . $r['term_name'] . '" Sub টার্মের Main টার্ম পাওয়া যায়নি (Inactive বা মুছে গেছে) — এটা ধরা হয়নি।'; }
        else { $mains[$id] = $r; }
    }
    if (!$mains) { $out['errors'][] = 'এই সেশনে কোনো সক্রিয় Main টার্ম নেই।'; return $out; }

    $wt = fn($r) => $r['weight_pct'] === null || $r['weight_pct'] === '' ? 0.0 : round((float) $r['weight_pct'], 2);
    $eps = 0.011;
    $sub_total = 0; foreach ($subs as $list) { $sub_total += count($list); }

    if ($type === 'flat') {
        $items = []; $sum = 0.0;
        foreach ($mains as $id => $r) {
            if ($wt($r) > 0) { $items[] = ['term_id' => $id, 'weight' => $wt($r)]; $sum += $wt($r); }
            else { $out['warnings'][] = '"' . $r['term_name'] . '"-এ মোটে ওয়েট নেই — ফাইনাল ফলাফলে ধরা হবে না।'; }
        }
        if ($sub_total > 0) { $out['warnings'][] = 'এই মডেলে সব পরীক্ষা Main হিসেবেই ধরা হয় — ' . $sub_total . 'টা Sub টার্ম হিসাবে আসবে না।'; }
        if (!$items) { $out['errors'][] = 'কোনো Main টার্মে ওয়েট দেওয়া হয়নি।'; }
        elseif (abs($sum - 100) > $eps) { $out['errors'][] = 'সব Main টার্মের মোটে ওয়েটের যোগফল এখন ' . rc_pct_fmt($sum) . '% — ঠিক ১০০% হতে হবে।'; }
        $out['groups'] = [['id' => 0, 'name' => 'টার্ম', 'weight' => 100.0, 'items' => $items]];
        return $out;
    }

    if ($type === 'avg_group') {
        foreach ($mains as $id => $r) {
            if ((int) $r['is_aggregated_result'] !== 1) { $out['warnings'][] = '"' . $r['term_name'] . '"-এ Aggregated Result = NO — গড়ে ধরা হবে না।'; continue; }
            $items = [['term_id' => $id, 'weight' => 0.0]];
            foreach ($subs[$id] ?? [] as $sr) { $items[] = ['term_id' => (int) $sr['id'], 'weight' => 0.0]; }
            $out['groups'][] = ['id' => $id, 'name' => $r['term_name'], 'weight' => 0.0, 'items' => $items];
        }
        if (!$out['groups']) { $out['errors'][] = 'Aggregated Result = YES করা কোনো Main টার্ম নেই।'; }
        return $out;
    }

    // nested / standalone: প্রতিটা Main = একটা গ্রুপ (নিজের পরীক্ষা + Sub)
    $gw_sum = 0.0; $n_groups = 0;
    foreach ($mains as $id => $r) {
        $include = $type === 'standalone' ? true : ($wt($r) > 0);
        if (!$include) { $out['warnings'][] = '"' . $r['term_name'] . '"-এ মোটে ওয়েট নেই — ফাইনাল ফলাফলে (ও এর Sub-গুলো) ধরা হবে না।'; continue; }
        $items = []; $sub_sum = 0.0; $bad = false;
        foreach ($subs[$id] ?? [] as $sr) {
            if ($wt($sr) <= 0) { $out['errors'][] = '"' . $sr['term_name'] . '" Sub টার্মের (প্যারেন্ট: ' . $r['term_name'] . ') ভেতরের ওয়েট দেওয়া হয়নি।'; $bad = true; continue; }
            $items[] = ['term_id' => (int) $sr['id'], 'weight' => $wt($sr)]; $sub_sum += $wt($sr);
        }
        if ($sub_sum > 100 + $eps) { $out['errors'][] = '"' . $r['term_name'] . '"-এর Sub টার্মগুলোর ওয়েটের যোগফল ' . rc_pct_fmt($sub_sum) . '% — ১০০%-এর বেশি হতে পারবে না।'; }
        $own = round(100 - $sub_sum, 2);
        if ($own > $eps) { array_unshift($items, ['term_id' => $id, 'weight' => $own]); }
        $out['groups'][] = ['id' => $id, 'name' => $r['term_name'], 'weight' => $type === 'nested' ? $wt($r) : 0.0, 'items' => $items];
        $gw_sum += $wt($r); $n_groups++;
    }
    if (!$n_groups) { $out['errors'][] = $type === 'nested' ? 'কোনো Main টার্মে মোটে ওয়েট দেওয়া হয়নি।' : 'কোনো Main টার্ম পাওয়া যায়নি।'; }
    elseif ($type === 'nested' && abs($gw_sum - 100) > $eps) { $out['errors'][] = 'সব Main টার্মের মোটে ওয়েটের যোগফল এখন ' . rc_pct_fmt($gw_sum) . '% — ঠিক ১০০% হতে হবে।'; }
    return $out;
}
}

// হিসাবের জন্য ব্যবহারযোগ্য গ্রুপ-কনফিগ (কাঠামোতে ভুল থাকলে খালি — তখন রেজাল্ট বের হয় না)
if (!function_exists('rc_pct_load_config')) {
function rc_pct_load_config(PDO $pdo, int $session_id, string $model_key): array
{
    $s = rc_pct_structure($pdo, $session_id, $model_key);
    return $s['errors'] ? [] : $s['groups'];
}
}

if (!function_exists('rc_pct_config_term_ids')) {
function rc_pct_config_term_ids(array $groups): array
{
    $ids = [];
    foreach ($groups as $g) { foreach ($g['items'] as $i) { $ids[(int) $i['term_id']] = true; } }
    return array_keys($ids);
}
}

// কাঠামোর অবস্থা HTML (Exam Terms ও Master Settings-এ দেখানোর জন্য): ত্রুটি (লাল), নোট (হলুদ), আর ঠিক থাকলে গ্রুপ-সারাংশ (সবুজ)
if (!function_exists('rc_pct_structure_html')) {
function rc_pct_structure_html(array $st, string $model_key): string
{
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $type = rc_pct_models()[$model_key]['type'] ?? 'flat';
    $h = '';
    if ($st['errors']) {
        $h .= '<div class="alert alert-danger py-2 small mb-2"><strong>⚠ ওয়েট/কাঠামো এখনো ঠিক হয়নি:</strong><ul class="mb-0">';
        foreach ($st['errors'] as $x) { $h .= '<li>' . $e($x) . '</li>'; }
        $h .= '</ul></div>';
    } else {
        $h .= '<div class="alert alert-success py-2 small mb-2"><strong>✔ কাঠামো ঠিক আছে।</strong><ul class="mb-0">';
        foreach ($st['groups'] as $g) {
            $parts = [];
            foreach ($g['items'] as $i) {
                $nm = $st['names'][$i['term_id']] ?? ('#' . $i['term_id']);
                $parts[] = $type === 'avg_group' ? $e($nm) : $e($nm) . ' ' . rc_pct_fmt($i['weight']) . '%';
            }
            if ($type === 'flat') { $h .= '<li>' . implode(' + ', $parts) . ' = ১০০%</li>'; }
            elseif ($type === 'nested') { $h .= '<li><strong>' . $e($g['name']) . '</strong> (মোটে ' . rc_pct_fmt($g['weight']) . '%): ' . implode(', ', $parts) . '</li>'; }
            elseif ($type === 'standalone') { $h .= '<li><strong>' . $e($g['name']) . '</strong> (আলাদা রেজাল্ট): ' . implode(', ', $parts) . '</li>'; }
            else { $h .= '<li><strong>' . $e($g['name']) . '</strong> গ্রুপ: ' . implode(' + ', $parts) . ' এর গড়</li>'; }
        }
        $h .= '</ul></div>';
    }
    if ($st['warnings']) {
        $h .= '<div class="alert alert-warning py-2 small mb-2"><ul class="mb-0">';
        foreach ($st['warnings'] as $x) { $h .= '<li>' . $e($x) . '</li>'; }
        $h .= '</ul></div>';
    }
    return $h;
}
}

// Exam Terms তালিকার ক্রম: Main (rank অনুযায়ী) → তার Sub-গুলো (rank অনুযায়ী, ইনডেন্ট)। ফিল্টারে প্যারেন্ট বাদ পড়লে Sub শেষে আলাদা।
// প্রতিটা সারিতে 'depth' (0 = Main, 1 = Sub) যোগ হয়।
if (!function_exists('rc_term_tree_order')) {
function rc_term_tree_order(array $rows): array
{
    $ids = []; foreach ($rows as $r) { $ids[(int) $r['id']] = true; }
    $subs = []; $mains = []; $orphans = [];
    foreach ($rows as $r) {
        $pid = (int) ($r['parent_term_id'] ?? 0);
        if ($pid > 0 && isset($ids[$pid])) { $subs[$pid][] = $r; }
        elseif ($pid > 0) { $orphans[] = $r; }
        else { $mains[] = $r; }
    }
    $out = [];
    foreach ($mains as $r) {
        $r['depth'] = 0; $out[] = $r;
        foreach ($subs[(int) $r['id']] ?? [] as $s) { $s['depth'] = 1; $out[] = $s; }
    }
    foreach ($orphans as $r) { $r['depth'] = 1; $out[] = $r; }
    return $out;
}
}

// তালিকার "Type / Weight" ঘরের HTML
if (!function_exists('rc_term_weight_cell_html')) {
function rc_term_weight_cell_html(array $row, array $names, string $model_type): string
{
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $w = ($row['weight_pct'] ?? null) === null || $row['weight_pct'] === '' ? null : rc_pct_fmt($row['weight_pct']);
    $pid = (int) ($row['parent_term_id'] ?? 0);
    if ($pid > 0) {
        $h = '<span class="badge bg-info text-dark">Sub</span> <span class="small text-muted">' . $e($names[$pid] ?? '?') . '-এর</span>';
        if (in_array($model_type, ['nested', 'standalone'], true)) { $h .= ' ' . ($w !== null ? '<strong>ভেতরে ' . $e($w) . '%</strong>' : '<span class="text-danger small">ওয়েট নেই</span>'); }
        return $h;
    }
    $h = '<span class="badge bg-primary">Main</span>';
    if (in_array($model_type, ['flat', 'nested'], true)) { $h .= ' ' . ($w !== null ? '<strong>মোটে ' . $e($w) . '%</strong>' : '<span class="text-muted small">ওয়েট নেই</span>'); }
    return $h;
}
}

// শুদ্ধ হিসাব (ডাটাবেস ছাড়া)। $pct_by_term = [term_id => %]। ফেরত: ['total' => ফাইনাল %, 'group_pct' => [group_id => মান]]
// flat-এ group_pct = ঐ গ্রুপ থেকে মোটে যত পয়েন্ট যোগ হলো; nested/standalone-এ গ্রুপের নিজের %।
// standalone-এ $pick_group_id যে গ্রুপের (না পেলে শেষ গ্রুপ) রেজাল্টই 'total'।
if (!function_exists('rc_pct_compute')) {
function rc_pct_compute(string $model_key, array $groups, array $pct_by_term, int $pick_group_id = 0): array
{
    $type = rc_pct_models()[$model_key]['type'] ?? 'flat';
    $group_pct = []; $total = 0.0;
    $standalone_val = null; // standalone-এ বেছে নেওয়া গ্রুপের মান (না পেলে শেষ গ্রুপের)
    foreach ($groups as $gi => $g) {
        $gid = $g['id'] ?? $gi;
        $sum = 0.0;
        foreach ($g['items'] as $it) { $sum += (float) $it['weight'] * (float) ($pct_by_term[$it['term_id']] ?? 0); }
        $val = $sum / 100.0;
        $group_pct[$gid] = $val;
        if ($type === 'flat') { $total += $val; }
        elseif ($type === 'nested') { $total += ((float) $g['weight']) * $val / 100.0; }
        else {
            if ($pick_group_id > 0 && (int) $gid === $pick_group_id) { $standalone_val = $val; $pick_group_id = -1; }
            elseif ($pick_group_id !== -1) { $standalone_val = $val; } // এখনো মিলেনি — আপাতত শেষ দেখা গ্রুপ
        }
    }
    if ($type === 'standalone') { $total = $standalone_val ?? 0.0; }
    return ['total' => $total, 'group_pct' => $group_pct];
}
}

// গড় মডেলের শুদ্ধ হিসাব: গ্রুপ = তার পরীক্ষাগুলোর নম্বরের সাধারণ গড় (মার্ক নেই/অনুপস্থিত পরীক্ষা = ০ নম্বর);
// ফাইনাল = গ্রুপগুলোর গড়। পূর্ণমানের গড়ও একইভাবে (যে পরীক্ষার পূর্ণমান জানা — অর্থাৎ Subject Assign আছে — শুধু সেগুলোর গড়)।
// $obt_by_term / $full_by_term = [term_id => মান]; ফেরত: obtained, full, group_obtained, group_full
if (!function_exists('rc_avg_group_compute')) {
function rc_avg_group_compute(array $groups, array $obt_by_term, array $full_by_term): array
{
    $g_obt = []; $g_full = []; $sum_o = 0.0; $sum_f = 0.0; $n = 0;
    foreach ($groups as $gi => $g) {
        $gid = $g['id'] ?? $gi;
        $cnt = count($g['items']);
        if ($cnt === 0) { continue; }
        $so = 0.0; $sf = 0.0; $nf = 0;
        foreach ($g['items'] as $it) {
            $tid = $it['term_id'];
            $so += (float) ($obt_by_term[$tid] ?? 0);
            if (isset($full_by_term[$tid])) { $sf += (float) $full_by_term[$tid]; $nf++; }
        }
        $g_obt[$gid] = $so / $cnt;
        $g_full[$gid] = $nf > 0 ? $sf / $nf : 0.0;
        $sum_o += $g_obt[$gid]; $sum_f += $g_full[$gid]; $n++;
    }
    return [
        'obtained' => $n ? $sum_o / $n : 0.0, 'full' => $n ? $sum_f / $n : 0.0,
        'group_obtained' => $g_obt, 'group_full' => $g_full,
    ];
}
}

if (!function_exists('rc_aggregate_pct_model')) {
function rc_aggregate_pct_model(PDO $pdo, int $student_id, array $term_ids, int $class_id, string $model_key, array $grade_bands, int $pick_group_id = 0): array
{
    $empty = [
        'per_term' => [], 'subject_breakdown' => [], 'final_obtained' => 0, 'final_full' => 0, 'final_percentage' => 0,
        'final_gl' => 'F', 'final_gpa' => 0.0, 'final_fail' => true, 'group_pct' => [], 'config_missing' => false,
    ];
    $term_ids = array_values(array_filter(array_map('intval', $term_ids)));
    if (!$term_ids) { return $empty; }

    $s = $pdo->prepare("SELECT session_id FROM exam_terms WHERE id = ?");
    $s->execute([$term_ids[0]]);
    $session_id = (int) $s->fetchColumn();
    $groups = $session_id ? rc_pct_load_config($pdo, $session_id, $model_key) : [];
    if (!$groups) { $empty['config_missing'] = true; return $empty; }

    // মডেল ৩-এ শুধু বেছে নেওয়া গ্রুপের (না পেলে শেষ গ্রুপের) পরীক্ষাগুলো লাগে
    $type = rc_pct_models()[$model_key]['type'];
    $active = $groups;
    if ($type === 'standalone') {
        $picked = null;
        foreach ($groups as $g) { $picked = $g; if ($g['id'] === $pick_group_id) { break; } }
        $active = [$picked];
        $pick_group_id = (int) $picked['id'];
    }
    $need = rc_pct_config_term_ids($active);

    $per_term = [];
    $subj = []; // subject_id => ['name','optional','pct'=>[term_id=>%],'full_sum','n']
    foreach ($need as $term_id) {
        $defs = rc_get_subject_defs($pdo, $class_id, $term_id, 0);
        if (!$defs) { continue; } // এই পরীক্ষায় Subject Assign নেই → ০% ধরা হবে
        $subject_ids = array_keys($defs);
        $in_subj = implode(',', array_fill(0, count($subject_ids), '?'));

        $mk = $pdo->prepare("SELECT subject_id, part_id, marks_obtained FROM exam_marks WHERE term_id = ? AND student_id = ? AND subject_id IN ($in_subj)");
        $mk->execute(array_merge([$term_id, $student_id], $subject_ids));
        $marks_map = [];
        foreach ($mk->fetchAll() as $r) { $marks_map[$r['subject_id']][$r['part_id']] = $r['marks_obtained']; }

        $at = $pdo->prepare("SELECT subject_id, part_id, status FROM exam_attendance_subject WHERE term_id = ? AND student_id = ? AND subject_id IN ($in_subj)");
        $at->execute(array_merge([$term_id, $student_id], $subject_ids));
        $att_map = [];
        foreach ($at->fetchAll() as $r) { $att_map[$r['subject_id']][$r['part_id']] = $r['status']; }

        $res = rc_student_result($defs, $marks_map, $att_map, $grade_bands, rc_excluded_subjects($pdo, $defs, (int) $term_id, (int) $student_id));
        $per_term[$term_id] = [
            'obtained_total' => $res['obtained_total'], 'full_total' => $res['full_total'],
            'percentage' => $res['percentage'], 'fail' => $res['overall_fail'],
            'opt_gpa_add' => $res['optional_gpa_add'] ?? 0.0,
        ];

        $sr_map = array_column($res['subject_rows'], null, 'subject_id');
        foreach ($defs as $sid => $def) {
            $sr = $sr_map[$sid] ?? null;
            if ($sr === null) { continue; } // Group-এর নয় / Not Eligible
            if (!empty($sr['not_taken'])) { continue; }
            if (!isset($subj[$sid])) { $subj[$sid] = ['name' => $def['name'], 'optional' => !empty($sr['optional']), 'pct' => [], 'obt' => [], 'full' => [], 'full_sum' => 0.0, 'n' => 0]; }
            $subj[$sid]['pct'][$term_id] = $sr['full'] > 0 ? ($sr['obtained'] / $sr['full']) * 100 : 0.0;
            $subj[$sid]['obt'][$term_id] = (float) $sr['obtained'];
            $subj[$sid]['full'][$term_id] = (float) $sr['full'];
            $subj[$sid]['full_sum'] += $sr['full'];
            $subj[$sid]['n']++;
        }
    }

    if (!$per_term) { $empty['group_pct'] = []; return $empty; }

    $pct_by_term = []; $opt_by_term = [];
    foreach ($need as $tid) {
        $pct_by_term[$tid] = isset($per_term[$tid]) ? $per_term[$tid]['percentage'] : 0.0; // অনুপস্থিত/ডেটা নেই = ০
        $opt_by_term[$tid] = isset($per_term[$tid]) ? (float) $per_term[$tid]['opt_gpa_add'] : 0.0;
    }
    $subject_breakdown = [];
    if ($type === 'avg_group') {
        // গড় মডেল: নম্বরের সাধারণ গড় (গ্রুপের গড় → গ্রুপগুলোর গড়); % = গড় প্রাপ্ত ÷ গড় পূর্ণমান
        $obt_by_term = []; $full_by_term = [];
        foreach ($per_term as $tid => $pt) { $obt_by_term[$tid] = (float) $pt['obtained_total']; $full_by_term[$tid] = (float) $pt['full_total']; }
        $calc_a = rc_avg_group_compute($active, $obt_by_term, $full_by_term);
        $final_obtained = $calc_a['obtained']; $final_full = $calc_a['full'];
        $final_percentage = $final_full > 0 ? ($final_obtained / $final_full) * 100 : 0.0;
        $calc = ['group_pct' => $calc_a['group_obtained']];
        foreach ($subj as $sid => $sb) {
            $sc = rc_avg_group_compute($active, $sb['obt'], $sb['full']);
            $subject_breakdown[$sid] = ['name' => $sb['name'], 'optional' => $sb['optional'], 'avg_obtained' => $sc['obtained'], 'avg_full' => $sc['full']];
        }
    } else {
        $calc = rc_pct_compute($model_key, $active, $pct_by_term, $pick_group_id);
        $final_percentage = $calc['total'];
        foreach ($subj as $sid => $sb) {
            $sp = [];
            foreach ($need as $tid) { $sp[$tid] = $sb['pct'][$tid] ?? 0.0; }
            $sc = rc_pct_compute($model_key, $active, $sp, $pick_group_id)['total'];
            $avg_full = $sb['n'] > 0 ? $sb['full_sum'] / $sb['n'] : 0.0;
            $subject_breakdown[$sid] = [
                'name' => $sb['name'], 'optional' => $sb['optional'],
                'avg_obtained' => ($sc / 100) * $avg_full, 'avg_full' => $avg_full,
            ];
        }
        $sum_full = 0.0;
        foreach ($per_term as $pt) { $sum_full += $pt['full_total']; }
        $final_full = $sum_full / count($per_term);
        $final_obtained = ($final_percentage / 100) * $final_full;
    }

    // ফেল = ফাইনাল %-এর Grade Point সেটিংসে পাস গ্রেড না পড়লে (মাসিক/ক্লাস টেস্টে আলাদা ফেল ধরা হয় না)
    $final_gl = 'F'; $final_gpa = 0.00; $final_fail = false;
    foreach ($grade_bands as $band) {
        if ($final_percentage >= (float) $band['mark_from'] && $final_percentage <= (float) $band['mark_to']) {
            $final_gl = $band['grade_letter']; $final_gpa = (float) $band['gpa']; break;
        }
    }
    if ($final_gl === 'F') { $final_fail = true; }
    if (!$final_fail) {
        $opt_add = $type === 'avg_group'
            ? rc_avg_group_compute($active, $opt_by_term, [])['obtained']
            : rc_pct_compute($model_key, $active, $opt_by_term, $pick_group_id)['total'];
        [$final_gpa, $final_gl] = array_slice(rc_apply_optional_bonus($final_gpa, $opt_add, $grade_bands, $final_gl, ''), 0, 2);
    }

    return [
        'per_term' => $per_term,
        'subject_breakdown' => $subject_breakdown,
        'final_obtained' => $final_obtained,
        'final_full' => $final_full,
        'final_percentage' => $final_percentage,
        'final_gl' => $final_gl,
        'final_gpa' => $final_gpa,
        'final_fail' => $final_fail,
        'group_pct' => $calc['group_pct'],
        'config_missing' => false,
    ];
}
}

// =========================================================================
// Result Lock/Unlock System — একটা Class+Term-এর রেজাল্ট একবার Publish হয়ে
// গেলে Admin "Lock" করে দিতে পারেন, যাতে Teacher/Admin কেউই আর ভুলবশত মার্ক
// এডিট করে দিতে না পারে। Lock/Unlock করলেই result_lock_audit-এ কে, কখন,
// কী করলো তার ইতিহাস থেকে যায়।
// =========================================================================

if (!function_exists('rc_lock_ensure_tables')) {
function rc_lock_ensure_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS result_locks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            class_id INT NOT NULL,
            term_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Unlocked',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_lock (session_id, class_id, term_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS result_lock_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            class_id INT NOT NULL,
            term_id INT NOT NULL,
            action VARCHAR(20) NOT NULL,
            acted_by VARCHAR(100) NULL,
            acted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_lookup (session_id, class_id, term_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // অটো-লক তারিখ: এই তারিখ পার হয়ে গেলে (পরের দিন থেকে) Class+Term অটো Lock হয়ে যায়। পুরনো ইনস্টলেশনে কলাম না থাকলে যোগ হবে
    static $col_checked = false;
    if (!$col_checked) {
        $col_checked = true;
        if (!$pdo->query("SHOW COLUMNS FROM result_locks LIKE 'auto_lock_date'")->fetch()) {
            $pdo->exec("ALTER TABLE result_locks ADD COLUMN auto_lock_date DATE NULL AFTER status");
        }
    }
}
}

// অটো-লক: auto_lock_date পার হয়ে গেছে (আজ > তারিখ) অথচ এখনো Locked না — এমন সব Class+Term (এই session-এর, বা $session_id = 0 হলে সব)
// এখন Lock করে দেয় এবং Audit-এ "Auto Locked" (by system) লেখে। কেউ ম্যানুয়ালি Unlock করলে তারিখটা মুছে যায় (rc_set_result_lock),
// তাই Unlock করার পর আর নিজে নিজে আবার Lock হয় না — নতুন তারিখ দিলে তবেই।
if (!function_exists('rc_apply_auto_locks')) {
function rc_apply_auto_locks(PDO $pdo, int $session_id = 0, int $class_id = 0, int $term_id = 0): void
{
    rc_lock_ensure_tables($pdo);
    $sql = "SELECT id, session_id, class_id, term_id FROM result_locks
            WHERE status <> 'Locked' AND auto_lock_date IS NOT NULL AND auto_lock_date < ?";
    // আজকের তারিখ PHP থেকে (config/db.php-তে Asia/Dhaka সেট করা) — MySQL-এর CURDATE() সার্ভারের টাইমজোনের উপর নির্ভর করে, মিলতে নাও পারে
    $params = [date('Y-m-d')];
    if ($session_id > 0) { $sql .= " AND session_id = ?"; $params[] = $session_id; }
    if ($class_id > 0)   { $sql .= " AND class_id = ?";   $params[] = $class_id; }
    if ($term_id > 0)    { $sql .= " AND term_id = ?";    $params[] = $term_id; }
    $q = $pdo->prepare($sql);
    $q->execute($params);
    $due = $q->fetchAll();
    if (!$due) { return; }
    $up  = $pdo->prepare("UPDATE result_locks SET status = 'Locked' WHERE id = ? AND status <> 'Locked'");
    $log = $pdo->prepare("INSERT INTO result_lock_audit (session_id, class_id, term_id, action, acted_by) VALUES (?, ?, ?, 'Auto Locked', 'system')");
    foreach ($due as $r) {
        $up->execute([$r['id']]);
        if ($up->rowCount() > 0) { $log->execute([$r['session_id'], $r['class_id'], $r['term_id']]); }
    }
}
}

// একটা নির্দিষ্ট Class+Term (একই Session-এর) বর্তমানে Locked কিনা — মার্ক এন্ট্রি সেভ
// করার আগে এটা চেক করে ব্লক করা হয় (Locked থাকলে Teacher/Admin কেউই সেভ করতে পারবে না)
if (!function_exists('rc_is_result_locked')) {
function rc_is_result_locked(PDO $pdo, int $session_id, int $class_id, int $term_id): bool
{
    rc_lock_ensure_tables($pdo);
    rc_apply_auto_locks($pdo, $session_id, $class_id, $term_id); // তারিখ পার হয়ে থাকলে আগে অটো-লক প্রয়োগ
    $stmt = $pdo->prepare("SELECT status FROM result_locks WHERE session_id = ? AND class_id = ? AND term_id = ?");
    $stmt->execute([$session_id, $class_id, $term_id]);
    $status = $stmt->fetchColumn();
    return $status === 'Locked';
}
}

// অটো-লক তারিখ সেট/মুছা (এক বা একাধিক Class-এর একটা Term-এর জন্য)। $date = null/'' হলে তারিখ মুছে যায়।
// এই তারিখের পরের দিন থেকে (আজ > তারিখ) ঐ Class+Term অটো Lock হয়; Locked/Unlocked স্ট্যাটাস এখানে বদলায় না।
if (!function_exists('rc_set_result_auto_lock_date')) {
function rc_set_result_auto_lock_date(PDO $pdo, int $session_id, array $class_ids, int $term_id, ?string $date): void
{
    rc_lock_ensure_tables($pdo);
    $date = ($date !== null && $date !== '') ? $date : null;
    $stmt = $pdo->prepare(
        "INSERT INTO result_locks (session_id, class_id, term_id, status, auto_lock_date) VALUES (?, ?, ?, 'Unlocked', ?)
         ON DUPLICATE KEY UPDATE auto_lock_date = VALUES(auto_lock_date)"
    );
    foreach ($class_ids as $cid) {
        $cid = (int) $cid;
        if ($cid > 0) { $stmt->execute([$session_id, $cid, $term_id, $date]); }
    }
}
}

// Lock/Unlock করা এবং সাথে সাথে Audit লগ লেখা (upsert — প্রথমবার Lock করলে রো তৈরি হয়,
// পরেরবার Toggle করলে আপডেট হয়)
if (!function_exists('rc_set_result_lock')) {
function rc_set_result_lock(PDO $pdo, int $session_id, int $class_id, int $term_id, bool $lock, string $actor): void
{
    rc_lock_ensure_tables($pdo);
    $status = $lock ? 'Locked' : 'Unlocked';
    // Unlock করলে অটো-লক তারিখও মুছে যায় — নাহলে তারিখ পার হওয়া Class আনলক করার সাথে সাথে আবার অটো Lock হয়ে যেত।
    // (Unlock সবসময় ম্যানুয়ালি করা যায়; চাইলে নতুন তারিখ দেওয়া যায়।) Lock করলে তারিখ যেমন ছিল তেমনই থাকে।
    $stmt = $pdo->prepare(
        "INSERT INTO result_locks (session_id, class_id, term_id, status, auto_lock_date) VALUES (?, ?, ?, ?, NULL)
         ON DUPLICATE KEY UPDATE status = VALUES(status)" . ($lock ? '' : ", auto_lock_date = NULL")
    );
    $stmt->execute([$session_id, $class_id, $term_id, $status]);

    $log = $pdo->prepare(
        "INSERT INTO result_lock_audit (session_id, class_id, term_id, action, acted_by) VALUES (?, ?, ?, ?, ?)"
    );
    $log->execute([$session_id, $class_id, $term_id, $lock ? 'Locked' : 'Unlocked', $actor]);
}
}
