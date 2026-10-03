<?php
// ══════════════════════════════════════════════════════════════
// School SaaS — Master Panel — Shared Sidebar Layout (Header অংশ)
// প্রতিটা master/*.php পেজে _guard.php require করার পর, এই ফাইল
// require করলেই অন্য প্রোডাক্টের মতো sidebar+topbar শেল বসে যাবে।
// ব্যবহার: require_once __DIR__.'/_layout_top.php'; ($pageTitle,
// $pageIcon, $activeKey ভ্যারিয়েবল সেট করে তারপর require করবে)
// ══════════════════════════════════════════════════════════════

$branding = getSaasBranding($pdo_master);
$pageTitle = $pageTitle ?? 'Master Panel';
$pageIcon  = $pageIcon  ?? '🏠';
$activeKey = $activeKey ?? '';
$smOpen = in_array($activeKey, ['create_tenant', 'migrate_tenant', 'client_list', 'edit_tenant', 'trash'], true);
$setOpen = in_array($activeKey, ['settings_branding', 'settings_theme', 'settings_sms', 'settings_security', 'change_password'], true);
?><!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo htmlspecialchars($pageTitle); ?> — Master Panel</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<?php if ($branding['favicon']): ?><link rel="icon" href="<?php echo htmlspecialchars($branding['favicon']); ?>"><?php endif; ?>
<style>
<?php echo renderThemeCSS($pdo_master); ?>
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--page-bg);color:var(--body-text);font-family:var(--font-family);font-weight:var(--font-weight-base);font-style:var(--font-style-base);min-height:100vh}
/* Sidebar */
.sidebar{position:fixed;top:0;left:0;width:var(--sidebar-width);height:100vh;background:var(--sidebar-bg);border-right:1px solid var(--sidebar-border);padding:20px 0;z-index:100;transition:width .2s ease;overflow-x:hidden;overflow-y:auto;scrollbar-width:none;-ms-overflow-style:none;}
.sidebar::-webkit-scrollbar{display:none;}
.sidebar .brand{padding:0 20px 20px;border-bottom:1px solid var(--sidebar-border);margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:8px;}
.sidebar .brand-text{min-width:0;overflow:hidden;}
.sidebar .brand h2{font-size:16px;color:var(--brand-text);white-space:nowrap;}
.sidebar .brand img{max-width:140px;max-height:42px;object-fit:contain;}
.sidebar .brand p{font-size:12px;color:#fff;font-weight:700;margin-top:2px;white-space:nowrap;}
.sidebar a{display:flex;align-items:center;gap:var(--menu-spacing);padding:10px 20px 10px 17px;color:var(--sidebar-text);text-decoration:none;font-size:var(--font-size-base);transition:.2s;white-space:nowrap;border-left:3px solid transparent;}
.sidebar a:hover{background:var(--sidebar-active-bg);color:var(--sidebar-text-hover)}
.sidebar a.active{background:var(--sidebar-active-bg);color:var(--sidebar-active-text);border-left:3px solid var(--sidebar-accent);}
.sidebar a span.icon{font-size:var(--icon-size);width:20px;text-align:center;flex-shrink:0;}
.sidebar a.nav-parent{cursor:pointer;user-select:none}
.sidebar a.nav-parent .nav-arrow{margin-left:auto;}
.sidebar .nav-arrow{font-size:10px;color:var(--sidebar-text);flex-shrink:0;transition:transform .15s}
.sidebar .sub-menu{overflow:hidden;transition:max-height .22s ease;background:rgba(0,0,0,.18)}
.sidebar a.sub-link{padding-left:31px;font-size:calc(var(--font-size-base) - 0.5px)}
/* Collapsed (icon-only) state */
.sidebar.collapsed{width:64px;}
.sidebar.collapsed .brand{padding:0 0 20px;justify-content:center;}
.sidebar.collapsed .brand-text{display:none;}
.sidebar.collapsed a{justify-content:center;padding:10px 0;}
.sidebar.collapsed a span:not(.icon),.sidebar.collapsed .nav-arrow{display:none;}
.sidebar.collapsed .sub-menu{max-height:0 !important;}
#sbToggle{position:fixed;top:16px;z-index:150;width:32px;height:32px;background:var(--sidebar-active-bg);border:1px solid var(--sidebar-border);border-radius:8px;color:var(--sidebar-text);font-size:16px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:left .2s ease;left:calc(var(--sidebar-width) + 8px);}
body.sidebar-collapsed #sbToggle{left:72px;}
/* Main */
.main{margin-left:var(--sidebar-width);padding:28px;transition:margin-left .2s ease;}
body.sidebar-collapsed .main{margin-left:64px;}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px}
.topbar h1{font-size:20px;color:var(--body-text);}
/* User menu (top-right dropdown) */
.user-menu{position:fixed;top:14px;right:20px;z-index:200;}
.user-menu-btn{display:flex;align-items:center;gap:6px;background:var(--sidebar-bg);padding:7px 14px;border-radius:20px;border:1px solid var(--sidebar-border);font-size:13px;color:var(--sidebar-active-text);cursor:pointer;user-select:none;transition:.2s;}
.user-menu-btn:hover{background:var(--sidebar-active-bg);border-color:var(--sidebar-accent);}
.user-menu-btn .um-arrow{font-size:9px;color:var(--sidebar-text);transition:transform .15s;}
.user-menu.open .user-menu-btn .um-arrow{transform:rotate(180deg);}
.user-menu-dropdown{position:absolute;top:calc(100% + 8px);right:0;min-width:200px;background:var(--sidebar-bg);border:1px solid var(--sidebar-border);border-radius:var(--border-radius);box-shadow:0 8px 24px rgba(0,0,0,.5);overflow:hidden;display:none;}
.user-menu.open .user-menu-dropdown{display:block;}
.user-menu-dropdown a{display:flex;align-items:center;gap:10px;padding:11px 16px;color:var(--sidebar-active-text);text-decoration:none;font-size:13px;transition:.2s;}
.user-menu-dropdown a:hover{background:var(--sidebar-active-bg);color:var(--sidebar-text-hover);}
.user-menu-dropdown a.danger{color:#f87171;border-top:1px solid var(--sidebar-border);}
.user-menu-dropdown a.danger:hover{background:#2a1414;}
/* কমন কম্পোনেন্ট */
.card-custom{background:var(--card-bg);border:1px solid var(--card-border);border-radius:var(--border-radius);}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px}
.stat{background:var(--card-bg);border:1px solid var(--card-border);border-radius:var(--border-radius);padding:20px 24px;}
.stat .num{font-size:32px;font-weight:700;color:var(--primary-color)}
.stat .lbl{font-size:13px;color:var(--sidebar-text);margin-top:4px}
.quick-links{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}
.quick-card{display:flex;align-items:center;gap:14px;background:var(--card-bg);border:1px solid var(--card-border);border-radius:var(--border-radius);padding:18px 20px;text-decoration:none;transition:.2s}
.quick-card:hover{border-color:var(--primary-color);}
.qc-icon{font-size:24px;flex-shrink:0;width:40px;height:40px;display:flex;align-items:center;justify-content:center;background:var(--sidebar-active-bg);border-radius:8px}
.qc-title{font-size:14px;font-weight:var(--font-weight-medium);color:var(--body-text)}
.qc-sub{font-size:11px;color:var(--sidebar-text);margin-top:2px}
table{color:var(--body-text);}
.table thead th{border-color:var(--card-border);color:var(--sidebar-text);font-size:12px;}
.table td{border-color:var(--card-border);vertical-align:middle;}
.form-control,.form-select{background:var(--sidebar-bg);border:1px solid var(--sidebar-border);color:var(--body-text);}
.form-control:focus,.form-select:focus{background:var(--sidebar-bg);border-color:var(--sidebar-accent);color:var(--body-text);box-shadow:0 0 0 .2rem rgba(139,92,246,.2);}
.form-control::placeholder{color:var(--sidebar-text);}
label,.form-label{color:var(--sidebar-text);}
.btn-primary{background-color:var(--primary-color);border-color:var(--primary-color);}
.alert{border:1px solid var(--sidebar-border);}
.alert-success{background:#0f3d2e;color:#4ade80;border-color:#1a5c42;}
.alert-danger{background:#3d1414;color:#f87171;border-color:#5c1f1f;}
.alert-info{background:#0f2d3d;color:#5eb8e0;border-color:#1a4d5c;}
.alert-warning{background:#3d340f;color:#facc15;border-color:#5c4f1a;}
.alert-secondary{background:var(--card-bg);color:var(--sidebar-text);border-color:var(--card-border);}
.btn-close{filter:invert(1) grayscale(1);}
.badge-active{background:var(--badge-active)!important;}
.badge-trial{background:var(--badge-trial)!important;}
.badge-suspended{background:var(--badge-suspended)!important;}
.badge-blocked{background:var(--badge-blocked)!important;}
.badge-cancelled{background:var(--badge-cancelled)!important;}
@media(max-width:768px){
  .sidebar{transform:translateX(-100%);}
  .sidebar.mob-open{transform:translateX(0);width:220px;}
  .main{margin-left:0 !important;padding:16px;padding-top:64px;max-width:100%;}
  .user-menu{position:fixed;top:10px;right:54px;}
  .topbar{flex-direction:column;align-items:flex-start;gap:10px;}
  #sbToggle{left:10px !important;top:10px;}
}
<?php echo renderFooterCreditCSS(); ?>
</style>
</head>
<body>
<button id="sbToggle" title="মেনু ছোট/বড় করুন" onclick="toggleSidebar()">&#9776;</button>
<div class="sidebar" id="saasSidebar">
  <div class="brand">
    <div class="brand-text">
      <?php if ($branding['logo']): ?>
        <img src="<?php echo htmlspecialchars($branding['logo']); ?>" alt="Logo">
        <h2><?php echo htmlspecialchars($branding['name']); ?></h2>
      <?php else: ?>
        <h2>🏫 <?php echo htmlspecialchars($branding['name']); ?></h2>
      <?php endif; ?>
      <p>Master Control</p>
    </div>
  </div>
  <?php if (in_array($_SESSION['master_role'] ?? '', ['superadmin','admin'], true)): ?><a href="demo_requests.php"><span class="icon">✉</span> Demo requests</a><?php endif; ?>
  <a href="dashboard.php" class="<?php echo $activeKey === 'dashboard' ? 'active' : ''; ?>"><span class="icon">🏠</span> Dashboard</a>
  <a href="#" class="nav-parent <?php echo $smOpen ? 'active' : ''; ?>" onclick="toggleMenu(event,'smMenu','smArrow')"><span class="icon">🏫</span> স্কুল ম্যানেজমেন্ট<span class="nav-arrow" id="smArrow"><?php echo $smOpen ? '▾' : '▸'; ?></span></a>
  <div class="sub-menu" id="smMenu" style="max-height:<?php echo $smOpen ? '160px' : '0'; ?>;">
    <a href="client_list.php" class="sub-link <?php echo $activeKey === 'client_list' ? 'active' : ''; ?>"><span class="icon">📋</span> স্কুলের তালিকা</a>
    <a href="create_tenant.php" class="sub-link <?php echo $activeKey === 'create_tenant' ? 'active' : ''; ?>"><span class="icon">➕</span> নতুন স্কুল</a>
    <a href="migrate_existing_tenant.php" class="sub-link <?php echo $activeKey === 'migrate_tenant' ? 'active' : ''; ?>"><span class="icon">🔁</span> পুরনো স্কুল মাইগ্রেট</a>
    <a href="trash.php" class="sub-link <?php echo $activeKey === 'trash' ? 'active' : ''; ?>"><span class="icon">🗑️</span> Trash</a>
  </div>
  <a href="deploy.php" class="<?php echo $activeKey === 'deploy' ? 'active' : ''; ?>"><span class="icon">🚀</span> Deploy</a>
  <a href="domain_tracker.php" class="<?php echo $activeKey === 'domain_tracker' ? 'active' : ''; ?>"><span class="icon">🌐</span> Domain Tracker</a>
  <a href="billing.php" class="<?php echo $activeKey === 'billing' ? 'active' : ''; ?>"><span class="icon">💳</span> Billing</a>
  <a href="activity.php" class="<?php echo $activeKey === 'activity' ? 'active' : ''; ?>"><span class="icon">📜</span> Activity Log</a>
  <?php if (($_SESSION['master_role'] ?? '') === 'superadmin'): ?>
  <a href="users.php" class="<?php echo $activeKey === 'users' ? 'active' : ''; ?>"><span class="icon">👥</span> Users</a>
  <?php endif; ?>
  <a href="#" class="nav-parent <?php echo $setOpen ? 'active' : ''; ?>" onclick="toggleMenu(event,'setMenu','setArrow')"><span class="icon">⚙️</span> Settings<span class="nav-arrow" id="setArrow"><?php echo $setOpen ? '▾' : '▸'; ?></span></a>
  <div class="sub-menu" id="setMenu" style="max-height:<?php echo $setOpen ? '180px' : '0'; ?>;">
    <a href="settings.php?tab=branding" class="sub-link <?php echo $activeKey === 'settings_branding' ? 'active' : ''; ?>"><span class="icon">🖼️</span> Branding</a>
    <a href="settings.php?tab=theme" class="sub-link <?php echo $activeKey === 'settings_theme' ? 'active' : ''; ?>"><span class="icon">🎨</span> থিম</a>
    <a href="settings.php?tab=sms" class="sub-link <?php echo $activeKey === 'settings_sms' ? 'active' : ''; ?>"><span class="icon">📩</span> SMS Gateway</a>
    <a href="settings.php?tab=security" class="sub-link <?php echo $activeKey === 'settings_security' ? 'active' : ''; ?>"><span class="icon">🔐</span> Security</a>
    <a href="change_password.php" class="sub-link <?php echo $activeKey === 'change_password' ? 'active' : ''; ?>"><span class="icon">🔒</span> পাসওয়ার্ড</a>
  </div>
  <?php echo renderFooterCredit($pdo_master, 'sidebar'); ?>
</div>
<script>if(window.innerWidth>768 && localStorage.getItem('saas_sidebar_collapsed')==='1'){document.getElementById('saasSidebar').classList.add('collapsed');document.body.classList.add('sidebar-collapsed');}</script>

<div class="user-menu" id="userMenu">
  <div class="user-menu-btn" onclick="toggleUserMenu(event)">👤 <?php echo htmlspecialchars($_SESSION['master_name'] ?? ''); ?><span class="um-arrow">▾</span></div>
  <div class="user-menu-dropdown">
    <a href="change_password.php"><span class="icon">🔒</span> পাসওয়ার্ড পরিবর্তন</a>
    <a href="logout.php" class="danger"><span class="icon">🚪</span> Logout</a>
  </div>
</div>

<div class="main">
  <div class="topbar">
    <h1><?php echo $pageIcon; ?> <?php echo htmlspecialchars($pageTitle); ?></h1>
  </div>
