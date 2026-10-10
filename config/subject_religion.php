<?php
/**
 * config/subject_religion.php — ধর্মভিত্তিক Subject (Islam / Hinduism / Buddhism / Christianity / Others)
 * -----------------------------------------------------------
 * academic_subjects.religion :  ফাঁকা = সবার জন্য (কমন);  মান থাকলে শুধু ঐ ধর্মের শিক্ষার্থীর জন্য।
 * একই Class-এ "ইসলাম শিক্ষা" (Islam) ও "হিন্দু ধর্ম শিক্ষা" (Hinduism) আলাদা Subject হিসেবে থাকবে;
 * মার্কস এন্ট্রি, Eligibility, রেজাল্ট, রুটিন — সব জায়গায় এই একটা নিয়মই খাটে।
 */

if (!function_exists('sr_options')) {
    /** ড্রপডাউনের মান — students.religion-এর ফিক্সড লিস্টের সাথে হুবহু মিল */
    function sr_options(): array
    {
        return ['Islam' => 'Islam (ইসলাম)', 'Hinduism' => 'Hinduism (হিন্দু)', 'Buddhism' => 'Buddhism (বৌদ্ধ)', 'Christianity' => 'Christianity (খ্রিস্টান)', 'Others' => 'Others (অন্যান্য)'];
    }
}

if (!function_exists('sr_ensure')) {
    function sr_ensure(PDO $pdo): void
    {
        try {
            if (!$pdo->query("SHOW COLUMNS FROM academic_subjects LIKE 'religion'")->fetch()) {
                $pdo->exec("ALTER TABLE academic_subjects ADD COLUMN religion VARCHAR(30) NULL DEFAULT NULL");
            }
        } catch (Throwable $e) { /* চুপচাপ */ }
    }
}

if (!function_exists('sr_norm')) {
    /** যেকোনো লেখা (Hindu, hinduism, ইসলাম...) থেকে ক্যানোনিকাল মান; চিনতে না পারলে '' */
    function sr_norm($v): string
    {
        $t = strtolower(trim((string) $v));
        if ($t === '') { return ''; }
        $map = [
            'islam' => 'Islam', 'muslim' => 'Islam', 'ইসলাম' => 'Islam', 'মুসলিম' => 'Islam',
            'hinduism' => 'Hinduism', 'hindu' => 'Hinduism', 'হিন্দু' => 'Hinduism', 'হিন্দুধর্ম' => 'Hinduism',
            'buddhism' => 'Buddhism', 'buddhist' => 'Buddhism', 'বৌদ্ধ' => 'Buddhism',
            'christianity' => 'Christianity', 'christian' => 'Christianity', 'খ্রিস্টান' => 'Christianity', 'খ্রীস্টান' => 'Christianity',
            'others' => 'Others', 'other' => 'Others', 'অন্যান্য' => 'Others',
        ];
        return $map[$t] ?? '';
    }
}

if (!function_exists('sr_applicable')) {
    /**
     * শিক্ষার্থী কি এই Subject-এর জন্য প্রযোজ্য?
     * Subject-এ ধর্ম না থাকলে সবার জন্য। ধর্ম থাকলে শিক্ষার্থীর ধর্ম হুবহু মিলতে হবে;
     * শিক্ষার্থীর ধর্ম ফাঁকা/অচেনা হলে প্রযোজ্য নয় (Eligibility পেজে সতর্কতা দেখানো হয়)।
     */
    function sr_applicable($subject_religion, $student_religion): bool
    {
        $s = sr_norm($subject_religion);
        if ($s === '') { return true; }
        return $s === sr_norm($student_religion);
    }
}

if (!function_exists('sr_subject_map')) {
    /** [subject_id => ক্যানোনিকাল ধর্ম বা ''] */
    function sr_subject_map(PDO $pdo, array $ids): array
    {
        $cache = [];
        $need = [];
        foreach ($ids as $i) { $i = (int) $i; if ($i > 0 && !array_key_exists($i, $cache)) { $need[$i] = $i; } }
        if ($need) {
            sr_ensure($pdo);
            try {
                $in = implode(',', $need);
                foreach ($pdo->query("SELECT id, religion FROM academic_subjects WHERE id IN ($in)")->fetchAll() as $r) {
                    $cache[(int) $r['id']] = sr_norm($r['religion'] ?? '');
                }
            } catch (Throwable $e) {}
            foreach ($need as $n) { if (!array_key_exists($n, $cache)) { $cache[$n] = ''; } }
        }
        $out = [];
        foreach ($ids as $i) { $out[(int) $i] = $cache[(int) $i] ?? ''; }
        return $out;
    }
}

if (!function_exists('sr_student_religion')) {
    /** [student_id => ক্যানোনিকাল ধর্ম বা ''] */
    function sr_student_religion(PDO $pdo, array $ids): array
    {
        $cache = [];
        $need = [];
        foreach ($ids as $i) { $i = (int) $i; if ($i > 0 && !array_key_exists($i, $cache)) { $need[$i] = $i; } }
        if ($need) {
            try {
                $in = implode(',', $need);
                foreach ($pdo->query("SELECT id, religion FROM students WHERE id IN ($in)")->fetchAll() as $r) {
                    $cache[(int) $r['id']] = sr_norm($r['religion'] ?? '');
                }
            } catch (Throwable $e) {}
            foreach ($need as $n) { if (!array_key_exists($n, $cache)) { $cache[$n] = ''; } }
        }
        $out = [];
        foreach ($ids as $i) { $out[(int) $i] = $cache[(int) $i] ?? ''; }
        return $out;
    }
}

if (!function_exists('sr_label')) {
    function sr_label($v): string
    {
        $n = sr_norm($v);
        return $n === '' ? '' : (sr_options()[$n] ?? $n);
    }
}
