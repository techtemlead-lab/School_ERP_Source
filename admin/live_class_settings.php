<?php
// admin/live_class_settings.php - E-Learning Module > Live Class Setting
// শুধু Admin-এর জন্য (Teacher Portal থেকে এই পেজে আসার কোনো রাস্তা নেই)
require_once __DIR__ . '/../config/live_class_100ms.php';

if (!function_exists('lcs_e')) {
    function lcs_e($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
}

lc_ensure_settings_table($pdo);

$lcs_msg = $_SESSION['lcs_msg'] ?? '';
unset($_SESSION['lcs_msg']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_live_settings'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['lcs_msg'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করুন।';
    } else {
        $pdo->prepare(
            "UPDATE elearning_live_settings SET hms_access_key=?, hms_secret=?, hms_template_id=? WHERE id=1"
        )->execute([
            trim($_POST['hms_access_key'] ?? ''),
            trim($_POST['hms_secret'] ?? ''),
            trim($_POST['hms_template_id'] ?? ''),
        ]);
        $_SESSION['lcs_msg'] = 'Live Class Setting সেভ হয়েছে।';
        sec_log_activity($pdo, 'Live Class', 'Update', 'Live Class Setting (100ms) আপডেট করা হয়েছে।');
    }
    header('Location: index.php?page=live_class_settings');
    exit();
}

$lcs_settings = lc_get_settings($pdo);
?>

<?php if ($lcs_msg): ?><div class="alert alert-success py-2"><?php echo lcs_e($lcs_msg); ?></div><?php endif; ?>

<div class="card-custom" style="max-width: 620px;">
    <h6 class="fw-bold mb-1">Live Class Setting (100ms)</h6>
    <p class="small text-muted">
        100ms Dashboard (dashboard.100ms.live) → Developer সেকশন থেকে Access Key/Secret পাবেন।
        Template থেকে "classroom" টেমপ্লেট বেছে নিলে host/guest রোল আগে থেকেই তৈরি থাকবে।
    </p>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="mb-2">
            <label class="form-label small fw-bold">100ms Access Key</label>
            <input type="text" name="hms_access_key" class="form-control form-control-sm" value="<?php echo lcs_e($lcs_settings['hms_access_key'] ?? ''); ?>">
        </div>
        <div class="mb-2">
            <label class="form-label small fw-bold">100ms Secret</label>
            <input type="password" name="hms_secret" class="form-control form-control-sm" value="<?php echo lcs_e($lcs_settings['hms_secret'] ?? ''); ?>" autocomplete="new-password">
        </div>
        <div class="mb-3">
            <label class="form-label small fw-bold">Template ID (ঐচ্ছিক)</label>
            <input type="text" name="hms_template_id" class="form-control form-control-sm" value="<?php echo lcs_e($lcs_settings['hms_template_id'] ?? ''); ?>">
        </div>
        <button type="submit" name="save_live_settings" class="btn btn-sm btn-primary">সেভ করুন</button>
    </form>
</div>

<div class="card-custom mt-3" style="max-width: 620px;">
    <h6 class="fw-bold mb-1">Recording Auto-Upload (YouTube)</h6>
    <p class="small text-muted mb-2">Live Class-এর রেকর্ডিং অটোমেটিক YouTube-এ (Unlisted) আপলোড করতে চাইলে চ্যানেল কানেক্ট করুন।</p>
    <a href="index.php?page=live_class_youtube_connect" class="btn btn-sm btn-outline-danger">
        <i class="fa-brands fa-youtube me-1"></i> YouTube Connect / Webhook Setup
    </a>
</div>
