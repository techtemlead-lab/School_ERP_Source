<?php
require_once __DIR__ . '/config/runtime.php';
erp_session_start();
require_once __DIR__ . '/config/master_db.php'; // $pdo_master
require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/config/csrf.php';

$pay = require __DIR__ . '/config/payment_settings.php';

$school_code = trim($_GET['school'] ?? $_POST['school_code'] ?? '');
$tenant = null;
$error = '';
$success = false;

if ($school_code) {
    $stmt = $pdo_master->prepare('SELECT * FROM tenants WHERE school_code = ? LIMIT 1');
    $stmt->execute([$school_code]);
    $tenant = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করো।';
    } elseif (!$tenant) {
        $error = 'স্কুল খুঁজে পাওয়া যায়নি।';
    } else {
        $method = $_POST['method'] ?? '';
        $sender = trim($_POST['sender_number'] ?? '');
        $txn    = trim($_POST['transaction_id'] ?? '');
        $amount = trim($_POST['amount'] ?? '');
        $months = (int) ($_POST['months'] ?? 1);
        $note   = trim($_POST['note'] ?? '');

        if (!in_array($method, ['bKash', 'Nagad'], true) || $sender === '' || $txn === '' || $amount === '') {
            $error = 'সব ফিল্ড পূরণ করো।';
        } else {
            $ins = $pdo_master->prepare(
                'INSERT INTO payment_requests (tenant_id, method, sender_number, transaction_id, amount, months, note, status)
                 VALUES (?,?,?,?,?,?,?,\'Pending\')'
            );
            $ins->execute([$tenant['id'], $method, $sender, $txn, $amount, max(1, $months), $note]);
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>সাবস্ক্রিপশন রিনিউ করুন</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background: #f1f5f9; }
        .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: none; }
        .pay-box { background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; padding: 14px; }
    </style>
</head>
<body class="p-4">
<div class="container" style="max-width: 560px;">
    <h4 class="fw-bold text-center mb-4">💳 সাবস্ক্রিপশন রিনিউ</h4>

    <?php if (!$tenant): ?>
        <div class="card-custom p-4">
            <p class="text-muted">স্কুলের Code দিয়ে খুঁজো (তোমার admin login লিংকে যেটা ব্যবহার হয়)।</p>
            <form method="GET">
                <div class="input-group">
                    <input type="text" name="school" class="form-control" placeholder="school code" required>
                    <button class="btn btn-primary">খুঁজো</button>
                </div>
            </form>
        </div>

    <?php elseif ($success): ?>
        <div class="card-custom p-4 text-center">
            <h5 class="fw-bold text-success">✅ পেমেন্ট রিকোয়েস্ট জমা হয়েছে!</h5>
            <p class="text-muted mb-0">যাচাই করে দ্রুতই তোমার স্কুল আবার চালু করে দেওয়া হবে (সাধারণত কয়েক ঘণ্টার মধ্যে)। কোনো সমস্যা হলে <?php echo htmlspecialchars($pay['support_phone']); ?> এ কল করো।</p>
        </div>

    <?php else: ?>
        <div class="card-custom p-4 mb-3">
            <h6 class="fw-bold"><?php echo htmlspecialchars($tenant['school_name']); ?></h6>
            <p class="mb-1">বর্তমান প্ল্যান: <b><?php echo htmlspecialchars($tenant['plan']); ?></b></p>
            <p class="mb-0">মাসিক ফি: <b>৳<?php echo number_format($pay['plan_prices'][$tenant['plan']] ?? 0); ?></b> / মাস</p>
        </div>

        <div class="pay-box mb-3">
            <b>যেভাবে পেমেন্ট করবে:</b>
            <ol class="mb-2 mt-2" style="font-size:14px;">
                <li><b>bKash</b> (<?php echo htmlspecialchars($pay['bkash_type']); ?>): <code><?php echo htmlspecialchars($pay['bkash_number']); ?></code> — Send Money করো</li>
                <li><b>Nagad</b> (<?php echo htmlspecialchars($pay['nagad_type']); ?>): <code><?php echo htmlspecialchars($pay['nagad_number']); ?></code> — Send Money করো</li>
                <li>টাকা পাঠানোর পর নিচের ফর্মে Transaction ID দিয়ে সাবমিট করো</li>
            </ol>
            <small class="text-muted">সমস্যা হলে কল করো: <?php echo htmlspecialchars($pay['support_phone']); ?></small>
        </div>

        <?php if ($error): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="card-custom p-4">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="school_code" value="<?php echo htmlspecialchars($tenant['school_code']); ?>">

                <div class="mb-3">
                    <label class="form-label small fw-bold">পেমেন্ট মাধ্যম</label>
                    <select name="method" class="form-select" required>
                        <option value="bKash">bKash</option>
                        <option value="Nagad">Nagad</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">যে নাম্বার থেকে পাঠিয়েছো</label>
                    <input type="text" name="sender_number" class="form-control" required placeholder="017XXXXXXXX">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Transaction ID</label>
                    <input type="text" name="transaction_id" class="form-control" required placeholder="8N7A5B2C1D">
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-bold">টাকার পরিমাণ</label>
                        <input type="number" name="amount" class="form-control" required min="1">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold">কত মাসের জন্য</label>
                        <input type="number" name="months" class="form-control" value="1" min="1" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">নোট (ঐচ্ছিক)</label>
                    <input type="text" name="note" class="form-control">
                </div>
                <button type="submit" name="submit_payment" class="btn btn-success fw-bold w-100 py-2">পেমেন্ট রিকোয়েস্ট জমা দাও</button>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
