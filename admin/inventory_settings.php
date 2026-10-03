<?php
// admin/inventory_settings.php - Inventory & Sales > Settings
require_once __DIR__ . '/inventory_common.php';
require_once __DIR__ . '/../config/uploads.php';

$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error_msg = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (isset($_POST['save_settings'])) {
        $allow_due_sale   = isset($_POST['allow_due_sale']) ? 1 : 0;
        $send_sms_on_sale = isset($_POST['send_sms_on_sale']) ? 1 : 0;

        $logo_path = null;
        if (!empty($_FILES['logo_file']['name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['logo_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png'];
            if ($file['size'] > 2 * 1024 * 1024) {
                $error_msg = 'লোগোর সাইজ সর্বোচ্চ 2MB হতে পারবে।';
            } elseif (!in_array($ext, $allowed, true)) {
                $error_msg = 'শুধু jpg/png ছবি আপলোড করা যাবে।';
            } else {
                $dir_abs = tenant_upload_abs(__DIR__ . '/..', 'inventory');
                if (!is_dir($dir_abs)) { mkdir($dir_abs, 0755, true); }
                $filename = 'logo_' . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir_abs . $filename)) {
                    $logo_path = tenant_upload_rel('inventory') . '/' . $filename;
                } else {
                    $error_msg = 'লোগো আপলোড ব্যর্থ হয়েছে।';
                }
            }
        }

        if ($error_msg === '') {
            if ($logo_path !== null) {
                $pdo->prepare("UPDATE inv_settings SET allow_due_sale = ?, send_sms_on_sale = ?, logo_path = ? WHERE id = 1")
                    ->execute([$allow_due_sale, $send_sms_on_sale, $logo_path]);
            } else {
                $pdo->prepare("UPDATE inv_settings SET allow_due_sale = ?, send_sms_on_sale = ? WHERE id = 1")
                    ->execute([$allow_due_sale, $send_sms_on_sale]);
            }
            sec_log_activity($pdo, 'Inventory', 'Update', "Inventory Settings আপডেট করা হয়েছে (Due Sale: " . ($allow_due_sale ? 'On' : 'Off') . ", SMS: " . ($send_sms_on_sale ? 'On' : 'Off') . ")");
            $success_msg = "Settings সেভ হয়েছে।";
        }
    }
}

$settings = inv_get_settings($pdo);
?>

<div class="card-custom">
    <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-gear"></i> Inventory & Sales &raquo; Settings</h4>
    <p class="text-muted mb-4" style="font-size:13px;">Due Sale, SMS Notification ও Invoice Logo কনফিগার করুন।</p>

    <?php if ($success_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($success_msg); ?></div><?php endif; ?>
    <?php if ($error_msg): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="card p-4 bg-light" style="max-width:600px;">
        <?php echo csrf_field(); ?>

        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" name="allow_due_sale" id="allowDueSale" <?php echo $settings['allow_due_sale'] ? 'checked' : ''; ?>>
            <label class="form-check-label fw-bold" for="allowDueSale">Due Sale চালু রাখুন</label>
            <div class="text-muted small">বন্ধ (Off) করলে Sales (POS)-এ পুরো টাকা পরিশোধ ছাড়া Invoice তৈরি করা যাবে না — কোনো বাকি/Due রাখা যাবে না।</div>
        </div>

        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" name="send_sms_on_sale" id="sendSmsOnSale" <?php echo $settings['send_sms_on_sale'] ? 'checked' : ''; ?>>
            <label class="form-check-label fw-bold" for="sendSmsOnSale">Sale হলে অভিভাবককে SMS পাঠান</label>
            <div class="text-muted small">শুধুমাত্র Student-লিংকড Sale-এর ক্ষেত্রে প্রযোজ্য — SMS Module-এ Gateway Enable/Configure করা থাকতে হবে (Global Settings &gt; SMS Settings)।</div>
        </div>

        <hr>

        <label class="form-label fw-bold">Invoice Logo</label>
        <?php if (!empty($settings['logo_path'])): ?>
            <div class="mb-2"><img src="../<?php echo htmlspecialchars($settings['logo_path']); ?>" alt="Logo" style="height:60px;"></div>
        <?php endif; ?>
        <input type="file" name="logo_file" class="form-control mb-1" accept=".jpg,.jpeg,.png">
        <div class="text-muted small mb-3">JPG/PNG, সর্বোচ্চ 2MB। Sales Invoice-এর উপরে দেখাবে।</div>

        <button type="submit" name="save_settings" class="btn btn-primary">Save Settings</button>
    </form>
</div>
