<?php
/**
 * config/demo_sync.php
 * -----------------------------------------------------------
 * Each public-demo visitor sandbox stays aligned with the current codebase:
 *
 *   1. Every table definition in application PHP and sql/*.sql (except the
 *      frozen golden snapshot) is applied when the table is missing.
 *   2. Columns present in those statements, or in *_ensure_column() calls,
 *      are added when the running table is behind the code.
 *   3. Shared ensure_*() helpers run so module migrations travel with the code.
 *   4. Dummy rows are inserted only where a table is still empty, so a code
 *      change fills new features without wiping a demo someone is using.
 *
 * New sandboxes run this sync when they are created. An existing sandbox runs
 * it again on the next request after application PHP or SQL changes, because
 * the fingerprint stored in that database no longer matches the source tree.
 */

function demo_root(): string
{
    return dirname(__DIR__);
}

function demo_fingerprint_path(): string
{
    return demo_root() . '/cron/demo_code_fingerprint.txt';
}

function demo_source_iterator(): RecursiveIteratorIterator
{
    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator(demo_root(), FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $current): bool {
            if ($current->isDir()) {
                return !in_array($current->getFilename(), ['.git', 'pdf_lib', 'uploads', 'logs', 'node_modules'], true);
            }
            return true;
        }
    );
    return new RecursiveIteratorIterator($filter);
}

function demo_source_fingerprint(): string
{
    $root = demo_root();
    $lines = [];
    foreach (demo_source_iterator() as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) {
            continue;
        }
        $rel = substr($file->getPathname(), strlen($root) + 1);
        if (!demo_source_is_tracked($rel)) {
            continue;
        }
        $lines[] = $rel . '|' . $file->getSize() . '|' . $file->getMTime();
    }
    sort($lines);
    return hash('sha256', implode("\n", $lines));
}

function demo_source_is_tracked(string $rel): bool
{
    $rel = str_replace('\\', '/', $rel);
    if (str_starts_with($rel, '.git/') || str_starts_with($rel, 'config/pdf_lib/')
        || str_starts_with($rel, 'uploads/') || str_starts_with($rel, 'logs/')
        || str_starts_with($rel, 'master/assets/uploads/')) {
        return false;
    }
    if ($rel === 'sql/demo_golden_snapshot.sql' || $rel === 'cron/demo_code_fingerprint.txt') {
        return false;
    }
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    return $ext === 'php' || $ext === 'sql';
}

function demo_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function demo_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, DATA_TYPE, COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute([$table]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['COLUMN_NAME']] = $row;
    }
    return $out;
}

function demo_count(PDO $pdo, string $table): int
{
    if (!demo_table_exists($pdo, $table)) {
        return 0;
    }
    return (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}

/**
 * @return array<string,string> table => SQL
 */
function demo_collect_create_statements(): array
{
    $best = [];
    foreach (demo_iter_source_files() as $path) {
        $text = @file_get_contents($path);
        if ($text === false || !str_contains(strtoupper($text), 'CREATE TABLE')) {
            continue;
        }
        foreach (demo_extract_create_sql($text) as $sql) {
            if (!preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([A-Za-z0-9_]+)/i', $sql, $m)) {
                continue;
            }
            $table = $m[1];
            if (!isset($best[$table]) || strlen($sql) > strlen($best[$table])) {
                $best[$table] = $sql;
            }
        }
    }
    return $best;
}

function demo_iter_source_files(): array
{
    $root = demo_root();
    $files = [];
    foreach (demo_source_iterator() as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $rel = substr($file->getPathname(), strlen($root) + 1);
        if (!demo_source_is_tracked($rel)) {
            continue;
        }
        $files[] = $file->getPathname();
    }
    sort($files);
    return $files;
}

function demo_extract_create_sql(string $source): array
{
    $statements = [];
    $upper = strtoupper($source);
    $offset = 0;
    $len = strlen($source);
    while (($pos = strpos($upper, 'CREATE TABLE', $offset)) !== false) {
        $depth = 0;
        $seenParen = false;
        $inQuote = null;
        $end = null;
        for ($i = $pos; $i < $len; $i++) {
            $c = $source[$i];
            if ($inQuote !== null) {
                if ($c === $inQuote) {
                    $prev = $i > $pos ? $source[$i - 1] : '';
                    if ($prev === '\\') {
                        continue;
                    }
                    if ($inQuote === "'" && ($i + 1) < $len && $source[$i + 1] === "'") {
                        $i++;
                        continue;
                    }
                    $inQuote = null;
                }
                continue;
            }
            if ($c === '(') {
                $depth++;
                $seenParen = true;
                continue;
            }
            if ($c === ')') {
                $depth = max(0, $depth - 1);
                continue;
            }
            // A double quote here closes the PHP string that holds the statement.
            // SQL files end the statement with a semicolon instead.
            if ($seenParen && $depth === 0 && ($c === ';' || $c === '"')) {
                $end = $i;
                break;
            }
            if ($c === "'" || $c === '"') {
                $inQuote = $c;
                continue;
            }
        }
        if ($end === null) {
            $offset = $pos + 12;
            continue;
        }
        $sql = substr($source, $pos, $end - $pos);
        $sql = demo_normalize_create_sql($sql);
        if ($sql !== null) {
            $statements[] = $sql;
        }
        $offset = $end + 1;
    }
    return $statements;
}

function demo_normalize_create_sql(string $sql): ?string
{
    $sql = trim($sql);
    if (!preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?[A-Za-z0-9_]+[`"]?\s*\(/i', $sql)) {
        return null;
    }
    if (str_contains($sql, '$') || str_contains($sql, '<?')) {
        return null;
    }
    $sql = preg_replace('/^CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\b)/i', 'CREATE TABLE IF NOT EXISTS ', $sql, 1);
    return rtrim($sql, " \t\r\n;") . ';';
}

function demo_strip_foreign_keys(string $sql): string
{
    return preg_replace(
        '/,?\s*CONSTRAINT\s+[`"\w]+\s+FOREIGN\s+KEY\s*\([^)]*\)\s*REFERENCES\s+[`"\w.]+\s*\([^)]*\)(?:\s*ON\s+DELETE\s+(?:CASCADE|SET\s+NULL|RESTRICT|NO\s+ACTION))?(?:\s*ON\s+UPDATE\s+(?:CASCADE|SET\s+NULL|RESTRICT|NO\s+ACTION))?/i',
        '',
        $sql
    );
}

function demo_exec_create(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        $retry = demo_strip_foreign_keys($sql);
        if ($retry !== $sql) {
            try {
                $pdo->exec($retry);
                return;
            } catch (Throwable $e2) {
                $e = $e2;
            }
        }
        error_log('Demo schema create skipped: ' . $e->getMessage());
    }
}

function demo_apply_create_columns(PDO $pdo, string $sql): void
{
    if (!preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+[`"]?([A-Za-z0-9_]+)[`"]?\s*\((.*)\)\s*ENGINE/is', $sql, $m)) {
        return;
    }
    $table = $m[1];
    if (!demo_table_exists($pdo, $table)) {
        return;
    }
    $existing = demo_columns($pdo, $table);
    foreach (demo_split_sql_list($m[2]) as $part) {
        $part = trim($part);
        if (!preg_match('/^[`"]?([A-Za-z0-9_]+)[`"]?\s+(.+)$/s', $part, $col)) {
            continue;
        }
        $name = $col[1];
        $upper = strtoupper($name);
        if (in_array($upper, ['PRIMARY', 'UNIQUE', 'KEY', 'CONSTRAINT', 'INDEX', 'FULLTEXT', 'FOREIGN'], true)) {
            continue;
        }
        if (isset($existing[$name])) {
            continue;
        }
        $definition = rtrim($col[2]);
        try {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$name` $definition");
            $existing[$name] = true;
        } catch (Throwable $e) {
            $definition = preg_replace('/\s+AFTER\s+[`"]?\w+[`"]?/i', '', $definition);
            try {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$name` $definition");
            } catch (Throwable $e2) {
                error_log("Demo column {$table}.{$name} skipped: " . $e2->getMessage());
            }
        }
    }
}

function demo_split_sql_list(string $body): array
{
    $parts = [];
    $buf = '';
    $depth = 0;
    $inQuote = null;
    $len = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        $c = $body[$i];
        if ($inQuote !== null) {
            $buf .= $c;
            if ($c === $inQuote && ($i === 0 || $body[$i - 1] !== '\\')) {
                if ($inQuote === "'" && ($i + 1) < $len && $body[$i + 1] === "'") {
                    $buf .= $body[++$i];
                    continue;
                }
                $inQuote = null;
            }
            continue;
        }
        if ($c === "'" || $c === '"') {
            $inQuote = $c;
            $buf .= $c;
            continue;
        }
        if ($c === '(') {
            $depth++;
            $buf .= $c;
            continue;
        }
        if ($c === ')') {
            $depth = max(0, $depth - 1);
            $buf .= $c;
            continue;
        }
        if ($c === ',' && $depth === 0) {
            $parts[] = $buf;
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') {
        $parts[] = $buf;
    }
    return $parts;
}

function demo_apply_ensure_column_calls(PDO $pdo): void
{
    $pattern = '/_ensure_column\s*\(\s*\$pdo\s*,\s*([\'"])([^\'"]+)\1\s*,\s*([\'"])([^\'"]+)\3\s*,\s*([\'"])(.*?)\5\s*\)/s';
    foreach (demo_iter_source_files() as $path) {
        $text = @file_get_contents($path);
        if ($text === false || !str_contains($text, '_ensure_column')) {
            continue;
        }
        if (!preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($matches as $call) {
            demo_add_column($pdo, $call[2], $call[4], $call[6]);
        }
    }

    $alter = '/ALTER\s+TABLE\s+[`"]?([A-Za-z0-9_]+)[`"]?\s+ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([A-Za-z0-9_]+)[`"]?\s+((?:[^;\'"]|\'[^\']*\'|"[^"]*")+)/i';
    $modify = '/ALTER\s+TABLE\s+[`"]?([A-Za-z0-9_]+)[`"]?\s+MODIFY\s+COLUMN\s+[`"]?([A-Za-z0-9_]+)[`"]?\s+((?:[^;\'"]|\'[^\']*\'|"[^"]*")+)/i';
    foreach (demo_iter_source_files() as $path) {
        $text = @file_get_contents($path);
        if ($text === false) {
            continue;
        }
        $upper = strtoupper($text);
        if (str_contains($upper, 'ADD COLUMN') && preg_match_all($alter, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $call) {
                $definition = trim($call[3]);
                if ($definition === '' || str_contains($definition, '$')) {
                    continue;
                }
                demo_add_column($pdo, $call[1], $call[2], $definition);
            }
        }
        if (str_contains($upper, 'MODIFY COLUMN') && preg_match_all($modify, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $call) {
                $definition = trim($call[3]);
                if ($definition === '' || str_contains($definition, '$') || !demo_table_exists($pdo, $call[1])) {
                    continue;
                }
                try {
                    $pdo->exec('ALTER TABLE `' . $call[1] . '` MODIFY COLUMN `' . $call[2] . '` ' . $definition);
                } catch (Throwable $e) {
                    error_log('Demo modify ' . $call[1] . '.' . $call[2] . ' skipped: ' . $e->getMessage());
                }
            }
        }
    }

    $branchCalls = [];
    foreach (demo_iter_source_files() as $path) {
        $text = @file_get_contents($path);
        if ($text === false || !str_contains($text, 'branch_ensure_column')) {
            continue;
        }
        if (preg_match_all("/branch_ensure_column\s*\(\s*\\\$pdo\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $text, $m)) {
            foreach ($m[1] as $table) {
                $branchCalls[$table] = true;
            }
        }
    }
    if ($branchCalls && is_file(demo_root() . '/config/branch_scope.php')) {
        require_once demo_root() . '/config/branch_scope.php';
        foreach (array_keys($branchCalls) as $table) {
            try {
                branch_ensure_column($pdo, $table);
            } catch (Throwable $e) {
                error_log('Demo branch column skipped: ' . $e->getMessage());
            }
        }
    }
}

function demo_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!demo_table_exists($pdo, $table)) {
        return;
    }
    $cols = demo_columns($pdo, $table);
    if (isset($cols[$column])) {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    } catch (Throwable $e) {
        $definition = preg_replace('/\s+AFTER\s+[`"]?\w+[`"]?/i', '', $definition);
        try {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        } catch (Throwable $e2) {
            error_log("Demo ensure column {$table}.{$column} skipped: " . $e2->getMessage());
        }
    }
}

function demo_run_helper_ensures(PDO $pdo): void
{
    $root = demo_root();
    $calls = [
        ['config/security_helpers.php', 'sec_ensure_tables'],
        ['config/permissions.php', 'perm_ensure_tables'],
        ['config/whatsapp.php', 'wa_ensure_schema'],
        ['config/website_facilities.php', 'wf_ensure_table'],
        ['config/website_menu.php', 'wm_ensure_schema'],
        ['config/scholarship_helpers.php', 'sch_ensure_tables'],
        ['config/accounts_helpers.php', 'ac_ensure_tables'],
        ['config/admission_fee_helpers.php', 'af_ensure_tables'],
        ['config/admission_fee_helpers.php', 'af_ensure_columns'],
        ['config/admission_fee_helpers.php', 'admission_fee_tables_ensure'],
        ['config/monthly_fee_helpers.php', 'mf_ensure_tables'],
        ['config/exam_fee_helpers.php', 'ef_ensure_tables'],
        ['config/exam_fee_helpers.php', 'ef_ensure_columns'],
        ['config/fee_collection_sms.php', 'fc_ensure_receipt_token_column'],
        ['config/home_messages.php', 'hm_ensure_schema'],
        ['config/qms_helpers.php', 'qms_ensure_tables'],
        ['config/live_class_100ms.php', 'lc_ensure_settings_table'],
        ['config/live_class_100ms.php', 'lc_ensure_recording_jobs_table'],
        ['config/report_card_helpers.php', 'rc_tiebreaker_ensure_table'],
        ['config/report_card_helpers.php', 'rc_pct_ensure_tables'],
        ['config/report_card_helpers.php', 'rc_lock_ensure_tables'],
        ['config/sms.php', 'sms_ensure_schema'],
    ];
    foreach ($calls as [$rel, $fn]) {
        $path = $root . '/' . $rel;
        if (!is_file($path)) {
            continue;
        }
        try {
            require_once $path;
            if (function_exists($fn)) {
                $fn($pdo);
            }
        } catch (Throwable $e) {
            error_log("Demo helper {$fn} skipped: " . $e->getMessage());
        }
    }

    foreach (['admin/transport_common.php', 'admin/inventory_common.php', 'admin/canteen_common.php'] as $rel) {
        $path = $root . '/' . $rel;
        if (!is_file($path)) {
            continue;
        }
        try {
            require_once $path;
        } catch (Throwable $e) {
            error_log("Demo include {$rel} skipped: " . $e->getMessage());
        }
    }
}

function demo_sync_schema(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $creates = demo_collect_create_statements();
    foreach ($creates as $sql) {
        demo_exec_create($pdo, $sql);
    }
    foreach ($creates as $sql) {
        demo_apply_create_columns($pdo, $sql);
    }
    demo_apply_ensure_column_calls($pdo);
    demo_run_helper_ensures($pdo);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function demo_sync_insert(PDO $pdo, string $table, array $data, array $match = []): int
{
    if (!demo_table_exists($pdo, $table)) {
        return 0;
    }
    $cols = demo_columns($pdo, $table);
    $requestedMatch = $match;
    $match = array_intersect_key($match, $cols);
    if ($requestedMatch && !$match) {
        return 0;
    }
    if ($match && isset($cols['id'])) {
        $where = [];
        $params = [];
        foreach ($match as $key => $value) {
            $where[] = "`$key` = ?";
            $params[] = $value;
        }
        $stmt = $pdo->prepare('SELECT id FROM `' . $table . '` WHERE ' . implode(' AND ', $where) . ' LIMIT 1');
        $stmt->execute($params);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            return (int) $existing;
        }
    } elseif ($match === [] && demo_count($pdo, $table) > 0 && isset($data['__only_if_empty'])) {
        return 0;
    }
    unset($data['__only_if_empty']);

    $data = array_intersect_key($data, $cols);
    foreach ($cols as $name => $meta) {
        if (isset($data[$name]) || stripos((string) $meta['EXTRA'], 'auto_increment') !== false) {
            continue;
        }
        $required = $meta['IS_NULLABLE'] === 'NO' && $meta['COLUMN_DEFAULT'] === null;
        if ($required) {
            $data[$name] = demo_dummy_value($meta);
        }
    }
    if (!$data) {
        return 0;
    }
    $names = array_keys($data);
    $placeholders = implode(', ', array_fill(0, count($names), '?'));
    $quoted = implode(', ', array_map(static fn($n) => "`$n`", $names));
    try {
        $stmt = $pdo->prepare("INSERT INTO `$table` ($quoted) VALUES ($placeholders)");
        $stmt->execute(array_values($data));
        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log("Demo seed {$table} skipped: " . $e->getMessage());
        return 0;
    }
}

function demo_dummy_value(array $meta)
{
    $type = strtolower((string) $meta['DATA_TYPE']);
    $columnType = (string) $meta['COLUMN_TYPE'];
    if ($type === 'enum' && preg_match("/enum\\('([^']*)'/i", $columnType, $m)) {
        return $m[1];
    }
    if (in_array($type, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint', 'decimal', 'float', 'double'], true)) {
        return 0;
    }
    if ($type === 'time') {
        return '08:00:00';
    }
    if ($type === 'year') {
        return (int) date('Y');
    }
    if ($type === 'date') {
        return date('Y-m-d');
    }
    if (in_array($type, ['datetime', 'timestamp'], true)) {
        return date('Y-m-d H:i:s');
    }
    return 'Demo';
}

function demo_sync_fill(PDO $pdo): void
{
    foreach (['demo_seed_passwords', 'demo_seed_features', 'demo_seed_empty_tables', 'demo_sync_assign_branch', 'demo_sync_device_punches', 'demo_sync_admission_fixtures', 'demo_sync_repair_visibility'] as $fn) {
        try {
            $fn($pdo);
        } catch (Throwable $e) {
            error_log('Demo fill ' . $fn . ' skipped: ' . $e->getMessage());
        }
    }
}

function demo_seed_passwords(PDO $pdo): void
{
    demo_set_blank_password($pdo, 'employees', 'Teacher@2026');
    demo_set_blank_password($pdo, 'students', 'Student@2026');
}

function demo_set_blank_password(PDO $pdo, string $table, string $plain): void
{
    if (!demo_table_exists($pdo, $table)) {
        return;
    }
    $cols = demo_columns($pdo, $table);
    if (!isset($cols['password'])) {
        return;
    }
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    if (isset($cols['must_change_password'])) {
        $pdo->prepare("UPDATE `$table` SET password = ?, must_change_password = 0 WHERE password IS NULL OR password = ''")
            ->execute([$hash]);
        return;
    }
    $pdo->prepare("UPDATE `$table` SET password = ? WHERE password IS NULL OR password = ''")->execute([$hash]);
}

function demo_seed_features(PDO $pdo): void
{
    $sessionId = demo_table_exists($pdo, 'academic_sessions')
        ? (int) $pdo->query('SELECT id FROM academic_sessions ORDER BY is_current_session DESC, id ASC LIMIT 1')->fetchColumn()
        : 0;
    $classId = 0;
    if (demo_table_exists($pdo, 'students')) {
        $classId = (int) $pdo->query(
            "SELECT class_id FROM students WHERE status = 'Active' AND class_id IS NOT NULL
             GROUP BY class_id ORDER BY COUNT(*) DESC, class_id ASC LIMIT 1"
        )->fetchColumn();
    }
    if (!$classId && demo_table_exists($pdo, 'academic_classes')) {
        $classId = (int) $pdo->query("SELECT id FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, id ASC LIMIT 1")->fetchColumn();
    }
    $sectionId = 0;
    $shiftId = 0;
    if ($classId && demo_table_exists($pdo, 'students')) {
        $placement = $pdo->query(
            "SELECT section_id, shift_id FROM students WHERE class_id = " . (int) $classId . " AND status = 'Active' ORDER BY id ASC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $sectionId = (int) ($placement['section_id'] ?? 0);
        $shiftId = (int) ($placement['shift_id'] ?? 0);
    }
    if (!$sectionId && demo_table_exists($pdo, 'academic_sections')) {
        $sectionId = (int) $pdo->query('SELECT id FROM academic_sections ORDER BY id ASC LIMIT 1')->fetchColumn();
    }
    if (!$shiftId && demo_table_exists($pdo, 'academic_shifts')) {
        $shiftId = (int) $pdo->query("SELECT id FROM academic_shifts WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetchColumn();
    }
    $branchId = demo_table_exists($pdo, 'branches')
        ? (int) $pdo->query("SELECT id FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetchColumn()
        : 0;
    $employeeId = demo_table_exists($pdo, 'employees')
        ? (int) $pdo->query("SELECT id FROM employees WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetchColumn()
        : 0;

    if ($sessionId && $classId && demo_table_exists($pdo, 'academic_subjects')) {
        $subjects = [
            ['Bangla', 'BAN', 'Compulsory'],
            ['English', 'ENG', 'Compulsory'],
            ['Mathematics', 'MAT', 'Compulsory'],
            ['Science', 'SCI', 'Optional'],
        ];
        $have = (int) $pdo->query('SELECT COUNT(*) FROM academic_subjects WHERE class_id = ' . (int) $classId)->fetchColumn();
        if ($have === 0) {
            foreach ($subjects as [$name, $code, $type]) {
                demo_sync_insert($pdo, 'academic_subjects', [
                    'class_id' => $classId,
                    'subject_name' => $name,
                    'subject_code' => $code . '-' . $classId,
                    'full_marks' => 100,
                    'subject_type' => $type,
                    'status' => 'Active',
                ], ['class_id' => $classId, 'subject_name' => $name]);
            }
        }
    }
    $subjectId = demo_table_exists($pdo, 'academic_subjects')
        ? (int) $pdo->query("SELECT id FROM academic_subjects WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetchColumn()
        : 0;

    $groupClassId = demo_table_exists($pdo, 'academic_classes')
        ? (int) $pdo->query("SELECT id FROM academic_classes WHERE status = 'Active' ORDER BY rank DESC, id DESC LIMIT 1")->fetchColumn()
        : 0;
    if ($groupClassId && demo_table_exists($pdo, 'academic_groups')) {
        $haveGroups = (int) $pdo->query('SELECT COUNT(*) FROM academic_groups WHERE class_id = ' . (int) $groupClassId)->fetchColumn();
        if ($haveGroups === 0) {
            foreach (['Science', 'Humanities', 'Business Studies'] as $group) {
                demo_sync_insert($pdo, 'academic_groups', [
                    'class_id' => $groupClassId,
                    'group_name' => $group,
                    'status' => 'Active',
                ], ['class_id' => $groupClassId, 'group_name' => $group]);
            }
        }
    }

    $typeId = demo_sync_insert($pdo, 'employee_types', [
        'type' => 'Full-time',
        'name' => 'Full-time Teacher',
        'status' => 'Active',
    ], ['name' => 'Full-time Teacher']);
    $designationId = demo_sync_insert($pdo, 'designations', [
        'type' => 'Teachers',
        'title' => 'Senior Teacher',
        'status' => 'Active',
    ], ['title' => 'Senior Teacher']);
    demo_sync_insert($pdo, 'employee_shifts', [
        'name' => 'Morning',
        'start_time' => '08:00:00',
        'end_time' => '14:00:00',
        'status' => 'Active',
    ], ['name' => 'Morning']);
    if ($employeeId) {
        demo_sync_insert($pdo, 'employee_educations', [
            'employee_id' => $employeeId,
            'degree' => 'B.Ed',
            'institute' => 'Demo Teachers College',
            'passing_year' => (int) date('Y') - 8,
        ], ['employee_id' => $employeeId, 'degree' => 'B.Ed']);
    }

    if ($typeId && $employeeId && demo_table_exists($pdo, 'employees')) {
        $sets = [];
        $cols = demo_columns($pdo, 'employees');
        if (isset($cols['employee_type_id'])) {
            $sets[] = 'employee_type_id = IFNULL(employee_type_id, ' . (int) $typeId . ')';
        }
        if (isset($cols['designation_id']) && $designationId) {
            $sets[] = 'designation_id = IFNULL(designation_id, ' . (int) $designationId . ')';
        }
        if ($sets) {
            $pdo->exec('UPDATE employees SET ' . implode(', ', $sets));
        }
    }

    if ($employeeId && $classId) {
        demo_sync_insert($pdo, 'class_teachers', [
            'employee_id' => $employeeId,
            'class_id' => $classId,
            'section_id' => $sectionId,
        ], ['class_id' => $classId, 'section_id' => $sectionId]);
    }
    if ($employeeId && $classId && $subjectId) {
        demo_sync_insert($pdo, 'teacher_subject_assignments', [
            'employee_id' => $employeeId,
            'class_id' => $classId,
            'subject_id' => $subjectId,
        ], ['employee_id' => $employeeId, 'class_id' => $classId, 'subject_id' => $subjectId]);
    }

    $admissionHead = demo_sync_insert($pdo, 'finance_account_heads', [
        'ac_type' => 'Academic',
        'ac_category' => 'Receipts',
        'parent_id' => 0,
        'ac_code' => 'ADM-FEE',
        'ac_head' => 'Admission Fee',
        'period_type' => 'Yearly',
        'has_child' => 'No',
        'status' => 'Active',
    ], ['ac_code' => 'ADM-FEE']);
    $tuitionHead = demo_sync_insert($pdo, 'finance_account_heads', [
        'ac_type' => 'Academic',
        'ac_category' => 'Receipts',
        'parent_id' => 0,
        'ac_code' => 'TUI-FEE',
        'ac_head' => 'Monthly Tuition',
        'period_type' => 'Monthly',
        'has_child' => 'No',
        'status' => 'Active',
    ], ['ac_code' => 'TUI-FEE']);
    demo_sync_insert($pdo, 'finance_account_heads', [
        'ac_type' => 'Academic',
        'ac_category' => 'Receipts',
        'parent_id' => 0,
        'ac_code' => 'EXM-FEE',
        'ac_head' => 'Exam Fee',
        'period_type' => 'Yearly',
        'has_child' => 'No',
        'status' => 'Active',
    ], ['ac_code' => 'EXM-FEE']);

    if ($sessionId && $classId && $admissionHead && demo_count($pdo, 'finance_admission_fee_settings') === 0) {
        $settingId = demo_sync_insert($pdo, 'finance_admission_fee_settings', [
            'session_id' => $sessionId,
            'class_id' => $classId,
            'shift_id' => 0,
            'section_id' => 0,
            'gender' => '',
            'applicability' => 'New',
            'status' => 'Active',
        ]);
        if ($settingId) {
            demo_sync_insert($pdo, 'finance_admission_fee_items', [
                'setting_id' => $settingId,
                'ac_head_id' => $admissionHead,
                'amount' => 5000,
            ], ['setting_id' => $settingId, 'ac_head_id' => $admissionHead]);
        }
    }
    if ($sessionId && $classId && $tuitionHead && demo_count($pdo, 'finance_monthly_fee_settings') === 0) {
        $settingId = demo_sync_insert($pdo, 'finance_monthly_fee_settings', [
            'session_id' => $sessionId,
            'class_id' => $classId,
            'shift_id' => 0,
            'section_id' => 0,
            'gender' => '',
            'applicability' => 'All',
            'status' => 'Active',
        ]);
        if ($settingId) {
            demo_sync_insert($pdo, 'finance_monthly_fee_items', [
                'setting_id' => $settingId,
                'ac_head_id' => $tuitionHead,
                'amount' => 1500,
            ], ['setting_id' => $settingId, 'ac_head_id' => $tuitionHead]);
        }
    }

    if ($sessionId && demo_count($pdo, 'exam_grade_points') === 0) {
        $grades = [
            ['A+', 5.00, 80, 100, 'Excellent'],
            ['A', 4.00, 70, 79, 'Very Good'],
            ['A-', 3.50, 60, 69, 'Good'],
            ['B', 3.00, 50, 59, 'Average'],
            ['C', 2.00, 40, 49, 'Below Average'],
            ['D', 1.00, 33, 39, 'Pass'],
            ['F', 0.00, 0, 32, 'Fail'],
        ];
        foreach ($grades as [$letter, $gpa, $from, $to, $remarks]) {
            demo_sync_insert($pdo, 'exam_grade_points', [
                'session_id' => $sessionId,
                'grade_letter' => $letter,
                'gpa' => $gpa,
                'mark_from' => $from,
                'mark_to' => $to,
                'remarks' => $remarks,
            ], ['session_id' => $sessionId, 'grade_letter' => $letter]);
        }
    }
    $termId = 0;
    if ($sessionId) {
        $termId = demo_sync_insert($pdo, 'exam_terms', [
            'session_id' => $sessionId,
            'term_name' => 'First Term',
            'rank' => 1,
            'is_final_term' => 0,
            'status' => 'Active',
        ], ['session_id' => $sessionId, 'term_name' => 'First Term']);
    }
    $partId = 0;
    if ($sessionId) {
        $partId = demo_sync_insert($pdo, 'exam_parts', [
            'session_id' => $sessionId,
            'part_name' => 'Written',
            'part_short_name' => 'WR',
            'rank' => 1,
            'status' => 'Active',
        ], ['session_id' => $sessionId, 'part_short_name' => 'WR']);
    }
    if ($sessionId && $classId && $termId && $partId && $subjectId) {
        demo_sync_insert($pdo, 'exam_assign_subjects', [
            'session_id' => $sessionId,
            'class_id' => $classId,
            'term_id' => $termId,
            'part_id' => $partId,
            'subject_id' => $subjectId,
            'full_mark_subject' => 100,
            'full_mark_exam' => 100,
            'pass_mark_exam' => 33,
            'status' => 'Active',
        ], ['term_id' => $termId, 'part_id' => $partId, 'subject_id' => $subjectId]);
    }
    if ($sessionId && $termId && $partId && $subjectId && $classId && demo_count($pdo, 'exam_marks') === 0) {
        $students = $pdo->query(
            "SELECT id, class_id, section_id, shift_id FROM students WHERE status = 'Active' ORDER BY id ASC LIMIT 8"
        )->fetchAll(PDO::FETCH_ASSOC);
        $marks = [88, 76, 91, 64, 55, 82, 47, 73];
        foreach ($students as $i => $student) {
            demo_sync_insert($pdo, 'exam_marks', [
                'session_id' => $sessionId,
                'term_id' => $termId,
                'part_id' => $partId,
                'subject_id' => $subjectId,
                'class_id' => $student['class_id'] ?: $classId,
                'shift_id' => $student['shift_id'] ?: $shiftId,
                'section_id' => $student['section_id'] ?: $sectionId,
                'student_id' => $student['id'],
                'marks_obtained' => $marks[$i] ?? 70,
            ], ['term_id' => $termId, 'part_id' => $partId, 'subject_id' => $subjectId, 'student_id' => $student['id']]);
        }
    }

    if ($classId && $sectionId && $subjectId && $employeeId && demo_count($pdo, 'routines') === 0) {
        $days = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday'];
        foreach ($days as $i => $day) {
            $hour = 9 + $i;
            demo_sync_insert($pdo, 'routines', [
                'class_id' => $classId,
                'shift_id' => $shiftId,
                'section_id' => $sectionId,
                'routine_days' => $day,
                'start_time' => sprintf('%02d:00:00', $hour),
                'end_time' => sprintf('%02d:40:00', $hour),
                'subject_id' => $subjectId,
                'teacher_id' => $employeeId,
                'status' => 'Active',
                'branch_id' => $branchId ?: null,
            ], ['section_id' => $sectionId, 'routine_days' => $day, 'subject_id' => $subjectId]);
        }
    }

    if (demo_count($pdo, 'student_daily_attendance') === 0 && demo_table_exists($pdo, 'students')) {
        $students = $pdo->query(
            "SELECT id, class_id, section_id, shift_id FROM students WHERE status = 'Active' ORDER BY id ASC LIMIT 10"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($students as $i => $student) {
            demo_sync_insert($pdo, 'student_daily_attendance', [
                'student_id' => $student['id'],
                'class_id' => $student['class_id'],
                'section_id' => $student['section_id'],
                'shift_id' => $student['shift_id'],
                'attendance_date' => date('Y-m-d'),
                'status' => $i === 3 ? 'Absent' : 'Present',
                'marked_by' => 'demo_admin',
            ], ['student_id' => $student['id'], 'attendance_date' => date('Y-m-d')]);
        }
    }
    if ($employeeId && demo_count($pdo, 'employee_daily_attendance') === 0) {
        demo_sync_insert($pdo, 'employee_daily_attendance', [
            'employee_id' => $employeeId,
            'attendance_date' => date('Y-m-d'),
            'status' => 'Present',
        ], ['employee_id' => $employeeId, 'attendance_date' => date('Y-m-d')]);
    }

    if (demo_count($pdo, 'admissions') === 0) {
        demo_sync_insert($pdo, 'admissions', [
            'student_name_bn' => 'ডেমো আবেদনকারী',
            'student_name_en' => 'Demo Applicant',
            'apply_class' => 'CLASS ONE',
            'apply_group' => 'General',
            'dob' => '2018-01-15',
            'religion' => 'Islam',
            'gender' => 'Male',
            'father_name' => 'Karim Uddin',
            'mother_name' => 'Rokeya Begum',
            'guardian_phone' => '01710000099',
            'address' => 'Demo Road, Dhaka',
            'status' => 'Pending',
        ], ['guardian_phone' => '01710000099']);
    }

    if ($classId && $subjectId && demo_count($pdo, 'elearning_homework') === 0) {
        demo_sync_insert($pdo, 'elearning_homework', [
            'class_id' => $classId,
            'section_id' => $sectionId,
            'subject_id' => $subjectId,
            'teacher_id' => $employeeId,
            'title' => 'Chapter 1 practice',
            'description' => 'Read the first chapter and answer the five questions.',
            'deadline' => date('Y-m-d H:i:s', strtotime('+7 days')),
            'status' => 'Active',
        ], ['title' => 'Chapter 1 practice']);
    }
    if ($classId && $subjectId && demo_count($pdo, 'elearning_live_classes') === 0) {
        demo_sync_insert($pdo, 'elearning_live_classes', [
            'class_id' => $classId,
            'section_id' => $sectionId,
            'subject_id' => $subjectId,
            'teacher_id' => $employeeId,
            'title' => 'Live Bangla class',
            'scheduled_at' => date('Y-m-d H:i:s', strtotime('+1 day 10:00')),
            'duration_minutes' => 40,
            'status' => 'Scheduled',
        ], ['title' => 'Live Bangla class']);
    }
    if ($classId && $subjectId && demo_count($pdo, 'elearning_recorded_classes') === 0) {
        demo_sync_insert($pdo, 'elearning_recorded_classes', [
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'teacher_id' => $employeeId,
            'title' => 'Recorded science lesson',
            'video_type' => 'youtube',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'status' => 'Active',
        ], ['title' => 'Recorded science lesson']);
    }

    $vehicleId = demo_sync_insert($pdo, 'transport_vehicles', [
        'vehicle_no' => 'DM-11-2026',
        'bus_no' => '01',
        'vehicle_name' => 'Demo Bus',
        'vehicle_type' => 'Bus',
        'capacity' => 40,
        'status' => 'Active',
    ], ['vehicle_no' => 'DM-11-2026']);
    $routeId = demo_sync_insert($pdo, 'transport_routes', [
        'route_no' => 'R-1',
        'route_name' => 'Mirpur Demo Route',
        'route_category' => 'Student',
        'vehicle_id' => $vehicleId ?: null,
        'monthly_fee' => 800,
        'status' => 'Active',
    ], ['route_name' => 'Mirpur Demo Route']);
    if ($routeId) {
        demo_sync_insert($pdo, 'transport_stops', [
            'route_id' => $routeId,
            'stop_name' => 'Mirpur 10',
            'stop_order' => 1,
        ], ['route_id' => $routeId, 'stop_name' => 'Mirpur 10']);
    }
    if ($routeId && demo_table_exists($pdo, 'students')) {
        $studentPk = (int) $pdo->query("SELECT id FROM students ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($studentPk) {
            $stopId = (int) $pdo->query('SELECT id FROM transport_stops WHERE route_id = ' . (int) $routeId . ' ORDER BY id ASC LIMIT 1')->fetchColumn();
            demo_sync_insert($pdo, 'transport_student_assign', [
                'student_id' => $studentPk,
                'route_id' => $routeId,
                'stop_id' => $stopId,
                'assigned_date' => date('Y-m-d'),
                'status' => 'Active',
            ], ['student_id' => $studentPk, 'route_id' => $routeId]);
        }
    }

    demo_sync_insert($pdo, 'inventory_items', [
        'name' => 'Exercise Book',
        'stock_qty' => 120,
    ], ['name' => 'Exercise Book']);

    $categoryId = demo_sync_insert($pdo, 'library_categories', [
        'category_name' => 'Text Books',
        'name' => 'Text Books',
        'status' => 'Active',
    ], ['category_name' => 'Text Books', 'name' => 'Text Books']);
    $bookId = demo_sync_insert($pdo, 'library_books', [
        'book_code' => 'DEMO-001',
        'title' => 'Demo Bangla Grammar',
        'author' => 'School ERP',
        'category_id' => $categoryId ?: null,
        'total_copies' => 5,
        'available_copies' => 4,
        'status' => 'Active',
    ], ['book_code' => 'DEMO-001']);
    if ($bookId && demo_table_exists($pdo, 'students')) {
        $studentPk = (int) $pdo->query("SELECT id FROM students ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($studentPk && demo_count($pdo, 'library_issues') === 0) {
            demo_sync_insert($pdo, 'library_issues', [
                'book_id' => $bookId,
                'student_id' => $studentPk,
                'issue_date' => date('Y-m-d'),
                'due_date' => date('Y-m-d', strtotime('+14 days')),
                'status' => 'Issued',
            ], ['book_id' => $bookId, 'student_id' => $studentPk]);
        }
    }

    $scholarshipId = demo_sync_insert($pdo, 'scholarship_types', [
        'name' => 'Merit Scholarship',
        'status' => 'Active',
    ], ['name' => 'Merit Scholarship']);
    if ($scholarshipId && demo_table_exists($pdo, 'students') && demo_count($pdo, 'scholarship_awards') === 0) {
        $studentPk = (int) $pdo->query("SELECT id FROM students ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($studentPk) {
            demo_sync_insert($pdo, 'scholarship_awards', [
                'scholarship_type_id' => $scholarshipId,
                'student_id' => $studentPk,
                'session_id' => $sessionId,
                'class_id' => $classId,
                'amount' => 2000,
                'status' => 'Pending',
            ], ['scholarship_type_id' => $scholarshipId, 'student_id' => $studentPk]);
        }
    }

    if (demo_count($pdo, 'website_contents') === 0) {
        demo_sync_insert($pdo, 'website_contents', [
            'title' => 'About the Demo School',
            'content' => 'Smart Model Academy is the sample school used by the one-click demo.',
            'status' => 'Active',
        ], ['title' => 'About the Demo School']);
    }
    if (demo_count($pdo, 'website_news') === 0) {
        demo_sync_insert($pdo, 'website_news', [
            'title' => 'Annual sports day',
            'short_desc' => 'The demo sports day is scheduled for next month.',
            'news_date' => date('Y-m-d'),
            'is_active' => 1,
        ], ['title' => 'Annual sports day']);
    }
    if (demo_count($pdo, 'website_events') === 0) {
        demo_sync_insert($pdo, 'website_events', [
            'title' => 'Science fair',
            'event_date' => date('Y-m-d', strtotime('+20 days')),
            'location' => 'School field',
            'description' => 'A sample event on the public website.',
            'is_active' => 1,
        ], ['title' => 'Science fair']);
    }
    if (demo_count($pdo, 'website_facilities') === 0) {
        demo_sync_insert($pdo, 'website_facilities', [
            'title' => 'Science Lab',
            'description' => 'A sample laboratory shown on the public website.',
            'is_active' => 1,
        ], ['title' => 'Science Lab']);
    }
    if (demo_count($pdo, 'website_breaking_news') === 0) {
        demo_sync_insert($pdo, 'website_breaking_news', [
            'news_text' => 'Admission for the demo session is open.',
            'is_active' => 1,
        ], ['news_text' => 'Admission for the demo session is open.']);
    }
    if (demo_count($pdo, 'message_corner') === 0) {
        demo_sync_insert($pdo, 'message_corner', [
            'role_key' => 'principal',
            'name' => 'Dr. Mohammad Rafiqul Islam',
            'designation' => 'Principal',
            'message' => 'Welcome to the Smart Model Academy demo.',
            'is_active' => 1,
        ], ['role_key' => 'principal']);
    }
    if (demo_count($pdo, 'gallery_items') === 0) {
        demo_sync_insert($pdo, 'gallery_items', [
            'caption' => 'Campus',
            'image_path' => 'logo.png',
            'is_active' => 1,
        ], ['caption' => 'Campus']);
    }

    if (demo_table_exists($pdo, 'sms_settings') && demo_count($pdo, 'sms_settings') === 0) {
        demo_sync_insert($pdo, 'sms_settings', [
            'id' => 1,
            'api_enabled' => 0,
            'content_admission' => 'Welcome to {SCHOOL_NAME}. ID: {STUDENT_ID}',
        ], ['id' => 1]);
    }

    $headId = demo_sync_insert($pdo, 'payroll_heads', [
        'category' => 'Addition',
        'ac_code' => 'BASIC',
        'ac_head' => 'Basic Salary',
        'period_type' => 'Monthly',
        'has_child' => 'No',
        'status' => 'Active',
    ], ['ac_code' => 'BASIC']);
    if ($typeId && $headId && demo_count($pdo, 'payroll_payscales') === 0) {
        $gradeId = demo_table_exists($pdo, 'payscale_grades')
            ? (int) $pdo->query('SELECT id FROM payscale_grades ORDER BY id ASC LIMIT 1')->fetchColumn()
            : 0;
        if (!$gradeId) {
            $gradeId = demo_sync_insert($pdo, 'payscale_grades', ['name' => 'Grade-1', 'status' => 'Active'], ['name' => 'Grade-1']);
        }
        $payscaleId = demo_sync_insert($pdo, 'payroll_payscales', [
            'title' => 'Teacher Scale A',
            'employee_type_id' => $typeId,
            'grade_id' => $gradeId,
            'basic_salary' => 25000,
            'status' => 'Active',
        ], ['title' => 'Teacher Scale A']);
        if ($payscaleId) {
            demo_sync_insert($pdo, 'payroll_payscale_items', [
                'payscale_id' => $payscaleId,
                'payroll_head_id' => $headId,
                'amount' => 5000,
            ], ['payscale_id' => $payscaleId, 'payroll_head_id' => $headId]);
            if ($employeeId && demo_count($pdo, 'payroll_salary_sheets') === 0) {
                $sheetId = demo_sync_insert($pdo, 'payroll_salary_sheets', [
                    'payscale_id' => $payscaleId,
                    'month' => (int) date('n'),
                    'year' => (int) date('Y'),
                    'working_days' => 26,
                    'status' => 'Finalized',
                ], ['payscale_id' => $payscaleId, 'month' => (int) date('n'), 'year' => (int) date('Y')]);
                if ($sheetId) {
                    demo_sync_insert($pdo, 'payroll_salary_sheet_items', [
                        'sheet_id' => $sheetId,
                        'employee_id' => $employeeId,
                        'absent_days' => 0,
                        'basic_salary' => 25000,
                        'gross_addition' => 5000,
                        'gross_deduction' => 0,
                        'net_salary' => 30000,
                        'payment_status' => 'Unpaid',
                    ], ['sheet_id' => $sheetId, 'employee_id' => $employeeId]);
                }
            }
        }
    }

    if (demo_count($pdo, 'accounts_chart') === 0) {
        $cashId = demo_sync_insert($pdo, 'accounts_chart', [
            'code' => '1000',
            'name' => 'Cash',
            'account_type' => 'Asset',
            'is_system' => 1,
            'status' => 'Active',
        ], ['code' => '1000']);
        $incomeId = demo_sync_insert($pdo, 'accounts_chart', [
            'code' => '4000',
            'name' => 'Fee Income',
            'account_type' => 'Income',
            'is_system' => 1,
            'status' => 'Active',
        ], ['code' => '4000']);
        if ($cashId && $incomeId && demo_count($pdo, 'accounts_journals') === 0) {
            $journalId = demo_sync_insert($pdo, 'accounts_journals', [
                'journal_no' => 'JV-DEMO-1',
                'journal_type' => 'Receipt',
                'journal_date' => date('Y-m-d'),
                'narration' => 'Demo fee receipt',
                'created_by' => 'demo_admin',
            ], ['journal_no' => 'JV-DEMO-1']);
            if ($journalId) {
                demo_sync_insert($pdo, 'accounts_journal_lines', [
                    'journal_id' => $journalId,
                    'account_id' => $cashId,
                    'debit' => 1500,
                    'credit' => 0,
                ], ['journal_id' => $journalId, 'account_id' => $cashId]);
                demo_sync_insert($pdo, 'accounts_journal_lines', [
                    'journal_id' => $journalId,
                    'account_id' => $incomeId,
                    'debit' => 0,
                    'credit' => 1500,
                ], ['journal_id' => $journalId, 'account_id' => $incomeId]);
            }
        }
    }

    $groupId = demo_sync_insert($pdo, 'promo_groups', [
        'group_name' => 'Demo Parents',
        'status' => 'Active',
    ], ['group_name' => 'Demo Parents']);
    if ($groupId) {
        demo_sync_insert($pdo, 'promo_contacts', [
            'group_id' => $groupId,
            'mobile' => '01710000001',
            'status' => 'Active',
        ], ['mobile' => '01710000001']);
    }

    if (demo_count($pdo, 'canteen_categories') === 0) {
        $catId = demo_sync_insert($pdo, 'canteen_categories', ['name' => 'Snacks'], ['name' => 'Snacks']);
        if ($catId && demo_count($pdo, 'canteen_menu_items') === 0) {
            demo_sync_insert($pdo, 'canteen_menu_items', [
                'category_id' => $catId,
                'name' => 'Chicken Bun',
                'price' => 40,
                'status' => 'Active',
            ], ['name' => 'Chicken Bun']);
        }
    }
}

function demo_seed_empty_tables(PDO $pdo): void
{
    $skip = [
        'active_sessions' => true,
        'activity_logs' => true,
        'user_menu_permissions' => true,
        'result_lock_audit' => true,
        'device_attendance_logs' => true,
        'transport_gps_logs' => true,
        'sms_logs' => true,
        'transport_events' => true,
    ];
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (isset($skip[$table]) || str_ends_with($table, '_log') || str_ends_with($table, '_logs')) {
            continue;
        }
        if (demo_count($pdo, $table) > 0) {
            continue;
        }
        $cols = demo_columns($pdo, $table);
        $data = [];
        foreach ($cols as $name => $meta) {
            if (stripos((string) $meta['EXTRA'], 'auto_increment') !== false) {
                continue;
            }
            $nullable = $meta['IS_NULLABLE'] === 'YES' || $meta['COLUMN_DEFAULT'] !== null;
            if ($nullable) {
                continue;
            }
            $data[$name] = demo_dummy_value($meta);
        }
        if (!$data) {
            try {
                $pdo->exec("INSERT INTO `$table` () VALUES ()");
            } catch (Throwable $e) {
                error_log("Demo filler {$table} skipped: " . $e->getMessage());
            }
            continue;
        }
        demo_sync_insert($pdo, $table, $data);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function demo_sync_state_ready(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS demo_code_sync (id TINYINT PRIMARY KEY, fingerprint CHAR(64) NOT NULL) ENGINE=InnoDB');
}

function demo_saved_fingerprint(PDO $pdo): string
{
    demo_sync_state_ready($pdo);
    $saved = $pdo->query('SELECT fingerprint FROM demo_code_sync WHERE id = 1')->fetchColumn();
    return is_string($saved) ? $saved : '';
}

function demo_store_fingerprint(PDO $pdo, string $fingerprint): void
{
    demo_sync_state_ready($pdo);
    $stmt = $pdo->prepare('INSERT INTO demo_code_sync (id, fingerprint) VALUES (1, ?) ON DUPLICATE KEY UPDATE fingerprint = VALUES(fingerprint)');
    $stmt->execute([$fingerprint]);
}

function demo_sync_assign_branch(PDO $pdo): void
{
    $branchId = 1;
    if (demo_table_exists($pdo, 'branches')) {
        $found = (int) $pdo->query('SELECT id FROM branches ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($found > 0) {
            $branchId = $found;
        }
    }
    $tables = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'branch_id'"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $table)) {
            continue;
        }
        try {
            $pdo->exec('UPDATE `' . $table . '` SET branch_id = ' . (int) $branchId . ' WHERE branch_id IS NULL OR branch_id = 0');
        } catch (Throwable $e) {
            error_log('Demo branch backfill ' . $table . ' skipped: ' . $e->getMessage());
        }
    }
}

function demo_sync_device_punches(PDO $pdo): void
{
    if (!demo_table_exists($pdo, 'device_attendance_logs') || !demo_table_exists($pdo, 'students')) {
        return;
    }
    $today = date('Y-m-d');
    $already = (int) $pdo->query("SELECT COUNT(*) FROM device_attendance_logs WHERE punch_time >= " . $pdo->quote($today . ' 00:00:00'))->fetchColumn();
    if ($already > 0) {
        return;
    }
    $deviceId = null;
    if (demo_table_exists($pdo, 'attendance_devices')) {
        $deviceId = (int) $pdo->query('SELECT id FROM attendance_devices ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($deviceId <= 0) {
            $deviceId = demo_sync_insert($pdo, 'attendance_devices', [
                'device_name' => 'Main Gate',
                'brand' => 'ZKTeco',
                'device_for' => 'both',
                'branch_id' => 1,
                'status' => 'Active',
            ]);
        }
    }
    $insert = $pdo->prepare('INSERT INTO device_attendance_logs (device_id, person_type, person_uid, punch_type, punch_time) VALUES (?, ?, ?, ?, ?)');
    $students = $pdo->query("SELECT student_id FROM students WHERE status = 'Active' AND student_id <> '' ORDER BY id ASC LIMIT 24")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($students as $i => $uid) {
        $minute = str_pad((string) ($i % 10), 2, '0', STR_PAD_LEFT);
        $insert->execute([$deviceId ?: null, 'student', $uid, 'in', $today . ' 08:' . $minute . ':00']);
        if ($i % 5 !== 4) {
            $insert->execute([$deviceId ?: null, 'student', $uid, 'out', $today . ' 13:' . $minute . ':00']);
        }
    }
    if (demo_table_exists($pdo, 'employees')) {
        $employees = $pdo->query("SELECT employee_id FROM employees WHERE employee_id <> '' ORDER BY id ASC LIMIT 8")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($employees as $i => $uid) {
            $insert->execute([$deviceId ?: null, 'employee', $uid, 'in', $today . ' 07:4' . $i . ':00']);
            $insert->execute([$deviceId ?: null, 'employee', $uid, 'out', $today . ' 14:0' . $i . ':00']);
        }
    }
}

function demo_sync_admission_fixtures(PDO $pdo): void
{
    if (!demo_table_exists($pdo, 'institute_settings') || !demo_table_exists($pdo, 'students')) {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE students MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Active'");
    } catch (Throwable $e) {
        error_log('Demo student status widen skipped: ' . $e->getMessage());
    }
    $cols = demo_columns($pdo, 'institute_settings');
    if (!isset($cols['admission_type'])) {
        demo_add_column($pdo, 'institute_settings', 'admission_type', "VARCHAR(20) NOT NULL DEFAULT 'General'");
    }
    $pdo->exec("UPDATE institute_settings SET admission_type = 'Admission Test' WHERE id = 1");
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE status = 'Pending'")->fetchColumn();
    if ($pending > 0) {
        return;
    }
    $sessionId = (int) $pdo->query('SELECT id FROM academic_sessions WHERE is_current_session = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
    $classId = (int) $pdo->query("SELECT id FROM academic_classes WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($sessionId <= 0 || $classId <= 0) {
        return;
    }
    $hash = password_hash('Student@2026', PASSWORD_DEFAULT);
    for ($i = 1; $i <= 4; $i++) {
        demo_sync_insert($pdo, 'students', [
            'session_id' => $sessionId,
            'shift_id' => 1,
            'class_id' => $classId,
            'section_id' => $classId,
            'student_id' => sprintf('DEMO-P%03d', $i),
            'roll_no' => (string) (900 + $i),
            'name_en' => 'Pending applicant ' . $i,
            'name_bn' => 'আবেদনকারী ' . $i,
            'gender' => $i % 2 ? 'Female' : 'Male',
            'blood_group' => 'O+',
            'religion' => 'Islam',
            'present_address' => 'Fictional demo address, Rangpur',
            'permanent_address' => 'Fictional demo address, Bangladesh',
            'status' => 'Pending',
            'branch_id' => 1,
            'test_marks' => 55 + ($i * 8),
            'password' => $hash,
            'must_change_password' => 0,
        ], ['student_id' => sprintf('DEMO-P%03d', $i)]);
    }
    demo_sync_insert($pdo, 'admission_settings', [
        'session_id' => $sessionId,
        'class_id' => $classId,
        'method' => 'Test',
        'total_seats' => 30,
        'full_marks' => 100,
        'pass_marks' => 40,
    ], ['session_id' => $sessionId, 'class_id' => $classId]);
}

function demo_sync_repair_visibility(PDO $pdo): void
{
    demo_add_column($pdo, 'student_daily_attendance', 'check_in_time', 'DATETIME NULL');
    demo_add_column($pdo, 'student_daily_attendance', 'check_out_time', 'DATETIME NULL');
    if (demo_table_exists($pdo, 'student_daily_attendance')) {
        $pdo->exec("UPDATE student_daily_attendance SET check_in_time = CONCAT(attendance_date, ' 08:05:00') WHERE status = 'Present' AND check_in_time IS NULL");
        $pdo->exec("UPDATE student_daily_attendance SET check_out_time = CONCAT(attendance_date, ' 13:10:00') WHERE status = 'Present' AND check_out_time IS NULL AND attendance_date <= CURDATE()");
    }
    if (demo_table_exists($pdo, 'finance_fee_collections')) {
        $pdo->exec("UPDATE finance_fee_collections SET folio_no = CONCAT('DEMO-F', id) WHERE folio_no IS NULL OR folio_no = ''");
    }
    if (demo_table_exists($pdo, 'login_logs') && demo_count($pdo, 'login_logs') === 0) {
        demo_sync_insert($pdo, 'login_logs', [
            'username' => 'demo_admin',
            'user_type' => 'Super Admin',
            'login_status' => 'Success',
            'ip_address' => '127.0.0.1',
            'device_type' => 'Desktop',
            'browser' => 'Demo',
            'os' => 'Linux',
        ]);
    }
    if (!demo_table_exists($pdo, 'exam_working_days') || !demo_table_exists($pdo, 'academic_sessions')) {
        return;
    }
    $sessionId = (int) $pdo->query('SELECT id FROM academic_sessions WHERE is_current_session = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
    $classId = demo_table_exists($pdo, 'academic_classes')
        ? (int) $pdo->query("SELECT id FROM academic_classes WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetchColumn()
        : 0;
    $termId = demo_table_exists($pdo, 'exam_terms')
        ? (int) $pdo->query('SELECT id FROM exam_terms ORDER BY id ASC LIMIT 1')->fetchColumn()
        : 0;
    if ($sessionId <= 0 || $classId <= 0 || $termId <= 0) {
        return;
    }
    $have = (int) $pdo->query('SELECT COUNT(*) FROM exam_working_days WHERE session_id = ' . (int) $sessionId)->fetchColumn();
    if ($have === 0) {
        demo_sync_insert($pdo, 'exam_working_days', [
            'session_id' => $sessionId,
            'term_id' => $termId,
            'class_id' => $classId,
            'start_date' => date('Y-01-01'),
            'end_date' => date('Y-06-30'),
            'total_working_days' => 120,
            'status' => 'Active',
        ], ['session_id' => $sessionId, 'term_id' => $termId, 'class_id' => $classId]);
    }
    if (demo_table_exists($pdo, 'academic_holidays') && demo_count($pdo, 'academic_holidays') === 0) {
        demo_sync_insert($pdo, 'academic_holidays', [
            'holiday_name' => 'Demo independence observance',
            'start_date' => date('Y-03-26'),
            'end_date' => date('Y-03-26'),
            'holiday_type' => 'National',
            'status' => 'Active',
        ], ['holiday_name' => 'Demo independence observance']);
    }
}

function demo_sync_database(PDO $pdo): void
{
    $lock = (int) $pdo->query("SELECT GET_LOCK('school_erp_demo_code_sync', 60)")->fetchColumn();
    if ($lock !== 1) {
        return;
    }
    try {
        $current = demo_source_fingerprint();
        if (demo_saved_fingerprint($pdo) === $current) {
            return;
        }
        demo_sync_schema($pdo);
        demo_sync_fill($pdo);
        demo_store_fingerprint($pdo, $current);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('school_erp_demo_code_sync')");
    }
}

function demo_refresh_if_code_changed(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    demo_sync_database($pdo);
}

function demo_after_snapshot(PDO $pdo): void
{
    demo_sync_database($pdo);
}
