<?php
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_features.php';
masterRequireAccess($pdo_master, 'trash', 'edit');
ensureTenantTrashColumns($pdo_master);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo_master->prepare("SELECT * FROM tenants WHERE id=? AND deleted_at IS NOT NULL");
    $stmt->execute([$id]);
    $tenant = $stmt->fetch();
    if ($tenant) {
        $pdo_master->prepare("UPDATE tenants SET deleted_at = NULL, deleted_by = NULL WHERE id = ?")->execute([$id]);
        logActivity($pdo_master, $id, 'tenant_restored', $tenant['school_name'] . ' (' . $tenant['school_code'] . ')');
        $_SESSION['dash_msg'] = '✅ "' . $tenant['school_name'] . '" ফিরিয়ে আনা হয়েছে।';
    }
}
header('Location: trash.php');
exit;
