<?php
/**
 * demo/index.php
 * -----------------------------------------------------------
 * Interactive Demo Sandbox Hub
 * Provides 1-Click Role Logins, Live Countdown Timer to next
 * 60-Minute DB Wipe, and Instant Reset Controls.
 */

session_start();

require_once __DIR__ . '/../config/saas_lang.php';
require_once __DIR__ . '/../cron/reset_demo_db.php';

// Self-healing reset check: If DB hasn't been reset in 1 hour (3600s), trigger it now
$timestamp_file = __DIR__ . '/../cron/demo_last_reset.txt';
$last_reset = file_exists($timestamp_file) ? (int) @file_get_contents($timestamp_file) : 0;
if ($last_reset === 0 || (time() - $last_reset) >= 3600) {
    reset_demo_database(true);
    $last_reset = time();
}

$seconds_elapsed = max(0, time() - $last_reset);
$seconds_remaining = max(0, 3600 - $seconds_elapsed);

$current_lang = saas_current_lang();
$other_lang = ($current_lang === 'bn') ? 'en' : 'bn';
$other_lang_label = ($current_lang === 'bn') ? 'English' : 'বাংলা';
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ($current_lang === 'bn') ? 'ইন্টারেক্টিভ ডেমো স্যান্ডবক্স | স্মার্ট স্কুল ইআরপি' : 'Interactive Demo Sandbox | Smart School ERP'; ?></title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                            900: '#312e81'
                        }
                    },
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', '"Hind Siliguri"', 'sans-serif'],
                        bn: ['"Hind Siliguri"', 'sans-serif']
                    }
                }
            }
        }
    </script>
    
    <style>
        .gradient-bg {
            background: radial-gradient(circle at 10% 20%, rgba(99, 102, 241, 0.08) 0%, transparent 40%),
                        radial-gradient(circle at 90% 80%, rgba(16, 185, 129, 0.08) 0%, transparent 40%);
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans antialiased min-h-screen flex flex-col">

    <!-- Top Banner -->
    <div class="bg-gradient-to-r from-amber-500 via-orange-500 to-amber-600 text-white text-xs sm:text-sm font-medium py-2 px-4 text-center shadow-inner flex items-center justify-center gap-2">
        <svg class="w-4 h-4 shrink-0 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <span>
            <?php echo ($current_lang === 'bn') 
                ? 'এটি একটি লাইভ স্যান্ডবক্স পরিবেশ। প্রতি ১ ঘণ্টা (৬০ মিনিট) পর পর সমস্ত ডাটাবেজ স্বয়ংক্রিয়ভাবে রিসেট হয়।' 
                : 'This is a live sandbox environment. The entire database resets automatically every 60 minutes.'; ?>
        </span>
    </div>

    <!-- Navigation Header -->
    <header class="bg-white/90 backdrop-blur-md border-b border-slate-200 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <!-- Brand -->
            <a href="../index.php" class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-brand-600 to-emerald-500 flex items-center justify-center text-white font-black text-xl shadow-md shadow-brand-500/20">
                    S
                </div>
                <div>
                    <span class="text-lg font-black tracking-tight text-slate-900 block leading-tight">School<span class="text-brand-600">ERP</span></span>
                    <span class="text-[10px] font-semibold tracking-wider uppercase text-slate-400 block">Interactive Sandbox</span>
                </div>
            </a>

            <!-- Right Controls -->
            <div class="flex items-center gap-3">
                <!-- Return to SaaS Home -->
                <a href="../index.php" class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    <span class="hidden sm:inline"><?php echo ($current_lang === 'bn') ? 'মূল ওয়েবসাইটে ফিরে যান' : 'Back to Main Site'; ?></span>
                </a>

                <!-- Lang Switch -->
                <a href="?lang=<?php echo $other_lang; ?>" class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-100 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"/></svg>
                    <span><?php echo $other_lang_label; ?></span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow gradient-bg py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <!-- Hero Title -->
            <div class="text-center max-w-3xl mx-auto mb-10">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-bold mb-4">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span>
                    <span><?php echo ($current_lang === 'bn') ? 'লাইভ টেস্টিং ইনস্ট্যান্স সক্রিয়' : 'Live Testing Instance Active'; ?></span>
                </div>
                <h1 class="text-2xl sm:text-4xl font-extrabold text-slate-900 tracking-tight mb-3">
                    <?php echo ($current_lang === 'bn') 
                        ? 'স্মার্ট মডেল একাডেমি অ্যান্ড কলেজ' 
                        : 'Smart Model Academy & College'; ?>
                </h1>
                <p class="text-sm sm:text-base text-slate-600">
                    <?php echo ($current_lang === 'bn')
                        ? 'নিচের যেকোনো রোলে ক্লিক করে পাসওয়ার্ড টাইপ ছাড়াই সরাসরি ড্যাশবোর্ডে প্রবেশ করুন। নতুন স্টুডেন্ট তৈরি, ফি কালেকশন বা সেটিংস পরিবর্তন করে নিশ্চিন্তে পুরো সিস্টেম যাচাই করুন।'
                        : 'Click any role below for instant 1-click access without typing credentials. Feel free to create students, collect fees, or change settings.'; ?>
                </p>
            </div>

            <!-- Countdown Timer & Instant Reset Widget -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sm:p-6 mb-10 max-w-3xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-brand-50 text-brand-600 flex items-center justify-center shrink-0 border border-brand-100">
                        <svg class="w-6 h-6 animate-spin" style="animation-duration: 10s;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                            <?php echo ($current_lang === 'bn') ? 'স্বয়ংক্রিয় ডাটাবেজ রিসেট বাকি' : 'Auto Reset Remaining'; ?>
                        </div>
                        <div class="text-2xl sm:text-3xl font-extrabold text-slate-900 font-mono" id="countdownTimer">
                            --:--
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 w-full sm:w-auto">
                    <button type="button" onclick="triggerInstantReset()" id="resetBtn" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs sm:text-sm font-semibold px-4 py-2.5 rounded-xl border border-slate-300 transition-all">
                        <svg class="w-4 h-4" id="resetIcon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        <span id="resetBtnText"><?php echo ($current_lang === 'bn') ? 'এখনই ফ্রেশ রিসেট করুন' : 'Instant Reset Now'; ?></span>
                    </button>
                </div>
            </div>

            <!-- 1-Click Role Cards Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-12">

                <!-- 1. School Admin / Principal -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-brand-300 transition-all p-6 flex flex-col justify-between group">
                    <div>
                        <div class="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center mb-4 group-hover:scale-105 transition-transform border border-indigo-100">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                        </div>
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-lg font-bold text-slate-900"><?php echo ($current_lang === 'bn') ? 'সুপার এডমিন / অধ্যক্ষ' : 'Super Admin / Principal'; ?></h3>
                        </div>
                        <p class="text-xs text-slate-500 mb-4 leading-relaxed">
                            <?php echo ($current_lang === 'bn') 
                                ? 'সম্পূর্ণ নিয়ন্ত্রণ: শিক্ষার্থী ভর্তি, ফি কালেকশন, স্টাফ তালিকা, রেজাল্ট, সেটিংস ও ব্রাঞ্চ নিয়ন্ত্রণ।' 
                                : 'Full control: Admissions, fee collection, staff records, results, settings, and multi-branch management.'; ?>
                        </p>
                        <div class="bg-slate-50 rounded-xl p-3 mb-6 border border-slate-100 text-[11px] font-mono text-slate-600 space-y-1">
                            <div><span class="text-slate-400">User:</span> <strong class="text-slate-800">demo_admin</strong></div>
                            <div><span class="text-slate-400">Pass:</span> <strong class="text-slate-800">Demo@2026</strong></div>
                        </div>
                    </div>
                    <a href="auto_login.php?role=admin" class="w-full inline-flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs sm:text-sm font-semibold py-3 px-4 rounded-xl shadow-sm transition-all group-hover:shadow-indigo-500/20">
                        <span><?php echo ($current_lang === 'bn') ? 'এডমিন ড্যাশবোর্ডে প্রবেশ' : 'Login as Admin'; ?></span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </a>
                </div>

                <!-- 2. Teacher Portal -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-emerald-300 transition-all p-6 flex flex-col justify-between group">
                    <div>
                        <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center mb-4 group-hover:scale-105 transition-transform border border-emerald-100">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                        </div>
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-lg font-bold text-slate-900"><?php echo ($current_lang === 'bn') ? 'শিক্ষক পোর্টাল' : 'Teacher Portal'; ?></h3>
                        </div>
                        <p class="text-xs text-slate-500 mb-4 leading-relaxed">
                            <?php echo ($current_lang === 'bn') 
                                ? 'শ্রেণিকক্ষ হাজিরা, পরীক্ষার নম্বর এন্ট্রি, ক্লাসের রুটিন দর্শন ও শিক্ষার্থীদের পারফরম্যান্স ট্র্যাকিং।' 
                                : 'Daily class attendance, marks entry, routine schedules, and student performance tracking.'; ?>
                        </p>
                        <div class="bg-slate-50 rounded-xl p-3 mb-6 border border-slate-100 text-[11px] font-mono text-slate-600 space-y-1">
                            <div><span class="text-slate-400">ID:</span> <strong class="text-slate-800">EMP-01</strong></div>
                            <div><span class="text-slate-400">Pass:</span> <strong class="text-slate-800">Teacher@2026</strong></div>
                        </div>
                    </div>
                    <a href="auto_login.php?role=teacher" class="w-full inline-flex items-center justify-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs sm:text-sm font-semibold py-3 px-4 rounded-xl shadow-sm transition-all group-hover:shadow-emerald-500/20">
                        <span><?php echo ($current_lang === 'bn') ? 'শিক্ষক হিসেবে প্রবেশ' : 'Login as Teacher'; ?></span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </a>
                </div>

                <!-- 3. Student / Guardian Portal -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-cyan-300 transition-all p-6 flex flex-col justify-between group">
                    <div>
                        <div class="w-12 h-12 rounded-xl bg-cyan-50 text-cyan-600 flex items-center justify-center mb-4 group-hover:scale-105 transition-transform border border-cyan-100">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        </div>
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-lg font-bold text-slate-900"><?php echo ($current_lang === 'bn') ? 'শিক্ষার্থী / অভিভাবক' : 'Student / Guardian'; ?></h3>
                        </div>
                        <p class="text-xs text-slate-500 mb-4 leading-relaxed">
                            <?php echo ($current_lang === 'bn') 
                                ? 'মাসিক ফি রসিদ, অনলাইন পেমেন্ট ট্র্যাকিং, পরীক্ষার রেজাল্ট কার্ড ও ক্লাসের রুটিন দর্শন।' 
                                : 'Fee receipts, online payment tracking, exam report cards, and routine schedules.'; ?>
                        </p>
                        <div class="bg-slate-50 rounded-xl p-3 mb-6 border border-slate-100 text-[11px] font-mono text-slate-600 space-y-1">
                            <div><span class="text-slate-400">ID:</span> <strong class="text-slate-800">STD-26-001</strong></div>
                            <div><span class="text-slate-400">Pass:</span> <strong class="text-slate-800">Student@2026</strong></div>
                        </div>
                    </div>
                    <a href="auto_login.php?role=student" class="w-full inline-flex items-center justify-center gap-2 bg-cyan-600 hover:bg-cyan-700 text-white text-xs sm:text-sm font-semibold py-3 px-4 rounded-xl shadow-sm transition-all group-hover:shadow-cyan-500/20">
                        <span><?php echo ($current_lang === 'bn') ? 'শিক্ষার্থী হিসেবে প্রবেশ' : 'Login as Student'; ?></span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </a>
                </div>

                <!-- 4. Accountant / Finance -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm hover:shadow-md hover:border-violet-300 transition-all p-6 flex flex-col justify-between group">
                    <div>
                        <div class="w-12 h-12 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center mb-4 group-hover:scale-105 transition-transform border border-violet-100">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div class="flex items-center justify-between mb-1">
                            <h3 class="text-lg font-bold text-slate-900"><?php echo ($current_lang === 'bn') ? 'হিসাবরক্ষক (Accountant)' : 'Accounts & Billing'; ?></h3>
                        </div>
                        <p class="text-xs text-slate-500 mb-4 leading-relaxed">
                            <?php echo ($current_lang === 'bn') 
                                ? 'ফি কালেকশন, মানি রিসিট প্রিন্ট, ভাউচার এন্ট্রি, মাসিক আয়-ব্যয় খতিয়ান ও হিসাব সমন্বয়।' 
                                : 'Fee collection, money receipts, voucher entries, expense ledger, and cash book balance.'; ?>
                        </p>
                        <div class="bg-slate-50 rounded-xl p-3 mb-6 border border-slate-100 text-[11px] font-mono text-slate-600 space-y-1">
                            <div><span class="text-slate-400">User:</span> <strong class="text-slate-800">demo_accountant</strong></div>
                            <div><span class="text-slate-400">Pass:</span> <strong class="text-slate-800">Accountant@2026</strong></div>
                        </div>
                    </div>
                    <a href="auto_login.php?role=accountant" class="w-full inline-flex items-center justify-center gap-2 bg-violet-600 hover:bg-violet-700 text-white text-xs sm:text-sm font-semibold py-3 px-4 rounded-xl shadow-sm transition-all group-hover:shadow-violet-500/20">
                        <span><?php echo ($current_lang === 'bn') ? 'হিসাবরক্ষক হিসেবে প্রবেশ' : 'Login as Accountant'; ?></span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </a>
                </div>

            </div>

            <!-- Public School Website Feature Banner -->
            <div class="bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 rounded-3xl p-6 sm:p-10 text-white shadow-xl flex flex-col lg:flex-row items-center justify-between gap-8 mb-12 border border-slate-800">
                <div class="max-w-2xl">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 text-emerald-400 text-xs font-semibold mb-4 border border-white/10">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                        <span><?php echo ($current_lang === 'bn') ? 'পাবলিক ওয়েবসাইট মডিউল' : 'Public Website Module'; ?></span>
                    </div>
                    <h2 class="text-xl sm:text-3xl font-extrabold mb-3">
                        <?php echo ($current_lang === 'bn') 
                            ? 'প্রতিটি স্কুলের নিজস্ব রেসপনসিভ পাবলিক ওয়েবসাইট' 
                            : 'Every School Gets a Dedicated Responsive Public Website'; ?>
                    </h2>
                    <p class="text-sm text-slate-300 leading-relaxed">
                        <?php echo ($current_lang === 'bn') 
                            ? 'ডাইনামিক নোটিশ বোর্ড, ফটো গ্যালারি, প্রিন্সিপালের বাণী, স্লাইডার এবং অনলাইন শিক্ষার্থী ভর্তি ফরমসহ স্বয়ংক্রিয় পাবলিক পোর্টাল দেখতে ভিজিট করুন।' 
                            : 'Experience dynamic notice boards, photo galleries, principal messages, hero sliders, and online student admission application forms.'; ?>
                    </p>
                </div>
                <div class="shrink-0 w-full sm:w-auto">
                    <a href="../index.php?school=demo" target="_blank" class="w-full sm:w-auto inline-flex items-center justify-center gap-3 bg-emerald-500 hover:bg-emerald-600 text-slate-900 font-bold text-sm px-6 py-3.5 rounded-xl shadow-lg hover:shadow-emerald-500/25 transition-all">
                        <span><?php echo ($current_lang === 'bn') ? 'স্কুলের পাবলিক সাইট দেখুন' : 'Visit Demo School Website'; ?></span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    </a>
                </div>
            </div>

            <!-- Sandbox Guidelines / FAQs -->
            <div class="bg-white rounded-2xl border border-slate-200 p-6 sm:p-8 max-w-4xl mx-auto shadow-sm">
                <h3 class="text-lg font-bold text-slate-900 mb-6 flex items-center gap-2">
                    <svg class="w-5 h-5 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span><?php echo ($current_lang === 'bn') ? 'স্যান্ডবক্স নির্দেশিকা ও প্রশ্নোত্তর' : 'Sandbox Guidelines & FAQs'; ?></span>
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
                    <div>
                        <h4 class="font-bold text-slate-800 mb-1">
                            <?php echo ($current_lang === 'bn') ? '১. ডাটাবেজ রিসেট হলে কী হয়?' : '1. What happens during reset?'; ?>
                        </h4>
                        <p class="text-slate-500 text-xs leading-relaxed">
                            <?php echo ($current_lang === 'bn') 
                                ? 'প্রতি ৬০ মিনিট পর পর পুরো ডাটাবেজটি মূল ফ্রেশ অবস্থায় ফিরে যায়। আপনার তৈরি করা কোনো ডাটা বা পরিবর্তন মুছে গিয়ে নতুন টেস্টের জন্য প্রস্তুত হয়।' 
                                : 'Every 60 minutes, the sandbox rolls back to its clean golden state, clearing tests and preparing a fresh environment.'; ?>
                        </p>
                    </div>

                    <div>
                        <h4 class="font-bold text-slate-800 mb-1">
                            <?php echo ($current_lang === 'bn') ? '২. আমি কি টেস্ট শিক্ষার্থী যোগ বা ফি কালেকশন করতে পারব?' : '2. Can I add students or test fees?'; ?>
                        </h4>
                        <p class="text-slate-500 text-xs leading-relaxed">
                            <?php echo ($current_lang === 'bn') 
                                ? 'হ্যাঁ, এটি ১০০% কার্যকরী পূর্ণাঙ্গ ইআরপি। যেকোনো ফিচার আপনি স্বাধীনভাবে পরীক্ষা করে দেখতে পারেন।' 
                                : 'Yes! This is a 100% functional ERP instance. Test student enrollment, generate receipts, and inspect financial reports freely.'; ?>
                        </p>
                    </div>

                    <div>
                        <h4 class="font-bold text-slate-800 mb-1">
                            <?php echo ($current_lang === 'bn') ? '৩. এটি কি মোবাইল ফোনে কাজ করে?' : '3. Does it work on mobile phones?'; ?>
                        </h4>
                        <p class="text-slate-500 text-xs leading-relaxed">
                            <?php echo ($current_lang === 'bn') 
                                ? 'হ্যাঁ, শিক্ষক, শিক্ষার্থী এবং এডমিন ড্যাশবোর্ড মোবাইল, ট্যাবলেট ও ল্যাপটপে সমানভাবে মসৃণ চলে।' 
                                : 'Yes, all portals are fully responsive and work seamlessly on mobile phones, tablets, and desktops.'; ?>
                        </p>
                    </div>

                    <div>
                        <h4 class="font-bold text-slate-800 mb-1">
                            <?php echo ($current_lang === 'bn') ? '৪. আপনার স্কুলের জন্য কাস্টম ডেমো চান?' : '4. Want a private dedicated demo?'; ?>
                        </h4>
                        <p class="text-slate-500 text-xs leading-relaxed">
                            <?php echo ($current_lang === 'bn') 
                                ? 'আমাদের সাথে যোগাযোগ করুন বা মূল ওয়েবসাইটের "ডেমোর আবেদন" ফর্ম পূরণ করুন।' 
                                : 'Contact our team or submit a "Request Dedicated Demo" form from the main website for a personalized trial.'; ?>
                        </p>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-slate-200 py-6 text-center text-xs text-slate-400">
        <p>&copy; <?php echo date('Y'); ?> SchoolERP SaaS Platform. All rights reserved.</p>
    </footer>

    <!-- Synchronized Live Timer & Reset Script -->
    <script>
        let remainingSeconds = <?php echo (int) $seconds_remaining; ?>;

        function updateTimerDisplay() {
            if (remainingSeconds <= 0) {
                document.getElementById('countdownTimer').textContent = "00:00 (Resetting...)";
                // Trigger reload to initiate self-healing reset
                setTimeout(() => {
                    location.reload();
                }, 2000);
                return;
            }

            const minutes = Math.floor(remainingSeconds / 60);
            const seconds = remainingSeconds % 60;
            const formatted = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
            document.getElementById('countdownTimer').textContent = formatted;
            remainingSeconds--;
        }

        setInterval(updateTimerDisplay, 1000);
        updateTimerDisplay();

        // Instant Reset via AJAX
        function triggerInstantReset() {
            if (!confirm('<?php echo ($current_lang === 'bn') ? 'আপনি কি নিশ্চিত যে ডেমো ডাটাবেজ রিসেট করতে চান?' : 'Are you sure you want to reset the demo database?'; ?>')) {
                return;
            }

            const btn = document.getElementById('resetBtn');
            const icon = document.getElementById('resetIcon');
            const txt = document.getElementById('resetBtnText');

            btn.disabled = true;
            btn.classList.add('opacity-75', 'cursor-not-allowed');
            icon.classList.add('animate-spin');
            txt.textContent = '<?php echo ($current_lang === 'bn') ? 'রিসেট হচ্ছে...' : 'Resetting...'; ?>';

            fetch('../cron/reset_demo_db.php?key=demo_reset_secret_2026&force=1')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        txt.textContent = '<?php echo ($current_lang === 'bn') ? 'রিসেট সম্পন্ন!' : 'Reset Successful!'; ?>';
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        alert(data.message || 'Reset failed');
                        btn.disabled = false;
                        btn.classList.remove('opacity-75', 'cursor-not-allowed');
                        icon.classList.remove('animate-spin');
                        txt.textContent = '<?php echo ($current_lang === 'bn') ? 'এখনই ফ্রেশ রিসেট করুন' : 'Instant Reset Now'; ?>';
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Network error during reset.');
                    btn.disabled = false;
                    btn.classList.remove('opacity-75', 'cursor-not-allowed');
                    icon.classList.remove('animate-spin');
                    txt.textContent = '<?php echo ($current_lang === 'bn') ? 'এখনই ফ্রেশ রিসেট করুন' : 'Instant Reset Now'; ?>';
                });
        }
    </script>
</body>
</html>
