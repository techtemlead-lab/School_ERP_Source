<?php
/**
 * cron/reset_demo_db.php
 * -----------------------------------------------------------
 * Automated Hourly Reset Engine for Demo School Sandbox.
 *
 * Restores the database `school_demo` back to its pristine golden state
 * from sql/demo_golden_snapshot.sql.
 *
 * Can be run via:
 * 1) Linux Crontab (e.g. 0 * * * * php /var/www/html/cron/reset_demo_db.php)
 * 2) Programmatic PHP function call: reset_demo_database()
 * 3) Direct Web Request (GET cron/reset_demo_db.php?key=demo_reset_secret_2026)
 */

function reset_demo_database(bool $force = false): array
{
    $start_time = microtime(true);
    $snapshot_file = __DIR__ . '/../sql/demo_golden_snapshot.sql';
    $timestamp_file = __DIR__ . '/demo_last_reset.txt';
    $log_file = __DIR__ . '/demo_reset.log';

    if (!file_exists($snapshot_file)) {
        return [
            'success' => false,
            'message' => 'Golden snapshot file not found at: ' . $snapshot_file
        ];
    }

    // Rate limit check unless $force is true (prevent spamming resets within 60 seconds)
    $last_reset = file_exists($timestamp_file) ? (int) @file_get_contents($timestamp_file) : 0;
    if (!$force && (time() - $last_reset) < 60) {
        return [
            'success' => true,
            'message' => 'Database was already reset recently. Next reset scheduled.',
            'last_reset' => $last_reset,
            'seconds_ago' => time() - $last_reset
        ];
    }

    // Resolve tenant credentials
    $db_name = getenv('DEMO_DB_NAME') ?: 'school_demo';
    $db_user = getenv('DEMO_DB_USER') ?: 'root';
    $db_pass = getenv('DEMO_DB_PASS') ?: 'root';
    $db_hosts = array_filter([getenv('DEMO_DB_HOST') ?: null, 'school-erp-mariadb', '127.0.0.1', 'localhost']);

    // Try finding config from master_db if available
    if (file_exists(__DIR__ . '/../config/master_db.php')) {
        try {
            require_once __DIR__ . '/../config/master_db.php';
            if (isset($pdo_master)) {
                $stmt = $pdo_master->prepare("SELECT * FROM tenants WHERE school_code = 'demo' LIMIT 1");
                $stmt->execute();
                $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($tenant) {
                    $db_name = $tenant['db_name'] ?? $db_name;
                    $db_user = $tenant['db_user'] ?? $db_user;
                    $db_pass = $tenant['db_pass'] ?? $db_pass;
                    if (!empty($tenant['db_host'])) {
                        array_unshift($db_hosts, $tenant['db_host']);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall back to defaults
        }
    }

    $db_hosts = array_unique($db_hosts);
    $pdo = null;
    $connected_host = '';
    $last_err = '';

    foreach ($db_hosts as $h) {
        try {
            $dsn = "mysql:host={$h};dbname={$db_name};charset=utf8mb4";
            $pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true
            ]);
            $connected_host = $h;
            break;
        } catch (\Throwable $e) {
            $last_err = $e->getMessage();
        }
    }

    if (!$pdo) {
        return [
            'success' => false,
            'message' => 'Could not connect to database on any candidate host. Last error: ' . $last_err
        ];
    }

    // Execute the pristine snapshot
    try {
        $sql = file_get_contents($snapshot_file);
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        $pdo->exec($sql);
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        require_once __DIR__ . '/../config/demo_sync.php';
        demo_after_snapshot($pdo);
    } catch (\Throwable $e) {
        return [
            'success' => false,
            'message' => 'Error executing snapshot SQL: ' . $e->getMessage()
        ];
    }

    // Clean up active sessions for demo in active_sessions table if exists
    try {
        $pdo->exec("DELETE FROM active_sessions WHERE username LIKE 'demo_%'");
    } catch (\Throwable $e) {
        // Table might not exist or already clean
    }

    // Clean up temporary uploads created by demo users in uploads/demo
    $demo_uploads_dir = __DIR__ . '/../uploads/demo';
    if (is_dir($demo_uploads_dir)) {
        try {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($demo_uploads_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                if ($fileinfo->getFilename() === '.htaccess' || $fileinfo->getFilename() === '.gitkeep') {
                    continue;
                }
                if ($fileinfo->isDir()) {
                    @rmdir($fileinfo->getRealPath());
                } else {
                    @unlink($fileinfo->getRealPath());
                }
            }
        } catch (\Throwable $e) {
            // Ignore filesystem errors during cleanup
        }
    }

    $now = time();
    @file_put_contents($timestamp_file, (string) $now);

    $duration = round(microtime(true) - $start_time, 2);
    $log_entry = "[" . date('Y-m-d H:i:s') . "] Demo DB reset completed in {$duration}s via {$connected_host}\n";
    @file_put_contents($log_file, $log_entry, FILE_APPEND);

    return [
        'success' => true,
        'message' => "Demo database successfully restored to pristine state in {$duration}s.",
        'reset_at' => date('Y-m-d H:i:s', $now),
        'timestamp' => $now,
        'duration' => $duration
    ];
}

// Check invocation context:
if (php_sapi_name() === 'cli') {
    echo "Starting Demo Database Reset...\n";
    $result = reset_demo_database(true);
    echo $result['message'] . "\n";
    exit($result['success'] ? 0 : 1);
}

// Web request invocation
if (isset($_GET['key']) || isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    // Simple protection key or action
    $valid_key = getenv('DEMO_RESET_KEY') ?: 'demo_reset_secret_2026';
    $req_key = $_GET['key'] ?? '';
    $force = isset($_GET['force']) && ($_GET['force'] === '1' || $_GET['force'] === 'true');

    if ($req_key !== $valid_key) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized reset request.']);
        exit;
    }

    $result = reset_demo_database($force);
    echo json_encode($result);
    exit;
}
