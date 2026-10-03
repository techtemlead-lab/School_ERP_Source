<?php
/**
 * config/lang.php
 * -----------------------------------------------------------
 * পাবলিক ওয়েবসাইটের জন্য সাধারণ Bangla/English টগল সিস্টেম।
 *
 * ব্যবহারঃ প্রতিটা পাবলিক পেজে (session_start() এর পরে, HTML প্রিন্ট হওয়ার আগে) —
 *   require_once __DIR__ . '/config/lang.php';
 * তারপর সব জায়গায় ঠিক টেক্সটের বদলে —
 *   <?php echo t('nav_home'); ?>
 * এভাবে লিখলেই বর্তমান ভাষা (Bangla/English) অনুযায়ী সঠিক টেক্সট প্রিন্ট হবে।
 *
 * নোটঃ এটা শুধু আমাদের নিজেদের লেখা স্ট্যাটিক UI টেক্সট (মেনু, বাটন, হেডিং, লেবেল)
 * অনুবাদ করে। Admin Panel থেকে Admin নিজে যা লেখেন (নোটিশের শিরোনাম, গ্যালারির
 * ক্যাপশন, টিচারের ডিজাইগনেশন ইত্যাদি — ডাটাবেজে সংরক্ষিত ডাটা), সেগুলো Admin
 * যেভাবে লিখেছেন সেভাবেই থাকে — অটো-ট্রান্সলেট হয় না।
 */

if (!isset($_SESSION)) {
    // এই ফাইল যদি কখনো session_start() এর আগে require হয়ে যায়, নিরাপদে নিজেই শুরু করে নেবে
    session_start();
}

// ?lang=bn অথবা ?lang=en URL এ থাকলে সেটাই এখন থেকে এই ভিজিটরের জন্য মনে রাখা হবে (session-এ)
if (isset($_GET['lang']) && in_array($_GET['lang'], ['bn', 'en'], true)) {
    $_SESSION['site_lang'] = $_GET['lang'];
}

// ডিফল্ট ভাষা: English — ভিজিটর নিজে BN/EN টগল থেকে বদলালে সেটাই সেশনে মনে থাকে
if (!isset($_SESSION['site_lang']) || !in_array($_SESSION['site_lang'], ['bn', 'en'], true)) {
    $_SESSION['site_lang'] = 'en';
}

function current_lang(): string
{
    return $_SESSION['site_lang'] ?? 'en';
}

/**
 * বর্তমান পেজের URL-এ শুধু lang প্যারামিটারটা বদলে (বাকি সব query string/anchor
 * ঠিক রেখে) একটা লিংক বানায় — হেডারের BN/EN টগল বাটনের জন্য ব্যবহৃত হয়।
 */
function lang_switch_url(string $lang): string
{
    $params = $_GET;
    $params['lang'] = $lang;
    $path = strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?');
    return htmlspecialchars($path . '?' . http_build_query($params));
}

/**
 * মূল অনুবাদ ডিকশনারি — প্রতিটা কী-এর জন্য bn/en দুটোই। নতুন কোনো স্ট্যাটিক টেক্সট
 * অনুবাদ করতে চাইলে এখানে একটা নতুন এন্ট্রি যোগ করে পেজে t('your_key') বসিয়ে দিন।
 */
function site_i18n_dictionary(): array
{
    return [
        // ---------- TOP BAR / HEADER ----------
        'eiin_label'            => ['bn' => 'EIIN', 'en' => 'EIIN'],
        'helpline_label'        => ['bn' => 'হেল্পলাইন:', 'en' => 'Helpline:'],
        'eiin_portal'           => ['bn' => 'EIIN পোর্টাল', 'en' => 'EIIN Portal'],
        'admission_apply_btn'   => ['bn' => 'ভর্তি আবেদন', 'en' => 'Admission Apply'],
        'login_label'           => ['bn' => 'লগইন', 'en' => 'Login'],
        'student_login'         => ['bn' => 'স্টুডেন্ট লগইন', 'en' => 'Student Login'],
        'teacher_login'         => ['bn' => 'টিচার লগইন', 'en' => 'Teacher Login'],
        'admin_login'           => ['bn' => 'এডমিন লগইন', 'en' => 'Admin Login'],
        'student_short'         => ['bn' => 'স্টুডেন্ট', 'en' => 'Student'],
        'teacher_short'         => ['bn' => 'টিচার', 'en' => 'Teacher'],
        'admin_short'           => ['bn' => 'এডমিন', 'en' => 'Admin'],
        'breaking_news_label'   => ['bn' => '🔴 ব্রেকিং নিউজ', 'en' => '🔴 Breaking News'],

        // ---------- MAIN NAV MENU ----------
        'nav_home'              => ['bn' => 'হোম', 'en' => 'Home'],
        'nav_about'             => ['bn' => 'আমাদের সম্পর্কে', 'en' => 'About Us'],
        'nav_academic'          => ['bn' => 'একাডেমিক', 'en' => 'Academic'],
        'nav_teachers'          => ['bn' => 'শিক্ষক ও স্টাফ', 'en' => 'Teachers & Staff'],
        'nav_notice'            => ['bn' => 'নোটিশ', 'en' => 'Notice'],
        'nav_result'            => ['bn' => 'ফলাফল', 'en' => 'Result'],
        'nav_result_board'      => ['bn' => 'Board Result', 'en' => 'Board Result'],
        'nav_result_academic'   => ['bn' => 'Academic Result', 'en' => 'Academic Result'],
        'nav_result_stats'      => ['bn' => 'Result Statistics', 'en' => 'Result Statistics'],
        'nav_video'             => ['bn' => 'ভিডিও ক্লাস', 'en' => 'Video Class'],
        'nav_news'              => ['bn' => 'নিউজ', 'en' => 'News'],
        'nav_career'            => ['bn' => 'ক্যারিয়ার', 'en' => 'Career'],
        'nav_gallery'           => ['bn' => 'গ্যালারি', 'en' => 'Gallery'],
        'nav_contact'           => ['bn' => 'যোগাযোগ', 'en' => 'Contact'],

        // ---------- HOMEPAGE: SIDEBAR / NOTICES / EVENTS ----------
        'latest_notices'        => ['bn' => 'সর্বশেষ নোটিশ', 'en' => 'Latest Notices'],
        'no_notice'             => ['bn' => 'কোনো নোটিশ নেই', 'en' => 'No notice available'],
        'view_all'              => ['bn' => 'সব', 'en' => 'All'],
        'upcoming_events'       => ['bn' => 'আসন্ন ইভেন্টসমূহ', 'en' => 'Upcoming Events'],
        'no_event'              => ['bn' => 'কোনো ইভেন্ট নেই', 'en' => 'No event available'],

        // ---------- OUR ACHIEVEMENTS / STATS ----------
        'our_achievements'      => ['bn' => 'আমাদের অর্জন', 'en' => 'Our Achievements'],
        'stat_students'         => ['bn' => 'ছাত্র-ছাত্রী', 'en' => 'Students'],
        'stat_teachers'         => ['bn' => 'শিক্ষক-শিক্ষিকা', 'en' => 'Teachers'],
        'stat_staffs'           => ['bn' => 'স্টাফ', 'en' => 'Staffs'],
        'stat_success_rate'     => ['bn' => 'পাশের হার', 'en' => 'Success Rate'],

        // ---------- TEACHERS SECTION ----------
        'our_teachers_staffs'   => ['bn' => 'OUR TEACHERS AND STAFFS', 'en' => 'OUR TEACHERS AND STAFFS'],
        'filter_all'            => ['bn' => 'All', 'en' => 'All'],
        'filter_teacher'        => ['bn' => 'Teacher', 'en' => 'Teacher'],
        'filter_staff'          => ['bn' => 'Staff', 'en' => 'Staff'],
        'filter_all_sections'   => ['bn' => 'সব সেকশন', 'en' => 'All Sections'],
        'filter_school_section' => ['bn' => 'স্কুল সেকশন', 'en' => 'School Section'],
        'filter_college_section' => ['bn' => 'কলেজ সেকশন', 'en' => 'College Section'],
        'sec_school_branch'     => ['bn' => 'স্কুল শাখা', 'en' => 'School Section'],
        'sec_college_branch'    => ['bn' => 'কলেজ শাখা', 'en' => 'College Section'],
        'see_all'               => ['bn' => 'See All ➔', 'en' => 'See All ➔'],
        'no_teacher_staff'      => ['bn' => 'এখনো কোনো Teacher/Staff যোগ করা হয়নি।', 'en' => 'No Teacher/Staff has been added yet.'],
        'all_teachers_staffs'   => ['bn' => 'সকল Teacher & Staff', 'en' => 'All Teachers & Staff'],

        // ---------- SUPPORT LINKS ----------
        'edu_support_links'     => ['bn' => 'শিক্ষা সহায়িকা লিংকসমূহ', 'en' => 'Education Support Links'],
        'admission_form_lbl'    => ['bn' => 'ভর্তি ফরম', 'en' => 'Admission Form'],
        'syllabus_lbl'          => ['bn' => 'সিলেবাস', 'en' => 'Syllabus'],
        'class_routine_lbl'     => ['bn' => 'ক্লাস রুটিন', 'en' => 'Class Routine'],

        // ---------- VIDEO SECTION ----------
        'video_section_title'   => ['bn' => 'গুরুত্বপূর্ণ ক্লাসের ভিডিও ও টিউটোরিয়াল', 'en' => 'Important Class Videos & Tutorials'],
        'all_classes'           => ['bn' => 'সকল শ্রেণি (All Classes)', 'en' => 'All Classes'],
        'search_video_ph'       => ['bn' => '🔍 ভিডিও খুঁজুন...', 'en' => '🔍 Search videos...'],
        'watch_on_youtube'      => ['bn' => 'Watch on', 'en' => 'Watch on'],
        'visit_youtube_channel' => ['bn' => 'আমাদের ইউটিউব চ্যানেল ভিজিট করুন', 'en' => 'Visit our YouTube channel'],

        // ---------- GALLERY SECTION ----------
        'photo_gallery'         => ['bn' => 'ফটো গ্যালারি', 'en' => 'Photo Gallery'],
        'all_photo_gallery'     => ['bn' => 'সকল ফটো গ্যালারি', 'en' => 'All Photo Gallery'],
        'no_gallery_photo'      => ['bn' => 'কোনো গ্যালারি ছবি নেই', 'en' => 'No gallery photo available'],
        'photo_album'           => ['bn' => 'ফটো অ্যালবাম', 'en' => 'Photo Album'],

        // ---------- FOOTER ----------
        'important_links'       => ['bn' => 'গুরুত্বপূর্ণ লিংক', 'en' => 'Important Links'],
        'all_rights_reserved'   => ['bn' => 'সর্বস্বত্ব সংরক্ষিত', 'en' => 'All rights reserved'],
        'back_to_home'          => ['bn' => 'হোমে ফিরে যান', 'en' => 'Back to Home'],
        'home_label'            => ['bn' => 'হোম', 'en' => 'Home'],

        // ---------- HOMEPAGE: HERO / BREAKING NEWS / PRINCIPAL MESSAGE ----------
        'hero_default_title'    => ['bn' => 'স্বাগতম', 'en' => 'Welcome'],
        'hero_default_desc'     => ['bn' => 'Website Module থেকে স্লাইড যোগ করুন।', 'en' => 'Add slides from the Website Module.'],
        'urgent_notice_label'   => ['bn' => 'জরুরি নোটিশ', 'en' => 'Urgent Notice'],
        'principal_message'     => ['bn' => 'অধ্যক্ষের বাণী', 'en' => "Principal's Message"],
        'principal_default_name'=> ['bn' => 'প্রফেসর মো: আবদুর রহমান', 'en' => 'Professor Md. Abdur Rahman'],
        'principal_default_desg'=> ['bn' => 'অধ্যক্ষ, ', 'en' => 'Principal, '],
        'principal_default_msg' => ['bn' => 'আমাদের প্রতিষ্ঠানে আপনাকে স্বাগতম। আমরা বিশ্বাস করি কেবল পাঠ্যপুস্তকের বিদ্যা নয়, বরং নৈতিকতা ও আধুনিক প্রযুক্তির সঠিক মেলবন্ধনেই তৈরি হয় প্রকৃত মানুষ...', 'en' => 'Welcome to our institution. We believe that a true human being is shaped not only by textbook knowledge, but by the right blend of ethics and modern technology...'],

        // ---------- ABOUT US / WHY CHOOSE US ----------
        'about_institution'     => ['bn' => 'আমাদের প্রতিষ্ঠান সম্পর্কে', 'en' => 'About Our Institution'],
        'why_choose_us'         => ['bn' => 'কেন আমাদের প্রতিষ্ঠান বেছে নেবেন?', 'en' => 'Why Choose Our Institution?'],
        'why_choose_intro'      => ['bn' => ' - এর নিজস্ব কতকগুলো ব্যতিক্রমধর্মী বৈশিষ্ট্য রয়েছে যে বৈশিষ্ট্যগুলোর কারণেই মূলত এই স্কুলে আগত ছাত্র-ছাত্রীরা ধাপে ধাপে নিজেদের উন্নয়ন ঘটাতে পারে...', 'en' => ' has some of its own distinctive features, and it is because of these features that students coming to this school are able to develop themselves step by step...'],
        'why_choose_default_institution' => ['bn' => 'আমাদের প্রতিষ্ঠান', 'en' => 'Our Institution'],
        'about_empty'           => ['bn' => 'প্রতিষ্ঠানের বিস্তারিত তথ্য শীঘ্রই যোগ করা হবে।', 'en' => 'Details about the institution will be added soon.'],
        'why_li_1'              => ['bn' => 'বিষয়ভিত্তিক সম্মানসহ স্নাতকোত্তর ডিগ্রীধারী শিক্ষক/শিক্ষিকা কর্তৃক পাঠদান।', 'en' => 'Teaching by post-graduate teachers with subject-based honors degrees.'],
        'why_li_2'              => ['bn' => 'সম্পূর্ণ নিরপেক্ষ নিয়োগপরীক্ষার মাধ্যমে যোগ্যতার ভিত্তিতে শিক্ষক/শিক্ষিকা নিয়োগ দান।', 'en' => 'Teachers recruited on merit through a completely fair recruitment exam.'],
        'why_li_3'              => ['bn' => 'গণিত ও ইংরেজি বিষয়ে সাধারণ ক্লাস ছাড়াও অতিরিক্ত ক্লাসের ব্যবস্থা।', 'en' => 'Extra classes in Mathematics and English in addition to regular classes.'],
        'why_li_4'              => ['bn' => 'নিয়মিত শ্রেণিকক্ষে পাঠ্যবিষয় বুঝিয়ে দিয়ে পড়া দেয়া এবং পরের দিন পড়া আদায় করা।', 'en' => 'Lessons explained and assigned in regular classes, checked the following day.'],
        'why_li_5'              => ['bn' => 'দৈনিকপাঠ না শিখলে সংশোধনী ক্লাসের মাধ্যমে পড়া শেখানোর ব্যবস্থা।', 'en' => 'Remedial classes for students who fail to learn the daily lesson.'],
        'why_li_6'              => ['bn' => 'সকল শাখায় ছাত্র-ছাত্রীর সংখ্যা ৩০-৩৫ জনের মধ্যে সীমিত।', 'en' => 'Class size limited to 30-35 students in every section.'],
        'why_li_7'              => ['bn' => "জুনিয়রওয়ান ও প্রথম শ্রেণিতে সার্বক্ষণিক দু'জন শিক্ষকের ব্যবস্থা।", 'en' => 'Two teachers present at all times in Junior-One and Class One.'],
        'read_more'             => ['bn' => 'Read More ➔', 'en' => 'Read More ➔'],
        'messages_title'        => ['bn' => 'বাণী', 'en' => 'Messages'],
        'all_messages'          => ['bn' => 'সকল বাণী', 'en' => 'All Messages'],
        'message_page_label'    => ['bn' => 'বাণী', 'en' => 'Message'],
        'message_not_found'     => ['bn' => 'বাণীটি খুঁজে পাওয়া যায়নি।', 'en' => 'Message not found.'],
        'no_message_found'      => ['bn' => 'এখনো কোনো বাণী যোগ করা হয়নি।', 'en' => 'No messages have been added yet.'],
        'back_to_all_messages'  => ['bn' => 'সকল বাণীতে ফিরে যান', 'en' => 'Back to All Messages'],
        'why_choose_redirect_alert' => ['bn' => 'কেন আমাদের প্রতিষ্ঠান বেছে নেবেন পেজে রিডাইরেক্ট হবে', 'en' => 'Will redirect to the Why Choose Us page'],

        // ---------- EVENTS ----------
        'upcoming_events2'      => ['bn' => 'আগামী ইভেন্টসমূহ', 'en' => 'Upcoming Events'],
        'no_event_added'        => ['bn' => 'এখনো কোনো ইভেন্ট যোগ করা হয়নি।', 'en' => 'No events have been added yet.'],
        'all_events_alert'      => ['bn' => 'সকল ইভেন্ট পেজে রিডাইরেক্ট হবে', 'en' => 'Will redirect to the All Events page'],

        // ---------- FACILITIES / FEATURES ----------
        'our_facilities'        => ['bn' => 'আমাদের সুবিধাসমূহ', 'en' => 'Our Facilities'],
        'facility_digital_title'=> ['bn' => 'ডিজিটাল ক্লাসরুম', 'en' => 'Digital Classroom'],
        'facility_digital_desc' => ['bn' => 'মাল্টিমিডিয়া প্রজেক্টর ও স্মার্ট বোর্ড সমৃদ্ধ আধুনিক শ্রেণী কক্ষ।', 'en' => 'Modern classrooms equipped with multimedia projectors and smart boards.'],
        'facility_science_title'=> ['bn' => 'বিজ্ঞানাগার', 'en' => 'Science Lab'],
        'facility_science_desc' => ['bn' => 'পদার্থ, রসায়ন ও জীববিজ্ঞানের সকল প্রয়োজনীয় যন্ত্রপাতি সমৃদ্ধ আধুনিক ল্যাব।', 'en' => 'Modern lab equipped with all necessary Physics, Chemistry and Biology equipment.'],
        'facility_computer_title'=> ['bn' => 'কম্পিউটার ল্যাব', 'en' => 'Computer Lab'],
        'facility_computer_desc'=> ['bn' => 'হাই-স্পিড ব্রডব্যান্ড ইন্টারনেট সংযোগসহ ৫০টি আধুনিক কম্পিউটার।', 'en' => '50 modern computers with high-speed broadband internet connection.'],
        'facility_library_title'=> ['bn' => 'সমৃদ্ধ লাইব্রেরি', 'en' => 'Rich Library'],
        'facility_library_desc' => ['bn' => 'হাজারো পাঠ্যবই, রেফারেন্স বুক ও পত্রিকার বিশাল সংগ্রহশালা।', 'en' => 'A vast collection of thousands of textbooks, reference books and newspapers.'],
        'facility_sports_title' => ['bn' => 'ইনডোর ও আউটডোর খেলাধুলা', 'en' => 'Indoor & Outdoor Sports'],
        'facility_sports_desc'  => ['bn' => 'বিশাল নিজস্ব খেলার মাঠ এবং নিয়মিত ইনডোর গেমসের সুব্যবস্থা।', 'en' => 'A large playground of its own and regular indoor games facilities.'],
        'facility_cctv_title'   => ['bn' => 'সিসিটিভি ও পূর্ণাঙ্গ নিরাপত্তা', 'en' => 'CCTV & Full Security'],
        'facility_cctv_desc'    => ['bn' => 'সমগ্র ক্যাম্পাস সার্বক্ষণিক সিসিটিভি ক্যামেরা দ্বারা নিয়ন্ত্রিত।', 'en' => 'The entire campus is monitored around the clock by CCTV cameras.'],

        // ---------- SUPPORT LINKS ----------
        'edu_support_desc'      => ['bn' => 'শিক্ষার্থী, শিক্ষক ও অভিভাবকদের সুবিধার্থে আমরা প্রয়োজনীয় শিক্ষা সামগ্রী ও তথ্য সহজে পাওয়ার ব্যবস্থা করেছি।', 'en' => 'For the convenience of students, teachers and guardians, we have made it easy to access necessary educational materials and information.'],
        'exam_routine_lbl'      => ['bn' => 'পরীক্ষার রুটিন', 'en' => 'Exam Routine'],
        'academic_calendar_lbl' => ['bn' => 'একাডেমিক ক্যালেন্ডার', 'en' => 'Academic Calendar'],
        'important_links_lbl'   => ['bn' => 'গুরুত্বপূর্ণ লিংক', 'en' => 'Important Links'],

        // ---------- STATS SECTION ----------
        'stats_heading'         => ['bn' => 'এক নজরে আমাদের অর্জন ও পরিসংখ্যান', 'en' => 'Our Achievements & Statistics at a Glance'],
        'stats_subheading'      => ['bn' => 'আমাদের শিক্ষা প্রতিষ্ঠানের সাফল্য এবং অগ্রযাত্রার কিছু গুরুত্বপূর্ণ তথ্য', 'en' => 'Some important facts about the success and progress of our institution'],
        'stat_total_students'   => ['bn' => 'মোট শিক্ষার্থী', 'en' => 'Total Students'],
        'stat_teachers_staff'   => ['bn' => 'শিক্ষক-শিক্ষিকা', 'en' => 'Teachers'],
        'stat_support_staff'    => ['bn' => 'সহকারী স্টাফ', 'en' => 'Support Staff'],
        'stat_pass_rate'        => ['bn' => 'পাসের হার', 'en' => 'Pass Rate'],

        // ---------- VIDEO SAMPLES ----------
        'class_play_kg'         => ['bn' => 'প্লে - কেজি (Play - KG)', 'en' => 'Play - KG'],
        'class_1_5'             => ['bn' => '১ম - ৫ম শ্রেণি', 'en' => 'Class 1 - 5'],
        'class_6_8'             => ['bn' => '৬ষ্ঠ - ৮TM শ্রেণি', 'en' => 'Class 6 - 8'],
        'class_9_10'            => ['bn' => '৯ম - ১০ম শ্রেণি', 'en' => 'Class 9 - 10'],
        'class_11_12'           => ['bn' => 'একাদশ - দ্বাদশ শ্রেণি', 'en' => 'Class 11 - 12'],
        'video1_title'          => ['bn' => 'মজার ছড়ায় বাংলা বর্ণমালা শিক্ষা', 'en' => 'Learning the Bangla Alphabet with Fun Rhymes'],
        'video1_teacher'        => ['bn' => 'প্রাথমিক শাখা টিচার মণ্ডলী', 'en' => 'Primary Section Teachers'],
        'video2_title'          => ['bn' => 'Basic English Grammar Rules', 'en' => 'Basic English Grammar Rules'],
        'video2_teacher'        => ['bn' => 'আলেয়া ইসলাম ম্যাম', 'en' => 'Alia Islam Mam'],
        'video3_title'          => ['bn' => 'সাধারণ বিজ্ঞান: মৌলিক পরীক্ষা-নিরীক্ষা', 'en' => 'General Science: Basic Experiments'],
        'video3_teacher'        => ['bn' => 'জামাল উদ্দিন স্যার', 'en' => 'Jamal Uddin Sir'],
        'video4_title'          => ['bn' => 'সাধারণ গণিত: বীজগণিতীয় রাশি ও সমাধান', 'en' => 'General Math: Algebraic Expressions & Solutions'],
        'video4_teacher'        => ['bn' => 'মোহাম্মদ মাহমুদ স্যার', 'en' => 'Mohammad Mahmud Sir'],
        'visit_youtube'         => ['bn' => '▶ আমাদের ইউটিউব চ্যানেল ভিজিট করুন ➔', 'en' => '▶ Visit our YouTube channel ➔'],

        // ---------- NEWS / CAREER / ALBUM ----------
        'recent_news'           => ['bn' => 'সাম্প্রতিক সংবাদ', 'en' => 'Recent News'],
        'career_notice'         => ['bn' => 'নিয়োগ বিজ্ঞপ্তি', 'en' => 'Career Notice'],
        'deadline_label'        => ['bn' => 'আবেদনের শেষ তারিখ:', 'en' => 'Application Deadline:'],
        'apply_now'             => ['bn' => 'আবেদন করুন', 'en' => 'Apply Now'],

        // ---------- FOOTER ----------
        'ministry_of_edu'       => ['bn' => 'শিক্ষা মন্ত্রণালয়', 'en' => 'Ministry of Education'],
        'dshe_link'             => ['bn' => 'মাধ্যমিক ও উচ্চশিক্ষা অধিদপ্তর', 'en' => 'Directorate of Secondary & Higher Education'],
        'nctb_link'             => ['bn' => 'এনসিটিবি (NCTB)', 'en' => 'NCTB'],
        'address_heading'       => ['bn' => 'ঠিকানা', 'en' => 'Address'],
        'helpline_colon'        => ['bn' => 'হেল্পলাইন:', 'en' => 'Helpline:'],
        'email_colon'           => ['bn' => 'ইমেইল:', 'en' => 'Email:'],
        'all_branch_address'    => ['bn' => 'সব শাখার ঠিকানা ও ম্যাপ ➔', 'en' => 'All Branch Addresses & Map ➔'],
        'default_address'       => ['bn' => 'আপনার ঠিকানা এখানে।', 'en' => 'Your address here.'],
        'social_media'          => ['bn' => 'সামাজিক যোগাযোগ', 'en' => 'Social Media'],
        'follow_our_page'       => ['bn' => 'আমাদের অফিসিয়াল পেজে যুক্ত থাকুন:', 'en' => 'Follow our official page:'],
        'our_location_map'      => ['bn' => 'আমাদের অবস্থান (Google Map)', 'en' => 'Our Location (Google Map)'],

        // ---------- NOTICES PAGE ----------
        'all_notices'           => ['bn' => 'সকল নোটিশ', 'en' => 'All Notices'],
        'search_notice_ph'      => ['bn' => 'নোটিশের শিরোনাম দিয়ে খুঁজুন...', 'en' => 'Search by notice title...'],
        'all_branch'            => ['bn' => 'সব Branch', 'en' => 'All Branches'],
        'search_btn'            => ['bn' => 'খুঁজুন', 'en' => 'Search'],
        'no_notice_found'       => ['bn' => 'কোনো নোটিশ পাওয়া যায়নি।', 'en' => 'No notices found.'],
        'has_attachment'        => ['bn' => 'সংযুক্তি আছে', 'en' => 'Has attachment'],
        'prev_page'             => ['bn' => '« আগে', 'en' => '« Previous'],
        'next_page'             => ['bn' => 'পরে »', 'en' => 'Next »'],

        // ---------- NOTICE VIEW PAGE ----------
        'notice_page_label'     => ['bn' => 'নোটিশ', 'en' => 'Notice'],
        'notice_not_found'      => ['bn' => 'নোটিশটি খুঁজে পাওয়া যায়নি অথবা এটি আর সক্রিয় নেই।', 'en' => 'Notice not found or is no longer active.'],
        'attached_file_default' => ['bn' => 'সংযুক্ত ফাইল', 'en' => 'Attached file'],
        'download_file'         => ['bn' => 'ডাউনলোড করুন', 'en' => 'Download'],
        'back_to_all_notices'   => ['bn' => 'সব নোটিশে ফিরে যান', 'en' => 'Back to All Notices'],

        // ---------- ADMISSION FORM PAGE ----------
        'admission_form_title'  => ['bn' => 'ভর্তির আবেদন ফর্ম', 'en' => 'Admission Application Form'],
        'af_eyebrow'            => ['bn' => 'একাডেমিক রেজিস্টার · সেশন এন্ট্রি', 'en' => 'Academic Register · Session Entry'],
        'af_hero_title'         => ['bn' => 'ছাত্র/ছাত্রী ভর্তি', 'en' => 'STUDENT ADMISSION'],
        'af_hero_sub'           => ['bn' => 'অনুগ্রহ করে নিচের সকল তথ্য সঠিকভাবে পূরণ করুন', 'en' => 'Please fill in all the information below correctly'],
        'af_sec_academic'       => ['bn' => 'একাডেমিক তথ্য', 'en' => 'Academic Information'],
        'af_sec_personal'       => ['bn' => 'ব্যক্তিগত তথ্য', 'en' => 'Personal Information'],
        'af_sec_prev_school'    => ['bn' => 'পূর্ববর্তী স্কুলের তথ্য', 'en' => 'Previous School Information'],
        'af_sec_address'        => ['bn' => 'বর্তমান ও স্থায়ী ঠিকানা', 'en' => 'Present & Permanent Address'],
        'af_sec_guardian'       => ['bn' => 'অভিভাবকের তথ্য', 'en' => 'Guardian Information'],
        'af_sec_payment'        => ['bn' => 'ভর্তি ফি ও পেমেন্ট', 'en' => 'Admission Fee & Payment'],
        'af_branch'             => ['bn' => 'শাখা', 'en' => 'Branch'],
        'af_select_branch'      => ['bn' => 'শাখা নির্বাচন করুন', 'en' => 'Select Branch'],
        'af_shift'              => ['bn' => 'শিফট', 'en' => 'Shift'],
        'af_select_shift'       => ['bn' => 'শিফট নির্বাচন করুন', 'en' => 'Select Shift'],
        'af_session'            => ['bn' => 'সেশন', 'en' => 'Session'],
        'af_select_session'     => ['bn' => 'সেশন নির্বাচন করুন', 'en' => 'Select Session'],
        'af_section'            => ['bn' => 'শাখা (সেকশন)', 'en' => 'Section'],
        'af_select_section'     => ['bn' => 'সেকশন নির্বাচন করুন', 'en' => 'Select Section'],
        'af_class'              => ['bn' => 'শ্রেণী', 'en' => 'Class'],
        'af_select_class'       => ['bn' => 'শ্রেণী নির্বাচন করুন', 'en' => 'Select Class'],
        'af_name_en'            => ['bn' => 'নাম (ইংরেজিতে)', 'en' => 'Name (in English)'],
        'af_gender'             => ['bn' => 'লিঙ্গ', 'en' => 'Gender'],
        'af_select_gender'      => ['bn' => 'লিঙ্গ নির্বাচন করুন', 'en' => 'Select Gender'],
        'af_male'               => ['bn' => 'পুরুষ', 'en' => 'Male'],
        'af_female'             => ['bn' => 'মহিলা', 'en' => 'Female'],
        'af_name_bn'            => ['bn' => 'নাম (বাংলায়)', 'en' => 'Name (in Bengali)'],
        'af_blood_group'        => ['bn' => 'রক্তের গ্রুপ', 'en' => 'Blood Group'],
        'af_select_blood_group' => ['bn' => 'রক্তের গ্রুপ নির্বাচন করুন', 'en' => 'Select Blood Group'],
        'af_unknown'            => ['bn' => 'অজানা', 'en' => 'Unknown'],
        'af_dob'                => ['bn' => 'জন্ম তারিখ', 'en' => 'Date of Birth'],
        'af_religion'           => ['bn' => 'ধর্ম', 'en' => 'Religion'],
        'af_select_religion'    => ['bn' => 'ধর্ম নির্বাচন করুন', 'en' => 'Select Religion'],
        'af_islam'              => ['bn' => 'ইসলাম', 'en' => 'Islam'],
        'af_hinduism'           => ['bn' => 'হিন্দু ধর্ম', 'en' => 'Hinduism'],
        'af_buddhism'           => ['bn' => 'বৌদ্ধ ধর্ম', 'en' => 'Buddhism'],
        'af_christianity'       => ['bn' => 'খ্রিস্টান ধর্ম', 'en' => 'Christianity'],
        'af_others'             => ['bn' => 'অন্যান্য', 'en' => 'Others'],
        'af_birth_reg_no'       => ['bn' => 'জন্ম নিবন্ধন নম্বর', 'en' => 'Birth Registration No'],
        'af_nationality'        => ['bn' => 'জাতীয়তা', 'en' => 'Nationality'],
        'af_email'              => ['bn' => 'ইমেইল', 'en' => 'Email'],
        'af_mobile'             => ['bn' => 'মোবাইল', 'en' => 'Mobile'],
        'af_orphan'             => ['bn' => 'এতিম কিনা', 'en' => 'Orphan'],
        'af_select_option'      => ['bn' => 'অপশন নির্বাচন করুন', 'en' => 'Select Option'],
        'af_yes'                => ['bn' => 'হ্যাঁ', 'en' => 'Yes'],
        'af_no'                 => ['bn' => 'না', 'en' => 'No'],
        'af_working_student'    => ['bn' => 'কর্মজীবী শিক্ষার্থী', 'en' => 'Working Student'],
        'af_disability_status'  => ['bn' => 'প্রতিবন্ধী অবস্থা', 'en' => 'Disability Status'],
        'af_none'               => ['bn' => 'নেই', 'en' => 'None'],
        'af_physical'           => ['bn' => 'শারীরিক', 'en' => 'Physical'],
        'af_visual'             => ['bn' => 'দৃষ্টি প্রতিবন্ধী', 'en' => 'Visual'],
        'af_hearing'            => ['bn' => 'শ্রবণ প্রতিবন্ধী', 'en' => 'Hearing'],
        'af_other'              => ['bn' => 'অন্যান্য', 'en' => 'Other'],
        'af_photo'              => ['bn' => 'ছবি', 'en' => 'Photo'],
        'af_photo_note'         => ['bn' => 'শুধু ছবি - পাসপোর্ট সাইজ - সর্বোচ্চ ৩০০ কেবি', 'en' => 'Only image - passport size - maximum 300KB'],
        'af_prev_school_name'   => ['bn' => 'পূর্ববর্তী স্কুলের নাম', 'en' => 'Previous School Name'],
        'af_prev_class'         => ['bn' => 'পূর্ববর্তী শ্রেণী', 'en' => 'Previous Class'],
        'af_prev_section'       => ['bn' => 'পূর্ববর্তী সেকশন', 'en' => 'Previous Section'],
        'af_prev_result'        => ['bn' => 'পূর্ববর্তী ফলাফল', 'en' => 'Previous Result'],
        'af_prev_roll'          => ['bn' => 'পূর্ববর্তী রোল', 'en' => 'Previous Roll'],
        'af_present_address'    => ['bn' => 'বর্তমান ঠিকানা', 'en' => 'Present Address'],
        'af_permanent_address'  => ['bn' => 'স্থায়ী ঠিকানা', 'en' => 'Permanent Address'],
        'af_same_as_present'    => ['bn' => 'বর্তমান ঠিকানার মতোই', 'en' => 'Same as Present Address'],
        'af_father_name'        => ['bn' => 'বাবার নাম', 'en' => "Father's Name"],
        'af_mother_name'        => ['bn' => 'মায়ের নাম', 'en' => "Mother's Name"],
        'af_father_nid'         => ['bn' => 'বাবার এনআইডি', 'en' => "Father's NID"],
        'af_mother_nid'         => ['bn' => 'মায়ের এনআইডি', 'en' => "Mother's NID"],
        'af_father_profession'  => ['bn' => 'বাবার পেশা', 'en' => "Father's Profession"],
        'af_mother_profession'  => ['bn' => 'মায়ের পেশা', 'en' => "Mother's Profession"],
        'af_father_phone'       => ['bn' => 'বাবার ফোন', 'en' => "Father's Phone"],
        'af_mother_photo'       => ['bn' => 'মায়ের ছবি', 'en' => "Mother's Photo"],
        'af_yearly_income'      => ['bn' => 'বাৎসরিক আয়', 'en' => 'Yearly Income'],
        'af_guardian_name'      => ['bn' => 'অভিভাবকের নাম', 'en' => "Guardian's Name"],
        'af_guardian_phone'     => ['bn' => 'অভিভাবকের ফোন', 'en' => "Guardian's Phone"],
        'af_landless'           => ['bn' => 'ভূমিহীন?', 'en' => 'Landless ?'],
        'af_father_photo'       => ['bn' => 'বাবার ছবি', 'en' => "Father's Photo"],
        'af_fee_notice'         => ['bn' => 'এই ক্লাসের জন্য ভর্তি ফি: <strong id="afFeeAmount">0</strong> টাকা — নিচে থেকে পেমেন্ট পদ্ধতি বেছে নিন।', 'en' => 'Admission fee for this class: <strong id="afFeeAmount">0</strong> Taka — choose a payment method below.'],
        'af_pay_manual'         => ['bn' => 'ম্যানুয়াল (বিকাশ/নগদ)', 'en' => 'Manual (bKash/Nagad)'],
        'af_pay_online_via'     => ['bn' => 'অনলাইনে পে করুন (', 'en' => 'Pay Online (via '],
        'af_send_money_to'      => ['bn' => 'নিচের নাম্বারে Send Money করুন:', 'en' => 'Send Money to the number below:'],
        'af_sent_from_method'   => ['bn' => 'পাঠানোর মাধ্যম', 'en' => 'Sent From (Method)'],
        'af_sender_number'      => ['bn' => 'প্রেরকের নম্বর', 'en' => 'Sender Number'],
        'af_trx_id'             => ['bn' => 'ট্রানজেকশন আইডি', 'en' => 'Transaction ID'],
        'af_trx_id_ph'          => ['bn' => 'যেমন: 8N7A6XXXXX', 'en' => 'e.g. 8N7A6XXXXX'],
        'af_auto_pay_note'      => ['bn' => '"Register" বাটনে ক্লিক করলে আপনাকে নিরাপদ Payment Gateway পেজে নিয়ে যাওয়া হবে — সেখানে Card/bKash/Nagad/Rocket দিয়ে সরাসরি পে করতে পারবেন। পেমেন্ট সফল হলে সাথে সাথেই ভর্তি নিশ্চিত হয়ে যাবে।', 'en' => 'Clicking "Register" will take you to a secure payment gateway page — you can pay directly with Card/bKash/Nagad/Rocket there. Your admission will be confirmed as soon as the payment succeeds.'],
        'af_register_btn'       => ['bn' => 'নিবন্ধন করুন', 'en' => 'Register'],
        'af_mobile_11_title'    => ['bn' => '১১ ডিজিটের মোবাইল নম্বর দিন', 'en' => 'Enter an 11-digit mobile number'],
        'af_mobile_11_err'      => ['bn' => 'মোবাইল নম্বর অবশ্যই ১১ ডিজিটের হতে হবে।', 'en' => 'Mobile number must be exactly 11 digits.'],

        // ---------- ACADEMIC RESULT / RESULT STATISTICS PAGES ----------
        'academic_result_title' => ['bn' => 'একাডেমিক রেজাল্ট', 'en' => 'Academic Result'],
        'result_statistics_title' => ['bn' => 'রেজাল্ট পরিসংখ্যান', 'en' => 'Result Statistics'],
        'ar_term_not_published' => ['bn' => 'এই টার্মের ফলাফল এখনও প্রকাশ করা হয়নি।', 'en' => 'Results for this term have not been published yet.'],
        'ar_student_not_found'  => ['bn' => 'স্টুডেন্ট পাওয়া যায়নি।', 'en' => 'Student not found.'],
        'ar_no_student_found'   => ['bn' => 'কোনো স্টুডেন্ট পাওয়া যায়নি।', 'en' => 'No student found.'],
        'ar_load_error'         => ['bn' => 'দুঃখিত, ফলাফল লোড করার সময় একটি সমস্যা হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।', 'en' => 'Sorry, something went wrong while loading the result. Please try again shortly.'],
        'ar_setup_incomplete'   => ['bn' => 'রেজাল্ট মডিউল এখনও সেটআপ করা হয়নি। অনুগ্রহ করে অ্যাডমিন প্যানেলে যোগাযোগ করুন।', 'en' => 'The Result Module has not been set up yet. Please contact the admin panel.'],
        'ar_field_required'    => ['bn' => 'খালি রাখা যাবে না', 'en' => 'This field can not be blank'],
        'ar_print_btn'          => ['bn' => 'প্রিন্ট', 'en' => 'Print'],
        'ar_result_badge'       => ['bn' => 'একাডেমিক ফলাফল', 'en' => 'Academic Result'],
        'ar_grade_th'           => ['bn' => 'গ্রেড', 'en' => 'Grade'],
        'ar_name_lbl'           => ['bn' => 'নাম', 'en' => 'Name'],
        'ar_father_name_lbl'    => ['bn' => "বাবার নাম", 'en' => "Father's Name"],
        'ar_class_lbl'          => ['bn' => 'শ্রেণী', 'en' => 'Class'],
        'ar_shift_word'         => ['bn' => 'শিফট', 'en' => 'Shift'],
        'ar_roll_id_lbl'        => ['bn' => 'রোল নং / স্টুডেন্ট আইডি', 'en' => 'Roll No / Student ID'],
        'ar_sl_th'              => ['bn' => 'ক্র.', 'en' => 'Sl.'],
        'ar_subject_th'         => ['bn' => 'বিষয়', 'en' => 'Subject'],
        'ar_gp_th'              => ['bn' => 'জিপি', 'en' => 'GP'],
        'ar_result_lbl'         => ['bn' => 'ফলাফল', 'en' => 'Result'],
        'ar_pass'               => ['bn' => 'পাস', 'en' => 'Pass'],
        'ar_fail'               => ['bn' => 'ফেল', 'en' => 'Fail'],
        'ar_no_subjects'        => ['bn' => 'এই ক্লাস/টার্মের জন্য এখনও কোনো সাবজেক্ট বরাদ্দ করা হয়নি।', 'en' => 'No subjects have been assigned for this class/term yet.'],
        'ar_search_again'       => ['bn' => 'আবার সার্চ করুন', 'en' => 'Search again'],
        'ar_setup_msg'          => ['bn' => 'রেজাল্ট মডিউল এখনও সেটআপ করা হয়নি। অ্যাডমিন প্যানেলে <b>Result Module &gt; Exam Settings</b> থেকে Exam Term/Part সেট করার পর এই পেজ কাজ করবে।', 'en' => 'The Result Module has not been set up yet. Once Exam Term/Part is configured from <b>Result Module &gt; Exam Settings</b> in the admin panel, this page will work.'],
        'ar_back_home'          => ['bn' => 'হোম পেজে ফিরে যান', 'en' => 'Back to Home Page'],
        'ar_search_for_result'  => ['bn' => 'ফলাফল খুঁজুন', 'en' => 'Search for Result'],
        'ar_select_exam_term'   => ['bn' => 'পরীক্ষার টার্ম নির্বাচন করুন', 'en' => 'Select Exam Term'],
        'ar_select_exam_part'   => ['bn' => 'পরীক্ষার পার্ট নির্বাচন করুন', 'en' => 'Select Exam Part'],
        'ar_term_lbl'           => ['bn' => 'টার্ম', 'en' => 'Term'],
        'ar_part_lbl'           => ['bn' => 'পার্ট', 'en' => 'Part'],
        'ar_roll_id_hint'       => ['bn' => '(না দিলে ক্লাসের সব ফলাফলের লিস্ট দেখাবে)', 'en' => '(leave blank to see the full result list for the class)'],
        'ar_roll_id_ph'         => ['bn' => 'যেমনঃ 12 বা STU-2026-001', 'en' => 'e.g. 12 or STU-2026-001'],
        'ar_search_btn'         => ['bn' => 'খুঁজুন', 'en' => 'Search'],
        'ar_result_list_title'  => ['bn' => 'ফলাফলের তালিকা', 'en' => 'Result List'],
        'ar_students_word'      => ['bn' => 'জন শিক্ষার্থী', 'en' => 'Students'],
        'ar_roll_no_th'         => ['bn' => 'রোল নং', 'en' => 'Roll No'],
        'ar_action_th'          => ['bn' => 'অ্যাকশন', 'en' => 'Action'],
        'ar_view_result_btn'    => ['bn' => 'রেজাল্ট দেখুন', 'en' => 'View Result'],

        // ---------- RESULT STATISTICS PAGE ----------
        'rs_select_session_err' => ['bn' => 'Session নির্বাচন করুন।', 'en' => 'Please select a Session.'],
        'rs_select_term_err'    => ['bn' => 'Term নির্বাচন করুন।', 'en' => 'Please select a Term.'],
        'rs_filter_title'       => ['bn' => 'ফিল্টার', 'en' => 'Filter'],
        'rs_class_hint'         => ['bn' => '(না দিলে সব ক্লাস)', 'en' => '(leave blank for all classes)'],
        'rs_all_classes'        => ['bn' => 'সব ক্লাস', 'en' => 'All Classes'],
        'rs_show_stats_btn'     => ['bn' => 'পরিসংখ্যান দেখুন', 'en' => 'Show Statistics'],
        'rs_appeared'           => ['bn' => 'অংশগ্রহণকারী', 'en' => 'Appeared'],
        'rs_passed'             => ['bn' => 'পাস', 'en' => 'Passed'],
        'rs_failed'             => ['bn' => 'ফেল', 'en' => 'Failed'],
        'rs_pass_rate'          => ['bn' => 'পাস রেট', 'en' => 'Pass Rate'],
        'rs_grade_distribution' => ['bn' => 'গ্রেড অনুযায়ী বিতরণ (জিপিএ)', 'en' => 'Grade-wise Distribution (GPA)'],
        'rs_no_result_found'    => ['bn' => 'এই ফিল্টারে কোনো স্টুডেন্টের ফলাফল পাওয়া যায়নি (সাবজেক্ট বরাদ্দ করা নেই অথবা কোনো স্টুডেন্ট নেই)।', 'en' => 'No student results found for this filter (either no subjects are assigned or there are no students).'],
    ];
}

/**
 * $key এর অনুবাদ ফেরত দেয়, বর্তমান ভাষা অনুযায়ী। কী পাওয়া না গেলে কী-টাই ফেরত দেয়
 * (যাতে পেজ কখনো ফাঁকা/ভাঙা না দেখায়, শুধু চোখে পড়ার মতো একটা মিসিং-কী দেখাবে)।
 */
function t(string $key): string
{
    static $dict = null;
    if ($dict === null) { $dict = site_i18n_dictionary(); }
    $lang = current_lang();
    if (isset($dict[$key][$lang])) {
        return $dict[$key][$lang];
    }
    return $dict[$key]['en'] ?? ($dict[$key]['bn'] ?? $key);
}
