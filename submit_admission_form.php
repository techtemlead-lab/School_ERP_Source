<?php
/**
 * submit_admission_form.php
 * -----------------------------------------------------------
 * admission_form.php থেকে POST হয়ে আসা ডেটা ভ্যালিডেট করে সরাসরি students
 * টেবিলে status='Pending' নিয়ে সেভ করে (Admin পরে Approve/Active করবে),
 * Student ID + Roll No অটো-জেনারেট করে, sms_admission অন থাকলে SMS পাঠায়,
 * তারপর admission_slip.php তে (একটা র‍্যান্ডম টোকেন সহ) রিডাইরেক্ট করে।
 */

session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/sms.php';
require_once __DIR__ . '/config/branch_scope.php';
require_once __DIR__ . '/config/admission_fee_helpers.php';
af_ensure_tables($pdo);

// students টেবিলে branch_id কলাম নিশ্চিত করা (self-healing — student/_bootstrap.php-এও
// একই কলাম যোগ করা হয়, কিন্তু online admission ফর্ম সেই bootstrap দিয়ে যায় না)
branch_ensure_column($pdo, 'students');

// blood_group এ এখন 'Unknown' অপশনও থাকে (৭ অক্ষর) — পুরনো ইনস্টলেশনে কলাম
// VARCHAR(5) থাকলে সেটা কেটে যেতে পারে, তাই নিরাপদে VARCHAR(10) এ বাড়ানো হচ্ছে
$saf_bg_len = $pdo->query(
    "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'blood_group'"
)->fetchColumn();
if ($saf_bg_len !== false && (int) $saf_bg_len < 10) {
    $pdo->exec("ALTER TABLE students MODIFY COLUMN blood_group VARCHAR(10) NOT NULL");
}

function back_with_error(string $message, array $old = []): void
{
    $_SESSION['af_err'] = $message;
    $_SESSION['af_old'] = $old;
    header('Location: admission_form.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admission_form.php');
    exit();
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    back_with_error('দুঃখিত, ফর্মের মেয়াদ শেষ হয়ে গেছে। পেজ রিলোড করে আবার চেষ্টা করুন।');
}

$f = function (string $key, $default = '') { return trim($_POST[$key] ?? $default); };
$i = function (string $key) { $v = trim($_POST[$key] ?? ''); return $v !== '' ? (int) $v : 0; };

$branch_id  = $i('branch_id');
$shift_id   = $i('shift_id');
$session_id = $i('session_id');
$section_id = $i('section_id');
$class_id   = $i('class_id');

$name_en = $f('name_en');
$name_bn = $f('name_bn');
$dob = $f('dob') ?: null;
$birth_reg_no = $f('birth_reg_no');
$gender = $f('gender');
$blood_group = $f('blood_group');
$religion = $f('religion');
$nationality = $f('nationality', 'Bangladeshi');
$email = $f('email');
$mobile = $f('mobile');
$orphan = $f('orphan');
$working_student = $f('working_student');
$disability_status = $f('disability_status');

$prev_school_name = $f('prev_school_name');
$prev_class_id = $i('prev_class_id') ?: null;
$prev_section = $f('prev_section');
$prev_roll = $f('prev_roll');
$prev_result = $f('prev_result');

$present_address = $f('present_address');
$permanent_address = $f('permanent_address');

$father_name = $f('father_name');
$father_nid = $f('father_nid');
$father_profession = $f('father_profession');
$father_phone = $f('father_phone');
$mother_name = $f('mother_name');
$mother_nid = $f('mother_nid');
$mother_profession = $f('mother_profession');
$yearly_income = $f('yearly_income');
$guardian_name = $f('guardian_name');
$guardian_phone = $f('guardian_phone');
$landless = $f('landless');

$old = $_POST;
unset($old['csrf_token']);

// ------------------------------------------------------------
// Admission Fee (Session+Class ভিত্তিক) — 0 হলে Payment ধাপ স্কিপ, আগের মতোই সরাসরি জমা হবে
// ------------------------------------------------------------
$af_fee_amount = ($session_id && $class_id) ? af_class_fee($pdo, $session_id, $class_id) : 0.0;
$pay_method = $f('pay_method'); // 'Manual' | 'Auto'
$pay_sender_method = $f('pay_sender_method', 'bKash');
$pay_sender_number = $f('pay_sender_number');
$pay_trx_id = $f('pay_trx_id');

// ------------------------------------------------------------
// Validation (স্ক্রিনশটের লাল * চিহ্নিত ফিল্ডগুলো)
// ------------------------------------------------------------
$errors = [];
if (!$branch_id) $errors[] = 'Branch';
if (!$shift_id) $errors[] = 'Shift';
if (!$session_id) $errors[] = 'Session';
if (!$section_id) $errors[] = 'Section';
if (!$class_id) $errors[] = 'Class';
if ($name_en === '') $errors[] = 'Name (in English)';
if ($gender === '') $errors[] = 'Gender';
if ($blood_group === '') $errors[] = 'Blood Group';
if ($religion === '') $errors[] = 'Religion';
if ($nationality === '') $errors[] = 'Nationality';
if ($present_address === '') $errors[] = 'Present Address';
if ($permanent_address === '') $errors[] = 'Permanent Address';
if ($father_name === '') $errors[] = "Father's Name";
if ($mother_name === '') $errors[] = "Mother's Name";
if ($guardian_name === '') $errors[] = "Guardian's Name";
if (!preg_match('/^01[0-9]{9}$/', $guardian_phone)) $errors[] = "Guardian's Phone (সঠিক ১১ ডিজিটের নম্বর)";

// ---- Admission Fee > 0 হলে Payment method + প্রয়োজনীয় তথ্য বাধ্যতামূলক ----
if ($af_fee_amount > 0) {
    if ($pay_method !== 'Manual' && $pay_method !== 'Auto') {
        $errors[] = 'Payment Method (Manual/Online)';
    } elseif ($pay_method === 'Manual') {
        if ($pay_sender_number === '' || $pay_trx_id === '') {
            $errors[] = 'Payment Sender Number / Transaction ID';
        }
    }
}

if ($errors) {
    back_with_error('এই ফিল্ডগুলো সঠিকভাবে পূরণ করুন: ' . implode(', ', $errors), $old);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back_with_error('সঠিক ইমেইল দিন।', $old);
}

// ------------------------------------------------------------
// ছবি আপলোড হ্যান্ডলার (Photo/Father's Photo/Mother's Photo — সবই ঐচ্ছিক, jpg/png, max 300KB)
// ------------------------------------------------------------
function af_handle_upload(string $field, array $old): ?string
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        back_with_error('ছবি আপলোডে সমস্যা হয়েছে, আবার চেষ্টা করুন।', $old);
    }
    if ($file['size'] > 300 * 1024) {
        back_with_error('ছবির সাইজ সর্বোচ্চ 300 KB হতে হবে।', $old);
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    if (!isset($allowed_mimes[$ext])) {
        back_with_error('শুধু jpg/png ছবি আপলোড করা যাবে।', $old);
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== $allowed_mimes[$ext]) {
        back_with_error('ছবির প্রকৃত কনটেন্ট এক্সটেনশনের সাথে মিলছে না।', $old);
    }

    $upload_dir = tenant_upload_abs(__DIR__, 'students');
    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
    $random_name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $random_name)) {
        back_with_error('ছবি সেভ করা যায়নি, আবার চেষ্টা করুন।', $old);
    }
    return tenant_upload_rel('students') . '/' . $random_name;
}

$photo_path = af_handle_upload('photo', $old);
$father_photo_path = af_handle_upload('father_photo', $old);
$mother_photo_path = af_handle_upload('mother_photo', $old);

// ------------------------------------------------------------
// পুরনো ইনস্টলেশনে students.status ENUM('Active','Inactive') হতে পারে —
// 'Pending' রাখতে হলে নিরাপদে VARCHAR এ কনভার্ট করে দেওয়া হচ্ছে (ডেটা অক্ষত থাকে)
// ------------------------------------------------------------
$col_type = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'status'"
)->fetchColumn();
if ($col_type !== false && stripos((string) $col_type, 'pending') === false) {
    $pdo->exec("ALTER TABLE students MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Active'");
}
// একজন শিক্ষার্থী নিজের ভর্তির স্লিপ যাচাই করতে পারে এমন একটা র‍্যান্ডম টোকেন কলাম, না থাকলে যোগ করা হচ্ছে
$tok_chk = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'admission_slip_token'"
)->fetchColumn();
if (!$tok_chk) {
    $pdo->exec("ALTER TABLE students ADD COLUMN admission_slip_token VARCHAR(64) NULL AFTER admission_id");
}

// ------------------------------------------------------------
// Student ID অটো-জেনারেট — {Session শেষ ২ ডিজিট}{Class Code}{৩ ডিজিট সিরিয়াল}
// ------------------------------------------------------------
$session_name = $pdo->prepare("SELECT session_name FROM academic_sessions WHERE id = ?");
$session_name->execute([$session_id]);
$session_name = $session_name->fetchColumn() ?: '';
$yy = substr(preg_replace('/[^0-9]/', '', $session_name), -2) ?: date('y');

$class_row = $pdo->prepare("SELECT class_code FROM academic_classes WHERE id = ?");
$class_row->execute([$class_id]);
$class_code = $class_row->fetchColumn() ?: '00';
$class_code = str_pad(substr($class_code, 0, 2), 2, '0', STR_PAD_LEFT);

$prefix = $yy . $class_code;
$last = $pdo->prepare("SELECT student_id FROM students WHERE student_id LIKE ? ORDER BY student_id DESC LIMIT 1");
$last->execute([$prefix . '%']);
$last_id = $last->fetchColumn();
$serial = 1;
if ($last_id && strlen($last_id) >= 3) {
    $serial = ((int) substr($last_id, -3)) + 1;
}
$student_id = $prefix . str_pad((string) $serial, 3, '0', STR_PAD_LEFT);

// Roll No অটো-জেনারেট — কিন্তু শুধু Admission Type "General" হলেই এখনই বসানো হয়।
// "Admission Test" / "Lottery" মোডে Roll No আসবে মেধাক্রম/ড্র থেকে (admission_publish.php থেকে
// Result Publish করার সময় বসে) — আবেদনের সময় খালি রাখা হচ্ছে যাতে ভুল Roll না বসে।
$adm_type_row = $pdo->query("SELECT admission_type FROM institute_settings WHERE id = 1")->fetch();
$adm_type = $adm_type_row['admission_type'] ?? 'General';

$roll_no = '';
if ($adm_type === 'General') {
    $roll_stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM students WHERE session_id = ? AND class_id = ? AND section_id = ? AND shift_id = ?"
    );
    $roll_stmt->execute([$session_id, $class_id, $section_id, $shift_id]);
    $roll_no = (string) ($roll_stmt->fetchColumn() + 1);
}

$slip_token = bin2hex(random_bytes(20));

// ------------------------------------------------------------
// সেভ
// ------------------------------------------------------------
try {
    $columns = [
        'session_id' => $session_id, 'shift_id' => $shift_id, 'class_id' => $class_id, 'section_id' => $section_id,
        'student_id' => $student_id, 'roll_no' => $roll_no, 'attendance_sms' => 1,
        'name_en' => $name_en, 'name_bn' => $name_bn ?: null, 'dob' => $dob, 'birth_reg_no' => $birth_reg_no ?: null,
        'gender' => $gender, 'orphan' => $orphan ?: null, 'disability_status' => $disability_status ?: null,
        'blood_group' => $blood_group, 'religion' => $religion, 'nationality' => $nationality,
        'working_student' => $working_student ?: null, 'photo_path' => $photo_path,
        'mobile' => $mobile ?: null, 'email' => $email ?: null,
        'present_address' => $present_address, 'permanent_address' => $permanent_address,
        'prev_school_name' => $prev_school_name ?: null, 'prev_class_id' => $prev_class_id,
        'prev_section' => $prev_section ?: null, 'prev_roll' => $prev_roll ?: null, 'prev_result' => $prev_result ?: null,
        'father_name' => $father_name, 'father_phone' => $father_phone ?: null, 'father_nid' => $father_nid ?: null,
        'father_profession' => $father_profession ?: null, 'father_photo_path' => $father_photo_path,
        'mother_name' => $mother_name, 'mother_nid' => $mother_nid ?: null, 'mother_profession' => $mother_profession ?: null,
        'mother_photo_path' => $mother_photo_path,
        'yearly_income' => $yearly_income ?: null, 'landless' => $landless ?: null,
        'guardian_type' => 'Father', 'guardian_name' => $guardian_name, 'guardian_phone' => $guardian_phone,
        'status' => 'Pending', 'admission_slip_token' => $slip_token,
        'branch_id' => $branch_id,
    ];
    $cols_sql = implode(', ', array_keys($columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $pdo->prepare("INSERT INTO students ($cols_sql) VALUES ($placeholders)")->execute(array_values($columns));
    $new_id = (int) $pdo->lastInsertId();

    // ---- Admission SMS (sms_settings.sms_admission অন থাকলে) ----
    $sms_settings = $pdo->query("SELECT sms_admission, content_admission FROM sms_settings WHERE id = 1")->fetch();
    if ($sms_settings && (int) ($sms_settings['sms_admission'] ?? 0) === 1) {
        $template = $sms_settings['content_admission'] ?: 'Dear guardian, {FIRST_NAME} {LAST_NAME} has been admitted to {SCHOOL_NAME} with Student ID {STUDENT_ID}. Roll: {ROLL}, Class: {CLASS}.';
        $school = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetch()['institute_name'] ?? '';
        $class_name_row = $pdo->prepare("SELECT class_name FROM academic_classes WHERE id = ?");
        $class_name_row->execute([$class_id]);
        $class_name = $class_name_row->fetchColumn() ?: '';

        $name_parts = explode(' ', trim($name_en), 2);
        $message = sms_apply_template($template, [
            'FIRST_NAME' => $name_parts[0] ?? '', 'LAST_NAME' => $name_parts[1] ?? '',
            'SCHOOL_NAME' => $school, 'STUDENT_ID' => $student_id, 'ROLL' => $roll_no !== '' ? $roll_no : 'পরে জানানো হবে', 'CLASS' => $class_name,
        ]);
        $result = send_sms($pdo, $guardian_phone, $message);

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sms_logs (
                id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NULL, phone VARCHAR(20) NOT NULL,
                purpose VARCHAR(50) NOT NULL DEFAULT 'admission', message TEXT NULL,
                status ENUM('Sent','Failed') NOT NULL DEFAULT 'Failed', response TEXT NULL,
                sent_by VARCHAR(100) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->prepare(
            "INSERT INTO sms_logs (student_id, phone, purpose, message, status, response, sent_by) VALUES (?, ?, 'admission', ?, ?, ?, 'Online Admission')"
        )->execute([$new_id, $guardian_phone, $message, $result['success'] ? 'Sent' : 'Failed', $result['response']]);
    }

    // ------------------------------------------------------------
    // Admission Fee > 0 হলে Payment claim রেকর্ড করে সেই অনুযায়ী পরের পেজে পাঠানো হয়।
    // ফি না থাকলে (0/খালি) — আগের মতোই সরাসরি Admission Slip পেজে যাবে।
    // ------------------------------------------------------------
    if ($af_fee_amount > 0 && $pay_method === 'Manual') {
        $method_label = 'Manual-' . ($pay_sender_method === 'Nagad' ? 'Nagad' : 'bKash');
        $pdo->prepare(
            "INSERT INTO admission_fee_claims (student_pk, session_id, class_id, amount, method, sender_number, trx_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')"
        )->execute([$new_id, $session_id, $class_id, $af_fee_amount, $method_label, $pay_sender_number, $pay_trx_id]);

        header('Location: admission_slip.php?token=' . urlencode($slip_token) . '&payment=pending');
        exit();
    }

    if ($af_fee_amount > 0 && $pay_method === 'Auto') {
        $pdo->prepare(
            "INSERT INTO admission_fee_claims (student_pk, session_id, class_id, amount, method, status)
             VALUES (?, ?, ?, ?, 'AutoGateway', 'Pending')"
        )->execute([$new_id, $session_id, $class_id, $af_fee_amount]);

        header('Location: admission_payment_init.php?student_pk=' . $new_id . '&token=' . urlencode($slip_token));
        exit();
    }

    header('Location: admission_slip.php?token=' . urlencode($slip_token));
    exit();
} catch (PDOException $e) {
    error_log('Public admission insert failed: ' . $e->getMessage());
    back_with_error('দুঃখিত, আবেদন জমা দেওয়া যায়নি। কিছুক্ষণ পর আবার চেষ্টা করুন।', $old);
}
