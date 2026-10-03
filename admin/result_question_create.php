<?php
// admin/result_question_create.php - Result Module > Question Management > Create Question
// এই পেজে বাইরের Question Management System (QMS) এর ডোমেইন সেট করা হয়।
// ডোমেইন সেট করা থাকলে বাটনে ক্লিক করলে সরাসরি সেই সিস্টেমে চলে যাবে।
// সেই সিস্টেম থেকে প্রশ্ন তৈরি হলে নিচের Webhook URL দিয়ে এই স্কুলে (Question List) পাঠাতে হবে।

require_once __DIR__ . '/../config/qms_helpers.php';
qms_ensure_tables($pdo);

$qc_err = $_SESSION['qc_err'] ?? '';
$qc_msg = $_SESSION['qc_msg'] ?? '';
unset($_SESSION['qc_err'], $_SESSION['qc_msg']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['qc_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_question_create');
        exit();
    }

    if (isset($_POST['save_domain'])) {
        $domain = trim($_POST['qms_domain_url'] ?? '');
        if ($domain !== '' && !preg_match('#^https?://#i', $domain)) {
            $domain = 'https://' . $domain;
        }
        $pdo->prepare("UPDATE qms_settings SET qms_domain_url = ? WHERE id = 1")->execute([$domain ?: null]);
        sec_log_activity($pdo, 'Result', 'Update', "QMS Domain URL আপডেট করা হয়েছে: {$domain}");
        $_SESSION['qc_msg'] = 'Question Management System এর ডোমেইন সেভ করা হয়েছে।';
    } elseif (isset($_POST['regenerate_token'])) {
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE qms_settings SET webhook_token = ? WHERE id = 1")->execute([$token]);
        sec_log_activity($pdo, 'Result', 'Update', 'QMS Webhook Token পুনরায় জেনারেট করা হয়েছে');
        $_SESSION['qc_msg'] = 'নতুন Webhook Token তৈরি হয়েছে। আগেরটা আর কাজ করবে না — QMS সিস্টেমে নতুন URL আপডেট করে দিন।';
    }

    header('Location: index.php?page=result_question_create');
    exit();
}

$settings = qms_get_settings($pdo);
$webhook_token = qms_ensure_webhook_token($pdo);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$webhook_url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/qms-webhook.php?token=' . $webhook_token;

function qcv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
?>

<div class="card-custom">
    <?php if ($qc_err): ?><div class="alert alert-danger py-2"><?php echo qcv($qc_err); ?></div><?php endif; ?>
    <?php if ($qc_msg): ?><div class="alert alert-success py-2"><?php echo qcv($qc_msg); ?></div><?php endif; ?>

    <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;">
        <i class="fa-solid fa-file-circle-plus text-white"></i>
    </div>

    <?php if (!empty($settings['qms_domain_url'])): ?>
        <h6 class="text-muted mb-3">Question Management System এ প্রশ্ন তৈরি করুন</h6>
        <a href="<?php echo qcv($settings['qms_domain_url']); ?>" target="_blank" rel="noopener" class="btn btn-primary mb-4">
            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Go to Question Management System
        </a>
        <p class="text-muted small mb-4">Domain: <?php echo qcv($settings['qms_domain_url']); ?></p>
    <?php else: ?>
        <h6 class="text-muted mb-4">এখনো Question Management System এর ডোমেইন সেট করা হয়নি</h6>
    <?php endif; ?>

    <form method="POST" action="index.php?page=result_question_create" class="mb-5">
        <?php echo csrf_field(); ?>
        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">QMS Domain URL</label>
            <div class="col-sm-6">
                <input type="text" name="qms_domain_url" class="form-control"
                       placeholder="https://example-qms.com" value="<?php echo qcv($settings['qms_domain_url'] ?? ''); ?>">
            </div>
        </div>
        <div class="row">
            <div class="col-sm-9 offset-sm-3">
                <button type="submit" name="save_domain" class="btn btn-success">Save Domain</button>
            </div>
        </div>
    </form>

    <hr>

    <h6 class="text-muted mb-3">Webhook URL (QMS সিস্টেমে এই URL সেট করুন)</h6>
    <p class="mb-2">প্রশ্ন Generate হলে QMS সিস্টেম এই URL এ POST (JSON) করলে তা <strong>Question List</strong> পেজে চলে আসবে।</p>
    <div class="input-group mb-4" style="max-width:600px;">
        <input type="text" class="form-control" readonly value="<?php echo qcv($webhook_url); ?>" onclick="this.select();">
    </div>

    <form method="POST" action="index.php?page=result_question_create" onsubmit="return confirm('নতুন Token তৈরি করলে আগের Webhook URL আর কাজ করবে না। আগে বাড়বেন?');">
        <?php echo csrf_field(); ?>
        <button type="submit" name="regenerate_token" class="btn btn-outline-danger btn-sm">Regenerate Token</button>
    </form>
</div>
