<?php
// admin/student_import.php - Student Module > Import Students (Excel/CSV/TXT)
//
// ফ্লো: (১) ফাইল আপলোড -> পার্স -> প্রতিটা রো ভ্যালিডেট করে প্রিভিউ টেবিল দেখানো
//       (২) "Confirm Import" চাপলে শুধু ভ্যালিড রো-গুলো students টেবিলে insert হয়।
// CSV/TXT/XLSX পার্সিং লজিক config/import_helpers.php তে শেয়ার্ড — Employee Import
// ইত্যাদি অন্যান্য বাল্ক-ইমপোর্ট ফিচারও একই ফাইল ব্যবহার করে।

require_once __DIR__ . '/../config/import_helpers.php';
require_once __DIR__ . '/../config/admission_fee_helpers.php';

// Class Nine/Ten ইত্যাদির Group (Science/Arts/Commerce) সেভ করার জন্য students.group_id কলাম
// থাকা আবশ্যক — student_admission.php না খুলেই সরাসরি Import পেজে এলেও যেন কলামটা থাকে
$imp_grp_col = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'group_id'"
)->fetchColumn();
if (!$imp_grp_col) {
    $pdo->exec("ALTER TABLE students ADD COLUMN group_id INT NULL AFTER section_id");
}

$imp_msg = $_SESSION['imp_msg'] ?? '';
$imp_err = $_SESSION['imp_err'] ?? '';
unset($_SESSION['imp_msg'], $_SESSION['imp_err']);

function iv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$import_field_aliases = [
    'student_id'        => ['student id', 'studentid', 'id', 'শিক্ষার্থী আইডি'],
    'name_en'           => ['name', 'student name', 'name (english)', 'nameen', 'নাম', 'শিক্ষার্থীর নাম'],
    'name_bn'           => ['name (bangla)', 'name bn', 'namebn', 'বাংলা নাম'],
    'group'             => ['group', 'গ্রুপ'],
    'roll_no'           => ['roll', 'roll no', 'rollno', 'রোল', 'রোল নং'],
    'gender'            => ['gender', 'sex', 'লিঙ্গ'],
    'dob'               => ['dob', 'date of birth', 'জন্ম তারিখ'],
    'blood_group'       => ['blood group', 'bloodgroup', 'রক্তের গ্রুপ'],
    'religion'          => ['religion', 'ধর্ম'],
    'mobile'            => ['mobile', 'phone', 'student mobile', 'মোবাইল'],
    'present_address'   => ['present address', 'address', 'বর্তমান ঠিকানা', 'ঠিকানা'],
    'permanent_address' => ['permanent address', 'স্থায়ী ঠিকানা'],
    'father_name'       => ["father name", "father's name", 'fathername', 'পিতার নাম'],
    'father_phone'      => ['father phone', 'father mobile', 'পিতার মোবাইল'],
    'mother_name'       => ["mother name", "mother's name", 'mothername', 'মাতার নাম'],
    'mother_phone'      => ['mother phone', 'mother mobile', 'মাতার মোবাইল'],
    'guardian_name'     => ['guardian name', 'অভিভাবকের নাম'],
    'guardian_phone'    => ['guardian phone', 'guardian mobile', 'অভিভাবকের মোবাইল'],
];

// -----------------------------------------------------------
// Session/Class/Shift/Section — ফাইল আপলোডের আগে একবার সিলেক্ট করা হয়, পুরো ফাইলের সব
// Student এই একই Session/Class/Shift/Section এ import হবে (এখন আর প্রতি সারিতে আলাদা
// Class/Section টেক্সট কলাম মেলাতে হবে না — এতে ভুল ম্যাচিং/Shift-mismatch এর ঝুঁকি থাকে না)
// -----------------------------------------------------------
$imp_sessions = $pdo->query("SELECT id, session_name FROM academic_sessions WHERE status = 'Active' ORDER BY id DESC")->fetchAll();
$imp_classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$imp_shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$imp_sections = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
$imp_groups_all = $pdo->query("SELECT class_id FROM academic_groups WHERE status = 'Active' GROUP BY class_id")->fetchAll(PDO::FETCH_COLUMN);
$imp_current_session = $pdo->query("SELECT id FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetchColumn() ?: 0;

// -----------------------------------------------------------
// ৩) Student ID / Roll No অটো-জেনারেট (student_id_next_ajax.php এর একই লজিক)
// -----------------------------------------------------------
function import_next_student_id(PDO $pdo, int $session_id, int $class_id, int $section_id): ?string
{
    // Student ID ফরম্যাট: session short_code + class_code + 0001 (উদাহরণ: 26010001)
    // $section_id প্যারামিটারটা ফাংশন সিগনেচার-কম্প্যাটিবিলিটির জন্য রাখা হয়েছে, ID তৈরিতে ব্যবহার হয় না
    $session_code = $pdo->prepare("SELECT short_code FROM academic_sessions WHERE id = ?");
    $session_code->execute([$session_id]);
    $session_code = $session_code->fetchColumn();

    $class_code = $pdo->prepare("SELECT class_code FROM academic_classes WHERE id = ?");
    $class_code->execute([$class_id]);
    $class_code = $class_code->fetchColumn();

    if (!$session_code || !preg_match('/^\d{2}$/', $session_code)) return null;
    if (!$class_code || !preg_match('/^\d{2}$/', $class_code)) return null;

    $prefix = $session_code . $class_code;
    $stmt = $pdo->prepare("SELECT student_id FROM students WHERE student_id LIKE ?");
    $stmt->execute([$prefix . '%']);
    $max_serial = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $suffix = substr($sid, strlen($prefix));
        if (preg_match('/^\d+$/', $suffix)) $max_serial = max($max_serial, (int) $suffix);
    }
    return $prefix . str_pad((string) ($max_serial + 1), 4, '0', STR_PAD_LEFT);
}

function import_next_roll(PDO $pdo, int $session_id, int $class_id, int $section_id): string
{
    $stmt = $pdo->prepare("SELECT roll_no FROM students WHERE session_id = ? AND class_id = ? AND section_id = ?");
    $stmt->execute([$session_id, $class_id, $section_id]);
    $max_roll = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $r) {
        if (preg_match('/^\d+$/', $r)) $max_roll = max($max_roll, (int) $r);
    }
    return (string) ($max_roll + 1);
}

// -----------------------------------------------------------
// ৪) টেম্প ফাইল হেল্পার — আপলোড হওয়া ফাইলটা preview -> confirm এই দুই ধাপের মাঝে
// সার্ভারে সাময়িকভাবে রাখা হয় (uploads/tmp এর ভেতর, token দিয়ে চেনা)
// -----------------------------------------------------------
$import_tmp_dir = tenant_upload_abs(__DIR__ . '/..', 'tmp');
if (!is_dir($import_tmp_dir)) { mkdir($import_tmp_dir, 0755, true); }

import_cleanup_old_temp($import_tmp_dir);

// -----------------------------------------------------------
// ৫) স্যাম্পল CSV টেমপ্লেট ডাউনলোড
// -----------------------------------------------------------
if (isset($_GET['sample'])) {
    while (ob_get_level() > 0) { ob_end_clean(); } // sidebar/header ইতিমধ্যে যা buffer-এ জমেছে তা ফেলে দাও
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="student_import_sample.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM যাতে Excel এ বাংলা ঠিকমতো দেখায়
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID', 'Name', 'Group', 'Roll No', 'Gender', 'DOB', 'Blood Group', 'Religion', 'Mobile', 'Present Address', 'Permanent Address', "Father Name", 'Father Phone', "Mother Name", 'Mother Phone', 'Guardian Name', 'Guardian Phone']);
    fputcsv($out, ['', 'করিম উদ্দিন', '', '', 'Male', '2014-05-10', 'B+', 'Islam', '01700000000', 'গ্রাম: X, রংপুর', 'গ্রাম: X, রংপুর', 'আব্দুল করিম', '01700000001', 'রহিমা বেগম', '01700000002', '', '01700000001']);
    fputcsv($out, ['', 'রহিম উদ্দিন', 'Science', '', 'Male', '2012-03-15', 'A+', 'Islam', '01700000003', 'গ্রাম: Y, রংপুর', 'গ্রাম: Y, রংপুর', 'সালাম মিয়া', '01700000004', 'জোসনা বেগম', '01700000005', '', '01700000004']);
    fclose($out);
    exit();
}

// -----------------------------------------------------------
// ৬) STEP 1: ফাইল আপলোড -> পার্স -> ভ্যালিডেট -> প্রিভিউ
// -----------------------------------------------------------
$preview_rows = null;
$preview_token = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_file'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['imp_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করুন।';
        header('Location: index.php?page=student_import');
        exit();
    }

    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['imp_err'] = 'ফাইল আপলোড করা যায়নি, আবার চেষ্টা করুন।';
        header('Location: index.php?page=student_import');
        exit();
    }

    // Session/Class/Section — ফাইলের সব Student এই একই জায়গায় import হবে
    $imp_session_id = (int) ($_POST['session_id'] ?? 0);
    $imp_class_id   = (int) ($_POST['class_id'] ?? 0);
    $imp_shift_id   = (int) ($_POST['shift_id'] ?? 0);
    $imp_section_id = (int) ($_POST['section_id'] ?? 0);

    if (!$imp_session_id || !$imp_class_id || !$imp_section_id) {
        $_SESSION['imp_err'] = 'Session, Class এবং Section সিলেক্ট করা আবশ্যক।';
        header('Location: index.php?page=student_import');
        exit();
    }

    // এই Session/Class(/Shift/Section)-এর জন্য কোনো Admission Fees Setting (যেকোনো Gender-এর)
    // একদমই না থাকলে পুরো ফাইলটাই এখানে ব্লক হয়ে যাবে। Male/Female আলাদা Fee হলে সেটার
    // সূক্ষ্ম মিল প্রতিটা সারির জন্য নিচে আলাদা করে যাচাই হবে (per-row check)।
    $gate_stmt = $pdo->prepare(
        "SELECT s.id FROM finance_admission_fee_settings s
         INNER JOIN finance_admission_fee_items i ON i.setting_id = s.id
         WHERE s.status = 'Active' AND s.session_id = ? AND s.class_id = ?
           AND (s.shift_id = 0 OR s.shift_id = ?)
           AND (s.section_id = 0 OR s.section_id = ?)
           AND s.applicability IN ('New', 'All', 'ExceptDiscount')
         LIMIT 1"
    );
    $gate_stmt->execute([$imp_session_id, $imp_class_id, $imp_shift_id, $imp_section_id]);
    if (!$gate_stmt->fetchColumn()) {
        $_SESSION['imp_err'] = 'এই Session/Class/Shift/Section-এর জন্য এখনো কোনো Admission Fees Setting করা হয়নি। প্রথমে Finance Module &gt; Student Accounts &gt; Admission Fees থেকে এই Class-এর জন্য Fee Setting তৈরি করুন, তারপর Import করুন।';
        header('Location: index.php?page=student_import');
        exit();
    }

    $file = $_FILES['import_file'];
    if ($file['size'] > 5 * 1024 * 1024) {
        $_SESSION['imp_err'] = 'ফাইলের সাইজ সর্বোচ্চ 5MB হতে হবে।';
        header('Location: index.php?page=student_import');
        exit();
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
        $_SESSION['imp_err'] = 'শুধু .csv, .txt অথবা .xlsx ফাইল আপলোড করা যাবে। (পুরনো .xls ফরম্যাট হলে Excel থেকে .xlsx বা .csv হিসেবে "Save As" করে আবার চেষ্টা করুন।)';
        header('Location: index.php?page=student_import');
        exit();
    }

    try {
        $rawRows = import_parse_file($file['tmp_name'], $ext);
    } catch (Exception $e) {
        $_SESSION['imp_err'] = $e->getMessage();
        header('Location: index.php?page=student_import');
        exit();
    }

    if (count($rawRows) < 2) {
        $_SESSION['imp_err'] = 'ফাইলে হেডার সারি বাদে অন্তত ১টা ডাটা সারি থাকতে হবে।';
        header('Location: index.php?page=student_import');
        exit();
    }

    $headerRow = array_shift($rawRows);
    $colMap = import_map_headers($headerRow, $import_field_aliases);

    if (!in_array('name_en', $colMap, true)) {
        $_SESSION['imp_err'] = 'ফাইলে অন্তত Name কলাম থাকা আবশ্যক (কলামের নাম মিলছে না — স্যাম্পল টেমপ্লেট দেখে ঠিক করুন)।';
        header('Location: index.php?page=student_import');
        exit();
    }

    // লুকআপ টেবিলগুলো আগে থেকে মেমরিতে এনে রাখা (প্রতি রো তে বারবার query না করে)
    $groups_lk = []; // key: groupname(lower) — যেহেতু Class ইতিমধ্যে ফিক্সড, শুধু নাম দিয়েই যথেষ্ট
    $class_has_group = false;
    foreach ($pdo->query("SELECT id, group_name FROM academic_groups WHERE status='Active' AND class_id = " . (int) $imp_class_id) as $r) {
        $groups_lk[mb_strtolower(trim($r['group_name']))] = $r['id'];
        $class_has_group = true;
    }

    $existing_ids_stmt = $pdo->query("SELECT student_id FROM students");
    $existing_ids = array_flip($existing_ids_stmt->fetchAll(PDO::FETCH_COLUMN));

    $seen_ids_in_file = [];
    $validated = [];

    // এই Session/Class/Section-এ আগে থেকে থাকা Roll গুলো — ফাইলে দেওয়া Roll ডুপ্লিকেট কিনা যাচাইয়ের জন্য
    $existing_rolls = [];
    $er_stmt = $pdo->prepare("SELECT roll_no FROM students WHERE session_id = ? AND class_id = ? AND section_id = ?");
    $er_stmt->execute([$imp_session_id, $imp_class_id, $imp_section_id]);
    foreach ($er_stmt->fetchAll(PDO::FETCH_COLUMN) as $er) { $existing_rolls[trim((string) $er)] = true; }
    $seen_rolls_in_file = [];

    foreach ($rawRows as $rowIdx => $raw) {
        if (!array_filter($raw, fn($v) => trim((string) $v) !== '')) continue; // পুরো সারি খালি হলে বাদ

        $row = [];
        foreach ($colMap as $idx => $field) { $row[$field] = trim((string) ($raw[$idx] ?? '')); }

        $errors = [];

        $name_en = $row['name_en'] ?? '';
        if ($name_en === '') $errors[] = 'নাম নেই';

        // Group (Science/Arts/Commerce) — শুধু সেই ক্লাসের জন্য যাচাই করা হয় যেখানে
        // Academic Module > Group List এ আগে থেকে Group তৈরি করা আছে। Class ইতিমধ্যে
        // উপরে ফিক্সড করা, তাই এখানে শুধু নাম মিলিয়ে group_id বের করা হচ্ছে।
        $group_id = null;
        if ($class_has_group) {
            $group_name_in = $row['group'] ?? '';
            if ($group_name_in === '') {
                $errors[] = "এই ক্লাসের জন্য Group (Science/Arts/Commerce) আবশ্যক কিন্তু ফাইলে দেওয়া নেই";
            } else {
                $group_id = $groups_lk[mb_strtolower($group_name_in)] ?? null;
                if (!$group_id) $errors[] = "গ্রুপ '" . $group_name_in . "' এই ক্লাসে খুঁজে পাওয়া যায়নি";
            }
        }

        $student_id = $row['student_id'] ?? '';
        if ($student_id !== '') {
            if (isset($existing_ids[$student_id]) || isset($seen_ids_in_file[$student_id])) {
                $errors[] = "Student ID '{$student_id}' আগে থেকেই আছে (ডুপ্লিকেট)";
            }
        }

        $roll_in = $row['roll_no'] ?? '';
        if ($roll_in !== '') {
            if (isset($existing_rolls[$roll_in]) || isset($seen_rolls_in_file[$roll_in])) {
                $errors[] = "Roll '{$roll_in}' এই Class/Section-এ আগে থেকেই আছে (ডুপ্লিকেট) — Roll কলাম খালি রাখলে অটো বসবে";
            }
            $seen_rolls_in_file[$roll_in] = true;
        }

        $gender = $row['gender'] ?? '';
        $genderNorm = match (mb_strtolower($gender)) {
            'male', 'ছেলে', 'boy', 'm' => 'Male',
            'female', 'মেয়ে', 'girl', 'f' => 'Female',
            default => $gender !== '' ? $gender : 'Male',
        };

        // Gender-নির্দিষ্ট Fee Setting (শুধু Male বা শুধু Female এর জন্য আলাদা Amount) থাকলে,
        // এই সারির Gender অনুযায়ী মিল আছে কিনা তা এখানে আলাদা করে যাচাই করা হয়
        if (!admission_fee_setting_exists($pdo, $imp_session_id, $imp_class_id, $imp_shift_id, $imp_section_id, $genderNorm, 'New')) {
            $errors[] = "এই Gender ({$genderNorm}) এর জন্য আলাদা Admission Fees Setting করা নেই";
        }

        $resolved = [
            'student_id' => $student_id, 'name_en' => $name_en, 'name_bn' => $row['name_bn'] ?? '',
            'class_id' => $imp_class_id, 'section_id' => $imp_section_id, 'group_id' => $group_id, 'session_id' => $imp_session_id, 'shift_id' => $imp_shift_id ?: null,
            'roll_no' => $row['roll_no'] ?? '', 'gender' => $genderNorm, 'dob' => $row['dob'] ?? '',
            'blood_group' => $row['blood_group'] ?? '', 'religion' => $row['religion'] ?? '',
            'mobile' => $row['mobile'] ?? '', 'present_address' => $row['present_address'] ?? '',
            'permanent_address' => $row['permanent_address'] ?? ($row['present_address'] ?? ''),
            'father_name' => $row['father_name'] ?? '', 'father_phone' => $row['father_phone'] ?? '',
            'mother_name' => $row['mother_name'] ?? '', 'mother_phone' => $row['mother_phone'] ?? '',
            'guardian_name' => $row['guardian_name'] ?? ($row['father_name'] ?? ''),
            'guardian_phone' => $row['guardian_phone'] ?? ($row['father_phone'] ?? ($row['mobile'] ?? '')),
        ];

        if ($student_id !== '') $seen_ids_in_file[$student_id] = true;

        $validated[] = ['row_no' => $rowIdx + 2, 'data' => $resolved, 'errors' => $errors, 'ok' => empty($errors)];
    }

    if (!$validated) {
        $_SESSION['imp_err'] = 'ফাইলে কোনো বৈধ ডাটা সারি পাওয়া যায়নি।';
        header('Location: index.php?page=student_import');
        exit();
    }

    $token = bin2hex(random_bytes(12));
    file_put_contents($import_tmp_dir . 'import_' . $token . '.json', json_encode($validated, JSON_UNESCAPED_UNICODE));

    $preview_rows = $validated;
    $preview_token = $token;
}

// -----------------------------------------------------------
// ৭) STEP 2: Confirm — শুধু ভ্যালিড রো গুলো insert করা
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['imp_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করুন।';
        header('Location: index.php?page=student_import');
        exit();
    }

    $token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
    $tmpFile = $import_tmp_dir . 'import_' . $token . '.json';
    if ($token === '' || !is_file($tmpFile)) {
        $_SESSION['imp_err'] = 'প্রিভিউ ডাটার মেয়াদ শেষ হয়ে গেছে (১ ঘন্টা), আবার ফাইল আপলোড করুন।';
        header('Location: index.php?page=student_import');
        exit();
    }

    $rows = json_decode(file_get_contents($tmpFile), true) ?: [];
    $imported = 0;
    $skipped = 0;

    $stmt = $pdo->prepare(
        "INSERT INTO students
            (session_id, shift_id, class_id, section_id, group_id, device_serial, student_id, roll_no, attendance_sms,
             name_en, name_bn, dob, gender, blood_group, religion, nationality, mobile,
             present_address, permanent_address, father_name, father_phone, mother_name, mother_phone,
             guardian_type, guardian_name, guardian_phone, status, branch_id)
         VALUES
            (?, ?, ?, ?, ?, '1', ?, ?, 1,
             ?, ?, ?, ?, ?, ?, 'Bangladeshi', ?,
             ?, ?, ?, ?, ?, ?,
             'Father', ?, ?, 'Active', ?)"
    );

    // current_student_list branch_apply_filter() দিয়ে students.branch_id ফিল্টার করে —
    // তাই import করা রেকর্ডে অবশ্যই লগইন branch বসাতে হবে (NULL থাকলে লিস্টে আসে না)
    branch_ensure_column($pdo, 'students');
    $import_branch_id = branch_id_for_insert();

    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            if (empty($r['ok'])) { $skipped++; continue; }
            $d = $r['data'];

            $student_id = $d['student_id'] !== '' ? $d['student_id'] : import_next_student_id($pdo, (int) $d['session_id'], (int) $d['class_id'], (int) $d['section_id']);
            if (!$student_id) { $student_id = 'IMP' . date('ymd') . rand(1000, 9999); } // কোড সেটআপ না থাকলে ফলব্যাক
            $roll_no = $d['roll_no'] !== '' ? $d['roll_no'] : import_next_roll($pdo, (int) $d['session_id'], (int) $d['class_id'], (int) $d['section_id']);

            $stmt->execute([
                $d['session_id'], $d['shift_id'] ?: null, $d['class_id'], $d['section_id'], $d['group_id'] ?: null, $student_id, $roll_no,
                $d['name_en'], $d['name_bn'] ?: null, $d['dob'] ?: null, $d['gender'] ?: 'Male', $d['blood_group'] ?: '', $d['religion'] ?: '',
                $d['mobile'] ?: null,
                $d['present_address'] ?: '', $d['permanent_address'] ?: '', $d['father_name'] ?: '', $d['father_phone'] ?: null,
                $d['mother_name'] ?: '', $d['mother_phone'] ?: null,
                $d['guardian_name'] ?: ($d['father_name'] ?: 'N/A'), $d['guardian_phone'] ?: ($d['mobile'] ?: '01700000000'),
                $import_branch_id,
            ]);
            $imported++;
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['imp_err'] = 'Import করার সময় সমস্যা হয়েছে: ' . $e->getMessage();
        header('Location: index.php?page=student_import');
        exit();
    }

    @unlink($tmpFile);
    $_SESSION['imp_msg'] = "{$imported} জন শিক্ষার্থী সফলভাবে import করা হয়েছে।" . ($skipped ? " {$skipped} টি সারি এরর থাকায় বাদ দেওয়া হয়েছে।" : '');
    sec_log_activity($pdo, 'Student', 'Import', "{$imported} জন শিক্ষার্থী import করা হয়েছে" . ($skipped ? " ({$skipped} টি সারি বাদ দেওয়া হয়েছে)" : '') . "।");
    header('Location: index.php?page=student_import');
    exit();
}

$csrf = htmlspecialchars(csrf_token());
$ok_count = $preview_rows ? count(array_filter($preview_rows, fn($r) => $r['ok'])) : 0;
$err_count = $preview_rows ? count($preview_rows) - $ok_count : 0;

// প্রিভিউ দেখানোর সময় উপরে Session/Class/Shift/Section এর নাম badge আকারে দেখানোর জন্য
$preview_ctx = null;
if ($preview_rows) {
    $first = $preview_rows[0]['data'];
    $ctx_stmt = $pdo->prepare(
        "SELECT
            (SELECT session_name FROM academic_sessions WHERE id = ?) AS session_name,
            (SELECT class_name FROM academic_classes WHERE id = ?) AS class_name,
            (SELECT shift_name FROM academic_shifts WHERE id = ?) AS shift_name,
            (SELECT section_name FROM academic_sections WHERE id = ?) AS section_name"
    );
    $ctx_stmt->execute([$first['session_id'], $first['class_id'], $first['shift_id'] ?: 0, $first['section_id']]);
    $preview_ctx = $ctx_stmt->fetch();
}
?>

<div class="card-custom">
    <h4 class="fw-bold mb-4 text-dark"><i class="fa-solid fa-file-import"></i> Student Module &gt; Import Students</h4>

    <?php if ($imp_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($imp_msg); ?></div><?php endif; ?>
    <?php if ($imp_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($imp_err); ?></div><?php endif; ?>

    <?php if (!$preview_rows): ?>
        <div class="row">
            <div class="col-md-7">
                <div class="card p-3 bg-light">
                    <h6 class="fw-bold mb-3">Session / Class / Section সিলেক্ট করুন, তারপর ফাইল আপলোড করুন</h6>
                    <form method="POST" action="index.php?page=student_import" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>

                        <div class="row g-2 mb-2">
                            <div class="col-sm-6">
                                <label class="form-label small fw-bold">Session <span class="text-danger">*</span></label>
                                <select name="session_id" class="form-select form-select-sm" required>
                                    <option value="">Select Session</option>
                                    <?php foreach ($imp_sessions as $s): ?>
                                        <option value="<?php echo (int) $s['id']; ?>" <?php echo (int) $s['id'] === (int) $imp_current_session ? 'selected' : ''; ?>><?php echo iv($s['session_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small fw-bold">Class <span class="text-danger">*</span></label>
                                <select name="class_id" id="impClassSelect" class="form-select form-select-sm" required onchange="impFilterSections(); impToggleGroupNote();">
                                    <option value="">Select Class</option>
                                    <?php foreach ($imp_classes as $c): ?>
                                        <option value="<?php echo (int) $c['id']; ?>"><?php echo iv($c['class_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-sm-6">
                                <label class="form-label small fw-bold">Shift</label>
                                <select name="shift_id" id="impShiftSelect" class="form-select form-select-sm" onchange="impFilterSections();">
                                    <option value="0">যেকোনো Shift</option>
                                    <?php foreach ($imp_shifts as $sh): ?>
                                        <option value="<?php echo (int) $sh['id']; ?>"><?php echo iv($sh['shift_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small fw-bold">Section <span class="text-danger">*</span></label>
                                <select name="section_id" id="impSectionSelect" class="form-select form-select-sm" required disabled>
                                    <option value="">Select Class first</option>
                                    <?php foreach ($imp_sections as $sec): ?>
                                        <option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" data-shift="<?php echo (int) $sec['shift_id']; ?>" hidden><?php echo iv($sec['section_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="alert alert-info small py-2 px-3 mb-3" id="impGroupNote" style="display:none;">
                            <i class="fa-solid fa-circle-info"></i> এই ক্লাসে Group (Science/Arts/Commerce) করা আছে — তাই ফাইলে প্রতিটা Student-এর জন্য <b>Group</b> কলাম দিতে হবে।
                        </div>

                        <hr>
                        <div class="mb-3">
                            <label class="form-label">Excel (.xlsx) / CSV / TXT ফাইল সিলেক্ট করুন</label>
                            <input type="file" name="import_file" class="form-control" accept=".xlsx,.csv,.txt" required>
                            <small class="text-muted d-block mt-1">সর্বোচ্চ 5MB। পুরনো .xls ফরম্যাট হলে আগে .xlsx বা .csv তে সেভ করে নিন।</small>
                        </div>
                        <button type="submit" name="upload_file" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-upload"></i> Upload &amp; Preview
                        </button>
                        <a href="index.php?page=student_import&sample=1" class="btn btn-outline-secondary btn-sm">
                            <i class="fa-solid fa-download"></i> স্যাম্পল টেমপ্লেট ডাউনলোড
                        </a>
                    </form>
                </div>
            </div>
            <div class="col-md-5">
                <div class="alert alert-info small">
                    <b>নিয়মাবলী:</b>
                    <ul class="mb-0 ps-3">
                        <li>উপরে <b>Session, Class, Section</b> সিলেক্ট করুন — ফাইলের সব Student এই একই জায়গায় import হবে। একই Class কিন্তু আলাদা Section-এর Student আলাদা করে আলাদা ফাইলে import করুন।</li>
                        <li>ফাইলে Class/Section/Session কলামের দরকার নেই — শুধু <b>Name</b> আবশ্যক, বাকিগুলো ঐচ্ছিক।</li>
                        <li>যে Class-এ Group করা আছে, সেই Class-এর ফাইলে <b>Group</b> কলাম আবশ্যক এবং Academic Module &gt; Group List এ তৈরি করা নামের সাথে মিলতে হবে।</li>
                        <li>সিলেক্ট করা Session/Class/Shift/Section-এর জন্য আগে থেকে <b>Finance Module &gt; Admission Fees</b> এ Fee Setting করা না থাকলে ফাইল আপলোডই হবে না।</li>
                        <li><b>Student ID</b> ও <b>Roll No</b> খালি রাখলে সিস্টেম নিজে থেকে অটো-জেনারেট করবে।</li>
                        <li>Upload করার পর প্রথমে প্রিভিউ দেখাবে — কোনো সারিতে ভুল থাকলে সেটা লাল দেখাবে, Confirm করলে শুধু ঠিক সারিগুলোই সেভ হবে।</li>
                    </ul>
                </div>
            </div>
        </div>

        <script>
            const impClassesWithGroup = <?php echo json_encode(array_map('intval', $imp_groups_all)); ?>;
            function impFilterSections() {
                const classId = document.getElementById('impClassSelect').value;
                const shiftId = document.getElementById('impShiftSelect').value;
                const secSelect = document.getElementById('impSectionSelect');
                secSelect.disabled = !classId;
                Array.from(secSelect.options).forEach(opt => {
                    if (!opt.value) { opt.hidden = false; return; }
                    const classOk = !!classId && opt.dataset.class === classId;
                    const shiftOk = !shiftId || shiftId === '0' || !opt.dataset.shift || opt.dataset.shift === '0' || opt.dataset.shift === shiftId;
                    opt.hidden = !(classOk && shiftOk);
                });
                secSelect.options[0].textContent = classId ? 'Select Section' : 'Select Class first';
                if (secSelect.value && Array.from(secSelect.options).find(o => o.value === secSelect.value)?.hidden) {
                    secSelect.value = '';
                }
            }
            function impToggleGroupNote() {
                const classId = parseInt(document.getElementById('impClassSelect').value || '0', 10);
                document.getElementById('impGroupNote').style.display = impClassesWithGroup.includes(classId) ? 'block' : 'none';
            }
            impFilterSections();
            impToggleGroupNote();
        </script>
    <?php else: ?>
        <div class="mb-3">
            <span class="badge bg-secondary"><?php echo iv($preview_ctx['session_name'] ?? ''); ?></span>
            <span class="badge bg-secondary"><?php echo iv($preview_ctx['class_name'] ?? ''); ?></span>
            <?php if (!empty($preview_ctx['shift_name'])): ?><span class="badge bg-secondary"><?php echo iv($preview_ctx['shift_name']); ?></span><?php endif; ?>
            <span class="badge bg-secondary"><?php echo iv($preview_ctx['section_name'] ?? ''); ?></span>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <span class="badge bg-success">ঠিক আছে: <?php echo $ok_count; ?></span>
                <span class="badge bg-danger">এরর: <?php echo $err_count; ?></span>
                <span class="text-muted small ms-2">মোট <?php echo count($preview_rows); ?> সারি পাওয়া গেছে</span>
            </div>
            <div>
                <a href="index.php?page=student_import" class="btn btn-outline-secondary btn-sm">বাতিল করুন</a>
                <form method="POST" action="index.php?page=student_import" class="d-inline">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($preview_token); ?>">
                    <button type="submit" name="confirm_import" class="btn btn-success btn-sm" <?php echo $ok_count === 0 ? 'disabled' : ''; ?>>
                        <i class="fa-solid fa-check"></i> Confirm Import (<?php echo $ok_count; ?> জন)
                    </button>
                </form>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>সারি</th><th>স্ট্যাটাস</th><th>নাম</th><th>গ্রুপ</th><th>রোল</th><th>পিতার নাম</th><th>বিস্তারিত</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview_rows as $r): $d = $r['data']; ?>
                        <tr class="<?php echo $r['ok'] ? '' : 'table-danger'; ?>">
                            <td><?php echo (int) $r['row_no']; ?></td>
                            <td><?php echo $r['ok'] ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">Error</span>'; ?></td>
                            <td><?php echo iv($d['name_en']); ?></td>
                            <td><?php echo $d['group_id'] ? '✓' : '<span class="text-muted">-</span>'; ?></td>
                            <td><?php echo iv($d['roll_no'] ?: '(অটো)'); ?></td>
                            <td><?php echo iv($d['father_name']); ?></td>
                            <td class="small text-danger"><?php echo iv(implode(', ', $r['errors'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
