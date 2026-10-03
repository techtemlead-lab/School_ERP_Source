<?php
// config/website_menu.php
// -----------------------------------------------------------
// Website Menu সিস্টেম (Menu > Sub Menu > Content) — admin প্যানেল আর পাবলিক
// হেডার দুই জায়গা থেকেই require হয়।
//  - ডিফল্ট (Core) মেনুগুলো প্রথমবার নিজে থেকেই DB তে সিড হয় (একবারই)
//  - Core মেনু শুধু Super Admin ডিলিট করতে পারবেন
//  - প্রতিটা মেনু/সাব-মেনুর নিচে Content যোগ করা যায় (page.php?id=.. এ দেখায়)
//  - গভীরতা ২ লেভেল: মেনু -> সাব-মেনু
// -----------------------------------------------------------

function wm_default_core_menus(): array
{
    // [title_key, বাংলা নাম, link, icon, sort]
    return [
        ['nav_home',     'হোম',              'index.php',    'fa-house',              10],
        ['nav_about',    'আমাদের সম্পর্কে',   'about.php',    'fa-circle-info',        20],
        ['nav_academic', 'একাডেমিক',          '#academic',    'fa-book-open',          30],
        ['nav_teachers', 'শিক্ষক ও স্টাফ',    'teachers.php', 'fa-chalkboard-user',    40],
        ['nav_notice',   'নোটিশ',             'notices.php',  'fa-bullhorn',           50],
        ['nav_result',   'ফলাফল',             '#result',      'fa-square-poll-vertical', 60],
        ['nav_video',    'ভিডিও ক্লাস',        '#videos',      'fa-circle-play',        70],
        ['nav_news',     'নিউজ',              '#news',        'fa-newspaper',          80],
        ['nav_career',   'ক্যারিয়ার',          '#career',      'fa-briefcase',          90],
        ['nav_gallery',  'গ্যালারি',           'gallery.php',  'fa-images',            100],
        ['nav_contact',  'যোগাযোগ',           '#contact',     'fa-envelope',          110],
    ];
}

function wm_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    // দ্রুত চেক: সিড হয়ে গেলে আর কিছু করার দরকার নেই (পাবলিক পেজে প্রতি রিকোয়েস্টে ALTER চালানো হবে না)
    try {
        $ok = $pdo->query("SELECT v FROM website_menu_meta WHERE k = 'core_seed_v2'")->fetchColumn();
        if ($ok) { $done = true; return; }
    } catch (PDOException $e) { /* টেবিল নেই — নিচে তৈরি হবে */ }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS website_menu_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            link VARCHAR(255) NOT NULL DEFAULT '',
            open_new_tab TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $cols = $pdo->query("SHOW COLUMNS FROM website_menu_items")->fetchAll(PDO::FETCH_COLUMN);
    $add = [
        'is_core'   => "ALTER TABLE website_menu_items ADD COLUMN is_core TINYINT(1) NOT NULL DEFAULT 0",
        'parent_id' => "ALTER TABLE website_menu_items ADD COLUMN parent_id INT NOT NULL DEFAULT 0",
        'title_key' => "ALTER TABLE website_menu_items ADD COLUMN title_key VARCHAR(50) NULL",
        'icon'      => "ALTER TABLE website_menu_items ADD COLUMN icon VARCHAR(50) NULL",
    ];
    foreach ($add as $c => $ddl) { if (!in_array($c, $cols, true)) $pdo->exec($ddl); }
    // link এখন ফাঁকা রাখা যাবে (ফাঁকা = page.php?id=.. অটো)
    $pdo->exec("ALTER TABLE website_menu_items MODIFY link VARCHAR(255) NOT NULL DEFAULT ''");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS website_contents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            content TEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ccols = $pdo->query("SHOW COLUMNS FROM website_contents")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('menu_id', $ccols, true)) {
        // menu_id = 0 মানে কোনো মেনুর সাথে যুক্ত না (আগের মতো হোমপেজের About সেকশনে দেখাবে)
        $pdo->exec("ALTER TABLE website_contents ADD COLUMN menu_id INT NOT NULL DEFAULT 0");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS website_menu_meta (k VARCHAR(50) PRIMARY KEY, v VARCHAR(255) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // আগে থেকে যোগ করা কাস্টম মেনুগুলো আগের জায়গাতেই (ভিডিও ক্লাস ও নিউজের মাঝে) থাকুক — এই একবারই
    $pdo->exec("UPDATE website_menu_items SET sort_order = 70 + LEAST(GREATEST(sort_order, 0), 9) WHERE is_core = 0 AND parent_id = 0");

    // ডিফল্ট মেনু একবারই সিড/মাইগ্রেট — পরে Super Admin ডিলিট করলে আর ফেরত আসবে না
    $find = $pdo->prepare("SELECT id FROM website_menu_items WHERE is_core = 1 AND (title_key = ? OR (title_key IS NULL AND link = ?)) LIMIT 1");
    $upd  = $pdo->prepare("UPDATE website_menu_items SET title_key = ?, icon = ?, sort_order = ?, link = ? WHERE id = ?");
    $ins  = $pdo->prepare("INSERT INTO website_menu_items (title, link, is_core, title_key, icon, sort_order) VALUES (?, ?, 1, ?, ?, ?)");
    foreach (wm_default_core_menus() as [$key, $title, $link, $icon, $sort]) {
        $find->execute([$key, $link]);
        $id = (int) $find->fetchColumn();
        if ($id) $upd->execute([$key, $icon, $sort, $link, $id]);
        else     $ins->execute([$title, $link, $key, $icon, $sort]);
    }
    $pdo->exec("INSERT INTO website_menu_meta (k, v) VALUES ('core_seed_v2', '1') ON DUPLICATE KEY UPDATE v = '1'");
    $done = true;
}

// মেনুর আসল URL — link ফাঁকা হলে page.php?id=..
function wm_url(array $m): string
{
    $l = trim((string) ($m['link'] ?? ''));
    return $l !== '' ? $l : 'page.php?id=' . (int) $m['id'];
}

// পাবলিক হেডারের জন্য: Active মেনু + তাদের Active সাব-মেনু
function wm_public_tree(PDO $pdo): array
{
    wm_ensure_schema($pdo);
    $rows = $pdo->query("SELECT * FROM website_menu_items WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
    $top = []; $kids = [];
    foreach ($rows as $r) {
        if ((int) $r['parent_id'] > 0) $kids[(int) $r['parent_id']][] = $r;
        else $top[(int) $r['id']] = $r;
    }
    $out = [];
    foreach ($top as $id => $t) { $t['children'] = $kids[$id] ?? []; $out[] = $t; }
    return $out;
}

// Admin এর জন্য: সব মেনু (hidden সহ) গাছের মতো সাজানো ফ্ল্যাট লিস্ট, প্রতিটায় 'depth'
function wm_admin_flat(PDO $pdo): array
{
    wm_ensure_schema($pdo);
    $rows = $pdo->query("SELECT * FROM website_menu_items ORDER BY sort_order ASC, id ASC")->fetchAll();
    $kids = []; $top = [];
    foreach ($rows as $r) {
        if ((int) $r['parent_id'] > 0) $kids[(int) $r['parent_id']][] = $r; else $top[] = $r;
    }
    $flat = [];
    foreach ($top as $t) {
        $t['depth'] = 0; $flat[] = $t;
        foreach ($kids[(int) $t['id']] ?? [] as $k) { $k['depth'] = 1; $flat[] = $k; }
    }
    // parent মুছে গেছে এমন এতিম সাব-মেনু (হওয়ার কথা না, তবু নিরাপদে দেখানো)
    $seen = array_column($flat, 'id');
    foreach ($rows as $r) { if (!in_array($r['id'], $seen)) { $r['depth'] = 1; $flat[] = $r; } }
    return $flat;
}

// মেনু ডিলিট: সাব-মেনুসহ; যুক্ত কন্টেন্ট মুছে যায় না — menu_id = 0 হয়ে যায় (আনঅ্যাসাইনড)
function wm_delete_menu(PDO $pdo, int $id): void
{
    $ids = [$id];
    $c = $pdo->prepare("SELECT id FROM website_menu_items WHERE parent_id = ?");
    $c->execute([$id]);
    foreach ($c->fetchAll(PDO::FETCH_COLUMN) as $cid) $ids[] = (int) $cid;
    $in = implode(',', array_map('intval', $ids));
    $pdo->exec("UPDATE website_contents SET menu_id = 0 WHERE menu_id IN ($in)");
    $pdo->exec("DELETE FROM website_menu_items WHERE id IN ($in)");
}
