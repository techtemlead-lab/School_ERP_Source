<?php
/**
 * cron/check_subscriptions.php
 * -----------------------------------------------------------
 * প্রতিদিন একবার cPanel Cron Job দিয়ে এই ফাইলটা রান করাতে হবে।
 * যেসব স্কুলের trial_ends_at বা subscription_ends_at পার হয়ে গেছে
 * (গ্রেস পিরিয়ড শেষে), তাদের status = 'Suspended' করে দেয়।
 *
 * cPanel Cron Job কমান্ড (দিনে একবার, রাত ১২টায়):
 *   0 0 * * * /usr/local/bin/php /home/tomarcpaneluser/public_html/cron/check_subscriptions.php
 *
 * (php এর সঠিক পাথ ExonHost সাপোর্ট থেকে জেনে নিও, সাধারণত `which php` দিয়ে বের করা যায়)
 */

require_once __DIR__ . '/../config/master_db.php'; // $pdo_master

$today = date('Y-m-d');

// Trial শেষ হয়ে grace period ও পার হয়ে গেছে এমন স্কুল
$stmt = $pdo_master->prepare("
    SELECT id, school_name, school_code, trial_ends_at, grace_days
    FROM tenants
    WHERE status = 'Active'
      AND plan = 'Trial'
      AND trial_ends_at IS NOT NULL
      AND DATE_ADD(trial_ends_at, INTERVAL grace_days DAY) < ?
");
$stmt->execute([$today]);
$expired_trials = $stmt->fetchAll();

// Paid subscription শেষ হয়ে grace period পার হয়ে গেছে এমন স্কুল
$stmt2 = $pdo_master->prepare("
    SELECT id, school_name, school_code, subscription_ends_at, grace_days
    FROM tenants
    WHERE status = 'Active'
      AND plan != 'Trial'
      AND subscription_ends_at IS NOT NULL
      AND DATE_ADD(subscription_ends_at, INTERVAL grace_days DAY) < ?
");
$stmt2->execute([$today]);
$expired_subs = $stmt2->fetchAll();

$to_suspend = array_merge($expired_trials, $expired_subs);
$suspended_count = 0;

foreach ($to_suspend as $t) {
    $upd = $pdo_master->prepare("UPDATE tenants SET status = 'Suspended' WHERE id = ?");
    $upd->execute([$t['id']]);
    $suspended_count++;
    echo "Suspended: {$t['school_name']} ({$t['school_code']})\n";
}

echo "\n--- সম্পন্ন। {$suspended_count} টা স্কুল Suspended করা হয়েছে। তারিখ: {$today} ---\n";
