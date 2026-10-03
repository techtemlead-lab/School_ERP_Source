<?php
require_once __DIR__ . '/_guard.php';

$error = '';
$success = '';
$activeTab = $_GET['tab'] ?? 'branding';
if (!in_array($activeTab, ['branding', 'theme', 'sms', 'security'], true)) $activeTab = 'branding';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করো।';
    } else {
        $action = $_POST['action'] ?? '';
        ensureSaasSettingsTable($pdo_master);

        // ── ব্র্যান্ডিং সেভ (Panel নাম) ──
        if ($action === 'save_branding') {
            $activeTab = 'branding';
            $panelName = trim($_POST['panel_name'] ?? '');
            if ($panelName !== '') {
                $pdo_master->prepare("INSERT INTO saas_settings (setting_key, setting_value) VALUES ('panel_name', ?)
                    ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$panelName]);
            }

            $uploadDir = __DIR__ . '/assets/uploads/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

            // লোগো আপলোড
            if (isset($_FILES['panel_logo']) && $_FILES['panel_logo']['error'] === 0) {
                $ext = strtolower(pathinfo($_FILES['panel_logo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'svg', 'webp'], true)) {
                    $fname = 'panel_logo_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['panel_logo']['tmp_name'], $uploadDir . $fname)) {
                        $pdo_master->prepare("INSERT INTO saas_settings (setting_key, setting_value) VALUES ('panel_logo', ?)
                            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$fname]);
                    }
                } else {
                    $error = 'লোগোর জন্য শুধু jpg, jpeg, png, svg, webp ফরম্যাট চলবে।';
                }
            }

            // ফেভিকন আপলোড
            if (isset($_FILES['panel_favicon']) && $_FILES['panel_favicon']['error'] === 0) {
                $ext = strtolower(pathinfo($_FILES['panel_favicon']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['ico', 'png', 'svg', 'webp'], true)) {
                    $fname = 'panel_favicon_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['panel_favicon']['tmp_name'], $uploadDir . $fname)) {
                        $pdo_master->prepare("INSERT INTO saas_settings (setting_key, setting_value) VALUES ('panel_favicon', ?)
                            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$fname]);
                    }
                } else {
                    $error = 'ফেভিকনের জন্য শুধু ico, png, svg, webp ফরম্যাট চলবে।';
                }
            }

            if ($error === '') $success = 'ব্র্যান্ডিং সেভ হয়েছে।';
        }

        // ── ব্র্যান্ডিং রিমুভ (লোগো/ফেভিকন) ──
        if ($action === 'remove_branding') {
            $activeTab = 'branding';
            $which = $_POST['remove_key'] ?? '';
            if (in_array($which, ['panel_logo', 'panel_favicon'], true)) {
                $old = $pdo_master->prepare("SELECT setting_value FROM saas_settings WHERE setting_key=?");
                $old->execute([$which]);
                $oldFile = $old->fetchColumn();
                if ($oldFile) @unlink(__DIR__ . '/assets/uploads/' . $oldFile);
                $pdo_master->prepare("DELETE FROM saas_settings WHERE setting_key=?")->execute([$which]);
                $success = 'রিমুভ করা হয়েছে।';
            }
        }

        // ── থিম সেভ ──
        if ($action === 'save_theme') {
            $activeTab = 'theme';
            saveThemeSettings($pdo_master, [
                'sidebar_bg'          => $_POST['sidebar_bg']          ?? '',
                'sidebar_active_bg'   => $_POST['sidebar_active_bg']   ?? '',
                'sidebar_active_text' => $_POST['sidebar_active_text'] ?? '',
                'sidebar_accent'      => $_POST['sidebar_accent']      ?? '',
                'brand_text'          => $_POST['brand_text']          ?? '',
                'primary_color'       => $_POST['primary_color']       ?? '',
                'page_bg'             => $_POST['page_bg']             ?? '',
                'card_bg'             => $_POST['card_bg']             ?? '',
                'body_text'           => $_POST['body_text']           ?? '',
                'font_family'         => $_POST['font_family']         ?? '',
                'font_size_base'      => $_POST['font_size_base']      ?? '',
                'border_radius'       => $_POST['border_radius']       ?? '',
                'badge_active'        => $_POST['badge_active']        ?? '',
                'badge_trial'         => $_POST['badge_trial']         ?? '',
                'badge_suspended'     => $_POST['badge_suspended']     ?? '',
                'badge_blocked'       => $_POST['badge_blocked']       ?? '',
                'badge_cancelled'     => $_POST['badge_cancelled']     ?? '',
                'footer_enabled'      => isset($_POST['footer_enabled']) ? '1' : '0',
                'footer_credit_text'  => $_POST['footer_credit_text']  ?? '',
                'footer_credit_url'   => $_POST['footer_credit_url']   ?? '',
            ]);
            $success = 'থিম সেভ হয়েছে।';
        }

        // ── থিম রিসেট ──
        if ($action === 'reset_theme') {
            $activeTab = 'theme';
            resetThemeSettings($pdo_master);
            $success = 'থিম ডিফল্টে ফিরিয়ে নেওয়া হয়েছে।';
        }

        // ── SMS Gateway সেভ ──
        if ($action === 'save_sms') {
            $activeTab = 'sms';
            saveSaasSmsSettings($pdo_master, [
                'api_url'         => $_POST['api_url']         ?? '',
                'api_key'         => $_POST['api_key']         ?? '',
                'sender_id'       => $_POST['sender_id']       ?? '',
                'param_phone'     => $_POST['param_phone']     ?? 'to',
                'param_message'   => $_POST['param_message']   ?? 'message',
                'param_apikey'    => $_POST['param_apikey']    ?? 'api_key',
                'param_sender'    => $_POST['param_sender']    ?? 'sender_id',
                'method'          => $_POST['method']          ?? 'GET',
                'success_keyword' => $_POST['success_keyword'] ?? '',
            ]);
            $success = 'SMS Gateway সেভ হয়েছে।';
        }

        // ── টেস্ট SMS পাঠানো ──
        if ($action === 'test_sms') {
            $activeTab = 'sms';
            $testPhone = trim($_POST['test_phone'] ?? '');
            if ($testPhone === '') {
                $error = 'টেস্ট করার জন্য একটা ফোন নম্বর দাও।';
            } else {
                $res = sendSaasSMS($pdo_master, $testPhone, 'এটি School SaaS Panel থেকে পাঠানো একটি টেস্ট SMS।', 'test');
                if ($res['success']) $success = '✅ টেস্ট SMS পাঠানো হয়েছে।';
                else $error = '❌ পাঠানো যায়নি: ' . htmlspecialchars($res['error'] ?? 'অজানা সমস্যা');
            }
        }

        // ── Security (Idle timeout) সেভ ──
        if ($action === 'save_security') {
            $activeTab = 'security';
            $minutes = max(0, (int) ($_POST['idle_timeout_minutes'] ?? 5));
            $pdo_master->prepare("INSERT INTO saas_settings (setting_key, setting_value) VALUES ('idle_timeout_minutes', ?)
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$minutes]);
            $success = 'Security সেটিংস সেভ হয়েছে।';
        }
    }
}

$theme = getThemeSettings($pdo_master);
saasSmsEnsureTables($pdo_master);
$idleMinutes = getIdleTimeoutMinutes($pdo_master);

$pageTitle = 'Settings';
$pageIcon  = '⚙️';
$activeKey = 'settings_' . $activeTab;
require_once __DIR__ . '/_layout_top.php';
?>
    <style>
        .settings-tabs { display: flex; gap: 4px; margin-bottom: 18px; border-bottom: 2px solid var(--card-border); }
        .settings-tabs a { padding: 10px 18px; font-weight: 600; font-size: 14px; color: var(--sidebar-text); text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -2px; }
        .settings-tabs a.active { color: var(--primary-color); border-color: var(--primary-color); }
        .swatch-row { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
        .swatch-row label { flex: 0 0 200px; font-size: 13px; font-weight: 600; margin: 0; color: var(--sidebar-text); }
        .swatch-row input[type=color] { width: 44px; height: 34px; padding: 2px; border-radius: 6px; border: 1px solid var(--sidebar-border); background: var(--sidebar-bg); }
        .swatch-row input[type=text], .swatch-row input[type=number] { max-width: 160px; }
        .brand-preview-box { border: 1px dashed var(--sidebar-border); border-radius: 8px; padding: 14px; text-align: center; background: var(--sidebar-bg); margin-bottom: 10px; }
        .brand-preview-box img { max-height: 60px; max-width: 100%; }
    </style>
    <div style="max-width: 820px;">
        <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

        <div class="settings-tabs">
            <a href="settings.php?tab=branding" class="<?php echo $activeTab === 'branding' ? 'active' : ''; ?>"><i class="fa fa-image me-1"></i> ব্র্যান্ডিং</a>
            <a href="settings.php?tab=theme" class="<?php echo $activeTab === 'theme' ? 'active' : ''; ?>"><i class="fa fa-palette me-1"></i> থিম</a>
            <a href="settings.php?tab=sms" class="<?php echo $activeTab === 'sms' ? 'active' : ''; ?>"><i class="fa fa-comment-sms me-1"></i> SMS Gateway</a>
            <a href="settings.php?tab=security" class="<?php echo $activeTab === 'security' ? 'active' : ''; ?>"><i class="fa fa-lock me-1"></i> Security</a>
        </div>

        <?php if ($activeTab === 'branding'): ?>
        <div class="card-custom p-4">
            <h6 class="fw-bold mb-3">🏷️ Panel নাম ও লোগো</h6>
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_branding">

                <div class="mb-3">
                    <label class="form-label fw-bold" style="font-size:13px;">Panel নাম</label>
                    <input type="text" name="panel_name" class="form-control" value="<?php echo htmlspecialchars($branding['name']); ?>" placeholder="যেমন: School SaaS">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold" style="font-size:13px;">লোগো</label>
                    <?php if ($branding['logo']): ?>
                        <div class="brand-preview-box"><img src="<?php echo htmlspecialchars($branding['logo']); ?>" alt="logo"></div>
                        <button type="submit" form="remove-logo-form" class="btn btn-sm btn-outline-danger mb-2"><i class="fa fa-trash me-1"></i> লোগো রিমুভ করো</button>
                    <?php endif; ?>
                    <input type="file" name="panel_logo" class="form-control" accept=".jpg,.jpeg,.png,.svg,.webp">
                    <small class="text-muted">jpg, png, svg, webp — টপবারে দেখাবে</small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold" style="font-size:13px;">ফেভিকন</label>
                    <?php if ($branding['favicon']): ?>
                        <div class="brand-preview-box"><img src="<?php echo htmlspecialchars($branding['favicon']); ?>" alt="favicon" style="max-height:32px;"></div>
                        <button type="submit" form="remove-favicon-form" class="btn btn-sm btn-outline-danger mb-2"><i class="fa fa-trash me-1"></i> ফেভিকন রিমুভ করো</button>
                    <?php endif; ?>
                    <input type="file" name="panel_favicon" class="form-control" accept=".ico,.png,.svg,.webp">
                </div>

                <button type="submit" class="btn btn-primary fw-bold"><i class="fa fa-save me-1"></i> সেভ করো</button>
            </form>

            <form id="remove-logo-form" method="POST" class="d-none">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="remove_branding">
                <input type="hidden" name="remove_key" value="panel_logo">
            </form>
            <form id="remove-favicon-form" method="POST" class="d-none">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="remove_branding">
                <input type="hidden" name="remove_key" value="panel_favicon">
            </form>
        </div>
        <?php endif; ?>

        <?php if ($activeTab === 'theme'): ?>
        <div class="card-custom p-4">
            <h6 class="fw-bold mb-3">🎨 রং ও ফন্ট</h6>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_theme">

                <div class="swatch-row">
                    <label>সাইডবার ব্যাকগ্রাউন্ড</label>
                    <input type="color" name="sidebar_bg" value="<?php echo htmlspecialchars($theme['sidebar_bg']); ?>" oninput="this.nextElementSibling.value=this.value">
                    <input type="text" value="<?php echo htmlspecialchars($theme['sidebar_bg']); ?>" oninput="this.previousElementSibling.value=this.value" class="form-control form-control-sm">
                </div>
                <div class="swatch-row">
                    <label>সাইডবার অ্যাকটিভ মেনু ব্যাকগ্রাউন্ড</label>
                    <input type="color" name="sidebar_active_bg" value="<?php echo htmlspecialchars($theme['sidebar_active_bg']); ?>" oninput="this.nextElementSibling.value=this.value">
                    <input type="text" value="<?php echo htmlspecialchars($theme['sidebar_active_bg']); ?>" oninput="this.previousElementSibling.value=this.value" class="form-control form-control-sm">
                </div>
                <div class="swatch-row">
                    <label>সাইডবার অ্যাকটিভ টেক্সট</label>
                    <input type="color" name="sidebar_active_text" value="<?php echo htmlspecialchars($theme['sidebar_active_text']); ?>" oninput="this.nextElementSibling.value=this.value">
                    <input type="text" value="<?php echo htmlspecialchars($theme['sidebar_active_text']); ?>" oninput="this.previousElementSibling.value=this.value" class="form-control form-control-sm">
                </div>
                <div class="swatch-row">
                    <label>অ্যাকসেন্ট কালার (বাম বর্ডার)</label>
                    <input type="color" name="sidebar_accent" value="<?php echo htmlspecialchars($theme['sidebar_accent']); ?>" oninput="this.nextElementSibling.value=this.value">
                    <input type="text" value="<?php echo htmlspecialchars($theme['sidebar_accent']); ?>" oninput="this.previousElementSibling.value=this.value" class="form-control form-control-sm">
                </div>
                <div class="swatch-row">
                    <label>ব্র্যান্ড নাম টেক্সট কালার</label>
                    <input type="color" name="brand_text" value="<?php echo htmlspecialchars($theme['brand_text']); ?>" oninput="this.nextElementSibling.value=this.value">
                    <input type="text" value="<?php echo htmlspecialchars($theme['brand_text']); ?>" oninput="this.previousElementSibling.value=this.value" class="form-control form-control-sm">
                </div>
                <div class="swatch-row">
                    <label>প্রাইমারি কালার (বাটন/লিংক)</label>
                    <input type="color" name="primary_color" value="<?php echo htmlspecialchars($theme['primary_color']); ?>" oninput="this.nextElementSibling.value=this.value">
                    <input type="text" value="<?php echo htmlspecialchars($theme['primary_color']); ?>" oninput="this.previousElementSibling.value=this.value" class="form-control form-control-sm">
                </div>
                <div class="swatch-row">
                    <label>পেজ ব্যাকগ্রাউন্ড</label>
                    <input type="color" name="page_bg" value="<?php echo htmlspecialchars($theme['page_bg']); ?>" oninput="this.nextElementSibling.value=this.value">
                    <input type="text" value="<?php echo htmlspecialchars($theme['page_bg']); ?>" oninput="this.previousElementSibling.value=this.value" class="form-control form-control-sm">
                </div>
                <div class="swatch-row">
                    <label>কার্ড ব্যাকগ্রাউন্ড</label>
                    <input type="color" name="card_bg" value="<?php echo htmlspecialchars($theme['card_bg']); ?>" oninput="this.nextElementSibling.value=this.value">
                    <input type="text" value="<?php echo htmlspecialchars($theme['card_bg']); ?>" oninput="this.previousElementSibling.value=this.value" class="form-control form-control-sm">
                </div>
                <div class="swatch-row">
                    <label>বডি টেক্সট</label>
                    <input type="color" name="body_text" value="<?php echo htmlspecialchars($theme['body_text']); ?>" oninput="this.nextElementSibling.value=this.value">
                    <input type="text" value="<?php echo htmlspecialchars($theme['body_text']); ?>" oninput="this.previousElementSibling.value=this.value" class="form-control form-control-sm">
                </div>

                <hr>
                <h6 class="fw-bold mb-3 mt-3">🏷️ স্ট্যাটাস ব্যাজ রং</h6>
                <div class="swatch-row">
                    <label>Active</label>
                    <input type="color" name="badge_active" value="<?php echo htmlspecialchars($theme['badge_active']); ?>" oninput="this.nextElementSibling.value=this.value">
                    <input type="text" value="<?php echo htmlspecialchars($theme['badge_active']); ?>" oninput="this.previousElementSibling.value=this.value" class="form-control form-control-sm">
                </div>
                <div class="swatch-row">
                    <label>Trial</label>
                    <input type="color" name="badge_trial" value="<?php echo htmlspecialchars($theme['badge_trial']); ?>" oninput="this.nextElementSibling.value=this.value">
                    <input type="text" value="<?php echo htmlspecialchars($theme['badge_trial']); ?>" oninput="this.previousElementSibling.value=this.value" class="form-control form-control-sm">
                </div>
                <div class="swatch-row">
                    <label>Suspended</label>
                    <input type="color" name="badge_suspended" value="<?php echo htmlspecialchars($theme['badge_suspended']); ?>" oninput="this.nextElementSibling.value=this.value">
                    <input type="text" value="<?php echo htmlspecialchars($theme['badge_suspended']); ?>" oninput="this.previousElementSibling.value=this.value" class="form-control form-control-sm">
                </div>
                <div class="swatch-row">
                    <label>Blocked</label>
                    <input type="color" name="badge_blocked" value="<?php echo htmlspecialchars($theme['badge_blocked']); ?>" oninput="this.nextElementSibling.value=this.value">
                    <input type="text" value="<?php echo htmlspecialchars($theme['badge_blocked']); ?>" oninput="this.previousElementSibling.value=this.value" class="form-control form-control-sm">
                </div>
                <div class="swatch-row">
                    <label>Cancelled</label>
                    <input type="color" name="badge_cancelled" value="<?php echo htmlspecialchars($theme['badge_cancelled']); ?>" oninput="this.nextElementSibling.value=this.value">
                    <input type="text" value="<?php echo htmlspecialchars($theme['badge_cancelled']); ?>" oninput="this.previousElementSibling.value=this.value" class="form-control form-control-sm">
                </div>

                <hr>
                <h6 class="fw-bold mb-3 mt-3">🔤 ফন্ট ও লেআউট</h6>
                <div class="mb-3">
                    <label class="form-label fw-bold" style="font-size:13px;">Font Family</label>
                    <select name="font_family" class="form-select">
                        <?php
                        $fonts = ["'Segoe UI', sans-serif" => 'Segoe UI', "'Poppins', sans-serif" => 'Poppins', "'Inter', sans-serif" => 'Inter', "'Roboto', sans-serif" => 'Roboto', "Arial, sans-serif" => 'Arial', "'Noto Sans Bengali', sans-serif" => 'Noto Sans Bengali'];
                        foreach ($fonts as $val => $label):
                        ?>
                        <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $theme['font_family'] === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold" style="font-size:13px;">Font Size (px)</label>
                        <input type="number" name="font_size_base" min="11" max="18" class="form-control" value="<?php echo htmlspecialchars($theme['font_size_base']); ?>">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold" style="font-size:13px;">Border Radius (px)</label>
                        <input type="number" name="border_radius" min="0" max="24" class="form-control" value="<?php echo htmlspecialchars($theme['border_radius']); ?>">
                    </div>
                </div>

                <hr>
                <h6 class="fw-bold mb-3 mt-3">📝 ফুটার ক্রেডিট</h6>
                <div class="form-check mb-2">
                    <input type="checkbox" name="footer_enabled" id="footer_enabled" class="form-check-input" <?php echo $theme['footer_enabled'] === '1' ? 'checked' : ''; ?>>
                    <label for="footer_enabled" class="form-check-label" style="font-size:13px;">ফুটার ক্রেডিট দেখাও</label>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold" style="font-size:13px;">ক্রেডিট টেক্সট</label>
                    <input type="text" name="footer_credit_text" class="form-control" value="<?php echo htmlspecialchars($theme['footer_credit_text']); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold" style="font-size:13px;">ক্রেডিট লিংক (ঐচ্ছিক)</label>
                    <input type="text" name="footer_credit_url" class="form-control" value="<?php echo htmlspecialchars($theme['footer_credit_url']); ?>" placeholder="https://...">
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-primary fw-bold"><i class="fa fa-save me-1"></i> থিম সেভ করো</button>
                    <button type="submit" name="action" value="reset_theme" class="btn btn-outline-secondary fw-bold" onclick="return confirm('থিম ডিফল্টে ফিরিয়ে নেবে?');" formnovalidate>
                        <i class="fa fa-rotate-left me-1"></i> ডিফল্টে ফিরাও
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($activeTab === 'sms'): ?>
        <div class="card-custom p-4">
            <h6 class="fw-bold mb-3">📩 SMS Gateway কনফিগারেশন</h6>
            <p style="font-size:12px;color:var(--sidebar-text)">যেকোনো Bangladeshi SMS gateway (BulkSMSBD, GreenWeb, Alpha SMS ইত্যাদি) এখানে কনফিগার করা যাবে — যেই gateway ব্যবহার করছ তার API ডকুমেন্টেশন থেকে URL/parameter নাম গুলো বসাও।</p>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_sms">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label small fw-bold">API URL</label>
                        <input type="text" name="api_url" class="form-control" placeholder="https://bulksmsbd.net/api/smsapi" value="<?php echo htmlspecialchars(getSaasSmsSetting($pdo_master, 'api_url')); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Method</label>
                        <select name="method" class="form-select">
                            <?php foreach (['GET','POST'] as $m): ?>
                            <option value="<?php echo $m; ?>" <?php echo getSaasSmsSetting($pdo_master, 'method', 'GET') === $m ? 'selected' : ''; ?>><?php echo $m; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">API Key</label>
                        <input type="text" name="api_key" class="form-control" value="<?php echo htmlspecialchars(getSaasSmsSetting($pdo_master, 'api_key')); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Sender ID (ঐচ্ছিক)</label>
                        <input type="text" name="sender_id" class="form-control" value="<?php echo htmlspecialchars(getSaasSmsSetting($pdo_master, 'sender_id')); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Phone Param নাম</label>
                        <input type="tel" name="param_phone" class="form-control phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="১১ ডিজিটের মোবাইল নম্বর দিন" value="<?php echo htmlspecialchars(getSaasSmsSetting($pdo_master, 'param_phone', 'to')); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Message Param নাম</label>
                        <input type="text" name="param_message" class="form-control" value="<?php echo htmlspecialchars(getSaasSmsSetting($pdo_master, 'param_message', 'message')); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">API Key Param নাম</label>
                        <input type="text" name="param_apikey" class="form-control" value="<?php echo htmlspecialchars(getSaasSmsSetting($pdo_master, 'param_apikey', 'api_key')); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Sender Param নাম</label>
                        <input type="text" name="param_sender" class="form-control" value="<?php echo htmlspecialchars(getSaasSmsSetting($pdo_master, 'param_sender', 'sender_id')); ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label small fw-bold">Success Keyword (ঐচ্ছিক — response এ এই শব্দ থাকলে "sent" ধরা হবে)</label>
                        <input type="text" name="success_keyword" class="form-control" value="<?php echo htmlspecialchars(getSaasSmsSetting($pdo_master, 'success_keyword')); ?>">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary fw-bold mt-3"><i class="fa fa-save me-1"></i> সেভ করো</button>
            </form>

            <hr>
            <h6 class="fw-bold mb-3">🧪 টেস্ট SMS পাঠাও</h6>
            <form method="POST" class="d-flex gap-2">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="test_sms">
                <input type="tel" name="test_phone" class="form-control phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="১১ ডিজিটের মোবাইল নম্বর দিন" placeholder="01xxxxxxxxx" required>
                <button type="submit" class="btn btn-outline-primary fw-bold text-nowrap">টেস্ট পাঠাও</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($activeTab === 'security'): ?>
        <div class="card-custom p-4">
            <h6 class="fw-bold mb-3">🔐 Security — Auto Logout</h6>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_security">
                <div class="mb-3">
                    <label class="form-label small fw-bold">নিষ্ক্রিয় থাকলে কত মিনিটে auto-logout হবে (0 = বন্ধ)</label>
                    <input type="number" name="idle_timeout_minutes" min="0" max="180" class="form-control" style="max-width:200px" value="<?php echo (int) $idleMinutes; ?>">
                </div>
                <button type="submit" class="btn btn-primary fw-bold"><i class="fa fa-save me-1"></i> সেভ করো</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
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
