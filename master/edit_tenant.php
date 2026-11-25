<?php
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_features.php';
require_once __DIR__ . '/../config/branding_policy.php';
masterRequireAccess($pdo_master, 'client_list', 'edit');
ensureTenantBlockedStatus($pdo_master);
ensureTenantBrandingPolicyColumn($pdo_master);

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo_master->prepare("SELECT * FROM tenants WHERE id=?");
$stmt->execute([$id]);
$tenant = $stmt->fetch();
if (!$tenant) { header('Location: client_list.php'); exit; }

$branding_surfaces = erp_branding_surfaces();
$branding_policies = erp_branding_policies();
$branding_policy   = erp_branding_decode_policy($tenant['branding_policy_json'] ?? null);

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করো।';
    } else {
        $school_name   = trim($_POST['school_name'] ?? '');
        $subdomain     = trim($_POST['subdomain'] ?? '') ?: null;
        $custom_domain = trim($_POST['custom_domain'] ?? '') ?: null;
        $plan          = $_POST['plan'] ?? 'Trial';
        $status        = $_POST['status'] ?? 'Active';
        $contact_name  = trim($_POST['contact_name'] ?? '');
        $contact_phone = trim($_POST['contact_phone'] ?? '');
        $contact_email = trim($_POST['contact_email'] ?? '');
        $trial_ends_at = trim($_POST['trial_ends_at'] ?? '') ?: null;
        $subscription_ends_at = trim($_POST['subscription_ends_at'] ?? '') ?: null;

        if ($school_name === '') {
            $errors[] = 'স্কুলের নাম আবশ্যক।';
        }

        // subdomain/custom_domain ইউনিক কিনা চেক (নিজেকে বাদ দিয়ে)
        if (!$errors && $subdomain) {
            $chk = $pdo_master->prepare("SELECT id FROM tenants WHERE subdomain = ? AND id != ?");
            $chk->execute([$subdomain, $id]);
            if ($chk->fetch()) $errors[] = 'এই Subdomain অন্য স্কুলে ব্যবহৃত হচ্ছে।';
        }
        if (!$errors && $custom_domain) {
            $chk = $pdo_master->prepare("SELECT id FROM tenants WHERE custom_domain = ? AND id != ?");
            $chk->execute([$custom_domain, $id]);
            if ($chk->fetch()) $errors[] = 'এই Custom Domain অন্য স্কুলে ব্যবহৃত হচ্ছে।';
        }

        if (!$errors) {
            $bp_in = ['default' => (string) ($_POST['branding_default'] ?? 'allow')];
            $bp_post = is_array($_POST['branding_surface'] ?? null) ? $_POST['branding_surface'] : [];
            foreach ($branding_surfaces as $sk => $_smeta) {
                $bp_in[$sk] = (string) ($bp_post[$sk] ?? $bp_in['default']);
            }
            $branding_json = erp_branding_encode_policy($bp_in);

            $pdo_master->prepare("UPDATE tenants SET school_name=?, subdomain=?, custom_domain=?, plan=?, status=?, contact_name=?, contact_phone=?, contact_email=?, branding_policy_json=?, trial_ends_at=?, subscription_ends_at=? WHERE id=?")
                ->execute([$school_name, $subdomain, $custom_domain, $plan, $status, $contact_name, $contact_phone, $contact_email, $branding_json, $trial_ends_at, $subscription_ends_at, $id]);

            logActivity($pdo_master, $id, 'tenant_updated', $school_name);

            $stmt->execute([$id]);
            $tenant = $stmt->fetch();
            $branding_policy = erp_branding_decode_policy($tenant['branding_policy_json'] ?? null);
            $success = true;
        }
    }
}

$pageTitle = 'স্কুল এডিট করো';
$pageIcon  = '✏️';
$activeKey = 'client_list';
require_once __DIR__ . '/_layout_top.php';
?>
    <div style="max-width: 720px;">
        <a href="client_list.php" class="btn btn-sm btn-outline-secondary mb-3">&larr; তালিকায় ফিরে যাও</a>

        <?php if ($success): ?><div class="alert alert-success">✅ আপডেট সফল হয়েছে!</div><?php endif; ?>
        <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endforeach; ?>

        <div class="card-custom p-4">
            <form method="POST">
                <?php echo csrf_field(); ?>

                <h6 class="fw-bold mb-3">স্কুলের তথ্য</h6>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">স্কুলের নাম *</label>
                        <input type="text" name="school_name" class="form-control" required value="<?php echo htmlspecialchars($tenant['school_name']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">School Code (অপরিবর্তনীয়)</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($tenant['school_code']); ?>" disabled>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Subdomain</label>
                        <input type="text" name="subdomain" class="form-control" value="<?php echo htmlspecialchars($tenant['subdomain'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Custom Domain</label>
                        <input type="text" name="custom_domain" class="form-control" value="<?php echo htmlspecialchars($tenant['custom_domain'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Plan</label>
                        <select name="plan" class="form-select">
                            <?php foreach (['Trial','Basic','Standard','Premium'] as $p): ?>
                            <option value="<?php echo $p; ?>" <?php echo $tenant['plan'] === $p ? 'selected' : ''; ?>><?php echo $p; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Status</label>
                        <select name="status" class="form-select">
                            <?php foreach (['Active','Suspended','Blocked','Cancelled'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo $tenant['status'] === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Trial শেষ তারিখ</label>
                        <input type="date" name="trial_ends_at" class="form-control" value="<?php echo htmlspecialchars($tenant['trial_ends_at'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Subscription শেষ তারিখ</label>
                        <input type="date" name="subscription_ends_at" class="form-control" value="<?php echo htmlspecialchars($tenant['subscription_ends_at'] ?? ''); ?>">
                    </div>
                </div>

                <h6 class="fw-bold mb-3 mt-4">যোগাযোগ</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">নাম</label>
                        <input type="text" name="contact_name" class="form-control" value="<?php echo htmlspecialchars($tenant['contact_name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">ফোন</label>
                        <input type="tel" name="contact_phone" class="form-control phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="১১ ডিজিটের মোবাইল নম্বর দিন" value="<?php echo htmlspecialchars($tenant['contact_phone'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">ইমেইল</label>
                        <input type="email" name="contact_email" class="form-control" value="<?php echo htmlspecialchars($tenant['contact_email'] ?? ''); ?>">
                    </div>
                </div>

                <div id="brandingPolicy" class="p-3 rounded mb-4" style="background: var(--sidebar-bg); border: 1px solid var(--sidebar-border);">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                        <h6 class="fw-bold mb-0" style="color: var(--body-text);">"Powered by TechTeam" ব্র্যান্ডিং কন্ট্রোল (প্রতিটি টেম্পলেট / সেকশন অনুযায়ী)</h6>
                        <div class="d-flex flex-wrap gap-1">
                            <button type="button" class="btn btn-sm btn-outline-success" onclick="setAllBrandingPolicy('allow')">সবগুলো Allow (ডিফল্ট)</button>
                            <button type="button" class="btn btn-sm btn-outline-warning" onclick="setAllBrandingPolicy('force_on')">সবগুলো Force ON (লক)</button>
                            <button type="button" class="btn btn-sm btn-outline-info" onclick="setAllBrandingPolicy('force_off')">সবগুলো Force OFF (White-label)</button>
                        </div>
                    </div>
                    <p class="small mb-3" style="color: var(--sidebar-text);">
                        প্রতিটি টেম্পলেট বা সেকশনের জন্য আলাদাভাবে ঠিক করুন স্কুল অ্যাডমিন নিজেরা ব্র্যান্ডিং অন/অফ করতে পারবে কি না। বাই ডিফল্ট সবগুলো <strong>Allow</strong> থাকে। পরিবর্তন করলে শেয়ার্ড মোডে তাৎক্ষণিক এবং আইসোলেটেড স্কুলে ~১ মিনিটের মধ্যে প্রোফাইল সিঙ্ক (`tenant.json`) হয়ে কার্যকর হবে।
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>সেকশন / টেম্পলেট</th>
                                    <th style="min-width: 260px;">পলিসি</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($branding_surfaces as $sk => $smeta): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold small" style="color: var(--body-text);"><?php echo htmlspecialchars($smeta['label']); ?></div>
                                        <div style="font-size: 11.5px; color: var(--sidebar-text);"><?php echo htmlspecialchars($smeta['desc']); ?></div>
                                    </td>
                                    <td>
                                        <select name="branding_surface[<?php echo htmlspecialchars($sk); ?>]" class="form-select form-select-sm branding-policy-sel">
                                            <?php foreach ($branding_policies as $pk => $plbl): ?>
                                            <option value="<?php echo htmlspecialchars($pk); ?>" <?php echo ($branding_policy[$sk] ?? 'allow') === $pk ? 'selected' : ''; ?>><?php echo htmlspecialchars($plbl); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold small" style="color: var(--body-text);">নতুন/ভবিষ্যৎ টেম্পলেটের ডিফল্ট পলিসি</div>
                                        <div style="font-size: 11.5px; color: var(--sidebar-text);">ভবিষ্যতে যুক্ত নতুন কোনো সেকশনের ক্ষেত্রে অটো প্রযোজ্য হবে</div>
                                    </td>
                                    <td>
                                        <select name="branding_default" class="form-select form-select-sm branding-policy-sel">
                                            <?php foreach ($branding_policies as $pk => $plbl): ?>
                                            <option value="<?php echo htmlspecialchars($pk); ?>" <?php echo ($branding_policy['default'] ?? 'allow') === $pk ? 'selected' : ''; ?>><?php echo htmlspecialchars($plbl); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="alert alert-secondary" style="font-size:12px;">
                    DB Host: <code><?php echo htmlspecialchars($tenant['db_host']); ?></code> ·
                    DB Name: <code><?php echo htmlspecialchars($tenant['db_name']); ?></code>
                    — ডাটাবেজ কানেকশন তথ্য এখান থেকে বদলানো যায় না (ভুল হলে স্কুলের ডাটা বিচ্ছিন্ন হয়ে যাবে)। বদলানোর দরকার হলে সরাসরি DB-তে গিয়ে করো।
                </div>

                <button type="submit" class="btn btn-primary fw-bold w-100 py-2">সেভ করো</button>
            </form>
        </div>
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

function setAllBrandingPolicy(val) {
    document.querySelectorAll('.branding-policy-sel').forEach(function (sel) {
        sel.value = val;
    });
}
</script>
