<?php
// admin/student_admission.php - Student Admission Form (স্ক্রিনশট অনুযায়ী)
require_once __DIR__ . '/../config/admission_fee_helpers.php';
// ভর্তির সময়েই যদি কোনো ছাত্র/ছাত্রী স্কলারশিপ/উপবৃত্তির জন্য চিহ্নিত হয় (মার্ক করে দেওয়া হয়),
// সেটা সরাসরি scholarship_awards-এ একটা Pending Award হিসেবে বসে যায় — ফলে আর আলাদা করে
// Assign Scholarship পেজে গিয়ে এন্ট্রি করতে হয় না, এবং Scholarship Report/Disbursement সহ
// বাকি সব জায়গায় এটা এমনিতেই দেখা যায় (config/scholarship_helpers.php - sch_ensure_tables())।
require_once __DIR__ . '/../config/scholarship_helpers.php';
sch_ensure_tables($pdo);

$stu_err = $_SESSION['stu_err'] ?? '';
$stu_msg = $_SESSION['stu_msg'] ?? '';
$stu_field_errors = $_SESSION['stu_field_errors'] ?? [];
$stu_old = $_SESSION['stu_old'] ?? null;
unset($_SESSION['stu_err'], $_SESSION['stu_msg'], $_SESSION['stu_field_errors'], $_SESSION['stu_old']);

// পাবলিক অনলাইন ভর্তি ফর্ম থেকে status='Pending' আসতে পারে — পুরনো ইনস্টলেশনে
// students.status ENUM('Active','Inactive') হলে নিরাপদে VARCHAR এ কনভার্ট করে দেওয়া হচ্ছে
$stu_col_type = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'status'"
)->fetchColumn();
if ($stu_col_type !== false && stripos((string) $stu_col_type, 'pending') === false) {
    $pdo->exec("ALTER TABLE students MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Active'");
}
$stu_res_col = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'admission_result'"
)->fetchColumn();
if (!$stu_res_col) {
    $pdo->exec("ALTER TABLE students ADD COLUMN admission_result VARCHAR(20) NULL");
}
$inst_adm_col = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'institute_settings' AND COLUMN_NAME = 'admission_type'"
)->fetchColumn();
if (!$inst_adm_col) {
    $pdo->exec("ALTER TABLE institute_settings ADD COLUMN admission_type VARCHAR(20) NOT NULL DEFAULT 'General'");
}

// blood_group এ এখন 'Unknown' অপশনও থাকে (৭ অক্ষর) — পুরনো ইনস্টলেশনে কলাম
// VARCHAR(5) থাকলে সেটা কেটে যেতে পারে, তাই নিরাপদে VARCHAR(10) এ বাড়ানো হচ্ছে
$stu_bg_len = $pdo->query(
    "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'blood_group'"
)->fetchColumn();
if ($stu_bg_len !== false && (int) $stu_bg_len < 10) {
    $pdo->exec("ALTER TABLE students MODIFY COLUMN blood_group VARCHAR(10) NOT NULL");
}
$adm_bg_len = $pdo->query(
    "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admissions' AND COLUMN_NAME = 'blood_group'"
)->fetchColumn();
if ($adm_bg_len !== false && (int) $adm_bg_len < 10) {
    $pdo->exec("ALTER TABLE admissions MODIFY COLUMN blood_group VARCHAR(10) NULL");
}

// Class Nine/Ten ইত্যাদির Science/Arts/Commerce Group সিলেক্ট করার জন্য students.group_id কলাম
// (আগে টেবিলে ছিল না — Result Module সহ অনেক জায়গায় এই কলাম রেফার করা হলেও Admission ফর্মে
// সেট করার কোনো উপায় ছিল না, তাই এখানে auto-migrate করা হলো)
$stu_grp_col = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'group_id'"
)->fetchColumn();
if (!$stu_grp_col) {
    $pdo->exec("ALTER TABLE students ADD COLUMN group_id INT NULL AFTER section_id");
}

// Status "Transfer" সিলেক্ট হলে স্টুডেন্টকে Archive Student List-এ পাঠানোর জন্য
// students.is_current কলাম দরকার (student_list.php-তেও একই migration আছে —
// কেউ প্রথমেই এই Admission ফর্মে এলে যাতে কলাম না থাকার এরর না দেয়)
$stu_ic_col = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'is_current'"
)->fetchColumn();
if (!$stu_ic_col) {
    $pdo->exec("ALTER TABLE students ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 1");
}

// -----------------------------------------------------------
// ছবি আপলোড হেল্পার (Student/Father/Mother Photo) — jpg/png, ম্যাক্স 300KB
// -----------------------------------------------------------
function stu_handle_upload(string $field): array
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'error' => null];
    }

    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'ছবি আপলোডে সমস্যা হয়েছে।'];
    }
    if ($file['size'] > 300 * 1024) {
        return ['ok' => false, 'path' => null, 'error' => 'ছবির সাইজ সর্বোচ্চ 300 KB হতে হবে।'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    if (!isset($allowed_mimes[$ext])) {
        return ['ok' => false, 'path' => null, 'error' => 'শুধু jpg/png ছবি আপলোড করা যাবে।'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== $allowed_mimes[$ext]) {
        return ['ok' => false, 'path' => null, 'error' => 'ছবির প্রকৃত কনটেন্ট এক্সটেনশনের সাথে মিলছে না।'];
    }

    $upload_dir = tenant_upload_abs(__DIR__ . '/..', 'students');
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $random_name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $random_name)) {
        return ['ok' => false, 'path' => null, 'error' => 'ছবি সেভ করা যায়নি।'];
    }

    return ['ok' => true, 'path' => tenant_upload_rel('students') . '/' . $random_name, 'error' => null];
}

// html sanitize (Comments — Quill থেকে আসা HTML)
function stu_sanitize_richtext(string $html): string
{
    $allowed = '<p><br><b><strong><i><em><u><ul><ol><li><a><span><h1><h2><h3><h4><blockquote>';
    $html = strip_tags($html, $allowed);
    $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/href\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i', 'href="#"', $html);
    return $html;
}

// -----------------------------------------------------------
// POST Handler (Create / Update)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_student'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['stu_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=student_admission');
        exit();
    }

    $id = (int) ($_POST['id'] ?? 0);
    $admission_id = (int) ($_POST['admission_id'] ?? 0);
    // Applicant List (admission_applicants.php) থেকে Edit-এ এলে "from=applicants" থাকে —
    // Validation error হলেও যেন সেই সূত্র হারিয়ে না যায়, তাই আলাদা করে রাখা হচ্ছে
    $from_qs = (($_POST['from'] ?? '') === 'applicants') ? '&from=applicants' : '';

    $f = function (string $key, $default = '') { return trim($_POST[$key] ?? $default); };
    $i = function (string $key) { $v = trim($_POST[$key] ?? ''); return $v !== '' ? (int) $v : null; };

    // ভ্যালিডেশন ফেল করলে ব্যবহারকারীর টাইপ করা সব ডেটা ($_SESSION['stu_old']) এবং
    // কোন কোন ফিল্ডে সমস্যা হয়েছে তার তালিকা ($_SESSION['stu_field_errors']) সেভ করে
    // রেখে একই ভিউতে ফিরিয়ে দেওয়া হয় — যাতে পুরো ফর্ম আবার নতুন করে পূরণ করা না লাগে।
    $stu_redirect_url = 'index.php?page=student_admission' . ($id ? '&view=edit&id=' . $id : '&view=add') . $from_qs;
    function stu_fail(string $url, string $summary, array $field_errors = []): void
    {
        $_SESSION['stu_err'] = $summary;
        $_SESSION['stu_field_errors'] = $field_errors;
        $_SESSION['stu_old'] = $_POST;
        header('Location: ' . $url);
        exit();
    }

    $session_id     = $i('session_id');
    $shift_id       = $i('shift_id');
    $class_id       = $i('class_id');
    $section_id     = $i('section_id');
    $group_id       = $i('group_id');
    $device_serial  = $f('device_serial', '1');
    $student_id     = $f('student_id');
    $tracking_id    = $f('tracking_id');
    $rfid_card_no   = $f('rfid_card_no');
    $roll_no        = $f('roll_no');
    $attendance_sms = isset($_POST['attendance_sms']) ? 1 : 0;

    // Branch — Super Admin ফর্ম থেকে নির্দিষ্ট Branch বেছে দিতে পারবে (নাহলে
    // ডিফল্ট নিজের বর্তমান সেশন Branch); সাধারণ (branch-locked) ইউজারের জন্য
    // POST থেকে আসা যেকোনো মান উপেক্ষা করে সবসময় তার নিজের branch_current_id()-ই
    // বসানো হয় — নাহলে কেউ ম্যানুয়ালি ফর্ম এডিট করে অন্য branch-এ স্টুডেন্ট
    // ঢুকিয়ে দিতে পারত। এই মানটা INSERT ও UPDATE দুই ক্ষেত্রেই বসবে, যাতে আগে
    // ভুল branch_id নিয়ে "আটকে" থাকা কোনো স্টুডেন্টকে শুধু Save চাপলেই ঠিক করা যায়।
    if (branch_is_super_admin()) {
        $posted_branch_id = (int) ($_POST['branch_id'] ?? 0);
        $valid_branch_ids = array_column(branch_list_active($pdo), 'id');
        $resolved_branch_id = ($posted_branch_id > 0 && in_array($posted_branch_id, $valid_branch_ids, true))
            ? $posted_branch_id
            : branch_current_id();
    } else {
        $resolved_branch_id = branch_current_id();
    }

    $name_en           = $f('name_en');
    $name_bn           = $f('name_bn');
    $dob                = $f('dob') ?: null;
    $birth_reg_no      = $f('birth_reg_no');
    $gender            = $f('gender');
    $orphan            = $f('orphan');
    $disability_status = $f('disability_status');
    $blood_group       = $f('blood_group');
    $religion          = $f('religion');
    $nationality       = $f('nationality', 'Bangladeshi');
    $working_student   = $f('working_student');

    $mobile            = $f('mobile');
    $email             = $f('email');
    $present_address   = $f('present_address');
    $permanent_address = $f('permanent_address');

    $prev_school_name = $f('prev_school_name');
    $prev_class_id    = $i('prev_class_id');
    $prev_section     = $f('prev_section');
    $prev_roll        = $f('prev_roll');
    $prev_result      = $f('prev_result');

    $father_name           = $f('father_name');
    $father_phone          = $f('father_phone');
    $father_nid            = $f('father_nid');
    $father_profession     = $f('father_profession');
    $father_designation    = $f('father_designation');
    $father_office_address = $f('father_office_address');
    $father_office_phone   = $f('father_office_phone');

    $mother_name           = $f('mother_name');
    $mother_phone          = $f('mother_phone');
    $mother_nid            = $f('mother_nid');
    $mother_profession     = $f('mother_profession');
    $mother_designation    = $f('mother_designation');
    $mother_office_address = $f('mother_office_address');
    $mother_office_phone   = $f('mother_office_phone');

    $yearly_income = $f('yearly_income');
    $landless      = $f('landless');

    // এই ছাত্র/ছাত্রীকে স্কলারশিপ/উপবৃত্তির জন্য মার্ক করা হচ্ছে কিনা — students টেবিলে কোনো
    // কলাম যুক্ত করা হচ্ছে না, বরং সরাসরি scholarship_awards-এ Pending Award তৈরি হবে
    $is_scholarship        = isset($_POST['is_scholarship']) ? 1 : 0;
    $scholarship_type_id   = $i('scholarship_type_id');
    $scholarship_amount    = (float) ($_POST['scholarship_amount'] ?? 0);

    $guardian_type            = $f('guardian_type'); // ডিফল্ট নেই — ইউজারকে নিজে সিলেক্ট করতে হবে
    $guardian_name            = $f('guardian_name');
    $guardian_relation        = $f('guardian_relation');
    $guardian_phone           = $f('guardian_phone');
    $guardian_profession      = $f('guardian_profession');
    $guardian_designation     = $f('guardian_designation');
    $guardian_office_contact  = $f('guardian_office_contact');
    $guardian_office_address  = $f('guardian_office_address');

    $status_posted = $f('status', 'Active');
    $status = in_array($status_posted, ['Pending', 'Active', 'Inactive'], true) ? $status_posted : 'Active';

    // Global Admission Type = "Admission Test" বা "Lottery" হলে, একজন Applicant (status='Pending')-এর
    // Status/Student ID/Roll No এই Edit ফর্ম থেকে কোনোভাবেই পরিবর্তন করা যাবে না — Test Exam সম্পন্ন করে
    // Admission Module (admission_mark_entry.php -> admission_publish.php) দিয়েই Selected/Active করতে হবে।
    // DB-তে বর্তমান তথ্য যা-ই থাকুক না কেন, posted value কে ইগনোর করে সেটাই জোর করে রাখা হচ্ছে (form tamper হলেও নিরাপদ থাকবে)।
    if ($id > 0) {
        $chk_row = $pdo->prepare("SELECT status, admission_result, student_id, roll_no FROM students WHERE id = ?");
        $chk_row->execute([$id]);
        $chk_row = $chk_row->fetch();

        if ($chk_row) {
            // Student ID এডিট ফর্ম থেকে কখনোই বদলানো যাবে না (form tamper হলেও নিরাপদ থাকার জন্য
            // সার্ভার-সাইডে সবসময় DB-র বর্তমান student_id-ই জোর করে রাখা হচ্ছে); Roll No প্রয়োজনে বদলানো যায়।
            $student_id = $chk_row['student_id'];

            if ($chk_row['status'] === 'Pending') {
                $adm_type_row = $pdo->query("SELECT admission_type FROM institute_settings WHERE id = 1")->fetch();
                $adm_type = $adm_type_row['admission_type'] ?? 'General';
                if (in_array($adm_type, ['Admission Test', 'Lottery'], true)) {
                    $status = 'Pending';
                    $roll_no = $chk_row['roll_no'];
                }
            }
        }
    }
    $comments = stu_sanitize_richtext($f('comments'));

    // ------------- Validation (স্ক্রিনশটের লাল * চিহ্নিত ফিল্ডগুলো) -------------
    $errors = [];
    $field_errors = [];
    $req = function (bool $missing, string $field_key, string $label) use (&$errors, &$field_errors) {
        if ($missing) { $errors[] = $label; $field_errors[$field_key] = "$label আবশ্যক।"; }
    };
    $req(!$session_id, 'session_id', 'Session');
    $req(!$shift_id, 'shift_id', 'Shift');
    $req(!$class_id, 'class_id', 'Class');
    $req(!$section_id, 'section_id', 'Section');
    $req($student_id === '', 'student_id', 'Student ID');
    $req($roll_no === '', 'roll_no', 'Roll No');
    $req($name_en === '', 'name_en', 'Name (in English)');
    $req($blood_group === '', 'blood_group', 'Blood Group');
    $req($religion === '', 'religion', 'Religion');
    $req($gender === '', 'gender', 'Gender');
    $req($present_address === '', 'present_address', 'Present Address');
    $req($permanent_address === '', 'permanent_address', 'Permanent Address');
    $req(!$dob, 'dob', 'Date of Birth');
    $req($father_name === '', 'father_name', "Father's Name");
    $req($father_phone === '', 'father_phone', "Father's Phone");
    $req($mother_name === '', 'mother_name', "Mother's Name");
    $req(!in_array($guardian_type, ['Father', 'Mother', 'Others'], true), 'guardian_type', 'Guardian / Receiver');
    $req($guardian_name === '', 'guardian_name', "Guardian's Name");
    $req($guardian_phone === '', 'guardian_phone', "Guardian's Phone");
    $req($is_scholarship && !$scholarship_type_id, 'scholarship_type_id', 'Scholarship Type');
    $req($is_scholarship && $scholarship_amount <= 0, 'scholarship_amount', 'Scholarship Amount');

    // ফোন নম্বর ফিল্ড — দেওয়া থাকলে অবশ্যই ঠিক ১১ ডিজিট হতে হবে (ফর্মের
    // JS দিয়ে এটা টাইপ করার সাথে সাথেই আটকানো হয়, এটা শুধু ব্যাকএন্ড সেফটি নেট)
    $phone_fields_to_check = [
        'mobile' => ['Mobile', $mobile], 'father_phone' => ["Father's Phone", $father_phone],
        'mother_phone' => ["Mother's Phone", $mother_phone], 'guardian_phone' => ["Guardian's Phone", $guardian_phone],
    ];
    foreach ($phone_fields_to_check as $key => [$label, $val]) {
        if ($val !== '' && !preg_match('/^\d{11}$/', $val)) {
            $errors[] = "$label (অবশ্যই ১১ ডিজিট হতে হবে)";
            $field_errors[$key] = 'অবশ্যই ১১ ডিজিট হতে হবে।';
        }
    }

    // digits-only ফিল্ড — দেওয়া থাকলে শুধু সংখ্যা থাকতে হবে (JS দিয়ে টাইপ করার
    // সময়ই আটকানো হয়, এটা শুধু ব্যাকএন্ড সেফটি নেট)
    $digits_only_fields_to_check = [
        'tracking_id' => 'Tracking ID', 'rfid_card_no' => 'RFID Card No', 'roll_no' => 'Roll No',
        'birth_reg_no' => 'Birth Registration No', 'father_nid' => "Father's NID", 'mother_nid' => "Mother's NID",
        'yearly_income' => 'Yearly Income', 'father_office_phone' => "Father's Office Contact No.",
        'mother_office_phone' => "Mother's Office Contact No.", 'guardian_office_contact' => 'Guardian Office Contact No.',
    ];
    $digits_only_values = [
        'tracking_id' => $tracking_id, 'rfid_card_no' => $rfid_card_no, 'roll_no' => $roll_no,
        'birth_reg_no' => $birth_reg_no, 'father_nid' => $father_nid, 'mother_nid' => $mother_nid,
        'yearly_income' => $yearly_income, 'father_office_phone' => $father_office_phone,
        'mother_office_phone' => $mother_office_phone, 'guardian_office_contact' => $guardian_office_contact,
    ];
    foreach ($digits_only_fields_to_check as $key => $label) {
        $val = $digits_only_values[$key];
        if ($val !== '' && !preg_match('/^\d+$/', $val)) {
            $errors[] = "$label (শুধু সংখ্যা দেওয়া যাবে)";
            $field_errors[$key] = 'শুধু সংখ্যা দেওয়া যাবে।';
        }
    }

    // Class-টার জন্য Group (Science/Arts/Commerce ইত্যাদি) তৈরি করা থাকলে সেটা বেছে নেওয়া বাধ্যতামূলক
    // (সব ক্লাসে Group থাকে না — যেমন Six/Seven — তাই যে ক্লাসের Group নেই, সেখানে এই চেক প্রযোজ্য না)
    $class_has_groups = false;
    if ($class_id) {
        $gchk = $pdo->prepare("SELECT COUNT(*) FROM academic_groups WHERE class_id = ? AND status = 'Active'");
        $gchk->execute([$class_id]);
        $class_has_groups = ((int) $gchk->fetchColumn()) > 0;
    }
    $req($class_has_groups && !$group_id, 'group_id', 'Group');

    if ($errors) {
        stu_fail($stu_redirect_url, 'এই ফিল্ডগুলো আবশ্যক: ' . implode(', ', $errors), $field_errors);
    }

    // নতুন Student (Add) হলে — এই Session/Class/Shift/Section/Gender এর জন্য
    // Finance Module > Student Accounts > Admission Fees এ Fee Setting করা না থাকলে
    // Admission করা যাবে না (Edit-এর ক্ষেত্রে এই বাধা প্রযোজ্য নয়)
    if ($id <= 0 && !admission_fee_setting_exists($pdo, (int) $session_id, (int) $class_id, (int) $shift_id, (int) $section_id, $gender)) {
        stu_fail($stu_redirect_url, 'এই Class/Session-এর জন্য এখনো কোনো Admission Fees Setting করা হয়নি। প্রথমে Finance Module > Student Accounts > Admission Fees থেকে Fee সেট করুন, তারপর Student Admission করুন।');
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        stu_fail($stu_redirect_url, 'সঠিক ইমেইল দিন।', ['email' => 'সঠিক ইমেইল দিন।']);
    }

    // Student ID ইউনিক কিনা যাচাই — নোট: Student Promotion/Migration-এ একই student_id নিয়ে
    // একাধিক রো তৈরি হয় (প্রতি Academic Year-এ একটা করে, পুরনোটা is_current=0/Archive হয়ে
    // যায়) — এটা ইচ্ছাকৃত ডিজাইন, একই ছাত্রের ইতিহাস ট্র্যাক করার জন্য। তাই এখানে শুধু অন্য
    // কোনো "Current" রেকর্ড (is_current = 1) এর সাথে কনফ্লিক্ট আছে কিনা দেখা হচ্ছে — প্রমোট করা
    // ছাত্রের নিজের পুরনো Archive রেকর্ডের সাথে মিলে গেলে ভুলভাবে "আগে থেকেই ব্যবহার হচ্ছে" এরর
    // দেখাবে না।
    $chk = $pdo->prepare("SELECT id FROM students WHERE student_id = ? AND id != ? AND is_current = 1");
    $chk->execute([$student_id, $id]);
    if ($chk->fetch()) {
        stu_fail($stu_redirect_url, 'এই Student ID আগে থেকেই ব্যবহার হচ্ছে, অন্য একটা দিন।', ['student_id' => 'এই Student ID আগে থেকেই ব্যবহার হচ্ছে।']);
    }

    $photo = stu_handle_upload('photo');
    $father_photo = stu_handle_upload('father_photo');
    $mother_photo = stu_handle_upload('mother_photo');
    $upload_error = $photo['error'] ?? $father_photo['error'] ?? $mother_photo['error'] ?? null;

    if ($upload_error) {
        stu_fail($stu_redirect_url, $upload_error);
    }

    $columns = [
        'session_id' => $session_id, 'shift_id' => $shift_id, 'class_id' => $class_id, 'section_id' => $section_id,
        'group_id' => $group_id, 'device_serial' => $device_serial ?: null, 'student_id' => $student_id, 'tracking_id' => $tracking_id ?: null,
        'rfid_card_no' => $rfid_card_no ?: null, 'roll_no' => $roll_no, 'attendance_sms' => $attendance_sms,
        'name_en' => $name_en, 'name_bn' => $name_bn ?: null, 'dob' => $dob, 'birth_reg_no' => $birth_reg_no ?: null,
        'gender' => $gender, 'orphan' => $orphan ?: null, 'disability_status' => $disability_status ?: null,
        'blood_group' => $blood_group, 'religion' => $religion, 'nationality' => $nationality, 'working_student' => $working_student ?: null,
        'mobile' => $mobile ?: null, 'email' => $email ?: null, 'present_address' => $present_address, 'permanent_address' => $permanent_address,
        'prev_school_name' => $prev_school_name ?: null, 'prev_class_id' => $prev_class_id, 'prev_section' => $prev_section ?: null,
        'prev_roll' => $prev_roll ?: null, 'prev_result' => $prev_result ?: null,
        'father_name' => $father_name, 'father_phone' => $father_phone ?: null, 'father_nid' => $father_nid ?: null,
        'father_profession' => $father_profession ?: null, 'father_designation' => $father_designation ?: null,
        'father_office_address' => $father_office_address ?: null, 'father_office_phone' => $father_office_phone ?: null,
        'mother_name' => $mother_name, 'mother_phone' => $mother_phone ?: null, 'mother_nid' => $mother_nid ?: null,
        'mother_profession' => $mother_profession ?: null, 'mother_designation' => $mother_designation ?: null,
        'mother_office_address' => $mother_office_address ?: null, 'mother_office_phone' => $mother_office_phone ?: null,
        'yearly_income' => $yearly_income ?: null, 'landless' => $landless ?: null,
        'guardian_type' => $guardian_type, 'guardian_name' => $guardian_name, 'guardian_relation' => $guardian_relation ?: null,
        'guardian_phone' => $guardian_phone, 'guardian_profession' => $guardian_profession ?: null,
        'guardian_designation' => $guardian_designation ?: null, 'guardian_office_contact' => $guardian_office_contact ?: null,
        'guardian_office_address' => $guardian_office_address ?: null,
        'status' => $status, 'comments' => $comments ?: null, 'branch_id' => $resolved_branch_id,
    ];

    // Status "Transfer" হলে স্টুডেন্টকে Archive Student List-এ পাঠিয়ে দেওয়া হচ্ছে
    // (is_current = 0)। নতুন Admission সবসময় is_current = 1 দিয়ে শুরু হয়।
    // এডিটের সময় Transfer ছাড়া অন্য Status হলে is_current টাচ করা হচ্ছে না —
    // নাহলে Promotion-এর মাধ্যমে আগে Archive হয়ে যাওয়া কোনো স্টুডেন্টের তথ্য
    // শুধু Update করতে গেলেও সে ভুলবশত আবার Current-এ ফিরে আসত।
    if ($status === 'Transfer') {
        $columns['is_current'] = 0;
    } elseif ($id <= 0) {
        $columns['is_current'] = 1;
    }

    if ($id > 0) {
        $set_sql = implode(', ', array_map(fn($k) => "$k = ?", array_keys($columns)));
        $params = array_values($columns);
        if ($photo['path']) { $set_sql .= ", photo_path = ?"; $params[] = $photo['path']; }
        if ($father_photo['path']) { $set_sql .= ", father_photo_path = ?"; $params[] = $father_photo['path']; }
        if ($mother_photo['path']) { $set_sql .= ", mother_photo_path = ?"; $params[] = $mother_photo['path']; }
        $params[] = $id;
        $pdo->prepare("UPDATE students SET $set_sql WHERE id = ?")->execute($params);
        $_SESSION['stu_msg'] = 'শিক্ষার্থীর তথ্য আপডেট করা হয়েছে।';
        sec_log_activity($pdo, 'Student', 'Update', "শিক্ষার্থীর তথ্য আপডেট করা হয়েছে: {$name_en} (Roll: {$roll_no})");
    } else {
        $columns['photo_path'] = $photo['path'];
        $columns['father_photo_path'] = $father_photo['path'];
        $columns['mother_photo_path'] = $mother_photo['path'];
        $columns['admission_id'] = $admission_id > 0 ? $admission_id : null;
        $cols_sql = implode(', ', array_keys($columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $pdo->prepare("INSERT INTO students ($cols_sql) VALUES ($placeholders)")->execute(array_values($columns));

        // এই student একটা অনলাইন ভর্তি আবেদন থেকে "Enroll" করা হলে, সেই আবেদনটা
        // স্বয়ংক্রিয়ভাবে Approved হয়ে যায় — Current Student List-এ চলে যাওয়ার লজিক এটাই
        if ($admission_id > 0) {
            $pdo->prepare("UPDATE admissions SET status = 'Approved' WHERE id = ?")->execute([$admission_id]);
        }

        $_SESSION['stu_msg'] = 'নতুন শিক্ষার্থী ভর্তি করা হয়েছে।' . ($admission_id > 0 ? ' সংশ্লিষ্ট অনলাইন আবেদনও Approved করা হয়েছে।' : '');
        sec_log_activity($pdo, 'Student', 'Create', "নতুন শিক্ষার্থী ভর্তি করা হয়েছে: {$name_en} (Roll: {$roll_no})");
    }

    // ------------------------------------------------------------------
    // "Scholarship" মার্ক করা থাকলে — এই ছাত্র/ছাত্রীর জন্য একটা Pending Scholarship
    // Award তৈরি করে দেওয়া হচ্ছে, যাতে Assign Scholarship পেজে আলাদা করে আর ঢুকতে না
    // হয় এবং Process Disbursement/Scholarship Report-সহ বাকি সব জায়গায় এটা এমনিতেই
    // দেখা যায়। একই স্টুডেন্ট/সেশন/টাইপের জন্য বারবার Save করলেও (Edit ফর্ম) ডুপ্লিকেট
    // Award না তৈরি হয় সেটা নিশ্চিত করা হচ্ছে (Pending/Disbursed থাকলে স্কিপ)।
    // ------------------------------------------------------------------
    $student_pk = $id > 0 ? $id : (int) $pdo->lastInsertId();
    if ($is_scholarship && $scholarship_type_id && $scholarship_amount > 0 && $student_pk > 0) {
        $sch_chk = $pdo->prepare(
            "SELECT id FROM scholarship_awards WHERE student_id = ? AND session_id = ? AND scholarship_type_id = ? AND status IN ('Pending', 'Disbursed') LIMIT 1"
        );
        $sch_chk->execute([$student_pk, $session_id, $scholarship_type_id]);
        if (!$sch_chk->fetch()) {
            $pdo->prepare(
                "INSERT INTO scholarship_awards (student_id, scholarship_type_id, session_id, class_id, amount, status, remarks, created_by)
                 VALUES (?, ?, ?, ?, ?, 'Pending', ?, ?)"
            )->execute([$student_pk, $scholarship_type_id, $session_id, $class_id, $scholarship_amount, 'Marked during Student Admission', $_SESSION['admin_user'] ?? '']);
        }
    }

    // Applicant List থেকে Edit-এ এসে থাকলে, Save করার পর আবার Applicant List-এই ফিরিয়ে নেওয়া হচ্ছে
    if (($_POST['from'] ?? '') === 'applicants') {
        header('Location: index.php?page=admission_applicants');
        exit();
    }

    header('Location: index.php?page=student_admission');
    exit();
}

$view = $_GET['view'] ?? 'add'; // এই পেজে ডিফল্ট ভিউই "Add"

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions WHERE status = 'Active' ORDER BY id DESC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
$groups   = $pdo->query("SELECT id, group_name, class_id FROM academic_groups WHERE status = 'Active' ORDER BY group_name ASC")->fetchAll();

$current_session = $pdo->query("SELECT session_name FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();

$edit_row = null;
if ($view === 'edit' || $view === 'view') {
    $edit_id = (int) ($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_row = $stmt->fetch();
}
$is_view_only = ($view === 'view');

$blank = [
    'id' => 0, 'session_id' => '', 'shift_id' => '', 'class_id' => '', 'section_id' => '', 'group_id' => '', 'device_serial' => '1',
    'student_id' => '', 'tracking_id' => '', 'rfid_card_no' => '', 'roll_no' => '', 'attendance_sms' => 1,
    'name_en' => '', 'name_bn' => '', 'dob' => '', 'birth_reg_no' => '', 'gender' => '', 'orphan' => '',
    'disability_status' => '', 'blood_group' => '', 'religion' => '', 'nationality' => 'Bangladeshi', 'working_student' => '',
    'photo_path' => null, 'mobile' => '', 'email' => '', 'present_address' => '', 'permanent_address' => '',
    'prev_school_name' => '', 'prev_class_id' => '', 'prev_section' => '', 'prev_roll' => '', 'prev_result' => '',
    'father_name' => '', 'father_phone' => '', 'father_nid' => '', 'father_profession' => '', 'father_designation' => '',
    'father_office_address' => '', 'father_office_phone' => '', 'father_photo_path' => null,
    'mother_name' => '', 'mother_phone' => '', 'mother_nid' => '', 'mother_profession' => '', 'mother_designation' => '',
    'mother_office_address' => '', 'mother_office_phone' => '', 'mother_photo_path' => null,
    'yearly_income' => '', 'landless' => '',
    'guardian_type' => '', 'guardian_name' => '', 'guardian_relation' => '', 'guardian_phone' => '',
    'guardian_profession' => '', 'guardian_designation' => '', 'guardian_office_contact' => '', 'guardian_office_address' => '',
    'status' => 'Active', 'comments' => '',
    'is_scholarship' => 0, 'scholarship_type_id' => '', 'scholarship_amount' => '',
];
$d = $edit_row ?: $blank;

// Edit/View-এ এলে, এই স্টুডেন্টের কোনো Pending/Disbursed Scholarship Award আগে থেকেই
// থাকলে সেটা এখানে প্রি-ফিল করে দেখানো হচ্ছে (checkbox + type + amount)
if ($edit_row) {
    $sch_existing_stmt = $pdo->prepare(
        "SELECT scholarship_type_id, amount FROM scholarship_awards
         WHERE student_id = ? AND status IN ('Pending', 'Disbursed') ORDER BY id DESC LIMIT 1"
    );
    $sch_existing_stmt->execute([$edit_row['id']]);
    $sch_existing = $sch_existing_stmt->fetch();
    if ($sch_existing) {
        $d['is_scholarship'] = 1;
        $d['scholarship_type_id'] = $sch_existing['scholarship_type_id'];
        $d['scholarship_amount'] = $sch_existing['amount'];
    }
}
$scholarship_types_active = $pdo->query("SELECT id, name, category FROM scholarship_types WHERE status = 'Active' ORDER BY name ASC")->fetchAll();

// ভ্যালিডেশন এরর হয়ে থাকলে ইউজারের আগের টাইপ করা সব ডেটা দিয়ে ফর্ম রিফিল করা
// হচ্ছে — $blank/$edit_row-এর প্রতিটা কী-ই ফর্মের কোনো না কোনো ফিল্ডের name
// অ্যাট্রিবিউটের সাথে মেলে, তাই জেনেরিকভাবে মিলিয়ে বসানো যায়। Checkbox
// (attendance_sms) ফর্মে আনচেক থাকলে POST-এ কী-ই আসে না, তাই সেটা আলাদাভাবে
// হ্যান্ডেল করা হচ্ছে। Photo আপলোড ফিল্ড ব্রাউজার সিকিউরিটির কারণে কখনোই
// প্রোগ্রাম্যাটিকালি রিফিল করা যায় না — সেটা আবার সিলেক্ট করে দিতে হবে।
if ($stu_old) {
    foreach ($d as $k => $v) {
        if ($k === 'id' || str_ends_with($k, '_photo_path')) continue;
        if (array_key_exists($k, $stu_old)) {
            $d[$k] = $stu_old[$k];
        }
    }
    $d['attendance_sms'] = isset($stu_old['attendance_sms']) ? 1 : 0;
    $d['is_scholarship'] = isset($stu_old['is_scholarship']) ? 1 : 0;
}

// Applicant List (admission_applicants.php) থেকে "Edit" বাটনে ক্লিক করে এলে ?from=applicants থাকে
$from_applicants = ($_GET['from'] ?? '') === 'applicants';

// এই Student এখনো "Pending" (Applicant) এবং Institute-এর Admission Type Test/Lottery হলে,
// Status ফিল্ডটা এই ফর্ম থেকে লক করে রাখা হচ্ছে — শুধু Test Exam Module দিয়েই পরিবর্তন করা যাবে
$adm_type_row_v = $pdo->query("SELECT admission_type FROM institute_settings WHERE id = 1")->fetch();
$adm_type_v = $adm_type_row_v['admission_type'] ?? 'General';
$status_locked = (in_array($view, ['edit', 'view'], true) && $d['status'] === 'Pending' && in_array($adm_type_v, ['Admission Test', 'Lottery'], true));

// Student ID Edit/View ফর্ম থেকে কখনোই বদলানো যাবে না — শুধু Add করার সময়ই এডিট করা যাবে (অটো-জেনারেটেড)
$student_id_locked = in_array($view, ['edit', 'view'], true);

// -----------------------------------------------------------
// View (Read-only Profile) মোডের জন্য দরকারি Lookup ডাটা
// -----------------------------------------------------------
$vw = null;
if ($is_view_only && $edit_row) {
    $vw_stmt = $pdo->prepare(
        "SELECT st.*, ses.session_name, med.medium_name, sh.shift_name, cl.class_name,
                sec.section_name, grp.group_name, pcl.class_name AS prev_class_name
         FROM students st
         LEFT JOIN academic_sessions ses ON ses.id = st.session_id
         LEFT JOIN academic_classes cl ON cl.id = st.class_id
         LEFT JOIN academic_mediums med ON med.id = cl.medium_id
         LEFT JOIN academic_shifts sh ON sh.id = st.shift_id
         LEFT JOIN academic_sections sec ON sec.id = st.section_id
         LEFT JOIN academic_groups grp ON grp.id = st.group_id
         LEFT JOIN academic_classes pcl ON pcl.id = st.prev_class_id
         WHERE st.id = ?"
    );
    $vw_stmt->execute([$edit_row['id']]);
    $vw = $vw_stmt->fetch();

    $vw_sch_stmt = $pdo->prepare(
        "SELECT sa.amount, sa.status, st.name AS type_name FROM scholarship_awards sa
         LEFT JOIN scholarship_types st ON st.id = sa.scholarship_type_id
         WHERE sa.student_id = ? AND sa.status IN ('Pending', 'Disbursed') ORDER BY sa.id DESC LIMIT 1"
    );
    $vw_sch_stmt->execute([$edit_row['id']]);
    $vw_scholarship = $vw_sch_stmt->fetch();

    $vw_inst = $pdo->query("SELECT institute_name, phone_number, email FROM institute_settings WHERE id = 1")->fetch() ?: [];
    $vw_website = $_SERVER['HTTP_HOST'] ?? '';

}

// admissions.php থেকে "Enroll" বাটনে ক্লিক করে এলে ($from_admission থাকলে),
// আবেদনের ডাটা দিয়ে ফর্ম আগে থেকেই ভরে দেওয়া হচ্ছে (শুধু নতুন যোগ করার সময়, edit-এ না)
$from_admission = ($view === 'add') ? (int) ($_GET['from_admission'] ?? 0) : 0;
$applied_class_hint = '';
$applied_group_hint = '';

if ($from_admission > 0 && !$edit_row) {
    $adm_stmt = $pdo->prepare("SELECT * FROM admissions WHERE id = ?");
    $adm_stmt->execute([$from_admission]);
    $adm = $adm_stmt->fetch();

    if ($adm) {
        $d['name_en']           = $adm['student_name_en'] ?? '';
        $d['name_bn']           = $adm['student_name_bn'] ?? '';
        $d['dob']               = $adm['dob'] ?? '';
        $d['religion']          = $adm['religion'] ?? '';
        $d['blood_group']       = $adm['blood_group'] ?? '';
        $d['gender']            = $adm['gender'] ?? '';
        $d['father_name']       = $adm['father_name'] ?? '';
        $d['mother_name']       = $adm['mother_name'] ?? '';
        $d['guardian_name']     = ''; // গার্ডিয়ান টাইপ ইউজার নিজে সিলেক্ট করবে — ডিফল্ট পিতা নয়
        $d['guardian_phone']    = $adm['guardian_phone'] ?? '';
        $d['email']             = $adm['guardian_email'] ?? '';
        $d['present_address']   = $adm['address'] ?? '';
        $d['permanent_address'] = $adm['address'] ?? '';
        $d['photo_path']        = $adm['photo_path'] ?? null;

        $applied_class_hint = $adm['apply_class'] ?? '';
        $applied_group_hint = $adm['apply_group'] ?? '';
    }
}

function stv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// নির্দিষ্ট ফিল্ডের নিচে লাল এরর মেসেজ দেখানোর জন্য (যেখানে যেখানে ভুল/মিসিং,
// ঠিক সেখানেই দেখাবে) এবং ইনপুটে লাল বর্ডার (is-invalid) বসানোর হেল্পার
function fe(array $field_errors, string $key): string
{
    if (empty($field_errors[$key])) return '';
    return '<div class="small text-danger mt-1">' . htmlspecialchars($field_errors[$key]) . '</div>';
}
function fecls(array $field_errors, string $key): string
{
    return !empty($field_errors[$key]) ? ' is-invalid' : '';
}
function stv_html($val): string { return (string) ($val ?? ''); }
function sel($a, $b): string { return (string) $a === (string) $b ? 'selected' : ''; }
?>

<?php if ($is_view_only && $vw): ?>
    <?php
    function vv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
    function vv_or($val): string { $s = trim((string) ($val ?? '')); return $s !== '' ? vv($s) : 'N/A'; }
    ?>
    <style>
        .vprofile-toolbar { background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:10px 14px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
        .vprofile-card { background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:25px; }
        .vprofile-section-title { background:#f1f3f5; padding:8px 14px; font-weight:bold; color:#334155; border-radius:4px; margin:22px 0 12px; }
        .vprofile-section-title:first-of-type { margin-top:0; }
        table.vprofile-table { width:100%; border-collapse: collapse; margin-bottom: 4px; }
        table.vprofile-table td { border:1px solid #e5e7eb; padding:10px 14px; vertical-align:top; font-size:14px; }
        table.vprofile-table td.vp-label { width:20%; font-weight:600; color:#334155; background:#fafafa; white-space:nowrap; }
        table.vprofile-table td.vp-value { width:30%; color:#1e293b; }
    </style>

    <div class="vprofile-toolbar">
        <div class="d-flex gap-2">
            <a href="index.php?page=student_admission&view=add" class="btn btn-success btn-sm" title="Add New"><i class="fa-solid fa-plus"></i></a>
            <a href="index.php?page=student_admission&view=edit&id=<?php echo (int) $vw['id']; ?>" class="btn btn-primary btn-sm" title="Edit"><i class="fa-solid fa-pen"></i></a>
            <a href="index.php?page=current_student_list" class="btn btn-warning btn-sm" title="Back to List"><i class="fa-solid fa-list"></i></a>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php?page=print_admission_form&id=<?php echo (int) $vw['id']; ?>" class="btn btn-info btn-sm text-white" title="Print"><i class="fa-solid fa-print"></i></a>
            <a href="index.php?page=current_student_list" class="btn btn-danger btn-sm" title="Close"><i class="fa-solid fa-xmark"></i></a>
        </div>
    </div>

    <div class="vprofile-card">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h4 class="fw-bold mb-2"><?php echo vv($vw['name_en']); ?></h4>
                <p class="mb-1">Address : <?php echo vv_or($vw['present_address']); ?></p>
                <p class="mb-1">Student's Mobile : <?php echo vv_or($vw['mobile']); ?></p>
                <p class="mb-1">Guardian Mobile : <?php echo vv_or($vw['guardian_phone']); ?></p>
                <p class="mb-1">Email : <?php echo vv_or($vw['email']); ?>, Website : <?php echo vv_or($vw_website); ?></p>
                <p class="mb-0">Session : <?php echo vv_or($vw['session_name']); ?></p>
            </div>
            <div style="width:110px;height:110px;border:1px solid #cbd5e1;flex-shrink:0;overflow:hidden;">
                <?php if (!empty($vw['photo_path'])): ?>
                    <img src="../<?php echo vv($vw['photo_path']); ?>" style="width:100%;height:100%;object-fit:cover;">
                <?php else: ?>
                    <div class="d-flex align-items-center justify-content-center h-100 text-muted"><i class="fa-solid fa-user fa-2x"></i></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="vprofile-section-title">Academic Information</div>
        <table class="vprofile-table">
            <tr><td class="vp-label">Medium</td><td class="vp-value"><?php echo vv_or($vw['medium_name']); ?></td><td class="vp-label">Shift</td><td class="vp-value"><?php echo vv_or($vw['shift_name']); ?></td></tr>
            <tr><td class="vp-label">Class</td><td class="vp-value"><?php echo vv_or($vw['class_name']); ?></td><td class="vp-label">Section</td><td class="vp-value"><?php echo vv_or($vw['section_name']); ?></td></tr>
            <tr><td class="vp-label">Group</td><td class="vp-value"><?php echo vv_or($vw['group_name']); ?></td><td class="vp-label"></td><td class="vp-value"></td></tr>
            <tr><td class="vp-label">Roll</td><td class="vp-value"><?php echo vv_or($vw['roll_no']); ?></td><td class="vp-label">Device ID</td><td class="vp-value"><?php echo vv_or($vw['device_serial']); ?></td></tr>
            <tr><td class="vp-label">Finger ID</td><td class="vp-value"><?php echo vv_or($vw['tracking_id']); ?></td><td class="vp-label">Card No</td><td class="vp-value"><?php echo vv_or($vw['rfid_card_no']); ?></td></tr>
        </table>

        <div class="vprofile-section-title">Personal Information</div>
        <table class="vprofile-table">
            <tr><td class="vp-label">Student's Name</td><td class="vp-value"><?php echo vv_or($vw['name_en']); ?></td><td class="vp-label">Student's ID</td><td class="vp-value"><?php echo vv_or($vw['student_id']); ?></td></tr>
            <tr><td class="vp-label">Date of Birth</td><td class="vp-value"><?php echo vv_or($vw['dob']); ?></td><td class="vp-label">Gender</td><td class="vp-value"><?php echo vv_or($vw['gender']); ?></td></tr>
            <tr><td class="vp-label">Religion</td><td class="vp-value"><?php echo vv_or($vw['religion']); ?></td><td class="vp-label">Birth Registraion No</td><td class="vp-value"><?php echo vv_or($vw['birth_reg_no']); ?></td></tr>
            <tr><td class="vp-label">Blood Group</td><td class="vp-value"><?php echo vv_or($vw['blood_group']); ?></td><td class="vp-label">Nationality</td><td class="vp-value"><?php echo vv_or($vw['nationality']); ?></td></tr>
            <tr><td class="vp-label">Orphan ?</td><td class="vp-value"><?php echo vv_or($vw['orphan']); ?></td><td class="vp-label">Scholarship</td><td class="vp-value"><?php echo $vw_scholarship ? vv($vw_scholarship['type_name'] . ' — ৳' . number_format((float) $vw_scholarship['amount'], 2) . ' (' . $vw_scholarship['status'] . ')') : 'N/A'; ?></td></tr>
        </table>

        <div class="vprofile-section-title">Previous School Information</div>
        <table class="vprofile-table">
            <tr><td class="vp-label">Previous School Name</td><td class="vp-value"><?php echo vv_or($vw['prev_school_name']); ?></td><td class="vp-label">Previous School Class</td><td class="vp-value"><?php echo vv_or($vw['prev_class_name']); ?></td></tr>
            <tr><td class="vp-label">Previous School Section</td><td class="vp-value"><?php echo vv_or($vw['prev_section']); ?></td><td class="vp-label">Previous School Roll</td><td class="vp-value"><?php echo vv_or($vw['prev_roll']); ?></td></tr>
            <tr><td class="vp-label">Previous School Result</td><td class="vp-value" colspan="3"><?php echo vv_or($vw['prev_result']); ?></td></tr>
        </table>

        <div class="vprofile-section-title">Contact Information</div>
        <table class="vprofile-table">
            <tr><td class="vp-label">Present Address</td><td class="vp-value"><?php echo vv_or($vw['present_address']); ?></td><td class="vp-label">Permanent Address</td><td class="vp-value"><?php echo vv_or($vw['permanent_address']); ?></td></tr>
            <tr><td class="vp-label">Mobile</td><td class="vp-value"><?php echo vv_or($vw['mobile']); ?></td><td class="vp-label">Email</td><td class="vp-value"><?php echo vv_or($vw['email']); ?></td></tr>
        </table>

        <div class="vprofile-section-title">Parents Information</div>
        <table class="vprofile-table">
            <tr><td class="vp-label">Father's Name</td><td class="vp-value"><?php echo vv_or($vw['father_name']); ?></td><td class="vp-label">Mother's Name</td><td class="vp-value"><?php echo vv_or($vw['mother_name']); ?></td></tr>
            <tr><td class="vp-label">Father's Mobile</td><td class="vp-value"><?php echo vv_or($vw['father_phone']); ?></td><td class="vp-label">Mother's Mobile</td><td class="vp-value"><?php echo vv_or($vw['mother_phone']); ?></td></tr>
            <tr><td class="vp-label">Father's Profession</td><td class="vp-value"><?php echo vv_or($vw['father_profession']); ?></td><td class="vp-label">Mother's Profession</td><td class="vp-value"><?php echo vv_or($vw['mother_profession']); ?></td></tr>
            <tr><td class="vp-label">Father's NID</td><td class="vp-value"><?php echo vv_or($vw['father_nid']); ?></td><td class="vp-label">Mother's NID</td><td class="vp-value"><?php echo vv_or($vw['mother_nid']); ?></td></tr>
            <tr><td class="vp-label">Father's Designation</td><td class="vp-value"><?php echo vv_or($vw['father_designation']); ?></td><td class="vp-label">Mother's Designation</td><td class="vp-value"><?php echo vv_or($vw['mother_designation']); ?></td></tr>
            <tr><td class="vp-label">Father's Office Name &amp; Address</td><td class="vp-value"><?php echo vv_or($vw['father_office_address']); ?></td><td class="vp-label">Mother's Office Name &amp; Address</td><td class="vp-value"><?php echo vv_or($vw['mother_office_address']); ?></td></tr>
            <tr><td class="vp-label">Father's Office Contact No</td><td class="vp-value"><?php echo vv_or($vw['father_office_phone']); ?></td><td class="vp-label">Mother's Office Contact No</td><td class="vp-value"><?php echo vv_or($vw['mother_office_phone']); ?></td></tr>
            <tr><td class="vp-label">Yearly Income</td><td class="vp-value"><?php echo vv_or($vw['yearly_income']); ?></td><td class="vp-label">Land Amount</td><td class="vp-value"><?php echo $vw['landless'] !== null && $vw['landless'] !== '' ? vv($vw['landless']) : 'N/A'; ?></td></tr>
        </table>

        <div class="vprofile-section-title">Guardian / Receiver Information</div>
        <table class="vprofile-table">
            <tr><td class="vp-label">Name</td><td class="vp-value"><?php echo vv_or($vw['guardian_name']); ?></td><td class="vp-label">Phone</td><td class="vp-value"><?php echo vv_or($vw['guardian_phone']); ?></td></tr>
            <tr><td class="vp-label">Profession</td><td class="vp-value"><?php echo vv_or($vw['guardian_profession']); ?></td><td class="vp-label">Designation</td><td class="vp-value"><?php echo vv_or($vw['guardian_designation']); ?></td></tr>
            <tr><td class="vp-label">Office Name &amp; Address</td><td class="vp-value"><?php echo vv_or($vw['guardian_office_address']); ?></td><td class="vp-label">Office Contact No</td><td class="vp-value"><?php echo vv_or($vw['guardian_office_contact']); ?></td></tr>
            <tr><td class="vp-label">Relation with Student</td><td class="vp-value"><?php echo vv_or($vw['guardian_relation']); ?></td><td class="vp-label">Status</td><td class="vp-value"><span class="fw-bold <?php echo $vw['status'] === 'Active' ? 'text-success' : 'text-secondary'; ?>"><?php echo vv($vw['status']); ?></span></td></tr>
            <tr><td class="vp-label">Created At</td><td class="vp-value"><?php echo vv_or($vw['created_at']); ?></td><td class="vp-label">Modified At</td><td class="vp-value"><?php echo vv_or($vw['updated_at']); ?></td></tr>
        </table>
    </div>

<?php elseif ($is_view_only && !$vw): ?>
    <div class="alert alert-danger">Student পাওয়া যায়নি।</div>
<?php else: ?>
<div class="card-custom">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <?php if ($from_applicants): ?>
            <a href="index.php?page=admission_applicants" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Applicant List</a>
        <?php else: ?>
            <a href="index.php?page=current_student_list" class="bg-warning bg-opacity-75 p-2 d-inline-block" style="width:40px;border-radius:4px;" title="Current Student List-এ ফিরে যান">
                <i class="fa-solid fa-file-lines text-white"></i>
            </a>
        <?php endif; ?>
        <span class="text-primary fw-bold">Current Session : <?php echo stv($current_session['session_name'] ?? '-'); ?></span>
    </div>

    <?php if ($stu_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($stu_err); ?></div><?php endif; ?>
    <?php if ($stu_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($stu_msg); ?></div><?php endif; ?>

    <?php if ($view === 'add' && !admission_fee_any_setting_exists($pdo)): ?>
        <div class="alert alert-warning py-2">
            <i class="fa-solid fa-triangle-exclamation"></i>
            এখনো কোনো <strong>Admission Fees Setting</strong> তৈরি করা হয়নি — Save করলে সিস্টেম আটকে দেবে।
            আগে <a href="index.php?page=finance_admission_fees&view=config" class="alert-link">Finance Module &gt; Student Accounts &gt; Admission Fees</a> থেকে সংশ্লিষ্ট Class-এর জন্য Fee Setting তৈরি করুন।
        </div>
    <?php endif; ?>

    <form method="POST" action="index.php?page=student_admission" enctype="multipart/form-data" id="studentForm" onsubmit="return beforeStudentSubmit()">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
        <input type="hidden" name="admission_id" value="<?php echo (int) $from_admission; ?>">
        <input type="hidden" name="from" value="<?php echo $from_applicants ? 'applicants' : ''; ?>">
        <?php if ($applied_class_hint): ?>
            <div class="alert alert-info py-2">
                <i class="fa-solid fa-circle-info"></i> এই ফর্মটা <strong>অনলাইন ভর্তি আবেদন #<?php echo (int) $from_admission; ?></strong> থেকে ভরে দেওয়া হয়েছে।
                আবেদনে উল্লেখিত ছিল — শ্রেণি: <strong><?php echo stv($applied_class_hint); ?></strong><?php if ($applied_group_hint): ?>, গ্রুপ: <strong><?php echo stv($applied_group_hint); ?></strong><?php endif; ?>।
                নিচে সঠিক Class/Section বেছে নিয়ে Save করলে এই আবেদনটা স্বয়ংক্রিয়ভাবে <strong>Approved</strong> হয়ে যাবে।
            </div>
        <?php endif; ?>

        <!-- ================= Academic Information ================= -->
        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3"><i class="fa-solid fa-circle-info"></i> Academic Information</h6>
        <?php if (branch_is_super_admin() && branch_multi_branch_mode($pdo)): ?>
        <div class="row">
            <div class="col-md-6">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Branch <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <select name="branch_id" class="form-select">
                            <?php foreach (branch_list_active($pdo) as $br): ?>
                                <option value="<?php echo (int) $br['id']; ?>" <?php echo sel($d['branch_id'] ?? branch_current_id(), $br['id']); ?>><?php echo stv($br['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">এই স্টুডেন্ট কোন Branch-এর — ভুল Branch-এ সেভ হয়ে থাকলে এখান থেকে ঠিক করে দিন।</small>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="row">
            <div class="col-md-6">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Session <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <select name="session_id" id="sessionSelect" class="form-select<?php echo fecls($stu_field_errors, 'session_id'); ?>" required onchange="stuAutoGenerateId()">
                            <option value="">Select Session</option>
                            <?php foreach ($sessions as $s): ?>
                                <option value="<?php echo (int) $s['id']; ?>" <?php echo sel($d['session_id'], $s['id']); ?>><?php echo stv($s['session_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php echo fe($stu_field_errors, 'session_id'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Class <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <select name="class_id" id="classSelect" class="form-select<?php echo fecls($stu_field_errors, 'class_id'); ?>" required onchange="filterSections(); filterGroups(); stuAutoGenerateId();">
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>" <?php echo sel($d['class_id'], $c['id']); ?>><?php echo stv($c['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php echo fe($stu_field_errors, 'class_id'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Device Serial / MAC</label>
                    <div class="col-sm-8"><input type="text" name="device_serial" class="form-control" value="<?php echo stv($d['device_serial']); ?>"></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Tracking ID</label>
                    <div class="col-sm-8">
                        <input type="text" name="tracking_id" class="form-control digits-only<?php echo fecls($stu_field_errors, 'tracking_id'); ?>" inputmode="numeric" value="<?php echo stv($d['tracking_id']); ?>">
                        <?php echo fe($stu_field_errors, 'tracking_id'); ?>
                        <small class="text-warning">Last Finger ID :</small>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">RFID Card No</label>
                    <div class="col-sm-8">
                        <input type="text" name="rfid_card_no" class="form-control digits-only<?php echo fecls($stu_field_errors, 'rfid_card_no'); ?>" inputmode="numeric" value="<?php echo stv($d['rfid_card_no']); ?>">
                        <?php echo fe($stu_field_errors, 'rfid_card_no'); ?>
                        <small class="text-warning">Not Depends on Device ID</small>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Shift <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <select name="shift_id" id="shiftSelect" class="form-select<?php echo fecls($stu_field_errors, 'shift_id'); ?>" required onchange="stuAutoGenerateId()">
                            <option value="">Select Shift</option>
                            <?php foreach ($shifts as $s): ?>
                                <option value="<?php echo (int) $s['id']; ?>" <?php echo sel($d['shift_id'], $s['id']); ?>><?php echo stv($s['shift_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php echo fe($stu_field_errors, 'shift_id'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Section <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <select name="section_id" id="sectionSelect" class="form-select<?php echo fecls($stu_field_errors, 'section_id'); ?>" required onchange="stuAutoGenerateId()">
                            <option value="">Select Section</option>
                            <?php foreach ($sections as $s): ?>
                                <option value="<?php echo (int) $s['id']; ?>" data-class="<?php echo (int) $s['class_id']; ?>" <?php echo sel($d['section_id'], $s['id']); ?>><?php echo stv($s['section_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php echo fe($stu_field_errors, 'section_id'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center" id="groupFieldRow" style="display:none;">
                    <label class="col-sm-4 col-form-label fw-bold text-end" id="groupFieldLabel">Group <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <select name="group_id" id="groupSelect" class="form-select<?php echo fecls($stu_field_errors, 'group_id'); ?>">
                            <option value="">Select Group</option>
                            <?php foreach ($groups as $g): ?>
                                <option value="<?php echo (int) $g['id']; ?>" data-class="<?php echo (int) $g['class_id']; ?>" <?php echo sel($d['group_id'], $g['id']); ?>><?php echo stv($g['group_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php echo fe($stu_field_errors, 'group_id'); ?>
                        <small class="text-muted">Nine/Ten এর মতো যে Class-এ Science/Arts/Commerce Group করা আছে, শুধু সেখানেই এই ফিল্ড দেখাবে।</small>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Student ID <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <input type="text" name="student_id" id="studentIdInput" class="form-control<?php echo fecls($stu_field_errors, 'student_id'); ?>" value="<?php echo stv($d['student_id']); ?>" required <?php echo $view === 'add' ? 'placeholder="Session/Class/Section/Shift সিলেক্ট করলে অটো আসবে"' : ''; ?> <?php echo $student_id_locked ? 'readonly style="background:#f1f3f5;"' : ''; ?>>
                        <?php echo fe($stu_field_errors, 'student_id'); ?>
                        <?php if ($view === 'add'): ?><small class="text-muted" id="studentIdHint"></small><?php endif; ?>
                        <?php if ($student_id_locked && !$status_locked): ?><small class="text-muted d-block mt-1"><i class="fa-solid fa-lock"></i> একবার তৈরি হয়ে গেলে Student ID পরিবর্তন করা যায় না।</small><?php endif; ?>
                        <?php if ($status_locked): ?><small class="text-danger d-block mt-1"><i class="fa-solid fa-lock"></i> Test Exam সম্পন্ন না হওয়া পর্যন্ত Applicant ID/Roll No পরিবর্তন করা যাবে না।</small><?php endif; ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Roll No <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <input type="text" name="roll_no" id="rollNoInput" class="form-control digits-only<?php echo fecls($stu_field_errors, 'roll_no'); ?>" inputmode="numeric" value="<?php echo stv($d['roll_no']); ?>" required <?php echo $status_locked ? 'readonly style="background:#f1f3f5;"' : ''; ?>>
                        <?php echo fe($stu_field_errors, 'roll_no'); ?>
                        <?php if ($status_locked): ?><small class="text-danger d-block mt-1"><i class="fa-solid fa-lock"></i> Test Exam সম্পন্ন না হওয়া পর্যন্ত App. No (Roll) পরিবর্তন করা যাবে না।</small><?php endif; ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <div class="col-sm-4"></div>
                    <div class="col-sm-8 d-flex align-items-center gap-2">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" role="switch" name="attendance_sms" id="attSms" style="width:2.5em;height:1.3em;" <?php echo $d['attendance_sms'] ? 'checked' : ''; ?>>
                        </div>
                        <label for="attSms" class="mb-0">Attendance SMS</label>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= Personal Information ================= -->
        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3 mt-2"><i class="fa-solid fa-circle-info"></i> Personal Information</h6>
        <div class="row">
            <div class="col-md-6">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Name (in English) <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <input type="text" name="name_en" class="form-control<?php echo fecls($stu_field_errors, 'name_en'); ?>" value="<?php echo stv($d['name_en']); ?>" required>
                        <?php echo fe($stu_field_errors, 'name_en'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Name (in Bengali)</label>
                    <div class="col-sm-8"><input type="text" name="name_bn" class="form-control" value="<?php echo stv($d['name_bn']); ?>"></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Date of Birth <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <input type="text" name="dob" id="dobInput" class="form-control<?php echo fecls($stu_field_errors, 'dob'); ?>" value="<?php echo stv($d['dob']); ?>" placeholder="YYYY-MM-DD" autocomplete="off" required>
                        <?php echo fe($stu_field_errors, 'dob'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Birth Registration No</label>
                    <div class="col-sm-8">
                        <input type="text" name="birth_reg_no" class="form-control digits-only<?php echo fecls($stu_field_errors, 'birth_reg_no'); ?>" inputmode="numeric" value="<?php echo stv($d['birth_reg_no']); ?>">
                        <?php echo fe($stu_field_errors, 'birth_reg_no'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Gender <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <select name="gender" class="form-select<?php echo fecls($stu_field_errors, 'gender'); ?>" required>
                            <option value="">Select Gender</option>
                            <option value="Male" <?php echo sel($d['gender'], 'Male'); ?>>Male</option>
                            <option value="Female" <?php echo sel($d['gender'], 'Female'); ?>>Female</option>
                        </select>
                        <?php echo fe($stu_field_errors, 'gender'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Orphan</label>
                    <div class="col-sm-8">
                        <select name="orphan" class="form-select">
                            <option value="">Select Option</option>
                            <option value="Yes" <?php echo sel($d['orphan'], 'Yes'); ?>>Yes</option>
                            <option value="No" <?php echo sel($d['orphan'], 'No'); ?>>No</option>
                        </select>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Disability Status</label>
                    <div class="col-sm-8">
                        <select name="disability_status" class="form-select">
                            <option value="">Select Option</option>
                            <option value="None" <?php echo sel($d['disability_status'], 'None'); ?>>None</option>
                            <option value="Physical" <?php echo sel($d['disability_status'], 'Physical'); ?>>Physical</option>
                            <option value="Visual" <?php echo sel($d['disability_status'], 'Visual'); ?>>Visual</option>
                            <option value="Hearing" <?php echo sel($d['disability_status'], 'Hearing'); ?>>Hearing</option>
                            <option value="Other" <?php echo sel($d['disability_status'], 'Other'); ?>>Other</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Blood Group <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <select name="blood_group" class="form-select<?php echo fecls($stu_field_errors, 'blood_group'); ?>" required>
                            <option value="">Select Blood Group</option>
                            <?php foreach (['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-', 'Unknown'] as $bg): ?>
                                <option value="<?php echo $bg; ?>" <?php echo sel($d['blood_group'], $bg); ?>><?php echo $bg; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php echo fe($stu_field_errors, 'blood_group'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Religion <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <select name="religion" class="form-select<?php echo fecls($stu_field_errors, 'religion'); ?>" required>
                            <option value="">Select Religion</option>
                            <?php foreach (['Islam', 'Hinduism', 'Buddhism', 'Christianity', 'Others'] as $rl): ?>
                                <option value="<?php echo $rl; ?>" <?php echo sel($d['religion'], $rl); ?>><?php echo $rl; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php echo fe($stu_field_errors, 'religion'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Nationality</label>
                    <div class="col-sm-8"><input type="text" name="nationality" class="form-control" value="<?php echo stv($d['nationality']); ?>"></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Working Student</label>
                    <div class="col-sm-8">
                        <select name="working_student" class="form-select">
                            <option value="">Select Option</option>
                            <option value="Yes" <?php echo sel($d['working_student'], 'Yes'); ?>>Yes</option>
                            <option value="No" <?php echo sel($d['working_student'], 'No'); ?>>No</option>
                        </select>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Photo</label>
                    <div class="col-sm-8">
                        <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png">
                        <small class="text-warning">(Allowed only {jpg,png} & maximum size is 300 KB)</small>
                        <?php if (!empty($d['photo_path'])): ?><br><small class="text-muted">বর্তমান: <a href="../<?php echo stv($d['photo_path']); ?>" target="_blank">দেখুন</a></small><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= Scholarship Information ================= -->
        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3 mt-2"><i class="fa-solid fa-circle-info"></i> Scholarship Information</h6>
        <div class="row">
            <div class="col-md-6">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">স্কলারশিপ/উপবৃত্তি?</label>
                    <div class="col-sm-8">
                        <div class="form-check pt-2">
                            <input type="checkbox" name="is_scholarship" id="isScholarshipChk" class="form-check-input" value="1" <?php echo $d['is_scholarship'] ? 'checked' : ''; ?> onchange="document.getElementById('scholarshipFieldsWrap').classList.toggle('d-none', !this.checked)">
                            <label class="form-check-label" for="isScholarshipChk">এই ছাত্র/ছাত্রী স্কলারশিপ/উপবৃত্তির জন্য চিহ্নিত করুন</label>
                        </div>
                        <small class="text-muted">মার্ক করে দিলে এটা স্বয়ংক্রিয়ভাবে Finance Module &gt; Scholarship &amp; Welfare-এ একটা Pending Award হিসেবে যুক্ত হয়ে যাবে — সেখান থেকেই পরে বকেয়ার সাথে সমন্বয় (Disbursement) করা যাবে।</small>
                    </div>
                </div>
            </div>
            <div class="col-md-6<?php echo $d['is_scholarship'] ? '' : ' d-none'; ?>" id="scholarshipFieldsWrap">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Scholarship Type</label>
                    <div class="col-sm-8">
                        <select name="scholarship_type_id" class="form-select<?php echo fecls($stu_field_errors, 'scholarship_type_id'); ?>">
                            <option value="">Select</option>
                            <?php foreach ($scholarship_types_active as $st): ?>
                                <option value="<?php echo (int) $st['id']; ?>" <?php echo sel($d['scholarship_type_id'], $st['id']); ?>><?php echo stv($st['name'] . ' (' . sch_category_label($st['category']) . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php echo fe($stu_field_errors, 'scholarship_type_id'); ?>
                        <?php if (empty($scholarship_types_active)): ?>
                            <small class="text-danger">কোনো Active Scholarship Type নেই — আগে <a href="index.php?page=scholarship_types&view=add" target="_blank">এখান থেকে</a> একটা তৈরি করুন।</small>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Amount</label>
                    <div class="col-sm-8">
                        <input type="number" step="0.01" min="0" name="scholarship_amount" class="form-control<?php echo fecls($stu_field_errors, 'scholarship_amount'); ?>" value="<?php echo stv($d['scholarship_amount']); ?>">
                        <?php echo fe($stu_field_errors, 'scholarship_amount'); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= Contact Information ================= -->
        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3 mt-2"><i class="fa-solid fa-circle-info"></i> Contact Information</h6>
        <div class="row">
            <div class="col-md-6">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Mobile</label>
                    <div class="col-sm-8">
                        <input type="text" name="mobile" id="mobileInput" class="form-control phone-11<?php echo fecls($stu_field_errors, 'mobile'); ?>" inputmode="numeric" maxlength="11" value="<?php echo stv($d['mobile']); ?>">
                        <div class="small text-danger<?php echo empty($stu_field_errors['mobile']) ? ' d-none' : ''; ?>" id="mobileInputErr"><?php echo htmlspecialchars($stu_field_errors['mobile'] ?? 'মোবাইল নম্বর অবশ্যই ১১ ডিজিটের হতে হবে।'); ?></div>
                    </div>
                </div>
                <div class="row mb-3 align-items-start">
                    <label class="col-sm-4 col-form-label fw-bold text-end pt-2">Present Address <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <textarea name="present_address" id="presentAddress" class="form-control<?php echo fecls($stu_field_errors, 'present_address'); ?>" rows="2" required><?php echo stv($d['present_address']); ?></textarea>
                        <?php echo fe($stu_field_errors, 'present_address'); ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Email</label>
                    <div class="col-sm-8">
                        <input type="email" name="email" class="form-control<?php echo fecls($stu_field_errors, 'email'); ?>" value="<?php echo stv($d['email']); ?>">
                        <?php echo fe($stu_field_errors, 'email'); ?>
                    </div>
                </div>
                <div class="row mb-2 align-items-start">
                    <label class="col-sm-4 col-form-label fw-bold text-end pt-2">Permanent Address <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <textarea name="permanent_address" id="permanentAddress" class="form-control<?php echo fecls($stu_field_errors, 'permanent_address'); ?>" rows="2" required><?php echo stv($d['permanent_address']); ?></textarea>
                        <?php echo fe($stu_field_errors, 'permanent_address'); ?>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-sm-8 offset-sm-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="sameAsPresent" onchange="copyPresentAddress()">
                            <label class="form-check-label small" for="sameAsPresent">Same as Present Address</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= Previous School Information ================= -->
        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3 mt-2"><i class="fa-solid fa-circle-info"></i> Previous School Information</h6>
        <div class="row">
            <div class="col-md-6">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Previous School Name</label>
                    <div class="col-sm-8"><input type="text" name="prev_school_name" class="form-control" value="<?php echo stv($d['prev_school_name']); ?>"></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Previous Section</label>
                    <div class="col-sm-8"><input type="text" name="prev_section" class="form-control" value="<?php echo stv($d['prev_section']); ?>"></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Previous Roll</label>
                    <div class="col-sm-8"><input type="text" name="prev_roll" class="form-control" value="<?php echo stv($d['prev_roll']); ?>"></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Previous Class</label>
                    <div class="col-sm-8">
                        <select name="prev_class_id" class="form-select">
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>" <?php echo sel($d['prev_class_id'], $c['id']); ?>><?php echo stv($c['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Previous Result</label>
                    <div class="col-sm-8"><input type="text" name="prev_result" class="form-control" value="<?php echo stv($d['prev_result']); ?>"></div>
                </div>
            </div>
        </div>

        <!-- ================= Parents Information ================= -->
        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3 mt-2"><i class="fa-solid fa-circle-info"></i> Parents Information</h6>
        <div class="row">
            <div class="col-md-6">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Father's Name <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <input type="text" name="father_name" id="fatherNameInput" class="form-control<?php echo fecls($stu_field_errors, 'father_name'); ?>" value="<?php echo stv($d['father_name']); ?>" required>
                        <?php echo fe($stu_field_errors, 'father_name'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Father's Phone <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <input type="text" name="father_phone" id="fatherPhoneInput" class="form-control phone-11<?php echo fecls($stu_field_errors, 'father_phone'); ?>" inputmode="numeric" maxlength="11" value="<?php echo stv($d['father_phone']); ?>" required>
                        <div class="small text-danger<?php echo empty($stu_field_errors['father_phone']) ? ' d-none' : ''; ?>" id="fatherPhoneInputErr"><?php echo htmlspecialchars($stu_field_errors['father_phone'] ?? 'মোবাইল নম্বর অবশ্যই ১১ ডিজিটের হতে হবে।'); ?></div>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Father's NID</label>
                    <div class="col-sm-8">
                        <input type="text" name="father_nid" class="form-control digits-only<?php echo fecls($stu_field_errors, 'father_nid'); ?>" inputmode="numeric" value="<?php echo stv($d['father_nid']); ?>">
                        <?php echo fe($stu_field_errors, 'father_nid'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Father's Profession</label>
                    <div class="col-sm-8"><input type="text" name="father_profession" id="fatherProfessionInput" class="form-control" value="<?php echo stv($d['father_profession']); ?>"></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Father's Designation</label>
                    <div class="col-sm-8"><input type="text" name="father_designation" id="fatherDesignationInput" class="form-control" value="<?php echo stv($d['father_designation']); ?>"></div>
                </div>
                <div class="row mb-3 align-items-start">
                    <label class="col-sm-4 col-form-label fw-bold text-end pt-2">Office Name & Address</label>
                    <div class="col-sm-8"><textarea name="father_office_address" id="fatherOfficeAddressInput" class="form-control" rows="2"><?php echo stv($d['father_office_address']); ?></textarea></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Father's Office Contact No.</label>
                    <div class="col-sm-8">
                        <input type="text" name="father_office_phone" id="fatherOfficePhoneInput" class="form-control digits-only<?php echo fecls($stu_field_errors, 'father_office_phone'); ?>" inputmode="numeric" value="<?php echo stv($d['father_office_phone']); ?>">
                        <?php echo fe($stu_field_errors, 'father_office_phone'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Yearly Income</label>
                    <div class="col-sm-8">
                        <input type="text" name="yearly_income" class="form-control digits-only<?php echo fecls($stu_field_errors, 'yearly_income'); ?>" inputmode="numeric" value="<?php echo stv($d['yearly_income']); ?>">
                        <?php echo fe($stu_field_errors, 'yearly_income'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Landless ?</label>
                    <div class="col-sm-8">
                        <select name="landless" class="form-select">
                            <option value="">Select Option</option>
                            <option value="Yes" <?php echo sel($d['landless'], 'Yes'); ?>>Yes</option>
                            <option value="No" <?php echo sel($d['landless'], 'No'); ?>>No</option>
                        </select>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Father's Photo</label>
                    <div class="col-sm-8">
                        <input type="file" name="father_photo" class="form-control" accept=".jpg,.jpeg,.png">
                        <small class="text-warning">(Allowed only {jpg,png} & maximum size is 300 KB)</small>
                        <?php if (!empty($d['father_photo_path'])): ?><br><small class="text-muted">বর্তমান: <a href="../<?php echo stv($d['father_photo_path']); ?>" target="_blank">দেখুন</a></small><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Mother's Name <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <input type="text" name="mother_name" id="motherNameInput" class="form-control<?php echo fecls($stu_field_errors, 'mother_name'); ?>" value="<?php echo stv($d['mother_name']); ?>" required>
                        <?php echo fe($stu_field_errors, 'mother_name'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Mother's Phone</label>
                    <div class="col-sm-8">
                        <input type="text" name="mother_phone" id="motherPhoneInput" class="form-control phone-11<?php echo fecls($stu_field_errors, 'mother_phone'); ?>" inputmode="numeric" maxlength="11" value="<?php echo stv($d['mother_phone']); ?>">
                        <div class="small text-danger<?php echo empty($stu_field_errors['mother_phone']) ? ' d-none' : ''; ?>" id="motherPhoneInputErr"><?php echo htmlspecialchars($stu_field_errors['mother_phone'] ?? 'মোবাইল নম্বর অবশ্যই ১১ ডিজিটের হতে হবে।'); ?></div>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Mother's NID</label>
                    <div class="col-sm-8">
                        <input type="text" name="mother_nid" class="form-control digits-only<?php echo fecls($stu_field_errors, 'mother_nid'); ?>" inputmode="numeric" value="<?php echo stv($d['mother_nid']); ?>">
                        <?php echo fe($stu_field_errors, 'mother_nid'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Mother's Profession</label>
                    <div class="col-sm-8"><input type="text" name="mother_profession" id="motherProfessionInput" class="form-control" value="<?php echo stv($d['mother_profession']); ?>"></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Mother's Designation</label>
                    <div class="col-sm-8"><input type="text" name="mother_designation" id="motherDesignationInput" class="form-control" value="<?php echo stv($d['mother_designation']); ?>"></div>
                </div>
                <div class="row mb-3 align-items-start">
                    <label class="col-sm-4 col-form-label fw-bold text-end pt-2">Office Name & Address</label>
                    <div class="col-sm-8"><textarea name="mother_office_address" id="motherOfficeAddressInput" class="form-control" rows="2"><?php echo stv($d['mother_office_address']); ?></textarea></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Mother's Office Contact No.</label>
                    <div class="col-sm-8">
                        <input type="text" name="mother_office_phone" id="motherOfficePhoneInput" class="form-control digits-only<?php echo fecls($stu_field_errors, 'mother_office_phone'); ?>" inputmode="numeric" value="<?php echo stv($d['mother_office_phone']); ?>">
                        <?php echo fe($stu_field_errors, 'mother_office_phone'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Mother's Photo</label>
                    <div class="col-sm-8">
                        <input type="file" name="mother_photo" class="form-control" accept=".jpg,.jpeg,.png">
                        <small class="text-warning">(Allowed only {jpg,png} & maximum size is 300 KB)</small>
                        <?php if (!empty($d['mother_photo_path'])): ?><br><small class="text-muted">বর্তমান: <a href="../<?php echo stv($d['mother_photo_path']); ?>" target="_blank">দেখুন</a></small><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= Guardian / Receiver Information ================= -->
        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3 mt-2"><i class="fa-solid fa-circle-info"></i> Guardian / Receiver Information</h6>
        <div class="row">
            <div class="col-md-6">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Guardian / Receiver</label>
                    <div class="col-sm-8 d-flex gap-3 align-items-center">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="guardian_type" id="gtFather" value="Father" <?php echo $d['guardian_type'] === 'Father' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="gtFather">Father</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="guardian_type" id="gtMother" value="Mother" <?php echo $d['guardian_type'] === 'Mother' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="gtMother">Mother</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="guardian_type" id="gtOthers" value="Others" <?php echo $d['guardian_type'] === 'Others' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="gtOthers">Others</label>
                        </div>
                        <?php echo fe($stu_field_errors, 'guardian_type'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Guardian's Name <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <input type="text" name="guardian_name" id="guardianNameInput" class="form-control<?php echo fecls($stu_field_errors, 'guardian_name'); ?>" value="<?php echo stv($d['guardian_name']); ?>" required>
                        <?php echo fe($stu_field_errors, 'guardian_name'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Relation With Student</label>
                    <div class="col-sm-8"><input type="text" name="guardian_relation" id="guardianRelationInput" class="form-control" value="<?php echo stv($d['guardian_relation']); ?>"></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Guardian's Phone <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <input type="text" name="guardian_phone" id="guardianPhoneInput" class="form-control phone-11<?php echo fecls($stu_field_errors, 'guardian_phone'); ?>" inputmode="numeric" maxlength="11" value="<?php echo stv($d['guardian_phone']); ?>" required>
                        <div class="small text-danger<?php echo empty($stu_field_errors['guardian_phone']) ? ' d-none' : ''; ?>" id="guardianPhoneInputErr"><?php echo htmlspecialchars($stu_field_errors['guardian_phone'] ?? 'মোবাইল নম্বর অবশ্যই ১১ ডিজিটের হতে হবে।'); ?></div>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Status <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <?php if ($status_locked): ?>
                            <input type="text" class="form-control" value="Pending (অনলাইন আবেদন)" disabled>
                            <small class="text-danger d-block mt-1"><i class="fa-solid fa-lock"></i> এই Applicant-এর Status শুধু <strong>Test Exam / Result Publish</strong> মডিউল থেকেই পরিবর্তন করা যাবে। এখান থেকে শুধু তথ্য Update করা যাবে।</small>
                        <?php else: ?>
                            <select name="status" class="form-select">
                                <option value="Pending" <?php echo sel($d['status'], 'Pending'); ?>>Pending (অনলাইন আবেদন)</option>
                                <option value="Active" <?php echo sel($d['status'], 'Active'); ?>>Active</option>
                                <option value="Inactive" <?php echo sel($d['status'], 'Inactive'); ?>>Inactive</option>
                                <option value="Transfer" <?php echo sel($d['status'], 'Transfer'); ?>>Transfer</option>
                            </select>
                            <small class="text-muted d-block mt-1">Transfer সিলেক্ট করে Save করলে এই স্টুডেন্ট স্বয়ংক্রিয়ভাবে Archive Student List-এ চলে যাবে।</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Guardian's Profession</label>
                    <div class="col-sm-8"><input type="text" name="guardian_profession" id="guardianProfessionInput" class="form-control" value="<?php echo stv($d['guardian_profession']); ?>"></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Guardian's Designation</label>
                    <div class="col-sm-8"><input type="text" name="guardian_designation" id="guardianDesignationInput" class="form-control" value="<?php echo stv($d['guardian_designation']); ?>"></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Office Contact No.</label>
                    <div class="col-sm-8">
                        <input type="text" name="guardian_office_contact" id="guardianOfficeContactInput" class="form-control digits-only<?php echo fecls($stu_field_errors, 'guardian_office_contact'); ?>" inputmode="numeric" value="<?php echo stv($d['guardian_office_contact']); ?>">
                        <?php echo fe($stu_field_errors, 'guardian_office_contact'); ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-start">
                    <label class="col-sm-4 col-form-label fw-bold text-end pt-2">Office Name & Address</label>
                    <div class="col-sm-8"><textarea name="guardian_office_address" id="guardianOfficeAddressInput" class="form-control" rows="2"><?php echo stv($d['guardian_office_address']); ?></textarea></div>
                </div>
            </div>
        </div>

        <!-- ================= Comments ================= -->
        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3 mt-2"><i class="fa-solid fa-circle-info"></i> Comments</h6>
        <div class="row mb-4">
            <label class="col-sm-2 col-form-label fw-bold text-end">Comments (If any)</label>
            <div class="col-sm-9">
                <div id="commentsQuill" style="background:#fff;"><?php echo stv_html($d['comments']); ?></div>
                <input type="hidden" id="commentsInput" name="comments">
            </div>
        </div>

        <hr>
        <div class="text-center">
            <button type="submit" name="save_student" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;">Save</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (!$is_view_only): ?>
<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.js"></script>
<link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/air-datepicker@3.6.0/air-datepicker.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/air-datepicker@3.6.0/air-datepicker.js"></script>

<script>
    // Date of Birth-এর জন্য বেটার ক্যালেন্ডার — উপরের মাস/সাল লেখায় ক্লিক করলে
    // আগে Months view, তারপর Years view (দশক ধরে ধরে, যেমন 2000-2009, 2010-2019)
    // দেখাবে — পুরনো জন্মতারিখ বেছে নেওয়ার সময় বারবার Prev চাপা লাগবে না।
    // (air-datepicker.js একটা UMD স্ক্রিপ্ট — type="module"/import দিয়ে লোড করা
    // যায় না, তাই এখানে সাধারণ <script> ট্যাগ দিয়ে লোড করে window.AirDatepicker
    // গ্লোবাল হিসেবে ব্যবহার করা হচ্ছে, Quill-এর মতোই)
    const dobEl = document.getElementById('dobInput');
    if (dobEl && typeof AirDatepicker !== 'undefined') {
        // এই লাইব্রেরির bundled ডিফল্ট ভাষা আসলে Russian (যাচাই করে দেখা গেছে) —
        // তাই এখানে সরাসরি English locale অবজেক্ট বসিয়ে দেওয়া হচ্ছে, আলাদা
        // locale ফাইল লোড না করেই (সেগুলো CommonJS ফরম্যাটে, প্লেইন <script>
        // ট্যাগে ব্যবহার করা যায় না)
        const enLocale = {
            days: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
            daysShort: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            daysMin: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'],
            months: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            monthsShort: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            today: 'Today',
            clear: 'Clear',
            dateFormat: 'yyyy-MM-dd',
            timeFormat: 'hh:mm aa',
            firstDay: 0,
        };
        new AirDatepicker('#dobInput', {
            locale: enLocale,
            dateFormat: 'yyyy-MM-dd',
            maxDate: new Date(),
            startDate: dobEl.value ? new Date(dobEl.value) : new Date(2015, 0, 1),
            autoClose: true,
            isMobile: window.innerWidth < 576,
            // ক্যালেন্ডার থেকে তারিখ বাছলে ভ্যালু প্রোগ্রাম্যাটিকভাবে বসে (টাইপ/blur হয় না),
            // তাই এখানে event পাঠিয়ে দেওয়া হচ্ছে যাতে "এই ঘরটি পূরণ করা হয়নি" এরর সাথে সাথে চলে যায়
            onSelect: function () {
                setTimeout(function () {
                    dobEl.dispatchEvent(new Event('input', { bubbles: true }));
                    dobEl.dispatchEvent(new Event('change', { bubbles: true }));
                }, 0);
            },
        });
    }
</script>

<script>
    // Quill রিমোট CDN (jsdelivr) থেকে লোড হয় — নেটওয়ার্ক ব্লক/স্লো/অফলাইন থাকলে
    // (অনেক স্কুলের ইন্টারনেট অনির্ভরযোগ্য) window.Quill আন্ডিফাইন্ড থেকে যায় এবং
    // `new Quill(...)` এক্সসেপশন থ্রো করত — আগে এটা try/catch ছাড়া থাকায় এই
    // <script> ব্লকের বাকি পুরো কোড (beforeStudentSubmit, ফোন ভ্যালিডেশন, এবং
    // নিচের stuIdManuallyEdited/stuAutoGenerateId — Student ID/Roll No অটো-জেনারেশন)
    // একদম রান-ই হতো না, ফলে Student ID ফাঁকা থেকে যেত (ফিল্ডটা required, কিন্তু
    // placeholder বলছিল "অটো আসবে") আর পুরো Student Admission ফর্মই সাবমিট করা
    // যেত না। এখন Quill লোড ব্যর্থ হলে চুপচাপ কমেন্টস এডিটর বাদ দিয়ে বাকি সব
    // (সবচেয়ে গুরুত্বপূর্ণ — Student ID/Roll No অটো-জেনারেশন) ঠিকভাবে কাজ করবে।
    let commentsQuill = null;
    try {
        if (typeof Quill !== 'undefined') {
            commentsQuill = new Quill('#commentsQuill', { theme: 'snow', modules: { toolbar: [['bold', 'italic', 'underline'], [{ 'list': 'ordered' }, { 'list': 'bullet' }], ['link'], ['clean']] } });
        }
    } catch (e) {
        commentsQuill = null;
    }

    function beforeStudentSubmit() {
        if (commentsQuill) {
            document.getElementById('commentsInput').value = commentsQuill.root.innerHTML;
        }
        const phoneOk = validateAllPhoneFields();
        const requiredOk = validateAllRequiredFields();
        if (!phoneOk || !requiredOk) return false;
        return true;
    }

    // ------------------------------------------------------------------
    // ফোন নম্বর ফিল্ড (.phone-11 ক্লাস দেওয়া): টাইপ করার সাথে সাথেই
    // ১) সংখ্যা ছাড়া অন্য কিছু ঢুকতে দেয় না ২) ১১ ডিজিটের বেশি নিলে বাকিটা
    // কেটে দেয় ৩) ১১ ডিজিট না হলে (বা আবশ্যক হয়ে থাকলে খালি থাকলেও) সাথে
    // সাথে নিচে লাল মেসেজ দেখায় — কোনো Submit বাটন চাপার দরকার হয় না
    // ------------------------------------------------------------------
    function validatePhoneField(input) {
        const errEl = document.getElementById(input.id + 'Err');
        let digits = input.value.replace(/\D/g, '');
        if (digits.length > 11) digits = digits.slice(0, 11);
        input.value = digits;

        const isEmptyRequired = digits.length === 0 && input.hasAttribute('required');
        const isWrongLength = digits.length > 0 && digits.length !== 11;
        const isInvalid = isEmptyRequired || isWrongLength;

        if (errEl) {
            errEl.textContent = isEmptyRequired ? 'এই ঘরটি পূরণ করা হয়নি।' : 'মোবাইল নম্বর অবশ্যই ১১ ডিজিটের হতে হবে।';
            errEl.classList.toggle('d-none', !isInvalid);
        }
        input.classList.toggle('is-invalid', isInvalid);
        return !isInvalid;
    }

    function validateAllPhoneFields() {
        let allOk = true;
        let firstInvalid = null;
        document.querySelectorAll('.phone-11').forEach(function (input) {
            if (!validatePhoneField(input)) {
                allOk = false;
                if (!firstInvalid) firstInvalid = input;
            }
        });
        if (firstInvalid) { firstInvalid.focus(); firstInvalid.scrollIntoView({ block: 'center' }); }
        return allOk;
    }

    document.querySelectorAll('.phone-11').forEach(function (input) {
        input.addEventListener('input', function () { validatePhoneField(input); });
        input.addEventListener('blur', function () { validatePhoneField(input); });
    });

    // ------------------------------------------------------------------
    // যে ঘরগুলো পূরণ করা হয়নি (আবশ্যক ফিল্ড খালি) সেগুলো ফিল্ড থেকে সরে
    // গেলেই (blur) সাথে সাথে লাল বর্ডার + নিচে লাল মেসেজ দেখাবে — Submit
    // চাপার জন্য অপেক্ষা করতে হয় না। .phone-11 ফিল্ডগুলো আলাদাভাবে উপরে
    // হ্যান্ডেল হয়, তাই এখানে বাদ দেওয়া হয়েছে (একই ফিল্ডে দুইবার মেসেজ
    // দেখানো এড়াতে)।
    // ------------------------------------------------------------------
    function markRequiredField(el, valid) {
        el.classList.toggle('is-invalid', !valid);
        // এই ফিল্ডের ঠিক পরের সিবলিং-ই এর এরর মেসেজের জায়গা (PHP থেকে fe()
        // দিয়ে রেন্ডার হলে সেটাই থাকে, নাহলে JS নিজে বানিয়ে নেয় — দুইবার
        // মেসেজ যেন না বসে সেজন্য থাকলে সেটাই রিইউজ করা হয়)
        let errEl = el.nextElementSibling;
        const isErrDiv = errEl && errEl.tagName === 'DIV' && errEl.classList.contains('text-danger');
        if (!valid) {
            if (!isErrDiv) {
                errEl = document.createElement('div');
                errEl.className = 'small text-danger mt-1';
                el.insertAdjacentElement('afterend', errEl);
            }
            errEl.textContent = 'এই ঘরটি পূরণ করা হয়নি।';
            errEl.classList.remove('d-none');
        } else if (isErrDiv) {
            errEl.classList.add('d-none');
        }
    }

    function isVisible(el) { return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); }

    function validateRequiredField(el) {
        const empty = el.value === null || el.value.trim() === '';
        markRequiredField(el, !empty);
        return !empty;
    }

    function getLiveRequiredFields() {
        const fields = Array.from(document.querySelectorAll('#studentForm [required]'))
            .filter(el => !el.classList.contains('phone-11') && isVisible(el));
        // Group ফিল্ড শুধু তখনই আবশ্যক যখন সিলেক্ট করা Class-এর Group আছে
        // (তখনই groupFieldRow দেখা যায়) — তাই এইচটিএমএল-এ required বসানো
        // নেই, ভিজিবল থাকলে এখানে যোগ করে নেওয়া হচ্ছে
        const groupRow = document.getElementById('groupFieldRow');
        const groupSelect = document.getElementById('groupSelect');
        if (groupRow && groupSelect && groupRow.style.display !== 'none' && !fields.includes(groupSelect)) {
            fields.push(groupSelect);
        }
        return fields;
    }

    function validateAllRequiredFields() {
        let allOk = true;
        let firstInvalid = null;
        getLiveRequiredFields().forEach(function (el) {
            if (!validateRequiredField(el)) {
                allOk = false;
                if (!firstInvalid) firstInvalid = el;
            }
        });
        if (firstInvalid) { firstInvalid.focus(); firstInvalid.scrollIntoView({ block: 'center' }); }
        return allOk;
    }

    getLiveRequiredFields().forEach(function (el) {
        el.addEventListener('blur', function () { validateRequiredField(el); });
        if (el.tagName === 'SELECT') el.addEventListener('change', function () { validateRequiredField(el); });
    });
    // ঘর পূরণ হওয়ার সাথে সাথে (টাইপ/সিলেক্ট/ক্যালেন্ডার থেকে বাছা) লাল বর্ডার ও
    // এরর মেসেজ অটো সরে যাবে — blur বা Submit-এর জন্য অপেক্ষা করতে হবে না।
    // (PHP থেকে রেন্ডার হওয়া এরর এবং JS-এর তৈরি এরর দুটোই এখানে সামলানো হয়;
    // .phone-11 ফিল্ড নিজস্ব লজিকে চলে, তাই বাদ)
    function clearFieldErrorIfFilled(el) {
        if (el.classList.contains('phone-11')) return;
        if (el.value === null || el.value.trim() === '') return;
        if (el.classList.contains('is-invalid')) markRequiredField(el, true);
    }
    document.querySelectorAll('#studentForm input, #studentForm select, #studentForm textarea').forEach(function (el) {
        if (['radio', 'checkbox', 'hidden', 'file'].includes(el.type)) return;
        el.addEventListener('input', function () { clearFieldErrorIfFilled(el); });
        el.addEventListener('change', function () { clearFieldErrorIfFilled(el); });
    });
    // Guardian / Receiver রেডিও — যেকোনো একটা সিলেক্ট করলেই এরর মেসেজ চলে যাবে
    document.querySelectorAll('input[name="guardian_type"]').forEach(function (r) {
        r.addEventListener('change', function () {
            const box = r.closest('.d-flex');
            if (box) box.querySelectorAll('.text-danger.mt-1').forEach(function (e) { e.classList.add('d-none'); });
        });
    });

    // Class বদলালে Group ফিল্ড দেখা/লুকানো দুটোই হতে পারে, তাই আলাদা করে বাইন্ড করা
    document.getElementById('groupSelect')?.addEventListener('change', function () {
        validateRequiredField(this);
    });
    document.getElementById('groupSelect')?.addEventListener('blur', function () {
        if (document.getElementById('groupFieldRow')?.style.display !== 'none') validateRequiredField(this);
    });

    // ------------------------------------------------------------------
    // .digits-only ক্লাস দেওয়া ফিল্ড (Tracking ID, RFID, Roll No, Birth Reg
    // No, NID, Yearly Income, Office Contact) — টাইপ করার সাথে সাথেই সংখ্যা
    // ছাড়া অন্য কিছু (অক্ষর, স্পেস, চিহ্ন) ঢুকতে দেয় না। এগুলোর কোনো নির্দিষ্ট
    // দৈর্ঘ্য বাধ্যতামূলক না (তাই ১১-ডিজিট মেসেজ এখানে দরকার নেই), শুধু ফিল্টার।
    // ------------------------------------------------------------------
    document.querySelectorAll('.digits-only').forEach(function (input) {
        input.addEventListener('input', function () {
            input.value = input.value.replace(/\D/g, '');
        });
    });

    // ------------------------------------------------------------------
    // Guardian / Receiver: Father বা Mother সিলেক্ট করলে সেই তথ্য (নাম,
    // ফোন, পেশা, পদবি, অফিসের ঠিকানা/নম্বর) অটোমেটিক Guardian ফিল্ডে
    // কপি হয়ে যাবে — ম্যানুয়ালি আবার টাইপ করা লাগবে না। "Others" সিলেক্ট
    // করলে ফিল্ডগুলো খালি করে দেওয়া হয়, নিজে থেকে পূরণ করতে হবে।
    // ------------------------------------------------------------------
    function autoFillGuardianFromType() {
        const type = document.querySelector('input[name="guardian_type"]:checked')?.value;
        const set = (id, val) => {
            const el = document.getElementById(id);
            if (!el) return;
            el.value = val || '';
            el.dispatchEvent(new Event('input', { bubbles: true }));
        };

        if (type === 'Father') {
            set('guardianNameInput', document.getElementById('fatherNameInput')?.value);
            set('guardianPhoneInput', document.getElementById('fatherPhoneInput')?.value);
            set('guardianProfessionInput', document.getElementById('fatherProfessionInput')?.value);
            set('guardianDesignationInput', document.getElementById('fatherDesignationInput')?.value);
            set('guardianOfficeContactInput', document.getElementById('fatherOfficePhoneInput')?.value);
            set('guardianOfficeAddressInput', document.getElementById('fatherOfficeAddressInput')?.value);
            set('guardianRelationInput', 'Father');
        } else if (type === 'Mother') {
            set('guardianNameInput', document.getElementById('motherNameInput')?.value);
            set('guardianPhoneInput', document.getElementById('motherPhoneInput')?.value);
            set('guardianProfessionInput', document.getElementById('motherProfessionInput')?.value);
            set('guardianDesignationInput', document.getElementById('motherDesignationInput')?.value);
            set('guardianOfficeContactInput', document.getElementById('motherOfficePhoneInput')?.value);
            set('guardianOfficeAddressInput', document.getElementById('motherOfficeAddressInput')?.value);
            set('guardianRelationInput', 'Mother');
        } else {
            // Others — নিজের তথ্য নিজে দিতে হবে, তাই ফাঁকা করে দেওয়া হচ্ছে
            set('guardianNameInput', '');
            set('guardianPhoneInput', '');
            set('guardianProfessionInput', '');
            set('guardianDesignationInput', '');
            set('guardianOfficeContactInput', '');
            set('guardianOfficeAddressInput', '');
            set('guardianRelationInput', '');
        }
    }
    document.querySelectorAll('input[name="guardian_type"]').forEach(function (radio) {
        radio.addEventListener('change', autoFillGuardianFromType);
    });

    // "Section" ড্রপডাউনে শুধু নির্বাচিত Class-এর section-গুলোই দেখানো হয়
    function filterSections() {
        const classId = document.getElementById('classSelect').value;
        const select = document.getElementById('sectionSelect');
        Array.from(select.options).forEach(opt => {
            if (!opt.value) { opt.hidden = false; return; }
            opt.hidden = !classId || opt.dataset.class !== classId;
        });
        // আগে বাছা Section অন্য Class-এর হলে রিসেট
        const cur = select.options[select.selectedIndex];
        if (cur && cur.value && cur.hidden) select.value = '';
        toggleShiftSection();
    }

    // Class সিলেক্ট করার আগে Shift ও Section ড্রপডাউন বন্ধ (disabled) থাকবে, কোনো অপশন দেখাবে না
    function toggleShiftSection() {
        const hasClass = !!document.getElementById('classSelect').value;
        [['shiftSelect', 'Select Shift'], ['sectionSelect', 'Select Section']].forEach(function (pair) {
            const sel = document.getElementById(pair[0]);
            if (!sel) return;
            sel.disabled = !hasClass;
            if (!hasClass) sel.value = '';
            sel.options[0].textContent = hasClass ? pair[1] : 'Select Class first';
        });
    }
    filterSections();

    // "Group" ফিল্ড — শুধু সেই Class-এ দেখাবে যেখানে Group তৈরি করা আছে (যেমনঃ Nine/Ten এ
    // Science/Arts/Commerce); বাকি Class (Six/Seven ইত্যাদি) এ ফিল্ডটা লুকানো ও optional থাকে।
    function filterGroups() {
        const classId = document.getElementById('classSelect').value;
        const select = document.getElementById('groupSelect');
        const row = document.getElementById('groupFieldRow');
        let hasGroupForClass = false;

        Array.from(select.options).forEach(opt => {
            if (!opt.value) { opt.hidden = false; return; }
            const matches = classId && opt.dataset.class === classId;
            opt.hidden = !matches;
            if (matches) hasGroupForClass = true;
        });

        if (hasGroupForClass) {
            row.style.display = '';
            select.required = true;
            if (select.value && select.selectedOptions[0] && select.selectedOptions[0].hidden) select.value = '';
        } else {
            row.style.display = 'none';
            select.required = false;
            select.value = '';
        }
    }
    filterGroups();

    // "Same as Present Address" — চেক করলে Permanent Address অটো-ফিল হয়
    function copyPresentAddress() {
        const checkbox = document.getElementById('sameAsPresent');
        const permanent = document.getElementById('permanentAddress');
        if (checkbox.checked) {
            permanent.value = document.getElementById('presentAddress').value;
            permanent.readOnly = true;
        } else {
            // টিক তুলে দিলে অটো-কপি হওয়া Permanent Address মুছে যাবে, নতুন করে লিখতে হবে
            permanent.value = '';
            permanent.readOnly = false;
        }
    }
    // টিক দেওয়া অবস্থায় Present Address বদলালে Permanent Address-ও সাথে সাথে মিলে যাবে
    document.getElementById('presentAddress')?.addEventListener('input', function () {
        if (document.getElementById('sameAsPresent')?.checked) {
            document.getElementById('permanentAddress').value = this.value;
        }
    });

    <?php if ($view === 'add'): ?>
    // Session + Class + Section + Shift সিলেক্ট হলে AJAX দিয়ে পরবর্তী Student ID ও Roll No
    // অটো-প্রিভিউ করে বসিয়ে দেওয়া হয় (শুধু নতুন Student যোগ করার সময়, Edit করার সময় না)
    let stuIdManuallyEdited = false;
    let stuRollManuallyEdited = false;
    document.getElementById('studentIdInput').addEventListener('input', () => stuIdManuallyEdited = true);
    document.getElementById('rollNoInput').addEventListener('input', () => stuRollManuallyEdited = true);

    function stuAutoGenerateId() {
        const sessionId = document.getElementById('sessionSelect').value;
        const classId = document.getElementById('classSelect').value;
        const sectionId = document.getElementById('sectionSelect').value;
        const shiftId = document.getElementById('shiftSelect').value;
        const hint = document.getElementById('studentIdHint');

        if (!sessionId || !classId) { return; }

        const params = new URLSearchParams({ session_id: sessionId, class_id: classId, section_id: sectionId || '', shift_id: shiftId || '' });
        fetch('ajax/get_next_student_id.php?' + params.toString())
            .then(res => res.json())
            .then(data => {
                if (!data.success) { if (hint) { hint.textContent = data.message || ''; } return; }
                const idInput = document.getElementById('studentIdInput');
                const rollInput = document.getElementById('rollNoInput');
                if (!stuIdManuallyEdited && (!idInput.value || idInput.dataset.auto === '1')) {
                    idInput.value = data.student_id;
                    idInput.dataset.auto = '1';
                }
                if (!stuRollManuallyEdited && (!rollInput.value || rollInput.dataset.auto === '1') && sectionId && shiftId) {
                    rollInput.value = data.roll_no;
                    rollInput.dataset.auto = '1';
                }
                if (hint) { hint.textContent = 'প্রিভিউ — Save করার আগ পর্যন্ত চূড়ান্ত না, চাইলে বদলে দিতে পারেন।'; }
            })
            .catch(() => {});
    }
    <?php endif; ?>
</script>
<?php endif; ?>
