<?php
// admin/hr_employee_id_card.php - Human Resource > Employees ID Card (স্ক্রিনশট অনুযায়ী)
// এখানেই Settings প্যানেল আছে: লোগো/ছবি/QR কোড/স্কুলের নাম কোথায় বসবে, কাস্টম টেমপ্লেট আপলোড ইত্যাদি।

function idc_esc($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS id_card_settings (
        id INT PRIMARY KEY DEFAULT 1,
        show_logo TINYINT(1) NOT NULL DEFAULT 1,
        logo_position ENUM('Left','Right') NOT NULL DEFAULT 'Right',
        show_photo TINYINT(1) NOT NULL DEFAULT 1,
        show_qr TINYINT(1) NOT NULL DEFAULT 0,
        qr_side ENUM('Front','Back') NOT NULL DEFAULT 'Back',
        show_school_name_front TINYINT(1) NOT NULL DEFAULT 1,
        show_school_name_back TINYINT(1) NOT NULL DEFAULT 1,
        front_template_path VARCHAR(255) NULL,
        back_template_path VARCHAR(255) NULL,
        accent_color VARCHAR(10) NOT NULL DEFAULT '#1e3a8a',
        signature_label VARCHAR(100) NOT NULL DEFAULT 'Signature of Headmaster',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
if ((int) $pdo->query("SELECT COUNT(*) FROM id_card_settings")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO id_card_settings (id) VALUES (1)");
}

$idc_msg = $_SESSION['idc_msg'] ?? '';
$idc_err = $_SESSION['idc_err'] ?? '';
unset($_SESSION['idc_msg'], $_SESSION['idc_err']);

// -----------------------------------------------------------
// টেমপ্লেট ইমেজ আপলোড হেল্পার
// -----------------------------------------------------------
function idc_handle_template_upload(string $field): array
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'error' => null];
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) { return ['ok' => false, 'path' => null, 'error' => 'ছবি আপলোডে সমস্যা হয়েছে।']; }
    if ($file['size'] > 1024 * 1024) { return ['ok' => false, 'path' => null, 'error' => 'ছবির সাইজ সর্বোচ্চ 1 MB হতে হবে।']; }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    if (!isset($allowed[$ext])) { return ['ok' => false, 'path' => null, 'error' => 'শুধু jpg/png ব্যবহার করুন।']; }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== $allowed[$ext]) { return ['ok' => false, 'path' => null, 'error' => 'ছবির কনটেন্ট এক্সটেনশনের সাথে মিলছে না।']; }
    $dir = tenant_upload_abs(__DIR__ . '/..', 'id_card_templates');
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) { return ['ok' => false, 'path' => null, 'error' => 'ছবি সেভ করা যায়নি।']; }
    return ['ok' => true, 'path' => tenant_upload_rel('id_card_templates') . '/' . $name, 'error' => null];
}

// -----------------------------------------------------------
// Settings সেভ হ্যান্ডলার
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_id_card_settings'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['idc_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $front = idc_handle_template_upload('front_template');
        $back  = idc_handle_template_upload('back_template');
        if (!$front['ok'] || !$back['ok']) {
            $_SESSION['idc_err'] = $front['error'] ?: $back['error'];
        } else {
            $set = [
                'show_logo' => isset($_POST['show_logo']) ? 1 : 0,
                'logo_position' => ($_POST['logo_position'] ?? 'Right') === 'Left' ? 'Left' : 'Right',
                'show_photo' => isset($_POST['show_photo']) ? 1 : 0,
                'show_qr' => isset($_POST['show_qr']) ? 1 : 0,
                'qr_side' => ($_POST['qr_side'] ?? 'Back') === 'Front' ? 'Front' : 'Back',
                'show_school_name_front' => isset($_POST['show_school_name_front']) ? 1 : 0,
                'show_school_name_back' => isset($_POST['show_school_name_back']) ? 1 : 0,
                'accent_color' => trim($_POST['accent_color'] ?? '#1e3a8a'),
                'signature_label' => trim($_POST['signature_label'] ?? 'Signature of Headmaster'),
            ];
            if ($front['path']) { $set['front_template_path'] = $front['path']; }
            if ($back['path'])  { $set['back_template_path'] = $back['path']; }
            if (isset($_POST['remove_front_template'])) { $set['front_template_path'] = null; }
            if (isset($_POST['remove_back_template']))  { $set['back_template_path'] = null; }

            $set_sql = implode(', ', array_map(fn($k) => "$k = ?", array_keys($set)));
            $pdo->prepare("UPDATE id_card_settings SET $set_sql WHERE id = 1")->execute(array_values($set));
            $_SESSION['idc_msg'] = 'ID Card Settings সেভ করা হয়েছে।';
            sec_log_activity($pdo, 'HR', 'Update', 'Employee ID Card Settings আপডেট করা হয়েছে');
        }
    }
    header('Location: index.php?page=hr_employee_id_card&tab=settings');
    exit();
}

$settings = $pdo->query("SELECT * FROM id_card_settings WHERE id = 1")->fetch();

// -----------------------------------------------------------
// Search ফিল্টার
// -----------------------------------------------------------
$employee_form_types = ['Teachers', 'Staffs', 'Board Of Directors', 'Academic Committee'];
$shifts = $pdo->query("SELECT id, name FROM employee_shifts WHERE status = 'Active' ORDER BY name ASC")->fetchAll();

$f_type  = $_GET['f_type'] ?? 'ALL';
$f_shift = (int) ($_GET['f_shift'] ?? 0);
$f_emp   = (int) ($_GET['f_emp'] ?? 0);
$searched = isset($_GET['search']);

$emp_where = ["e.is_current = 1", "e.status = 'Active'"];
$emp_params = [];
if ($f_type !== 'ALL') { $emp_where[] = 'e.type = ?'; $emp_params[] = $f_type; }
if ($f_shift > 0) { $emp_where[] = 'e.shift_id = ?'; $emp_params[] = $f_shift; }
branch_apply_filter($emp_where, $emp_params, 'e.branch_id');
$emp_list_stmt = $pdo->prepare("SELECT id, name, employee_id FROM employees e WHERE " . implode(' AND ', $emp_where) . " ORDER BY e.name ASC");
$emp_list_stmt->execute($emp_params);
$employee_options = $emp_list_stmt->fetchAll();

$cards = [];
if ($searched) {
    $where = $emp_where;
    $params = $emp_params;
    if ($f_emp > 0) { $where[] = 'e.id = ?'; $params[] = $f_emp; }

    $sql = "SELECT e.*, dg.title AS designation_title
            FROM employees e LEFT JOIN designations dg ON dg.id = e.designation_id
            WHERE " . implode(' AND ', $where) . " ORDER BY e.name ASC LIMIT 60";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cards = $stmt->fetchAll();
}

$branch = $pdo->query("SELECT * FROM branches ORDER BY id ASC LIMIT 1")->fetch();
$institute = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
$logo_path = !empty($institute['logo_path']) ? '../' . idc_esc($institute['logo_path']) : '';
?>
<style>
    .idc-card { width: 320px; border: 1px solid #94a3b8; border-radius: 6px; overflow: hidden; background: #fff; font-size: 11px; position: relative; }
    .idc-card-front-header { text-align: center; padding: 6px 0 2px; }
    .idc-card-front-header .idc-title { color: #16a34a; font-weight: 700; text-decoration: underline; font-size: 12px; }
    .idc-photo-logo-row { display: flex; justify-content: space-between; align-items: flex-start; padding: 4px 10px; }
    .idc-photo { width: 70px; height: 80px; object-fit: cover; border: 1px solid #cbd5e1; }
    .idc-logo { width: 55px; height: 55px; object-fit: contain; }
    .idc-name { text-align: center; color: #16a34a; font-weight: 700; font-size: 13px; margin-top: 4px; }
    .idc-info { padding: 0 12px; }
    .idc-info div { margin-bottom: 2px; }
    .idc-banner { color: #fff; text-align: center; font-weight: 700; padding: 4px; margin-top: 6px; font-size: 11px; }
    .idc-sig { text-align: center; padding: 4px; font-size: 10px; border-top: 1px solid #e2e8f0; }
    .idc-back { padding: 8px 12px; text-align: center; }
    .idc-back .idc-underline { text-decoration: underline; font-weight: 700; }
    .idc-back-logo { width: 45px; height: 45px; object-fit: contain; margin: 4px auto; display: block; }
    .idc-qr { position: absolute; bottom: 6px; right: 6px; width: 50px; height: 50px; }
    .idc-pair { display: inline-block; vertical-align: top; margin: 0 12px 20px 0; }
    @media print {
        .no-print { display: none !important; }
        .idc-pair { page-break-inside: avoid; }
    }
</style>

<!-- ============ Settings Panel ============ -->
<!-- ============ Tab Buttons (no page reload, pure JS toggle) ============ -->
<?php $active_tab = ($_GET['tab'] ?? ($searched ? 'idcard' : 'idcard')) === 'settings' ? 'settings' : 'idcard'; ?>
<div class="d-flex gap-2 mb-3 no-print">
    <button type="button" id="tabBtnSettings" class="btn fw-bold px-4" onclick="showTab('settings')">
        <i class="fa-solid fa-gear"></i> Settings
    </button>
    <button type="button" id="tabBtnIdCard" class="btn fw-bold px-4" onclick="showTab('idcard')">
        <i class="fa-solid fa-id-card"></i> ID Card
    </button>
</div>

<!-- ============ Settings Panel ============ -->
<div class="card-custom no-print" id="settingsPanel" style="display:none;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold text-dark m-0"><i class="fa-solid fa-gear"></i> ID Card Settings</h6>
    </div>
    <?php if ($idc_msg): ?><div class="alert alert-success py-2"><?php echo idc_esc($idc_msg); ?></div><?php endif; ?>
    <?php if ($idc_err): ?><div class="alert alert-danger py-2"><?php echo idc_esc($idc_err); ?></div><?php endif; ?>

    <form method="POST" action="index.php?page=hr_employee_id_card" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="show_logo" id="showLogo" <?php echo $settings['show_logo'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="showLogo">Show Logo</label>
                </div>
                <select name="logo_position" class="form-select form-select-sm mt-1">
                    <option value="Left" <?php echo $settings['logo_position'] === 'Left' ? 'selected' : ''; ?>>Logo বসবে বামে</option>
                    <option value="Right" <?php echo $settings['logo_position'] === 'Right' ? 'selected' : ''; ?>>Logo বসবে ডানে</option>
                </select>
            </div>
            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="show_photo" id="showPhoto" <?php echo $settings['show_photo'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="showPhoto">Show Employee Photo</label>
                </div>
                <small class="text-muted">ছবি সবসময় বামে (স্ট্যান্ডার্ড ID card লেআউট)</small>
            </div>
            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="show_qr" id="showQr" <?php echo $settings['show_qr'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="showQr">Show QR Code</label>
                </div>
                <select name="qr_side" class="form-select form-select-sm mt-1">
                    <option value="Front" <?php echo $settings['qr_side'] === 'Front' ? 'selected' : ''; ?>>QR বসবে সামনে</option>
                    <option value="Back" <?php echo $settings['qr_side'] === 'Back' ? 'selected' : ''; ?>>QR বসবে পেছনে</option>
                </select>
            </div>

            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="show_school_name_front" id="ssnf" <?php echo $settings['show_school_name_front'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="ssnf">সামনে স্কুলের নাম দেখাও (ব্যানারে)</label>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="show_school_name_back" id="ssnb" <?php echo $settings['show_school_name_back'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="ssnb">পেছনে স্কুলের নাম দেখাও</label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold mb-1">Card Accent Color</label>
                <input type="color" name="accent_color" class="form-control form-control-color" value="<?php echo idc_esc($settings['accent_color']); ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label small fw-bold mb-1">Signature Label</label>
                <input type="text" name="signature_label" class="form-control" value="<?php echo idc_esc($settings['signature_label']); ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label small fw-bold mb-1">Front Template (কাস্টম ব্যাকগ্রাউন্ড, ঐচ্ছিক)</label>
                <input type="file" name="front_template" class="form-control" accept=".jpg,.jpeg,.png">
                <?php if (!empty($settings['front_template_path'])): ?>
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="checkbox" name="remove_front_template" id="rmFront">
                        <label class="form-check-label small" for="rmFront">বর্তমান Front Template সরিয়ে দাও (<a href="../<?php echo idc_esc($settings['front_template_path']); ?>" target="_blank">দেখুন</a>)</label>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold mb-1">Back Template (কাস্টম ব্যাকগ্রাউন্ড, ঐচ্ছিক)</label>
                <input type="file" name="back_template" class="form-control" accept=".jpg,.jpeg,.png">
                <?php if (!empty($settings['back_template_path'])): ?>
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="checkbox" name="remove_back_template" id="rmBack">
                        <label class="form-check-label small" for="rmBack">বর্তমান Back Template সরিয়ে দাও (<a href="../<?php echo idc_esc($settings['back_template_path']); ?>" target="_blank">দেখুন</a>)</label>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-3">
            <button type="submit" name="save_id_card_settings" class="btn btn-primary fw-bold px-4" style="background-color:#1e3a8a;">Save Settings</button>
        </div>
    </form>
</div>

<!-- ============ ID Card Panel (Search + Results) ============ -->
<div id="idCardPanel">

<!-- ============ Search Panel ============ -->
<div class="card-custom no-print">
    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Employees ID Card</h6>
    <form method="GET" action="index.php">
        <input type="hidden" name="page" value="hr_employee_id_card">
        <input type="hidden" name="search" value="1">

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label text-end">Employee Type <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="f_type" class="form-select">
                    <option value="ALL" <?php echo $f_type === 'ALL' ? 'selected' : ''; ?>>ALL</option>
                    <?php foreach ($employee_form_types as $t): ?>
                        <option value="<?php echo idc_esc($t); ?>" <?php echo $f_type === $t ? 'selected' : ''; ?>><?php echo idc_esc($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label text-end">Shift</label>
            <div class="col-sm-6">
                <select name="f_shift" class="form-select">
                    <option value="0">Select Shift</option>
                    <?php foreach ($shifts as $sh): ?>
                        <option value="<?php echo (int) $sh['id']; ?>" <?php echo $f_shift === (int) $sh['id'] ? 'selected' : ''; ?>><?php echo idc_esc($sh['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-4 align-items-center">
            <label class="col-sm-3 col-form-label text-end">Employee</label>
            <div class="col-sm-6">
                <select name="f_emp" class="form-select">
                    <option value="0">Select Employee (খালি রাখলে সবগুলো কার্ড দেখাবে)</option>
                    <?php foreach ($employee_options as $eo): ?>
                        <option value="<?php echo (int) $eo['id']; ?>" <?php echo $f_emp === (int) $eo['id'] ? 'selected' : ''; ?>><?php echo idc_esc($eo['employee_id'] . ' - ' . $eo['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row">
            <div class="col-sm-6 offset-sm-3 text-end">
                <button type="submit" class="btn btn-primary fw-bold px-4" style="background-color:#1e3a8a;">Search</button>
            </div>
        </div>
    </form>
</div>

<?php if ($searched): ?>
    <div class="d-flex justify-content-end mb-2 no-print">
        <button type="button" class="btn btn-primary fw-bold" style="background-color:#1e3a8a;" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Print
        </button>
    </div>

    <div class="card-custom">
        <?php if (!$cards): ?>
            <div class="text-muted text-center py-4">এই ফিল্টারে কোনো Employee পাওয়া যায়নি।</div>
        <?php endif; ?>
        <?php foreach ($cards as $emp): ?>
            <div class="idc-pair">
                <!-- FRONT -->
                <div class="idc-card" style="<?php echo $settings['front_template_path'] ? "background-image:url('../" . idc_esc($settings['front_template_path']) . "');background-size:cover;" : ''; ?>">
                    <div class="idc-card-front-header"><span class="idc-title">Identity Card</span></div>
                    <div class="idc-photo-logo-row" style="<?php echo $settings['logo_position'] === 'Left' ? 'flex-direction:row-reverse;' : ''; ?>">
                        <?php if ($settings['show_photo']): ?>
                            <img class="idc-photo" src="<?php echo $emp['photo_path'] ? '../' . idc_esc($emp['photo_path']) : 'data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2728%27 height=%2728%27%3E%3Crect width=%2728%27 height=%2728%27 fill=%27%23e2e8f0%27/%3E%3C/svg%3E'; ?>">
                        <?php else: ?><div></div><?php endif; ?>
                        <?php if ($settings['show_logo'] && $logo_path): ?><img class="idc-logo" src="<?php echo $logo_path; ?>"><?php endif; ?>
                    </div>
                    <div class="idc-name"><?php echo idc_esc($emp['name']); ?></div>
                    <div class="idc-info text-center">
                        <div><?php echo idc_esc($emp['designation_title'] ?: ''); ?></div>
                    </div>
                    <div class="idc-info mt-1">
                        <div><b>ID No</b> : <?php echo idc_esc($emp['employee_id']); ?></div>
                        <?php if (!empty($emp['major_subject'])): ?><div><b>Subject</b> : <?php echo idc_esc($emp['major_subject']); ?></div><?php endif; ?>
                        <div><b>Blood Group</b> : <?php echo idc_esc($emp['blood_group'] ?: '-'); ?></div>
                    </div>
                    <?php if ($settings['show_school_name_front']): ?>
                        <div class="idc-banner" style="background-color: <?php echo idc_esc($settings['accent_color']); ?>;">
                            <?php echo idc_esc($branch['branch_name'] ?? ''); ?><br>
                            <span style="font-weight:400;font-size:10px;"><?php echo idc_esc(trim(($branch['city'] ?? '') . ' (' . ($branch['branch_name'] ?? '') . ')')); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="idc-sig"><?php echo idc_esc($settings['signature_label']); ?></div>
                    <?php if ($settings['show_qr'] && $settings['qr_side'] === 'Front'): ?>
                        <div class="idc-qr" id="qr-front-<?php echo (int) $emp['id']; ?>"></div>
                    <?php endif; ?>
                </div>

                <!-- BACK -->
                <div class="idc-card mt-2" style="<?php echo $settings['back_template_path'] ? "background-image:url('../" . idc_esc($settings['back_template_path']) . "');background-size:cover;" : ''; ?>">
                    <div class="idc-back">
                        <div class="fw-bold">Personal Information :</div>
                        <div>Joining Date : <?php echo $emp['joining_date'] ? idc_esc(date('d-M-Y', strtotime($emp['joining_date']))) : '-'; ?></div>
                        <div>Contact : <?php echo idc_esc($emp['mobile']); ?></div>
                        <div class="idc-underline mt-1">Permanent Address :</div>
                        <div><?php echo nl2br(idc_esc($emp['permanent_address'] ?: '-')); ?></div>

                        <?php if ($settings['show_logo'] && $logo_path): ?><img class="idc-back-logo" src="<?php echo $logo_path; ?>"><?php endif; ?>
                        <div class="idc-underline">In case of lost please inform or return it to</div>

                        <?php if ($settings['show_school_name_back']): ?>
                            <div class="fw-bold mt-1"><?php echo idc_esc($branch['branch_name'] ?? ''); ?></div>
                        <?php endif; ?>
                        <div><?php echo idc_esc($branch['address'] ?? ''); ?></div>
                        <div>Phone : <?php echo idc_esc($branch['phone'] ?? ''); ?></div>
                        <div>Email : <?php echo idc_esc($institute['email'] ?? ''); ?></div>
                        <?php if (!empty($institute['website'])): ?><div>Web : <?php echo idc_esc($institute['website']); ?></div><?php endif; ?>
                    </div>
                    <?php if ($settings['show_qr'] && $settings['qr_side'] === 'Back'): ?>
                        <div class="idc-qr" id="qr-back-<?php echo (int) $emp['id']; ?>"></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($settings['show_qr']): ?>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
            <?php foreach ($cards as $emp): ?>
                (function() {
                    var el = document.getElementById('qr-<?php echo $settings['qr_side'] === 'Front' ? 'front' : 'back'; ?>-<?php echo (int) $emp['id']; ?>');
                    if (el && window.QRCode) {
                        new QRCode(el, { text: <?php echo json_encode((string) $emp['employee_id']); ?>, width: 50, height: 50 });
                    }
                })();
            <?php endforeach; ?>
        </script>
    <?php endif; ?>
<?php endif; ?>

</div><!-- /#idCardPanel -->

<style>
    #tabBtnSettings, #tabBtnIdCard { background: #e2e8f0; color: #334155; border: none; }
    #tabBtnSettings.active, #tabBtnIdCard.active { background-color: #1e3a8a; color: #fff; }
</style>
<script>
    function showTab(tab) {
        const settingsPanel = document.getElementById('settingsPanel');
        const idCardPanel = document.getElementById('idCardPanel');
        const btnSettings = document.getElementById('tabBtnSettings');
        const btnIdCard = document.getElementById('tabBtnIdCard');
        if (tab === 'settings') {
            settingsPanel.style.display = '';
            idCardPanel.style.display = 'none';
            btnSettings.classList.add('active');
            btnIdCard.classList.remove('active');
        } else {
            settingsPanel.style.display = 'none';
            idCardPanel.style.display = '';
            btnIdCard.classList.add('active');
            btnSettings.classList.remove('active');
        }
    }
    // পেজ লোড হওয়ার সাথে সাথে সঠিক ট্যাব দেখানো (রিলোড ছাড়া টগল হয় বলে এটা শুধু initial state ঠিক করার জন্য)
    showTab('<?php echo $active_tab; ?>');
</script>

