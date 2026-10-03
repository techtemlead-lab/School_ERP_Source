<?php
// config/qms_helpers.php - Result Module > Question Management
// -----------------------------------------------------------------
// এই স্কুলের জন্য বাইরের Question Management System (QMS) এর domain এবং
// ওই সিস্টেম থেকে আসা webhook/API verify করার টোকেন এখানে সেভ থাকে।
// প্রতিটা স্কুল নিজের ডোমেইনে qms-webhook.php কল পাবে (100ms-webhook.php এর মতোই),
// তাই config/db.php নিজে থেকেই ঠিক স্কুলের DB-তে কানেক্ট করে দেয় — আলাদা কিছু লাগে না।

if (!function_exists('qms_ensure_tables')) {
    function qms_ensure_tables(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS qms_settings (
                id INT PRIMARY KEY DEFAULT 1,
                qms_domain_url VARCHAR(255) NULL,
                webhook_token VARCHAR(64) NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec("INSERT IGNORE INTO qms_settings (id) VALUES (1)");

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS qms_questions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                external_question_id VARCHAR(100) NULL,
                subject VARCHAR(100) NULL,
                class_name VARCHAR(100) NULL,
                question_type VARCHAR(50) NULL,
                question_text MEDIUMTEXT NULL,
                options_json TEXT NULL,
                correct_answer VARCHAR(255) NULL,
                marks DECIMAL(6,2) NULL,
                raw_payload LONGTEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Received',
                received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_external_id (external_question_id),
                KEY idx_subject (subject)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

/** settings রো রিড করে দেয় (না থাকলে টেবিল বানিয়ে খালি রো রিটার্ন করে) */
if (!function_exists('qms_get_settings')) {
    function qms_get_settings(PDO $pdo): array
    {
        qms_ensure_tables($pdo);
        $row = $pdo->query("SELECT * FROM qms_settings WHERE id = 1")->fetch();
        return $row ?: ['id' => 1, 'qms_domain_url' => null, 'webhook_token' => null];
    }
}

/** webhook token না থাকলে একবার generate করে সেভ করে দেয় */
if (!function_exists('qms_ensure_webhook_token')) {
    function qms_ensure_webhook_token(PDO $pdo): string
    {
        $settings = qms_get_settings($pdo);
        if (!empty($settings['webhook_token'])) {
            return $settings['webhook_token'];
        }
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE qms_settings SET webhook_token = ? WHERE id = 1")->execute([$token]);
        return $token;
    }
}
