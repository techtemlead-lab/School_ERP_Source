<?php
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_features.php';
masterRequireAccess($pdo_master, 'dashboard', 'view');
ensureTenantTrashColumns($pdo_master);
ensureTenantBlockedStatus($pdo_master);

$total     = (int) $pdo_master->query("SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL")->fetchColumn();
$active    = (int) $pdo_master->query("SELECT COUNT(*) FROM tenants WHERE status='Active' AND deleted_at IS NULL")->fetchColumn();
$trial     = (int) $pdo_master->query("SELECT COUNT(*) FROM tenants WHERE (status='Trial' OR plan='Trial') AND deleted_at IS NULL")->fetchColumn();
$suspended = (int) $pdo_master->query("SELECT COUNT(*) FROM tenants WHERE status='Suspended' AND deleted_at IS NULL")->fetchColumn();
$blocked   = (int) $pdo_master->query("SELECT COUNT(*) FROM tenants WHERE status='Blocked' AND deleted_at IS NULL")->fetchColumn();
$trashed   = (int) $pdo_master->query("SELECT COUNT(*) FROM tenants WHERE deleted_at IS NOT NULL")->fetchColumn();

// ডোমেইন এক্সপায়ারিং সুন/এক্সপায়ার্ড কাউন্ট (Domain Tracker টেবিল না থাকলে চুপচাপ ০)
try {
    ensureDomainsTable($pdo_master);
    $expiringDomains = 0;
    $rows = $pdo_master->query("SELECT expires_at FROM domain_tracker WHERE expires_at IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $exp) {
        $st = domainCalcStatus($exp);
        if ($st === 'Expiring Soon' || $st === 'Expired') $expiringDomains++;
    }
} catch (PDOException $e) {
    $expiringDomains = 0;
}

// payment_requests টেবিল না থাকলে (এখনো _install_billing.php রান করা হয়নি) চুপচাপ ০ দেখাবে
try {
    $pending_count = (int) $pdo_master->query("SELECT COUNT(*) c FROM payment_requests WHERE status='Pending'")->fetch()['c'];
} catch (PDOException $e) {
    $pending_count = 0;
}

$flash = $_SESSION['dash_msg'] ?? null;
unset($_SESSION['dash_msg']);

$pageTitle = 'Dashboard';
$pageIcon  = '🏠';
$activeKey = 'dashboard';
require_once __DIR__ . '/_layout_top.php';
?>
    <?php if ($flash): ?><div class="alert alert-info"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <div class="stats">
        <div class="stat"><div class="num"><?php echo $total; ?></div><div class="lbl">মোট স্কুল</div></div>
        <div class="stat"><div class="num" style="color:var(--badge-active)"><?php echo $active; ?></div><div class="lbl">✅ Active</div></div>
        <div class="stat"><div class="num" style="color:var(--badge-trial)"><?php echo $trial; ?></div><div class="lbl">Trial</div></div>
        <div class="stat"><div class="num" style="color:var(--badge-suspended)"><?php echo $suspended; ?></div><div class="lbl">⏸️ Suspended</div></div>
        <div class="stat"><div class="num" style="color:var(--badge-blocked)"><?php echo $blocked; ?></div><div class="lbl">🚫 Blocked</div></div>
        <div class="stat"><div class="num" style="color:var(--badge-cancelled)"><?php echo $trashed; ?></div><div class="lbl">🗑️ Trash</div></div>
        <div class="stat"><div class="num" style="color:var(--badge-blocked)"><?php echo $expiringDomains; ?></div><div class="lbl">🌐 Domain মেয়াদ শেষ/কাছাকাছি</div></div>
        <div class="stat">
            <div class="num" style="color:var(--primary-color)"><?php echo $pending_count; ?></div>
            <div class="lbl">💳 Pending Billing</div>
        </div>
    </div>

    <div class="quick-links">
        <a href="client_list.php" class="quick-card">
            <span class="qc-icon">📋</span>
            <div><div class="qc-title">স্কুলের তালিকা</div><div class="qc-sub">সব স্কুল দেখুন ও ম্যানেজ করুন</div></div>
        </a>
        <a href="create_tenant.php" class="quick-card">
            <span class="qc-icon">➕</span>
            <div><div class="qc-title">নতুন স্কুল</div><div class="qc-sub">নতুন tenant যোগ করুন</div></div>
        </a>
        <a href="trash.php" class="quick-card">
            <span class="qc-icon">🗑️</span>
            <div><div class="qc-title">Trash</div><div class="qc-sub">ডিলিট করা স্কুল দেখুন/ফিরিয়ে আনুন</div></div>
        </a>
        <a href="domain_tracker.php" class="quick-card">
            <span class="qc-icon">🌐</span>
            <div><div class="qc-title">Domain Tracker</div><div class="qc-sub">ডোমেইনের মেয়াদ/রিনিউয়াল ট্র্যাক করুন</div></div>
        </a>
        <a href="activity.php" class="quick-card">
            <span class="qc-icon">📜</span>
            <div><div class="qc-title">Activity Log</div><div class="qc-sub">সাম্প্রতিক কার্যক্রম দেখুন</div></div>
        </a>
    </div>
<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
