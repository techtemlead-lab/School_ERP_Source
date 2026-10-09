<?php
/**
 * config/scholarship_application_helpers.php
 * -----------------------------------------------------------
 * শিক্ষাবৃত্তি আবেদন (Scholarship Application) — Student Portal থেকে অভিভাবক আবেদন করেন,
 * Admin যাচাই করে Approve/Reject করেন। Approve করলে বিদ্যমান Scholarship মডিউলের
 * scholarship_awards-এ একটা Pending Award তৈরি হয় (config/scholarship_helpers.php), তারপর
 * "Process Disbursement" পেজে টাকা বকেয়া ফি-র সাথে সমন্বয় / হাতে নগদ হিসেবে বণ্টন হয়।
 *
 * ব্যবহার করে: student/scholarship_apply.php, admin/scholarship_applications.php,
 *               scholarship_application_print.php
 */

require_once __DIR__ . '/scholarship_helpers.php';

if (!function_exists('sa_e')) {
    function sa_e($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
}

function sa_ensure_tables(PDO $pdo): void
{
    sch_ensure_tables($pdo); // scholarship_awards ইত্যাদি
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS scholarship_applications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            session_id INT NULL,
            class_id INT NULL,
            applicant_name VARCHAR(150) NOT NULL,
            applicant_relation ENUM('Father','Mother','Guardian') NOT NULL DEFAULT 'Father',
            child_order TINYINT NOT NULL DEFAULT 1,
            occupation VARCHAR(150) NULL,
            monthly_income DECIMAL(12,2) NOT NULL DEFAULT 0,
            family_members INT NOT NULL DEFAULT 0,
            earning_members INT NOT NULL DEFAULT 0,
            children_json TEXT NULL,
            present_address TEXT NULL,
            permanent_address TEXT NULL,
            mobile VARCHAR(20) NULL,
            whatsapp VARCHAR(20) NULL,
            email VARCHAR(150) NULL,
            reason TEXT NULL,
            status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
            admin_remarks TEXT NULL,
            scholarship_type_id INT NULL,
            approved_amount DECIMAL(10,2) NULL,
            settle_mode ENUM('Fee','Cash') NULL,
            award_id INT NULL,
            reviewed_by VARCHAR(100) NULL,
            reviewed_at DATETIME NULL,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_student (student_id), KEY idx_status (status), KEY idx_award (award_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** আবেদন নং — যেমন SA-2026-00012 (আলাদা কলাম লাগে না, id + বছর থেকে তৈরি) */
function sa_app_no(array $app): string
{
    $year = !empty($app['applied_at']) ? date('Y', strtotime($app['applied_at'])) : date('Y');
    return 'SA-' . $year . '-' . str_pad((string) (int) $app['id'], 5, '0', STR_PAD_LEFT);
}

/**
 * Application + (থাকলে) Award/Disbursement অবস্থা মিলিয়ে একটা ডিসপ্লে স্ট্যাটাস।
 * $app-তে award_status (scholarship_awards.status) দরকার, থাকলে disbursement তথ্যও দেখায়।
 * @return array{label:string, badge:string, key:string}
 */
function sa_status_meta(array $app): array
{
    if ($app['status'] === 'Pending')  { return ['key' => 'pending',   'label' => 'যাচাইয়ের অপেক্ষায়', 'badge' => 'bg-warning text-dark']; }
    if ($app['status'] === 'Rejected') { return ['key' => 'rejected',  'label' => 'বাতিল',              'badge' => 'bg-danger']; }
    // Approved
    $as = $app['award_status'] ?? null;
    if ($as === 'Disbursed') { return ['key' => 'disbursed', 'label' => 'বৃত্তি প্রদান সম্পন্ন', 'badge' => 'bg-success']; }
    if ($as === 'Cancelled') { return ['key' => 'cancelled', 'label' => 'অনুমোদন পরে বাতিল',    'badge' => 'bg-secondary']; }
    return ['key' => 'approved', 'label' => 'অনুমোদিত — প্রদানের অপেক্ষায়', 'badge' => 'bg-info text-dark'];
}

function sa_mode_label(?string $mode): string
{
    return $mode === 'Cash' ? 'হাতে নগদ' : ($mode === 'Fee' ? 'ফি-র বকেয়ার সাথে সমন্বয়' : '—');
}

function sa_relation_label(string $rel): string
{
    return ['Father' => 'বাবা', 'Mother' => 'মা', 'Guardian' => 'অভিভাবক'][$rel] ?? $rel;
}

/** সন্তানদের টেবিল (JSON) সুরক্ষিতভাবে ডিকোড */
function sa_children(?string $json): array
{
    $rows = json_decode((string) $json, true);
    return is_array($rows) ? $rows : [];
}

/** ফর্ম থেকে আসা সন্তানদের সারি পরিষ্কার করা — সর্বোচ্চ ৬টা, পুরো খালি সারি বাদ */
function sa_clean_children(array $post): array
{
    $out = [];
    $g = is_array($post['child_gender'] ?? null) ? $post['child_gender'] : []; $a = is_array($post['child_age'] ?? null) ? $post['child_age'] : [];
    $i = is_array($post['child_inst'] ?? null) ? $post['child_inst'] : []; $c = is_array($post['child_class'] ?? null) ? $post['child_class'] : [];
    $n = min(6, max(count($g), count($a), count($i), count($c)));
    for ($k = 0; $k < $n; $k++) {
        $row = [
            'gender' => mb_substr(trim((string) ($g[$k] ?? '')), 0, 20),
            'age'    => mb_substr(trim((string) ($a[$k] ?? '')), 0, 10),
            'inst'   => mb_substr(trim((string) ($i[$k] ?? '')), 0, 150),
            'class'  => mb_substr(trim((string) ($c[$k] ?? '')), 0, 60),
        ];
        if ($row['gender'] !== '' || $row['age'] !== '' || $row['inst'] !== '' || $row['class'] !== '') { $out[] = $row; }
    }
    return $out;
}

/** একজন স্টুডেন্টের এই সেশনে এখনো সক্রিয় (Pending/Approved) আবেদন আছে কিনা */
function sa_has_active_application(PDO $pdo, int $student_id, ?int $session_id): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM scholarship_applications a
         LEFT JOIN scholarship_awards aw ON aw.id = a.award_id
         WHERE a.student_id = ? AND (a.session_id <=> ?)
           AND (a.status = 'Pending' OR (a.status = 'Approved' AND (aw.id IS NULL OR aw.status <> 'Cancelled')))"
    );
    $stmt->execute([$student_id, $session_id]);
    return (int) $stmt->fetchColumn() > 0;
}

/** আবেদন + ছাত্র + award/disbursement একসাথে (student ও admin দুই পেজেই এই একই SELECT) */
function sa_select_sql(): string
{
    return "SELECT a.*, st.name_en, st.student_id AS student_code, st.roll_no, st.gender AS student_gender,
                   c.class_name, ses.session_name, sh.shift_name,
                   t.name AS type_name,
                   aw.status AS award_status,
                   d.adjusted_amount, d.cash_amount, d.receipt_no AS disb_receipt, d.disbursed_at
            FROM scholarship_applications a
            LEFT JOIN students st ON st.id = a.student_id
            LEFT JOIN academic_classes c ON c.id = a.class_id
            LEFT JOIN academic_sessions ses ON ses.id = a.session_id
            LEFT JOIN academic_shifts sh ON sh.id = st.shift_id
            LEFT JOIN scholarship_types t ON t.id = a.scholarship_type_id
            LEFT JOIN scholarship_awards aw ON aw.id = a.award_id
            LEFT JOIN scholarship_disbursements d ON d.award_id = a.award_id";
}
