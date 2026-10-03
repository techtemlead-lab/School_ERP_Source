<?php
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_features.php';
masterRequireAccess($pdo_master, 'client_list', 'view');
ensureTenantTrashColumns($pdo_master);
ensureTenantBlockedStatus($pdo_master);

$search = trim($_GET['q'] ?? '');
$fPlan  = trim($_GET['plan'] ?? '');
$fStat  = trim($_GET['status'] ?? '');

// deleted_at IS NULL — Trash-এ থাকা স্কুল এই তালিকায় দেখাবে না
$sql  = "SELECT * FROM tenants WHERE deleted_at IS NULL";
$args = [];
if ($search !== '') {
    $sql .= " AND (school_name LIKE ? OR school_code LIKE ? OR subdomain LIKE ? OR custom_domain LIKE ? OR contact_name LIKE ? OR contact_phone LIKE ?)";
    $like = '%' . $search . '%';
    array_push($args, $like, $like, $like, $like, $like, $like);
}
if ($fPlan !== '') { $sql .= " AND plan = ?"; $args[] = $fPlan; }
if ($fStat !== '') { $sql .= " AND status = ?"; $args[] = $fStat; }
$sql .= " ORDER BY id DESC";

$stmt = $pdo_master->prepare($sql);
$stmt->execute($args);
$tenants = $stmt->fetchAll();

$flash = $_SESSION['dash_msg'] ?? null;
unset($_SESSION['dash_msg']);

$pageTitle = 'স্কুলের তালিকা';
$pageIcon  = '📋';
$activeKey = 'client_list';
require_once __DIR__ . '/_layout_top.php';
?>
    <?php if ($flash): ?><div class="alert alert-info"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <div class="card-custom p-3 mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-bold">খুঁজুন</label>
                <input type="text" name="q" class="form-control" placeholder="স্কুলের নাম, কোড, ফোন..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Plan</label>
                <select name="plan" class="form-select">
                    <option value="">সব</option>
                    <?php foreach (['Trial','Basic','Standard','Premium'] as $p): ?>
                    <option value="<?php echo $p; ?>" <?php echo $fPlan === $p ? 'selected' : ''; ?>><?php echo $p; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Status</label>
                <select name="status" class="form-select">
                    <option value="">সব</option>
                    <?php foreach (['Active','Suspended','Blocked','Cancelled'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $fStat === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary flex-fill"><i class="fa fa-search"></i></button>
                <a href="client_list.php" class="btn btn-outline-secondary flex-fill" title="Reset"><i class="fa fa-rotate-left"></i></a>
            </div>
        </form>
    </div>

    <div class="card-custom p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold mb-0">মোট <?php echo count($tenants); ?> টা স্কুল</h6>
            <div class="d-flex gap-2">
                <a href="trash.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-trash me-1"></i> Trash</a>
                <a href="create_tenant.php" class="btn btn-sm btn-success"><i class="fa fa-plus me-1"></i> নতুন স্কুল</a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>স্কুলের নাম</th>
                        <th>Subdomain / Domain</th>
                        <th>Plan</th>
                        <th>Status</th>
                        <th>যোগাযোগ</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$tenants): ?>
                    <tr><td colspan="7" class="text-center py-4" style="color:var(--sidebar-text)">কিছু পাওয়া যায়নি।</td></tr>
                <?php endif; ?>
                <?php foreach ($tenants as $t): ?>
                    <?php
                        $badgeClass = ['Active' => 'badge-active', 'Suspended' => 'badge-suspended', 'Blocked' => 'badge-blocked', 'Cancelled' => 'badge-cancelled'][$t['status']] ?? 'badge-active';
                        $statusIcon = ['Active' => '✅', 'Suspended' => '⏸️', 'Blocked' => '🚫', 'Cancelled' => '🗑️'][$t['status']] ?? '';
                    ?>
                    <tr>
                        <td>#<?php echo $t['id']; ?></td>
                        <td class="fw-bold"><?php echo htmlspecialchars($t['school_name']); ?><br><small style="color:var(--sidebar-text)"><?php echo htmlspecialchars($t['school_code']); ?></small></td>
                        <td><code><?php echo htmlspecialchars($t['subdomain'] ?: ($t['custom_domain'] ?: '-')); ?></code></td>
                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($t['plan']); ?></span></td>
                        <td><span class="badge <?php echo $badgeClass; ?>"><?php echo $statusIcon; ?> <?php echo htmlspecialchars($t['status']); ?></span></td>
                        <td><small><?php echo htmlspecialchars($t['contact_name'] ?: '-'); ?><br><?php echo htmlspecialchars($t['contact_phone'] ?: ''); ?></small></td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <a href="edit_tenant.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa fa-pen"></i></a>
                                <a href="deploy.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Deploy SQL"><i class="fa fa-rocket"></i></a>
                                <?php if ($t['status'] !== 'Active'): ?>
                                    <form method="POST" action="toggle_status.php" onsubmit="return confirm('Activate করবে?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="tenant_id" value="<?php echo $t['id']; ?>">
                                        <input type="hidden" name="new_status" value="Active">
                                        <button class="btn btn-sm btn-outline-success" title="Activate"><i class="fa fa-check"></i></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($t['status'] === 'Active'): ?>
                                    <form method="POST" action="toggle_status.php" onsubmit="return confirm('Suspend করবে? (সাময়িক বন্ধ)');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="tenant_id" value="<?php echo $t['id']; ?>">
                                        <input type="hidden" name="new_status" value="Suspended">
                                        <button class="btn btn-sm btn-outline-warning" title="Suspend (সাময়িক বন্ধ)"><i class="fa fa-pause"></i></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($t['status'] !== 'Blocked'): ?>
                                    <form method="POST" action="toggle_status.php" onsubmit="return confirm('Block করবে? (এই স্কুল আর অ্যাক্সেস করতে পারবে না, পরে চাইলে Activate করে ফিরিয়ে আনা যাবে)');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="tenant_id" value="<?php echo $t['id']; ?>">
                                        <input type="hidden" name="new_status" value="Blocked">
                                        <button class="btn btn-sm btn-outline-danger" title="Block"><i class="fa fa-ban"></i></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($t['status'] !== 'Cancelled'): ?>
                                    <form method="POST" action="toggle_status.php" onsubmit="return confirm('একদম বাতিল করবে? এটা সহজে ফেরানো যাবে না।');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="tenant_id" value="<?php echo $t['id']; ?>">
                                        <input type="hidden" name="new_status" value="Cancelled">
                                        <button class="btn btn-sm btn-outline-secondary" title="Cancel"><i class="fa fa-xmark"></i></button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" action="delete_tenant.php" onsubmit="return confirm('এই স্কুলটা Trash-এ সরাবে (স্থায়ীভাবে ডিলিট হবে না, পরে Trash থেকে ফিরিয়ে আনা যাবে)?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Trash-এ পাঠাও"><i class="fa fa-trash"></i></button>
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
