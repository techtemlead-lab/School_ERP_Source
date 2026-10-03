<?php
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_features.php';
masterRequireAccess($pdo_master, 'trash', 'view');
ensureTenantTrashColumns($pdo_master);

$stmt = $pdo_master->query(
    "SELECT t.*, u.name AS deleted_by_name
     FROM tenants t
     LEFT JOIN master_users u ON t.deleted_by = u.id
     WHERE t.deleted_at IS NOT NULL
     ORDER BY t.deleted_at DESC"
);
$trashed = $stmt->fetchAll();

$flash = $_SESSION['dash_msg'] ?? null;
unset($_SESSION['dash_msg']);

$pageTitle = 'Trash';
$pageIcon  = '🗑️';
$activeKey = 'trash';
require_once __DIR__ . '/_layout_top.php';
?>
    <?php if ($flash): ?><div class="alert alert-info"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <div class="alert alert-secondary" style="font-size:13px;">
        এখানে যেসব স্কুল "Trash-এ পাঠাও" দিয়ে ডিলিট করা হয়েছে সেগুলো জমা থাকে। যেকোনো সময় <b>Restore</b>
        করে ফিরিয়ে আনতে পারবে, অথবা <b>Permanently Delete</b> দিয়ে একদম স্থায়ীভাবে মুছে দিতে পারবে (এটা আর ফেরানো যাবে না)।
    </div>

    <div class="card-custom p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold mb-0">Trash-এ <?php echo count($trashed); ?> টা স্কুল</h6>
            <a href="client_list.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i> স্কুলের তালিকায় ফিরে যাও</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>স্কুলের নাম</th>
                        <th>Subdomain / Domain</th>
                        <th>Deleted At</th>
                        <th>Deleted By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$trashed): ?>
                    <tr><td colspan="6" class="text-center py-4" style="color:var(--sidebar-text)">Trash খালি।</td></tr>
                <?php endif; ?>
                <?php foreach ($trashed as $t): ?>
                    <tr>
                        <td>#<?php echo $t['id']; ?></td>
                        <td class="fw-bold"><?php echo htmlspecialchars($t['school_name']); ?><br><small style="color:var(--sidebar-text)"><?php echo htmlspecialchars($t['school_code']); ?></small></td>
                        <td><code><?php echo htmlspecialchars($t['subdomain'] ?: ($t['custom_domain'] ?: '-')); ?></code></td>
                        <td><small><?php echo htmlspecialchars($t['deleted_at']); ?></small></td>
                        <td><small><?php echo htmlspecialchars($t['deleted_by_name'] ?? '—'); ?></small></td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <form method="POST" action="restore_tenant.php" onsubmit="return confirm('এই স্কুলটা ফিরিয়ে আনবে?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                                    <button class="btn btn-sm btn-outline-success" title="Restore"><i class="fa fa-rotate-left me-1"></i> Restore</button>
                                </form>
                                <form method="POST" action="purge_tenant.php" onsubmit="return confirm('একদম স্থায়ীভাবে ডিলিট করবে? এটা আর কখনো ফেরানো যাবে না! (স্কুলের নিজস্ব DB/ডাটা এখান থেকে মুছে যায় না, শুধু tenant রেকর্ডটা মুছবে)');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Permanently Delete"><i class="fa fa-trash-can me-1"></i> স্থায়ীভাবে ডিলিট</button>
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
