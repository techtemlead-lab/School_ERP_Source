<?php
// ══════════════════════════════════════════════════════════════
// School SaaS — Master Panel — নতুন ৩টা ফিচারের self-healing
// migration হেল্পার (ensureBillingTables()-এর মতোই প্যাটার্ন):
//   ১) Trash — tenants ডিলিট করলে সরাসরি না মুছে soft-delete
//   ২) tenants.status এ 'Blocked' যোগ (Active/Suspended/Cancelled এর পাশে)
//   ৩) Domain Tracker — ডোমেইনের মেয়াদ/রিনিউয়াল তথ্য রাখার নতুন টেবিল
// যে পেজে যেটা লাগবে, সেই পেজের শুরুতে require_once করে কল করলেই
// প্রথমবার ভিজিটেই টেবিল/কলাম নিজে থেকে তৈরি হয়ে যাবে।
// ══════════════════════════════════════════════════════════════

function ensureTenantTrashColumns($pdo) {
    static $done = false;
    if ($done) return;

    $ensureCol = function (PDO $pdo, string $table, string $column, string $definition) {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    };
    $ensureCol($pdo, 'tenants', 'deleted_at', 'DATETIME NULL DEFAULT NULL');
    $ensureCol($pdo, 'tenants', 'deleted_by', 'INT NULL DEFAULT NULL');

    $done = true;
}

// tenants.status এনামে 'Blocked' যোগ করে। পুরনো ভ্যালু (Active/Suspended/
// Cancelled) এবং পুরনো ডেটা কিছুই বদলায় না/মুছে না — শুধু নতুন একটা
// অপশন এলাউড লিস্টে যুক্ত হয়, তাই এটা সম্পূর্ণ নিরাপদ/non-destructive।
function ensureTenantBlockedStatus($pdo) {
    static $done = false;
    if ($done) return;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM tenants LIKE 'status'")->fetch();
        if ($col && stripos($col['Type'], "'Blocked'") === false) {
            $pdo->exec("ALTER TABLE tenants MODIFY status ENUM('Active','Suspended','Cancelled','Blocked') NOT NULL DEFAULT 'Active'");
        }
    } catch (Exception $e) { /* সমস্যা হলে পুরনো এনাম দিয়েই চলবে, ক্র্যাশ করবে না */ }
    $done = true;
}

function ensureDomainsTable($pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS domain_tracker (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        domain_name    VARCHAR(150) NOT NULL UNIQUE,
        tenant_id      INT NULL,
        registrar      VARCHAR(100) NULL,
        purchased_at   DATE NULL,
        expires_at     DATE NULL,
        renewal_cost   DECIMAL(10,2) NULL,
        auto_renew     TINYINT(1) NOT NULL DEFAULT 0,
        dns_provider   VARCHAR(100) NULL,
        nameservers    VARCHAR(255) NULL,
        notes          VARCHAR(255) NULL,
        created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

// Domain expiry তারিখ থেকে স্ট্যাটাস বের করে: Active / Expiring Soon / Expired / Unknown
function domainCalcStatus(?string $expiresAt): string {
    if (!$expiresAt) return 'Unknown';
    $today = new DateTime('today');
    $exp   = DateTime::createFromFormat('Y-m-d', $expiresAt) ?: new DateTime($expiresAt);
    $exp->setTime(0, 0, 0);
    if ($exp < $today) return 'Expired';
    $daysLeft = (int) $today->diff($exp)->format('%a');
    return $daysLeft <= 30 ? 'Expiring Soon' : 'Active';
}

function domainDaysLeft(?string $expiresAt): ?int {
    if (!$expiresAt) return null;
    $today = new DateTime('today');
    $exp   = DateTime::createFromFormat('Y-m-d', $expiresAt) ?: new DateTime($expiresAt);
    $exp->setTime(0, 0, 0);
    return (int) $today->diff($exp)->format('%r%a');
}
