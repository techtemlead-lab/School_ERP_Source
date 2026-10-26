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

if (!function_exists('ea_print_header')) {
    /** প্রিন্টের হেডিং: প্রতিষ্ঠানের নাম, ঠিকানা, ফোন ও রিপোর্টের শিরোনাম। $always=true হলে স্ক্রিনেও দেখায়। */
    function ea_print_header(PDO $pdo, string $title, bool $always = true): void
    {
        $branch = []; $inst = [];
        try { $branch = $pdo->query("SELECT * FROM branches ORDER BY id ASC LIMIT 1")->fetch() ?: []; } catch (PDOException $e) {}
        try { $inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch() ?: []; } catch (PDOException $e) {}
        $name  = ($branch['branch_name'] ?? '') ?: ($inst['institute_name'] ?? '');
        $addr  = trim(($branch['address'] ?? '') . ', ' . ($branch['city'] ?? ''), ', ');
        $phone = ($branch['phone'] ?? '') ?: ($inst['phone_number'] ?? '');
        $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        echo '<div class="ea-print-header" style="text-align:center;margin-bottom:14px;' . ($always ? '' : 'display:none;') . '">';
        if ($name !== '')  { echo '<h4 style="color:#1e3a8a;font-weight:800;margin:0 0 4px;">' . $e($name) . '</h4>'; }
        if ($addr !== '')  { echo '<div style="font-size:12px;color:#555;">' . $e($addr) . '</div>'; }
        if ($phone !== '') { echo '<div style="font-size:12px;color:#555;">Phone: ' . $e($phone) . '</div>'; }
        echo '<div style="margin-top:8px;font-weight:700;font-size:14px;color:#334155;">' . $e($title) . '</div></div>';
        if (!$always) { echo '<style>@media print{.ea-print-header{display:block !important;}}</style>'; }
    }
}

if (!function_exists('ea_print_controls')) {
    /** Portrait/Landscape বেছে নেওয়ার ড্রপডাউন + Print বাটন (স্ক্রিনে দেখায়, প্রিন্টে লুকায়)। */
    function ea_print_controls(string $default = 'portrait'): void
    {
        $default = $default === 'landscape' ? 'landscape' : 'portrait';
        echo '<div class="d-flex justify-content-end align-items-center gap-2 mb-2 no-print">'
           . '<label class="small fw-bold mb-0" for="eaOrient">Page:</label>'
           . '<select id="eaOrient" class="form-select form-select-sm" style="width:auto;">'
           . '<option value="portrait"' . ($default === 'portrait' ? ' selected' : '') . '>Portrait</option>'
           . '<option value="landscape"' . ($default === 'landscape' ? ' selected' : '') . '>Landscape</option>'
           . '</select>'
           . '<button type="button" class="btn btn-sm btn-primary fw-bold" style="background-color:#1e3a8a;" onclick="eaPrint()"><i class="fa-solid fa-print"></i> Print</button>'
           . '</div>'
           . '<script>function eaPrint(){var o=document.getElementById("eaOrient").value;var s=document.getElementById("eaPageStyle");'
           . 'if(!s){s=document.createElement("style");s.id="eaPageStyle";document.head.appendChild(s);}'
           . 's.textContent="@page{size:A4 "+o+";margin:8mm;}";window.print();}</script>';
    }
}

if (!function_exists('ea_inst_name')) {
    function ea_inst_name(PDO $pdo, $branch): string
    {
        $n = is_array($branch) ? (string) ($branch['branch_name'] ?? '') : '';
        if ($n !== '') { return $n; }
        try { return (string) ($pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetchColumn() ?: ''); }
        catch (PDOException $e) { return ''; }
    }
}
