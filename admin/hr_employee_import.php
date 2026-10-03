<?php
// admin/hr_employee_import.php - HR Module > Import Employees (Excel/CSV/TXT)
//
// student_import.php এর একই প্যাটার্ন: (১) আপলোড -> পার্স -> ভ্যালিডেট -> প্রিভিউ
// (২) Confirm করলে শুধু ভ্যালিড রো গুলো employees টেবিলে insert হয়।
// CSV/TXT/XLSX পার্সিং লজিক config/import_helpers.php তে শেয়ার্ড।

require_once __DIR__ . '/../config/import_helpers.php';

// employees/designations/employee_types/employee_shifts/employee_grades টেবিল
// এখনো তৈরি না হলে (একদম নতুন tenant) নিশ্চিত করে নেওয়া
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
    "CREATE TABLE IF NOT EXISTS employee_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS employee_shifts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS employees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('Teachers','Staffs','Board Of Directors','Academic Committee') NOT NULL DEFAULT 'Teachers',
        employee_type_id INT NULL, designation_id INT NULL, shift_id INT NULL, grade_id INT NULL, `rank` INT NULL,
        employee_id VARCHAR(50) NOT NULL UNIQUE, device_serial VARCHAR(100) NULL, tracking_id VARCHAR(100) NULL,
        rfid_card_no VARCHAR(100) NULL, joining_date DATE NULL, bank_account_no VARCHAR(100) NULL,
        previous_institute VARCHAR(255) NULL, previous_designation VARCHAR(255) NULL,
        name VARCHAR(150) NOT NULL, mobile VARCHAR(20) NOT NULL, email VARCHAR(150) NULL, dob DATE NULL,
        national_id VARCHAR(50) NULL, photo_path VARCHAR(255) NULL, fathers_name VARCHAR(150) NULL, mothers_name VARCHAR(150) NULL,
        marital_status VARCHAR(20) NULL, religion VARCHAR(50) NULL, blood_group VARCHAR(5) NULL, gender VARCHAR(10) NULL,
        facebook_link VARCHAR(255) NULL, major_subject VARCHAR(150) NULL, extra_qualification TEXT NULL,
        present_address TEXT NULL, permanent_address TEXT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active', is_current TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$imp_msg = $_SESSION['emp_imp_msg'] ?? '';
$imp_err = $_SESSION['emp_imp_err'] ?? '';
unset($_SESSION['emp_imp_msg'], $_SESSION['emp_imp_err']);

function eiv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$emp_import_field_aliases = [
    'employee_id'    => ['employee id', 'employeeid', 'id', 'কর্মচারী আইডি'],
    'type'           => ['type', 'category', 'ধরন'],
    'name'           => ['name', 'employee name', 'নাম'],
    'designation'    => ['designation', 'পদবি'],
    'employee_type'  => ['employee type', 'কর্মচারী ধরন'],
    'shift'          => ['shift', 'working shift', 'শিফট'],
    'mobile'         => ['mobile', 'phone', 'মোবাইল'],
    'email'          => ['email', 'ইমেইল'],
    'dob'            => ['dob', 'date of birth', 'জন্ম তারিখ'],
    'joining_date'   => ['joining date', 'join date', 'যোগদানের তারিখ'],
    'gender'         => ['gender', 'sex', 'লিঙ্গ'],
    'blood_group'    => ['blood group', 'রক্তের গ্রুপ'],
    'religion'       => ['religion', 'ধর্ম'],
    'marital_status' => ['marital status', 'বৈবাহিক অবস্থা'],
    'national_id'    => ['national id', 'nid', 'জাতীয় পরিচয়পত্র'],
    'fathers_name'   => ["father's name", 'fathers name', 'পিতার নাম'],
    'mothers_name'   => ["mother's name", 'mothers name', 'মাতার নাম'],
    'present_address'   => ['present address', 'address', 'বর্তমান ঠিকানা'],
    'permanent_address' => ['permanent address', 'স্থায়ী ঠিকানা'],
    'bank_account_no'   => ['bank account no', 'bank account', 'ব্যাংক হিসাব নং'],
    'facebook_link'     => ['facebook link', 'facebook', 'ফেসবুক লিংক'],
];

// -----------------------------------------------------------
// Employee ID অটো-জেনারেট — student_id এর মতো কোনো প্রি-ডিফাইন্ড কোড-প্যাটার্ন নেই,
// তাই সাধারণ "EMP0001" স্টাইলে সিরিয়াল বাড়িয়ে বাড়িয়ে দেওয়া হয়
// -----------------------------------------------------------
function emp_import_next_id(PDO $pdo): string
{
    $stmt = $pdo->query("SELECT employee_id FROM employees WHERE employee_id LIKE 'EMP%'");
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $eid) {
        if (preg_match('/^EMP0*(\d+)$/', $eid, $m)) $max = max($max, (int) $m[1]);
    }
    return 'EMP' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
}

$import_tmp_dir = tenant_upload_abs(__DIR__ . '/..', 'tmp');
if (!is_dir($import_tmp_dir)) { mkdir($import_tmp_dir, 0755, true); }
import_cleanup_old_temp($import_tmp_dir);

// -----------------------------------------------------------
// স্যাম্পল CSV টেমপ্লেট ডাউনলোড
// -----------------------------------------------------------
if (isset($_GET['sample'])) {
    while (ob_get_level() > 0) { ob_end_clean(); } // sidebar/header ইতিমধ্যে যা buffer-এ জমেছে তা ফেলে দাও
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="employee_import_sample.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Employee ID', 'Type', 'Name', 'Designation', 'Employee Type', 'Shift', 'Mobile', 'Email', 'DOB', 'Joining Date', 'Gender', 'Blood Group', 'Religion', 'Marital Status', 'National ID', "Father's Name", "Mother's Name", 'Present Address', 'Permanent Address']);
    fputcsv($out, ['', 'Teachers', 'মোঃ রফিকুল ইসলাম', 'সহকারী শিক্ষক', 'শিক্ষক', 'সকাল', '01700000000', 'rafiqul@example.com', '1990-01-15', '2020-01-01', 'Male', 'B+', 'Islam', 'Married', '1234567890123', 'আব্দুল হক', 'রহিমা খাতুন', 'গ্রাম: X, রংপুর', 'গ্রাম: X, রংপুর']);
    fclose($out);
    exit();
}

// -----------------------------------------------------------
// STEP 1: আপলোড -> পার্স -> ভ্যালিডেট -> প্রিভিউ
// -----------------------------------------------------------
$preview_rows = null;
$preview_token = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_file'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['emp_imp_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করুন।';
        header('Location: index.php?page=hr_employee_import');
        exit();
    }
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['emp_imp_err'] = 'ফাইল আপলোড করা যায়নি, আবার চেষ্টা করুন।';
        header('Location: index.php?page=hr_employee_import');
        exit();
    }

    $file = $_FILES['import_file'];
    if ($file['size'] > 5 * 1024 * 1024) {
        $_SESSION['emp_imp_err'] = 'ফাইলের সাইজ সর্বোচ্চ 5MB হতে হবে।';
        header('Location: index.php?page=hr_employee_import');
        exit();
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
        $_SESSION['emp_imp_err'] = 'শুধু .csv, .txt অথবা .xlsx ফাইল আপলোড করা যাবে। (পুরনো .xls হলে Excel থেকে .xlsx/.csv তে "Save As" করে আবার চেষ্টা করুন।)';
        header('Location: index.php?page=hr_employee_import');
        exit();
    }

    try {
        $rawRows = import_parse_file($file['tmp_name'], $ext);
    } catch (Exception $e) {
        $_SESSION['emp_imp_err'] = $e->getMessage();
        header('Location: index.php?page=hr_employee_import');
        exit();
    }

    if (count($rawRows) < 2) {
        $_SESSION['emp_imp_err'] = 'ফাইলে হেডার সারি বাদে অন্তত ১টা ডাটা সারি থাকতে হবে।';
        header('Location: index.php?page=hr_employee_import');
        exit();
    }

    $headerRow = array_shift($rawRows);
    $colMap = import_map_headers($headerRow, $emp_import_field_aliases);

    if (!in_array('name', $colMap, true)) {
        $_SESSION['emp_imp_err'] = 'ফাইলে অন্তত Name কলাম থাকা আবশ্যক (কলামের নাম মিলছে না — স্যাম্পল টেমপ্লেট দেখে ঠিক করুন)।';
        header('Location: index.php?page=hr_employee_import');
        exit();
    }

    $designations_lk = [];
    foreach ($pdo->query("SELECT id, title FROM designations WHERE status='Active'") as $r) $designations_lk[mb_strtolower(trim($r['title']))] = $r['id'];
    $emp_types_lk = [];
    foreach ($pdo->query("SELECT id, name FROM employee_types WHERE status='Active'") as $r) $emp_types_lk[mb_strtolower(trim($r['name']))] = $r['id'];
    $shifts_lk = [];
    foreach ($pdo->query("SELECT id, name FROM employee_shifts WHERE status='Active'") as $r) $shifts_lk[mb_strtolower(trim($r['name']))] = $r['id'];

    $existing_ids = array_flip($pdo->query("SELECT employee_id FROM employees")->fetchAll(PDO::FETCH_COLUMN));
    $seen_ids_in_file = [];
    $validated = [];

    foreach ($rawRows as $rowIdx => $raw) {
        if (!array_filter($raw, fn($v) => trim((string) $v) !== '')) continue;

        $row = [];
        foreach ($colMap as $idx => $field) { $row[$field] = trim((string) ($raw[$idx] ?? '')); }

        $errors = [];

        $name = $row['name'] ?? '';
        if ($name === '') $errors[] = 'নাম নেই';

        $mobile = $row['mobile'] ?? '';
        if ($mobile === '') $errors[] = 'মোবাইল নাম্বার নেই';

        $typeRaw = mb_strtolower($row['type'] ?? '');
        $type = match ($typeRaw) {
            'teachers', 'teacher', 'শিক্ষক' => 'Teachers',
            'staffs', 'staff', 'কর্মচারী' => 'Staffs',
            'board of directors' => 'Board Of Directors',
            'academic committee' => 'Academic Committee',
            default => 'Teachers',
        };

        $designation_id = !empty($row['designation']) ? ($designations_lk[mb_strtolower($row['designation'])] ?? null) : null;
        if (!empty($row['designation']) && !$designation_id) {
            $errors[] = "ডেজিগনেশন '" . $row['designation'] . "' খুঁজে পাওয়া যায়নি (Admin > HR > Designation এ আগে যোগ করে নিন)";
        }

        $employee_type_id = !empty($row['employee_type']) ? ($emp_types_lk[mb_strtolower($row['employee_type'])] ?? null) : null;
        $shift_id = !empty($row['shift']) ? ($shifts_lk[mb_strtolower($row['shift'])] ?? null) : null;

        $employee_id = $row['employee_id'] ?? '';
        if ($employee_id !== '' && (isset($existing_ids[$employee_id]) || isset($seen_ids_in_file[$employee_id]))) {
            $errors[] = "Employee ID '{$employee_id}' আগে থেকেই আছে (ডুপ্লিকেট)";
        }

        $genderRaw = mb_strtolower($row['gender'] ?? '');
        $gender = match ($genderRaw) {
            'male', 'ছেলে', 'm' => 'Male',
            'female', 'মেয়ে', 'f' => 'Female',
            default => $row['gender'] !== '' ? $row['gender'] : '',
        };

        $resolved = [
            'employee_id' => $employee_id, 'type' => $type, 'name' => $name,
            'designation_id' => $designation_id, 'employee_type_id' => $employee_type_id, 'shift_id' => $shift_id,
            'mobile' => $mobile, 'email' => $row['email'] ?? '', 'dob' => $row['dob'] ?? '', 'joining_date' => $row['joining_date'] ?? '',
            'gender' => $gender, 'blood_group' => $row['blood_group'] ?? '', 'religion' => $row['religion'] ?? '',
            'marital_status' => $row['marital_status'] ?? '', 'national_id' => $row['national_id'] ?? '',
            'fathers_name' => $row['fathers_name'] ?? '', 'mothers_name' => $row['mothers_name'] ?? '',
            'present_address' => $row['present_address'] ?? '', 'permanent_address' => $row['permanent_address'] ?? ($row['present_address'] ?? ''),
            'bank_account_no' => $row['bank_account_no'] ?? '', 'facebook_link' => $row['facebook_link'] ?? '',
            'designation_label' => $row['designation'] ?? '',
        ];

        if ($employee_id !== '') $seen_ids_in_file[$employee_id] = true;

        $validated[] = ['row_no' => $rowIdx + 2, 'data' => $resolved, 'errors' => $errors, 'ok' => empty($errors)];
    }

    if (!$validated) {
        $_SESSION['emp_imp_err'] = 'ফাইলে কোনো বৈধ ডাটা সারি পাওয়া যায়নি।';
        header('Location: index.php?page=hr_employee_import');
        exit();
    }

    $token = bin2hex(random_bytes(12));
    file_put_contents($import_tmp_dir . 'import_emp_' . $token . '.json', json_encode($validated, JSON_UNESCAPED_UNICODE));

    $preview_rows = $validated;
    $preview_token = $token;
}

// -----------------------------------------------------------
// STEP 2: Confirm — শুধু ভ্যালিড রো insert
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['emp_imp_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করুন।';
        header('Location: index.php?page=hr_employee_import');
        exit();
    }

    $token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
    $tmpFile = $import_tmp_dir . 'import_emp_' . $token . '.json';
    if ($token === '' || !is_file($tmpFile)) {
        $_SESSION['emp_imp_err'] = 'প্রিভিউ ডাটার মেয়াদ শেষ হয়ে গেছে (১ ঘন্টা), আবার ফাইল আপলোড করুন।';
        header('Location: index.php?page=hr_employee_import');
        exit();
    }

    $rows = json_decode(file_get_contents($tmpFile), true) ?: [];
    $imported = 0;
    $skipped = 0;

    $stmt = $pdo->prepare(
        "INSERT INTO employees
            (employee_id, type, designation_id, employee_type_id, shift_id, `rank`, device_serial,
             name, mobile, email, dob, joining_date, gender, blood_group, religion, marital_status, national_id,
             fathers_name, mothers_name, present_address, permanent_address, bank_account_no, facebook_link,
             status, is_current)
         VALUES
            (?, ?, ?, ?, ?, 0, '1',
             ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?,
             'Active', 1)"
    );

    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            if (empty($r['ok'])) { $skipped++; continue; }
            $d = $r['data'];

            $employee_id = $d['employee_id'] !== '' ? $d['employee_id'] : emp_import_next_id($pdo);

            $stmt->execute([
                $employee_id, $d['type'], $d['designation_id'] ?: null, $d['employee_type_id'] ?: null, $d['shift_id'] ?: null,
                $d['name'], $d['mobile'], $d['email'] ?: null, $d['dob'] ?: null, $d['joining_date'] ?: null,
                $d['gender'] ?: null, $d['blood_group'] ?: null, $d['religion'] ?: null, $d['marital_status'] ?: null, $d['national_id'] ?: null,
                $d['fathers_name'] ?: null, $d['mothers_name'] ?: null, $d['present_address'] ?: null, $d['permanent_address'] ?: null,
                $d['bank_account_no'] ?: null, $d['facebook_link'] ?: null,
            ]);
            $imported++;
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['emp_imp_err'] = 'Import করার সময় সমস্যা হয়েছে: ' . $e->getMessage();
        header('Location: index.php?page=hr_employee_import');
        exit();
    }

    @unlink($tmpFile);
    $_SESSION['emp_imp_msg'] = "{$imported} জন কর্মচারী/শিক্ষক সফলভাবে import করা হয়েছে।" . ($skipped ? " {$skipped} টি সারি এরর থাকায় বাদ দেওয়া হয়েছে।" : '');
    sec_log_activity($pdo, 'HR', 'Import', "{$imported} জন Employee import করা হয়েছে" . ($skipped ? " ({$skipped} টি সারি বাদ দেওয়া হয়েছে)" : ''));
    header('Location: index.php?page=hr_employee_import');
    exit();
}

$ok_count = $preview_rows ? count(array_filter($preview_rows, fn($r) => $r['ok'])) : 0;
$err_count = $preview_rows ? count($preview_rows) - $ok_count : 0;
?>

<div class="card-custom">
    <h4 class="fw-bold mb-4 text-dark"><i class="fa-solid fa-file-import"></i> HR Module &gt; Import Employees</h4>

    <?php if ($imp_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($imp_msg); ?></div><?php endif; ?>
    <?php if ($imp_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($imp_err); ?></div><?php endif; ?>

    <?php if (!$preview_rows): ?>
        <div class="row">
            <div class="col-md-6">
                <div class="card p-3 bg-light">
                    <h6 class="fw-bold mb-3">ফাইল আপলোড করুন</h6>
                    <form method="POST" action="index.php?page=hr_employee_import" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label">Excel (.xlsx) / CSV / TXT ফাইল সিলেক্ট করুন</label>
                            <input type="file" name="import_file" class="form-control" accept=".xlsx,.csv,.txt" required>
                            <small class="text-muted d-block mt-1">সর্বোচ্চ 5MB। পুরনো .xls ফরম্যাট হলে আগে .xlsx বা .csv তে সেভ করে নিন।</small>
                        </div>
                        <button type="submit" name="upload_file" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-upload"></i> Upload &amp; Preview
                        </button>
                        <a href="index.php?page=hr_employee_import&sample=1" class="btn btn-outline-secondary btn-sm">
                            <i class="fa-solid fa-download"></i> স্যাম্পল টেমপ্লেট ডাউনলোড
                        </a>
                    </form>
                </div>
            </div>
            <div class="col-md-6">
                <div class="alert alert-info small">
                    <b>নিয়মাবলী:</b>
                    <ul class="mb-0 ps-3">
                        <li>প্রথম সারি অবশ্যই হেডার (কলামের নাম) হতে হবে।</li>
                        <li>আবশ্যক কলাম: <b>Name, Mobile</b> — বাকিগুলো ঐচ্ছিক।</li>
                        <li><b>Designation</b> দিলে Admin &gt; HR &gt; Designation এ আগে থেকে তৈরি করা নামের সাথে মিলতে হবে, নাহলে সেই সারি এরর দেখাবে।</li>
                        <li><b>Employee ID</b> খালি রাখলে সিস্টেম নিজে থেকে (EMP0001, EMP0002...) অটো-জেনারেট করবে।</li>
                        <li><b>Type</b> কলামে Teachers / Staffs / Board Of Directors / Academic Committee এর যেকোনো একটা দিন, খালি বা না মিললে ডিফল্ট 'Teachers' বসবে।</li>
                        <li>Upload করার পর প্রথমে প্রিভিউ দেখাবে — Confirm করলে শুধু ঠিক সারিগুলোই সেভ হবে।</li>
                    </ul>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <span class="badge bg-success">ঠিক আছে: <?php echo $ok_count; ?></span>
                <span class="badge bg-danger">এরর: <?php echo $err_count; ?></span>
                <span class="text-muted small ms-2">মোট <?php echo count($preview_rows); ?> সারি পাওয়া গেছে</span>
            </div>
            <div>
                <a href="index.php?page=hr_employee_import" class="btn btn-outline-secondary btn-sm">বাতিল করুন</a>
                <form method="POST" action="index.php?page=hr_employee_import" class="d-inline">
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
                        <th>সারি</th><th>স্ট্যাটাস</th><th>নাম</th><th>টাইপ</th><th>ডেজিগনেশন</th><th>মোবাইল</th><th>বিস্তারিত</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview_rows as $r): $d = $r['data']; ?>
                        <tr class="<?php echo $r['ok'] ? '' : 'table-danger'; ?>">
                            <td><?php echo (int) $r['row_no']; ?></td>
                            <td><?php echo $r['ok'] ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">Error</span>'; ?></td>
                            <td><?php echo eiv($d['name']); ?></td>
                            <td><?php echo eiv($d['type']); ?></td>
                            <td><?php echo $d['designation_id'] ? '✓ ' . eiv($d['designation_label']) : '<span class="text-muted">—</span>'; ?></td>
                            <td><?php echo eiv($d['mobile']); ?></td>
                            <td class="small text-danger"><?php echo eiv(implode(', ', $r['errors'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
