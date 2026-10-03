<?php
// ══════════════════════════════════════════════════════════════
// School SaaS — Master Panel Theme Engine
// (একটি SaaS Panel-এর থিম ইঞ্জিনের হুবহু কপি — sidebar/topbar/
// বাটন/কার্ড — পুরো প্যানেলের color, font, layout এখান থেকেই কন্ট্রোল)
// settings.php > 🎨 থিম ট্যাব থেকে সেভ করা মান saas_settings টেবিলে
// theme_ প্রিফিক্স দিয়ে জমা থাকে। প্রতিটা পেজের <style> ব্লকের শুরুতে
// renderThemeCSS($pdo_master) call করলেই :root{--var:...} বসিয়ে দেয়।
// ══════════════════════════════════════════════════════════════

function ensureSaasSettingsTable($pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS saas_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $done = true;
}

function themeDefaults() {
    return [
        // রং — সাইডবার
        'sidebar_bg'         => '#15101f',
        'sidebar_border'     => '#2a1f3d',
        'sidebar_text'       => '#8a7fa0',
        'sidebar_text_hover' => '#e5deef',
        'sidebar_active_bg'  => '#241a38',
        'sidebar_active_text'=> '#c4b5fd',
        'sidebar_accent'     => '#8b5cf6',
        'brand_text'         => '#a78bfa',
        // রং — Topbar
        'topbar_text'        => '#ffffff',
        // রং — Primary action (বাটন/হাইলাইট)
        'primary_color'      => '#8b5cf6',
        // রং — Card / Page background
        'page_bg'            => '#0a0a0a',
        'card_bg'            => '#141414',
        'card_border'        => '#1e1e1e',
        'body_text'          => '#dddddd',
        // ফন্ট
        'font_family'        => "'Segoe UI', sans-serif",
        'font_size_base'     => '13',
        'font_bold'          => '0',
        'font_italic'        => '0',
        // লেআউট
        'icon_size'          => '16',
        'menu_spacing'       => '10',
        'sidebar_width'      => '220',
        'border_radius'      => '10',
        // স্ট্যাটাস ব্যাজ রং (tenant status)
        'badge_active'       => '#10b981',
        'badge_trial'        => '#f59e0b',
        'badge_suspended'    => '#f97316',
        'badge_blocked'      => '#ef4444',
        'badge_cancelled'    => '#6b7280',
        // ফুটার ক্রেডিট (সাইডবার নিচে + Login পেজে দেখাবে)
        'footer_enabled'     => '1',
        'footer_version'     => 'School ERP V-1.0',
        'footer_credit_text' => 'Powered by: Your Company',
        'footer_credit_url'  => '',
    ];
}

function getThemeSettings($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;

    $defaults = themeDefaults();
    $theme = $defaults;
    try {
        ensureSaasSettingsTable($pdo);
        $rows = $pdo->query("SELECT setting_key, setting_value FROM saas_settings WHERE setting_key LIKE 'theme\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as $k => $v) {
            $shortKey = substr($k, 6); // 'theme_' প্রিফিক্স বাদ
            if (array_key_exists($shortKey, $defaults) && $v !== '') {
                $theme[$shortKey] = $v;
            }
        }
    } catch (Exception $e) { /* টেবিল সমস্যা হলে ডিফল্ট দিয়েই চলবে */ }

    $cache = $theme;
    return $theme;
}

function saveThemeSettings($pdo, array $values) {
    $defaults = themeDefaults();
    ensureSaasSettingsTable($pdo);
    foreach ($defaults as $key => $def) {
        if (!array_key_exists($key, $values)) continue;
        $val = trim($values[$key]);
        $pdo->prepare("INSERT INTO saas_settings (setting_key, setting_value)
            VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
            ->execute(['theme_' . $key, $val]);
    }
}

function resetThemeSettings($pdo) {
    ensureSaasSettingsTable($pdo);
    $pdo->exec("DELETE FROM saas_settings WHERE setting_key LIKE 'theme\\_%'");
}

// ── CSS Custom Properties রেন্ডার করে ────────────────────────────
function renderThemeCSS($pdo) {
    $t = getThemeSettings($pdo);

    $fontWeight = ($t['font_bold'] === '1') ? '700' : '400';
    $fontWeightMedium = ($t['font_bold'] === '1') ? '700' : '600';
    $fontStyle  = ($t['font_italic'] === '1') ? 'italic' : 'normal';

    $css = ":root{";
    $css .= "--sidebar-bg:{$t['sidebar_bg']};";
    $css .= "--sidebar-border:{$t['sidebar_border']};";
    $css .= "--sidebar-text:{$t['sidebar_text']};";
    $css .= "--sidebar-text-hover:{$t['sidebar_text_hover']};";
    $css .= "--sidebar-active-bg:{$t['sidebar_active_bg']};";
    $css .= "--sidebar-active-text:{$t['sidebar_active_text']};";
    $css .= "--sidebar-accent:{$t['sidebar_accent']};";
    $css .= "--brand-text:{$t['brand_text']};";
    $css .= "--topbar-text:{$t['topbar_text']};";
    $css .= "--primary-color:{$t['primary_color']};";
    $css .= "--page-bg:{$t['page_bg']};";
    $css .= "--card-bg:{$t['card_bg']};";
    $css .= "--card-border:{$t['card_border']};";
    $css .= "--body-text:{$t['body_text']};";
    $css .= "--font-family:{$t['font_family']};";
    $css .= "--font-size-base:{$t['font_size_base']}px;";
    $css .= "--font-weight-base:{$fontWeight};";
    $css .= "--font-weight-medium:{$fontWeightMedium};";
    $css .= "--font-style-base:{$fontStyle};";
    $css .= "--icon-size:{$t['icon_size']}px;";
    $css .= "--menu-spacing:{$t['menu_spacing']}px;";
    $css .= "--sidebar-width:{$t['sidebar_width']}px;";
    $css .= "--border-radius:{$t['border_radius']}px;";
    $css .= "--badge-active:{$t['badge_active']};";
    $css .= "--badge-trial:{$t['badge_trial']};";
    $css .= "--badge-suspended:{$t['badge_suspended']};";
    $css .= "--badge-blocked:{$t['badge_blocked']};";
    $css .= "--badge-cancelled:{$t['badge_cancelled']};";
    $css .= "}";

    return $css;
}

// ── Branding (Logo/Favicon/Panel নাম) ──────────────────────────
function getSaasBranding($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = ['logo' => '', 'favicon' => '', 'name' => 'School ERP'];
    try {
        ensureSaasSettingsTable($pdo);
        $rows = $pdo->query("SELECT setting_key, setting_value FROM saas_settings WHERE setting_key IN ('panel_logo','panel_favicon','panel_name')")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($rows['panel_logo']))    $cache['logo']    = 'assets/uploads/' . $rows['panel_logo'];
        if (!empty($rows['panel_favicon'])) $cache['favicon'] = 'assets/uploads/' . $rows['panel_favicon'];
        if (!empty($rows['panel_name']))    $cache['name']    = $rows['panel_name'];
    } catch (Exception $e) { /* সমস্যা হলে ডিফল্টেই ফিরে যাবে */ }

    return $cache;
}

// ── ফুটার ক্রেডিট রেন্ডার করে (Login পেজ + Sidebar + Page bottom) ──
// $context: 'sidebar' → সাইডবারের নিচে ছোট ভার্সন, 'login' → লগইন কার্ডের নিচে,
// 'page' → পেজের নিচে floating ভার্সন
function renderFooterCredit($pdo, $context = 'sidebar') {
    $t = getThemeSettings($pdo);
    if (($t['footer_enabled'] ?? '1') !== '1') return '';

    $version = htmlspecialchars($t['footer_version'] ?? '');
    $credit  = htmlspecialchars($t['footer_credit_text'] ?? '');
    $url     = htmlspecialchars($t['footer_credit_url'] ?? '');

    if ($version === '' && $credit === '') return '';

    $versionHtml = $version !== '' ? "<span class=\"fc-version\">{$version}</span>" : '';
    $creditHtml  = '';
    if ($credit !== '') {
        $creditHtml = $url !== ''
            ? "<a class=\"fc-credit\" href=\"{$url}\" target=\"_blank\" rel=\"noopener\">{$credit}</a>"
            : "<span class=\"fc-credit\">{$credit}</span>";
    }

    if ($context === 'sidebar') {
        return "<div class=\"footer-credit footer-credit-sidebar\">{$versionHtml}{$creditHtml}</div>";
    }
    if ($context === 'login') {
        return "<div class=\"footer-credit footer-credit-login\">{$versionHtml}{$creditHtml}</div>";
    }
    return "<div class=\"footer-credit footer-credit-page\">{$versionHtml}{$creditHtml}</div>";
}

function renderFooterCreditCSS() {
    return <<<CSS
.footer-credit{font-size:11px;line-height:1.5;}
.footer-credit a,.footer-credit span{color:inherit;text-decoration:none;display:block;}
.footer-credit a:hover{opacity:0.8;text-decoration:underline;}
.footer-credit .fc-version{display:block;color:var(--sidebar-text);}
.footer-credit .fc-credit{display:block;color:var(--sidebar-accent);font-weight:var(--font-weight-medium);}
.footer-credit-sidebar{position:relative;margin-top:16px;padding:14px 20px 4px;border-top:1px solid var(--sidebar-border);text-align:center;z-index:1;}
.sidebar.collapsed .footer-credit-sidebar{display:none;}
.footer-credit-page{text-align:center;padding:20px 10px;}
.footer-credit-login{margin-top:24px;text-align:center;}
.footer-credit-login .fc-version,.footer-credit-login .fc-credit{display:inline;}
.footer-credit-login .fc-version::after{content:" · ";color:var(--sidebar-text);}
CSS;
}
