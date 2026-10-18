<?php
// admin/canteen_settings.php - Canteen > Settings
require_once __DIR__ . '/canteen_common.php';
canteen_require_manage();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $err = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (isset($_POST['save_settings'])) {
        $allow_due_sale = isset($_POST['allow_due_sale']) ? 1 : 0;
        $block_low = isset($_POST['block_on_low_stock']) ? 1 : 0;
        $pdo->prepare("UPDATE canteen_settings SET allow_due_sale = ?, block_on_low_stock = ? WHERE id = 1")->execute([$allow_due_sale, $block_low]);
        sec_log_activity($pdo, 'Canteen', 'Update', 'Canteen Settings আপডেট করা হয়েছে: Allow Due Sale = ' . ($allow_due_sale ? 'On' : 'Off') . ', কাঁচামাল কম হলে Sale আটকাও = ' . ($block_low ? 'On' : 'Off'));
        $msg = 'Settings সেভ হয়েছে।';
    }
}

$settings = canteen_get_settings($pdo);
?>

<div class="card-custom">
    <h4 class="fw-bold mb-4 text-dark"><i class="fa-solid fa-gear"></i> Canteen &raquo; Settings</h4>

    <?php if ($msg): ?><div class="alert alert-success py-2"><?php echo cv($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?php echo cv($err); ?></div><?php endif; ?>

    <form method="POST" style="max-width:560px;">
        <?php echo csrf_field(); ?>
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="allowDueSale" name="allow_due_sale" <?php echo $settings['allow_due_sale'] ? 'checked' : ''; ?>>
            <label class="form-check-label fw-bold" for="allowDueSale">Due Sale (বাকিতে বিক্রি) চালু রাখো</label>
            <div class="small text-muted mt-1">
                বন্ধ করলে POS-এ Paid Amount সবসময় Grand Total-এর সমান হয়ে যাবে — কোনো বাকি রাখা যাবে না, প্রতিটা বিক্রি সাথে সাথে পুরো টাকা নিয়ে সম্পন্ন করতে হবে।
            </div>
        </div>
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="blockLowStock" name="block_on_low_stock" <?php echo !empty($settings['block_on_low_stock']) ? 'checked' : ''; ?>>
            <label class="form-check-label fw-bold" for="blockLowStock">কাঁচামালের স্টক কম হলে Sale আটকাও</label>
            <div class="small text-muted mt-1">
                চালু করলে Recipe অনুযায়ী কাঁচামাল না থাকলে POS-এ বিক্রি হবে না। বন্ধ থাকলে (ডিফল্ট) বিক্রি হবে, স্টক মাইনাসে যেতে পারে এবং "মাইনাস" সতর্কতা দেখাবে।
            </div>
        </div>
        <button type="submit" name="save_settings" class="btn btn-primary fw-bold px-4" style="background-color:#1e3a8a;">সেভ করো</button>
    </form>
</div>
