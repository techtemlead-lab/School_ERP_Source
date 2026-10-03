<?php
require_once __DIR__ . '/../config/saas_lang.php';
$lang = saas_current_lang();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang === 'bn' ? 'প্রাইভেসি পলিসি — স্কুল ইআরপি' : 'Privacy Policy — School ERP'; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: <?php echo ($lang === 'bn') ? "'Hind Siliguri', sans-serif" : "'Inter', sans-serif"; ?>; }</style>
</head>
<body class="bg-slate-50 text-slate-800 antialiased p-6 sm:p-12">
    <div class="max-w-4xl mx-auto bg-white rounded-3xl p-8 sm:p-12 shadow-sm border border-slate-200">
        <a href="index.php" class="text-xs font-bold text-emerald-600 hover:underline mb-6 inline-block">← <?php echo $lang === 'bn' ? 'হোমপেজে ফিরে যান' : 'Back to Home'; ?></a>
        <h1 class="text-3xl font-extrabold text-slate-900 mb-6"><?php echo $lang === 'bn' ? 'ডেটা প্রাইভেসি ও নিরাপত্তা পলিসি' : 'Data Privacy & Security Policy'; ?></h1>
        <div class="prose prose-slate text-sm leading-relaxed space-y-6 text-slate-600">
            <p><?php echo $lang === 'bn' ? 'স্কুল ইআরপি প্রতিটি শিক্ষা প্রতিষ্ঠানের তথ্যের শতভাগ গোপনীয়তা ও সুরক্ষার নিশ্চয়তা প্রদান করে। আমাদের ক্লাউড সিস্টেমে আপনার প্রতিষ্ঠানের ডেটা কীভাবে সুরক্ষিত রাখা হয় তা নিচে স্পষ্ট করা হলো:' : 'School ERP guarantees 100% data confidentiality and protection for every educational institution. Here is how your data is safeguarded in our cloud architecture:'; ?></p>
            
            <h3 class="text-lg font-bold text-slate-900"><?php echo $lang === 'bn' ? '১. ডেডিকেটেড ডেটাবেজ আইসোলেশন' : '1. Dedicated Database Isolation'; ?></h3>
            <p><?php echo $lang === 'bn' ? 'প্রতিটি ক্লায়েন্ট স্কুলের জন্য সম্পূর্ণ পৃথক ও সুরক্ষিত ডেটাবেজ বরাদ্দ থাকে। কোনো অবস্থাতেই এক প্রতিষ্ঠানের ডেটা অন্য প্রতিষ্ঠানের সাথে মিশ্রিত হয় না।' : 'Each educational institution is provisioned with a fully isolated and dedicated database. Data from one school can never be accessed or viewed by another.'; ?></p>

            <h3 class="text-lg font-bold text-slate-900"><?php echo $lang === 'bn' ? '২. শিক্ষার্থী ও অভিভাবকের তথ্যের গোপনীয়তা' : '2. Student & Parent Confidentiality'; ?></h3>
            <p><?php echo $lang === 'bn' ? 'শিক্ষার্থীদের ব্যক্তিগত তথ্য, মোবাইল নম্বর, ফি আদায়ের হিসাব কোনো তৃতীয় পক্ষের কাছে শেয়ার বা বিক্রি করা হয় না।' : 'Personal data of students, parent phone numbers, and financial transactions are never shared, monetized, or exposed to third parties.'; ?></p>

            <h3 class="text-lg font-bold text-slate-900"><?php echo $lang === 'bn' ? '৩. অটোমেটিক ব্যাকআপ ও রিকভারি' : '3. Automated Backups & Disaster Recovery'; ?></h3>
            <p><?php echo $lang === 'bn' ? 'সার্ভারে প্রতিদিন স্বয়ংক্রিয় ব্যাকআপ তৈরি হয় এবং ক্লাউডে সংরক্ষিত থাকে। কোনো প্রযুক্তিগত সমস্যা দেখা দিলে কয়েক মিনিটের মধ্যে ডেটা রিকভার করা সম্ভব।' : 'Automatic offsite backups are taken every 24 hours. Full restoration can be achieved within minutes in the event of hardware or system failures.'; ?></p>
        </div>
    </div>
</body>
</html>
