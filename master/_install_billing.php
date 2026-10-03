<?php
require_once __DIR__ . '/_guard.php';

/**
 * master/_install_billing.php
 * -----------------------------------------------------------
 * একবার ভিজিট করলেই billing এর জন্য দরকারি টেবিল/কলাম তৈরি হয়ে যাবে।
 * বারবার ভিজিট করলেও সমস্যা নাই (idempotent — ensure_column প্যাটার্ন)।
 */

function master_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

$log = [];

// ১) tenants টেবিলে বিলিং-সংক্রান্ত কলাম যোগ করা (আগে থেকেই থাকলে স্কিপ)
master_ensure_column($pdo_master, 'tenants', 'last_payment_at', 'DATE NULL AFTER subscription_ends_at');
master_ensure_column($pdo_master, 'tenants', 'grace_days', 'INT NOT NULL DEFAULT 3 AFTER last_payment_at');
$log[] = '✅ tenants টেবিলে billing কলাম চেক/যোগ করা হয়েছে।';

// ২) payment_requests টেবিল
$pdo_master->exec("
    CREATE TABLE IF NOT EXISTS payment_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        method ENUM('bKash','Nagad') NOT NULL,
        sender_number VARCHAR(30) NOT NULL,
        transaction_id VARCHAR(50) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        months INT NOT NULL DEFAULT 1,
        note VARCHAR(255) NULL,
        status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
        admin_note VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at TIMESTAMP NULL,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$log[] = '✅ payment_requests টেবিল রেডি।';
?>
<!DOCTYPE html>
<html lang="bn">
<head><meta charset="UTF-8"><title>Billing Install</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"></head>
<body class="p-4">
<div class="container" style="max-width:600px;">
    <h5 class="fw-bold mb-3">🧩 Billing Setup</h5>
    <?php foreach ($log as $l): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($l); ?></div><?php endforeach; ?>
    <a href="dashboard.php" class="btn btn-primary">Dashboard এ ফিরে যাও</a>
</div>
</body>
</html>
