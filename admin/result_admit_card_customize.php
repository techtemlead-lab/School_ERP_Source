<?php
// admin/result_admit_card_customize.php - Result Module > Result Reports > Customize Admit Card
// জেনারেল ও অ্যাডমিশন (General / Admission) দুই ধরনের অ্যাডমিট কার্ডের লেআউট, কালার মোড,
// কাস্টম সাইজ, টেম্পলেট-ভিত্তিক হেডার/স্বাক্ষর/ফিল্ড কাস্টমাইজেশন ও ১-পেজ নিয়মাবলী এখান থেকে সেভ করা যায়।

require_once __DIR__ . '/../config/seat_card_helper.php';
require_once __DIR__ . '/../config/uploads.php';

function accv($val): string
{
    return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8');
}

function acc_unlink_owned(?string $rel_path): void
{
    $p = str_replace('\\', '/', ltrim(trim((string) ($rel_path ?? '')), '/'));
    if ($p === '' || strpos($p, '..') !== false) {
        return;
    }
    if (strpos($p, 'uploads/') !== 0 || strpos($p, '/admit_card/') === false) {
        return;
    }
    $full = __DIR__ . '/../' . $p;
    if (is_file($full)) {
        @unlink($full);
    }
}

$acc_msg = $_SESSION['acc_msg'] ?? '';
$acc_err = $_SESSION['acc_err'] ?? '';
unset($_SESSION['acc_msg'], $_SESSION['acc_err']);

$admit_type = ac_normalize_type($_POST['admit_type'] ?? ($_GET['admit_type'] ?? 'general'));

$fr_bid = branch_current_id();
$scope_bid = $fr_bid !== null ? max(0, (int) $fr_bid) : 0;
$institute = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
if ($fr_bid !== null) {
    $bst = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $bst->execute([$fr_bid]);
    $branch = $bst->fetch(PDO::FETCH_ASSOC) ?: [];
} else {
    $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
}

$ers_row = [];
try {
    $ers_row = $pdo->query("SELECT left_signature, right_signature FROM exam_result_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $ers_row = [];
}

$can_edit    = !function_exists('perm_action_allowed') || perm_action_allowed($pdo, 'result_admit_card_customize', 'edit');
$can_routine = !function_exists('perm_page_allowed') || perm_page_allowed($pdo, 'result_exam_routine');

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_admit_card_settings']) || isset($_POST['update_admit_card_settings_reset']) || isset($_POST['update_admit_instructions_reset']))) {
    $redir = 'index.php?page=result_admit_card_customize&admit_type=' . $admit_type;
    if (isset($_POST['update_admit_instructions_reset'])) {
        $redir .= '#dacRulesSection';
    }

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['acc_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: ' . $redir);
        exit();
    }
    if (!$can_edit) {
        $_SESSION['acc_err'] = 'অ্যাডমিট কার্ড কাস্টমাইজেশন পরিবর্তনের অনুমতি আপনার নেই।';
        header('Location: ' . $redir);
        exit();
    }

    if (isset($_POST['update_admit_instructions_reset'])) {
        ac_reset_instructions($pdo, $fr_bid, $admit_type);
        if (function_exists('sec_log_activity')) {
            sec_log_activity($pdo, 'Result', 'Update', "Admit Card ({$admit_type}) পরীক্ষার নিয়মাবলী ডিফল্টে রিসেট করা হয়েছে।");
        }
        $_SESSION['acc_msg'] = 'পরীক্ষার নিয়মাবলী ডিফল্টে ফিরিয়ে আনা হয়েছে।';
        header('Location: ' . $redir);
        exit();
    }

    if (isset($_POST['update_admit_card_settings_reset'])) {
        $old_owned = ac_reset_settings($pdo, $fr_bid, $admit_type);
        foreach ($old_owned as $op) {
            acc_unlink_owned($op);
        }
        unset($_SESSION['dac_color_' . $admit_type], $_SESSION['dac_color']);
        if (function_exists('sec_log_activity')) {
            sec_log_activity($pdo, 'Result', 'Update', "Admit Card ({$admit_type}) কাস্টমাইজেশন সেটিংস ডিফল্টে রিসেট করা হয়েছে।");
        }
        $_SESSION['acc_msg'] = 'অ্যাডমিট কার্ড কাস্টমাইজেশন সেটিংস ডিফল্টে ফিরিয়ে আনা হয়েছে।';
        header('Location: ' . $redir);
        exit();
    }

    // Save Customization
    $prev_cfg = ac_load_settings($pdo, $fr_bid, $admit_type);
    $prev_same_scope = ((int) ($prev_cfg['scope_branch_id'] ?? -1) === $scope_bid) && !empty($prev_cfg['is_custom']);

    $up_logo  = erp_image_upload('admit_logo', false, 'admit_card', 2 * 1024 * 1024, 1200);
    $up_sig_p = erp_image_upload('sig_principal_file', false, 'admit_card', 2 * 1024 * 1024, 1200);
    $up_sig_s = erp_image_upload('sig_secondary_file', false, 'admit_card', 2 * 1024 * 1024, 1200);

    $upload_err = (!$up_logo['ok'] ? $up_logo['error'] : null)
        ?? (!$up_sig_p['ok'] ? $up_sig_p['error'] : null)
        ?? (!$up_sig_s['ok'] ? $up_sig_s['error'] : null);

    if ($upload_err) {
        foreach ([$up_logo['path'] ?? null, $up_sig_p['path'] ?? null, $up_sig_s['path'] ?? null] as $tmp_up) {
            if ($tmp_up) {
                acc_unlink_owned($tmp_up);
            }
        }
        $_SESSION['acc_err'] = $upload_err;
        header('Location: ' . $redir);
        exit();
    }

    $resolve_img = static function (array $up, string $clear_key, string $prev_path, bool $same_scope) use ($pdo, $scope_bid, $admit_type): array {
        $cleared = isset($_POST[$clear_key]);
        if (!empty($up['path'])) {
            $can_del = $same_scope && $prev_path !== '' && $prev_path !== $up['path'] && !ac_is_asset_in_use($pdo, $prev_path, $scope_bid, $admit_type);
            return ['path' => (string) $up['path'], 'delete_old' => $can_del];
        }
        if ($cleared) {
            $can_del = $same_scope && $prev_path !== '' && !ac_is_asset_in_use($pdo, $prev_path, $scope_bid, $admit_type);
            return ['path' => '', 'delete_old' => $can_del];
        }
        return ['path' => $prev_path, 'delete_old' => false];
    };

    $r_logo  = $resolve_img($up_logo,  'clear_logo',          (string) ($prev_cfg['logo_path'] ?? ''),          $prev_same_scope);
    $r_sig_p = $resolve_img($up_sig_p, 'clear_sig_principal', (string) ($prev_cfg['sig_principal_path'] ?? ''), $prev_same_scope);
    $r_sig_s = $resolve_img($up_sig_s, 'clear_sig_secondary', (string) ($prev_cfg['sig_secondary_path'] ?? ''), $prev_same_scope);

    if ($r_logo['delete_old'])  { acc_unlink_owned((string) ($prev_cfg['logo_path'] ?? '')); }
    if ($r_sig_p['delete_old']) { acc_unlink_owned((string) ($prev_cfg['sig_principal_path'] ?? '')); }
    if ($r_sig_s['delete_old']) { acc_unlink_owned((string) ($prev_cfg['sig_secondary_path'] ?? '')); }

    $dl_in = ac_normalize_layout_key((string) ($_POST['default_layout'] ?? '1full_modern'));
    $ft_in = ($dl_in === '1full_classic') ? 'classic' : 'modern';

    $new_cfg = [
        'default_layout'         => $dl_in,
        'default_ori'            => (string) ($_POST['default_ori'] ?? 'portrait'),
        'default_w'              => (float) ($_POST['default_w'] ?? 94.0),
        'default_h'              => (float) ($_POST['default_h'] ?? 135.0),
        'default_gap'            => (float) ($_POST['default_gap'] ?? 4.0),
        'full_template'          => $ft_in,
        'default_color_mode'     => (($_POST['default_color_mode'] ?? 'color') === 'grayscale') ? 'grayscale' : 'color',

        'card_title'             => (string) ($_POST['card_title'] ?? ($admit_type === 'admission' ? 'Admission Admit Card' : 'Admit Card')),
        'show_nb_note'           => isset($_POST['show_nb_note']) ? 1 : 0,
        'nb_note_text'           => (string) ($_POST['nb_note_text'] ?? ''),
        'show_compact_note'      => isset($_POST['show_compact_note']) ? 1 : 0,
        'compact_note_text'      => (string) ($_POST['compact_note_text'] ?? ''),

        'show_logo'              => isset($_POST['show_logo']) ? 1 : 0,
        'logo_path'              => $r_logo['path'],
        'show_institute_name'    => isset($_POST['show_institute_name']) ? 1 : 0,
        'custom_institute_name'  => (string) ($_POST['custom_institute_name'] ?? ''),
        'show_slogan'            => isset($_POST['show_slogan']) ? 1 : 0,
        'custom_slogan'          => (string) ($_POST['custom_slogan'] ?? ''),
        'show_address'           => isset($_POST['show_address']) ? 1 : 0,
        'show_address_half'      => isset($_POST['show_address_half']) ? 1 : 0,
        'custom_address'         => (string) ($_POST['custom_address'] ?? ''),
        'show_phone'             => isset($_POST['show_phone']) ? 1 : 0,
        'custom_phone'           => (string) ($_POST['custom_phone'] ?? ''),
        'show_email'             => isset($_POST['show_email']) ? 1 : 0,
        'custom_email'           => (string) ($_POST['custom_email'] ?? ''),
        'show_website'           => isset($_POST['show_website']) ? 1 : 0,
        'custom_website'         => (string) ($_POST['custom_website'] ?? ''),
        'show_eiin'              => isset($_POST['show_eiin']) ? 1 : 0,
        'custom_eiin'            => (string) ($_POST['custom_eiin'] ?? ''),
        'show_issue_date'        => isset($_POST['show_issue_date']) ? 1 : 0,

        'show_sig_principal'     => isset($_POST['show_sig_principal']) ? 1 : 0,
        'sig_principal_path'     => $r_sig_p['path'],
        'sig_principal_name'     => (string) ($_POST['sig_principal_name'] ?? ''),
        'sig_principal_label'    => (string) ($_POST['sig_principal_label'] ?? ''),
        'show_sig_secondary'     => isset($_POST['show_sig_secondary']) ? 1 : 0,
        'sig_secondary_path'     => $r_sig_s['path'],
        'sig_secondary_name'     => (string) ($_POST['sig_secondary_name'] ?? ''),
        'sig_secondary_label'    => (string) ($_POST['sig_secondary_label'] ?? ''),
        'show_sig_candidate'     => isset($_POST['show_sig_candidate']) ? 1 : 0,
        'sig_candidate_label'    => (string) ($_POST['sig_candidate_label'] ?? 'Signature of the Candidate'),

        'show_photo'             => isset($_POST['show_photo']) ? 1 : 0,
        'show_qr'                => isset($_POST['show_qr']) ? 1 : 0,
        'show_student_id'        => isset($_POST['show_student_id']) ? 1 : 0,
        'show_roll'              => isset($_POST['show_roll']) ? 1 : 0,
        'show_class'             => isset($_POST['show_class']) ? 1 : 0,
        'show_group'             => isset($_POST['show_group']) ? 1 : 0,
        'show_shift'             => isset($_POST['show_shift']) ? 1 : 0,
        'show_section'           => isset($_POST['show_section']) ? 1 : 0,
        'show_branch'            => isset($_POST['show_branch']) ? 1 : 0,
        'show_session'           => isset($_POST['show_session']) ? 1 : 0,
        'show_dob'               => isset($_POST['show_dob']) ? 1 : 0,
        'show_father_name'       => isset($_POST['show_father_name']) ? 1 : 0,
        'show_mother_name'       => isset($_POST['show_mother_name']) ? 1 : 0,
        'show_student_address'   => isset($_POST['show_student_address']) ? 1 : 0,
        'show_gender'            => isset($_POST['show_gender']) ? 1 : 0,
        'show_religion'          => isset($_POST['show_religion']) ? 1 : 0,
        'show_blood_group'       => isset($_POST['show_blood_group']) ? 1 : 0,
        'show_exam_schedule'     => isset($_POST['show_exam_schedule']) ? 1 : 0,
        'show_instructions'      => isset($_POST['show_instructions']) ? 1 : 0,

        'show_version'           => isset($_POST['show_version']) ? 1 : 0,
        'custom_version'         => (string) ($_POST['custom_version'] ?? ''),
        'show_category'          => isset($_POST['show_category']) ? 1 : 0,
        'custom_category'        => (string) ($_POST['custom_category'] ?? 'General'),
        'show_age'               => isset($_POST['show_age']) ? 1 : 0,
        'show_birth_reg'         => isset($_POST['show_birth_reg']) ? 1 : 0,
        'show_student_mobile'    => isset($_POST['show_student_mobile']) ? 1 : 0,
        'show_permanent_address' => isset($_POST['show_permanent_address']) ? 1 : 0,
        'show_routine'           => ($admit_type === 'general' && isset($_POST['show_routine'])) ? 1 : 0,
    ];

    ac_save_settings($pdo, $fr_bid, $new_cfg, $admit_type);
    $_SESSION['dac_color_' . $admit_type] = $new_cfg['default_color_mode'];

    // 1-পেজ পরীক্ষার নিয়মাবলী: পরিবর্তন হলেই কেবল কাস্টম হিসেবে সেভ
    $cur_instr = ac_load_instructions($pdo, $fr_bid, $admit_type);
    $govt_def = ac_default_instructions($admit_type);
    $norm_nl = static fn(string $s): string => str_replace(["\r\n", "\r"], "\n", trim($s));
    $note_in   = $norm_nl((string) ($_POST['note_text'] ?? ''));
    $head_in   = $norm_nl((string) ($_POST['heading'] ?? ''));
    $rules_in  = $norm_nl((string) ($_POST['rules_text'] ?? ''));
    $carry_in  = $norm_nl((string) ($_POST['carry_text'] ?? ''));
    $barred_in = $norm_nl((string) ($_POST['barred_text'] ?? ''));

    $instr_already_custom = !empty($cur_instr['is_custom']) && ((int) ($cur_instr['scope_branch_id'] ?? -1) === $scope_bid);
    $instr_changed = ($note_in   !== $norm_nl((string) $govt_def['note_text']))
                  || ($head_in   !== $norm_nl((string) $govt_def['heading']))
                  || ($rules_in  !== $norm_nl((string) $govt_def['rules_text']))
                  || ($carry_in  !== $norm_nl((string) $govt_def['carry_text']))
                  || ($barred_in !== $norm_nl((string) $govt_def['barred_text']));

    $extra_msg = '';
    if ($instr_already_custom || $instr_changed) {
        if ($note_in === '' && $head_in === '' && $rules_in === '' && $carry_in === '' && $barred_in === '') {
            $extra_msg = ' (পরীক্ষার নিয়মাবলীর সব ঘর ফাঁকা থাকায় নিয়মাবলী আপডেট করা হয়নি; লুকাতে চাইলে টগল বন্ধ রাখুন।)';
        } else {
            ac_save_instructions($pdo, $fr_bid, [
                'note_text'   => $note_in,
                'heading'     => $head_in,
                'rules_text'  => $rules_in,
                'carry_text'  => $carry_in,
                'barred_text' => $barred_in,
            ], $admit_type);
        }
    }

    if (function_exists('sec_log_activity')) {
        sec_log_activity($pdo, 'Result', 'Update', "Admit Card ({$admit_type}) কাস্টমাইজেশন সেটিংস আপডেট করা হয়েছে।");
    }
    $_SESSION['acc_msg'] = 'অ্যাডমিট কার্ডের কাস্টমাইজেশন সেটিংস সফলভাবে সেভ হয়েছে।' . $extra_msg;
    header('Location: ' . $redir);
    exit();
}

// -----------------------------------------------------------
// বর্তমান সেটিংস ও DB ফলব্যাক তথ্য লোড
// -----------------------------------------------------------
$S = ac_load_settings($pdo, $fr_bid, $admit_type);
$instr = ac_load_instructions($pdo, $fr_bid, $admit_type);
$govt_defaults = ac_default_instructions($admit_type);
$has_own_settings = !empty($S['is_custom']) && ((int) ($S['scope_branch_id'] ?? -1) === $scope_bid);
$has_own_instr = !empty($instr['is_custom']) && ((int) ($instr['scope_branch_id'] ?? -1) === $scope_bid);

$db_inst_name = trim((string) (($branch['branch_name'] ?? '') ?: ($institute['institute_name'] ?? '')));
$db_slogan    = trim((string) ($institute['slogan'] ?? ''));
$db_addr      = $branch ? trim(implode(', ', array_filter([$branch['address'] ?? '', $branch['city'] ?? ''])) . (!empty($branch['zip_code']) ? '-' . $branch['zip_code'] : ''), ' ,-') : '';
$db_phone     = ac_format_phone(trim((string) (($institute['phone_number'] ?? '') ?: ($branch['phone'] ?? ''))));
$db_email     = trim((string) (($institute['email'] ?? '') ?: ($branch['email'] ?? '')));
$db_website   = trim((string) ($institute['website'] ?? ''));
$db_eiin      = trim((string) ($institute['eiin_no'] ?? ''));
$db_logo      = trim((string) ($institute['logo_path'] ?? ''));
$db_sig_p     = trim((string) ($branch['signature_path'] ?? ''));
$db_lbl_p     = trim((string) ($ers_row['right_signature'] ?? '')) ?: 'Head of Institute / Principal';
$db_lbl_s     = trim((string) ($ers_row['left_signature'] ?? '')) ?: 'Class Teacher';

$layout_options = ac_layout_labels();

// প্রতিটি হেডার আইটেমের সাথে কোন কোন লেআউট সাপোর্ট করে (data-layouts)
$header_fields = [
    ['show_institute_name', 'custom_institute_name', 'প্রতিষ্ঠানের নাম (Institute / Branch Name)', $db_inst_name, 'যেমন: ABC Ideal School', 'all', ''],
    ['show_slogan',         'custom_slogan',         'ট্যাগলাইন / স্লোগান (Tagline / Slogan)',   $db_slogan,    'ফাঁকা রাখলে Institute Settings এর ট্যাগলাইন বসবে (না থাকলে লুকানো থাকবে)', 'all', ''],
    ['show_address',        'custom_address',        'প্রতিষ্ঠানের ঠিকানা (Address)',              $db_addr,      'ফাঁকা রাখলে Branch ঠিকানা বসবে', 'all', ''],
    ['show_phone',          'custom_phone',          'মোবাইল / ফোন নম্বর (+88 সহ)',              $db_phone,     'ফাঁকা রাখলে +88 সহ অটো DB ফোন নম্বর বসবে', '1full_modern,1full_classic,2half', 'কার্ডে প্রদর্শনের সময় নম্বরের শুরুতে স্বয়ংক্রিয়ভাবে +88 বসবে'],
    ['show_email',          'custom_email',          'ইমেইল অ্যাড্রেস (Email Address)',           $db_email,     'ফাঁকা রাখলে Institute/Branch ইমেইল বসবে (না থাকলে লুকানো থাকবে)', '1full_modern,1full_classic,2half', ''],
    ['show_website',        'custom_website',        'ওয়েবসাইট (Website URL)',                    $db_website,   'ফাঁকা রাখলে Institute ওয়েবসাইট বসবে (না থাকলে লুকানো থাকবে)', 'all', ''],
    ['show_eiin',           'custom_eiin',           'EIIN / কোড (Classic ১-পেজ)',               $db_eiin,      'ফাঁকা রাখলে EIIN বসবে', '1full_classic', ''],
];

// প্রতিটি ফিল্ডের সাথে প্রযোজ্য টেম্পলেটগুলো (data-layouts)
$student_toggles = [
    ['show_photo',             'শিক্ষার্থীর ছবি (Photo)',               'all'],
    ['show_qr',                'QR Code',                               '1full_modern,3rect,4rect,4sq,custom'],
    ['show_student_id',        'Student ID / Reg. No.',                 'all'],
    ['show_roll',              $admit_type === 'admission' ? 'Admission Roll No.' : 'Roll No.', 'all'],
    ['show_class',             'Class',                                 'all'],
    ['show_group',             'Group',                                 '1full_modern,1full_classic,2half'],
    ['show_shift',             'Shift',                                 'all'],
    ['show_section',           'Section',                               '1full_classic,2half,3rect,4rect,4sq,custom'],
    ['show_version',           'Version',                               '1full_modern'],
    ['show_category',          'Category',                              '1full_modern'],
    ['show_branch',            'Branch / Centre Name',                  '1full_classic,2half'],
    ['show_session',           'Academic Session',                      '1full_modern,1full_classic,2half'],
    ['show_dob',               'Date of Birth',                         '1full_modern,1full_classic'],
    ['show_age',               'Age / বয়স',                             '1full_modern'],
    ['show_birth_reg',         'Birth Reg. No.',                        '1full_modern'],
    ['show_student_mobile',    'Student Mobile (+88 সহ)',               '1full_modern'],
    ['show_father_name',       "Father's Name",                         '1full_modern,1full_classic'],
    ['show_mother_name',       "Mother's Name",                         '1full_modern,1full_classic'],
    ['show_student_address',   'Present / Candidate Address',           '1full_modern,1full_classic'],
    ['show_permanent_address', 'Permanent Address',                     '1full_modern'],
    ['show_gender',            'Gender',                                '1full_modern,1full_classic'],
    ['show_religion',          'Religion',                              '1full_classic'],
    ['show_blood_group',       'Blood Group',                           '1full_classic'],
    ['show_exam_schedule',     'Exam Date / Part / Timings',            '1full_classic'],
];
?>

<style>
.acc-panel { padding: 0 !important; overflow: hidden; }
.acc-panel-hd {
    display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;
    padding: 13px 18px; cursor: pointer; user-select: none;
    background: #f8fafc; border-bottom: 1px solid #e2e8f0; transition: background .15s;
}
.acc-panel.collapsed .acc-panel-hd { border-bottom-color: transparent; }
.acc-panel-hd:hover { background: #eff6ff; }
.acc-panel-title { font-weight: 700; font-size: 14.5px; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px; }
.acc-chev { color: #475569; font-size: 13px; transition: transform .2s ease; }
.acc-panel.collapsed .acc-chev { transform: rotate(-90deg); }
.acc-panel-bd { padding: 16px 18px; }
.acc-panel.collapsed .acc-panel-bd { display: none; }
.acc-sticky-bar {
    position: sticky; bottom: 0; z-index: 1020;
    background: rgba(255, 255, 255, 0.96); backdrop-filter: blur(6px);
    border: 1px solid #cbd5e1; border-top: 2px solid #1e3a8a;
    border-radius: 10px; padding: 11px 18px;
    box-shadow: 0 -4px 16px rgba(15, 23, 42, 0.08);
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="fw-bold mb-0"><i class="fa-solid fa-sliders text-primary me-2"></i>Customize Admit Card</h5>
        <div class="small text-muted mt-1">
            স্কোপ:
            <?php if ($fr_bid !== null && !empty($branch['branch_name'])): ?>
                <span class="badge bg-info text-dark"><?php echo accv($branch['branch_name']); ?></span>
            <?php else: ?>
                <span class="badge bg-secondary">সকল ব্রাঞ্চ / গ্লোবাল</span>
            <?php endif; ?>
            <?php if ($has_own_settings): ?>
                <span class="badge bg-success ms-1">কাস্টম সেটিংস সক্রিয়</span>
            <?php else: ?>
                <span class="badge bg-light text-dark border ms-1">ডিফল্ট (ইনস্টিটিউট ডাটা)</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <div class="btn-group btn-group-sm" role="group" aria-label="Admit Card Type">
            <a href="index.php?page=result_admit_card_customize&admit_type=general"
               class="btn fw-semibold <?php echo $admit_type === 'general' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                <i class="fa-solid fa-graduation-cap me-1"></i> General Exam
            </a>
            <a href="index.php?page=result_admit_card_customize&admit_type=admission"
               class="btn fw-semibold <?php echo $admit_type === 'admission' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                <i class="fa-solid fa-user-plus me-1"></i> Admission Exam
            </a>
        </div>
        <a href="index.php?page=result_download_admit_card&admit_type=<?php echo accv($admit_type); ?>" class="btn btn-dark btn-sm fw-bold">
            <i class="fa-solid fa-id-card me-1"></i> Download Admit Card
        </a>
    </div>
</div>

<?php if ($acc_msg): ?><div class="alert alert-success py-2"><?php echo accv($acc_msg); ?></div><?php endif; ?>
<?php if ($acc_err): ?><div class="alert alert-danger py-2"><?php echo accv($acc_err); ?></div><?php endif; ?>
<?php if (!$can_edit): ?><div class="alert alert-warning py-2">আপনার এই পেজটি কেবল দেখার অনুমতি আছে; সেভ বা পরিবর্তনের অনুমতি নেই।</div><?php endif; ?>

<form method="POST" action="index.php?page=result_admit_card_customize&admit_type=<?php echo accv($admit_type); ?>" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="admit_type" value="<?php echo accv($admit_type); ?>">

    <!-- টপ লেআউট ও কালার কন্ট্রোল বার (সবসময় দৃশ্যমান) -->
    <div class="card-custom mb-3 border-primary" style="border-width:1.5px !important;">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3 flex-wrap gap-2">
            <h6 class="fw-bold mb-0">
                <i class="fa-solid fa-table-cells-large text-primary me-2"></i>অ্যাডমিট কার্ডের ধরন ও পেজ লেআউট নির্বাচন
                <span class="badge bg-primary-subtle text-primary border ms-1">
                    <?php echo $admit_type === 'admission' ? 'Admission Admit Card' : 'General Exam Admit Card'; ?>
                </span>
            </h6>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="accToggleAll(true)">
                    <i class="fa-solid fa-angles-down me-1"></i> সব খুলুন
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="accToggleAll(false)">
                    <i class="fa-solid fa-angles-up me-1"></i> সব গুছিয়ে নিন
                </button>
            </div>
        </div>

        <div class="row g-3 align-items-end">
            <div class="col-md-7">
                <label class="form-label fw-bold small mb-1">অ্যাডমিট কার্ড লেআউট / টেম্পলেট নির্বাচন (Download পেজে এটাই প্রিন্ট হবে)</label>
                <select name="default_layout" id="accLayout" data-ss="off" class="form-select form-select-sm" onchange="accLayoutToggle()">
                    <?php foreach ($layout_options as $k => $lbl): ?>
                        <option value="<?php echo $k; ?>"<?php echo ($S['default_layout'] ?? '1full_modern') === $k ? ' selected' : ''; ?>><?php echo accv($lbl); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label fw-bold small mb-1">ডিফল্ট প্রিন্ট কালার মোড (Color / Grayscale)</label>
                <select name="default_color_mode" id="accColorMode" data-ss="off" class="form-select form-select-sm">
                    <option value="color"<?php echo ($S['default_color_mode'] ?? 'color') === 'color' ? ' selected' : ''; ?>>Color (রঙিন — ডিফল্ট)</option>
                    <option value="grayscale"<?php echo ($S['default_color_mode'] ?? 'color') === 'grayscale' ? ' selected' : ''; ?>>Grayscale (সাদা-কালো B&amp;W)</option>
                </select>
            </div>

            <div class="col-12" id="accCustomWrap" style="display:none;">
                <div class="p-2 bg-light border rounded d-flex align-items-center gap-2 flex-wrap">
                    <span class="small fw-bold text-primary"><i class="fa-solid fa-ruler-combined me-1"></i>Custom Layout সেটিংস:</span>
                    <select name="default_ori" id="accOri" data-ss="off" class="form-select form-select-sm" style="width:auto;" onchange="accUpdateCustomCount()">
                        <option value="portrait"<?php echo $S['default_ori'] === 'portrait' ? ' selected' : ''; ?>>Portrait</option>
                        <option value="landscape"<?php echo $S['default_ori'] === 'landscape' ? ' selected' : ''; ?>>Landscape</option>
                    </select>
                    <label class="small mb-0">চওড়া (W):</label>
                    <input type="number" name="default_w" id="accW" class="form-control form-control-sm" style="width:76px;" min="50" max="287" step="0.5" value="<?php echo accv($S['default_w']); ?>" oninput="accUpdateCustomCount()">
                    <label class="small mb-0">উচ্চতা (H):</label>
                    <input type="number" name="default_h" id="accH" class="form-control form-control-sm" style="width:76px;" min="40" max="287" step="0.5" value="<?php echo accv($S['default_h']); ?>" oninput="accUpdateCustomCount()">
                    <label class="small mb-0">গ্যাপ:</label>
                    <input type="number" name="default_gap" id="accGap" class="form-control form-control-sm" style="width:68px;" min="0" max="30" step="0.5" value="<?php echo accv($S['default_gap'] ?? 4); ?>" oninput="accUpdateCustomCount()">
                    <span class="small text-muted">mm</span>
                    <span class="badge bg-secondary ms-auto" id="accCustomPer">প্রতি A4 পাতায় 4 টা</span>
                </div>
            </div>
        </div>
        <div class="small text-muted mt-2">
            নির্বাচিত লেআউট ও টেম্পলেট অনুযায়ী নিচে কেবল প্রযোজ্য কাস্টমাইজেশন কার্ডগুলোই দেখাবে (শুরুতে সবগুলো মিনিমাইজ করা আছে — প্রয়োজনীয় কার্ডে ক্লিক করে খুলুন)।
        </div>
    </div>

    <!-- ১. কার্ডের শিরোনাম ও টেম্পলেট-নির্দিষ্ট অপশন -->
    <div class="acc-panel card-custom mb-3 collapsed" id="accCardTitleSec" data-layouts="all">
        <div class="acc-panel-hd" onclick="accTogglePanel(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();accTogglePanel(this);}" role="button" tabindex="0">
            <h6 class="acc-panel-title"><i class="fa-solid fa-tag text-primary"></i> ১. কার্ডের শিরোনাম ও নোট অপশন</h6>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-secondary border small">সকল টেম্পলেট</span>
                <i class="fa-solid fa-chevron-down acc-chev"></i>
            </div>
        </div>
        <div class="acc-panel-bd">
            <div class="row g-3 align-items-end">
                <div class="col-md-4" data-layouts="all">
                    <label class="form-label fw-bold small mb-1">কার্ডের শিরোনাম (Card Title)</label>
                    <input type="text" name="card_title" maxlength="80" class="form-control form-control-sm" value="<?php echo accv($S['card_title']); ?>" placeholder="<?php echo $admit_type === 'admission' ? 'Admission Admit Card' : 'Admit Card'; ?>">
                </div>
                <div class="col-md-4" data-layouts="1full_modern">
                    <label class="form-label fw-bold small mb-1">ভার্সন ওভাররাইড (Version)</label>
                    <input type="text" name="custom_version" list="accVerList" maxlength="60" class="form-control form-control-sm" value="<?php echo accv($S['custom_version'] ?? ''); ?>" placeholder="ফাঁকা রাখলে স্কুলের ভার্সন (অথবা All) অটো আসবে">
                    <datalist id="accVerList">
                        <option value="All">
                        <option value="Bangla">
                        <option value="English">
                        <option value="Bangla & English">
                    </datalist>
                </div>
                <div class="col-md-4" data-layouts="1full_modern">
                    <label class="form-label fw-bold small mb-1">ক্যাটাগরি (Category)</label>
                    <input type="text" name="custom_category" maxlength="60" class="form-control form-control-sm" value="<?php echo accv($S['custom_category'] ?? 'General'); ?>" placeholder="General / Internal / External">
                </div>
                <div class="col-md-8" data-layouts="2half">
                    <div class="form-check form-switch mb-1">
                        <input class="form-check-input" type="checkbox" role="switch" id="sw_nb_note" name="show_nb_note"<?php echo !empty($S['show_nb_note']) ? ' checked' : ''; ?>>
                        <label class="form-check-label fw-bold small" for="sw_nb_note">২-পেজ (Half Page) কার্ডে নিচের N.B. নোট দেখাবে</label>
                    </div>
                    <input type="text" name="nb_note_text" maxlength="255" class="form-control form-control-sm" value="<?php echo accv($S['nb_note_text']); ?>">
                </div>
                <div class="col-md-8" data-layouts="3rect,4rect,4sq,custom">
                    <div class="form-check form-switch mb-1">
                        <input class="form-check-input" type="checkbox" role="switch" id="sw_compact_note" name="show_compact_note"<?php echo !empty($S['show_compact_note']) ? ' checked' : ''; ?>>
                        <label class="form-check-label fw-bold small" for="sw_compact_note">৩/৪-পেজ ও কাস্টম কার্ডে নিচের ফুটনোট দেখাবে</label>
                    </div>
                    <input type="text" name="compact_note_text" maxlength="255" class="form-control form-control-sm" value="<?php echo accv($S['compact_note_text']); ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- ২. লোগো, হেডার ও যোগাযোগের তথ্য -->
    <div class="acc-panel card-custom mb-3 collapsed" id="accHeaderSec" data-layouts="all">
        <div class="acc-panel-hd" onclick="accTogglePanel(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();accTogglePanel(this);}" role="button" tabindex="0">
            <h6 class="acc-panel-title"><i class="fa-solid fa-building-columns text-primary"></i> ২. লোগো, হেডার ও প্রতিষ্ঠানের তথ্য (On/Off এবং কাস্টম লেখা)</h6>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-secondary border small">সকল টেম্পলেট</span>
                <i class="fa-solid fa-chevron-down acc-chev"></i>
            </div>
        </div>
        <div class="acc-panel-bd">
            <p class="small text-muted mb-3">
                টগল অন রেখে কাস্টম ঘর ফাঁকা রাখলে স্বয়ংক্রিয়ভাবে প্রতিষ্ঠানের মূল সেটিংস (Institute / Branch Settings) থেকে তথ্য নেওয়া হবে; সেখানেও ফাঁকা থাকলে কার্ডে কিছু দেখাবে না। ফোন নম্বরের শুরুতে অ্যাডমিট কার্ডে স্বয়ংক্রিয়ভাবে <code>+88</code> বসবে।
            </p>

            <div class="border rounded p-3 mb-3 bg-light" data-layouts="all">
                <div class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="sw_logo" name="show_logo"<?php echo !empty($S['show_logo']) ? ' checked' : ''; ?>>
                            <label class="form-check-label fw-bold" for="sw_logo">অ্যাডমিট কার্ডে লোগো দেখাবে</label>
                        </div>
                        <div class="small text-muted mt-1">আলাদা লোগো আপলোড না করলে প্রতিষ্ঠানের মূল লোগো ব্যবহৃত হবে।</div>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-bold mb-1">অ্যাডমিট কার্ডের নিজস্ব লোগো (ঐচ্ছিক — JPG/PNG/WEBP)</label>
                        <input type="file" name="admit_logo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3 text-center">
                        <?php if (!empty($S['logo_path'])): ?>
                            <img src="../<?php echo accv(ltrim($S['logo_path'], '/')); ?>" alt="Custom Logo" style="max-height:54px; max-width:120px; object-fit:contain;" class="border bg-white p-1 rounded">
                            <div class="form-check form-check-inline mt-1 d-block">
                                <input class="form-check-input" type="checkbox" id="clear_logo" name="clear_logo" value="1">
                                <label class="form-check-label small text-danger" for="clear_logo">কাস্টম লোগো সরান</label>
                            </div>
                        <?php elseif ($db_logo !== ''): ?>
                            <img src="../<?php echo accv(ltrim($db_logo, '/')); ?>" alt="Institute Logo" style="max-height:48px; max-width:110px; object-fit:contain;" class="border bg-white p-1 rounded">
                            <div class="small text-muted">মূল লোগো চালু</div>
                        <?php else: ?>
                            <span class="small text-muted">কোনো লোগো নেই</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:29%;">হেডার আইটেম</th>
                            <th class="text-center" style="width:11%;">দেখাবে?</th>
                            <th>কাস্টম লেখা (ফাঁকা রাখলে অটো DB থেকে আসবে)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($header_fields as [$sw_key, $txt_key, $lbl, $db_val, $hint, $layouts, $extra_note]): ?>
                        <tr data-layouts="<?php echo accv($layouts); ?>">
                            <td>
                                <div class="fw-semibold small"><?php echo accv($lbl); ?></div>
                                <div class="text-muted" style="font-size:11.5px;">
                                    বর্তমান DB: <?php echo $db_val !== '' ? '<code>' . accv($db_val) . '</code>' : '<span class="fst-italic">ফাঁকা (কার্ডে লুকানো থাকবে)</span>'; ?>
                                </div>
                                <?php if ($extra_note !== ''): ?>
                                    <div class="text-primary" style="font-size:11px;"><?php echo accv($extra_note); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="sw_<?php echo $sw_key; ?>" name="<?php echo $sw_key; ?>"<?php echo !empty($S[$sw_key]) ? ' checked' : ''; ?>>
                                </div>
                            </td>
                            <td>
                                <input type="text" name="<?php echo $txt_key; ?>" maxlength="255" class="form-control form-control-sm" value="<?php echo accv($S[$txt_key] ?? ''); ?>" placeholder="<?php echo accv($hint); ?>">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr data-layouts="2half">
                            <td>
                                <div class="fw-semibold small">২-পেজ (Half Page) কার্ডের হেডারে ঠিকানা</div>
                                <div class="text-muted" style="font-size:11.5px;">ট্যাগলাইনের নিচে আলাদা ঠিকানা দেখাতে চাইলে চালু করুন</div>
                            </td>
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="sw_show_address_half" name="show_address_half"<?php echo !empty($S['show_address_half']) ? ' checked' : ''; ?>>
                                </div>
                            </td>
                            <td class="small text-muted">উপরের Address ফিল্ড অন থাকলে তবেই এটি প্রদর্শিত হবে।</td>
                        </tr>
                        <tr data-layouts="2half">
                            <td>
                                <div class="fw-semibold small">Issue Date (প্রকাশের তারিখ)</div>
                                <div class="text-muted" style="font-size:11.5px;">২-পেজ কার্ডের ডান কোনায় আজকের তারিখ</div>
                            </td>
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="sw_show_issue_date" name="show_issue_date"<?php echo !empty($S['show_issue_date']) ? ' checked' : ''; ?>>
                                </div>
                            </td>
                            <td class="small text-muted">স্বয়ংক্রিয়ভাবে প্রিন্টের তারিখে (`<?php echo date('d/m/Y'); ?>`) রেন্ডার হয়।</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ৩. স্বাক্ষর ও অনুমোদন -->
    <div class="acc-panel card-custom mb-3 collapsed" id="accSigSec" data-layouts="all">
        <div class="acc-panel-hd" onclick="accTogglePanel(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();accTogglePanel(this);}" role="button" tabindex="0">
            <h6 class="acc-panel-title"><i class="fa-solid fa-signature text-primary"></i> ৩. স্বাক্ষর ও অনুমোদন (Digital Signatures &amp; Labels)</h6>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-secondary border small">সকল টেম্পলেট</span>
                <i class="fa-solid fa-chevron-down acc-chev"></i>
            </div>
        </div>
        <div class="acc-panel-bd">
            <div class="row g-3">
                <div class="col-md-6" data-layouts="all">
                    <div class="border rounded p-3 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold small">প্রধান স্বাক্ষর (Principal / Head of Institute)</span>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="sw_sig_p" name="show_sig_principal"<?php echo !empty($S['show_sig_principal']) ? ' checked' : ''; ?>>
                                <label class="form-check-label small" for="sw_sig_p">দেখাবে</label>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">স্বাক্ষরকারীর নাম (ঐচ্ছিক, যেমন: Principal Name)</label>
                            <input type="text" name="sig_principal_name" maxlength="100" class="form-control form-control-sm" value="<?php echo accv($S['sig_principal_name'] ?? ''); ?>" placeholder="ফাঁকা রাখলে শুধু পদবি দেখাবে">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">পদবি লেবেল (Designation)</label>
                            <input type="text" name="sig_principal_label" maxlength="100" class="form-control form-control-sm" value="<?php echo accv($S['sig_principal_label'] ?? ''); ?>" placeholder="ডিফল্ট: <?php echo accv($db_lbl_p); ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">ডিজিটাল স্বাক্ষরের ছবি আপলোড (PNG স্বচ্ছ ব্যাকগ্রাউন্ড ভালো)</label>
                            <input type="file" name="sig_principal_file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="form-control form-control-sm">
                        </div>
                        <?php if (!empty($S['sig_principal_path'])): ?>
                            <div class="d-flex align-items-center gap-2 mt-2">
                                <img src="../<?php echo accv(ltrim($S['sig_principal_path'], '/')); ?>" alt="Principal Sig" style="max-height:36px; max-width:140px; object-fit:contain;" class="border bg-white px-2 py-1 rounded">
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" id="clear_sig_p" name="clear_sig_principal" value="1">
                                    <label class="form-check-label small text-danger" for="clear_sig_p">স্বাক্ষর সরান</label>
                                </div>
                            </div>
                        <?php elseif ($db_sig_p !== ''): ?>
                            <div class="small text-muted mt-1">বর্তমানে Branch স্বাক্ষর চালু আছে (নতুন দিলে সেটি অগ্রাধিকার পাবে)।</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-md-6" data-layouts="2half,3rect,4rect,4sq,custom">
                    <div class="border rounded p-3 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold small">দ্বিতীয় স্বাক্ষর (Class Teacher / Controller)</span>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="sw_sig_s" name="show_sig_secondary"<?php echo !empty($S['show_sig_secondary']) ? ' checked' : ''; ?>>
                                <label class="form-check-label small" for="sw_sig_s">দেখাবে</label>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">স্বাক্ষরকারীর নাম (ঐচ্ছিক)</label>
                            <input type="text" name="sig_secondary_name" maxlength="100" class="form-control form-control-sm" value="<?php echo accv($S['sig_secondary_name'] ?? ''); ?>" placeholder="ফাঁকা রাখলে শুধু পদবি দেখাবে">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">পদবি লেবেল (Designation)</label>
                            <input type="text" name="sig_secondary_label" maxlength="100" class="form-control form-control-sm" value="<?php echo accv($S['sig_secondary_label'] ?? ''); ?>" placeholder="ডিফল্ট: <?php echo accv($db_lbl_s); ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">ডিজিটাল স্বাক্ষরের ছবি আপলোড (ঐচ্ছিক)</label>
                            <input type="file" name="sig_secondary_file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="form-control form-control-sm">
                        </div>
                        <?php if (!empty($S['sig_secondary_path'])): ?>
                            <div class="d-flex align-items-center gap-2 mt-2">
                                <img src="../<?php echo accv(ltrim($S['sig_secondary_path'], '/')); ?>" alt="Secondary Sig" style="max-height:36px; max-width:140px; object-fit:contain;" class="border bg-white px-2 py-1 rounded">
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" id="clear_sig_s" name="clear_sig_secondary" value="1">
                                    <label class="form-check-label small text-danger" for="clear_sig_s">স্বাক্ষর সরান</label>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-12" data-layouts="1full_classic">
                    <div class="border rounded p-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="sw_sig_cand" name="show_sig_candidate"<?php echo !empty($S['show_sig_candidate']) ? ' checked' : ''; ?>>
                            <label class="form-check-label fw-bold small" for="sw_sig_cand">Classic ১-পেজ কার্ডে "Signature of the Candidate" বক্স দেখাবে</label>
                        </div>
                        <div style="min-width:260px;">
                            <input type="text" name="sig_candidate_label" maxlength="100" class="form-control form-control-sm" value="<?php echo accv($S['sig_candidate_label'] ?? 'Signature of the Candidate'); ?>" placeholder="Signature of the Candidate">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ৪. শিক্ষার্থীর তথ্যের ফিল্ড অন/অফ -->
    <div class="acc-panel card-custom mb-3 collapsed" id="accFieldsSec" data-layouts="all">
        <div class="acc-panel-hd" onclick="accTogglePanel(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();accTogglePanel(this);}" role="button" tabindex="0">
            <h6 class="acc-panel-title">
                <i class="fa-solid fa-list-check text-primary"></i> ৪. শিক্ষার্থীর তথ্যের ফিল্ড নিয়ন্ত্রণ (Show / Hide Fields)
                <span class="badge bg-light text-dark border ms-1" id="accFieldCountBadge"></span>
            </h6>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-secondary border small">টেম্পলেট অনুযায়ী ফিল্টার্ড</span>
                <i class="fa-solid fa-chevron-down acc-chev"></i>
            </div>
        </div>
        <div class="acc-panel-bd">
            <div class="row g-2">
                <?php foreach ($student_toggles as [$t_key, $t_lbl, $t_layouts]): ?>
                <div class="col-md-4 col-sm-6" data-layouts="<?php echo accv($t_layouts); ?>">
                    <div class="form-check form-switch border rounded px-3 py-2 ps-5">
                        <input class="form-check-input" type="checkbox" role="switch" id="sw_<?php echo $t_key; ?>" name="<?php echo $t_key; ?>"<?php echo !empty($S[$t_key]) ? ' checked' : ''; ?>>
                        <label class="form-check-label small fw-semibold" for="sw_<?php echo $t_key; ?>"><?php echo accv($t_lbl); ?></label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ৫. এক্সাম রুটিন (শুধু General + টেম্পলেট ২ এর জন্য) -->
    <?php if ($admit_type === 'general'): ?>
    <div class="acc-panel card-custom mb-3 collapsed" id="dacRoutineSection" data-layouts="1full_modern">
        <div class="acc-panel-hd" onclick="accTogglePanel(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();accTogglePanel(this);}" role="button" tabindex="0">
            <h6 class="acc-panel-title"><i class="fa-solid fa-calendar-days text-primary"></i> ৫. এক্সাম রুটিন (টেম্পলেট ২ — ১-পেজ লেআউট)</h6>
            <div class="d-flex align-items-center gap-3">
                <div class="form-check form-switch mb-0" onclick="event.stopPropagation()">
                    <input class="form-check-input" type="checkbox" role="switch" id="sw_show_routine" name="show_routine"<?php echo !empty($S['show_routine']) ? ' checked' : ''; ?>>
                    <label class="form-check-label small fw-bold" for="sw_show_routine">অ্যাডমিট কার্ডে Exam Routine দেখাবে</label>
                </div>
                <i class="fa-solid fa-chevron-down acc-chev"></i>
            </div>
        </div>
        <div class="acc-panel-bd">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <p class="small text-muted mb-0" style="max-width:740px;">
                    চালু থাকলে <strong>Result Module &gt; Exam Procedure &gt; Exam Routine</strong> মডিউলে সংরক্ষিত রুটিন স্বয়ংক্রিয়ভাবে অ্যাডমিট কার্ডে যুক্ত হবে। কোনো শ্রেণি বা পরীক্ষার রুটিন যুক্ত করা না থাকলে অ্যাডমিট কার্ড জেনারেট ব্লক থাকবে — রুটিন যুক্ত করার পর অথবা এই অপশনটি অফ (OFF) করলে পুনরায় জেনারেট করা যাবে।
                </p>
                <?php if ($can_routine): ?>
                <a href="index.php?page=result_exam_routine" class="btn btn-outline-primary btn-sm fw-semibold text-nowrap">
                    <i class="fa-solid fa-calendar-days me-1"></i> Exam Routine মডিউলে যান
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ৬. ১-পেজ পরীক্ষার নিয়মাবলী (শুধু ১-পেজ টেম্পলেট ১ ও ২ এর জন্য) -->
    <div class="acc-panel card-custom mb-3 collapsed" id="dacRulesSection" data-layouts="1full_modern,1full_classic">
        <div class="acc-panel-hd" onclick="accTogglePanel(this)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();accTogglePanel(this);}" role="button" tabindex="0">
            <h6 class="acc-panel-title">
                <i class="fa-solid fa-scale-balanced text-primary"></i> <?php echo $admit_type === 'general' ? '৬.' : '৫.'; ?> ১-পেজ অ্যাডমিট কার্ডের পরীক্ষার নিয়মাবলী
                <?php if ($has_own_instr): ?>
                    <span class="badge bg-warning text-dark ms-1">কাস্টম নিয়ম চালু</span>
                <?php else: ?>
                    <span class="badge bg-light text-primary border ms-1">ডিফল্ট চালু</span>
                <?php endif; ?>
            </h6>
            <div class="d-flex align-items-center gap-3">
                <div class="form-check form-switch mb-0" onclick="event.stopPropagation()">
                    <input class="form-check-input" type="checkbox" role="switch" id="sw_show_instructions" name="show_instructions"<?php echo !empty($S['show_instructions']) ? ' checked' : ''; ?>>
                    <label class="form-check-label small fw-bold" for="sw_show_instructions">১-পেজ কার্ডে নিয়মাবলী দেখাবে</label>
                </div>
                <i class="fa-solid fa-chevron-down acc-chev"></i>
            </div>
        </div>
        <div class="acc-panel-bd">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-bold small mb-1">বিশেষ দ্রষ্টব্য / Warning Note (লাল অংশ)</label>
                    <textarea name="note_text" id="accInstrNote" rows="2" class="form-control form-control-sm"><?php echo accv($instr['note_text'] ?? ''); ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label fw-bold small mb-1">নির্দেশাবলীর শিরোনাম (Heading)</label>
                    <input type="text" name="heading" id="accInstrHeading" maxlength="255" class="form-control form-control-sm" value="<?php echo accv($instr['heading'] ?? ''); ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-bold small mb-1">পরীক্ষার নিয়মাবলী (প্রতি লাইনে একটি করে নিয়ম)</label>
                    <textarea name="rules_text" id="accInstrRules" rows="7" class="form-control form-control-sm"><?php echo accv($instr['rules_text'] ?? ''); ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small mb-1">পরীক্ষার হলে যা সঙ্গে আনতে হবে (Must Carry)</label>
                    <textarea name="carry_text" id="accInstrCarry" rows="2" class="form-control form-control-sm"><?php echo accv($instr['carry_text'] ?? ''); ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small mb-1">পরীক্ষার হলে নিষিদ্ধ সামগ্রী (Barred Items)</label>
                    <textarea name="barred_text" id="accInstrBarred" rows="2" class="form-control form-control-sm"><?php echo accv($instr['barred_text'] ?? ''); ?></textarea>
                </div>
                <div class="col-12 d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="accFillGovtDefaults()">
                        <i class="fa-solid fa-wand-magic-sparkles me-1"></i> ডিফল্ট টেক্সট লোড করুন
                    </button>
                    <?php if ($has_own_instr && $can_edit): ?>
                    <button type="submit" name="update_admit_instructions_reset" value="1" class="btn btn-outline-danger btn-sm" onclick="return confirm('কাস্টম নিয়মকানুন মুছে ডিফল্ট নিয়ম ফিরিয়ে আনবেন?')">
                        <i class="fa-solid fa-rotate-left me-1"></i> ডিফল্ট নিয়ম ফিরিয়ে আনুন
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($can_edit): ?>
    <div class="acc-sticky-bar d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3 mb-2">
        <div>
            <?php if ($has_own_settings): ?>
            <button type="submit" name="update_admit_card_settings_reset" value="1" class="btn btn-outline-danger btn-sm" onclick="return confirm('অ্যাডমিট কার্ডের সব কাস্টমাইজেশন (লোগো/স্বাক্ষর/টগল) মুছে ডিফল্টে ফিরিয়ে আনবেন?')">
                <i class="fa-solid fa-rotate-left me-1"></i> সব কাস্টমাইজেশন ডিফল্টে রিসেট করুন
            </button>
            <?php else: ?>
            <span class="small text-muted">পরিবর্তন করার পর সংরক্ষণ করতে ডানের <strong>Save Settings</strong> বাটনে ক্লিক করুন।</span>
            <?php endif; ?>
        </div>
        <button type="submit" name="update_admit_card_settings" value="1" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;">
            <i class="fa-solid fa-floppy-disk me-1"></i> Save Settings
        </button>
    </div>
    <?php endif; ?>
</form>

<script>
function accTogglePanel(hd) {
    var p = hd && hd.closest ? hd.closest('.acc-panel') : null;
    if (p) {
        p.classList.toggle('collapsed');
    }
}

function accToggleAll(openAll) {
    document.querySelectorAll('.acc-panel').forEach(function (p) {
        if (p.style.display === 'none') { return; }
        p.classList.toggle('collapsed', !openAll);
    });
}

function accUpdateCustomCount() {
    var ori = (document.getElementById('accOri') || {}).value || 'portrait';
    var w = parseFloat((document.getElementById('accW') || {}).value) || 94;
    var h = parseFloat((document.getElementById('accH') || {}).value) || 135;
    var g = parseFloat((document.getElementById('accGap') || {}).value);
    if (isNaN(g) || g < 0) { g = 4; }
    var pw = (ori === 'landscape' ? 297 : 210) - 16;
    var ph = (ori === 'landscape' ? 210 : 297) - 16;
    w = Math.max(50, Math.min(pw, w));
    h = Math.max(40, Math.min(ph, h));
    var cols = Math.max(1, Math.floor((pw + g + 0.01) / (w + g)));
    var rows = Math.max(1, Math.floor((ph + g + 0.01) / (h + g)));
    var b = document.getElementById('accCustomPer');
    if (b) { b.textContent = 'প্রতি A4 পাতায় ' + (cols * rows) + ' টা'; }
}

function accLayoutToggle() {
    var l = document.getElementById('accLayout');
    var val = l ? l.value : '1full_modern';
    var w = document.getElementById('accCustomWrap');
    if (w) {
        w.style.display = (val === 'custom') ? 'block' : 'none';
    }
    if (val === 'custom') {
        accUpdateCustomCount();
    }

    var visibleFieldCount = 0;
    document.querySelectorAll('[data-layouts]').forEach(function (el) {
        var spec = (el.getAttribute('data-layouts') || '').trim();
        var allowed = (spec === '' || spec === 'all')
            ? true
            : spec.split(',').map(function (s) { return s.trim(); }).indexOf(val) !== -1;
        el.style.display = allowed ? '' : 'none';
        if (allowed && el.closest('#accFieldsSec') && el.querySelector('input[type="checkbox"]')) {
            visibleFieldCount++;
        }
    });
    var fb = document.getElementById('accFieldCountBadge');
    if (fb) {
        fb.textContent = visibleFieldCount + ' টি ফিল্ড প্রযোজ্য';
    }
}
accLayoutToggle();

(function () {
    var hash = window.location.hash;
    if (hash) {
        var target = document.querySelector(hash);
        if (target && target.classList.contains('acc-panel') && target.style.display !== 'none') {
            target.classList.remove('collapsed');
            setTimeout(function () { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 80);
        }
    }
})();

function accFillGovtDefaults() {
    var d = <?php echo json_encode($govt_defaults, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    document.getElementById('accInstrNote').value = d.note_text || '';
    document.getElementById('accInstrHeading').value = d.heading || '';
    document.getElementById('accInstrRules').value = d.rules_text || '';
    document.getElementById('accInstrCarry').value = d.carry_text || '';
    document.getElementById('accInstrBarred').value = d.barred_text || '';
}
</script>
