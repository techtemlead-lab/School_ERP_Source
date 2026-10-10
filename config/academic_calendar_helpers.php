<?php
// config/academic_calendar_helpers.php
// Academic Calendar-এর শেয়ার্ড হেল্পার: Category (নাম + রং + "ছুটি ধরা হবে কি না"), Branch-এর Weekly Holiday,
// ছুটির রেঞ্জ (শুধু ছুটি-ধরা Category) আর মাস/বছরের হিসাব (কাজের দিন, ছুটি, পরীক্ষার সম্ভাব্য দিন)।
// Working Days ক্যালকুলেশন, Exam Routine আর Calendar (List/Month/Year/Print) সবাই একই নিয়ম ব্যবহার করে।

if (!function_exists('ac_ensure_tables')) {

function ac_ensure_tables(PDO $pdo): void
{

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_holidays (
            id INT AUTO_INCREMENT PRIMARY KEY,
            holiday_name VARCHAR(150) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            holiday_type VARCHAR(30) NOT NULL DEFAULT 'General',
            remarks VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS academic_calendar_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(30) NOT NULL,
            color VARCHAR(9) NOT NULL DEFAULT '#1e3a8a',
            is_holiday TINYINT(1) NOT NULL DEFAULT 1,
            is_exam TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // পুরনো টেবিলে is_exam না থাকলে যোগ (self-healing)
    if (!$pdo->query("SHOW COLUMNS FROM academic_calendar_categories LIKE 'is_exam'")->fetch()) {
        $pdo->exec("ALTER TABLE academic_calendar_categories ADD COLUMN is_exam TINYINT(1) NOT NULL DEFAULT 0 AFTER is_holiday");
    }

    // প্রথমবার: আগের ৫টা Holiday Type হুবহু একই নামে (পুরনো এন্ট্রি নিজে থেকেই মিলে যায়) + নতুন ৩টা (ছুটি নয়)
    if ((int) $pdo->query("SELECT COUNT(*) FROM academic_calendar_categories")->fetchColumn() === 0) {
        $seed = [
            ['General',        '#1e3a8a', 1, 0],
            ['Government',     '#0f766e', 1, 0],
            ['Religious',      '#a16207', 1, 0],
            ['Vacation',       '#7c3aed', 1, 0],
            ['Emergency',      '#dc2626', 1, 0],
            ['Exam',           '#f59e0b', 0, 1],
            ['Event',          '#0891b2', 0, 0],
            ['Result Publish', '#16a34a', 0, 0],
        ];
        $ins = $pdo->prepare("INSERT IGNORE INTO academic_calendar_categories (name, color, is_holiday, is_exam, sort_order) VALUES (?, ?, ?, ?, ?)");
        foreach ($seed as $i => $s) { $ins->execute([$s[0], $s[1], $s[2], $s[3], $i + 1]); }
    }
}

// নাম => ['id','name','color','is_holiday'] (Active Category, sort_order অনুযায়ী)
function ac_categories(PDO $pdo, bool $include_inactive = false): array
{
    ac_ensure_tables($pdo);
    $sql = "SELECT id, name, color, is_holiday, is_exam, status FROM academic_calendar_categories"
         . ($include_inactive ? '' : " WHERE status='Active'") . " ORDER BY sort_order ASC, id ASC";
    $out = [];
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['is_holiday'] = (int) $r['is_holiday'];
        $r['is_exam'] = (int) $r['is_exam'];
        $out[$r['name']] = $r;
    }
    return $out;
}

// Category অনুযায়ী রং (Category মুছে ফেলা/অজানা হলে ডিফল্ট নীল)
function ac_color(array $cats, string $type): string
{
    return $cats[$type]['color'] ?? '#1e3a8a';
}

// Category-টা ছুটি ধরা হয় কি না (অজানা/মুছে যাওয়া Category = ছুটি, কারণ আগে সবই ছুটি ছিল)
function ac_is_holiday_type(array $cats, string $type): bool
{
    return !isset($cats[$type]) || (int) $cats[$type]['is_holiday'] === 1;
}

// Branch-এর Weekly Holiday (যেমন ['Friday']) — Working Days পেজের মতোই প্রথম Active Branch থেকে
function ac_weekly_holidays(PDO $pdo): array
{
    try {
        require_once __DIR__ . '/branch_scope.php';
        $branch = branch_current_id();
        if ($branch !== null) {
            $q = $pdo->prepare("SELECT weekly_holidays FROM branches WHERE id=? AND status='Active'");
            $q->execute([$branch]);
            $b = $q->fetch();
        } else {
            $b = $pdo->query("SELECT weekly_holidays FROM branches WHERE status='Active' ORDER BY id ASC LIMIT 1")->fetch();
        }
        return array_values(array_filter(array_map('trim', explode(',', $b['weekly_holidays'] ?? 'Friday'))));
    } catch (Throwable $e) {
        return ['Friday'];
    }
}

// দুই তারিখের মধ্যে ওভারল্যাপ করা Active এন্ট্রি (সব Category), start_date ASC
function ac_entries(PDO $pdo, string $start, string $end): array
{
    ac_ensure_tables($pdo);
    $st = $pdo->prepare("SELECT * FROM academic_holidays WHERE status='Active' AND start_date <= ? AND end_date >= ? ORDER BY start_date ASC, id ASC");
    $st->execute([$end, $start]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// শুধু "ছুটি ধরা হবে" Category-র রেঞ্জ [['start_date','end_date'], ...] — Working Days ক্যালকুলেশনে বাদ যাবে
function ac_holiday_ranges(PDO $pdo, string $start, string $end): array
{
    $cats = ac_categories($pdo, true);
    $out = [];
    foreach (ac_entries($pdo, $start, $end) as $e) {
        if (ac_is_holiday_type($cats, $e['holiday_type'])) {
            $out[] = ['start_date' => $e['start_date'], 'end_date' => $e['end_date'], 'holiday_name' => $e['holiday_name']];
        }
    }
    return $out;
}

// একটা সময়সীমার প্রতিটা দিনের ম্যাপ: 'Y-m-d' => ['weekly'=>bool, 'entries'=>[rows...], 'holiday'=>bool, 'primary'=>row|null]
// primary = দিনের ঘরে যে এন্ট্রির রং দেখাবে (ছুটি-ধরা Category আগে, তারপর বাকিগুলো)
function ac_day_map(PDO $pdo, string $start, string $end, ?array $cats = null, ?array $weekly = null): array
{
    $cats   = $cats ?? ac_categories($pdo, true);
    $weekly = $weekly ?? ac_weekly_holidays($pdo);
    $map = [];
    foreach (ac_entries($pdo, $start, $end) as $e) {
        $t = max(strtotime($e['start_date']), strtotime($start));
        $te = min(strtotime($e['end_date']), strtotime($end));
        for (; $t <= $te; $t += 86400) {
            $map[date('Y-m-d', $t)]['entries'][] = $e;
        }
    }
    $days = [];
    for ($t = strtotime($start); $t <= strtotime($end); $t += 86400) {
        $ymd = date('Y-m-d', $t);
        $entries = $map[$ymd]['entries'] ?? [];
        $primary = null; $is_hol = false;
        foreach ($entries as $e) {
            if (ac_is_holiday_type($cats, $e['holiday_type'])) { $is_hol = true; if (!$primary || !ac_is_holiday_type($cats, $primary['holiday_type'])) $primary = $e; }
            elseif (!$primary) { $primary = $e; }
        }
        $days[$ymd] = [
            'weekly'  => in_array(date('l', $t), $weekly, true),
            'entries' => $entries,
            'holiday' => $is_hol,
            'primary' => $primary,
        ];
    }
    return $days;
}

// মাসভিত্তিক + বছরের হিসাব:
//  working = সাপ্তাহিক ছুটি ও ছুটি-Category বাদে কাজের দিন
//  weekly  = সাপ্তাহিক ছুটির দিন (Friday ইত্যাদি)
//  holiday = সাপ্তাহিক ছুটি নয় এমন দিন যা ছুটি-Category-র আওতায়
//  exam    = Exam-ধরনের (ছুটি নয়) এন্ট্রির সম্ভাব্য পরীক্ষার-দিন যেগুলো কাজের দিনও বটে — "possible window"
function ac_year_stats(PDO $pdo, int $year, ?array $cats = null, ?array $weekly = null): array
{
    $cats = $cats ?? ac_categories($pdo, true);
    $dm = ac_day_map($pdo, "$year-01-01", "$year-12-31", $cats, $weekly);
    $stats = [];
    for ($m = 1; $m <= 12; $m++) $stats[$m] = ['working' => 0, 'weekly' => 0, 'holiday' => 0, 'exam' => 0];
    foreach ($dm as $ymd => $d) {
        $m = (int) substr($ymd, 5, 2);
        if ($d['weekly']) { $stats[$m]['weekly']++; continue; }
        if ($d['holiday']) { $stats[$m]['holiday']++; continue; }
        $stats[$m]['working']++;
        foreach ($d['entries'] as $e) {
            if (!empty($cats[$e['holiday_type']]['is_exam'])) { $stats[$m]['exam']++; break; }
        }
    }
    $tot = ['working' => 0, 'weekly' => 0, 'holiday' => 0, 'exam' => 0];
    foreach ($stats as $s) foreach ($tot as $k => $_) $tot[$k] += $s[$k];
    return ['months' => $stats, 'total' => $tot];
}

}
