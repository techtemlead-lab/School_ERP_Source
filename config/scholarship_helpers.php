<?php
/**
 * config/scholarship_helpers.php
 * -----------------------------------------------------------
 * সরকারি/বোর্ড/এনজিও/স্কুল স্কলারশিপ (উপবৃত্তি ইত্যাদি) ম্যানেজমেন্টের জন্য শেয়ার্ড টেবিল/হেল্পার।
 * admin/scholarship_types.php, scholarship_award.php, scholarship_disbursement.php,
 * scholarship_report.php — সবগুলো ফাইল এটা ব্যবহার করে।
 *
 * ডিজাইন: একটা Award (কোন স্টুডেন্ট কোন স্কলারশিপে কত টাকা পাচ্ছে) → Disbursement (সেই টাকা
 * কীভাবে বণ্টন হলো — কতটুকু বকেয়া বেতনের সাথে সমন্বয় হলো, কতটুকু অভিভাবককে হাতে দেওয়া হলো)।
 * বকেয়ার সাথে সমন্বয় করা অংশ finance_fee_collections-এ Paid এন্ট্রি হিসেবে বসে (payment_method
 * = "Scholarship-<Type>"), তাই Finance-এর সব Due/Ledger রিপোর্টেও এটা স্বয়ংক্রিয়ভাবে প্রতিফলিত হয়।
 */

if (!function_exists('sch_ensure_tables')) {
    function sch_ensure_tables(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scholarship_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                category ENUM('Government','Board','NGO','School') NOT NULL DEFAULT 'Government',
                description TEXT NULL,
                status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scholarship_awards (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scholarship_disbursements (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Disbursement-এর সময় ঠিক কোন Head/Period-এ কতটুকু সমন্বয় হলো তার বিস্তারিত লাইন —
        // finance_fee_collections.id লিংক করা থাকে যাতে পরে চাইলে ট্রেস করা যায়
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scholarship_disbursement_lines (
                id INT AUTO_INCREMENT PRIMARY KEY,
                disbursement_id INT NOT NULL,
                ac_head_id INT NULL,
                period TINYINT NOT NULL DEFAULT 0,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                finance_collection_id INT NULL,
                KEY idx_disbursement (disbursement_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // স্কুলের নিজস্ব তহবিল থেকে দরিদ্র/চ্যারিটি ফান্ড হিসেবে সরাসরি নগদ সাহায্য — এটা ফি
        // ওয়েভার না, তাই income কমায় না, বরং Finance-এর একটা Payment Voucher (Expense) হিসেবে বসে।
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS poor_fund_aids (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

/** স্কলারশিপ ক্যাটাগরির বাংলা লেবেল */
if (!function_exists('sch_category_label')) {
    function sch_category_label(string $cat): string
    {
        return ['Government' => 'সরকারি', 'Board' => 'বোর্ড', 'NGO' => 'এনজিও', 'School' => 'স্কুল'][$cat] ?? $cat;
    }
}

/**
 * একটা Award-এর টাকা বকেয়ার সাথে সমন্বয় করার Waterfall লজিক — Admission আগে, তারপর মাস ১ থেকে
 * ১২ পর্যন্ত ক্রমানুসারে, প্রতিটার মধ্যে যে হেডগুলোতে Due আছে সেগুলোতে যতটুকু সম্ভব সমন্বয় করে,
 * বাকি টাকা (থাকলে) Cash Amount হিসেবে ফেরত দেয়। এতে Admin-কে ম্যানুয়ালি প্রতিটা হেড বেছে
 * নেওয়া লাগে না, অথচ Finance-এর Due Report-এর সাথে হিসাব সবসময় সঠিকভাবে মিলে যায়
 * (dr_resolve_period() — finance_due_report_common.php-এর একই ফাংশন ব্যবহার করে)।
 *
 * @return array{lines: array, adjusted_total: float, cash_amount: float}
 */
if (!function_exists('sch_waterfall_adjust')) {
    function sch_waterfall_adjust(PDO $pdo, int $student_id, int $session_id, int $class_id, float $available_amount, array $fee_heads): array
    {
        $lines = [];
        $remaining = round($available_amount, 2);

        foreach ([0 => true] + dr_months() as $period => $x) {
            if ($remaining <= 0) { break; }
            $items = dr_resolve_period($pdo, $student_id, $session_id, $class_id, (int) $period, $fee_heads);
            foreach ($items as $item) {
                if ($remaining <= 0) { break; }
                if ($item['dues'] <= 0) { continue; }
                $take = min($item['dues'], $remaining);
                $lines[] = ['ac_head_id' => $item['ac_head_id'], 'period' => (int) $period, 'amount' => round($take, 2), 'head_name' => $item['name']];
                $remaining = round($remaining - $take, 2);
            }
        }

        $adjusted_total = round(array_sum(array_column($lines, 'amount')), 2);
        $cash_amount = round($available_amount - $adjusted_total, 2);

        return ['lines' => $lines, 'adjusted_total' => $adjusted_total, 'cash_amount' => max(0, $cash_amount)];
    }
}

/** নিশ্চিত করা "Poor Fund / Charity Aid" নামের একটা Payments Account Head আছে — না থাকলে তৈরি করা */
if (!function_exists('sch_ensure_poor_fund_head')) {
    function sch_ensure_poor_fund_head(PDO $pdo): int
    {
        $existing = $pdo->query("SELECT id FROM finance_account_heads WHERE ac_head = 'Poor Fund / Charity Aid' LIMIT 1")->fetchColumn();
        if ($existing) { return (int) $existing; }

        $last_code = $pdo->query("SELECT ac_code FROM finance_account_heads WHERE ac_category = 'Payments' ORDER BY CAST(ac_code AS UNSIGNED) DESC LIMIT 1")->fetchColumn();
        $next_code = $last_code ? (string) ((int) $last_code + 1) : '101';

        // ac_code ইউনিক হতে হবে — কোনো কারণে কনফ্লিক্ট করলে টাইমস্ট্যাম্প যোগ করে দেওয়া হচ্ছে
        $chk = $pdo->prepare("SELECT id FROM finance_account_heads WHERE ac_code = ?");
        $chk->execute([$next_code]);
        if ($chk->fetch()) { $next_code = $next_code . '-' . substr((string) time(), -4); }

        $ins = $pdo->prepare(
            "INSERT INTO finance_account_heads (ac_type, ac_category, ac_code, ac_head, period_type, has_child, status)
             VALUES ('General', 'Payments', ?, 'Poor Fund / Charity Aid', 'Monthly', 'No', 'Active')"
        );
        $ins->execute([$next_code]);
        return (int) $pdo->lastInsertId();
    }
}

/** পরবর্তী Receipt No — SCH-YYYYMMDD-### / PF-YYYYMMDD-### ফরম্যাটে */
if (!function_exists('sch_next_receipt_no')) {
    function sch_next_receipt_no(PDO $pdo, string $table, string $prefix): string
    {
        $today_prefix = $prefix . '-' . date('Ymd') . '-';
        $stmt = $pdo->prepare("SELECT receipt_no FROM `$table` WHERE receipt_no LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$today_prefix . '%']);
        $last = $stmt->fetchColumn();
        $next = 1;
        if ($last) {
            $parts = explode('-', $last);
            $next = ((int) end($parts)) + 1;
        }
        return $today_prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
