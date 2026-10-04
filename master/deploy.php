<?php
require_once __DIR__ . '/_guard.php';
masterRequireAccess($pdo_master, 'deploy', 'edit');
if(erp_env('APP_ENV')==='production'){
 $pageTitle='Production releases';$activeKey='deploy';require __DIR__.'/_layout_top.php';
 echo '<div class="card-custom p-4"><h2>Production releases</h2><p>Push or merge reviewed changes to the stable branch. GitHub Actions verifies the application, creates a private backup and applies additive migrations before updating each runtime.</p><p>School SQL credentials and direct database maintenance are restricted to the operator runtime.</p><a class="btn btn-primary" href="https://github.com/techtemlead-lab/School_ERP_Source/actions/workflows/deploy-stable.yml" target="_blank" rel="noopener">View release pipeline</a></div>';
 require __DIR__.'/_layout_bottom.php';exit;
}


// ── SQL Statement Parser ─────────────────────────────────────
// -- কমেন্ট লাইন ও /* */ ব্লক বাদ দিয়ে ';' দিয়ে split করে
// clean statement-দের array রিটার্ন করে।
function parseSqlStatements(string $sql): array {
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $lines = explode("\n", $sql);
    $lines = array_filter($lines, function ($l) {
        $t = ltrim($l);
        return $t !== '' && !str_starts_with($t, '--');
    });
    $sql = implode("\n", $lines);
    $stmts = array_map('trim', explode(';', $sql));
    $stmts = array_filter($stmts, fn($s) => strlen($s) > 3);
    return array_values($stmts);
}

$tenants = $pdo_master->query("SELECT id, school_name, school_code, db_host, db_name, db_user, db_pass FROM tenants ORDER BY school_name ASC")->fetchAll();

$results = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $results[] = ['label' => 'System', 'log' => [['❌', 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করো।']]];
    } else {
        $sqlText = $_POST['sql_text'] ?? '';
        $target  = $_POST['target'] ?? 'all'; // 'all' অথবা tenant id
        $statements = parseSqlStatements($sqlText);

        if (!$statements) {
            $results[] = ['label' => 'System', 'log' => [['❌', 'কোনো valid SQL statement পাওয়া যায়নি।']]];
        } else {
            $targets = $target === 'all' ? $tenants : array_filter($tenants, fn($t) => (string) $t['id'] === (string) $target);

            foreach ($targets as $t) {
                $log = [];
                try {
                    $tp = new PDO(
                        "mysql:host={$t['db_host']};dbname={$t['db_name']};charset=utf8mb4",
                        $t['db_user'], $t['db_pass'],
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );
                    $okCount = 0;
                    foreach ($statements as $stmt) {
                        try {
                            $tp->exec($stmt);
                            $okCount++;
                        } catch (PDOException $e) {
                            $log[] = ['⚠️', substr($stmt, 0, 60) . '... → ' . $e->getMessage()];
                        }
                    }
                    $log[] = ['✅', "{$okCount}/" . count($statements) . " statement সফলভাবে রান হয়েছে।"];
                } catch (PDOException $e) {
                    $log[] = ['❌', 'DB কানেক্ট করা যায়নি: ' . $e->getMessage()];
                }
                $results[] = ['label' => $t['school_name'] . ' (' . $t['school_code'] . ')', 'log' => $log];
            }
            logActivity($pdo_master, $target === 'all' ? null : (int) $target, 'deploy_sql', count($statements) . ' statement · ' . ($target === 'all' ? 'সব স্কুল' : 'একটা স্কুল'));
            $ran = true;
        }
    }
}

$preselectId = (int) ($_GET['id'] ?? 0);

$pageTitle = 'Deploy SQL';
$pageIcon  = '🚀';
$activeKey = 'deploy';
require_once __DIR__ . '/_layout_top.php';
?>
    <style>pre.log-box { background: var(--sidebar-bg); border: 1px solid var(--sidebar-border); color: var(--body-text); padding: 12px; border-radius: 8px; font-size: 12.5px; max-height: 220px; overflow: auto; }</style>

    <div class="alert alert-secondary" style="font-size:13px;">
        ⚠️ এখানে যেই SQL দিবে সেটা সরাসরি স্কুলের ডাটাবেজে রান হয়ে যাবে (যেমন নতুন মডিউল যোগ করার পর সব স্কুলের DB-তে নতুন টেবিল/কলাম বসানো)। <code>CREATE TABLE IF NOT EXISTS</code> বা <code>ALTER TABLE ... ADD COLUMN</code> স্টাইলের SQL সবচেয়ে নিরাপদ — আগে থেকে ব্যাকআপ রাখা ভালো।
    </div>

    <div class="card-custom p-4 mb-3">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="mb-3">
                <label class="form-label small fw-bold">কোন স্কুলে রান করবে</label>
                <select name="target" class="form-select">
                    <option value="all">🌐 সব স্কুল (<?php echo count($tenants); ?> টা)</option>
                    <?php foreach ($tenants as $t): ?>
                    <option value="<?php echo $t['id']; ?>" <?php echo $preselectId === (int) $t['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['school_name']); ?> (<?php echo htmlspecialchars($t['school_code']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold">SQL Statement(s)</label>
                <textarea name="sql_text" class="form-control" rows="8" placeholder="ALTER TABLE students ADD COLUMN blood_group VARCHAR(5) NULL;" required><?php echo htmlspecialchars($_POST['sql_text'] ?? ''); ?></textarea>
            </div>
            <button type="submit" class="btn btn-danger fw-bold" onclick="return confirm('নিশ্চিত? এটা সরাসরি স্কুলের ডাটাবেজে রান হয়ে যাবে।');"><i class="fa fa-rocket me-1"></i> Deploy করো</button>
        </form>
    </div>

    <?php if ($ran): ?>
    <div class="card-custom p-3">
        <h6 class="fw-bold mb-3">রেজাল্ট</h6>
        <?php foreach ($results as $r): ?>
            <div class="mb-3">
                <div class="fw-bold mb-1" style="font-size:13px;"><?php echo htmlspecialchars($r['label']); ?></div>
                <pre class="log-box mb-0"><?php foreach ($r['log'] as $l) { echo htmlspecialchars($l[0] . ' ' . $l[1]) . "\n"; } ?></pre>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
