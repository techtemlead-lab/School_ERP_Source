<?php
// admin/device_settings.php - Device Settings (Global Settings > Device Settings)
// বিভিন্ন কোম্পানির Attendance Machine (Cloud-based / Non-cloud-Local) অ্যাড, এডিট,
// কানেকশন টেস্ট এবং তাদের Live Punch Data দেখার পেজ।

// -----------------------------------------------------------
// Self-healing: এই দুটো টেবিল আগে কোথাও তৈরি হতো না (schema.sql-এও ছিল না),
// এখন এখানেই প্রথমবার পেজ লোড হওয়ার সময় না থাকলে বানিয়ে নেওয়া হচ্ছে —
// পুরনো সব client DB-তেও পরের পেজ-লোডেই আপনাআপনি ঠিক হয়ে যাবে।
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS attendance_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_name VARCHAR(150) NOT NULL,
        brand VARCHAR(50) NOT NULL,
        device_model VARCHAR(100) NULL,
        device_for ENUM('student','employee','both') NOT NULL DEFAULT 'both',
        branch_id INT NULL,
        connection_type ENUM('cloud','local') NOT NULL DEFAULT 'local',
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
        INDEX idx_device (device_id, punch_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
foreach (['verify_mode' => "VARCHAR(20) NULL", 'is_processed' => "TINYINT(1) NOT NULL DEFAULT 0", 'processed_at' => "DATETIME NULL"] as $dcol => $ddef) {
    $has = $pdo->query("SHOW COLUMNS FROM device_attendance_logs LIKE '$dcol'")->fetch();
    if (!$has) { try { $pdo->exec("ALTER TABLE device_attendance_logs ADD COLUMN `$dcol` $ddef"); } catch (Throwable $e) {} }
}
// পুরনো ভার্সনে person_type/punch_type NOT NULL ছিল (পাঞ্চ এলেই সাথে সাথে person
// ম্যাচ করে বসানো হতো) — এখন প্রথমে raw log সেভ হয়, processing পরে হয়, তাই nullable করা দরকার
try { $pdo->exec("ALTER TABLE device_attendance_logs MODIFY COLUMN person_type ENUM('student','employee') NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE device_attendance_logs MODIFY COLUMN punch_type ENUM('in','out') NOT NULL DEFAULT 'in'"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE device_attendance_logs ADD UNIQUE KEY uq_device_uid_time (device_id, person_uid, punch_time)"); } catch (Throwable $e) {}

// zk_receiver.php / zk_agent_list.php (ZKTeco ADMS push + LAN Sync Agent) — একই
// স্কিম এখানেও ensure করা হচ্ছে যাতে এই পেজ আগে ভিজিট হলেও কোনো সমস্যা না হয়
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS attendance_agent_settings (
        id INT PRIMARY KEY,
        secret VARCHAR(64) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$agent_secret = $pdo->query("SELECT secret FROM attendance_agent_settings WHERE id = 1")->fetchColumn();
if (!$agent_secret) {
    $agent_secret = bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO attendance_agent_settings (id, secret) VALUES (1, ?) ON DUPLICATE KEY UPDATE secret = secret")->execute([$agent_secret]);
    $reread = $pdo->query("SELECT secret FROM attendance_agent_settings WHERE id = 1")->fetchColumn();
    if ($reread) { $agent_secret = $reread; }
}
$agent_base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/admin';

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
foreach (['check_in_time' => 'DATETIME NULL', 'check_out_time' => 'DATETIME NULL'] as $scol => $sdef) {
    $has = $pdo->query("SHOW COLUMNS FROM student_daily_attendance LIKE '$scol'")->fetch();
    if (!$has) { try { $pdo->exec("ALTER TABLE student_daily_attendance ADD COLUMN `$scol` $sdef"); } catch (Throwable $e) {} }
}
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

// এক-বারের ক্লিনআপ: আগে Local ডিভাইসেও fsockopen টেস্ট চালানোর ফলে ভুলভাবে
// live_status='offline' সেভ হয়ে গিয়েছিল (সার্ভার তো প্রাইভেট IP-তে কখনোই পৌঁছাতে
// পারে না)। সেই স্টেল/ভুল স্ট্যাটাস মুছে "Unknown" এ ফিরিয়ে দেওয়া হচ্ছে — zk_receiver.php
// ডেটা পেলে এমনিতেই এটা "online" হয়ে যাবে।
try {
    $pdo->exec("UPDATE attendance_devices SET live_status = NULL WHERE connection_type = 'local' AND live_status = 'offline' AND last_seen_at IS NULL");
} catch (Throwable $e) {}

$dev_msg = $_SESSION['dev_msg'] ?? '';
$dev_err = $_SESSION['dev_err'] ?? '';
unset($_SESSION['dev_msg'], $_SESSION['dev_err']);

$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

$valid_brands    = ['ZKTeco', 'eSSL', 'Hikvision', 'Anviz', 'Realtime', 'ClockOnWork', 'Other'];
$valid_protocols = ['zkteco_tcp' => 'ZKTeco TCP/UDP (SDK)', 'push_sdk' => 'Push SDK (ADMS)', 'adms' => 'ADMS (HTTP Push)', 'onvif' => 'ONVIF', 'other' => 'Other'];

// -----------------------------------------------------------
// একটা Local ডিভাইসের সাথে সরাসরি সংযোগ পরীক্ষা (socket দিয়ে
// IP:Port খোলা আছে কিনা দেখা) — Cloud ডিভাইসের জন্য API URL এ
// হালকা HTTP রিকোয়েস্ট পাঠানো হয়।
// -----------------------------------------------------------
function device_test_connection(array $device): array
{
    if ($device['connection_type'] === 'local') {
        $ip   = trim((string) ($device['ip_address'] ?? ''));
        $port = (int) ($device['port'] ?? 4370);
        if ($ip === '') {
            return ['online' => false, 'note' => 'IP Address সেট করা নেই।'];
        }
        $conn = @fsockopen($ip, $port ?: 4370, $errno, $errstr, 3);
        if ($conn) {
            fclose($conn);
            return ['online' => true, 'note' => "সংযোগ সফল ({$ip}:{$port})"];
        }
        return ['online' => false, 'note' => $errstr ?: 'ডিভাইসে সংযোগ করা যায়নি।'];
    }

    // Cloud ডিভাইস
    $url = trim((string) ($device['api_base_url'] ?? ''));
    if ($url === '') {
        return ['online' => false, 'note' => 'API Base URL সেট করা নেই।'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    $http_ok = curl_errno($ch) === 0;
    $curl_err = curl_error($ch);
    curl_close($ch);

    return $http_ok
        ? ['online' => true, 'note' => 'Cloud API রেসপন্স করেছে।']
        : ['online' => false, 'note' => $curl_err ?: 'Cloud API থেকে রেসপন্স পাওয়া যায়নি।'];
}

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['dev_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=device_settings');
        exit();
    }

    if (isset($_POST['save_device'])) {
        $id               = (int) ($_POST['id'] ?? 0);
        $device_name      = trim($_POST['device_name'] ?? '');
        $brand            = trim($_POST['brand'] ?? '');
        $device_model     = trim($_POST['device_model'] ?? '');
        $device_for       = trim($_POST['device_for'] ?? 'both');
        $branch_id        = (int) ($_POST['branch_id'] ?? 0) ?: null;
        $connection_type  = trim($_POST['connection_type'] ?? 'local');

        $cloud_provider   = trim($_POST['cloud_provider'] ?? '');
        $api_base_url     = trim($_POST['api_base_url'] ?? '');
        $api_key          = trim($_POST['api_key'] ?? '');
        $api_secret       = trim($_POST['api_secret'] ?? '');
        $cloud_device_sn  = trim($_POST['cloud_device_sn'] ?? '');

        $ip_address       = trim($_POST['ip_address'] ?? '');
        $port             = (int) ($_POST['port'] ?? 4370) ?: 4370;
        $device_serial    = trim($_POST['device_serial'] ?? '');
        $comm_key         = trim($_POST['comm_key'] ?? '');
        $protocol         = trim($_POST['protocol'] ?? 'zkteco_tcp');

        $status           = trim($_POST['status'] ?? 'Active');
        $notes            = trim($_POST['notes'] ?? '');

        if (!in_array($device_for, ['student', 'employee', 'both'], true)) $device_for = 'both';
        if (!in_array($connection_type, ['cloud', 'local'], true)) $connection_type = 'local';
        if (!array_key_exists($protocol, $valid_protocols)) $protocol = 'zkteco_tcp';
        if (!in_array($status, ['Active', 'Inactive'], true)) $status = 'Active';

        // Local/LAN ডিভাইসের জন্য Brand সিলেক্ট না করলেও চলবে — বেশিরভাগ LAN ডিভাইসই
        // ZKTeco, তাই ডিফল্ট বসিয়ে দেওয়া হচ্ছে (অন্য প্রোডাক্টের quick-add প্যাটার্নের মতো)।
        if ($connection_type === 'local' && $brand === '') { $brand = 'ZKTeco'; }
        if ($device_name === '' && $connection_type === 'local' && $device_serial !== '') { $device_name = 'Device ' . $device_serial; }

        if ($device_name === '' || $brand === '') {
            $_SESSION['dev_err'] = 'Device Name এবং Brand আবশ্যক।';
        } elseif ($connection_type === 'local' && $ip_address === '') {
            $_SESSION['dev_err'] = 'Local ডিভাইসের জন্য IP Address আবশ্যক।';
        } elseif ($connection_type === 'cloud' && $api_base_url === '') {
            $_SESSION['dev_err'] = 'Cloud ডিভাইসের জন্য API Base URL আবশ্যক।';
        } else {
            // Local ডিভাইস নতুন করে যোগ করার সময় একই Serial Number আগে থেকেই থাকলে
            // ডুপ্লিকেট row না বানিয়ে সেই existing device-টাই আপডেট করে দেওয়া হচ্ছে।
            if ($id === 0 && $connection_type === 'local' && $device_serial !== '') {
                $dup = $pdo->prepare("SELECT id FROM attendance_devices WHERE device_serial = ? LIMIT 1");
                $dup->execute([$device_serial]);
                $dup_id = $dup->fetchColumn();
                if ($dup_id) { $id = (int) $dup_id; }
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE attendance_devices SET
                        device_name=?, brand=?, device_model=?, device_for=?, branch_id=?, connection_type=?,
                        cloud_provider=?, api_base_url=?, api_key=?, api_secret=?, cloud_device_sn=?,
                        ip_address=?, port=?, device_serial=?, comm_key=?, protocol=?,
                        status=?, notes=?
                     WHERE id=?"
                );
                $stmt->execute([
                    $device_name, $brand, $device_model ?: null, $device_for, $branch_id, $connection_type,
                    $cloud_provider ?: null, $api_base_url ?: null, $api_key ?: null, $api_secret ?: null, $cloud_device_sn ?: null,
                    $ip_address ?: null, $port, $device_serial ?: null, $comm_key ?: null, $protocol,
                    $status, $notes ?: null, $id,
                ]);
                sec_log_activity($pdo, 'Device Settings', 'Update', "ডিভাইসের তথ্য আপডেট করা হয়েছে: {$device_name} ({$brand})");
                $_SESSION['dev_msg'] = 'ডিভাইসের তথ্য আপডেট করা হয়েছে।';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO attendance_devices
                        (device_name, brand, device_model, device_for, branch_id, connection_type,
                         cloud_provider, api_base_url, api_key, api_secret, cloud_device_sn,
                         ip_address, port, device_serial, comm_key, protocol, status, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $device_name, $brand, $device_model ?: null, $device_for, $branch_id, $connection_type,
                    $cloud_provider ?: null, $api_base_url ?: null, $api_key ?: null, $api_secret ?: null, $cloud_device_sn ?: null,
                    $ip_address ?: null, $port, $device_serial ?: null, $comm_key ?: null, $protocol, $status, $notes ?: null,
                ]);
                sec_log_activity($pdo, 'Device Settings', 'Create', "নতুন ডিভাইস যোগ করা হয়েছে: {$device_name} ({$brand})");
                $_SESSION['dev_msg'] = 'নতুন ডিভাইস যোগ করা হয়েছে।';
            }
        }
        header('Location: index.php?page=device_settings');
        exit();

    } elseif (isset($_POST['delete_device'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_dev_stmt = $pdo->prepare("SELECT device_name FROM attendance_devices WHERE id = ?");
        $del_dev_stmt->execute([$id]);
        $del_device_name = $del_dev_stmt->fetchColumn();
        $pdo->prepare("DELETE FROM attendance_devices WHERE id = ?")->execute([$id]);
        sec_log_activity($pdo, 'Device Settings', 'Delete', "ডিভাইস মুছে ফেলা হয়েছে: " . ($del_device_name ?: "#$id"));
        $_SESSION['dev_msg'] = 'ডিভাইস মুছে ফেলা হয়েছে।';
        header('Location: index.php?page=device_settings');
        exit();

    } elseif (isset($_POST['test_connection'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM attendance_devices WHERE id = ?");
        $stmt->execute([$id]);
        $device = $stmt->fetch();

        if ($device) {
            if ($device['connection_type'] === 'local') {
                // Local/LAN ডিভাইসের IP সাধারণত প্রাইভেট (192.168.x.x) — এই ওয়েব সার্ভার
                // (cPanel hosting) ইন্টারনেট থেকে ওই IP-তে কখনোই পৌঁছাতে পারবে না, তাই
                // সরাসরি fsockopen টেস্ট চালালে সবসময় ভুলভাবে "ব্যর্থ" দেখাবে। তাই Local
                // ডিভাইসের জন্য এই বাটন থেকে live_status ওভাররাইট না করে শুধু বুঝিয়ে দেওয়া
                // হচ্ছে আসল টেস্ট কীভাবে করতে হয় — zk_receiver.php ডেটা পেলেই
                // live_status/last_seen_at এমনিতেই "online" হয়ে যাবে।
                $_SESSION['dev_msg'] = 'ℹ️ ' . $device['device_name'] . ': এটা একটা Local/LAN ডিভাইস — এই সার্ভার থেকে সরাসরি টেস্ট করা যায় না (প্রাইভেট IP)। PC-তে Sync Agent (zk_agent.py) চালু থাকলে ডেটা এলেই এই ডিভাইস এমনিতেই "Online" দেখাবে — Live Data ট্যাব চেক করুন।';
            } else {
                $result = device_test_connection($device);
                $live_status = $result['online'] ? 'online' : 'offline';
                $upd = $pdo->prepare("UPDATE attendance_devices SET live_status=?, last_seen_at=IF(?=1, NOW(), last_seen_at) WHERE id=?");
                $upd->execute([$live_status, $result['online'] ? 1 : 0, $id]);
                $_SESSION['dev_msg'] = ($result['online'] ? '✅ ' : '⚠️ ') . $device['device_name'] . ': ' . $result['note'];
            }
        } else {
            $_SESSION['dev_err'] = 'ডিভাইস খুঁজে পাওয়া যায়নি।';
        }
        header('Location: index.php?page=device_settings#liveTab');
        exit();

    } elseif (isset($_POST['reprocess_pending'])) {
        // person_type IS NULL মানে আগে কোনো student/employee-এর সাথে ম্যাচ হয়নি —
        // admin হয়তো এখন rfid_card_no/student_id/Device ID বসিয়ে দিয়েছেন, তাই আবার চেষ্টা
        $logs = $pdo->query("SELECT * FROM device_attendance_logs WHERE person_type IS NULL AND is_processed = 0 ORDER BY punch_time ASC LIMIT 500")->fetchAll();
        $done = 0; $matched = 0;
        foreach ($logs as $log) {
            $uid = $log['person_uid'];
            $punchTime = $log['punch_time'];
            $punchType = $log['punch_type'];

            // NOTE: student/employee এডিট ফর্মে "Finger ID" যেই কলামে সেভ হয় সেটা আসলে
            // tracking_id (device_serial আলাদা, ওটা GPS/Transport ট্র্যাকারের MAC-এর জন্য) —
            // তাই এখানে tracking_id-ও ম্যাচ করা হচ্ছে, নাহলে "Finger ID" বসালেও Unmatched থেকে যাবে।
            $stu = $pdo->prepare("SELECT id, class_id, section_id, shift_id FROM students WHERE status = 'Active' AND (rfid_card_no = ? OR student_id = ? OR device_serial = ? OR tracking_id = ?) LIMIT 1");
            $stu->execute([$uid, $uid, $uid, $uid]);
            $stuRow = $stu->fetch();
            $personType = null;

            if ($stuRow) {
                $personType = 'student';
                $date = date('Y-m-d', strtotime($punchTime));
                $att = $pdo->prepare("SELECT id FROM student_daily_attendance WHERE student_id = ? AND attendance_date = ?");
                $att->execute([(int) $stuRow['id'], $date]);
                $row = $att->fetch();
                if (!$row) {
                    $pdo->prepare("INSERT INTO student_daily_attendance (student_id, class_id, section_id, shift_id, attendance_date, status, marked_by, check_in_time, check_out_time) VALUES (?, ?, ?, ?, ?, 'Present', 'Device', ?, ?)")
                        ->execute([(int) $stuRow['id'], $stuRow['class_id'], $stuRow['section_id'], $stuRow['shift_id'], $date, $punchType === 'in' ? $punchTime : null, $punchType === 'out' ? $punchTime : null]);
                } elseif ($punchType === 'out') {
                    $pdo->prepare("UPDATE student_daily_attendance SET check_out_time = ?, status = 'Present' WHERE id = ?")->execute([$punchTime, $row['id']]);
                }
            } else {
                $emp = $pdo->prepare("SELECT id FROM employees WHERE status = 'Active' AND (rfid_card_no = ? OR employee_id = ? OR device_serial = ? OR tracking_id = ?) LIMIT 1");
                $emp->execute([$uid, $uid, $uid, $uid]);
                $empRow = $emp->fetch();
                if ($empRow) {
                    $personType = 'employee';
                    $date = date('Y-m-d', strtotime($punchTime));
                    $att = $pdo->prepare("SELECT id FROM employee_daily_attendance WHERE employee_id = ? AND attendance_date = ?");
                    $att->execute([(int) $empRow['id'], $date]);
                    $row = $att->fetch();
                    if (!$row) {
                        $pdo->prepare("INSERT INTO employee_daily_attendance (employee_id, attendance_date, status, marked_by, source, check_in_time, check_out_time) VALUES (?, ?, 'Present', 'Device', 'device', ?, ?)")
                            ->execute([(int) $empRow['id'], $date, $punchType === 'in' ? $punchTime : null, $punchType === 'out' ? $punchTime : null]);
                    } elseif ($punchType === 'out') {
                        $pdo->prepare("UPDATE employee_daily_attendance SET check_out_time = ? WHERE id = ?")->execute([$punchTime, $row['id']]);
                    }
                }
            }

            if ($personType) {
                $pdo->prepare("UPDATE device_attendance_logs SET is_processed = 1, processed_at = NOW(), person_type = ? WHERE id = ?")->execute([$personType, $log['id']]);
                $matched++;
            }
            $done++;
        }
        $_SESSION['dev_msg'] = "{$done} টি pending log চেক করা হয়েছে — {$matched} টি এখন ম্যাচ পেয়ে attendance তৈরি হয়েছে।";
        header('Location: index.php?page=device_settings#liveTab');
        exit();

    } elseif (isset($_POST['skip_all_pending'])) {
        // আগে এটা শুধু is_processed=1 করে "pending" থেকে সরাতো, কিন্তু রো-টা টেবিলে
        // থেকেই যেত (Live Data-এ "Unmatched" হয়েই দেখাতে থাকতো) — এখন সরাসরি
        // ডিলিট করে দেওয়া হচ্ছে, যাতে লিস্ট থেকেও একেবারে সরে যায়।
        $del = $pdo->prepare("DELETE FROM device_attendance_logs WHERE person_type IS NULL AND is_processed = 0");
        $del->execute();
        $skipped = $del->rowCount();
        $_SESSION['dev_msg'] = "{$skipped} টি অ্যানম্যাচড log স্থায়ীভাবে ডিলিট করা হয়েছে (কোনো attendance তৈরি হয়নি)।";
        header('Location: index.php?page=device_settings#liveTab');
        exit();

    }
}

// -----------------------------------------------------------
// Data fetch
// -----------------------------------------------------------
$devices = $pdo->query("SELECT d.*, b.short_name AS branch_name FROM attendance_devices d LEFT JOIN branches b ON b.id = d.branch_id ORDER BY d.id DESC")->fetchAll();

$edit_device = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM attendance_devices WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_device = $stmt->fetch();
}

$form_defaults = $edit_device ?: [
    'id' => 0, 'device_name' => '', 'brand' => '', 'device_model' => '', 'device_for' => 'both', 'branch_id' => '',
    'connection_type' => 'local', 'cloud_provider' => '', 'api_base_url' => '', 'api_key' => '', 'api_secret' => '', 'cloud_device_sn' => '',
    'ip_address' => '', 'port' => 4370, 'device_serial' => '', 'comm_key' => '', 'protocol' => 'zkteco_tcp',
    'status' => 'Active', 'notes' => '',
];

$branches_list = $pdo->query("SELECT id, short_name FROM branches ORDER BY short_name ASC")->fetchAll();

// সাম্প্রতিক ৫০টা Live Punch Log (থাকলে) — student/employee নাম resolve করে দেখানো হয়
$recent_logs = $pdo->query(
    "SELECT l.*, d.device_name,
        COALESCE(s.name_en, e.name) AS person_name,
        COALESCE(s.student_id, e.employee_id) AS person_code
     FROM device_attendance_logs l
     LEFT JOIN attendance_devices d ON d.id = l.device_id
     LEFT JOIN students s ON l.person_type = 'student' AND (s.rfid_card_no = l.person_uid OR s.student_id = l.person_uid OR s.device_serial = l.person_uid OR s.tracking_id = l.person_uid)
     LEFT JOIN employees e ON l.person_type = 'employee' AND (e.rfid_card_no = l.person_uid OR e.employee_id = l.person_uid OR e.device_serial = l.person_uid OR e.tracking_id = l.person_uid)
     ORDER BY l.punch_time DESC LIMIT 50"
)->fetchAll();

$pending_count = (int) $pdo->query("SELECT COUNT(*) FROM device_attendance_logs WHERE person_type IS NULL AND is_processed = 0")->fetchColumn();

function dv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
?>

<div class="card-custom">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-fingerprint"></i> Device Settings</h4>
        <div>
            <button type="button" class="btn btn-outline-primary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#cfgModal"><i class="fa-solid fa-gear"></i> Device Config Guide (Cloud/ADMS)</button>
            <button type="button" class="btn btn-outline-success btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#agentModal"><i class="fa-solid fa-network-wired"></i> LAN Sync Agent Setup</button>
        </div>
    </div>

    <?php if ($dev_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($dev_msg); ?></div><?php endif; ?>
    <?php if ($dev_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($dev_err); ?></div><?php endif; ?>

    <ul class="nav nav-tabs mb-4" id="deviceTab" role="tablist">
        <li class="nav-item"><button class="nav-link active fw-bold" data-bs-toggle="tab" data-bs-target="#devicesTab">Devices</button></li>
        <li class="nav-item"><button class="nav-link fw-bold" data-bs-toggle="tab" data-bs-target="#liveTab">Live Data <?php echo $pending_count > 0 ? '<span class="badge bg-warning text-dark ms-1">' . $pending_count . ' pending</span>' : ''; ?></button></li>
    </ul>

    <div class="tab-content">
        <!-- ============== Devices Tab ============== -->
        <div class="tab-pane fade show active" id="devicesTab">

            <div class="row">
                <div class="col-lg-6">
                    <h6 class="fw-bold mb-3"><?php echo $edit_device ? 'Edit Device' : 'Add New Device'; ?></h6>
                    <form method="POST" action="index.php?page=device_settings">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="id" value="<?php echo (int) $form_defaults['id']; ?>">

                        <div class="mb-3">
                            <label class="form-label fw-bold">Device Name <span class="text-muted small">(local + serial দিলে খালি রাখলেও অটো বসে যাবে)</span></label>
                            <input type="text" name="device_name" class="form-control" placeholder="যেমনঃ Main Gate ZKTeco" value="<?php echo dv($form_defaults['device_name']); ?>">
                        </div>

                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label class="form-label fw-bold">Brand / Company <span class="text-muted small">(Local হলে ফাঁকা রাখলে ZKTeco ধরা হবে)</span></label>
                                <select name="brand" class="form-select">
                                    <option value="">-- Select Brand --</option>
                                    <?php foreach ($valid_brands as $b): ?>
                                        <option value="<?php echo $b; ?>" <?php echo $form_defaults['brand'] === $b ? 'selected' : ''; ?>><?php echo $b; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="form-label fw-bold">Model</label>
                                <input type="text" name="device_model" class="form-control" placeholder="যেমনঃ K40, X990" value="<?php echo dv($form_defaults['device_model']); ?>">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label class="form-label fw-bold">Device For</label>
                                <select name="device_for" class="form-select">
                                    <option value="student" <?php echo $form_defaults['device_for'] === 'student' ? 'selected' : ''; ?>>Student</option>
                                    <option value="employee" <?php echo $form_defaults['device_for'] === 'employee' ? 'selected' : ''; ?>>Employee</option>
                                    <option value="both" <?php echo $form_defaults['device_for'] === 'both' ? 'selected' : ''; ?>>Both</option>
                                </select>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="form-label fw-bold">Branch</label>
                                <select name="branch_id" class="form-select">
                                    <option value="">-- No Branch --</option>
                                    <?php foreach ($branches_list as $br): ?>
                                        <option value="<?php echo (int) $br['id']; ?>" <?php echo (int) $form_defaults['branch_id'] === (int) $br['id'] ? 'selected' : ''; ?>><?php echo dv($br['short_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold d-block">Connection Type <span class="text-danger">*</span></label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="connection_type" id="conn_local" value="local" <?php echo $form_defaults['connection_type'] === 'local' ? 'checked' : ''; ?>>
                                <label class="btn btn-outline-primary fw-bold" for="conn_local"><i class="fa-solid fa-network-wired"></i> Local / Non-Cloud (LAN)</label>

                                <input type="radio" class="btn-check" name="connection_type" id="conn_cloud" value="cloud" <?php echo $form_defaults['connection_type'] === 'cloud' ? 'checked' : ''; ?>>
                                <label class="btn btn-outline-primary fw-bold" for="conn_cloud"><i class="fa-solid fa-cloud"></i> Cloud-Based</label>
                            </div>
                        </div>

                        <!-- ===== Local fields ===== -->
                        <div id="localFields" class="border rounded p-3 mb-3 bg-light">
                            <h6 class="fw-bold text-primary small mb-2"><i class="fa-solid fa-network-wired"></i> Local Device Info (LAN)</h6>
                            <p class="small text-muted mb-3">
                                যে ডিভাইসে ইন্টারনেট নেই সেটার জন্য ডিভাইসের গায়ে/Menu → System Info-এ থাকা
                                <strong>Device Serial No</strong> আর সেটার <strong>IP Address</strong> দিলেই যথেষ্ট —
                                বাকি সব (sync, employee match, attendance বসানো) স্বয়ংক্রিয়ভাবে হয়ে যাবে, যদি ওই
                                ডিভাইসের LAN-এ থাকা PC-তে Sync Agent চালু থাকে। Brand/Protocol ফাঁকা রাখলে
                                ডিফল্ট ZKTeco ধরে নেওয়া হবে।
                            </p>
                            <div class="row">
                                <div class="col-sm-6 mb-3">
                                    <label class="form-label small fw-bold">IP Address</label>
                                    <input type="text" name="ip_address" class="form-control" placeholder="192.168.1.201" value="<?php echo dv($form_defaults['ip_address']); ?>">
                                </div>
                                <div class="col-sm-6 mb-3">
                                    <label class="form-label small fw-bold">Port</label>
                                    <input type="number" name="port" class="form-control" placeholder="4370" value="<?php echo dv($form_defaults['port']); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-sm-6 mb-3">
                                    <label class="form-label small fw-bold">Device Serial No</label>
                                    <input type="text" name="device_serial" class="form-control" value="<?php echo dv($form_defaults['device_serial']); ?>">
                                </div>
                                <div class="col-sm-6 mb-3">
                                    <label class="form-label small fw-bold">Comm Key / Password</label>
                                    <input type="text" name="comm_key" class="form-control" value="<?php echo dv($form_defaults['comm_key']); ?>">
                                </div>
                            </div>
                            <!-- Protocol ফিল্ড আলাদা করে দেখানো হচ্ছে না (অন্য প্রোডাক্টের মতোই) —
                                 ডিফল্ট ZKTeco TCP/UDP (SDK) হিসেবেই সাবমিট হবে, edit করা device-এর
                                 আগের protocol থাকলে সেটাই বজায় থাকবে। -->
                            <input type="hidden" name="protocol" value="<?php echo dv($form_defaults['protocol'] ?: 'zkteco_tcp'); ?>">
                        </div>

                        <!-- ===== Cloud fields ===== -->
                        <div id="cloudFields" class="border rounded p-3 mb-3 bg-light">
                            <h6 class="fw-bold text-primary small mb-3"><i class="fa-solid fa-cloud"></i> Cloud Device Info</h6>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Cloud Provider Name</label>
                                <input type="text" name="cloud_provider" class="form-control" placeholder="যেমনঃ ZKBio CVSecurity Cloud" value="<?php echo dv($form_defaults['cloud_provider']); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">API Base URL</label>
                                <input type="text" name="api_base_url" class="form-control" placeholder="https://api.provider.com/v1" value="<?php echo dv($form_defaults['api_base_url']); ?>">
                            </div>
                            <div class="row">
                                <div class="col-sm-6 mb-3">
                                    <label class="form-label small fw-bold">API Key</label>
                                    <input type="text" name="api_key" class="form-control" value="<?php echo dv($form_defaults['api_key']); ?>">
                                </div>
                                <div class="col-sm-6 mb-3">
                                    <label class="form-label small fw-bold">API Secret</label>
                                    <input type="password" name="api_secret" class="form-control" value="<?php echo dv($form_defaults['api_secret']); ?>" autocomplete="new-password">
                                </div>
                            </div>
                            <div class="mb-1">
                                <label class="form-label small fw-bold">Cloud Device ID / Serial</label>
                                <input type="text" name="cloud_device_sn" class="form-control" value="<?php echo dv($form_defaults['cloud_device_sn']); ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"><?php echo dv($form_defaults['notes']); ?></textarea>
                        </div>

                        <div class="row mb-4 align-items-center">
                            <label class="col-sm-3 col-form-label fw-bold">Status</label>
                            <div class="col-sm-9">
                                <select name="status" class="form-select">
                                    <option value="Active" <?php echo $form_defaults['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo $form_defaults['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>

                        <button type="submit" name="save_device" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $edit_device ? 'Update Device' : 'Add Device'; ?></button>
                        <?php if ($edit_device): ?><a href="index.php?page=device_settings" class="btn btn-secondary px-4 fw-bold ms-2">Cancel Edit</a><?php endif; ?>
                    </form>
                </div>

                <div class="col-lg-6">
                    <h6 class="fw-bold mb-3">Device List</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle">
                            <thead class="table-dark">
                                <tr><th>Device</th><th>Type</th><th>Status</th><th>Live</th><th class="text-end">Action</th></tr>
                            </thead>
                            <tbody>
                                <?php if ($devices): foreach ($devices as $d): $csrf = htmlspecialchars(csrf_token()); ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo dv($d['device_name']); ?></strong><br>
                                            <small class="text-muted"><?php echo dv($d['brand']); ?><?php echo $d['device_model'] ? ' / ' . dv($d['device_model']) : ''; ?><?php echo $d['branch_name'] ? ' • ' . dv($d['branch_name']) : ''; ?></small>
                                        </td>
                                        <td>
                                            <?php if ($d['connection_type'] === 'cloud'): ?>
                                                <span class="badge bg-info text-dark"><i class="fa-solid fa-cloud"></i> Cloud</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><i class="fa-solid fa-network-wired"></i> Local</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $d['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                        <td>
                                            <?php
                                            $ls = $d['live_status'];
                                            if ($ls === 'online') echo '<span class="badge bg-success">● Online</span>';
                                            elseif ($ls === 'offline') echo '<span class="badge bg-danger">● Offline</span>';
                                            else echo '<span class="badge bg-secondary">● Unknown</span>';
                                            ?>
                                        </td>
                                        <td class="text-end">
                                            <form method="POST" action="index.php?page=device_settings" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                                                <button type="submit" name="test_connection" class="btn btn-outline-info btn-sm" title="Test Connection"><i class="fa-solid fa-plug-circle-check"></i></button>
                                            </form>
                                            <a href="index.php?page=device_settings&edit=<?php echo (int) $d['id']; ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                                            <form method="POST" action="index.php?page=device_settings" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                                                <button type="submit" name="delete_device" class="btn btn-danger btn-sm">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="5" class="text-center text-muted py-3">এখনো কোনো ডিভাইস যোগ করা হয়নি</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============== Live Data Tab ============== -->
        <div class="tab-pane fade" id="liveTab">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h6 class="fw-bold mb-0">Live Punch Data (সর্বশেষ ৫০টি)</h6>
                <div>
                    <?php if ($pending_count > 0): ?>
                        <form method="POST" action="index.php?page=device_settings" class="d-inline">
                            <?php echo csrf_field(); ?>
                            <button type="submit" name="reprocess_pending" class="btn btn-outline-success btn-sm fw-bold" onclick="return confirm('<?php echo $pending_count; ?> টি pending log প্রসেস করবেন? পুরনো তারিখের attendance-ও তৈরি হয়ে যাবে।');"><i class="fa-solid fa-rotate-right"></i> Reprocess (<?php echo $pending_count; ?>)</button>
                        </form>
                        <form method="POST" action="index.php?page=device_settings" class="d-inline">
                            <?php echo csrf_field(); ?>
                            <button type="submit" name="skip_all_pending" class="btn btn-outline-danger btn-sm fw-bold" onclick="return confirm('<?php echo $pending_count; ?> টি পুরনো Unmatched log স্থায়ীভাবে ডিলিট করবেন? এটা আর ফিরিয়ে আনা যাবে না, কোনো attendance-ও তৈরি হবে না।');"><i class="fa-solid fa-trash"></i> পুরনো সব ডিলিট করুন</button>
                        </form>
                    <?php endif; ?>
                    <a href="index.php?page=device_settings#liveTab" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-rotate"></i> Refresh</a>
                </div>
            </div>

            <div class="alert alert-light border small">
                <i class="fa-solid fa-circle-info text-primary"></i>
                Cloud ডিভাইসগুলো তাদের নিজস্ব API/Webhook থেকে অথবা ZKTeco ADMS push (<code>zk_receiver.php</code>) দিয়ে, এবং Local/LAN ডিভাইসগুলো Sync Agent (<code>zk_agent.py</code>) দিয়ে ডেটা পাঠালে সেটা এই টেবিলে (<code>device_attendance_logs</code>) জমা হবে।
                উপরের ডিভাইস লিস্ট থেকে <i class="fa-solid fa-plug-circle-check"></i> বাটনে ক্লিক করে যেকোনো ডিভাইসের কানেকশন স্ট্যাটাস (Online/Offline) সাথে সাথে চেক করা যাবে।
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle">
                    <thead class="table-dark">
                        <tr><th>Time</th><th>Device</th><th>Type</th><th>Person</th><th>Verify Mode</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_logs): foreach ($recent_logs as $log): ?>
                            <tr>
                                <td class="small"><?php echo dv($log['punch_time']); ?></td>
                                <td class="small"><?php echo dv($log['device_name']); ?></td>
                                <td class="small text-capitalize"><?php echo dv($log['person_type']); ?></td>
                                <td class="small">
                                    <?php if ($log['person_name']): ?>
                                        <span class="fw-bold"><?php echo dv($log['person_name']); ?></span>
                                        <span class="text-muted">(<?php echo dv($log['person_code']); ?>)</span>
                                    <?php else: ?>
                                        <span class="text-warning"><?php echo dv($log['person_uid']); ?> ⚠️ Student/Employee এর সাথে ম্যাচ নেই</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?php echo dv($log['verify_mode']); ?></td>
                                <td><?php echo $log['person_type'] ? '<span class="badge bg-success">✓ Matched</span>' : '<span class="badge bg-warning text-dark">⚠️ Unmatched</span>'; ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">এখনো কোনো Live Punch Data আসেনি</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Device Config Guide Modal (Cloud/ADMS — internet-connected ডিভাইসের জন্য) -->
<div class="modal fade" id="cfgModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="fa-solid fa-gear"></i> Device Configuration (Cloud/ADMS)</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">Device-এর মেনু থেকে নিচের সেটিংস দিন — একবার সেট করলে অটো sync হবে। শুধু ইন্টারনেট-কানেক্টেড ডিভাইসের জন্য।</p>
                <div class="bg-light border rounded p-3 small">
                    <strong class="text-danger">K40 / K60 / K90 / F18:</strong><br>
                    Menu → Comm → Cloud Server<br>
                    Server Address&nbsp;: <code><?php echo dv($_SERVER['HTTP_HOST'] ?? ''); ?></code><br>
                    Server Port&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: <code>80</code> (HTTPS হলে <code>443</code>)<br>
                    HTTPS&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: <?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'Enable' : 'Disable'; ?><br>
                    Enable Push&nbsp;&nbsp;: Yes<br>
                    URL Path&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: <code>/admin/zk_receiver.php</code>
                </div>
                <p class="small text-muted mt-3 mb-0">
                    ⚠️ Device-এ ইন্টারনেট কানেকশন লাগবে।<br>
                    ✅ Connect হলে উপরের Device লিস্টে auto যোগ হয়ে যাবে (নাম/Branch পরে বসিয়ে দিন)।<br>
                    📋 Student/Employee প্রোফাইলের <strong>Finger ID (Tracking ID)</strong> ফিল্ডে ডিভাইসের PIN বসাতে হবে — এটা দিয়েই পাঞ্চ ডেটা সঠিক ছাত্র/কর্মীর সাথে মিলবে। ("Device Serial / MAC" ফিল্ডটা আলাদা — সেটা GPS/Transport ট্র্যাকারের জন্য, fingerprint ম্যাচিংয়ের জন্য না।)
                </p>
            </div>
        </div>
    </div>
</div>

<!-- LAN Sync Agent Setup Modal (ইন্টারনেট-বিহীন ডিভাইসের জন্য) -->
<div class="modal fade" id="agentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="fa-solid fa-network-wired"></i> LAN Sync Agent সেটআপ</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">
                    যে PC-টা ডিভাইসগুলোর মতোই একই LAN-এ আছে, সেখানে <code>zk_agent.py</code> চালিয়ে রাখুন —
                    নিচের দুটো তথ্য <code>agent_config.ini</code>-এ একবার বসিয়ে দিলেই হবে। নতুন Local device
                    উপরের "Add Device" ফর্ম (Connection Type: Local/Non-Cloud) দিয়ে যোগ করলে agent restart
                    করারও দরকার নেই, পরের cycle-এই ধরে নেবে।
                </p>
                <div class="bg-light border rounded p-3 small">
                    base_url&nbsp;=&nbsp;<code><?php echo dv($agent_base_url); ?></code><br>
                    secret&nbsp;&nbsp;&nbsp;&nbsp;=&nbsp;<code><?php echo dv($agent_secret); ?></code>
                </div>
                <p class="small text-muted mt-3 mb-0">
                    ⚠️ শুধুমাত্র ইন্টারনেট-বিহীন ডিভাইসের জন্যই এই agent লাগবে — যেসব ডিভাইসে Cloud/ADMS সেট করা যায়, তাদের জন্য "Device Config Guide"-ই যথেষ্ট।<br>
                    🔒 এই secret কাউকে শেয়ার করবেন না — এটা দিয়ে আপনার device list দেখা যায়।<br>
                    ➕ Add Device ফর্মে <strong>Connection Type = Local/Non-Cloud</strong> সিলেক্ট করে IP Address, Port ও Device Serial No বসিয়ে সেভ করলেই agent সেটা ধরে নেবে।
                </p>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var localBox = document.getElementById('localFields');
    var cloudBox = document.getElementById('cloudFields');
    var radios = document.querySelectorAll('input[name="connection_type"]');

    function syncFields() {
        var val = document.querySelector('input[name="connection_type"]:checked').value;
        localBox.style.display = (val === 'local') ? 'block' : 'none';
        cloudBox.style.display = (val === 'cloud') ? 'block' : 'none';
    }
    radios.forEach(function (r) { r.addEventListener('change', syncFields); });
    syncFields();

    // URL এ #liveTab থাকলে Live Data ট্যাব খুলে দাও
    if (window.location.hash === '#liveTab') {
        var trigger = document.querySelector('#deviceTab button[data-bs-target="#liveTab"]');
        if (trigger) new bootstrap.Tab(trigger).show();
    }
})();
</script>
