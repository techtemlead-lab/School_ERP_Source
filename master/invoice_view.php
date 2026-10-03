<?php
require_once __DIR__ . '/_guard.php';
masterRequireAccess($pdo_master, 'billing', 'view');
ensureBillingTables($pdo_master);

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo_master->prepare(
    "SELECT pr.*, t.school_name, t.school_code
     FROM payment_requests pr
     JOIN tenants t ON t.id = pr.tenant_id
     WHERE pr.id = ? LIMIT 1"
);
$stmt->execute([$id]);
$req = $stmt->fetch();

require __DIR__ . '/invoice_template.php';
