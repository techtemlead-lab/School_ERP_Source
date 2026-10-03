<?php
// admin/security_active_sessions.php - System & Security > Active Sessions
// এই মুহূর্তে সিস্টেমে কারা লগইন অবস্থায় আছে (active_sessions টেবিল থেকে) — কোন IP/Device/
// Browser থেকে, কতক্ষণ ধরে, শেষ কখন কিছু করেছে। এডমিন চাইলে কোনো একটা সেশনকে সাথে সাথে
// "Force Logout" করে দিতে পারবে — এরপর সেই ইউজারের পরবর্তী কোনো রিকোয়েস্টেই তাকে
// sec_enforce_session() (config/security_helpers.php) ধরে ফেলে লগইন পেজে পাঠিয়ে দেয়।
require_once __DIR__ . '/../config/security_helpers.php';
sec_ensure_tables($pdo);

$is_super_admin = $_SESSION['is_super_admin'] ?? true;
if (!$is_super_admin) {
    echo '<div class="card-custom"><div class="alert alert-danger mb-0"><i class="fa-solid fa-lock me-2"></i>শুধুমাত্র <b>Super Admin</b> Active Sessions দেখতে/ম্যানেজ করতে পারবেন।</div></div>';
    return;
}

function asv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$as_msg = $_SESSION['as_msg'] ?? '';
$as_err = $_SESSION['as_err'] ?? '';
unset($_SESSION['as_msg'], $_SESSION['as_err']);

// এই কারেন্ট রিকোয়েস্টের নিজের সেশন-কী — লিস্টে "এটাই আপনার বর্তমান সেশন" চিহ্নিত করার জন্য,
// এবং নিজের সেশনকে ভুলবশত Force Logout করে ফেলার ক্ষেত্রে একটা সতর্কবার্তা দেখানোর জন্য
$my_session_key = sec_session_key();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['as_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=security_active_sessions');
        exit();
    }

    if (isset($_POST['force_logout'])) {
        $target_key = trim($_POST['session_key'] ?? '');
        $chk = $pdo->prepare("SELECT username FROM active_sessions WHERE session_key = ? AND status = 'Active'");
        $chk->execute([$target_key]);
        $target_username = $chk->fetchColumn();

        if (!$target_username) {
            $_SESSION['as_err'] = 'এই সেশনটা আর Active নেই (হয়তো ইতিমধ্যে লগআউট হয়ে গেছে)।';
        } else {
            $pdo->prepare("UPDATE active_sessions SET status = 'ForceLogout' WHERE session_key = ?")->execute([$target_key]);
            sec_log_activity($pdo, 'Security', 'Force Logout', "{$target_username}-এর সেশন জোরপূর্বক লগআউট করা হয়েছে");
            $_SESSION['as_msg'] = "{$target_username}-এর সেশন জোরপূর্বক লগআউট করা হয়েছে — পরবর্তী পেজ লোডেই তিনি লগইন পেজে চলে যাবেন।";
        }
    } elseif (isset($_POST['force_logout_all_others'])) {
        $stmt = $pdo->prepare("UPDATE active_sessions SET status = 'ForceLogout' WHERE status = 'Active' AND session_key != ?");
        $stmt->execute([$my_session_key]);
        $count = $stmt->rowCount();
        sec_log_activity($pdo, 'Security', 'Force Logout', "নিজের বাদে বাকি সব ({$count} টা) Active সেশন জোরপূর্বক লগআউট করা হয়েছে");
        $_SESSION['as_msg'] = "নিজের সেশন বাদে বাকি {$count} টা Active সেশন জোরপূর্বক লগআউট করা হয়েছে।";
    }

    header('Location: index.php?page=security_active_sessions');
    exit();
}

// পুরনো Active রো যেগুলো আসলে Timeout-এর কারণে মৃত হয়ে গেছে (কিন্তু ইউজার আর কোনো
// রিকোয়েস্ট না করায় sec_enforce_session() ট্রিগার হয়নি) — লিস্টে দেখানোর আগেই সেগুলোকে
// "Expired" এ মার্ক করে দেওয়া হচ্ছে, যাতে "Active" লিস্টে বাস্তবে মৃত সেশন না দেখায়
$settings = sec_get_settings($pdo);
$timeout_minutes = (int) $settings['session_timeout_minutes'];
if ($timeout_minutes > 0) {
    $pdo->prepare("UPDATE active_sessions SET status = 'Expired' WHERE status = 'Active' AND last_activity < DATE_SUB(NOW(), INTERVAL ? MINUTE)")
        ->execute([$timeout_minutes]);
}

$rows = $pdo->query("SELECT * FROM active_sessions WHERE status = 'Active' ORDER BY last_activity DESC")->fetchAll();
$csrf = htmlspecialchars(csrf_token());
?>

<div class="card-custom mb-3">
    <?php if ($as_msg): ?><div class="alert alert-success py-2"><?php echo asv($as_msg); ?></div><?php endif; ?>
    <?php if ($as_err): ?><div class="alert alert-danger py-2"><?php echo asv($as_err); ?></div><?php endif; ?>
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h6 class="fw-bold mb-1"><i class="fa-solid fa-users me-1"></i> এই মুহূর্তে Active Sessions</h6>
            <div class="text-muted small">মোট <?php echo count($rows); ?> টা Active সেশন। Session Timeout: <b><?php echo $timeout_minutes > 0 ? $timeout_minutes . ' মিনিট' : 'বন্ধ'; ?></b> — <a href="index.php?page=security_settings">Settings বদলান</a></div>
        </div>
        <form method="POST" action="index.php?page=security_active_sessions" onsubmit="return confirm('আপনার নিজের সেশন বাদে বাকি সব Active সেশন জোরপূর্বক লগআউট হয়ে যাবে, নিশ্চিত?');">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <button type="submit" name="force_logout_all_others" class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-power-off me-1"></i>নিজের বাদে বাকি সব Logout করুন</button>
        </form>
    </div>
</div>

<div class="card-custom">
    <div class="table-responsive">
        <table class="table table-bordered align-middle" style="font-size:13px;">
            <thead class="table-light">
                <tr>
                    <th>Username</th><th>User Type</th><th>IP Address</th><th>Device</th><th>Browser</th><th>OS</th>
                    <th>Login Time</th><th>শেষ কার্যকলাপ</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): foreach ($rows as $row): $is_me = ($row['session_key'] === $my_session_key); ?>
                    <tr class="<?php echo $is_me ? 'table-primary' : ''; ?>">
                        <td class="fw-bold"><?php echo asv($row['username']); ?> <?php echo $is_me ? '<span class="badge bg-primary">এই আপনি</span>' : ''; ?></td>
                        <td><?php echo asv($row['user_type']); ?></td>
                        <td><?php echo asv($row['ip_address']); ?></td>
                        <td><?php echo asv($row['device_type']); ?></td>
                        <td><?php echo asv($row['browser']); ?></td>
                        <td><?php echo asv($row['os']); ?></td>
                        <td class="text-nowrap"><?php echo asv(date('Y-m-d H:i:s', strtotime($row['login_time']))); ?></td>
                        <td class="text-nowrap"><?php echo asv(date('Y-m-d H:i:s', strtotime($row['last_activity']))); ?></td>
                        <td>
                            <?php if ($is_me): ?>
                                <span class="text-muted small">—</span>
                            <?php else: ?>
                                <form method="POST" action="index.php?page=security_active_sessions" class="d-inline" onsubmit="return confirm('<?php echo asv($row['username']); ?>-কে জোরপূর্বক লগআউট করবেন?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="session_key" value="<?php echo asv($row['session_key']); ?>">
                                    <button type="submit" name="force_logout" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-power-off"></i> Force Logout</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9" class="text-center text-muted py-3">এই মুহূর্তে কোনো Active Session নেই</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
