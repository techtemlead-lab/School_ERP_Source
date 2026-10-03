<?php
/**
 * config/exam_fee_helpers.php
 * -----------------------------------------------------------
 * Exam Fee এখন আলাদা কোনো সিস্টেম না — Finance Module-এরই একটা Account Head
 * (যেমন "Exam Fee") আর সেই হেডের Monthly Fee Setting-এর amount দিয়ে চলে।
 * এই ফাইল শুধু বুঝিয়ে দেয় কোন Exam Term কোন মাসের কোন Account Head-এর সাথে
 * সংযুক্ত (exam_terms.fee_month + exam_terms.exam_fee_head_id), আর সেই ভিত্তিতে
 * Finance Module-এর নিজস্ব dr_resolve_period() হেল্পার (finance_due_report_common.php)
 * দিয়ে Due/Paid হিসাব করে।
 *
 * Fee জমা (Collection) সবসময় Finance Module > Fees Collection পেজ থেকেই হবে —
 * এই ফাইল শুধু bKash/Nagad-এ Student নিজে জমা দেওয়া Transaction ID-এর একটা
 * "Pending Review" queue রাখে; Admin Approve করলে সরাসরি আসল
 * finance_fee_collections টেবিলে একটা Paid এন্ট্রি হয়ে যায় (Finance Module-এর
 * বাকি সব রিপোর্ট/লেজারেও তখন এটা হিসাবে চলে আসবে)।
 */

if (!function_exists('ef_ensure_columns')) {
    function ef_ensure_columns(PDO $pdo): void
    {
        // finance_account_heads টেবিলটা Finance Module-এর (admin/finance_head.php স্বাভাবিকভাবে
        // এটা তৈরি করে), কিন্তু Exam Fee সংক্রান্ত এই পেজগুলো (Exam Terms, Exam Fee Online Payments)
        // সরাসরি এই টেবিল থেকে SELECT করে — Finance Module কখনো ভিজিট করা না হলে এই টেবিলটাই
        // থাকে না, ফলে সরাসরি Result Module থেকে ঢুকলে Fatal Error হয়ে যেত। তাই এখানেও একই
        // স্ট্রাকচারে নিরাপদে auto-create করা হচ্ছে (finance_head.php এর সাথে হুবহু মিলিয়ে)।
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS finance_account_heads (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $chk = function (string $table, string $col) use ($pdo): bool {
            return (bool) $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch();
        };
        if (!$chk('exam_terms', 'fee_month')) {
            $pdo->exec("ALTER TABLE exam_terms ADD COLUMN fee_month TINYINT NULL AFTER term_name");
        }
        if (!$chk('exam_terms', 'exam_fee_head_id')) {
            $pdo->exec("ALTER TABLE exam_terms ADD COLUMN exam_fee_head_id INT NULL AFTER fee_month");
        }
    }
}

if (!function_exists('ef_ensure_tables')) {
    function ef_ensure_tables(PDO $pdo): void
    {
        ef_ensure_columns($pdo);

        // Exam Seat Plan এর Room তথ্য (Admin জেনারেট করলে সেভ হয়, Student পোর্টাল থেকে পড়া হয়)
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS exam_seat_rooms (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // স্কুলের নিজের bKash/Nagad নাম্বার (Manual) — Student পোর্টাল থেকে ম্যানুয়াল অনলাইন পেমেন্টের জন্য
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS exam_fee_gateway_settings (
                id INT PRIMARY KEY DEFAULT 1,
                is_enabled TINYINT(1) NOT NULL DEFAULT 0,
                bkash_number VARCHAR(30) NULL,
                bkash_type VARCHAR(20) NOT NULL DEFAULT 'Personal',
                nagad_number VARCHAR(30) NULL,
                nagad_type VARCHAR(20) NOT NULL DEFAULT 'Personal',
                instructions TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // নোট: Auto (অনলাইন গেটওয়ে — SSLCommerz/ShurjoPay/PayStation/EPS) এর Credential এখানে
        // আলাদাভাবে রাখা হয় না — পুরো সিস্টেমে (Admission Fee + Exam Fee + ভবিষ্যতে অন্য যা কিছু)
        // একটাই Global Payment Gateway থাকে, যা admission_fee_gateway_settings টেবিলে (Global
        // Settings > Payment Gateway Settings পেজ থেকে) সেট করা হয় — af_gateway_settings() /
        // af_auto_gateway_ready() / af_provider_label() (config/admission_fee_helpers.php) দিয়ে
        // ব্যবহার করা হয়। এখানে শুধু ম্যানুয়াল bKash/Nagad নাম্বার (Exam Fee এর নিজস্ব) থাকে।

        // Student নিজে bKash/Nagad এ পাঠানো Transaction ID জমা দিলে, বা Auto Gateway দিয়ে পে করলে
        // এখানে একটা এন্ট্রি হয় (Manual হলে "Pending" — Admin Approve করলে; Auto হলে callback
        // ভ্যালিডেশনের পরপরই সাথে সাথে "Approved") — finance_fee_collections এ আসল Paid এন্ট্রি বসে যায় (নিচে দেখুন)
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS exam_fee_online_claims (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Auto Gateway এর জন্য: গেটওয়ের নিজস্ব validation id + কোন finance_fee_collections
        // এন্ট্রিতে পোস্ট হলো তার লিংক — Manual bKash/Nagad claim গুলোতে এগুলো ফাঁকাই থাকবে।
        // status ENUM এ 'Rejected' আছে কিন্তু Auto গেটওয়ের ব্যর্থ পেমেন্টের জন্য 'Rejected' ব্যবহার
        // করা হয় (আলাদা 'Failed' ভ্যালু ছাড়াই — Rejected মানেই "এই claim দিয়ে ফি পরিশোধ হয়নি")
        $claim_cols = [
            'gateway_val_id' => "VARCHAR(100) NULL AFTER trx_id",
            'finance_collection_id' => "INT NULL AFTER verified_at",
        ];
        $chk4 = function (string $col) use ($pdo): bool {
            return (bool) $pdo->query("SHOW COLUMNS FROM `exam_fee_online_claims` LIKE " . $pdo->quote($col))->fetch();
        };
        foreach ($claim_cols as $col => $def) {
            if (!$chk4($col)) {
                $pdo->exec("ALTER TABLE exam_fee_online_claims ADD COLUMN `$col` $def");
            }
        }
    }
}

/**
 * Gateway (ম্যানুয়াল bKash/Nagad) সেটিংস — এখন এই ফাংশনও পুরোপুরি Global Payment Gateway
 * Settings (admission_fee_gateway_settings টেবিল, af_gateway_settings() — config/admission_fee_helpers.php)
 * থেকেই ম্যানুয়াল bKash/Nagad নাম্বার নিয়ে আসে। Exam Fee এর নিজস্ব exam_fee_gateway_settings
 * টেবিলটা আর ব্যবহার হয় না (ব্যাকওয়ার্ড-কম্প্যাটিবিলিটির জন্য টেবিলটা থেকে যায়, কিন্তু কোনো
 * ডাটা এখান থেকে পড়া হয় না) — এক জায়গায় (Global Settings > Payment Gateway Settings) Auto/Manual
 * সেট করলেই Admission Fee, Exam Fee, Monthly Fee — সব জায়গায় একই সেটিংস কাজ করে।
 * ef_gateway_settings() নামটা রাখা হয়েছে যাতে admit_card.php/seat_plan.php ইত্যাদি কোনো
 * পরিবর্তন ছাড়াই আগের মতোই কল করতে পারে।
 */
if (!function_exists('ef_gateway_settings')) {
    function ef_gateway_settings(PDO $pdo): array
    {
        require_once __DIR__ . '/admission_fee_helpers.php';
        af_ensure_tables($pdo);
        $g = af_gateway_settings($pdo);
        return [
            'is_enabled'   => (int) ($g['manual_enabled'] ?? 0),
            'bkash_number' => $g['bkash_number'] ?? '',
            'bkash_type'   => $g['bkash_type'] ?? 'Personal',
            'nagad_number' => $g['nagad_number'] ?? '',
            'nagad_type'   => $g['nagad_type'] ?? 'Personal',
            'instructions' => $g['manual_instructions'] ?? '',
        ];
    }
}

/**
 * Approve হওয়া (Verified) একটা Auto Gateway পেমেন্টকে আসল finance_fee_collections এ Paid
 * এন্ট্রি হিসেবে বসানো — admin/result_exam_fee_collection.php (Manual Approve) এর মতোই একই
 * ফলাফল, যাতে Finance Module-এর হিসাব সবসময় মিলে থাকে এবং Admit Card সাথে সাথে আনলক হয়।
 */
if (!function_exists('ef_post_to_finance')) {
    function ef_post_to_finance(PDO $pdo, array $claim, string $payment_method): int
    {
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

        $ins = $pdo->prepare(
            "INSERT INTO finance_fee_collections (student_id, session_id, period_type, month, ac_head_id, amount, payment_method, trx_id, collected_by, status)
             VALUES (?, ?, 'Monthly', ?, ?, ?, ?, ?, ?, 'Paid')"
        );
        $ins->execute([
            $claim['student_id'], $claim['session_id'], $claim['month'], $claim['ac_head_id'],
            $claim['amount'], $payment_method, $claim['trx_id'], $payment_method,
        ]);
        return (int) $pdo->lastInsertId();
    }
}

/** একটা Exam Term কোন মাস + কোন Account Head এর সাথে যুক্ত করা আছে */
if (!function_exists('ef_term_fee_config')) {
    function ef_term_fee_config(PDO $pdo, int $term_id): array
    {
        $stmt = $pdo->prepare(
            "SELECT exam_terms.fee_month, exam_terms.exam_fee_head_id, finance_account_heads.ac_head, finance_account_heads.ac_code
             FROM exam_terms
             LEFT JOIN finance_account_heads ON finance_account_heads.id = exam_terms.exam_fee_head_id
             WHERE exam_terms.id = ? LIMIT 1"
        );
        $stmt->execute([$term_id]);
        $row = $stmt->fetch();
        return [
            'fee_month'   => $row ? (int) $row['fee_month'] : 0,
            'ac_head_id'  => $row ? (int) $row['exam_fee_head_id'] : 0,
            'ac_head'     => $row['ac_head'] ?? '',
            'ac_code'     => $row['ac_code'] ?? '',
        ];
    }
}

/**
 * একটা Student এই Term-এর Exam Fee পরিশোধ করেছে কিনা — সরাসরি Finance Module-এর
 * dr_resolve_period() (finance_due_report_common.php) ব্যবহার করে হিসাব করা হয়, যাতে
 * Finance-এর Dues রিপোর্ট আর এখানকার হিসাব সবসময় মিলে যায়।
 * Term-এ Fee Month/Account Head সেট করা না থাকলে সবসময় eligible (কোনো গেট নেই)।
 */
if (!function_exists('ef_is_eligible')) {
    function ef_is_eligible(PDO $pdo, int $session_id, int $term_id, int $class_id, int $student_id): array
    {
        $cfg = ef_term_fee_config($pdo, $term_id);
        if ($cfg['fee_month'] <= 0 || $cfg['ac_head_id'] <= 0) {
            return ['eligible' => true, 'amount' => 0.0, 'dues' => 0.0, 'fee_month' => 0, 'ac_head_id' => 0, 'ac_head' => '', 'claim' => null];
        }

        require_once __DIR__ . '/../admin/finance_due_report_common.php';
        dr_ensure_tables($pdo);

        $head_row = [['id' => $cfg['ac_head_id'], 'ac_code' => $cfg['ac_code'], 'ac_head' => $cfg['ac_head']]];
        $items = dr_resolve_period($pdo, $student_id, $session_id, $class_id, $cfg['fee_month'], $head_row);

        // এই ক্লাস/মাসের জন্য এই হেডে কোনো amount সেট করা না থাকলে (base <= 0) dr_resolve_period
        // কিছু রিটার্ন করে না — তার মানে ফি ধরাই হয়নি, তাই সরাসরি eligible
        if (!$items) {
            return ['eligible' => true, 'amount' => 0.0, 'dues' => 0.0, 'fee_month' => $cfg['fee_month'], 'ac_head_id' => $cfg['ac_head_id'], 'ac_head' => $cfg['ac_head'], 'claim' => null];
        }

        $item = $items[0];
        $eligible = $item['dues'] <= 0;

        $claim = null;
        if (!$eligible) {
            $c_stmt = $pdo->prepare("SELECT * FROM exam_fee_online_claims WHERE term_id = ? AND student_id = ? LIMIT 1");
            $c_stmt->execute([$term_id, $student_id]);
            $claim = $c_stmt->fetch() ?: null;
        }

        return [
            'eligible'   => $eligible,
            'amount'     => $item['payable'],
            'dues'       => $item['dues'],
            'fee_month'  => $cfg['fee_month'],
            'ac_head_id' => $cfg['ac_head_id'],
            'ac_head'    => $cfg['ac_head'],
            'claim'      => $claim,
        ];
    }
}
