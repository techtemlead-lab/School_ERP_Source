<?php
/**
 * config/monthly_fee_helpers.php
 * -----------------------------------------------------------
 * Tuition Fee, Session Fee — Finance Module-এ যত রকম মাসভিত্তিক Account Head আছে,
 * সবগুলোর জন্য Student পোর্টাল থেকে এক সাথে "একটা মাসের সব ফি" অনলাইনে গেটওয়ে দিয়ে
 * পরিশোধ করার সাপোর্ট। Exam Fee-এর মতোই Admission Fee-এর সাথে শেয়ার করা একই Global
 * Payment Gateway (af_gateway_settings() — config/admission_fee_helpers.php) ব্যবহার হয়,
 * এখানে আলাদা কোনো Gateway Credential রাখা হয় না।
 *
 * হিসাব সবসময় admin/finance_due_report_common.php এর dr_resolve_period() থেকেই আসে,
 * যাতে Finance Module-এর Dues রিপোর্ট আর এখানকার হিসাব সবসময় মিলে যায়। পেমেন্ট সফল হলে
 * admin/finance_fees_collection.php এর ম্যানুয়াল কালেকশনের মতোই — প্রতিটা Account Head-এর
 * জন্য আলাদা আলাদা finance_fee_collections রো বসে (একই Folio No শেয়ার করে), যাতে
 * হেড-ওয়াইজ Dues সঠিকভাবে শূন্য হয়ে যায়।
 */

if (!function_exists('mf_ensure_tables')) {
    function mf_ensure_tables(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS monthly_fee_online_claims (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Manual bKash/Nagad claim-এর জন্য বাড়তি কলাম (Auto Gateway claim-এ ফাঁকাই থাকবে) —
        // method কলামে 'AutoGateway' | 'bKash' | 'Nagad' বসে।
        $extra_cols = [
            'sender_number' => "VARCHAR(30) NULL AFTER method",
            'note'          => "VARCHAR(255) NULL AFTER folio_no",
        ];
        $chk = function (string $col) use ($pdo): bool {
            return (bool) $pdo->query("SHOW COLUMNS FROM `monthly_fee_online_claims` LIKE " . $pdo->quote($col))->fetch();
        };
        foreach ($extra_cols as $col => $def) {
            if (!$chk($col)) {
                $pdo->exec("ALTER TABLE monthly_fee_online_claims ADD COLUMN `$col` $def");
            }
        }
    }
}

/**
 * একটা Student এর একটা নির্দিষ্ট মাসের জন্য সব Academic Account Head (Tuition/Session/ইত্যাদি)
 * মিলিয়ে মোট কত Due আছে — dr_resolve_period() দিয়ে হেড-ওয়াইজ হিসাব করে সরাসরি টোটাল করা হয়।
 */
if (!function_exists('mf_month_due')) {
    function mf_month_due(PDO $pdo, array $student, int $session_id, int $month): array
    {
        require_once __DIR__ . '/../admin/finance_due_report_common.php';
        require_once __DIR__ . '/admission_fee_helpers.php';
        dr_ensure_tables($pdo);
        af_ensure_tables($pdo);

        $fee_heads = $pdo->query(
            "SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC"
        )->fetchAll();

        // Global Settings > Payment Gateway Settings এ Admin যে হেডগুলো "অনলাইনে নেওয়া হবে"
        // বলে টিক দিয়েছেন শুধু সেগুলোই এখানে ধরা হয় (কিছু টিক না দিলে সব হেডই)।
        $allowed = af_online_head_ids(af_gateway_settings($pdo));
        if ($allowed) {
            $fee_heads = array_values(array_filter($fee_heads, fn($h) => in_array((int) $h['id'], $allowed, true)));
        }
        if (!$fee_heads) { return ['due' => 0.0, 'items' => []]; }

        $items = dr_resolve_period($pdo, (int) $student['id'], $session_id, (int) $student['class_id'], $month, $fee_heads);
        $due = array_sum(array_column($items, 'dues'));

        return ['due' => round($due, 2), 'items' => $items];
    }
}

/**
 * Verified (ভ্যালিডেটেড) একটা Auto Gateway পেমেন্টকে আসল finance_fee_collections এ Paid
 * এন্ট্রি হিসেবে বসানো — admin/finance_fees_collection.php এর ম্যানুয়াল কালেকশনের মতোই প্রতিটা
 * Account Head-এর জন্য আলাদা রো (একই Folio শেয়ার করে), যাতে হেড-ওয়াইজ Dues ঠিকভাবে মেটে।
 * পেমেন্ট থেকে callback আসা পর্যন্ত dues বদলে যেতে পারে (অন্য কোনোভাবে ইতিমধ্যে জমা হয়ে
 * গেলে) — তাই amount সবসময় সেই মুহূর্তের আসল dues এর সাথে ক্ল্যাম্প করে নেওয়া হয়, বেশি বসে না।
 */
if (!function_exists('mf_post_to_finance')) {
    function mf_post_to_finance(PDO $pdo, array $claim, string $payment_method): array
    {
        require_once __DIR__ . '/../admin/finance_due_report_common.php';
        dr_ensure_tables($pdo);

        $stu_stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? LIMIT 1");
        $stu_stmt->execute([$claim['student_id']]);
        $student = $stu_stmt->fetch();
        if (!$student) { return ['count' => 0, 'folio_no' => null]; }

        $info = mf_month_due($pdo, $student, (int) $claim['session_id'], (int) $claim['month']);

        $folio_no = $claim['folio_no'] ?: ('FEE' . date('ymd') . strtoupper(substr(uniqid(), -5)));

        $ins = $pdo->prepare(
            "INSERT INTO finance_fee_collections (student_id, session_id, period_type, month, ac_head_id, amount, payment_method, trx_id, folio_no, collected_by, status)
             VALUES (?, ?, 'Monthly', ?, ?, ?, ?, ?, ?, ?, 'Paid')"
        );

        $count = 0;
        foreach ($info['items'] as $it) {
            if ($it['ac_head_id'] <= 0 || $it['dues'] <= 0) { continue; }
            $ins->execute([
                $claim['student_id'], $claim['session_id'], $claim['month'], $it['ac_head_id'],
                $it['dues'], $payment_method, $claim['trx_id'], $folio_no, $payment_method,
            ]);
            $count++;
        }

        return ['count' => $count, 'folio_no' => $folio_no];
    }
}
