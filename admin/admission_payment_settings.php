<?php
// admin/admission_payment_settings.php - Global Settings > Payment Gateway Settings
// Online Admission Form-এ Admission Fee নেওয়ার জন্য দুটো পদ্ধতি এখানে কনফিগার করা হয়:
// (১) Manual — bKash/Nagad নাম্বারে Send Money করে Sender Number + Transaction ID জমা দেবে,
//     Admin পরে "Fee Claims / Payments" পেজ থেকে Approve করলে Finance-এ Paid এন্ট্রি বসে।
// (২) Auto — একটা অনলাইন Payment Gateway (SSLCommerz / ShurjoPay / PayStation / EPS — যেকোনো
//     একটা একসাথে Active রাখা যায়) দিয়ে সরাসরি অনলাইনে কার্ড/bKash/Nagad/Rocket দিয়ে পে করে
//     সাথে সাথেই Paid হয়ে যায়।
// উপরে একটা মাস্টার টগল আছে — বন্ধ থাকলে Admission Form-এ কোনো Payment ধাপই দেখাবে না,
// আবেদন আগের মতোই সরাসরি জমা হবে (ফি নেওয়া লাগবে না)।
// ফি এমাউন্ট এখানে সেট হয় না — সেটা Admission Test/Lottery > Settings পেজে Session+Class
// ভিত্তিক সেট করতে হয় (admission_settings.admission_fee)।
require_once __DIR__ . '/../config/admission_fee_helpers.php';
af_ensure_tables($pdo);

function apsv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$aps_msg = $_SESSION['aps_msg'] ?? '';
$aps_err = $_SESSION['aps_err'] ?? '';
unset($_SESSION['aps_msg'], $_SESSION['aps_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_gateway'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['aps_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO admission_fee_gateway_settings
                (id, ac_head_id, online_payment_enabled, manual_enabled, bkash_number, bkash_type, nagad_number, nagad_type, manual_instructions,
                 auto_enabled, auto_provider,
                 sslcz_store_id, sslcz_store_password, sslcz_sandbox,
                 shurjopay_username, shurjopay_password, shurjopay_prefix, shurjopay_sandbox,
                 paystation_merchant_id, paystation_password, paystation_sandbox,
                 eps_merchant_id, eps_username, eps_password, eps_hash_key, eps_sandbox, online_head_ids)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?,
                     ?, ?,
                     ?, ?, ?,
                     ?, ?, ?, ?,
                     ?, ?, ?,
                     ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                ac_head_id = VALUES(ac_head_id), online_payment_enabled = VALUES(online_payment_enabled),
                manual_enabled = VALUES(manual_enabled),
                bkash_number = VALUES(bkash_number), bkash_type = VALUES(bkash_type),
                nagad_number = VALUES(nagad_number), nagad_type = VALUES(nagad_type),
                manual_instructions = VALUES(manual_instructions),
                auto_enabled = VALUES(auto_enabled), auto_provider = VALUES(auto_provider),
                sslcz_store_id = VALUES(sslcz_store_id),
                sslcz_store_password = CASE WHEN VALUES(sslcz_store_password) = '' THEN sslcz_store_password ELSE VALUES(sslcz_store_password) END,
                sslcz_sandbox = VALUES(sslcz_sandbox),
                shurjopay_username = VALUES(shurjopay_username),
                shurjopay_password = CASE WHEN VALUES(shurjopay_password) = '' THEN shurjopay_password ELSE VALUES(shurjopay_password) END,
                shurjopay_prefix = VALUES(shurjopay_prefix), shurjopay_sandbox = VALUES(shurjopay_sandbox),
                paystation_merchant_id = VALUES(paystation_merchant_id),
                paystation_password = CASE WHEN VALUES(paystation_password) = '' THEN paystation_password ELSE VALUES(paystation_password) END,
                paystation_sandbox = VALUES(paystation_sandbox),
                eps_merchant_id = VALUES(eps_merchant_id), eps_username = VALUES(eps_username),
                eps_password = CASE WHEN VALUES(eps_password) = '' THEN eps_password ELSE VALUES(eps_password) END,
                eps_hash_key = CASE WHEN VALUES(eps_hash_key) = '' THEN eps_hash_key ELSE VALUES(eps_hash_key) END,
                eps_sandbox = VALUES(eps_sandbox),
                online_head_ids = VALUES(online_head_ids)"
        );

        // অনলাইনে পরিশোধযোগ্য Account Head গুলো (চেকবক্স) — কিছু না বাছাই করলে সব হেডই চালু থাকবে
        $posted_heads = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['online_head_ids'] ?? [])), fn($v) => $v > 0
        )));
        $stmt->execute([
            (int) ($_POST['ac_head_id'] ?? 0) ?: null,
            isset($_POST['online_payment_enabled']) ? 1 : 0,
            isset($_POST['manual_enabled']) ? 1 : 0,
            trim($_POST['bkash_number'] ?? ''),
            $_POST['bkash_type'] ?? 'Personal',
            trim($_POST['nagad_number'] ?? ''),
            $_POST['nagad_type'] ?? 'Personal',
            trim($_POST['manual_instructions'] ?? ''),
            isset($_POST['auto_enabled']) ? 1 : 0,
            $_POST['auto_provider'] ?? 'sslcommerz',
            trim($_POST['sslcz_store_id'] ?? ''),
            trim($_POST['sslcz_store_password'] ?? ''), // খালি রাখলে পুরনো Password-ই থেকে যাবে
            isset($_POST['sslcz_sandbox']) ? 1 : 0,
            trim($_POST['shurjopay_username'] ?? ''),
            trim($_POST['shurjopay_password'] ?? ''),
            trim($_POST['shurjopay_prefix'] ?? ''),
            isset($_POST['shurjopay_sandbox']) ? 1 : 0,
            trim($_POST['paystation_merchant_id'] ?? ''),
            trim($_POST['paystation_password'] ?? ''),
            isset($_POST['paystation_sandbox']) ? 1 : 0,
            trim($_POST['eps_merchant_id'] ?? ''),
            trim($_POST['eps_username'] ?? ''),
            trim($_POST['eps_password'] ?? ''),
            trim($_POST['eps_hash_key'] ?? ''),
            isset($_POST['eps_sandbox']) ? 1 : 0,
            implode(',', $posted_heads),
        ]);
        sec_log_activity($pdo, 'Admission', 'Update', 'Admission Payment Gateway সেটিংস আপডেট করা হয়েছে');
        $_SESSION['aps_msg'] = 'Payment Gateway সেটিংস সেভ হয়েছে।';
    }
    header('Location: index.php?page=admission_payment_settings');
    exit();
}

$gateway = af_gateway_settings($pdo);
$fee_heads = $pdo->query("SELECT id, ac_code, ac_head, ac_type FROM finance_account_heads WHERE has_child = 'No' AND status = 'Active' ORDER BY ac_code ASC")->fetchAll();
$selected_heads = af_online_head_ids($gateway); // খালি = সব হেড অনলাইনে নেওয়া হবে
$active_provider = $gateway['auto_provider'] ?? 'sslcommerz';
?>
<div class="card-custom">
    <?php if ($aps_msg): ?><div class="alert alert-success py-2"><?php echo apsv($aps_msg); ?></div><?php endif; ?>
    <?php if ($aps_err): ?><div class="alert alert-danger py-2"><?php echo apsv($aps_err); ?></div><?php endif; ?>

    <div class="alert alert-info small">
        <i class="fa-solid fa-circle-info me-1"></i>
        <strong>এই পেজের Payment Gateway সেটিংস (Auto + Manual দুটোই) পুরো সিস্টেমে কাজ করে।</strong>
        একবার এখানে সেট করলেই নিচের সব জায়গায় একই গেটওয়ে/নাম্বার ব্যবহার হবে — আলাদা করে আর কোথাও সেট করতে হবে না:
        <ul class="mb-0 mt-2">
            <li><strong>Admission Fee</strong> — অনলাইন Admission Form (পেমেন্ট সফল হলেই ফর্ম জমা হবে)</li>
            <li><strong>Exam Fee</strong> — Student পোর্টালের Admit Card / Seat Plan পেজ (পেমেন্টের সাথে সাথেই আনলক)</li>
            <li><strong>Monthly / Tuition / Session Fee</strong> — Student পোর্টালের My Dues পেজ (মাসভিত্তিক সব ফি একসাথে)</li>
        </ul>
    </div>

    <form method="POST">
        <?php echo csrf_field(); ?>

        <!-- ---------------- মাস্টার টগল ---------------- -->
        <div class="d-flex align-items-center justify-content-between border rounded p-3 mb-4" style="background:#f8f9fb;">
            <div>
                <h5 class="mb-1"><i class="fa-solid fa-credit-card me-2"></i>Online Admission Fee Payment</h5>
                <p class="text-muted small mb-0">
                    বন্ধ থাকলে Admission Form-এ কোনো Payment ধাপ দেখাবে না — আগের মতোই সরাসরি ফ্রি আবেদন জমা হবে।
                    ফি এমাউন্ট আলাদা করে সেট করতে হয় <a href="index.php?page=admission_settings">Admission Test/Lottery &gt; Settings</a> পেজে।
                </p>
            </div>
            <div class="form-check form-switch fs-4">
                <input class="form-check-input" type="checkbox" name="online_payment_enabled" id="onlinePaymentEnabled" <?php echo !empty($gateway['online_payment_enabled']) ? 'checked' : ''; ?>>
            </div>
        </div>

        <hr>
        <h6 class="fw-bold mb-2"><i class="fa-solid fa-list-check me-2 text-primary"></i>কোন কোন Fee অনলাইনে নেওয়া হবে (Account Head)</h6>
        <p class="text-muted small mb-2">
            নিচের হেডগুলো সরাসরি <a href="index.php?page=finance_head">Finance &gt; Account Head</a> থেকে আসছে।
            <strong>বাম পাশে টিক দিন</strong> — শুধু সেই ফি-গুলোই Student পোর্টালে অনলাইনে (Auto Gateway বা Manual bKash/Nagad) পরিশোধ করা যাবে,
            বাকিগুলো অফিসে সরাসরি জমা দিতে হবে। একটাও টিক না দিলে সব হেডই অনলাইনে নেওয়া হবে।
        </p>
        <?php if (!$fee_heads): ?>
            <div class="alert alert-warning small py-2 mb-4">Finance &gt; Account Head এ এখনো কোনো Active হেড তৈরি করা হয়নি।</div>
        <?php else: ?>
            <div class="border rounded mb-2" style="background:#f8fafc;max-height:320px;overflow-y:auto;">
                <table class="table table-sm align-middle mb-0" style="font-size:13px;">
                    <thead class="table-light" style="position:sticky;top:0;z-index:1;">
                        <tr>
                            <th style="width:210px;">
                                অনলাইনে নেওয়া হবে?
                                <div class="fw-normal mt-1">
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="apsToggleHeads(true)">সব</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="apsToggleHeads(false)">কোনোটাই না</button>
                                    <span class="text-muted ms-1" style="font-size:11px;">(<span id="apsHeadCount"><?php echo count($selected_heads); ?></span>/<?php echo count($fee_heads); ?>)</span>
                                </div>
                            </th>
                            <th>Account Head</th>
                            <th style="width:230px;">
                                Admission Form-এর ফি এই হেডে বসবে
                                <div class="fw-normal text-muted" style="font-size:11px;">(যেকোনো একটা)</div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fee_heads as $h): $hid = (int) $h['id']; ?>
                            <tr>
                                <td>
                                    <div class="form-check mb-0">
                                        <input class="form-check-input aps-head-cb" type="checkbox" name="online_head_ids[]"
                                               value="<?php echo $hid; ?>" id="apsHead<?php echo $hid; ?>"
                                               onchange="apsCountHeads()" <?php echo in_array($hid, $selected_heads, true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="apsHead<?php echo $hid; ?>">অনলাইনে নেব</label>
                                    </div>
                                </td>
                                <td>
                                    <strong><?php echo apsv(trim($h['ac_code'] . ' - ' . $h['ac_head'])); ?></strong>
                                    <?php if (($h['ac_type'] ?? '') !== ''): ?>
                                        <span class="badge bg-light text-secondary border ms-1" style="font-size:10px;"><?php echo apsv($h['ac_type']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="radio" name="ac_head_id"
                                               value="<?php echo $hid; ?>" id="apsAdmHead<?php echo $hid; ?>"
                                               <?php echo (int) ($gateway['ac_head_id'] ?? 0) === $hid ? 'checked' : ''; ?>>
                                        <label class="form-check-label text-muted" for="apsAdmHead<?php echo $hid; ?>">এই হেডে</label>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td colspan="2" class="text-muted">— কোনো হেড বেছে না দিলে —</td>
                            <td>
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="radio" name="ac_head_id" value="0" id="apsAdmHeadNone"
                                           <?php echo (int) ($gateway['ac_head_id'] ?? 0) === 0 ? 'checked' : ''; ?>>
                                    <label class="form-check-label text-muted" for="apsAdmHeadNone">"Admission Fee" নামের হেডে</label>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="alert alert-light border small mb-4">
                <strong>দুটো কলাম কেন?</strong>
                <ul class="mb-0 mt-1">
                    <li><strong>টিক (বাম কলাম)</strong> — শিক্ষার্থীর <em>Due</em> থেকে কোন ফি-গুলো অনলাইনে নেওয়া হবে।
                        যেমন Tuition + Exam টিক দিলে My Dues পেজে ওই দুটোই অনলাইনে জমা দেওয়া যাবে, Library Fee অফিসে জমা দিতে হবে।
                        Exam Fee-র ক্ষেত্রে Term-এর সাথে যুক্ত হেডটা টিক না দিলে Admit Card পেজে পেমেন্ট অপশন দেখাবে না।</li>
                    <li><strong>রেডিও (ডান কলাম)</strong> — নতুন ভর্তির অনলাইন Admission Form-এর ফি-টা কোন হেডে বসবে।
                        এই ফি Due থেকে আসে না (নতুন ছাত্রের তো এখনো Due নেই), ফর্মে সেট করা একটাই ফিক্সড এমাউন্ট —
                        তাই সেটা কোন হেডে যাবে তা আলাদা করে বলে দিতে হয়। সাধারণত "Admission Fees" হেডটাই বেছে দেবেন।</li>
                </ul>
            </div>
        <?php endif; ?>

        <hr>
        <h6 class="fw-bold mb-2"><i class="fa-solid fa-mobile-screen-button me-2 text-primary"></i>Manual Payment (bKash / Nagad)</h6>
        <p class="text-muted small">
            অভিভাবক/শিক্ষার্থী নিজে এই নাম্বারে Send Money করে Sender Number + Transaction ID জমা দেবেন — Admin যাচাই করে Approve করলে Finance-এ Paid এন্ট্রি বসবে।
            এই একই নাম্বার তিন জায়গায় ব্যবহার হয় — Admission Fee (<a href="index.php?page=admission_fee_claims">Fee Claims / Payments</a>),
            Exam Fee (<a href="index.php?page=result_exam_fee_collection">Exam Fee Online Payments</a>) এবং
            Monthly/Tuition Fee (<a href="index.php?page=finance_online_payments">Online Fee Payments</a>)।
        </p>
        <div class="row g-3 align-items-center mb-2">
            <div class="col-auto">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="manual_enabled" id="manualEnabled" <?php echo !empty($gateway['manual_enabled']) ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold" for="manualEnabled">Manual Payment চালু রাখুন</label>
                </div>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold" style="font-size:13px;">bKash Number</label>
                <input type="text" name="bkash_number" class="form-control" value="<?php echo apsv($gateway['bkash_number']); ?>" placeholder="01XXXXXXXXX">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold" style="font-size:13px;">bKash Type</label>
                <select name="bkash_type" class="form-select">
                    <option value="Personal" <?php echo ($gateway['bkash_type'] ?? '') === 'Personal' ? 'selected' : ''; ?>>Personal</option>
                    <option value="Merchant" <?php echo ($gateway['bkash_type'] ?? '') === 'Merchant' ? 'selected' : ''; ?>>Merchant</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold" style="font-size:13px;">Nagad Number</label>
                <input type="text" name="nagad_number" class="form-control" value="<?php echo apsv($gateway['nagad_number']); ?>" placeholder="01XXXXXXXXX">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold" style="font-size:13px;">Nagad Type</label>
                <select name="nagad_type" class="form-select">
                    <option value="Personal" <?php echo ($gateway['nagad_type'] ?? '') === 'Personal' ? 'selected' : ''; ?>>Personal</option>
                    <option value="Merchant" <?php echo ($gateway['nagad_type'] ?? '') === 'Merchant' ? 'selected' : ''; ?>>Merchant</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label fw-bold" style="font-size:13px;">অভিভাবকদের জন্য নির্দেশনা (ঐচ্ছিক)</label>
                <textarea name="manual_instructions" class="form-control" rows="2" placeholder="যেমন: উপরের নাম্বারে Send Money করে Transaction ID এখানে জমা দিন।"><?php echo apsv($gateway['manual_instructions']); ?></textarea>
            </div>
        </div>

        <hr class="mt-4">
        <h6 class="fw-bold mb-2"><i class="fa-solid fa-globe me-2 text-primary"></i>Auto Payment (অনলাইন গেটওয়ে)</h6>
        <p class="text-muted small">
            একসাথে একটা গেটওয়েই Active রাখা যায়। পেমেন্ট সফল হলে সাথে সাথেই স্বয়ংক্রিয়ভাবে Finance-এ Paid এন্ট্রি বসে যাবে — Admin Approve করা লাগবে না।
            <strong>এই একই গেটওয়ে সেটিংস পুরো সিস্টেমে ব্যবহার হয়</strong> — Admission Fee (এই পেজ) এবং Exam Fee (Student পোর্টাল &gt; Admit Card পেজে "Pay Online") দুটোই এখান থেকেই Store ID/Password নিয়ে কাজ করবে, আলাদা করে আর কোথাও সেট করতে হবে না।
        </p>
        <div class="row g-3 align-items-center mb-3">
            <div class="col-auto">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="auto_enabled" id="autoEnabled" <?php echo !empty($gateway['auto_enabled']) ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold" for="autoEnabled">Auto Payment চালু রাখুন</label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold mb-1" style="font-size:13px;">Active Gateway</label>
                <select name="auto_provider" id="autoProviderSelect" class="form-select" onchange="apsToggleProvider()">
                    <option value="sslcommerz" <?php echo $active_provider === 'sslcommerz' ? 'selected' : ''; ?>>SSLCommerz</option>
                    <option value="shurjopay" <?php echo $active_provider === 'shurjopay' ? 'selected' : ''; ?>>ShurjoPay</option>
                    <option value="paystation" <?php echo $active_provider === 'paystation' ? 'selected' : ''; ?>>PayStation</option>
                    <option value="eps" <?php echo $active_provider === 'eps' ? 'selected' : ''; ?>>EPS (Easy Payment System)</option>
                </select>
            </div>
        </div>

        <!-- ===== SSLCommerz ===== -->
        <div id="providerBox_sslcommerz" class="border rounded p-3 mb-3" style="background:rgba(169,119,46,0.04);">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong>SSLCommerz</strong>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" name="sslcz_sandbox" id="sslczSandbox" <?php echo !empty($gateway['sslcz_sandbox']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="sslczSandbox">Sandbox (টেস্ট মোড)</label>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" style="font-size:13px;">Store ID</label>
                    <input type="text" name="sslcz_store_id" class="form-control" value="<?php echo apsv($gateway['sslcz_store_id']); ?>" placeholder="yourstoreid">
                </div>
                <div class="col-md-6">
                    <label class="form-label" style="font-size:13px;">Store Password (API/Secret Key)</label>
                    <input type="password" name="sslcz_store_password" class="form-control" placeholder="<?php echo !empty($gateway['sslcz_store_password']) ? '•••••••• (বদলাতে না চাইলে খালি রাখুন)' : 'Store Password'; ?>" autocomplete="new-password">
                </div>
            </div>
            <p class="text-muted small mt-2 mb-0"><a href="https://sslcommerz.com" target="_blank" rel="noopener">sslcommerz.com</a> এ Merchant Account খুলে Store ID/Password এখানে বসান।</p>
        </div>

        <!-- ===== ShurjoPay ===== -->
        <div id="providerBox_shurjopay" class="border rounded p-3 mb-3" style="background:rgba(169,119,46,0.04); display:none;">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong>ShurjoPay</strong>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" name="shurjopay_sandbox" id="shurjopaySandbox" <?php echo !empty($gateway['shurjopay_sandbox']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="shurjopaySandbox">Sandbox (টেস্ট মোড)</label>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" style="font-size:13px;">Username</label>
                    <input type="text" name="shurjopay_username" class="form-control" value="<?php echo apsv($gateway['shurjopay_username']); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" style="font-size:13px;">Password</label>
                    <input type="password" name="shurjopay_password" class="form-control" placeholder="<?php echo !empty($gateway['shurjopay_password']) ? '•••••••• (বদলাতে না চাইলে খালি রাখুন)' : 'Password'; ?>" autocomplete="new-password">
                </div>
                <div class="col-md-4">
                    <label class="form-label" style="font-size:13px;">Prefix / Store ID</label>
                    <input type="text" name="shurjopay_prefix" class="form-control" value="<?php echo apsv($gateway['shurjopay_prefix']); ?>">
                </div>
            </div>
            <p class="text-muted small mt-2 mb-0"><a href="https://shurjopay.com.bd" target="_blank" rel="noopener">shurjopay.com.bd</a> থেকে Merchant Account খুলে এই তথ্য বসান।</p>
        </div>

        <!-- ===== PayStation ===== -->
        <div id="providerBox_paystation" class="border rounded p-3 mb-3" style="background:rgba(169,119,46,0.04); display:none;">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong>PayStation</strong>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" name="paystation_sandbox" id="paystationSandbox" <?php echo !empty($gateway['paystation_sandbox']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="paystationSandbox">Sandbox (টেস্ট মোড)</label>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" style="font-size:13px;">Merchant ID</label>
                    <input type="text" name="paystation_merchant_id" class="form-control" value="<?php echo apsv($gateway['paystation_merchant_id']); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" style="font-size:13px;">Password (API Key)</label>
                    <input type="password" name="paystation_password" class="form-control" placeholder="<?php echo !empty($gateway['paystation_password']) ? '•••••••• (বদলাতে না চাইলে খালি রাখুন)' : 'Password'; ?>" autocomplete="new-password">
                </div>
            </div>
            <p class="text-muted small mt-2 mb-0"><a href="https://www.paystation.com.bd" target="_blank" rel="noopener">paystation.com.bd</a> থেকে Merchant Account খুলে এই তথ্য বসান।</p>
        </div>

        <!-- ===== EPS ===== -->
        <div id="providerBox_eps" class="border rounded p-3 mb-3" style="background:rgba(169,119,46,0.04); display:none;">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong>EPS (Easy Payment System)</strong>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" name="eps_sandbox" id="epsSandbox" <?php echo !empty($gateway['eps_sandbox']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="epsSandbox">Sandbox (টেস্ট মোড)</label>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" style="font-size:13px;">Merchant ID</label>
                    <input type="text" name="eps_merchant_id" class="form-control" value="<?php echo apsv($gateway['eps_merchant_id']); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" style="font-size:13px;">Username</label>
                    <input type="text" name="eps_username" class="form-control" value="<?php echo apsv($gateway['eps_username']); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" style="font-size:13px;">Password</label>
                    <input type="password" name="eps_password" class="form-control" placeholder="<?php echo !empty($gateway['eps_password']) ? '•••••••• (বদলাতে না চাইলে খালি রাখুন)' : 'Password'; ?>" autocomplete="new-password">
                </div>
                <div class="col-md-4">
                    <label class="form-label" style="font-size:13px;">Hash Key</label>
                    <input type="password" name="eps_hash_key" class="form-control" placeholder="<?php echo !empty($gateway['eps_hash_key']) ? '•••••••• (বদলাতে না চাইলে খালি রাখুন)' : 'Hash Key'; ?>" autocomplete="new-password">
                </div>
            </div>
            <p class="text-muted small mt-2 mb-0">
                <a href="https://eps.com.bd" target="_blank" rel="noopener">eps.com.bd</a> থেকে Merchant Account খুলে এই তথ্য বসান।
                <strong>নোট:</strong> EPS-এর ইন্টিগ্রেশন এখানে EPS-এর প্রকাশিত সাধারণ কনভেনশন অনুযায়ী করা হয়েছে — লাইভে যাওয়ার আগে অবশ্যই Sandbox-এ টেস্ট করে নিন, প্রয়োজনে EPS সাপোর্ট (integration@eps.com.bd) থেকে আপনার একাউন্টের এক্স্যাক্ট এন্ডপয়েন্ট যাচাই করে নিন।
            </p>
        </div>

        <button type="submit" name="save_gateway" class="btn btn-primary fw-bold mt-2">Save Gateway Settings</button>
    </form>
</div>

<script>
function apsToggleProvider() {
    const sel = document.getElementById('autoProviderSelect').value;
    ['sslcommerz', 'shurjopay', 'paystation', 'eps'].forEach(p => {
        const box = document.getElementById('providerBox_' + p);
        if (box) box.style.display = (p === sel) ? 'block' : 'none';
    });
}
apsToggleProvider();

// ---- অনলাইনে পরিশোধযোগ্য Account Head চেকবক্স ----
function apsCountHeads() {
    const boxes = document.querySelectorAll('.aps-head-cb');
    let n = 0;
    boxes.forEach(b => { if (b.checked) n++; });
    const el = document.getElementById('apsHeadCount');
    if (el) el.textContent = n;
}
function apsToggleHeads(state) {
    document.querySelectorAll('.aps-head-cb').forEach(b => { b.checked = state; });
    apsCountHeads();
}
apsCountHeads();
</script>
