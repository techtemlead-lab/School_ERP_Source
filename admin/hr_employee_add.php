<?php
// admin/hr_employee_add.php - Human Resource > HR Module > Add Employee (স্ক্রিনশট অনুযায়ী)
$emp_err = $_SESSION['emp_err'] ?? '';
$emp_msg = $_SESSION['emp_msg'] ?? '';
unset($_SESSION['emp_err'], $_SESSION['emp_msg']);

// -----------------------------------------------------------
// প্রয়োজনীয় টেবিলগুলো না থাকলে অটো তৈরি
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS employees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('Teachers','Staffs','Board Of Directors','Academic Committee') NOT NULL DEFAULT 'Teachers',
        employee_type_id INT NULL,
        designation_id INT NULL,
        shift_id INT NULL,
        grade_id INT NULL,
        `rank` INT NULL,
        employee_id VARCHAR(50) NOT NULL UNIQUE,
        device_serial VARCHAR(100) NULL,
        tracking_id VARCHAR(100) NULL,
        rfid_card_no VARCHAR(100) NULL,
        joining_date DATE NULL,
        bank_account_no VARCHAR(100) NULL,
        previous_institute VARCHAR(255) NULL,
        previous_designation VARCHAR(255) NULL,
        name VARCHAR(150) NOT NULL,
        mobile VARCHAR(20) NOT NULL,
        email VARCHAR(150) NULL,
        dob DATE NULL,
        national_id VARCHAR(50) NULL,
        photo_path VARCHAR(255) NULL,
        fathers_name VARCHAR(150) NULL,
        mothers_name VARCHAR(150) NULL,
        marital_status VARCHAR(20) NULL,
        religion VARCHAR(50) NULL,
        blood_group VARCHAR(5) NULL,
        gender VARCHAR(10) NULL,
        facebook_link VARCHAR(255) NULL,
        major_subject VARCHAR(150) NULL,
        extra_qualification TEXT NULL,
        present_address TEXT NULL,
        permanent_address TEXT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        is_current TINYINT(1) NOT NULL DEFAULT 1,
        branch_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
// Self-healing: employees টেবিল আগে থেকেই থাকলে (পুরনো tenant) CREATE TABLE IF NOT
// EXISTS নতুন কলাম (tracking_id/device_serial/rfid_card_no) যোগ করবে না — সেজন্য
// আলাদাভাবে চেক করে মিসিং কলাম থাকলে ALTER করে দেওয়া হচ্ছে, নাহলে Finger ID/Device
// Serial ফিল্ডে ডেটা দিলেও চুপচাপ সেভ না হয়ে হারিয়ে যাবে (device_settings.php-এর
// attendance matching-এর জন্যও এই কলামগুলো লাগে)।
foreach ([
    'tracking_id' => "VARCHAR(100) NULL",
    'device_serial' => "VARCHAR(100) NULL",
    'rfid_card_no' => "VARCHAR(100) NULL",
    'section_level' => "VARCHAR(10) NULL", // Teacher এর জন্য: 'School' অথবা 'College' (ওয়েবসাইটে সেকশন অনুযায়ী দেখানোর জন্য)
] as $emp_col => $emp_def) {
    $has = $pdo->query("SHOW COLUMNS FROM employees LIKE '$emp_col'")->fetch();
    if (!$has) { try { $pdo->exec("ALTER TABLE employees ADD COLUMN `$emp_col` $emp_def"); } catch (Throwable $e) {} }
}
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS employee_educations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        institute VARCHAR(255) NULL,
        degree VARCHAR(150) NULL,
        country VARCHAR(100) NULL,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
// Teacher-এর Class Teacher (Class + Section) ও Subject এসাইনমেন্ট
// teacher_subject_assignments — result_assign_subject_teacher.php এর সাথে একই টেবিল/স্ট্রাকচার (মার্কস এন্ট্রি অ্যাক্সেস ওখান থেকেই চলে)
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
// section_id = 0 মানে নির্দিষ্ট সেকশন নেই (পুরো Class)। একটা Class+Section-এ একজনই Class Teacher
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS class_teachers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        class_id INT NOT NULL,
        section_id INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_class_section (class_id, section_id),
        KEY idx_employee (employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
// Grade — এখনো আলাদা কোনো ম্যানেজমেন্ট পেজ নেই, তাই একবার তৈরি হলে কয়েকটা ডিফল্ট Grade বসিয়ে দেওয়া হচ্ছে
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS employee_grades (
        id INT AUTO_INCREMENT PRIMARY KEY,
        grade_name VARCHAR(100) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
if ((int) $pdo->query("SELECT COUNT(*) FROM employee_grades")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO employee_grades (grade_name) VALUES ('Grade 1'), ('Grade 2'), ('Grade 3'), ('Grade 4'), ('Grade 5')");
}

// -----------------------------------------------------------
// ছবি আপলোড হেল্পার (student_admission.php-এর প্যাটার্ন অনুসরণ করে)
// -----------------------------------------------------------
function emp_handle_upload(string $field): array
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
    $upload_dir = tenant_upload_abs(__DIR__ . '/..', 'employees');
    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
    $random_name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $random_name)) {
        return ['ok' => false, 'path' => null, 'error' => 'ছবি সেভ করা যায়নি।'];
    }
    return ['ok' => true, 'path' => tenant_upload_rel('employees') . '/' . $random_name, 'error' => null];
}

// -----------------------------------------------------------
// POST Handler (Create / Update)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_employee'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['emp_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=hr_employee_add');
        exit();
    }

    $f = fn($k) => trim($_POST[$k] ?? '');
    $id                   = (int) ($_POST['id'] ?? 0);
    $type                 = $f('type');
    $employee_type_id     = (int) $f('employee_type_id');
    $designation_id       = (int) $f('designation_id');
    $shift_id             = (int) $f('shift_id');
    $grade_id             = (int) $f('grade_id');
    $rank                 = (int) $f('rank');
    $employee_id          = $f('employee_id');
    $device_serial        = $f('device_serial');
    $tracking_id          = $f('tracking_id');
    $rfid_card_no         = $f('rfid_card_no');
    $joining_date         = $f('joining_date');
    $bank_account_no      = $f('bank_account_no');
    $previous_institute   = $f('previous_institute');
    $previous_designation = $f('previous_designation');
    $name                 = $f('name');
    $mobile               = $f('mobile');
    $email                = $f('email');
    $dob                  = $f('dob');
    $national_id          = $f('national_id');
    $fathers_name         = $f('fathers_name');
    $mothers_name         = $f('mothers_name');
    $marital_status       = $f('marital_status');
    $religion             = $f('religion');
    $blood_group          = $f('blood_group');
    $gender               = $f('gender');
    $facebook_link        = $f('facebook_link');
    $major_subject        = $f('major_subject');
    $extra_qualification  = $f('extra_qualification');
    $present_address      = $f('present_address');
    $permanent_address    = $f('permanent_address');
    $status                = $f('status') ?: 'Active';
    $section_level        = $f('section_level');

    $errors = [];
    if (!in_array($type, ['Teachers', 'Staffs', 'Board Of Directors', 'Academic Committee'], true)) $errors[] = 'Type';
    // Teacher/Staff হলে Section (School / College / Both) বাধ্যতামূলক — শুধু ওয়েবসাইটে কে কোন শাখার সেটা দেখানোর জন্য; অন্য টাইপে সেভ হবে না
    $has_section = in_array($type, ['Teachers', 'Staffs'], true);
    if ($has_section && !in_array($section_level, ['School', 'College', 'Both'], true)) $errors[] = 'Section (School / College / Both)';
    if (!$has_section) $section_level = '';
    if ($employee_type_id <= 0) $errors[] = 'Employee Type';
    if ($designation_id <= 0) $errors[] = 'Designation';
    if ($shift_id <= 0) $errors[] = 'Working Shift';
    if ($grade_id <= 0) $errors[] = 'Grade';
    if ($rank <= 0) $errors[] = 'Rank';
    if ($employee_id === '') $errors[] = 'Employee ID';
    if ($name === '') $errors[] = 'Name';
    if ($mobile === '') $errors[] = 'Mobile';
    if ($dob === '') $errors[] = 'Date Of Birth';
    if ($fathers_name === '') $errors[] = "Father's Name";
    if ($mothers_name === '') $errors[] = "Mother's Name";
    if ($marital_status === '') $errors[] = 'Marital Status';
    if ($religion === '') $errors[] = 'Religion';
    if ($blood_group === '') $errors[] = 'Blood Group';
    if ($gender === '') $errors[] = 'Gender';
    if ($present_address === '') $errors[] = 'Present Address';
    if ($permanent_address === '') $errors[] = 'Permanent Address';

    $photo = emp_handle_upload('photo');
    if (!$photo['ok']) { $errors[] = $photo['error']; }

    if ($errors) {
        $_SESSION['emp_err'] = 'নিচের ফিল্ডগুলো ঠিক করুন: ' . implode(', ', $errors);
    } else {
        $chk = $pdo->prepare("SELECT id FROM employees WHERE employee_id = ? AND id != ?");
        $chk->execute([$employee_id, $id]);
        if ($chk->fetch()) {
            $_SESSION['emp_err'] = 'এই Employee ID ইতিমধ্যে ব্যবহার হয়েছে, অন্য একটা দিন।';
        } else {
            $cols = [
                'type' => $type, 'section_level' => $section_level ?: null, 'employee_type_id' => $employee_type_id ?: null, 'designation_id' => $designation_id ?: null,
                'shift_id' => $shift_id ?: null, 'grade_id' => $grade_id ?: null, 'rank' => $rank, 'employee_id' => $employee_id,
                'device_serial' => $device_serial ?: null, 'tracking_id' => $tracking_id ?: null, 'rfid_card_no' => $rfid_card_no ?: null,
                'joining_date' => $joining_date ?: null, 'bank_account_no' => $bank_account_no ?: null,
                'previous_institute' => $previous_institute ?: null, 'previous_designation' => $previous_designation ?: null,
                'name' => $name, 'mobile' => $mobile, 'email' => $email ?: null, 'dob' => $dob ?: null, 'national_id' => $national_id ?: null,
                'fathers_name' => $fathers_name, 'mothers_name' => $mothers_name, 'marital_status' => $marital_status,
                'religion' => $religion, 'blood_group' => $blood_group, 'gender' => $gender, 'facebook_link' => $facebook_link ?: null,
                'major_subject' => $major_subject ?: null, 'extra_qualification' => $extra_qualification ?: null,
                'present_address' => $present_address, 'permanent_address' => $permanent_address, 'status' => $status,
            ];
            if ($photo['path']) { $cols['photo_path'] = $photo['path']; }
            if ($id === 0) { $cols['branch_id'] = branch_id_for_insert(); } // নতুন employee লগইন করা branch-এই সেভ হবে

            if ($id > 0) {
                $set_sql = implode(', ', array_map(fn($k) => "$k = ?", array_keys($cols)));
                $stmt = $pdo->prepare("UPDATE employees SET $set_sql WHERE id = ?");
                $stmt->execute([...array_values($cols), $id]);
                $emp_id_final = $id;
                $_SESSION['emp_msg'] = 'Employee তথ্য আপডেট করা হয়েছে।';
                sec_log_activity($pdo, 'HR', 'Update', "Employee তথ্য আপডেট করা হয়েছে: {$name} ({$employee_id})");
                $pdo->prepare("DELETE FROM employee_educations WHERE employee_id = ?")->execute([$id]);
            } else {
                $col_names = implode(', ', array_keys($cols));
                $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                $stmt = $pdo->prepare("INSERT INTO employees ($col_names) VALUES ($placeholders)");
                $stmt->execute(array_values($cols));
                $emp_id_final = (int) $pdo->lastInsertId();
                $_SESSION['emp_msg'] = 'নতুন Employee যোগ করা হয়েছে।';
                sec_log_activity($pdo, 'HR', 'Create', "নতুন Employee যোগ করা হয়েছে: {$name} ({$employee_id})");
            }

            // Class Teacher + Subject এসাইনমেন্ট (শুধু Teachers; অন্য টাইপে গেলে আগের এসাইনমেন্ট মুছে যাবে)
            $pdo->prepare("DELETE FROM class_teachers WHERE employee_id = ?")->execute([$emp_id_final]);
            $pdo->prepare("DELETE FROM teacher_subject_assignments WHERE employee_id = ?")->execute([$emp_id_final]);
            if ($type === 'Teachers') {
                $ct_classes  = (array) ($_POST['ct_class'] ?? []);
                $ct_sections = (array) ($_POST['ct_section'] ?? []);
                $ct_del = $pdo->prepare("DELETE FROM class_teachers WHERE class_id = ? AND section_id = ?");
                $ct_ins = $pdo->prepare("INSERT INTO class_teachers (employee_id, class_id, section_id) VALUES (?, ?, ?)");
                foreach ($ct_classes as $i => $cid) {
                    $cid = (int) $cid; $sid = (int) ($ct_sections[$i] ?? 0);
                    if ($cid <= 0) continue;
                    $ct_del->execute([$cid, $sid]); // ঐ Class+Section-এ আগের Class Teacher থাকলে বদলে যাবে
                    $ct_ins->execute([$emp_id_final, $cid, $sid]);
                }
                $sa_classes = (array) ($_POST['sa_class'] ?? []);
                $sa_subjects = (array) ($_POST['sa_subjects'] ?? []);
                $sa_ins = $pdo->prepare("INSERT IGNORE INTO teacher_subject_assignments (employee_id, class_id, subject_id) VALUES (?, ?, ?)");
                foreach ($sa_classes as $i => $cid) {
                    $cid = (int) $cid;
                    if ($cid <= 0) continue;
                    foreach (array_unique(array_map('intval', (array) ($sa_subjects[$i] ?? []))) as $sub_id) {
                        if ($sub_id > 0) $sa_ins->execute([$emp_id_final, $cid, $sub_id]);
                    }
                }
            }

            // Educational Qualification (রিপিটেবল রো)
            $institutes = $_POST['institute'] ?? [];
            $degrees    = $_POST['degree'] ?? [];
            $countries  = $_POST['country'] ?? [];
            $edu_stmt = $pdo->prepare("INSERT INTO employee_educations (employee_id, institute, degree, country) VALUES (?, ?, ?, ?)");
            for ($i = 0; $i < count($institutes); $i++) {
                $inst = trim($institutes[$i] ?? '');
                $deg  = trim($degrees[$i] ?? '');
                $ctry = trim($countries[$i] ?? '');
                if ($inst !== '' || $deg !== '' || $ctry !== '') {
                    $edu_stmt->execute([$emp_id_final, $inst ?: null, $deg ?: null, $ctry ?: null]);
                }
            }

            header('Location: index.php?page=hr_employee_add');
            exit();
        }
    }
    header('Location: index.php?page=hr_employee_add' . ($id > 0 ? '&id=' . $id : ''));
    exit();
}

// -----------------------------------------------------------
// ফর্মের জন্য ডেটা রেডি করা
// -----------------------------------------------------------
// ড্রপডাউনের টেবিলগুলো (Employee Type / Designation / Working Shift) আলাদা পেজ প্রথম ভিজিট করলে তবেই তৈরি হতো —
// সেই পেজগুলো না খুলে সরাসরি Add Employee খুললে "Base table or view not found" ফাটাল এরর আসত।
// তাই এখানেও একই স্ট্রাকচারে IF NOT EXISTS দিয়ে তৈরি করে নেওয়া হচ্ছে (আগে থেকে থাকলে কিছুই বদলায় না)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS employee_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('Full-time','Part-time') NOT NULL DEFAULT 'Full-time',
        name VARCHAR(100) NOT NULL,
        deduct_salary_on_absent TINYINT(1) NOT NULL DEFAULT 0,
        for_absent_days INT NOT NULL DEFAULT 0,
        salary_deduct_days INT NOT NULL DEFAULT 0,
        has_overtime TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS designations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('Teachers','Staffs','Board Of Directors','Academic Committee') NOT NULL DEFAULT 'Teachers',
        title VARCHAR(150) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS employee_shifts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        green_limit INT NOT NULL DEFAULT 0,
        orange_limit INT NOT NULL DEFAULT 0,
        red_limit INT NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$employee_types = $pdo->query("SELECT id, name FROM employee_types WHERE status = 'Active' ORDER BY name ASC")->fetchAll();
$designations   = $pdo->query("SELECT id, title, type FROM designations WHERE status = 'Active' ORDER BY title ASC")->fetchAll();
$shifts         = $pdo->query("SELECT id, name FROM employee_shifts WHERE status = 'Active' ORDER BY name ASC")->fetchAll();
$grades         = $pdo->query("SELECT id, grade_name FROM employee_grades WHERE status = 'Active' ORDER BY grade_name ASC")->fetchAll();

$edit_id = (int) ($_GET['id'] ?? 0);
$edit_row = null;
$edu_rows = [];
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_row = $stmt->fetch();
    if ($edit_row) {
        $estmt = $pdo->prepare("SELECT institute, degree, country FROM employee_educations WHERE employee_id = ?");
        $estmt->execute([$edit_id]);
        $edu_rows = $estmt->fetchAll();
    }
}

$last_rank = (int) ($pdo->query("SELECT MAX(`rank`) FROM employees")->fetchColumn() ?? 0);
// Rank ডুপ্লিকেট সতর্কতার জন্য: rank → নাম (এডিটে নিজেকে বাদ দিয়ে)
$rank_taken = [];
foreach ($pdo->query("SELECT id, `rank`, name FROM employees WHERE is_current = 1 AND `rank` IS NOT NULL")->fetchAll() as $rk) {
    if ((int) $rk['id'] === $edit_id) continue;
    $rank_taken[(int) $rk['rank']] = $rk['name'];
}

// Academic Assignment (Class Teacher / Subject) এর ড্রপডাউন ডেটা
$asg_classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$asg_sec_where = ["status = 'Active'"]; $asg_sec_params = [];
branch_apply_filter($asg_sec_where, $asg_sec_params, 'branch_id');
$asg_sections = [];
try {
    $sec_stmt = $pdo->prepare("SELECT id, class_id, section_name FROM academic_sections WHERE " . implode(' AND ', $asg_sec_where) . " ORDER BY section_name ASC");
    $sec_stmt->execute($asg_sec_params);
    $asg_sec_rows = $sec_stmt->fetchAll();
} catch (Throwable $e) { $asg_sec_rows = []; } // পুরনো tenant-এ branch_id কলাম না থাকলে
foreach ($asg_sec_rows as $sc) { $asg_sections[(int) $sc['class_id']][] = ['id' => (int) $sc['id'], 'n' => $sc['section_name']]; }
$asg_subjects = [];
foreach ($pdo->query("SELECT id, class_id, subject_name FROM academic_subjects WHERE status = 'Active' ORDER BY subject_name ASC")->fetchAll() as $sb) {
    $asg_subjects[(int) $sb['class_id']][] = ['id' => (int) $sb['id'], 'n' => $sb['subject_name']];
}
// এই Teacher-এর বর্তমান এসাইনমেন্ট (এডিটে প্রি-ফিল) + কোন Class/Section-এ কে Class Teacher (বদলে ফেলার সতর্কতার জন্য)
$my_ct = []; $my_sa = [];
$class_teacher_of = [];
foreach ($pdo->query("SELECT ct.employee_id, ct.class_id, ct.section_id, e.name FROM class_teachers ct JOIN employees e ON e.id = ct.employee_id")->fetchAll() as $ct) {
    $class_teacher_of[$ct['class_id'] . '_' . $ct['section_id']] = ['emp' => (int) $ct['employee_id'], 'name' => $ct['name']];
    if ($edit_id > 0 && (int) $ct['employee_id'] === $edit_id) $my_ct[] = ['c' => (int) $ct['class_id'], 's' => (int) $ct['section_id']];
}
if ($edit_id > 0) {
    $sa_stmt = $pdo->prepare("SELECT class_id, subject_id FROM teacher_subject_assignments WHERE employee_id = ?");
    $sa_stmt->execute([$edit_id]);
    foreach ($sa_stmt->fetchAll() as $sa) { $my_sa[(int) $sa['class_id']][] = (int) $sa['subject_id']; }
}

// Employee ID সাজেশন — বছর + ৪ ডিজিট সিরিয়াল (এডিটেবল, চাইলে বদলানো যাবে)
$suggest_employee_id = '';
if (!$edit_row) {
    $year = date('Y');
    $like = $year . '%';
    $stmt = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_id LIKE ? ORDER BY employee_id DESC LIMIT 1");
    $stmt->execute([$like]);
    $last = $stmt->fetchColumn();
    $next_seq = $last ? ((int) substr($last, 4)) + 1 : 1;
    $suggest_employee_id = $year . str_pad((string) $next_seq, 4, '0', STR_PAD_LEFT);
}

$d = $edit_row ?: [
    'id' => 0, 'type' => 'Teachers', 'section_level' => '', 'employee_type_id' => '', 'designation_id' => '', 'shift_id' => '', 'grade_id' => '',
    'rank' => '', 'employee_id' => $suggest_employee_id, 'device_serial' => '', 'tracking_id' => '', 'rfid_card_no' => '',
    'joining_date' => '', 'bank_account_no' => '', 'previous_institute' => '', 'previous_designation' => '',
    'name' => '', 'mobile' => '', 'email' => '', 'dob' => '', 'national_id' => '', 'photo_path' => '',
    'fathers_name' => '', 'mothers_name' => '', 'marital_status' => '', 'religion' => '', 'blood_group' => '',
    'gender' => '', 'facebook_link' => '', 'major_subject' => '', 'extra_qualification' => '',
    'present_address' => '', 'permanent_address' => '', 'status' => 'Active',
];

function ev($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$marital_statuses = ['Single', 'Married', 'Divorced', 'Widowed'];
$religions = ['Islam', 'Hinduism', 'Christianity', 'Buddhism', 'Other'];
$blood_groups = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];
$genders = ['Male', 'Female', 'Other'];
$employee_form_types = ['Teachers', 'Staffs', 'Board Of Directors', 'Academic Committee'];
?>
<div class="card-custom">
    <?php if ($emp_err): ?><div class="alert alert-danger py-2"><?php echo ev($emp_err); ?></div><?php endif; ?>
    <?php if ($emp_msg): ?><div class="alert alert-success py-2"><?php echo ev($emp_msg); ?></div><?php endif; ?>

    <a href="index.php?page=hr_employee_list" class="bg-warning bg-opacity-75 p-2 mb-4 d-inline-block text-decoration-none" style="width:40px;border-radius:4px;" title="Manage Employees">
        <i class="fa-solid fa-file-lines text-white"></i>
    </a>

    <form method="POST" action="index.php?page=hr_employee_add<?php echo $edit_id ? '&id=' . $edit_id : ''; ?>" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

        <!-- ===================== Professional Information ===================== -->
        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3"><i class="fa-solid fa-circle-info"></i> Professional Information</h6>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Type <span class="text-danger">*</span></label>
            <div class="col-sm-8 d-flex flex-wrap gap-4 align-items-center">
                <?php foreach ($employee_form_types as $t): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="type" id="etype_<?php echo ev(str_replace(' ', '_', $t)); ?>" value="<?php echo ev($t); ?>" <?php echo $d['type'] === $t ? 'checked' : ''; ?> required>
                        <label class="form-check-label" for="etype_<?php echo ev(str_replace(' ', '_', $t)); ?>"><?php echo ev($t); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="row mb-3 align-items-center" id="empSectionRow">
            <label class="col-sm-3 col-form-label fw-bold text-end">Section <span class="text-danger">*</span></label>
            <div class="col-sm-8 d-flex flex-wrap gap-4 align-items-center">
                <?php foreach (['School' => 'School Section', 'College' => 'College Section', 'Both' => 'Both (School + College)'] as $sv => $sl): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="section_level" id="esec_<?php echo $sv; ?>" value="<?php echo $sv; ?>" <?php echo ($d['section_level'] ?? '') === $sv ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="esec_<?php echo $sv; ?>"><?php echo $sl; ?></label>
                    </div>
                <?php endforeach; ?>
                <small class="text-muted">শুধু ওয়েবসাইটে কে কোন শাখার (School/College) তা দেখানোর জন্য</small>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Employee Type <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="employee_type_id" class="form-select" required>
                    <option value="">Select Employee Type</option>
                    <?php foreach ($employee_types as $et): ?>
                        <option value="<?php echo (int) $et['id']; ?>" <?php echo (int) $d['employee_type_id'] === (int) $et['id'] ? 'selected' : ''; ?>><?php echo ev($et['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$employee_types): ?><small class="text-danger">প্রথমে "Employee Type" পেজ থেকে অন্তত একটা টাইপ যোগ করুন।</small><?php endif; ?>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Designation <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="designation_id" class="form-select" required>
                    <option value="">Select Designation</option>
                    <?php foreach ($designations as $dg): ?>
                        <option value="<?php echo (int) $dg['id']; ?>" <?php echo (int) $d['designation_id'] === (int) $dg['id'] ? 'selected' : ''; ?>><?php echo ev($dg['title']); ?> (<?php echo ev($dg['type']); ?>)</option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$designations): ?><small class="text-danger">প্রথমে "All Designation" পেজ থেকে অন্তত একটা Designation যোগ করুন।</small><?php endif; ?>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Working Shift <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="shift_id" class="form-select" required>
                    <option value="">Select Shift Name</option>
                    <?php foreach ($shifts as $sh): ?>
                        <option value="<?php echo (int) $sh['id']; ?>" <?php echo (int) $d['shift_id'] === (int) $sh['id'] ? 'selected' : ''; ?>><?php echo ev($sh['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$shifts): ?><small class="text-danger">প্রথমে "Working Shift" পেজ থেকে অন্তত একটা শিফট যোগ করুন।</small><?php endif; ?>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Grade <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="grade_id" class="form-select" required>
                    <option value="">Select Grade</option>
                    <?php foreach ($grades as $g): ?>
                        <option value="<?php echo (int) $g['id']; ?>" <?php echo (int) $d['grade_id'] === (int) $g['id'] ? 'selected' : ''; ?>><?php echo ev($g['grade_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-1 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Rank <span class="text-danger">*</span></label>
            <div class="col-sm-6"><input type="number" name="rank" id="empRank" min="1" class="form-control" value="<?php echo ev($d['rank'] !== '' && $d['rank'] !== null ? $d['rank'] : ($last_rank + 1)); ?>" required></div>
        </div>
        <div class="row mb-3">
            <div class="col-sm-6 offset-sm-3 d-flex justify-content-between">
                <small class="text-danger" id="rankDupMsg"></small>
                <small class="text-muted">ওয়েবসাইটে Teacher/Staff ছোট Rank আগে দেখায় · Last Rank No : <?php echo $last_rank; ?></small>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Employee ID <span class="text-danger">*</span></label>
            <div class="col-sm-6"><input type="text" name="employee_id" class="form-control" value="<?php echo ev($d['employee_id']); ?>" required></div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Device Serial / MAC</label>
            <div class="col-sm-6"><input type="text" name="device_serial" class="form-control" value="<?php echo ev($d['device_serial']); ?>"></div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Tracking ID</label>
            <div class="col-sm-6"><input type="text" name="tracking_id" class="form-control" value="<?php echo ev($d['tracking_id']); ?>" placeholder="Last Finger ID"></div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">RFID Card No</label>
            <div class="col-sm-6"><input type="text" name="rfid_card_no" class="form-control" value="<?php echo ev($d['rfid_card_no']); ?>" placeholder="Not Depends on Device ID"></div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Joining Date</label>
            <div class="col-sm-6"><input type="date" name="joining_date" class="form-control" value="<?php echo ev($d['joining_date']); ?>"></div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Bank Account No</label>
            <div class="col-sm-6"><input type="text" name="bank_account_no" class="form-control" value="<?php echo ev($d['bank_account_no']); ?>"></div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Previous Institute</label>
            <div class="col-sm-6"><input type="text" name="previous_institute" class="form-control" value="<?php echo ev($d['previous_institute']); ?>"></div>
        </div>

        <div class="row mb-4 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Previous Designation</label>
            <div class="col-sm-6"><input type="text" name="previous_designation" class="form-control" value="<?php echo ev($d['previous_designation']); ?>"></div>
        </div>

        <!-- ===================== Academic Assignment (শুধু Teachers) ===================== -->
        <div id="asgBlock">
            <h6 class="fw-bold text-primary border-bottom pb-2 mb-3 mt-4"><i class="fa-solid fa-chalkboard-user"></i> Academic Assignment</h6>

            <div class="row mb-2">
                <label class="col-sm-3 col-form-label fw-bold text-end">Class Teacher</label>
                <div class="col-sm-8">
                    <div id="ctRows"></div>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addCtRow()"><i class="fa-solid fa-plus"></i> Class যোগ করুন</button>
                    <div><small class="text-muted">একটা Class+Section-এর Class Teacher একজনই হবে।</small></div>
                </div>
            </div>

            <div class="row mb-4 mt-3">
                <label class="col-sm-3 col-form-label fw-bold text-end">Subject Teacher</label>
                <div class="col-sm-8">
                    <div id="saRows"></div>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addSaRow()"><i class="fa-solid fa-plus"></i> Class ও Subject যোগ করুন</button>
                    <div><small class="text-muted">কোন Class-এর কোন কোন Subject এই শিক্ষক পড়ান।</small></div>
                </div>
            </div>
        </div>

        <!-- ===================== Personal Information ===================== -->
        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3 mt-4"><i class="fa-solid fa-circle-info"></i> Personal Information</h6>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Name <span class="text-danger">*</span></label>
            <div class="col-sm-6"><input type="text" name="name" class="form-control" value="<?php echo ev($d['name']); ?>" required></div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Mobile <span class="text-danger">*</span></label>
            <div class="col-sm-6"><input type="tel" name="mobile" class="form-control phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="১১ ডিজিটের মোবাইল নম্বর দিন" value="<?php echo ev($d['mobile']); ?>" required></div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Email</label>
            <div class="col-sm-6"><input type="email" name="email" class="form-control" value="<?php echo ev($d['email']); ?>"></div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Date Of Birth <span class="text-danger">*</span></label>
            <div class="col-sm-6"><input type="date" name="dob" class="form-control" value="<?php echo ev($d['dob']); ?>" required></div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">National ID</label>
            <div class="col-sm-6"><input type="text" name="national_id" class="form-control" value="<?php echo ev($d['national_id']); ?>"></div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Photo</label>
            <div class="col-sm-6">
                <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png">
                <small class="text-danger">Image Width = 262px and Height = 300px</small>
                <?php if (!empty($d['photo_path'])): ?><div class="mt-2"><img src="../<?php echo ev($d['photo_path']); ?>" style="height:60px;border-radius:6px;"></div><?php endif; ?>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Fathers Name <span class="text-danger">*</span></label>
            <div class="col-sm-6"><input type="text" name="fathers_name" class="form-control" value="<?php echo ev($d['fathers_name']); ?>" required></div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Mothers Name <span class="text-danger">*</span></label>
            <div class="col-sm-6"><input type="text" name="mothers_name" class="form-control" value="<?php echo ev($d['mothers_name']); ?>" required></div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Marital Status <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="marital_status" class="form-select" required>
                    <option value="">Select Marital Status</option>
                    <?php foreach ($marital_statuses as $ms): ?>
                        <option value="<?php echo $ms; ?>" <?php echo $d['marital_status'] === $ms ? 'selected' : ''; ?>><?php echo $ms; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Religion <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="religion" class="form-select" required>
                    <option value="">Select Religion</option>
                    <?php foreach ($religions as $rl): ?>
                        <option value="<?php echo $rl; ?>" <?php echo $d['religion'] === $rl ? 'selected' : ''; ?>><?php echo $rl; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Blood Group <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="blood_group" class="form-select" required>
                    <option value="">Select Blood Group</option>
                    <?php foreach ($blood_groups as $bg): ?>
                        <option value="<?php echo $bg; ?>" <?php echo $d['blood_group'] === $bg ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Gender <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="gender" class="form-select" required>
                    <option value="">Select Gender</option>
                    <?php foreach ($genders as $gd): ?>
                        <option value="<?php echo $gd; ?>" <?php echo $d['gender'] === $gd ? 'selected' : ''; ?>><?php echo $gd; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-4 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Facebook Link</label>
            <div class="col-sm-6"><input type="text" name="facebook_link" class="form-control" value="<?php echo ev($d['facebook_link']); ?>"></div>
        </div>

        <!-- ===================== Educational Qualification ===================== -->
        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3 mt-4"><i class="fa-solid fa-circle-info"></i> Educational Qualification</h6>

        <div id="eduRowsWrap">
            <?php
            $edu_seed = $edu_rows ?: [['institute' => '', 'degree' => '', 'country' => '']];
            foreach ($edu_seed as $idx => $er):
            ?>
                <div class="edu-row row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label text-end">Name of the Institute</label>
                    <div class="col-sm-6"><input type="text" name="institute[]" class="form-control" value="<?php echo ev($er['institute']); ?>"></div>
                </div>
                <div class="edu-row row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label text-end">Name of the Degree</label>
                    <div class="col-sm-6"><input type="text" name="degree[]" class="form-control" value="<?php echo ev($er['degree']); ?>"></div>
                </div>
                <div class="edu-row row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label text-end">Country Name</label>
                    <div class="col-sm-6"><input type="text" name="country[]" class="form-control" value="<?php echo ev($er['country']); ?>"></div>
                </div>
                <?php if ($idx < count($edu_seed) - 1): ?><hr class="text-muted"><?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div class="row mb-4">
            <div class="col-sm-6 offset-sm-3 text-end">
                <button type="button" class="btn btn-primary" style="background-color:#1e3a8a;" onclick="addEduRow()"><i class="fa-solid fa-plus"></i></button>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Major Subject</label>
            <div class="col-sm-6"><input type="text" name="major_subject" class="form-control" value="<?php echo ev($d['major_subject']); ?>"></div>
        </div>

        <div class="row mb-4 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Extra Qualification</label>
            <div class="col-sm-6"><textarea name="extra_qualification" rows="3" class="form-control"><?php echo ev($d['extra_qualification']); ?></textarea></div>
        </div>

        <!-- ===================== Contact Information ===================== -->
        <h6 class="fw-bold text-primary border-bottom pb-2 mb-3 mt-4"><i class="fa-solid fa-circle-info"></i> Contact Information</h6>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Present Address <span class="text-danger">*</span></label>
            <div class="col-sm-6"><textarea name="present_address" id="presentAddress" rows="3" class="form-control" required><?php echo ev($d['present_address']); ?></textarea></div>
        </div>

        <div class="row mb-2 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Permanent Address <span class="text-danger">*</span></label>
            <div class="col-sm-6"><textarea name="permanent_address" id="permanentAddress" rows="3" class="form-control" required><?php echo ev($d['permanent_address']); ?></textarea></div>
        </div>
        <div class="row mb-4">
            <div class="col-sm-6 offset-sm-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="sameAsPresent" onclick="copyPresentAddress()">
                    <label class="form-check-label" for="sameAsPresent">Same as Present Address</label>
                </div>
            </div>
        </div>

        <div class="row mb-4 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Status <span class="text-danger">*</span></label>
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
                <button type="submit" name="save_employee" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $edit_id ? 'Update' : 'Create'; ?></button>
                <a href="index.php?page=hr_employee_add" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div>
        </div>
    </form>
</div>

<script>
// Section (School/College/Both) শুধু Teachers ও Staffs টাইপে দেখায় ও বাধ্যতামূলক; Academic Assignment শুধু Teachers-এ
(function () {
    var row = document.getElementById('empSectionRow');
    var asg = document.getElementById('asgBlock');
    var secs = row.querySelectorAll('input[name="section_level"]');
    function sync() {
        var t = document.querySelector('input[name="type"]:checked');
        var v = t ? t.value : '';
        var hasSec = v === 'Teachers' || v === 'Staffs';
        row.style.display = hasSec ? '' : 'none';
        secs.forEach(function (r) { r.required = hasSec; });
        asg.style.display = v === 'Teachers' ? '' : 'none';
        asg.querySelectorAll('select, input').forEach(function (el) { el.disabled = v !== 'Teachers'; });
    }
    document.querySelectorAll('input[name="type"]').forEach(function (r) { r.addEventListener('change', sync); });
    window.syncEmpType = sync;
})();

// ---------------- Rank ডুপ্লিকেট সতর্কতা ----------------
(function () {
    var taken = <?php echo json_encode($rank_taken, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var inp = document.getElementById('empRank'), msg = document.getElementById('rankDupMsg');
    function chk() {
        var r = parseInt(inp.value, 10);
        msg.textContent = (r && taken[r]) ? 'এই Rank আগে থেকেই "' + taken[r] + '"-এর আছে (একই Rank থাকলে নাম অনুযায়ী সাজাবে)।' : '';
    }
    inp.addEventListener('input', chk); chk();
})();

// ---------------- Class Teacher / Subject Assignment ----------------
var ASG = {
    classes: <?php echo json_encode($asg_classes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>,
    sections: <?php echo json_encode($asg_sections, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>,
    subjects: <?php echo json_encode($asg_subjects, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>,
    ctOf: <?php echo json_encode($class_teacher_of, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>,
    myCt: <?php echo json_encode($my_ct); ?>,
    mySa: <?php echo json_encode($my_sa); ?>,
    me: <?php echo (int) $edit_id; ?>
};
function asgEsc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
function asgClassOptions(sel) {
    var h = '<option value="">Select Class</option>';
    ASG.classes.forEach(function (c) { h += '<option value="' + c.id + '"' + (String(sel) === String(c.id) ? ' selected' : '') + '>' + asgEsc(c.class_name) + '</option>'; });
    return h;
}

function addCtRow(cls, sec) {
    var wrap = document.getElementById('ctRows');
    var row = document.createElement('div');
    row.className = 'd-flex flex-wrap gap-2 align-items-center mb-2 ct-row';
    row.innerHTML = '<select name="ct_class[]" class="form-select" style="max-width:220px">' + asgClassOptions(cls) + '</select>' +
        '<select name="ct_section[]" class="form-select" style="max-width:200px"></select>' +
        '<button type="button" class="btn btn-sm btn-outline-danger" title="Remove"><i class="fa-solid fa-xmark"></i></button>' +
        '<small class="text-warning-emphasis w-100 ct-warn"></small>';
    var cSel = row.children[0], sSel = row.children[1], warn = row.querySelector('.ct-warn');
    function fillSections(selSec) {
        var list = ASG.sections[cSel.value] || [];
        var h = '<option value="0">' + (list.length ? 'সব Section / নির্দিষ্ট নয়' : 'কোনো Section নেই') + '</option>';
        list.forEach(function (x) { h += '<option value="' + x.id + '"' + (String(selSec) === String(x.id) ? ' selected' : '') + '>' + asgEsc(x.n) + '</option>'; });
        sSel.innerHTML = h; check();
    }
    function check() {
        var cur = ASG.ctOf[cSel.value + '_' + sSel.value];
        warn.textContent = (cSel.value && cur && cur.emp !== ASG.me) ? '⚠ এখন এখানে Class Teacher: ' + cur.name + ' — সেভ করলে ইনি বদলে যাবেন।' : '';
    }
    cSel.addEventListener('change', function () { fillSections(0); });
    sSel.addEventListener('change', check);
    row.querySelector('button').addEventListener('click', function () { row.remove(); });
    wrap.appendChild(row);
    fillSections(sec || 0);
}

var saIdx = 0;
function addSaRow(cls, picked) {
    var wrap = document.getElementById('saRows');
    var i = saIdx++;
    var row = document.createElement('div');
    row.className = 'border rounded p-2 mb-2 sa-row';
    row.innerHTML = '<div class="d-flex gap-2 align-items-center mb-2">' +
        '<select name="sa_class[' + i + ']" class="form-select" style="max-width:220px">' + asgClassOptions(cls) + '</select>' +
        '<button type="button" class="btn btn-sm btn-outline-danger" title="Remove"><i class="fa-solid fa-xmark"></i></button></div>' +
        '<div class="sa-subjects row g-1"></div>';
    var cSel = row.querySelector('select'), box = row.querySelector('.sa-subjects');
    function fill(sel) {
        var list = ASG.subjects[cSel.value] || [];
        if (!cSel.value) { box.innerHTML = '<div class="text-muted small col-12">আগে Class সিলেক্ট করুন।</div>'; return; }
        if (!list.length) { box.innerHTML = '<div class="text-muted small col-12">এই Class-এ কোনো Subject নেই (আগে Subjects পেজ থেকে যোগ করুন)।</div>'; return; }
        var h = '';
        list.forEach(function (x) {
            var id = 'sa' + i + '_' + x.id;
            h += '<div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="sa_subjects[' + i + '][]" value="' + x.id + '" id="' + id + '"' + ((sel || []).indexOf(x.id) > -1 ? ' checked' : '') + '>' +
                 '<label class="form-check-label" for="' + id + '">' + asgEsc(x.n) + '</label></div></div>';
        });
        box.innerHTML = h;
    }
    cSel.addEventListener('change', function () { fill([]); });
    row.querySelector('button').addEventListener('click', function () { row.remove(); });
    wrap.appendChild(row);
    fill(picked);
}

(function () {
    ASG.myCt.forEach(function (x) { addCtRow(x.c, x.s); });
    Object.keys(ASG.mySa).forEach(function (c) { addSaRow(parseInt(c, 10), ASG.mySa[c]); });
    window.syncEmpType();
})();
</script>
<script>
function addEduRow() {
    const wrap = document.getElementById('eduRowsWrap');
    const rows = wrap.querySelectorAll('.edu-row');
    const last3 = Array.from(rows).slice(-3);
    const hr = document.createElement('hr');
    hr.className = 'text-muted';
    wrap.appendChild(hr);
    last3.forEach(function (row) {
        const clone = row.cloneNode(true);
        clone.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
        wrap.appendChild(clone);
    });
}
function copyPresentAddress() {
    const box = document.getElementById('sameAsPresent');
    const present = document.getElementById('presentAddress');
    const permanent = document.getElementById('permanentAddress');
    if (box.checked) {
        permanent.value = present.value;
        permanent.readOnly = true;
    } else {
        permanent.readOnly = false;
    }
}

// ফোন নম্বর ফিল্ড (.phone-11 ক্লাস): টাইপ করার সাথে সাথেই সংখ্যা ছাড়া অন্য কিছু নেয় না, ১১ ডিজিটের বেশি হলে কেটে দেয়
document.querySelectorAll('.phone-11').forEach(function (input) {
    // প্রতিটা ফিল্ডের ঠিক পরে একটা এরর মেসেজ বসানো হচ্ছে (আগে থেকে না থাকলে)
    let errEl = input.nextElementSibling;
    if (!errEl || !errEl.classList.contains('phone11-err')) {
        errEl = document.createElement('div');
        errEl.className = 'small text-danger mt-1 d-none phone11-err';
        errEl.textContent = 'মোবাইল নম্বর অবশ্যই ১১ ডিজিটের হতে হবে।';
        input.insertAdjacentElement('afterend', errEl);
    }

    function checkPhone11() {
        let digits = input.value.replace(/\D/g, '');
        if (digits.length > 11) digits = digits.slice(0, 11);
        input.value = digits;
        // খালি রাখা যাবে (আবশ্যক না হলে) — কিন্তু কিছু লিখলে অবশ্যই ১১ ডিজিট হতে হবে,
        // ১১ ডিজিট হয়ে গেলেই মেসেজ সাথে সাথে (রিলোড ছাড়া) সরে যাবে
        const invalid = digits.length > 0 && digits.length !== 11;
        errEl.classList.toggle('d-none', !invalid);
        input.classList.toggle('is-invalid', invalid);
    }
    input.addEventListener('input', checkPhone11);
    input.addEventListener('blur', checkPhone11);
});
</script>
