<?php
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_features.php';
masterRequireAccess($pdo_master, 'domain_tracker', 'view');
ensureDomainsTable($pdo_master);

$search = trim($_GET['q'] ?? '');
$fStat  = trim($_GET['status'] ?? '');

$sql  = "SELECT d.*, t.school_name FROM domain_tracker d LEFT JOIN tenants t ON d.tenant_id = t.id WHERE 1=1";
$args = [];
if ($search !== '') {
    $sql .= " AND (d.domain_name LIKE ? OR d.registrar LIKE ? OR t.school_name LIKE ?)";
    $like = '%' . $search . '%';
    array_push($args, $like, $like, $like);
}
$sql .= " ORDER BY (d.expires_at IS NULL), d.expires_at ASC";

$stmt = $pdo_master->prepare($sql);
$stmt->execute($args);
$domains = $stmt->fetchAll();

// প্রতিটা ডোমেইনের জন্য মেয়াদ থেকে স্ট্যাটাস বের করা (Active/Expiring Soon/Expired/Unknown)
foreach ($domains as &$d) {
    $d['calc_status'] = domainCalcStatus($d['expires_at']);
    $d['days_left']   = domainDaysLeft($d['expires_at']);
}
unset($d);

if ($fStat !== '') {
    $domains = array_values(array_filter($domains, fn($d) => $d['calc_status'] === $fStat));
}

$tenants = $pdo_master->query("SELECT id, school_name FROM tenants WHERE deleted_at IS NULL ORDER BY school_name")->fetchAll();

$editRow = null;
if (!empty($_GET['edit'])) {
    $eid = (int) $_GET['edit'];
    $stmt = $pdo_master->prepare("SELECT * FROM domain_tracker WHERE id=?");
    $stmt->execute([$eid]);
    $editRow = $stmt->fetch();
}

$flash = $_SESSION['dash_msg'] ?? null;
unset($_SESSION['dash_msg']);

$pageTitle = 'Domain Tracker';
$pageIcon  = '🌐';
$activeKey = 'domain_tracker';
require_once __DIR__ . '/_layout_top.php';

$badgeMap = [
    'Active'         => 'badge-active',
    'Expiring Soon'  => 'badge-trial',
    'Expired'        => 'badge-blocked',
    'Unknown'        => 'badge-cancelled',
];
$iconMap = [
    'Active'         => '✅',
    'Expiring Soon'  => '⏳',
    'Expired'        => '⛔',
    'Unknown'        => '❔',
];
?>
    <?php if ($flash): ?><div class="alert alert-info"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <div class="card-custom p-3 mb-3">
        <h6 class="fw-bold mb-3"><?php echo $editRow ? '✏️ ডোমেইন এডিট করো' : '➕ নতুন ডোমেইন যোগ করো'; ?></h6>
        <form method="POST" action="domain_save.php" class="row g-2">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo $editRow['id'] ?? ''; ?>">

            <div class="col-md-3">
                <label class="form-label small fw-bold">Domain Name *</label>
                <input type="text" name="domain_name" class="form-control" placeholder="example.com" required value="<?php echo htmlspecialchars($editRow['domain_name'] ?? ''); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">যুক্ত স্কুল (ঐচ্ছিক)</label>
                <select name="tenant_id" class="form-select">
                    <option value="">— কোনো নির্দিষ্ট স্কুল না —</option>
                    <?php foreach ($tenants as $t): ?>
                    <option value="<?php echo $t['id']; ?>" <?php echo (isset($editRow['tenant_id']) && (int)$editRow['tenant_id'] === (int)$t['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['school_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Registrar</label>
                <input type="text" name="registrar" class="form-control" placeholder="e.g. Namecheap" value="<?php echo htmlspecialchars($editRow['registrar'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">DNS Provider</label>
                <input type="text" name="dns_provider" class="form-control" placeholder="e.g. Cloudflare" value="<?php echo htmlspecialchars($editRow['dns_provider'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Renewal Cost (৳)</label>
                <input type="number" step="0.01" name="renewal_cost" class="form-control" value="<?php echo htmlspecialchars($editRow['renewal_cost'] ?? ''); ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-bold">Purchase Date</label>
                <input type="date" name="purchased_at" class="form-control" value="<?php echo htmlspecialchars($editRow['purchased_at'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Expiry Date *</label>
                <input type="date" name="expires_at" class="form-control" required value="<?php echo htmlspecialchars($editRow['expires_at'] ?? ''); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Nameservers</label>
                <input type="text" name="nameservers" class="form-control" placeholder="ns1.example.com, ns2.example.com" value="<?php echo htmlspecialchars($editRow['nameservers'] ?? ''); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Notes</label>
                <input type="text" name="notes" class="form-control" value="<?php echo htmlspecialchars($editRow['notes'] ?? ''); ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="auto_renew" id="autoRenew" value="1" <?php echo !empty($editRow['auto_renew']) ? 'checked' : ''; ?>>
                    <label class="form-check-label small" for="autoRenew">Auto Renew</label>
                </div>
            </div>

            <div class="col-12 d-flex gap-2 mt-2">
                <button class="btn btn-primary"><?php echo $editRow ? 'আপডেট করো' : 'যোগ করো'; ?></button>
                <?php if ($editRow): ?><a href="domain_tracker.php" class="btn btn-outline-secondary">বাতিল</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card-custom p-3">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h6 class="fw-bold mb-0">মোট <?php echo count($domains); ?> টা ডোমেইন</h6>
            <form method="GET" class="d-flex gap-2">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="ডোমেইন/রেজিস্ট্রার/স্কুল খুঁজুন..." value="<?php echo htmlspecialchars($search); ?>">
                <select name="status" class="form-select form-select-sm">
                    <option value="">সব স্ট্যাটাস</option>
                    <?php foreach (['Active','Expiring Soon','Expired','Unknown'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $fStat === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-primary"><i class="fa fa-search"></i></button>
                <a href="domain_tracker.php" class="btn btn-sm btn-outline-secondary" title="Reset"><i class="fa fa-rotate-left"></i></a>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th>যুক্ত স্কুল</th>
                        <th>Registrar / DNS</th>
                        <th>Expiry</th>
                        <th>Renewal Cost</th>
                        <th>Auto Renew</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$domains): ?>
                    <tr><td colspan="8" class="text-center py-4" style="color:var(--sidebar-text)">কোনো ডোমেইন যোগ করা হয়নি।</td></tr>
                <?php endif; ?>
                <?php foreach ($domains as $d): ?>
                    <tr>
                        <td class="fw-bold"><?php echo htmlspecialchars($d['domain_name']); ?><?php if ($d['notes']): ?><br><small style="color:var(--sidebar-text)"><?php echo htmlspecialchars($d['notes']); ?></small><?php endif; ?></td>
                        <td><small><?php echo htmlspecialchars($d['school_name'] ?? '—'); ?></small></td>
                        <td><small><?php echo htmlspecialchars($d['registrar'] ?: '-'); ?><br><?php echo htmlspecialchars($d['dns_provider'] ?: ''); ?></small></td>
                        <td>
                            <small>
                                <?php echo $d['expires_at'] ? htmlspecialchars($d['expires_at']) : '—'; ?><br>
                                <?php if ($d['days_left'] !== null): ?>
                                    <span style="color:var(--sidebar-text)">
                                        <?php echo $d['days_left'] >= 0 ? $d['days_left'] . ' দিন বাকি' : abs($d['days_left']) . ' দিন আগে মেয়াদ শেষ হয়েছে'; ?>
                                    </span>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td><small><?php echo $d['renewal_cost'] !== null ? '৳' . htmlspecialchars($d['renewal_cost']) : '-'; ?></small></td>
                        <td><?php echo $d['auto_renew'] ? '✅' : '—'; ?></td>
                        <td><span class="badge <?php echo $badgeMap[$d['calc_status']]; ?>"><?php echo $iconMap[$d['calc_status']]; ?> <?php echo $d['calc_status']; ?></span></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="domain_tracker.php?edit=<?php echo $d['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa fa-pen"></i></a>
                                <form method="POST" action="domain_delete.php" onsubmit="return confirm('এই ডোমেইন এন্ট্রিটা ডিলিট করবে?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
