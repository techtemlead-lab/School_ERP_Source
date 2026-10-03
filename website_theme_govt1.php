<?php
// website_theme_govt1.php - Website Module > Website Theme = "Govt Theme-1"
// index.php থেকে include হয় (উপরের সব ডাটা fetch — $inst, $site_notices, $site_gallery,
// site_val(), site_bn_date() ইত্যাদি — আগে থেকেই তৈরি থাকে, এখানে আলাদা করে লাগবে না)।
// নেভিগেশন মেনু ও কিছু ডেমো বক্স (গ্যালারী হাইলাইট, ডাউনলোডস, প্রধান শিক্ষক, ই-সেবা) আপাতত
// স্ট্যাটিক টেমপ্লেট মার্কআপ — শুধু নোটিশ, গুরুত্বপূর্ণ লিংক, ব্রেকিং নিউজ ও ইনস্টিটিউট তথ্য
// রিয়েল ডাটাবেজ থেকে আসে।

// গুরুত্বপূর্ণ লিংক — index.php-তে আগেই fetch করা $site_useful_links (Website Module > Useful
// Links) পুনর্ব্যবহার করা হচ্ছে, এখানে আলাদা ডুপ্লিকেট কোয়েরির দরকার নেই
$gt_useful_links = $site_useful_links ?? [];

// ব্রেকিং নিউজ (Website Module > Breaking News থেকে)
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS website_breaking_news (
            id INT AUTO_INCREMENT PRIMARY KEY,
            news_text VARCHAR(500) NOT NULL,
            link VARCHAR(255) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $gt_breaking_news = $pdo->query("SELECT news_text, link FROM website_breaking_news WHERE is_active = 1 ORDER BY sort_order ASC, id DESC")->fetchAll();
} catch (PDOException $e) {
    $gt_breaking_news = [];
}

// এপিএ (APA) ও শুদ্ধাচার কৌশল — Website Module > Govt Theme Settings থেকে (admin/website_govt_theme.php)
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS website_govt_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            doc_type ENUM('APA','Suddhachar') NOT NULL DEFAULT 'APA',
            title VARCHAR(200) NOT NULL,
            doc_year VARCHAR(20) NULL,
            doc_link VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $gt_apa_docs = $pdo->prepare("SELECT title, doc_year, doc_link FROM website_govt_documents WHERE doc_type = 'APA' AND is_active = 1 ORDER BY sort_order ASC, id DESC");
    $gt_apa_docs->execute();
    $gt_apa_docs = $gt_apa_docs->fetchAll();
    $gt_suddhachar_docs = $pdo->prepare("SELECT title, doc_year, doc_link FROM website_govt_documents WHERE doc_type = 'Suddhachar' AND is_active = 1 ORDER BY sort_order ASC, id DESC");
    $gt_suddhachar_docs->execute();
    $gt_suddhachar_docs = $gt_suddhachar_docs->fetchAll();
} catch (PDOException $e) {
    $gt_apa_docs = [];
    $gt_suddhachar_docs = [];
}

$gt_name  = site_val($inst['institute_name'] ?? '', 'Your School Name');
$gt_slogan = site_val($inst['slogan'] ?? '', '');

// ক্লাসভিত্তিক শিক্ষার্থী সংখ্যা — "অধ্যয়নরত শিক্ষার্থীর সংখ্যা/তালিকা" নেভিগেশন আইটেমের জন্য
// (ব্যক্তিগত তথ্য/নাম প্রকাশ না করে শুধু অ্যাগ্রিগেট কাউন্ট — প্রাইভেসির জন্য নিরাপদ)
try {
    $gt_class_counts = $pdo->query(
        "SELECT ac.class_name, COUNT(s.id) AS total
         FROM academic_classes ac
         LEFT JOIN students s ON s.class_id = ac.id AND s.status = 'Active'
         WHERE ac.status = 'Active'
         GROUP BY ac.id, ac.class_name
         ORDER BY ac.rank ASC, ac.class_name ASC"
    )->fetchAll();
} catch (PDOException $e) {
    $gt_class_counts = [];
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $gt_name; ?></title>
<?php if (!empty($inst['favicon_path'])):
    $gt_fav_ext = strtolower(pathinfo($inst['favicon_path'], PATHINFO_EXTENSION));
    $gt_fav_mime = $gt_fav_ext === 'png' ? 'image/png' : ($gt_fav_ext === 'svg' ? 'image/svg+xml' : 'image/x-icon');
    $gt_fav_ver = @filemtime(__DIR__ . '/' . $inst['favicon_path']) ?: time();
?>
<link rel="icon" type="<?php echo $gt_fav_mime; ?>" href="<?php echo htmlspecialchars($inst['favicon_path']); ?>?v=<?php echo $gt_fav_ver; ?>">
<?php endif; ?>
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --blue:#2196c9;
    --blue-dark:#1a7ba8;
    --maroon:#7a2418;
    --red-arrow:#d63b1f;
    --link-blue:#1a5fa8;
    --box-bg:#ececec;
    --page-bg:#ffffff;
    --border:#dcdcdc;
    --text:#222;
    --muted:#666;
  }
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:'Hind Siliguri',sans-serif; color:var(--text); background:var(--page-bg); font-size:14px; line-height:1.6; position:relative;}
  a{color:var(--link-blue); text-decoration:none;}
  a:hover{text-decoration:underline;}
  img{max-width:100%; display:block;}
  .wrap{max-width:1160px; margin:0 auto; position:relative; z-index:1;}

  body{background:#ffffff;}

  .banner{
    position:relative; height:230px; overflow:hidden;
    background:
      linear-gradient(180deg, rgba(0,0,0,0.05) 0%, rgba(0,0,0,0.55) 100%),
      linear-gradient(120deg, #bcd9ea 0%, #9fc7de 18%, #7fae86 42%, #5c8f63 58%, #45785a 72%, #3a6659 100%);
  }
  .banner-crest{
    position:absolute; top:14px; left:14px; width:92px; height:92px; border-radius:6px;
    background:#3a8f3a; border:2px solid #fff; box-shadow:0 3px 10px rgba(0,0,0,0.35);
    display:flex; align-items:center; justify-content:center; flex-direction:column; color:#fff;
    font-size:1.8rem; overflow:hidden;
  }
  .banner-crest img{width:100%; height:100%; object-fit:cover;}
  .banner-text{
    position:absolute; left:16px; bottom:14px; color:#fff;
    text-shadow:0 2px 6px rgba(0,0,0,0.65), 0 0 2px rgba(0,0,0,0.5);
  }
  .banner-text h1{font-size:1.9rem; font-weight:700; margin-bottom:4px;}
  .banner-text p{font-size:1rem; font-weight:500;}

  nav{background:#fff; border-bottom:1px solid var(--border); position:relative; z-index:30;}
  .nav-list{list-style:none; display:flex; flex-wrap:wrap; margin:0; padding:0;}
  .nav-item{position:relative; border-right:1px solid var(--border); white-space:nowrap;}
  .nav-item:last-child{border-right:none;}
  .nav-link{
    display:block; padding:13px 18px; color:#333; font-size:.92rem; font-weight:500; cursor:pointer;
    line-height:1.2; text-decoration:none;
  }
  .nav-item:hover > .nav-link{background:#f3f3f3; color:var(--blue-dark);}
  .submenu{
    list-style:none; margin:0; padding:0;
    display:none; position:absolute; top:100%; left:0; background:#fff; min-width:250px;
    box-shadow:0 8px 16px rgba(0,0,0,0.15); z-index:40; border:1px solid var(--border);
  }
  .nav-item.has-dropdown:hover .submenu, .nav-item.has-dropdown.open .submenu{display:block;}
  .submenu-item{white-space:nowrap;}
  .submenu-link{
    display:block; padding:10px 16px; font-size:.86rem; border-bottom:1px solid #f0f0f0;
    color:var(--link-blue); text-decoration:none;
  }
  .submenu-item:last-child .submenu-link{border-bottom:none;}
  .submenu-link:hover{background:#f7f7f7;}

  .main-layout{
    display:grid; grid-template-columns:2.15fr 1fr; gap:16px; padding:16px 24px 30px;
    background:var(--page-bg);
  }

  .gbox{background:var(--box-bg); border:1px solid #ddd; border-radius:2px; margin-bottom:16px; padding:16px;}
  .gbox-flex{display:flex; gap:16px; align-items:flex-start;}
  .gbox-photo{
    width:150px; height:110px; border-radius:2px; flex-shrink:0; overflow:hidden;
    background:linear-gradient(160deg,#9fd8ff 0%,#e8f6ff 35%,#dfe9dc 55%,#4a6b52 100%);
    display:flex; align-items:flex-end; justify-content:center; position:relative;
  }
  .gbox-title{color:#1a4f7a; font-size:1.05rem; font-weight:700; margin-bottom:8px;}
  .gbox-link{color:var(--link-blue); font-size:.9rem; font-weight:600;}
  .gbox-foot{display:flex; justify-content:flex-end; margin-top:10px;}
  .btn-all{
    background:#dcdcdc; border:1px solid #c8c8c8; color:#333; font-size:.8rem; padding:5px 16px;
    border-radius:2px; cursor:pointer; font-family:inherit;
  }
  .btn-all:hover{background:#cfcfcf;}

  .gbox-head{display:flex; align-items:center; gap:12px; margin-bottom:10px;}
  .gbox-icon{
    width:52px; height:52px; border-radius:4px; flex-shrink:0; display:flex; align-items:center;
    justify-content:center; font-size:1.6rem; background:#fff; border:1px solid #ddd;
  }
  .gbox-head h2{font-size:1.05rem; font-weight:700; color:#222;}

  .notice-list, .dl-list{list-style:none;}
  .notice-list li, .dl-list li{padding:7px 0; font-size:.9rem; display:flex; gap:8px;}
  .notice-list li::before{content:"›"; color:var(--red-arrow); font-weight:700; flex-shrink:0;}
  .dl-list li::before{content:"›"; color:var(--red-arrow); font-weight:700; flex-shrink:0;}
  .notice-list a{color:var(--maroon);}
  .notice-list a:hover{text-decoration:underline;}
  .dl-list a{color:var(--link-blue);}

  .news-bar{background:var(--box-bg); border:1px solid #ddd; padding:12px 16px; display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; gap:12px;}
  .news-bar span.label{font-weight:600; color:#333; font-size:.95rem; flex-shrink:0;}
  .news-bar .news-text{font-size:.88rem; color:var(--text); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;}

  .sbox{margin-bottom:16px; border:1px solid var(--border);}
  .sbox-head{background:var(--blue); color:#fff; padding:9px 14px; font-size:.95rem; font-weight:600;}
  .sbox-body{background:#fff; padding:14px;}
  .ht-photo{
    width:110px; height:120px; margin:0 auto 10px; background:#dfe7ef; border-radius:2px; overflow:hidden;
    display:flex; align-items:center; justify-content:center; font-size:2.4rem;
  }
  .ht-name{text-align:center; color:var(--link-blue); font-weight:700; font-size:.95rem;}

  .side-list{list-style:none;}
  .side-list li{padding:7px 0; font-size:.88rem; display:flex; gap:6px;}
  .side-list li::before{content:"›"; color:var(--red-arrow); font-weight:700;}
  .side-list a{color:var(--link-blue);}
  .side-list a.highlight{color:#cc2200; font-weight:600;}

  footer{border-top:1px solid var(--border); padding:20px 0; background:#fff;}
  .footer-grid{display:flex; justify-content:space-between; gap:24px; flex-wrap:wrap; font-size:.86rem;}
  .footer-grid .col{flex:1; min-width:240px;}
  .footer-grid .col.right{text-align:right;}
  .footer-grid p{margin-top:3px; color:var(--text);}
  .footer-grid a{color:var(--link-blue);}
  .footer-grid strong{color:#333;}

  @media (max-width:900px){
    .main-layout{grid-template-columns:1fr;}
    .gbox-flex{flex-direction:column;}
    .footer-grid .col.right{text-align:left;}
    .nav-list{flex-direction:column;}
    .nav-item{border-right:none; border-bottom:1px solid var(--border);}
    .submenu{position:static; box-shadow:none; border:none; display:none;}
    .nav-item.has-dropdown:hover .submenu{display:none;}
    .nav-item.has-dropdown.open .submenu{display:block;}
    .banner-text h1{font-size:1.3rem;}
  }
</style>
</head>
<body>

<div class="banner">
  <div class="banner-crest">
    <?php if (!empty($inst['logo_path'])): ?>
        <img src="<?php echo htmlspecialchars($inst['logo_path']); ?>" alt="Logo">
    <?php else: ?>
        🛡️
    <?php endif; ?>
  </div>
  <div class="banner-text">
    <h1><?php echo $gt_name; ?></h1>
    <?php if ($gt_slogan): ?><p><?php echo $gt_slogan; ?></p><?php endif; ?>
  </div>
</div>

<nav>
  <div class="wrap">
    <ul class="nav-list">
      <li class="nav-item"><a href="index.php" class="nav-link">প্রথম পাতা</a></li>

      <li class="nav-item has-dropdown">
        <span class="nav-link">পরিচিতি ▾</span>
        <ul class="submenu">
          <li class="submenu-item"><a href="#about" class="submenu-link">এক নজরে পরিচিতি</a></li>
          <li class="submenu-item"><a href="#about" class="submenu-link">সংক্ষিপ্ত ইতিহাস</a></li>
        </ul>
      </li>

      <li class="nav-item"><a href="#staff" class="nav-link">জনবল</a></li>

      <li class="nav-item has-dropdown">
        <span class="nav-link">শিক্ষার্থী ▾</span>
        <ul class="submenu">
          <li class="submenu-item"><a href="#students" class="submenu-link">অধ্যয়নরত শিক্ষার্থীর সংখ্যা</a></li>
          <li class="submenu-item"><a href="#students" class="submenu-link">অধ্যয়নরত শিক্ষার্থীর তালিকা</a></li>
          <li class="submenu-item"><a href="#contact" class="submenu-link">বেতন ও অন্যান্য ফি প্রদানের তথ্য</a></li>
        </ul>
      </li>

      <li class="nav-item has-dropdown">
        <span class="nav-link">পরীক্ষার ফলাফল ▾</span>
        <ul class="submenu">
          <li class="submenu-item"><a href="#downloads" class="submenu-link">পাবলিক পরীক্ষা</a></li>
          <li class="submenu-item"><a href="academic_result.php" class="submenu-link">বিদ্যালয়ের অভ্যন্তরীণ পরীক্ষা</a></li>
        </ul>
      </li>

      <li class="nav-item"><a href="#gallery" class="nav-link">ছবির গ্যালারী</a></li>
      <li class="nav-item"><a href="#contact" class="nav-link">যোগাযোগ</a></li>
      <li class="nav-item"><a href="#apa" class="nav-link">এপিএ</a></li>
      <li class="nav-item"><a href="#suddhachar" class="nav-link">শুদ্ধাচার কৌশল</a></li>
    </ul>
  </div>
</nav>

<div class="wrap main-layout">

  <div>
    <?php $gt_latest_photo = $site_gallery[0] ?? null; ?>
    <div class="gbox">
      <div class="gbox-flex">
        <div class="gbox-photo">
          <?php if ($gt_latest_photo && !empty($gt_latest_photo['image_path'])): ?>
            <img src="<?php echo htmlspecialchars($gt_latest_photo['image_path']); ?>" alt="<?php echo htmlspecialchars($gt_latest_photo['caption'] ?? ''); ?>" style="width:100%;height:100%;object-fit:cover;">
          <?php endif; ?>
        </div>
        <div style="flex-grow:1;">
          <div class="gbox-title"><?php echo $gt_latest_photo ? htmlspecialchars($gt_latest_photo['caption'] ?? 'ফটো গ্যালারী') : 'ফটো গ্যালারী'; ?></div>
          <a href="#gallery" class="gbox-link">সকল ছবির গ্যালারী</a>
        </div>
      </div>
      <div class="gbox-foot"><a href="#gallery" class="btn-all" style="text-decoration:none;display:inline-block;">সকল</a></div>
    </div>

    <?php if ($show_notices_home): ?>
    <div class="gbox">
      <div class="gbox-head">
        <div class="gbox-icon">📝</div>
        <h2>নোটিশ বোর্ড</h2>
      </div>
      <ul class="notice-list">
        <?php if ($site_notices): foreach ($site_notices as $n): ?>
          <li><a href="notice_view.php?id=<?php echo (int) $n['id']; ?>"><?php echo htmlspecialchars($n['title']); ?><?php if (!empty($n['branch_short_name'])): ?> <span style="font-size:11px;padding:1px 6px;border-radius:8px;background:#eef2ff;color:#3730a3;"><?php echo htmlspecialchars($n['branch_short_name']); ?></span><?php endif; ?></a></li>
        <?php endforeach; else: ?>
          <li>এখনো কোনো নোটিশ যোগ করা হয়নি।</li>
        <?php endif; ?>
      </ul>
      <div class="gbox-foot"><a href="notices.php" class="btn-all" style="text-decoration:none;display:inline-block;">সকল</a></div>
    </div>
    <?php endif; ?>

    <?php if ($gt_breaking_news): ?>
    <div class="news-bar">
      <span class="label">খবর :</span>
      <span class="news-text"><?php echo implode('    •    ', array_map(fn($n) => htmlspecialchars($n['news_text']), $gt_breaking_news)); ?></span>
      <button class="btn-all">সকল</button>
    </div>
    <?php endif; ?>

    <div class="gbox" id="downloads">
      <div class="gbox-head">
        <div class="gbox-icon">⬇️</div>
        <h2>ডাউনলোডস / পরীক্ষার ফলাফল</h2>
      </div>
      <ul class="dl-list">
        <?php if ($site_board_results): foreach ($site_board_results as $br): ?>
          <li><a href="<?php echo htmlspecialchars($br['result_link']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($br['title']); ?> (<?php echo htmlspecialchars($br['exam_year']); ?>)</a></li>
        <?php endforeach; else: ?>
          <li>এখনো কোনো বোর্ড রেজাল্ট/ডাউনলোড যোগ করা হয়নি।</li>
        <?php endif; ?>
      </ul>
    </div>

    <div class="gbox" id="about">
      <div class="gbox-head">
        <div class="gbox-icon">🏫</div>
        <h2>পরিচিতি</h2>
      </div>
      <?php if ($site_about_content): foreach ($site_about_content as $aci => $ac): ?>
        <div style="<?php echo $aci > 0 ? 'margin-top:14px;padding-top:14px;border-top:1px solid #ddd;' : ''; ?>">
          <div style="font-weight:700;color:#1a4f7a;margin-bottom:6px;font-size:.95rem;"><?php echo htmlspecialchars($ac['title']); ?></div>
          <div style="font-size:.88rem;color:var(--text);"><?php echo nl2br(htmlspecialchars($ac['content'])); ?></div>
        </div>
      <?php endforeach; else: ?>
        <p style="font-size:.88rem;color:var(--muted);"><?php echo !empty($inst['short_description']) ? nl2br(htmlspecialchars($inst['short_description'])) : 'প্রতিষ্ঠানের পরিচিতি এখনো যোগ করা হয়নি।'; ?></p>
      <?php endif; ?>
    </div>

    <div class="gbox" id="staff">
      <div class="gbox-head">
        <div class="gbox-icon">👥</div>
        <h2>জনবল (শিক্ষক ও কর্মচারী)</h2>
      </div>
      <?php if ($show_teachers_home && $site_teachers): ?>
        <ul class="notice-list">
          <?php foreach ($site_teachers as $t): ?>
            <li><?php echo htmlspecialchars($t['name']); ?><?php if (!empty($t['designation_title'])): ?> — <span style="color:var(--muted);"><?php echo htmlspecialchars($t['designation_title']); ?></span><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p style="font-size:.88rem;color:var(--muted);">জনবলের তথ্য এখনো যোগ করা হয়নি।</p>
      <?php endif; ?>
    </div>

    <div class="gbox" id="students">
      <div class="gbox-head">
        <div class="gbox-icon">🎓</div>
        <h2>অধ্যয়নরত শিক্ষার্থীর সংখ্যা (ক্লাসভিত্তিক)</h2>
      </div>
      <?php if ($gt_class_counts): ?>
        <table style="width:100%;font-size:.88rem;border-collapse:collapse;">
          <thead><tr style="border-bottom:1px solid #ccc;"><th style="text-align:left;padding:5px 0;">ক্লাস</th><th style="text-align:right;padding:5px 0;">শিক্ষার্থী সংখ্যা</th></tr></thead>
          <tbody>
            <?php foreach ($gt_class_counts as $cc): ?>
              <tr style="border-bottom:1px solid #eee;">
                <td style="padding:5px 0;"><?php echo htmlspecialchars($cc['class_name']); ?></td>
                <td style="padding:5px 0;text-align:right;font-weight:600;"><?php echo (int) $cc['total']; ?></td>
              </tr>
            <?php endforeach; ?>
            <tr><td style="padding:8px 0;font-weight:700;">সর্বমোট</td><td style="padding:8px 0;text-align:right;font-weight:700;"><?php echo (int) $student_count; ?></td></tr>
          </tbody>
        </table>
      <?php else: ?>
        <p style="font-size:.88rem;color:var(--muted);">সর্বমোট শিক্ষার্থী: <?php echo (int) $student_count; ?></p>
      <?php endif; ?>
    </div>

    <?php if ($site_gallery): ?>
    <div class="gbox" id="gallery">
      <div class="gbox-head">
        <div class="gbox-icon">🖼️</div>
        <h2>ছবির গ্যালারী</h2>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;">
        <?php foreach ($site_gallery as $g): ?>
          <div style="border-radius:2px;overflow:hidden;border:1px solid #ddd;">
            <img src="<?php echo htmlspecialchars($g['image_path']); ?>" alt="<?php echo htmlspecialchars($g['caption'] ?? ''); ?>" style="width:100%;height:90px;object-fit:cover;display:block;">
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($gt_apa_docs): ?>
    <div class="gbox" id="apa">
      <div class="gbox-head">
        <div class="gbox-icon">📄</div>
        <h2>এপিএ (APA)</h2>
      </div>
      <ul class="dl-list">
        <?php foreach ($gt_apa_docs as $ad): ?>
          <li><a href="<?php echo htmlspecialchars($ad['doc_link']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($ad['title']); ?><?php if (!empty($ad['doc_year'])): ?> (<?php echo htmlspecialchars($ad['doc_year']); ?>)<?php endif; ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <?php if ($gt_suddhachar_docs): ?>
    <div class="gbox" id="suddhachar">
      <div class="gbox-head">
        <div class="gbox-icon">📄</div>
        <h2>শুদ্ধাচার কৌশল</h2>
      </div>
      <ul class="dl-list">
        <?php foreach ($gt_suddhachar_docs as $sd): ?>
          <li><a href="<?php echo htmlspecialchars($sd['doc_link']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($sd['title']); ?><?php if (!empty($sd['doc_year'])): ?> (<?php echo htmlspecialchars($sd['doc_year']); ?>)<?php endif; ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <?php if ($site_messages): foreach ($site_messages as $gt_msg): ?>
    <div class="sbox">
      <div class="sbox-head"><?php echo htmlspecialchars($gt_msg['designation']); ?></div>
      <div class="sbox-body">
        <div class="ht-photo">
          <?php if (!empty($gt_msg['photo_path'])): ?>
            <img src="<?php echo htmlspecialchars($gt_msg['photo_path']); ?>" alt="<?php echo htmlspecialchars($gt_msg['name']); ?>" style="width:100%;height:100%;object-fit:cover;">
          <?php else: ?>
            👩🏻‍🏫
          <?php endif; ?>
        </div>
        <div class="ht-name"><?php echo htmlspecialchars($gt_msg['name']); ?></div>
        <?php if (!empty($gt_msg['message'])): ?>
          <p style="font-size:.8rem;color:var(--muted);margin-top:8px;text-align:center;"><?php echo nl2br(htmlspecialchars(hm_excerpt($gt_msg['message'], 140))); ?></p>
        <?php endif; ?>
        <div style="text-align:center;margin-top:8px;"><a href="message_view.php?id=<?php echo (int) $gt_msg['id']; ?>" class="btn-all" style="text-decoration:none;display:inline-block;">Read More ➔</a></div>
      </div>
    </div>
    <?php endforeach; ?>
    <div style="text-align:center;margin-bottom:14px;"><a href="messages.php" class="btn-all" style="text-decoration:none;display:inline-block;">সকল বাণী ➔</a></div>
    <?php else: ?>
    <div class="sbox">
      <div class="sbox-head">প্রধান শিক্ষক</div>
      <div class="sbox-body">
        <div class="ht-photo">👩🏻‍🏫</div>
        <div class="ht-name">&nbsp;</div>
      </div>
    </div>
    <?php endif; ?>

    <div class="sbox">
      <div class="sbox-head">অভ্যন্তরীণ ই-সেবা</div>
      <div class="sbox-body">
        <ul class="side-list">
          <li><a href="https://www.eschool.gov.bd" target="_blank" rel="noopener">ই-স্কুল</a></li>
          <li><a href="https://www.pathshala.gov.bd" target="_blank" rel="noopener">পাঠশালা</a></li>
          <li><a href="https://pds.mopa.gov.bd" target="_blank" rel="noopener">পিডিএস (সরকারি মাধ্যমিক)</a></li>
          <li><a href="admission_form.php" class="highlight">ভর্তি পরীক্ষার আবেদন</a></li>
        </ul>
      </div>
    </div>

    <div class="sbox">
      <div class="sbox-head">গুরুত্বপূর্ণ লিংক</div>
      <div class="sbox-body">
        <ul class="side-list">
          <?php if ($gt_useful_links): foreach ($gt_useful_links as $l): ?>
            <li><a href="<?php echo htmlspecialchars($l['url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($l['title']); ?></a></li>
          <?php endforeach; else: ?>
            <li>এখনো কোনো লিংক যোগ করা হয়নি।</li>
          <?php endif; ?>
        </ul>
      </div>
    </div>

    <div class="sbox">
      <div class="sbox-head">ওয়েব মাস্টার</div>
      <div class="sbox-body">
        <ul class="side-list">
          <li><a href="admin/index.php">ওয়েব মাস্টার লগইন</a></li>
        </ul>
      </div>
    </div>
  </div>

</div>

<footer id="contact">
  <div class="wrap footer-grid">
    <div class="col">
      <strong>যোগাযোগ:</strong>
      <?php if (count($site_branches) > 1): ?>
        <?php foreach ($site_branches as $bi => $sb): ?>
          <p><strong>Branch <?php echo $bi + 1; ?><?php echo $sb['short_name'] ? ' (' . htmlspecialchars($sb['short_name']) . ')' : ''; ?>:</strong> <?php echo $sb['phone'] ? htmlspecialchars($sb['phone']) : ''; ?><?php echo $sb['email'] ? ' · ' . htmlspecialchars($sb['email']) : ''; ?></p>
        <?php endforeach; ?>
      <?php else: ?>
        <?php if (!empty($inst['phone_number'])): ?><p>ফোন: <?php echo site_val($inst['phone_number']); ?></p><?php endif; ?>
        <?php if (!empty($inst['email'])): ?><p>Email: <a href="mailto:<?php echo htmlspecialchars($inst['email']); ?>"><?php echo site_val($inst['email']); ?></a></p><?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="col right">
      <strong><?php echo $gt_name; ?></strong>
      <p>সর্বস্বত্ব সংরক্ষিত © <?php echo date('Y'); ?></p>
    </div>
  </div>
</footer>

<script>
  document.querySelectorAll('.nav-item.has-dropdown > .nav-link').forEach(function(el){
    el.addEventListener('click', function(e){
      if(window.innerWidth <= 900){
        e.preventDefault();
        this.parentElement.classList.toggle('open');
      }
    });
  });
</script>

</body>
</html>
