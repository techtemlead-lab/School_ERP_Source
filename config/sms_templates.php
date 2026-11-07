<?php
/**
 * config/sms_templates.php
 * -----------------------------------------------------------
 * সিস্টেমের যে যে জায়গা থেকে SMS যায় — প্রতিটার জন্য আলাদা আলাদা টেমপ্লেট।
 * সব টেমপ্লেট "SMS Settings > SMS Template Settings" পেজ থেকে এডিট করা যায়।
 *
 *  - আগে থেকে থাকা ৮টা টেমপ্লেট (Admission, Fee Collection, Due, Absent ইত্যাদি) sms_settings টেবিলের
 *    content_* কলামেই থাকে (পুরনো ডাটা/ডেমো সিঙ্ক অক্ষত রাখতে) — এদের On/Off টগলও আগের মতোই sms_* কলামে।
 *  - নতুনগুলো (Result, Inventory Sale, Transport Fee, বাসের তিনটা GPS SMS) sms_templates টেবিলে থাকে
 *    — প্রতিটার নিজস্ব On/Off সহ।
 *  - টেমপ্লেট ফাঁকা থাকলে নিচের রেজিস্ট্রির ডিফল্ট টেক্সট ব্যবহার হয়, তাই কিছু না করলেও আগের মতোই SMS যায়।
 *
 * ব্যবহার:  $msg = sms_template_render($pdo, 'transport_fee', ['NAME' => ..., 'AMOUNT' => ...]);
 *          if (sms_template_enabled($pdo, 'transport_fee')) { send_sms(...); }
 */

if (!function_exists('sms_template_registry')) {

/**
 * সব SMS ইভেন্টের তালিকা।
 * key => [group, title, where (কোথা থেকে যায়), default, placeholders[name=>বর্ণনা],
 *         legacy (sms_settings-এর content কলাম বা null), toggle (sms_settings-এর On/Off কলাম বা null), wired (স্বয়ংক্রিয় পাঠানো চালু আছে কি না)]
 */
function sms_template_registry(): array
{
    $stu = ['FIRST_NAME' => 'শিক্ষার্থীর নামের প্রথম অংশ', 'LAST_NAME' => 'শিক্ষার্থীর নামের বাকি অংশ', 'SCHOOL_NAME' => 'প্রতিষ্ঠানের নাম'];
    return [
        // ---------------- ভর্তি ----------------
        'admission' => [
            'group' => 'ভর্তি', 'title' => 'Student Admission SMS',
            'where' => 'Online Admission ফর্ম জমা ও Admission Fee পেমেন্ট সফল হলে',
            'default' => 'Dear guardian, {FIRST_NAME} {LAST_NAME} has been admitted to {SCHOOL_NAME} with Student ID {STUDENT_ID}. Roll: {ROLL}, Class: {CLASS}.',
            'placeholders' => $stu + ['STUDENT_ID' => 'Student ID', 'ROLL' => 'রোল নম্বর', 'CLASS' => 'ক্লাসের নাম'],
            'legacy' => 'content_admission', 'toggle' => 'sms_admission', 'wired' => true,
        ],
        'congratulation' => [
            'group' => 'ভর্তি', 'title' => 'Students Congratulation SMS',
            'where' => 'অভিনন্দন SMS (স্বয়ংক্রিয় পাঠানো এখনো চালু নেই — টেমপ্লেট রাখা আছে)',
            'default' => 'Congratulations {FIRST_NAME} {LAST_NAME}! — {SCHOOL_NAME}',
            'placeholders' => $stu,
            'legacy' => 'content_congratulation', 'toggle' => 'sms_congratulation', 'wired' => false,
        ],
        'teacher_add' => [
            'group' => 'কর্মচারী / শিক্ষক', 'title' => 'Teacher / Employee Add SMS',
            'where' => 'নতুন শিক্ষক/কর্মচারী যোগ করলে (স্বয়ংক্রিয় পাঠানো এখনো চালু নেই — টেমপ্লেট রাখা আছে)',
            'default' => 'Welcome to {SCHOOL_NAME}.',
            'placeholders' => ['SCHOOL_NAME' => 'প্রতিষ্ঠানের নাম'],
            'legacy' => 'content_teacher_add', 'toggle' => 'sms_add_employee', 'wired' => false,
        ],
        // ---------------- হাজিরা ----------------
        'punch_in' => [
            'group' => 'হাজিরা', 'title' => 'Students Punch In SMS',
            'where' => 'ডিভাইসে Punch In (স্বয়ংক্রিয় পাঠানো এখনো চালু নেই — টেমপ্লেট রাখা আছে)',
            'default' => '{FIRST_NAME} {LAST_NAME} has arrived at {SCHOOL_NAME}.',
            'placeholders' => $stu,
            'legacy' => 'content_punch_in', 'toggle' => 'sms_punch_in', 'wired' => false,
        ],
        'punch_out' => [
            'group' => 'হাজিরা', 'title' => 'Students Punch Out SMS',
            'where' => 'ডিভাইসে Punch Out (স্বয়ংক্রিয় পাঠানো এখনো চালু নেই — টেমপ্লেট রাখা আছে)',
            'default' => '{FIRST_NAME} {LAST_NAME} has left {SCHOOL_NAME}.',
            'placeholders' => $stu,
            'legacy' => 'content_punch_out', 'toggle' => 'sms_punch_out', 'wired' => false,
        ],
        'absent' => [
            'group' => 'হাজিরা', 'title' => 'Students Absent SMS',
            'where' => 'Attendance > Absent List থেকে "Send SMS" চাপলে',
            'default' => 'Dear guardian, {FIRST_NAME} {LAST_NAME} is absent on {SCHOOL_NAME} today.',
            'placeholders' => $stu + ['DATE' => 'আজকের তারিখ (dd/mm/yyyy)'],
            'legacy' => 'content_absent', 'toggle' => 'sms_absent', 'wired' => true,
        ],
        // ---------------- ফি / হিসাব ----------------
        'fee_collection' => [
            'group' => 'ফি ও হিসাব', 'title' => 'Students Fee Collection SMS',
            'where' => 'Fees Collection / Quick Collection-এ টাকা নেওয়ার পর',
            'default' => 'Dear Parent, Tk {AMOUNT} collected for {FIRST_NAME} {LAST_NAME} ({FEES_TYPE}). Receipt: {RECEIPT_LINK}',
            'placeholders' => $stu + ['AMOUNT' => 'জমার পরিমাণ', 'FEES_TYPE' => 'ফি-এর নাম', 'FOLIO_NO' => 'Folio নম্বর', 'RECEIPT_LINK' => 'Money Receipt লিংক (না বসালেও শেষে অটো যোগ হয়)'],
            'legacy' => 'content_fee_collection', 'toggle' => 'sms_fee_collection', 'wired' => true,
        ],
        'due' => [
            'group' => 'ফি ও হিসাব', 'title' => 'Due SMS',
            'where' => 'Finance > Students Dues থেকে "Send SMS" চাপলে',
            'default' => 'Dear guardian, {FIRST_NAME} {LAST_NAME} (Class: {CLASS}, Roll: {ROLL}) has a due amount of {DUE_AMOUNT} at {SCHOOL_NAME}. Please clear the dues soon.',
            'placeholders' => $stu + ['NAME' => 'পুরো নাম', 'CLASS' => 'ক্লাস', 'ROLL' => 'রোল', 'DUE_AMOUNT' => 'আজ পর্যন্ত বকেয়া', 'AMOUNT' => 'বকেয়া (till মাস/বছর সহ)', 'TILL_MONTH' => 'চলতি মাস/বছর'],
            'legacy' => 'content_due', 'toggle' => 'sms_due', 'wired' => true,
        ],
        'transport_fee' => [
            'group' => 'ফি ও হিসাব', 'title' => 'Transport Fee Received SMS',
            'where' => 'Transport Fees > Collect-এ পেমেন্ট নেওয়ার পর',
            'default' => 'প্রিয় অভিভাবক, {NAME} এর ট্রান্সপোর্ট ফি ৳{AMOUNT} গৃহীত হয়েছে। ধন্যবাদ - {SCHOOL_NAME}',
            'placeholders' => ['NAME' => 'শিক্ষার্থীর নাম', 'AMOUNT' => 'গৃহীত টাকা', 'SCHOOL_NAME' => 'প্রতিষ্ঠানের নাম'],
            'legacy' => null, 'toggle' => null, 'wired' => true,
        ],
        'inventory_sale' => [
            'group' => 'ফি ও হিসাব', 'title' => 'Inventory (Store) Sale SMS',
            'where' => 'Inventory > Sales-এ শিক্ষার্থীর নামে বিক্রি হলে (Inventory Settings-এ "SMS on Sale" চালু থাকলে)',
            'default' => 'প্রিয় অভিভাবক, {CUSTOMER_NAME} এর জন্য স্টোর থেকে পণ্য ক্রয় করা হয়েছে। মোট: ৳{TOTAL}, জমা: ৳{PAID}, বাকি: ৳{DUE}। ইনভয়েস: {INVOICE_NO}',
            'placeholders' => ['CUSTOMER_NAME' => 'শিক্ষার্থীর নাম', 'TOTAL' => 'মোট', 'PAID' => 'জমা', 'DUE' => 'বাকি', 'INVOICE_NO' => 'ইনভয়েস নম্বর', 'SCHOOL_NAME' => 'প্রতিষ্ঠানের নাম'],
            'legacy' => null, 'toggle' => null, 'wired' => true,
        ],
        // ---------------- রেজাল্ট ----------------
        'result' => [
            'group' => 'রেজাল্ট', 'title' => 'Exam Result SMS',
            'where' => 'Result > Students Merit List থেকে "Send SMS" চাপলে',
            'default' => "Dear guardian, {NAME}'s exam result from {SCHOOL_NAME}: {RESULT_SUMMARY}",
            'placeholders' => ['NAME' => 'শিক্ষার্থীর নাম', 'SCHOOL_NAME' => 'প্রতিষ্ঠানের নাম', 'RESULT_SUMMARY' => 'Total, GPA, Grade, Merit একসাথে (যেমন: Total: 450, GPA: 5, Grade: A+, Merit: 3)', 'TOTAL' => 'মোট নম্বর', 'GPA' => 'GPA', 'GRADE' => 'Grade', 'MERIT' => 'মেধা ক্রম'],
            'legacy' => null, 'toggle' => null, 'wired' => true,
        ],
        // ---------------- লগইন ও পাসওয়ার্ড ----------------
        'student_login_info' => [
            'group' => 'লগইন ও পাসওয়ার্ড', 'title' => 'Student Login Info SMS (User ID + Password)',
            'where' => 'SMS > Students (User/Password) পেজ থেকে "Send SMS" চাপলে — অভিভাবকের নম্বরে',
            'default' => '{SCHOOL_NAME}: প্রিয় অভিভাবক, {NAME} (ID: {STUDENT_ID})-এর লগইন তথ্য — Student ID: {STUDENT_ID}, Password: {PASSWORD}। প্রথমবার লগইন করে পাসওয়ার্ড বদলে নিন।',
            'placeholders' => ['NAME' => 'শিক্ষার্থীর নাম', 'STUDENT_ID' => 'Student ID (ইউজার আইডি)', 'PASSWORD' => 'পাসওয়ার্ড', 'SCHOOL_NAME' => 'প্রতিষ্ঠানের নাম', 'LOGIN_URL' => 'Student পোর্টাল লগইন লিংক'],
            'legacy' => null, 'toggle' => null, 'wired' => true,
        ],
        'student_password_reset' => [
            'group' => 'লগইন ও পাসওয়ার্ড', 'title' => 'Student Password Reset SMS',
            'where' => 'SMS > Students পেজে "Change Password" (রিসেট) করলে — অভিভাবকের নম্বরে নতুন পাসওয়ার্ড',
            'default' => '{SCHOOL_NAME}: প্রিয় অভিভাবক, {NAME} (ID: {STUDENT_ID})-এর পাসওয়ার্ড রিসেট করা হয়েছে। নতুন Password: {PASSWORD}। লগইন করে পাসওয়ার্ড বদলে নিন।',
            'placeholders' => ['NAME' => 'শিক্ষার্থীর নাম', 'STUDENT_ID' => 'Student ID (ইউজার আইডি)', 'PASSWORD' => 'নতুন পাসওয়ার্ড', 'SCHOOL_NAME' => 'প্রতিষ্ঠানের নাম', 'LOGIN_URL' => 'Student পোর্টাল লগইন লিংক'],
            'legacy' => null, 'toggle' => null, 'wired' => true, 'default_enabled' => false,
        ],
        'employee_login_info' => [
            'group' => 'লগইন ও পাসওয়ার্ড', 'title' => 'Employee / Teacher Login Info SMS (User ID + Password)',
            'where' => 'SMS > Employees (User/Password) পেজ থেকে "Send SMS" চাপলে — কর্মচারীর নিজের নম্বরে',
            'default' => '{SCHOOL_NAME}: প্রিয় {NAME}, আপনার লগইন তথ্য — Employee ID: {EMPLOYEE_ID}, Password: {PASSWORD}। প্রথমবার লগইন করে পাসওয়ার্ড বদলে নিন।',
            'placeholders' => ['NAME' => 'কর্মচারীর নাম', 'EMPLOYEE_ID' => 'Employee ID (ইউজার আইডি)', 'PASSWORD' => 'পাসওয়ার্ড', 'SCHOOL_NAME' => 'প্রতিষ্ঠানের নাম', 'LOGIN_URL' => 'Teacher পোর্টাল লগইন লিংক'],
            'legacy' => null, 'toggle' => null, 'wired' => true,
        ],
        'employee_password_reset' => [
            'group' => 'লগইন ও পাসওয়ার্ড', 'title' => 'Employee / Teacher Password Reset SMS',
            'where' => 'SMS > Employees পেজে "Change Password" (রিসেট) করলে — কর্মচারীর নিজের নম্বরে নতুন পাসওয়ার্ড',
            'default' => '{SCHOOL_NAME}: প্রিয় {NAME}, আপনার পাসওয়ার্ড রিসেট করা হয়েছে। Employee ID: {EMPLOYEE_ID}, নতুন Password: {PASSWORD}। লগইন করে পাসওয়ার্ড বদলে নিন।',
            'placeholders' => ['NAME' => 'কর্মচারীর নাম', 'EMPLOYEE_ID' => 'Employee ID (ইউজার আইডি)', 'PASSWORD' => 'নতুন পাসওয়ার্ড', 'SCHOOL_NAME' => 'প্রতিষ্ঠানের নাম', 'LOGIN_URL' => 'Teacher পোর্টাল লগইন লিংক'],
            'legacy' => null, 'toggle' => null, 'wired' => true, 'default_enabled' => false,
        ],
        // ---------------- ট্রান্সপোর্ট (GPS) ----------------
        'transport_arrive_school' => [
            'group' => 'ট্রান্সপোর্ট (GPS)', 'title' => 'Bus Arrived School SMS',
            'where' => 'বাস স্কুলে পৌঁছালে (GPS cron থেকে স্বয়ংক্রিয়, রুটের সব অভিভাবককে)',
            'default' => '{NAME} এর স্কুল বাস ({BUS}) স্কুলে পৌঁছেছে।',
            'placeholders' => ['NAME' => 'শিক্ষার্থীর নাম', 'BUS' => 'বাসের নাম/নম্বর', 'SCHOOL_NAME' => 'প্রতিষ্ঠানের নাম'],
            'legacy' => null, 'toggle' => null, 'wired' => true,
        ],
        'transport_depart_school' => [
            'group' => 'ট্রান্সপোর্ট (GPS)', 'title' => 'Bus Departed School SMS',
            'where' => 'বাস স্কুল ছেড়ে রওনা দিলে (GPS cron থেকে স্বয়ংক্রিয়)',
            'default' => '{NAME} এর স্কুল বাস ({BUS}) স্কুল ছেড়ে রওনা দিয়েছে।',
            'placeholders' => ['NAME' => 'শিক্ষার্থীর নাম', 'BUS' => 'বাসের নাম/নম্বর', 'SCHOOL_NAME' => 'প্রতিষ্ঠানের নাম'],
            'legacy' => null, 'toggle' => null, 'wired' => true,
        ],
        'transport_stop_reached' => [
            'group' => 'ট্রান্সপোর্ট (GPS)', 'title' => 'Bus Reached Stop SMS',
            'where' => 'বাস কোনো স্টপেজে পৌঁছালে (GPS cron থেকে, শুধু ওই স্টপেজের অভিভাবককে)',
            'default' => '{NAME} এর স্কুল বাস ({BUS}) আপনার স্টপেজ ({STOP}) এ পৌঁছেছে।',
            'placeholders' => ['NAME' => 'শিক্ষার্থীর নাম', 'BUS' => 'বাসের নাম/নম্বর', 'STOP' => 'স্টপেজের নাম', 'SCHOOL_NAME' => 'প্রতিষ্ঠানের নাম'],
            'legacy' => null, 'toggle' => null, 'wired' => true,
        ],
    ];
}

/** sms_templates টেবিল না থাকলে বানানো (নতুন ইভেন্টগুলোর টেমপ্লেট + On/Off এখানে থাকে) */
function sms_templates_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sms_templates (
                event_key VARCHAR(60) NOT NULL PRIMARY KEY,
                content TEXT NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('sms_templates_ensure: ' . $e->getMessage());
    }
}

/** সবগুলোর বর্তমান ভ্যালু একবারে: key => ['content' => string, 'enabled' => bool, 'is_default' => bool] */
function sms_templates_load_all(PDO $pdo): array
{
    sms_templates_ensure($pdo);
    $reg = sms_template_registry();

    $legacy_row = [];
    try { $legacy_row = $pdo->query("SELECT * FROM sms_settings WHERE id = 1")->fetch() ?: []; } catch (Throwable $e) {}
    $new_rows = [];
    try {
        foreach ($pdo->query("SELECT event_key, content, is_enabled FROM sms_templates")->fetchAll() as $r) { $new_rows[$r['event_key']] = $r; }
    } catch (Throwable $e) {}

    $out = [];
    foreach ($reg as $key => $def) {
        if ($def['legacy'] !== null) {
            $content = trim((string) ($legacy_row[$def['legacy']] ?? ''));
            $enabled = $def['toggle'] !== null ? ((int) ($legacy_row[$def['toggle']] ?? 0) === 1) : true;
        } else {
            $content = trim((string) ($new_rows[$key]['content'] ?? ''));
            // সেভ না থাকলে আগের আচরণই: আগে থেকে যাওয়া SMS চালু, নতুন যোগ হওয়া (default_enabled=false) বন্ধ
            $enabled = isset($new_rows[$key]) ? ((int) $new_rows[$key]['is_enabled'] === 1) : (bool) ($def['default_enabled'] ?? true);
        }
        $out[$key] = [
            'content' => $content !== '' ? $content : $def['default'],
            'enabled' => $enabled,
            'is_default' => $content === '',
        ];
    }
    return $out;
}

/** একটা ইভেন্টের টেমপ্লেট টেক্সট (সেভ করা না থাকলে ডিফল্ট) */
function sms_template_get(PDO $pdo, string $key): string
{
    $reg = sms_template_registry();
    if (!isset($reg[$key])) { return ''; }
    $all = sms_templates_load_all($pdo);
    return $all[$key]['content'];
}

/** এই ইভেন্টের SMS চালু আছে কি না (legacy হলে sms_* টগল, নতুনগুলোর নিজস্ব সুইচ) */
function sms_template_enabled(PDO $pdo, string $key): bool
{
    $reg = sms_template_registry();
    if (!isset($reg[$key])) { return false; }
    $all = sms_templates_load_all($pdo);
    return $all[$key]['enabled'];
}

/** পোর্টালের লগইন লিংক (SMS-এর {LOGIN_URL} এর জন্য)। CLI/হোস্ট না থাকলে ফাঁকা। $portal = 'student' | 'teacher' */
function sms_portal_login_url(string $portal): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') { return ''; }
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host . '/' . ($portal === 'teacher' ? 'teacher' : 'student') . '/login.php';
}

/** টেমপ্লেট নিয়ে {PLACEHOLDER} গুলো $data দিয়ে বদলে মেসেজ বানানো */
function sms_template_render(PDO $pdo, string $key, array $data): string
{
    return sms_apply_template(sms_template_get($pdo, $key), array_map(fn($v) => (string) $v, $data));
}

/**
 * নতুন (sms_templates-এ থাকা) ইভেন্টের টেমপ্লেট সেভ। legacy ইভেন্ট sms_settings.php-এর নিজস্ব সেভ হ্যান্ডলার দিয়ে সেভ হয়।
 * $content ফাঁকা হলে ডিফল্ট ব্যবহার হবে।
 */
function sms_template_save_new(PDO $pdo, string $key, string $content, bool $enabled): void
{
    sms_templates_ensure($pdo);
    $st = $pdo->prepare(
        "INSERT INTO sms_templates (event_key, content, is_enabled) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE content = VALUES(content), is_enabled = VALUES(is_enabled)"
    );
    $st->execute([$key, trim($content), $enabled ? 1 : 0]);
}

}
