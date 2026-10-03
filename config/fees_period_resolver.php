<?php
/**
 * config/fees_period_resolver.php
 *
 * একটা Student + একটা Period (Admission = 0, অথবা মাস 1-12)-এর জন্য হেড-ওয়াইজ
 * Dues / Waived / Paid / Payable রিসলভ করার শেয়ার্ড হেল্পার।
 *
 * আগে এই ফাংশনটা শুধু admin/finance_fees_collection.php-এর ভেতরে ছিল। এখন
 * admin/ajax/fees_dues_preview_ajax.php (মাসে ক্লিক করলেই ডানপাশে লাইভ Dues Summary)
 * থেকেও এটা লাগে, তাই একই লজিক দুই জায়গায় কপি না রেখে এখানে সরিয়ে আনা হয়েছে —
 * দুই জায়গার হিসাব সবসময় হুবহু এক থাকবে।
 */

/**
 * একটা Student-এর জন্য কোন Fee Setting (Monthly / Admission) প্রযোজ্য সেটা বেছে নেওয়া।
 *
 * আগে সব জায়গায় শুধু Session+Class মিলিয়ে "প্রথম Setting" (ORDER BY id LIMIT 1) নেওয়া হতো — ফলে একই Class-এ
 * Section/Shift/Gender/Applicability ভেদে একাধিক Setting থাকলে (যেমন FIVE-A আলাদা, FIVE-C আলাদা) সবাই প্রথমটার
 * ফিই পেত; পরের Setting-এ যোগ করা Fee (যেমন Exam Fee) Collection-এ কখনোই আসত না।
 *
 * এখন Student-এর নিজের Shift/Section/Gender মিলিয়ে বাছা হয় (Admission Fees / Student Import-এ যে নিয়মে মিল হয় তাই):
 *   Setting-এর shift_id/section_id = 0 (সব) অথবা Student-এর সাথে মেলে, gender ফাঁকা (সব) অথবা মেলে।
 * একাধিক মিললে যেটা বেশি নির্দিষ্ট (Section > Shift > Gender) সেটা; সমান হলে Monthly-তে Applicability
 * (All > ExceptDiscount > New), তারপর ছোট id। কোনোটাই না মিললে আগের মতো প্রথম Setting-এ ফিরে যায় (কিছু ভাঙে না)।
 */
if (!function_exists('sfc_pick_fee_setting')) {
    function sfc_pick_fee_setting(PDO $pdo, string $table, int $student_id, int $session_id, int $class_id): int
    {
        if (!in_array($table, ['finance_monthly_fee_settings', 'finance_admission_fee_settings'], true)) { return 0; }
        static $stu_cache = [];
        if (!array_key_exists($student_id, $stu_cache)) {
            try {
                $st = $pdo->prepare("SELECT shift_id, section_id, gender FROM students WHERE id = ?");
                $st->execute([$student_id]);
                $stu_cache[$student_id] = $st->fetch() ?: [];
            } catch (PDOException $e) {
                $stu_cache[$student_id] = [];
            }
        }
        $stu = $stu_cache[$student_id];
        $shift = (int) ($stu['shift_id'] ?? 0);
        $section = (int) ($stu['section_id'] ?? 0);
        $gender = (string) ($stu['gender'] ?? '');

        $q = $pdo->prepare("SELECT * FROM `$table` WHERE session_id = ? AND class_id = ? ORDER BY id ASC");
        $q->execute([$session_id, $class_id]);
        $rows = $q->fetchAll();
        if (!$rows) { return 0; }

        $app_rank = ['All' => 2, 'ExceptDiscount' => 1, 'New' => 0];
        $best_id = 0; $best_score = -1;
        foreach ($rows as $r) {
            $r_shift = (int) ($r['shift_id'] ?? 0);
            $r_sec = (int) ($r['section_id'] ?? 0);
            $r_gender = (string) ($r['gender'] ?? '');
            if (($r['status'] ?? 'Active') === 'Inactive') { continue; }
            if ($r_shift !== 0 && $r_shift !== $shift) { continue; }
            if ($r_sec !== 0 && $r_sec !== $section) { continue; }
            if ($r_gender !== '' && strcasecmp($r_gender, $gender) !== 0) { continue; }
            $score = ($r_sec ? 400 : 0) + ($r_shift ? 200 : 0) + ($r_gender !== '' ? 100 : 0);
            if ($table === 'finance_monthly_fee_settings') { $score += $app_rank[$r['applicability'] ?? 'New'] ?? 0; }
            if ($score > $best_score) { $best_score = $score; $best_id = (int) $r['id']; } // সমান হলে ছোট id-ই থাকে (id ASC)
        }
        return $best_id ?: (int) $rows[0]['id']; // কোনোটাই না মিললে পুরনো আচরণ: প্রথম Setting
    }
}

if (!function_exists('sfc_resolve_period')) {
    function sfc_resolve_period(PDO $pdo, int $student_id, int $session_id, int $class_id, int $period, array $fee_heads): array
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

        // period = 0 (Admission)-এর জন্যও ওয়েভার এপ্লাই হবে — আগে এখানে "if ($period > 0)" গার্ড
        // থাকায় Admission Fee-এর উপর ওয়েভার দেওয়া থাকলেও এই পেজে সেটা কখনো দেখাতো/এপ্লাই হতো না।
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
        $lump_paid = 0.0;
        foreach ($p->fetchAll() as $r) {
            if ($r['ac_head_id'] === null) { $lump_paid += (float) $r['paid_amt']; continue; }
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
            $items[] = ['ac_head_id' => $hid, 'name' => trim($fh['ac_code'] . ' ' . $fh['ac_head']), 'waived' => $waived, 'paid' => $paid, 'payable' => $payable, 'dues' => $dues];
        }

        if ($lump_paid > 0) {
            $items[] = ['ac_head_id' => 0, 'name' => 'Already Collected (Quick Collection)', 'waived' => 0, 'paid' => $lump_paid, 'payable' => 0, 'dues' => 0];
        }

        return $items;
    }
}
