<?php
// admin/recorded_class.php - E-Learning Module > Recorded Class
// একই প্যাটার্নে homework.php/live_class.php এর মতো — Admin + Teacher Portal দুই জায়গা
// থেকেই কাজ করে। YouTube/Vimeo লিংক এমবেড করা হয় (ExonHost শেয়ার্ড হোস্টিং-এ সরাসরি ভিডিও
// আপলোড/স্ট্রিমিং খরচসাপেক্ষ, তাই সরাসরি আপলোড রাখা হয়নি — cadetadmission প্রজেক্টেও একই কারণে
// লিংক-ভিত্তিক রাখা হয়েছিল)।

if (!function_exists('rc_e')) {
    function rc_e($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
}

/** YouTube/Vimeo এর সাধারণ শেয়ার লিংক থেকে embeddable player URL বানায় */
if (!function_exists('rc_to_embed_url')) {
    function rc_to_embed_url(string $url, string $type): string
    {
        $url = trim($url);
        if ($type === 'youtube') {
            if (preg_match('~youtu\.be/([A-Za-z0-9_-]+)~', $url, $m)
                || preg_match('~[?&]v=([A-Za-z0-9_-]+)~', $url, $m)
                || preg_match('~youtube\.com/embed/([A-Za-z0-9_-]+)~', $url, $m)) {
                return 'https://www.youtube.com/embed/' . $m[1];
            }
            return $url;
        }
        if ($type === 'vimeo') {
            if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
                return 'https://player.vimeo.com/video/' . $m[1];
            }
            return $url;
        }
        return $url;
    }
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS elearning_recorded_classes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        section_id INT NULL,
        subject_id INT NOT NULL,
        teacher_id INT NULL,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        video_type ENUM('youtube','vimeo') NOT NULL DEFAULT 'youtube',
        video_url VARCHAR(500) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_class_section (class_id, section_id),
        KEY idx_subject (subject_id),
        KEY idx_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$is_teacher_ctx = !empty($_SESSION['teacher_logged']);
$me_teacher_id  = $is_teacher_ctx ? (int) ($_SESSION['teacher_id'] ?? 0) : 0;

function rc_teacher_class_subjects(PDO $pdo, int $teacher_id): array
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
$rc_allowed_map = $is_teacher_ctx ? rc_teacher_class_subjects($pdo, $me_teacher_id) : [];
$rc_allowed_class_ids = array_keys($rc_allowed_map);

$rc_err = $_SESSION['rc_err'] ?? '';
$rc_msg = $_SESSION['rc_msg'] ?? '';
unset($_SESSION['rc_err'], $_SESSION['rc_msg']);

// -----------------------------------------------------------------
// POST হ্যান্ডলার
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['rc_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=recorded_class');
        exit();
    }

    if (isset($_POST['save_recorded_class'])) {
        $rc_id       = (int) ($_POST['rc_id'] ?? 0);
        $class_id    = (int) ($_POST['class_id'] ?? 0);
        $section_id  = (int) ($_POST['section_id'] ?? 0) ?: null;
        $subject_id  = (int) ($_POST['subject_id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $video_type  = in_array($_POST['video_type'] ?? '', ['youtube', 'vimeo'], true) ? $_POST['video_type'] : 'youtube';
        $video_url   = trim($_POST['video_url'] ?? '');

        $valid = $class_id && $subject_id && $title !== '' && $video_url !== '';
        if ($valid && $is_teacher_ctx) {
            if (!isset($rc_allowed_map[$class_id]) || !in_array($subject_id, $rc_allowed_map[$class_id], true)) {
                $valid = false;
                $_SESSION['rc_err'] = 'আপনি এই Class/Subject এর জন্য Assign করা নন।';
            }
        }

        if (!$valid) {
            if (empty($_SESSION['rc_err'])) { $_SESSION['rc_err'] = 'Class, Subject, Title ও ভিডিও লিংক অবশ্যই দিতে হবে।'; }
        } else {
            $teacher_id_to_save = $is_teacher_ctx ? $me_teacher_id : ((int) ($_POST['teacher_id'] ?? 0) ?: null);

            if ($rc_id) {
                $sql = "UPDATE elearning_recorded_classes SET class_id=?, section_id=?, subject_id=?, title=?, description=?, video_type=?, video_url=? WHERE id=?";
                $params = [$class_id, $section_id, $subject_id, $title, $description, $video_type, $video_url, $rc_id];
                if ($is_teacher_ctx) { $sql .= " AND teacher_id=?"; $params[] = $me_teacher_id; }
                $pdo->prepare($sql)->execute($params);
                $_SESSION['rc_msg'] = 'রেকর্ডেড ক্লাস আপডেট হয়েছে।';
                sec_log_activity($pdo, 'Recorded Class', 'Update', "রেকর্ডেড ক্লাস আপডেট করা হয়েছে: {$title}");
            } else {
                $pdo->prepare(
                    "INSERT INTO elearning_recorded_classes (class_id, section_id, subject_id, teacher_id, title, description, video_type, video_url)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([$class_id, $section_id, $subject_id, $teacher_id_to_save, $title, $description, $video_type, $video_url]);
                $_SESSION['rc_msg'] = 'রেকর্ডেড ক্লাস যোগ হয়েছে।';
                sec_log_activity($pdo, 'Recorded Class', 'Create', "নতুন রেকর্ডেড ক্লাস যোগ করা হয়েছে: {$title}");
            }
        }
        header('Location: index.php?page=recorded_class');
        exit();
    }

    if (isset($_POST['delete_recorded_class'])) {
        $rc_id = (int) ($_POST['rc_id'] ?? 0);
        $del_rc_title_row = $pdo->prepare("SELECT title FROM elearning_recorded_classes WHERE id = ?");
        $del_rc_title_row->execute([$rc_id]);
        $del_rc_title = $del_rc_title_row->fetchColumn();
        $sql = "DELETE FROM elearning_recorded_classes WHERE id=?";
        $params = [$rc_id];
        if ($is_teacher_ctx) { $sql .= " AND teacher_id=?"; $params[] = $me_teacher_id; }
        $pdo->prepare($sql)->execute($params);
        $_SESSION['rc_msg'] = 'রেকর্ডেড ক্লাসটি মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Recorded Class', 'Delete', "রেকর্ডেড ক্লাস মুছে ফেলা হয়েছে: " . ($del_rc_title ?: "#$rc_id"));
        header('Location: index.php?page=recorded_class');
        exit();
    }

    if (isset($_POST['toggle_status'])) {
        $rc_id = (int) ($_POST['rc_id'] ?? 0);
        $sql = "UPDATE elearning_recorded_classes SET status = IF(status='Active','Inactive','Active') WHERE id=?";
        $params = [$rc_id];
        if ($is_teacher_ctx) { $sql .= " AND teacher_id=?"; $params[] = $me_teacher_id; }
        $pdo->prepare($sql)->execute($params);
        header('Location: index.php?page=recorded_class');
        exit();
    }
}

// -----------------------------------------------------------------
// ড্রপডাউন ডাটা
// -----------------------------------------------------------------
if ($is_teacher_ctx) {
    if ($rc_allowed_class_ids) {
        $in = implode(',', array_fill(0, count($rc_allowed_class_ids), '?'));
        $stmt = $pdo->prepare("SELECT id, class_name FROM academic_classes WHERE id IN ($in) AND status='Active' ORDER BY rank");
        $stmt->execute($rc_allowed_class_ids);
        $rc_classes = $stmt->fetchAll();
    } else { $rc_classes = []; }
} else {
    $rc_classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status='Active' ORDER BY rank")->fetchAll();
}
$rc_teachers = $is_teacher_ctx ? [] : $pdo->query("SELECT id, name FROM employees WHERE type='Teachers' AND status='Active' ORDER BY name")->fetchAll();

$rc_edit_id = (int) ($_GET['edit'] ?? 0);
$rc_edit = null;
if ($rc_edit_id) {
    $sql = "SELECT * FROM elearning_recorded_classes WHERE id = ?";
    $params = [$rc_edit_id];
    if ($is_teacher_ctx) { $sql .= " AND teacher_id = ?"; $params[] = $me_teacher_id; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rc_edit = $stmt->fetch();
}

$list_sql = "SELECT r.*, c.class_name, s.section_name, sub.subject_name
             FROM elearning_recorded_classes r
             LEFT JOIN academic_classes c ON c.id = r.class_id
             LEFT JOIN academic_sections s ON s.id = r.section_id
             LEFT JOIN academic_subjects sub ON sub.id = r.subject_id";
$list_params = [];
if ($is_teacher_ctx) { $list_sql .= " WHERE r.teacher_id = ?"; $list_params[] = $me_teacher_id; }
$list_sql .= " ORDER BY r.created_at DESC";
$stmt = $pdo->prepare($list_sql);
$stmt->execute($list_params);
$rc_list = $stmt->fetchAll();
?>

<?php if ($rc_msg): ?><div class="alert alert-success py-2"><?php echo rc_e($rc_msg); ?></div><?php endif; ?>
<?php if ($rc_err): ?><div class="alert alert-danger py-2"><?php echo rc_e($rc_err); ?></div><?php endif; ?>

<div class="row">
    <div class="col-lg-4 mb-3">
        <div class="card-custom">
            <h6 class="fw-bold mb-3"><?php echo $rc_edit ? 'রেকর্ডেড ক্লাস এডিট করুন' : 'নতুন রেকর্ডেড ক্লাস যোগ করুন'; ?></h6>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="rc_id" value="<?php echo (int) ($rc_edit['id'] ?? 0); ?>">

                <div class="mb-2">
                    <label class="form-label small fw-bold">Class</label>
                    <select name="class_id" id="rcClassSelect" class="form-select form-select-sm" required>
                        <option value="">-- নির্বাচন করুন --</option>
                        <?php foreach ($rc_classes as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>" <?php echo (($rc_edit['class_id'] ?? 0) == $c['id']) ? 'selected' : ''; ?>>
                                <?php echo rc_e($c['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold">Section (খালি রাখলে সব সেকশন)</label>
                    <select name="section_id" id="rcSectionSelect" class="form-select form-select-sm">
                        <option value="">-- সব সেকশন --</option>
                        <?php
                        $all_sections = $pdo->query("SELECT id, class_id, section_name FROM academic_sections WHERE status='Active'")->fetchAll();
                        foreach ($all_sections as $sec) {
                            if ($is_teacher_ctx && !in_array((int) $sec['class_id'], $rc_allowed_class_ids, true)) { continue; }
                            $sel = ($rc_edit['section_id'] ?? null) == $sec['id'] ? 'selected' : '';
                            echo '<option value="' . (int) $sec['id'] . '" data-class="' . (int) $sec['class_id'] . '" ' . $sel . '>' . rc_e($sec['section_name']) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold">Subject</label>
                    <select name="subject_id" id="rcSubjectSelect" class="form-select form-select-sm" required>
                        <option value="">-- আগে Class সিলেক্ট করুন --</option>
                        <?php
                        if ($is_teacher_ctx) {
                            $all_sub_ids = $rc_allowed_map ? array_unique(array_merge(...array_values($rc_allowed_map))) : [];
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
                            if ($is_teacher_ctx && !in_array((int) $sub['id'], $rc_allowed_map[(int) $sub['class_id']] ?? [], true)) { continue; }
                            $sel = ($rc_edit['subject_id'] ?? null) == $sub['id'] ? 'selected' : '';
                            echo '<option value="' . (int) $sub['id'] . '" data-class="' . (int) $sub['class_id'] . '" ' . $sel . '>' . rc_e($sub['subject_name']) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <?php if (!$is_teacher_ctx): ?>
                <div class="mb-2">
                    <label class="form-label small fw-bold">Assigned Teacher (ঐচ্ছিক)</label>
                    <select name="teacher_id" class="form-select form-select-sm">
                        <option value="">-- নির্বাচন করুন --</option>
                        <?php foreach ($rc_teachers as $t): ?>
                            <option value="<?php echo (int) $t['id']; ?>" <?php echo (($rc_edit['teacher_id'] ?? 0) == $t['id']) ? 'selected' : ''; ?>>
                                <?php echo rc_e($t['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="mb-2">
                    <label class="form-label small fw-bold">Title</label>
                    <input type="text" name="title" class="form-control form-control-sm" required value="<?php echo rc_e($rc_edit['title'] ?? ''); ?>">
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold">বিবরণ</label>
                    <textarea name="description" rows="2" class="form-control form-control-sm"><?php echo rc_e($rc_edit['description'] ?? ''); ?></textarea>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <label class="form-label small fw-bold">Video Type</label>
                        <select name="video_type" class="form-select form-select-sm">
                            <option value="youtube" <?php echo (($rc_edit['video_type'] ?? '') === 'youtube') ? 'selected' : ''; ?>>YouTube</option>
                            <option value="vimeo" <?php echo (($rc_edit['video_type'] ?? '') === 'vimeo') ? 'selected' : ''; ?>>Vimeo</option>
                        </select>
                    </div>
                    <div class="col-8">
                        <label class="form-label small fw-bold">Video Link</label>
                        <input type="text" name="video_url" class="form-control form-control-sm" placeholder="https://youtu.be/..." required value="<?php echo rc_e($rc_edit['video_url'] ?? ''); ?>">
                    </div>
                </div>

                <button type="submit" name="save_recorded_class" class="btn btn-sm btn-primary w-100">
                    <?php echo $rc_edit ? 'আপডেট করুন' : 'যোগ করুন'; ?>
                </button>
                <?php if ($rc_edit): ?>
                    <a href="index.php?page=recorded_class" class="btn btn-sm btn-secondary w-100 mt-1">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="col-lg-8 mb-3">
        <div class="card-custom">
            <h6 class="fw-bold mb-3">রেকর্ডেড ক্লাস লিস্ট</h6>
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Title</th><th>Class/Section</th><th>Subject</th><th>Video</th><th>Status</th><th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rc_list as $r): ?>
                        <tr>
                            <td><?php echo rc_e($r['title']); ?></td>
                            <td><?php echo rc_e($r['class_name']); ?><?php echo $r['section_name'] ? ' / ' . rc_e($r['section_name']) : ' (সব সেকশন)'; ?></td>
                            <td><?php echo rc_e($r['subject_name']); ?></td>
                            <td><a href="<?php echo rc_e($r['video_url']); ?>" target="_blank"><?php echo ucfirst($r['video_type']); ?> লিংক</a></td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="rc_id" value="<?php echo (int) $r['id']; ?>">
                                    <button type="submit" name="toggle_status" class="btn btn-sm badge <?php echo $r['status'] === 'Active' ? 'bg-success' : 'bg-secondary'; ?> border-0">
                                        <?php echo rc_e($r['status']); ?>
                                    </button>
                                </form>
                            </td>
                            <td class="text-nowrap">
                                <a href="index.php?page=recorded_class&edit=<?php echo (int) $r['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen"></i></a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('আপনি কি নিশ্চিত এটা ডিলিট করতে চান?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="rc_id" value="<?php echo (int) $r['id']; ?>">
                                    <button type="submit" name="delete_recorded_class" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$rc_list): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">এখনো কোনো রেকর্ডেড ক্লাস যোগ করা হয়নি।</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function rcFilterByClass() {
    const classId = document.getElementById('rcClassSelect').value;
    ['rcSectionSelect', 'rcSubjectSelect'].forEach(function (selId) {
        const select = document.getElementById(selId);
        select.disabled = !classId;
        Array.from(select.options).forEach(function (opt) {
            if (!opt.value) { opt.hidden = false; return; }
            opt.hidden = !classId || opt.dataset.class !== classId;
        });
    });
    const secSel = document.getElementById('rcSectionSelect');
    if (secSel.selectedOptions[0] && secSel.selectedOptions[0].hidden) { secSel.value = ''; }
    const subSel = document.getElementById('rcSubjectSelect');
    if (subSel.selectedOptions[0] && subSel.selectedOptions[0].hidden) { subSel.value = ''; }
}
document.getElementById('rcClassSelect')?.addEventListener('change', rcFilterByClass);
rcFilterByClass();
</script>
