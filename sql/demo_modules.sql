CREATE TABLE IF NOT EXISTS academic_holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        holiday_name VARCHAR(150) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        holiday_type VARCHAR(30) NOT NULL DEFAULT 'General',
        remarks VARCHAR(255) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_mediums (
        id INT AUTO_INCREMENT PRIMARY KEY,
        medium_name VARCHAR(100) NOT NULL,
        medium_code VARCHAR(20) NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_classes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        medium_id INT NULL,
        class_name VARCHAR(100) NOT NULL,
        rank INT NOT NULL DEFAULT 0,
        class_code VARCHAR(5) NULL,
        has_group TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_name VARCHAR(100) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admission_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        class_id INT NOT NULL,
        method ENUM('None','Test','Lottery') NOT NULL DEFAULT 'None',
        total_seats INT NULL,
        full_marks INT NULL,
        pass_marks INT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_session_class (session_id, class_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_daily_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        class_id INT NULL,
        section_id INT NULL,
        shift_id INT NULL,
        attendance_date DATE NOT NULL,
        status ENUM('Present','Absent') NOT NULL DEFAULT 'Absent',
        marked_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_student_date (student_id, attendance_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sms_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NULL,
        phone VARCHAR(20) NOT NULL,
        purpose VARCHAR(50) NOT NULL DEFAULT 'absent',
        message TEXT NULL,
        status ENUM('Sent','Failed') NOT NULL DEFAULT 'Failed',
        response TEXT NULL,
        sent_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canteen_settings (
    id INT PRIMARY KEY,
    allow_due_sale TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canteen_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canteen_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canteen_subcategories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
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
        person_type ENUM('student','employee') NULL,
        person_uid VARCHAR(100) NOT NULL,
        punch_type ENUM('in','out') NOT NULL DEFAULT 'in',
        punch_time DATETIME NOT NULL,
        verify_mode VARCHAR(20) NULL,
        raw_payload TEXT NULL,
        is_processed TINYINT(1) NOT NULL DEFAULT 0,
        processed_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_person (person_type, person_uid, punch_time),
        INDEX idx_device (device_id, punch_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_agent_settings (
        id INT PRIMARY KEY,
        secret VARCHAR(64) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_daily_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        attendance_date DATE NOT NULL,
        check_in_time DATETIME NULL,
        check_out_time DATETIME NULL,
        status ENUM('Present','Absent') NOT NULL DEFAULT 'Present',
        marked_by VARCHAR(100) NULL,
        source VARCHAR(30) NOT NULL DEFAULT 'device',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_employee_date (employee_id, attendance_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_fee_collections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        session_id INT NOT NULL,
        period_type ENUM('Admission','Monthly') NOT NULL,
        month TINYINT NOT NULL DEFAULT 0,
        ac_head_id INT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash',
        trx_id VARCHAR(100) NULL,
        folio_no VARCHAR(50) NULL,
        receipt_no VARCHAR(50) NULL,
        collected_by VARCHAR(100) NULL,
        collected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'Paid',
        KEY idx_lookup (student_id, session_id, period_type, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_vouchers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        voucher_no VARCHAR(50) NOT NULL,
        voucher_type VARCHAR(20) NOT NULL,
        transaction_date DATE NOT NULL,
        payment_type VARCHAR(10) NOT NULL DEFAULT 'Cash',
        description TEXT NULL,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_voucher_no (voucher_no),
        KEY idx_type (voucher_type), KEY idx_date (transaction_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_voucher_details (
        id INT AUTO_INCREMENT PRIMARY KEY,
        voucher_id INT NOT NULL,
        ac_head_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        remarks VARCHAR(255) NULL,
        KEY idx_voucher (voucher_id), KEY idx_head (ac_head_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_admission_fee_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        class_id INT NOT NULL,
        shift_id INT NOT NULL DEFAULT 0,
        section_id INT NOT NULL DEFAULT 0,
        gender VARCHAR(10) NOT NULL DEFAULT '',
        applicability VARCHAR(30) NOT NULL DEFAULT 'New',
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_lookup (session_id, class_id, shift_id, section_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_admission_fee_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_id INT NOT NULL,
        ac_head_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        KEY idx_setting (setting_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_bank_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bank_name VARCHAR(150) NOT NULL,
        account_no VARCHAR(60) NULL,
        branch_name VARCHAR(150) NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_money_receipt_settings (
        id INT PRIMARY KEY DEFAULT 1,
        copy_type VARCHAR(20) NOT NULL DEFAULT 'student',
        paper_size VARCHAR(20) NOT NULL DEFAULT 'a4_portrait',
        show_due TINYINT(1) NOT NULL DEFAULT 1,
        show_logo TINYINT(1) NOT NULL DEFAULT 1,
        show_signature TINYINT(1) NOT NULL DEFAULT 1,
        footer_note VARCHAR(255) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_student_waivers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        session_id INT NOT NULL,
        class_id INT NOT NULL,
        month TINYINT NOT NULL,
        ac_head_id INT NOT NULL,
        waiver_type ENUM('Percentage','Fixed') NOT NULL DEFAULT 'Fixed',
        waiver_value DECIMAL(10,2) NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_lookup (student_id, session_id, month, ac_head_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_account_heads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ac_type VARCHAR(30) NOT NULL,
        ac_category VARCHAR(20) NOT NULL DEFAULT 'Receipts',
        parent_id INT NOT NULL DEFAULT 0,
        ac_code VARCHAR(30) NOT NULL,
        ac_head VARCHAR(150) NOT NULL,
        period_type VARCHAR(10) NOT NULL DEFAULT 'Monthly',
        has_child VARCHAR(5) NOT NULL DEFAULT 'No',
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ac_code (ac_code),
        KEY idx_parent (parent_id), KEY idx_type (ac_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_monthly_fee_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        class_id INT NOT NULL,
        shift_id INT NOT NULL DEFAULT 0,
        section_id INT NOT NULL DEFAULT 0,
        gender VARCHAR(10) NOT NULL DEFAULT '',
        applicability VARCHAR(30) NOT NULL DEFAULT 'New',
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_lookup (session_id, class_id, shift_id, section_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_monthly_fee_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_id INT NOT NULL,
        month TINYINT NOT NULL,
        ac_head_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_item (setting_id, month, ac_head_id),
        KEY idx_setting (setting_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_student_admission_fees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        session_id INT NOT NULL,
        ac_head_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_item (student_id, session_id, ac_head_id),
        KEY idx_student (student_id, session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_student_monthly_fees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        session_id INT NOT NULL,
        month TINYINT NOT NULL,
        ac_head_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_item (student_id, session_id, month, ac_head_id),
        KEY idx_student (student_id, session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS elearning_homework (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        section_id INT NULL,
        subject_id INT NOT NULL,
        teacher_id INT NULL,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        attachment_path VARCHAR(255) NULL,
        deadline DATETIME NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_class_section (class_id, section_id),
        KEY idx_subject (subject_id),
        KEY idx_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS elearning_homework_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        homework_id INT NOT NULL,
        student_id INT NOT NULL,
        submission_text TEXT NULL,
        attachment_path VARCHAR(255) NULL,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        marks DECIMAL(5,2) NULL,
        feedback TEXT NULL,
        status ENUM('Submitted','Checked') NOT NULL DEFAULT 'Submitted',
        UNIQUE KEY uniq_submission (homework_id, student_id),
        KEY idx_homework (homework_id),
        KEY idx_student (student_id)
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
        branch_id INT NULL,
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

CREATE TABLE IF NOT EXISTS employee_grades (
        id INT AUTO_INCREMENT PRIMARY KEY,
        grade_name VARCHAR(100) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('Full-time','Part-time') NOT NULL DEFAULT 'Full-time',
        name VARCHAR(100) NOT NULL,
        deduct_salary_on_absent TINYINT(1) NOT NULL DEFAULT 0,
        for_absent_days INT NOT NULL DEFAULT 0,
        salary_deduct_days INT NOT NULL DEFAULT 0,
        has_overtime TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_shifts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        green_limit INT NOT NULL DEFAULT 0,
        orange_limit INT NOT NULL DEFAULT 0,
        red_limit INT NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS id_card_settings (
        id INT PRIMARY KEY DEFAULT 1,
        show_logo TINYINT(1) NOT NULL DEFAULT 1,
        logo_position ENUM('Left','Right') NOT NULL DEFAULT 'Right',
        show_photo TINYINT(1) NOT NULL DEFAULT 1,
        show_qr TINYINT(1) NOT NULL DEFAULT 0,
        qr_side ENUM('Front','Back') NOT NULL DEFAULT 'Back',
        show_school_name_front TINYINT(1) NOT NULL DEFAULT 1,
        show_school_name_back TINYINT(1) NOT NULL DEFAULT 1,
        front_template_path VARCHAR(255) NULL,
        back_template_path VARCHAR(255) NULL,
        accent_color VARCHAR(10) NOT NULL DEFAULT '#1e3a8a',
        signature_label VARCHAR(100) NOT NULL DEFAULT 'Signature of Headmaster',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branch_id INT NULL,
        user_type VARCHAR(30) NOT NULL,
        username VARCHAR(100) NOT NULL UNIQUE,
        email VARCHAR(150) NULL,
        password VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        expires_at DATETIME NULL,
        last_login DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL,
        user_type VARCHAR(30) NOT NULL,
        login_status VARCHAR(10) NOT NULL DEFAULT 'Success',
        ip_address VARCHAR(45) NULL,
        device_type VARCHAR(20) NULL,
        browser VARCHAR(50) NULL,
        os VARCHAR(50) NULL,
        user_agent VARCHAR(255) NULL,
        login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_username (username),
        INDEX idx_login_time (login_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_settings (
    id INT PRIMARY KEY,
    allow_due_sale TINYINT(1) NOT NULL DEFAULT 1,
    send_sms_on_sale TINYINT(1) NOT NULL DEFAULT 1,
    logo_path VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_sale_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    return_no VARCHAR(40) NOT NULL,
    return_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_sale_return_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_id INT NOT NULL,
    item_id INT NOT NULL,
    qty INT NOT NULL,
    rate DECIMAL(10,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_subcategories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    stock_qty INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    address VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_no VARCHAR(40) NOT NULL,
    supplier_name VARCHAR(150) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    due_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_purchase_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    item_id INT NOT NULL,
    qty INT NOT NULL,
    rate DECIMAL(10,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_supplier_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(150) NOT NULL,
    invoice_no VARCHAR(40) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    due_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    item_id INT NOT NULL,
    qty INT NOT NULL,
    rate DECIMAL(10,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_sale_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_stock_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    adj_type VARCHAR(20) NOT NULL,
    qty INT NOT NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leave_registers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_type_id INT NULL,
        employee_id INT NOT NULL,
        leave_type_id INT NULL,
        from_date DATE NOT NULL,
        to_date DATE NOT NULL,
        total_days INT NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leave_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_type_id INT NULL,
        title VARCHAR(150) NOT NULL,
        short_title VARCHAR(30) NOT NULL,
        leave_days INT NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS library_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_name VARCHAR(150) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS library_books (
        id INT AUTO_INCREMENT PRIMARY KEY,
        book_code VARCHAR(50) NULL,
        isbn VARCHAR(30) NULL,
        title VARCHAR(255) NOT NULL,
        title_bn VARCHAR(255) NULL,
        author VARCHAR(150) NULL,
        publisher VARCHAR(150) NULL,
        category_id INT NULL,
        edition VARCHAR(50) NULL,
        language VARCHAR(30) NOT NULL DEFAULT 'Bengali',
        shelf_no VARCHAR(30) NULL,
        rack_no VARCHAR(30) NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_copies INT NOT NULL DEFAULT 1,
        available_copies INT NOT NULL DEFAULT 1,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_book_code (book_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS library_issues (
        id INT AUTO_INCREMENT PRIMARY KEY,
        book_id INT NOT NULL,
        student_id INT NOT NULL,
        issue_date DATE NOT NULL,
        due_date DATE NOT NULL,
        return_date DATE NULL,
        fine_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        fine_paid TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('Issued','Returned','Lost') NOT NULL DEFAULT 'Issued',
        remarks VARCHAR(255) NULL,
        issued_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_book (book_id), INDEX idx_student (student_id), INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS library_settings (
        id INT PRIMARY KEY DEFAULT 1, fine_per_day DECIMAL(6,2) NOT NULL DEFAULT 5.00,
        max_days_allowed INT NOT NULL DEFAULT 14, max_books_per_student INT NOT NULL DEFAULT 2
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS elearning_live_classes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        section_id INT NULL,
        subject_id INT NOT NULL,
        teacher_id INT NULL,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        scheduled_at DATETIME NOT NULL,
        duration_minutes INT NOT NULL DEFAULT 40,
        room_id VARCHAR(255) NULL,
        room_error VARCHAR(500) NULL,
        status ENUM('Scheduled','Live','Ended','Cancelled') NOT NULL DEFAULT 'Scheduled',
        branch_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_class_section (class_id, section_id),
        KEY idx_subject (subject_id),
        KEY idx_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payscale_grades (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_payscales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NOT NULL,
        employee_type_id INT NOT NULL,
        grade_id INT NOT NULL,
        basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_heads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category ENUM('Addition','Deduction') NOT NULL DEFAULT 'Addition',
        absent_deduction ENUM('Yes','No') NOT NULL DEFAULT 'No',
        parent_id INT NULL DEFAULT NULL,
        ac_code VARCHAR(50) NOT NULL,
        ac_head VARCHAR(150) NOT NULL,
        period_type ENUM('Monthly','Yearly') NOT NULL DEFAULT 'Monthly',
        has_child ENUM('Yes','No') NOT NULL DEFAULT 'No',
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_parent_id (parent_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_payscale_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        payscale_id INT NOT NULL,
        payroll_head_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        UNIQUE KEY uniq_payscale_head (payscale_id, payroll_head_id),
        CONSTRAINT fk_ppi_payscale FOREIGN KEY (payscale_id) REFERENCES payroll_payscales(id) ON DELETE CASCADE,
        CONSTRAINT fk_ppi_head FOREIGN KEY (payroll_head_id) REFERENCES payroll_heads(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_salary_sheets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        payscale_id INT NOT NULL,
        month TINYINT NOT NULL,
        year SMALLINT NOT NULL,
        working_days INT NOT NULL DEFAULT 30,
        status ENUM('Draft','Finalized') NOT NULL DEFAULT 'Draft',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_payscale_month_year (payscale_id, month, year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_salary_sheet_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sheet_id INT NOT NULL,
        employee_id INT NOT NULL,
        absent_days INT NOT NULL DEFAULT 0,
        basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        gross_addition DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        gross_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        net_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        breakdown_json TEXT NULL,
        UNIQUE KEY uniq_sheet_employee (sheet_id, employee_id),
        CONSTRAINT fk_gssi_sheet FOREIGN KEY (sheet_id) REFERENCES payroll_salary_sheets(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS salary_charts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_type_id INT NOT NULL,
        payrolls_head VARCHAR(150) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS certificate_config (
        id INT PRIMARY KEY DEFAULT 1,
        template_style VARCHAR(20) NOT NULL DEFAULT 'style1',
        custom_template_path VARCHAR(255) NULL,
        positions_json TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS id_card_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        side ENUM('front','back') NOT NULL DEFAULT 'front',
        image_path VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS testimonial_settings (
        id INT PRIMARY KEY DEFAULT 1,
        show_header TINYINT(1) NOT NULL DEFAULT 1,
        show_footer TINYINT(1) NOT NULL DEFAULT 1,
        orientation VARCHAR(10) NOT NULL DEFAULT 'portrait',
        template_style VARCHAR(20) NOT NULL DEFAULT 'classic',
        custom_template_path VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transfer_certificate_config (
        id INT PRIMARY KEY DEFAULT 1,
        template_style VARCHAR(20) NOT NULL DEFAULT 'style1',
        custom_template_path VARCHAR(255) NULL,
        positions_json TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promo_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_name VARCHAR(150) NOT NULL,
        image_path VARCHAR(255) NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promo_contacts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        mobile VARCHAR(20) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_group (group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS elearning_recorded_classes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        section_id INT NULL,
        subject_id INT NOT NULL,
        teacher_id INT NULL,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        video_type ENUM('youtube','vimeo') NOT NULL DEFAULT 'youtube',
        video_url VARCHAR(500) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_class_section (class_id, section_id),
        KEY idx_subject (subject_id),
        KEY idx_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_assign_subjects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        class_id INT NOT NULL,
        term_id INT NOT NULL,
        part_id INT NOT NULL,
        subject_id INT NOT NULL,
        full_mark_subject DECIMAL(6,2) NOT NULL DEFAULT 100,
        mark_for_term_percent DECIMAL(6,2) NOT NULL DEFAULT 100,
        full_mark_exam DECIMAL(6,2) NOT NULL DEFAULT 100,
        pass_mark_exam DECIMAL(6,2) NOT NULL DEFAULT 33,
        pass_individual TINYINT(1) NOT NULL DEFAULT 1,
        fail_on_absent TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_assign (term_id, part_id, subject_id),
        KEY idx_class (class_id), KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_terms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        term_name VARCHAR(100) NOT NULL,
        rank INT NOT NULL DEFAULT 1,
        is_final_term TINYINT(1) NOT NULL DEFAULT 0,
        is_aggregated_result TINYINT(1) NOT NULL DEFAULT 0,
        admit_card_with_routine TINYINT(1) NOT NULL DEFAULT 0,
        admit_card_with_instruction TINYINT(1) NOT NULL DEFAULT 0,
        exam_routine_with_instruction TINYINT(1) NOT NULL DEFAULT 0,
        website_result_publish TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_attendance_subject (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        term_id INT NOT NULL,
        part_id INT NOT NULL,
        subject_id INT NOT NULL,
        class_id INT NOT NULL,
        shift_id INT NOT NULL,
        section_id INT NOT NULL,
        student_id INT NOT NULL,
        status ENUM('Present','Absent') NOT NULL DEFAULT 'Absent',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_att (term_id, part_id, subject_id, student_id),
        KEY idx_lookup (class_id, shift_id, section_id, term_id, part_id, subject_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_eligibility (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        term_id INT NOT NULL,
        class_id INT NOT NULL,
        shift_id INT NOT NULL,
        section_id INT NOT NULL,
        student_id INT NOT NULL,
        subject_id INT NOT NULL,
        is_eligible TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_elig (term_id, student_id, subject_id),
        KEY idx_lookup (class_id, shift_id, section_id, term_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_parts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        main_part_id INT NULL,
        part_name VARCHAR(100) NOT NULL,
        part_short_name VARCHAR(30) NOT NULL,
        calc_method VARCHAR(20) NOT NULL DEFAULT 'Average',
        sub_part_full_marks DECIMAL(6,2) NULL,
        rank INT NOT NULL DEFAULT 1,
        term_combine_method VARCHAR(20) NOT NULL DEFAULT 'None',
        attach_scope VARCHAR(20) NOT NULL DEFAULT 'Every Term',
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_session (session_id),
        KEY idx_main_part (main_part_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_routines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        class_id INT NOT NULL,
        shift_id INT NOT NULL,
        section_id INT NOT NULL,
        term_id INT NOT NULL,
        part_id INT NOT NULL,
        subject_id INT NOT NULL,
        exam_date DATE NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_routine (class_id, shift_id, section_id, term_id, part_id, subject_id),
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_grade_points (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        grade_letter VARCHAR(10) NOT NULL,
        gpa DECIMAL(4,2) NOT NULL DEFAULT 0.00,
        mark_from DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        mark_to DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        remarks VARCHAR(100) NULL,
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_marks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        term_id INT NOT NULL,
        part_id INT NOT NULL,
        subject_id INT NOT NULL,
        class_id INT NOT NULL,
        shift_id INT NOT NULL,
        section_id INT NOT NULL,
        student_id INT NOT NULL,
        marks_obtained DECIMAL(6,2) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_mark (term_id, part_id, subject_id, student_id),
        KEY idx_lookup (class_id, shift_id, section_id, term_id, part_id, subject_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NULL,
        shift_id INT NULL,
        section_id INT NOT NULL,
        routine_days VARCHAR(100) NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        has_break TINYINT(1) NOT NULL DEFAULT 0,
        subject_id INT NOT NULL,
        teacher_id INT NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_lookup (class_id, shift_id, section_id),
        KEY idx_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_result_settings (
        id INT PRIMARY KEY DEFAULT 1,
        show_medium_name TINYINT(1) NOT NULL DEFAULT 1,
        show_student_roll_no TINYINT(1) NOT NULL DEFAULT 1,
        show_student_picture_qr TINYINT(1) NOT NULL DEFAULT 0,
        add_watermark_background TINYINT(1) NOT NULL DEFAULT 1,
        show_banner_instead_logo TINYINT(1) NOT NULL DEFAULT 0,
        pass_fail_summary_merit_list TINYINT(1) NOT NULL DEFAULT 1,
        merit_list_with_subjects TINYINT(1) NOT NULL DEFAULT 1,
        show_final_grade_letter_point TINYINT(1) NOT NULL DEFAULT 1,
        show_subject_grade_letter TINYINT(1) NOT NULL DEFAULT 1,
        show_subject_grade_point TINYINT(1) NOT NULL DEFAULT 1,
        show_result_remarks TINYINT(1) NOT NULL DEFAULT 1,
        show_working_days_attend_absent TINYINT(1) NOT NULL DEFAULT 1,
        show_absent_count TINYINT(1) NOT NULL DEFAULT 1,
        write_manual_attendance_count TINYINT(1) NOT NULL DEFAULT 1,
        show_discipline_cleanliness TINYINT(1) NOT NULL DEFAULT 1,
        show_interpretation TINYINT(1) NOT NULL DEFAULT 1,
        show_total_subject_fail_count TINYINT(1) NOT NULL DEFAULT 0,
        show_part_marks_calculation TINYINT(1) NOT NULL DEFAULT 1,
        calculate_merit_position_by_marks TINYINT(1) NOT NULL DEFAULT 0,
        enable_merit_tiebreaker TINYINT(1) NOT NULL DEFAULT 1,
        aggregate_result_type VARCHAR(50) NOT NULL DEFAULT 'Terms Wise Percentage',
        report_card_template VARCHAR(30) NOT NULL DEFAULT 'Govt. / MPO',
        left_signature VARCHAR(100) NOT NULL DEFAULT 'Signature of Teacher',
        middle_signature VARCHAR(100) NOT NULL DEFAULT 'Signature of Coordinator',
        right_signature VARCHAR(100) NOT NULL DEFAULT 'Signature of Headmaster',
        body_font_size INT NOT NULL DEFAULT 12,
        body_font_color VARCHAR(10) NOT NULL DEFAULT '#333333',
        institute_name_color VARCHAR(10) NOT NULL DEFAULT '#000080',
        table_header_color VARCHAR(10) NOT NULL DEFAULT '#333333',
        fail_letter_grade_color VARCHAR(10) NOT NULL DEFAULT '#ff0000',
        body_font_style VARCHAR(20) NOT NULL DEFAULT 'Normal',
        gpa_color VARCHAR(10) NOT NULL DEFAULT '#00B1E1',
        remarks_progress_color VARCHAR(10) NOT NULL DEFAULT '#00B1E1',
        table_footer_color VARCHAR(10) NOT NULL DEFAULT '#00B1E1',
        fail_part_marks_color VARCHAR(10) NOT NULL DEFAULT '#ff0000',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_working_days (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        term_id INT NOT NULL,
        class_id INT NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        total_working_days INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_wd (term_id, class_id),
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_branch_transfer_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        from_branch_id INT NULL,
        to_branch_id INT NOT NULL,
        transferred_by VARCHAR(100) NULL,
        transferred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_vehicles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vehicle_no VARCHAR(50) NOT NULL,
        bus_no VARCHAR(20) NULL,
        vehicle_name VARCHAR(100) NULL,
        vehicle_type ENUM('Bus','Microbus','Car','Van','Other') NOT NULL DEFAULT 'Bus',
        capacity INT NOT NULL DEFAULT 0,
        driver_id INT NULL,
        helper_id INT NULL,
        gps_device_identifier VARCHAR(150) NULL,
        gps_device_ref_id VARCHAR(100) NULL,
        fitness_expiry DATE NULL,
        insurance_expiry DATE NULL,
        tax_token_expiry DATE NULL,
        status ENUM('Active','Inactive','Maintenance') NOT NULL DEFAULT 'Active',
        remarks TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_vehicle_no (vehicle_no),
        INDEX idx_gps_device (gps_device_identifier)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_routes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        route_no VARCHAR(20) NULL,
        route_name VARCHAR(150) NOT NULL,
        route_category ENUM('Student','Staff','Mixed') NOT NULL DEFAULT 'Student',
        vehicle_id INT NULL,
        monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_stops (
        id INT AUTO_INCREMENT PRIMARY KEY,
        route_id INT NOT NULL,
        stop_name VARCHAR(150) NOT NULL,
        stop_order INT NOT NULL DEFAULT 0,
        lat DECIMAL(10,7) NULL,
        lng DECIMAL(10,7) NULL,
        radius_m INT NOT NULL DEFAULT 100,
        pickup_time TIME NULL,
        drop_time TIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_route_order (route_id, stop_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_student_assign (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        route_id INT NOT NULL,
        stop_id INT NOT NULL,
        fee_override DECIMAL(10,2) NULL,
        tracking_token VARCHAR(64) NULL,
        assigned_date DATE NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        remarks VARCHAR(255) NULL,
        INDEX idx_student (student_id), INDEX idx_route (route_id),
        UNIQUE KEY uniq_token (tracking_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_staff_assign (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        route_id INT NOT NULL,
        stop_id INT NOT NULL,
        fee_override DECIMAL(10,2) NULL,
        assigned_date DATE NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        remarks VARCHAR(255) NULL,
        INDEX idx_employee (employee_id), INDEX idx_route2 (route_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_fee_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        route_id INT NOT NULL,
        month_year VARCHAR(7) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        status ENUM('Unpaid','Partial','Paid') NOT NULL DEFAULT 'Unpaid',
        payment_date DATE NULL,
        payment_method VARCHAR(50) NULL,
        trx_id VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_student_month (student_id, month_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_gps_settings (
        id INT PRIMARY KEY DEFAULT 1,
        provider_name VARCHAR(50) NOT NULL DEFAULT 'traccar',
        base_url VARCHAR(255) NULL,
        username VARCHAR(150) NULL,
        password VARCHAR(255) NULL,
        poll_interval_sec INT NOT NULL DEFAULT 20,
        school_lat DECIMAL(10,7) NULL,
        school_lng DECIMAL(10,7) NULL,
        geofence_radius_m INT NOT NULL DEFAULT 200,
        connection_status ENUM('Not Tested','Connected','Failed') NOT NULL DEFAULT 'Not Tested',
        last_tested_at DATETIME NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_gps_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        vehicle_id INT NOT NULL,
        lat DECIMAL(10,7) NOT NULL,
        lng DECIMAL(10,7) NOT NULL,
        speed_kmh DECIMAL(6,2) NOT NULL DEFAULT 0,
        course_deg DECIMAL(6,2) NOT NULL DEFAULT 0,
        ignition TINYINT(1) NOT NULL DEFAULT 0,
        device_time DATETIME NOT NULL,
        received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vehicle_time (vehicle_id, device_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_vehicle_live (
        vehicle_id INT PRIMARY KEY,
        lat DECIMAL(10,7) NULL,
        lng DECIMAL(10,7) NULL,
        speed_kmh DECIMAL(6,2) NOT NULL DEFAULT 0,
        course_deg DECIMAL(6,2) NOT NULL DEFAULT 0,
        ignition TINYINT(1) NOT NULL DEFAULT 0,
        device_time DATETIME NULL,
        connection_status ENUM('Online','Offline','Unknown') NOT NULL DEFAULT 'Unknown',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vehicle_id INT NOT NULL,
        event_type ENUM('Arrived School','Departed School','Stop Reached','Offline Alert') NOT NULL,
        stop_id INT NULL,
        event_time DATETIME NOT NULL,
        lat DECIMAL(10,7) NULL,
        lng DECIMAL(10,7) NULL,
        sms_sent TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vehicle_event_time (vehicle_id, event_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_staff (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        role ENUM('Driver','Helper') NOT NULL DEFAULT 'Driver',
        phone VARCHAR(20) NULL,
        license_no VARCHAR(50) NULL,
        license_expiry DATE NULL,
        nid_no VARCHAR(30) NULL,
        address TEXT NULL,
        joining_date DATE NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        notice_date DATE NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS website_why_choose (
        id INT AUTO_INCREMENT PRIMARY KEY,
        image_path VARCHAR(255) NOT NULL,
        title VARCHAR(255) NOT NULL,
        description VARCHAR(500) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS website_breaking_news (
        id INT AUTO_INCREMENT PRIMARY KEY,
        news_text VARCHAR(500) NOT NULL,
        link VARCHAR(255) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS website_govt_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        doc_type ENUM('APA','Suddhachar') NOT NULL DEFAULT 'APA',
        title VARCHAR(200) NOT NULL,
        doc_year VARCHAR(20) NULL,
        doc_link VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS website_albums (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        cover_image VARCHAR(255) NOT NULL,
        description VARCHAR(500) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS website_news (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        image_path VARCHAR(255) NULL,
        short_desc VARCHAR(500) NULL,
        full_desc TEXT NULL,
        news_date DATE NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS website_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        image_path VARCHAR(255) NULL,
        event_date DATE NOT NULL,
        location VARCHAR(255) NULL,
        description TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS message_corner (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        designation VARCHAR(150) NOT NULL,
        photo_path VARCHAR(255) NULL,
        message TEXT NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS website_careers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        position_title VARCHAR(150) NOT NULL,
        department VARCHAR(150) NULL,
        deadline_date DATE NULL,
        description TEXT NULL,
        apply_link VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS website_board_results (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        exam_year VARCHAR(20) NOT NULL,
        result_link VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS website_useful_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NOT NULL,
        url VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS website_social_media (
        id INT AUTO_INCREMENT PRIMARY KEY,
        platform VARCHAR(50) NOT NULL,
        url VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accounts_chart (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(20) NOT NULL,
                name VARCHAR(150) NOT NULL,
                account_type ENUM('Asset','Liability','Equity','Income','Expense') NOT NULL,
                parent_id INT NULL,
                is_group TINYINT(1) NOT NULL DEFAULT 0,
                opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
                opening_balance_type ENUM('Dr','Cr') NOT NULL DEFAULT 'Dr',
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'Active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_code (code),
                KEY idx_type (account_type), KEY idx_parent (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts_journals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                journal_no VARCHAR(30) NOT NULL,
                journal_type ENUM('Journal','Receipt','Payment','Contra') NOT NULL DEFAULT 'Journal',
                journal_date DATE NOT NULL,
                narration VARCHAR(500) NULL,
                reference_type VARCHAR(50) NULL,
                reference_id INT NULL,
                created_by VARCHAR(100) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_journal_no (journal_no),
                KEY idx_date (journal_date), KEY idx_type (journal_type),
                KEY idx_ref (reference_type, reference_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts_journal_lines (
                id INT AUTO_INCREMENT PRIMARY KEY,
                journal_id INT NOT NULL,
                account_id INT NOT NULL,
                debit DECIMAL(14,2) NOT NULL DEFAULT 0,
                credit DECIMAL(14,2) NOT NULL DEFAULT 0,
                remarks VARCHAR(255) NULL,
                KEY idx_journal (journal_id), KEY idx_account (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts_vendors (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                phone VARCHAR(30) NULL,
                email VARCHAR(150) NULL,
                address VARCHAR(255) NULL,
                opening_due DECIMAL(14,2) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'Active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts_vendor_bills (
                id INT AUTO_INCREMENT PRIMARY KEY,
                vendor_id INT NOT NULL,
                bill_no VARCHAR(50) NOT NULL,
                bill_date DATE NOT NULL,
                expense_account_id INT NOT NULL,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                description VARCHAR(255) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Unpaid',
                journal_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_vendor (vendor_id), KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts_vendor_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                vendor_id INT NOT NULL,
                bill_id INT NULL,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                payment_date DATE NOT NULL,
                payment_account_id INT NOT NULL,
                journal_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_vendor (vendor_id), KEY idx_bill (bill_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admission_fee_gateway_settings (
                id INT PRIMARY KEY DEFAULT 1,
                ac_head_id INT NULL,
                online_payment_enabled TINYINT(1) NOT NULL DEFAULT 1,
                manual_enabled TINYINT(1) NOT NULL DEFAULT 0,
                bkash_number VARCHAR(30) NULL,
                bkash_type VARCHAR(20) NOT NULL DEFAULT 'Personal',
                nagad_number VARCHAR(30) NULL,
                nagad_type VARCHAR(20) NOT NULL DEFAULT 'Personal',
                manual_instructions TEXT NULL,
                auto_enabled TINYINT(1) NOT NULL DEFAULT 0,
                auto_provider VARCHAR(20) NOT NULL DEFAULT 'sslcommerz',
                sslcz_store_id VARCHAR(100) NULL,
                sslcz_store_password VARCHAR(150) NULL,
                sslcz_sandbox TINYINT(1) NOT NULL DEFAULT 1,
                shurjopay_username VARCHAR(100) NULL,
                shurjopay_password VARCHAR(150) NULL,
                shurjopay_prefix VARCHAR(20) NULL,
                shurjopay_sandbox TINYINT(1) NOT NULL DEFAULT 1,
                paystation_merchant_id VARCHAR(100) NULL,
                paystation_password VARCHAR(150) NULL,
                paystation_sandbox TINYINT(1) NOT NULL DEFAULT 1,
                eps_merchant_id VARCHAR(100) NULL,
                eps_username VARCHAR(100) NULL,
                eps_password VARCHAR(150) NULL,
                eps_hash_key VARCHAR(150) NULL,
                eps_sandbox TINYINT(1) NOT NULL DEFAULT 1,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admission_fee_claims (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_pk INT NULL,
                session_id INT NOT NULL,
                class_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                method VARCHAR(30) NOT NULL,
                sender_number VARCHAR(30) NULL,
                trx_id VARCHAR(100) NULL,
                gateway_val_id VARCHAR(100) NULL,
                status ENUM('Pending','Approved','Rejected','Failed') NOT NULL DEFAULT 'Pending',
                submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                verified_by VARCHAR(100) NULL,
                verified_at DATETIME NULL,
                finance_collection_id INT NULL,
                pending_submission_id INT NULL,
                KEY idx_student (student_pk), KEY idx_trx (trx_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admission_pending_submissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                token VARCHAR(64) NOT NULL,
                session_id INT NOT NULL,
                class_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                payload LONGTEXT NOT NULL,
                status ENUM('Pending','Completed','Expired') NOT NULL DEFAULT 'Pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                new_student_id INT NULL,
                KEY idx_token (token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS demo_requests (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,contact_name VARCHAR(120) NOT NULL,school_name VARCHAR(200) NOT NULL,email VARCHAR(150) NOT NULL,phone VARCHAR(30) NOT NULL,student_count INT UNSIGNED NOT NULL,plan VARCHAR(20) NOT NULL DEFAULT '',requirements TEXT NOT NULL,status ENUM('New','Contacted','Closed') NOT NULL DEFAULT 'New',consented_at DATETIME NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(status,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS demo_request_limits (ip_hash CHAR(64) PRIMARY KEY,window_start DATETIME NOT NULL,request_count INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS exam_seat_rooms (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id INT NOT NULL,
                term_id INT NOT NULL,
                part_id INT NOT NULL DEFAULT 0,
                class_id INT NOT NULL,
                shift_id INT NOT NULL DEFAULT 0,
                section_id INT NOT NULL DEFAULT 0,
                room VARCHAR(100) NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_room (session_id, term_id, part_id, class_id, shift_id, section_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_fee_gateway_settings (
                id INT PRIMARY KEY DEFAULT 1,
                is_enabled TINYINT(1) NOT NULL DEFAULT 0,
                bkash_number VARCHAR(30) NULL,
                bkash_type VARCHAR(20) NOT NULL DEFAULT 'Personal',
                nagad_number VARCHAR(30) NULL,
                nagad_type VARCHAR(20) NOT NULL DEFAULT 'Personal',
                instructions TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_fee_online_claims (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id INT NOT NULL,
                term_id INT NOT NULL,
                student_id INT NOT NULL,
                ac_head_id INT NOT NULL,
                month TINYINT NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                method VARCHAR(20) NOT NULL DEFAULT 'bKash',
                trx_id VARCHAR(100) NULL,
                status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
                submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                verified_by VARCHAR(100) NULL,
                verified_at DATETIME NULL,
                UNIQUE KEY uniq_claim (term_id, student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_role_settings (
                role_key VARCHAR(30) NOT NULL PRIMARY KEY,
                is_shown TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS elearning_live_settings (
                id INT PRIMARY KEY DEFAULT 1,
                hms_access_key TEXT NULL,
                hms_secret TEXT NULL,
                hms_template_id VARCHAR(150) NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS elearning_recording_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                live_class_id INT NULL,
                room_id VARCHAR(255) NULL,
                recording_url TEXT NULL,
                status ENUM('pending','downloading','uploading','completed','failed') NOT NULL DEFAULT 'pending',
                youtube_video_id VARCHAR(50) NULL,
                error VARCHAR(500) NULL,
                attempts INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monthly_fee_online_claims (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                session_id INT NOT NULL,
                class_id INT NOT NULL,
                month TINYINT NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                method VARCHAR(20) NOT NULL DEFAULT 'AutoGateway',
                trx_id VARCHAR(100) NULL,
                gateway_val_id VARCHAR(100) NULL,
                status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
                submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                verified_by VARCHAR(100) NULL,
                verified_at DATETIME NULL,
                folio_no VARCHAR(50) NULL,
                UNIQUE KEY uniq_claim (student_id, session_id, month)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_menu_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            system_user_id INT NOT NULL,
            page_key VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_page (system_user_id, page_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qms_settings (
                id INT PRIMARY KEY DEFAULT 1,
                qms_domain_url VARCHAR(255) NULL,
                webhook_token VARCHAR(64) NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qms_questions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                external_question_id VARCHAR(100) NULL,
                subject VARCHAR(100) NULL,
                class_name VARCHAR(100) NULL,
                question_type VARCHAR(50) NULL,
                question_text MEDIUMTEXT NULL,
                options_json TEXT NULL,
                correct_answer VARCHAR(255) NULL,
                marks DECIMAL(6,2) NULL,
                raw_payload LONGTEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Received',
                received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_external_id (external_question_id),
                KEY idx_subject (subject)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS result_merit_tiebreakers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            class_id INT NOT NULL DEFAULT 0,
            criterion_type VARCHAR(20) NOT NULL,
            subject_id INT NULL,
            priority_order INT NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_session_class (session_id, class_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS result_locks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            class_id INT NOT NULL,
            term_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Unlocked',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_lock (session_id, class_id, term_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS result_lock_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            class_id INT NOT NULL,
            term_id INT NOT NULL,
            action VARCHAR(20) NOT NULL,
            acted_by VARCHAR(100) NULL,
            acted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_lookup (session_id, class_id, term_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scholarship_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                category ENUM('Government','Board','NGO','School') NOT NULL DEFAULT 'Government',
                description TEXT NULL,
                status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scholarship_awards (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                scholarship_type_id INT NOT NULL,
                session_id INT NOT NULL,
                class_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                status ENUM('Pending','Disbursed','Cancelled') NOT NULL DEFAULT 'Pending',
                remarks VARCHAR(255) NULL,
                created_by VARCHAR(100) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_student (student_id), KEY idx_session (session_id), KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scholarship_disbursements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                award_id INT NOT NULL,
                student_id INT NOT NULL,
                total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                adjusted_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                cash_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash',
                trx_id VARCHAR(100) NULL,
                receipt_no VARCHAR(50) NULL,
                remarks VARCHAR(255) NULL,
                disbursed_by VARCHAR(100) NULL,
                disbursed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_award (award_id), KEY idx_student (student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scholarship_disbursement_lines (
                id INT AUTO_INCREMENT PRIMARY KEY,
                disbursement_id INT NOT NULL,
                ac_head_id INT NULL,
                period TINYINT NOT NULL DEFAULT 0,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                finance_collection_id INT NULL,
                KEY idx_disbursement (disbursement_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS poor_fund_aids (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                session_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                reason VARCHAR(255) NULL,
                voucher_id INT NULL,
                receipt_no VARCHAR(50) NULL,
                given_by VARCHAR(100) NULL,
                given_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_student (student_id), KEY idx_session (session_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS active_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_key VARCHAR(64) NOT NULL,
                username VARCHAR(100) NOT NULL,
                user_type VARCHAR(30) NOT NULL DEFAULT 'Unknown',
                ip_address VARCHAR(45) NULL,
                device_type VARCHAR(20) NULL,
                browser VARCHAR(50) NULL,
                os VARCHAR(50) NULL,
                login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                status VARCHAR(20) NOT NULL DEFAULT 'Active',
                UNIQUE KEY uniq_session_key (session_key),
                KEY idx_username (username), KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS security_settings (
                id INT PRIMARY KEY DEFAULT 1,
                session_timeout_minutes INT NOT NULL DEFAULT 60,
                force_single_session TINYINT(1) NOT NULL DEFAULT 0,
                updated_by VARCHAR(100) NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) NOT NULL DEFAULT 'System',
                user_type VARCHAR(30) NULL,
                module VARCHAR(60) NOT NULL,
                action VARCHAR(30) NOT NULL,
                description VARCHAR(255) NULL,
                ip_address VARCHAR(45) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_username (username), KEY idx_module (module),
                KEY idx_action (action), KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS website_menu_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            link VARCHAR(255) NOT NULL DEFAULT '',
            open_new_tab TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS website_contents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            content TEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS website_menu_meta (k VARCHAR(50) PRIMARY KEY, v VARCHAR(255) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_settings (
            id INT PRIMARY KEY,
            api_phone_number_id VARCHAR(50) NULL,
            api_access_token TEXT NULL,
            api_business_account_id VARCHAR(50) NULL,
            api_version VARCHAR(10) NOT NULL DEFAULT 'v20.0',
            api_enabled TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
