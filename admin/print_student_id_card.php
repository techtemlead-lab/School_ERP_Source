<?php
// admin/print_student_id_card.php - Print Students ID Card (কনফিগারেশন + টেমপ্লেট আপলোড + জেনারেট)
$idc_err = $_SESSION['idc_err'] ?? '';
$idc_msg = $_SESSION['idc_msg'] ?? '';
unset($_SESSION['idc_err'], $_SESSION['idc_msg']);

function icv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// টেবিল না থাকলে অটো-তৈরি (id_card_templates)
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS id_card_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        side ENUM('front','back') NOT NULL DEFAULT 'front',
        image_path VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// -----------------------------------------------------------
// আগেরবার Submit করা সেটিংস (Show Session/Roll/... টগল, Front/Back Part, Orientation,
// Top/Bottom Color, Establishment) মনে রাখার টেবিল — একটাই রো (id=1), যাতে কনফিগ ফর্মে
// ফিরে এলে যা ON করা ছিল তা ON-ই থাকে, নিজে থেকে OFF হয়ে না যায়
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS id_card_settings (
        id INT PRIMARY KEY DEFAULT 1,
        show_session TINYINT(1) NOT NULL DEFAULT 0,
        show_class_section TINYINT(1) NOT NULL DEFAULT 0,
        show_shift TINYINT(1) NOT NULL DEFAULT 0,
        show_group TINYINT(1) NOT NULL DEFAULT 0,
        show_roll TINYINT(1) NOT NULL DEFAULT 0,
        show_dob TINYINT(1) NOT NULL DEFAULT 0,
        show_blood TINYINT(1) NOT NULL DEFAULT 0,
        show_parent_photo TINYINT(1) NOT NULL DEFAULT 0,
        show_parent_phone TINYINT(1) NOT NULL DEFAULT 0,
        front_part VARCHAR(20) NOT NULL DEFAULT 'general',
        back_part VARCHAR(20) NOT NULL DEFAULT 'general',
        orientation VARCHAR(15) NOT NULL DEFAULT 'Portrait',
        top_color VARCHAR(10) NOT NULL DEFAULT '#ef4444',
        bottom_color VARCHAR(10) NOT NULL DEFAULT '#1e293b',
        establishment VARCHAR(50) NULL,
        show_qr TINYINT(1) NOT NULL DEFAULT 0,
        qr_side VARCHAR(10) NOT NULL DEFAULT 'Back',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
// টেবিলটা আগে থেকেই (পুরনো/আংশিক অবস্থায়) থাকলে — "CREATE TABLE IF NOT EXISTS" নতুন করে
// কলাম যোগ করে না, তাই প্রতিটা দরকারি কলাম নিজে থেকে আছে কিনা চেক করে, না থাকলে ALTER
// করে যোগ করে দেওয়া হচ্ছে (self-healing) — এই একই প্যাটার্ন device_settings.php-তেও আছে
foreach ([
    'show_session' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'show_class_section' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'show_shift' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'show_group' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'show_roll' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'show_dob' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'show_blood' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'show_parent_photo' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'show_parent_phone' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'front_part' => "VARCHAR(20) NOT NULL DEFAULT 'general'",
    'back_part' => "VARCHAR(20) NOT NULL DEFAULT 'general'",
    'orientation' => "VARCHAR(15) NOT NULL DEFAULT 'Portrait'",
    'top_color' => "VARCHAR(10) NOT NULL DEFAULT '#ef4444'",
    'bottom_color' => "VARCHAR(10) NOT NULL DEFAULT '#1e293b'",
    'establishment' => 'VARCHAR(50) NULL',
    'show_qr' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'qr_side' => "VARCHAR(10) NOT NULL DEFAULT 'Back'",
] as $idc_col => $idc_def) {
    $idc_has = $pdo->query("SHOW COLUMNS FROM id_card_settings LIKE '$idc_col'")->fetch();
    if (!$idc_has) {
        try { $pdo->exec("ALTER TABLE id_card_settings ADD COLUMN `$idc_col` $idc_def"); } catch (Throwable $e) {}
    }
}
$idc_settings = $pdo->query("SELECT * FROM id_card_settings WHERE id = 1")->fetch();
if (!$idc_settings) {
    $pdo->exec("INSERT INTO id_card_settings (id) VALUES (1)");
    $idc_settings = $pdo->query("SELECT * FROM id_card_settings WHERE id = 1")->fetch();
}

// -----------------------------------------------------------
// টেমপ্লেট আপলোড হেল্পার
// -----------------------------------------------------------
function idc_handle_upload(string $field): array
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'path' => null, 'error' => 'কোনো ফাইল আপলোড করা হয়নি।'];
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'ফাইল আপলোডে সমস্যা হয়েছে।'];
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return ['ok' => false, 'path' => null, 'error' => 'ফাইলের সাইজ সর্বোচ্চ 2MB হতে হবে।'];
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
        return ['ok' => false, 'path' => null, 'error' => 'ফাইলের প্রকৃত কনটেন্ট এক্সটেনশনের সাথে মিলছে না।'];
    }
    $upload_dir = tenant_upload_abs(__DIR__ . '/..', 'id_card_templates');
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $random_name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $random_name)) {
        return ['ok' => false, 'path' => null, 'error' => 'ফাইল সেভ করা যায়নি।'];
    }
    return ['ok' => true, 'path' => tenant_upload_rel('id_card_templates') . '/' . $random_name, 'error' => null];
}

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['idc_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=print_student_id_card');
        exit();
    }

    // ---- নতুন টেমপ্লেট আপলোড ----
    if (isset($_POST['upload_template'])) {
        $tname = trim($_POST['template_name'] ?? '');
        $tside = ($_POST['template_side'] ?? 'front') === 'back' ? 'back' : 'front';

        if ($tname === '') {
            $_SESSION['idc_err'] = 'টেমপ্লেটের নাম দিন।';
        } else {
            $up = idc_handle_upload('template_image');
            if (!$up['ok']) {
                $_SESSION['idc_err'] = $up['error'];
            } else {
                $pdo->prepare("INSERT INTO id_card_templates (name, side, image_path) VALUES (?, ?, ?)")
                    ->execute([$tname, $tside, $up['path']]);
                $_SESSION['idc_msg'] = 'নতুন টেমপ্লেট আপলোড করা হয়েছে।';
                sec_log_activity($pdo, 'Print', 'Create', "ID Card টেমপ্লেট আপলোড করা হয়েছে: {$tname} ({$tside})");
            }
        }
        header('Location: index.php?page=print_student_id_card');
        exit();
    }

    // ---- টেমপ্লেট ডিলিট ----
    if (isset($_POST['delete_template'])) {
        $tid = (int) ($_POST['id'] ?? 0);
        $tstmt = $pdo->prepare("SELECT image_path FROM id_card_templates WHERE id = ?");
        $tstmt->execute([$tid]);
        $trow = $tstmt->fetch();
        $pdo->prepare("DELETE FROM id_card_templates WHERE id = ?")->execute([$tid]);
        if ($trow && !empty($trow['image_path'])) {
            $abs = __DIR__ . '/../' . $trow['image_path'];
            if (is_file($abs)) @unlink($abs);
        }
        $_SESSION['idc_msg'] = 'টেমপ্লেট মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Print', 'Delete', "ID Card টেমপ্লেট মুছে ফেলা হয়েছে: #{$tid}");
        header('Location: index.php?page=print_student_id_card');
        exit();
    }

    // ---- ID Card জেনারেট (Submit) ----
    if (isset($_POST['generate_cards'])) {
        $_SESSION['idc_generate'] = $_POST; // পরের ধাপে (print view) ব্যবহারের জন্য সেশনে রাখা হচ্ছে

        // যা যা টগল/অপশন এবার সিলেক্ট করা হলো সেটা সেভ করে রাখা হচ্ছে, যাতে পরেরবার
        // এই ফর্মে ফিরে এলে নিজে থেকে OFF হয়ে না যায় — যেটা ON করা হয়েছিল সেটা ON-ই থাকবে
        $pdo->prepare(
            "UPDATE id_card_settings SET
                show_session = ?, show_class_section = ?, show_shift = ?, show_group = ?,
                show_roll = ?, show_dob = ?, show_blood = ?, show_parent_photo = ?, show_parent_phone = ?,
                front_part = ?, back_part = ?, orientation = ?, top_color = ?, bottom_color = ?, establishment = ?,
                show_qr = ?, qr_side = ?
             WHERE id = 1"
        )->execute([
            isset($_POST['show_session']) ? 1 : 0,
            isset($_POST['show_class_section']) ? 1 : 0,
            isset($_POST['show_shift']) ? 1 : 0,
            isset($_POST['show_group']) ? 1 : 0,
            isset($_POST['show_roll']) ? 1 : 0,
            isset($_POST['show_dob']) ? 1 : 0,
            isset($_POST['show_blood']) ? 1 : 0,
            isset($_POST['show_parent_photo']) ? 1 : 0,
            isset($_POST['show_parent_phone']) ? 1 : 0,
            ($_POST['front_part'] ?? 'general') === 'general' ? 'general' : $_POST['front_part'],
            ($_POST['back_part'] ?? 'general') === 'parents' ? 'parents' : 'general',
            ($_POST['orientation'] ?? 'Portrait') === 'Landscape' ? 'Landscape' : 'Portrait',
            trim($_POST['top_color'] ?? '#ef4444') ?: '#ef4444',
            trim($_POST['bottom_color'] ?? '#1e293b') ?: '#1e293b',
            trim($_POST['establishment'] ?? '') ?: null,
            isset($_POST['show_qr']) ? 1 : 0,
            ($_POST['qr_side'] ?? 'Back') === 'Front' ? 'Front' : 'Back',
        ]);
        sec_log_activity($pdo, 'Print', 'Update', 'ID Card সেটিংস আপডেট করা হয়েছে।');

        header('Location: index.php?page=print_student_id_card&view=output');
        exit();
    }
}

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes ORDER BY rank ASC, class_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections ORDER BY section_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts ORDER BY shift_name ASC")->fetchAll();

$front_templates = $pdo->query("SELECT * FROM id_card_templates WHERE side = 'front' ORDER BY id DESC")->fetchAll();
$back_templates  = $pdo->query("SELECT * FROM id_card_templates WHERE side = 'back' ORDER BY id DESC")->fetchAll();

// ID Card ফর্মে Class সিলেক্ট করার পর Shift/Section/Student — সবকটাই সেই Class অনুযায়ী
// ফিল্টার হয়ে দেখানোর জন্য (client-side, নতুন কোনো পেজ-রিলোড ছাড়াই) সব Current Student-এর
// একটা হালকা লিস্ট (id, নাম, আইডি, রোল, class/section/shift) JSON আকারে পাঠানো হচ্ছে।
$idc_where = ['is_current = 1'];
$idc_params = [];
branch_apply_filter($idc_where, $idc_params, 'branch_id');
$idc_students_stmt = $pdo->prepare(
    "SELECT id, name_en, student_id, roll_no, class_id, section_id, shift_id
     FROM students WHERE " . implode(' AND ', $idc_where) . " ORDER BY name_en ASC"
);
$idc_students_stmt->execute($idc_params);
$idc_all_students = $idc_students_stmt->fetchAll();

// একটা Class-এ কোন কোন Shift আছে সেটা academic_sections (class_id + shift_id) থেকে বের করা —
// Shift ড্রপডাউন এখন থেকে সিলেক্ট করা Class অনুযায়ী ফিল্টার হবে
$idc_class_shifts = []; // shift_id => [class_id, class_id, ...]
foreach ($sections as $sec) {
    if ($sec['shift_id']) {
        $idc_class_shifts[(int) $sec['shift_id']][] = (int) $sec['class_id'];
    }
}

$view = $_GET['view'] ?? 'config';
?>

<?php if ($view === 'output'): ?>
    <?php
    // ================= OUTPUT VIEW: আসল কার্ড জেনারেট করা =================
    $g = $_SESSION['idc_generate'] ?? [];
    unset($_SESSION['idc_generate']);

    $g_session = (int) ($g['session_id'] ?? 0);
    $g_class   = (int) ($g['class_id'] ?? 0);
    $g_section = (int) ($g['section_id'] ?? 0);
    $g_shift   = (int) ($g['shift_id'] ?? 0);
    $g_student = (int) ($g['student_id_row'] ?? 0);
    $g_template_front = (int) ($g['template_front_id'] ?? 0);
    $g_template_back  = (int) ($g['template_back_id'] ?? 0);
    $g_top_color    = $g['top_color'] ?? '#ef4444';
    $g_bottom_color = $g['bottom_color'] ?? '#1e293b';
    $g_establishment = icv($g['establishment'] ?? '');
    $g_validation_date = icv($g['validation_date'] ?? '');
    $g_orientation = ($g['orientation'] ?? 'Portrait') === 'Landscape' ? 'Landscape' : 'Portrait';
    $g_show_qr = isset($g['show_qr']);
    $g_qr_side = ($g['qr_side'] ?? 'Back') === 'Front' ? 'Front' : 'Back';

    $toggles = [
        'show_session' => isset($g['show_session']), 'show_group' => isset($g['show_group']),
        'show_class_section' => isset($g['show_class_section']), 'show_roll' => isset($g['show_roll']),
        'show_shift' => isset($g['show_shift']), 'show_dob' => isset($g['show_dob']),
        'show_blood' => isset($g['show_blood']), 'show_parent_photo' => isset($g['show_parent_photo']),
        'show_parent_phone' => isset($g['show_parent_phone']),
    ];

    $where = ['students.is_current = 1'];
    $params = [];
    if ($g_session) { $where[] = 'students.session_id = ?'; $params[] = $g_session; }
    if ($g_class)   { $where[] = 'students.class_id = ?'; $params[] = $g_class; }
    if ($g_section) { $where[] = 'students.section_id = ?'; $params[] = $g_section; }
    if ($g_shift)   { $where[] = 'students.shift_id = ?'; $params[] = $g_shift; }
    if ($g_student) { $where[] = 'students.id = ?'; $params[] = $g_student; }

    $sql = "SELECT students.*, academic_sessions.session_name, academic_classes.class_name,
                   academic_sections.section_name, academic_shifts.shift_name, academic_groups.group_name
            FROM students
            LEFT JOIN academic_sessions ON academic_sessions.id = students.session_id
            LEFT JOIN academic_classes ON academic_classes.id = students.class_id
            LEFT JOIN academic_sections ON academic_sections.id = students.section_id
            LEFT JOIN academic_shifts ON academic_shifts.id = students.shift_id
            LEFT JOIN academic_groups ON academic_groups.id = students.group_id
            WHERE " . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    $front_tpl = null;
    if ($g_template_front) {
        $ts = $pdo->prepare("SELECT * FROM id_card_templates WHERE id = ?");
        $ts->execute([$g_template_front]);
        $front_tpl = $ts->fetch();
    }
    $back_tpl = null;
    if ($g_template_back) {
        $ts2 = $pdo->prepare("SELECT * FROM id_card_templates WHERE id = ?");
        $ts2->execute([$g_template_back]);
        $back_tpl = $ts2->fetch();
    }
    $g_back_part = ($g['back_part'] ?? 'general') === 'parents' ? 'parents' : 'general';
    $inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
    // ব্যাক পার্টের "In case of lost..." লাইনে দেখানোর জন্য ব্রাঞ্চের ঠিকানা —
    // institute_settings-এ আলাদা address ফিল্ড নেই, তাই branches টেবিল থেকে টানা হচ্ছে
    $idc_bid = branch_current_id();
    if ($idc_bid !== null) {
        $idc_branch_stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
        $idc_branch_stmt->execute([$idc_bid]);
        $idc_branch = $idc_branch_stmt->fetch();
    } else {
        $idc_branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
    }
    ?>
    <style>
        @media print {
            .no-print { display: none !important; }
            /* এই সিস্টেমের মূল অ্যাপ শেল (sidebar/topbar/breadcrumb) প্রিন্টে সম্পূর্ণ হাইড,
               নাহলে প্রথম পেজটা সাইডবারের জন্য ফাঁকা চলে যায় আর কার্ড একটার পর একটা
               আলাদা পেজে চলে যায় */
            .sidebar, .sticky-top-area { display: none !important; }
            .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; }
            body { background: #fff !important; margin: 0 !important; }
            @page { size: A4; margin: 6mm; }
            .idcard { margin: 3px !important; }
            .idc-page-break { page-break-before: always; }
        }
        /* Front আর Back — দুইটা আলাদা সেকশনে, একই ক্রমে, যাতে A4 পেজে যতগুলো কার্ড ধরে
           ততগুলো একসাথে সাজিয়ে দেখানো যায় (এক কার্ডের জন্য এক পেজ নষ্ট না হয়) */
        .idc-section { text-align: center; }
        .idc-page-break { height: 0; }
        .idcard {
            width: 320px; height: 202px; border-radius: 10px; overflow: hidden; position: relative;
            display: inline-block; margin: 6px; vertical-align: top; box-shadow: 0 0 4px rgba(0,0,0,0.3);
            background-size: cover; background-position: center; font-family: Arial, sans-serif;
            page-break-inside: avoid;
        }
        .idcard.idcard-portrait { width: 202px; height: 320px; }
        .idcard.idcard-portrait .content { flex-direction: column; align-items: center; text-align: center; padding-top: 14px; }
        .idcard.idcard-portrait .photo { margin: 0 auto; }
        .idcard.idcard-portrait .info { text-align: center; width: 100%; }
        .idcard .top-bar { height: 8px; <?php echo $g_top_color ? "background:{$g_top_color};" : ''; ?> }
        .idcard .bottom-bar { height: 8px; position:absolute; bottom:0; left:0; right:0; <?php echo $g_bottom_color ? "background:{$g_bottom_color};" : ''; ?> }
        .idc-watermark {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            width: 68%; max-height: 68%; object-fit: contain; opacity: 0.14; z-index: 0; pointer-events: none;
        }
        .idcard .header, .idcard .content, .idc-back-footer { position: relative; z-index: 1; }
        .idcard .content { display: flex; padding: 10px; gap: 10px; }
        .idcard .photo { width: 70px; height: 85px; object-fit: cover; border: 2px solid #fff; border-radius: 4px; background:#eee; flex-shrink: 0; }
        .idcard .info { font-size: 11px; color: #111; line-height: 1.6; }
        .idcard .info strong.idc-name { display: block; font-size: 13px; margin-bottom: 4px; text-align: center; }
        .idcard .info-table { display: table; margin: 0 auto; }
        .idcard .info-table .idc-row { display: table-row; }
        .idcard .info-table .idc-label,
        .idcard .info-table .idc-colon,
        .idcard .info-table .idc-value { display: table-cell; vertical-align: top; padding: 1px 0; }
        .idcard .info-table .idc-label { white-space: nowrap; padding-right: 6px; text-align: left; }
        .idcard .info-table .idc-colon { white-space: nowrap; padding-right: 6px; text-align: left; }
        .idcard .info-table .idc-value { text-align: left; }
        .idcard .header { text-align:center; font-size:11px; font-weight:bold; padding-top:4px; }
        .idcard.idcard-back .content { flex-direction: column; justify-content: flex-start; text-align: center; padding: 8px 14px 10px; height: 100%; box-sizing: border-box; }
        .idcard.idcard-back .parent-photo-row { display: flex; gap: 10px; justify-content: center; margin-bottom: 6px; }
        .idcard.idcard-back .parent-photo { width: 42px; height: 50px; object-fit: cover; border: 1px solid #fff; border-radius: 3px; background:#eee; flex-shrink:0; }
        .idc-back-footer { text-align: center; margin-top: 12px; }
        .idc-back-footer img { width: 26px; height: 26px; object-fit: contain; margin-bottom: 2px; }
        .idc-back-footer .idc-lost-line { font-size: 9px; color: #444; padding: 0 6px; line-height: 1.35; }
        .idc-back-footer .idc-lost-title { font-weight: bold; text-decoration: underline; margin-bottom: 2px; }
        .idc-back-footer .idc-lost-inst { font-weight: bold; color: #111; font-size: 9.5px; }
        .idc-back-footer .idc-web-link { color: #1d70b8; font-weight: bold; }
        .idc-qr { position: absolute; bottom: 6px; right: 6px; width: 46px; height: 46px; background: #fff; padding: 2px; border-radius: 3px; z-index: 2; }
    </style>

    <div class="no-print d-flex justify-content-between align-items-center mb-3">
        <a href="index.php?page=print_student_id_card" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>

    <?php if (!$students): ?>
        <div class="card-custom"><p class="text-muted mb-0">নির্বাচিত ফিল্টারে কোনো শিক্ষার্থী পাওয়া যায়নি।</p></div>
    <?php else: ?>
        <div class="no-print"><h6 class="text-muted mb-2">Front Side (সব স্টুডেন্ট)</h6></div>
        <div class="text-center idc-section">
            <?php foreach ($students as $st): ?>
                <div class="idcard<?php echo $g_orientation === 'Portrait' ? ' idcard-portrait' : ''; ?>" style="<?php echo $front_tpl ? "background-image:url('../{$front_tpl['image_path']}');" : 'background:#f0f9ff;border:1px solid #ccc;'; ?>">
                    <div class="top-bar"></div>
                    <?php if (!empty($inst['logo_path'])): ?><img class="idc-watermark" src="../<?php echo icv($inst['logo_path']); ?>"><?php endif; ?>
                    <div class="header"><?php echo icv($inst['institute_name'] ?? ''); ?></div>
                    <div class="content">
                        <?php if (!empty($st['photo_path'])): ?>
                            <img class="photo" src="../<?php echo icv($st['photo_path']); ?>">
                        <?php else: ?>
                            <div class="photo d-flex align-items-center justify-content-center text-muted">Photo</div>
                        <?php endif; ?>
                        <div class="info">
                            <strong class="idc-name"><?php echo icv($st['name_en']); ?></strong>
                            <?php
                            // ID/Class/Roll/Shift/... — লেবেল আর ভ্যালু টেবিলের মতো সারিবদ্ধভাবে (কোলন বরাবর
                            // মেলানো) দেখানোর জন্য প্রতিটা সারি [লেবেল, ভ্যালু] আকারে জমা করা হচ্ছে
                            $idc_rows = [['ID', $st['student_id']]];
                            if ($toggles['show_class_section']) {
                                $idc_rows[] = ['Class', ($st['class_name'] ?? '-') . (!empty($st['section_name']) ? ' (' . $st['section_name'] . ')' : '')];
                            }
                            if ($toggles['show_roll'])   { $idc_rows[] = ['Roll', $st['roll_no']]; }
                            if ($toggles['show_shift'])  { $idc_rows[] = ['Shift', $st['shift_name'] ?? '-']; }
                            if ($toggles['show_session']) { $idc_rows[] = ['Session', $st['session_name'] ?? '-']; }
                            if ($toggles['show_group'] && $st['group_name']) { $idc_rows[] = ['Group', $st['group_name']]; }
                            if ($toggles['show_dob'] && $st['dob']) { $idc_rows[] = ['DOB', $st['dob']]; }
                            if ($toggles['show_blood'] && $st['blood_group']) { $idc_rows[] = ['Blood', $st['blood_group']]; }
                            ?>
                            <div class="info-table">
                                <?php foreach ($idc_rows as $idc_row): ?>
                                    <div class="idc-row">
                                        <div class="idc-label"><?php echo icv($idc_row[0]); ?></div>
                                        <div class="idc-colon">:</div>
                                        <div class="idc-value"><?php echo icv($idc_row[1]); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($g_show_qr && $g_qr_side === 'Front'): ?>
                        <div class="idc-qr" id="qr-front-<?php echo (int) $st['id']; ?>"></div>
                    <?php endif; ?>
                    <div class="bottom-bar"></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="idc-page-break"></div>
        <div class="no-print"><h6 class="text-muted mb-2 mt-3">Back Side (সব স্টুডেন্ট — একই ক্রমে, ফ্রন্টের সাথে মিলিয়ে ডাবল-সাইড প্রিন্ট করা যাবে)</h6></div>
        <div class="text-center idc-section">
            <?php foreach ($students as $st): ?>
                <div class="idcard idcard-back<?php echo $g_orientation === 'Portrait' ? ' idcard-portrait' : ''; ?>" style="<?php echo $back_tpl ? "background-image:url('../{$back_tpl['image_path']}');" : 'background:#f8fafc;border:1px solid #ccc;'; ?>">
                    <div class="top-bar"></div>
                    <?php if (!empty($inst['logo_path'])): ?><img class="idc-watermark" src="../<?php echo icv($inst['logo_path']); ?>"><?php endif; ?>
                    <div class="content">
                        <?php if ($g_back_part === 'parents'): ?>
                            <div class="info">
                                <?php if ($toggles['show_parent_photo'] && (!empty($st['father_photo_path']) || !empty($st['mother_photo_path']))): ?>
                                    <div class="parent-photo-row">
                                        <?php if (!empty($st['father_photo_path'])): ?><img class="parent-photo" src="../<?php echo icv($st['father_photo_path']); ?>"><?php endif; ?>
                                        <?php if (!empty($st['mother_photo_path'])): ?><img class="parent-photo" src="../<?php echo icv($st['mother_photo_path']); ?>"><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php
                                // Father/Mother/Guardian/Address — একই টেবিল-স্টাইলে (কোলন বরাবর মেলানো) দেখানোর জন্য
                                $idc_back_rows = [
                                    ['Father', $st['father_name'] ?? '-'],
                                ];
                                if ($toggles['show_parent_phone']) { $idc_back_rows[] = ['Phone', $st['father_phone'] ?? '-']; }
                                $idc_back_rows[] = ['Mother', $st['mother_name'] ?? '-'];
                                if ($toggles['show_parent_phone']) { $idc_back_rows[] = ['Phone', $st['mother_phone'] ?? '-']; }
                                if ($toggles['show_parent_phone'] && !empty($st['guardian_phone'])) { $idc_back_rows[] = ['Guardian', $st['guardian_phone']]; }
                                if (!empty($st['present_address'])) { $idc_back_rows[] = ['Address', $st['present_address']]; }
                                ?>
                                <div class="info-table">
                                    <?php foreach ($idc_back_rows as $idc_row): ?>
                                        <div class="idc-row">
                                            <div class="idc-label"><?php echo icv($idc_row[0]); ?></div>
                                            <div class="idc-colon">:</div>
                                            <div class="idc-value"><?php echo icv($idc_row[1]); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="info">
                                <?php if ($g_establishment): ?>Established: <?php echo $g_establishment; ?><br><?php endif; ?>
                                <?php if ($g_validation_date): ?>Valid Until: <?php echo $g_validation_date; ?><br><?php endif; ?>
                                <div class="mt-2" style="border-top:1px solid #999; padding-top:2px; width:140px; margin:6px auto 0;">Authority Signature</div>
                            </div>
                        <?php endif; ?>

                        <div class="idc-back-footer">
                            <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo icv($inst['logo_path']); ?>"><?php endif; ?>
                            <div class="idc-lost-line">
                                <div class="idc-lost-title">In case of lost, please inform or return it to</div>
                                <div class="idc-lost-inst"><?php echo icv($inst['institute_name'] ?? ''); ?><?php if (!empty($idc_branch['city'])): ?>, <?php echo icv($idc_branch['city']); ?><?php endif; ?></div>
                                <?php if (!empty($idc_branch['address'])): ?>
                                    <div><?php echo icv($idc_branch['address']); ?><?php if (!empty($idc_branch['city'])): ?>, <?php echo icv($idc_branch['city']); ?><?php endif; ?><?php if (!empty($idc_branch['zip_code'])): ?>, <?php echo icv($idc_branch['zip_code']); ?><?php endif; ?></div>
                                <?php endif; ?>
                                <?php if (!empty($inst['phone_number'])): ?><div>Phone: <?php echo icv($inst['phone_number']); ?></div><?php endif; ?>
                                <div>Email: <?php echo !empty($inst['email']) ? icv($inst['email']) : ''; ?></div>
                                <?php if (!empty($inst['website'])): ?><div class="idc-web-link">Web: <?php echo icv($inst['website']); ?></div><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($g_show_qr && $g_qr_side === 'Back'): ?>
                        <div class="idc-qr" id="qr-back-<?php echo (int) $st['id']; ?>"></div>
                    <?php endif; ?>
                    <div class="bottom-bar"></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($g_show_qr && $students): ?>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
            <?php foreach ($students as $st): ?>
                (function() {
                    var el = document.getElementById('qr-<?php echo $g_qr_side === 'Front' ? 'front' : 'back'; ?>-<?php echo (int) $st['id']; ?>');
                    if (el && window.QRCode) {
                        new QRCode(el, { text: <?php echo json_encode((string) $st['student_id']); ?>, width: 42, height: 42 });
                    }
                })();
            <?php endforeach; ?>
        </script>
    <?php endif; ?>

<?php else: ?>
    <!-- ================= CONFIG VIEW ================= -->
    <div class="card-custom">
        <?php if ($idc_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($idc_msg); ?></div><?php endif; ?>
        <?php if ($idc_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($idc_err); ?></div><?php endif; ?>

        <form method="POST" action="index.php?page=print_student_id_card">
            <?php echo csrf_field(); ?>
            <div class="row">
                <div class="col-md-4">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Session <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="session_id" class="form-select" required>
                                <option value="">Select Session</option>
                                <?php foreach ($sessions as $s): ?><option value="<?php echo (int) $s['id']; ?>"><?php echo icv($s['session_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Section</label>
                        <div class="col-sm-8">
                            <select name="section_id" id="idcSectionSelect" class="form-select" disabled onchange="filterIdcStudents()">
                                <option value="">Select Class first</option>
                                <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" data-shift="<?php echo (int) $sec['shift_id']; ?>" hidden><?php echo icv($sec['section_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Establishment</label>
                        <div class="col-sm-8"><input type="text" name="establishment" class="form-control" placeholder="i.e : 1951" value="<?php echo icv($idc_settings['establishment'] ?? ''); ?>"></div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Class <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="class_id" id="idcClassSelect" class="form-select" required onchange="filterIdcSections(); filterIdcShifts(); filterIdcStudents();">
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo icv($c['class_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Student</label>
                        <div class="col-sm-8 position-relative">
                            <input type="hidden" name="student_id_row" id="idcStudentIdInput" value="">
                            <input type="text" id="idcStudentSearchInput" class="form-control" placeholder="সবাই (Bulk) — অথবা Name/ID/Roll লিখে একজনকে খুঁজুন" autocomplete="off" disabled>
                            <div id="idcStudentSuggestions" class="list-group shadow-sm" style="position:absolute; z-index:20; width:100%; max-height:220px; overflow-y:auto; display:none;"></div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-5 col-form-label fw-bold text-end">Front Part</label>
                        <div class="col-sm-7 pt-2">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="front_part" value="general" checked>
                                <label class="form-check-label">General</label>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-5 col-form-label fw-bold text-end">Orientation</label>
                        <div class="col-sm-7 pt-2">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="orientation" value="Portrait" <?php echo ($idc_settings['orientation'] ?? 'Portrait') === 'Portrait' ? 'checked' : ''; ?>>
                                <label class="form-check-label">Portrait</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="orientation" value="Landscape" <?php echo ($idc_settings['orientation'] ?? 'Portrait') === 'Landscape' ? 'checked' : ''; ?>>
                                <label class="form-check-label">Landscape</label>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-5 col-form-label fw-bold text-end">QR Code</label>
                        <div class="col-sm-7 pt-2">
                            <div class="form-check form-switch d-inline-block me-3">
                                <input class="form-check-input" type="checkbox" name="show_qr" id="idcShowQr" <?php echo !empty($idc_settings['show_qr']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="idcShowQr">Show QR</label>
                            </div>
                            <select name="qr_side" class="form-select form-select-sm d-inline-block" style="width:auto;">
                                <option value="Front" <?php echo ($idc_settings['qr_side'] ?? 'Back') === 'Front' ? 'selected' : ''; ?>>QR বসবে সামনে</option>
                                <option value="Back" <?php echo ($idc_settings['qr_side'] ?? 'Back') === 'Back' ? 'selected' : ''; ?>>QR বসবে পেছনে</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Shift</label>
                        <div class="col-sm-8">
                            <select name="shift_id" id="idcShiftSelect" class="form-select" disabled onchange="filterIdcSections(); filterIdcStudents();">
                                <option value="">Select Class first</option>
                                <?php foreach ($shifts as $sh): ?><option value="<?php echo (int) $sh['id']; ?>" data-classes="<?php echo icv(implode(',', $idc_class_shifts[$sh['id']] ?? [])); ?>" hidden><?php echo icv($sh['shift_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Validation Date <span class="text-danger">*</span></label>
                        <div class="col-sm-8"><input type="date" name="validation_date" class="form-control" required></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-5 col-form-label fw-bold text-end">Back Part</label>
                        <div class="col-sm-7 pt-2">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="back_part" value="general" <?php echo ($idc_settings['back_part'] ?? 'general') === 'general' ? 'checked' : ''; ?>>
                                <label class="form-check-label">General</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="back_part" value="parents" <?php echo ($idc_settings['back_part'] ?? 'general') === 'parents' ? 'checked' : ''; ?>>
                                <label class="form-check-label">Parent's Info</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-2">
                <div class="col-md-4">
                    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="show_session" id="tSession" <?php echo !empty($idc_settings['show_session']) ? 'checked' : ''; ?>><label class="form-check-label" for="tSession">Show Session</label></div>
                    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="show_class_section" id="tClassSec" <?php echo !empty($idc_settings['show_class_section']) ? 'checked' : ''; ?>><label class="form-check-label" for="tClassSec">Show Class &amp; Section</label></div>
                    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="show_shift" id="tShift" <?php echo !empty($idc_settings['show_shift']) ? 'checked' : ''; ?>><label class="form-check-label" for="tShift">Show Shift</label></div>
                </div>
                <div class="col-md-4">
                    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="show_group" id="tGroup" <?php echo !empty($idc_settings['show_group']) ? 'checked' : ''; ?>><label class="form-check-label" for="tGroup">Show Group</label></div>
                    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="show_roll" id="tRoll" <?php echo !empty($idc_settings['show_roll']) ? 'checked' : ''; ?>><label class="form-check-label" for="tRoll">Show Roll No.</label></div>
                    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="show_dob" id="tDob" <?php echo !empty($idc_settings['show_dob']) ? 'checked' : ''; ?>><label class="form-check-label" for="tDob">Date of Birth</label></div>
                </div>
                <div class="col-md-4">
                    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="show_blood" id="tBlood" <?php echo !empty($idc_settings['show_blood']) ? 'checked' : ''; ?>><label class="form-check-label" for="tBlood">Blood Group</label></div>
                    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="show_parent_photo" id="tPPhoto" <?php echo !empty($idc_settings['show_parent_photo']) ? 'checked' : ''; ?>><label class="form-check-label" for="tPPhoto">Show Parent's Picture</label></div>
                    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="show_parent_phone" id="tPPhone" <?php echo !empty($idc_settings['show_parent_phone']) ? 'checked' : ''; ?>><label class="form-check-label" for="tPPhone">Show parent's Phone</label></div>
                </div>
            </div>

            <div class="row mt-3 align-items-center">
                <label class="col-sm-2 col-form-label fw-bold">Select ID Card Template (Front)</label>
                <div class="col-sm-10">
                    <div class="d-flex gap-3 flex-wrap">
                        <?php if ($front_templates): foreach ($front_templates as $t): ?>
                            <label class="text-center">
                                <input type="radio" name="template_front_id" value="<?php echo (int) $t['id']; ?>" class="d-block mx-auto mb-1">
                                <img src="../<?php echo icv($t['image_path']); ?>" style="width:110px;height:70px;object-fit:cover;border:2px solid #ddd;border-radius:6px;">
                                <div class="small text-muted"><?php echo icv($t['name']); ?></div>
                            </label>
                        <?php endforeach; else: ?>
                            <p class="text-muted small">কোনো Front টেমপ্লেট নাই — নিচে থেকে একটা আপলোড করুন।</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="row mt-3 align-items-center">
                <label class="col-sm-2 col-form-label fw-bold">Top / Bottom Color</label>
                <div class="col-sm-10 d-flex gap-3 align-items-center">
                    <label>Top: <input type="color" name="top_color" value="<?php echo icv($idc_settings['top_color'] ?? '#ef4444'); ?>"></label>
                    <label>Bottom: <input type="color" name="bottom_color" value="<?php echo icv($idc_settings['bottom_color'] ?? '#1e293b'); ?>"></label>
                </div>
            </div>

            <hr>
            <div class="text-center">
                <button type="submit" name="generate_cards" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;">Submit</button>
            </div>
        </form>
    </div>

    <!-- ================= টেমপ্লেট আপলোড / ম্যানেজমেন্ট ================= -->
    <div class="card-custom mt-3">
        <h6 class="fw-bold mb-3"><i class="fa-solid fa-upload"></i> নতুন ID Card Template আপলোড করুন</h6>
        <form method="POST" action="index.php?page=print_student_id_card" enctype="multipart/form-data" class="row g-2 align-items-end">
            <?php echo csrf_field(); ?>
            <div class="col-md-3">
                <label class="form-label small">Template Name</label>
                <input type="text" name="template_name" class="form-control" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Side</label>
                <select name="template_side" class="form-select">
                    <option value="front">Front</option>
                    <option value="back">Back</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Template Image (jpg/png, max 2MB)</label>
                <input type="file" name="template_image" class="form-control" accept=".jpg,.jpeg,.png" required>
            </div>
            <div class="col-md-3">
                <button type="submit" name="upload_template" class="btn btn-success w-100">Upload Template</button>
            </div>
        </form>

        <?php if ($front_templates || $back_templates): ?>
            <hr>
            <h6 class="fw-bold mb-2">বর্তমান টেমপ্লেট সমূহ</h6>
            <div class="d-flex gap-3 flex-wrap">
                <?php foreach (array_merge($front_templates, $back_templates) as $t): $csrf = htmlspecialchars(csrf_token()); ?>
                    <div class="text-center border rounded p-2" style="width:130px;">
                        <img src="../<?php echo icv($t['image_path']); ?>" style="width:110px;height:70px;object-fit:cover;border-radius:4px;">
                        <div class="small mt-1"><?php echo icv($t['name']); ?> <span class="badge bg-secondary"><?php echo icv($t['side']); ?></span></div>
                        <form method="POST" action="index.php?page=print_student_id_card" onsubmit="return confirm('মুছে ফেলবেন?');">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="id" value="<?php echo (int) $t['id']; ?>">
                            <button type="submit" name="delete_template" class="btn btn-sm btn-outline-danger mt-1">Delete</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // student_id, name_en, roll_no, class_id, section_id, shift_id সহ Current সব স্টুডেন্টের হালকা লিস্ট —
        // Class/Section/Shift সিলেক্ট করলে এখান থেকেই client-side ফিল্টার হয়ে Student সার্চে দেখানো হবে,
        // কোনো পেজ-রিলোড লাগবে না।
        const idcAllStudents = <?php echo json_encode(array_map(function ($s) {
            return [
                'id' => (int) $s['id'], 'name' => $s['name_en'], 'sid' => $s['student_id'],
                'roll' => $s['roll_no'], 'class_id' => (int) $s['class_id'],
                'section_id' => (int) $s['section_id'], 'shift_id' => (int) $s['shift_id'],
            ];
        }, $idc_all_students), JSON_UNESCAPED_UNICODE); ?>;

        // Class সিলেক্ট না করা পর্যন্ত Section/Shift/Student — তিনটাই বন্ধ থাকবে,
        // যাতে ভুল ক্লাসের সেকশন/শিফট/স্টুডেন্ট দেখা না যায় ও একই নামের সেকশন বারবার না আসে
        function filterIdcSections() {
            const classId = document.getElementById('idcClassSelect').value;
            const shiftId = document.getElementById('idcShiftSelect').value;
            const select = document.getElementById('idcSectionSelect');
            select.disabled = !classId;
            let visibleCount = 0;
            Array.from(select.options).forEach(opt => {
                if (!opt.value) { opt.hidden = false; return; }
                const classOk = !!classId && opt.dataset.class === classId;
                const shiftOk = !shiftId || !opt.dataset.shift || opt.dataset.shift === '0' || opt.dataset.shift === shiftId;
                opt.hidden = !(classOk && shiftOk);
                if (!opt.hidden) visibleCount++;
            });
            select.options[0].textContent = classId ? 'Select Section' : 'Select Class first';
            if (select.value && Array.from(select.options).find(o => o.value === select.value)?.hidden) {
                select.value = '';
            }
        }

        function filterIdcShifts() {
            const classId = document.getElementById('idcClassSelect').value;
            const select = document.getElementById('idcShiftSelect');
            select.disabled = !classId;
            Array.from(select.options).forEach(opt => {
                if (!opt.value) { opt.hidden = false; return; }
                const classes = (opt.dataset.classes || '').split(',').filter(Boolean);
                // এই Shift-টা যদি কোনো Section-এর সাথে এখনো লিংক করা না থাকে (data-classes ফাঁকা),
                // নিরাপদ থাকার জন্য সেটাকে সব Class-এর জন্যই দেখানো হবে — বাদ দেওয়া হবে না
                opt.hidden = !!classId && classes.length > 0 && !classes.includes(classId);
            });
            select.options[0].textContent = classId ? 'Select Shift' : 'Select Class first';
            if (select.value && Array.from(select.options).find(o => o.value === select.value)?.hidden) {
                select.value = '';
            }
        }

        function idcRenderStudentList(query) {
            const classId  = document.getElementById('idcClassSelect').value;
            const sectionId = document.getElementById('idcSectionSelect').value;
            const shiftId  = document.getElementById('idcShiftSelect').value;
            const box = document.getElementById('idcStudentSuggestions');
            const q = (query || '').trim().toLowerCase();

            if (!classId) { box.style.display = 'none'; box.innerHTML = ''; return; }

            const matches = idcAllStudents.filter(s =>
                String(s.class_id) === classId
                && (!sectionId || String(s.section_id) === sectionId)
                && (!shiftId || String(s.shift_id) === shiftId)
                && (
                    q === '' ||
                    (s.name || '').toLowerCase().includes(q)
                    || (s.sid || '').toLowerCase().includes(q)
                    || (s.roll || '').toLowerCase().includes(q)
                )
            ).slice(0, 100);

            if (matches.length === 0) {
                box.innerHTML = '<div class="list-group-item text-muted small">এই Class/Section-এ কোনো স্টুডেন্ট পাওয়া যায়নি</div>';
                box.style.display = 'block';
                return;
            }
            box.innerHTML = matches.map(s =>
                `<button type="button" class="list-group-item list-group-item-action small idc-suggest-item" data-id="${s.id}" data-label="${(s.name || '').replace(/"/g, '&quot;')} (${s.sid || ''})">
                    ${s.name} <span class="text-muted">— ID: ${s.sid || '-'}, Roll: ${s.roll || '-'}</span>
                 </button>`
            ).join('');
            box.style.display = 'block';
        }

        function filterIdcStudents() {
            const classId  = document.getElementById('idcClassSelect').value;
            const searchInput = document.getElementById('idcStudentSearchInput');
            searchInput.disabled = !classId;
            // Class/Section/Shift বদলালে আগের সিলেক্ট করা Student রিসেট হয়ে যাবে (Bulk-এ ফিরে যাবে)
            document.getElementById('idcStudentIdInput').value = '';
            searchInput.value = '';
            searchInput.placeholder = classId
                ? 'সবাই (Bulk) — নিচে Class/Section অনুযায়ী লিস্ট, অথবা Name/ID/Roll দিয়ে খুঁজুন'
                : 'আগে Class সিলেক্ট করুন';
            // Class সিলেক্ট করলেই ওই Class/Section-এর স্টুডেন্ট লিস্ট সাথে সাথে দেখাবে (সার্চ করা বাধ্যতামূলক না)
            idcRenderStudentList('');
        }

        document.getElementById('idcStudentSearchInput').addEventListener('input', function () {
            document.getElementById('idcStudentIdInput').value = ''; // নতুন করে টাইপ করলে আগের সিলেকশন ক্লিয়ার
            idcRenderStudentList(this.value);
        });

        document.getElementById('idcStudentSearchInput').addEventListener('focus', function () {
            if (!this.disabled) idcRenderStudentList(this.value);
        });

        document.getElementById('idcStudentSuggestions').addEventListener('click', function (e) {
            const item = e.target.closest('.idc-suggest-item');
            if (!item) return;
            document.getElementById('idcStudentIdInput').value = item.dataset.id;
            document.getElementById('idcStudentSearchInput').value = item.dataset.label;
            this.style.display = 'none';
        });

        document.addEventListener('click', function (e) {
            const box = document.getElementById('idcStudentSuggestions');
            if (!e.target.closest('#idcStudentSearchInput') && !e.target.closest('#idcStudentSuggestions')) {
                box.style.display = 'none';
            }
        });

        filterIdcSections();
        filterIdcShifts();
        filterIdcStudents();
    </script>
<?php endif; ?>
