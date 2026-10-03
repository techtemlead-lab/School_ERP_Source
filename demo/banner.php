<?php require_once __DIR__ . '/../config/demo.php'; ?>
<aside id="demo-notice" data-demo-server="<?= time() ?>" data-demo-reset="<?= demo_reset_at() ?>" style="position:sticky;top:0;z-index:2000;background:#0d3e35;color:#fff;padding:12px 20px;font:13px/1.5 system-ui;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
<span><strong>PUBLIC DEMO</strong> &middot; Your browser has a private sandbox. Resets at <strong><?= date('g:00 A',demo_reset_at()) ?> (Dhaka)</strong> &middot; <span data-demo-countdown></span></span>
<span><a style="color:#a9f4d7;margin-right:12px" href="<?= erp_e(erp_primary_url()) ?>/demo/">Demo guide</a><a style="color:#a9f4d7" href="/demo/export.php">Export my demo</a> &middot; <button style="background:none;border:0;color:#a9f4d7;cursor:pointer" onclick="window.print()">Print</button> &middot; Password changes disabled</span></aside>
<script src="/assets/demo-session.js?v=2" defer></script>

<style>
body:has(#demo-notice) .sidebar{top:var(--demo-banner-height,80px);height:calc(100vh - var(--demo-banner-height,80px))}
body:has(#demo-notice) .sticky-top-area{top:var(--demo-banner-height,80px)}
@media(max-width:767px){
body:has(#demo-notice) .main-wrapper{margin-left:64px!important;min-width:0}
body:has(#demo-notice) .sidebar.collapsed{width:64px}
body:has(#demo-notice) .sidebar:not(.collapsed){width:260px;box-shadow:8px 0 24px #0005}
body:has(#demo-notice) .content-area{padding:12px}
body:has(#demo-notice) .card-custom{padding:12px;overflow:auto}
body:has(#demo-notice) .breadcrumb-bar{flex-wrap:wrap}
}
@media print{#demo-notice,.sidebar,.sticky-top-area{display:none!important}.main-wrapper{margin-left:0!important}}
</style>
<script>
(function(){const banner=document.getElementById('demo-notice');new ResizeObserver(()=>document.documentElement.style.setProperty('--demo-banner-height',banner.offsetHeight+'px')).observe(banner)})();
</script>
