<?php
// admin/result_exam_fee_settings.php - Result Module > Exam Procedure > Exam Fee Gateway Settings
//
// এই পেজে আর আলাদা করে কোনো গেটওয়ে সেটিংস সেট করা হয় না। পুরো সিস্টেমে (Admission Fee +
// Exam Fee + Monthly/Tuition Fee) একটাই Global Payment Gateway সেটিংস থাকে —
// Global Settings > Payment Gateway Settings পেজে Auto (SSLCommerz/ShurjoPay/PayStation/EPS)
// আর Manual (bKash/Nagad) দুটোই একবার সেট করলেই সব জায়গায় কাজ করে।
// এই পেজ এখন Exam Fee-এর জন্য সেই সেটিংসের বর্তমান অবস্থা (Status) দেখায় আর Exam Fee
// কনফিগার করার ধাপগুলো মনে করিয়ে দেয়।
//
// Exam Fee এমাউন্ট আর Collection পুরোপুরি Finance Module থেকে হয় — Finance > Account Head এ
// "Exam Fee" নামে একটা Head বানিয়ে, Finance > Monthly Fees Settings এ ক্লাস-ভিত্তিক এমাউন্ট
// সেট করে, তারপর Result > Exam Terms পেজে সেই Head + মাস কোন Exam Term-এর সাথে যুক্ত তা
// বেছে নিতে হবে।
require_once __DIR__ . '/../config/exam_fee_helpers.php';
require_once __DIR__ . '/../config/admission_fee_helpers.php';
ef_ensure_tables($pdo);
af_ensure_tables($pdo);

function efsv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$gateway = ef_gateway_settings($pdo);          // Manual bKash/Nagad — Global সেটিংস থেকেই আসে
$auto_gw = af_gateway_settings($pdo);
$auto_ready = af_auto_gateway_ready($auto_gw);
$auto_provider_label = af_provider_label($auto_gw['auto_provider'] ?? 'sslcommerz');
$manual_ready = !empty($gateway['is_enabled']) && (!empty($gateway['bkash_number']) || !empty($gateway['nagad_number']));
?>
<div class="card-custom">

    <div class="alert alert-info small">
        <i class="fa-solid fa-circle-info"></i>
        Exam Fee এমাউন্ট ও কালেকশন সম্পূর্ণ <strong>Finance Module</strong> থেকে নিয়ন্ত্রিত হয়:
        <ol class="mb-0 mt-2">
            <li><a href="index.php?page=finance_head" class="fw-bold">Finance &gt; Account Head</a> এ "Exam Fee" নামে একটা Head তৈরি করুন (একবারই লাগবে)।</li>
            <li><a href="index.php?page=finance_monthly_fees" class="fw-bold">Finance &gt; Monthly Fees Settings</a> এ প্রতিটা Class-এর জন্য কোন মাসে কত Exam Fee তা সেট করুন।</li>
            <li><a href="index.php?page=result_exam_terms" class="fw-bold">Result &gt; Exam Terms</a> পেজে গিয়ে প্রতিটা Term-এর সাথে সেই মাস + Account Head যুক্ত করে দিন।</li>
            <li>অফিসে সরাসরি ফি জমা নিলে <a href="index.php?page=finance_fees_collection" class="fw-bold">Finance &gt; Fees Collection</a> পেজ থেকে কালেকশন করুন — Seat Plan/Admit Card স্বয়ংক্রিয়ভাবে আনলক হয়ে যাবে।</li>
        </ol>
    </div>

    <div class="alert alert-secondary small d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <i class="fa-solid fa-sliders me-1"></i>
            <strong>Payment Gateway সেটিংস এখন একটাই জায়গায়।</strong>
            Auto আর Manual — দুটোই <strong>Global Settings &gt; Payment Gateway Settings</strong> পেজ থেকে একবার সেট করলেই
            Admission Fee, Exam Fee আর Monthly/Tuition Fee — সব জায়গায় সেটাই কাজ করে। এই পেজে আলাদা করে কিছু সেট করার দরকার নেই।
        </div>
        <a href="index.php?page=admission_payment_settings" class="btn btn-sm btn-primary fw-bold text-nowrap">
            Payment Gateway Settings <i class="fa-solid fa-arrow-right ms-1"></i>
        </a>
    </div>

    <h6 class="fw-bold mb-3 mt-4"><i class="fa-solid fa-circle-check me-2 text-primary"></i>বর্তমান অবস্থা (Exam Fee অনলাইন পেমেন্ট)</h6>
    <div class="row g-3">

        <div class="col-md-6">
            <div class="p-3 rounded h-100" style="background:<?php echo $auto_ready ? '#ecfdf5' : '#fff7ed'; ?>;border:1px solid <?php echo $auto_ready ? '#a7f3d0' : '#fed7aa'; ?>;">
                <div class="fw-bold mb-2" style="font-size:13.5px;">
                    <i class="fa-solid fa-globe me-1"></i> Auto Payment (অনলাইন গেটওয়ে)
                    <span class="badge <?php echo $auto_ready ? 'bg-success' : 'bg-warning text-dark'; ?> ms-1"><?php echo $auto_ready ? 'চালু' : 'বন্ধ'; ?></span>
                </div>
                <?php if ($auto_ready): ?>
                    <p class="mb-0 text-muted" style="font-size:12.5px;">
                        <strong><?php echo efsv($auto_provider_label); ?></strong> চালু আছে। Student পোর্টালের Admit Card পেজে "Pay Online" বাটন দেখাবে —
                        পেমেন্ট সফল হওয়ার সাথে সাথেই Finance-এ Paid এন্ট্রি বসে যাবে আর Admit Card/Seat Plan আনলক হয়ে যাবে (Admin-এর Approve লাগবে না)।
                    </p>
                <?php else: ?>
                    <p class="mb-0 text-muted" style="font-size:12.5px;">
                        এখনো কনফিগার করা নেই। Global Settings &gt; Payment Gateway Settings পেজে SSLCommerz/ShurjoPay/PayStation/EPS এর যেকোনো একটা
                        Credential দিয়ে চালু করলে Admission Fee, Exam Fee, Monthly Fee — সবখানেই একসাথে চালু হয়ে যাবে।
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-md-6">
            <div class="p-3 rounded h-100" style="background:<?php echo $manual_ready ? '#ecfdf5' : '#f8fafc'; ?>;border:1px solid <?php echo $manual_ready ? '#a7f3d0' : '#e2e8f0'; ?>;">
                <div class="fw-bold mb-2" style="font-size:13.5px;">
                    <i class="fa-solid fa-mobile-screen-button me-1"></i> Manual Payment (bKash / Nagad)
                    <span class="badge <?php echo $manual_ready ? 'bg-success' : 'bg-secondary'; ?> ms-1"><?php echo $manual_ready ? 'চালু' : 'বন্ধ'; ?></span>
                </div>
                <?php if ($manual_ready): ?>
                    <ul class="mb-2 ps-3 text-muted" style="font-size:12.5px;">
                        <?php if (!empty($gateway['bkash_number'])): ?>
                            <li>bKash: <strong><?php echo efsv($gateway['bkash_number']); ?></strong> (<?php echo efsv($gateway['bkash_type']); ?>)</li>
                        <?php endif; ?>
                        <?php if (!empty($gateway['nagad_number'])): ?>
                            <li>Nagad: <strong><?php echo efsv($gateway['nagad_number']); ?></strong> (<?php echo efsv($gateway['nagad_type']); ?>)</li>
                        <?php endif; ?>
                    </ul>
                    <p class="mb-0 text-muted" style="font-size:12.5px;">
                        Student এই নাম্বারে টাকা পাঠিয়ে Transaction ID জমা দিতে পারবে।
                        জমা দেওয়া Transaction গুলো <a href="index.php?page=result_exam_fee_collection" class="fw-bold">Exam Fee Online Payments</a> পেজে
                        Approve করলেই Finance-এ Paid এন্ট্রি হয়ে যাবে।
                    </p>
                <?php else: ?>
                    <p class="mb-0 text-muted" style="font-size:12.5px;">
                        Manual bKash/Nagad বন্ধ আছে — Student-দের সরাসরি School Office-এ গিয়ে ফি জমা দিতে বলা হবে।
                        চালু করতে Global Settings &gt; Payment Gateway Settings পেজের Manual সেকশনে নাম্বার বসিয়ে Enable করুন।
                    </p>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <?php if (!empty($gateway['instructions'])): ?>
        <div class="alert alert-light border small mt-3 mb-0">
            <strong>Student-দের জন্য নির্দেশনা:</strong> <?php echo nl2br(efsv($gateway['instructions'])); ?>
        </div>
    <?php endif; ?>
</div>
