<?php
// admin/promo_send_sms.php - Promotional Module > Send Custom SMS
// তিনটা ট্যাব (Students / Employees / Custom Group) থেকে ফিল্টার করে মোবাইল
// নাম্বার বের করে Mobile বক্সে বসানো হয়, তারপর SMS Body লিখে Send করলে
// send_sms() দিয়ে পাঠানো হয় এবং sms_logs টেবিলে লগ থাকে (SMS Details Report এ দেখা যাবে)

require_once __DIR__ . '/../config/sms.php'; // send_sms(), sms_apply_template()
require_once __DIR__ . '/../config/whatsapp.php'; // send_whatsapp_template(), send_whatsapp_text()
sms_ensure_schema($pdo);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS sms_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NULL,
        phone VARCHAR(20) NOT NULL,
        purpose VARCHAR(50) NOT NULL DEFAULT 'absent',
        message TEXT NULL,
        status ENUM('Sent','Failed') NOT NULL DEFAULT 'Failed',
        response TEXT NULL,
        sent_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
wa_ensure_schema($pdo); // sms_logs টেবিল তৈরি হওয়ার পর — channel কলাম এখান থেকেই self-heal হয়ে যোগ হয়

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS promo_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_name VARCHAR(150) NOT NULL,
        image_path VARCHAR(255) NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS promo_contacts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        mobile VARCHAR(20) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_group (group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

function pss_esc($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$pss_err = $_SESSION['pss_err'] ?? '';
$pss_msg = $_SESSION['pss_msg'] ?? '';
unset($_SESSION['pss_err'], $_SESSION['pss_msg']);

// -----------------------------------------------------------
// POST: SMS / WhatsApp পাঠানো
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_sms'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['pss_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=promo_send_sms');
        exit();
    }

    $channel     = ($_POST['channel'] ?? 'SMS') === 'WhatsApp' ? 'WhatsApp' : 'SMS';
    $mobile_raw  = trim($_POST['mobile'] ?? '');
    $redirect_qs = $_POST['redirect_qs'] ?? '';

    // মোবাইল নাম্বার পার্স (দুই চ্যানেলেই কমন)
    $parts = preg_split('/[,\r\n\s]+/', $mobile_raw, -1, PREG_SPLIT_NO_EMPTY);
    $numbers = [];
    foreach ($parts as $p) {
        $n = preg_replace('/[^0-9]/', '', $p);
        if (strlen($n) === 13 && str_starts_with($n, '880')) { $n = substr($n, 2); }
        if (strlen($n) === 11 && str_starts_with($n, '01')) { $numbers[$n] = true; }
    }
    $numbers = array_keys($numbers);

    if (!$numbers) {
        $_SESSION['pss_err'] = 'কোনো সঠিক মোবাইল নাম্বার পাওয়া যায়নি।';
        header('Location: index.php?page=promo_send_sms&' . $redirect_qs);
        exit();
    }

    $sent_by = $_SESSION['admin_user'] ?? 'admin';

    if ($channel === 'WhatsApp') {
        $tpl_name  = trim($_POST['wa_template'] ?? '');
        $tpl_lang  = trim($_POST['wa_lang'] ?? 'en_US') ?: 'en_US';
        $tpl_params_raw = trim($_POST['wa_params'] ?? '');
        $tpl_params = $tpl_params_raw === '' ? [] : array_map('trim', explode(',', $tpl_params_raw));

        if ($tpl_name === '') {
            $_SESSION['pss_err'] = 'WhatsApp পাঠাতে Template Name আবশ্যক (Meta-তে আগে থেকে approve করা থাকতে হবে)।';
        } else {
            $log_stmt = $pdo->prepare(
                "INSERT INTO sms_logs (student_id, phone, purpose, channel, message, status, response, sent_by) VALUES (NULL, ?, 'promo', 'WhatsApp', ?, ?, ?, ?)"
            );
            $log_message = "Template: {$tpl_name} ({$tpl_lang})" . ($tpl_params ? ' | Params: ' . implode(', ', $tpl_params) : '');
            $sent = 0; $failed = 0;
            foreach ($numbers as $num) {
                $res = send_whatsapp_template($pdo, $num, $tpl_name, $tpl_lang, $tpl_params);
                $log_stmt->execute([$num, $log_message, $res['success'] ? 'Sent' : 'Failed', $res['response'], $sent_by]);
                if ($res['success']) { $sent++; } else { $failed++; }
            }
            $_SESSION['pss_msg'] = "{$sent} টি নাম্বারে WhatsApp মেসেজ পাঠানো হয়েছে।" . ($failed > 0 ? " {$failed} টি ব্যর্থ হয়েছে।" : '');
            sec_log_activity($pdo, 'Promotional', 'Send', "{$sent} টি নাম্বারে WhatsApp মেসেজ পাঠানো হয়েছে (Template: {$tpl_name})" . ($failed > 0 ? ", {$failed} টি ব্যর্থ" : ''));
        }
    } else {
        $body = trim($_POST['sms_body'] ?? '');
        if ($body === '') {
            $_SESSION['pss_err'] = 'SMS Body আবশ্যক।';
        } else {
            $log_stmt = $pdo->prepare(
                "INSERT INTO sms_logs (student_id, phone, purpose, channel, message, status, response, sent_by) VALUES (NULL, ?, 'promo', 'SMS', ?, ?, ?, ?)"
            );
            $sent = 0; $failed = 0;
            foreach ($numbers as $num) {
                $res = send_sms($pdo, $num, $body);
                $log_stmt->execute([$num, $body, $res['success'] ? 'Sent' : 'Failed', $res['response'], $sent_by]);
                if ($res['success']) { $sent++; } else { $failed++; }
            }
            $_SESSION['pss_msg'] = "{$sent} টি নাম্বারে SMS পাঠানো হয়েছে।" . ($failed > 0 ? " {$failed} টি ব্যর্থ হয়েছে।" : '');
            sec_log_activity($pdo, 'Promotional', 'Send', "{$sent} টি নাম্বারে SMS পাঠানো হয়েছে" . ($failed > 0 ? ", {$failed} টি ব্যর্থ" : ''));
        }
    }

    header('Location: index.php?page=promo_send_sms&' . $redirect_qs);
    exit();
}

// -----------------------------------------------------------
// ফিল্টার অপশন লোড
// -----------------------------------------------------------
$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes ORDER BY rank ASC, class_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name FROM academic_sections ORDER BY section_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts ORDER BY shift_name ASC")->fetchAll();
$designations = $pdo->query("SELECT id, title FROM designations ORDER BY title ASC")->fetchAll();
$groups   = $pdo->query("SELECT id, group_name FROM promo_groups WHERE status = 'Active' ORDER BY group_name ASC")->fetchAll();
$blood_groups = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];

$active_tab = $_GET['tab'] ?? 'students';
$mobile_prefill = '';

// -- Students Info ট্যাব --
$s_session = $_GET['s_session'] ?? '';
$s_class   = $_GET['s_class'] ?? '';
$s_shift   = $_GET['s_shift'] ?? '';
$s_section = $_GET['s_section'] ?? '';
$s_gender  = $_GET['s_gender'] ?? '';
$s_blood   = $_GET['s_blood'] ?? '';
$s_sid     = trim($_GET['s_sid'] ?? '');

if ($active_tab === 'students' && isset($_GET['s_search'])) {
    $where = ["students.status = 'Active'"];
    $params = [];
    if ($s_session !== '') { $where[] = 'students.session_id = ?'; $params[] = (int) $s_session; }
    if ($s_class !== '')   { $where[] = 'students.class_id = ?';   $params[] = (int) $s_class; }
    if ($s_shift !== '')   { $where[] = 'students.shift_id = ?';   $params[] = (int) $s_shift; }
    if ($s_section !== '') { $where[] = 'students.section_id = ?'; $params[] = (int) $s_section; }
    if ($s_gender !== '')  { $where[] = 'students.gender = ?';     $params[] = $s_gender; }
    if ($s_blood !== '')   { $where[] = 'students.blood_group = ?'; $params[] = $s_blood; }
    if ($s_sid !== '')     { $where[] = '(students.student_id LIKE ? OR students.name_en LIKE ?)'; $params[] = "%$s_sid%"; $params[] = "%$s_sid%"; }
    branch_apply_filter($where, $params, 'students.branch_id');

    $sql = "SELECT DISTINCT COALESCE(NULLIF(students.mobile,''), students.guardian_phone) AS num FROM students WHERE " . implode(' AND ', $where) . " LIMIT 2000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $mobile_prefill = implode(', ', array_filter(array_column($stmt->fetchAll(), 'num')));
}

// -- Employees Info ট্যাব --
$e_type   = $_GET['e_type'] ?? '';
$e_desig  = $_GET['e_desig'] ?? '';
$e_gender = $_GET['e_gender'] ?? '';
$e_blood  = $_GET['e_blood'] ?? '';
$e_eid    = trim($_GET['e_eid'] ?? '');

if ($active_tab === 'employees' && isset($_GET['e_search'])) {
    $where = ["employees.status = 'Active'", "employees.mobile != ''"];
    $params = [];
    if ($e_type !== '')   { $where[] = 'employees.type = ?';            $params[] = $e_type; }
    if ($e_desig !== '')  { $where[] = 'employees.designation_id = ?';  $params[] = (int) $e_desig; }
    if ($e_gender !== '') { $where[] = 'employees.gender = ?';          $params[] = $e_gender; }
    if ($e_blood !== '')  { $where[] = 'employees.blood_group = ?';     $params[] = $e_blood; }
    if ($e_eid !== '')    { $where[] = '(employees.employee_id LIKE ? OR employees.name LIKE ?)'; $params[] = "%$e_eid%"; $params[] = "%$e_eid%"; }
    branch_apply_filter($where, $params, 'employees.branch_id');

    $sql = "SELECT DISTINCT mobile AS num FROM employees WHERE " . implode(' AND ', $where) . " LIMIT 2000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $mobile_prefill = implode(', ', array_filter(array_column($stmt->fetchAll(), 'num')));
}

// -- Custom Group ট্যাব --
$c_group = $_GET['c_group'] ?? '';
if ($active_tab === 'custom' && isset($_GET['c_search'])) {
    if ($c_group !== '') {
        $stmt = $pdo->prepare("SELECT DISTINCT mobile AS num FROM promo_contacts WHERE group_id = ? AND status = 'Active'");
        $stmt->execute([(int) $c_group]);
        $mobile_prefill = implode(', ', array_filter(array_column($stmt->fetchAll(), 'num')));
    }
}

$csrf = htmlspecialchars(csrf_token());
$redirect_qs = pss_esc(http_build_query($_GET));
?>

<div class="card-custom mb-3">
    <?php if ($pss_msg): ?><div class="alert alert-success py-2"><?php echo pss_esc($pss_msg); ?></div><?php endif; ?>
    <?php if ($pss_err): ?><div class="alert alert-danger py-2"><?php echo pss_esc($pss_err); ?></div><?php endif; ?>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'students' ? 'active fw-bold' : ''; ?>" href="index.php?page=promo_send_sms&tab=students"><i class="fa-solid fa-book me-1"></i> Students Information</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'employees' ? 'active fw-bold' : ''; ?>" href="index.php?page=promo_send_sms&tab=employees"><i class="fa-solid fa-user-tie me-1"></i> Employees Information</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'custom' ? 'active fw-bold' : ''; ?>" href="index.php?page=promo_send_sms&tab=custom"><i class="fa-solid fa-users me-1"></i> Custom Group Information</a>
        </li>
    </ul>

    <?php if ($active_tab === 'students'): ?>
        <form method="GET" action="index.php" class="mb-3">
            <input type="hidden" name="page" value="promo_send_sms">
            <input type="hidden" name="tab" value="students">
            <input type="hidden" name="s_search" value="1">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Session</label>
                    <select name="s_session" class="form-select">
                        <option value="">Select Session</option>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo (string) $s_session === (string) $s['id'] ? 'selected' : ''; ?>><?php echo pss_esc($s['session_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Class</label>
                    <select name="s_class" class="form-select">
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>" <?php echo (string) $s_class === (string) $c['id'] ? 'selected' : ''; ?>><?php echo pss_esc($c['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Shift</label>
                    <select name="s_shift" class="form-select">
                        <option value="">Select Shift</option>
                        <?php foreach ($shifts as $sh): ?>
                            <option value="<?php echo (int) $sh['id']; ?>" <?php echo (string) $s_shift === (string) $sh['id'] ? 'selected' : ''; ?>><?php echo pss_esc($sh['shift_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Section</label>
                    <select name="s_section" class="form-select">
                        <option value="">Select Section</option>
                        <?php foreach ($sections as $sec): ?>
                            <option value="<?php echo (int) $sec['id']; ?>" <?php echo (string) $s_section === (string) $sec['id'] ? 'selected' : ''; ?>><?php echo pss_esc($sec['section_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Gender</label>
                    <select name="s_gender" class="form-select">
                        <option value="">Select Gender</option>
                        <option value="Male" <?php echo $s_gender === 'Male' ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo $s_gender === 'Female' ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Blood Group</label>
                    <select name="s_blood" class="form-select">
                        <option value="">Select Blood Group</option>
                        <?php foreach ($blood_groups as $bg): ?>
                            <option value="<?php echo $bg; ?>" <?php echo $s_blood === $bg ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Student ID</label>
                    <input type="text" name="s_sid" class="form-control" value="<?php echo pss_esc($s_sid); ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 fw-bold" style="background-color:#1e3a8a;">Load Mobile Numbers</button>
                </div>
            </div>
        </form>

    <?php elseif ($active_tab === 'employees'): ?>
        <form method="GET" action="index.php" class="mb-3">
            <input type="hidden" name="page" value="promo_send_sms">
            <input type="hidden" name="tab" value="employees">
            <input type="hidden" name="e_search" value="1">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Type</label>
                    <select name="e_type" class="form-select">
                        <option value="">Select Type</option>
                        <?php foreach (['Teachers', 'Staffs', 'Board Of Directors', 'Academic Committee'] as $t): ?>
                            <option value="<?php echo $t; ?>" <?php echo $e_type === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Designation</label>
                    <select name="e_desig" class="form-select">
                        <option value="">Select Designation</option>
                        <?php foreach ($designations as $d): ?>
                            <option value="<?php echo (int) $d['id']; ?>" <?php echo (string) $e_desig === (string) $d['id'] ? 'selected' : ''; ?>><?php echo pss_esc($d['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Gender</label>
                    <select name="e_gender" class="form-select">
                        <option value="">Select Gender</option>
                        <option value="Male" <?php echo $e_gender === 'Male' ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo $e_gender === 'Female' ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Blood Group</label>
                    <select name="e_blood" class="form-select">
                        <option value="">Select Blood Group</option>
                        <?php foreach ($blood_groups as $bg): ?>
                            <option value="<?php echo $bg; ?>" <?php echo $e_blood === $bg ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Employee ID / Name</label>
                    <input type="text" name="e_eid" class="form-control" value="<?php echo pss_esc($e_eid); ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 fw-bold" style="background-color:#1e3a8a;">Load Mobile Numbers</button>
                </div>
            </div>
        </form>

    <?php else: ?>
        <form method="GET" action="index.php" class="mb-3">
            <input type="hidden" name="page" value="promo_send_sms">
            <input type="hidden" name="tab" value="custom">
            <input type="hidden" name="c_search" value="1">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Custom Group</label>
                    <select name="c_group" class="form-select">
                        <option value="">Select Group</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?php echo (int) $g['id']; ?>" <?php echo (string) $c_group === (string) $g['id'] ? 'selected' : ''; ?>><?php echo pss_esc($g['group_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 fw-bold" style="background-color:#1e3a8a;">Load Mobile Numbers</button>
                </div>
                <?php if (!$groups): ?>
                    <div class="col-12"><small class="text-muted fst-italic">এখনো কোনো Group নাই — <a href="index.php?page=promo_groups&view=add">প্রথমে একটা Group তৈরি করুন</a>।</small></div>
                <?php endif; ?>
            </div>
        </form>
    <?php endif; ?>

    <hr>

    <form method="POST" action="index.php?page=promo_send_sms" id="promoSendSmsForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="redirect_qs" value="<?php echo $redirect_qs; ?>">

        <div class="mb-3">
            <label class="form-label fw-bold d-block">Send Via</label>
            <div class="btn-group" role="group">
                <input type="radio" class="btn-check" name="channel" id="chSms" value="SMS" checked>
                <label class="btn btn-outline-primary" for="chSms"><i class="fa-solid fa-comment-sms me-1"></i> SMS</label>

                <input type="radio" class="btn-check" name="channel" id="chWa" value="WhatsApp">
                <label class="btn btn-outline-success" for="chWa"><i class="fa-brands fa-whatsapp me-1"></i> WhatsApp</label>
            </div>
            <?php if (!wa_get_gateway_settings($pdo)['enabled']): ?>
                <div class="small text-muted mt-1">WhatsApp পাঠাতে আগে <a href="index.php?page=whatsapp_settings">WhatsApp API Settings</a> থেকে কনফিগার ও Enable করে নিন।</div>
            <?php endif; ?>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-bold">Mobile <span class="text-danger">*</span></label>
                <textarea name="mobile" id="pssMobile" class="form-control" rows="8" required placeholder="01717xxxxxx, 01625xxxxxx"><?php echo pss_esc($mobile_prefill); ?></textarea>
            </div>
            <div class="col-md-6">
                <div id="pssSmsFields">
                    <label class="form-label fw-bold">SMS Body <span class="text-danger">*</span></label>
                    <textarea name="sms_body" id="pssBody" class="form-control" rows="8"></textarea>
                    <div class="small text-danger mt-1" id="pssCounter">Character Left : 1071 | Character Count : 0 | SMS Length : 1</div>
                    <small class="text-muted fst-italic">(160 ASCII / 70 UNICODE) Characters = 1 SMS. You can type maximum 1071 ASCII/UNICODE characters.</small>
                </div>
                <div id="pssWaFields" style="display:none;">
                    <label class="form-label fw-bold">Template Name <span class="text-danger">*</span></label>
                    <input type="text" name="wa_template" class="form-control mb-2" placeholder="Meta-তে approve করা Template-এর নাম, যেমন: fee_reminder">
                    <label class="form-label fw-bold">Language Code</label>
                    <input type="text" name="wa_lang" class="form-control mb-2" value="en_US" placeholder="en_US">
                    <label class="form-label fw-bold">Body Variables (ঐচ্ছিক)</label>
                    <input type="text" name="wa_params" class="form-control" placeholder="{{1}}, {{2}} ... এর মান কমা দিয়ে, ক্রম অনুযায়ী">
                    <small class="text-muted fst-italic">Template-এ কোনো ভ্যারিয়েবল {{1}}, {{2}} না থাকলে এটা ফাঁকা রাখুন।</small>
                </div>
            </div>
        </div>
        <div class="mt-3">
            <button type="submit" name="send_sms" class="btn btn-success px-4 fw-bold" onclick="return confirm('পাঠাতে চান, নিশ্চিত?');"><i class="fa-solid fa-paper-plane me-1"></i> Send</button>
        </div>
    </form>
</div>

<script>
(function() {
    var body = document.getElementById('pssBody');
    var counter = document.getElementById('pssCounter');
    function update() {
        var text = body.value;
        var isUnicode = /[^\x00-\x7F]/.test(text);
        var singleLimit = isUnicode ? 70 : 160;
        var multiLimit = isUnicode ? 67 : 153;
        var maxChars = multiLimit * 7;
        var len = text.length;
        var smsLength = len === 0 ? 0 : (len <= singleLimit ? 1 : Math.ceil(len / multiLimit));
        var left = maxChars - len;
        counter.textContent = 'Character Left : ' + left + '  |  Character Count : ' + len + '  |  SMS Length : ' + smsLength;
    }
    if (body) { body.addEventListener('input', update); update(); }

    // চ্যানেল টগল: SMS হলে SMS Body বক্স, WhatsApp হলে Template বক্স দেখাবে
    var chSms = document.getElementById('chSms');
    var chWa = document.getElementById('chWa');
    var smsFields = document.getElementById('pssSmsFields');
    var waFields = document.getElementById('pssWaFields');
    var smsBody = document.getElementById('pssBody');
    var waTemplate = document.querySelector('input[name="wa_template"]');
    function toggleChannel() {
        var isWa = chWa.checked;
        smsFields.style.display = isWa ? 'none' : 'block';
        waFields.style.display = isWa ? 'block' : 'none';
        smsBody.required = !isWa;
        waTemplate.required = isWa;
    }
    if (chSms && chWa) {
        chSms.addEventListener('change', toggleChannel);
        chWa.addEventListener('change', toggleChannel);
        toggleChannel();
    }
})();
</script>
