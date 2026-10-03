<?php
// admin/institute.php - Institute Settings (এখন আসল DB backend সহ)
$institute_msg = $_SESSION['institute_msg'] ?? '';
$institute_err = $_SESSION['institute_err'] ?? '';
unset($_SESSION['institute_msg'], $_SESSION['institute_err']);

// -----------------------------------------------------------
// ফাইল আপলোড হেল্পার (favicon/banner/logo) — extension + real
// MIME টাইপ দুটোই যাচাই হয়, random filename দেওয়া হয়
// -----------------------------------------------------------
function institute_handle_upload(string $field): array
{
    // ['ok' => bool, 'path' => ?string, 'error' => ?string]
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'error' => null]; // ফাইল না দিলে সমস্যা নাই (optional)
    }

    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => "$field আপলোডে সমস্যা হয়েছে।"];
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return ['ok' => false, 'path' => null, 'error' => "$field এর সাইজ 2MB এর বেশি হওয়া যাবে না।"];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'ico' => 'image/x-icon'];
    if (!isset($allowed_mimes[$ext])) {
        return ['ok' => false, 'path' => null, 'error' => "$field এর ফাইল টাইপ সমর্থিত না (শুধু jpg/png/webp)।"];
    }

    if ($ext !== 'ico') { // finfo ico ফাইলে অনির্ভরযোগ্য, তাই ico বাদে বাকিগুলোর real mime চেক করা হচ্ছে
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if ($mime !== $allowed_mimes[$ext]) {
            return ['ok' => false, 'path' => null, 'error' => "$field এর প্রকৃত কনটেন্ট এক্সটেনশনের সাথে মিলছে না।"];
        }
    }

    $upload_dir = tenant_upload_abs(__DIR__ . '/..', 'institute');
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $random_name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $random_name)) {
        return ['ok' => false, 'path' => null, 'error' => "$field সেভ করা যায়নি।"];
    }

    return ['ok' => true, 'path' => tenant_upload_rel('institute') . '/' . $random_name, 'error' => null];
}

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_institute']) || isset($_POST['update_general_info']) || isset($_POST['update_admission_type']))) {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['institute_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (isset($_POST['update_institute'])) {
        $facebook_page = trim($_POST['facebook_page'] ?? '');
        $youtube_video = trim($_POST['youtube_video'] ?? '');
        $admin_theme   = trim($_POST['admin_theme'] ?? 'Deep Green');
        $website_theme = trim($_POST['website_theme'] ?? 'E-Learning');

        if (!in_array($admin_theme, ['Deep Green', 'Dark'], true)) $admin_theme = 'Deep Green';
        if (!in_array($website_theme, ['E-Learning', 'Standard', 'Govt Theme-1'], true)) $website_theme = 'E-Learning';

        $stmt = $pdo->prepare(
            "UPDATE institute_settings SET facebook_page = ?, youtube_video = ?, admin_theme = ?, website_theme = ? WHERE id = 1"
        );
        $stmt->execute([$facebook_page ?: null, $youtube_video ?: null, $admin_theme, $website_theme]);
        sec_log_activity($pdo, 'Institute Settings', 'Update', "Institute Settings আপডেট করা হয়েছে (Admin Theme: {$admin_theme}, Website Theme: {$website_theme})");
        $_SESSION['institute_msg'] = 'Institute Settings সফলভাবে সেভ হয়েছে!';

    } elseif (isset($_POST['update_general_info'])) {
        $eiin_no        = trim($_POST['eiin_no'] ?? '');
        $institute_name = trim($_POST['institute_name'] ?? '');
        $phone_number   = trim($_POST['phone_number'] ?? '');
        $slogan         = trim($_POST['slogan'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $short_desc     = institute_sanitize_richtext(trim($_POST['short_description'] ?? ''));
        $why_choose     = institute_sanitize_richtext(trim($_POST['why_choose_institute'] ?? ''));

        if ($institute_name === '' || $email === '' || $short_desc === '') {
            $_SESSION['institute_err'] = 'Institute Name, Email ও Short Description আবশ্যক।';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['institute_err'] = 'সঠিক ইমেইল দিন।';
        } else {
            $favicon = institute_handle_upload('favicon');
            $banner  = institute_handle_upload('banner');
            $logo    = institute_handle_upload('logo');

            $upload_error = $favicon['error'] ?? $banner['error'] ?? $logo['error'] ?? null;

            if ($upload_error) {
                $_SESSION['institute_err'] = $upload_error;
            } else {
                // "মুছে ফেলুন" চেকবক্স চেক করা থাকলে এবং নতুন ফাইল আপলোড না হলে, পুরনো ফাইলটা ডিলিট করে path NULL করা হয়
                $remove_favicon = isset($_POST['remove_favicon']) && $favicon['path'] === null;
                $remove_banner  = isset($_POST['remove_banner']) && $banner['path'] === null;
                $remove_logo    = isset($_POST['remove_logo']) && $logo['path'] === null;

                $final_favicon_path = $favicon['path']; // নতুন আপলোড হলে সেটাই, নাহলে null (মানে কলাম অপরিবর্তিত থাকবে)
                $final_banner_path  = $banner['path'];
                $final_logo_path    = $logo['path'];

                // পুরনো ফাইল যেগুলো রিপ্লেস/রিমুভ হচ্ছে, সার্ভার থেকে ডিলিট করে দেওয়া (orphan ফাইল এড়াতে)
                $old_paths = $pdo->query("SELECT favicon_path, banner_path, logo_path FROM institute_settings WHERE id = 1")->fetch();
                foreach ([
                    ['old' => $old_paths['favicon_path'] ?? null, 'new' => $favicon['path'], 'remove' => $remove_favicon],
                    ['old' => $old_paths['banner_path'] ?? null, 'new' => $banner['path'], 'remove' => $remove_banner],
                    ['old' => $old_paths['logo_path'] ?? null, 'new' => $logo['path'], 'remove' => $remove_logo],
                ] as $f) {
                    if (!empty($f['old']) && ($f['new'] !== null || $f['remove'])) {
                        $old_full_path = __DIR__ . '/../' . $f['old'];
                        if (is_file($old_full_path)) { @unlink($old_full_path); }
                    }
                }

                // যেসব ফাইল নতুন আপলোড হয়নি এবং remove করাও হয়নি, সেগুলোর কলাম আগের ভ্যালুতেই থাকবে (COALESCE)
                // remove করা হলে explicit NULL সেট করা হবে
                $stmt = $pdo->prepare(
                    "UPDATE institute_settings SET
                        eiin_no = ?, institute_name = ?, phone_number = ?, slogan = ?, email = ?,
                        short_description = ?, why_choose_institute = ?,
                        favicon_path = " . ($remove_favicon ? "NULL" : "COALESCE(?, favicon_path)") . ",
                        banner_path  = " . ($remove_banner ? "NULL" : "COALESCE(?, banner_path)") . ",
                        logo_path    = " . ($remove_logo ? "NULL" : "COALESCE(?, logo_path)") . "
                     WHERE id = 1"
                );
                $bind = [
                    $eiin_no ?: null, $institute_name, $phone_number ?: null, $slogan ?: null, $email,
                    $short_desc, $why_choose ?: null,
                ];
                if (!$remove_favicon) { $bind[] = $final_favicon_path; }
                if (!$remove_banner)  { $bind[] = $final_banner_path; }
                if (!$remove_logo)    { $bind[] = $final_logo_path; }
                $stmt->execute($bind);
                sec_log_activity($pdo, 'Institute Settings', 'Update', "General Information আপডেট করা হয়েছে: {$institute_name}");
                $_SESSION['institute_msg'] = 'General Information সফলভাবে সেভ হয়েছে!';
            }
        }
    } elseif (isset($_POST['update_admission_type'])) {
        $admission_type = trim($_POST['admission_type'] ?? 'General');
        if (!in_array($admission_type, ['General', 'Admission Test', 'Lottery'], true)) { $admission_type = 'General'; }

        $col_chk = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'institute_settings' AND COLUMN_NAME = 'admission_type'"
        )->fetchColumn();
        if (!$col_chk) {
            $pdo->exec("ALTER TABLE institute_settings ADD COLUMN admission_type VARCHAR(20) NOT NULL DEFAULT 'General'");
        }

        $pdo->prepare("UPDATE institute_settings SET admission_type = ? WHERE id = 1")->execute([$admission_type]);
        sec_log_activity($pdo, 'Institute Settings', 'Update', "Admission Settings আপডেট করা হয়েছে: {$admission_type}");
        $_SESSION['institute_msg'] = 'Admission Settings সফলভাবে সেভ হয়েছে!';
    }

    header('Location: index.php?page=institute');
    exit();
}

// -----------------------------------------------------------
// বর্তমান ভ্যালু লোড করা (ফর্ম prefill করার জন্য)
// -----------------------------------------------------------
$col_chk = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'institute_settings' AND COLUMN_NAME = 'admission_type'"
)->fetchColumn();
if (!$col_chk) {
    $pdo->exec("ALTER TABLE institute_settings ADD COLUMN admission_type VARCHAR(20) NOT NULL DEFAULT 'General'");
}

$settings = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
if (!$settings) {
    // টেবিলে রো না থাকলে (sql/schema.sql ইমপোর্ট না করা থাকলে) খালি ডিফল্ট দেখানো হবে
    $settings = [
        'facebook_page' => '', 'youtube_video' => '', 'admin_theme' => 'Deep Green', 'website_theme' => 'E-Learning',
        'eiin_no' => '', 'institute_name' => '', 'phone_number' => '', 'slogan' => '', 'email' => '',
        'favicon_path' => null, 'banner_path' => null, 'logo_path' => null,
        'short_description' => '', 'why_choose_institute' => '', 'admission_type' => 'General',
    ];
}

function iv($val): string { return htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// rich-text ফিল্ড (short_description/why_choose_institute) সেভ করার আগেই
// institute_sanitize_richtext() দিয়ে পরিষ্কার করা হয়, তাই এখানে raw বসানো
// নিরাপদ — htmlspecialchars করলে CKEditor ট্যাগগুলো literal টেক্সট হিসেবে দেখাবে
function iv_html($val): string { return (string)($val ?? ''); }

// Short Description / Why Choose Institute — এখন rich text editor (CKEditor)
// দিয়ে লেখা হয়, তাই HTML ট্যাগ আসবে। সেভ করার আগে শুধু নিরাপদ ফরম্যাটিং ট্যাগ
// রাখা হয় (bold/italic/list/link ইত্যাদি), script/iframe/on* attribute বাদ যায়।
function institute_sanitize_richtext(string $html): string
{
    $allowed = '<p><br><b><strong><i><em><u><ul><ol><li><a><span><h1><h2><h3><h4><blockquote>';
    $html = strip_tags($html, $allowed);
    // on* ইভেন্ট অ্যাট্রিবিউট (onclick, onerror ইত্যাদি) সরিয়ে দেওয়া হচ্ছে
    $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    // javascript: লিংক সরিয়ে দেওয়া হচ্ছে
    $html = preg_replace('/href\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i', 'href="#"', $html);
    return $html;
}
?>

<div class="card-custom">
    <?php if ($institute_msg): ?>
        <div class="alert alert-success py-2"><?php echo htmlspecialchars($institute_msg); ?></div>
    <?php endif; ?>
    <?php if ($institute_err): ?>
        <div class="alert alert-danger py-2"><?php echo htmlspecialchars($institute_err); ?></div>
    <?php endif; ?>

    <div class="d-flex border-bottom pb-3 mb-4 gap-3 align-items-center" style="font-size: 15px;">
        <span id="tabInstBtn" onclick="switchTab('institute')" class="tab-btn d-flex align-items-center gap-2 text-primary fw-bold">
            <i class="fa-solid fa-wrench"></i> Institutes Settings
        </span>
        <div class="text-muted">|</div>
        <span id="tabGenBtn" onclick="switchTab('general')" class="tab-btn d-flex align-items-center gap-2 text-secondary">
            <i class="fa-regular fa-square"></i> General Information
        </span>
        <div class="text-muted">|</div>
        <span id="tabAdmBtn" onclick="switchTab('admission')" class="tab-btn d-flex align-items-center gap-2 text-secondary">
            <i class="fa-solid fa-user-graduate"></i> Admission Settings
        </span>
    </div>

    <div id="instituteSection">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end" style="font-size: 13.5px;">Facebook Page</label>
                <div class="col-sm-6"><input type="text" name="facebook_page" class="form-control" value="<?php echo iv($settings['facebook_page']); ?>"></div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end" style="font-size: 13.5px;">Youtube Video</label>
                <div class="col-sm-6"><input type="text" name="youtube_video" class="form-control" value="<?php echo iv($settings['youtube_video']); ?>"></div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end" style="font-size: 13.5px;">Admin Theme <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="admin_theme" class="form-select">
                        <option value="Deep Green" <?php echo $settings['admin_theme'] === 'Deep Green' ? 'selected' : ''; ?>>Deep Green</option>
                        <option value="Dark" <?php echo $settings['admin_theme'] === 'Dark' ? 'selected' : ''; ?>>Dark</option>
                    </select>
                </div>
            </div>
            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end" style="font-size: 13.5px;">Website Theme <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="website_theme" class="form-select">
                        <option value="E-Learning" <?php echo $settings['website_theme'] === 'E-Learning' ? 'selected' : ''; ?>>E-Learning</option>
                        <option value="Standard" <?php echo $settings['website_theme'] === 'Standard' ? 'selected' : ''; ?>>Standard</option>
                        <option value="Govt Theme-1" <?php echo $settings['website_theme'] === 'Govt Theme-1' ? 'selected' : ''; ?>>Govt Theme-1</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="update_institute" class="btn btn-primary px-4 fw-bold" style="background-color: #1e3a8a;">Submit</button>
                    <a href="index.php" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

    <div id="generalSection" style="display: none;">
        <form method="POST" enctype="multipart/form-data" id="generalInfoForm" onsubmit="return beforeGeneralInfoSubmit()">
            <?php echo csrf_field(); ?>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end" style="font-size: 13.5px;">EIIN No.</label>
                <div class="col-sm-6"><input type="text" name="eiin_no" class="form-control" value="<?php echo iv($settings['eiin_no']); ?>"></div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end" style="font-size: 13.5px;">Institute Name <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="institute_name" class="form-control" value="<?php echo iv($settings['institute_name']); ?>" required></div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end" style="font-size: 13.5px;">Phone Number</label>
                <div class="col-sm-6"><input type="tel" name="phone_number" class="form-control phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="১১ ডিজিটের মোবাইল নম্বর দিন" value="<?php echo iv($settings['phone_number']); ?>"></div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end" style="font-size: 13.5px;">Slogan</label>
                <div class="col-sm-6"><input type="text" name="slogan" class="form-control" value="<?php echo iv($settings['slogan']); ?>"></div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end" style="font-size: 13.5px;">Email <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="email" name="email" class="form-control" value="<?php echo iv($settings['email']); ?>" required></div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end" style="font-size: 13.5px;">Favicon</label>
                <div class="col-sm-6">
                    <input type="file" name="favicon" class="form-control" accept=".jpg,.jpeg,.png,.webp,.ico">
                    <?php if (!empty($settings['favicon_path'])): ?>
                        <div class="mt-2 d-flex align-items-center gap-2">
                            <a href="../<?php echo iv($settings['favicon_path']); ?>" target="_blank">
                                <img src="../<?php echo iv($settings['favicon_path']); ?>" alt="Favicon" style="width:36px;height:36px;object-fit:contain;border:1px solid #dee2e6;border-radius:4px;padding:2px;background:#fff;">
                            </a>
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="remove_favicon" value="1" id="removeFavicon">
                                <label class="form-check-label text-danger small" for="removeFavicon">বর্তমান Favicon মুছে ফেলুন</label>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end" style="font-size: 13.5px;">Banner</label>
                <div class="col-sm-6">
                    <input type="file" name="banner" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                    <?php if (!empty($settings['banner_path'])): ?>
                        <div class="mt-2 d-flex align-items-center gap-2">
                            <a href="../<?php echo iv($settings['banner_path']); ?>" target="_blank">
                                <img src="../<?php echo iv($settings['banner_path']); ?>" alt="Banner" style="width:70px;height:36px;object-fit:cover;border:1px solid #dee2e6;border-radius:4px;">
                            </a>
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="remove_banner" value="1" id="removeBanner">
                                <label class="form-check-label text-danger small" for="removeBanner">বর্তমান Banner মুছে ফেলুন</label>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end" style="font-size: 13.5px;">Logo</label>
                <div class="col-sm-6">
                    <input type="file" name="logo" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                    <?php if (!empty($settings['logo_path'])): ?>
                        <div class="mt-2 d-flex align-items-center gap-2">
                            <a href="../<?php echo iv($settings['logo_path']); ?>" target="_blank">
                                <img src="../<?php echo iv($settings['logo_path']); ?>" alt="Logo" style="width:36px;height:36px;object-fit:contain;border:1px solid #dee2e6;border-radius:4px;padding:2px;background:#fff;">
                            </a>
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="removeLogo">
                                <label class="form-check-label text-danger small" for="removeLogo">বর্তমান Logo মুছে ফেলুন</label>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row mb-3 align-items-start">
                <label class="col-sm-3 col-form-label fw-bold text-end pt-2" style="font-size: 13.5px;">Short Description <span class="text-danger">*</span></label>
                <div class="col-sm-8">
                    <div id="shortDescQuill" style="background:#fff;"><?php echo iv_html($settings['short_description']); ?></div>
                    <input type="hidden" id="shortDescriptionEditor" name="short_description">
                </div>
            </div>
            <div class="row mb-4 align-items-start">
                <label class="col-sm-3 col-form-label fw-bold text-end pt-2" style="font-size: 13.5px;">Why Choose Institute ?</label>
                <div class="col-sm-8">
                    <div id="whyChooseQuill" style="background:#fff;"><?php echo iv_html($settings['why_choose_institute']); ?></div>
                    <input type="hidden" id="whyChooseEditor" name="why_choose_institute">
                </div>
            </div>
            <div class="row">
                <div class="col-sm-8 offset-sm-3">
                    <button type="submit" name="update_general_info" class="btn btn-primary px-4 fw-bold" style="background-color: #1e3a8a;">Submit</button>
                    <a href="index.php" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

    <div id="admissionSection" style="display: none;">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="alert alert-info py-2 small">
                এই সেটিং পুরো স্কুলের জন্য একবারে প্রযোজ্য — যেভাবে সিলেক্ট করবেন, অনলাইন ভর্তি আবেদন সেভাবেই প্রসেস হবে।
            </div>
            <div class="row mb-3">
                <label class="col-sm-3 col-form-label fw-bold text-end" style="font-size: 13.5px;">Admission Type <span class="text-danger">*</span></label>
                <div class="col-sm-8">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="admission_type" id="admTypeGeneral" value="General" <?php echo ($settings['admission_type'] ?? 'General') === 'General' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="admTypeGeneral">
                            <strong>General</strong> — অনলাইনে Apply করলে সরাসরি Pending হয়ে যাবে, অ্যাডমিন Dashboard/Student List থেকে একজন একজন করে Process করে সরাসরি Active (Current Student) করবে।
                        </label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="admission_type" id="admTypeTest" value="Admission Test" <?php echo ($settings['admission_type'] ?? '') === 'Admission Test' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="admTypeTest">
                            <strong>Admission Test</strong> — Apply করলে ক্লাস অনুযায়ী Admission Module-এ পরীক্ষার জন্য জমা হবে; মার্ক দিয়ে মেধাক্রম প্রকাশ করলে পাস করা শিক্ষার্থীরা ক্লাস অনুযায়ী রোল পেয়ে Current Student-এ চলে যাবে।
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="admission_type" id="admTypeLottery" value="Lottery" <?php echo ($settings['admission_type'] ?? '') === 'Lottery' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="admTypeLottery">
                            <strong>Lottery</strong> — Apply করলে ক্লাস অনুযায়ী Admission Module-এ জমা হবে; সেখান থেকে ক্লাস-ভিত্তিক ডিজিটাল লটারি চালিয়ে বিজয়ীদের বাছাই করা যাবে।
                        </label>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-8 offset-sm-3">
                    <button type="submit" name="update_admission_type" class="btn btn-primary px-4 fw-bold" style="background-color: #1e3a8a;">Submit</button>
                    <a href="index.php?page=admission_selection" class="btn btn-outline-primary px-4 fw-bold ms-2">Go to Admission Module</a>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.js"></script>
<link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">

<script>
var quillReady = false;
var shortDescQuillInstance = null;
var whyChooseQuillInstance = null;

function initQuillEditors() {
    if (quillReady) return;
    quillReady = true;

    var toolbarOptions = [
        ['bold', 'italic', 'underline'],
        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
        [{ 'header': [1, 2, 3, false] }],
        ['link'],
        ['clean']
    ];

    shortDescQuillInstance = new Quill('#shortDescQuill', { theme: 'snow', modules: { toolbar: toolbarOptions } });
    whyChooseQuillInstance = new Quill('#whyChooseQuill', { theme: 'snow', modules: { toolbar: toolbarOptions } });
}

function beforeGeneralInfoSubmit() {
    // Quill-এর বর্তমান কনটেন্ট hidden input-এ বসানো হচ্ছে যাতে ফর্ম POST-এ যায়
    if (shortDescQuillInstance) {
        document.getElementById('shortDescriptionEditor').value = shortDescQuillInstance.root.innerHTML;
    }
    if (whyChooseQuillInstance) {
        document.getElementById('whyChooseEditor').value = whyChooseQuillInstance.root.innerHTML;
    }

    const shortDescVal = document.getElementById('shortDescriptionEditor').value.trim();
    if (shortDescVal === '' || shortDescVal === '<p><br></p>') {
        alert('Short Description আবশ্যক, খালি রাখা যাবে না।');
        return false;
    }
    return true;
}

function switchTab(tab) {
    const instSec = document.getElementById('instituteSection');
    const genSec = document.getElementById('generalSection');
    const admSec = document.getElementById('admissionSection');
    const tabInstBtn = document.getElementById('tabInstBtn');
    const tabGenBtn = document.getElementById('tabGenBtn');
    const tabAdmBtn = document.getElementById('tabAdmBtn');

    instSec.style.display = 'none';
    genSec.style.display = 'none';
    admSec.style.display = 'none';
    tabInstBtn.className = 'tab-btn d-flex align-items-center gap-2 text-secondary';
    tabGenBtn.className = 'tab-btn d-flex align-items-center gap-2 text-secondary';
    tabAdmBtn.className = 'tab-btn d-flex align-items-center gap-2 text-secondary';
    tabInstBtn.querySelector('i').className = 'fa-solid fa-wrench';
    tabGenBtn.querySelector('i').className = 'fa-regular fa-square';
    tabAdmBtn.querySelector('i').className = 'fa-solid fa-user-graduate';

    if (tab === 'institute') {
        instSec.style.display = 'block';
        tabInstBtn.className = 'tab-btn d-flex align-items-center gap-2 text-primary fw-bold';
    } else if (tab === 'admission') {
        admSec.style.display = 'block';
        tabAdmBtn.className = 'tab-btn d-flex align-items-center gap-2 text-primary fw-bold';
    } else {
        genSec.style.display = 'block';
        tabGenBtn.className = 'tab-btn d-flex align-items-center gap-2 text-primary fw-bold';
        tabGenBtn.querySelector('i').className = 'fa-solid fa-square-check';
        // General Information ট্যাব প্রথমবার দেখানোর সময়ই Quill init হয় —
        // hidden (display:none) অবস্থায় init করলে editor উচ্চতা ভুল হিসেব করে, তাই lazy-load
        initQuillEditors();
    }
}

// sidebar থেকে "Admission Settings" লিংকে ক্লিক করলে সরাসরি Admission ট্যাব খুলে যাবে
document.addEventListener('DOMContentLoaded', function () {
    if (localStorage.getItem('institute_open_tab') === 'admission') {
        localStorage.removeItem('institute_open_tab');
        switchTab('admission');
    }
});
</script>
<script>
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
