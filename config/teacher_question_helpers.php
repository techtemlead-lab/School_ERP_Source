<?php
/**
 * config/teacher_question_helpers.php
 * -----------------------------------------------------------
 * Teacher Question Upload — শিক্ষক পরীক্ষার প্রশ্নপত্র (ফাইল এবং/অথবা টাইপ করা প্রশ্ন) আপলোড করেন,
 * Admin যাচাই করে Approve/Reject করেন। প্রশ্নপত্র গোপনীয়, তাই ফাইল সরাসরি URL-এ খোলা যায় না —
 * শুধু লগইন করা Admin বা ফাইলের মালিক শিক্ষক teacher_question_file.php দিয়ে ডাউনলোড করতে পারেন।
 *
 * ব্যবহার করে: admin/teacher_questions.php (Admin + Teacher পোর্টাল দুই জায়গা থেকেই include হয়),
 *               teacher_question_file.php
 */

if (!function_exists('tq_e')) {
    function tq_e($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
}

function tq_ensure_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS teacher_question_papers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_id INT NULL,
            teacher_id INT NULL,
            session_id INT NULL,
            class_id INT NOT NULL,
            subject_id INT NOT NULL,
            exam_name VARCHAR(150) NULL,
            title VARCHAR(200) NOT NULL,
            full_marks DECIMAL(6,2) NULL,
            duration_minutes INT NULL,
            exam_date DATE NULL,
            question_text MEDIUMTEXT NULL,
            file_path VARCHAR(100) NULL,
            file_name VARCHAR(200) NULL,
            file_ext VARCHAR(10) NULL,
            status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
            admin_remarks TEXT NULL,
            submitted_at DATETIME NULL,
            reviewed_by VARCHAR(100) NULL,
            reviewed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_branch (branch_id), KEY idx_teacher (teacher_id), KEY idx_class (class_id), KEY idx_subject (subject_id), KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (!$pdo->query("SHOW COLUMNS FROM teacher_question_papers LIKE 'branch_id'")->fetch()) {
        $pdo->exec('ALTER TABLE teacher_question_papers ADD COLUMN branch_id INT NULL AFTER id, ADD KEY idx_branch (branch_id)');
    }
}

/** ['label' => বাংলা, 'badge' => bootstrap class] */
function tq_status_meta(string $status): array
{
    switch ($status) {
        case 'Approved': return ['label' => 'অনুমোদিত',            'badge' => 'bg-success'];
        case 'Rejected': return ['label' => 'বাতিল/সংশোধন দরকার',  'badge' => 'bg-danger'];
        default:         return ['label' => 'অনুমোদনের অপেক্ষায়',  'badge' => 'bg-warning text-dark'];
    }
}

/** অনুমোদিত এক্সটেনশন => [Content-Type, inline দেখানো যাবে কিনা] */
function tq_allowed_types(): array
{
    return [
        'pdf'  => ['application/pdf', true],
        'jpg'  => ['image/jpeg', true],
        'jpeg' => ['image/jpeg', true],
        'png'  => ['image/png', true],
        'doc'  => ['application/msword', false],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', false],
    ];
}

/** ফাইলের ফোল্ডারের (ফিজিক্যাল) পাথ — না থাকলে বানায় এবং সরাসরি ওয়েব অ্যাক্সেস বন্ধ করার .htaccess লিখে */
function tq_storage_dir(string $project_root): string
{
    $dir = tenant_upload_abs($project_root, 'teacher_questions');
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Question file storage is unavailable.');
    }
    $deny = "Require all denied\n";
    $ht = $dir . '.htaccess';
    if (!is_file($ht) || file_get_contents($ht) !== $deny) {
        if (file_put_contents($ht, $deny, LOCK_EX) === false) {
            throw new RuntimeException('Cannot protect confidential question storage.');
        }
    }
    return $dir;
}

/**
 * আপলোড করা ফাইল যাচাই ও সংরক্ষণ।
 * @return array{ok:bool, file_path:?string, file_name:?string, file_ext:?string, error:?string}
 */
function tq_handle_upload(array $file, string $project_root): array
{
    $none = ['ok' => true, 'file_path' => null, 'file_name' => null, 'file_ext' => null, 'error' => null];
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) { return $none; }
    $fail = function (string $m) { return ['ok' => false, 'file_path' => null, 'file_name' => null, 'file_ext' => null, 'error' => $m]; };
    if ($file['error'] !== UPLOAD_ERR_OK) { return $fail('ফাইল আপলোডে সমস্যা হয়েছে।'); }
    if (!is_uploaded_file($file['tmp_name'] ?? '') || ($file['size'] ?? 0) < 1) { return $fail('Select a valid nonempty file.'); }
    if ($file['size'] > 10 * 1024 * 1024) { return $fail('ফাইলের সাইজ 10MB এর বেশি হওয়া যাবে না।'); }

    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $types = tq_allowed_types();
    if (!isset($types[$ext])) { return $fail('শুধু PDF, Word (doc/docx) অথবা ছবি (jpg/png) আপলোড করা যাবে।'); }

    // এক্সটেনশনের সাথে আসল কনটেন্টও মিলিয়ে দেখা (নাম বদলে স্ক্রিপ্ট ঢোকানো ঠেকাতে)
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? finfo_file($fi, $file['tmp_name']) : '';
        if ($fi) { finfo_close($fi); }
        $ok = [
            'pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
            'doc' => ['application/msword', 'application/octet-stream', 'application/CDFV2'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        ][$ext];
        if ($mime !== '' && !in_array($mime, $ok, true)) { return $fail('ফাইলের ধরন ঠিক নেই — সঠিক PDF/Word/ছবি ফাইল দিন।'); }
    }

    if ($ext === 'docx') {
        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) { return $fail('Select a valid Word document.'); }
        $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false;
        $zip->close();
        if (!$valid) { return $fail('Select a valid Word document.'); }
    } elseif ($ext === 'doc' && file_get_contents($file['tmp_name'], false, null, 0, 8) !== hex2bin('d0cf11e0a1b11ae1')) {
        return $fail('Select a valid Word document.');
    }
    $dir = tq_storage_dir($project_root);
    if (!is_dir($dir)) { return $fail('ফাইল রাখার ফোল্ডার তৈরি করা যায়নি।'); }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $stored)) { return $fail('ফাইল সেভ করা যায়নি।'); }

    $orig = mb_substr(preg_replace('/[^\p{L}\p{N}\s._()-]/u', '', basename((string) $file['name'])), 0, 180);
    return ['ok' => true, 'file_path' => $stored, 'file_name' => $orig !== '' ? $orig : ('question.' . $ext), 'file_ext' => $ext, 'error' => null];
}

/** পুরনো ফাইল মুছে ফেলা (শুধু স্টোরেজ ফোল্ডারের ভেতর — basename দিয়ে পাথ ট্র্যাভার্সাল ঠেকানো) */
function tq_delete_file(?string $stored_name, string $project_root): void
{
    if (!$stored_name) { return; }
    $path = tq_storage_dir($project_root) . basename($stored_name);
    if (is_file($path)) { @unlink($path); }
}

function tq_scope(PDO $pdo, string $role, string $alias = 'q'): array
{
    [$where, $params] = erp_portal_branch_scope($pdo, $role, "$alias.branch_id");
    if ($role === 'teacher') { $where .= " AND $alias.teacher_id=?"; $params[] = (int) $_SESSION['teacher_id']; }
    return [$where, $params];
}
