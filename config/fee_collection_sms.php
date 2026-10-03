<?php
/**
 * config/fee_collection_sms.php
 * -----------------------------------------------------------
 * Fee Collect হওয়ার পর অভিভাবকের নাম্বারে SMS পাঠানোর হেল্পার — SMS-এ একটা Money
 * Receipt লিংক থাকে (money_receipt.php?token=...), যেটাতে ক্লিক করলে লগইন ছাড়াই
 * শুধু ঐ Student-এর Money Receipt (Student Copy) দেখা যায়।
 *
 * টোকেন ফলিও নম্বরের বদলে আলাদা random token — folio_no প্রিন্ট করা কাগজে/স্ক্রিনে
 * থাকে বলেই সেটা দিয়ে সরাসরি পাবলিক লিংক বানানো নিরাপদ না (আন্দাজ করা কঠিন হলেও
 * অপ্রয়োজনীয় ঝুঁকি) — admission_slip.php যেভাবে admission_slip_token ব্যবহার করে,
 * এখানেও ঠিক সেই একই প্যাটার্ন।
 *
 * SMS Settings > Content ট্যাবের "Students Fee Collection SMS" টগল আর
 * "Students Fee Collection SMS Content" টেমপ্লেট এটাই ব্যবহার করে — আগে এই টগল/
 * টেমপ্লেট UI-তে থাকলেও কোথাও থেকে আসলে SMS পাঠানো হতো না, এখন Student Fees
 * Collection আর Quick Collection — দুই জায়গা থেকেই কালেকশনের পর এই ফাংশন কল হয়।
 */

/** finance_fee_collections টেবিলে receipt_token কলাম না থাকলে (পুরনো ইনস্টলেশন) নিরাপদে যোগ করা */
if (!function_exists('fc_ensure_receipt_token_column')) {
    function fc_ensure_receipt_token_column(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM finance_fee_collections")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('receipt_token', $cols, true)) {
                $pdo->exec("ALTER TABLE finance_fee_collections ADD COLUMN receipt_token VARCHAR(40) NULL AFTER folio_no");
                $pdo->exec("ALTER TABLE finance_fee_collections ADD INDEX idx_receipt_token (receipt_token)");
            }
        } catch (Throwable $e) {
            error_log('fc_ensure_receipt_token_column: ' . $e->getMessage());
        }
    }
}

/**
 * একটা ফলিওর জন্য নতুন, আন্দাজ করা যায় না এমন Receipt Token — SMS-এ ক্যারেক্টার
 * (তাই কস্ট) বাঁচাতে ছোট রাখা হয়েছে (9 বাইট = 18 hex ক্যারেক্টার = 72-বিট র‍্যান্ডম,
 * এখনো ব্রুট-ফোর্স করে আন্দাজ করার মতো না — admission_slip_token এর 20-বাইটের চেয়ে
 * ছোট রাখা হলো কারণ এটা প্রতিটা SMS-এর সাথে যায়, ওটা যায় না)।
 */
if (!function_exists('fc_new_receipt_token')) {
    function fc_new_receipt_token(): string
    {
        return bin2hex(random_bytes(9));
    }
}

/**
 * Fee Collection সফল হওয়ার পর কল করতে হবে — sms_settings.sms_fee_collection টগল অন
 * থাকলে, ছাত্রের guardian_phone-এ Money Receipt লিংকসহ SMS পাঠায়। টগল অফ থাকলে,
 * নাম্বার না থাকলে, বা গেটওয়ে কনফিগার করা না থাকলে চুপচাপ কিছুই করে না — Fee
 * Collection ব্যর্থ হবে না SMS ব্যর্থ হলেও (fire-and-forget)।
 */
if (!function_exists('fc_send_receipt_sms')) {
    function fc_send_receipt_sms(PDO $pdo, int $student_id, string $folio_no, string $receipt_token, float $amount, array $fee_head_names = []): void
    {
        try {
            require_once __DIR__ . '/sms.php';

            $s = $pdo->query("SELECT sms_fee_collection, content_fee_collection FROM sms_settings WHERE id = 1")->fetch();
            if (!$s || (int) ($s['sms_fee_collection'] ?? 0) !== 1) { return; }

            $stu_stmt = $pdo->prepare("SELECT name_en, guardian_phone FROM students WHERE id = ?");
            $stu_stmt->execute([$student_id]);
            $stu = $stu_stmt->fetch();
            if (!$stu || empty($stu['guardian_phone'])) { return; }

            $school = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetch()['institute_name'] ?? '';

            $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
            // SMS-এ ক্যারেক্টার (তাই কস্ট) বাঁচাতে লম্বা 'money_receipt.php?token=' এর বদলে
            // ছোট alias 'mr.php?t=' ব্যবহার করা হয় (mr.php শুধু money_receipt.php-কেই কল করে,
            // ?t= প্যারামিটারটা ?token= হিসেবে পাস করে দেয়) — admin/ থেকে কল হলেও root-এ থাকা
            // পাবলিক পেজ বলে '/../' লাগে না
            $link = $base_url . '/mr.php?t=' . rawurlencode($receipt_token);

            // $fee_head_names -এ কল করা পেজ থেকেই পরিষ্কার Fee Head নাম (Account Code ছাড়া)
            // পাঠানো হয় — দেখুন finance_fees_collection.php / finance_quick_collection.php
            $fee_type_label = $fee_head_names ? implode(', ', array_unique($fee_head_names)) : 'Fees';

            $template = trim((string) ($s['content_fee_collection'] ?? ''));
            if ($template === '') {
                $template = 'Dear Parent, Tk {AMOUNT} collected for {FIRST_NAME} {LAST_NAME} ({FEES_TYPE}). Receipt: {RECEIPT_LINK}';
            }
            $has_link_placeholder = strpos($template, '{RECEIPT_LINK}') !== false;

            $name_parts = explode(' ', trim((string) $stu['name_en']), 2);
            // পয়সা 0 হলে .00 বাদ দেওয়া হয় (যেমন 200.00 এর বদলে 200) — SMS-এ কয়েক ক্যারেক্টার বাঁচে
            $amount_label = (fmod($amount, 1.0) === 0.0) ? number_format($amount, 0) : number_format($amount, 2);
            $message = sms_apply_template($template, [
                'FIRST_NAME'  => $name_parts[0] ?? '',
                'LAST_NAME'   => $name_parts[1] ?? '',
                'SCHOOL_NAME' => $school,
                'AMOUNT'      => $amount_label,
                'FEES_TYPE'   => $fee_type_label,
                'FOLIO_NO'    => $folio_no,
                'RECEIPT_LINK'=> $link,
            ]);
            // পুরনো ইনস্টলেশনে টেমপ্লেটে {RECEIPT_LINK} বসানো না থাকলেও (আগে থেকে সেভ করা টেমপ্লেট)
            // লিংকটা যেন বাদ না পড়ে যায় — শেষে যোগ করে দেওয়া হয়
            if (!$has_link_placeholder) {
                $message .= ' Receipt: ' . $link;
            }

            $result = send_sms($pdo, $stu['guardian_phone'], $message);

            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS sms_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NULL, phone VARCHAR(20) NOT NULL,
                    purpose VARCHAR(50) NOT NULL DEFAULT 'fee_collection', message TEXT NULL,
                    status ENUM('Sent','Failed') NOT NULL DEFAULT 'Failed', response TEXT NULL,
                    sent_by VARCHAR(100) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $pdo->prepare(
                "INSERT INTO sms_logs (student_id, phone, purpose, message, status, response, sent_by) VALUES (?, ?, 'fee_collection', ?, ?, ?, ?)"
            )->execute([
                $student_id, $stu['guardian_phone'], $message,
                $result['success'] ? 'Sent' : 'Failed', $result['response'], $_SESSION['admin_user'] ?? 'System',
            ]);
        } catch (Throwable $e) {
            // SMS পাঠাতে সমস্যা হলেও Fee Collection সেভ থাকবে — শুধু লগ করা হচ্ছে
            error_log('fc_send_receipt_sms: ' . $e->getMessage());
        }
    }
}
