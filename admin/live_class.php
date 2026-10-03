<?php
// admin/live_class.php - E-Learning Module > Live Class
// একই প্যাটার্নে homework.php এর মতো — Admin + Teacher Portal দুই জায়গা থেকেই কাজ করে।
// Class/Section/Subject হায়ারার্কির উপর বসানো — Batch/Course/Payment কনসেপ্ট নেই।

require_once __DIR__ . '/../config/live_class_100ms.php';

if (!function_exists('lcx_e')) {
    function lcx_e($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS elearning_live_classes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        section_id INT NULL,
        subject_id INT NOT NULL,
        teacher_id INT NULL,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        scheduled_at DATETIME NOT NULL,
        duration_minutes INT NOT NULL DEFAULT 40,
        room_id VARCHAR(255) NULL,
        room_error VARCHAR(500) NULL,
        status ENUM('Scheduled','Live','Ended','Cancelled') NOT NULL DEFAULT 'Scheduled',
        branch_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_class_section (class_id, section_id),
        KEY idx_subject (subject_id),
        KEY idx_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
// পুরনো ডাটাবেজে (branch ফিচার আসার আগে তৈরি হওয়া) elearning_live_classes টেবিলে branch_id
// কলাম না-ও থাকতে পারে — নিচের লিস্ট কোয়েরিতে branch ফিল্টার লাগে, তাই এখানেই নিশ্চিত করা হচ্ছে
branch_ensure_column($pdo, 'elearning_live_classes');
lc_ensure_settings_table($pdo);

$is_teacher_ctx = !empty($_SESSION['teacher_logged']);
$me_teacher_id  = $is_teacher_ctx ? (int) ($_SESSION['teacher_id'] ?? 0) : 0;

function lcx_teacher_class_subjects(PDO $pdo, int $teacher_id): array
{
    $stmt = $pdo->prepare(
        // Routine-এ যে ক্লাস/সাবজেক্টে টিচারকে দেওয়া আছে সেগুলোও ধরা হয় — অর্থাৎ
        // Result > Assign Subject Teacher পেজে আলাদা করে এসাইন না করলেও, Routine-এ
        // টিচার সেট করা থাকলেই এখানে সেই ক্লাস/সাবজেক্ট পাওয়া যাবে
        // (result_marks_entry_subject.php-ও ঠিক এই একই নিয়মে চলে)।
        "SELECT class_id, subject_id FROM teacher_subject_assignments WHERE employee_id = ?
         UNION
         SELECT class_id, subject_id FROM routines WHERE teacher_id = ? AND status = 'Active' AND class_id IS NOT NULL"
    );
    $stmt->execute([$teacher_id, $teacher_id]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) { $map[(int) $row['class_id']][] = (int) $row['subject_id']; }
    return $map;
}
$lcx_allowed_map = $is_teacher_ctx ? lcx_teacher_class_subjects($pdo, $me_teacher_id) : [];
$lcx_allowed_class_ids = array_keys($lcx_allowed_map);

$lcx_err = $_SESSION['lcx_err'] ?? '';
$lcx_msg = $_SESSION['lcx_msg'] ?? '';
unset($_SESSION['lcx_err'], $_SESSION['lcx_msg']);

// -----------------------------------------------------------------
// POST হ্যান্ডলার
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['lcx_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=live_class');
        exit();
    }

    if (isset($_POST['save_live_class'])) {
        $lc_id       = (int) ($_POST['lc_id'] ?? 0);
        $class_id    = (int) ($_POST['class_id'] ?? 0);
        $section_id  = (int) ($_POST['section_id'] ?? 0) ?: null;
        $subject_id  = (int) ($_POST['subject_id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $scheduled_at = trim($_POST['scheduled_at'] ?? '');
        $duration    = max(5, (int) ($_POST['duration_minutes'] ?? 40));

        $valid = $class_id && $subject_id && $title !== '' && $scheduled_at !== '';
        if ($valid && $is_teacher_ctx) {
            if (!isset($lcx_allowed_map[$class_id]) || !in_array($subject_id, $lcx_allowed_map[$class_id], true)) {
                $valid = false;
                $_SESSION['lcx_err'] = 'আপনি এই Class/Subject এর জন্য Assign করা নন।';
            }
        }

        if (!$valid) {
            if (empty($_SESSION['lcx_err'])) { $_SESSION['lcx_err'] = 'Class, Subject, Title ও সময় অবশ্যই দিতে হবে।'; }
        } else {
            $teacher_id_to_save = $is_teacher_ctx ? $me_teacher_id : ((int) ($_POST['teacher_id'] ?? 0) ?: null);

            if ($lc_id) {
                $sql = "UPDATE elearning_live_classes SET class_id=?, section_id=?, subject_id=?, title=?, description=?, scheduled_at=?, duration_minutes=? WHERE id=?";
                $params = [$class_id, $section_id, $subject_id, $title, $description, $scheduled_at, $duration, $lc_id];
                if ($is_teacher_ctx) { $sql .= " AND teacher_id=?"; $params[] = $me_teacher_id; }
                $pdo->prepare($sql)->execute($params);
                $_SESSION['lcx_msg'] = 'লাইভ ক্লাস আপডেট হয়েছে।';
                sec_log_activity($pdo, 'Live Class', 'Update', "লাইভ ক্লাস আপডেট করা হয়েছে: {$title}");
            } else {
                $pdo->prepare(
                    "INSERT INTO elearning_live_classes (class_id, section_id, subject_id, teacher_id, title, description, scheduled_at, duration_minutes, branch_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([$class_id, $section_id, $subject_id, $teacher_id_to_save, $title, $description, $scheduled_at, $duration, branch_id_for_insert()]);
                $new_id = (int) $pdo->lastInsertId();

                // 100ms এ রুম তৈরি করার চেষ্টা — ব্যর্থ হলেও ক্লাসটা সেভ থাকবে, শুধু room_error দেখানো হবে
                // (Host পেজে ঢোকার সময় আবার চেষ্টা করা যায়)
                $room = lc_create_room($pdo, ['id' => $new_id, 'title' => $title]);
                if ($room['ok']) {
                    $pdo->prepare("UPDATE elearning_live_classes SET room_id=?, room_error=NULL WHERE id=?")->execute([$room['room_id'], $new_id]);
                } else {
                    $pdo->prepare("UPDATE elearning_live_classes SET room_error=? WHERE id=?")->execute([$room['error'], $new_id]);
                }
                $_SESSION['lcx_msg'] = $room['ok'] ? 'লাইভ ক্লাস তৈরি হয়েছে।' : ('লাইভ ক্লাস সেভ হয়েছে, কিন্তু রুম তৈরিতে সমস্যা: ' . $room['error']);
                sec_log_activity($pdo, 'Live Class', 'Create', "নতুন লাইভ ক্লাস তৈরি করা হয়েছে: {$title}");
            }
        }
        header('Location: index.php?page=live_class');
        exit();
    }

    if (isset($_POST['delete_live_class'])) {
        $lc_id = (int) ($_POST['lc_id'] ?? 0);
        $del_lc_title_row = $pdo->prepare("SELECT title FROM elearning_live_classes WHERE id = ?");
        $del_lc_title_row->execute([$lc_id]);
        $del_lc_title = $del_lc_title_row->fetchColumn();
        $sql = "DELETE FROM elearning_live_classes WHERE id=?";
        $params = [$lc_id];
        if ($is_teacher_ctx) { $sql .= " AND teacher_id=?"; $params[] = $me_teacher_id; }
        $pdo->prepare($sql)->execute($params);
        $_SESSION['lcx_msg'] = 'লাইভ ক্লাসটি মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Live Class', 'Delete', "লাইভ ক্লাস মুছে ফেলা হয়েছে: " . ($del_lc_title ?: "#$lc_id"));
        header('Location: index.php?page=live_class');
        exit();
    }

    if (isset($_POST['set_status'])) {
        $lc_id = (int) ($_POST['lc_id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['Scheduled', 'Live', 'Ended', 'Cancelled'], true) ? $_POST['status'] : 'Scheduled';
        $sql = "UPDATE elearning_live_classes SET status=? WHERE id=?";
        $params = [$status, $lc_id];
        if ($is_teacher_ctx) { $sql .= " AND teacher_id=?"; $params[] = $me_teacher_id; }
        $pdo->prepare($sql)->execute($params);
        header('Location: index.php?page=live_class');
        exit();
    }
}

// -----------------------------------------------------------------
// ড্রপডাউন ডাটা
// -----------------------------------------------------------------
if ($is_teacher_ctx) {
    if ($lcx_allowed_class_ids) {
        $in = implode(',', array_fill(0, count($lcx_allowed_class_ids), '?'));
        $stmt = $pdo->prepare("SELECT id, class_name FROM academic_classes WHERE id IN ($in) AND status='Active' ORDER BY rank");
        $stmt->execute($lcx_allowed_class_ids);
        $lcx_classes = $stmt->fetchAll();
    } else { $lcx_classes = []; }
} else {
    $lcx_classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status='Active' ORDER BY rank")->fetchAll();
}
$lcx_teachers = $is_teacher_ctx ? [] : $pdo->query("SELECT id, name FROM employees WHERE type='Teachers' AND status='Active' ORDER BY name")->fetchAll();

$lcx_edit_id = (int) ($_GET['edit'] ?? 0);
$lcx_edit = null;
if ($lcx_edit_id) {
    $sql = "SELECT * FROM elearning_live_classes WHERE id = ?";
    $params = [$lcx_edit_id];
    if ($is_teacher_ctx) { $sql .= " AND teacher_id = ?"; $params[] = $me_teacher_id; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lcx_edit = $stmt->fetch();
}

$list_sql = "SELECT l.*, c.class_name, s.section_name, sub.subject_name
             FROM elearning_live_classes l
             LEFT JOIN academic_classes c ON c.id = l.class_id
             LEFT JOIN academic_sections s ON s.id = l.section_id
             LEFT JOIN academic_subjects sub ON sub.id = l.subject_id";
$list_params = [];
$lcx_bf_where = []; $lcx_bf_params = [];
branch_apply_filter($lcx_bf_where, $lcx_bf_params, 'l.branch_id');
$lcx_conditions = [];
if ($is_teacher_ctx) { $lcx_conditions[] = 'l.teacher_id = ?'; $list_params[] = $me_teacher_id; }
if ($lcx_bf_where) { $lcx_conditions = array_merge($lcx_conditions, $lcx_bf_where); $list_params = array_merge($list_params, $lcx_bf_params); }
if ($lcx_conditions) { $list_sql .= ' WHERE ' . implode(' AND ', $lcx_conditions); }
$list_sql .= " ORDER BY l.scheduled_at DESC";
$stmt = $pdo->prepare($list_sql);
$stmt->execute($list_params);
$lcx_list = $stmt->fetchAll();

$lcx_settings = lc_get_settings($pdo);
$lcx_settings_missing = empty($lcx_settings['hms_access_key']) || empty($lcx_settings['hms_secret']);
?>

<?php if ($lcx_msg): ?><div class="alert alert-success py-2"><?php echo lcx_e($lcx_msg); ?></div><?php endif; ?>
<?php if ($lcx_err): ?><div class="alert alert-danger py-2"><?php echo lcx_e($lcx_err); ?></div><?php endif; ?>
<?php if (!$is_teacher_ctx && $lcx_settings_missing): ?>
    <div class="alert alert-warning py-2">
        100ms এর Access Key/Secret এখনো সেট করা হয়নি, তাই নতুন ক্লাসের রুম তৈরি হবে না।
        <a href="index.php?page=live_class_settings" class="fw-bold">এখনই সেট করুন</a>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-4 mb-3">
        <div class="card-custom">
            <h6 class="fw-bold mb-3"><?php echo $lcx_edit ? 'লাইভ ক্লাস এডিট করুন' : 'নতুন লাইভ ক্লাস শিডিউল করুন'; ?></h6>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="lc_id" value="<?php echo (int) ($lcx_edit['id'] ?? 0); ?>">

                <div class="mb-2">
                    <label class="form-label small fw-bold">Class</label>
                    <select name="class_id" id="lcxClassSelect" class="form-select form-select-sm" required>
                        <option value="">-- নির্বাচন করুন --</option>
                        <?php foreach ($lcx_classes as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>" <?php echo (($lcx_edit['class_id'] ?? 0) == $c['id']) ? 'selected' : ''; ?>>
                                <?php echo lcx_e($c['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold">Section (খালি রাখলে সব সেকশন)</label>
                    <select name="section_id" id="lcxSectionSelect" class="form-select form-select-sm">
                        <option value="">-- সব সেকশন --</option>
                        <?php
                        $all_sections = $pdo->query("SELECT id, class_id, section_name FROM academic_sections WHERE status='Active'")->fetchAll();
                        foreach ($all_sections as $sec) {
                            if ($is_teacher_ctx && !in_array((int) $sec['class_id'], $lcx_allowed_class_ids, true)) { continue; }
                            $sel = ($lcx_edit['section_id'] ?? null) == $sec['id'] ? 'selected' : '';
                            echo '<option value="' . (int) $sec['id'] . '" data-class="' . (int) $sec['class_id'] . '" ' . $sel . '>' . lcx_e($sec['section_name']) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold">Subject</label>
                    <select name="subject_id" id="lcxSubjectSelect" class="form-select form-select-sm" required>
                        <option value="">-- আগে Class সিলেক্ট করুন --</option>
                        <?php
                        if ($is_teacher_ctx) {
                            $all_sub_ids = $lcx_allowed_map ? array_unique(array_merge(...array_values($lcx_allowed_map))) : [];
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
                        foreach ($subs as $sub) {
                            if ($is_teacher_ctx && !in_array((int) $sub['id'], $lcx_allowed_map[(int) $sub['class_id']] ?? [], true)) { continue; }
                            $sel = ($lcx_edit['subject_id'] ?? null) == $sub['id'] ? 'selected' : '';
                            echo '<option value="' . (int) $sub['id'] . '" data-class="' . (int) $sub['class_id'] . '" ' . $sel . '>' . lcx_e($sub['subject_name']) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <?php if (!$is_teacher_ctx): ?>
                <div class="mb-2">
                    <label class="form-label small fw-bold">Assigned Teacher (ঐচ্ছিক)</label>
                    <select name="teacher_id" class="form-select form-select-sm">
                        <option value="">-- নির্বাচন করুন --</option>
                        <?php foreach ($lcx_teachers as $t): ?>
                            <option value="<?php echo (int) $t['id']; ?>" <?php echo (($lcx_edit['teacher_id'] ?? 0) == $t['id']) ? 'selected' : ''; ?>>
                                <?php echo lcx_e($t['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="mb-2">
                    <label class="form-label small fw-bold">Title</label>
                    <input type="text" name="title" class="form-control form-control-sm" required value="<?php echo lcx_e($lcx_edit['title'] ?? ''); ?>">
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold">বিবরণ</label>
                    <textarea name="description" rows="2" class="form-control form-control-sm"><?php echo lcx_e($lcx_edit['description'] ?? ''); ?></textarea>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-8">
                        <label class="form-label small fw-bold">তারিখ ও সময়</label>
                        <input type="datetime-local" name="scheduled_at" class="form-control form-control-sm" required
                               value="<?php echo $lcx_edit && $lcx_edit['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($lcx_edit['scheduled_at'])) : ''; ?>">
                    </div>
                    <div class="col-4">
                        <label class="form-label small fw-bold">সময়কাল (মিনিট)</label>
                        <input type="number" name="duration_minutes" class="form-control form-control-sm" min="5" value="<?php echo (int) ($lcx_edit['duration_minutes'] ?? 40); ?>">
                    </div>
                </div>

                <button type="submit" name="save_live_class" class="btn btn-sm btn-primary w-100">
                    <?php echo $lcx_edit ? 'আপডেট করুন' : 'শিডিউল করুন'; ?>
                </button>
                <?php if ($lcx_edit): ?>
                    <a href="index.php?page=live_class" class="btn btn-sm btn-secondary w-100 mt-1">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="col-lg-8 mb-3">
        <div class="card-custom">
            <h6 class="fw-bold mb-3">লাইভ ক্লাস লিস্ট</h6>
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Title</th><th>Class/Section</th><th>Subject</th><th>সময়</th><th>Status</th><th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lcx_list as $l): ?>
                        <tr>
                            <td><?php echo lcx_e($l['title']); ?></td>
                            <td><?php echo lcx_e($l['class_name']); ?><?php echo $l['section_name'] ? ' / ' . lcx_e($l['section_name']) : ' (সব সেকশন)'; ?></td>
                            <td><?php echo lcx_e($l['subject_name']); ?></td>
                            <td><?php echo lcx_e(date('d M, h:i A', strtotime($l['scheduled_at']))); ?> (<?php echo (int) $l['duration_minutes']; ?> মিনিট)</td>
                            <td>
                                <?php
                                $badge = ['Scheduled' => 'secondary', 'Live' => 'success', 'Ended' => 'dark', 'Cancelled' => 'danger'][$l['status']] ?? 'secondary';
                                echo '<span class="badge bg-' . $badge . '">' . lcx_e($l['status']) . '</span>';
                                if ($l['room_error']) { echo '<div class="small text-danger mt-1">' . lcx_e($l['room_error']) . '</div>'; }
                                ?>
                            </td>
                            <td class="text-nowrap">
                                <?php if ($l['room_id'] && in_array($l['status'], ['Scheduled', 'Live'], true)): ?>
                                    <a href="index.php?page=live_class_host&id=<?php echo (int) $l['id']; ?>" class="btn btn-sm btn-success">Host</a>
                                <?php endif; ?>
                                <a href="index.php?page=live_class&edit=<?php echo (int) $l['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen"></i></a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('আপনি কি নিশ্চিত এটা ডিলিট করতে চান?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="lc_id" value="<?php echo (int) $l['id']; ?>">
                                    <button type="submit" name="delete_live_class" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$lcx_list): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">এখনো কোনো লাইভ ক্লাস শিডিউল করা হয়নি।</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function lcxFilterByClass() {
    const classId = document.getElementById('lcxClassSelect').value;
    ['lcxSectionSelect', 'lcxSubjectSelect'].forEach(function (selId) {
        const select = document.getElementById(selId);
        select.disabled = !classId;
        Array.from(select.options).forEach(function (opt) {
            if (!opt.value) { opt.hidden = false; return; }
            opt.hidden = !classId || opt.dataset.class !== classId;
        });
    });
    const secSel = document.getElementById('lcxSectionSelect');
    if (secSel.selectedOptions[0] && secSel.selectedOptions[0].hidden) { secSel.value = ''; }
    const subSel = document.getElementById('lcxSubjectSelect');
    if (subSel.selectedOptions[0] && subSel.selectedOptions[0].hidden) { subSel.value = ''; }
}
document.getElementById('lcxClassSelect')?.addEventListener('change', lcxFilterByClass);
lcxFilterByClass();
</script>
