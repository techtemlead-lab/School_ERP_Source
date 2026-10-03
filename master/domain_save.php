<?php
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_features.php';
ensureDomainsTable($pdo_master);

$id = (int) ($_POST['id'] ?? 0);
masterRequireAccess($pdo_master, 'domain_tracker', $id ? 'edit' : 'add');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['dash_msg'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করো।';
        header('Location: domain_tracker.php');
        exit;
    }

    $domain_name  = trim($_POST['domain_name'] ?? '');
    $tenant_id    = trim($_POST['tenant_id'] ?? '') !== '' ? (int) $_POST['tenant_id'] : null;
    $registrar    = trim($_POST['registrar'] ?? '') ?: null;
    $purchased_at = trim($_POST['purchased_at'] ?? '') ?: null;
    $expires_at   = trim($_POST['expires_at'] ?? '') ?: null;
    $renewal_cost = trim($_POST['renewal_cost'] ?? '') !== '' ? (float) $_POST['renewal_cost'] : null;
    $auto_renew   = !empty($_POST['auto_renew']) ? 1 : 0;
    $dns_provider = trim($_POST['dns_provider'] ?? '') ?: null;
    $nameservers  = trim($_POST['nameservers'] ?? '') ?: null;
    $notes        = trim($_POST['notes'] ?? '') ?: null;

    if ($domain_name === '' || $expires_at === null) {
        $_SESSION['dash_msg'] = '⚠️ Domain Name আর Expiry Date আবশ্যক।';
        header('Location: domain_tracker.php');
        exit;
    }

    // একই domain_name অন্য এন্ট্রিতে থাকলে আটকাও (নিজেকে বাদ দিয়ে)
    $chk = $pdo_master->prepare("SELECT id FROM domain_tracker WHERE domain_name = ? AND id != ?");
    $chk->execute([$domain_name, $id]);
    if ($chk->fetch()) {
        $_SESSION['dash_msg'] = '⚠️ এই ডোমেইনটা আগেই যোগ করা আছে।';
        header('Location: domain_tracker.php');
        exit;
    }

    if ($id) {
        $stmt = $pdo_master->prepare("SELECT id FROM domain_tracker WHERE id=?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) { $id = 0; }
    }

    if ($id) {
        $pdo_master->prepare("UPDATE domain_tracker SET domain_name=?, tenant_id=?, registrar=?, purchased_at=?, expires_at=?, renewal_cost=?, auto_renew=?, dns_provider=?, nameservers=?, notes=? WHERE id=?")
            ->execute([$domain_name, $tenant_id, $registrar, $purchased_at, $expires_at, $renewal_cost, $auto_renew, $dns_provider, $nameservers, $notes, $id]);
        logActivity($pdo_master, $tenant_id, 'domain_updated', $domain_name);
        $_SESSION['dash_msg'] = '✅ "' . $domain_name . '" আপডেট হয়েছে।';
    } else {
        $pdo_master->prepare("INSERT INTO domain_tracker (domain_name, tenant_id, registrar, purchased_at, expires_at, renewal_cost, auto_renew, dns_provider, nameservers, notes) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$domain_name, $tenant_id, $registrar, $purchased_at, $expires_at, $renewal_cost, $auto_renew, $dns_provider, $nameservers, $notes]);
        logActivity($pdo_master, $tenant_id, 'domain_added', $domain_name);
        $_SESSION['dash_msg'] = '✅ "' . $domain_name . '" যোগ করা হয়েছে।';
    }
}
header('Location: domain_tracker.php');
exit;
