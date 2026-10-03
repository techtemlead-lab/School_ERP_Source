<?php
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_features.php';
masterRequireAccess($pdo_master, 'domain_tracker', 'edit');
ensureDomainsTable($pdo_master);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo_master->prepare("SELECT * FROM domain_tracker WHERE id=?");
    $stmt->execute([$id]);
    $domain = $stmt->fetch();
    if ($domain) {
        $pdo_master->prepare("DELETE FROM domain_tracker WHERE id=?")->execute([$id]);
        logActivity($pdo_master, $domain['tenant_id'], 'domain_deleted', $domain['domain_name']);
        $_SESSION['dash_msg'] = '🗑️ "' . $domain['domain_name'] . '" ডিলিট করা হয়েছে।';
    }
}
header('Location: domain_tracker.php');
exit;
