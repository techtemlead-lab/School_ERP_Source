<?php
require_once __DIR__ . '/../config/fees_period_resolver.php'; // sfc_pick_fee_setting() — Student-এর Section/Shift/Gender মিলিয়ে সঠিক Fee Setting বাছার শেয়ার্ড হেল্পার
// admin/finance_due_report_common.php - Students Due Report (Level 1/2/3) এর জন্য শেয়ার্ড হেল্পার
// এই ফাইলটা কোনো রুটেবল পেজ না — finance_students_dues.php / finance_students_due_details.php /
// finance_students_due_month_details.php তিনটাতেই require হয়।

if (!function_exists('dr_ensure_tables')) {
    function dr_ensure_tables(PDO $pdo): void
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
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sms_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NULL,
                phone VARCHAR(20) NOT NULL,
                purpose VARCHAR(50) NOT NULL DEFAULT 'due',
                message TEXT NULL,
                status ENUM('Sent','Failed') NOT NULL DEFAULT 'Failed',
                response TEXT NULL,
                sent_by VARCHAR(100) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

if (!function_exists('dr_months')) {
    function dr_months(): array
    {
        return [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
            7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];
    }
}

/**
 * একটা Student + একটা Period (0 = Admission, 1-12 = মাস)-এর জন্য হেড-ওয়াইজ
 * Dues/Waived/Paid/Payable রিসলভ করা — finance_fees_collection.php এর
 * sfc_resolve_period() এর সাথে হুবহু একই লজিক, যাতে সব জায়গায় হিসাব মিলে যায়।
 */
if (!function_exists('dr_resolve_period')) {
    function dr_resolve_period(PDO $pdo, int $student_id, int $session_id, int $class_id, int $period, array $fee_heads): array
    {
        $base_map = [];
        if ($period === 0) {
            $a = $pdo->prepare("SELECT ac_head_id, amount FROM finance_student_admission_fees WHERE student_id = ? AND session_id = ?");
            $a->execute([$student_id, $session_id]);
            $rows = $a->fetchAll();
            if ($rows) {
                foreach ($rows as $r) { $base_map[(int) $r['ac_head_id']] = (float) $r['amount']; }
            } else {
                $csid = sfc_pick_fee_setting($pdo, 'finance_admission_fee_settings', $student_id, $session_id, $class_id);
                if ($csid) {
                    $ci = $pdo->prepare("SELECT ac_head_id, amount FROM finance_admission_fee_items WHERE setting_id = ?");
                    $ci->execute([$csid]);
                    foreach ($ci->fetchAll() as $r) { $base_map[(int) $r['ac_head_id']] = (float) $r['amount']; }
                }
            }
        } else {
            $m = $pdo->prepare("SELECT ac_head_id, amount FROM finance_student_monthly_fees WHERE student_id = ? AND session_id = ? AND month = ?");
            $m->execute([$student_id, $session_id, $period]);
            $rows = $m->fetchAll();
            if ($rows) {
                foreach ($rows as $r) { $base_map[(int) $r['ac_head_id']] = (float) $r['amount']; }
            } else {
                $csid = sfc_pick_fee_setting($pdo, 'finance_monthly_fee_settings', $student_id, $session_id, $class_id);
                if ($csid) {
                    $ci = $pdo->prepare("SELECT ac_head_id, amount FROM finance_monthly_fee_items WHERE setting_id = ? AND month = ?");
                    $ci->execute([$csid, $period]);
                    foreach ($ci->fetchAll() as $r) { $base_map[(int) $r['ac_head_id']] = (float) $r['amount']; }
                }
            }
        }

        // period = 0 (Admission)-এর জন্যও ওয়েভার লুকআপ হবে — "if ($period > 0)" গার্ড থাকায়
        // Admission Fee-এর ওয়েভার Due Report-এ আগে কখনো ধরা পড়তো না
        $waiver_map = [];
        $w = $pdo->prepare("SELECT ac_head_id, waiver_type, waiver_value FROM finance_student_waivers WHERE student_id = ? AND session_id = ? AND month = ? AND status = 'Active'");
        $w->execute([$student_id, $session_id, $period]);
        foreach ($w->fetchAll() as $r) {
            $waiver_map[(int) $r['ac_head_id']] = ['type' => $r['waiver_type'], 'value' => (float) $r['waiver_value']];
        }

        $period_type = $period === 0 ? 'Admission' : 'Monthly';
        $paid_map = [];
        $p = $pdo->prepare("SELECT ac_head_id, COALESCE(SUM(amount),0) AS paid_amt FROM finance_fee_collections WHERE student_id = ? AND session_id = ? AND period_type = ? AND month = ? GROUP BY ac_head_id");
        $p->execute([$student_id, $session_id, $period_type, $period]);
        foreach ($p->fetchAll() as $r) {
            if ($r['ac_head_id'] === null) { continue; }
            $paid_map[(int) $r['ac_head_id']] = (float) $r['paid_amt'];
        }

        $items = [];
        foreach ($fee_heads as $fh) {
            $hid = (int) $fh['id'];
            if (!isset($base_map[$hid]) || $base_map[$hid] <= 0) { continue; }
            $base = $base_map[$hid];
            $waived = 0.0;
            if (isset($waiver_map[$hid])) {
                $waived = $waiver_map[$hid]['type'] === 'Percentage'
                    ? round($base * $waiver_map[$hid]['value'] / 100, 2)
                    : $waiver_map[$hid]['value'];
                $waived = min($waived, $base);
            }
            $payable = round($base - $waived, 2);
            $paid = $paid_map[$hid] ?? 0.0;
            $dues = max(0, round($payable - $paid, 2));
            $items[] = ['ac_head_id' => $hid, 'name' => trim($fh['ac_code'] . ' ' . $fh['ac_head']), 'base' => $base, 'waived' => $waived, 'paid' => $paid, 'payable' => $payable, 'dues' => $dues];
        }

        return $items;
    }
}

/**
 * একটা Student-এর পুরো সেশনের সামারি — প্রতিটা মাসের Due + Admission Due +
 * Till Current / Future Payable / Total Collection (গ্রস, সারা বছরের এসেসড ফি, Paid বাদ না দিয়ে)।
 */
if (!function_exists('dr_student_summary')) {
    function dr_student_summary(PDO $pdo, array $student, int $session_id, array $fee_heads, int $current_month): array
    {
        $class_id = (int) $student['class_id'];
        $sid = (int) $student['id'];

        $admission_items = dr_resolve_period($pdo, $sid, $session_id, $class_id, 0, $fee_heads);
        $admission_due = array_sum(array_column($admission_items, 'dues'));
        $admission_payable = array_sum(array_column($admission_items, 'payable'));

        $month_dues = [];
        $month_payables = [];
        foreach (dr_months() as $m => $name) {
            $items = dr_resolve_period($pdo, $sid, $session_id, $class_id, $m, $fee_heads);
            $month_dues[$m] = array_sum(array_column($items, 'dues'));
            $month_payables[$m] = array_sum(array_column($items, 'payable'));
        }

        $till_current = 0.0;
        $future_payable = 0.0;
        foreach ($month_dues as $m => $due) {
            if ($m <= $current_month) { $till_current += $due; } else { $future_payable += $due; }
        }

        $total_collection = $admission_payable + array_sum($month_payables);
        $total_due = $admission_due + array_sum($month_dues);

        return [
            'admission_due' => $admission_due,
            'month_dues'    => $month_dues,
            'till_current'  => $till_current,
            'future_payable'=> $future_payable,
            'total_collection' => $total_collection,
            'total_due'     => $total_due,
        ];
    }
}

/**
 * একটা Session-এর প্রতিটা Active Class-এর Active শিক্ষার্থীদের সব মিলিয়ে মোট Due —
 * finance_balance_sheet.php (Current Assets: Dues from Students) ও
 * finance_payable_receivable.php (Receivable) — দুই জায়গাতেই শেয়ার্ড, যাতে সংখ্যা মিলে যায়।
 * শুধু যেসব ক্লাসে Due > 0 আছে সেগুলোই রিটার্ন করে।
 */
if (!function_exists('dr_dues_by_class')) {
    function dr_dues_by_class(PDO $pdo, int $session_id, array $fee_heads, int $current_month): array
    {
        if ($session_id <= 0) { return []; }

        $classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();

        $stu_where = ['session_id = ?', "status = 'Active'"];
        $stu_params = [$session_id];
        branch_apply_filter($stu_where, $stu_params);
        $stmt = $pdo->prepare("SELECT id, class_id FROM students WHERE " . implode(' AND ', $stu_where));
        $stmt->execute($stu_params);
        $all_students = $stmt->fetchAll();

        $by_class = [];
        foreach ($all_students as $stu) {
            $summary = dr_student_summary($pdo, $stu, $session_id, $fee_heads, $current_month);
            $cid = (int) $stu['class_id'];
            // চলতি মাস পর্যন্ত Due (Admission Due + Jan..চলতি মাস) — পুরো বছরের নয়
            $by_class[$cid] = ($by_class[$cid] ?? 0.0) + (float) $summary['admission_due'] + (float) $summary['till_current'];
        }

        $result = [];
        foreach ($classes as $cl) {
            $cid = (int) $cl['id'];
            if (!empty($by_class[$cid]) && $by_class[$cid] > 0) {
                $result[] = ['class_id' => $cid, 'class_name' => $cl['class_name'], 'due' => $by_class[$cid]];
            }
        }
        return $result;
    }
}
