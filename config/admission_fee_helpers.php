<?php
/**
 * config/admission_fee_helpers.php
 * -----------------------------------------------------------
 * Online Admission Form-এ Admission Fee পেমেন্ট (Manual bKash/Nagad + Auto Gateway)
 * এর জন্য শেয়ার্ড টেবিল/হেল্পার। admission_form.php, submit_admission_form.php,
 * admission_payment_init.php, admission_payment_callback.php এবং admin-এর
 * payment_gateway_settings.php (Global Settings) / admission_fee_claims.php —
 * সবগুলো ফাইল এটা ব্যবহার করে।
 *
 * ডিজাইন: admission_settings টেবিলে প্রতিটা Session+Class-এর জন্য admission_fee
 * (আলাদা করে সেট না করলে 0 — মানে সেই ক্লাসে অনলাইনে ফি লাগবে না, ফর্ম আগের মতোই কাজ করবে)।
 * ফি > 0 হলে + Global "Online Payment Enabled" টগল চালু থাকলে — Public ফর্মে Payment ধাপ
 * দেখানো হয় (Manual/Auto — যেগুলো Admin Enable করেছেন)।
 *
 * Auto (অনলাইন গেটওয়ে) একসাথে একটাই Active থাকে (auto_provider কলাম) —
 * SSLCommerz | ShurjoPay | PayStation | EPS — এর মধ্যে যেটা Admin বেছে নিয়েছেন।
 */

if (!function_exists('af_ensure_columns')) {
    function af_ensure_columns(PDO $pdo): void
    {
        $chk = function (string $table, string $col) use ($pdo): bool {
            return (bool) $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch();
        };
        // admission_settings টেবিল অন্য ফাইলেও (admission_settings.php ইত্যাদি) তৈরি হয়, তাই
        // এখানেও নিশ্চিত করে নেওয়া হচ্ছে যাতে এই ফাইল একাই কল হলেও কাজ করে
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS admission_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id INT NOT NULL,
                class_id INT NOT NULL,
                method ENUM('None','Test','Lottery') NOT NULL DEFAULT 'None',
                total_seats INT NULL,
                full_marks INT NULL,
                pass_marks INT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_session_class (session_id, class_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        if (!$chk('admission_settings', 'admission_fee')) {
            $pdo->exec("ALTER TABLE admission_settings ADD COLUMN admission_fee DECIMAL(10,2) NULL DEFAULT NULL AFTER pass_marks");
        }
    }
}

if (!function_exists('af_ensure_tables')) {
    function af_ensure_tables(PDO $pdo): void
    {
        af_ensure_columns($pdo);

        // স্কুলের bKash/Nagad নাম্বার (Manual) + Auto Gateway Credential — দুটোই এক জায়গায়
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS admission_fee_gateway_settings (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        // এই টেবিল আগে থেকেই থাকতে পারে (পুরনো ইনস্টলেশন) — CREATE TABLE IF NOT EXISTS তখন কোনো
        // নতুন কলাম যোগ করবে না, তাই প্রতিটা নতুন কলাম আলাদাভাবে নিশ্চিত করা হচ্ছে (self-healing)
        $gw_cols = [
            'online_payment_enabled' => "TINYINT(1) NOT NULL DEFAULT 1",
            'auto_provider' => "VARCHAR(20) NOT NULL DEFAULT 'sslcommerz'",
            'shurjopay_username' => "VARCHAR(100) NULL",
            'shurjopay_password' => "VARCHAR(150) NULL",
            'shurjopay_prefix' => "VARCHAR(20) NULL",
            'shurjopay_sandbox' => "TINYINT(1) NOT NULL DEFAULT 1",
            'paystation_merchant_id' => "VARCHAR(100) NULL",
            'paystation_password' => "VARCHAR(150) NULL",
            'paystation_sandbox' => "TINYINT(1) NOT NULL DEFAULT 1",
            'eps_merchant_id' => "VARCHAR(100) NULL",
            'eps_username' => "VARCHAR(100) NULL",
            'eps_password' => "VARCHAR(150) NULL",
            'eps_hash_key' => "VARCHAR(150) NULL",
            'eps_sandbox' => "TINYINT(1) NOT NULL DEFAULT 1",
            // কোন কোন Finance Account Head অনলাইনে (Auto Gateway / Manual bKash-Nagad) পরিশোধ
            // করা যাবে — কমা দিয়ে আলাদা করা head id এর লিস্ট। খালি থাকলে "সব হেড" ধরা হয়
            // (পুরনো ইনস্টলেশনে আগের মতোই কাজ করবে)।
            'online_head_ids' => "VARCHAR(255) NULL",
        ];
        $chk2 = function (string $col) use ($pdo): bool {
            return (bool) $pdo->query("SHOW COLUMNS FROM `admission_fee_gateway_settings` LIKE " . $pdo->quote($col))->fetch();
        };
        foreach ($gw_cols as $col => $def) {
            if (!$chk2($col)) {
                $pdo->exec("ALTER TABLE admission_fee_gateway_settings ADD COLUMN `$col` $def");
            }
        }

        // প্রতিটা পেমেন্ট এটেম্পট/ক্লেইম — Manual হলে Admin Review করে Approve করলে,
        // Auto (Online Gateway) হলে callback ভ্যালিডেশনের পর সাথে সাথেই — status='Approved' হয়ে
        // finance_fee_collections এ আসল Paid এন্ট্রি বসে (finance_collection_id এ লিংক থাকে)।
        // Auto Gateway এর ক্ষেত্রে পেমেন্ট সফল না হওয়া পর্যন্ত students টেবিলে কোনো রো তৈরি হয় না
        // (নিচে admission_pending_submissions দেখুন) — তাই student_pk তখন NULL থাকে, আর
        // pending_submission_id দিয়ে সেই স্টেজিং রো-টার সাথে যুক্ত থাকে। পেমেন্ট ভ্যালিডেট হওয়ার
        // পরই af_finalize_admission() আসল students রো বানায় আর তখন student_pk বসে যায়।
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS admission_fee_claims (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        // পুরনো ইনস্টলেশনে এই টেবিল আগে থেকেই থাকতে পারে (student_pk NOT NULL, pending_submission_id
        // কলাম ছাড়া) — self-healing ভাবে দুটোই নিশ্চিত করা হচ্ছে
        $claim_chk = function (string $col) use ($pdo): bool {
            return (bool) $pdo->query("SHOW COLUMNS FROM `admission_fee_claims` LIKE " . $pdo->quote($col))->fetch();
        };
        if (!$claim_chk('pending_submission_id')) {
            $pdo->exec("ALTER TABLE admission_fee_claims ADD COLUMN pending_submission_id INT NULL AFTER class_id");
        }
        $null_chk = $pdo->query(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admission_fee_claims' AND COLUMN_NAME = 'student_pk'"
        )->fetchColumn();
        if ($null_chk === 'NO') {
            $pdo->exec("ALTER TABLE admission_fee_claims MODIFY COLUMN student_pk INT NULL");
        }

        // ------------------------------------------------------------------
        // "গেটওয়ে ধরে ফি নিয়ে তারপর সাবমিট হবে" — Auto (অনলাইন গেটওয়ে) বেছে নিলে ফর্মের সব
        // তথ্য (ও আগেই আপলোড হওয়া ছবির পাথ) এখানে সাময়িকভাবে জমা থাকে; students টেবিলে আসল
        // Admission রো তখনই তৈরি হয় যখন গেটওয়ে পেমেন্ট ভ্যালিডেট হয় (admission_payment_callback.php)।
        // পেমেন্ট বাতিল/ব্যর্থ হলে এই রো Pending-ই থেকে যায় — একই টোকেন দিয়ে আবার Pay করার
        // চেষ্টা করা যায় (admission_payment_pending.php), ফর্ম নতুন করে পূরণ করতে হয় না।
        // ------------------------------------------------------------------
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS admission_pending_submissions (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

/** Gateway সেটিংস রো — সবসময় id=1, না থাকলে ডিফল্ট array রিটার্ন করে */
if (!function_exists('af_gateway_settings')) {
    function af_gateway_settings(PDO $pdo): array
    {
        $row = $pdo->query("SELECT * FROM admission_fee_gateway_settings WHERE id = 1 LIMIT 1")->fetch();
        return $row ?: [
            'ac_head_id' => null, 'online_payment_enabled' => 1,
            'manual_enabled' => 0, 'bkash_number' => '', 'bkash_type' => 'Personal',
            'nagad_number' => '', 'nagad_type' => 'Personal', 'manual_instructions' => '',
            'auto_enabled' => 0, 'auto_provider' => 'sslcommerz',
            'sslcz_store_id' => '', 'sslcz_store_password' => '', 'sslcz_sandbox' => 1,
            'shurjopay_username' => '', 'shurjopay_password' => '', 'shurjopay_prefix' => '', 'shurjopay_sandbox' => 1,
            'paystation_merchant_id' => '', 'paystation_password' => '', 'paystation_sandbox' => 1,
            'eps_merchant_id' => '', 'eps_username' => '', 'eps_password' => '', 'eps_hash_key' => '', 'eps_sandbox' => 1,
            'online_head_ids' => '',
        ];
    }
}

/**
 * Admin কোন কোন Finance Account Head অনলাইনে পরিশোধযোগ্য করে রেখেছেন — id এর array।
 * খালি array রিটার্ন করলে তার মানে "কোনো নির্দিষ্ট হেড বাছাই করা নেই" = সব হেডই অনলাইনে
 * পরিশোধ করা যাবে (পুরনো ইনস্টলেশনে আগের মতোই কাজ করবে)। এটা Monthly/Tuition Fee,
 * Exam Fee — সব জায়গায় একই নিয়মে প্রযোজ্য।
 */
if (!function_exists('af_online_head_ids')) {
    function af_online_head_ids(array $gw): array
    {
        $raw = trim((string) ($gw['online_head_ids'] ?? ''));
        if ($raw === '') { return []; }
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), fn($v) => $v > 0)));
        return $ids;
    }
}

/** একটা নির্দিষ্ট Account Head অনলাইনে পরিশোধ করা যাবে কিনা (কিছু বাছাই করা না থাকলে সবই যাবে) */
if (!function_exists('af_head_online_allowed')) {
    function af_head_online_allowed(array $gw, int $head_id): bool
    {
        $ids = af_online_head_ids($gw);
        return !$ids || in_array($head_id, $ids, true);
    }
}

/** বর্তমানে Active করা Auto Provider-এর প্রয়োজনীয় Credential সব ভরা আছে কিনা — থাকলেই "Pay Online" অপশন দেখানো হবে */
if (!function_exists('af_auto_gateway_ready')) {
    function af_auto_gateway_ready(array $gw): bool
    {
        if (empty($gw['auto_enabled'])) { return false; }
        switch ($gw['auto_provider'] ?? 'sslcommerz') {
            case 'shurjopay':
                return !empty($gw['shurjopay_username']) && !empty($gw['shurjopay_password']) && !empty($gw['shurjopay_prefix']);
            case 'paystation':
                return !empty($gw['paystation_merchant_id']) && !empty($gw['paystation_password']);
            case 'eps':
                return !empty($gw['eps_merchant_id']) && !empty($gw['eps_username']) && !empty($gw['eps_password']);
            case 'sslcommerz':
            default:
                return !empty($gw['sslcz_store_id']) && !empty($gw['sslcz_store_password']);
        }
    }
}

/** Provider কী-এর প্রদর্শনযোগ্য নাম — UI/মেসেজে ব্যবহারের জন্য */
if (!function_exists('af_provider_label')) {
    function af_provider_label(string $provider): string
    {
        return ['sslcommerz' => 'SSLCommerz', 'shurjopay' => 'ShurjoPay', 'paystation' => 'PayStation', 'eps' => 'EPS'][$provider] ?? $provider;
    }
}

/**
 * admin/student_admission.php (Student Module > Student Admission) থেকে নতুন স্টুডেন্ট Add করার
 * সময় — Finance Module > Student Accounts > Admission Fees (finance_admission_fee_settings /
 * finance_admission_fee_items) এ এই Session/Class/Shift/Section/Gender-এর জন্য অন্তত একটা
 * Fee Setting (কমপক্ষে একটা Item সহ) করা আছে কিনা যাচাই করে। shift_id/section_id = 0 এবং
 * gender = '' মানে "All" (সব শিফট/সেকশন/জেন্ডারের জন্য প্রযোজ্য) — তাই ওয়াইল্ডকার্ড হিসেবে মেলানো হয়।
 * এটা এবং af_class_fee() সম্পূর্ণ আলাদা দুটো Admission Fee সিস্টেম — af_class_fee()
 * (admission_settings টেবিল) পাবলিক অনলাইন ভর্তি ফর্ম/পেমেন্ট গেটওয়ের জন্য, আর এটা Finance
 * Module-এর নিজস্ব বিস্তারিত (Shift/Section/Gender/Applicability ভিত্তিক) সেটিং-এর জন্য।
 */
if (!function_exists('admission_fee_tables_ensure')) {
    function admission_fee_tables_ensure(PDO $pdo): void
    {
        // এই দুটো টেবিল সাধারণত admin/finance_admission_fees.php পেজ প্রথমবার ভিজিট করলে তৈরি
        // হয়ে যায়, কিন্তু কোনো ফ্রেশ ইনস্টলেশনে Student Admission ফর্মে আগে এলে যাতে
        // "Table doesn't exist" ফ্যাটাল এরর না দেয়, তাই এখানেও নিরাপদে ensure করা হচ্ছে
        // (finance_admission_fees.php-এর সাথে হুবহু একই স্কিমা)।
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS finance_admission_fee_settings (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS finance_admission_fee_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_id INT NOT NULL,
                ac_head_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                KEY idx_setting (setting_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

if (!function_exists('admission_fee_setting_exists')) {
    function admission_fee_setting_exists(PDO $pdo, int $session_id, int $class_id, int $shift_id, int $section_id, string $gender): bool
    {
        admission_fee_tables_ensure($pdo);
        $stmt = $pdo->prepare(
            "SELECT fs.id FROM finance_admission_fee_settings fs
             WHERE fs.session_id = ? AND fs.class_id = ?
               AND (fs.shift_id = 0 OR fs.shift_id = ?)
               AND (fs.section_id = 0 OR fs.section_id = ?)
               AND (fs.gender = '' OR fs.gender = ?)
               AND EXISTS (SELECT 1 FROM finance_admission_fee_items fi WHERE fi.setting_id = fs.id)
             LIMIT 1"
        );
        $stmt->execute([$session_id, $class_id, $shift_id, $section_id, $gender]);
        return (bool) $stmt->fetchColumn();
    }
}

/** সিস্টেমে (যেকোনো Session/Class-এর জন্য) আদৌ কোনো Admission Fees Setting তৈরি করা আছে কিনা — Add ফর্মের উপরে সতর্কবার্তা দেখানোর জন্য */
if (!function_exists('admission_fee_any_setting_exists')) {
    function admission_fee_any_setting_exists(PDO $pdo): bool
    {
        admission_fee_tables_ensure($pdo);
        return (bool) $pdo->query(
            "SELECT fs.id FROM finance_admission_fee_settings fs
             WHERE EXISTS (SELECT 1 FROM finance_admission_fee_items fi WHERE fi.setting_id = fs.id)
             LIMIT 1"
        )->fetchColumn();
    }
}

/** একটা Session+Class-এর জন্য Admission Fee কত (সেট করা না থাকলে 0.0 — মানে ফি লাগবে না) */
if (!function_exists('af_class_fee')) {
    function af_class_fee(PDO $pdo, int $session_id, int $class_id): float
    {
        $stmt = $pdo->prepare("SELECT admission_fee FROM admission_settings WHERE session_id = ? AND class_id = ?");
        $stmt->execute([$session_id, $class_id]);
        $v = $stmt->fetchColumn();
        return $v !== false && $v !== null ? (float) $v : 0.0;
    }
}

/** সব Session+Class-এর ফি একসাথে — Public ফর্মে JS ম্যাপ বানানোর জন্য (session_id-class_id => fee) */
if (!function_exists('af_all_class_fees')) {
    function af_all_class_fees(PDO $pdo): array
    {
        $map = [];
        $rows = $pdo->query("SELECT session_id, class_id, admission_fee FROM admission_settings WHERE admission_fee IS NOT NULL AND admission_fee > 0")->fetchAll();
        foreach ($rows as $r) {
            $map[$r['session_id'] . '-' . $r['class_id']] = (float) $r['admission_fee'];
        }
        return $map;
    }
}

/**
 * Approve হওয়া একটা claim কে আসল finance_fee_collections এ Paid এন্ট্রি হিসেবে বসানো —
 * Manual Approve (admin/admission_fee_claims.php) আর Auto callback (admission_payment_callback.php)
 * দুটো জায়গা থেকেই এই একই ফাংশন কল হয়, যাতে হিসাব সবসময় Finance Module-এর সাথে মিলে থাকে।
 */
if (!function_exists('af_post_to_finance')) {
    function af_post_to_finance(PDO $pdo, array $claim, string $payment_method): int
    {
        $gw = af_gateway_settings($pdo);
        $ac_head_id = (int) ($gw['ac_head_id'] ?? 0);
        if ($ac_head_id <= 0) {
            // Admin কোনো Account Head সিলেক্ট না করে থাকলে — ডিফল্ট "Admission Fee" নামের হেড খোঁজা হয়
            $h = $pdo->query("SELECT id FROM finance_account_heads WHERE ac_head LIKE '%Admission%' AND status='Active' ORDER BY id ASC LIMIT 1")->fetchColumn();
            $ac_head_id = $h ? (int) $h : 0;
        }
        if ($ac_head_id <= 0) { return 0; } // কোনো Account Head-ই নেই — পোস্ট করা যাবে না, claim শুধু Approved থাকবে

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS finance_fee_collections (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $folio_no = 'ADM' . date('ymd') . strtoupper(substr(uniqid(), -5));
        $ins = $pdo->prepare(
            "INSERT INTO finance_fee_collections (student_id, session_id, period_type, month, ac_head_id, amount, payment_method, trx_id, folio_no, collected_by, status)
             VALUES (?, ?, 'Admission', 0, ?, ?, ?, ?, ?, 'Online Admission', 'Paid')"
        );
        $ins->execute([
            $claim['student_pk'], $claim['session_id'], $ac_head_id, $claim['amount'],
            $payment_method, $claim['trx_id'], $folio_no,
        ]);
        return (int) $pdo->lastInsertId();
    }
}

/**
 * সাময়িকভাবে জমা রাখা (Pending) ফর্ম পেলোড থেকে আসল "Admission" — মানে students টেবিলে
 * আসল রো — বসানো হয় এই ফাংশন দিয়ে। submit_admission_form.php-এর আগের সরাসরি insert
 * লজিকটাই এখানে সরানো হয়েছে, যাতে দুটো জায়গা থেকেই একই লজিক (Student ID/Roll No জেনারেশন,
 * SMS ইত্যাদি) reuse হয়:
 *   ১) ফি লাগে না বা Manual পেমেন্ট বেছে নেওয়া হয়েছে — submit_admission_form.php থেকেই
 *      সরাসরি (ফর্ম সাবমিট করার সাথে সাথেই)।
 *   ২) Auto (অনলাইন গেটওয়ে) বেছে নেওয়া হয়েছে — admission_payment_callback.php থেকে,
 *      কিন্তু কেবল পেমেন্ট ভ্যালিডেট হওয়ার পরই (তাই সত্যিকারের "গেটওয়ে ফি নিয়ে তারপর সাবমিট")।
 *
 * $d এ ঠিক submit_admission_form.php-এর মতোই সব ফিল্ড থাকতে হবে (নিচের কী-গুলো)।
 * রিটার্ন করে ['new_id', 'student_id', 'roll_no', 'slip_token']।
 */
if (!function_exists('af_finalize_admission')) {
    function af_finalize_admission(PDO $pdo, array $d): array
    {
        require_once __DIR__ . '/sms.php';
        require_once __DIR__ . '/branch_scope.php';

        branch_ensure_column($pdo, 'students');

        $saf_bg_len = $pdo->query(
            "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'blood_group'"
        )->fetchColumn();
        if ($saf_bg_len !== false && (int) $saf_bg_len < 10) {
            $pdo->exec("ALTER TABLE students MODIFY COLUMN blood_group VARCHAR(10) NOT NULL");
        }

        $col_type = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'status'"
        )->fetchColumn();
        if ($col_type !== false && stripos((string) $col_type, 'pending') === false) {
            $pdo->exec("ALTER TABLE students MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Active'");
        }
        $tok_chk = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'admission_slip_token'"
        )->fetchColumn();
        if (!$tok_chk) {
            $pdo->exec("ALTER TABLE students ADD COLUMN admission_slip_token VARCHAR(64) NULL AFTER admission_id");
        }

        $session_id = (int) $d['session_id'];
        $class_id = (int) $d['class_id'];
        $section_id = (int) $d['section_id'];
        $shift_id = (int) $d['shift_id'];

        // Student ID অটো-জেনারেট — {Session শেষ ২ ডিজিট}{Class Code}{৩ ডিজিট সিরিয়াল}
        $session_name_stmt = $pdo->prepare("SELECT session_name FROM academic_sessions WHERE id = ?");
        $session_name_stmt->execute([$session_id]);
        $session_name = $session_name_stmt->fetchColumn() ?: '';
        $yy = substr(preg_replace('/[^0-9]/', '', $session_name), -2) ?: date('y');

        $class_row = $pdo->prepare("SELECT class_code FROM academic_classes WHERE id = ?");
        $class_row->execute([$class_id]);
        $class_code = $class_row->fetchColumn() ?: '00';
        $class_code = str_pad(substr($class_code, 0, 2), 2, '0', STR_PAD_LEFT);

        $prefix = $yy . $class_code;
        $last = $pdo->prepare("SELECT student_id FROM students WHERE student_id LIKE ? ORDER BY student_id DESC LIMIT 1");
        $last->execute([$prefix . '%']);
        $last_id = $last->fetchColumn();
        $serial = 1;
        if ($last_id && strlen($last_id) >= 3) {
            $serial = ((int) substr($last_id, -3)) + 1;
        }
        $student_id = $prefix . str_pad((string) $serial, 3, '0', STR_PAD_LEFT);

        // Roll No — শুধু Admission Type "General" হলেই এখনই বসানো হয়
        $adm_type_row = $pdo->query("SELECT admission_type FROM institute_settings WHERE id = 1")->fetch();
        $adm_type = $adm_type_row['admission_type'] ?? 'General';

        $roll_no = '';
        if ($adm_type === 'General') {
            $roll_stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM students WHERE session_id = ? AND class_id = ? AND section_id = ? AND shift_id = ?"
            );
            $roll_stmt->execute([$session_id, $class_id, $section_id, $shift_id]);
            $roll_no = (string) ($roll_stmt->fetchColumn() + 1);
        }

        $slip_token = bin2hex(random_bytes(20));

        $columns = [
            'session_id' => $session_id, 'shift_id' => $shift_id, 'class_id' => $class_id, 'section_id' => $section_id,
            'student_id' => $student_id, 'roll_no' => $roll_no, 'attendance_sms' => 1,
            'name_en' => $d['name_en'], 'name_bn' => $d['name_bn'] ?: null, 'dob' => $d['dob'] ?: null, 'birth_reg_no' => $d['birth_reg_no'] ?: null,
            'gender' => $d['gender'], 'orphan' => $d['orphan'] ?: null, 'disability_status' => $d['disability_status'] ?: null,
            'blood_group' => $d['blood_group'], 'religion' => $d['religion'], 'nationality' => $d['nationality'],
            'working_student' => $d['working_student'] ?: null, 'photo_path' => $d['photo_path'] ?? null,
            'mobile' => $d['mobile'] ?: null, 'email' => $d['email'] ?: null,
            'present_address' => $d['present_address'], 'permanent_address' => $d['permanent_address'],
            'prev_school_name' => $d['prev_school_name'] ?: null, 'prev_class_id' => $d['prev_class_id'] ?: null,
            'prev_section' => $d['prev_section'] ?: null, 'prev_roll' => $d['prev_roll'] ?: null, 'prev_result' => $d['prev_result'] ?: null,
            'father_name' => $d['father_name'], 'father_phone' => $d['father_phone'] ?: null, 'father_nid' => $d['father_nid'] ?: null,
            'father_profession' => $d['father_profession'] ?: null, 'father_photo_path' => $d['father_photo_path'] ?? null,
            'mother_name' => $d['mother_name'], 'mother_nid' => $d['mother_nid'] ?: null, 'mother_profession' => $d['mother_profession'] ?: null,
            'mother_photo_path' => $d['mother_photo_path'] ?? null,
            'yearly_income' => $d['yearly_income'] ?: null, 'landless' => $d['landless'] ?: null,
            'guardian_type' => 'Father', 'guardian_name' => $d['guardian_name'], 'guardian_phone' => $d['guardian_phone'],
            'status' => 'Pending', 'admission_slip_token' => $slip_token,
            'branch_id' => $d['branch_id'],
        ];
        $cols_sql = implode(', ', array_keys($columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $pdo->prepare("INSERT INTO students ($cols_sql) VALUES ($placeholders)")->execute(array_values($columns));
        $new_id = (int) $pdo->lastInsertId();

        // ---- Admission SMS (sms_settings.sms_admission অন থাকলে) ----
        $sms_settings = $pdo->query("SELECT sms_admission, content_admission FROM sms_settings WHERE id = 1")->fetch();
        if ($sms_settings && (int) ($sms_settings['sms_admission'] ?? 0) === 1) {
            $template = $sms_settings['content_admission'] ?: 'Dear guardian, {FIRST_NAME} {LAST_NAME} has been admitted to {SCHOOL_NAME} with Student ID {STUDENT_ID}. Roll: {ROLL}, Class: {CLASS}.';
            $school = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetch()['institute_name'] ?? '';
            $class_name_row = $pdo->prepare("SELECT class_name FROM academic_classes WHERE id = ?");
            $class_name_row->execute([$class_id]);
            $class_name = $class_name_row->fetchColumn() ?: '';

            $name_parts = explode(' ', trim($d['name_en']), 2);
            $message = sms_apply_template($template, [
                'FIRST_NAME' => $name_parts[0] ?? '', 'LAST_NAME' => $name_parts[1] ?? '',
                'SCHOOL_NAME' => $school, 'STUDENT_ID' => $student_id, 'ROLL' => $roll_no !== '' ? $roll_no : 'পরে জানানো হবে', 'CLASS' => $class_name,
            ]);
            $result = send_sms($pdo, $d['guardian_phone'], $message);

            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS sms_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NULL, phone VARCHAR(20) NOT NULL,
                    purpose VARCHAR(50) NOT NULL DEFAULT 'admission', message TEXT NULL,
                    status ENUM('Sent','Failed') NOT NULL DEFAULT 'Failed', response TEXT NULL,
                    sent_by VARCHAR(100) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $pdo->prepare(
                "INSERT INTO sms_logs (student_id, phone, purpose, message, status, response, sent_by) VALUES (?, ?, 'admission', ?, ?, ?, 'Online Admission')"
            )->execute([$new_id, $d['guardian_phone'], $message, $result['success'] ? 'Sent' : 'Failed', $result['response']]);
        }

        return ['new_id' => $new_id, 'student_id' => $student_id, 'roll_no' => $roll_no, 'slip_token' => $slip_token];
    }
}
