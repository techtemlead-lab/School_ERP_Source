<?php
// admin/employee_attendance_common.php
// Employee Attendance রিপোর্টগুলোর (Daily/Monthly/Details/Dashboard) শেয়ার্ড হেল্পার।
// ডিভাইসের সেভ করা punch_type ('in'/'out') ধরা হয় না — দিনের সব পাঞ্চ সময় ধরে:
// প্রথম = IN, শেষ = OUT (২টার বেশি/২টা হলে), মাঝেরগুলো = Middle Punch।
// ম্যাচিং: rfid_card_no / employee_id / tracking_id / device_serial — চারটার যেকোনোটা।

if (!function_exists('ea_uids')) {
    function ea_uids(array $emp): array
    {
        $u = [];
        foreach (['rfid_card_no', 'employee_id', 'tracking_id', 'device_serial'] as $k) {
            $v = trim((string) ($emp[$k] ?? ''));
            if ($v !== '') { $u[$v] = true; }
        }
        return array_map('strval', array_keys($u));
    }
}

if (!function_exists('ea_clean_times')) {
    /** সময়গুলো সাজিয়ে ডুপ্লিকেট/৬০ সেকেন্ডের মধ্যের দ্বিতীয় পাঞ্চ বাদ দেয় */
    function ea_clean_times(array $times): array
    {
        $times = array_values(array_unique(array_filter($times)));
        sort($times);
        $kept = []; $last = null;
        foreach ($times as $t) {
            $ts = strtotime($t);
            if ($last !== null && ($ts - $last) < 60) { continue; }
            $kept[] = $t; $last = $ts;
        }
        return $kept;
    }
}

if (!function_exists('ea_day_times')) {
    function ea_day_times(array $times): array
    {
        $t = ea_clean_times($times);
        if (!$t) { return ['in' => null, 'out' => null, 'middle' => []]; }
        return [
            'in'     => $t[0],
            'out'    => count($t) > 1 ? end($t) : null,
            'middle' => count($t) > 2 ? array_slice($t, 1, -1) : [],
        ];
    }
}

if (!function_exists('ea_fetch_punches')) {
    /**
     * [from, to) সময়সীমায় এই কর্মীর সব পাঞ্চ — ['Y-m-d' => [সময়...]]।
     * ডিভাইস লগ + employee_daily_attendance এ সেভ করা check_in/out সময় (ম্যানুয়াল/সিঙ্ক) একসাথে।
     */
    function ea_fetch_punches(PDO $pdo, array $emp, string $from_date, string $to_date_excl): array
    {
        $by_day = [];
        $uids = ea_uids($emp);
        if ($uids) {
            try {
                $in = implode(',', array_fill(0, count($uids), '?'));
                $st = $pdo->prepare("SELECT punch_time FROM device_attendance_logs
                                     WHERE person_type = 'employee' AND person_uid IN ($in) AND punch_time >= ? AND punch_time < ?
                                     ORDER BY punch_time ASC");
                $st->execute(array_merge($uids, [$from_date, $to_date_excl]));
                foreach ($st->fetchAll(PDO::FETCH_COLUMN, 0) as $t) { $by_day[substr($t, 0, 10)][] = $t; }
            } catch (PDOException $e) { /* টেবিল না থাকলেও রিপোর্ট চলবে */ }
        }
        try {
            $st = $pdo->prepare("SELECT attendance_date, check_in_time, check_out_time FROM employee_daily_attendance
                                 WHERE employee_id = ? AND attendance_date >= ? AND attendance_date < ?");
            $st->execute([(int) $emp['id'], $from_date, $to_date_excl]);
            foreach ($st->fetchAll() as $r) {
                foreach (['check_in_time', 'check_out_time'] as $c) {
                    if (!empty($r[$c])) { $by_day[$r['attendance_date']][] = $r[$c]; }
                }
            }
        } catch (PDOException $e) { /* ignore */ }
        return $by_day;
    }
}

if (!function_exists('ea_fmt_time')) {
    function ea_fmt_time($t): string { return $t ? date('h:i A', strtotime($t)) : ''; }
}
