<?php
require_once __DIR__ . '/../config/saas_lang.php';
$lang = saas_current_lang();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang === 'bn' ? 'ব্যবহারের শর্তাবলী — স্কুল ইআরপি' : 'Terms of Service — School ERP'; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: <?php echo ($lang === 'bn') ? "'Hind Siliguri', sans-serif" : "'Inter', sans-serif"; ?>; }</style>
</head>
<body class="bg-slate-50 text-slate-800 antialiased p-6 sm:p-12">
    <div class="max-w-4xl mx-auto bg-white rounded-3xl p-8 sm:p-12 shadow-sm border border-slate-200">
        <a href="index.php" class="text-xs font-bold text-emerald-600 hover:underline mb-6 inline-block">← <?php echo $lang === 'bn' ? 'হোমপেজে ফিরে যান' : 'Back to Home'; ?></a>
        <h1 class="text-3xl font-extrabold text-slate-900 mb-6"><?php echo $lang === 'bn' ? 'নিয়ম ও ব্যবহারের শর্তাবলী (Terms of Service)' : 'Terms of Service'; ?></h1>
        <div class="prose prose-slate text-sm leading-relaxed space-y-6 text-slate-600">
            <h3 class="text-lg font-bold text-slate-900"><?php echo $lang === 'bn' ? '১. সেবার আওতা ও লাইসেন্স' : '1. Scope of Service & Subscription License'; ?></h3>
            <p><?php echo $lang === 'bn' ? 'স্কুল ইআরপি সাবস্ক্রিপশন ভিত্তিক একটি ক্লাউড সফটওয়্যার। প্রতিষ্ঠানটি তাদের নির্বাচিত প্যাকেজ অনুযায়ী সফটওয়্যার ব্যবহারের পূর্ণ প্রবেশাধিকার পাবে।' : 'School ERP is offered as a Cloud Software-as-a-Service (SaaS). Subscribers enjoy full operational access in accordance with their designated tier.'; ?></p>

            <h3 class="text-lg font-bold text-slate-900"><?php echo $lang === 'bn' ? '২. সাবস্ক্রিপশন ও নবায়ন' : '2. Billing & Subscription Renewals'; ?></h3>
            <p><?php echo $lang === 'bn' ? 'প্যাকেজের মেয়াদোত্তীর্ণ হওয়ার পূর্বে নবায়ন সম্পন্ন করতে হবে। বিলিং সংক্রান্ত সহায়তা ও ইনভয়েস মাস্টার প্যানেল থেকে পর্যবেক্ষণ করা যাবে।' : 'Subscriptions are billed on a monthly or annual cycle. Renewal notifications and automated invoices are managed seamlessly.'; ?></p>

            <h3 class="text-lg font-bold text-slate-900"><?php echo $lang === 'bn' ? '৩. কারিগরি সহায়তা ও এসএলএ' : '3. Technical Support & SLA'; ?></h3>
            <p><?php echo $lang === 'bn' ? 'গ্রাহকদের জন্য সার্বক্ষণিক ফোন ও অনলাইন সহায়তা প্রদান করা হয়। নির্ধারিত সিস্টেম মেইনটেন্যান্স ব্যতীত সিস্টেমের আপটাইম ৯৯.৯% নিশ্চিত করা হয়।' : '24/7 priority customer support is included with 99.9% targeted cloud availability.'; ?></p>
        </div>
    </div>
</body>
</html>
