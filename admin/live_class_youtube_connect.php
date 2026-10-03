<?php
// admin/live_class_youtube_connect.php - E-Learning Module > Live Class > YouTube Connect
require_once __DIR__ . '/../config/live_class_100ms.php';
require_once __DIR__ . '/../config/youtube_upload.php';

if (!function_exists('ytc_e')) {
    function ytc_e($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
}

lc_ensure_settings_table($pdo);
yt_ensure_settings_columns($pdo);

if (($_GET['action'] ?? '') === 'disconnect' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (csrf_verify($_POST['csrf_token'] ?? null)) {
        $pdo->prepare("UPDATE elearning_live_settings SET youtube_refresh_token = NULL, youtube_channel_title = NULL WHERE id = 1")->execute();
        $_SESSION['ytc_msg'] = 'YouTube চ্যানেল ডিসকানেক্ট করা হয়েছে।';
        sec_log_activity($pdo, 'Live Class', 'Update', 'YouTube চ্যানেল ডিসকানেক্ট করা হয়েছে।');
    }
    header('Location: index.php?page=live_class_youtube_connect');
    exit();
}

$ytc_msg = $_SESSION['ytc_msg'] ?? '';
unset($_SESSION['ytc_msg']);

$settings = lc_get_settings($pdo);
$connected = yt_is_connected($settings);

$school_code = $GLOBALS['tenant']['school_code'] ?? '';
$connect_url = $school_code ? yt_oauth_consent_url(yt_oauth_make_state($school_code)) : null;

$webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
    . ($_SERVER['HTTP_HOST'] ?? '') . '/100ms-webhook.php?token=' . lc_ensure_webhook_secret($pdo);
?>

<?php if ($ytc_msg): ?><div class="alert alert-success py-2"><?php echo ytc_e($ytc_msg); ?></div><?php endif; ?>

<div class="card-custom mb-3" style="max-width: 640px;">
    <h6 class="fw-bold mb-1">YouTube Auto-Upload (রেকর্ডিং)</h6>
    <p class="small text-muted">
        Live Class হোস্ট পেজ থেকে "Start Recording" চাপলে ক্লাসটা রেকর্ড হবে, শেষে অটোমেটিক
        আপনার কানেক্ট করা YouTube চ্যানেলে <b>Unlisted</b> হিসেবে আপলোড হয়ে Recorded Class লিস্টে যোগ হয়ে যাবে।
    </p>

    <?php if ($connected): ?>
        <div class="alert alert-success py-2 d-flex justify-content-between align-items-center">
            <span>✅ কানেক্টেড: <b><?php echo ytc_e($settings['youtube_channel_title'] ?? 'YouTube Channel'); ?></b></span>
            <form method="POST" action="index.php?page=live_class_youtube_connect&action=disconnect" onsubmit="return confirm('ডিসকানেক্ট করতে চান?');">
                <?php echo csrf_field(); ?>
                <button type="submit" class="btn btn-sm btn-outline-danger">Disconnect</button>
            </form>
        </div>
    <?php else: ?>
        <?php if ($connect_url): ?>
            <a href="<?php echo ytc_e($connect_url); ?>" class="btn btn-sm btn-danger">
                <i class="fa-brands fa-youtube me-1"></i> YouTube চ্যানেল Connect করুন
            </a>
        <?php else: ?>
            <div class="alert alert-warning py-2 mb-0">স্কুলের তথ্য শনাক্ত করা যায়নি।</div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="card-custom" style="max-width: 640px;">
    <h6 class="fw-bold mb-1">100ms Webhook URL</h6>
    <p class="small text-muted">
        এই URL-টা কপি করে আপনার 100ms Dashboard (dashboard.100ms.live) → Developer → Webhooks সেকশনে যোগ করুন,
        ইভেন্ট হিসেবে <code>beam.recording.success</code> সিলেক্ট করুন। এটা ছাড়া রেকর্ডিং অটো-আপলোড হবে না।
    </p>
    <div class="input-group input-group-sm">
        <input type="text" class="form-control" readonly value="<?php echo ytc_e($webhook_url); ?>" id="webhookUrlInput">
        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('webhookUrlInput').value)">কপি</button>
    </div>
</div>
