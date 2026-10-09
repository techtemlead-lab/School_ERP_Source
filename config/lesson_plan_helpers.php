<?php
/**
 * config/lesson_plan_helpers.php
 * -----------------------------------------------------------
 * Lesson Plan মডিউলের শেয়ার্ড হেল্পার — টেবিল তৈরি (self-healing), স্ট্যাটাস লেবেল,
 * আর Money Receipt-এর ডিজাইনে প্রিন্ট শিট রেন্ডার (CSS + HTML)।
 *
 * ব্যবহার করে:
 *   - admin/lesson_plan.php        (Admin + Teacher পোর্টাল দুই জায়গা থেকেই include হয়)
 *   - student/lesson_plan.php      (শুধু Approved প্ল্যান দেখায়)
 *   - lesson_plan_print.php        (স্বতন্ত্র প্রিন্ট পেজ — Admin / Teacher / Student তিনজনই খুলতে পারে)
 *
 * Workflow: Teacher তৈরি করে (Draft) -> Submit (Pending) -> Admin Approve/Reject
 *           -> Approved হলেই শুধু Student Portal-এ দেখা যায় ও প্রিন্ট করা যায়।
 */

if (!function_exists('lp_e')) {
    function lp_e($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
}

/** টেবিল না থাকলে অটো তৈরি — admin/homework.php-র মতোই self-healing migration */
function lp_ensure_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS elearning_lesson_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_id INT NULL,
            session_id INT NULL,
            class_id INT NOT NULL,
            section_id INT NULL,
            subject_id INT NOT NULL,
            teacher_id INT NULL,
            title VARCHAR(200) NOT NULL,
            chapter VARCHAR(200) NULL,
            lesson_date DATE NULL,
            duration_minutes INT NULL,
            objectives TEXT NULL,
            methods TEXT NULL,
            materials TEXT NULL,
            activities TEXT NULL,
            assessment TEXT NULL,
            homework TEXT NULL,
            status ENUM('Draft','Pending','Approved','Rejected') NOT NULL DEFAULT 'Draft',
            admin_remarks TEXT NULL,
            submitted_at DATETIME NULL,
            reviewed_by VARCHAR(100) NULL,
            reviewed_at DATETIME NULL,
            created_by VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_class_section (class_id, section_id),
            KEY idx_subject (subject_id),
            KEY idx_teacher (teacher_id),
            KEY idx_status (status),
            KEY idx_branch (branch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $column = $pdo->query("SHOW COLUMNS FROM elearning_lesson_plans LIKE 'branch_id'")->fetch();
    if (!$column) {
        $pdo->exec('ALTER TABLE elearning_lesson_plans ADD COLUMN branch_id INT NULL AFTER id, ADD KEY idx_branch (branch_id)');
    }
    if (!$pdo->query("SHOW COLUMNS FROM academic_sections LIKE 'branch_id'")->fetch()) {
        $pdo->exec('ALTER TABLE academic_sections ADD COLUMN branch_id INT NULL');
    }
}

/** Route context is explicit: simultaneous portal logins must not grant another role. */
function lp_require_role(PDO $pdo, string $role): ?array
{
    if ($role === 'admin') {
        if (empty($_SESSION['admin_logged']) || empty($_SESSION['admin_user'])) {
            erp_stop(403, 'Sign in to the school admin portal first.');
        }
        if (!empty($_SESSION['is_super_admin'])) {
            $q = $pdo->prepare('SELECT id FROM admin WHERE username=?');
            $q->execute([$_SESSION['admin_user']]);
        } else {
            $q = $pdo->prepare("SELECT id FROM system_users WHERE id=? AND username=? AND status='Active' AND (expires_at IS NULL OR expires_at>NOW())");
            $q->execute([(int) ($_SESSION['system_user_id'] ?? 0), $_SESSION['admin_user']]);
        }
        if (!$q->fetchColumn() || !perm_page_allowed($pdo, 'lesson_plan')) {
            erp_stop(403, 'You do not have permission to access lesson plans.');
        }
        if (function_exists('sec_enforce_session') && sec_enforce_session($pdo) !== null) {
            erp_stop(403, 'Your admin session has expired. Sign in again.');
        }
        return null;
    }
    $table = $role === 'teacher' ? 'employees' : 'students';
    $key = $role === 'teacher' ? 'teacher_id' : 'student_pk';
    if (!in_array($role, ['teacher', 'student'], true) || empty($_SESSION[$role . '_logged']) || empty($_SESSION[$key])) {
        erp_stop(403, 'Sign in to the selected school portal first.');
    }
    $q = $pdo->prepare("SELECT * FROM $table WHERE id=? AND status='Active'" . ($role === 'teacher' ? " AND type='Teachers'" : ''));
    $q->execute([(int) $_SESSION[$key]]);
    $row = $q->fetch();
    if (!$row || (!erp_is_demo() && !empty($row['must_change_password']))) {
        erp_stop(403, 'Use an active account and change its initial password before accessing lesson plans.');
    }
    return $row;
}

function lp_role_branch(PDO $pdo, string $role): ?int
{
    if ($role === 'admin') {
        if (empty($_SESSION['is_super_admin'])) {
            $q = $pdo->prepare('SELECT branch_id FROM system_users WHERE id=?');
            $q->execute([(int) ($_SESSION['system_user_id'] ?? 0)]);
            $id = $q->fetchColumn();
        } else {
            $id = $_SESSION['active_branch_id'] ?? null;
        }
    } else {
        $table = $role === 'teacher' ? 'employees' : 'students';
        $key = $role === 'teacher' ? 'teacher_id' : 'student_pk';
        $q = $pdo->prepare("SELECT branch_id FROM $table WHERE id=?");
        $q->execute([(int) ($_SESSION[$key] ?? 0)]);
        $id = $q->fetchColumn();
    }
    if ($id) { return (int) $id; }
    if ($role !== 'admin') {
        $branches = $pdo->query("SELECT id FROM branches WHERE status='Active'")->fetchAll(PDO::FETCH_COLUMN);
        if (count($branches) === 1) { return (int) $branches[0]; }
    }
    return null;
}

/** Reuse the same access predicate for listing, printing and every write. */
function lp_plan_scope(PDO $pdo, string $role, string $alias = 'p'): array
{
    $where = []; $params = [];
    $branch = lp_role_branch($pdo, $role);
    if ($branch !== null) { $where[] = "$alias.branch_id=?"; $params[] = $branch; }
    elseif ($role !== 'admin') { $where[] = "$alias.branch_id IS NULL"; }
    if ($role === 'teacher') {
        $where[] = "$alias.teacher_id=?"; $params[] = (int) $_SESSION['teacher_id'];
    } elseif ($role === 'student') {
        $q = $pdo->prepare('SELECT class_id, section_id, session_id FROM students WHERE id=?');
        $q->execute([(int) $_SESSION['student_pk']]); $student = $q->fetch();
        $where[] = "$alias.status='Approved' AND $alias.class_id=? AND ($alias.section_id IS NULL OR $alias.section_id=?) AND ($alias.session_id IS NULL OR $alias.session_id=?)";
        array_push($params, (int) $student['class_id'], (int) $student['section_id'], (int) $student['session_id']);
    } else { $where[] = "$alias.status<>'Draft'"; }
    return [implode(' AND ', $where), $params];
}

function lp_teacher_class_subjects(PDO $pdo, int $teacher_id): array
{
    $q = $pdo->prepare("SELECT class_id, subject_id FROM teacher_subject_assignments WHERE employee_id=? UNION SELECT class_id, subject_id FROM routines WHERE teacher_id=? AND status='Active' AND class_id IS NOT NULL");
    $q->execute([$teacher_id, $teacher_id]);
    $map = [];
    foreach ($q->fetchAll() as $row) { $map[(int) $row['class_id']][] = (int) $row['subject_id']; }
    return $map;
}

function lp_teacher_can_teach(PDO $pdo, int $teacher_id, int $class, int $subject, ?int $section): bool
{
    $q = $pdo->prepare("SELECT 1 FROM teacher_subject_assignments WHERE employee_id=? AND class_id=? AND subject_id=? UNION SELECT 1 FROM routines WHERE teacher_id=? AND class_id=? AND subject_id=? AND status='Active' AND (section_id IS NULL OR section_id=?) LIMIT 1");
    $q->execute([$teacher_id, $class, $subject, $teacher_id, $class, $subject, $section]);
    return (bool) $q->fetchColumn();
}

/** Validate the destination as well as the source when a plan is edited. */
function lp_destination_branch(PDO $pdo, string $role, int $class, ?int $section, ?int $teacher): ?int
{
    $branch = lp_role_branch($pdo, $role);
    if ($role !== 'admin' && $branch === null && branch_multi_branch_mode($pdo)) {
        throw new RuntimeException('Assign your account to a branch before creating lesson plans.');
    }
    if (!$pdo->query("SELECT id FROM academic_classes WHERE id=" . (int) $class . " AND status='Active'")->fetchColumn()) {
        throw new RuntimeException('Select an active class.');
    }
    if ($section !== null) {
        $q = $pdo->prepare("SELECT branch_id FROM academic_sections WHERE id=? AND class_id=? AND status='Active'");
        $q->execute([$section, $class]); $row = $q->fetch();
        $section_branch = !empty($row['branch_id']) ? (int) $row['branch_id'] : null;
        if ($row && $section_branch === null && !branch_multi_branch_mode($pdo)) { $section_branch = $branch; }
        if (!$row || ($branch !== null && $section_branch !== $branch)) {
            throw new RuntimeException('Select a section in your branch and class.');
        }
        $branch = $section_branch ?? $branch;
    }
    if ($teacher !== null) {
        $q = $pdo->prepare("SELECT branch_id FROM employees WHERE id=? AND type='Teachers' AND status='Active'");
        $q->execute([$teacher]); $row = $q->fetch();
        $teacher_branch = !empty($row['branch_id']) ? (int) $row['branch_id'] : null;
        if ($row && $teacher_branch === null && !branch_multi_branch_mode($pdo)) { $teacher_branch = $branch; }
        if (!$row || ($branch !== null && $teacher_branch !== $branch)) {
            throw new RuntimeException('Select an active teacher in the same branch.');
        }
        $branch = $teacher_branch ?? $branch;
    }
    if ($branch === null) {
        $branches = $pdo->query("SELECT id FROM branches WHERE status='Active'")->fetchAll(PDO::FETCH_COLUMN);
        if (count($branches) === 1) { $branch = (int) $branches[0]; }
        elseif ($role === 'admin' && !empty($_SESSION['is_super_admin'])) {
            throw new RuntimeException('Select a branch or a section before creating a plan.');
        }
    }
    return $branch;
}

/** স্ট্যাটাস => [বাংলা লেবেল, bootstrap badge class, প্রিন্টের স্ট্যাম্পের রঙ] */
function lp_status_meta(string $status): array
{
    switch ($status) {
        case 'Approved': return ['label' => 'অনুমোদিত',        'en' => 'APPROVED', 'badge' => 'bg-success',            'color' => '#15803d'];
        case 'Pending':  return ['label' => 'অনুমোদনের অপেক্ষায়', 'en' => 'PENDING',  'badge' => 'bg-warning text-dark', 'color' => '#b45309'];
        case 'Rejected': return ['label' => 'বাতিল/সংশোধন দরকার', 'en' => 'REJECTED', 'badge' => 'bg-danger',             'color' => '#b91c1c'];
        default:         return ['label' => 'ড্রাফট',           'en' => 'DRAFT',    'badge' => 'bg-secondary',          'color' => '#475569'];
    }
}

/** ফর্মের টেক্সট ফিল্ডগুলোর তালিকা: কলাম => [লেবেল (বাংলা), প্রিন্টে ইংরেজি লেবেল, placeholder] */
function lp_text_fields(): array
{
    return [
        'objectives' => ['শিখনফল / উদ্দেশ্য',          'Learning Objectives',  'এই পাঠ শেষে শিক্ষার্থীরা কী শিখবে...'],
        'methods'    => ['পাঠদান পদ্ধতি',              'Teaching Methods',     'আলোচনা, প্রশ্নোত্তর, দলীয় কাজ ইত্যাদি...'],
        'materials'  => ['শিক্ষা উপকরণ',               'Teaching Materials',   'বোর্ড, চার্ট, পাঠ্যবই, মডেল ইত্যাদি...'],
        'activities' => ['পাঠ উপস্থাপনা / শ্রেণি কার্যক্রম', 'Lesson Activities',    'ধাপে ধাপে ক্লাসে কী কী করা হবে...'],
        'assessment' => ['মূল্যায়ন',                   'Assessment',           'পাঠ শেষে কীভাবে যাচাই করা হবে...'],
        'homework'   => ['বাড়ির কাজ',                 'Home Work',            'শিক্ষার্থীদের জন্য বাড়ির কাজ (থাকলে)...'],
    ];
}

/** বর্তমান একাডেমিক সেশনের id (না থাকলে null) */
function lp_current_session_id(PDO $pdo): ?int
{
    try {
        $id = $pdo->query("SELECT id FROM academic_sessions WHERE is_current_session = 1 AND status = 'Active' ORDER BY id DESC LIMIT 1")->fetchColumn();
        return $id ? (int) $id : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * প্রিন্ট শিটের CSS — money_receipt.php-র ডিজাইনের সাথে মিল রেখে (নেভি পিল ট্যাগ, আউটলাইন পিল,
 * বক্সড ইনফো গ্রিড, হালকা নীল টেবিল হেডার, ওয়াটারমার্ক, গোল স্ট্যাম্প, স্বাক্ষরের ঘর)।
 */
function lp_print_css(): string
{
    return <<<'CSS'
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { font-family: Arial, 'Noto Sans Bengali', 'Nirmala UI', sans-serif; background: #eef1f5; padding: 16px; color: #111827; }
        .lp-bar { max-width: 210mm; margin: 0 auto 14px; display: flex; justify-content: space-between; align-items: center; }
        .lp-sheet { background: #fff; width: 210mm; min-height: 280mm; margin: 0 auto 20px; padding: 10mm 10mm 8mm; box-sizing: border-box;
                    box-shadow: 0 0 8px rgba(0,0,0,0.15); position: relative; overflow: hidden; display: flex; flex-direction: column; }
        .lp-watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 90mm; max-height: 90mm; object-fit: contain; opacity: 0.07; z-index: 1; }
        .lp-body { position: relative; z-index: 2; flex: 1 1 auto; display: flex; flex-direction: column; }

        .lp-head { display: flex; align-items: flex-start; gap: 10px; border-bottom: 1px solid #d1d5db; padding-bottom: 8px; }
        .lp-head-logo { width: 62px; flex: 0 0 62px; }
        .lp-head-logo img { max-width: 62px; max-height: 62px; }
        .lp-head-text { flex: 1 1 auto; text-align: center; }
        .lp-head-text h1 { margin: 0; font-size: 20px; font-weight: 700; color: #1f2937; }
        .lp-inst-line { font-size: 11px; color: #4b5563; margin-top: 1px; }
        .lp-head-qr { width: 60px; flex: 0 0 60px; }
        .lp-head-qr img, .lp-head-qr canvas { width: 58px !important; height: 58px !important; }

        .lp-tagrow { display: flex; justify-content: space-between; align-items: center; margin: 10px 0 8px; }
        .lp-pill { background: #1e3a5f; color: #fff; font-weight: 700; font-size: 12px; letter-spacing: .6px; padding: 6px 12px; border-radius: 4px; text-transform: uppercase; }
        .lp-pill-out { border: 1.5px solid #1e3a5f; color: #1e3a5f; font-weight: 700; font-size: 11px; letter-spacing: .5px; padding: 4px 12px; border-radius: 999px; text-transform: uppercase; }

        table.lp-grid { width: 100%; border-collapse: collapse; border: 1px solid #d1d5db; margin-bottom: 10px; }
        table.lp-grid td { width: 50%; border: 1px solid #e5e7eb; padding: 5px 8px; vertical-align: top; }
        .lp-lbl { display: block; font-size: 9px; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; }
        .lp-val { display: block; font-size: 13px; font-weight: 700; color: #111827; margin-top: 1px; word-break: break-word; }

        table.lp-items { width: 100%; border-collapse: collapse; border: 1px solid #d1d5db; margin-bottom: 10px; font-size: 12px; }
        table.lp-items thead th { background: #e8eef7; color: #1f2937; padding: 6px 8px; text-align: left; font-weight: 700; border-bottom: 1px solid #cbd5e1; }
        table.lp-items tbody td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; line-height: 1.55; }
        table.lp-items tbody td.lp-k { width: 28%; font-weight: 700; color: #1e3a5f; background: #f8fafc; border-right: 1px solid #e5e7eb; }
        table.lp-items tbody tr { page-break-inside: avoid; break-inside: avoid; }
        .lp-empty { color: #9ca3af; }

        .lp-note { display: flex; justify-content: space-between; gap: 12px; border: 1px solid; border-radius: 4px; padding: 7px 10px; font-weight: 700; font-size: 12px; margin-bottom: 8px; }
        .lp-note small { font-weight: 400; display: block; margin-top: 2px; color: #374151; }

        .lp-stamp { position: absolute; bottom: 52mm; right: 24mm; z-index: 3; transform: rotate(-18deg);
                    width: 118px; height: 118px; border: 3px double; border-radius: 50%; display: flex; flex-direction: column;
                    align-items: center; justify-content: center; text-align: center; opacity: .45; pointer-events: none; }
        .lp-stamp b { font-size: 19px; letter-spacing: 1px; }
        .lp-stamp span { font-size: 9px; margin-top: 2px; }

        .lp-sign-row { display: flex; justify-content: space-between; margin-top: auto; padding-top: 38px; }
        .lp-sign { text-align: center; font-size: 11px; font-weight: 700; min-width: 150px; }
        .lp-sign .lp-sign-line { border-top: 1px solid #374151; margin-bottom: 3px; }
        .lp-sign small { display: block; font-weight: 400; color: #4b5563; font-size: 10px; min-height: 12px; }
        .lp-foot { text-align: center; font-size: 9px; color: #9ca3af; margin-top: 8px; }

        @media (max-width: 800px) { body { padding: 8px; overflow-x: auto; } }
        @media print {
            body { background: #fff; padding: 0; margin: 0; }
            .lp-bar { display: none; }
            .lp-sheet { box-shadow: none; margin: 0; width: auto; min-height: 0; padding: 0; min-height: 277mm; overflow: visible; }
        }
        @page { size: A4 portrait; margin: 8mm; }
CSS;
}

/**
 * একটা Lesson Plan-এর প্রিন্ট শিট (HTML) রেন্ডার করে।
 * $plan  : elearning_lesson_plans রো + class_name, section_name, subject_name, teacher_name, session_name
 * $inst  : institute_settings রো
 * $copy  : 'Student Copy' / 'Teacher Copy' / 'Office Copy'
 * $addr_line : "ঠিকানা, শহর জিপ" (branches টেবিল থেকে, না থাকলে ফাঁকা)
 * $logo_prefix : লোগোর পাথ-প্রিফিক্স (root থেকে কল হলে '')
 */
function lp_render_sheet(array $plan, array $inst, string $copy, string $addr_line, string $logo_prefix, string $qr_id): string
{
    $meta = lp_status_meta((string) $plan['status']);
    $fields = lp_text_fields();
    $logo = !empty($inst['logo_path']) ? $logo_prefix . $inst['logo_path'] : '';

    $dateTxt = !empty($plan['lesson_date']) ? date('d F Y', strtotime($plan['lesson_date'])) : '—';
    $classTxt = trim(($plan['class_name'] ?? '') . (!empty($plan['section_name']) ? ' - ' . $plan['section_name'] : ' (সব সেকশন)'));
    $durTxt = !empty($plan['duration_minutes']) ? ((int) $plan['duration_minutes'] . ' min') : '—';
    $planNo = 'LP-' . str_pad((string) (int) $plan['id'], 5, '0', STR_PAD_LEFT);

    $stampDate = '';
    if ($plan['status'] === 'Approved' && !empty($plan['reviewed_at'])) { $stampDate = date('d/m/Y', strtotime($plan['reviewed_at'])); }
    elseif ($plan['status'] === 'Pending' && !empty($plan['submitted_at'])) { $stampDate = date('d/m/Y', strtotime($plan['submitted_at'])); }

    $GLOBALS['lp_qr_ids'][] = [
        'id'   => $qr_id,
        'text' => $planNo . ' | ' . ($plan['class_name'] ?? '') . ' | ' . ($plan['subject_name'] ?? '') . ' | ' . $meta['en'],
    ];

    ob_start();
    ?>
    <div class="lp-sheet">
        <?php if ($logo): ?><img src="<?php echo lp_e($logo); ?>" class="lp-watermark" alt=""><?php endif; ?>
        <div class="lp-stamp" style="color: <?php echo $meta['color']; ?>; border-color: <?php echo $meta['color']; ?>;">
            <b><?php echo lp_e($meta['en']); ?></b>
            <?php if ($stampDate): ?><span><?php echo lp_e($stampDate); ?></span><?php endif; ?>
        </div>

        <div class="lp-body">
            <div class="lp-head">
                <div class="lp-head-logo"><?php if ($logo): ?><img src="<?php echo lp_e($logo); ?>" alt=""><?php endif; ?></div>
                <div class="lp-head-text">
                    <h1><?php echo lp_e($inst['institute_name'] ?? 'School Name'); ?></h1>
                    <?php if ($addr_line !== ''): ?><div class="lp-inst-line">(<?php echo lp_e($addr_line); ?>)</div><?php endif; ?>
                    <?php if (!empty($inst['phone_number'])): ?><div class="lp-inst-line">Phone: <?php echo lp_e($inst['phone_number']); ?></div><?php endif; ?>
                </div>
                <div class="lp-head-qr" id="<?php echo lp_e($qr_id); ?>"></div>
            </div>

            <div class="lp-tagrow">
                <div class="lp-pill">Lesson Plan</div>
                <div class="lp-pill-out"><?php echo lp_e($copy); ?></div>
            </div>

            <table class="lp-grid">
                <tr>
                    <td><span class="lp-lbl">Session</span><span class="lp-val"><?php echo lp_e($plan['session_name'] ?? '—'); ?></span></td>
                    <td><span class="lp-lbl">Class</span><span class="lp-val"><?php echo lp_e($classTxt ?: '—'); ?></span></td>
                </tr>
                <tr>
                    <td><span class="lp-lbl">Subject</span><span class="lp-val"><?php echo lp_e($plan['subject_name'] ?? '—'); ?></span></td>
                    <td><span class="lp-lbl">Teacher</span><span class="lp-val"><?php echo lp_e($plan['teacher_name'] ?: '—'); ?></span></td>
                </tr>
                <tr>
                    <td><span class="lp-lbl">Topic / Lesson</span><span class="lp-val"><?php echo lp_e($plan['title']); ?></span></td>
                    <td><span class="lp-lbl">Chapter</span><span class="lp-val"><?php echo lp_e(($plan['chapter'] ?? '') !== '' ? $plan['chapter'] : '—'); ?></span></td>
                </tr>
                <tr>
                    <td><span class="lp-lbl">Lesson Date</span><span class="lp-val"><?php echo lp_e($dateTxt); ?></span></td>
                    <td><span class="lp-lbl">Plan No &nbsp;/&nbsp; Duration</span><span class="lp-val"><?php echo lp_e($planNo . '  /  ' . $durTxt); ?></span></td>
                </tr>
            </table>

            <table class="lp-items">
                <thead><tr><th>Lesson Details</th><th>Description</th></tr></thead>
                <tbody>
                <?php foreach ($fields as $col => $f): $v = trim((string) ($plan[$col] ?? '')); ?>
                    <tr>
                        <td class="lp-k"><?php echo lp_e($f[0]); ?><br><span style="font-weight:400;font-size:10px;color:#6b7280;"><?php echo lp_e($f[1]); ?></span></td>
                        <td><?php echo $v !== '' ? nl2br(lp_e($v)) : '<span class="lp-empty">—</span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $noteColors = [
                'Approved' => ['#15803d', '#f0fdf4'], 'Pending' => ['#b45309', '#fffbeb'],
                'Rejected' => ['#b91c1c', '#fef2f2'], 'Draft' => ['#475569', '#f8fafc'],
            ];
            [$nc, $nbg] = $noteColors[$plan['status']] ?? $noteColors['Draft'];
            ?>
            <div class="lp-note" style="border-color: <?php echo $nc; ?>; background: <?php echo $nbg; ?>; color: <?php echo $nc; ?>;">
                <div>
                    Approval Status : <?php echo lp_e($meta['en']); ?>
                    <?php if (trim((string) ($plan['admin_remarks'] ?? '')) !== ''): ?>
                        <small>Remarks: <?php echo lp_e($plan['admin_remarks']); ?></small>
                    <?php endif; ?>
                </div>
                <div style="text-align:right;">
                    <?php if ($plan['status'] === 'Approved' && !empty($plan['reviewed_at'])): ?>
                        <?php echo lp_e(date('d M Y', strtotime($plan['reviewed_at']))); ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="lp-sign-row">
                <div class="lp-sign">
                    <small><?php echo lp_e($plan['teacher_name'] ?? ''); ?></small>
                    <div class="lp-sign-line"></div>Prepared By (Teacher)
                </div>
                <div class="lp-sign">
                    <small><?php echo $plan['status'] === 'Approved' ? lp_e($plan['reviewed_by'] ?? '') : ''; ?></small>
                    <div class="lp-sign-line"></div>Approved By (Head / Principal)
                </div>
            </div>
            <div class="lp-foot">Printed on <?php echo lp_e(date('d M Y, h:i A')); ?></div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
