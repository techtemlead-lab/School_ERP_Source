<?php
// admission_form.php - পাবলিক ওয়েবসাইটের অনলাইন ভর্তি ফরম (স্ক্রিনশট অনুযায়ী)
// submit_admission_form.php তে POST হয়ে students টেবিলে status='Pending' নিয়ে সেভ হয়,
// তারপর admission_slip.php তে রিডাইরেক্ট হয়ে Admission Slip প্রিন্ট করা যায়।
require_once __DIR__ . '/config/runtime.php';
erp_session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/admission_fee_helpers.php';
require_once __DIR__ . '/config/lang.php';
af_ensure_tables($pdo);

function afv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$af_err = $_SESSION['af_err'] ?? '';
$old = $_SESSION['af_old'] ?? [];
unset($_SESSION['af_err'], $_SESSION['af_old']);

$branches = $pdo->query("SELECT id, branch_name FROM branches WHERE status = 'Active' ORDER BY id ASC")->fetchAll();
$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions WHERE status = 'Active' ORDER BY id DESC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();

// Session+Class ভিত্তিক Admission Fee ম্যাপ (0/খালি হলে সেই ক্লাসে কোনো Payment ধাপ দেখানো হবে না)
$af_fee_map = af_all_class_fees($pdo);
$af_gateway = af_gateway_settings($pdo);
$af_online_on = !empty($af_gateway['online_payment_enabled']);
$af_manual_on = $af_online_on && !empty($af_gateway['manual_enabled']) && (!empty($af_gateway['bkash_number']) || !empty($af_gateway['nagad_number']));
$af_auto_on = $af_online_on && af_auto_gateway_ready($af_gateway);
$af_auto_provider_label = af_provider_label($af_gateway['auto_provider'] ?? 'sslcommerz');
$af_payment_active = ($af_manual_on || $af_auto_on) && !empty($af_fee_map);

$ov = fn($key, $default = '') => afv($old[$key] ?? $default);
$osel = fn($key, $value) => (($old[$key] ?? '') == $value) ? 'selected' : '';
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo t('admission_form_title'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --ink: #17263b;        /* গাঢ় নেভি — হেডিং/টেক্সট */
        --paper: #faf7f0;      /* ledger paper */
        --card: #fffdf9;
        --rule: #ddd4bf;       /* হালকা ব্রাস-টিন্টেড রুল লাইন */
        --seal: #7a2331;       /* অক্সব্লাড রেড — সিল/অ্যাকসেন্ট */
        --seal-dark: #5e1a25;
        --brass: #a9772e;      /* ব্রাস গোল্ড — বর্ডার/ফ্লারিশ */
        --brass-soft: #d7bf8e;
        --err: #b3261e;
        --err-bg: #fdf0ee;
    }
    * { box-sizing: border-box; }
    body {
        font-family: 'IBM Plex Sans', 'Segoe UI', sans-serif;
        color: var(--ink);
        margin: 0;
        background:
            repeating-linear-gradient(180deg, rgba(169,119,46,0.05) 0px, rgba(169,119,46,0.05) 1px, transparent 1px, transparent 34px),
            var(--paper);
    }

    /* ---------- Hero / Cover Band ---------- */
    .af-hero {
        position: relative;
        background: linear-gradient(180deg, var(--ink) 0%, #223a56 100%);
        padding: 44px 20px 34px;
        text-align: center;
        overflow: hidden;
    }
    .af-hero::before {
        content: "";
        position: absolute; inset: 0;
        background-image: radial-gradient(circle at 18% 30%, rgba(215,191,142,0.08), transparent 40%),
                           radial-gradient(circle at 85% 70%, rgba(215,191,142,0.07), transparent 45%);
        pointer-events: none;
    }
    .af-eyebrow {
        font-family: 'IBM Plex Sans', sans-serif;
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 3px;
        text-transform: uppercase;
        color: var(--brass-soft);
        margin: 0 0 10px;
    }
    h1.af-title {
        font-family: 'Fraunces', serif;
        font-optical-sizing: auto;
        text-align: center;
        color: #fff;
        font-weight: 700;
        letter-spacing: 0.5px;
        margin: 0;
        font-size: 34px;
        position: relative;
        z-index: 1;
    }
    .af-hero-sub {
        font-size: 13.5px;
        color: #c7d2e0;
        margin: 10px 0 0;
        position: relative; z-index: 1;
    }
    .af-hero-rule {
        width: 120px;
        height: 3px;
        margin: 18px auto 0;
        background: linear-gradient(90deg, transparent, var(--brass), transparent);
        position: relative; z-index: 1;
    }
    /* সিল/স্ট্যাম্প মোটিফ — বিশুদ্ধ ডেকোরেশন, কোনো ফাংশনালিটি নেই */
    .af-seal {
        position: absolute;
        top: 26px; right: 5%;
        width: 78px; height: 78px;
        border-radius: 50%;
        border: 2px solid var(--brass-soft);
        display: none;
        align-items: center; justify-content: center;
        text-align: center;
        color: var(--brass-soft);
        font-family: 'IBM Plex Sans', sans-serif;
        font-size: 8.5px;
        font-weight: 700;
        letter-spacing: 1.5px;
        transform: rotate(-8deg);
        opacity: 0.85;
        z-index: 1;
    }
    .af-seal span { display: block; }
    .af-seal .af-seal-star { font-size: 18px; margin: 3px 0; color: var(--brass-soft); }
    @media (min-width: 620px) { .af-seal { display: flex; } }

    /* ---------- Alert ---------- */
    .af-alert {
        max-width: 1000px; margin: 22px auto 0; padding: 13px 18px; border-radius: 6px;
        background: var(--err-bg); color: var(--err); border: 1px solid #f3c8c4; font-size: 14px; font-weight: 500;
    }

    /* ---------- Form Card (ledger sheet) ---------- */
    .af-wrap { max-width: 1000px; margin: -20px auto 60px; padding: 0 20px; position: relative; z-index: 2; }
    .af-wrap form {
        display: block;
        background: var(--card);
        border: 1px solid var(--rule);
        border-radius: 8px;
        box-shadow: 0 18px 40px rgba(23,38,59,0.12), 0 2px 8px rgba(23,38,59,0.06);
        padding: 8px 34px 34px;
        position: relative;
        animation: afRise 0.5s ease both;
    }
    @media (prefers-reduced-motion: reduce) { .af-wrap form { animation: none; } }
    @keyframes afRise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    /* কোণে ভাঁজ করা কাগজের ছোট্ট ছাপ — বিশুদ্ধ ডেকোরেশন */
    .af-wrap form::after {
        content: "";
        position: absolute; top: 0; right: 0;
        width: 0; height: 0;
        border-style: solid;
        border-width: 0 26px 26px 0;
        border-color: transparent var(--paper) transparent transparent;
        filter: drop-shadow(-1px 1px 1px rgba(23,38,59,0.08));
    }
    @media (max-width: 700px) { .af-wrap { padding: 0 12px; } .af-wrap form { padding: 6px 18px 26px; } }

    /* ---------- Section markers — CSS counter দিয়ে অটো-নাম্বার (HTML অপরিবর্তিত) ---------- */
    .af-wrap form { counter-reset: af-section; }
    .af-section-bar {
        counter-increment: af-section;
        display: flex;
        align-items: center;
        gap: 14px;
        margin: 30px 0 22px;
        font-family: 'Fraunces', serif;
        font-weight: 600;
        font-size: 17px;
        color: var(--ink);
        background: none;
        padding: 0;
        border-radius: 0;
    }
    .af-section-bar::before {
        content: counter(af-section, decimal-leading-zero);
        flex-shrink: 0;
        width: 34px; height: 34px;
        border-radius: 50%;
        background: var(--seal);
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-family: 'IBM Plex Sans', sans-serif;
        font-weight: 700;
        font-size: 13px;
        box-shadow: 0 3px 8px rgba(122,35,49,0.35);
    }
    .af-section-bar::after {
        content: "";
        flex: 1;
        height: 1px;
        background: linear-gradient(90deg, var(--rule), transparent);
    }

    .af-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px 40px; }
    @media (max-width: 700px) { .af-grid { grid-template-columns: 1fr; } }
    .af-field { display: grid; grid-template-columns: 150px 1fr; align-items: center; gap: 10px; }
    @media (max-width: 700px) { .af-field { grid-template-columns: 1fr; } }
    .af-field.full { grid-column: 1 / -1; }
    .af-field label { font-weight: 600; font-size: 13.5px; color: #3a4c63; }
    .af-field .req { color: var(--seal); }

    .af-field input[type=text], .af-field input[type=date], .af-field input[type=email], .af-field input[type=tel],
    .af-field select, .af-field textarea {
        width: 100%;
        border: 1px solid var(--rule);
        border-radius: 5px;
        padding: 9px 12px;
        font-size: 14px;
        font-family: inherit;
        background: #fff;
        color: var(--ink);
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .af-field input:hover, .af-field select:hover, .af-field textarea:hover { border-color: var(--brass-soft); }
    .af-field input:focus, .af-field select:focus, .af-field textarea:focus {
        outline: none;
        border-color: var(--brass);
        box-shadow: 0 0 0 3px rgba(169,119,46,0.16);
    }
    .af-field textarea { resize: vertical; }
    .af-field input[type=file] { font-size: 13px; }

    /* ফাইল-আপলোড বক্স — "attach" স্টাইল (শুধু র‍্যাপার ডিভে ক্লাস যোগ করা, ইনপুট অপরিবর্তিত) */
    .af-file-box {
        border: 1.5px dashed var(--brass-soft);
        border-radius: 6px;
        padding: 10px 12px;
        background: rgba(169,119,46,0.04);
    }
    .af-file-note { color: var(--seal); font-size: 11.5px; display: block; margin-top: 4px; }

    .af-check-row { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
    .af-check-row input[type=checkbox] { accent-color: var(--seal); width: 16px; height: 16px; }

    hr.af-sep { border: none; border-top: 1px dashed var(--rule); margin: 8px 0 0; }

    .af-submit-wrap { text-align: center; margin-top: 40px; }
    .af-btn {
        background: var(--seal);
        color: #fff;
        border: 2px solid var(--seal-dark);
        padding: 13px 52px;
        font-size: 14px;
        font-weight: 700;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        border-radius: 4px;
        cursor: pointer;
        font-family: 'IBM Plex Sans', sans-serif;
        box-shadow: 0 6px 16px rgba(122,35,49,0.3);
        transition: transform 0.12s ease, box-shadow 0.12s ease, background 0.12s ease;
    }
    .af-btn:hover { background: var(--seal-dark); box-shadow: 0 4px 10px rgba(122,35,49,0.32); }
    .af-btn:active { transform: translateY(2px); box-shadow: 0 2px 6px rgba(122,35,49,0.3); }

    @media print { .top-bar, .header-brand, .navbar, .af-hero { display: none !important; } }
</style>
</head>
<body>

<?php $current_page = 'admission'; require __DIR__ . '/site_header.php'; ?>

<div class="af-hero">
    <div class="af-seal"><span class="af-seal-star">&#9733;</span><span>SCHOOL<br>SEAL</span></div>
    <p class="af-eyebrow"><?php echo t('af_eyebrow'); ?></p>
    <h1 class="af-title"><?php echo t('af_hero_title'); ?></h1>
    <p class="af-hero-sub"><?php echo t('af_hero_sub'); ?></p>
    <div class="af-hero-rule"></div>
</div>

<?php if ($af_err): ?><div class="af-alert"><?php echo afv($af_err); ?></div><?php endif; ?>

<div class="af-wrap">
    <form method="POST" action="submit_admission_form.php" enctype="multipart/form-data" id="admissionForm">
        <?php echo csrf_field(); ?>

        <div class="af-section-bar"><?php echo t('af_sec_academic'); ?></div>
        <div class="af-grid">
            <div class="af-field">
                <label><?php echo t('af_branch'); ?> <span class="req">*</span></label>
                <select name="branch_id" required>
                    <option value=""><?php echo t('af_select_branch'); ?></option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?php echo (int) $b['id']; ?>" <?php echo $osel('branch_id', $b['id']); ?>><?php echo afv($b['branch_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="af-field">
                <label><?php echo t('af_shift'); ?> <span class="req">*</span></label>
                <select name="shift_id" required>
                    <option value=""><?php echo t('af_select_shift'); ?></option>
                    <?php foreach ($shifts as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $osel('shift_id', $s['id']); ?>><?php echo afv($s['shift_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="af-field">
                <label><?php echo t('af_session'); ?> <span class="req">*</span></label>
                <select name="session_id" required>
                    <option value=""><?php echo t('af_select_session'); ?></option>
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $osel('session_id', $s['id']); ?>><?php echo afv($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="af-field">
                <label><?php echo t('af_section'); ?> <span class="req">*</span></label>
                <select name="section_id" id="sectionSelect" required>
                    <option value=""><?php echo t('af_select_section'); ?></option>
                    <?php foreach ($sections as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" data-class="<?php echo (int) $s['class_id']; ?>" <?php echo $osel('section_id', $s['id']); ?>><?php echo afv($s['section_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="af-field">
                <label><?php echo t('af_class'); ?> <span class="req">*</span></label>
                <select name="class_id" id="classSelect" required onchange="afFilterSections()">
                    <option value=""><?php echo t('af_select_class'); ?></option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $osel('class_id', $c['id']); ?>><?php echo afv($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="af-section-bar"><?php echo t('af_sec_personal'); ?></div>
        <div class="af-grid">
            <div class="af-field"><label><?php echo t('af_name_en'); ?> <span class="req">*</span></label><input type="text" name="name_en" required value="<?php echo $ov('name_en'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_gender'); ?> <span class="req">*</span></label>
                <select name="gender" required>
                    <option value=""><?php echo t('af_select_gender'); ?></option>
                    <option value="Male" <?php echo $osel('gender', 'Male'); ?>><?php echo t('af_male'); ?></option>
                    <option value="Female" <?php echo $osel('gender', 'Female'); ?>><?php echo t('af_female'); ?></option>
                </select>
            </div>
            <div class="af-field"><label><?php echo t('af_name_bn'); ?></label><input type="text" name="name_bn" value="<?php echo $ov('name_bn'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_blood_group'); ?> <span class="req">*</span></label>
                <select name="blood_group" required>
                    <option value=""><?php echo t('af_select_blood_group'); ?></option>
                    <?php foreach (['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-', 'Unknown'] as $bg): ?>
                        <option value="<?php echo $bg; ?>" <?php echo $osel('blood_group', $bg); ?>><?php echo $bg === 'Unknown' ? t('af_unknown') : $bg; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="af-field"><label><?php echo t('af_dob'); ?></label><input type="date" name="dob" value="<?php echo $ov('dob'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_religion'); ?> <span class="req">*</span></label>
                <select name="religion" required>
                    <option value=""><?php echo t('af_select_religion'); ?></option>
                    <?php foreach (['Islam' => 'af_islam', 'Hinduism' => 'af_hinduism', 'Buddhism' => 'af_buddhism', 'Christianity' => 'af_christianity', 'Others' => 'af_others'] as $rl => $rl_key): ?>
                        <option value="<?php echo $rl; ?>" <?php echo $osel('religion', $rl); ?>><?php echo t($rl_key); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="af-field"><label><?php echo t('af_birth_reg_no'); ?></label><input type="text" name="birth_reg_no" value="<?php echo $ov('birth_reg_no'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_nationality'); ?> <span class="req">*</span></label><input type="text" name="nationality" required value="<?php echo $ov('nationality', 'Bangladeshi'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_email'); ?></label><input type="email" name="email" value="<?php echo $ov('email'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_mobile'); ?></label><input type="tel" name="mobile" class="phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="<?php echo t('af_mobile_11_title'); ?>" value="<?php echo $ov('mobile'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_orphan'); ?></label>
                <select name="orphan">
                    <option value="">Select Option</option>
                    <option value="Yes" <?php echo $osel('orphan', 'Yes'); ?>>Yes</option>
                    <option value="No" <?php echo $osel('orphan', 'No'); ?>>No</option>
                </select>
            </div>
            <div class="af-field"><label><?php echo t('af_working_student'); ?></label>
                <select name="working_student">
                    <option value="">Select Option</option>
                    <option value="Yes" <?php echo $osel('working_student', 'Yes'); ?>>Yes</option>
                    <option value="No" <?php echo $osel('working_student', 'No'); ?>>No</option>
                </select>
            </div>
            <div class="af-field"><label><?php echo t('af_disability_status'); ?></label>
                <select name="disability_status">
                    <option value="">Select Option</option>
                    <?php foreach (['None' => 'af_none', 'Physical' => 'af_physical', 'Visual' => 'af_visual', 'Hearing' => 'af_hearing', 'Other' => 'af_other'] as $ds => $ds_key): ?>
                        <option value="<?php echo $ds; ?>" <?php echo $osel('disability_status', $ds); ?>><?php echo t($ds_key); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="af-field"><label><?php echo t('af_photo'); ?></label>
                <div class="af-file-box">
                    <input type="file" name="photo" accept=".jpg,.jpeg,.png">
                    <span class="af-file-note"><?php echo t('af_photo_note'); ?></span>
                </div>
            </div>
        </div>

        <hr class="af-sep">
        <div class="af-section-bar"><?php echo t('af_sec_prev_school'); ?></div>
        <div class="af-grid">
            <div class="af-field"><label><?php echo t('af_prev_school_name'); ?></label><input type="text" name="prev_school_name" value="<?php echo $ov('prev_school_name'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_prev_class'); ?></label>
                <select name="prev_class_id">
                    <option value=""><?php echo t('af_select_class'); ?></option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $osel('prev_class_id', $c['id']); ?>><?php echo afv($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="af-field"><label><?php echo t('af_prev_section'); ?></label><input type="text" name="prev_section" value="<?php echo $ov('prev_section'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_prev_result'); ?></label><input type="text" name="prev_result" value="<?php echo $ov('prev_result'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_prev_roll'); ?></label><input type="text" name="prev_roll" value="<?php echo $ov('prev_roll'); ?>"></div>
        </div>

        <hr class="af-sep">
        <div class="af-section-bar"><?php echo t('af_sec_address'); ?></div>
        <div class="af-grid">
            <div class="af-field"><label><?php echo t('af_present_address'); ?> <span class="req">*</span></label><textarea name="present_address" id="presentAddress" rows="2" required><?php echo $ov('present_address'); ?></textarea></div>
            <div class="af-field">
                <label><?php echo t('af_permanent_address'); ?> <span class="req">*</span></label>
                <div>
                    <textarea name="permanent_address" id="permanentAddress" rows="2" required><?php echo $ov('permanent_address'); ?></textarea>
                    <div class="af-check-row">
                        <input type="checkbox" id="sameAsPresent" onchange="afCopyPresentAddress()">
                        <label for="sameAsPresent" style="font-weight:400;"><?php echo t('af_same_as_present'); ?></label>
                    </div>
                </div>
            </div>
        </div>

        <hr class="af-sep">
        <div class="af-section-bar"><?php echo t('af_sec_guardian'); ?></div>
        <div class="af-grid">
            <div class="af-field"><label><?php echo t('af_father_name'); ?> <span class="req">*</span></label><input type="text" name="father_name" required value="<?php echo $ov('father_name'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_mother_name'); ?> <span class="req">*</span></label><input type="text" name="mother_name" required value="<?php echo $ov('mother_name'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_father_nid'); ?></label><input type="text" name="father_nid" value="<?php echo $ov('father_nid'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_mother_nid'); ?></label><input type="text" name="mother_nid" value="<?php echo $ov('mother_nid'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_father_profession'); ?></label><input type="text" name="father_profession" value="<?php echo $ov('father_profession'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_mother_profession'); ?></label><input type="text" name="mother_profession" value="<?php echo $ov('mother_profession'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_father_phone'); ?></label><input type="tel" name="father_phone" class="phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="<?php echo t('af_mobile_11_title'); ?>" value="<?php echo $ov('father_phone'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_mother_photo'); ?></label>
                <div class="af-file-box">
                    <input type="file" name="mother_photo" accept=".jpg,.jpeg,.png">
                    <span class="af-file-note"><?php echo t('af_photo_note'); ?></span>
                </div>
            </div>
            <div class="af-field"><label><?php echo t('af_yearly_income'); ?></label><input type="text" name="yearly_income" value="<?php echo $ov('yearly_income'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_guardian_name'); ?> <span class="req">*</span></label><input type="text" name="guardian_name" required value="<?php echo $ov('guardian_name'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_guardian_phone'); ?> <span class="req">*</span></label><input type="tel" name="guardian_phone" class="phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="<?php echo t('af_mobile_11_title'); ?>" required value="<?php echo $ov('guardian_phone'); ?>"></div>
            <div class="af-field"><label><?php echo t('af_landless'); ?></label>
                <select name="landless">
                    <option value="">Select Option</option>
                    <option value="Yes" <?php echo $osel('landless', 'Yes'); ?>>Yes</option>
                    <option value="No" <?php echo $osel('landless', 'No'); ?>>No</option>
                </select>
            </div>
            <div class="af-field"><label><?php echo t('af_father_photo'); ?></label>
                <div class="af-file-box">
                    <input type="file" name="father_photo" accept=".jpg,.jpeg,.png">
                    <span class="af-file-note"><?php echo t('af_photo_note'); ?></span>
                </div>
            </div>
        </div>

        <?php if ($af_payment_active): ?>
        <hr class="af-sep">
        <div class="af-section-bar"><?php echo t('af_sec_payment'); ?></div>
        <div id="afFeeNotice" class="af-alert" style="display:none; background:#eef6f1; color:#1c5e39; border-color:#bfe3cd; margin:0 0 18px;">
            <?php echo t('af_fee_notice'); ?>
        </div>
        <div id="afPaymentBox" style="display:none;">
            <div class="af-check-row" style="gap:22px; margin-bottom:16px;">
                <?php if ($af_manual_on): ?>
                <label style="display:flex; align-items:center; gap:6px; font-weight:600; font-size:13.5px; cursor:pointer;">
                    <input type="radio" name="pay_method" value="Manual" onchange="afTogglePayMethod()" checked> <?php echo t('af_pay_manual'); ?>
                </label>
                <?php endif; ?>
                <?php if ($af_auto_on): ?>
                <label style="display:flex; align-items:center; gap:6px; font-weight:600; font-size:13.5px; cursor:pointer;">
                    <input type="radio" name="pay_method" value="Auto" onchange="afTogglePayMethod()" <?php echo !$af_manual_on ? 'checked' : ''; ?>> <?php echo t('af_pay_online_via'); ?><?php echo afv($af_auto_provider_label); ?>)
                </label>
                <?php endif; ?>
            </div>

            <?php if ($af_manual_on): ?>
            <div id="afManualBox" class="af-grid" style="background:rgba(169,119,46,0.05); padding:16px; border-radius:6px; margin-bottom:12px;">
                <div class="af-field full">
                    <label style="grid-column:1/-1;"><?php echo t('af_send_money_to'); ?></label>
                    <div style="grid-column:1/-1; font-size:13.5px; line-height:1.9;">
                        <?php if (!empty($af_gateway['bkash_number'])): ?>
                            <div><strong>bKash (<?php echo afv($af_gateway['bkash_type']); ?>):</strong> <?php echo afv($af_gateway['bkash_number']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($af_gateway['nagad_number'])): ?>
                            <div><strong>Nagad (<?php echo afv($af_gateway['nagad_type']); ?>):</strong> <?php echo afv($af_gateway['nagad_number']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($af_gateway['manual_instructions'])): ?>
                            <div class="text-muted" style="margin-top:6px; color:#5c6b7d;"><?php echo nl2br(afv($af_gateway['manual_instructions'])); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="af-field">
                    <label><?php echo t('af_sent_from_method'); ?></label>
                    <select name="pay_sender_method">
                        <option value="bKash">bKash</option>
                        <option value="Nagad">Nagad</option>
                    </select>
                </div>
                <div class="af-field"><label><?php echo t('af_sender_number'); ?></label><input type="tel" name="pay_sender_number" inputmode="numeric" maxlength="11" placeholder="01XXXXXXXXX"></div>
                <div class="af-field full"><label><?php echo t('af_trx_id'); ?></label><input type="text" name="pay_trx_id" placeholder="<?php echo t('af_trx_id_ph'); ?>"></div>
            </div>
            <?php endif; ?>

            <?php if ($af_auto_on): ?>
            <div id="afAutoBox" class="af-grid" style="background:rgba(169,119,46,0.05); padding:16px; border-radius:6px; margin-bottom:12px; display:none;">
                <div class="af-field full" style="grid-template-columns:1fr;">
                    <p style="margin:0; font-size:13.5px; color:#3a4c63;"><?php echo t('af_auto_pay_note'); ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="af-submit-wrap">
            <button type="submit" class="af-btn"><?php echo t('af_register_btn'); ?></button>
        </div>
    </form>
</div>

<script>
function afFilterSections() {
    const classId = document.getElementById('classSelect').value;
    const select = document.getElementById('sectionSelect');
    Array.from(select.options).forEach(opt => {
        if (!opt.value) { opt.hidden = false; return; }
        opt.hidden = classId && opt.dataset.class !== classId;
    });
}
afFilterSections();

// ---- Admission Fee / Payment section (Session+Class অনুযায়ী দেখানো হয়) ----
<?php if ($af_payment_active): ?>
const afFeeMap = <?php echo json_encode($af_fee_map, JSON_NUMERIC_CHECK); ?>;

function afUpdateFeeBox() {
    const sessionId = document.querySelector('select[name="session_id"]').value;
    const classId = document.getElementById('classSelect').value;
    const notice = document.getElementById('afFeeNotice');
    const box = document.getElementById('afPaymentBox');
    const amountEl = document.getElementById('afFeeAmount');
    const key = sessionId + '-' + classId;
    const fee = afFeeMap[key] || 0;

    if (sessionId && classId && fee > 0) {
        amountEl.textContent = fee;
        notice.style.display = 'block';
        box.style.display = 'block';
    } else {
        notice.style.display = 'none';
        box.style.display = 'none';
    }
}
document.querySelector('select[name="session_id"]').addEventListener('change', afUpdateFeeBox);
document.getElementById('classSelect').addEventListener('change', afUpdateFeeBox);
afUpdateFeeBox();

function afTogglePayMethod() {
    const selected = document.querySelector('input[name="pay_method"]:checked');
    const method = selected ? selected.value : '';
    const manualBox = document.getElementById('afManualBox');
    const autoBox = document.getElementById('afAutoBox');
    if (manualBox) manualBox.style.display = (method === 'Manual') ? 'grid' : 'none';
    if (autoBox) autoBox.style.display = (method === 'Auto') ? 'grid' : 'none';
}
afTogglePayMethod();
<?php endif; ?>

function afCopyPresentAddress() {
    const checkbox = document.getElementById('sameAsPresent');
    const permanent = document.getElementById('permanentAddress');
    if (checkbox.checked) {
        permanent.value = document.getElementById('presentAddress').value;
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
        errEl.textContent = <?php echo json_encode(t('af_mobile_11_err')); ?>;
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
</body>
</html>
