<?php
// admin/print_student_testimonial.php - Print Student Testimonial (প্রত্যয়নপত্র)
function tsv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// ব্রাউজারের Back বাটনে চাপলে যাতে bfcache থেকে পুরনো (ফর্মে ভরা/আগের আউটপুট)
// পেজ না দেখিয়ে সার্ভার থেকে টাটকা/ফাঁকা পেজ রিলোড হয়, সেজন্য no-cache header
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$view = $_GET['view'] ?? 'config';

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes ORDER BY rank ASC, class_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections ORDER BY section_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts ORDER BY shift_name ASC")->fetchAll();

// একটা Class-এ কোন কোন Shift আছে (Class সিলেক্ট না করা পর্যন্ত Shift/Section বন্ধ থাকবে)
$tst_class_shifts = [];
foreach ($sections as $sec) {
    if ($sec['shift_id']) {
        $tst_class_shifts[(int) $sec['shift_id']][] = (int) $sec['class_id'];
    }
}

// Class/Section সিলেক্ট করলে Student ড্রপডাউন সেই অনুযায়ী ফিল্টার হবে এবং
// টাইপ করে (ID/Name/Roll) সার্চও করা যাবে — তাই সবগুলো Current Student একসাথে
// JS-এ পাঠিয়ে দেওয়া হচ্ছে, client-side ফিল্টারিং হবে
$tst_stu_where = ['students.is_current = 1'];
$tst_stu_params = [];
branch_apply_filter($tst_stu_where, $tst_stu_params, 'students.branch_id');
$tst_stu_stmt = $pdo->prepare(
    "SELECT id, name_en, student_id, roll_no, class_id, section_id FROM students WHERE " . implode(' AND ', $tst_stu_where) . " ORDER BY name_en ASC LIMIT 2000"
);
$tst_stu_stmt->execute($tst_stu_params);
$all_students = $tst_stu_stmt->fetchAll();

// -----------------------------------------------------------
// Testimonial Settings (Header/Footer toggle, Orientation, Template)
// টেবিল না থাকলে অটো-তৈরি + ডিফল্ট রো
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS testimonial_settings (
        id INT PRIMARY KEY DEFAULT 1,
        show_header TINYINT(1) NOT NULL DEFAULT 1,
        show_footer TINYINT(1) NOT NULL DEFAULT 1,
        orientation VARCHAR(10) NOT NULL DEFAULT 'portrait',
        template_style VARCHAR(20) NOT NULL DEFAULT 'classic',
        custom_template_path VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
if (!$pdo->query("SELECT id FROM testimonial_settings WHERE id = 1")->fetch()) {
    $pdo->exec("INSERT INTO testimonial_settings (id) VALUES (1)");
}

// কাস্টম টেমপ্লেট (background/letterhead) আপলোড হেল্পার — id_card_templates এর
// প্যাটার্ন অনুসরণ করে (tenant-wise ফোল্ডার, jpg/png only, mime যাচাই)
function tst_handle_template_upload(string $field): array
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'path' => null, 'error' => 'কোনো ফাইল আপলোড করা হয়নি।'];
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'ফাইল আপলোডে সমস্যা হয়েছে।'];
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        return ['ok' => false, 'path' => null, 'error' => 'ফাইলের সাইজ সর্বোচ্চ 3MB হতে হবে।'];
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
    $upload_dir = tenant_upload_abs(__DIR__ . '/..', 'testimonial_templates');
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $random_name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $random_name)) {
        return ['ok' => false, 'path' => null, 'error' => 'ফাইল সেভ করা যায়নি।'];
    }
    return ['ok' => true, 'path' => tenant_upload_rel('testimonial_templates') . '/' . $random_name, 'error' => null];
}

// ---- সেটিংস সেভ (Header/Footer toggle, Orientation, Template select) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_testimonial_settings'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['tst_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $tpl = $_POST['template_style'] ?? 'classic';
        if (!in_array($tpl, ['classic', 'modern', 'elegant', 'custom'], true)) { $tpl = 'classic'; }
        $existing = $pdo->query("SELECT custom_template_path FROM testimonial_settings WHERE id = 1")->fetch();
        if ($tpl === 'custom' && empty($existing['custom_template_path'])) {
            $tpl = 'classic'; // কাস্টম টেমপ্লেট আপলোড না থাকলে custom সিলেক্ট করা যাবে না
        }
        $orientation = ($_POST['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';
        $pdo->prepare("UPDATE testimonial_settings SET show_header = ?, show_footer = ?, orientation = ?, template_style = ? WHERE id = 1")
            ->execute([
                isset($_POST['show_header']) ? 1 : 0,
                isset($_POST['show_footer']) ? 1 : 0,
                $orientation,
                $tpl,
            ]);
        $_SESSION['tst_msg'] = 'টেস্টিমোনিয়াল সেটিংস সংরক্ষণ করা হয়েছে।';
        sec_log_activity($pdo, 'Print', 'Update', 'টেস্টিমোনিয়াল সেটিংস আপডেট করা হয়েছে।');
    }
    header('Location: index.php?page=print_student_testimonial');
    exit();
}

// ---- কাস্টম টেমপ্লেট আপলোড ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_testimonial_template'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['tst_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $up = tst_handle_template_upload('custom_template_image');
        if (!$up['ok']) {
            $_SESSION['tst_err'] = $up['error'];
        } else {
            $old = $pdo->query("SELECT custom_template_path FROM testimonial_settings WHERE id = 1")->fetch();
            if (!empty($old['custom_template_path'])) {
                $old_abs = __DIR__ . '/../' . $old['custom_template_path'];
                if (is_file($old_abs)) { @unlink($old_abs); }
            }
            $pdo->prepare("UPDATE testimonial_settings SET custom_template_path = ?, template_style = 'custom' WHERE id = 1")
                ->execute([$up['path']]);
            $_SESSION['tst_msg'] = 'কাস্টম টেমপ্লেট আপলোড করে সিলেক্ট করা হয়েছে।';
            sec_log_activity($pdo, 'Print', 'Update', 'কাস্টম টেস্টিমোনিয়াল টেমপ্লেট আপলোড করা হয়েছে।');
        }
    }
    header('Location: index.php?page=print_student_testimonial');
    exit();
}

// ---- কাস্টম টেমপ্লেট মুছে ফেলা ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_testimonial_template'])) {
    if (csrf_verify($_POST['csrf_token'] ?? null)) {
        $old = $pdo->query("SELECT custom_template_path FROM testimonial_settings WHERE id = 1")->fetch();
        if (!empty($old['custom_template_path'])) {
            $old_abs = __DIR__ . '/../' . $old['custom_template_path'];
            if (is_file($old_abs)) { @unlink($old_abs); }
        }
        $pdo->prepare("UPDATE testimonial_settings SET custom_template_path = NULL, template_style = IF(template_style = 'custom', 'classic', template_style) WHERE id = 1")->execute();
        $_SESSION['tst_msg'] = 'কাস্টম টেমপ্লেট মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Print', 'Delete', 'কাস্টম টেস্টিমোনিয়াল টেমপ্লেট মুছে ফেলা হয়েছে।');
    }
    header('Location: index.php?page=print_student_testimonial');
    exit();
}

$tst_settings = $pdo->query("SELECT * FROM testimonial_settings WHERE id = 1")->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_testimonial'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['tst_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=print_student_testimonial');
        exit();
    }
    $_SESSION['tst_generate'] = $_POST;
    header('Location: index.php?page=print_student_testimonial&view=output');
    exit();
}
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

<?php if ($view === 'output' && !empty($_SESSION['tst_generate'])): ?>
    <?php
    $g = $_SESSION['tst_generate'];
    unset($_SESSION['tst_generate']);

    $g_session  = (int) ($g['session_id'] ?? 0);
    $g_class    = (int) ($g['class_id'] ?? 0);
    $g_shift    = (int) ($g['shift_id'] ?? 0);
    $g_section  = (int) ($g['section_id'] ?? 0);
    $g_student  = (int) ($g['student_row_id'] ?? 0);
    $g_lang     = ($g['language'] ?? 'en') === 'bn' ? 'bn' : 'en';
    $g_conduct  = $g['conduct'] ?? 'Good';
    $conduct_map_bn = ['Good' => 'ভালো', 'Very Good' => 'অতি উত্তম', 'Excellent' => 'চমৎকার'];

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

    $inst = $pdo->query("SELECT institute_name, phone_number, logo_path FROM institute_settings WHERE id = 1")->fetch();
    $fr_bid2 = branch_current_id();
if ($fr_bid2 !== null) {
    $branch_stmt2 = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $branch_stmt2->execute([$fr_bid2]);
    $branch = $branch_stmt2->fetch();
} else {
    $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
}
    ?>
    <style>
        @media print {
            @page { size: A4 <?php echo $tst_settings['orientation'] === 'landscape' ? 'landscape' : 'portrait'; ?>; }
            .no-print { display: none !important; }
            /* এই সিস্টেমের মূল অ্যাপ শেল (sidebar/topbar/breadcrumb) প্রিন্টে সম্পূর্ণ হাইড,
               যাতে শুধু টেস্টিমোনিয়াল কার্ডটাই প্রিন্ট হয় */
            .sidebar, .sticky-top-area { display: none !important; }
            .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; }
            body { background: #fff !important; }
            .testimonial-page {
                page-break-after: always;
                page-break-inside: avoid;
                break-inside: avoid;
                border: none !important;
                margin: 0 auto !important;
            }
        }
        .testimonial-page { max-width: <?php echo $tst_settings['orientation'] === 'landscape' ? '1150px' : '900px'; ?>; margin: 0 auto 30px; padding: 40px; border: 1px solid #ddd; background: #fff; font-family: <?php echo $g_lang === 'bn' ? "'Noto Sans Bengali', Arial" : 'Georgia, serif'; ?>, sans-serif; }
        .testimonial-body { font-size: 15px; line-height: 2; margin-top: 20px; }
        .signature-row { display:flex; justify-content: space-between; margin-top: 60px; }
        .signature-line { border-top: 1px solid #333; width: 200px; text-align: center; padding-top: 6px; font-size: 13px; }

        /* --- Template Styles --- */
        .testimonial-page.tpl-modern { border: none; border-top: 6px solid #0d9488; }
        .testimonial-page.tpl-modern .tst-heading { color: #0d9488; letter-spacing: 1px; }
        .testimonial-page.tpl-elegant { border: 3px double #92400e; }
        .testimonial-page.tpl-elegant .tst-heading { color: #92400e; }
        .testimonial-page.tpl-custom {
            border: none;
            background-image: url('../<?php echo tsv($tst_settings['custom_template_path'] ?? ''); ?>');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }
    </style>

    <div class="no-print d-flex justify-content-between align-items-center mb-3">
        <a href="index.php?page=print_student_testimonial" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>

    <?php if (!$students): ?>
        <div class="card-custom"><p class="text-muted mb-0">নির্বাচিত ফিল্টারে কোনো শিক্ষার্থী পাওয়া যায়নি।</p></div>
    <?php else: foreach ($students as $st):
        $ref_no = 'REF/' . date('Y') . '/' . str_pad((string) $st['id'], 4, '0', STR_PAD_LEFT);
        $conduct_text = $g_lang === 'bn' ? ($conduct_map_bn[$g_conduct] ?? 'ভালো') : $g_conduct;
    ?>
        <div class="testimonial-page tpl-<?php echo tsv($tst_settings['template_style']); ?>">
            <?php if ($tst_settings['show_header']): ?>
            <div class="text-center mb-2">
                <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo tsv($inst['logo_path']); ?>" style="max-height:70px;"><br><?php endif; ?>
                <h4 class="fw-bold text-primary tst-heading mb-1"><?php echo tsv($inst['institute_name'] ?? ''); ?></h4>
                <p class="small text-muted mb-0">
                    <?php if ($branch): ?>(<?php echo tsv($branch['address']); ?>, <?php echo tsv($branch['city']); ?>-<?php echo tsv($branch['zip_code']); ?>)<br><?php endif; ?>
                    <?php if ($g_lang === 'bn'): ?>ফোন: <?php else: ?>Phone: <?php endif; ?><?php echo tsv($inst['phone_number'] ?? ''); ?>
                </p>
            </div>
            <?php endif; ?>

            <h5 class="text-center fw-bold mt-4" style="letter-spacing:1px;">
                <?php echo $g_lang === 'bn' ? 'ছাত্র/ছাত্রী প্রত্যয়নপত্র' : 'STUDENT TESTIMONIAL'; ?>
            </h5>

            <div class="d-flex justify-content-between mt-4" style="font-size:14px;">
                <span><?php echo $g_lang === 'bn' ? 'স্মারক নং' : 'Ref No'; ?>: <?php echo tsv($ref_no); ?></span>
                <span><?php echo $g_lang === 'bn' ? 'তারিখ' : 'Date'; ?>: <?php echo date('d/m/Y'); ?></span>
            </div>

            <div class="testimonial-body">
                <?php if ($g_lang === 'bn'): ?>
                    এই মর্মে প্রত্যয়ন করা যাচ্ছে যে, জনাব/জনাবা <strong><?php echo tsv($st['name_bn'] ?: $st['name_en']); ?></strong>
                    (স্টুডেন্ট আইডি: <strong><?php echo tsv($st['student_id']); ?></strong>),
                    পিতা: <strong><?php echo tsv($st['father_name']); ?></strong>, মাতা: <strong><?php echo tsv($st['mother_name']); ?></strong> —
                    এই প্রতিষ্ঠানের একজন নিয়মিত ছাত্র/ছাত্রী ছিল, যার রোল নং <strong><?php echo tsv($st['roll_no']); ?></strong>,
                    শ্রেণি <strong><?php echo tsv($st['class_name'] ?? ''); ?></strong>, শাখা <strong><?php echo tsv($st['section_name'] ?? ''); ?></strong>,
                    শিক্ষাবর্ষ <strong><?php echo tsv($st['session_name'] ?? ''); ?></strong>।
                    <br><br>
                    প্রতিষ্ঠানে অবস্থানকালীন সময়ে তার আচার-আচরণ ও নৈতিক চরিত্র <strong><?php echo tsv($conduct_text); ?></strong> ছিল বলে জানা যায়।
                    সে একজন নিয়মিত, আন্তরিক ও শৃঙ্খলাপরায়ণ শিক্ষার্থী।
                    <br><br>
                    ভবিষ্যৎ জীবনে তার সর্বাঙ্গীণ সাফল্য কামনা করছি।
                <?php else: ?>
                    This is to certify that Mr./Ms. <strong><?php echo tsv($st['name_en']); ?></strong>
                    (Student ID: <strong><?php echo tsv($st['student_id']); ?></strong>), son/daughter of
                    Mr. <strong><?php echo tsv($st['father_name']); ?></strong> and
                    Mrs. <strong><?php echo tsv($st['mother_name']); ?></strong>, was a bona fide student of this
                    institution, bearing Roll No. <strong><?php echo tsv($st['roll_no']); ?></strong> of Class
                    <strong><?php echo tsv($st['class_name'] ?? ''); ?></strong>, Section
                    <strong><?php echo tsv($st['section_name'] ?? ''); ?></strong>, during the academic session
                    <strong><?php echo tsv($st['session_name'] ?? ''); ?></strong>.
                    <br><br>
                    During his/her stay in this institution, his/her conduct and moral character were found to be
                    <strong><?php echo tsv($conduct_text); ?></strong>. He/She is a regular, sincere and disciplined student.
                    <br><br>
                    We wish him/her every success in future life.
                <?php endif; ?>
            </div>

            <?php if ($tst_settings['show_footer']): ?>
            <div class="signature-row">
                <div class="signature-line"><?php echo $g_lang === 'bn' ? 'শ্রেণি শিক্ষক' : 'Class Teacher'; ?></div>
                <div class="signature-line"><?php echo $g_lang === 'bn' ? 'অধ্যক্ষ' : 'Principal'; ?></div>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; endif; ?>

<?php else: ?>
    <?php
    $tst_err = $_SESSION['tst_err'] ?? '';
    $tst_msg = $_SESSION['tst_msg'] ?? '';
    unset($_SESSION['tst_err'], $_SESSION['tst_msg']);
    ?>
    <div class="card-custom">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-normal text-dark mb-0"><i class="fa-solid fa-file-signature text-primary me-1"></i> Print Student Testimonial</h5>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#tstSettingsModal">
                <i class="fa-solid fa-gear"></i> Testimonial Settings
            </button>
        </div>
        <?php if ($tst_msg): ?><div class="alert alert-success py-2"><?php echo tsv($tst_msg); ?></div><?php endif; ?>
        <?php if ($tst_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($tst_err); ?></div><?php endif; ?>

        <form method="POST" action="index.php?page=print_student_testimonial">
            <?php echo csrf_field(); ?>
            <div class="row">
                <div class="col-md-4">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Session <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="session_id" class="form-select" required>
                                <option value="">Select Session</option>
                                <?php foreach ($sessions as $s): ?><option value="<?php echo (int) $s['id']; ?>"><?php echo tsv($s['session_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Section</label>
                        <div class="col-sm-8">
                            <select name="section_id" id="tstSectionSelect" class="form-select" disabled>
                                <option value="">Select Class first</option>
                                <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" data-shift="<?php echo (int) $sec['shift_id']; ?>" hidden><?php echo tsv($sec['section_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Class <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="class_id" id="tstClassSelect" class="form-select" required onchange="filterTstSections(); filterTstShifts();">
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo tsv($c['class_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Student</label>
                        <div class="col-sm-8 position-relative">
                            <input type="text" id="tstStudentSearch" class="form-control" placeholder="সবাই (Bulk) — Student ID/Name/Roll দিয়ে খুঁজুন" autocomplete="off" value="সবাই (Bulk)">
                            <input type="hidden" name="student_row_id" id="tstStudentRowId" value="">
                            <div id="tstStudentDropdown" class="list-group position-absolute w-100" style="z-index:1000; max-height:240px; overflow-y:auto; display:none; top:100%;"></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Shift <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="shift_id" id="tstShiftSelect" class="form-select" required disabled onchange="filterTstSections();">
                                <option value="">Select Class first</option>
                                <?php foreach ($shifts as $sh): ?><option value="<?php echo (int) $sh['id']; ?>" data-classes="<?php echo tsv(implode(',', $tst_class_shifts[$sh['id']] ?? [])); ?>" hidden><?php echo tsv($sh['shift_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Format <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="language" class="form-select" required>
                                <option value="en">English</option>
                                <option value="bn">বাংলা (Bangla)</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Conduct <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="conduct" class="form-select" required>
                                <option value="Good">Good</option>
                                <option value="Very Good">Very Good</option>
                                <option value="Excellent">Excellent</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-end mt-3">
                <button type="submit" name="generate_testimonial" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;">Submit</button>
            </div>
        </form>
    </div>

    <!-- ========================== Testimonial Settings Modal ========================== -->
    <div class="modal fade" id="tstSettingsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-gear text-primary me-1"></i> Testimonial Settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="index.php?page=print_student_testimonial">
                        <?php echo csrf_field(); ?>

                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="tstShowHeader" name="show_header" <?php echo $tst_settings['show_header'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="tstShowHeader">Header (Logo / Institute Name / Address) দেখাও</label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="tstShowFooter" name="show_footer" <?php echo $tst_settings['show_footer'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="tstShowFooter">Footer (Signature Line) দেখাও</label>
                        </div>

                        <div class="mb-3">
                            <label class="fw-bold d-block mb-1">Page Orientation</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="orientation" id="tstOrientPortrait" value="portrait" <?php echo $tst_settings['orientation'] === 'portrait' ? 'checked' : ''; ?>>
                                <label class="btn btn-outline-primary" for="tstOrientPortrait">Portrait</label>

                                <input type="radio" class="btn-check" name="orientation" id="tstOrientLandscape" value="landscape" <?php echo $tst_settings['orientation'] === 'landscape' ? 'checked' : ''; ?>>
                                <label class="btn btn-outline-primary" for="tstOrientLandscape">Landscape</label>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="fw-bold d-block mb-2">Template</label>
                            <div class="row g-2">
                                <div class="col-6 col-md-3">
                                    <input type="radio" class="btn-check" name="template_style" id="tplClassic" value="classic" <?php echo $tst_settings['template_style'] === 'classic' ? 'checked' : ''; ?>>
                                    <label class="btn btn-outline-secondary w-100" for="tplClassic">Classic</label>
                                </div>
                                <div class="col-6 col-md-3">
                                    <input type="radio" class="btn-check" name="template_style" id="tplModern" value="modern" <?php echo $tst_settings['template_style'] === 'modern' ? 'checked' : ''; ?>>
                                    <label class="btn btn-outline-secondary w-100" for="tplModern">Modern</label>
                                </div>
                                <div class="col-6 col-md-3">
                                    <input type="radio" class="btn-check" name="template_style" id="tplElegant" value="elegant" <?php echo $tst_settings['template_style'] === 'elegant' ? 'checked' : ''; ?>>
                                    <label class="btn btn-outline-secondary w-100" for="tplElegant">Elegant</label>
                                </div>
                                <div class="col-6 col-md-3">
                                    <input type="radio" class="btn-check" name="template_style" id="tplCustom" value="custom" <?php echo empty($tst_settings['custom_template_path']) ? 'disabled' : ''; ?> <?php echo $tst_settings['template_style'] === 'custom' ? 'checked' : ''; ?>>
                                    <label class="btn btn-outline-secondary w-100" for="tplCustom">Custom<?php echo empty($tst_settings['custom_template_path']) ? ' (আপলোড করুন)' : ''; ?></label>
                                </div>
                            </div>
                        </div>

                        <div class="text-end">
                            <button type="submit" name="save_testimonial_settings" class="btn btn-primary fw-bold px-4" style="background-color:#1e3a8a;">Save Settings</button>
                        </div>
                    </form>

                    <hr>

                    <h6 class="fw-bold mb-2">কাস্টম টেমপ্লেট (Background/Letterhead ছবি)</h6>

                    <?php if (!empty($tst_settings['custom_template_path'])): ?>
                        <div class="d-flex align-items-center gap-3 mb-3 p-2 border rounded">
                            <img src="../<?php echo tsv($tst_settings['custom_template_path']); ?>" style="max-height:80px; border:1px solid #ddd;" alt="Custom Template">
                            <div class="flex-grow-1 small text-muted">বর্তমান কাস্টম টেমপ্লেট — এটাই টেস্টিমোনিয়ালের ব্যাকগ্রাউন্ড হিসেবে ব্যবহৃত হবে</div>
                            <form method="POST" action="index.php?page=print_student_testimonial" onsubmit="return confirm('কাস্টম টেমপ্লেট মুছে ফেলতে চান?');">
                                <?php echo csrf_field(); ?>
                                <button type="submit" name="remove_testimonial_template" class="btn btn-sm btn-outline-danger">Remove</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="index.php?page=print_student_testimonial" enctype="multipart/form-data" class="d-flex gap-2">
                        <?php echo csrf_field(); ?>
                        <input type="file" name="custom_template_image" accept=".jpg,.jpeg,.png" class="form-control" required>
                        <button type="submit" name="upload_testimonial_template" class="btn btn-success text-nowrap">Upload</button>
                    </form>
                    <p class="small text-muted mt-2 mb-0">A4 সাইজের একটা background/letterhead ছবি (jpg/png, সর্বোচ্চ 3MB) আপলোড করুন — টেস্টিমোনিয়ালের টেক্সট এই ছবির উপরে বসবে। আপলোড করলেই এটা স্বয়ংক্রিয়ভাবে Template হিসেবে সিলেক্ট হয়ে যাবে।</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        const tstAllStudents = <?php echo json_encode(array_map(function ($s) {
            return [
                'id' => (int) $s['id'], 'name' => $s['name_en'], 'sid' => $s['student_id'],
                'roll' => $s['roll_no'], 'class_id' => (int) $s['class_id'], 'section_id' => (int) $s['section_id'],
            ];
        }, $all_students), JSON_UNESCAPED_UNICODE); ?>;

        function filterTstSections() {
            const classId = document.getElementById('tstClassSelect').value;
            const shiftId = document.getElementById('tstShiftSelect').value;
            const select = document.getElementById('tstSectionSelect');
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
        function filterTstShifts() {
            const classId = document.getElementById('tstClassSelect').value;
            const select = document.getElementById('tstShiftSelect');
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
        filterTstSections();
        filterTstShifts();

        // ------------------------------------------------------------------
        // Student ড্রপডাউন — সিলেক্ট করা Class/Section অনুযায়ী ফিল্টার হয়,
        // এবং Student ID / Name / Roll দিয়ে টাইপ করে সার্চও করা যায়
        // ------------------------------------------------------------------
        const tstSearchInput = document.getElementById('tstStudentSearch');
        const tstHiddenId = document.getElementById('tstStudentRowId');
        const tstDropdown = document.getElementById('tstStudentDropdown');
        const tstClassSelect = document.getElementById('tstClassSelect');
        const tstSectionSelect = document.getElementById('tstSectionSelect');

        function tstMatchingStudents() {
            const classId = tstClassSelect.value;
            const sectionId = tstSectionSelect.value;
            const q = tstSearchInput.value.trim().toLowerCase();
            const isBulkText = tstSearchInput.value.trim() === 'সবাই (Bulk)';
            return tstAllStudents.filter(function (s) {
                if (classId && String(s.class_id) !== classId) return false;
                if (sectionId && String(s.section_id) !== sectionId) return false;
                if (q === '' || isBulkText) return true;
                return (s.name || '').toLowerCase().includes(q)
                    || (s.sid || '').toLowerCase().includes(q)
                    || (s.roll || '').toLowerCase().includes(q);
            });
        }

        function tstEsc(s) {
            const d = document.createElement('div');
            d.textContent = s || '';
            return d.innerHTML;
        }

        function tstRenderDropdown() {
            const matches = tstMatchingStudents().slice(0, 50);
            let html = '<button type="button" class="list-group-item list-group-item-action fw-bold" data-id="">সবাই (Bulk)</button>';
            matches.forEach(function (s) {
                const label = tstEsc(s.name) + ' (' + tstEsc(s.sid) + ')';
                html += '<button type="button" class="list-group-item list-group-item-action" data-id="' + s.id + '" data-label="' + label.replace(/"/g, '&quot;') + '">'
                    + tstEsc(s.name) + ' (' + tstEsc(s.sid) + (s.roll ? ', Roll: ' + tstEsc(s.roll) : '') + ')</button>';
            });
            if (matches.length === 0) {
                html += '<div class="list-group-item text-muted small">এই Class/Section-এ কোনো স্টুডেন্ট পাওয়া যায়নি।</div>';
            }
            tstDropdown.innerHTML = html;
            tstDropdown.style.display = 'block';
        }

        tstSearchInput.addEventListener('focus', function () {
            tstRenderDropdown();
            if (tstSearchInput.value.trim() === 'সবাই (Bulk)') tstSearchInput.select();
        });
        tstSearchInput.addEventListener('input', tstRenderDropdown);
        tstClassSelect.addEventListener('change', function () {
            // Class বদলালে আগের সিলেক্ট করা Student আর প্রযোজ্য নাও হতে পারে, তাই রিসেট
            tstHiddenId.value = '';
            tstSearchInput.value = 'সবাই (Bulk)';
        });
        tstSectionSelect.addEventListener('change', function () {
            tstHiddenId.value = '';
            tstSearchInput.value = 'সবাই (Bulk)';
        });

        tstDropdown.addEventListener('click', function (e) {
            const btn = e.target.closest('button[data-id]');
            if (!btn) return;
            tstHiddenId.value = btn.dataset.id;
            tstSearchInput.value = btn.dataset.id === '' ? 'সবাই (Bulk)' : btn.dataset.label;
            tstDropdown.style.display = 'none';
        });

        document.addEventListener('click', function (e) {
            if (!tstDropdown.contains(e.target) && e.target !== tstSearchInput) {
                tstDropdown.style.display = 'none';
            }
        });
    </script>
<?php endif; ?>
