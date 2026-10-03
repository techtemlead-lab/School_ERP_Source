<?php
require_once __DIR__ . '/_guard.php';
masterRequireAccess($pdo_master, 'billing', 'view');

// payment_requests টেবিল (আর দরকারি tenants কলাম) অটো-তৈরি — আলাদা করে
// _install_billing.php ভিজিট করা লাগবে না
ensureBillingTables($pdo_master);

// --- Approve/Reject অ্যাকশন ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['billing_msg'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করো।';
    } else {
        $req_id = (int) ($_POST['request_id'] ?? 0);
        $action = $_POST['action'];

        $stmt = $pdo_master->prepare('SELECT * FROM payment_requests WHERE id = ? AND status = "Pending"');
        $stmt->execute([$req_id]);
        $req = $stmt->fetch();

        if ($req) {
            if ($action === 'approve') {
                // Tenant এর subscription_ends_at বাড়িয়ে দাও (আজ থেকে অথবা বিদ্যমান তারিখ থেকে, যেটা পরে)
                $t = $pdo_master->prepare('SELECT * FROM tenants WHERE id = ?');
                $t->execute([$req['tenant_id']]);
                $tenant = $t->fetch();

                if ($tenant) {
                    $base_date = $tenant['subscription_ends_at'] && $tenant['subscription_ends_at'] >= date('Y-m-d')
                        ? $tenant['subscription_ends_at']
                        : date('Y-m-d');
                    $new_end = date('Y-m-d', strtotime($base_date . ' + ' . (int) $req['months'] . ' months'));

                    $upd = $pdo_master->prepare(
                        "UPDATE tenants SET status = 'Active', subscription_ends_at = ?, last_payment_at = CURDATE() WHERE id = ?"
                    );
                    $upd->execute([$new_end, $tenant['id']]);

                    $ru = $pdo_master->prepare("UPDATE payment_requests SET status='Approved', reviewed_at = NOW() WHERE id = ?");
                    $ru->execute([$req_id]);
                    logActivity($pdo_master, $tenant['id'], 'payment_approved', '৳' . $req['amount'] . ' — ' . $req['months'] . ' মাস');
                    if (function_exists('smsSendPaymentSuccess')) {
                        smsSendPaymentSuccess($pdo_master, $tenant['id'], (float) $req['amount']);
                    }

                    $_SESSION['billing_msg'] = "✅ Approved — {$tenant['school_name']} এখন {$new_end} পর্যন্ত Active।";
                }
            } elseif ($action === 'reject') {
                $note = trim($_POST['admin_note'] ?? '');
                $ru = $pdo_master->prepare("UPDATE payment_requests SET status='Rejected', admin_note=?, reviewed_at = NOW() WHERE id = ?");
                $ru->execute([$note, $req_id]);
                logActivity($pdo_master, $req['tenant_id'], 'payment_rejected', $note);
                $_SESSION['billing_msg'] = '❌ Payment request reject করা হয়েছে।';
            }
        }
    }
    header('Location: billing.php');
    exit();
}

$pending  = $pdo_master->query("SELECT pr.*, t.school_name, t.school_code FROM payment_requests pr JOIN tenants t ON t.id = pr.tenant_id WHERE pr.status='Pending' ORDER BY pr.created_at ASC")->fetchAll();
$recent   = $pdo_master->query("SELECT pr.*, t.school_name FROM payment_requests pr JOIN tenants t ON t.id = pr.tenant_id WHERE pr.status!='Pending' ORDER BY pr.reviewed_at DESC LIMIT 15")->fetchAll();
$flash = $_SESSION['billing_msg'] ?? null;
unset($_SESSION['billing_msg']);
$pageTitle = 'Billing — Payment Requests';
$pageIcon  = '💳';
$activeKey = 'billing';
require_once __DIR__ . '/_layout_top.php';
?>
        <?php if ($flash): ?><div class="alert alert-info"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

        <h6 class="fw-bold mb-3">⏳ Pending (<?php echo count($pending); ?>)</h6>
        <div class="card-custom p-3 mb-4">
            <?php if (!$pending): ?>
                <p class="text-muted mb-0 text-center py-3">কোনো pending রিকোয়েস্ট নেই।</p>
            <?php endif; ?>
            <?php foreach ($pending as $r): ?>
                <div class="border rounded p-3 mb-2">
                    <div class="d-flex justify-content-between flex-wrap">
                        <div>
                            <b><?php echo htmlspecialchars($r['school_name']); ?></b>
                            <span class="text-muted"> (<?php echo htmlspecialchars($r['school_code']); ?>)</span><br>
                            <small><?php echo htmlspecialchars($r['method']); ?> · <?php echo htmlspecialchars($r['sender_number']); ?> · TrxID: <b><?php echo htmlspecialchars($r['transaction_id']); ?></b></small><br>
                            <small>৳<?php echo number_format((float) $r['amount'], 2); ?> — <?php echo (int) $r['months']; ?> মাস — <?php echo htmlspecialchars($r['created_at']); ?></small>
                            <?php if ($r['note']): ?><br><small class="text-muted">নোট: <?php echo htmlspecialchars($r['note']); ?></small><?php endif; ?>
                        </div>
                        <div class="d-flex gap-2 align-items-start mt-2 mt-md-0">
                            <form method="POST" onsubmit="return confirm('Approve করবে?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                                <input type="hidden" name="action" value="approve">
                                <button class="btn btn-success btn-sm fw-bold">✅ Approve</button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Reject করবে?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                                <input type="hidden" name="action" value="reject">
                                <button class="btn btn-outline-danger btn-sm fw-bold">❌ Reject</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <h6 class="fw-bold mb-3">সাম্প্রতিক ইতিহাস</h6>
        <div class="card-custom p-3">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>স্কুল</th><th>মাধ্যম</th><th>পরিমাণ</th><th>স্ট্যাটাস</th><th>তারিখ</th><th>Invoice</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['school_name']); ?></td>
                            <td><?php echo htmlspecialchars($r['method']); ?></td>
                            <td>৳<?php echo number_format((float) $r['amount'], 2); ?></td>
                            <td><span class="badge bg-<?php echo $r['status'] === 'Approved' ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars($r['status']); ?></span></td>
                            <td><small><?php echo htmlspecialchars($r['reviewed_at']); ?></small></td>
                            <td><a href="invoice_view.php?id=<?php echo $r['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fa fa-receipt"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recent): ?><tr><td colspan="6" class="text-center text-muted py-3">এখনো কিছু নেই।</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
