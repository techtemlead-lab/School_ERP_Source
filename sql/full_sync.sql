-- =============================================================
-- School ERP — FULL SYNC SCRIPT
-- =============================================================
-- এই ফাইলটা phpMyAdmin-এর SQL ট্যাবে পুরোটা একসাথে পেস্ট করে Go চাপুন।
-- "Duplicate column" বা "Table already exists" এরর দেখালে সেই একটা লাইন
-- স্কিপ হয়ে বাকি স্ক্রিপ্ট চলতেই থাকবে — চিন্তার কিছু নাই, এটা normal।
-- এই স্ক্রিপ্ট বহুবার রান করলেও সমস্যা নাই (idempotent)।
-- =============================================================

-- ---------- Core tables (fresh ইনস্টলে তৈরি হবে, আগে থেকে থাকলে স্কিপ) ----------
CREATE TABLE IF NOT EXISTS admin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO admin (username, password)
SELECT 'admin', '$2y$10$Mow2b5SF/eMTtfdgnoflqOfsvv/MTxwhMU2xC6VteLfc9gza71jhy'
WHERE NOT EXISTS (SELECT 1 FROM admin WHERE username = 'admin');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- আগে থেকে admissions টেবিল অন্য/পুরনো স্ট্রাকচারে থাকলে এগুলো আলাদাভাবে চালান:
ALTER TABLE admissions ADD COLUMN dob DATE NOT NULL AFTER apply_group;
ALTER TABLE admissions ADD COLUMN religion VARCHAR(30) NOT NULL AFTER dob;
ALTER TABLE admissions ADD COLUMN blood_group VARCHAR(5) NULL AFTER religion;
ALTER TABLE admissions ADD COLUMN gender VARCHAR(10) NOT NULL AFTER blood_group;
ALTER TABLE admissions ADD COLUMN father_name VARCHAR(150) NOT NULL AFTER gender;
ALTER TABLE admissions ADD COLUMN mother_name VARCHAR(150) NOT NULL AFTER father_name;
ALTER TABLE admissions ADD COLUMN guardian_email VARCHAR(150) NULL AFTER guardian_phone;
ALTER TABLE admissions ADD COLUMN address TEXT NOT NULL AFTER guardian_email;
ALTER TABLE admissions ADD COLUMN photo_path VARCHAR(255) NULL AFTER address;
ALTER TABLE admissions ADD COLUMN marksheet_path VARCHAR(255) NULL AFTER photo_path;
ALTER TABLE admissions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------- Institute Settings ----------
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
    favicon_path VARCHAR(255) NULL,
    banner_path VARCHAR(255) NULL,
    logo_path VARCHAR(255) NULL,
    short_description TEXT NULL,
    why_choose_institute TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO institute_settings (id, facebook_page, youtube_video, admin_theme, website_theme, institute_name, email, slogan, short_description)
SELECT 1, '', '',
    'Deep Green', 'E-Learning', 'Your School Name', 'info@yourschool.com',
    'An enlightened generation, our promise',
    'আপনার প্রতিষ্ঠানের সংক্ষিপ্ত পরিচিতি এখানে লিখুন।'
WHERE NOT EXISTS (SELECT 1 FROM institute_settings WHERE id = 1);
ALTER TABLE institute_settings CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------- Website Module: Notice / Slider / Gallery ----------
CREATE TABLE IF NOT EXISTS notices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    notice_date DATE NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE notices ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE notices CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE sliders ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE sliders CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gallery_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caption VARCHAR(255) NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE gallery_items ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE gallery_items CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------- Academic: Session ----------
CREATE TABLE IF NOT EXISTS academic_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_name VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE academic_sessions ADD COLUMN short_code VARCHAR(20) NULL AFTER session_name;
ALTER TABLE academic_sessions ADD COLUMN is_fiscal_year TINYINT(1) NOT NULL DEFAULT 0 AFTER short_code;
ALTER TABLE academic_sessions ADD COLUMN start_month VARCHAR(20) NULL AFTER is_fiscal_year;
ALTER TABLE academic_sessions ADD COLUMN end_month VARCHAR(20) NULL AFTER start_month;
ALTER TABLE academic_sessions ADD COLUMN is_current_session TINYINT(1) NOT NULL DEFAULT 0 AFTER end_month;
ALTER TABLE academic_sessions ADD COLUMN status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active' AFTER is_current_session;
ALTER TABLE academic_sessions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------- Academic: Medium ----------
CREATE TABLE IF NOT EXISTS academic_mediums (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medium_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE academic_mediums ADD COLUMN medium_code VARCHAR(20) NULL AFTER medium_name;
ALTER TABLE academic_mediums ADD COLUMN status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active' AFTER medium_code;
ALTER TABLE academic_mediums CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------- Academic: Shift ----------
CREATE TABLE IF NOT EXISTS academic_shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shift_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE academic_shifts ADD COLUMN medium_id INT NULL AFTER id;
ALTER TABLE academic_shifts ADD COLUMN start_time TIME NULL AFTER shift_name;
ALTER TABLE academic_shifts ADD COLUMN end_time TIME NULL AFTER start_time;
ALTER TABLE academic_shifts ADD COLUMN green_limit INT NOT NULL DEFAULT 0 AFTER end_time;
ALTER TABLE academic_shifts ADD COLUMN orange_limit INT NOT NULL DEFAULT 0 AFTER green_limit;
ALTER TABLE academic_shifts ADD COLUMN red_limit INT NOT NULL DEFAULT 0 AFTER orange_limit;
ALTER TABLE academic_shifts ADD COLUMN status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active' AFTER red_limit;
ALTER TABLE academic_shifts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------- Academic: Class ----------
CREATE TABLE IF NOT EXISTS academic_classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE academic_classes ADD COLUMN medium_id INT NULL AFTER id;
ALTER TABLE academic_classes ADD COLUMN rank INT NOT NULL DEFAULT 0 AFTER class_name;
ALTER TABLE academic_classes ADD COLUMN class_code VARCHAR(5) NULL AFTER rank;
ALTER TABLE academic_classes ADD COLUMN has_group TINYINT(1) NOT NULL DEFAULT 0 AFTER class_code;
ALTER TABLE academic_classes ADD COLUMN status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active' AFTER has_group;
ALTER TABLE academic_classes CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------- Academic: Section ----------
CREATE TABLE IF NOT EXISTS academic_sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE academic_sections ADD COLUMN class_id INT NULL AFTER id;
ALTER TABLE academic_sections ADD COLUMN shift_id INT NULL AFTER class_id;
ALTER TABLE academic_sections ADD COLUMN status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active' AFTER section_name;
ALTER TABLE academic_sections CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------- Academic: Group ----------
CREATE TABLE IF NOT EXISTS academic_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE academic_groups ADD COLUMN class_id INT NULL AFTER id;
ALTER TABLE academic_groups ADD COLUMN status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active' AFTER group_name;
ALTER TABLE academic_groups CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------- Academic: Subject ----------
CREATE TABLE IF NOT EXISTS academic_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_name VARCHAR(150) NOT NULL,
    class_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE academic_subjects ADD COLUMN class_id INT NULL AFTER id;
ALTER TABLE academic_subjects ADD COLUMN combined_with_id INT NULL AFTER subject_name;
ALTER TABLE academic_subjects ADD COLUMN subject_code VARCHAR(20) NULL AFTER combined_with_id;
ALTER TABLE academic_subjects ADD COLUMN full_marks INT NOT NULL DEFAULT 100 AFTER subject_code;
ALTER TABLE academic_subjects ADD COLUMN subject_type ENUM('Compulsory','Optional','Continuous Assessment') NOT NULL DEFAULT 'Compulsory' AFTER full_marks;
ALTER TABLE academic_subjects ADD COLUMN group_id INT NULL AFTER subject_type;
ALTER TABLE academic_subjects ADD COLUMN optional_count INT NULL AFTER group_id;
ALTER TABLE academic_subjects ADD COLUMN status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active' AFTER optional_count;
ALTER TABLE academic_subjects CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------- Inventory / Sales (আগে থেকেই থাকার কথা) ----------
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

-- ---------- Branch Settings ----------
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

INSERT INTO branches (short_name, branch_name, address, city, zip_code, phone, weekly_holidays, status)
SELECT * FROM (SELECT 'MAIN', 'Main Branch', 'Your address here', 'City', '0000', '01700000000', 'Friday', 'Active') AS tmp
WHERE NOT EXISTS (SELECT 1 FROM branches);

-- ---------- SMS Settings ----------
CREATE TABLE IF NOT EXISTS sms_settings (
    id INT PRIMARY KEY DEFAULT 1,
    show_teachers_home TINYINT(1) NOT NULL DEFAULT 1,
    show_section_filter TINYINT(1) NOT NULL DEFAULT 0,
    show_teachers_mobile TINYINT(1) NOT NULL DEFAULT 1,
    show_teachers_address TINYINT(1) NOT NULL DEFAULT 1,
    show_teachers_email TINYINT(1) NOT NULL DEFAULT 1,
    show_count_home TINYINT(1) NOT NULL DEFAULT 1,
    sms_admission TINYINT(1) NOT NULL DEFAULT 1,
    sms_congratulation TINYINT(1) NOT NULL DEFAULT 0,
    sms_punch_in TINYINT(1) NOT NULL DEFAULT 0,
    sms_punch_out TINYINT(1) NOT NULL DEFAULT 0,
    sms_add_employee TINYINT(1) NOT NULL DEFAULT 0,
    sms_absent TINYINT(1) NOT NULL DEFAULT 0,
    sms_due TINYINT(1) NOT NULL DEFAULT 1,
    sms_fee_collection TINYINT(1) NOT NULL DEFAULT 1,
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
    'Dear Parent, TK {AMOUNT} for {FEES_TYPE} is collected for {FIRST_NAME} {LAST_NAME} from {SCHOOL_NAME}.'
WHERE NOT EXISTS (SELECT 1 FROM sms_settings WHERE id = 1);

-- ---------- Students ----------
CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NULL, shift_id INT NULL, class_id INT NULL, section_id INT NULL,
    device_serial VARCHAR(50) NULL, student_id VARCHAR(50) NOT NULL, tracking_id VARCHAR(50) NULL,
    rfid_card_no VARCHAR(50) NULL, roll_no VARCHAR(20) NOT NULL, attendance_sms TINYINT(1) NOT NULL DEFAULT 1,
    name_en VARCHAR(150) NOT NULL, name_bn VARCHAR(150) NULL, dob DATE NULL, birth_reg_no VARCHAR(50) NULL,
    gender VARCHAR(10) NOT NULL, orphan VARCHAR(5) NULL, disability_status VARCHAR(30) NULL,
    blood_group VARCHAR(5) NOT NULL, religion VARCHAR(30) NOT NULL, nationality VARCHAR(50) NOT NULL DEFAULT 'Bangladeshi',
    working_student VARCHAR(5) NULL, photo_path VARCHAR(255) NULL,
    mobile VARCHAR(20) NULL, email VARCHAR(150) NULL, present_address TEXT NOT NULL, permanent_address TEXT NOT NULL,
    prev_school_name VARCHAR(200) NULL, prev_class_id INT NULL, prev_section VARCHAR(50) NULL,
    prev_roll VARCHAR(20) NULL, prev_result VARCHAR(50) NULL,
    father_name VARCHAR(150) NOT NULL, father_phone VARCHAR(20) NULL, father_nid VARCHAR(30) NULL,
    father_profession VARCHAR(100) NULL, father_designation VARCHAR(100) NULL, father_office_address TEXT NULL,
    father_office_phone VARCHAR(20) NULL, father_photo_path VARCHAR(255) NULL,
    mother_name VARCHAR(150) NOT NULL, mother_phone VARCHAR(20) NULL, mother_nid VARCHAR(30) NULL,
    mother_profession VARCHAR(100) NULL, mother_designation VARCHAR(100) NULL, mother_office_address TEXT NULL,
    mother_office_phone VARCHAR(20) NULL, mother_photo_path VARCHAR(255) NULL,
    yearly_income VARCHAR(50) NULL, landless VARCHAR(5) NULL,
    guardian_type VARCHAR(20) NOT NULL DEFAULT 'Father', guardian_name VARCHAR(150) NOT NULL,
    guardian_relation VARCHAR(100) NULL, guardian_phone VARCHAR(20) NOT NULL, guardian_profession VARCHAR(100) NULL,
    guardian_designation VARCHAR(100) NULL, guardian_office_contact VARCHAR(20) NULL, guardian_office_address TEXT NULL,
    group_id INT NULL, admission_id INT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active', comments TEXT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 1, promoted_from_id INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_student_id (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE students ADD COLUMN group_id INT NULL AFTER guardian_office_address;
ALTER TABLE students ADD COLUMN admission_id INT NULL AFTER group_id;
ALTER TABLE students CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- =============================================================
-- শেষ। এবার সব টেবিল আছে কিনা চেক করুন:
-- SHOW TABLES;
-- =============================================================

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
