<?php
/**
 * cron/process_recorded_uploads.php
 * -----------------------------------------------------------
 * cPanel Cron Job এ প্রতি ৫-১০ মিনিটে একবার এই কমান্ড চালানো লাগবে:
 *   /usr/bin/php /home/USER/public_html/cron/process_recorded_uploads.php
 *
 * এটা CLI থেকে চলে বলে কোনো একটা নির্দিষ্ট স্কুলের ডোমেইন/রিকোয়েস্ট নেই —
 * তাই master DB থেকে সবগুলো Active স্কুলের লিস্ট নিয়ে, একে একে প্রতিটার নিজের DB-তে
 * ঢুকে সেই স্কুলের pending recording job থাকলে প্রসেস করে।
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('এই স্ক্রিপ্টটা শুধু cron/CLI থেকে চালানোর জন্য।');
}

require_once __DIR__ . '/../config/tenant_direct.php'; // $pdo_master
require_once __DIR__ . '/../config/live_class_100ms.php';
require_once __DIR__ . '/../config/youtube_upload.php';

$tenants = get_all_active_tenants($pdo_master);
echo "[" . date('Y-m-d H:i:s') . "] মোট " . count($tenants) . " টা স্কুল চেক করা হচ্ছে...\n";

foreach ($tenants as $tenant) {
    $school_code = $tenant['school_code'];
    try {
        $pdo = connect_tenant_db($tenant);
    } catch (Throwable $e) {
        echo "  [{$school_code}] DB কানেক্ট ব্যর্থ, স্কিপ করা হলো: " . $e->getMessage() . "\n";
        continue;
    }

    // এই স্কুলে elearning মডিউল আদৌ সেটআপ করা আছে কিনা (টেবিল না থাকলে চুপচাপ স্কিপ)
    $has_jobs_table = $pdo->query("SHOW TABLES LIKE 'elearning_recording_jobs'")->fetch();
    if (!$has_jobs_table) { continue; }

    $settings = lc_get_settings($pdo);
    yt_ensure_settings_columns($pdo);
    $settings = lc_get_settings($pdo); // কলাম যোগ হওয়ার পর আবার লোড

    if (!yt_is_connected($settings)) {
        continue; // এই স্কুল YouTube কানেক্ট করেনি, প্রসেস করার কিছু নেই
    }

    $jobs = $pdo->query(
        "SELECT * FROM elearning_recording_jobs WHERE status IN ('pending','failed') AND attempts < 3 ORDER BY id ASC LIMIT 5"
    )->fetchAll();

    foreach ($jobs as $job) {
        echo "  [{$school_code}] Job #{$job['id']} প্রসেস হচ্ছে...\n";
        $pdo->prepare("UPDATE elearning_recording_jobs SET status='downloading', attempts = attempts + 1 WHERE id = ?")->execute([$job['id']]);

        $tmp_file = sys_get_temp_dir() . '/rec_' . $job['id'] . '_' . bin2hex(random_bytes(4)) . '.mp4';
        $ok_download = false;
        $fh = fopen($tmp_file, 'w');
        if ($fh) {
            $ch = curl_init($job['recording_url']);
            curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 0, CURLOPT_FOLLOWLOCATION => true]);
            $ok_download = curl_exec($ch) !== false;
            $curl_err = curl_error($ch);
            curl_close($ch);
            fclose($fh);
        }

        if (!$ok_download || !is_file($tmp_file) || filesize($tmp_file) < 1024) {
            $pdo->prepare("UPDATE elearning_recording_jobs SET status='failed', error=? WHERE id=?")
                ->execute(['ডাউনলোড ব্যর্থ: ' . ($curl_err ?? 'unknown'), $job['id']]);
            echo "    ডাউনলোড ব্যর্থ।\n";
            @unlink($tmp_file);
            continue;
        }

        // ক্লাসের title/subject/class/section/teacher তথ্য বের করা (থাকলে)
        $title = 'Recorded Class';
        $class_id = null; $section_id = null; $subject_id = null; $teacher_id = null;
        if ($job['live_class_id']) {
            $stmt = $pdo->prepare("SELECT title, class_id, section_id, subject_id, teacher_id FROM elearning_live_classes WHERE id = ?");
            $stmt->execute([$job['live_class_id']]);
            $lc = $stmt->fetch();
            if ($lc) {
                $title = $lc['title'];
                $class_id = $lc['class_id']; $section_id = $lc['section_id'];
                $subject_id = $lc['subject_id']; $teacher_id = $lc['teacher_id'];
            }
        }

        $pdo->prepare("UPDATE elearning_recording_jobs SET status='uploading' WHERE id=?")->execute([$job['id']]);
        $upload = yt_upload_video($settings, $tmp_file, $title, 'Auto-uploaded recording — ' . date('d M Y', time()));
        @unlink($tmp_file);

        if (!$upload['ok']) {
            $pdo->prepare("UPDATE elearning_recording_jobs SET status='failed', error=? WHERE id=?")->execute([$upload['error'], $job['id']]);
            echo "    আপলোড ব্যর্থ: {$upload['error']}\n";
            continue;
        }

        $pdo->prepare("UPDATE elearning_recording_jobs SET status='completed', youtube_video_id=? WHERE id=?")
            ->execute([$upload['video_id'], $job['id']]);

        // class_id/subject_id না থাকলে (ম্যানুয়াল রেকর্ডিং/room মেলেনি) Recorded Class এ যোগ করা সম্ভব না,
        // সেক্ষেত্রে শুধু YouTube-এ আপলোড হয়ে থাকবে, অ্যাডমিনকে ম্যানুয়ালি "Recorded Class" এ যোগ করতে হবে
        if ($class_id && $subject_id) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS elearning_recorded_classes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    class_id INT NOT NULL, section_id INT NULL, subject_id INT NOT NULL, teacher_id INT NULL,
                    title VARCHAR(200) NOT NULL, description TEXT NULL,
                    video_type ENUM('youtube','vimeo') NOT NULL DEFAULT 'youtube', video_url VARCHAR(500) NOT NULL,
                    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $pdo->prepare(
                "INSERT INTO elearning_recorded_classes (class_id, section_id, subject_id, teacher_id, title, description, video_type, video_url)
                 VALUES (?, ?, ?, ?, ?, ?, 'youtube', ?)"
            )->execute([
                $class_id, $section_id, $subject_id, $teacher_id, $title,
                'Live Class থেকে অটোমেটিক আপলোড হয়েছে।',
                'https://www.youtube.com/watch?v=' . $upload['video_id'],
            ]);
            echo "    ✅ আপলোড সম্পন্ন, Recorded Class এ যোগ হয়েছে (video_id={$upload['video_id']}).\n";
        } else {
            echo "    ✅ আপলোড সম্পন্ন (video_id={$upload['video_id']}), কিন্তু ক্লাসের তথ্য না মেলায় Recorded Class এ অটো যোগ হয়নি।\n";
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] সম্পন্ন।\n";
