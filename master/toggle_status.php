<?php
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_features.php';
ensureTenantBlockedStatus($pdo_master);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['dash_msg'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করো।';
    } else {
        $tenant_id = (int) ($_POST['tenant_id'] ?? 0);
        $new_status = $_POST['new_status'] ?? '';

        if (in_array($new_status, ['Active', 'Suspended', 'Blocked', 'Cancelled'], true) && $tenant_id) {
            $stmt = $pdo_master->prepare('UPDATE tenants SET status = ? WHERE id = ?');
            $stmt->execute([$new_status, $tenant_id]);
            $t = $pdo_master->prepare('SELECT school_name FROM tenants WHERE id=?');
            $t->execute([$tenant_id]);
            logActivity($pdo_master, $tenant_id, 'status_changed', ($t->fetchColumn() ?: '') . ' → ' . $new_status);
            $_SESSION['dash_msg'] = "স্ট্যাটাস বদলে {$new_status} করা হয়েছে।";
        }
    }
}
header('Location: dashboard.php');
exit();
