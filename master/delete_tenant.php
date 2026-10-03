<?php
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_features.php';
masterRequireAccess($pdo_master, 'client_list', 'edit');
ensureTenantTrashColumns($pdo_master);

// এখন আর সরাসরি DELETE করা হয় না — tenant রেকর্ডটা "Trash" এ সরিয়ে
// রাখা হয় (deleted_at সেট করে), যাতে ভুলে ডিলিট হয়ে গেলেও Trash মেনু
// থেকে Restore করে ফিরিয়ে আনা যায়। আসলেই স্থায়ীভাবে মুছতে হলে
// Trash পেজ থেকে "Permanently Delete" করতে হবে।
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null)) {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo_master->prepare("SELECT * FROM tenants WHERE id=? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $tenant = $stmt->fetch();
    if ($tenant) {
        $pdo_master->prepare("UPDATE tenants SET deleted_at = NOW(), deleted_by = ? WHERE id = ?")
            ->execute([$_SESSION['master_user_id'] ?? null, $id]);
        logActivity($pdo_master, $id, 'tenant_trashed', $tenant['school_name'] . ' (' . $tenant['school_code'] . ')');
        $_SESSION['dash_msg'] = '🗑️ "' . $tenant['school_name'] . '" Trash-এ সরানো হয়েছে। প্রয়োজন হলে Trash মেনু থেকে ফিরিয়ে আনতে পারবে।';
    }
}
header('Location: client_list.php');
exit;
