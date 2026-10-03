-- =============================================================
-- School ERP — Database Schema
-- =============================================================
-- এই ফাইলটা cPanel phpMyAdmin-এ ইমপোর্ট করুন (your_db_name DB-তে)।
-- নতুন সেটআপে পুরো ফাইলটা রান করুন। আগে থেকে admissions টেবিল থাকলে,
-- নিচের ALTER TABLE অংশটুকু আলাদাভাবে রান করুন (নতুন কলামগুলোর জন্য)।
-- =============================================================

CREATE TABLE IF NOT EXISTS admin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ডিফল্ট অ্যাডমিন ইউজার তৈরি (username: admin — পাসওয়ার্ড বিক্রেতা আলাদাভাবে দেবেন)
-- ⚠️ লগইন করার পরপরই Change Password থেকে পাসওয়ার্ড বদলে ফেলুন
INSERT INTO admin (username, password)
SELECT 'admin', '$2y$10$Mow2b5SF/eMTtfdgnoflqOfsvv/MTxwhMU2xC6VteLfc9gza71jhy'
WHERE NOT EXISTS (SELECT 1 FROM admin WHERE username = 'admin');
-- ↑ bcrypt হ্যাশ। প্রথম লগইনের পরই পাসওয়ার্ড বদলে ফেলুন।

CREATE TABLE IF NOT EXISTS admissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_name_bn VARCHAR(150) NOT NULL,
    student_name_en VARCHAR(150) NOT NULL,
    apply_class VARCHAR(100) NOT NULL,
    apply_group VARCHAR(150) NOT NULL,
    dob DATE NOT NULL,
    religion VARCHAR(30) NOT NULL,
    blood_group VARCHAR(5) NULL,
    gender VARCHAR(10) NOT NULL,
    father_name VARCHAR(150) NOT NULL,
    mother_name VARCHAR(150) NOT NULL,
    guardian_phone VARCHAR(15) NOT NULL,
    guardian_email VARCHAR(150) NULL,
    address TEXT NOT NULL,
    photo_path VARCHAR(255) NULL,
    marksheet_path VARCHAR(255) NULL,
    status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================================
-- আগে থেকেই admissions টেবিল থাকলে (পুরনো ভার্সনে), এই ALTER গুলো
-- আলাদা করে রান করুন — নতুন কলামগুলো যোগ করবে (থাকলে এরর দেখাবে,
-- সেক্ষেত্রে সেই লাইনটা স্কিপ করে বাকিগুলো চালান):
-- ==========================================================
-- ALTER TABLE admissions ADD COLUMN dob DATE NOT NULL AFTER apply_group;
-- ALTER TABLE admissions ADD COLUMN religion VARCHAR(30) NOT NULL AFTER dob;
-- ALTER TABLE admissions ADD COLUMN blood_group VARCHAR(5) NULL AFTER religion;
-- ALTER TABLE admissions ADD COLUMN gender VARCHAR(10) NOT NULL AFTER blood_group;
-- ALTER TABLE admissions ADD COLUMN father_name VARCHAR(150) NOT NULL AFTER gender;
-- ALTER TABLE admissions ADD COLUMN mother_name VARCHAR(150) NOT NULL AFTER father_name;
-- ALTER TABLE admissions ADD COLUMN guardian_email VARCHAR(150) NULL AFTER guardian_phone;
-- ALTER TABLE admissions ADD COLUMN address TEXT NOT NULL AFTER guardian_email;
-- ALTER TABLE admissions ADD COLUMN photo_path VARCHAR(255) NULL AFTER address;
-- ALTER TABLE admissions ADD COLUMN marksheet_path VARCHAR(255) NULL AFTER photo_path;

CREATE TABLE IF NOT EXISTS academic_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_name VARCHAR(50) NOT NULL,
    short_code VARCHAR(20) NULL,
    is_fiscal_year TINYINT(1) NOT NULL DEFAULT 0,
    start_month VARCHAR(20) NULL,
    end_month VARCHAR(20) NULL,
    is_current_session TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS academic_mediums (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medium_name VARCHAR(100) NOT NULL,
    medium_code VARCHAR(20) NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS academic_shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medium_id INT NULL,
    shift_name VARCHAR(100) NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    green_limit INT NOT NULL DEFAULT 0,
    orange_limit INT NOT NULL DEFAULT 0,
    red_limit INT NOT NULL DEFAULT 0,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS academic_classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medium_id INT NULL,
    class_name VARCHAR(100) NOT NULL,
    rank INT NOT NULL DEFAULT 0,
    class_code VARCHAR(5) NULL,
    has_group TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS academic_sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NULL,
    shift_id INT NULL,
    section_name VARCHAR(100) NOT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS academic_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NULL,
    group_name VARCHAR(100) NOT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS academic_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NULL,
    subject_name VARCHAR(150) NOT NULL,
    combined_with_id INT NULL,
    subject_code VARCHAR(20) NULL,
    full_marks INT NOT NULL DEFAULT 100,
    subject_type ENUM('Compulsory','Optional','Continuous Assessment') NOT NULL DEFAULT 'Compulsory',
    group_id INT NULL,
    optional_count INT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventory_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    stock_qty INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(150) NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    qty INT NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    due_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(150) NOT NULL,
    invoice_no VARCHAR(50) NOT NULL UNIQUE,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    due_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================================
-- Institute Settings — সিঙ্গেল-রো টেবিল (সবসময় id=1)
-- ==========================================================
CREATE TABLE IF NOT EXISTS institute_settings (
    id INT PRIMARY KEY DEFAULT 1,
    facebook_page VARCHAR(255) NULL,
    youtube_video VARCHAR(255) NULL,
    admin_theme VARCHAR(30) NOT NULL DEFAULT 'Deep Green',
    website_theme VARCHAR(30) NOT NULL DEFAULT 'E-Learning',
    eiin_no VARCHAR(30) NULL,
    institute_name VARCHAR(200) NOT NULL DEFAULT '',
    phone_number VARCHAR(30) NULL,
    slogan VARCHAR(255) NULL,
    email VARCHAR(150) NULL,
    website VARCHAR(150) NULL,
    favicon_path VARCHAR(255) NULL,
    banner_path VARCHAR(255) NULL,
    logo_path VARCHAR(255) NULL,
    short_description TEXT NULL,
    why_choose_institute TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ডিফল্ট একটা রো তৈরি (জেনেরিক ডেমো ভ্যালু দিয়ে — নতুন প্রতিটা School নিজের
-- Institute Settings পেজ থেকে এটা বদলে নেবে, তাই এখানে কোনো নির্দিষ্ট client এর
-- আসল তথ্য না রেখে জেনেরিক ব্র্যান্ড ডিফল্ট রাখা হলো)
INSERT INTO institute_settings
    (id, admin_theme, website_theme, institute_name, email, slogan, short_description)
SELECT 1,
    'Deep Green', 'E-Learning',
    'Your School Name',
    '',
    'Excellence in Education',
    'এই স্কুলের সংক্ষিপ্ত পরিচিতি এখানে বসবে — Institute Settings পেজ থেকে বদলে নিন।'
WHERE NOT EXISTS (SELECT 1 FROM institute_settings WHERE id = 1);

-- ==========================================================
-- Website Module — Notice / Slider / Gallery (মূল ওয়েবসাইটকে
-- ডাইনামিক করার জন্য)
-- ==========================================================
CREATE TABLE IF NOT EXISTS notices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    notice_date DATE NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sliders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    subtitle VARCHAR(500) NULL,
    image_path VARCHAR(255) NOT NULL,
    button_text VARCHAR(100) NULL,
    button_link VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gallery_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caption VARCHAR(255) NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ডেমো ডাটা (আগে যা হার্ডকোড ছিল index.php তে, সেগুলোই এখন DB তে) —
-- না থাকলে টেবিল খালি থাকবে, index.php নিজে থেকে খালি অবস্থাও হ্যান্ডল করে
INSERT INTO notices (title, notice_date)
SELECT * FROM (SELECT 'অর্ধ-বার্ষিক পরীক্ষার ফলাফল প্রকাশ সংক্রান্ত তথ্য।', '2026-08-15') AS tmp
WHERE NOT EXISTS (SELECT 1 FROM notices);

INSERT INTO notices (title, notice_date)
SELECT * FROM (SELECT 'একাদশ শ্রেণিতে ভর্তি আবেদন ২০২৬-২০২৭।', '2026-08-10') AS tmp
WHERE (SELECT COUNT(*) FROM notices) = 1;

INSERT INTO notices (title, notice_date)
SELECT * FROM (SELECT 'অভিভাবক সমাবেশ ও বিশেষ ক্লাস রুটিন।', '2026-08-05') AS tmp
WHERE (SELECT COUNT(*) FROM notices) = 2;

INSERT INTO sliders (title, subtitle, image_path, button_text, button_link, sort_order)
SELECT * FROM (SELECT 'মানসম্মত শিক্ষাই আমাদের অঙ্গীকার', 'একটি সুশৃঙ্খল ও আধুনিক শিক্ষা পরিবেশের মাধ্যমে আগামী প্রজন্মের সুনাগরিক গড়ে তোলাই আমাদের মূল লক্ষ্য।', 'https://images.unsplash.com/photo-1562774053-701939374585?q=80&w=1400', 'বিস্তারিত জানুন', '#about', 1) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM sliders);

INSERT INTO sliders (title, subtitle, image_path, button_text, button_link, sort_order)
SELECT * FROM (SELECT 'আধুনিক ও ডিজিটাল ক্লাসরুম', 'স্মার্ট প্রজেক্টর ও মাল্টিমিডিয়া প্রযুক্তির মাধ্যমে সহজ ও আকর্ষণীয় পাঠদান ব্যবস্থা।', 'https://images.unsplash.com/photo-1509062522246-3755977927d7?q=80&w=1400', 'সুবিধাসমূহ দেখুন', '#academic', 2) AS tmp
WHERE (SELECT COUNT(*) FROM sliders) = 1;

INSERT INTO sliders (title, subtitle, image_path, button_text, button_link, sort_order)
SELECT * FROM (SELECT 'একাদশ শ্রেণিতে ভর্তি চলছে!', '২০২৬-২০২৭ শিক্ষাবর্ষে মানসম্মত শিক্ষার নতুন পথচলায় অংশ নিতে আজই আবেদন করুন।', 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?q=80&w=1400', 'ভর্তি ফরম পূরণ করুন', 'admission.php', 3) AS tmp
WHERE (SELECT COUNT(*) FROM sliders) = 2;

INSERT INTO gallery_items (caption, image_path, sort_order)
SELECT * FROM (SELECT 'স্কুল ক্যাম্পাস', 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?q=80&w=600', 1) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM gallery_items);

INSERT INTO gallery_items (caption, image_path, sort_order)
SELECT * FROM (SELECT 'ডিজিটাল ক্লাসরুম', 'https://images.unsplash.com/photo-1509062522246-3755977927d7?q=80&w=600', 2) AS tmp
WHERE (SELECT COUNT(*) FROM gallery_items) = 1;

INSERT INTO gallery_items (caption, image_path, sort_order)
SELECT * FROM (SELECT 'বিজ্ঞানাগার', 'https://images.unsplash.com/photo-1532094349884-543bc11b234d?q=80&w=600', 3) AS tmp
WHERE (SELECT COUNT(*) FROM gallery_items) = 2;

INSERT INTO gallery_items (caption, image_path, sort_order)
SELECT * FROM (SELECT 'বার্ষিক ক্রীড়া প্রতিযোগিতা', 'https://images.unsplash.com/photo-1577896851231-70ef18881754?q=80&w=600', 4) AS tmp
WHERE (SELECT COUNT(*) FROM gallery_items) = 3;

-- ==========================================================
-- Branch Settings (Global Settings > Branch)
-- ==========================================================
CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    short_name VARCHAR(30) NOT NULL,
    branch_name VARCHAR(200) NOT NULL,
    address TEXT NOT NULL,
    city VARCHAR(100) NOT NULL,
    zip_code VARCHAR(20) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    fax VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    signature_path VARCHAR(255) NULL,
    weekly_holidays VARCHAR(100) NOT NULL DEFAULT 'Friday',
    map_iframe TEXT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- জেনেরিক ডিফল্ট branch — নতুন School নিজের Branch Settings পেজ থেকে এটা বদলে নেবে
INSERT INTO branches (short_name, branch_name, address, city, zip_code, phone, weekly_holidays, status)
SELECT * FROM (
    SELECT
        'MAIN' AS c_short_name,
        'Your School Name' AS c_branch_name,
        'ঠিকানা এখানে বসান' AS c_address,
        'Dhaka' AS c_city,
        '' AS c_zip_code,
        '' AS c_phone,
        'Friday' AS c_weekly_holidays,
        'Active' AS c_status
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM branches);

-- ==========================================================
-- SMS Settings (Global Settings > SMS Settings) — সিঙ্গেল-রো (id=1)
-- ==========================================================
CREATE TABLE IF NOT EXISTS sms_settings (
    id INT PRIMARY KEY DEFAULT 1,
    -- General Settings (On/Off)
    show_teachers_home TINYINT(1) NOT NULL DEFAULT 1,
    show_section_filter TINYINT(1) NOT NULL DEFAULT 0,
    show_teachers_mobile TINYINT(1) NOT NULL DEFAULT 1,
    show_teachers_address TINYINT(1) NOT NULL DEFAULT 1,
    show_teachers_email TINYINT(1) NOT NULL DEFAULT 1,
    show_count_home TINYINT(1) NOT NULL DEFAULT 1,
    show_notices_home TINYINT(1) NOT NULL DEFAULT 1,
    show_events_home TINYINT(1) NOT NULL DEFAULT 1,
    -- SMS Settings (On/Off)
    sms_admission TINYINT(1) NOT NULL DEFAULT 1,
    sms_congratulation TINYINT(1) NOT NULL DEFAULT 0,
    sms_punch_in TINYINT(1) NOT NULL DEFAULT 0,
    sms_punch_out TINYINT(1) NOT NULL DEFAULT 0,
    sms_add_employee TINYINT(1) NOT NULL DEFAULT 0,
    sms_absent TINYINT(1) NOT NULL DEFAULT 0,
    sms_due TINYINT(1) NOT NULL DEFAULT 1,
    sms_fee_collection TINYINT(1) NOT NULL DEFAULT 1,
    -- SMS Content Settings
    content_admission TEXT NULL,
    content_congratulation TEXT NULL,
    content_teacher_add TEXT NULL,
    content_punch_in TEXT NULL,
    content_punch_out TEXT NULL,
    content_absent TEXT NULL,
    content_due TEXT NULL,
    content_fee_collection TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sms_settings (id, content_admission, content_congratulation, content_teacher_add, content_punch_in, content_punch_out, content_absent, content_due, content_fee_collection)
SELECT 1,
    'Assalamualaikum, your child is admitted now to {SCHOOL_NAME} in class {CLASS_NAME}. Your child roll : {ROLL_NO}, ID : {STUDENT_ID}. You can sign in to our parents portal (www.yourschool.com) by using Username : {USERNAME} & Password : {PASSWORD}',
    'Congratulation, your child {FIRST_NAME} {LAST_NAME} is admitted in {SCHOOL_NAME}',
    'Dear Teacher, your profile is created to {SCHOOL_NAME}. Your ID : {EMPLOYEE_ID}. You can sign in to our teachers portal (yourschool.com) by using Username : {USERNAME} & Password : {PASSWORD}',
    'Dear guardian, {FIRST_NAME} {LAST_NAME} is attend on {SCHOOL_NAME} today.',
    'Dear guardian, {FIRST_NAME} {LAST_NAME} is left {SCHOOL_NAME} today.',
    'Dear guardian, {FIRST_NAME} {LAST_NAME} is absent on {SCHOOL_NAME} today.',
    'Dear guardian, Total Tk. {AMOUNT} is due on {SCHOOL_NAME} for {NAME}.',
    'Dear Parent, Tk {AMOUNT} collected for {FIRST_NAME} {LAST_NAME} ({FEES_TYPE}). Receipt: {RECEIPT_LINK}'
WHERE NOT EXISTS (SELECT 1 FROM sms_settings WHERE id = 1);

-- ==========================================================
-- Students (Student Module > Student Admission) — সম্পূর্ণ ভর্তি ফর্ম
-- ==========================================================
-- ==========================================================
-- HR মডিউল — Designations / Employees (হোমপেজে Teachers/Staffs
-- দেখানোর জন্য দরকার, তাই নতুন tenant তৈরির সময়েই বানিয়ে রাখা হচ্ছে —
-- আগে শুধু admin > HR পেজ প্রথমবার ভিজিট করলে lazy তৈরি হতো, যার কারণে
-- নতুন school-এর পাবলিক হোমপেজ ভিজিট করলে টেবিল না থাকায় error হতো)
-- ==========================================================
CREATE TABLE IF NOT EXISTS message_corner (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    designation VARCHAR(150) NOT NULL,
    role_key VARCHAR(30) NOT NULL DEFAULT 'other',
    photo_path VARCHAR(255) NULL,
    message TEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_role_settings (
    role_key VARCHAR(30) NOT NULL PRIMARY KEY,
    is_shown TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    label_bn VARCHAR(100) NULL,
    is_custom TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS designations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('Teachers','Staffs','Board Of Directors','Academic Committee') NOT NULL DEFAULT 'Teachers',
    title VARCHAR(150) NOT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('Teachers','Staffs','Board Of Directors','Academic Committee') NOT NULL DEFAULT 'Teachers',
    employee_type_id INT NULL,
    designation_id INT NULL,
    shift_id INT NULL,
    grade_id INT NULL,
    `rank` INT NULL,
    section_level VARCHAR(10) NULL,
    employee_id VARCHAR(50) NOT NULL UNIQUE,
    device_serial VARCHAR(100) NULL,
    tracking_id VARCHAR(100) NULL,
    rfid_card_no VARCHAR(100) NULL,
    joining_date DATE NULL,
    bank_account_no VARCHAR(100) NULL,
    previous_institute VARCHAR(255) NULL,
    previous_designation VARCHAR(255) NULL,
    name VARCHAR(150) NOT NULL,
    mobile VARCHAR(20) NOT NULL,
    email VARCHAR(150) NULL,
    dob DATE NULL,
    national_id VARCHAR(50) NULL,
    photo_path VARCHAR(255) NULL,
    fathers_name VARCHAR(150) NULL,
    mothers_name VARCHAR(150) NULL,
    marital_status VARCHAR(20) NULL,
    religion VARCHAR(50) NULL,
    blood_group VARCHAR(5) NULL,
    gender VARCHAR(10) NULL,
    facebook_link VARCHAR(255) NULL,
    major_subject VARCHAR(150) NULL,
    extra_qualification TEXT NULL,
    present_address TEXT NULL,
    permanent_address TEXT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_educations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    institute VARCHAR(255) NULL,
    degree VARCHAR(150) NULL,
    country VARCHAR(100) NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Academic Information
    session_id INT NULL,
    shift_id INT NULL,
    class_id INT NULL,
    section_id INT NULL,
    device_serial VARCHAR(50) NULL,
    student_id VARCHAR(50) NOT NULL,
    tracking_id VARCHAR(50) NULL,
    rfid_card_no VARCHAR(50) NULL,
    roll_no VARCHAR(20) NOT NULL,
    attendance_sms TINYINT(1) NOT NULL DEFAULT 1,

    -- Personal Information
    name_en VARCHAR(150) NOT NULL,
    name_bn VARCHAR(150) NULL,
    dob DATE NULL,
    birth_reg_no VARCHAR(50) NULL,
    gender VARCHAR(10) NOT NULL,
    orphan VARCHAR(5) NULL,
    disability_status VARCHAR(30) NULL,
    blood_group VARCHAR(5) NOT NULL,
    religion VARCHAR(30) NOT NULL,
    nationality VARCHAR(50) NOT NULL DEFAULT 'Bangladeshi',
    working_student VARCHAR(5) NULL,
    photo_path VARCHAR(255) NULL,

    -- Contact Information
    mobile VARCHAR(20) NULL,
    email VARCHAR(150) NULL,
    present_address TEXT NOT NULL,
    permanent_address TEXT NOT NULL,

    -- Previous School Information
    prev_school_name VARCHAR(200) NULL,
    prev_class_id INT NULL,
    prev_section VARCHAR(50) NULL,
    prev_roll VARCHAR(20) NULL,
    prev_result VARCHAR(50) NULL,

    -- Parents Information
    father_name VARCHAR(150) NOT NULL,
    father_phone VARCHAR(20) NULL,
    father_nid VARCHAR(30) NULL,
    father_profession VARCHAR(100) NULL,
    father_designation VARCHAR(100) NULL,
    father_office_address TEXT NULL,
    father_office_phone VARCHAR(20) NULL,
    father_photo_path VARCHAR(255) NULL,

    mother_name VARCHAR(150) NOT NULL,
    mother_phone VARCHAR(20) NULL,
    mother_nid VARCHAR(30) NULL,
    mother_profession VARCHAR(100) NULL,
    mother_designation VARCHAR(100) NULL,
    mother_office_address TEXT NULL,
    mother_office_phone VARCHAR(20) NULL,
    mother_photo_path VARCHAR(255) NULL,

    yearly_income VARCHAR(50) NULL,
    landless VARCHAR(5) NULL,

    -- Guardian / Receiver Information
    guardian_type VARCHAR(20) NOT NULL DEFAULT 'Father',
    guardian_name VARCHAR(150) NOT NULL,
    guardian_relation VARCHAR(100) NULL,
    guardian_phone VARCHAR(20) NOT NULL,
    guardian_profession VARCHAR(100) NULL,
    guardian_designation VARCHAR(100) NULL,
    guardian_office_contact VARCHAR(20) NULL,
    guardian_office_address TEXT NULL,

    group_id INT NULL,
    admission_id INT NULL,

    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    comments TEXT NULL,

    -- is_current/promoted_from_id: Student Promotion-এ (admin/student_promote.php,
    -- admin/student_migration.php) একই student_id নিয়ে প্রতি Academic Year-এ একটা করে নতুন
    -- রো তৈরি হয় (পুরনোটা is_current=0/Archive হয়ে যায়, promoted_from_id দিয়ে আগের রো-র সাথে
    -- লিংক থাকে) — তাই student_id ইচ্ছাকৃতভাবে UNIQUE না, শুধু একটা সাধারণ INDEX (লুকআপ
    -- পারফরম্যান্সের জন্য)।
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    promoted_from_id INT NULL DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_student_id (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================
-- Attendance Devices (ZKTeco/eSSL ইত্যাদি) + তাদের Punch Log
-- (Global Settings > Device Settings পেজের জন্য)
-- ==========================================================
CREATE TABLE IF NOT EXISTS attendance_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_name VARCHAR(150) NOT NULL,
    brand VARCHAR(50) NOT NULL,
    device_model VARCHAR(100) NULL,
    device_for ENUM('student','employee','both') NOT NULL DEFAULT 'both',
    branch_id INT NULL,
    connection_type ENUM('cloud','local') NOT NULL DEFAULT 'local',
    cloud_provider VARCHAR(100) NULL,
    api_base_url VARCHAR(255) NULL,
    api_key VARCHAR(255) NULL,
    api_secret VARCHAR(255) NULL,
    cloud_device_sn VARCHAR(100) NULL,
    ip_address VARCHAR(50) NULL,
    port INT NOT NULL DEFAULT 4370,
    device_serial VARCHAR(100) NULL,
    comm_key VARCHAR(50) NULL,
    protocol VARCHAR(30) NOT NULL DEFAULT 'zkteco_tcp',
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    notes TEXT NULL,
    live_status VARCHAR(20) NULL,
    last_seen_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_attendance_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NULL,
    person_type ENUM('student','employee') NOT NULL,
    person_uid VARCHAR(100) NOT NULL,
    punch_type ENUM('in','out') NOT NULL,
    punch_time DATETIME NOT NULL,
    raw_payload TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_person (person_type, person_uid, punch_time),
    INDEX idx_device (device_id, punch_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================
-- Canteen Module (Raw Materials, Recipe, Menu, POS, Due)
-- ==========================================================
CREATE TABLE IF NOT EXISTS canteen_settings (
    id INT PRIMARY KEY,
    allow_due_sale TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO canteen_settings (id, allow_due_sale) SELECT 1, 1 WHERE NOT EXISTS (SELECT 1 FROM canteen_settings WHERE id = 1);

CREATE TABLE IF NOT EXISTS canteen_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canteen_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canteen_raw_materials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    unit_id INT NULL,
    current_stock DECIMAL(10,3) NOT NULL DEFAULT 0,
    reorder_level DECIMAL(10,3) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canteen_suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    address VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canteen_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_no VARCHAR(40) NOT NULL,
    supplier_name VARCHAR(150) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    due_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canteen_purchase_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    raw_material_id INT NOT NULL,
    qty DECIMAL(10,3) NOT NULL,
    rate DECIMAL(10,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canteen_supplier_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canteen_menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NULL,
    name VARCHAR(150) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    photo_path VARCHAR(255) NULL,
    description VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canteen_recipe_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    menu_item_id INT NOT NULL,
    raw_material_id INT NOT NULL,
    qty_required DECIMAL(10,3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canteen_sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(40) NOT NULL,
    buyer_type VARCHAR(10) NOT NULL DEFAULT 'walkin',
    student_pk INT NULL,
    student_code VARCHAR(50) NULL,
    employee_pk INT NULL,
    employee_code VARCHAR(50) NULL,
    customer_name VARCHAR(150) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    due_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canteen_sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    qty INT NOT NULL,
    rate DECIMAL(10,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canteen_sale_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canteen_stock_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    raw_material_id INT NOT NULL,
    adj_type VARCHAR(20) NOT NULL,
    qty DECIMAL(10,3) NOT NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- HR: Teacher Class Teacher / Subject এসাইনমেন্ট ----------
CREATE TABLE IF NOT EXISTS teacher_subject_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_assign (employee_id, class_id, subject_id),
    KEY idx_employee (employee_id),
    KEY idx_class_subject (class_id, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    class_id INT NOT NULL,
    section_id INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_class_section (class_id, section_id),
    KEY idx_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Website Module: Facilities (আমাদের সুবিধাসমূহ) ----------
-- ডিফল্ট ৬টা সুবিধা প্রথমবার পেজ লোডে config/website_facilities.php অটো বসায়
CREATE TABLE IF NOT EXISTS website_facilities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    title_en VARCHAR(255) NULL,
    description TEXT NULL,
    description_en TEXT NULL,
    image_path VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
