<?php
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_features.php';
masterRequireAccess($pdo_master, 'trash', 'edit');
ensureTenantTrashColumns($pdo_master);

// Trash-এ থাকা তথ্যই শুধু স্থায়ীভাবে ডিলিট করা যাবে (deleted_at IS NOT NULL) —
// এইটা একটা সেফটি চেক, যাতে ভুলে Active কোনো স্কুল সরাসরি এখান দিয়ে মুছে না যায়
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo_master->prepare("SELECT * FROM tenants WHERE id=? AND deleted_at IS NOT NULL");
    $stmt->execute([$id]);
    $tenant = $stmt->fetch();
    if ($tenant) {
        $pdo_master->prepare("DELETE FROM tenants WHERE id=?")->execute([$id]);
        logActivity($pdo_master, null, 'tenant_purged', $tenant['school_name'] . ' (' . $tenant['school_code'] . ')');
        $_SESSION['dash_msg'] = '🗑️ "' . $tenant['school_name'] . '" স্থায়ীভাবে ডিলিট করা হয়েছে। (মনে রেখো: এর DB/ডাটা এখান থেকে মুছে যায়নি, শুধু tenant রেকর্ডটা মুছে গেছে)';
    }
}
header('Location: trash.php');
exit;
