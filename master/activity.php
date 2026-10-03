<?php
require_once __DIR__ . '/_guard.php';
masterRequireAccess($pdo_master, 'activity', 'view');
ensureActivityLogTable($pdo_master);

$logs = $pdo_master->query(
    "SELECT l.*, t.school_name, t.school_code, u.name AS done_by_name
     FROM activity_log l
     LEFT JOIN tenants t ON l.tenant_id = t.id
     LEFT JOIN master_users u ON l.done_by = u.id
     ORDER BY l.created_at DESC LIMIT 150"
)->fetchAll();

$pageTitle = 'Activity Log';
$pageIcon  = '📜';
$activeKey = 'activity';
require_once __DIR__ . '/_layout_top.php';
?>
    <div class="card-custom p-0" style="overflow:hidden;">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr><th>সময়</th><th>স্কুল</th><th>Action</th><th>Details</th><th>করেছে</th></tr>
                </thead>
                <tbody>
                <?php if (!$logs): ?>
                    <tr><td colspan="5" class="text-center py-4" style="color:var(--sidebar-text)">এখনো কোনো অ্যাক্টিভিটি নেই।</td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td><small style="color:var(--sidebar-text)"><?php echo htmlspecialchars($l['created_at']); ?></small></td>
                        <td><?php echo htmlspecialchars($l['school_name'] ?? '—'); ?></td>
                        <td><code style="background:var(--sidebar-bg);padding:2px 6px;border-radius:4px;font-size:11px;color:var(--sidebar-accent)"><?php echo htmlspecialchars($l['action']); ?></code></td>
                        <td style="color:var(--sidebar-text)"><?php echo htmlspecialchars($l['details']); ?></td>
                        <td><small><?php echo htmlspecialchars($l['done_by_name'] ?? 'System'); ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
