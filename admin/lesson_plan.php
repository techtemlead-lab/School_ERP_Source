<?php
// admin/lesson_plan.php - E-Learning Module > Lesson Plan
// -----------------------------------------------------------------
// এই একই ফাইল Admin Panel আর Teacher Portal দুই জায়গা থেকেই include হয় (admin/homework.php
// যেভাবে করে ঠিক সেই প্যাটার্নে)।
//
//   Teacher context : নিজের Assign করা Class+Subject-এর জন্য Lesson Plan তৈরি/এডিট করে
//                     (Draft সেভ করা যায়, অথবা "Submit for Approval" দিয়ে অ্যাডমিনের কাছে পাঠানো যায়)।
//                     শুধু নিজের বানানো প্ল্যানই দেখা/এডিট/ডিলিট করা যায়।
//   Admin context   : Teacher-দের Submit করা প্ল্যান Approve / Reject (মন্তব্যসহ) করে। অ্যাডমিন নিজেও
//                     প্ল্যান বানাতে পারে — সেটা সরাসরি Approved হয়।
//
// শুধু Approved প্ল্যানই Student Portal-এ (নিজের Class/Section অনুযায়ী) দেখা যায়।
// প্রিন্ট: ../lesson_plan_print.php?id=<id> (Money Receipt-এর ডিজাইনে)।

// This controller is included only by the authenticated portal routers.
require_once __DIR__ . '/../config/runtime.php';
$lp_route = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
if (!in_array($lp_route, ['/admin/index.php', '/teacher/index.php'], true) || !isset($pdo)) {
    erp_stop(404, 'Open lesson plans from your school portal.');
}
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../config/lesson_plan_helpers.php';
$is_teacher_ctx = $lp_route === '/teacher/index.php';
$lp_role = $is_teacher_ctx ? 'teacher' : 'admin';
lp_require_role($pdo, $lp_role);
lp_ensure_tables($pdo);
$me_teacher_id = $is_teacher_ctx ? (int) $_SESSION['teacher_id'] : 0;
$lp_admin_name = $is_teacher_ctx ? '' : (string) $_SESSION['admin_user'];
$lp_base = 'index.php?page=lesson_plan';
[$lp_write_where, $lp_write_params] = lp_plan_scope($pdo, $lp_role, 'elearning_lesson_plans');

$lp_allowed_map = $is_teacher_ctx ? lp_teacher_class_subjects($pdo, $me_teacher_id) : [];
$lp_allowed_class_ids = array_keys($lp_allowed_map);

$lp_err = $_SESSION['lp_err'] ?? '';
$lp_msg = $_SESSION['lp_msg'] ?? '';
unset($_SESSION['lp_err'], $_SESSION['lp_msg']);

// -----------------------------------------------------------------
// POST হ্যান্ডলার
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['lp_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: ' . $lp_base);
        exit();
    }

    // --- তৈরি / আপডেট ---
    if (isset($_POST['save_draft']) || isset($_POST['save_submit'])) {
        $lp_id      = (int) ($_POST['lp_id'] ?? 0);
        $class_id   = (int) ($_POST['class_id'] ?? 0);
        $section_id = (int) ($_POST['section_id'] ?? 0) ?: null;
        $subject_id = (int) ($_POST['subject_id'] ?? 0);
        $title      = mb_substr(trim($_POST['title'] ?? ''), 0, 200);
        $chapter    = mb_substr(trim($_POST['chapter'] ?? ''), 0, 200);
        $date_raw   = trim($_POST['lesson_date'] ?? '');
        $lesson_date = null;
        if ($date_raw !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $date_raw);
            if ($d && $d->format('Y-m-d') === $date_raw) { $lesson_date = $date_raw; }
        }
        $duration = (int) ($_POST['duration_minutes'] ?? 0);
        $duration = ($duration > 0 && $duration <= 600) ? $duration : null;

        $texts = [];
        foreach (array_keys(lp_text_fields()) as $col) {
            $texts[$col] = mb_substr(trim($_POST[$col] ?? ''), 0, 5000);
        }

        $err = '';
        if (!$class_id || !$subject_id || $title === '') {
            $err = 'Class, Subject ও Lesson Title অবশ্যই দিতে হবে।';
        }
        if ($err === '' && $lesson_date === null) {
            $err = 'Lesson Date সঠিকভাবে দিন।';
        }
        // নির্বাচিত Section সত্যিই এই Class-এর কিনা
        if ($err === '' && $section_id) {
            $chk = $pdo->prepare("SELECT 1 FROM academic_sections WHERE id = ? AND class_id = ?");
            $chk->execute([$section_id, $class_id]);
            if (!$chk->fetchColumn()) { $err = 'নির্বাচিত Section এই Class-এর নয়।'; }
        }
        // নির্বাচিত Subject সত্যিই এই Class-এর কিনা
        if ($err === '') {
            $chk = $pdo->prepare("SELECT 1 FROM academic_subjects WHERE id = ? AND class_id = ? AND status='Active'");
            $chk->execute([$subject_id, $class_id]);
            if (!$chk->fetchColumn()) { $err = 'নির্বাচিত Subject এই Class-এর নয়।'; }
        }
        // Teacher হলে শুধু নিজের Assign করা Class/Subject
        if ($err === '' && $is_teacher_ctx) {
            if (!isset($lp_allowed_map[$class_id]) || !in_array($subject_id, $lp_allowed_map[$class_id], true)) {
                $err = 'আপনি এই Class/Subject এর জন্য Assign করা নন।';
            }
        }

        $teacher_id_to_save = $is_teacher_ctx ? $me_teacher_id : ((int) ($_POST['teacher_id'] ?? 0) ?: null);
        $branch_id_to_save = null;
        if ($err === '') {
            try {
                $branch_id_to_save = lp_destination_branch($pdo, $lp_role, $class_id, $section_id, $teacher_id_to_save);
                if ($is_teacher_ctx && !lp_teacher_can_teach($pdo, $me_teacher_id, $class_id, $subject_id, $section_id)) {
                    throw new RuntimeException('You are not assigned to this class, subject and section.');
                }
            } catch (RuntimeException $e) { $err = $e->getMessage(); }
        }
        if ($lp_id && $err === '') {
            $q = $pdo->prepare("SELECT id FROM elearning_lesson_plans WHERE id=? AND $lp_write_where");
            $q->execute(array_merge([$lp_id], $lp_write_params));
            if (!$q->fetchColumn()) { erp_stop(403, 'You cannot edit this lesson plan.'); }
        }
        if ($err !== '') {
            $_SESSION['lp_err'] = $err;
            header('Location: ' . $lp_base . ($lp_id ? '&action=form&id=' . $lp_id : '&action=form'));
            exit();
        }

        if ($is_teacher_ctx) {
            // Teacher: Draft নাকি Submit — সে কখনো নিজে Approved করতে পারে না।
            // অনুমোদিত প্ল্যান এডিট করলে সেটা আবার Draft/Pending হয়ে যায় (পরিবর্তিত কনটেন্ট নতুন করে অনুমোদন লাগবে)।
            $new_status = isset($_POST['save_submit']) ? 'Pending' : 'Draft';
        } else {
            $new_status = null; // Admin: নতুন হলে Approved; এডিটে বিদ্যমান স্ট্যাটাস অপরিবর্তিত
        }

        if ($lp_id) {
            $sets = "branch_id=?, class_id=?, section_id=?, subject_id=?, title=?, chapter=?, lesson_date=?, duration_minutes=?,
                     objectives=?, methods=?, materials=?, activities=?, assessment=?, homework=?";
            $params = [$branch_id_to_save, $class_id, $section_id, $subject_id, $title, $chapter ?: null, $lesson_date, $duration,
                       $texts['objectives'], $texts['methods'], $texts['materials'], $texts['activities'], $texts['assessment'], $texts['homework']];
            if ($is_teacher_ctx) {
                $sets .= ", status=?, submitted_at=?, admin_remarks=NULL, reviewed_by=NULL, reviewed_at=NULL";
                $params[] = $new_status;
                $params[] = $new_status === 'Pending' ? date('Y-m-d H:i:s') : null;
            } else {
                $sets .= ", teacher_id=?";
                $params[] = $teacher_id_to_save;
            }
            $sql = "UPDATE elearning_lesson_plans SET $sets WHERE id=? AND $lp_write_where";
            $params[] = $lp_id;
            $params = array_merge($params, $lp_write_params);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($is_teacher_ctx && $stmt->rowCount() === 0) {
                // কিছু বদলায়নি, অথবা প্ল্যানটা তার নয় — দ্বিতীয় ক্ষেত্র আলাদা করে ধরা
                $own = $pdo->prepare("SELECT 1 FROM elearning_lesson_plans WHERE id=? AND teacher_id=?");
                $own->execute([$lp_id, $me_teacher_id]);
                if (!$own->fetchColumn()) {
                    $_SESSION['lp_err'] = 'এই Lesson Plan পাওয়া যায়নি।';
                    header('Location: ' . $lp_base);
                    exit();
                }
            }
            $_SESSION['lp_msg'] = $new_status === 'Pending' ? 'Lesson Plan অনুমোদনের জন্য জমা দেওয়া হয়েছে।' : 'Lesson Plan আপডেট হয়েছে।';
            sec_log_activity($pdo, 'Lesson Plan', 'Update', "Lesson Plan আপডেট করা হয়েছে: {$title}");
        } else {
            $status_ins = $is_teacher_ctx ? $new_status : 'Approved';
            $pdo->prepare(
                "INSERT INTO elearning_lesson_plans
                    (branch_id, session_id, class_id, section_id, subject_id, teacher_id, title, chapter, lesson_date, duration_minutes,
                     objectives, methods, materials, activities, assessment, homework,
                     status, submitted_at, reviewed_by, reviewed_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $branch_id_to_save, lp_current_session_id($pdo), $class_id, $section_id, $subject_id, $teacher_id_to_save, $title, $chapter ?: null, $lesson_date, $duration,
                $texts['objectives'], $texts['methods'], $texts['materials'], $texts['activities'], $texts['assessment'], $texts['homework'],
                $status_ins,
                $status_ins === 'Pending' ? date('Y-m-d H:i:s') : null,
                $is_teacher_ctx ? null : $lp_admin_name,
                $is_teacher_ctx ? null : date('Y-m-d H:i:s'),
                $is_teacher_ctx ? ('teacher#' . $me_teacher_id) : $lp_admin_name,
            ]);
            $_SESSION['lp_msg'] = $is_teacher_ctx
                ? ($status_ins === 'Pending' ? 'Lesson Plan অনুমোদনের জন্য জমা দেওয়া হয়েছে।' : 'Lesson Plan ড্রাফট হিসেবে সেভ হয়েছে।')
                : 'Lesson Plan তৈরি ও অনুমোদিত হয়েছে।';
            sec_log_activity($pdo, 'Lesson Plan', 'Create', "নতুন Lesson Plan তৈরি করা হয়েছে: {$title}");
        }
        header('Location: ' . $lp_base);
        exit();
    }

    // --- ডিলিট ---
    if (isset($_POST['delete_lp'])) {
        $lp_id = (int) ($_POST['lp_id'] ?? 0);
        $row = $pdo->prepare("SELECT title, teacher_id, status FROM elearning_lesson_plans WHERE id = ? AND $lp_write_where");
        $row->execute(array_merge([$lp_id], $lp_write_params));
        $old = $row->fetch();
        if ($old) {
            if ($is_teacher_ctx && ((int) $old['teacher_id'] !== $me_teacher_id || $old['status'] === 'Approved')) {
                // অনুমোদিত প্ল্যান টিচার নিজে ডিলিট করতে পারবে না (অ্যাডমিন করতে পারবে)
                $_SESSION['lp_err'] = 'এই Lesson Plan আপনি মুছতে পারবেন না।';
            } else {
                $pdo->prepare("DELETE FROM elearning_lesson_plans WHERE id = ? AND $lp_write_where" . ($is_teacher_ctx ? " AND status<>'Approved'" : ""))->execute(array_merge([$lp_id], $lp_write_params));
                $_SESSION['lp_msg'] = 'Lesson Plan মুছে ফেলা হয়েছে।';
                sec_log_activity($pdo, 'Lesson Plan', 'Delete', "Lesson Plan মুছে ফেলা হয়েছে: " . $old['title']);
            }
        }
        header('Location: ' . $lp_base);
        exit();
    }

    // --- Admin: Approve / Reject ---
    if (!$is_teacher_ctx && (isset($_POST['approve_lp']) || isset($_POST['reject_lp']))) {
        $lp_id   = (int) ($_POST['lp_id'] ?? 0);
        $remarks = mb_substr(trim($_POST['admin_remarks'] ?? ''), 0, 1000);
        $approve = isset($_POST['approve_lp']);
        if (!$approve && $remarks === '') {
            $_SESSION['lp_err'] = 'বাতিল করলে টিচারের জন্য একটা মন্তব্য (কেন/কী সংশোধন দরকার) লিখুন।';
            header('Location: ' . $lp_base . '&action=view&id=' . $lp_id);
            exit();
        }
        $stmt = $pdo->prepare(
            "UPDATE elearning_lesson_plans SET status=?, admin_remarks=?, reviewed_by=?, reviewed_at=NOW()
             WHERE id=? AND $lp_write_where"
        );
        $stmt->execute(array_merge([$approve ? 'Approved' : 'Rejected', $remarks ?: null, $lp_admin_name, $lp_id], $lp_write_params));
        if ($stmt->rowCount()) {
            $_SESSION['lp_msg'] = $approve ? 'Lesson Plan অনুমোদন করা হয়েছে — এখন স্টুডেন্ট প্যানেলে দেখা যাবে।' : 'Lesson Plan বাতিল করে টিচারকে ফেরত পাঠানো হয়েছে।';
            sec_log_activity($pdo, 'Lesson Plan', $approve ? 'Approve' : 'Reject', "Lesson Plan #{$lp_id} " . ($approve ? 'অনুমোদিত' : 'বাতিল'));
        } else {
            $_SESSION['lp_err'] = 'এই Lesson Plan-এর স্ট্যাটাস বদলানো যায়নি (ড্রাফট বা পাওয়া যায়নি)।';
        }
        header('Location: ' . $lp_base . '&action=view&id=' . $lp_id);
        exit();
    }
}

// -----------------------------------------------------------------
// ড্রপডাউন ডাটা
// -----------------------------------------------------------------
if ($is_teacher_ctx) {
    if ($lp_allowed_class_ids) {
        $in = implode(',', array_fill(0, count($lp_allowed_class_ids), '?'));
        $stmt = $pdo->prepare("SELECT id, class_name FROM academic_classes WHERE id IN ($in) AND status='Active' ORDER BY rank");
        $stmt->execute($lp_allowed_class_ids);
        $lp_classes = $stmt->fetchAll();
    } else {
        $lp_classes = [];
    }
} else {
    $lp_classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status='Active' ORDER BY rank")->fetchAll();
}
$lp_branch = lp_role_branch($pdo, $lp_role);
$lp_teacher_query = $pdo->prepare("SELECT id, name FROM employees WHERE type='Teachers' AND status='Active'" . ($lp_branch !== null ? (branch_multi_branch_mode($pdo) ? ' AND branch_id=?' : ' AND (branch_id=? OR branch_id IS NULL)') : '') . ' ORDER BY name');
$lp_teacher_query->execute($lp_branch !== null ? [$lp_branch] : []);
$lp_teachers = $is_teacher_ctx ? [] : $lp_teacher_query->fetchAll();

$lp_action  = $_GET['action'] ?? 'list';
$lp_get_id  = (int) ($_GET['id'] ?? 0);

$lp_select_sql = "SELECT p.*, c.class_name, s.section_name, sub.subject_name, e.name AS teacher_name, ses.session_name
                  FROM elearning_lesson_plans p
                  LEFT JOIN academic_classes c ON c.id = p.class_id
                  LEFT JOIN academic_sections s ON s.id = p.section_id
                  LEFT JOIN academic_subjects sub ON sub.id = p.subject_id
                  LEFT JOIN employees e ON e.id = p.teacher_id
                  LEFT JOIN academic_sessions ses ON ses.id = p.session_id";

/** একটা প্ল্যান লোড — teacher হলে শুধু নিজেরটা, admin হলে Draft বাদে সব */
function lp_load_one(PDO $pdo, string $select_sql, int $id, bool $is_teacher, int $teacher_id): ?array
{
    $sql = $select_sql . " WHERE p.id = ?";
    $params = [$id];
    [$scope, $scope_params] = lp_plan_scope($pdo, $is_teacher ? 'teacher' : 'admin');
    $sql .= ' AND ' . $scope;
    $params = array_merge($params, $scope_params);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

// =================================================================
// action=view — বিস্তারিত দেখা + (Admin) Approve/Reject
// =================================================================
if ($lp_action === 'view' && $lp_get_id) {
    $plan = lp_load_one($pdo, $lp_select_sql, $lp_get_id, $is_teacher_ctx, $me_teacher_id);
    if (!$plan) {
        echo '<div class="alert alert-danger">Lesson Plan পাওয়া যায়নি।</div>';
        return;
    }
    $meta = lp_status_meta($plan['status']);
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="fw-bold mb-0"><?php echo lp_e($plan['title']); ?> <span class="badge <?php echo $meta['badge']; ?> ms-2"><?php echo lp_e($meta['label']); ?></span></h5>
        <div class="d-flex gap-2">
            <a href="../lesson_plan_print.php?id=<?php echo (int) $plan['id']; ?>&amp;portal=<?php echo $lp_role; ?>" target="_blank" class="btn btn-sm btn-dark"><i class="fa-solid fa-print me-1"></i> Print</a>
            <?php if (!$is_teacher_ctx || $plan['status'] !== 'Pending'): ?>
                <a href="<?php echo $lp_base; ?>&action=form&id=<?php echo (int) $plan['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen me-1"></i> Edit</a>
            <?php endif; ?>
            <a href="<?php echo $lp_base; ?>" class="btn btn-sm btn-secondary"><i class="fa-solid fa-arrow-left me-1"></i> ফিরে যান</a>
        </div>
    </div>

    <?php if ($lp_msg): ?><div class="alert alert-success py-2"><?php echo lp_e($lp_msg); ?></div><?php endif; ?>
    <?php if ($lp_err): ?><div class="alert alert-danger py-2"><?php echo lp_e($lp_err); ?></div><?php endif; ?>

    <?php if ($plan['status'] === 'Rejected' && trim((string) $plan['admin_remarks']) !== ''): ?>
        <div class="alert alert-danger"><b>অ্যাডমিনের মন্তব্য:</b> <?php echo nl2br(lp_e($plan['admin_remarks'])); ?></div>
    <?php endif; ?>

    <div class="card-custom mb-3">
        <div class="row g-2">
            <div class="col-md-3"><b>Session:</b> <?php echo lp_e($plan['session_name'] ?? '—'); ?></div>
            <div class="col-md-3"><b>Class:</b> <?php echo lp_e($plan['class_name']); ?><?php echo $plan['section_name'] ? ' / ' . lp_e($plan['section_name']) : ' (সব সেকশন)'; ?></div>
            <div class="col-md-3"><b>Subject:</b> <?php echo lp_e($plan['subject_name']); ?></div>
            <div class="col-md-3"><b>Teacher:</b> <?php echo lp_e($plan['teacher_name'] ?: '—'); ?></div>
            <div class="col-md-3"><b>Chapter:</b> <?php echo lp_e($plan['chapter'] ?: '—'); ?></div>
            <div class="col-md-3"><b>Date:</b> <?php echo $plan['lesson_date'] ? lp_e(date('d M Y', strtotime($plan['lesson_date']))) : '—'; ?></div>
            <div class="col-md-3"><b>Duration:</b> <?php echo $plan['duration_minutes'] ? (int) $plan['duration_minutes'] . ' min' : '—'; ?></div>
        </div>
    </div>

    <div class="card-custom mb-3">
        <?php foreach (lp_text_fields() as $col => $f): $v = trim((string) ($plan[$col] ?? '')); ?>
            <div class="mb-3">
                <div class="fw-bold small text-uppercase text-secondary"><?php echo lp_e($f[0]); ?></div>
                <div><?php echo $v !== '' ? nl2br(lp_e($v)) : '<span class="text-muted">—</span>'; ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (!$is_teacher_ctx && $plan['status'] !== 'Draft'): ?>
    <div class="card-custom">
        <h6 class="fw-bold mb-2">অনুমোদন / বাতিল</h6>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="lp_id" value="<?php echo (int) $plan['id']; ?>">
            <textarea name="admin_remarks" rows="2" class="form-control form-control-sm mb-2"
                      placeholder="মন্তব্য (বাতিল করলে বাধ্যতামূলক — টিচার এটা দেখতে পাবে)"><?php echo lp_e($plan['admin_remarks'] ?? ''); ?></textarea>
            <button type="submit" name="approve_lp" class="btn btn-sm btn-success" onclick="return confirm('অনুমোদন করলে এটা স্টুডেন্ট প্যানেলে দেখা যাবে। নিশ্চিত?');"><i class="fa-solid fa-check me-1"></i> Approve</button>
            <button type="submit" name="reject_lp" class="btn btn-sm btn-danger"><i class="fa-solid fa-xmark me-1"></i> Reject</button>
        </form>
    </div>
    <?php endif; ?>
    <?php
    return;
}

// =================================================================
// action=form — তৈরি / এডিট ফর্ম
// =================================================================
if ($lp_action === 'form') {
    $lp_edit = null;
    if ($lp_get_id) {
        $lp_edit = lp_load_one($pdo, $lp_select_sql, $lp_get_id, $is_teacher_ctx, $me_teacher_id);
        if (!$lp_edit) {
            echo '<div class="alert alert-danger">Lesson Plan পাওয়া যায়নি।</div>';
            return;
        }
    }
    $section_query = $pdo->prepare("SELECT id, class_id, section_name FROM academic_sections WHERE status='Active'" . ($lp_branch !== null ? (branch_multi_branch_mode($pdo) ? ' AND branch_id=?' : ' AND (branch_id=? OR branch_id IS NULL)') : ''));
    $section_query->execute($lp_branch !== null ? [$lp_branch] : []);
    $all_sections = $section_query->fetchAll();
    if ($is_teacher_ctx) {
        $all_sub_ids = $lp_allowed_map ? array_unique(array_merge(...array_values($lp_allowed_map))) : [];
        $subs = [];
        if ($all_sub_ids) {
            $in = implode(',', array_fill(0, count($all_sub_ids), '?'));
            $sub_stmt = $pdo->prepare("SELECT id, class_id, subject_name FROM academic_subjects WHERE id IN ($in)");
            $sub_stmt->execute($all_sub_ids);
            $subs = $sub_stmt->fetchAll();
        }
    } else {
        $subs = $pdo->query("SELECT id, class_id, subject_name FROM academic_subjects WHERE status='Active'")->fetchAll();
    }
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0"><?php echo $lp_edit ? 'Lesson Plan এডিট করুন' : 'নতুন Lesson Plan'; ?></h5>
        <a href="<?php echo $lp_base; ?>" class="btn btn-sm btn-secondary"><i class="fa-solid fa-arrow-left me-1"></i> ফিরে যান</a>
    </div>

    <?php if ($lp_err): ?><div class="alert alert-danger py-2"><?php echo lp_e($lp_err); ?></div><?php endif; ?>
    <?php if ($is_teacher_ctx && $lp_edit && $lp_edit['status'] === 'Approved'): ?>
        <div class="alert alert-warning py-2 small">এটা অনুমোদিত প্ল্যান। এডিট করে সেভ করলে আবার অনুমোদন লাগবে, এবং ততক্ষণ স্টুডেন্ট প্যানেলে দেখা যাবে না।</div>
    <?php endif; ?>
    <?php if ($lp_edit && $lp_edit['status'] === 'Rejected' && trim((string) $lp_edit['admin_remarks']) !== ''): ?>
        <div class="alert alert-danger py-2 small"><b>অ্যাডমিনের মন্তব্য:</b> <?php echo nl2br(lp_e($lp_edit['admin_remarks'])); ?></div>
    <?php endif; ?>

    <form method="POST" class="card-custom">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="lp_id" value="<?php echo (int) ($lp_edit['id'] ?? 0); ?>">

        <div class="row g-2">
            <div class="col-md-3">
                <label class="form-label small fw-bold">Class</label>
                <select name="class_id" id="lpClassSelect" class="form-select form-select-sm" required>
                    <option value="">-- নির্বাচন করুন --</option>
                    <?php foreach ($lp_classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo (($lp_edit['class_id'] ?? 0) == $c['id']) ? 'selected' : ''; ?>><?php echo lp_e($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Section (খালি = সব সেকশন)</label>
                <select name="section_id" id="lpSectionSelect" class="form-select form-select-sm">
                    <option value="">-- সব সেকশন --</option>
                    <?php foreach ($all_sections as $sec):
                        if ($is_teacher_ctx && !in_array((int) $sec['class_id'], $lp_allowed_class_ids, true)) { continue; } ?>
                        <option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" <?php echo (($lp_edit['section_id'] ?? null) == $sec['id']) ? 'selected' : ''; ?>><?php echo lp_e($sec['section_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Subject</label>
                <select name="subject_id" id="lpSubjectSelect" class="form-select form-select-sm" required>
                    <option value="">-- আগে Class সিলেক্ট করুন --</option>
                    <?php foreach ($subs as $sub):
                        if ($is_teacher_ctx && !in_array((int) $sub['id'], $lp_allowed_map[(int) $sub['class_id']] ?? [], true)) { continue; } ?>
                        <option value="<?php echo (int) $sub['id']; ?>" data-class="<?php echo (int) $sub['class_id']; ?>" <?php echo (($lp_edit['subject_id'] ?? null) == $sub['id']) ? 'selected' : ''; ?>><?php echo lp_e($sub['subject_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!$is_teacher_ctx): ?>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Teacher</label>
                <select name="teacher_id" class="form-select form-select-sm">
                    <option value="">-- নির্বাচন করুন --</option>
                    <?php foreach ($lp_teachers as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php echo (($lp_edit['teacher_id'] ?? 0) == $t['id']) ? 'selected' : ''; ?>><?php echo lp_e($t['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="col-md-5">
                <label class="form-label small fw-bold">Lesson Title / Topic</label>
                <input type="text" name="title" maxlength="200" class="form-control form-control-sm" required value="<?php echo lp_e($lp_edit['title'] ?? ''); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Chapter (ঐচ্ছিক)</label>
                <input type="text" name="chapter" maxlength="200" class="form-control form-control-sm" value="<?php echo lp_e($lp_edit['chapter'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Lesson Date</label>
                <input type="date" name="lesson_date" class="form-control form-control-sm" required value="<?php echo lp_e($lp_edit['lesson_date'] ?? date('Y-m-d')); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Duration (মিনিট)</label>
                <input type="number" name="duration_minutes" min="1" max="600" class="form-control form-control-sm" value="<?php echo lp_e($lp_edit['duration_minutes'] ?? ''); ?>">
            </div>

            <?php foreach (lp_text_fields() as $col => $f): ?>
            <div class="col-md-6">
                <label class="form-label small fw-bold"><?php echo lp_e($f[0]); ?></label>
                <textarea name="<?php echo $col; ?>" rows="4" maxlength="5000" class="form-control form-control-sm" placeholder="<?php echo lp_e($f[2]); ?>"><?php echo lp_e($lp_edit[$col] ?? ''); ?></textarea>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-3 d-flex gap-2 flex-wrap">
            <?php if ($is_teacher_ctx): ?>
                <button type="submit" name="save_submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-paper-plane me-1"></i> Submit for Approval</button>
                <button type="submit" name="save_draft" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-floppy-disk me-1"></i> Save as Draft</button>
            <?php else: ?>
                <button type="submit" name="save_draft" class="btn btn-sm btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i> <?php echo $lp_edit ? 'আপডেট করুন' : 'সেভ ও অনুমোদন করুন'; ?></button>
            <?php endif; ?>
            <a href="<?php echo $lp_base; ?>" class="btn btn-sm btn-secondary">Cancel</a>
        </div>
    </form>

    <script>
    // Class সিলেক্ট করলে Section ও Subject-এ শুধু সেই Class-এর অপশন দেখানো (homework.php এর মতোই)
    function lpFilterByClass() {
        const classId = document.getElementById('lpClassSelect').value;
        ['lpSectionSelect', 'lpSubjectSelect'].forEach(function (selId) {
            const select = document.getElementById(selId);
            select.disabled = !classId;
            Array.from(select.options).forEach(function (opt) {
                if (!opt.value) { opt.hidden = false; return; }
                opt.hidden = !classId || opt.dataset.class !== classId;
            });
            if (select.selectedOptions[0] && select.selectedOptions[0].hidden) { select.value = ''; }
        });
    }
    document.getElementById('lpClassSelect').addEventListener('change', lpFilterByClass);
    lpFilterByClass();
    </script>
    <?php
    return;
}

// =================================================================
// ডিফল্ট: লিস্ট
// =================================================================
$lp_filter = $_GET['status'] ?? ($is_teacher_ctx ? 'all' : 'Pending');
$lp_valid_filters = $is_teacher_ctx ? ['all', 'Draft', 'Pending', 'Approved', 'Rejected'] : ['all', 'Pending', 'Approved', 'Rejected'];
if (!in_array($lp_filter, $lp_valid_filters, true)) { $lp_filter = 'all'; }

[$base_where, $base_params] = lp_plan_scope($pdo, $lp_role);

// ট্যাব কাউন্ট
$cnt_stmt = $pdo->prepare("SELECT p.status, COUNT(*) AS n FROM elearning_lesson_plans p WHERE $base_where GROUP BY p.status");
$cnt_stmt->execute($base_params);
$lp_counts = ['Draft' => 0, 'Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
foreach ($cnt_stmt->fetchAll() as $r) { $lp_counts[$r['status']] = (int) $r['n']; }
$lp_counts['all'] = array_sum($lp_counts);

$list_sql = $lp_select_sql . " WHERE $base_where";
$list_params = $base_params;
if ($lp_filter !== 'all') { $list_sql .= " AND p.status = ?"; $list_params[] = $lp_filter; }
$list_sql .= " ORDER BY p.lesson_date DESC, p.id DESC LIMIT 300";
$stmt = $pdo->prepare($list_sql);
$stmt->execute($list_params);
$lp_list = $stmt->fetchAll();

$tab_labels = ['all' => 'সব', 'Draft' => 'ড্রাফট', 'Pending' => 'Pending', 'Approved' => 'Approved', 'Rejected' => 'Rejected'];
?>
<?php if ($lp_msg): ?><div class="alert alert-success py-2"><?php echo lp_e($lp_msg); ?></div><?php endif; ?>
<?php if ($lp_err): ?><div class="alert alert-danger py-2"><?php echo lp_e($lp_err); ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="fw-bold mb-0"><i class="fa-solid fa-book me-2"></i>Lesson Plan</h5>
    <a href="<?php echo $lp_base; ?>&action=form" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus me-1"></i> নতুন Lesson Plan</a>
</div>

<ul class="nav nav-pills mb-3 gap-1">
    <?php foreach ($lp_valid_filters as $k): ?>
        <li class="nav-item">
            <a class="nav-link py-1 px-3 <?php echo $lp_filter === $k ? 'active' : 'bg-white border'; ?>" href="<?php echo $lp_base; ?>&status=<?php echo urlencode($k); ?>">
                <?php echo lp_e($tab_labels[$k]); ?> <span class="badge bg-secondary ms-1"><?php echo (int) $lp_counts[$k]; ?></span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="card-custom">
    <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle">
            <thead class="table-light">
                <tr><th>Date</th><th>Title</th><th>Class / Section</th><th>Subject</th><?php if (!$is_teacher_ctx): ?><th>Teacher</th><?php endif; ?><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php foreach ($lp_list as $p): $m = lp_status_meta($p['status']); ?>
                <tr>
                    <td class="text-nowrap"><?php echo $p['lesson_date'] ? lp_e(date('d M Y', strtotime($p['lesson_date']))) : '—'; ?></td>
                    <td><a href="<?php echo $lp_base; ?>&action=view&id=<?php echo (int) $p['id']; ?>"><?php echo lp_e($p['title']); ?></a></td>
                    <td><?php echo lp_e($p['class_name']); ?><?php echo $p['section_name'] ? ' / ' . lp_e($p['section_name']) : ' (সব)'; ?></td>
                    <td><?php echo lp_e($p['subject_name']); ?></td>
                    <?php if (!$is_teacher_ctx): ?><td><?php echo lp_e($p['teacher_name'] ?: '—'); ?></td><?php endif; ?>
                    <td><span class="badge <?php echo $m['badge']; ?>"><?php echo lp_e($m['label']); ?></span></td>
                    <td class="text-nowrap">
                        <a href="<?php echo $lp_base; ?>&action=view&id=<?php echo (int) $p['id']; ?>" class="btn btn-sm btn-outline-info" title="দেখুন<?php echo $is_teacher_ctx ? '' : ' / অনুমোদন'; ?>"><i class="fa-solid fa-eye"></i></a>
                        <?php if (!$is_teacher_ctx || $p['status'] !== 'Pending'): ?>
                            <a href="<?php echo $lp_base; ?>&action=form&id=<?php echo (int) $p['id']; ?>" class="btn btn-sm btn-outline-primary" title="এডিট"><i class="fa-solid fa-pen"></i></a>
                        <?php endif; ?>
                        <a href="../lesson_plan_print.php?id=<?php echo (int) $p['id']; ?>&amp;portal=<?php echo $lp_role; ?>" target="_blank" class="btn btn-sm btn-outline-dark" title="প্রিন্ট"><i class="fa-solid fa-print"></i></a>
                        <?php if (!$is_teacher_ctx || $p['status'] !== 'Approved'): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('আপনি কি নিশ্চিত এটা ডিলিট করতে চান?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="lp_id" value="<?php echo (int) $p['id']; ?>">
                            <button type="submit" name="delete_lp" class="btn btn-sm btn-outline-danger" title="ডিলিট"><i class="fa-solid fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$lp_list): ?>
                <tr><td colspan="<?php echo $is_teacher_ctx ? 6 : 7; ?>" class="text-center text-muted py-3">কোনো Lesson Plan পাওয়া যায়নি।</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
