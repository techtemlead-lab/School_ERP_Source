<?php
/**
 * config/home_messages.php
 * -----------------------------------------------------------
 * হোমপেজের "বাণী" (Message Corner) সেকশনের শেয়ার্ড হেল্পার।
 *
 * - প্রতিটা বাণীর একটা "পদবির ধরন" (role_key) থাকে — Director, Chairman, Principal ইত্যাদি
 * - Global Settings > Settings > Home Page Settings থেকে প্রতিটা ধরনের জন্য
 *   On/Off আর Order (কোনটা আগে দেখাবে) ঠিক করা যায় — message_role_settings টেবিলে সেভ হয়
 * - হোমপেজে সর্বোচ্চ ৩টা বাণী দেখায় (Order অনুযায়ী), বাকিগুলো messages.php তে
 * - পুরনো ইনস্টলেশনে কোনো ম্যানুয়াল SQL লাগে না — self-heal (lazy migration) করা আছে
 */

/**
 * ডিফল্ট পদবির তালিকা। key কখনো বদলাবেন না (ডাটাবেজে সেভ থাকে) — শুধু লেবেল/ডিফল্ট অর্ডার বদলানো যাবে।
 * নতুন পদবি যোগ করতে চাইলে এখানে একটা লাইন যোগ করলেই Home Page Settings ও Message Corner
 * ফর্মে অটো চলে আসবে।
 */
function hm_roles(): array
{
    $roles = [
        'chairman'       => ['bn' => 'চেয়ারম্যান',            'en' => 'Chairman',            'order' => 1],
        'director'       => ['bn' => 'পরিচালক',               'en' => 'Director',            'order' => 2],
        'principal'      => ['bn' => 'অধ্যক্ষ / প্রিন্সিপাল',    'en' => 'Principal',           'order' => 3],
        'vice_principal' => ['bn' => 'উপাধ্যক্ষ / ভাইস প্রিন্সিপাল', 'en' => 'Vice Principal',  'order' => 4],
        'headmaster'     => ['bn' => 'প্রধান শিক্ষক',           'en' => 'Headmaster',          'order' => 5],
        'asst_headmaster' => ['bn' => 'সহকারী প্রধান শিক্ষক',    'en' => 'Assistant Headmaster', 'order' => 6],
        'other'          => ['bn' => 'অন্যান্য',                'en' => 'Others',              'order' => 99],
    ];
    foreach (hm_custom_roles() as $key => $r) { // Admin-এর নিজে যোগ করা পদবি
        $roles[$key] = $r;
    }
    return $roles;
}

/** কাস্টম পদবির ক্যাশ (hm_reload_custom_roles() দিয়ে ভরা হয়) */
function hm_custom_roles(?array $set = null): array
{
    static $cache = [];
    if ($set !== null) $cache = $set;
    return $cache;
}

function hm_reload_custom_roles(PDO $pdo): void
{
    $out = [];
    try {
        $rows = $pdo->query("SELECT role_key, label_bn, sort_order FROM message_role_settings WHERE is_custom = 1 ORDER BY sort_order ASC, role_key ASC")->fetchAll();
        foreach ($rows as $r) {
            $out[$r['role_key']] = ['bn' => (string) $r['label_bn'], 'en' => '', 'order' => (int) $r['sort_order'], 'custom' => true];
        }
    } catch (PDOException $e) {
        error_log('hm_reload_custom_roles: ' . $e->getMessage());
    }
    hm_custom_roles($out);
}

/** লেবেল দেখানোর জন্য — ইংরেজি নাম থাকলে বন্ধনীতে */
function hm_role_text(array $r): string
{
    return $r['bn'] . (!empty($r['en']) ? ' (' . $r['en'] . ')' : '');
}

/** নতুন পদবি যোগ — সফল হলে key, নাহলে null */
function hm_add_role(PDO $pdo, string $label): ?string
{
    hm_ensure_schema($pdo);
    $label = trim(preg_replace('/\s+/u', ' ', $label));
    if ($label === '' || mb_strlen($label) > 100) return null;
    foreach (hm_roles() as $r) { // একই নামে দুইবার নয়
        if (mb_strtolower($r['bn']) === mb_strtolower($label) || mb_strtolower($r['en'] ?? '') === mb_strtolower($label)) return null;
    }
    $max = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM message_role_settings WHERE role_key <> 'other'")->fetchColumn();
    $key = 'custom_' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO message_role_settings (role_key, is_shown, sort_order, label_bn, is_custom) VALUES (?, 1, ?, ?, 1)")
        ->execute([$key, min($max + 1, 98), $label]);
    hm_reload_custom_roles($pdo);
    return $key;
}

/** কাস্টম পদবি মোছা — এই পদবির বাণীগুলো "অন্যান্য"-তে সরে যায় (ডিফল্ট পদবি মোছা যায় না) */
function hm_delete_role(PDO $pdo, string $key): bool
{
    hm_ensure_schema($pdo);
    if (!isset(hm_custom_roles()[$key])) return false;
    if (hm_table_exists($pdo, 'message_corner')) {
        $pdo->prepare("UPDATE message_corner SET role_key = 'other' WHERE role_key = ?")->execute([$key]);
    }
    $pdo->prepare("DELETE FROM message_role_settings WHERE role_key = ? AND is_custom = 1")->execute([$key]);
    hm_reload_custom_roles($pdo);
    return true;
}

function hm_role_label(string $key): string
{
    $roles = hm_roles();
    return hm_role_text($roles[$key] ?? $roles['other']);
}

function hm_table_exists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * টেবিল/কলাম self-heal — যেকোনো পাবলিক পেজ বা অ্যাডমিন পেজ থেকে নিরাপদে কল করা যায়।
 */
function hm_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS message_role_settings (
                role_key VARCHAR(30) NOT NULL PRIMARY KEY,
                is_shown TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $rc = $pdo->query("SHOW COLUMNS FROM message_role_settings")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('label_bn', $rc, true)) $pdo->exec("ALTER TABLE message_role_settings ADD COLUMN label_bn VARCHAR(100) NULL");
        if (!in_array('is_custom', $rc, true)) $pdo->exec("ALTER TABLE message_role_settings ADD COLUMN is_custom TINYINT(1) NOT NULL DEFAULT 0");
        // নতুন role এলে তার ডিফল্ট সারি বানানো (আগে থেকে সেভ করা সারি টাচ হয় না)
        $have = $pdo->query("SELECT role_key FROM message_role_settings")->fetchAll(PDO::FETCH_COLUMN, 0);
        $ins = $pdo->prepare("INSERT IGNORE INTO message_role_settings (role_key, is_shown, sort_order) VALUES (?, 1, ?)");
        foreach (hm_roles() as $key => $r) {
            if (!in_array($key, $have, true)) $ins->execute([$key, $r['order']]);
        }
    } catch (PDOException $e) {
        error_log('hm_ensure_schema (role settings): ' . $e->getMessage());
    }
    hm_reload_custom_roles($pdo);

    // message_corner-এ role_key কলাম (পুরনো বাণীগুলো 'other' হয়ে থাকবে — ডিফল্ট On, তাই কিছুই হারায় না)
    try {
        if (hm_table_exists($pdo, 'message_corner')) {
            $cols = $pdo->query("SHOW COLUMNS FROM message_corner")->fetchAll(PDO::FETCH_COLUMN, 0);
            if (!in_array('role_key', $cols, true)) {
                $pdo->exec("ALTER TABLE message_corner ADD COLUMN role_key VARCHAR(30) NOT NULL DEFAULT 'other' AFTER designation");
            }
        }
    } catch (PDOException $e) {
        error_log('hm_ensure_schema (role_key): ' . $e->getMessage());
    }

    // sms_settings-এ Notice / Event On-Off কলাম
    try {
        if (hm_table_exists($pdo, 'sms_settings')) {
            $cols = $pdo->query("SHOW COLUMNS FROM sms_settings")->fetchAll(PDO::FETCH_COLUMN, 0);
            foreach (['show_notices_home', 'show_events_home', 'show_edu_links_home'] as $c) {
                if (!in_array($c, $cols, true)) {
                    $pdo->exec("ALTER TABLE sms_settings ADD COLUMN $c TINYINT(1) NOT NULL DEFAULT 1");
                }
            }
        }
    } catch (PDOException $e) {
        error_log('hm_ensure_schema (sms_settings): ' . $e->getMessage());
    }
}

/**
 * Home Page Settings ফর্মের জন্য — প্রতিটা role-এর On/Off + Order, Order অনুযায়ী সাজানো।
 */
function hm_get_role_settings(PDO $pdo): array
{
    hm_ensure_schema($pdo);
    $out = [];
    $roles = hm_roles();
    try {
        $rows = $pdo->query("SELECT role_key, is_shown, sort_order FROM message_role_settings ORDER BY sort_order ASC, role_key ASC")->fetchAll();
    } catch (PDOException $e) {
        $rows = [];
    }
    foreach ($rows as $r) {
        if (!isset($roles[$r['role_key']])) continue; // কোড থেকে সরানো পুরনো role বাদ
        $out[$r['role_key']] = ['is_shown' => (int) $r['is_shown'], 'sort_order' => (int) $r['sort_order']];
    }
    foreach ($roles as $key => $r) { // টেবিল পড়া না গেলেও ফর্ম যেন ফাঁকা না দেখায়
        if (!isset($out[$key])) $out[$key] = ['is_shown' => 1, 'sort_order' => $r['order']];
    }
    return $out;
}

/**
 * Home Page Settings-এর Update বাটন থেকে role On/Off + Order সেভ।
 * $shown = $_POST['role_show'] (চেকড হলেই key থাকে), $orders = $_POST['role_order'][key] = সংখ্যা
 */
function hm_save_role_settings(PDO $pdo, array $shown, array $orders): void
{
    hm_ensure_schema($pdo);
    $up = $pdo->prepare("UPDATE message_role_settings SET is_shown = ?, sort_order = ? WHERE role_key = ?");
    foreach (hm_roles() as $key => $r) {
        $order = isset($orders[$key]) ? (int) $orders[$key] : $r['order'];
        if ($order < 1) $order = 1;
        if ($order > 999) $order = 999;
        $up->execute([isset($shown[$key]) ? 1 : 0, $order, $key]);
    }
}

/**
 * পাবলিক সাইটের জন্য বাণীর তালিকা — শুধু Active বাণী + যে পদবি Home Page Settings-এ On আছে।
 * Order: আগে পদবির Order (ছোট সংখ্যা আগে), তারপর বাণীর নিজের Sort Order, তারপর id।
 * $limit = null হলে সবগুলো।
 */
function hm_get_messages(PDO $pdo, ?int $limit = null): array
{
    if (!hm_table_exists($pdo, 'message_corner')) return [];
    hm_ensure_schema($pdo);
    $sql = "SELECT m.* FROM message_corner m
            LEFT JOIN message_role_settings s ON s.role_key = m.role_key
            WHERE m.is_active = 1 AND COALESCE(s.is_shown, 1) = 1
            ORDER BY COALESCE(s.sort_order, 99) ASC, m.sort_order ASC, m.id ASC";
    if ($limit !== null) $sql .= " LIMIT " . (int) $limit;
    try {
        return $pdo->query($sql)->fetchAll();
    } catch (PDOException $e) {
        error_log('hm_get_messages: ' . $e->getMessage());
        return [];
    }
}

/** একটা নির্দিষ্ট বাণী (Active + পদবি On থাকলে), নাহলে false */
function hm_get_message(PDO $pdo, int $id)
{
    if (!hm_table_exists($pdo, 'message_corner')) return false;
    hm_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT m.* FROM message_corner m
         LEFT JOIN message_role_settings s ON s.role_key = m.role_key
         WHERE m.id = ? AND m.is_active = 1 AND COALESCE(s.is_shown, 1) = 1"
    );
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/** কার্ডে দেখানোর ছোট অংশ (ডেটাবেজের মূল লেখা অক্ষত থাকে) */
function hm_excerpt(string $text, int $width = 260): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    return mb_strlen($text) > $width ? rtrim(mb_substr($text, 0, $width)) . '…' : $text;
}
