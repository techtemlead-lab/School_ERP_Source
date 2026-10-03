<?php
// admin/whatsapp_settings.php - Global Settings > WhatsApp API Settings
require_once __DIR__ . '/../config/whatsapp.php';
wa_ensure_schema($pdo);

$wa_msg = $_SESSION['wa_msg'] ?? '';
$wa_err = $_SESSION['wa_err'] ?? '';
unset($_SESSION['wa_msg'], $_SESSION['wa_err']);

// -----------------------------------------------------------
// সেটিংস সেভ করা
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_whatsapp_settings'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['wa_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $stmt = $pdo->prepare(
            "UPDATE whatsapp_settings SET
                api_phone_number_id = ?,
                api_access_token = ?,
                api_business_account_id = ?,
                api_version = ?,
                api_enabled = ?
             WHERE id = 1"
        );
        $stmt->execute([
            trim($_POST['api_phone_number_id'] ?? ''),
            trim($_POST['api_access_token'] ?? ''),
            trim($_POST['api_business_account_id'] ?? ''),
            trim($_POST['api_version'] ?? 'v20.0') ?: 'v20.0',
            isset($_POST['api_enabled']) ? 1 : 0,
        ]);
        $_SESSION['wa_msg'] = 'WhatsApp API Settings সফলভাবে সেভ হয়েছে!';
        sec_log_activity($pdo, 'WhatsApp', 'Update', 'WhatsApp API Settings আপডেট করা হয়েছে।');
    }
    header('Location: index.php?page=whatsapp_settings');
    exit();
}

// -----------------------------------------------------------
// Test WhatsApp মেসেজ পাঠানো (শুধু session window চালু থাকলে কাজ করবে,
// কারণ এটা send_whatsapp_text() ব্যবহার করে, template না)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_whatsapp'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['wa_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $test_phone = trim($_POST['test_phone'] ?? '');
        if ($test_phone === '') {
            $_SESSION['wa_err'] = 'Test মেসেজ পাঠাতে একটা মোবাইল নম্বর দিন।';
        } else {
            $result = send_whatsapp_text($pdo, $test_phone, 'This is a test WhatsApp message from School ERP.');
            if ($result['success']) {
                $_SESSION['wa_msg'] = 'Test WhatsApp মেসেজ পাঠানো হয়েছে। রেসপন্সঃ ' . $result['response'];
            } else {
                $_SESSION['wa_err'] = 'Test মেসেজ পাঠানো যায়নি — ' . $result['response']
                    . ' (মনে রাখবেন, টেক্সট মেসেজ শুধু কাস্টমার ২৪ ঘণ্টার মধ্যে নিজে মেসেজ করলে যায়, নাহলে আগে Meta-তে একটা Template Approve করিয়ে সেটা টেস্ট করুন)';
            }
        }
    }
    header('Location: index.php?page=whatsapp_settings');
    exit();
}

$settings = $pdo->query("SELECT * FROM whatsapp_settings WHERE id = 1")->fetch();
if (!$settings) {
    $settings = [
        'api_phone_number_id' => '', 'api_access_token' => '', 'api_business_account_id' => '',
        'api_version' => 'v20.0', 'api_enabled' => 0, 'updated_at' => '',
    ];
}

function wsv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
?>

<?php if ($wa_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($wa_msg); ?></div><?php endif; ?>
<?php if ($wa_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($wa_err); ?></div><?php endif; ?>

<div class="card-custom mb-4">
    <h4 class="fw-bold mb-4 text-dark"><i class="fa-brands fa-whatsapp text-success"></i> WhatsApp API Settings</h4>
    <form method="POST" action="index.php?page=whatsapp_settings">
        <?php echo csrf_field(); ?>
        <div class="row mb-2">
            <div class="col-md-4 mb-3">
                <label class="fw-bold small mb-1">Phone Number ID <span class="text-danger">*</span></label>
                <input type="text" name="api_phone_number_id" class="form-control" value="<?php echo wsv($settings['api_phone_number_id']); ?>" placeholder="e.g. 109876543210987">
            </div>
            <div class="col-md-4 mb-3">
                <label class="fw-bold small mb-1">Business Account ID <span class="text-muted fw-normal">(ঐচ্ছিক)</span></label>
                <input type="text" name="api_business_account_id" class="form-control" value="<?php echo wsv($settings['api_business_account_id']); ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="fw-bold small mb-1">API Version</label>
                <input type="text" name="api_version" class="form-control" value="<?php echo wsv($settings['api_version'] ?: 'v20.0'); ?>" placeholder="v20.0">
            </div>
            <div class="col-md-8 mb-3">
                <label class="fw-bold small mb-1">Access Token <span class="text-danger">*</span></label>
                <input type="text" name="api_access_token" class="form-control" value="<?php echo wsv($settings['api_access_token']); ?>" placeholder="Meta Graph API permanent/system-user access token">
            </div>
            <div class="col-md-4 mb-3 d-flex align-items-end">
                <div class="d-flex align-items-center gap-2">
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input wa-toggle" type="checkbox" role="switch" name="api_enabled" id="api_enabled" <?php echo !empty($settings['api_enabled']) ? 'checked' : ''; ?> style="width:2.5em;height:1.3em;">
                    </div>
                    <label for="api_enabled" class="mb-0 small">WhatsApp API সচল রাখো (Enable)</label>
                </div>
            </div>
        </div>
        <div class="alert alert-light border small">
            <i class="fa-solid fa-circle-info text-primary"></i>
            Meta Business Suite &gt; WhatsApp &gt; API Setup থেকে Phone Number ID পাবেন। Access Token হিসেবে
            সাময়িক Test Token না বসিয়ে System User দিয়ে বানানো Permanent Access Token ব্যবহার করা ভালো, নাহলে
            কিছুদিন পর টোকেন এক্সপায়ার হয়ে মেসেজ পাঠানো বন্ধ হয়ে যাবে। প্রথমবার (কাস্টমারের ২৪ ঘণ্টা session window
            ছাড়া) মেসেজ পাঠাতে Meta-তে আগে থেকে Approve করা Template লাগবে।
        </div>
        <div class="text-end">
            <button type="submit" name="save_whatsapp_settings" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;">Update</button>
        </div>
    </form>

    <hr class="my-4">
    <h6 class="fw-bold text-primary mb-3"><i class="fa-solid fa-vial"></i> Test WhatsApp মেসেজ পাঠান</h6>
    <div class="small text-muted mb-2">এটা টেক্সট মেসেজ পাঠাবে (Template না), তাই শুধু ঐ নম্বর থেকে আপনার WhatsApp Business নম্বরে সম্প্রতি (২৪ ঘণ্টার মধ্যে) মেসেজ করা থাকলেই যাবে।</div>
    <form method="POST" action="index.php?page=whatsapp_settings" class="row g-2 align-items-center">
        <?php echo csrf_field(); ?>
        <div class="col-sm-4">
            <input type="tel" name="test_phone" class="form-control phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="১১ ডিজিটের মোবাইল নম্বর দিন" placeholder="01XXXXXXXXX" required>
        </div>
        <div class="col-sm-3">
            <button type="submit" name="send_test_whatsapp" class="btn btn-outline-success fw-bold w-100"><i class="fa-brands fa-whatsapp"></i> Send Test</button>
        </div>
    </form>

    <div class="small text-muted mt-3">
        সর্বশেষ আপডেট হয়েছে: <?php echo !empty($settings['updated_at']) ? htmlspecialchars($settings['updated_at']) : 'এখনো আপডেট হয়নি'; ?>
    </div>
</div>

<style>
.wa-toggle { background-color: #ef4444; border-color: #ef4444; }
.wa-toggle:checked { background-color: #1e3a8a; border-color: #1e3a8a; }
</style>
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
