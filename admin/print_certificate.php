<?php
// admin/print_certificate.php - Print Certificate (প্রত্যয়নপত্র)
// একটি সাধারণ/বহুমুখী সনদপত্র জেনারেটর — Merit, Achievement, Appreciation, Sports,
// Participation, Perfect Attendance, Good Conduct অথবা সম্পূর্ণ Custom টেক্সট দিয়ে
// সনদ তৈরি করা যায়। Print Student Testimonial / Print Transfer Certificate পেজের
// সাথে সামঞ্জস্যপূর্ণ প্যাটার্ন অনুসরণ করে বানানো হয়েছে।
function gcv(?string $val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// ব্রাউজারের Back বাটনে চাপলে যাতে bfcache থেকে পুরনো (ফর্মে ভরা/আগের আউটপুট)
// পেজ না দেখিয়ে সার্ভার থেকে টাটকা/ফাঁকা পেজ রিলোড হয়, সেজন্য no-cache header
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// -----------------------------------------------------------
// টেবিল না থাকলে অটো-তৈরি (certificate_config) — Transfer Certificate/Testimonial এর
// নিজস্ব টেবিল থেকে আলাদা, যাতে সেটিংস একে অপরের সাথে conflict না করে
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS certificate_config (
        id INT PRIMARY KEY DEFAULT 1,
        template_style VARCHAR(20) NOT NULL DEFAULT 'style1',
        custom_template_path VARCHAR(255) NULL,
        positions_json TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
if ((int) $pdo->query("SELECT COUNT(*) FROM certificate_config")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO certificate_config (id) VALUES (1)");
}

$view = $_GET['view'] ?? 'config';

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes ORDER BY rank ASC, class_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections ORDER BY section_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts ORDER BY shift_name ASC")->fetchAll();

// একটা Class-এ কোন কোন Shift আছে (Class সিলেক্ট না করা পর্যন্ত Shift/Section বন্ধ থাকবে)
$gc_class_shifts = [];
foreach ($sections as $sec) {
    if ($sec['shift_id']) {
        $gc_class_shifts[(int) $sec['shift_id']][] = (int) $sec['class_id'];
    }
}

$gc_stu_where = ['students.is_current = 1'];
$gc_stu_params = [];
branch_apply_filter($gc_stu_where, $gc_stu_params, 'students.branch_id');
$gc_stu_stmt = $pdo->prepare(
    "SELECT students.id, students.name_en, students.name_bn, students.student_id, students.roll_no,
            students.class_id, students.section_id, students.shift_id,
            academic_classes.class_name, academic_sections.section_name, academic_sessions.session_name
     FROM students
     LEFT JOIN academic_classes ON academic_classes.id = students.class_id
     LEFT JOIN academic_sections ON academic_sections.id = students.section_id
     LEFT JOIN academic_sessions ON academic_sessions.id = students.session_id
     WHERE " . implode(' AND ', $gc_stu_where) . " ORDER BY students.name_en ASC LIMIT 2000"
);
$gc_stu_stmt->execute($gc_stu_params);
$all_students = $gc_stu_stmt->fetchAll();

// -----------------------------------------------------------
// সনদের ধরন — প্রতিটার নিজস্ব বাংলা/ইংরেজি শিরোনাম ও ডিফল্ট কারণ (reason) আছে,
// তবে ব্যবহারকারী ফর্মে চাইলে reason টেক্সট বদলে নিজের মতো লিখতে পারবে
// -----------------------------------------------------------
$cert_types = [
    'merit'         => ['label' => 'Certificate of Merit (মেধা সনদ)',
                         'title_en' => 'CERTIFICATE OF MERIT', 'title_bn' => 'মেধা সনদপত্র',
                         'reason_en' => 'outstanding academic performance', 'reason_bn' => 'অসামান্য একাডেমিক ফলাফলের'],
    'achievement'   => ['label' => 'Certificate of Achievement (কৃতিত্ব সনদ)',
                         'title_en' => 'CERTIFICATE OF ACHIEVEMENT', 'title_bn' => 'কৃতিত্ব সনদপত্র',
                         'reason_en' => 'notable achievement in academic and co-curricular activities', 'reason_bn' => 'শিক্ষা ও সহশিক্ষা কার্যক্রমে উল্লেখযোগ্য কৃতিত্বের'],
    'appreciation'  => ['label' => 'Certificate of Appreciation (সম্মাননা সনদ)',
                         'title_en' => 'CERTIFICATE OF APPRECIATION', 'title_bn' => 'সম্মাননা সনদপত্র',
                         'reason_en' => 'valuable contribution to the institution', 'reason_bn' => 'প্রতিষ্ঠানে মূল্যবান অবদানের'],
    'excellence'    => ['label' => 'Certificate of Excellence (শ্রেষ্ঠত্ব সনদ)',
                         'title_en' => 'CERTIFICATE OF EXCELLENCE', 'title_bn' => 'শ্রেষ্ঠত্ব সনদপত্র',
                         'reason_en' => 'excellence in overall academic performance', 'reason_bn' => 'সার্বিক একাডেমিক ফলাফলে শ্রেষ্ঠত্বের'],
    'sports'        => ['label' => 'Sports Certificate (ক্রীড়া সনদ)',
                         'title_en' => 'SPORTS CERTIFICATE', 'title_bn' => 'ক্রীড়া সনদপত্র',
                         'reason_en' => 'outstanding performance in the annual sports competition', 'reason_bn' => 'বার্ষিক ক্রীড়া প্রতিযোগিতায় অসামান্য পারফরম্যান্সের'],
    'participation' => ['label' => 'Certificate of Participation (অংশগ্রহণ সনদ)',
                         'title_en' => 'CERTIFICATE OF PARTICIPATION', 'title_bn' => 'অংশগ্রহণ সনদপত্র',
                         'reason_en' => 'active participation in the program', 'reason_bn' => 'কর্মসূচিতে সক্রিয় অংশগ্রহণের'],
    'attendance'    => ['label' => 'Perfect Attendance Certificate (উপস্থিতি সনদ)',
                         'title_en' => 'CERTIFICATE OF PERFECT ATTENDANCE', 'title_bn' => 'পরিপূর্ণ উপস্থিতি সনদপত্র',
                         'reason_en' => 'maintaining perfect attendance throughout the academic session', 'reason_bn' => 'শিক্ষাবর্ষ জুড়ে পরিপূর্ণ উপস্থিতি বজায় রাখার'],
    'conduct'       => ['label' => 'Good Conduct Certificate (সদাচরণ সনদ)',
                         'title_en' => 'CERTIFICATE OF GOOD CONDUCT', 'title_bn' => 'সদাচরণ সনদপত্র',
                         'reason_en' => 'exemplary conduct, discipline and moral character', 'reason_bn' => 'অনুকরণীয় আচরণ, শৃঙ্খলা ও নৈতিক চরিত্রের'],
    'custom'        => ['label' => 'Custom / Blank Certificate (কাস্টম সনদ)',
                         'title_en' => 'CERTIFICATE', 'title_bn' => 'সনদপত্র',
                         'reason_en' => 'this special recognition', 'reason_bn' => 'এই বিশেষ স্বীকৃতির'],
];

// সনদের মূল বডি টেক্সট তৈরি — $reason ফাঁকা থাকলে ধরনভিত্তিক ডিফল্ট reason বসবে
function gc_build_body(string $type, string $lang, array $cert_types, string $name, string $sid, string $class_name, string $section_name, string $session_name, string $reason): string
{
    $t = $cert_types[$type] ?? $cert_types['custom'];
    $reason = trim($reason);
    if ($reason === '') {
        $reason = $lang === 'bn' ? $t['reason_bn'] : $t['reason_en'];
    }
    $reason = gcv($reason);
    $name = gcv($name); $sid = gcv($sid); $class_name = gcv($class_name);
    $section_name = gcv($section_name); $session_name = gcv($session_name);

    if ($type === 'custom') {
        if ($lang === 'bn') {
            return "এই মর্মে প্রত্যয়ন করা যাচ্ছে যে, <b>{$name}</b> (স্টুডেন্ট আইডি: <b>{$sid}</b>), শ্রেণি <b>{$class_name}</b>, শাখা <b>{$section_name}</b>, শিক্ষাবর্ষ <b>{$session_name}</b> — {$reason}।";
        }
        return "This is to certify that <b>{$name}</b> (Student ID: <b>{$sid}</b>) of Class <b>{$class_name}</b>, Section <b>{$section_name}</b>, academic session <b>{$session_name}</b> — {$reason}.";
    }

    if ($lang === 'bn') {
        return "এই মর্মে প্রত্যয়ন করা যাচ্ছে যে, <b>{$name}</b> (স্টুডেন্ট আইডি: <b>{$sid}</b>), শ্রেণি <b>{$class_name}</b>, শাখা <b>{$section_name}</b> — শিক্ষাবর্ষ <b>{$session_name}</b>-এ {$reason} জন্য এই সনদপত্র প্রদান করা হলো।"
             . "<br><br>তার আন্তরিক প্রচেষ্টা ও নিষ্ঠার জন্য আমরা কৃতজ্ঞতা প্রকাশ করছি এবং ভবিষ্যতের সকল কাজে সাফল্য কামনা করছি।";
    }
    return "This is to certify that <b>{$name}</b> (Student ID: <b>{$sid}</b>) of Class <b>{$class_name}</b>, Section <b>{$section_name}</b>, has been awarded this certificate for {$reason} during the academic session <b>{$session_name}</b>."
         . "<br><br>We appreciate his/her sincere effort and dedication, and wish him/her continued success in all future endeavors.";
}

// ডিফল্ট পজিশন (ইঞ্চিতে, A4 পেজের উপর-বাম কোণ থেকে) — "Blank/Pre-printed Card" মোডে ব্যবহার হয়
$default_positions = [
    'header_top' => 0.5, 'header_left' => 1.0, 'header_visible' => 1,
    'title_top'  => 2.2, 'title_left'  => 1.0,
    'body_top'   => 2.8, 'body_left'   => 1.0, 'body_width' => 6.5,
    'date_top'   => 1.85, 'date_right'  => 0.6,
    'sign_top'   => 6.5, 'sign_left'   => 5.5,
];

// -----------------------------------------------------------
// কাস্টম টেমপ্লেট (background/letterhead) আপলোড হেল্পার
// -----------------------------------------------------------
function gc_handle_upload(string $field): array
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'error' => null];
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'path' => null, 'error' => 'ফাইল আপলোডে সমস্যা হয়েছে।'];
    if ($file['size'] > 3 * 1024 * 1024) return ['ok' => false, 'path' => null, 'error' => 'ফাইলের সাইজ সর্বোচ্চ 3MB হতে হবে।'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    if (!isset($allowed_mimes[$ext])) return ['ok' => false, 'path' => null, 'error' => 'শুধু jpg/png ছবি আপলোড করা যাবে।'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== $allowed_mimes[$ext]) return ['ok' => false, 'path' => null, 'error' => 'ফাইলের প্রকৃত কনটেন্ট এক্সটেনশনের সাথে মিলছে না।'];
    $upload_dir = tenant_upload_abs(__DIR__ . '/..', 'general_certificate_templates');
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    $random_name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $random_name)) return ['ok' => false, 'path' => null, 'error' => 'ফাইল সেভ করা যায়নি।'];
    return ['ok' => true, 'path' => tenant_upload_rel('general_certificate_templates') . '/' . $random_name, 'error' => null];
}

// -----------------------------------------------------------
// টেমপ্লেট + পজিশন সেভ করা
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_template_settings'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['gc_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=print_certificate');
        exit();
    }

    $style = $_POST['template_style'] ?? 'style1';
    $allowed_styles = ['style1', 'style2', 'style3', 'style4', 'style5', 'style6', 'style7', 'custom', 'blank'];
    if (!in_array($style, $allowed_styles, true)) $style = 'style1';

    $positions = $default_positions;
    foreach (array_keys($default_positions) as $key) {
        if ($key === 'header_visible') {
            $positions[$key] = isset($_POST['pos_header_visible']) ? 1 : 0;
        } else {
            $positions[$key] = (float) ($_POST['pos_' . $key] ?? $default_positions[$key]);
        }
    }

    $custom_path = null;
    if ($style === 'custom') {
        $up = gc_handle_upload('custom_template');
        if (!$up['ok'] && $up['error']) {
            $_SESSION['gc_err'] = $up['error'];
            header('Location: index.php?page=print_certificate');
            exit();
        }
        $custom_path = $up['path'];
    }

    $stmt = $pdo->prepare(
        "UPDATE certificate_config SET template_style = ?, custom_template_path = COALESCE(?, custom_template_path), positions_json = ? WHERE id = 1"
    );
    $stmt->execute([$style, $custom_path, json_encode($positions)]);
    $_SESSION['gc_msg'] = 'টেমপ্লেট সেটিংস সেভ করা হয়েছে।';
    sec_log_activity($pdo, 'Print', 'Update', "Certificate টেমপ্লেট সেটিংস আপডেট করা হয়েছে ({$style})।");
    header('Location: index.php?page=print_certificate');
    exit();
}

// -----------------------------------------------------------
// কাস্টম টেমপ্লেট মুছে ফেলা
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_certificate_template'])) {
    if (csrf_verify($_POST['csrf_token'] ?? null)) {
        $old = $pdo->query("SELECT custom_template_path FROM certificate_config WHERE id = 1")->fetch();
        if (!empty($old['custom_template_path'])) {
            $old_abs = __DIR__ . '/../' . $old['custom_template_path'];
            if (is_file($old_abs)) { @unlink($old_abs); }
        }
        $pdo->prepare("UPDATE certificate_config SET custom_template_path = NULL, template_style = IF(template_style = 'custom', 'style1', template_style) WHERE id = 1")->execute();
        $_SESSION['gc_msg'] = 'কাস্টম টেমপ্লেট মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Print', 'Delete', 'কাস্টম সার্টিফিকেট টেমপ্লেট মুছে ফেলা হয়েছে।');
    }
    header('Location: index.php?page=print_certificate');
    exit();
}

// -----------------------------------------------------------
// Generate (Print) সাবমিট
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_certificate'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['gc_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=print_certificate');
        exit();
    }
    $_SESSION['gc_generate'] = $_POST;
    header('Location: index.php?page=print_certificate&view=output');
    exit();
}

$config = $pdo->query("SELECT * FROM certificate_config WHERE id = 1")->fetch();
if (!$config) $config = ['template_style' => 'style1', 'custom_template_path' => null, 'positions_json' => null];
$saved_positions = $config['positions_json'] ? json_decode($config['positions_json'], true) : $default_positions;
$saved_positions = array_merge($default_positions, $saved_positions ?: []);
?>
<script>
    // ব্রাউজার bfcache থেকে এই পেজ দেখালে (event.persisted === true) জোর করে
    // সার্ভার থেকে রিলোড করাচ্ছে, যাতে Back বাটনে সবসময় টাটকা/ফাঁকা পেজ আসে
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            window.location.reload();
        }
    });
</script>

<?php if ($view === 'output' && !empty($_SESSION['gc_generate'])): ?>
    <?php
    $g = $_SESSION['gc_generate'];
    unset($_SESSION['gc_generate']);

    $g_session = (int) ($g['session_id'] ?? 0);
    $g_class   = (int) ($g['class_id'] ?? 0);
    $g_shift   = (int) ($g['shift_id'] ?? 0);
    $g_section = (int) ($g['section_id'] ?? 0);
    $g_student = (int) ($g['student_row_id'] ?? 0);
    $g_lang    = ($g['language'] ?? 'en') === 'bn' ? 'bn' : 'en';
    $g_type    = array_key_exists($g['cert_type'] ?? '', $cert_types) ? $g['cert_type'] : 'merit';
    $g_reason  = trim((string) ($g['reason'] ?? ''));
    $g_date    = !empty($g['cert_date']) ? $g['cert_date'] : date('Y-m-d');

    $where = ['students.is_current = 1'];
    $params = [];
    if ($g_session) { $where[] = 'students.session_id = ?'; $params[] = $g_session; }
    if ($g_class)   { $where[] = 'students.class_id = ?'; $params[] = $g_class; }
    if ($g_shift)   { $where[] = 'students.shift_id = ?'; $params[] = $g_shift; }
    if ($g_section) { $where[] = 'students.section_id = ?'; $params[] = $g_section; }
    if ($g_student) { $where[] = 'students.id = ?'; $params[] = $g_student; }

    $sql = "SELECT students.*, academic_sessions.session_name, academic_classes.class_name, academic_sections.section_name
            FROM students
            LEFT JOIN academic_sessions ON academic_sessions.id = students.session_id
            LEFT JOIN academic_classes ON academic_classes.id = students.class_id
            LEFT JOIN academic_sections ON academic_sections.id = students.section_id
            WHERE " . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    $inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
    $fr_bid2 = branch_current_id();
    if ($fr_bid2 !== null) {
        $branch_stmt2 = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
        $branch_stmt2->execute([$fr_bid2]);
        $branch = $branch_stmt2->fetch();
    } else {
        $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
    }
    $addr_line = $branch ? ($branch['address'] . ', ' . $branch['city'] . ', ' . $branch['zip_code']) : '';

    $style = $config['template_style'];
    $pos = $saved_positions;
    $t = $cert_types[$g_type];
    $title = $g_lang === 'bn' ? $t['title_bn'] : $t['title_en'];
    ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Great+Vibes&display=swap');
        @media print { .no-print { display: none !important; } .cert-page { page-break-after: always; box-shadow:none !important; } }
        .cert-page { width: 8.27in; height: 11.69in; margin: 0 auto 30px; background: #fff; position: relative; box-shadow: 0 0 6px rgba(0,0,0,0.25); font-family: Georgia, serif; overflow: hidden; }
        .cert-inner-border { position:absolute; inset:0.3in; border: 3px solid #1d70b8; }
        .cert-inner-border2 { position:absolute; inset:0.42in; border: 1px solid #1d70b8; }
        .cert-stripe-top { position:absolute; top:0.3in; left:0.3in; right:0.3in; height:14px; background: repeating-linear-gradient(90deg, #e0559f 0 10px, transparent 10px 20px); }
        .cert-ribbon { position:absolute; top:0.3in; left:0.3in; right:0.3in; height:8px; }
        .cert-corner { position:absolute; width:26px; height:26px; border: 3px solid #1d70b8; border-radius:50%; }
        .cert-corner-bracket { position:absolute; width:36px; height:36px; }
        .cert-cb-tl { top:0.38in; left:0.38in; border-top:3px solid; border-left:3px solid; }
        .cert-cb-tr { top:0.38in; right:0.38in; border-top:3px solid; border-right:3px solid; }
        .cert-cb-bl { bottom:0.38in; left:0.38in; border-bottom:3px solid; border-left:3px solid; }
        .cert-cb-br { bottom:0.38in; right:0.38in; border-bottom:3px solid; border-right:3px solid; }
        .cert-diamond { position:absolute; width:12px; height:12px; transform:rotate(45deg); border:2px solid; }
        .cert-watermark { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); max-width:3.6in; max-height:3.6in; opacity:0.07; object-fit:contain; }
        .cert-header { position:absolute; text-align:center; width:6.3in; }
        .cert-header img { max-height: 60px; }
        .cert-header h3 { font-weight:bold; color:#1e3a5f; margin: 6px 0 2px; }
        .cert-header p { font-size: 12px; color:#555; margin:0; }
        .cert-title { position:absolute; left:0; right:0; width:100%; text-align:center; font-weight:bold; font-size: 18px; letter-spacing: 1px; text-decoration: underline; color:#333; }
        .cert-date { position:absolute; font-size: 12px; color:#555; white-space: nowrap; text-align: right; }
        .cert-body { position:absolute; font-size: 13.5px; line-height: 2; color:#222; }
        .cert-sign { position:absolute; text-align:center; font-size:12px; }
        .cert-sign .line { border-top:1px solid #333; width:180px; margin-bottom:4px; }

        /* --- Style 7: Ornate Gold Certificate --- */
        .cert-style7 { background:#faf6ea !important; }
        .cert-style7-pattern { position:absolute; inset:0; background: repeating-linear-gradient(45deg, rgba(184,134,11,0.06) 0 2px, transparent 2px 16px); }
        .cert-style7-outer { position:absolute; inset:0.2in; border:2px solid #b8860b; }
        .cert-style7-band { position:absolute; inset:0.3in; border:1px dotted #b8860b; }
        .cert-style7-band2 { position:absolute; inset:0.36in; border:1px solid #8a6d1f; }
        .cert-style7-inner { position:absolute; inset:0.5in; border:1px solid #b8860b; }
        .cert-style7-flourish {
            position:absolute; top:0.78in; left:50%; transform:translateX(-50%); width:220px; height:36px;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 40'%3E%3Cpath d='M10 20 C40 5,60 35,90 20 C95 15,100 15,100 20' stroke='%23b8860b' stroke-width='1.5' fill='none'/%3E%3Cpath d='M190 20 C160 5,140 35,110 20 C105 15,100 15,100 20' stroke='%23b8860b' stroke-width='1.5' fill='none'/%3E%3Ccircle cx='100' cy='20' r='4' fill='%23b8860b'/%3E%3Ccircle cx='60' cy='14' r='2' fill='%23b8860b'/%3E%3Ccircle cx='140' cy='14' r='2' fill='%23b8860b'/%3E%3C/svg%3E") no-repeat center/contain;
        }
        .cert-style7 .cert-header h3 { font-family:'Playfair Display', Georgia, serif; color:#7a5c17; letter-spacing:1px; }
        .cert-style7 .cert-title { font-family:'Playfair Display', Georgia, serif; color:#8a6d1f; letter-spacing:3px; text-decoration:none; border-bottom:1px solid #b8860b; padding-bottom:4px; }
        .cert-style7 .cert-body { font-family: Georgia, serif; color:#3a3226; }
        .cert-style7 .cert-body b:nth-of-type(1) { font-family:'Great Vibes', cursive; font-size:26px; color:#8a6d1f; font-weight:normal; }
        .cert-style7 .cert-sign .line { border-top-color:#8a6d1f; }
        .cert-seal { width:60px; height:60px; border-radius:50%; margin:0 auto 4px; position:relative;
            background: repeating-conic-gradient(from 0deg, #b8860b 0deg 11.25deg, #d4af37 11.25deg 22.5deg);
            box-shadow: 0 0 0 2px #8a6d1f, 0 2px 4px rgba(0,0,0,0.25);
        }
        .cert-seal::after { content:''; position:absolute; inset:8px; border-radius:50%; background:#c9a227; box-shadow: inset 0 0 0 1.5px #8a6d1f; }
        .cert-seal-wrap { position:absolute; text-align:center; font-size:9px; color:#8a6d1f; font-family:'Playfair Display',Georgia,serif; letter-spacing:1px; }
    </style>

    <div class="no-print d-flex justify-content-between align-items-center mb-3">
        <a href="index.php?page=print_certificate" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>

    <?php if (!$students): ?>
        <div class="card-custom"><p class="text-muted mb-0">নির্বাচিত ফিল্টারে কোনো শিক্ষার্থী পাওয়া যায়নি।</p></div>
    <?php else: foreach ($students as $st):
        $name = $g_lang === 'bn' ? ($st['name_bn'] ?: $st['name_en']) : $st['name_en'];
        $body = gc_build_body($g_type, $g_lang, $cert_types, (string) $name, (string) $st['student_id'], (string) ($st['class_name'] ?? ''), (string) ($st['section_name'] ?? ''), (string) ($st['session_name'] ?? ''), $g_reason);
        $sign_label = $g_lang === 'bn' ? 'অধ্যক্ষের স্বাক্ষর' : 'Signature of Principal';
    ?>
        <div class="cert-page <?php echo $style === 'style7' ? 'cert-style7' : ''; ?>">
            <?php if ($style === 'custom' && !empty($config['custom_template_path'])): ?>
                <img src="../<?php echo gcv($config['custom_template_path']); ?>" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
            <?php elseif ($style === 'style1'): ?>
                <!-- Royal Navy & Gold -->
                <div class="cert-ribbon" style="background: repeating-linear-gradient(90deg, #b8860b 0 8px, transparent 8px 16px);"></div>
                <div class="cert-inner-border" style="border-color:#1e3a5f; border-width:2px;"></div>
                <div class="cert-inner-border2" style="border-color:#b8860b;"></div>
                <div class="cert-corner-bracket cert-cb-tl" style="border-color:#b8860b;"></div>
                <div class="cert-corner-bracket cert-cb-tr" style="border-color:#b8860b;"></div>
                <div class="cert-corner-bracket cert-cb-bl" style="border-color:#b8860b;"></div>
                <div class="cert-corner-bracket cert-cb-br" style="border-color:#b8860b;"></div>
            <?php elseif ($style === 'style2'): ?>
                <!-- Emerald Classic -->
                <div class="cert-inner-border" style="border-color:#1f4d36; border-width:2.5px;"></div>
                <div class="cert-inner-border2" style="border-color:#1f4d36;"></div>
                <div class="cert-corner" style="top:0.45in; left:0.45in; border-color:#1f4d36;"></div>
                <div class="cert-corner" style="top:0.45in; right:0.45in; border-color:#1f4d36;"></div>
                <div class="cert-corner" style="bottom:0.45in; left:0.45in; border-color:#1f4d36;"></div>
                <div class="cert-corner" style="bottom:0.45in; right:0.45in; border-color:#1f4d36;"></div>
            <?php elseif ($style === 'style3'): ?>
                <!-- Minimalist Elegant -->
                <div class="cert-inner-border" style="border-width:0.75px; border-color:#94a3b8;"></div>
            <?php elseif ($style === 'style4'): ?>
                <!-- Sapphire Frame -->
                <div class="cert-inner-border" style="border-width:1px; border-color:#1d4ed8;"></div>
                <div class="cert-inner-border2" style="border-color:#1d4ed8;"></div>
                <div class="cert-diamond" style="top:0.42in; left:calc(50% - 6px); border-color:#1d4ed8;"></div>
                <div class="cert-diamond" style="bottom:0.42in; left:calc(50% - 6px); border-color:#1d4ed8;"></div>
            <?php elseif ($style === 'style5'): ?>
                <!-- Teal Heritage -->
                <div class="cert-ribbon" style="background: repeating-linear-gradient(90deg,#0f6466 0 10px, transparent 10px 18px);"></div>
                <div class="cert-inner-border" style="border-color:#0f6466; border-width:2px;"></div>
                <div class="cert-inner-border2" style="border-color:#0f6466; border-style:dashed;"></div>
                <div class="cert-corner-bracket cert-cb-tl" style="border-color:#0f6466;"></div>
                <div class="cert-corner-bracket cert-cb-br" style="border-color:#0f6466;"></div>
            <?php elseif ($style === 'style6'): ?>
                <!-- Charcoal & Gold -->
                <div class="cert-inner-border" style="border-color:#1f2937; border-width:5px;"></div>
                <div class="cert-inner-border2" style="border-color:#b8860b; border-width:1px;"></div>
                <div class="cert-corner-bracket cert-cb-tl" style="border-color:#b8860b;"></div>
                <div class="cert-corner-bracket cert-cb-tr" style="border-color:#b8860b;"></div>
                <div class="cert-corner-bracket cert-cb-bl" style="border-color:#b8860b;"></div>
                <div class="cert-corner-bracket cert-cb-br" style="border-color:#b8860b;"></div>
            <?php elseif ($style === 'style7'): ?>
                <!-- Ornate Gold Certificate -->
                <div class="cert-style7-pattern"></div>
                <div class="cert-style7-outer"></div>
                <div class="cert-style7-band"></div>
                <div class="cert-style7-band2"></div>
                <div class="cert-style7-inner"></div>
                <div class="cert-style7-flourish"></div>
            <?php endif; ?>

            <?php if (!empty($inst['logo_path']) && !in_array($style, ['custom', 'blank', 'style3', 'style7'], true)): ?>
                <img src="../<?php echo gcv($inst['logo_path']); ?>" class="cert-watermark">
            <?php endif; ?>

            <?php if ($pos['header_visible'] || $style !== 'blank'): ?>
                <div class="cert-header" style="top:<?php echo $pos['header_top']; ?>in; left:<?php echo $pos['header_left']; ?>in;">
                    <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo gcv($inst['logo_path']); ?>"><br><?php endif; ?>
                    <h3><?php echo gcv($inst['institute_name'] ?? ''); ?></h3>
                    <p><?php echo gcv($addr_line); ?></p>
                    <?php
                    $contact_bits = [];
                    if (!empty($inst['phone_number'])) { $contact_bits[] = 'Mobile: ' . gcv($inst['phone_number']); }
                    if (!empty($inst['website'])) { $contact_bits[] = gcv($inst['website']); }
                    if ($contact_bits) { echo '<p>' . implode(' | ', $contact_bits) . '</p>'; }
                    ?>
                </div>
            <?php endif; ?>

            <div class="cert-title" style="top:<?php echo $pos['title_top']; ?>in;"><?php echo gcv($title); ?></div>
            <div class="cert-date" style="top:<?php echo $pos['date_top']; ?>in; right:<?php echo $pos['date_right']; ?>in;"><?php echo $g_lang === 'bn' ? 'তারিখ' : 'Date'; ?>: <?php echo date('d F, Y', strtotime($g_date)); ?></div>
            <div class="cert-body" style="top:<?php echo $pos['body_top']; ?>in; left:<?php echo $pos['body_left']; ?>in; width:<?php echo $pos['body_width']; ?>in;"><?php echo $body; ?></div>
            <div class="cert-sign" style="top:<?php echo $pos['sign_top']; ?>in; left:<?php echo $pos['sign_left']; ?>in;">
                <div class="line"></div><?php echo gcv($sign_label); ?>
            </div>
            <?php if ($style === 'style7'): ?>
                <div class="cert-seal-wrap" style="top:<?php echo $pos['sign_top'] - 0.55; ?>in; left:0.9in;">
                    <div class="cert-seal"></div>
                    <?php echo $g_lang === 'bn' ? 'অফিসিয়াল সিল' : 'OFFICIAL SEAL'; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; endif; ?>

<?php else: ?>
    <?php
    $gc_err = $_SESSION['gc_err'] ?? '';
    $gc_msg = $_SESSION['gc_msg'] ?? '';
    unset($_SESSION['gc_err'], $_SESSION['gc_msg']);
    ?>
    <div class="card-custom">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-normal text-dark mb-0"><i class="fa-solid fa-certificate text-primary me-1"></i> Print Certificate (প্রত্যয়নপত্র)</h5>
        </div>
        <?php if ($gc_msg): ?><div class="alert alert-success py-2"><?php echo gcv($gc_msg); ?></div><?php endif; ?>
        <?php if ($gc_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($gc_err); ?></div><?php endif; ?>

        <!-- ================= ফিল্টার + সনদের তথ্য ফর্ম ================= -->
        <form method="POST" action="index.php?page=print_certificate" id="gcConfigForm">
            <?php echo csrf_field(); ?>
            <div class="row">
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Session</label>
                    <select name="session_id" id="gcSession" class="form-select" onchange="updateGcPreview()">
                        <option value="">Select Session</option>
                        <?php foreach ($sessions as $s): ?><option value="<?php echo (int) $s['id']; ?>"><?php echo gcv($s['session_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Class <span class="text-danger">*</span></label>
                    <select name="class_id" id="gcClassSelect" class="form-select" required onchange="filterGcSections(); filterGcShifts(); updateGcPreview();">
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo gcv($c['class_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Shift</label>
                    <select name="shift_id" id="gcShiftSelect" class="form-select" disabled onchange="filterGcSections();">
                        <option value="">Select Class first</option>
                        <?php foreach ($shifts as $sh): ?><option value="<?php echo (int) $sh['id']; ?>" data-classes="<?php echo gcv(implode(',', $gc_class_shifts[$sh['id']] ?? [])); ?>" hidden><?php echo gcv($sh['shift_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Section</label>
                    <select name="section_id" id="gcSectionSelect" class="form-select" disabled>
                        <option value="">Select Class first</option>
                        <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" data-shift="<?php echo (int) $sec['shift_id']; ?>" hidden><?php echo gcv($sec['section_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">Student</label>
                    <div class="position-relative">
                        <input type="text" id="gcStudentSearch" class="form-control" placeholder="সবাই (Bulk) — ID/Name/Roll দিয়ে খুঁজুন" autocomplete="off" value="সবাই (Bulk)">
                        <input type="hidden" name="student_row_id" id="gcStudentRowId" value="">
                        <div id="gcStudentDropdown" class="list-group position-absolute w-100" style="z-index:1000; max-height:240px; overflow-y:auto; display:none; top:100%;"></div>
                    </div>
                </div>
            </div>

            <div class="row mt-2">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Certificate Type <span class="text-danger">*</span></label>
                    <select name="cert_type" id="gcCertType" class="form-select" required onchange="applyGcTypeDefaults(); updateGcPreview();">
                        <?php foreach ($cert_types as $key => $t): ?>
                            <option value="<?php echo gcv($key); ?>" data-reason-en="<?php echo gcv($t['reason_en']); ?>" data-reason-bn="<?php echo gcv($t['reason_bn']); ?>"><?php echo gcv($t['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label small fw-bold">Reason / Achievement Details</label>
                    <input type="text" name="reason" id="gcReason" class="form-control" placeholder="যেমন: বার্ষিক বিজ্ঞান মেলায় ১ম স্থান অর্জনের" oninput="updateGcPreview()">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Language / ভাষা</label>
                    <select name="language" id="gcLanguage" class="form-select" onchange="applyGcTypeDefaults(); updateGcPreview();">
                        <option value="en">English (ইংরেজি)</option>
                        <option value="bn">বাংলা (Bangla)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Certificate Date</label>
                    <input type="date" name="cert_date" id="gcDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" onchange="updateGcPreview()">
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary" onclick="toggleGcTemplatePanel()" title="Template Settings">
                    <i class="fa-solid fa-gear"></i>
                </button>
                <button type="submit" name="generate_certificate" class="btn btn-primary fw-bold" style="background-color:#1e3a8a;"><i class="fa-solid fa-print"></i> Print</button>
            </div>
        </form>

        <!-- ================= লাইভ প্রিভিউ + Template Options (দুই কলাম) ================= -->
        <div class="row mt-4">
            <div class="col-lg-8">
                <style>
                    @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Great+Vibes&display=swap');
                    .gc-live-preview-wrap { display:flex; justify-content:center; background:#eef1f5; padding:20px; border-radius:8px; }
                    #gcLivePreviewPage {
                        width: 100%; max-width: 650px; aspect-ratio: 0.707; background:#fff; position:relative;
                        box-shadow: 0 0 10px rgba(0,0,0,0.15); font-family: Georgia, serif; overflow:hidden;
                    }
                    #gcLivePreviewPage.lp-style7 { background:#faf6ea; }
                    #gcLivePreviewPage .lp-border { position:absolute; inset:5%; border: 3px solid #1d70b8; }
                    #gcLivePreviewPage .lp-border2 { position:absolute; inset:8%; border: 1px solid #2b8a4a; }
                    #gcLivePreviewPage .lp-stripe { position:absolute; top:5%; left:5%; right:5%; height:8px; background: repeating-linear-gradient(90deg,#e0559f 0 8px, transparent 8px 16px); }
                    #gcLivePreviewPage .lp-circle { position:absolute; width:16px; height:16px; border:2px solid #1d70b8; border-radius:50%; }
                    #gcLivePreviewPage .lp-cb { position:absolute; width:14px; height:14px; }
                    #gcLivePreviewPage .lp-cb-tl { top:6%; left:6%; border-top:2px solid; border-left:2px solid; }
                    #gcLivePreviewPage .lp-cb-tr { top:6%; right:6%; border-top:2px solid; border-right:2px solid; }
                    #gcLivePreviewPage .lp-cb-bl { bottom:6%; left:6%; border-bottom:2px solid; border-left:2px solid; }
                    #gcLivePreviewPage .lp-cb-br { bottom:6%; right:6%; border-bottom:2px solid; border-right:2px solid; }
                    #gcLivePreviewPage .lp-diamond { position:absolute; width:8px; height:8px; transform:rotate(45deg); border:1.5px solid; }
                    #gcLivePreviewPage .lp-watermark { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); max-width:38%; max-height:38%; opacity:0.08; object-fit:contain; }
                    #gcLivePreviewPage .lp-ornate-outer { position:absolute; inset:5%; border:2px solid #b8860b; }
                    #gcLivePreviewPage .lp-ornate-band { position:absolute; inset:7%; border:1px dotted #b8860b; }
                    #gcLivePreviewPage .lp-ornate-band2 { position:absolute; inset:8.5%; border:1px solid #8a6d1f; }
                    #gcLivePreviewPage .lp-inner-hairline { position:absolute; inset:11%; border:1px solid #b8860b; }
                    #gcLivePreviewPage.lp-style7 .lp-title { font-family:'Playfair Display', Georgia, serif; color:#8a6d1f; letter-spacing:2px; text-decoration:none; border-bottom:1px solid #b8860b; padding-bottom:2px; }
                    #gcLivePreviewPage.lp-style7 .lp-header h5 { font-family:'Playfair Display', Georgia, serif; color:#7a5c17; }
                    #gcLivePreviewPage.lp-style7 .lp-body b:nth-of-type(1) { font-family:'Great Vibes', cursive; font-size:15px; color:#8a6d1f; font-weight:normal; }
                    #gcLivePreviewPage .lp-header { position:absolute; top:8%; left:15%; right:15%; text-align:center; }
                    #gcLivePreviewPage .lp-header h5 { font-weight:bold; color:#1e3a5f; margin:4px 0 0; font-size:15px; }
                    #gcLivePreviewPage .lp-header p { font-size:10px; color:#666; margin:0; }
                    #gcLivePreviewPage .lp-title { position:absolute; top:26%; left:15%; right:15%; text-align:center; font-weight:bold; font-size:14px; text-decoration:underline; }
                    #gcLivePreviewPage .lp-date { position:absolute; top:22%; right:8%; font-size:10px; color:#666; }
                    #gcLivePreviewPage .lp-body { position:absolute; top:33%; left:8%; right:8%; font-size:11px; line-height:1.9; color:#222; }
                    #gcLivePreviewPage .lp-sign { position:absolute; bottom:10%; right:10%; text-align:center; font-size:10px; }
                    #gcLivePreviewPage .lp-sign .line { border-top:1px solid #333; width:110px; margin-bottom:4px; }
                </style>
                <p class="small text-muted mb-2"><i class="fa-solid fa-eye"></i> লাইভ প্রিভিউ — উপরে তথ্য বদলালে এখানে সাথে সাথে দেখা যাবে। (আসল প্রিন্ট A4 সাইজে হবে, এটা শুধু প্রিভিউ)</p>
                <div class="gc-live-preview-wrap">
                    <div id="gcLivePreviewPage">
                        <div id="gcBorderLayer"></div>
                        <?php if (!empty($inst['logo_path'])): ?>
                            <img src="../<?php echo gcv($inst['logo_path']); ?>" class="lp-watermark" id="gcWatermark">
                        <?php endif; ?>
                        <div class="lp-header" id="gcHeader">
                            <h5><?php echo gcv($pdo->query("SELECT institute_name FROM institute_settings WHERE id=1")->fetchColumn() ?: ''); ?></h5>
                            <p><?php echo gcv($branch ? ($branch['address'] . ', ' . $branch['city']) : ''); ?></p>
                        </div>
                        <div class="lp-title" id="gcTitle">CERTIFICATE OF MERIT</div>
                        <div class="lp-date" id="gcDatePreview">Date: <?php echo date('d F, Y'); ?></div>
                        <div class="lp-body" id="gcBody">শিক্ষার্থী নির্বাচন করুন প্রিভিউ দেখতে...</div>
                        <div class="lp-sign"><div class="line"></div><span id="gcSignLabel">Signature of Principal</span></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4" id="gcTemplateOptionsPanel">
                <div class="card-custom">
                    <h6 class="fw-bold mb-3">Template Options</h6>
                    <form method="POST" action="index.php?page=print_certificate" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>

                        <style>
                            .gc-tpl-card { display:flex; align-items:center; gap:12px; padding:10px; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:10px; cursor:pointer; }
                            .gc-tpl-card:hover { border-color:#93c5fd; }
                            .gc-tpl-card.gc-tpl-selected { border-color:#1d70b8; border-width:2px; background:#f0f7ff; }
                            .gc-tpl-preview { position:relative; width:90px; height:64px; background:#fff; border:1px solid #cbd5e1; border-radius:4px; overflow:hidden; flex-shrink:0; }
                            .gc-tpl-preview .gc-tpl-line { position:absolute; }
                            .gc-tpl-mock-header { top:5px; left:6px; right:6px; height:5px; background:#94a3b8; border-radius:2px; }
                            .gc-tpl-mock-title { top:14px; left:16px; right:16px; height:4px; background:#334155; border-radius:2px; }
                            .gc-tpl-mock-text { left:6px; right:6px; height:3px; background:#cbd5e1; border-radius:2px; }
                            .gc-tpl-p1-stripe { top:2px; left:2px; right:2px; height:4px; background: repeating-linear-gradient(90deg,#b8860b 0 4px, transparent 4px 8px); }
                            .gc-tpl-p1-border { inset:2px; border:1.5px solid #1e3a5f; }
                            .gc-tpl-p1-border2 { inset:5px; border:1px solid #b8860b; }
                            .gc-tpl-p2-border { inset:2px; border:2px solid #1f4d36; }
                            .gc-tpl-p2-border2 { inset:5px; border:1px solid #1f4d36; }
                            .gc-tpl-p2-circle { width:7px;height:7px;border:1.5px solid #1f4d36;border-radius:50%; }
                            .gc-tpl-p4-border { inset:2px; border:1px solid #1d4ed8; }
                            .gc-tpl-p5-border { inset:4px; border:1px dashed #0f6466; }
                            .gc-tpl-p5-stripe { top:2px; left:4px; right:4px; height:3px; background: repeating-linear-gradient(90deg,#0f6466 0 4px, transparent 4px 7px); }
                            .gc-tpl-p6-border { inset:1px; border:3px solid #1f2937; }
                            .gc-tpl-p6-border2 { inset:5px; border:1px solid #b8860b; }
                            .gc-tpl-p7-band { inset:2px; border:4px solid #b8860b; opacity:0.55; }
                            .gc-tpl-p7-inner { inset:9px; border:1px solid #b8860b; }
                        </style>

                        <div style="max-height:340px; overflow-y:auto;">
                        <?php
                        $tpl_options = [
                            'style1' => 'Royal Navy & Gold', 'style2' => 'Emerald Classic',
                            'style3' => 'Minimalist Elegant', 'style4' => 'Sapphire Frame',
                            'style5' => 'Teal Heritage', 'style6' => 'Charcoal & Gold',
                            'style7' => 'Ornate Gold Certificate',
                            'custom' => 'Custom Upload', 'blank'  => 'Blank (Pre-printed Card)',
                        ];
                        foreach ($tpl_options as $key => $label):
                            $selected = $config['template_style'] === $key;
                        ?>
                            <label class="gc-tpl-card <?php echo $selected ? 'gc-tpl-selected' : ''; ?>">
                                <input type="radio" name="template_style" value="<?php echo $key; ?>" <?php echo $selected ? 'checked' : ''; ?>
                                    onclick="document.querySelectorAll('.gc-tpl-card').forEach(c=>c.classList.remove('gc-tpl-selected'));this.closest('.gc-tpl-card').classList.add('gc-tpl-selected');updateGcPreviewBorder('<?php echo $key; ?>');">
                                <div class="gc-tpl-preview">
                                    <?php if ($key === 'style1'): ?>
                                        <div class="gc-tpl-line gc-tpl-p1-stripe"></div><div class="gc-tpl-line gc-tpl-p1-border"></div><div class="gc-tpl-line gc-tpl-p1-border2"></div>
                                        <div class="gc-tpl-line gc-tpl-mock-header" style="top:14px;"></div><div class="gc-tpl-line gc-tpl-mock-title" style="top:26px;"></div>
                                        <div class="gc-tpl-line gc-tpl-mock-text" style="top:38px;"></div><div class="gc-tpl-line gc-tpl-mock-text" style="top:44px;right:30px;"></div>
                                    <?php elseif ($key === 'style2'): ?>
                                        <div class="gc-tpl-line gc-tpl-p2-border"></div><div class="gc-tpl-line gc-tpl-p2-border2"></div>
                                        <div class="gc-tpl-line gc-tpl-p2-circle" style="top:6px;left:6px;"></div><div class="gc-tpl-line gc-tpl-p2-circle" style="top:6px;right:6px;"></div>
                                        <div class="gc-tpl-line gc-tpl-mock-header" style="top:16px;"></div><div class="gc-tpl-line gc-tpl-mock-title" style="top:28px;"></div>
                                        <div class="gc-tpl-line gc-tpl-mock-text" style="top:40px;"></div><div class="gc-tpl-line gc-tpl-mock-text" style="top:46px;right:30px;"></div>
                                    <?php elseif ($key === 'style3'): ?>
                                        <div class="gc-tpl-line gc-tpl-mock-header" style="top:8px;"></div><div class="gc-tpl-line gc-tpl-mock-title" style="top:20px;"></div>
                                        <div class="gc-tpl-line gc-tpl-mock-text" style="top:34px;"></div><div class="gc-tpl-line gc-tpl-mock-text" style="top:40px;right:30px;"></div>
                                        <div class="gc-tpl-line gc-tpl-mock-text" style="top:46px;right:10px;"></div>
                                    <?php elseif ($key === 'style4'): ?>
                                        <div class="gc-tpl-line gc-tpl-p4-border"></div>
                                        <div class="gc-tpl-line gc-tpl-mock-header" style="top:9px;"></div><div class="gc-tpl-line gc-tpl-mock-title" style="top:20px;"></div>
                                        <div class="gc-tpl-line gc-tpl-mock-text" style="top:34px;"></div><div class="gc-tpl-line gc-tpl-mock-text" style="top:40px;right:30px;"></div>
                                    <?php elseif ($key === 'style5'): ?>
                                        <div class="gc-tpl-line gc-tpl-p5-stripe"></div><div class="gc-tpl-line gc-tpl-p5-border"></div>
                                        <div class="gc-tpl-line gc-tpl-mock-header" style="top:11px;"></div><div class="gc-tpl-line gc-tpl-mock-title" style="top:22px;"></div>
                                        <div class="gc-tpl-line gc-tpl-mock-text" style="top:35px;"></div><div class="gc-tpl-line gc-tpl-mock-text" style="top:41px;right:30px;"></div>
                                    <?php elseif ($key === 'style6'): ?>
                                        <div class="gc-tpl-line gc-tpl-p6-border"></div><div class="gc-tpl-line gc-tpl-p6-border2"></div>
                                        <div class="gc-tpl-line gc-tpl-mock-header" style="top:10px;"></div><div class="gc-tpl-line gc-tpl-mock-title" style="top:21px;"></div>
                                        <div class="gc-tpl-line gc-tpl-mock-text" style="top:34px;"></div><div class="gc-tpl-line gc-tpl-mock-text" style="top:40px;right:30px;"></div>
                                    <?php elseif ($key === 'style7'): ?>
                                        <div style="position:absolute;inset:0;background:#faf6ea;"></div>
                                        <div class="gc-tpl-line gc-tpl-p7-band"></div>
                                        <div class="gc-tpl-line gc-tpl-p7-inner"></div>
                                        <div class="gc-tpl-line gc-tpl-mock-header" style="top:13px;background:#8a6d1f;"></div><div class="gc-tpl-line gc-tpl-mock-title" style="top:24px;background:#8a6d1f;"></div>
                                        <div class="gc-tpl-line gc-tpl-mock-text" style="top:38px;background:#d9c48a;"></div><div class="gc-tpl-line gc-tpl-mock-text" style="top:44px;right:30px;background:#d9c48a;"></div>
                                    <?php elseif ($key === 'custom'): ?>
                                        <?php if (!empty($config['custom_template_path'])): ?>
                                            <img src="../<?php echo gcv($config['custom_template_path']); ?>" style="width:100%;height:100%;object-fit:cover;">
                                        <?php else: ?>
                                            <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:9px;">No file</div>
                                        <?php endif; ?>
                                    <?php elseif ($key === 'blank'): ?>
                                        <div class="gc-tpl-line gc-tpl-mock-title" style="top:8px;left:10px;right:30px;"></div>
                                        <div class="gc-tpl-line gc-tpl-mock-text" style="top:20px;"></div><div class="gc-tpl-line gc-tpl-mock-text" style="top:26px;right:30px;"></div>
                                        <div class="gc-tpl-line gc-tpl-mock-text" style="top:38px;"></div>
                                    <?php endif; ?>
                                </div>
                                <div class="small fw-bold"><?php echo $label; ?></div>
                            </label>
                        <?php endforeach; ?>
                        </div>

                        <?php if (!empty($config['custom_template_path'])): ?>
                            <div class="d-flex align-items-center gap-2 mb-2 p-2 border rounded">
                                <div class="flex-grow-1 small text-muted">বর্তমান কাস্টম টেমপ্লেট আপলোড করা আছে</div>
                                <button type="submit" formaction="index.php?page=print_certificate" name="remove_certificate_template" class="btn btn-sm btn-outline-danger" onclick="return confirm('কাস্টম টেমপ্লেট মুছে ফেলতে চান?');">Remove</button>
                            </div>
                        <?php endif; ?>

                        <div class="mb-2 mt-2">
                            <label class="form-label small">Custom Template Image (jpg/png, max 3MB)</label>
                            <input type="file" name="custom_template" class="form-control form-control-sm" accept=".jpg,.jpeg,.png">
                        </div>

                        <details class="mb-2">
                            <summary class="small fw-bold" style="cursor:pointer;">Field Position (ইঞ্চিতে) — pre-printed কার্ডের জন্য</summary>
                            <div class="form-check form-switch mt-2 mb-2">
                                <input class="form-check-input" type="checkbox" id="gcPosHeaderVisible" name="pos_header_visible" <?php echo $saved_positions['header_visible'] ? 'checked' : ''; ?>>
                                <label class="form-check-label small" for="gcPosHeaderVisible">Header প্রিন্ট করুন</label>
                            </div>
                            <div class="row g-2">
                                <?php
                                $field_labels = [
                                    'header_top' => 'Header Top', 'header_left' => 'Header Left',
                                    'title_top' => 'Title Top',
                                    'date_top' => 'Date Top', 'date_right' => 'Date Right (ডান কিনারা থেকে)',
                                    'body_top' => 'Body Top', 'body_left' => 'Body Left', 'body_width' => 'Body Width',
                                    'sign_top' => 'Sign Top', 'sign_left' => 'Sign Left',
                                ];
                                foreach ($field_labels as $key => $label): ?>
                                    <div class="col-6">
                                        <label class="form-label small mb-0"><?php echo $label; ?></label>
                                        <input type="number" step="0.05" name="pos_<?php echo $key; ?>" class="form-control form-control-sm" value="<?php echo gcv((string) $saved_positions[$key]); ?>">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>

                        <div class="text-center mt-3">
                            <button type="submit" name="save_template_settings" class="btn btn-success btn-sm px-4 fw-bold">Save Settings</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <script>
        function filterGcSections() {
            const classId = document.getElementById('gcClassSelect').value;
            const shiftId = document.getElementById('gcShiftSelect').value;
            const select = document.getElementById('gcSectionSelect');
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

        function filterGcShifts() {
            const classId = document.getElementById('gcClassSelect').value;
            const select = document.getElementById('gcShiftSelect');
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
        filterGcSections();
        filterGcShifts();

        function toggleGcTemplatePanel() {
            const panel = document.getElementById('gcTemplateOptionsPanel');
            panel.style.display = (panel.style.display === 'none') ? '' : 'none';
        }

        // ------------------------------------------------------------------
        // Student সার্চ — সিলেক্ট করা Class/Section অনুযায়ী ফিল্টার হয়,
        // এবং Student ID / Name / Roll দিয়ে টাইপ করে সার্চও করা যায়
        // ------------------------------------------------------------------
        const gcAllStudents = <?php echo json_encode(array_map(function ($s) {
            return [
                'id' => (int) $s['id'], 'name_en' => $s['name_en'], 'name_bn' => $s['name_bn'],
                'sid' => $s['student_id'], 'roll' => $s['roll_no'],
                'class_id' => (int) $s['class_id'], 'section_id' => (int) $s['section_id'], 'shift_id' => (int) $s['shift_id'],
                'class' => $s['class_name'], 'section' => $s['section_name'], 'session' => $s['session_name'],
            ];
        }, $all_students), JSON_UNESCAPED_UNICODE); ?>;

        let gcSelectedStudent = null;

        const gcSearchInput = document.getElementById('gcStudentSearch');
        const gcHiddenId = document.getElementById('gcStudentRowId');
        const gcDropdown = document.getElementById('gcStudentDropdown');
        const gcClassSelect = document.getElementById('gcClassSelect');
        const gcSectionSelect = document.getElementById('gcSectionSelect');
        const gcShiftSelect = document.getElementById('gcShiftSelect');

        function gcMatchingStudents() {
            const classId = gcClassSelect.value;
            const sectionId = gcSectionSelect.value;
            const shiftId = gcShiftSelect.value;
            const q = gcSearchInput.value.trim().toLowerCase();
            const isBulkText = gcSearchInput.value.trim() === 'সবাই (Bulk)';
            return gcAllStudents.filter(function (s) {
                if (classId && String(s.class_id) !== classId) return false;
                if (sectionId && String(s.section_id) !== sectionId) return false;
                if (shiftId && String(s.shift_id) !== shiftId) return false;
                if (q === '' || isBulkText) return true;
                return (s.name_en || '').toLowerCase().includes(q)
                    || (s.name_bn || '').toLowerCase().includes(q)
                    || (s.sid || '').toLowerCase().includes(q)
                    || (s.roll || '').toLowerCase().includes(q);
            });
        }

        function gcEsc(s) {
            const d = document.createElement('div');
            d.textContent = s || '';
            return d.innerHTML;
        }

        function gcRenderDropdown() {
            const matches = gcMatchingStudents().slice(0, 50);
            let html = '<button type="button" class="list-group-item list-group-item-action fw-bold" data-id="">সবাই (Bulk)</button>';
            matches.forEach(function (s) {
                const label = gcEsc(s.sid) + ' — ' + gcEsc(s.name_en);
                html += '<button type="button" class="list-group-item list-group-item-action" data-id="' + s.id + '" data-label="' + label.replace(/"/g, '&quot;') + '">'
                    + gcEsc(s.sid) + ' — ' + gcEsc(s.name_en) + (s.roll ? ' (Roll: ' + gcEsc(s.roll) + ')' : '') + '</button>';
            });
            if (matches.length === 0) {
                html += '<div class="list-group-item text-muted small">এই Class/Section-এ কোনো স্টুডেন্ট পাওয়া যায়নি।</div>';
            }
            gcDropdown.innerHTML = html;
            gcDropdown.style.display = 'block';
        }

        gcSearchInput.addEventListener('focus', function () {
            gcRenderDropdown();
            if (gcSearchInput.value.trim() === 'সবাই (Bulk)') gcSearchInput.select();
        });
        gcSearchInput.addEventListener('input', gcRenderDropdown);
        [gcClassSelect, gcSectionSelect, gcShiftSelect].forEach(function (el) {
            el.addEventListener('change', function () {
                gcHiddenId.value = '';
                gcSelectedStudent = null;
                gcSearchInput.value = 'সবাই (Bulk)';
                updateGcPreview();
            });
        });

        gcDropdown.addEventListener('click', function (e) {
            const btn = e.target.closest('button[data-id]');
            if (!btn) return;
            const id = btn.dataset.id;
            gcHiddenId.value = id;
            gcSelectedStudent = id === '' ? null : (gcAllStudents.find(s => String(s.id) === id) || null);
            gcSearchInput.value = id === '' ? 'সবাই (Bulk)' : btn.dataset.label;
            gcDropdown.style.display = 'none';
            updateGcPreview();
        });

        document.addEventListener('click', function (e) {
            if (!gcDropdown.contains(e.target) && e.target !== gcSearchInput) {
                gcDropdown.style.display = 'none';
            }
        });

        // ------------------------------------------------------------------
        // Certificate Type বদলালে Reason ফিল্ডে ডিফল্ট টেক্সট বসে (এডিট করা যায়)
        // ------------------------------------------------------------------
        function applyGcTypeDefaults() {
            const opt = gcCertTypeSelect().selectedOptions[0];
            const lang = document.getElementById('gcLanguage').value;
            const reasonField = document.getElementById('gcReason');
            const defaultReason = lang === 'bn' ? opt.dataset.reasonBn : opt.dataset.reasonEn;
            reasonField.value = defaultReason || '';
        }
        function gcCertTypeSelect() { return document.getElementById('gcCertType'); }

        const certTitles = {
            <?php foreach ($cert_types as $key => $t): ?>
            <?php echo json_encode($key); ?>: { en: <?php echo json_encode($t['title_en']); ?>, bn: <?php echo json_encode($t['title_bn']); ?> },
            <?php endforeach; ?>
        };
        const certSign = { en: 'Signature of Principal', bn: 'অধ্যক্ষের স্বাক্ষর' };

        function gcBuildBody(type, lang, d, reason) {
            reason = (reason || '').trim();
            if (type === 'custom') {
                if (lang === 'bn') {
                    return `এই মর্মে প্রত্যয়ন করা যাচ্ছে যে, <b>${d.name}</b> (স্টুডেন্ট আইডি: <b>${d.sid}</b>), শ্রেণি <b>${d.class}</b>, শাখা <b>${d.section}</b>, শিক্ষাবর্ষ <b>${d.session}</b> — ${gcEsc(reason)}।`;
                }
                return `This is to certify that <b>${d.name}</b> (Student ID: <b>${d.sid}</b>) of Class <b>${d.class}</b>, Section <b>${d.section}</b>, academic session <b>${d.session}</b> — ${gcEsc(reason)}.`;
            }
            if (lang === 'bn') {
                return `এই মর্মে প্রত্যয়ন করা যাচ্ছে যে, <b>${d.name}</b> (স্টুডেন্ট আইডি: <b>${d.sid}</b>), শ্রেণি <b>${d.class}</b>, শাখা <b>${d.section}</b> — শিক্ষাবর্ষ <b>${d.session}</b>-এ ${gcEsc(reason)} জন্য এই সনদপত্র প্রদান করা হলো।<br><br>তার আন্তরিক প্রচেষ্টা ও নিষ্ঠার জন্য আমরা কৃতজ্ঞতা প্রকাশ করছি এবং ভবিষ্যতের সকল কাজে সাফল্য কামনা করছি।`;
            }
            return `This is to certify that <b>${d.name}</b> (Student ID: <b>${d.sid}</b>) of Class <b>${d.class}</b>, Section <b>${d.section}</b>, has been awarded this certificate for ${gcEsc(reason)} during the academic session <b>${d.session}</b>.<br><br>We appreciate his/her sincere effort and dedication, and wish him/her continued success in all future endeavors.`;
        }

        function updateGcPreview() {
            const lang = document.getElementById('gcLanguage').value;
            const type = gcCertTypeSelect().value;
            const reason = document.getElementById('gcReason').value;
            const dateVal = document.getElementById('gcDate').value;

            document.getElementById('gcTitle').textContent = (certTitles[type] || certTitles['merit'])[lang];
            document.getElementById('gcSignLabel').textContent = certSign[lang];
            document.getElementById('gcDatePreview').textContent = (lang === 'bn' ? 'তারিখ: ' : 'Date: ') + (dateVal ? new Date(dateVal).toLocaleDateString('en-GB', { day: '2-digit', month: 'long', year: 'numeric' }) : '');

            if (!gcSelectedStudent) {
                document.getElementById('gcBody').textContent = lang === 'bn' ? 'শিক্ষার্থী নির্বাচন করুন প্রিভিউ দেখতে...' : 'Select a student to preview...';
                return;
            }

            const s = gcSelectedStudent;
            const d = {
                name: (lang === 'bn' ? (s.name_bn || s.name_en) : s.name_en) || '',
                sid: s.sid || '', class: s.class || '', section: s.section || '', session: s.session || '',
            };
            document.getElementById('gcBody').innerHTML = gcBuildBody(type, lang, d, reason);
        }

        function updateGcPreviewBorder(style) {
            const layer = document.getElementById('gcBorderLayer');
            const wm = document.getElementById('gcWatermark');
            const page = document.getElementById('gcLivePreviewPage');
            let html = '';
            if (style === 'style1') {
                html = '<div class="lp-stripe" style="background: repeating-linear-gradient(90deg,#b8860b 0 6px, transparent 6px 12px);"></div>'
                     + '<div class="lp-border" style="border-color:#1e3a5f;border-width:2px;"></div>'
                     + '<div class="lp-border2" style="border-color:#b8860b;"></div>'
                     + '<div class="lp-cb lp-cb-tl" style="border-color:#b8860b;"></div><div class="lp-cb lp-cb-tr" style="border-color:#b8860b;"></div>'
                     + '<div class="lp-cb lp-cb-bl" style="border-color:#b8860b;"></div><div class="lp-cb lp-cb-br" style="border-color:#b8860b;"></div>';
            } else if (style === 'style2') {
                html = '<div class="lp-border" style="border-color:#1f4d36;border-width:2.5px;"></div><div class="lp-border2" style="border-color:#1f4d36;"></div>'
                     + '<div class="lp-circle" style="top:6%;left:6%;border-color:#1f4d36;"></div><div class="lp-circle" style="top:6%;right:6%;border-color:#1f4d36;"></div>'
                     + '<div class="lp-circle" style="bottom:6%;left:6%;border-color:#1f4d36;"></div><div class="lp-circle" style="bottom:6%;right:6%;border-color:#1f4d36;"></div>';
            } else if (style === 'style3') {
                html = '<div class="lp-border" style="border-width:1px;border-color:#94a3b8;"></div>';
            } else if (style === 'style4') {
                html = '<div class="lp-border" style="border-width:1.5px;border-color:#1d4ed8;"></div><div class="lp-border2" style="border-color:#1d4ed8;"></div>'
                     + '<div class="lp-diamond" style="top:8%;left:calc(50% - 4px);border-color:#1d4ed8;"></div><div class="lp-diamond" style="bottom:8%;left:calc(50% - 4px);border-color:#1d4ed8;"></div>';
            } else if (style === 'style5') {
                html = '<div class="lp-stripe" style="background: repeating-linear-gradient(90deg,#0f6466 0 8px, transparent 8px 16px);"></div>'
                     + '<div class="lp-border" style="border-color:#0f6466;border-width:2px;"></div><div class="lp-border2" style="border-color:#0f6466;border-style:dashed;"></div>'
                     + '<div class="lp-cb lp-cb-tl" style="border-color:#0f6466;"></div><div class="lp-cb lp-cb-br" style="border-color:#0f6466;"></div>';
            } else if (style === 'style6') {
                html = '<div class="lp-border" style="border-width:5px;border-color:#1f2937;"></div><div class="lp-border2" style="border-color:#b8860b;border-width:1px;"></div>'
                     + '<div class="lp-cb lp-cb-tl" style="border-color:#b8860b;"></div><div class="lp-cb lp-cb-tr" style="border-color:#b8860b;"></div>'
                     + '<div class="lp-cb lp-cb-bl" style="border-color:#b8860b;"></div><div class="lp-cb lp-cb-br" style="border-color:#b8860b;"></div>';
            } else if (style === 'style7') {
                html = '<div class="lp-ornate-outer"></div><div class="lp-ornate-band"></div><div class="lp-ornate-band2"></div><div class="lp-inner-hairline"></div>';
            } else if (style === 'blank') {
                document.getElementById('gcHeader').style.display = 'none';
                html = '';
            }
            if (style !== 'blank') document.getElementById('gcHeader').style.display = '';
            if (wm) wm.style.display = (style === 'style3' || style === 'blank' || style === 'custom' || style === 'style7') ? 'none' : '';
            if (page) page.classList.toggle('lp-style7', style === 'style7');
            layer.innerHTML = html;
        }

        applyGcTypeDefaults();
        updateGcPreviewBorder('<?php echo $config['template_style']; ?>');
        updateGcPreview();
    </script>
    </div>
<?php endif; ?>
