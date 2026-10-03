<?php
/**
 * config/saas_strings.php
 * -----------------------------------------------------------
 * Bilingual translations dictionary (Bengali & English)
 * for the SaaS Public Marketing Website & Demo Gateway.
 */

return [
    // ══════════════════════════════════════════════════════════
    // বাংলা (BENGALI) - DEFAULT
    // ══════════════════════════════════════════════════════════
    'bn' => [
        // Meta & Branding
        'meta_title' => 'স্কুল ইআরপি — পূর্ণাঙ্গ ক্লাউড স্কুল ম্যানেজমেন্ট সফটওয়্যার',
        'meta_desc' => 'ডিজিটাল ভর্তি, স্বয়ংক্রিয় ফি কালেকশন, বায়োমেট্রিক হাজিরা, লাইভ ক্লাস, জিপিএস বাস ট্র্যাকিং এবং পূর্ণাঙ্গ স্কুল অটোমেশন সল্যুশন।',
        'brand_name' => 'স্কুল ইআরপি',
        'brand_tagline' => 'স্মার্ট শিক্ষা ব্যবস্থাপনার বিশ্বস্ত সঙ্গী',
        
        // Navigation
        'nav_home' => 'হোম',
        'nav_features' => 'মডিউল ও ফিচার',
        'nav_why_us' => 'কেন বেছে নেবেন',
        'nav_pricing' => 'প্যাকেজ ও মূল্য',
        'nav_security' => 'নিরাপত্তা ও ক্লাউড',
        'nav_contact' => 'যোগাযোগ',
        'nav_live_demo' => 'লাইভ ডেমো দেখুন 🚀',
        'nav_login' => 'লগইন',
        'nav_superadmin' => 'মাস্টার প্যানেল',
        
        // Hero Section
        'hero_badge' => '✨ আধুনিক শিক্ষা প্রতিষ্ঠানের জন্য অল-ইন-ওয়ান ক্লাউড ইআরপি',
        'hero_title_1' => 'আপনার স্কুলের সম্পূর্ণ ব্যবস্থাপনা',
        'hero_title_highlight' => 'এখন ১০০% ডিজিটাল ও স্বয়ংক্রিয়',
        'hero_subtitle' => 'অনলাইন ভর্তি, ফি ও হিসাব, রেজাল্ট শিট, স্মার্ট বায়োমেট্রিক হাজিরা, রিয়েল-টাইম এসএমএস ও জিপিএস বাস ট্র্যাকিং — সবকিছু একটিমাত্র সুরক্ষিত ক্লাউড প্ল্যাটফর্মে।',
        'hero_btn_demo' => 'লাইভ ইন্টারঅ্যাক্টিভ ডেমো 🚀',
        'hero_btn_request' => 'ডেডিকেটেড ডেমো বুক করুন',
        'hero_stats_schools' => 'সক্রিয় প্রতিষ্ঠান',
        'hero_stats_students' => 'শিক্ষার্থী পরিচালিত',
        'hero_stats_modules' => 'শক্তিশালী মডিউল',
        'hero_stats_uptime' => 'ক্লাউড আপটাইম গ্যারান্টি',

        // Modules Section
        'modules_heading_badge' => 'পূর্ণাঙ্গ সমাধান',
        'modules_title' => 'শিক্ষা প্রতিষ্ঠান পরিচালনার ৮০+ আধুনিক মডিউল',
        'modules_subtitle' => 'প্লে গ্রুপ থেকে কলেজ — যেকোনো শিক্ষা প্রতিষ্ঠানের দৈনন্দিন জটিল কাজগুলো এখন হবে সহজ, দ্রুত ও নির্ভুল।',
        
        'mod_admission_title' => 'অনলাইন ও অফলাইন ভর্তি',
        'mod_admission_desc' => 'ডিজিটাল ভর্তি ফরম, ফি পেমেন্ট গেটওয়ে (bKash/Nagad), অ্যাডমিশন স্লিপ ও সিট কার্ড স্বয়ংক্রিয় প্রিন্ট।',

        'mod_attendance_title' => 'স্মার্ট বায়োমেট্রিক হাজিরা',
        'mod_attendance_desc' => 'ZKTeco বায়োমেট্রিক ও RFID কার্ড সিঙ্ক। শিক্ষার্থী ঢুকলে বা বের হলে সাথে সাথে অভিভাবকের ফোনে এসএমএস।',

        'mod_accounts_title' => 'ফি ও স্বয়ংক্রিয় হিসাবরক্ষণ',
        'mod_accounts_desc' => 'মাসিক বেতন, সেশন ফি, পরীক্ষার ফি ভাউচার, বকেয়া তালিকা, ডেইলি কালেকশন ও ট্রায়াল ব্যালেন্স রিপোর্ট।',

        'mod_exams_title' => 'পরীক্ষা ও অটো রেজাল্ট শিট',
        'mod_exams_desc' => 'মার্ক এন্ট্রি, জিপিএ ও গ্রেডিং সিস্টেম, মেরিট লিস্ট এবং এক ক্লিকে রঙিন প্রোগ্রেস রিপোর্ট কার্ড তৈরি।',

        'mod_transport_title' => 'লাইভ জিপিএস বাস ট্র্যাকিং',
        'mod_transport_desc' => 'Traccar সার্ভার ইন্টিগ্রেশন। স্কুল বাসের লাইভ লোকেশন, পিকআপ/ড্রপ পয়েন্ট অ্যালার্ট ও ট্রান্সপোর্ট ফি।',

        'mod_liveclass_title' => 'লাইভ ক্লাস ও ভিডিও লাইব্রেরি',
        'mod_liveclass_desc' => '100ms দিয়ে হাই-কোয়ালিটি লাইভ ইন্টারঅ্যাক্টিভ ক্লাস এবং ক্লাসের রেকর্ডিং ইউটিউবে অটোমেটিক আপলোড।',

        'mod_sms_title' => 'স্মার্ট এসএমএস ও হোয়াটসঅ্যাপ',
        'mod_sms_desc' => 'হাজিরা, ফি আদায়, জরুরি নোটিশ ও পরীক্ষার ফলাফল সরাসরি বাংলা এসএমএস ও হোয়াটসঅ্যাপে প্রেরণ।',

        'mod_multibranch_title' => 'মাল্টি-ব্রাঞ্চ সাপোর্ট',
        'mod_multibranch_desc' => 'একাধিক শাখা থাকলে একটি সফটওয়্যার থেকেই প্রতিটি ব্রাঞ্চের আলাদা ডেটা, শিফট ও ইউজার নিয়ন্ত্রণ।',

        'mod_idcard_title' => 'আইডি কার্ড ও সার্টিফিকেট',
        'mod_idcard_desc' => 'বারকোড/কিউআর কোডসহ শিক্ষার্থী ও শিক্ষক আইডি কার্ড, প্রশংসাপত্র ও ট্রান্সফার সার্টিফিকেট প্রিন্ট।',

        // Why Choose Us
        'why_heading_badge' => 'কেন আমরা সেরা',
        'why_title' => 'কেন শত শত শিক্ষা প্রতিষ্ঠান আমাদের উপর আস্থা রাখছে?',
        'why_subtitle' => 'শুধু একটি সফটওয়্যার নয়, আপনার প্রতিষ্ঠানের প্রতিটি স্তরকে উন্নত করার জন্য এটি একটি নির্ভরযোগ্য ডিজিটাল পার্টনার।',
        
        'why_feat1_title' => 'ব্যবহার করা অত্যন্ত সহজ',
        'why_feat1_desc' => 'সহজ বাংলা ও ইংরেজি ইন্টারফেস। কম্পিউটার জানা যেকোনো স্টাফ মাত্র ১ ঘণ্টার ট্রেনিংয়ে দক্ষ হতে পারবে।',

        'why_feat2_title' => 'ডেডিকেটেড ক্লাউড সিকিউরিটি',
        'why_feat2_desc' => 'প্রতিটি স্কুলের ডেটা সম্পূর্ণ আলাদা ডেটাবেজে সংরক্ষিত। অটোমেটিক দৈনিক ব্যাকআপ ও ২৪/৭ ডেটা প্রোটেকশন।',

        'why_feat3_title' => 'মোবাইল ও ট্যাবলেট ফ্রেন্ডলি',
        'why_feat3_desc' => 'স্মার্টফোন, ট্যাবলেট, ল্যাপটপ বা কম্পিউটার — যেকোনো ডিভাইসে ব্রাউজার থেকেই ফুল স্পিডে চলবে।',

        'why_feat4_title' => '২৪/৭ সরাসরি সাপোর্ট ও ট্রেনিং',
        'why_feat4_desc' => 'আমাদের দক্ষ সাপোর্ট টিম সার্বক্ষণিক প্রস্তুত। ডেটা এন্ট্রি ট্রেনিং ও ফ্রি টেকনিক্যাল সাপোর্ট সার্বক্ষণিক।',

        // Pricing Section
        'pricing_heading_badge' => 'স্বচ্ছ প্যাকেজ',
        'pricing_title' => 'আপনার প্রতিষ্ঠানের চাহিদামতো সঠিক প্যাকেজ বেছে নিন',
        'pricing_subtitle' => 'কোনো লুকায়িত চার্জ নেই। আপনার স্কুলের আকার অনুযায়ী সেরা বাজেট-বান্ধব প্ল্যান।',
        'pricing_period' => '/মাসিক',
        'pricing_billed_yearly' => '(বার্ষিক পরিশোধে বিশেষ ছাড় প্রযোজ্য)',

        'plan_trial_name' => 'ফ্রি ট্রায়াল',
        'plan_trial_price' => '৳০',
        'plan_trial_desc' => '১৪ দিনের জন্য সফটওয়্যারের আসল কার্যকারিতা পরীক্ষা করে দেখার জন্য।',
        'plan_trial_cta' => 'বিনামূল্যে শুরু করুন',

        'plan_basic_name' => 'বেসিক প্ল্যান',
        'plan_basic_price' => '৳২,৫০০',
        'plan_basic_desc' => 'ছোট ও মাঝারি স্কুল বা কিন্ডারগার্টেনের জন্য আদর্শ ডিজিটাল সূচনা।',
        'plan_basic_cta' => 'বেসিক প্ল্যান নিন',

        'plan_standard_name' => 'স্ট্যান্ডার্ড প্ল্যান',
        'plan_standard_price' => '৳৫,০০০',
        'plan_standard_badge' => 'সর্বাধিক জনপ্রিয়',
        'plan_standard_desc' => 'পূর্ণাঙ্গ হাই স্কুল ও মাদ্রাসার যাবতীয় অ্যাকাউন্টিং ও এসএমএস সুবিধার জন্য।',
        'plan_standard_cta' => 'স্ট্যান্ডার্ড প্ল্যান নিন',

        'plan_premium_name' => 'প্রিমিয়াম এন্টারপ্রাইজ',
        'plan_premium_price' => '৳৮,৫০০',
        'plan_premium_desc' => 'মাল্টি-ব্রাঞ্চ, বায়োমেট্রিক হাজিরা, লাইভ ক্লাস ও জিপিএস বাস ট্র্যাকিং সম্বলিত সম্পূর্ণ সল্যুশন।',
        'plan_premium_cta' => 'প্রিমিয়াম প্ল্যান নিন',

        // Pricing Feature Check items
        'feat_students_limit' => 'সর্বোচ্চ :limit শিক্ষার্থী',
        'feat_students_unlimited' => 'আনলিমিটেড শিক্ষার্থী',
        'feat_branches' => ':count টি শাখা নিয়ন্ত্রণ',
        'feat_admission_online' => 'ডিজিটাল ভর্তি সিস্টেম',
        'feat_fees_accounts' => 'ফি ও অ্যাকাউন্টিং মডিউল',
        'feat_exams_result' => 'পরীক্ষা ও অটো রেজাল্ট কার্ড',
        'feat_sms_gateway' => 'বাংলা অটো এসএমএস গেটওয়ে',
        'feat_biometric_sync' => 'বায়োমেট্রিক মেশিন ইন্টিগ্রেশন',
        'feat_live_classes' => '100ms লাইভ ক্লাস ও ইউটিউব ব্যাকআপ',
        'feat_gps_bus' => 'লাইভ জিপিএস বাস ট্র্যাকিং',
        'feat_custom_domain' => 'কাস্টম ডোমেইন সাপোর্ট (own domain)',
        'feat_support' => '২৪/৭ প্রায়োরিটি ফোন ও হোয়াটসঅ্যাপ সাপোর্ট',

        // Request Demo Modal
        'modal_demo_title' => 'একটি ফ্রি ডেডিকেটেড ডেমো বুক করুন',
        'modal_demo_subtitle' => 'ফর্মটি পূরণ করুন। আমাদের বিশেষজ্ঞ টিম আপনার সুবিধাজনক সময়ে লাইভ ভিডিও কলে সফটওয়্যারের সম্পূর্ণ ডেমো দেখাবে।',
        'modal_field_school' => 'প্রতিষ্ঠানের নাম',
        'modal_field_school_ph' => 'যেমন: আদর্শ উচ্চ বিদ্যালয়',
        'modal_field_contact' => 'দায়িত্বপ্রাপ্ত ব্যক্তির নাম',
        'modal_field_contact_ph' => 'যেমন: মোঃ রফিকুল ইসলাম (প্রধান শিক্ষক)',
        'modal_field_phone' => 'মোবাইল নম্বর',
        'modal_field_phone_ph' => 'যেমন: 017XXXXXXXX',
        'modal_field_email' => 'ইমেইল অ্যাড্রেস (যদি থাকে)',
        'modal_field_email_ph' => 'যেমন: school@example.com',
        'modal_field_students' => 'আনুমানিক শিক্ষার্থী সংখ্যা',
        'modal_field_students_opt1' => '২০০ এর নিচে',
        'modal_field_students_opt2' => '২০০ - ৫০০ জন',
        'modal_field_students_opt3' => '৫০০ - ১,৫০০ জন',
        'modal_field_students_opt4' => '১,৫০০ জনের বেশি',
        'modal_field_date' => 'ডেমো দেখার সুবিধাজনক তারিখ',
        'modal_btn_submit' => 'ডেমোর জন্য আবেদন জমা দিন',
        'modal_submitting' => 'জমা হচ্ছে...',
        'modal_success_title' => 'ধন্যবাদ! আপনার আবেদন সফলভাবে জমা হয়েছে।',
        'modal_success_desc' => 'আমাদের টেকনিক্যাল টিম খুব শীঘ্রই আপনার দেওয়া নম্বরে যোগাযোগ করে লাইভ ডেমো কনফার্ম করবে।',
        'modal_btn_close' => 'ঠিক আছে',

        // Trust & Security Section
        'trust_badge' => 'নিরাপত্তা ও ডেটা সুরক্ষার নিশ্চয়তা',
        'trust_title' => 'আপনার প্রতিষ্ঠানের প্রতিটি ডেটা শতভাগ সুরক্ষিত',
        'trust_p1_title' => 'আলাদা ও সুরক্ষিত ডেটাবেজ',
        'trust_p1_desc' => 'আমাদের মাল্টি-টেন্যান্ট আর্কিটেকচারে প্রতিটা প্রতিষ্ঠানের জন্য সম্পূর্ণ পৃথক ডেটাবেজ সংরক্ষিত থাকে, ফলে এক স্কুলের ডেটা অন্য স্কুল দেখতে পারে না।',
        'trust_p2_title' => 'স্বয়ংক্রিয় ক্লাউড ব্যাকআপ',
        'trust_p2_desc' => 'সার্ভারে প্রতিদিন মধ্যরাতে স্বয়ংক্রিয় সম্পূর্ণ ব্যাকআপ সংরক্ষিত হয়। কোনো দুর্ঘটনা ঘটলেও কয়েক মিনিটে সম্পূর্ণ ডেটা পুনরুদ্ধার সম্ভব।',
        'trust_p3_title' => 'এসএসএল এনক্রিপশন',
        'trust_p3_desc' => 'প্রতিটি সেশন এবং পেমেন্ট ট্রানজেকশন ২৫৬-বিট এসএসএল (HTTPS) এনক্রিপশন দ্বারা বিশ্বস্ততার সাথে সুরক্ষিত।',

        // Footer
        'footer_about' => 'স্কুল ইআরপি হলো বাংলাদেশের আধুনিক স্কুল, কলেজ, মাদ্রাসা ও কিন্ডারগার্টেনের দৈনন্দিন প্রশাসনিক কাজগুলোকে ডিজিটাল করার শীর্ষস্থানীয় ক্লাউড সফটওয়্যার।',
        'footer_quick_links' => 'প্রয়োজনীয় লিংক',
        'footer_modules' => 'শীর্ষ মডিউলসমূহ',
        'footer_contact_us' => 'সরাসরি যোগাযোগ',
        'footer_phone' => '+৮৮০১XXXXXXXXX',
        'footer_email' => 'support@schooledo.com',
        'footer_address' => 'ঢাকা, বাংলাদেশ',
        'footer_copyright' => 'সর্বস্বত্ব সংরক্ষিত।',
        'footer_privacy' => 'প্রাইভেসি পলিসি',
        'footer_terms' => 'শর্তাবলী ও নীতিমালা',
    ],

    // ══════════════════════════════════════════════════════════
    // ENGLISH
    // ══════════════════════════════════════════════════════════
    'en' => [
        // Meta & Branding
        'meta_title' => 'School ERP — Complete Cloud School Management Software',
        'meta_desc' => 'Digital admission, automated fee collection, biometric attendance, live video classes, GPS bus tracking and complete school automation.',
        'brand_name' => 'School ERP',
        'brand_tagline' => 'Smart Cloud Platform for Modern Education',
        
        // Navigation
        'nav_home' => 'Home',
        'nav_features' => 'Modules & Features',
        'nav_why_us' => 'Why Choose Us',
        'nav_pricing' => 'Pricing Plans',
        'nav_security' => 'Security & Cloud',
        'nav_contact' => 'Contact',
        'nav_live_demo' => 'Live Demo 🚀',
        'nav_login' => 'Login',
        'nav_superadmin' => 'Master Panel',
        
        // Hero Section
        'hero_badge' => '✨ All-in-One Cloud ERP for Progressive Educational Institutions',
        'hero_title_1' => 'Manage Your Entire School Operations',
        'hero_title_highlight' => '100% Digital & Automated',
        'hero_subtitle' => 'Online admissions, fees and accounting, digital report cards, smart biometric attendance, automated SMS alerts and live GPS bus tracking — all on one secure cloud platform.',
        'hero_btn_demo' => 'Live Interactive Demo 🚀',
        'hero_btn_request' => 'Request Dedicated Demo',
        'hero_stats_schools' => 'Active Institutions',
        'hero_stats_students' => 'Students Managed',
        'hero_stats_modules' => 'Robust Modules',
        'hero_stats_uptime' => 'Cloud Uptime Guarantee',

        // Modules Section
        'modules_heading_badge' => 'Complete Ecosystem',
        'modules_title' => '80+ Enterprise Modules to Power Your Institution',
        'modules_subtitle' => 'From Playgroup to College — every complex day-to-day operation is streamlined with maximum speed, clarity, and precision.',
        
        'mod_admission_title' => 'Online & Offline Admissions',
        'mod_admission_desc' => 'Digital application portal, integrated payment gateways (bKash/Nagad), auto admission slips & seat cards generator.',

        'mod_attendance_title' => 'Smart Biometric Attendance',
        'mod_attendance_desc' => 'ZKTeco biometrics & RFID card sync with instant automated arrival/departure SMS notifications to parents.',

        'mod_accounts_title' => 'Fees & Automated Accounting',
        'mod_accounts_desc' => 'Monthly tuition, exam fee vouchers, due tracking, daily collection reports, and double-entry trial balance ledger.',

        'mod_exams_title' => 'Examinations & Auto Report Cards',
        'mod_exams_desc' => 'Mark entries, customizable GPA/grading rules, merit position ranking, and 1-click printable color progress cards.',

        'mod_transport_title' => 'Live GPS Bus Tracking',
        'mod_transport_desc' => 'Integrated Traccar tracking server. Live bus geolocation on maps, pickup/drop geofencing, and transport fee management.',

        'mod_liveclass_title' => 'Live Classroom & Video Archive',
        'mod_liveclass_desc' => 'High-definition interactive live classes powered by 100ms, with automated background upload of recordings to YouTube.',

        'mod_sms_title' => 'Smart SMS & WhatsApp Gateway',
        'mod_sms_desc' => 'Dynamic Bangla & English notifications for attendance, fee reminders, exam results, and administrative emergency circulars.',

        'mod_multibranch_title' => 'Multi-Branch Administration',
        'mod_multibranch_desc' => 'Manage multiple school branches with isolated campus data, shifts, and role-based staff permissions from a unified system.',

        'mod_idcard_title' => 'ID Cards & Certificates Generator',
        'mod_idcard_desc' => 'Printable student and faculty ID cards with Barcodes/QR codes, Transfer Certificates (TC), and Testimonials.',

        // Why Choose Us
        'why_heading_badge' => 'Our Advantage',
        'why_title' => 'Why Institutions Trust Our Cloud School ERP',
        'why_subtitle' => 'More than just software — an enduring digital transformation partner tailored for long-term educational excellence.',
        
        'why_feat1_title' => 'Effortless & Intuitive to Use',
        'why_feat1_desc' => 'Clean bilingual interface in Bangla and English. Any staff member can master the platform with less than 1 hour of training.',

        'why_feat2_title' => 'Dedicated Database Isolation',
        'why_feat2_desc' => 'True multi-tenant architecture where your institution enjoys an isolated, dedicated database with automatic daily snapshots.',

        'why_feat3_title' => 'Mobile, Tablet & PC Responsive',
        'why_feat3_desc' => 'Optimized for high-speed responsiveness across smartphones, tablets, laptops, and administrative desktops.',

        'why_feat4_title' => '24/7 Dedicated Support & Onboarding',
        'why_feat4_desc' => 'Continuous technical support, free initial data migration assistance, and on-demand staff training sessions.',

        // Pricing Section
        'pricing_heading_badge' => 'Transparent Plans',
        'pricing_title' => 'Choose the Right Plan for Your Institution',
        'pricing_subtitle' => 'Zero hidden setup fees. Predictable, budget-friendly pricing designed to scale with your institution.',
        'pricing_period' => '/month',
        'pricing_billed_yearly' => '(Special discounts available for annual billing)',

        'plan_trial_name' => 'Free Trial',
        'plan_trial_price' => '$0',
        'plan_trial_desc' => '14 days of full feature exploration with zero commitment.',
        'plan_trial_cta' => 'Start Free Trial',

        'plan_basic_name' => 'Basic Plan',
        'plan_basic_price' => '$25',
        'plan_basic_desc' => 'Perfect digital kickoff for small to medium kindergartens and primary schools.',
        'plan_basic_cta' => 'Get Basic Plan',

        'plan_standard_name' => 'Standard Plan',
        'plan_standard_price' => '$50',
        'plan_standard_badge' => 'Most Popular',
        'plan_standard_desc' => 'Comprehensive management solution for modern high schools, colleges, and madrasas.',
        'plan_standard_cta' => 'Get Standard Plan',

        'plan_premium_name' => 'Premium Enterprise',
        'plan_premium_price' => '$85',
        'plan_premium_desc' => 'Unrestricted power with multi-branch, biometric sync, live video classes, and GPS tracking.',
        'plan_premium_cta' => 'Get Premium Plan',

        // Pricing Feature Check items
        'feat_students_limit' => 'Up to :limit Students',
        'feat_students_unlimited' => 'Unlimited Students',
        'feat_branches' => ':count Campus / Branch Management',
        'feat_admission_online' => 'Online Admissions Portal',
        'feat_fees_accounts' => 'Fees & Accounting Ledger',
        'feat_exams_result' => 'Examinations & Auto Report Cards',
        'feat_sms_gateway' => 'SMS Gateway Integration',
        'feat_biometric_sync' => 'Biometric Machine Synchronization',
        'feat_live_classes' => '100ms Live Classes & YouTube Sync',
        'feat_gps_bus' => 'Live GPS Bus Tracking',
        'feat_custom_domain' => 'Custom Domain Support (own URL)',
        'feat_support' => '24/7 Priority Phone & WhatsApp Support',

        // Request Demo Modal
        'modal_demo_title' => 'Book a Free Personalized Live Demo',
        'modal_demo_subtitle' => 'Fill out the form below. Our product specialists will arrange a tailored screen-share walkthrough at your convenience.',
        'modal_field_school' => 'Institution / School Name',
        'modal_field_school_ph' => 'e.g. Model High School & College',
        'modal_field_contact' => 'Contact Person Name & Title',
        'modal_field_contact_ph' => 'e.g. Dr. John Doe (Principal)',
        'modal_field_phone' => 'Mobile / WhatsApp Number',
        'modal_field_phone_ph' => 'e.g. +880 17XXXXXXXX',
        'modal_field_email' => 'Official Email (Optional)',
        'modal_field_email_ph' => 'e.g. admin@school.edu',
        'modal_field_students' => 'Approximate Student Count',
        'modal_field_students_opt1' => 'Under 200 Students',
        'modal_field_students_opt2' => '200 - 500 Students',
        'modal_field_students_opt3' => '500 - 1,500 Students',
        'modal_field_students_opt4' => 'Over 1,500 Students',
        'modal_field_date' => 'Preferred Demo Date',
        'modal_btn_submit' => 'Submit Demo Request',
        'modal_submitting' => 'Submitting...',
        'modal_success_title' => 'Thank you! Your Demo Request has been received.',
        'modal_success_desc' => 'Our technical team will reach out to your contact number shortly to confirm the scheduled walkthrough.',
        'modal_btn_close' => 'Close',

        // Trust & Security Section
        'trust_badge' => 'Enterprise Security & SLA',
        'trust_title' => 'Your Institution’s Data is 100% Isolated & Protected',
        'trust_p1_title' => 'Isolated Database per Institution',
        'trust_p1_desc' => 'Our SaaS architecture provisions a separate, dedicated database for each school, completely preventing cross-tenant data leakage.',
        'trust_p2_title' => 'Automated Daily Cloud Backups',
        'trust_p2_desc' => 'Full automated database and asset snapshots are archived offsite every night. Zero data loss guarantee.',
        'trust_p3_title' => '256-Bit SSL/TLS Encryption',
        'trust_p3_desc' => 'Every browser session, student grade, and financial transaction is secured with bank-grade SSL encryption protocols.',

        // Footer
        'footer_about' => 'School ERP is the premier cloud management suite engineered to automate administrative workflows for schools, colleges, and madrasas.',
        'footer_quick_links' => 'Quick Navigation',
        'footer_modules' => 'Core Modules',
        'footer_contact_us' => 'Direct Contact',
        'footer_phone' => '+880 1XXXXXXXXX',
        'footer_email' => 'support@schooledo.com',
        'footer_address' => 'Dhaka, Bangladesh',
        'footer_copyright' => 'All Rights Reserved.',
        'footer_privacy' => 'Privacy Policy',
        'footer_terms' => 'Terms of Service',
    ],
];
