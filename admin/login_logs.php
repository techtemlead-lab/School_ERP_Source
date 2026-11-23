<?php
// admin/login_logs.php - User Management > Login / Session Log
// কে (Username), কোন User Type, কোন IP/Device/Browser/OS থেকে, কতবার লগইন
// (সফল/ব্যর্থ) করেছে তার সম্পূর্ণ হিস্ট্রি — login_logs টেবিল থেকে (log হয় admin/index.php-তে)
// শুধুমাত্র Super Admin এই পেজ দেখতে পারবে।
require_once __DIR__ . '/../config/table_print.php';

$is_super_admin = $_SESSION['is_super_admin'] ?? true;
if (!$is_super_admin) {
    echo '<div class="card-custom"><div class="alert alert-danger mb-0"><i class="fa-solid fa-lock me-2"></i>শুধুমাত্র <b>Super Admin</b> Login Log অ্যাক্সেস করতে পারবেন।</div></div>';
    return;
}

// টেবিল না থাকলে অটো তৈরি (self-healing — admin/index.php তেও একই CREATE আছে)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS login_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL,
        user_type VARCHAR(30) NOT NULL,
        login_status VARCHAR(10) NOT NULL DEFAULT 'Success',
        ip_address VARCHAR(45) NULL,
        device_type VARCHAR(20) NULL,
        browser VARCHAR(50) NULL,
        os VARCHAR(50) NULL,
        user_agent VARCHAR(255) NULL,
        login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_username (username),
        INDEX idx_login_time (login_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

function llv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$ll_msg = $_SESSION['ll_msg'] ?? '';
unset($_SESSION['ll_msg']);

// পুরনো লগ মুছে ফেলা (ঐচ্ছিক হাউজকিপিং — ৯০+ দিনের পুরনো লগ মুছবে)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_old_logs'])) {
    if (csrf_verify($_POST['csrf_token'] ?? null)) {
        $pdo->exec("DELETE FROM login_logs WHERE login_time < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        $_SESSION['ll_msg'] = '৯০ দিনের বেশি পুরনো লগ মুছে ফেলা হয়েছে।';
    }
    header('Location: index.php?page=login_logs');
    exit();
}

// -----------------------------------------------------------
// ফিল্টার
// -----------------------------------------------------------
$f_uname   = trim($_GET['f_uname'] ?? '');
$f_type    = $_GET['f_type'] ?? 'All';
$f_status  = $_GET['f_status'] ?? 'All';
$f_ip      = trim($_GET['f_ip'] ?? '');
$f_from    = trim($_GET['f_from'] ?? '');
$f_to      = trim($_GET['f_to'] ?? '');
$is_print_all = tprint_is_print_all();
$page_no   = max(1, (int) ($_GET['p'] ?? 1));
$per_page  = 50;

$where = [];
$params = [];
if ($f_uname !== '') { $where[] = 'username LIKE ?'; $params[] = "%$f_uname%"; }
if ($f_type !== 'All') { $where[] = 'user_type = ?'; $params[] = $f_type; }
if ($f_status !== 'All') { $where[] = 'login_status = ?'; $params[] = $f_status; }
if ($f_ip !== '') { $where[] = 'ip_address LIKE ?'; $params[] = "%$f_ip%"; }
if ($f_from !== '') { $where[] = 'login_time >= ?'; $params[] = $f_from . ' 00:00:00'; }
if ($f_to !== '') { $where[] = 'login_time <= ?'; $params[] = $f_to . ' 23:59:59'; }
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// টোটাল কাউন্ট (পেজিনেশনের জন্য)
$cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM login_logs $where_sql");
$cnt_stmt->execute($params);
$total_rows = (int) $cnt_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_rows / $per_page));
$page_no = min($page_no, $total_pages);
$offset = $is_print_all ? 0 : (($page_no - 1) * $per_page);
$query_limit = $is_print_all ? 2000 : $per_page;

$sql = "SELECT * FROM login_logs $where_sql ORDER BY login_time DESC LIMIT $query_limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// টপ সামারি — কোন ইউজার কতবার সফলভাবে লগইন করেছে (একই ফিল্টার উইন্ডোর মধ্যে, সর্বোচ্চ ১০ জন)
$sum_stmt = $pdo->prepare(
    "SELECT username, user_type, COUNT(*) AS cnt, MAX(login_time) AS last_time
     FROM login_logs
     " . ($where_sql ? $where_sql . " AND login_status = 'Success'" : "WHERE login_status = 'Success'") . "
     GROUP BY username, user_type ORDER BY cnt DESC LIMIT 10"
);
$sum_stmt->execute($params);
$summary_rows = $sum_stmt->fetchAll();

$user_types_ll = ['Super Admin', 'Accountant', 'Admin', 'Data Entry Operator', 'Demo', 'Human Resource', 'Student', 'Teacher', 'Unknown'];
$csrf = htmlspecialchars(csrf_token());
?>
<?php echo tprint_page_css('landscape', 10); ?>
<style>
    #llFilterRow.collapse:not(.show) { display: none; }
    #llFilterRow.collapse.show { display: table-row; }
</style>

<?php echo tprint_header_html($pdo, 'Login / Session Log', 'Total Records: ' . $total_rows); ?>

<div class="card-custom mb-3">
    <?php if ($ll_msg): ?><div class="alert alert-success py-2"><?php echo llv($ll_msg); ?></div><?php endif; ?>
    <h6 class="fw-bold mb-3"><i class="fa-solid fa-ranking-star me-1"></i> সবচেয়ে বেশি লগইন করা ইউজার (এই ফিল্টার অনুযায়ী, সর্বোচ্চ ১০ জন)</h6>
    <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0" style="font-size:13px;">
            <thead class="table-light">
                <tr><th>Username</th><th>User Type</th><th>মোট সফল লগইন</th><th>সর্বশেষ লগইন</th></tr>
            </thead>
            <tbody>
                <?php if ($summary_rows): foreach ($summary_rows as $s): ?>
                    <tr>
                        <td><?php echo llv($s['username']); ?></td>
                        <td><?php echo llv($s['user_type']); ?></td>
                        <td><span class="badge bg-primary"><?php echo (int) $s['cnt']; ?></span></td>
                        <td><?php echo llv(date('Y-m-d H:i', strtotime($s['last_time']))); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="4" class="text-center text-muted py-2">কোনো ডাটা নেই</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card-custom">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print">
        <div class="text-muted small">মোট <?php echo $total_rows; ?> টি লগইন রেকর্ড — Page <?php echo $page_no; ?> / <?php echo $total_pages; ?></div>
        <div class="d-flex align-items-center gap-1 flex-wrap">
            <?php echo tprint_controls([
                'orient'        => 'landscape',
                'wrap'          => false,
                'print_all_url' => tprint_all_url(),
                'total_count'   => $total_rows,
                'shown_count'   => count($rows),
                'is_print_all'  => $is_print_all,
            ]); ?>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-1" data-bs-toggle="collapse" data-bs-target="#llFilterRow" title="Show/Hide Filter"><i class="fa-solid fa-chevron-up"></i></button>
            <a href="index.php?page=login_logs" class="btn btn-sm btn-outline-danger" title="Clear Filter"><i class="fa-solid fa-xmark"></i></a>
            <form method="POST" action="index.php?page=login_logs" class="d-inline" onsubmit="return confirm('৯০ দিনের বেশি পুরনো সব লগ মুছে যাবে, নিশ্চিত?');">
                <?php echo csrf_field(); ?>
                <button type="submit" name="clear_old_logs" class="btn btn-sm btn-outline-warning" title="Clear logs older than 90 days"><i class="fa-solid fa-broom"></i> পুরনো লগ Clear</button>
            </form>
        </div>
    </div>

    <form method="GET" action="index.php">
        <input type="hidden" name="page" value="login_logs">
        <div class="table-responsive">
            <table class="table table-bordered align-middle" style="font-size:13px;">
                <thead class="table-light">
                    <tr>
                        <th>Srl.</th>
                        <th>Username</th>
                        <th>User Type</th>
                        <th>Status</th>
                        <th>IP Address</th>
                        <th>Device</th>
                        <th>Browser</th>
                        <th>OS</th>
                        <th>Login Time</th>
                    </tr>
                    <tr class="collapse show no-print" id="llFilterRow">
                        <th></th>
                        <th><input type="text" name="f_uname" class="form-control form-control-sm" placeholder="Username" value="<?php echo llv($f_uname); ?>"></th>
                        <th>
                            <select name="f_type" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="All">All</option>
                                <?php foreach ($user_types_ll as $ut): ?>
                                    <option value="<?php echo llv($ut); ?>" <?php echo $f_type === $ut ? 'selected' : ''; ?>><?php echo llv($ut); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th>
                            <select name="f_status" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="All" <?php echo $f_status === 'All' ? 'selected' : ''; ?>>All</option>
                                <option value="Success" <?php echo $f_status === 'Success' ? 'selected' : ''; ?>>Success</option>
                                <option value="Failed" <?php echo $f_status === 'Failed' ? 'selected' : ''; ?>>Failed</option>
                            </select>
                        </th>
                        <th><input type="text" name="f_ip" class="form-control form-control-sm" placeholder="IP" value="<?php echo llv($f_ip); ?>"></th>
                        <th colspan="2">
                            <div class="d-flex gap-1">
                                <input type="date" name="f_from" class="form-control form-control-sm" value="<?php echo llv($f_from); ?>" title="From">
                                <input type="date" name="f_to" class="form-control form-control-sm" value="<?php echo llv($f_to); ?>" title="To">
                            </div>
                        </th>
                        <th></th>
                        <th><button type="submit" class="btn btn-sm btn-outline-primary w-100">Filter</button></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): $srl = $offset + 1; foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo llv($row['username']); ?></td>
                            <td><?php echo llv($row['user_type']); ?></td>
                            <td><?php echo $row['login_status'] === 'Success' ? '<span class="badge bg-success">Success</span>' : '<span class="badge bg-danger">Failed</span>'; ?></td>
                            <td><?php echo llv($row['ip_address']); ?></td>
                            <td><?php echo llv($row['device_type']); ?></td>
                            <td><?php echo llv($row['browser']); ?></td>
                            <td><?php echo llv($row['os']); ?></td>
                            <td class="text-nowrap"><?php echo llv(date('Y-m-d H:i:s', strtotime($row['login_time']))); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="9" class="text-center text-muted py-3">No data found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (!$is_print_all && $total_pages > 1): ?>
            <nav class="d-flex justify-content-center no-print">
                <ul class="pagination pagination-sm">
                    <?php
                    $qs = $_GET; // pagination লিংকে বর্তমান ফিল্টার ধরে রাখার জন্য
                    unset($qs['print_all'], $qs['print_orient']);
                    for ($p = 1; $p <= $total_pages; $p++):
                        $qs['p'] = $p;
                        $qs['page'] = 'login_logs';
                        $url = 'index.php?' . http_build_query($qs);
                    ?>
                        <li class="page-item <?php echo $p === $page_no ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo llv($url); ?>"><?php echo $p; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </form>
</div>
