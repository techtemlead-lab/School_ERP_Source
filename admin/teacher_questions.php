<?php
// admin/teacher_questions.php - Question Management > Teacher Questions
// -----------------------------------------------------------------
// এই একই ফাইল Admin Panel আর Teacher Portal দুই জায়গা থেকেই include হয় (homework.php / lesson_plan.php-র
// প্যাটার্নে)।
//   Teacher context : নিজের Assign করা Class+Subject-এর জন্য প্রশ্নপত্র (ফাইল এবং/অথবা টাইপ করা প্রশ্ন) আপলোড করে,
//                     Admin অনুমোদন না করা পর্যন্ত এডিট/মুছতে পারে। শুধু নিজের আপলোড দেখে।
//   Admin context   : সব শিক্ষকের আপলোড দেখে, প্রশ্ন পড়ে/ফাইল খুলে Approve বা Reject (মন্তব্যসহ) করে।
// প্রশ্নপত্র গোপনীয় — শিক্ষার্থীরা কখনো দেখে না; ফাইল শুধু teacher_question_file.php দিয়ে (Admin/মালিক শিক্ষক) খোলা যায়।

require_once __DIR__ . '/../config/portal_access.php';
$tq_role = erp_portal_controller_role(['admin', 'teacher']);
erp_portal_require_role($pdo, $tq_role, 'teacher_questions');
require_once __DIR__ . '/../config/teacher_question_helpers.php';
tq_ensure_tables($pdo);

$is_teacher_ctx = $tq_role === 'teacher';
[$tq_write_where, $tq_write_params] = tq_scope($pdo, $tq_role, 'teacher_question_papers');
$me_teacher_id  = $is_teacher_ctx ? (int) ($_SESSION['teacher_id'] ?? 0) : 0;
$tq_admin_name  = $is_teacher_ctx ? '' : (string) ($_SESSION['admin_user'] ?? 'Admin');
$tq_base        = 'index.php?page=teacher_questions';
$tq_root        = __DIR__ . '/..';

if (!function_exists('tq_teacher_class_subjects')) {
    function tq_teacher_class_subjects(PDO $pdo, int $teacher_id): array
    {
        // routines টেবিল (Routine Module থেকে তৈরি হয়) এখনো না থাকলে শুধু Assign Subject Teacher-এর তথ্য দিয়েই চলবে
        $queries = [
            "SELECT class_id, subject_id FROM teacher_subject_assignments WHERE employee_id = ?
             UNION
             SELECT class_id, subject_id FROM routines WHERE teacher_id = ? AND status = 'Active' AND class_id IS NOT NULL",
            "SELECT class_id, subject_id FROM teacher_subject_assignments WHERE employee_id = ?",
        ];
        $rows = [];
        foreach ($queries as $qi => $sql) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($qi === 0 ? [$teacher_id, $teacher_id] : [$teacher_id]);
                $rows = $stmt->fetchAll();
                break;
            } catch (\Throwable $e) { $rows = []; }
        }
        $map = [];
        foreach ($rows as $row) { $map[(int) $row['class_id']][] = (int) $row['subject_id']; }
        return $map;
    }
}
$tq_allowed_map = $is_teacher_ctx ? tq_teacher_class_subjects($pdo, $me_teacher_id) : [];
$tq_allowed_class_ids = array_keys($tq_allowed_map);

$tq_err = $_SESSION['tq_err'] ?? '';
$tq_msg = $_SESSION['tq_msg'] ?? '';
unset($_SESSION['tq_err'], $_SESSION['tq_msg']);

// -----------------------------------------------------------------
// POST
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['tq_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: ' . $tq_base);
        exit();
    }

    // --- Teacher: আপলোড / আপডেট ---
    if ($is_teacher_ctx && isset($_POST['save_question'])) {
        $qid        = (int) ($_POST['q_id'] ?? 0);
        $class_id   = (int) ($_POST['class_id'] ?? 0);
        $subject_id = (int) ($_POST['subject_id'] ?? 0);
        $exam_name  = mb_substr(trim($_POST['exam_name'] ?? ''), 0, 150);
        $title      = mb_substr(trim($_POST['title'] ?? ''), 0, 200);
        $marks      = ($_POST['full_marks'] ?? '') !== '' ? round((float) $_POST['full_marks'], 2) : null;
        $duration   = (int) ($_POST['duration_minutes'] ?? 0);
        $duration   = ($duration > 0 && $duration <= 600) ? $duration : null;
        $date_raw   = trim($_POST['exam_date'] ?? '');
        $exam_date  = null;
        if ($date_raw !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $date_raw);
            if ($d && $d->format('Y-m-d') === $date_raw) { $exam_date = $date_raw; }
        }
        $qtext = mb_substr(trim($_POST['question_text'] ?? ''), 0, 60000);

        $existing = null;
        if ($qid) {
            $st = $pdo->prepare("SELECT * FROM teacher_question_papers WHERE id = ? AND $tq_write_where");
            $st->execute(array_merge([$qid], $tq_write_params));
            $existing = $st->fetch();
        }

        $err = '';
        if ($qid && !$existing) { $err = 'এই প্রশ্নপত্র পাওয়া যায়নি।'; }
        elseif ($existing && $existing['status'] === 'Approved') { $err = 'অনুমোদিত প্রশ্নপত্র এডিট করা যায় না। পরিবর্তন লাগলে অ্যাডমিনের সাথে যোগাযোগ করুন।'; }
        elseif (!$class_id || !$subject_id || $title === '') { $err = 'Class, Subject ও শিরোনাম অবশ্যই দিতে হবে।'; }
        elseif ($marks !== null && ($marks < 0 || $marks > 1000)) { $err = 'পূর্ণমান সঠিক নয়।'; }
        elseif (!isset($tq_allowed_map[$class_id]) || !in_array($subject_id, $tq_allowed_map[$class_id], true)) { $err = 'আপনি এই Class/Subject এর জন্য Assign করা নন।'; }
        else {
            $chk = $pdo->prepare("SELECT 1 FROM academic_subjects WHERE id = ? AND class_id = ?");
            $chk->execute([$subject_id, $class_id]);
            if (!$chk->fetchColumn()) { $err = 'নির্বাচিত Subject এই Class-এর নয়।'; }
        }

        $question_branch = null;
        if ($err === '') {
            try { $question_branch = lp_destination_branch($pdo, 'teacher', $class_id, null, $me_teacher_id); }
            catch (RuntimeException $e) { $err = $e->getMessage(); }
        }
        $upload = ['ok'  => true, 'file_path' => null, 'file_name' => null, 'file_ext' => null, 'error' => null];
        if ($err === '') {
            $upload = tq_handle_upload($_FILES['question_file'] ?? [], $tq_root);
            if (!$upload['ok']) { $err = $upload['error']; }
        }
        if ($err === '' && $qtext === '' && !$upload['file_path'] && !($existing && $existing['file_path'])) {
            $err = 'প্রশ্নপত্রের ফাইল আপলোড করুন অথবা প্রশ্ন টাইপ করে লিখুন।';
            // সদ্য আপলোড হওয়া ফাইল (থাকলে) আর লাগছে না — এখানে সে ক্ষেত্র আসে না, কারণ $upload['file_path'] ফাঁকা
        }

        if ($err !== '') {
            if (!empty($upload['file_path'])) { tq_delete_file($upload['file_path'], $tq_root); }
            $_SESSION['tq_err'] = $err;
            header('Location: ' . $tq_base . '&view=form' . ($qid ? '&id=' . $qid : ''));
            exit();
        }

        if ($existing) {
            $sets = "class_id=?, subject_id=?, exam_name=?, title=?, full_marks=?, duration_minutes=?, exam_date=?, question_text=?,
                     status='Pending', submitted_at=NOW(), admin_remarks=NULL, reviewed_by=NULL, reviewed_at=NULL";
            $params = [$class_id, $subject_id, $exam_name ?: null, $title, $marks, $duration, $exam_date, $qtext ?: null];
            if ($upload['file_path']) {
                $sets .= ", file_path=?, file_name=?, file_ext=?";
                array_push($params, $upload['file_path'], $upload['file_name'], $upload['file_ext']);
            }
            $params[] = $qid;
            $stmt = $pdo->prepare("UPDATE teacher_question_papers SET $sets WHERE id=? AND $tq_write_where AND status <> 'Approved'");
            $stmt->execute(array_merge($params, $tq_write_params));
            if (!$stmt->rowCount()) {
                if ($upload['file_path']) { tq_delete_file($upload['file_path'], $tq_root); }
                $_SESSION['tq_err'] = 'This question paper is already approved or unavailable.';
                header('Location: ' . $tq_base); exit;
            }
            if ($upload['file_path']) { tq_delete_file($existing['file_path'], $tq_root); }
            $_SESSION['tq_msg'] = 'প্রশ্নপত্র আপডেট হয়েছে এবং অনুমোদনের জন্য আবার জমা হয়েছে।';
            sec_log_activity($pdo, 'Question', 'Update', "শিক্ষকের প্রশ্নপত্র আপডেট: {$title}");
        } else {
            $sess = $pdo->query("SELECT id FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetchColumn();
            $pdo->prepare(
                "INSERT INTO teacher_question_papers
                    (branch_id, teacher_id, session_id, class_id, subject_id, exam_name, title, full_marks, duration_minutes, exam_date,
                     question_text, file_path, file_name, file_ext, status, submitted_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())"
            )->execute([$question_branch, $me_teacher_id, $sess ?: null, $class_id, $subject_id, $exam_name ?: null, $title, $marks, $duration, $exam_date,
                        $qtext ?: null, $upload['file_path'], $upload['file_name'], $upload['file_ext']]);
            $_SESSION['tq_msg'] = 'প্রশ্নপত্র জমা হয়েছে — অ্যাডমিন অনুমোদন করলে কনফার্ম হবে।';
            sec_log_activity($pdo, 'Question', 'Create', "শিক্ষকের প্রশ্নপত্র আপলোড: {$title}");
        }
        header('Location: ' . $tq_base);
        exit();
    }

    // --- ডিলিট (Teacher: নিজের অননুমোদিত; Admin: যেকোনো) ---
    if (isset($_POST['delete_question'])) {
        $qid = (int) ($_POST['q_id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM teacher_question_papers WHERE id = ? AND $tq_write_where");
        $st->execute(array_merge([$qid], $tq_write_params));
        $old = $st->fetch();
        if ($old && (!$is_teacher_ctx || ((int) $old['teacher_id'] === $me_teacher_id && $old['status'] !== 'Approved'))) {
            $del = $pdo->prepare("DELETE FROM teacher_question_papers WHERE id = ? AND $tq_write_where" . ($is_teacher_ctx ? " AND status<>'Approved'" : ''));
            $del->execute(array_merge([$qid], $tq_write_params));
            if ($del->rowCount()) { tq_delete_file($old['file_path'], $tq_root); }
            $_SESSION['tq_msg'] = 'প্রশ্নপত্র মুছে ফেলা হয়েছে।';
            sec_log_activity($pdo, 'Question', 'Delete', 'শিক্ষকের প্রশ্নপত্র মুছে ফেলা হয়েছে: ' . $old['title']);
        } else {
            $_SESSION['tq_err'] = 'এই প্রশ্নপত্র আপনি মুছতে পারবেন না।';
        }
        header('Location: ' . $tq_base);
        exit();
    }

    // --- Admin: Approve / Reject ---
    if (!$is_teacher_ctx && (isset($_POST['approve_question']) || isset($_POST['reject_question']))) {
        $qid = (int) ($_POST['q_id'] ?? 0);
        $remarks = mb_substr(trim($_POST['admin_remarks'] ?? ''), 0, 1000);
        $approve = isset($_POST['approve_question']);
        if (!$approve && $remarks === '') {
            $_SESSION['tq_err'] = 'বাতিল করলে শিক্ষকের জন্য একটা মন্তব্য (কেন/কী সংশোধন দরকার) লিখুন।';
            header('Location: ' . $tq_base . '&view=detail&id=' . $qid);
            exit();
        }
        $upd = $pdo->prepare("UPDATE teacher_question_papers SET status=?, admin_remarks=?, reviewed_by=?, reviewed_at=NOW() WHERE id=? AND $tq_write_where");
        $upd->execute(array_merge([$approve ? 'Approved' : 'Rejected', $remarks ?: null, $tq_admin_name, $qid], $tq_write_params));
        $_SESSION['tq_msg'] = $approve ? 'প্রশ্নপত্র অনুমোদিত হয়েছে।' : 'প্রশ্নপত্র বাতিল করে শিক্ষককে ফেরত পাঠানো হয়েছে।';
        sec_log_activity($pdo, 'Question', $approve ? 'Approve' : 'Reject', "প্রশ্নপত্র #{$qid} " . ($approve ? 'অনুমোদিত' : 'বাতিল'));
        header('Location: ' . $tq_base . '&view=detail&id=' . $qid);
        exit();
    }
}

$tq_view = $_GET['view'] ?? 'list';
$tq_id   = (int) ($_GET['id'] ?? 0);

$tq_select = "SELECT q.*, c.class_name, sub.subject_name, e.name AS teacher_name
              FROM teacher_question_papers q
              LEFT JOIN academic_classes c ON c.id = q.class_id
              LEFT JOIN academic_subjects sub ON sub.id = q.subject_id
              LEFT JOIN employees e ON e.id = q.teacher_id";

function tq_load_one(PDO $pdo, string $select, int $id, bool $is_teacher, int $teacher_id): ?array
{
    [$scope, $scope_params] = tq_scope($pdo, $is_teacher ? 'teacher' : 'admin');
    $sql = $select . " WHERE q.id = ? AND $scope"; $params = array_merge([$id], $scope_params);
    $st = $pdo->prepare($sql); $st->execute($params);
    $r = $st->fetch();
    return $r ?: null;
}

// =================================================================
// বিস্তারিত
// =================================================================
if ($tq_view === 'detail' && $tq_id) {
    $q = tq_load_one($pdo, $tq_select, $tq_id, $is_teacher_ctx, $me_teacher_id);
    if (!$q) { echo '<div class="alert alert-danger">প্রশ্নপত্র পাওয়া যায়নি।</div>'; return; }
    $m = tq_status_meta($q['status']);
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="fw-bold mb-0"><?php echo tq_e($q['title']); ?> <span class="badge <?php echo $m['badge']; ?> ms-2"><?php echo tq_e($m['label']); ?></span></h5>
        <div class="d-flex gap-2">
            <?php if ($is_teacher_ctx && $q['status'] !== 'Approved'): ?>
                <a href="<?php echo $tq_base; ?>&view=form&id=<?php echo (int) $q['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen me-1"></i> Edit</a>
            <?php endif; ?>
            <a href="<?php echo $tq_base; ?>" class="btn btn-sm btn-secondary"><i class="fa-solid fa-arrow-left me-1"></i> ফিরে যান</a>
        </div>
    </div>
    <?php if ($tq_msg): ?><div class="alert alert-success py-2"><?php echo tq_e($tq_msg); ?></div><?php endif; ?>
    <?php if ($tq_err): ?><div class="alert alert-danger py-2"><?php echo tq_e($tq_err); ?></div><?php endif; ?>
    <?php if ($is_teacher_ctx && $q['status'] === 'Rejected' && trim((string) $q['admin_remarks']) !== ''): ?>
        <div class="alert alert-danger"><b>অ্যাডমিনের মন্তব্য:</b> <?php echo nl2br(tq_e($q['admin_remarks'])); ?></div>
    <?php endif; ?>

    <div class="card-custom mb-3">
        <div class="row g-2">
            <div class="col-md-3"><b>Class:</b> <?php echo tq_e($q['class_name']); ?></div>
            <div class="col-md-3"><b>Subject:</b> <?php echo tq_e($q['subject_name']); ?></div>
            <div class="col-md-3"><b>Teacher:</b> <?php echo tq_e($q['teacher_name'] ?: '—'); ?></div>
            <div class="col-md-3"><b>পরীক্ষা:</b> <?php echo tq_e($q['exam_name'] ?: '—'); ?></div>
            <div class="col-md-3"><b>পূর্ণমান:</b> <?php echo $q['full_marks'] !== null ? tq_e(rtrim(rtrim(number_format((float) $q['full_marks'], 2, '.', ''), '0'), '.')) : '—'; ?></div>
            <div class="col-md-3"><b>সময়:</b> <?php echo $q['duration_minutes'] ? (int) $q['duration_minutes'] . ' মিনিট' : '—'; ?></div>
            <div class="col-md-3"><b>পরীক্ষার তারিখ:</b> <?php echo $q['exam_date'] ? tq_e(date('d M Y', strtotime($q['exam_date']))) : '—'; ?></div>
            <div class="col-md-3"><b>জমা:</b> <?php echo $q['submitted_at'] ? tq_e(date('d M Y, h:i A', strtotime($q['submitted_at']))) : '—'; ?></div>
        </div>
        <?php if ($q['file_path']): ?>
            <div class="mt-3">
                <i class="fa-solid fa-paperclip me-1"></i> <b><?php echo tq_e($q['file_name']); ?></b>
                <a href="../teacher_question_file.php?portal=<?php echo $tq_role; ?>&amp;id=<?php echo (int) $q['id']; ?>" target="_blank" class="btn btn-sm btn-dark ms-2">খুলুন</a>
                <a href="../teacher_question_file.php?portal=<?php echo $tq_role; ?>&amp;id=<?php echo (int) $q['id']; ?>&download=1" class="btn btn-sm btn-outline-dark">ডাউনলোড</a>
            </div>
        <?php endif; ?>
        <?php if (trim((string) $q['question_text']) !== ''): ?>
            <hr><div class="fw-bold small text-uppercase text-secondary mb-1">প্রশ্ন (টাইপ করা)</div>
            <div style="white-space:pre-wrap;"><?php echo tq_e($q['question_text']); ?></div>
        <?php endif; ?>
    </div>

    <?php if (!$is_teacher_ctx): ?>
    <form method="POST" class="card-custom">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="q_id" value="<?php echo (int) $q['id']; ?>">
        <h6 class="fw-bold mb-2">কনফার্ম / বাতিল</h6>
        <textarea name="admin_remarks" rows="2" maxlength="1000" class="form-control form-control-sm mb-2" placeholder="মন্তব্য (বাতিল করলে বাধ্যতামূলক — শিক্ষক এটা দেখবে)"><?php echo tq_e($q['admin_remarks'] ?? ''); ?></textarea>
        <button type="submit" name="approve_question" class="btn btn-sm btn-success" onclick="return confirm('প্রশ্নপত্রটি অনুমোদন (কনফার্ম) করতে চান?');"><i class="fa-solid fa-check me-1"></i> Approve</button>
        <button type="submit" name="reject_question" class="btn btn-sm btn-danger"><i class="fa-solid fa-xmark me-1"></i> Reject</button>
        <?php if ($q['status'] !== 'Pending'): ?><span class="small text-muted ms-2">বর্তমান সিদ্ধান্ত: <?php echo tq_e($m['label']); ?> (<?php echo tq_e($q['reviewed_by'] ?? ''); ?>) — চাইলে বদলাতে পারেন।</span><?php endif; ?>
    </form>
    <?php endif; ?>
    <?php
    return;
}

// =================================================================
// ফর্ম (শুধু Teacher)
// =================================================================
if ($tq_view === 'form') {
    if (!$is_teacher_ctx) { header('Location: ' . $tq_base); exit(); }
    $edit = null;
    if ($tq_id) {
        $edit = tq_load_one($pdo, $tq_select, $tq_id, true, $me_teacher_id);
        if (!$edit || $edit['status'] === 'Approved') {
            echo '<div class="alert alert-warning">এই প্রশ্নপত্র এডিট করা যাবে না (পাওয়া যায়নি বা অনুমোদিত)।</div>';
            return;
        }
    }
    if ($tq_allowed_class_ids) {
        $in = implode(',', array_fill(0, count($tq_allowed_class_ids), '?'));
        $cs = $pdo->prepare("SELECT id, class_name FROM academic_classes WHERE id IN ($in) AND status='Active' ORDER BY rank");
        $cs->execute($tq_allowed_class_ids);
        $classes = $cs->fetchAll();
        $sub_ids = array_unique(array_merge(...array_values($tq_allowed_map)));
        $in2 = implode(',', array_fill(0, count($sub_ids), '?'));
        $ss = $pdo->prepare("SELECT id, class_id, subject_name FROM academic_subjects WHERE id IN ($in2)");
        $ss->execute($sub_ids);
        $subs = $ss->fetchAll();
    } else { $classes = []; $subs = []; }
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0"><?php echo $edit ? 'প্রশ্নপত্র এডিট' : 'নতুন প্রশ্নপত্র আপলোড'; ?></h5>
        <a href="<?php echo $tq_base; ?>" class="btn btn-sm btn-secondary"><i class="fa-solid fa-arrow-left me-1"></i> ফিরে যান</a>
    </div>
    <?php if ($tq_err): ?><div class="alert alert-danger py-2"><?php echo tq_e($tq_err); ?></div><?php endif; ?>
    <?php if (!$classes): ?><div class="alert alert-warning">আপনাকে কোনো Class/Subject এ Assign করা হয়নি — অ্যাডমিনের সাথে যোগাযোগ করুন।</div><?php endif; ?>
    <?php if ($edit && $edit['status'] === 'Rejected' && trim((string) $edit['admin_remarks']) !== ''): ?>
        <div class="alert alert-danger py-2 small"><b>অ্যাডমিনের মন্তব্য:</b> <?php echo nl2br(tq_e($edit['admin_remarks'])); ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="card-custom">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="q_id" value="<?php echo (int) ($edit['id'] ?? 0); ?>">
        <div class="row g-2">
            <div class="col-md-3">
                <label class="form-label small fw-bold">Class</label>
                <select name="class_id" id="tqClass" class="form-select form-select-sm" required>
                    <option value="">-- নির্বাচন করুন --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo (($edit['class_id'] ?? 0) == $c['id']) ? 'selected' : ''; ?>><?php echo tq_e($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Subject</label>
                <select name="subject_id" id="tqSubject" class="form-select form-select-sm" required>
                    <option value="">-- আগে Class সিলেক্ট করুন --</option>
                    <?php foreach ($subs as $s):
                        if (!in_array((int) $s['id'], $tq_allowed_map[(int) $s['class_id']] ?? [], true)) { continue; } ?>
                        <option value="<?php echo (int) $s['id']; ?>" data-class="<?php echo (int) $s['class_id']; ?>" <?php echo (($edit['subject_id'] ?? 0) == $s['id']) ? 'selected' : ''; ?>><?php echo tq_e($s['subject_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">পরীক্ষার নাম</label>
                <input type="text" name="exam_name" list="tqExamList" maxlength="150" class="form-control form-control-sm" value="<?php echo tq_e($edit['exam_name'] ?? ''); ?>" placeholder="যেমন: অর্ধবার্ষিক পরীক্ষা">
                <datalist id="tqExamList"><option value="প্রথম সাময়িক পরীক্ষা"><option value="অর্ধবার্ষিক পরীক্ষা"><option value="বার্ষিক পরীক্ষা"><option value="ক্লাস টেস্ট"><option value="মডেল টেস্ট"></datalist>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">পরীক্ষার তারিখ</label>
                <input type="date" name="exam_date" class="form-control form-control-sm" value="<?php echo tq_e($edit['exam_date'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold">শিরোনাম</label>
                <input type="text" name="title" maxlength="200" class="form-control form-control-sm" required value="<?php echo tq_e($edit['title'] ?? ''); ?>" placeholder="যেমন: বাংলা ১ম পত্র — অর্ধবার্ষিক ২০২৬">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">পূর্ণমান</label>
                <input type="number" name="full_marks" min="0" max="1000" step="0.5" class="form-control form-control-sm" value="<?php echo tq_e($edit['full_marks'] ?? ''); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">সময় (মিনিট)</label>
                <input type="number" name="duration_minutes" min="1" max="600" class="form-control form-control-sm" value="<?php echo tq_e($edit['duration_minutes'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold">প্রশ্নপত্রের ফাইল (PDF / Word / ছবি, সর্বোচ্চ <?php echo erp_is_demo() ? '2MB' : '10MB'; ?>)</label>
                <input type="file" name="question_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="form-control form-control-sm">
                <?php if (!empty($edit['file_path'])): ?>
                    <div class="small text-muted mt-1">বর্তমান ফাইল: <?php echo tq_e($edit['file_name']); ?> (নতুন দিলে পাল্টে যাবে)</div>
                <?php endif; ?>
            </div>
            <div class="col-12">
                <label class="form-label small fw-bold">অথবা প্রশ্ন সরাসরি টাইপ করুন (ফাইল না থাকলে বাধ্যতামূলক)</label>
                <textarea name="question_text" rows="8" class="form-control form-control-sm"><?php echo tq_e($edit['question_text'] ?? ''); ?></textarea>
            </div>
        </div>
        <div class="alert alert-light border small mt-3 mb-2">জমা দিলে প্রশ্নপত্র "অনুমোদনের অপেক্ষায়" থাকবে। অ্যাডমিন কনফার্ম করলে চূড়ান্ত হবে; প্রশ্ন শিক্ষার্থীরা দেখতে পাবে না।</div>
        <button type="submit" name="save_question" class="btn btn-sm btn-primary"><i class="fa-solid fa-cloud-arrow-up me-1"></i> <?php echo $edit ? 'আপডেট করে জমা দিন' : 'আপলোড করে জমা দিন'; ?></button>
        <a href="<?php echo $tq_base; ?>" class="btn btn-sm btn-secondary">Cancel</a>
    </form>
    <script>
    function tqFilter() {
        var cid = document.getElementById('tqClass').value, sel = document.getElementById('tqSubject');
        sel.disabled = !cid;
        Array.from(sel.options).forEach(function (o) { if (!o.value) { o.hidden = false; return; } o.hidden = !cid || o.dataset.class !== cid; });
        if (sel.selectedOptions[0] && sel.selectedOptions[0].hidden) { sel.value = ''; }
    }
    document.getElementById('tqClass').addEventListener('change', tqFilter); tqFilter();
    </script>
    <?php
    return;
}

// =================================================================
// তালিকা
// =================================================================
$tab = $_GET['status'] ?? ($is_teacher_ctx ? 'all' : 'Pending');
if (!in_array($tab, ['all', 'Pending', 'Approved', 'Rejected'], true)) { $tab = 'all'; }
$f_class = (int) ($_GET['class_id'] ?? 0);

[$base_where, $base_params] = tq_scope($pdo, $tq_role);

$cnt = $pdo->prepare("SELECT q.status, COUNT(*) n FROM teacher_question_papers q WHERE $base_where GROUP BY q.status");
$cnt->execute($base_params);
$counts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
foreach ($cnt->fetchAll() as $r) { $counts[$r['status']] = (int) $r['n']; }
$counts['all'] = array_sum($counts);

$where = $base_where; $params = $base_params;
if ($tab !== 'all') { $where .= " AND q.status = ?"; $params[] = $tab; }
if ($f_class) { $where .= " AND q.class_id = ?"; $params[] = $f_class; }
$st = $pdo->prepare($tq_select . " WHERE $where ORDER BY q.id DESC LIMIT 300");
$st->execute($params);
$list = $st->fetchAll();
$all_classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status='Active' ORDER BY rank")->fetchAll();
$tab_labels = ['all' => 'সব', 'Pending' => 'Pending', 'Approved' => 'Approved', 'Rejected' => 'Rejected'];
?>
<?php if ($tq_msg): ?><div class="alert alert-success py-2"><?php echo tq_e($tq_msg); ?></div><?php endif; ?>
<?php if ($tq_err): ?><div class="alert alert-danger py-2"><?php echo tq_e($tq_err); ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="fw-bold mb-0"><i class="fa-solid fa-file-circle-question me-2"></i>Teacher Questions</h5>
    <?php if ($is_teacher_ctx): ?><a href="<?php echo $tq_base; ?>&view=form" class="btn btn-sm btn-primary"><i class="fa-solid fa-cloud-arrow-up me-1"></i> নতুন প্রশ্নপত্র আপলোড</a><?php endif; ?>
</div>

<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
    <ul class="nav nav-pills gap-1">
        <?php foreach ($tab_labels as $k => $l): ?>
            <li class="nav-item"><a class="nav-link py-1 px-3 <?php echo $tab === $k ? 'active' : 'bg-white border'; ?>" href="<?php echo $tq_base; ?>&status=<?php echo urlencode($k); ?>&class_id=<?php echo $f_class; ?>"><?php echo tq_e($l); ?> <span class="badge bg-secondary ms-1"><?php echo (int) $counts[$k]; ?></span></a></li>
        <?php endforeach; ?>
    </ul>
    <form method="GET" class="d-flex gap-2">
        <input type="hidden" name="page" value="teacher_questions"><input type="hidden" name="status" value="<?php echo tq_e($tab); ?>">
        <select name="class_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">সব ক্লাস</option>
            <?php foreach ($all_classes as $c): if ($is_teacher_ctx && !in_array((int) $c['id'], $tq_allowed_class_ids, true)) { continue; } ?>
                <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo tq_e($c['class_name']); ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="card-custom">
    <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle">
            <thead class="table-light"><tr><th>জমার তারিখ</th><th>শিরোনাম</th><th>পরীক্ষা</th><th>Class</th><th>Subject</th><?php if (!$is_teacher_ctx): ?><th>Teacher</th><?php endif; ?><th>ফাইল</th><th>অবস্থা</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($list as $q): $m = tq_status_meta($q['status']); ?>
                <tr>
                    <td class="text-nowrap"><?php echo $q['submitted_at'] ? tq_e(date('d M Y', strtotime($q['submitted_at']))) : '—'; ?></td>
                    <td><a href="<?php echo $tq_base; ?>&view=detail&id=<?php echo (int) $q['id']; ?>"><?php echo tq_e($q['title']); ?></a></td>
                    <td><?php echo tq_e($q['exam_name'] ?: '—'); ?></td>
                    <td><?php echo tq_e($q['class_name']); ?></td>
                    <td><?php echo tq_e($q['subject_name']); ?></td>
                    <?php if (!$is_teacher_ctx): ?><td><?php echo tq_e($q['teacher_name'] ?: '—'); ?></td><?php endif; ?>
                    <td><?php echo $q['file_path'] ? '<i class="fa-solid fa-paperclip"></i> ' . tq_e(strtoupper((string) $q['file_ext'])) : ($q['question_text'] ? 'টেক্সট' : '—'); ?></td>
                    <td><span class="badge <?php echo $m['badge']; ?>"><?php echo tq_e($m['label']); ?></span></td>
                    <td class="text-nowrap">
                        <a href="<?php echo $tq_base; ?>&view=detail&id=<?php echo (int) $q['id']; ?>" class="btn btn-sm btn-outline-info" title="<?php echo $is_teacher_ctx ? 'দেখুন' : 'দেখুন / কনফার্ম'; ?>"><i class="fa-solid fa-eye"></i></a>
                        <?php if ($is_teacher_ctx && $q['status'] !== 'Approved'): ?>
                            <a href="<?php echo $tq_base; ?>&view=form&id=<?php echo (int) $q['id']; ?>" class="btn btn-sm btn-outline-primary" title="এডিট"><i class="fa-solid fa-pen"></i></a>
                        <?php endif; ?>
                        <?php if (!$is_teacher_ctx || $q['status'] !== 'Approved'): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('আপনি কি নিশ্চিত এটা ডিলিট করতে চান?');">
                            <?php echo csrf_field(); ?><input type="hidden" name="q_id" value="<?php echo (int) $q['id']; ?>">
                            <button type="submit" name="delete_question" class="btn btn-sm btn-outline-danger" title="ডিলিট"><i class="fa-solid fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$list): ?><tr><td colspan="<?php echo $is_teacher_ctx ? 8 : 9; ?>" class="text-center text-muted py-3">কোনো প্রশ্নপত্র নেই।</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
