<?php
/**
 * admin/zk_receiver.php — ZKTeco ADMS Push Receiver (School ERP)
 * ─────────────────────────────────────────────────────────
 * অন্য একটি প্রোডাক্টের zk_receiver.php থেকে প্রোটোকল লজিক (হ্যান্ডশেক,
 * ATTLOG পার্সিং, gap-heuristic check-in/out) হুবহু অ্যাডাপ্ট করা হয়েছে —
 * সেটা প্রমাণিত (আসল ডিভাইসে কাজ করছে), তাই প্রোটোকল অংশ বদলানো হয়নি।
 * শুধু টেবিল/এন্টিটি ম্যাপিং এই সিস্টেমের নিজের স্কিমার সাথে মেলানো হয়েছে —
 * এখানে students এবং employees দুই ধরনের person থাকে (SaaS-এ শুধু
 * employees ছিল), তাই PIN দিয়ে rfid_card_no/student_id(বা employee_id)/
 * device_serial — এই তিনটার যেকোনোটার সাথে মিলিয়ে students/employees দুই
 * টেবিলেই খোঁজা হয় (এই সিস্টেমের আগে থেকে বানানো attendance_today.php ইত্যাদি
 * রিপোর্ট পেজগুলো rfid_card_no/student_id কনভেনশন ধরেই বানানো ছিল, তাই সেটাই
 * প্রাইমারি রাখা হয়েছে, device_serial অতিরিক্ত fallback হিসেবে)।
 *
 * Device Settings (ডিভাইসে বসাতে হবে):
 *   Server Address : আপনার স্কুলের ডোমেইন/সাবডোমেইন (যেমন school.yourdomain.com)
 *   Server Port    : 80 (বা 443 HTTPS হলে)
 *   Path/URL       : /admin/zk_receiver.php
 *
 * এই ফাইলটা লগইন ছাড়াই ডিভাইস সরাসরি কল করে (submit_admission_form.php-এর
 * মতো — session_start() + config/db.php দিয়েই ডোমেইন থেকে সঠিক tenant DB
 * রিজলভ হয়ে যায়, আলাদা কোনো auth লাগে না)।
 */

require_once __DIR__ . '/../config/runtime.php';
erp_session_start();
define('ZK_LOG_FILE', __DIR__ . '/../logs/zk_sync.log');

function zkLog(string $msg): void {
    $dir = dirname(ZK_LOG_FILE);
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents(ZK_LOG_FILE, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function zkResponse(string $body, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

try {
    require_once __DIR__ . '/../config/db.php'; // $pdo — ডোমেইন থেকে tenant অটো-রিজলভ হয়ে যায়
} catch (Throwable $e) {
    zkLog('DB LOAD ERROR: ' . $e->getMessage());
    zkResponse('OK'); // ডিভাইসকে error দেখাবো না
}

// -----------------------------------------------------------
// Self-healing: প্রয়োজনীয় টেবিল/কলাম না থাকলে বানিয়ে/যোগ করে নেওয়া
// -----------------------------------------------------------
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attendance_devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            device_name VARCHAR(150) NOT NULL,
            brand VARCHAR(50) NOT NULL DEFAULT 'ZKTeco',
            device_model VARCHAR(100) NULL,
            device_for ENUM('student','employee','both') NOT NULL DEFAULT 'both',
            branch_id INT NULL,
            connection_type ENUM('cloud','local') NOT NULL DEFAULT 'cloud',
            cloud_provider VARCHAR(100) NULL,
            api_base_url VARCHAR(255) NULL,
            api_key VARCHAR(255) NULL,
            api_secret VARCHAR(255) NULL,
            cloud_device_sn VARCHAR(100) NULL,
            ip_address VARCHAR(50) NULL,
            port INT NOT NULL DEFAULT 4370,
            device_serial VARCHAR(100) NULL,
            comm_key VARCHAR(50) NULL,
            protocol VARCHAR(30) NOT NULL DEFAULT 'zkteco_tcp',
            status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            notes TEXT NULL,
            live_status VARCHAR(20) NULL,
            last_seen_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS device_attendance_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            device_id INT NULL,
            person_type ENUM('student','employee') NULL,
            person_uid VARCHAR(100) NOT NULL,
            punch_type ENUM('in','out') NOT NULL DEFAULT 'in',
            punch_time DATETIME NOT NULL,
            verify_mode VARCHAR(20) NULL,
            raw_payload TEXT NULL,
            is_processed TINYINT(1) NOT NULL DEFAULT 0,
            processed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_person (person_type, person_uid, punch_time),
            INDEX idx_device (device_id, punch_time),
            UNIQUE KEY uq_device_uid_time (device_id, person_uid, punch_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // পুরনো device_attendance_logs (device_settings.php যদি আগে ভিজিট হয়ে থাকে,
    // পুরনো ভার্সনে is_processed/verify_mode/unique key ছিল না) — self-heal
    foreach ([
        'verify_mode'  => "VARCHAR(20) NULL AFTER punch_time",
        'is_processed' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER raw_payload",
        'processed_at' => "DATETIME NULL AFTER is_processed",
    ] as $col => $def) {
        $has = $pdo->query("SHOW COLUMNS FROM device_attendance_logs LIKE '$col'")->fetch();
        if (!$has) { try { $pdo->exec("ALTER TABLE device_attendance_logs ADD COLUMN `$col` $def"); } catch (Throwable $e) {} }
    }
    try { $pdo->exec("ALTER TABLE device_attendance_logs MODIFY COLUMN person_type ENUM('student','employee') NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE device_attendance_logs ADD UNIQUE KEY uq_device_uid_time (device_id, person_uid, punch_time)"); } catch (Throwable $e) {}

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS student_daily_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            class_id INT NULL,
            section_id INT NULL,
            shift_id INT NULL,
            attendance_date DATE NOT NULL,
            status ENUM('Present','Absent') NOT NULL DEFAULT 'Absent',
            marked_by VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_date (student_id, attendance_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    foreach (['check_in_time' => 'DATETIME NULL', 'check_out_time' => 'DATETIME NULL'] as $col => $def) {
        $has = $pdo->query("SHOW COLUMNS FROM student_daily_attendance LIKE '$col'")->fetch();
        if (!$has) { try { $pdo->exec("ALTER TABLE student_daily_attendance ADD COLUMN `$col` $def"); } catch (Throwable $e) {} }
    }

    // employees-এর জন্য সমান্তরাল টেবিল (HR Attendance মডিউল এখনো পুরোপুরি বানানো
    // হয়নি, কিন্তু ডিভাইসের ডেটা যেন হারিয়ে না যায় সেজন্য এই টেবিলে জমা থাকবে —
    // পরে HR Attendance পেজ বানালে এটাই ব্যবহার হবে)
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS employee_daily_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            check_in_time DATETIME NULL,
            check_out_time DATETIME NULL,
            status ENUM('Present','Absent') NOT NULL DEFAULT 'Present',
            marked_by VARCHAR(100) NULL,
            source VARCHAR(30) NOT NULL DEFAULT 'device',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_employee_date (employee_id, attendance_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Throwable $e) {
    zkLog('TABLE CREATE ERROR: ' . $e->getMessage());
}

// -----------------------------------------------------------
// Request info
// -----------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$sn     = trim($_GET['SN'] ?? $_POST['SN'] ?? 'UNKNOWN');
$table  = trim($_GET['table'] ?? $_POST['table'] ?? '');
$cmd    = trim($_GET['c'] ?? $_GET['cmd'] ?? '');
$rawBody = file_get_contents('php://input') ?: '';

zkLog("[$sn] $method | table=$table cmd=$cmd | body_len=" . strlen($rawBody));

// -----------------------------------------------------------
// ডিভাইস সেলফ-রেজিস্টার/last_seen আপডেট — device_serial দিয়ে ম্যাচ করে,
// না থাকলে নতুন সারি বানিয়ে দেওয়া হয় যাতে অ্যাডমিন Device Settings থেকে
// পরে নাম/branch বসিয়ে দিতে পারেন (অন্য প্রোডাক্টের মতোই self-register)
// -----------------------------------------------------------
$device_id = null;
if ($sn !== 'UNKNOWN') {
    try {
        $stmt = $pdo->prepare("SELECT id FROM attendance_devices WHERE device_serial = ? OR cloud_device_sn = ? LIMIT 1");
        $stmt->execute([$sn, $sn]);
        $row = $stmt->fetch();
        if ($row) {
            $device_id = (int) $row['id'];
            $pdo->prepare("UPDATE attendance_devices SET last_seen_at = NOW(), live_status = 'online' WHERE id = ?")->execute([$device_id]);
        } else {
            $pdo->prepare(
                "INSERT INTO attendance_devices (device_name, brand, connection_type, device_serial, status, live_status, last_seen_at)
                 VALUES (?, 'ZKTeco', 'cloud', ?, 'Active', 'online', NOW())"
            )->execute(["Auto: $sn", $sn]);
            $device_id = (int) $pdo->lastInsertId();
        }
    } catch (Throwable $e) {
        zkLog("[$sn] Device register error: " . $e->getMessage());
    }
}

// ══════════════════════════════════════════════
//  GET Requests — ADMS Handshake & Heartbeat
// ══════════════════════════════════════════════
if ($method === 'GET') {
    // ১. Registry হ্যান্ডশেক (ডিভাইস প্রথমবার কানেক্ট করলে)
    if ($table === 'options' && $cmd === 'registry') {
        $resp  = "GET PUSH\n";
        $resp .= "Pushver=2.4.1\n";
        $resp .= "ServerVersion=2.4.1 2015-04-07\n";
        $resp .= "PushOptionsFlag=1\n";
        $resp .= "ErrorDelay=30\n";
        $resp .= "RequestDelay=10\n";
        $resp .= "TransTimes=00:00;14:05\n";
        $resp .= "TransInterval=1\n";
        $resp .= "TransFlag=1111000000\n";
        $resp .= "TimeZone=6\n"; // Bangladesh GMT+6
        $resp .= "Realtime=1\n";
        $resp .= "Encrypt=None\n";
        $resp .= "ServerName=SchoolAttendance\n";
        $resp .= "ATTLOGStamp=None\n";
        $resp .= "OPERLOGStamp=None\n";
        $resp .= "ATTPHOTOStamp=None\n";
        zkLog("[$sn] Registry handshake OK");
        zkResponse($resp);
    }

    // ২. Heartbeat/getrequest — ডিভাইস পোল করে
    if (isset($_GET['SN']) && !$table) {
        zkLog("[$sn] Heartbeat / getrequest");
        zkResponse("OK\nDATE TIME:" . date('Y-m-d H:i:s') . "\n");
    }

    if ($table === 'ATTLOG' && $cmd === '') {
        zkResponse('OK');
    }

    zkResponse('OK');
}

// ══════════════════════════════════════════════
//  POST Requests — Data Push
// ══════════════════════════════════════════════
if ($method === 'POST') {
    // ── ATTLOG: পাঞ্চ রেকর্ড (সবচেয়ে গুরুত্বপূর্ণ) ──
    // Body ফরম্যাট (tab separated): PIN\tDateTime\tStatus\tVerify\tWorkCode\tReserved
    if ($table === 'ATTLOG' || strpos($rawBody, 'ATTLOG') !== false) {
        $attData = $rawBody;
        if (strpos($rawBody, 'ATTLOG=') !== false) {
            parse_str($rawBody, $parsed);
            $attData = $parsed['ATTLOG'] ?? $rawBody;
        }

        $lines = explode("\n", trim($attData));
        $inserted = 0; $skipped = 0; $errors = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line || strpos($line, 'ATTLOG') !== false) { continue; }

            $parts = preg_split('/\t+/', $line);
            if (count($parts) < 2) { $parts = preg_split('/\s+/', $line, 6); }
            if (count($parts) < 2) { continue; }

            $pin = trim($parts[0] ?? '');
            $dt1 = trim($parts[1] ?? '');
            $dt2 = trim($parts[2] ?? '');

            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $dt2)) {
                $punchTime  = $dt1 . ' ' . $dt2;
                $verifyType = (int) ($parts[4] ?? 0);
            } else {
                $punchTime  = $dt1;
                $verifyType = (int) ($parts[3] ?? 0);
            }

            if (!$pin || !strtotime($punchTime)) {
                zkLog("[$sn] SKIP invalid line: $line");
                $skipped++;
                continue;
            }
            $punchTime = date('Y-m-d H:i:s', strtotime($punchTime));
            $verifyLabel = [0 => 'Password', 1 => 'Fingerprint', 2 => 'Card'][$verifyType] ?? 'Other';

            try {
                [$personType, $personId] = zk_resolve_person($pdo, $pin);
                $punchType = zk_determine_punch_type($pdo, $pin, $punchTime);
                $isProcessed = $personType ? 1 : 0;

                $stmt = $pdo->prepare(
                    "INSERT IGNORE INTO device_attendance_logs (device_id, person_type, person_uid, punch_type, punch_time, verify_mode, raw_payload, is_processed, processed_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, IF(? = 1, NOW(), NULL))"
                );
                $stmt->execute([$device_id, $personType, $pin, $punchType, $punchTime, $verifyLabel, $line, $isProcessed, $isProcessed]);
                if ($stmt->rowCount() > 0) {
                    $inserted++;
                    if ($personType === 'student' && $personId) {
                        zk_mark_student($pdo, $personId, $punchType, $punchTime);
                    } elseif ($personType === 'employee' && $personId) {
                        zk_mark_employee($pdo, $personId, $punchType, $punchTime);
                    }
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                zkLog("[$sn] INSERT ERROR: " . $e->getMessage());
                $errors++;
            }
        }

        zkLog("[$sn] ATTLOG done — inserted=$inserted skipped=$skipped errors=$errors");
        zkResponse("OK: $inserted");
    }

    if ($table === 'OPERLOG') { zkLog("[$sn] OPERLOG received (logged only)"); zkResponse('OK'); }
    if ($table === 'user')    { zkLog("[$sn] User data received (ignored)"); zkResponse('OK'); }
    if ($table === 'options') { zkLog("[$sn] Options push received"); zkResponse('OK'); }

    zkResponse('OK');
}

zkResponse('OK');

// ══════════════════════════════════════════════
//  Helpers — person resolve, in/out determine, daily attendance mark
// ══════════════════════════════════════════════

/**
 * ডিভাইসের PIN দিয়ে student/employee খোঁজা। যেসব রিপোর্ট পেজ (attendance_today.php,
 * attendance_daily_summary.php ইত্যাদি) আগে থেকেই বানানো আছে, সেগুলো
 * device_attendance_logs.person_uid-কে students.rfid_card_no অথবা
 * students.student_id-এর সাথে মেলায় — তাই এখানেও ঠিক সেই একই কনভেনশন
 * অনুসরণ করা হচ্ছে (student_admission.php-এর "Device ID" ফিল্ড অর্থাৎ
 * device_serial থাকলে সেটাও অতিরিক্তভাবে চেক করা হয়, backward compatible)।
 * রিটার্ন করে [person_type, internal_id] অথবা [null, null]।
 */
function zk_resolve_person(PDO $pdo, string $pin): array
{
    // NOTE: Student/Employee এডিট ফর্মে "Finger ID" যেই কলামে সেভ হয় সেটা আসলে
    // tracking_id (device_serial আলাদা একটা ফিল্ড — "Device Serial / MAC", GPS/Transport
    // ট্র্যাকারের জন্য) — তাই tracking_id-ও এখানে চেক করা হচ্ছে, নাহলে প্রোফাইলে
    // "Finger ID" বসালেও পাঞ্চ Unmatched-ই থেকে যাবে।
    $stu = $pdo->prepare(
        "SELECT id FROM students WHERE status = 'Active' AND (rfid_card_no = ? OR student_id = ? OR device_serial = ? OR tracking_id = ?) LIMIT 1"
    );
    $stu->execute([$pin, $pin, $pin, $pin]);
    $stuRow = $stu->fetch();
    if ($stuRow) { return ['student', (int) $stuRow['id']]; }

    $emp = $pdo->prepare(
        "SELECT id FROM employees WHERE status = 'Active' AND (rfid_card_no = ? OR employee_id = ? OR device_serial = ? OR tracking_id = ?) LIMIT 1"
    );
    $emp->execute([$pin, $pin, $pin, $pin]);
    $empRow = $emp->fetch();
    if ($empRow) { return ['employee', (int) $empRow['id']]; }

    return [null, null];
}

/**
 * দিনের প্রথম পাঞ্চ = 'in', তারপরের যেকোনো পাঞ্চ = 'out' — attendance_today.php-এর
 * MIN(in)/MAX(out) কোয়েরির সাথে সামঞ্জস্যপূর্ণ (২টার বেশি পাঞ্চ হলেও শেষটাই out ধরা হবে)।
 */
function zk_determine_punch_type(PDO $pdo, string $pin, string $punchTime): string
{
    $date = date('Y-m-d', strtotime($punchTime));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM device_attendance_logs WHERE person_uid = ? AND DATE(punch_time) = ?");
    $stmt->execute([$pin, $date]);
    return ((int) $stmt->fetchColumn()) === 0 ? 'in' : 'out';
}

function zk_mark_student(PDO $pdo, int $studentId, string $punchType, string $punchTime): void
{
    $date = date('Y-m-d', strtotime($punchTime));
    $stu = $pdo->prepare("SELECT class_id, section_id, shift_id FROM students WHERE id = ?");
    $stu->execute([$studentId]);
    $stuRow = $stu->fetch() ?: [];

    $att = $pdo->prepare("SELECT id FROM student_daily_attendance WHERE student_id = ? AND attendance_date = ?");
    $att->execute([$studentId, $date]);
    $row = $att->fetch();

    if (!$row) {
        $pdo->prepare(
            "INSERT INTO student_daily_attendance (student_id, class_id, section_id, shift_id, attendance_date, status, marked_by, check_in_time, check_out_time)
             VALUES (?, ?, ?, ?, ?, 'Present', 'Device', ?, ?)"
        )->execute([
            $studentId, $stuRow['class_id'] ?? null, $stuRow['section_id'] ?? null, $stuRow['shift_id'] ?? null, $date,
            $punchType === 'in' ? $punchTime : null,
            $punchType === 'out' ? $punchTime : null,
        ]);
        return;
    }

    if ($punchType === 'out') {
        $pdo->prepare("UPDATE student_daily_attendance SET check_out_time = ?, status = 'Present' WHERE id = ?")->execute([$punchTime, $row['id']]);
    } else {
        $pdo->prepare("UPDATE student_daily_attendance SET status = 'Present' WHERE id = ?")->execute([$row['id']]);
    }
}

function zk_mark_employee(PDO $pdo, int $employeeId, string $punchType, string $punchTime): void
{
    $date = date('Y-m-d', strtotime($punchTime));
    $att = $pdo->prepare("SELECT id FROM employee_daily_attendance WHERE employee_id = ? AND attendance_date = ?");
    $att->execute([$employeeId, $date]);
    $row = $att->fetch();

    if (!$row) {
        $pdo->prepare(
            "INSERT INTO employee_daily_attendance (employee_id, attendance_date, status, marked_by, source, check_in_time, check_out_time)
             VALUES (?, ?, 'Present', 'Device', 'device', ?, ?)"
        )->execute([
            $employeeId, $date,
            $punchType === 'in' ? $punchTime : null,
            $punchType === 'out' ? $punchTime : null,
        ]);
        return;
    }

    if ($punchType === 'out') {
        $pdo->prepare("UPDATE employee_daily_attendance SET check_out_time = ? WHERE id = ?")->execute([$punchTime, $row['id']]);
    }
}
