<?php
// admin/security_activity_log.php - System & Security > Activity Log
// কে (Username), কখন, কোন মডিউলে, কী করলো (Login/Logout/Create/Update/Delete/Force Logout
// ইত্যাদি) তার সংক্ষিপ্ত ইতিহাস — activity_logs টেবিল থেকে (sec_log_activity() ফাংশন দিয়ে
// লেখা হয়, বর্তমানে Auth (Login/Logout/Auto Logout), Security (Settings/Force Logout) এবং
// User Management (Create/Update/Delete) মডিউল থেকে লগ হয়)।
require_once __DIR__ . '/../config/security_helpers.php';
sec_ensure_tables($pdo);

$is_super_admin = $_SESSION['is_super_admin'] ?? true;
if (!$is_super_admin) {
    echo '<div class="card-custom"><div class="alert alert-danger mb-0"><i class="fa-solid fa-lock me-2"></i>শুধুমাত্র <b>Super Admin</b> Activity Log দেখতে পারবেন।</div></div>';
    return;
}

function alv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$al_msg = $_SESSION['al_msg'] ?? '';
unset($_SESSION['al_msg']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_old_logs'])) {
    if (csrf_verify($_POST['csrf_token'] ?? null)) {
        $pdo->exec("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)");
        $_SESSION['al_msg'] = '১৮০ দিনের বেশি পুরনো Activity Log মুছে ফেলা হয়েছে।';
    }
    header('Location: index.php?page=security_activity_log');
    exit();
}

$f_uname  = trim($_GET['f_uname'] ?? '');
$f_module = trim($_GET['f_module'] ?? 'All');
$f_action = trim($_GET['f_action'] ?? 'All');
$f_from   = trim($_GET['f_from'] ?? '');
$f_to     = trim($_GET['f_to'] ?? '');
$page_no  = max(1, (int) ($_GET['p'] ?? 1));
$per_page = 50;

$where = [];
$params = [];
if ($f_uname !== '') { $where[] = 'username LIKE ?'; $params[] = "%$f_uname%"; }
if ($f_module !== 'All' && $f_module !== '') { $where[] = 'module = ?'; $params[] = $f_module; }
if ($f_action !== 'All' && $f_action !== '') { $where[] = 'action = ?'; $params[] = $f_action; }
if ($f_from !== '') { $where[] = 'created_at >= ?'; $params[] = $f_from . ' 00:00:00'; }
if ($f_to !== '') { $where[] = 'created_at <= ?'; $params[] = $f_to . ' 23:59:59'; }
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs $where_sql");
$cnt_stmt->execute($params);
$total_rows = (int) $cnt_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_rows / $per_page));
$page_no = min($page_no, $total_pages);
$offset = ($page_no - 1) * $per_page;

$sql = "SELECT * FROM activity_logs $where_sql ORDER BY created_at DESC LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$modules_al = $pdo->query("SELECT DISTINCT module FROM activity_logs ORDER BY module ASC")->fetchAll(PDO::FETCH_COLUMN);
$actions_al = $pdo->query("SELECT DISTINCT action FROM activity_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exp_stmt = $pdo->prepare("SELECT * FROM activity_logs $where_sql ORDER BY created_at DESC");
    $exp_stmt->execute($params);
    $exp_rows = $exp_stmt->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity_log_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Username', 'User Type', 'Module', 'Action', 'Description', 'IP Address', 'Time']);
    foreach ($exp_rows as $r) {
        fputcsv($out, [$r['username'], $r['user_type'], $r['module'], $r['action'], $r['description'], $r['ip_address'], $r['created_at']]);
    }
    fclose($out);
    exit();
}

$csrf = htmlspecialchars(csrf_token());
$action_badge = [
    'Login' => 'success', 'Logout' => 'secondary', 'Auto Logout' => 'warning',
    'Create' => 'success', 'Update' => 'primary', 'Delete' => 'danger', 'Force Logout' => 'danger',
];
?>
<style>
    #alFilterRow.collapse:not(.show) { display: none; }
    #alFilterRow.collapse.show { display: table-row; }
</style>

<div class="card-custom">
    <?php if ($al_msg): ?><div class="alert alert-success py-2"><?php echo alv($al_msg); ?></div><?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="text-muted small">মোট <?php echo $total_rows; ?> টি এন্ট্রি — Page <?php echo $page_no; ?> / <?php echo $total_pages; ?></div>
        <div class="d-flex gap-1">
            <a href="index.php?<?php echo http_build_query(array_merge($_GET, ['page' => 'security_activity_log', 'export' => 'csv'])); ?>" class="btn btn-sm btn-outline-success"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#alFilterRow" title="Show/Hide Filter"><i class="fa-solid fa-chevron-up"></i></button>
            <a href="index.php?page=security_activity_log" class="btn btn-sm btn-outline-danger" title="Clear Filter"><i class="fa-solid fa-xmark"></i></a>
            <form method="POST" action="index.php?page=security_activity_log" class="d-inline" onsubmit="return confirm('১৮০ দিনের বেশি পুরনো সব Activity Log মুছে যাবে, নিশ্চিত?');">
                <?php echo csrf_field(); ?>
                <button type="submit" name="clear_old_logs" class="btn btn-sm btn-outline-warning" title="Clear logs older than 180 days"><i class="fa-solid fa-broom"></i></button>
            </form>
        </div>
    </div>

    <form method="GET" action="index.php">
        <input type="hidden" name="page" value="security_activity_log">
        <div class="table-responsive">
            <table class="table table-bordered align-middle" style="font-size:13px;">
                <thead class="table-light">
                    <tr>
                        <th>Srl.</th><th>Username</th><th>User Type</th><th>Module</th><th>Action</th><th>Description</th><th>IP</th><th>Time</th>
                    </tr>
                    <tr class="collapse show" id="alFilterRow">
                        <th></th>
                        <th><input type="text" name="f_uname" class="form-control form-control-sm" placeholder="Username" value="<?php echo alv($f_uname); ?>"></th>
                        <th></th>
                        <th>
                            <select name="f_module" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="All">All</option>
                                <?php foreach ($modules_al as $m): ?>
                                    <option value="<?php echo alv($m); ?>" <?php echo $f_module === $m ? 'selected' : ''; ?>><?php echo alv($m); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th>
                            <select name="f_action" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="All">All</option>
                                <?php foreach ($actions_al as $a): ?>
                                    <option value="<?php echo alv($a); ?>" <?php echo $f_action === $a ? 'selected' : ''; ?>><?php echo alv($a); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th colspan="2">
                            <div class="d-flex gap-1">
                                <input type="date" name="f_from" class="form-control form-control-sm" value="<?php echo alv($f_from); ?>" title="From">
                                <input type="date" name="f_to" class="form-control form-control-sm" value="<?php echo alv($f_to); ?>" title="To">
                            </div>
                        </th>
                        <th><button type="submit" class="btn btn-sm btn-outline-primary w-100">Filter</button></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): $srl = $offset + 1; foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td class="fw-bold"><?php echo alv($row['username']); ?></td>
                            <td><?php echo alv($row['user_type']); ?></td>
                            <td><?php echo alv($row['module']); ?></td>
                            <td><span class="badge bg-<?php echo $action_badge[$row['action']] ?? 'secondary'; ?>"><?php echo alv($row['action']); ?></span></td>
                            <td><?php echo alv($row['description']); ?></td>
                            <td><?php echo alv($row['ip_address']); ?></td>
                            <td class="text-nowrap"><?php echo alv(date('Y-m-d H:i:s', strtotime($row['created_at']))); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="8" class="text-center text-muted py-3">কোনো Activity Log পাওয়া যায়নি</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <nav class="d-flex justify-content-center">
                <ul class="pagination pagination-sm">
                    <?php
                    $qs = $_GET;
                    for ($p = 1; $p <= $total_pages; $p++):
                        $qs['p'] = $p;
                        $qs['page'] = 'security_activity_log';
                        $url = 'index.php?' . http_build_query($qs);
                    ?>
                        <li class="page-item <?php echo $p === $page_no ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo alv($url); ?>"><?php echo $p; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </form>
</div>
