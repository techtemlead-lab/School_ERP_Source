<?php
/**
 * config/login_ui.php — Admin / Teacher / Student লগইন পেজের শেয়ার্ড ডিজাইন
 * ---------------------------------------------------------------------------
 * বাঁ দিকে স্কুলের পরিচয়-প্যানেল, ডান দিকে ফর্ম (Split-screen)। তিনটা পোর্টালের রঙ আলাদা।
 * শুধু ডিজাইন — লগইনের লজিক (ক্যাপচা, লকআউট, ব্রাঞ্চ ইত্যাদি) প্রতিটা পেজের নিজের কোডেই আছে।
 *
 * ব্যবহার: require_once __DIR__ . '/../config/login_ui.php';
 *   <style><?php echo login_ui_css('admin'); ?></style>
 *   echo login_ui_aside('admin', $inst_name, $logo_url);
 */

if (!function_exists('lgu_e')) {
    function lgu_e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

/** পোর্টাল অনুযায়ী থিম: রঙ + লেখা */
function login_ui_theme(string $role): array
{
    $themes = [
        'admin' => [
            'ink' => '#1c1917', 'ink2' => '#292524', 'accent' => '#d97706', 'accent_dk' => '#b45309', 'soft' => '#fef3c7',
            'chip' => 'Management Console', 'icon' => 'fa-gauge-high',
            'title' => 'প্রশাসনিক প্যানেল',
            'sub' => 'ভর্তি, হিসাব, রেজাল্ট ও পুরো প্রতিষ্ঠান — এক জায়গা থেকে পরিচালনা করুন।',
            'points' => [['fa-user-graduate', 'ভর্তি ও শিক্ষার্থী ব্যবস্থাপনা'], ['fa-wallet', 'ফি, আয়-ব্যয় ও হিসাব'], ['fa-file-lines', 'পরীক্ষা, রেজাল্ট ও রিপোর্ট']],
            'form_title' => 'অ্যাডমিন সাইন-ইন', 'form_sub' => 'আপনার ইউজারনেম ও পাসওয়ার্ড দিন',
        ],
        'teacher' => [
            'ink' => '#4c0519', 'ink2' => '#6b1d3a', 'accent' => '#be123c', 'accent_dk' => '#9f1239', 'soft' => '#ffe4e6',
            'chip' => 'Teacher Portal', 'icon' => 'fa-chalkboard-user',
            'title' => 'শিক্ষক পোর্টাল',
            'sub' => 'ক্লাস, হাজিরা, মার্ক এন্ট্রি ও পাঠ-পরিকল্পনা — সবকিছু আপনার হাতের কাছে।',
            'points' => [['fa-clipboard-check', 'হাজিরা ও মার্ক এন্ট্রি'], ['fa-book-open-reader', 'লেসন প্ল্যান ও প্রশ্নপত্র আপলোড'], ['fa-calendar-days', 'আমার ক্লাস রুটিন']],
            'form_title' => 'শিক্ষক সাইন-ইন', 'form_sub' => 'এমপ্লয়ি আইডি ও পাসওয়ার্ড দিন',
        ],
        'student' => [
            'ink' => '#1e1b4b', 'ink2' => '#312e81', 'accent' => '#4f46e5', 'accent_dk' => '#4338ca', 'soft' => '#e0e7ff',
            'chip' => 'Student & Guardian', 'icon' => 'fa-user-graduate',
            'title' => 'শিক্ষার্থী ও অভিভাবক পোর্টাল',
            'sub' => 'ফলাফল, ফি-র হিসাব, রুটিন ও শিক্ষাবৃত্তির আবেদন — এক নজরে।',
            'points' => [['fa-award', 'পরীক্ষার ফলাফল ও রিপোর্ট কার্ড'], ['fa-receipt', 'ফি, বকেয়া ও রসিদ'], ['fa-hand-holding-dollar', 'শিক্ষাবৃত্তির অনলাইন আবেদন']],
            'form_title' => 'সাইন-ইন', 'form_sub' => 'ইউজারনেম (Student ID) ও পাসওয়ার্ড দিন',
        ],
    ];
    return $themes[$role] ?? $themes['admin'];
}

/** স্কুলের নামের আদ্যক্ষর (লোগো না থাকলে মনোগ্রাম) */
function login_ui_monogram(string $name): string
{
    $name = trim(preg_replace('/\(.*?\)/u', '', $name));
    $parts = preg_split('/[\s&,\-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = '';
    foreach ($parts as $p) {
        if (in_array(mb_strtolower($p), ['and', 'of', 'the'], true)) { continue; }
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
        if (mb_strlen($out) >= 2) { break; }
    }
    return $out !== '' ? $out : 'SE';
}

/** স্কুলের লোগো URL (institute_settings.logo_path) — ফাইল থাকলে URL, না থাকলে '' */
function login_ui_logo_url(?PDO $pdo, string $prefix = '../'): string
{
    if (!$pdo) { return ''; }
    try {
        $p = (string) $pdo->query("SELECT logo_path FROM institute_settings WHERE id = 1")->fetchColumn();
        if ($p !== '' && is_file(__DIR__ . '/../' . ltrim($p, '/'))) { return $prefix . ltrim($p, '/'); }
    } catch (\Throwable $e) { /* টেবিল/কলাম নাই */ }
    return '';
}

/** বাঁ দিকের পরিচয়-প্যানেল (HTML) */
function login_ui_aside(string $role, string $inst_name, string $logo_url = ''): string
{
    $t = login_ui_theme($role);
    ob_start(); ?>
    <aside class="lg-aside">
        <div class="lg-brand">
            <?php if ($logo_url !== ''): ?>
                <img src="<?php echo lgu_e($logo_url); ?>" alt="" class="lg-logo">
            <?php else: ?>
                <span class="lg-mono"><?php echo lgu_e(login_ui_monogram($inst_name)); ?></span>
            <?php endif; ?>
            <span class="lg-brand-name"><?php echo lgu_e($inst_name); ?></span>
        </div>
        <div class="lg-hero">
            <span class="lg-chip"><i class="fa-solid <?php echo $t['icon']; ?>"></i> <?php echo lgu_e($t['chip']); ?></span>
            <h1><?php echo lgu_e($t['title']); ?></h1>
            <p><?php echo lgu_e($t['sub']); ?></p>
            <ul class="lg-points">
                <?php foreach ($t['points'] as $pt): ?>
                    <li><span class="lg-pi"><i class="fa-solid <?php echo $pt[0]; ?>"></i></span><?php echo lgu_e($pt[1]); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="lg-aside-foot">&copy; <?php echo date('Y'); ?> <?php echo lgu_e($inst_name); ?></div>
    </aside>
    <?php
    return ob_get_clean();
}

/** ফর্মের নিচের "অন্য পোর্টাল" লিংক। $links = [['label'=>..., 'href'=>..., 'icon'=>...], ...] */
function login_ui_switch(array $links): string
{
    ob_start(); ?>
    <div class="lg-switch">
        <span>অন্য পোর্টাল:</span>
        <?php foreach ($links as $l): ?>
            <a href="<?php echo lgu_e($l['href']); ?>"><i class="fa-solid <?php echo lgu_e($l['icon']); ?>"></i> <?php echo lgu_e($l['label']); ?></a>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

/** ভেরিফিকেশন কোড বক্স (id = captchaBox / captchaRefreshBtn — নিচের JS এগুলো ধরে) */
function login_ui_captcha(string $svg_markup): string
{
    ob_start(); ?>
    <div class="lg-field">
        <label class="lg-label">Verification Code</label>
        <div id="captchaBox" class="lg-captcha">
            <?php echo $svg_markup; ?>
            <button type="button" id="captchaRefreshBtn" title="নতুন কোড"><i class="fa-solid fa-rotate"></i></button>
        </div>
        <div class="lg-input">
            <i class="fa-solid fa-shield-halved"></i>
            <input type="text" name="verification_code" placeholder="উপরের সংখ্যাগুলো লিখুন" autocomplete="off" required>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/** ক্যাপচা রিফ্রেশ + পাসওয়ার্ড দেখানোর JS */
function login_ui_script(): string
{
    return <<<'JS'
<script>
document.getElementById('captchaRefreshBtn')?.addEventListener('click', function () {
    var btn = this, icon = btn.querySelector('i');
    icon.classList.add('fa-spin');
    fetch('captcha_refresh.php').then(function (r) { return r.json(); }).then(function (data) {
        document.getElementById('captchaBox').querySelector('svg').outerHTML = data.svg;
    }).finally(function () { icon.classList.remove('fa-spin'); });
});
document.querySelectorAll('.lg-eye').forEach(function (b) {
    b.addEventListener('click', function () {
        var inp = b.parentNode.querySelector('input'); if (!inp) return;
        var show = inp.type === 'password'; inp.type = show ? 'text' : 'password';
        b.querySelector('i').className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
    });
});
</script>
JS;
}

/** পুরো লগইন পেজের CSS (Bootstrap-এর উপরে) */
function login_ui_css(string $role): string
{
    $t = login_ui_theme($role);
    $css = <<<'CSS'
:root { --ink:{INK}; --ink2:{INK2}; --accent:{ACC}; --accent-dk:{ACCDK}; --soft:{SOFT}; }
* { box-sizing: border-box; }
html, body { height: 100%; }
body.lg { margin: 0; background: #f6f5f1; color: #1f2937;
          font-family: 'Hind Siliguri', 'Plus Jakarta Sans', 'Segoe UI', system-ui, -apple-system, sans-serif; }
.lg-shell { min-height: 100vh; display: grid; grid-template-columns: minmax(380px, 46%) 1fr; }

/* ---------- বাঁ প্যানেল ---------- */
.lg-aside { position: relative; overflow: hidden; color: #fff; padding: 40px 48px; display: flex; flex-direction: column; justify-content: space-between;
            background:
              radial-gradient(circle at 100% 0%, rgba(255,255,255,.10) 0, rgba(255,255,255,0) 38%),
              radial-gradient(rgba(255,255,255,.09) 1.2px, transparent 1.3px) 0 0 / 22px 22px,
              linear-gradient(160deg, var(--ink2) 0%, var(--ink) 70%); }
.lg-aside::after { content: ""; position: absolute; right: -140px; bottom: -140px; width: 420px; height: 420px; border-radius: 50%;
                   border: 56px solid rgba(255,255,255,.06); pointer-events: none; }
.lg-brand { display: flex; align-items: center; gap: 14px; position: relative; z-index: 1; }
.lg-logo { width: 54px; height: 54px; object-fit: contain; background: #fff; border-radius: 14px; padding: 5px; }
.lg-mono { width: 54px; height: 54px; border-radius: 14px; background: var(--accent); color: #fff; font-weight: 800; font-size: 20px; letter-spacing: .5px;
           display: flex; align-items: center; justify-content: center; }
.lg-brand-name { font-size: 18px; font-weight: 700; line-height: 1.3; }
.lg-hero { position: relative; z-index: 1; max-width: 460px; margin: 40px 0; }
.lg-chip { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase;
           padding: 6px 12px; border-radius: 999px; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.22); }
.lg-hero h1 { font-size: 36px; line-height: 1.25; font-weight: 700; margin: 18px 0 12px; }
.lg-hero p { font-size: 16px; line-height: 1.7; color: rgba(255,255,255,.78); margin: 0 0 26px; }
.lg-points { list-style: none; padding: 0; margin: 0; display: grid; gap: 12px; }
.lg-points li { display: flex; align-items: center; gap: 12px; font-size: 15px; color: rgba(255,255,255,.92); }
.lg-pi { width: 34px; height: 34px; border-radius: 10px; background: rgba(255,255,255,.12); display: inline-flex; align-items: center; justify-content: center; color: var(--soft); flex: 0 0 34px; }
.lg-aside-foot { font-size: 12.5px; color: rgba(255,255,255,.55); position: relative; z-index: 1; }

/* ---------- ডান দিকের ফর্ম ---------- */
.lg-main { display: flex; align-items: center; justify-content: center; padding: 36px 24px; }
.lg-card { width: 100%; max-width: 420px; }
.lg-card h2 { font-size: 28px; font-weight: 700; color: var(--ink); margin: 0 0 4px; }
.lg-card .lg-sub { color: #6b7280; font-size: 14.5px; margin: 0 0 24px; }
.lg-bar { height: 4px; width: 46px; border-radius: 4px; background: var(--accent); margin-bottom: 16px; }
.lg-field { margin-bottom: 16px; }
.lg-label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
.lg-input { display: flex; align-items: center; gap: 10px; background: #fff; border: 1.5px solid #e2e0d8; border-radius: 12px; padding: 0 14px; transition: border-color .15s, box-shadow .15s; }
.lg-input:focus-within { border-color: var(--accent); box-shadow: 0 0 0 4px color-mix(in srgb, var(--accent) 16%, transparent); }
.lg-input > i { color: #9ca3af; width: 16px; text-align: center; }
.lg-input:focus-within > i { color: var(--accent); }
.lg-input input, .lg-input select { flex: 1; border: 0; outline: 0; background: transparent; padding: 13px 0; font-size: 15px; color: #111827; min-width: 0; }
.lg-input select { cursor: pointer; }
.lg-eye { border: 0; background: transparent; color: #9ca3af; padding: 6px; cursor: pointer; }
.lg-eye:hover { color: var(--accent); }
.lg-captcha { margin-bottom: 20px; position: relative; background: #fff; border: 1.5px dashed #d6d3c8; border-radius: 12px; padding: 10px 10px 16px; text-align: center; }
.lg-captcha svg { display: block; width: 100%; max-width: 260px; height: auto; margin: 0 auto; }
.lg-captcha button { position: absolute; right: 12px; bottom: -13px; width: 32px; height: 32px; border-radius: 50%; border: 3px solid #f6f5f1;
                     background: var(--accent); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 12px; }
.lg-btn { width: 100%; border: 0; border-radius: 12px; background: var(--accent); color: #fff; font-weight: 700; font-size: 16px; padding: 14px 18px;
          display: flex; align-items: center; justify-content: center; gap: 10px; cursor: pointer; transition: background .15s, transform .1s; margin-top: 6px; }
.lg-btn:hover { background: var(--accent-dk); }
.lg-btn:active { transform: translateY(1px); }
.lg-alert { border-radius: 12px; padding: 11px 14px; font-size: 13.5px; margin-bottom: 16px; display: flex; gap: 10px; align-items: flex-start; }
.lg-alert.err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.lg-alert.warn { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.lg-note { font-size: 12.5px; color: #6b7280; background: var(--soft); border-radius: 10px; padding: 10px 12px; margin-top: 18px; line-height: 1.6; }
.lg-switch { margin-top: 24px; padding-top: 18px; border-top: 1px solid #e7e5dc; font-size: 13px; color: #6b7280; display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; }
.lg-switch a { color: var(--accent-dk); font-weight: 600; text-decoration: none; }
.lg-switch a:hover { text-decoration: underline; }

@media (max-width: 900px) {
    .lg-shell { grid-template-columns: 1fr; }
    .lg-aside { padding: 24px 22px 28px; }
    .lg-hero { margin: 22px 0 0; }
    .lg-hero h1 { font-size: 24px; margin-top: 12px; }
    .lg-hero p, .lg-points, .lg-aside-foot { display: none; }
    .lg-aside::after { width: 240px; height: 240px; border-width: 34px; right: -90px; bottom: -110px; }
    .lg-main { padding: 26px 18px 40px; align-items: flex-start; }
}
CSS;
    return strtr($css, ['{INK}' => $t['ink'], '{INK2}' => $t['ink2'], '{ACC}' => $t['accent'], '{ACCDK}' => $t['accent_dk'], '{SOFT}' => $t['soft']]);
}
