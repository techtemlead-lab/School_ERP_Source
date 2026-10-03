<?php
/**
 * config/website_facilities.php
 * -----------------------------------------------------------
 * ওয়েবসাইটের "আমাদের সুবিধাসমূহ" (Our Facilities) সেকশনের ডাইনামিক ডেটা।
 * Admin > Website Module > Facilities List থেকে ম্যানেজ হয়।
 *
 * টেবিল প্রথমবার তৈরি হওয়ার সময়ই আগের ৬টা হার্ডকোডেড সুবিধা ডিফল্ট হিসেবে বসানো হয়
 * (ওয়েবসাইট যেমন দেখাচ্ছিল ঠিক তেমনই থাকে)। তারপর Admin চাইলে এডিট/ডিলিট/নতুন যোগ করতে পারে —
 * সব মুছে ফেললে আর ডিফল্ট ফিরে আসে না, সেকশনটা ওয়েবসাইটে লুকিয়ে যায়।
 */

function wf_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $existed = (bool) $pdo->query("SHOW TABLES LIKE 'website_facilities'")->fetch();
    if ($existed) return;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS website_facilities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            title_en VARCHAR(255) NULL,
            description TEXT NULL,
            description_en TEXT NULL,
            image_path VARCHAR(255) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // ডিফল্ট ৬টা — আগে index.php-তে হার্ডকোড করা ছিল
    $dict = function_exists('site_i18n_dictionary') ? site_i18n_dictionary() : [];
    $defaults = [
        ['digital',  'https://images.unsplash.com/photo-1509062522246-3755977927d7?q=80&w=600'],
        ['science',  'https://images.unsplash.com/photo-1532094349884-543bc11b234d?q=80&w=600'],
        ['computer', 'https://images.unsplash.com/photo-1517694712202-14dd9538aa97?q=80&w=600'],
        ['library',  'https://images.unsplash.com/photo-1521587760476-6c12a4b040da?q=80&w=600'],
        ['sports',   'https://images.unsplash.com/photo-1577896851231-70ef18881754?q=80&w=600'],
        ['cctv',     'https://images.unsplash.com/photo-1557597774-9d273605dfa9?q=80&w=600'],
    ];
    $ins = $pdo->prepare("INSERT INTO website_facilities (title, title_en, description, description_en, image_path, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($defaults as $i => [$k, $img]) {
        $tb = $dict["facility_{$k}_title"]['bn'] ?? null;
        if ($tb === null) continue; // lang ফাইল পাওয়া না গেলে ডিফল্ট বাদ — Admin নিজে যোগ করবে
        $ins->execute([
            $tb, $dict["facility_{$k}_title"]['en'] ?? null,
            $dict["facility_{$k}_desc"]['bn'] ?? null, $dict["facility_{$k}_desc"]['en'] ?? null,
            $img, $i + 1,
        ]);
    }
}

/** পাবলিক সাইটের জন্য Active সুবিধাগুলো (sort_order অনুযায়ী) */
function wf_get_active(PDO $pdo): array
{
    try {
        wf_ensure_table($pdo);
        return $pdo->query("SELECT * FROM website_facilities WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** বর্তমান ভাষা অনুযায়ী টেক্সট; সেই ভাষায় না থাকলে অন্য ভাষারটা */
function wf_text(array $row, string $field, string $lang): string
{
    $bn = trim((string) ($row[$field] ?? ''));
    $en = trim((string) ($row[$field . '_en'] ?? ''));
    return $lang === 'en' ? ($en !== '' ? $en : $bn) : ($bn !== '' ? $bn : $en);
}

/** ছবির URL: http(s) হলে সরাসরি, নইলে সাইট-রুট রিলেটিভ */
function wf_image_src(?string $path): string
{
    $path = trim((string) $path);
    return $path === '' ? '' : htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
}
