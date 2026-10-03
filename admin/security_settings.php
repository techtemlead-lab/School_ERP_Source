<?php
// admin/security_settings.php - System & Security > Session Timeout Settings
// কতক্ষণ নিষ্ক্রিয় (Idle) থাকলে একটা লগইন সেশন স্বয়ংক্রিয়ভাবে লগআউট হয়ে যাবে, এবং একই
// ইউজার একসাথে একাধিক ব্রাউজার/ডিভাইসে লগইন থাকতে পারবে কিনা — এই দুটো সেটিং এখান থেকে।
require_once __DIR__ . '/../config/security_helpers.php';
sec_ensure_tables($pdo);

$is_super_admin = $_SESSION['is_super_admin'] ?? true;
if (!$is_super_admin) {
    echo '<div class="card-custom"><div class="alert alert-danger mb-0"><i class="fa-solid fa-lock me-2"></i>শুধুমাত্র <b>Super Admin</b> Security Settings পরিবর্তন করতে পারবেন।</div></div>';
    return;
}

function ssv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$ss_msg = $_SESSION['ss_msg'] ?? '';
$ss_err = $_SESSION['ss_err'] ?? '';
unset($_SESSION['ss_msg'], $_SESSION['ss_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ss_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=security_settings');
        exit();
    }

    $timeout_minutes = (int) ($_POST['session_timeout_minutes'] ?? 60);
    $force_single = isset($_POST['force_single_session']) ? 1 : 0;

    if ($timeout_minutes < 0 || $timeout_minutes > 1440) {
        $_SESSION['ss_err'] = 'Session Timeout ০ (বন্ধ) থেকে ১৪৪০ মিনিট (২৪ ঘণ্টা) এর মধ্যে দিন।';
    } else {
        $pdo->prepare(
            "INSERT INTO security_settings (id, session_timeout_minutes, force_single_session, updated_by)
             VALUES (1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE session_timeout_minutes = VALUES(session_timeout_minutes),
                 force_single_session = VALUES(force_single_session), updated_by = VALUES(updated_by)"
        )->execute([$timeout_minutes, $force_single, $_SESSION['admin_user'] ?? '']);

        sec_log_activity(
            $pdo,
            'Security',
            'Update',
            "Session Timeout সেটিংস আপডেট করা হয়েছে: Timeout = " . ($timeout_minutes > 0 ? "{$timeout_minutes} মিনিট" : 'বন্ধ') . ", Single Session = " . ($force_single ? 'চালু' : 'বন্ধ')
        );
        $_SESSION['ss_msg'] = 'Security Settings আপডেট করা হয়েছে।';
    }
    header('Location: index.php?page=security_settings');
    exit();
}

$settings = sec_get_settings($pdo);
?>

<div class="card-custom" style="max-width:700px;">
    <?php if ($ss_msg): ?><div class="alert alert-success py-2"><?php echo ssv($ss_msg); ?></div><?php endif; ?>
    <?php if ($ss_err): ?><div class="alert alert-danger py-2"><?php echo ssv($ss_err); ?></div><?php endif; ?>

    <form method="POST" action="index.php?page=security_settings">
        <?php echo csrf_field(); ?>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-5 col-form-label fw-bold text-end">Session Timeout (মিনিট)</label>
            <div class="col-sm-5">
                <input type="number" min="0" max="1440" step="1" name="session_timeout_minutes" class="form-control" value="<?php echo (int) $settings['session_timeout_minutes']; ?>">
                <small class="text-muted">এই সময় ধরে কোনো কার্যকলাপ না থাকলে (কোনো পেজ লোড না করলে) সেশন স্বয়ংক্রিয়ভাবে লগআউট হয়ে যাবে। <b>০</b> দিলে টাইমআউট বন্ধ থাকবে।</small>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-5 col-form-label fw-bold text-end">Single Session Per User</label>
            <div class="col-sm-5">
                <div class="form-check pt-2">
                    <input type="checkbox" name="force_single_session" id="ssForceSingle" class="form-check-input" value="1" <?php echo $settings['force_single_session'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="ssForceSingle">একই ইউজার নতুন জায়গায় লগইন করলে আগের সেশন(গুলো) স্বয়ংক্রিয়ভাবে লগআউট হয়ে যাবে</label>
                </div>
                <small class="text-muted">চালু থাকলে, একজন ইউজার একই সময়ে শুধু একটা ব্রাউজার/ডিভাইসেই লগইন থাকতে পারবেন — নতুন জায়গায় লগইন করলে আগের সব জায়গা থেকে অটো লগআউট হয়ে যাবে।</small>
            </div>
        </div>

        <hr>
        <div class="text-center">
            <button type="submit" class="btn btn-primary px-4 fw-bold">Save Settings</button>
            <?php if (!empty($settings['updated_at'])): ?>
                <div class="text-muted small mt-2">সর্বশেষ আপডেট: <?php echo ssv(date('Y-m-d H:i', strtotime($settings['updated_at']))); ?> — <?php echo ssv($settings['updated_by'] ?? ''); ?></div>
            <?php endif; ?>
        </div>
    </form>
</div>
