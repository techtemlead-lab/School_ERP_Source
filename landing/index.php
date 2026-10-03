<?php
/**
 * landing/index.php
 * -----------------------------------------------------------
 * School ERP — Official SaaS Marketing & Product Landing Page
 * Bilingual: Bangla (default) & English with instant toggle.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/saas_lang.php';
$lang = saas_current_lang();
$other_lang = ($lang === 'bn') ? 'en' : 'bn';
$other_lang_label = ($lang === 'bn') ? '🇬🇧 English' : '🇧🇩 বাংলা';

$is_subfolder = (strpos($_SERVER['PHP_SELF'] ?? '', '/landing/') !== false);
$demo_url     = $is_subfolder ? '../demo/index.php' : 'demo/index.php';
$master_url   = $is_subfolder ? '../master/login.php' : 'master/login.php';
$ajax_url     = $is_subfolder ? 'demo_request_ajax.php' : 'landing/demo_request_ajax.php';
$privacy_url  = $is_subfolder ? 'privacy.php' : 'landing/privacy.php';
$terms_url    = $is_subfolder ? 'terms.php' : 'landing/terms.php';
$logo_url     = $is_subfolder ? '../logo.png' : 'logo.png';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(saas_t('meta_title')); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars(saas_t('meta_desc')); ?>">
    <link rel="icon" href="<?php echo $logo_url; ?>" type="image/png">
    
    <!-- Google Fonts: Hind Siliguri (for Bangla) and Inter (for English) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#f0fdf4',
                            100: '#dcfce7',
                            500: '#10b981',
                            600: '#059669',
                            700: '#047857',
                            900: '#064e3b',
                        },
                        navy: {
                            800: '#0f172a',
                            900: '#020617',
                        }
                    },
                    fontFamily: {
                        sans: <?php echo ($lang === 'bn') ? "['Hind Siliguri', 'Inter', 'sans-serif']" : "['Inter', 'sans-serif']"; ?>
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: <?php echo ($lang === 'bn') ? "'Hind Siliguri', sans-serif" : "'Inter', sans-serif"; ?>; }
        .gradient-glow {
            background: radial-gradient(circle at 50% 20%, rgba(16, 185, 129, 0.15), transparent 70%);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 antialiased selection:bg-brand-500 selection:text-white">

    <!-- Sticky Navigation Bar -->
    <header class="sticky top-0 z-50 bg-white/90 backdrop-blur-md border-b border-slate-200 transition-all">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
            <!-- Brand Logo -->
            <a href="index.php" class="flex items-center gap-3 group">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-tr from-brand-600 to-emerald-400 flex items-center justify-center text-white text-xl shadow-md group-hover:scale-105 transition-transform">
                    🏫
                </div>
                <div>
                    <span class="text-xl font-bold text-slate-900 tracking-tight block"><?php echo htmlspecialchars(saas_t('brand_name')); ?></span>
                    <span class="text-xs text-brand-600 font-semibold uppercase tracking-wider block"><?php echo htmlspecialchars(saas_t('brand_tagline')); ?></span>
                </div>
            </a>

            <!-- Desktop Nav Links -->
            <nav class="hidden md:flex items-center gap-8 text-sm font-medium text-slate-600">
                <a href="#features" class="hover:text-brand-600 transition-colors"><?php echo htmlspecialchars(saas_t('nav_features')); ?></a>
                <a href="#why-us" class="hover:text-brand-600 transition-colors"><?php echo htmlspecialchars(saas_t('nav_why_us')); ?></a>
                <a href="#pricing" class="hover:text-brand-600 transition-colors"><?php echo htmlspecialchars(saas_t('nav_pricing')); ?></a>
                <a href="#security" class="hover:text-brand-600 transition-colors"><?php echo htmlspecialchars(saas_t('nav_security')); ?></a>
                <a href="#contact" class="hover:text-brand-600 transition-colors"><?php echo htmlspecialchars(saas_t('nav_contact')); ?></a>
            </nav>

            <!-- Action Buttons & Language Switcher -->
            <div class="flex items-center gap-3">
                <!-- Language Toggle Button -->
                <a href="?lang=<?php echo $other_lang; ?>" class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg border border-slate-300 hover:border-brand-500 hover:text-brand-600 transition-all bg-white text-slate-700 shadow-sm" title="Switch Language">
                    <span><?php echo $other_lang_label; ?></span>
                </a>

                <!-- Live Demo Button -->
                <a href="<?php echo $demo_url; ?>" class="hidden sm:inline-flex items-center gap-2 bg-gradient-to-r from-emerald-500 to-brand-600 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm hover:shadow-brand-500/25 hover:opacity-95 transition-all">
                    <span><?php echo htmlspecialchars(saas_t('nav_live_demo')); ?></span>
                </a>

                <!-- Superadmin Portal Link -->
                <a href="<?php echo $master_url; ?>" class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition-colors" title="Master Admin">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <span class="hidden lg:inline"><?php echo htmlspecialchars(saas_t('nav_superadmin')); ?></span>
                </a>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="relative pt-12 pb-20 lg:pt-20 lg:pb-32 overflow-hidden gradient-glow border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10">
            <!-- Badge -->
            <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-brand-50 border border-brand-200 text-brand-700 text-xs sm:text-sm font-semibold mb-6 shadow-sm">
                <?php echo htmlspecialchars(saas_t('hero_badge')); ?>
            </div>

            <!-- Main Heading -->
            <h1 class="text-3xl sm:text-5xl lg:text-6xl font-extrabold text-slate-900 tracking-tight leading-tight max-w-4xl mx-auto mb-6">
                <?php echo htmlspecialchars(saas_t('hero_title_1')); ?><br>
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-brand-600 via-emerald-500 to-teal-600">
                    <?php echo htmlspecialchars(saas_t('hero_title_highlight')); ?>
                </span>
            </h1>

            <!-- Subtitle -->
            <p class="text-base sm:text-lg lg:text-xl text-slate-600 max-w-3xl mx-auto mb-10 leading-relaxed">
                <?php echo htmlspecialchars(saas_t('hero_subtitle')); ?>
            </p>

            <!-- Dual CTAs -->
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4 mb-16">
                <a href="<?php echo $demo_url; ?>" class="w-full sm:w-auto inline-flex items-center justify-center gap-3 bg-brand-600 hover:bg-brand-700 text-white font-semibold text-base px-8 py-4 rounded-xl shadow-lg shadow-brand-600/30 hover:shadow-xl hover:-translate-y-0.5 transition-all">
                    <span><?php echo htmlspecialchars(saas_t('hero_btn_demo')); ?></span>
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </a>
                <button onclick="openDemoModal()" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-white hover:bg-slate-100 text-slate-800 font-semibold text-base px-8 py-4 rounded-xl border border-slate-300 shadow-sm hover:-translate-y-0.5 transition-all">
                    <span><?php echo htmlspecialchars(saas_t('hero_btn_request')); ?></span>
                    <svg class="w-5 h-5 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                </button>
            </div>

            <!-- Stats Bar -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6 max-w-4xl mx-auto pt-8 border-t border-slate-200">
                <div class="p-4 rounded-xl bg-white border border-slate-100 shadow-sm">
                    <span class="text-3xl font-extrabold text-brand-600 block"><?php echo saas_num(50); ?>+</span>
                    <span class="text-xs sm:text-sm text-slate-500 font-medium"><?php echo htmlspecialchars(saas_t('hero_stats_schools')); ?></span>
                </div>
                <div class="p-4 rounded-xl bg-white border border-slate-100 shadow-sm">
                    <span class="text-3xl font-extrabold text-brand-600 block"><?php echo saas_num(25000); ?>+</span>
                    <span class="text-xs sm:text-sm text-slate-500 font-medium"><?php echo htmlspecialchars(saas_t('hero_stats_students')); ?></span>
                </div>
                <div class="p-4 rounded-xl bg-white border border-slate-100 shadow-sm">
                    <span class="text-3xl font-extrabold text-brand-600 block"><?php echo saas_num(80); ?>+</span>
                    <span class="text-xs sm:text-sm text-slate-500 font-medium"><?php echo htmlspecialchars(saas_t('hero_stats_modules')); ?></span>
                </div>
                <div class="p-4 rounded-xl bg-white border border-slate-100 shadow-sm">
                    <span class="text-3xl font-extrabold text-brand-600 block">৯৯.৯%</span>
                    <span class="text-xs sm:text-sm text-slate-500 font-medium"><?php echo htmlspecialchars(saas_t('hero_stats_uptime')); ?></span>
                </div>
            </div>
        </div>
    </section>


    <!-- 80+ Modules Catalog Section -->
    <section id="features" class="py-20 lg:py-28 bg-white border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <span class="inline-block px-3 py-1 text-xs font-bold text-brand-700 bg-brand-50 border border-brand-200 rounded-full mb-3 uppercase tracking-wider">
                    <?php echo htmlspecialchars(saas_t('modules_heading_badge')); ?>
                </span>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight mb-4">
                    <?php echo htmlspecialchars(saas_t('modules_title')); ?>
                </h2>
                <p class="text-base sm:text-lg text-slate-600">
                    <?php echo htmlspecialchars(saas_t('modules_subtitle')); ?>
                </p>
            </div>

            <!-- Modules Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <!-- Module 1: Admissions -->
                <div class="p-8 rounded-2xl border border-slate-200 hover:border-brand-500 hover:shadow-xl hover:-translate-y-1 transition-all bg-slate-50/50">
                    <div class="w-12 h-12 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center text-2xl mb-5">📝</div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('mod_admission_title')); ?></h3>
                    <p class="text-slate-600 text-sm leading-relaxed"><?php echo htmlspecialchars(saas_t('mod_admission_desc')); ?></p>
                </div>

                <!-- Module 2: Biometric Attendance -->
                <div class="p-8 rounded-2xl border border-slate-200 hover:border-brand-500 hover:shadow-xl hover:-translate-y-1 transition-all bg-slate-50/50">
                    <div class="w-12 h-12 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center text-2xl mb-5">⏱️</div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('mod_attendance_title')); ?></h3>
                    <p class="text-slate-600 text-sm leading-relaxed"><?php echo htmlspecialchars(saas_t('mod_attendance_desc')); ?></p>
                </div>

                <!-- Module 3: Fees & Accounts -->
                <div class="p-8 rounded-2xl border border-slate-200 hover:border-brand-500 hover:shadow-xl hover:-translate-y-1 transition-all bg-slate-50/50">
                    <div class="w-12 h-12 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center text-2xl mb-5">💳</div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('mod_accounts_title')); ?></h3>
                    <p class="text-slate-600 text-sm leading-relaxed"><?php echo htmlspecialchars(saas_t('mod_accounts_desc')); ?></p>
                </div>

                <!-- Module 4: Exams & Results -->
                <div class="p-8 rounded-2xl border border-slate-200 hover:border-brand-500 hover:shadow-xl hover:-translate-y-1 transition-all bg-slate-50/50">
                    <div class="w-12 h-12 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center text-2xl mb-5">📊</div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('mod_exams_title')); ?></h3>
                    <p class="text-slate-600 text-sm leading-relaxed"><?php echo htmlspecialchars(saas_t('mod_exams_desc')); ?></p>
                </div>

                <!-- Module 5: GPS Bus Tracking -->
                <div class="p-8 rounded-2xl border border-slate-200 hover:border-brand-500 hover:shadow-xl hover:-translate-y-1 transition-all bg-slate-50/50">
                    <div class="w-12 h-12 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center text-2xl mb-5">🚌</div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('mod_transport_title')); ?></h3>
                    <p class="text-slate-600 text-sm leading-relaxed"><?php echo htmlspecialchars(saas_t('mod_transport_desc')); ?></p>
                </div>

                <!-- Module 6: Live 100ms Classes -->
                <div class="p-8 rounded-2xl border border-slate-200 hover:border-brand-500 hover:shadow-xl hover:-translate-y-1 transition-all bg-slate-50/50">
                    <div class="w-12 h-12 rounded-xl bg-cyan-100 text-cyan-600 flex items-center justify-center text-2xl mb-5">🎥</div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('mod_liveclass_title')); ?></h3>
                    <p class="text-slate-600 text-sm leading-relaxed"><?php echo htmlspecialchars(saas_t('mod_liveclass_desc')); ?></p>
                </div>

                <!-- Module 7: Smart SMS & WhatsApp -->
                <div class="p-8 rounded-2xl border border-slate-200 hover:border-brand-500 hover:shadow-xl hover:-translate-y-1 transition-all bg-slate-50/50">
                    <div class="w-12 h-12 rounded-xl bg-teal-100 text-teal-600 flex items-center justify-center text-2xl mb-5">💬</div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('mod_sms_title')); ?></h3>
                    <p class="text-slate-600 text-sm leading-relaxed"><?php echo htmlspecialchars(saas_t('mod_sms_desc')); ?></p>
                </div>

                <!-- Module 8: Multi-Branch Control -->
                <div class="p-8 rounded-2xl border border-slate-200 hover:border-brand-500 hover:shadow-xl hover:-translate-y-1 transition-all bg-slate-50/50">
                    <div class="w-12 h-12 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center text-2xl mb-5">🏢</div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('mod_multibranch_title')); ?></h3>
                    <p class="text-slate-600 text-sm leading-relaxed"><?php echo htmlspecialchars(saas_t('mod_multibranch_desc')); ?></p>
                </div>

                <!-- Module 9: ID Cards & Certificates -->
                <div class="p-8 rounded-2xl border border-slate-200 hover:border-brand-500 hover:shadow-xl hover:-translate-y-1 transition-all bg-slate-50/50">
                    <div class="w-12 h-12 rounded-xl bg-orange-100 text-orange-600 flex items-center justify-center text-2xl mb-5">🪪</div>
                    <h3 class="text-xl font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('mod_idcard_title')); ?></h3>
                    <p class="text-slate-600 text-sm leading-relaxed"><?php echo htmlspecialchars(saas_t('mod_idcard_desc')); ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- Why Choose Us Section -->
    <section id="why-us" class="py-20 lg:py-28 bg-slate-50 border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <span class="inline-block px-3 py-1 text-xs font-bold text-brand-700 bg-brand-50 border border-brand-200 rounded-full mb-3 uppercase tracking-wider">
                    <?php echo htmlspecialchars(saas_t('why_heading_badge')); ?>
                </span>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight mb-4">
                    <?php echo htmlspecialchars(saas_t('why_title')); ?>
                </h2>
                <p class="text-base sm:text-lg text-slate-600">
                    <?php echo htmlspecialchars(saas_t('why_subtitle')); ?>
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-sm">
                    <div class="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl font-bold mb-4">1</div>
                    <h4 class="text-lg font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('why_feat1_title')); ?></h4>
                    <p class="text-slate-600 text-sm"><?php echo htmlspecialchars(saas_t('why_feat1_desc')); ?></p>
                </div>

                <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-sm">
                    <div class="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl font-bold mb-4">2</div>
                    <h4 class="text-lg font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('why_feat2_title')); ?></h4>
                    <p class="text-slate-600 text-sm"><?php echo htmlspecialchars(saas_t('why_feat2_desc')); ?></p>
                </div>

                <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-sm">
                    <div class="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl font-bold mb-4">3</div>
                    <h4 class="text-lg font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('why_feat3_title')); ?></h4>
                    <p class="text-slate-600 text-sm"><?php echo htmlspecialchars(saas_t('why_feat3_desc')); ?></p>
                </div>

                <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-sm">
                    <div class="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl font-bold mb-4">4</div>
                    <h4 class="text-lg font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('why_feat4_title')); ?></h4>
                    <p class="text-slate-600 text-sm"><?php echo htmlspecialchars(saas_t('why_feat4_desc')); ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section id="pricing" class="py-20 lg:py-28 bg-white border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <span class="inline-block px-3 py-1 text-xs font-bold text-brand-700 bg-brand-50 border border-brand-200 rounded-full mb-3 uppercase tracking-wider">
                    <?php echo htmlspecialchars(saas_t('pricing_heading_badge')); ?>
                </span>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight mb-4">
                    <?php echo htmlspecialchars(saas_t('pricing_title')); ?>
                </h2>
                <p class="text-base sm:text-lg text-slate-600">
                    <?php echo htmlspecialchars(saas_t('pricing_subtitle')); ?>
                </p>
            </div>

            <!-- Pricing Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
                <!-- Plan 1: Trial -->
                <div class="rounded-3xl border border-slate-200 p-8 flex flex-col justify-between hover:shadow-lg transition-all bg-slate-50">
                    <div>
                        <h3 class="text-xl font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('plan_trial_name')); ?></h3>
                        <p class="text-xs text-slate-500 mb-6"><?php echo htmlspecialchars(saas_t('plan_trial_desc')); ?></p>
                        <div class="text-4xl font-extrabold text-slate-900 mb-6">
                            <?php echo htmlspecialchars(saas_t('plan_trial_price')); ?>
                            <span class="text-xs font-medium text-slate-500"><?php echo htmlspecialchars(saas_t('pricing_period')); ?></span>
                        </div>
                        <ul class="space-y-3 text-xs text-slate-600 mb-8">
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_students_limit', ['limit' => saas_num(100)])); ?></li>
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_branches', ['count' => saas_num(1)])); ?></li>
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_admission_online')); ?></li>
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_fees_accounts')); ?></li>
                        </ul>
                    </div>
                    <button onclick="openDemoModal('Free Trial')" class="w-full py-3 rounded-xl border border-slate-300 font-bold text-xs text-slate-700 hover:bg-slate-200 transition-colors">
                        <?php echo htmlspecialchars(saas_t('plan_trial_cta')); ?>
                    </button>
                </div>

                <!-- Plan 2: Basic -->
                <div class="rounded-3xl border border-slate-200 p-8 flex flex-col justify-between hover:shadow-lg transition-all bg-white">
                    <div>
                        <h3 class="text-xl font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('plan_basic_name')); ?></h3>
                        <p class="text-xs text-slate-500 mb-6"><?php echo htmlspecialchars(saas_t('plan_basic_desc')); ?></p>
                        <div class="text-4xl font-extrabold text-slate-900 mb-6">
                            <?php echo htmlspecialchars(saas_t('plan_basic_price')); ?>
                            <span class="text-xs font-medium text-slate-500"><?php echo htmlspecialchars(saas_t('pricing_period')); ?></span>
                        </div>
                        <ul class="space-y-3 text-xs text-slate-600 mb-8">
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_students_limit', ['limit' => saas_num(300)])); ?></li>
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_branches', ['count' => saas_num(1)])); ?></li>
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_admission_online')); ?></li>
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_fees_accounts')); ?></li>
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_exams_result')); ?></li>
                        </ul>
                    </div>
                    <button onclick="openDemoModal('Basic Plan')" class="w-full py-3 rounded-xl border border-brand-500 text-brand-600 font-bold text-xs hover:bg-brand-50 transition-colors">
                        <?php echo htmlspecialchars(saas_t('plan_basic_cta')); ?>
                    </button>
                </div>

                <!-- Plan 3: Standard (Featured) -->
                <div class="rounded-3xl border-2 border-brand-500 p-8 flex flex-col justify-between shadow-xl relative bg-white scale-105 z-10">
                    <span class="absolute -top-3.5 left-1/2 -translate-x-1/2 bg-brand-600 text-white text-[11px] font-bold px-3 py-1 rounded-full uppercase tracking-wider shadow">
                        <?php echo htmlspecialchars(saas_t('plan_standard_badge')); ?>
                    </span>
                    <div>
                        <h3 class="text-xl font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('plan_standard_name')); ?></h3>
                        <p class="text-xs text-slate-500 mb-6"><?php echo htmlspecialchars(saas_t('plan_standard_desc')); ?></p>
                        <div class="text-4xl font-extrabold text-brand-600 mb-6">
                            <?php echo htmlspecialchars(saas_t('plan_standard_price')); ?>
                            <span class="text-xs font-medium text-slate-500"><?php echo htmlspecialchars(saas_t('pricing_period')); ?></span>
                        </div>
                        <ul class="space-y-3 text-xs text-slate-700 mb-8 font-medium">
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_students_limit', ['limit' => saas_num(800)])); ?></li>
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_branches', ['count' => saas_num(2)])); ?></li>
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_sms_gateway')); ?></li>
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_biometric_sync')); ?></li>
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_exams_result')); ?></li>
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_support')); ?></li>
                        </ul>
                    </div>
                    <button onclick="openDemoModal('Standard Plan')" class="w-full py-3.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-xs shadow-md shadow-brand-600/25 transition-all">
                        <?php echo htmlspecialchars(saas_t('plan_standard_cta')); ?>
                    </button>
                </div>

                <!-- Plan 4: Premium -->
                <div class="rounded-3xl border border-slate-200 p-8 flex flex-col justify-between hover:shadow-lg transition-all bg-white">
                    <div>
                        <h3 class="text-xl font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('plan_premium_name')); ?></h3>
                        <p class="text-xs text-slate-500 mb-6"><?php echo htmlspecialchars(saas_t('plan_premium_desc')); ?></p>
                        <div class="text-4xl font-extrabold text-slate-900 mb-6">
                            <?php echo htmlspecialchars(saas_t('plan_premium_price')); ?>
                            <span class="text-xs font-medium text-slate-500"><?php echo htmlspecialchars(saas_t('pricing_period')); ?></span>
                        </div>
                        <ul class="space-y-3 text-xs text-slate-600 mb-8">
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_students_unlimited')); ?></li>
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_branches', ['count' => 'আনলিমিটেড'])); ?></li>
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_gps_bus')); ?></li>
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_live_classes')); ?></li>
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_custom_domain')); ?></li>
                            <li class="flex items-center gap-2">✓ <?php echo htmlspecialchars(saas_t('feat_support')); ?></li>
                        </ul>
                    </div>
                    <button onclick="openDemoModal('Premium Plan')" class="w-full py-3 rounded-xl border border-brand-500 text-brand-600 font-bold text-xs hover:bg-brand-50 transition-colors">
                        <?php echo htmlspecialchars(saas_t('plan_premium_cta')); ?>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Cloud Security & Isolation -->
    <section id="security" class="py-20 bg-slate-900 text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <span class="inline-block px-3 py-1 text-xs font-bold text-emerald-400 bg-emerald-950/80 border border-emerald-800 rounded-full mb-3 uppercase tracking-wider">
                    <?php echo htmlspecialchars(saas_t('trust_badge')); ?>
                </span>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-white tracking-tight mb-4">
                    <?php echo htmlspecialchars(saas_t('trust_title')); ?>
                </h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="p-8 rounded-2xl bg-slate-800/80 border border-slate-700">
                    <div class="text-3xl mb-4">🛡️</div>
                    <h4 class="text-xl font-bold mb-2"><?php echo htmlspecialchars(saas_t('trust_p1_title')); ?></h4>
                    <p class="text-slate-400 text-sm leading-relaxed"><?php echo htmlspecialchars(saas_t('trust_p1_desc')); ?></p>
                </div>
                <div class="p-8 rounded-2xl bg-slate-800/80 border border-slate-700">
                    <div class="text-3xl mb-4">☁️</div>
                    <h4 class="text-xl font-bold mb-2"><?php echo htmlspecialchars(saas_t('trust_p2_title')); ?></h4>
                    <p class="text-slate-400 text-sm leading-relaxed"><?php echo htmlspecialchars(saas_t('trust_p2_desc')); ?></p>
                </div>
                <div class="p-8 rounded-2xl bg-slate-800/80 border border-slate-700">
                    <div class="text-3xl mb-4">🔒</div>
                    <h4 class="text-xl font-bold mb-2"><?php echo htmlspecialchars(saas_t('trust_p3_title')); ?></h4>
                    <p class="text-slate-400 text-sm leading-relaxed"><?php echo htmlspecialchars(saas_t('trust_p3_desc')); ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer id="contact" class="bg-slate-950 text-slate-400 pt-16 pb-12 border-t border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-10 mb-12">
                <div class="md:col-span-2">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-9 h-9 rounded-lg bg-brand-600 flex items-center justify-center text-white text-lg">🏫</div>
                        <span class="text-xl font-bold text-white"><?php echo htmlspecialchars(saas_t('brand_name')); ?></span>
                    </div>
                    <p class="text-sm text-slate-400 max-w-md leading-relaxed mb-6">
                        <?php echo htmlspecialchars(saas_t('footer_about')); ?>
                    </p>
                    <div class="flex gap-4">
                        <a href="<?php echo $demo_url; ?>" class="text-xs bg-brand-600/20 text-brand-400 border border-brand-500/30 px-3 py-1.5 rounded-lg hover:bg-brand-600 hover:text-white transition-all">
                            <?php echo htmlspecialchars(saas_t('nav_live_demo')); ?>
                        </a>
                        <a href="<?php echo $master_url; ?>" class="text-xs bg-slate-800 text-slate-300 px-3 py-1.5 rounded-lg hover:bg-slate-700 transition-all">
                            <?php echo htmlspecialchars(saas_t('nav_superadmin')); ?>
                        </a>
                    </div>
                </div>

                <div>
                    <h5 class="text-white text-sm font-bold uppercase tracking-wider mb-4"><?php echo htmlspecialchars(saas_t('footer_quick_links')); ?></h5>
                    <ul class="space-y-2 text-sm">
                        <li><a href="#features" class="hover:text-white transition-colors"><?php echo htmlspecialchars(saas_t('nav_features')); ?></a></li>
                        <li><a href="#pricing" class="hover:text-white transition-colors"><?php echo htmlspecialchars(saas_t('nav_pricing')); ?></a></li>
                        <li><a href="#why-us" class="hover:text-white transition-colors"><?php echo htmlspecialchars(saas_t('nav_why_us')); ?></a></li>
                        <li><a href="<?php echo $privacy_url; ?>" class="hover:text-white transition-colors"><?php echo htmlspecialchars(saas_t('footer_privacy')); ?></a></li>
                        <li><a href="<?php echo $terms_url; ?>" class="hover:text-white transition-colors"><?php echo htmlspecialchars(saas_t('footer_terms')); ?></a></li>
                    </ul>
                </div>

                <div>
                    <h5 class="text-white text-sm font-bold uppercase tracking-wider mb-4"><?php echo htmlspecialchars(saas_t('footer_contact_us')); ?></h5>
                    <ul class="space-y-2 text-sm">
                        <li class="flex items-center gap-2">📞 <span><?php echo htmlspecialchars(saas_t('footer_phone')); ?></span></li>
                        <li class="flex items-center gap-2">✉️ <span><?php echo htmlspecialchars(saas_t('footer_email')); ?></span></li>
                        <li class="flex items-center gap-2">📍 <span><?php echo htmlspecialchars(saas_t('footer_address')); ?></span></li>
                    </ul>
                </div>
            </div>

            <div class="pt-8 border-t border-slate-800 text-center text-xs text-slate-500">
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(saas_t('brand_name')); ?>. <?php echo htmlspecialchars(saas_t('footer_copyright')); ?>
            </div>
        </div>
    </footer>

    <!-- Dedicated Demo Request Modal -->
    <div id="demoModal" class="fixed inset-0 z-50 hidden bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-100 relative animate-in fade-in zoom-in duration-200">
            <!-- Close Button -->
            <button onclick="closeDemoModal()" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 text-2xl font-bold">&times;</button>

            <!-- Modal Header -->
            <div id="modalFormContainer">
                <div class="mb-6">
                    <span class="inline-block px-3 py-1 text-[11px] font-bold text-brand-700 bg-brand-50 border border-brand-200 rounded-full mb-2 uppercase">
                        Book a Live Demo
                    </span>
                    <h3 class="text-2xl font-extrabold text-slate-900"><?php echo htmlspecialchars(saas_t('modal_demo_title')); ?></h3>
                    <p class="text-xs text-slate-500 mt-1"><?php echo htmlspecialchars(saas_t('modal_demo_subtitle')); ?></p>
                </div>

                <div id="modalAlert" class="hidden mb-4 p-3 rounded-xl text-xs font-semibold"></div>

                <!-- Form -->
                <form id="demoRequestForm" onsubmit="submitDemoForm(event)" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1"><?php echo htmlspecialchars(saas_t('modal_field_school')); ?> *</label>
                        <input type="text" name="school_name" required placeholder="<?php echo htmlspecialchars(saas_t('modal_field_school_ph')); ?>" class="w-full text-sm px-4 py-2.5 rounded-xl border border-slate-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1"><?php echo htmlspecialchars(saas_t('modal_field_contact')); ?> *</label>
                            <input type="text" name="contact_name" required placeholder="<?php echo htmlspecialchars(saas_t('modal_field_contact_ph')); ?>" class="w-full text-sm px-4 py-2.5 rounded-xl border border-slate-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1"><?php echo htmlspecialchars(saas_t('modal_field_phone')); ?> *</label>
                            <input type="tel" name="phone" required placeholder="<?php echo htmlspecialchars(saas_t('modal_field_phone_ph')); ?>" class="w-full text-sm px-4 py-2.5 rounded-xl border border-slate-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1"><?php echo htmlspecialchars(saas_t('modal_field_students')); ?></label>
                            <select name="student_count" class="w-full text-sm px-4 py-2.5 rounded-xl border border-slate-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all bg-white">
                                <option value="Under 200"><?php echo htmlspecialchars(saas_t('modal_field_students_opt1')); ?></option>
                                <option value="200-500"><?php echo htmlspecialchars(saas_t('modal_field_students_opt2')); ?></option>
                                <option value="500-1500" selected><?php echo htmlspecialchars(saas_t('modal_field_students_opt3')); ?></option>
                                <option value="Over 1500"><?php echo htmlspecialchars(saas_t('modal_field_students_opt4')); ?></option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1"><?php echo htmlspecialchars(saas_t('modal_field_date')); ?></label>
                            <input type="date" name="preferred_date" class="w-full text-sm px-4 py-2.5 rounded-xl border border-slate-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all bg-white">
                        </div>
                    </div>

                    <button type="submit" id="submitBtn" class="w-full py-3.5 mt-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-sm shadow-lg shadow-brand-600/30 transition-all flex items-center justify-center gap-2">
                        <span><?php echo htmlspecialchars(saas_t('modal_btn_submit')); ?></span>
                    </button>
                </form>
            </div>

            <!-- Success State -->
            <div id="modalSuccessContainer" class="hidden text-center py-8">
                <div class="w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 text-3xl flex items-center justify-center mx-auto mb-4">✓</div>
                <h4 class="text-2xl font-bold text-slate-900 mb-2"><?php echo htmlspecialchars(saas_t('modal_success_title')); ?></h4>
                <p class="text-sm text-slate-600 max-w-sm mx-auto mb-6"><?php echo htmlspecialchars(saas_t('modal_success_desc')); ?></p>
                <button onclick="closeDemoModal()" class="px-6 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-sm">
                    <?php echo htmlspecialchars(saas_t('modal_btn_close')); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Scripts for Modal & AJAX -->
    <script>
        function openDemoModal(planName = '') {
            document.getElementById('demoModal').classList.remove('hidden');
            if (planName) {
                // autofill if needed
            }
        }

        function closeDemoModal() {
            document.getElementById('demoModal').classList.add('hidden');
            document.getElementById('modalFormContainer').classList.remove('hidden');
            document.getElementById('modalSuccessContainer').classList.add('hidden');
            document.getElementById('modalAlert').classList.add('hidden');
            document.getElementById('demoRequestForm').reset();
        }

        function submitDemoForm(e) {
            e.preventDefault();
            const btn = document.getElementById('submitBtn');
            const alertBox = document.getElementById('modalAlert');
            const form = document.getElementById('demoRequestForm');

            btn.disabled = true;
            btn.innerHTML = '<span><?php echo htmlspecialchars(saas_t("modal_submitting")); ?></span>';
            alertBox.classList.add('hidden');

            const formData = new FormData(form);

            fetch('<?php echo $ajax_url; ?>', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('modalFormContainer').classList.add('hidden');
                    document.getElementById('modalSuccessContainer').classList.remove('hidden');
                } else {
                    alertBox.className = 'p-3 rounded-xl text-xs font-semibold bg-rose-50 border border-rose-200 text-rose-700 block mb-4';
                    alertBox.innerText = data.error || 'Submission failed. Please check your information.';
                    btn.disabled = false;
                    btn.innerHTML = '<span><?php echo htmlspecialchars(saas_t("modal_btn_submit")); ?></span>';
                }
            })
            .catch(err => {
                alertBox.className = 'p-3 rounded-xl text-xs font-semibold bg-rose-50 border border-rose-200 text-rose-700 block mb-4';
                alertBox.innerText = 'Network error occurred. Please try again.';
                btn.disabled = false;
                btn.innerHTML = '<span><?php echo htmlspecialchars(saas_t("modal_btn_submit")); ?></span>';
            });
        }
    </script>
</body>
</html>
