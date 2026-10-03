<?php
// admin/print_transfer_certificate.php - Student Transfer Certificate
require_once __DIR__ . '/../config/report_card_helpers.php';

function tcv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// একজন ছাত্রের সর্বশেষ পরীক্ষার ফলাফল (Result Module-এর একই Final Aggregated Result হিসাব
// ব্যবহার করে) — TC-তে "Result of Last Examination" অটো-ফিল করার জন্য। কোনো রেজাল্ট ডেটা
// না থাকলে null রিটার্ন করে, তখন TC-তে এই লাইনটা বাদ দিয়ে দেওয়া হয় (পুরনো আচরণের মতোই)।
// -----------------------------------------------------------
function tc_get_result_summary(PDO $pdo, array $student): ?array
{
    $session_id = (int) ($student['session_id'] ?? 0);
    $class_id   = (int) ($student['class_id'] ?? 0);
    $student_row_id = (int) ($student['id'] ?? 0);
    if (!$session_id || !$class_id || !$student_row_id) { return null; }

    $terms_stmt = $pdo->prepare("SELECT id FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
    $terms_stmt->execute([$session_id]);
    $term_ids = $terms_stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$term_ids) { return null; }

    $gb_stmt = $pdo->prepare("SELECT grade_letter, gpa, mark_from, mark_to, remarks FROM exam_grade_points WHERE session_id = ? ORDER BY mark_from DESC");
    $gb_stmt->execute([$session_id]);
    $grade_bands = $gb_stmt->fetchAll();
    if (!$grade_bands) { return null; }

    $agg_type_row = $pdo->query("SELECT aggregate_result_type FROM exam_result_settings WHERE id = 1")->fetchColumn();
    $agg_type = $agg_type_row ?: 'Terms Wise Percentage';

    $agg = rc_aggregate_terms_result($pdo, $student_row_id, $term_ids, $class_id, $agg_type, $grade_bands);
    if (!$agg['per_term']) { return null; }

    return [
        'gpa' => $agg['final_gpa'],
        'gl' => $agg['final_gl'],
        'fail' => $agg['final_fail'],
    ];
}

// ব্রাউজারের Back বাটনে চাপলে যাতে bfcache থেকে পুরনো (ফর্মে ভরা/আগের আউটপুট)
// পেজ না দেখিয়ে সার্ভার থেকে টাটকা/ফাঁকা পেজ রিলোড হয়, সেজন্য no-cache header
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// -----------------------------------------------------------
// টেবিল না থাকলে অটো-তৈরি (transfer_certificate_config)
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS transfer_certificate_config (
        id INT PRIMARY KEY DEFAULT 1,
        template_style VARCHAR(20) NOT NULL DEFAULT 'style1',
        custom_template_path VARCHAR(255) NULL,
        positions_json TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
if ((int) $pdo->query("SELECT COUNT(*) FROM transfer_certificate_config")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO transfer_certificate_config (id) VALUES (1)");
}

$view = $_GET['view'] ?? 'config';

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes ORDER BY rank ASC, class_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections ORDER BY section_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts ORDER BY shift_name ASC")->fetchAll();

// একটা Class-এ কোন কোন Shift আছে সেটা academic_sections (class_id + shift_id) থেকে বের করা —
// Class সিলেক্ট না করা পর্যন্ত Shift/Section ফাঁকা/বন্ধ থাকবে, আর Section-এ একই নাম
// (একাধিক ক্লাসের) বারবার দেখানোর সমস্যাটাও এতে ঠিক হয়ে যাবে
$tc_class_shifts = []; // shift_id => [class_id, class_id, ...]
foreach ($sections as $sec) {
    if ($sec['shift_id']) {
        $tc_class_shifts[(int) $sec['shift_id']][] = (int) $sec['class_id'];
    }
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
// টেমপ্লেট আপলোড হেল্পার
// -----------------------------------------------------------
function tc_handle_upload(string $field): array
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
    $upload_dir = tenant_upload_abs(__DIR__ . '/..', 'certificate_templates');
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    $random_name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $random_name)) return ['ok' => false, 'path' => null, 'error' => 'ফাইল সেভ করা যায়নি।'];
    return ['ok' => true, 'path' => tenant_upload_rel('certificate_templates') . '/' . $random_name, 'error' => null];
}

// -----------------------------------------------------------
// টেমপ্লেট + পজিশন সেভ করা
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_template_settings'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['tc_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=print_transfer_certificate');
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
        $up = tc_handle_upload('custom_template');
        if (!$up['ok'] && $up['error']) {
            $_SESSION['tc_err'] = $up['error'];
            header('Location: index.php?page=print_transfer_certificate');
            exit();
        }
        $custom_path = $up['path'];
    }

    $stmt = $pdo->prepare(
        "UPDATE transfer_certificate_config SET template_style = ?, custom_template_path = COALESCE(?, custom_template_path), positions_json = ? WHERE id = 1"
    );
    $stmt->execute([$style, $custom_path, json_encode($positions)]);
    $_SESSION['tc_msg'] = 'টেমপ্লেট সেটিংস সেভ করা হয়েছে।';
    sec_log_activity($pdo, 'Print', 'Update', "Transfer Certificate টেমপ্লেট সেটিংস আপডেট করা হয়েছে ({$style})।");
    header('Location: index.php?page=print_transfer_certificate');
    exit();
}

// -----------------------------------------------------------
// Generate (Print) সাবমিট
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_certificate'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['tc_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=print_transfer_certificate');
        exit();
    }
    $_SESSION['tc_generate'] = $_POST;
    header('Location: index.php?page=print_transfer_certificate&view=output');
    exit();
}

$config = $pdo->query("SELECT * FROM transfer_certificate_config WHERE id = 1")->fetch();
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

<?php if ($view === 'output' && !empty($_SESSION['tc_generate'])): ?>
    <?php
    $g = $_SESSION['tc_generate'];
    unset($_SESSION['tc_generate']);

    $g_session = (int) ($g['session_id'] ?? 0);
    $g_class   = (int) ($g['class_id'] ?? 0);
    $g_shift   = (int) ($g['shift_id'] ?? 0);
    $g_section = (int) ($g['section_id'] ?? 0);
    $g_student = (int) ($g['student_row_id'] ?? 0);
    $g_lang    = ($g['language'] ?? 'en') === 'bn' ? 'bn' : 'en';

    $where = ['students.is_current = 1'];
    $params = [];
    if ($g_session) { $where[] = 'students.session_id = ?'; $params[] = $g_session; }
    if ($g_class)   { $where[] = 'students.class_id = ?'; $params[] = $g_class; }
    if ($g_shift)   { $where[] = 'students.shift_id = ?'; $params[] = $g_shift; }
    if ($g_section) { $where[] = 'students.section_id = ?'; $params[] = $g_section; }
    if ($g_student) { $where[] = 'students.id = ?'; $params[] = $g_student; }

    $sql = "SELECT students.*, academic_sessions.session_name, academic_classes.class_name
            FROM students
            LEFT JOIN academic_sessions ON academic_sessions.id = students.session_id
            LEFT JOIN academic_classes ON academic_classes.id = students.class_id
            WHERE " . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    // institute_settings.website কলামটা মূল schema.sql-এ নেই (কোনো self-heal ছাড়াই এতদিন
    // এখানে ব্যবহার হচ্ছিল) — ফলে TC Generate/Print করতে গেলেই "Unknown column 'website'"
    // Fatal error দিয়ে পুরো আউটপুট পেজই ক্র্যাশ করত, অর্থাৎ Transfer Certificate প্রিন্ট করাই
    // এতদিন সম্ভব ছিল না। এখানে নিরাপদে self-heal করে যোগ করা হলো।
    $tc_inst_cols = $pdo->query("SHOW COLUMNS FROM institute_settings")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('website', $tc_inst_cols, true)) {
        $pdo->exec("ALTER TABLE institute_settings ADD COLUMN website VARCHAR(150) NULL");
    }
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
    ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Great+Vibes&display=swap');
        @media print { .no-print { display: none !important; } .cert-page { page-break-after: always; box-shadow:none !important; } }
        .cert-page { width: 8.27in; height: 11.69in; margin: 0 auto 30px; background: #fff; position: relative; box-shadow: 0 0 6px rgba(0,0,0,0.25); font-family: Georgia, serif; overflow: hidden; }
        .cert-inner-border { position:absolute; inset:0.3in; border: 3px solid #1d70b8; }
        .cert-inner-border2 { position:absolute; inset:0.42in; border: 1px solid #1d70b8; }
        .cert-stripe-top { position:absolute; top:0.3in; left:0.3in; right:0.3in; height:14px; background: repeating-linear-gradient(90deg, #e0559f 0 10px, transparent 10px 20px); }
        .cert-ribbon { position:absolute; top:0.3in; left:0.3in; right:0.3in; height:8px; }
        .cert-dashed { position:absolute; inset:0.3in; border: 3px dashed #1d70b8; }
        .cert-plain-border { position:absolute; inset:0.4in; border: 2px solid #2b8a8a; }
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
        <a href="index.php?page=print_transfer_certificate" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>

    <?php if (!$students): ?>
        <div class="card-custom"><p class="text-muted mb-0">নির্বাচিত ফিল্টারে কোনো শিক্ষার্থী পাওয়া যায়নি।</p></div>
    <?php else: foreach ($students as $st):
        // Result Module-এর ডেটা থেকে অটো — কোনো রেজাল্ট না থাকলে (মার্ক এন্ট্রি/গ্রেড পয়েন্ট
        // সেট করা না থাকলে) null আসবে, তখন নিচের বাক্যটা বাদ পড়ে যাবে (আগের আচরণ ঠিক থাকবে)
        $tc_result = tc_get_result_summary($pdo, $st);
        if ($g_lang === 'bn') {
            $result_sentence = '';
            if ($tc_result) {
                $result_sentence = $tc_result['fail']
                    ? ' সর্বশেষ পরীক্ষায় সে অকৃতকার্য হয়েছিল।'
                    : ' সর্বশেষ পরীক্ষায় সে জিপিএ <b>' . tcv(number_format($tc_result['gpa'], 2)) . '</b> (গ্রেড <b>' . tcv($tc_result['gl']) . '</b>) নিয়ে কৃতকার্য হয়েছে।';
            }
            $body = "এই মর্মে প্রত্যয়ন করা যাচ্ছে যে, <b>" . tcv($st['name_en']) . " " . tcv($st['name_bn']) . "</b>, পিতা: <b>" . tcv($st['father_name']) . "</b>, মাতা: <b>" . tcv($st['mother_name']) . "</b> — " . tcv($inst['institute_name'] ?? '') . "-এর একজন নিয়মিত ছাত্র/ছাত্রী ছিল, শিক্ষাবর্ষ <b>" . tcv($st['session_name'] ?? '') . "</b>, শ্রেণি <b>" . tcv($st['class_name'] ?? '') . "</b>। তার আইডি নং <b>" . tcv($st['student_id']) . "</b>। জন্ম নিবন্ধন অনুযায়ী তার জন্ম তারিখ <b>" . tcv($st['dob']) . "</b>। প্রতিষ্ঠানের সকল পাওনা পরিশোধ করা হয়েছে।" . $result_sentence . "<br><br>আমার জানামতে, প্রতিষ্ঠানে অবস্থানকালীন সময়ে সে রাষ্ট্র বা আইনবিরোধী কোনো কর্মকাণ্ডে জড়িত ছিল না। তার নৈতিক চরিত্র ভালো। আমি তার সর্বাঙ্গীণ সাফল্য কামনা করছি।";
            $title = 'স্থানান্তর সনদ';
            $sign_label = 'প্রধান শিক্ষকের স্বাক্ষর';
        } else {
            $result_sentence = '';
            if ($tc_result) {
                $result_sentence = $tc_result['fail']
                    ? ' He/She was unsuccessful in the last examination held at this institution.'
                    : ' He/She has passed the last examination held at this institution securing GPA <b>' . tcv(number_format($tc_result['gpa'], 2)) . '</b> (Grade <b>' . tcv($tc_result['gl']) . '</b>).';
            }
            $body = "This is to certify that <b>" . tcv($st['name_en']) . " " . tcv($st['name_bn']) . "</b>, Father <b>" . tcv($st['father_name']) . "</b> and Mother <b>" . tcv($st['mother_name']) . "</b>, was a regular student of " . tcv($inst['institute_name'] ?? '') . " in the academic year <b>" . tcv($st['session_name'] ?? '') . "</b>, studying in class <b>" . tcv($st['class_name'] ?? '') . "</b>. His/Her ID No. is <b>" . tcv($st['student_id']) . "</b>. According to birth certificate, His/her date of birth is <b>" . tcv($st['dob']) . "</b>. He/She has cleared all dues of the institution." . $result_sentence . "<br><br>To my knowledge, during his/her study period at this institution, he/she was not involved in any activity against the state or law and order. His/Her moral character is good. I wish him/her all-round success.";
            $title = 'TRANSFER CERTIFICATE';
            $sign_label = 'Signature of Headmaster';
        }
        ?>
        <div class="cert-page <?php echo $style === 'style7' ? 'cert-style7' : ''; ?>">
            <?php if ($style === 'custom' && !empty($config['custom_template_path'])): ?>
                <img src="../<?php echo tcv($config['custom_template_path']); ?>" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
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
                <img src="../<?php echo tcv($inst['logo_path']); ?>" class="cert-watermark">
            <?php endif; ?>

            <?php if ($pos['header_visible'] || $style !== 'blank'): ?>
                <div class="cert-header" style="top:<?php echo $pos['header_top']; ?>in; left:<?php echo $pos['header_left']; ?>in;">
                    <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo tcv($inst['logo_path']); ?>"><br><?php endif; ?>
                    <h3><?php echo tcv($inst['institute_name'] ?? ''); ?></h3>
                    <p><?php echo tcv($addr_line); ?></p>
                    <?php
                    // ফোন/ওয়েবসাইট — যেটা সেট করা আছে শুধু সেটাই দেখাবে, খালি থাকলে সেই অংশ
                    // (আর মাঝের "|" আলাদা করার চিহ্নটাও) বাদ যাবে, যাতে "Mobile: |" এর মতো
                    // অসম্পূর্ণ লাইন না দেখায়
                    $contact_bits = [];
                    if (!empty($inst['phone_number'])) { $contact_bits[] = 'Mobile: ' . tcv($inst['phone_number']); }
                    if (!empty($inst['website'])) { $contact_bits[] = tcv($inst['website']); }
                    if ($contact_bits) { echo '<p>' . implode(' | ', $contact_bits) . '</p>'; }
                    ?>
                </div>
            <?php endif; ?>

            <div class="cert-title" style="top:<?php echo $pos['title_top']; ?>in;"><?php echo tcv($title); ?></div>
            <div class="cert-date" style="top:<?php echo $pos['date_top']; ?>in; right:<?php echo $pos['date_right']; ?>in;">Date: <?php echo date('d F, Y'); ?></div>
            <div class="cert-body" style="top:<?php echo $pos['body_top']; ?>in; left:<?php echo $pos['body_left']; ?>in; width:<?php echo $pos['body_width']; ?>in;"><?php echo $body; ?></div>
            <div class="cert-sign" style="top:<?php echo $pos['sign_top']; ?>in; left:<?php echo $pos['sign_left']; ?>in;">
                <div class="line"></div><?php echo tcv($sign_label); ?>
            </div>
            <?php if ($style === 'style7'): ?>
                <div class="cert-seal-wrap" style="top:<?php echo $pos['sign_top'] - 0.55; ?>in; left:0.9in;">
                    <div class="cert-seal"></div>
                    OFFICIAL SEAL
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; endif; ?>

<?php else: ?>
    <?php
    $tc_err = $_SESSION['tc_err'] ?? '';
    $tc_msg = $_SESSION['tc_msg'] ?? '';
    unset($_SESSION['tc_err'], $_SESSION['tc_msg']);
    ?>
    <div class="card-custom">
        <?php if ($tc_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($tc_msg); ?></div><?php endif; ?>
        <?php if ($tc_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($tc_err); ?></div><?php endif; ?>

        <!-- ================= ফিল্টার ফর্ম (উপরে) ================= -->
        <form method="POST" action="index.php?page=print_transfer_certificate" id="certConfigForm">
            <?php echo csrf_field(); ?>
            <div class="row">
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Session <span class="text-danger">*</span></label>
                    <select name="session_id" id="lpSession" class="form-select" required onchange="updateLivePreview()">
                        <option value="">Select Session</option>
                        <?php foreach ($sessions as $s): ?><option value="<?php echo (int) $s['id']; ?>"><?php echo tcv($s['session_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Class <span class="text-danger">*</span></label>
                    <select name="class_id" id="tcClassSelect" class="form-select" required onchange="filterTcSections(); filterTcShifts(); updateLivePreview();">
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo tcv($c['class_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Shift <span class="text-danger">*</span></label>
                    <select name="shift_id" id="tcShiftSelect" class="form-select" required disabled onchange="filterTcSections();">
                        <option value="">Select Class first</option>
                        <?php foreach ($shifts as $sh): ?><option value="<?php echo (int) $sh['id']; ?>" data-classes="<?php echo tcv(implode(',', $tc_class_shifts[$sh['id']] ?? [])); ?>" hidden><?php echo tcv($sh['shift_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Section <span class="text-danger">*</span></label>
                    <select name="section_id" id="tcSectionSelect" class="form-select" required disabled>
                        <option value="">Select Class first</option>
                        <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" data-shift="<?php echo (int) $sec['shift_id']; ?>" hidden><?php echo tcv($sec['section_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Student</label>
                    <div class="position-relative">
                        <input type="text" id="tcStudentSearch" class="form-control" placeholder="সবাই (Bulk) — ID/Name/Roll দিয়ে খুঁজুন" autocomplete="off" value="সবাই (Bulk)">
                        <input type="hidden" name="student_row_id" id="lpStudent" value="">
                        <div id="tcStudentDropdown" class="list-group position-absolute w-100" style="z-index:1000; max-height:240px; overflow-y:auto; display:none; top:100%;"></div>
                    </div>
                    <?php
                    $all_students = $pdo->query(
                        "SELECT students.id, students.name_en, students.name_bn, students.student_id, students.roll_no,
                                students.class_id, students.section_id, students.shift_id, students.father_name,
                                students.mother_name, students.dob, academic_classes.class_name, academic_sessions.session_name
                         FROM students
                         LEFT JOIN academic_classes ON academic_classes.id = students.class_id
                         LEFT JOIN academic_sessions ON academic_sessions.id = students.session_id
                         WHERE students.is_current = 1 ORDER BY students.name_en ASC LIMIT 2000"
                    )->fetchAll();
                    ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Language / ভাষা</label>
                    <select name="language" id="lpLanguage" class="form-select" onchange="updateLivePreview()">
                        <option value="en">English (ইংরেজি)</option>
                        <option value="bn">বাংলা (Bangla)</option>
                    </select>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary" onclick="toggleTemplatePanel()" title="Template Settings">
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
                    .live-preview-wrap { display:flex; justify-content:center; background:#eef1f5; padding:20px; border-radius:8px; }
                    #livePreviewPage {
                        width: 100%; max-width: 650px; aspect-ratio: 0.707; background:#fff; position:relative;
                        box-shadow: 0 0 10px rgba(0,0,0,0.15); font-family: Georgia, serif; overflow:hidden;
                    }
                    #livePreviewPage.lp-style7 { background:#faf6ea; }
                    #livePreviewPage .lp-border { position:absolute; inset:5%; border: 3px solid #1d70b8; }
                    #livePreviewPage .lp-border2 { position:absolute; inset:8%; border: 1px solid #2b8a4a; }
                    #livePreviewPage .lp-stripe { position:absolute; top:5%; left:5%; right:5%; height:8px; background: repeating-linear-gradient(90deg,#e0559f 0 8px, transparent 8px 16px); }
                    #livePreviewPage .lp-circle { position:absolute; width:16px; height:16px; border:2px solid #1d70b8; border-radius:50%; }
                    #livePreviewPage .lp-cb { position:absolute; width:14px; height:14px; }
                    #livePreviewPage .lp-cb-tl { top:6%; left:6%; border-top:2px solid; border-left:2px solid; }
                    #livePreviewPage .lp-cb-tr { top:6%; right:6%; border-top:2px solid; border-right:2px solid; }
                    #livePreviewPage .lp-cb-bl { bottom:6%; left:6%; border-bottom:2px solid; border-left:2px solid; }
                    #livePreviewPage .lp-cb-br { bottom:6%; right:6%; border-bottom:2px solid; border-right:2px solid; }
                    #livePreviewPage .lp-diamond { position:absolute; width:8px; height:8px; transform:rotate(45deg); border:1.5px solid; }
                    #livePreviewPage .lp-watermark { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); max-width:38%; max-height:38%; opacity:0.08; object-fit:contain; }
                    #livePreviewPage .lp-ornate-outer { position:absolute; inset:5%; border:2px solid #b8860b; }
                    #livePreviewPage .lp-ornate-band { position:absolute; inset:7%; border:1px dotted #b8860b; }
                    #livePreviewPage .lp-ornate-band2 { position:absolute; inset:8.5%; border:1px solid #8a6d1f; }
                    #livePreviewPage .lp-inner-hairline { position:absolute; inset:11%; border:1px solid #b8860b; }
                    #livePreviewPage.lp-style7 .lp-title { font-family:'Playfair Display', Georgia, serif; color:#8a6d1f; letter-spacing:2px; text-decoration:none; border-bottom:1px solid #b8860b; padding-bottom:2px; }
                    #livePreviewPage.lp-style7 .lp-header h5 { font-family:'Playfair Display', Georgia, serif; color:#7a5c17; }
                    #livePreviewPage.lp-style7 .lp-body b:nth-of-type(1) { font-family:'Great Vibes', cursive; font-size:15px; color:#8a6d1f; font-weight:normal; }
                    #livePreviewPage .lp-header { position:absolute; top:8%; left:15%; right:15%; text-align:center; }
                    #livePreviewPage .lp-header h5 { font-weight:bold; color:#1e3a5f; margin:4px 0 0; font-size:15px; }
                    #livePreviewPage .lp-header p { font-size:10px; color:#666; margin:0; }
                    #livePreviewPage .lp-title { position:absolute; top:26%; left:15%; right:15%; text-align:center; font-weight:bold; font-size:14px; text-decoration:underline; }
                    #livePreviewPage .lp-date { position:absolute; top:22%; right:8%; font-size:10px; color:#666; }
                    #livePreviewPage .lp-body { position:absolute; top:33%; left:8%; right:8%; font-size:11px; line-height:1.9; color:#222; }
                    #livePreviewPage .lp-sign { position:absolute; bottom:10%; right:10%; text-align:center; font-size:10px; }
                    #livePreviewPage .lp-sign .line { border-top:1px solid #333; width:110px; margin-bottom:4px; }
                </style>
                <p class="small text-muted mb-2"><i class="fa-solid fa-eye"></i> লাইভ প্রিভিউ — উপরে ফিল্টার/ভাষা বদলালে এখানে সাথে সাথে দেখা যাবে। (আসল প্রিন্ট A4 সাইজে হবে, এটা শুধু প্রিভিউ)</p>
                <div class="live-preview-wrap">
                    <div id="livePreviewPage">
                        <div id="lpBorderLayer"></div>
                        <?php if (!empty($inst['logo_path'])): ?>
                            <img src="../<?php echo tcv($inst['logo_path']); ?>" class="lp-watermark" id="lpWatermark">
                        <?php endif; ?>
                        <div class="lp-header" id="lpHeader">
                            <h5><?php echo tcv($inst_name = $pdo->query("SELECT institute_name FROM institute_settings WHERE id=1")->fetchColumn() ?: ''); ?></h5>
                            <p><?php echo tcv($branch_addr = ($branch ? $branch['address'] . ', ' . $branch['city'] : '')); ?></p>
                        </div>
                        <div class="lp-title" id="lpTitle">TRANSFER CERTIFICATE</div>
                        <div class="lp-date" id="lpDate">Date: <?php echo date('d F, Y'); ?></div>
                        <div class="lp-body" id="lpBody">শিক্ষার্থী নির্বাচন করুন প্রিভিউ দেখতে...</div>
                        <div class="lp-sign"><div class="line"></div><span id="lpSignLabel">Signature of Headmaster</span></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4" id="templateOptionsPanel">
                <div class="card-custom">
                    <h6 class="fw-bold mb-3">Template Options</h6>
                    <form method="POST" action="index.php?page=print_transfer_certificate" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>

                        <style>
                            .tpl-card { display:flex; align-items:center; gap:12px; padding:10px; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:10px; cursor:pointer; }
                            .tpl-card:hover { border-color:#93c5fd; }
                            .tpl-card.tpl-selected { border-color:#1d70b8; border-width:2px; background:#f0f7ff; }
                            .tpl-preview { position:relative; width:90px; height:64px; background:#fff; border:1px solid #cbd5e1; border-radius:4px; overflow:hidden; flex-shrink:0; }
                            .tpl-preview .tpl-line { position:absolute; }
                            .tpl-mock-header { top:5px; left:6px; right:6px; height:5px; background:#94a3b8; border-radius:2px; }
                            .tpl-mock-title { top:14px; left:16px; right:16px; height:4px; background:#334155; border-radius:2px; }
                            .tpl-mock-text { left:6px; right:6px; height:3px; background:#cbd5e1; border-radius:2px; }
                            .tpl-p1-stripe { top:2px; left:2px; right:2px; height:4px; background: repeating-linear-gradient(90deg,#b8860b 0 4px, transparent 4px 8px); }
                            .tpl-p1-border { inset:2px; border:1.5px solid #1e3a5f; }
                            .tpl-p1-border2 { inset:5px; border:1px solid #b8860b; }
                            .tpl-p2-border { inset:2px; border:2px solid #1f4d36; }
                            .tpl-p2-border2 { inset:5px; border:1px solid #1f4d36; }
                            .tpl-p2-circle { width:7px;height:7px;border:1.5px solid #1f4d36;border-radius:50%; }
                            .tpl-p4-border { inset:2px; border:1px solid #1d4ed8; }
                            .tpl-p5-border { inset:4px; border:1px dashed #0f6466; }
                            .tpl-p5-stripe { top:2px; left:4px; right:4px; height:3px; background: repeating-linear-gradient(90deg,#0f6466 0 4px, transparent 4px 7px); }
                            .tpl-p6-border { inset:1px; border:3px solid #1f2937; }
                            .tpl-p6-border2 { inset:5px; border:1px solid #b8860b; }
                            .tpl-p7-band { inset:2px; border:4px solid #b8860b; opacity:0.55; }
                            .tpl-p7-inner { inset:9px; border:1px solid #b8860b; }
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
                            <label class="tpl-card <?php echo $selected ? 'tpl-selected' : ''; ?>">
                                <input type="radio" name="template_style" value="<?php echo $key; ?>" <?php echo $selected ? 'checked' : ''; ?>
                                    onclick="document.querySelectorAll('.tpl-card').forEach(c=>c.classList.remove('tpl-selected'));this.closest('.tpl-card').classList.add('tpl-selected');updateLivePreviewBorder('<?php echo $key; ?>');">
                                <div class="tpl-preview">
                                    <?php if ($key === 'style1'): ?>
                                        <div class="tpl-line tpl-p1-stripe"></div><div class="tpl-line tpl-p1-border"></div><div class="tpl-line tpl-p1-border2"></div>
                                        <div class="tpl-line tpl-mock-header" style="top:14px;"></div><div class="tpl-line tpl-mock-title" style="top:26px;"></div>
                                        <div class="tpl-line tpl-mock-text" style="top:38px;"></div><div class="tpl-line tpl-mock-text" style="top:44px;right:30px;"></div>
                                    <?php elseif ($key === 'style2'): ?>
                                        <div class="tpl-line tpl-p2-border"></div><div class="tpl-line tpl-p2-border2"></div>
                                        <div class="tpl-line tpl-p2-circle" style="top:6px;left:6px;"></div><div class="tpl-line tpl-p2-circle" style="top:6px;right:6px;"></div>
                                        <div class="tpl-line tpl-mock-header" style="top:16px;"></div><div class="tpl-line tpl-mock-title" style="top:28px;"></div>
                                        <div class="tpl-line tpl-mock-text" style="top:40px;"></div><div class="tpl-line tpl-mock-text" style="top:46px;right:30px;"></div>
                                    <?php elseif ($key === 'style3'): ?>
                                        <div class="tpl-line tpl-mock-header" style="top:8px;"></div><div class="tpl-line tpl-mock-title" style="top:20px;"></div>
                                        <div class="tpl-line tpl-mock-text" style="top:34px;"></div><div class="tpl-line tpl-mock-text" style="top:40px;right:30px;"></div>
                                        <div class="tpl-line tpl-mock-text" style="top:46px;right:10px;"></div>
                                    <?php elseif ($key === 'style4'): ?>
                                        <div class="tpl-line tpl-p4-border"></div>
                                        <div class="tpl-line tpl-mock-header" style="top:9px;"></div><div class="tpl-line tpl-mock-title" style="top:20px;"></div>
                                        <div class="tpl-line tpl-mock-text" style="top:34px;"></div><div class="tpl-line tpl-mock-text" style="top:40px;right:30px;"></div>
                                    <?php elseif ($key === 'style5'): ?>
                                        <div class="tpl-line tpl-p5-stripe"></div><div class="tpl-line tpl-p5-border"></div>
                                        <div class="tpl-line tpl-mock-header" style="top:11px;"></div><div class="tpl-line tpl-mock-title" style="top:22px;"></div>
                                        <div class="tpl-line tpl-mock-text" style="top:35px;"></div><div class="tpl-line tpl-mock-text" style="top:41px;right:30px;"></div>
                                    <?php elseif ($key === 'style6'): ?>
                                        <div class="tpl-line tpl-p6-border"></div><div class="tpl-line tpl-p6-border2"></div>
                                        <div class="tpl-line tpl-mock-header" style="top:10px;"></div><div class="tpl-line tpl-mock-title" style="top:21px;"></div>
                                        <div class="tpl-line tpl-mock-text" style="top:34px;"></div><div class="tpl-line tpl-mock-text" style="top:40px;right:30px;"></div>
                                    <?php elseif ($key === 'style7'): ?>
                                        <div style="position:absolute;inset:0;background:#faf6ea;"></div>
                                        <div class="tpl-line tpl-p7-band"></div>
                                        <div class="tpl-line tpl-p7-inner"></div>
                                        <div class="tpl-line tpl-mock-header" style="top:13px;background:#8a6d1f;"></div><div class="tpl-line tpl-mock-title" style="top:24px;background:#8a6d1f;"></div>
                                        <div class="tpl-line tpl-mock-text" style="top:38px;background:#d9c48a;"></div><div class="tpl-line tpl-mock-text" style="top:44px;right:30px;background:#d9c48a;"></div>
                                    <?php elseif ($key === 'custom'): ?>
                                        <?php if (!empty($config['custom_template_path'])): ?>
                                            <img src="../<?php echo tcv($config['custom_template_path']); ?>" style="width:100%;height:100%;object-fit:cover;">
                                        <?php else: ?>
                                            <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:9px;">No file</div>
                                        <?php endif; ?>
                                    <?php elseif ($key === 'blank'): ?>
                                        <div class="tpl-line tpl-mock-title" style="top:8px;left:10px;right:30px;"></div>
                                        <div class="tpl-line tpl-mock-text" style="top:20px;"></div><div class="tpl-line tpl-mock-text" style="top:26px;right:30px;"></div>
                                        <div class="tpl-line tpl-mock-text" style="top:38px;"></div>
                                    <?php endif; ?>
                                </div>
                                <div class="small fw-bold"><?php echo $label; ?></div>
                            </label>
                        <?php endforeach; ?>
                        </div>

                        <div class="mb-2 mt-2">
                            <label class="form-label small">Custom Template Image (jpg/png, max 3MB)</label>
                            <input type="file" name="custom_template" class="form-control form-control-sm" accept=".jpg,.jpeg,.png">
                        </div>

                        <details class="mb-2">
                            <summary class="small fw-bold" style="cursor:pointer;">Field Position (ইঞ্চিতে) — pre-printed কার্ডের জন্য</summary>
                            <div class="form-check form-switch mt-2 mb-2">
                                <input class="form-check-input" type="checkbox" id="posHeaderVisible" name="pos_header_visible" <?php echo $saved_positions['header_visible'] ? 'checked' : ''; ?>>
                                <label class="form-check-label small" for="posHeaderVisible">Header প্রিন্ট করুন</label>
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
                                        <input type="number" step="0.05" name="pos_<?php echo $key; ?>" class="form-control form-control-sm" value="<?php echo tcv($saved_positions[$key]); ?>">
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
        function filterTcSections() {
            const classId = document.getElementById('tcClassSelect').value;
            const shiftId = document.getElementById('tcShiftSelect').value;
            const select = document.getElementById('tcSectionSelect');
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

        function filterTcShifts() {
            const classId = document.getElementById('tcClassSelect').value;
            const select = document.getElementById('tcShiftSelect');
            select.disabled = !classId;
            Array.from(select.options).forEach(opt => {
                if (!opt.value) { opt.hidden = false; return; }
                const classesList = (opt.dataset.classes || '').split(',').filter(Boolean);
                // এই Shift কোনো Section-এর সাথে এখনো লিংক করা না থাকলে (data-classes ফাঁকা),
                // নিরাপদ থাকার জন্য সব Class-এর জন্যই দেখানো হবে
                opt.hidden = !!classId && classesList.length > 0 && !classesList.includes(classId);
            });
            select.options[0].textContent = classId ? 'Select Shift' : 'Select Class first';
            if (select.value && Array.from(select.options).find(o => o.value === select.value)?.hidden) {
                select.value = '';
            }
        }
        filterTcSections();
        filterTcShifts();

        function toggleTemplatePanel() {
            const panel = document.getElementById('templateOptionsPanel');
            panel.style.display = (panel.style.display === 'none') ? '' : 'none';
        }

        // ------------------------------------------------------------------
        // Student সার্চ — সিলেক্ট করা Class/Section অনুযায়ী ফিল্টার হয়,
        // এবং Student ID / Name / Roll দিয়ে টাইপ করে সার্চও করা যায়
        // ------------------------------------------------------------------
        const tcAllStudents = <?php echo json_encode(array_map(function ($s) {
            return [
                'id' => (int) $s['id'], 'name_en' => $s['name_en'], 'name_bn' => $s['name_bn'],
                'sid' => $s['student_id'], 'roll' => $s['roll_no'],
                'class_id' => (int) $s['class_id'], 'section_id' => (int) $s['section_id'], 'shift_id' => (int) $s['shift_id'],
                'father' => $s['father_name'], 'mother' => $s['mother_name'], 'dob' => $s['dob'],
                'class' => $s['class_name'], 'session' => $s['session_name'],
            ];
        }, $all_students), JSON_UNESCAPED_UNICODE); ?>;

        let tcSelectedStudent = null;

        const tcSearchInput = document.getElementById('tcStudentSearch');
        const tcHiddenId = document.getElementById('lpStudent');
        const tcDropdown = document.getElementById('tcStudentDropdown');
        const tcClassSelect = document.getElementById('tcClassSelect');
        const tcSectionSelect = document.getElementById('tcSectionSelect');
        const tcShiftSelect = document.getElementById('tcShiftSelect');

        function tcMatchingStudents() {
            const classId = tcClassSelect.value;
            const sectionId = tcSectionSelect.value;
            const shiftId = tcShiftSelect.value;
            const q = tcSearchInput.value.trim().toLowerCase();
            const isBulkText = tcSearchInput.value.trim() === 'সবাই (Bulk)';
            return tcAllStudents.filter(function (s) {
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

        function tcEsc(s) {
            const d = document.createElement('div');
            d.textContent = s || '';
            return d.innerHTML;
        }

        function tcRenderDropdown() {
            const matches = tcMatchingStudents().slice(0, 50);
            let html = '<button type="button" class="list-group-item list-group-item-action fw-bold" data-id="">সবাই (Bulk)</button>';
            matches.forEach(function (s) {
                const label = tcEsc(s.sid) + ' — ' + tcEsc(s.name_en);
                html += '<button type="button" class="list-group-item list-group-item-action" data-id="' + s.id + '" data-label="' + label.replace(/"/g, '&quot;') + '">'
                    + tcEsc(s.sid) + ' — ' + tcEsc(s.name_en) + (s.roll ? ' (Roll: ' + tcEsc(s.roll) + ')' : '') + '</button>';
            });
            if (matches.length === 0) {
                html += '<div class="list-group-item text-muted small">এই Class/Section-এ কোনো স্টুডেন্ট পাওয়া যায়নি।</div>';
            }
            tcDropdown.innerHTML = html;
            tcDropdown.style.display = 'block';
        }

        tcSearchInput.addEventListener('focus', function () {
            tcRenderDropdown();
            if (tcSearchInput.value.trim() === 'সবাই (Bulk)') tcSearchInput.select();
        });
        tcSearchInput.addEventListener('input', tcRenderDropdown);
        tcClassSelect.addEventListener('change', function () {
            // Class বদলালে আগের সিলেক্ট করা Student আর প্রযোজ্য নাও হতে পারে, তাই রিসেট
            tcHiddenId.value = '';
            tcSelectedStudent = null;
            tcSearchInput.value = 'সবাই (Bulk)';
            updateLivePreview();
        });
        tcSectionSelect.addEventListener('change', function () {
            tcHiddenId.value = '';
            tcSelectedStudent = null;
            tcSearchInput.value = 'সবাই (Bulk)';
            updateLivePreview();
        });
        tcShiftSelect.addEventListener('change', function () {
            tcHiddenId.value = '';
            tcSelectedStudent = null;
            tcSearchInput.value = 'সবাই (Bulk)';
            updateLivePreview();
        });

        tcDropdown.addEventListener('click', function (e) {
            const btn = e.target.closest('button[data-id]');
            if (!btn) return;
            const id = btn.dataset.id;
            tcHiddenId.value = id;
            tcSelectedStudent = id === '' ? null : (tcAllStudents.find(s => String(s.id) === id) || null);
            tcSearchInput.value = id === '' ? 'সবাই (Bulk)' : btn.dataset.label;
            tcDropdown.style.display = 'none';
            updateLivePreview();
        });

        document.addEventListener('click', function (e) {
            if (!tcDropdown.contains(e.target) && e.target !== tcSearchInput) {
                tcDropdown.style.display = 'none';
            }
        });

        const certTexts = {
            en: (d) => `This is to certify that <b>${d.name_en} ${d.name_bn}</b>, Father <b>${d.father}</b> and Mother <b>${d.mother}</b>, was a regular student of this institution in the academic year <b>${d.session}</b>, studying in class <b>${d.class}</b>. His/Her ID No. is <b>${d.sid}</b>. His/her date of birth is <b>${d.dob}</b>. He/She has cleared all dues of the institution.<br><br>To my knowledge, he/she was not involved in any anti-state or unlawful activity. His/Her moral character is good. I wish him/her all-round success.`,
            bn: (d) => `এই মর্মে প্রত্যয়ন করা যাচ্ছে যে, <b>${d.name_en} ${d.name_bn}</b>, পিতা: <b>${d.father}</b>, মাতা: <b>${d.mother}</b> — এই প্রতিষ্ঠানের একজন নিয়মিত ছাত্র/ছাত্রী ছিল, শিক্ষাবর্ষ <b>${d.session}</b>, শ্রেণি <b>${d.class}</b>। আইডি নং <b>${d.sid}</b>। জন্ম তারিখ <b>${d.dob}</b>। সকল পাওনা পরিশোধ করা হয়েছে।<br><br>আমার জানামতে, সে রাষ্ট্র বা আইনবিরোধী কোনো কর্মকাণ্ডে জড়িত ছিল না। তার নৈতিক চরিত্র ভালো। আমি তার সর্বাঙ্গীণ সাফল্য কামনা করছি।`
        };
        const certTitles = { en: 'TRANSFER CERTIFICATE', bn: 'স্থানান্তর সনদ' };
        const certSign = { en: 'Signature of Headmaster', bn: 'প্রধান শিক্ষকের স্বাক্ষর' };

        function updateLivePreview() {
            const lang = document.getElementById('lpLanguage').value;

            document.getElementById('lpTitle').textContent = certTitles[lang];
            document.getElementById('lpSignLabel').textContent = certSign[lang];

            if (!tcSelectedStudent) {
                document.getElementById('lpBody').textContent = lang === 'bn' ? 'শিক্ষার্থী নির্বাচন করুন প্রিভিউ দেখতে...' : 'Select a student to preview...';
                return;
            }

            const s = tcSelectedStudent;
            const d = {
                name_en: s.name_en || '', name_bn: s.name_bn || '',
                father: s.father || '', mother: s.mother || '',
                dob: s.dob || '', sid: s.sid || '',
                class: s.class || '', session: s.session || '',
            };
            document.getElementById('lpBody').innerHTML = certTexts[lang](d);
        }

        function updateLivePreviewBorder(style) {
            const layer = document.getElementById('lpBorderLayer');
            const wm = document.getElementById('lpWatermark');
            const page = document.getElementById('livePreviewPage');
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
                document.getElementById('lpHeader').style.display = 'none';
                html = '';
            }
            if (style !== 'blank') document.getElementById('lpHeader').style.display = '';
            if (wm) wm.style.display = (style === 'style3' || style === 'blank' || style === 'custom' || style === 'style7') ? 'none' : '';
            if (page) page.classList.toggle('lp-style7', style === 'style7');
            layer.innerHTML = html;
        }

        updateLivePreviewBorder('<?php echo $config['template_style']; ?>');
        updateLivePreview();
    </script>
    </div>
<?php endif; ?>
