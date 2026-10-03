<?php
// admin/result_assign_exams.php - Result Module > Exam Settings > Assign Exams
// স্ক্রিনশট অনুযায়ী: Class + Exam Term + Exam Part সিলেক্ট করলে ঐ ক্লাসের সব Subject লিস্ট আসবে,
// প্রতিটা Subject এর জন্য Full Mark, Mark for Term(%), Full Mark(Exam), Pass Mark(Exam),
// Pass Individual? এবং Fail on Absent? সেট করে একসাথে সেভ করা যাবে।
$ae_err = $_SESSION['ae_err'] ?? '';
$ae_msg = $_SESSION['ae_msg'] ?? '';
unset($_SESSION['ae_err'], $_SESSION['ae_msg']);

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_assign_subjects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        class_id INT NOT NULL,
        term_id INT NOT NULL,
        part_id INT NOT NULL,
        subject_id INT NOT NULL,
        full_mark_subject DECIMAL(6,2) NOT NULL DEFAULT 100,
        mark_for_term_percent DECIMAL(6,2) NOT NULL DEFAULT 100,
        full_mark_exam DECIMAL(6,2) NOT NULL DEFAULT 100,
        pass_mark_exam DECIMAL(6,2) NOT NULL DEFAULT 33,
        pass_individual TINYINT(1) NOT NULL DEFAULT 1,
        fail_on_absent TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_assign (term_id, part_id, subject_id),
        KEY idx_class (class_id), KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

function aev($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// POST Handler - একসাথে সব Subject এর মার্ক সেভ (Insert বা Update)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ae_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_assign_exams');
        exit();
    }

    if (isset($_POST['save_assign'])) {
        $class_id = (int) ($_POST['class_id'] ?? 0);
        $term_id  = (int) ($_POST['term_id'] ?? 0);
        $part_id  = (int) ($_POST['part_id'] ?? 0);
        $subject_ids = $_POST['subject_id'] ?? [];

        if ($class_id <= 0 || $term_id <= 0 || $part_id <= 0 || !$subject_ids) {
            $_SESSION['ae_err'] = 'Class, Exam Term, Exam Part সিলেক্ট করুন এবং অন্তত একটা Subject থাকতে হবে।';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO exam_assign_subjects
                    (session_id, class_id, term_id, part_id, subject_id, full_mark_subject, mark_for_term_percent, full_mark_exam, pass_mark_exam, pass_individual, fail_on_absent, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')
                 ON DUPLICATE KEY UPDATE
                    full_mark_subject=VALUES(full_mark_subject), mark_for_term_percent=VALUES(mark_for_term_percent),
                    full_mark_exam=VALUES(full_mark_exam), pass_mark_exam=VALUES(pass_mark_exam),
                    pass_individual=VALUES(pass_individual), fail_on_absent=VALUES(fail_on_absent), status='Active'"
            );
            foreach ($subject_ids as $sid) {
                $sid = (int) $sid;
                $full_mark_subject = (float) ($_POST['full_mark_subject'][$sid] ?? 100);
                $mark_for_term     = (float) ($_POST['mark_for_term_percent'][$sid] ?? 100);
                $full_mark_exam    = (float) ($_POST['full_mark_exam'][$sid] ?? 100);
                $pass_mark_exam    = (float) ($_POST['pass_mark_exam'][$sid] ?? 33);
                $pass_individual   = (isset($_POST['pass_individual'][$sid]) && $_POST['pass_individual'][$sid] === '1') ? 1 : 0;
                $fail_on_absent    = (isset($_POST['fail_on_absent'][$sid]) && $_POST['fail_on_absent'][$sid] === '1') ? 1 : 0;

                $stmt->execute([
                    $current_session_id, $class_id, $term_id, $part_id, $sid,
                    $full_mark_subject, $mark_for_term, $full_mark_exam, $pass_mark_exam,
                    $pass_individual, $fail_on_absent,
                ]);
            }
            sec_log_activity($pdo, 'Result', 'Update', "ক্লাস আইডি {$class_id}, টার্ম আইডি {$term_id}, পার্ট আইডি {$part_id} এর জন্য Assign Exam সেভ করা হয়েছে");
            $_SESSION['ae_msg'] = 'Assign Exam সফলভাবে সেভ হয়েছে।';
        }
        header('Location: index.php?page=result_assign_exams&view=add&class_id=' . $class_id . '&term_id=' . $term_id . '&part_id=' . $part_id);
        exit();
    } elseif (isset($_POST['delete_assign'])) {
        $class_id = (int) ($_POST['class_id'] ?? 0);
        $term_id  = (int) ($_POST['term_id'] ?? 0);
        $part_id  = (int) ($_POST['part_id'] ?? 0);
        $pdo->prepare("DELETE FROM exam_assign_subjects WHERE class_id=? AND term_id=? AND part_id=?")->execute([$class_id, $term_id, $part_id]);
        sec_log_activity($pdo, 'Result', 'Delete', "ক্লাস আইডি {$class_id}, টার্ম আইডি {$term_id}, পার্ট আইডি {$part_id} এর Assign Exam মুছে ফেলা হয়েছে");
        $_SESSION['ae_msg'] = 'Assign Exam মুছে ফেলা হয়েছে।';
        header('Location: index.php?page=result_assign_exams');
        exit();
    }
}

$view = $_GET['view'] ?? 'list';

$classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status='Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$terms_stmt = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id=? AND status='Active' ORDER BY rank ASC");
$terms_stmt->execute([$current_session_id]);
$terms = $terms_stmt->fetchAll();
$parts_stmt = $pdo->prepare("SELECT id, part_name, main_part_id FROM exam_parts WHERE session_id=? AND status='Active' ORDER BY rank ASC");
$parts_stmt->execute([$current_session_id]);
$parts = $parts_stmt->fetchAll();
?>

<style>
.yn-btn3 { border: none; border-radius: 4px; color: #fff; font-weight: 700; font-size: 12px; padding: 3px 12px; min-width: 50px; }
.yn-yes3 { background-color: #1e3a8a; }
.yn-no3  { background-color: #e0533f; }
</style>

<?php if ($view === 'add'): ?>
    <?php
    $sel_class = (int) ($_GET['class_id'] ?? 0);
    $sel_term  = (int) ($_GET['term_id'] ?? 0);
    $sel_part  = (int) ($_GET['part_id'] ?? 0);

    $subjects = [];
    $existing = [];
    if ($sel_class > 0) {
        $sstmt = $pdo->prepare("SELECT id, subject_name, subject_code, full_marks FROM academic_subjects WHERE class_id=? AND status='Active' ORDER BY subject_name ASC");
        $sstmt->execute([$sel_class]);
        $subjects = $sstmt->fetchAll();
    }
    if ($sel_class > 0 && $sel_term > 0 && $sel_part > 0) {
        $estmt = $pdo->prepare("SELECT * FROM exam_assign_subjects WHERE class_id=? AND term_id=? AND part_id=?");
        $estmt->execute([$sel_class, $sel_term, $sel_part]);
        foreach ($estmt->fetchAll() as $er) { $existing[(int) $er['subject_id']] = $er; }
    }
    ?>
    <div class="card-custom">
        <?php if ($ae_err): ?><div class="alert alert-danger py-2"><?php echo aev($ae_err); ?></div><?php endif; ?>
        <?php if ($ae_msg): ?><div class="alert alert-success py-2"><?php echo aev($ae_msg); ?></div><?php endif; ?>

        <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;">
            <i class="fa-solid fa-envelope text-white"></i>
        </div>

        <form method="GET" action="index.php" class="mb-2">
            <input type="hidden" name="page" value="result_assign_exams">
            <input type="hidden" name="view" value="add">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Class <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="class_id" id="ae_class" class="form-select">
                        <option value="0">-- Select Class --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>" <?php echo $sel_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo aev($c['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Exam Term <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="term_id" id="ae_term" class="form-select">
                        <option value="0">-- Select Exam Term --</option>
                        <?php foreach ($terms as $t): ?>
                            <option value="<?php echo (int) $t['id']; ?>" <?php echo $sel_term === (int) $t['id'] ? 'selected' : ''; ?>><?php echo aev($t['term_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Exam Part <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="part_id" id="ae_part" class="form-select">
                        <option value="0">-- Select Exam Part --</option>
                        <?php foreach ($parts as $p): ?>
                            <option value="<?php echo (int) $p['id']; ?>" <?php echo $sel_part === (int) $p['id'] ? 'selected' : ''; ?>><?php echo aev($p['part_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>

        <div id="ae_result_wrap">
        <?php if ($sel_class <= 0 || $sel_term <= 0 || $sel_part <= 0): ?>
            <div class="text-center text-muted py-4">উপরে Class, Exam Term এবং Exam Part সিলেক্ট করুন — তাহলে ঐ ক্লাসের সব Subject লিস্ট এখানে দেখাবে।</div>
        <?php elseif (!$subjects): ?>
            <div class="text-center text-muted py-4">এই ক্লাসের জন্য কোনো Subject পাওয়া যায়নি। প্রথমে Academic Module &gt; Subject থেকে Subject যোগ করুন।</div>
        <?php else: ?>
            <form method="POST" action="index.php?page=result_assign_exams">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
                <input type="hidden" name="term_id" value="<?php echo $sel_term; ?>">
                <input type="hidden" name="part_id" value="<?php echo $sel_part; ?>">

                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Subject</th>
                                <th>Full Mark</th>
                                <th>Mark for Term (%)</th>
                                <th>Full Mark (Exam)</th>
                                <th>Pass Mark (Exam)</th>
                                <th class="text-center">Pass Individual ?</th>
                                <th class="text-center">Fail on Absent ?</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $sub):
                                $sid = (int) $sub['id'];
                                $ex = $existing[$sid] ?? null;
                                $full_mark_subject     = $ex ? $ex['full_mark_subject']     : $sub['full_marks'];
                                $mark_for_term_percent  = $ex ? $ex['mark_for_term_percent'] : 100;
                                $full_mark_exam         = $ex ? $ex['full_mark_exam']        : $sub['full_marks'];
                                $pass_mark_exam         = $ex ? $ex['pass_mark_exam']         : round($sub['full_marks'] * 0.33);
                                $pass_individual        = $ex ? (int) $ex['pass_individual']  : 1;
                                $fail_on_absent         = $ex ? (int) $ex['fail_on_absent']   : 1;
                                $pi_hid = 'pi_' . $sid; $fa_hid = 'fa_' . $sid;
                            ?>
                                <tr>
                                    <td>
                                        <input type="hidden" name="subject_id[]" value="<?php echo $sid; ?>">
                                        <?php echo aev($sub['subject_name']); ?>
                                        <?php if ($sub['subject_code']): ?><span class="text-muted small">(<?php echo aev($sub['subject_code']); ?>)</span><?php endif; ?>
                                    </td>
                                    <td style="max-width:90px;"><input type="number" step="0.01" name="full_mark_subject[<?php echo $sid; ?>]" class="form-control form-control-sm" value="<?php echo aev($full_mark_subject); ?>"></td>
                                    <td style="max-width:100px;"><input type="number" step="0.01" name="mark_for_term_percent[<?php echo $sid; ?>]" class="form-control form-control-sm" value="<?php echo aev($mark_for_term_percent); ?>"></td>
                                    <td style="max-width:100px;"><input type="number" step="0.01" name="full_mark_exam[<?php echo $sid; ?>]" class="form-control form-control-sm" value="<?php echo aev($full_mark_exam); ?>"></td>
                                    <td style="max-width:100px;"><input type="number" step="0.01" name="pass_mark_exam[<?php echo $sid; ?>]" class="form-control form-control-sm" value="<?php echo aev($pass_mark_exam); ?>"></td>
                                    <td class="text-center">
                                        <input type="hidden" name="pass_individual[<?php echo $sid; ?>]" id="<?php echo $pi_hid; ?>" value="<?php echo $pass_individual ? '1' : '0'; ?>">
                                        <button type="button" class="yn-btn3 <?php echo $pass_individual ? 'yn-yes3' : 'yn-no3'; ?>" data-target="<?php echo $pi_hid; ?>" onclick="ynToggle3(this)"><?php echo $pass_individual ? 'Yes' : 'No'; ?></button>
                                    </td>
                                    <td class="text-center">
                                        <input type="hidden" name="fail_on_absent[<?php echo $sid; ?>]" id="<?php echo $fa_hid; ?>" value="<?php echo $fail_on_absent ? '1' : '0'; ?>">
                                        <button type="button" class="yn-btn3 <?php echo $fail_on_absent ? 'yn-yes3' : 'yn-no3'; ?>" data-target="<?php echo $fa_hid; ?>" onclick="ynToggle3(this)"><?php echo $fail_on_absent ? 'Yes' : 'No'; ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <hr>
                <div class="row">
                    <div class="col-sm-6 offset-sm-3">
                        <button type="submit" name="save_assign" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Create</button>
                        <a href="index.php?page=result_assign_exams" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                    </div>
                </div>
            </form>
        <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <?php
    // ------------------- LIST VIEW: distinct Class + Term + Part combos -------------------
    $sql = "SELECT eas.class_id, eas.term_id, eas.part_id,
                   c.class_name, t.term_name, p.part_name,
                   COUNT(*) AS subject_count
            FROM exam_assign_subjects eas
            LEFT JOIN academic_classes c ON c.id = eas.class_id
            LEFT JOIN exam_terms t ON t.id = eas.term_id
            LEFT JOIN exam_parts p ON p.id = eas.part_id
            WHERE eas.session_id = ?
            GROUP BY eas.class_id, eas.term_id, eas.part_id
            ORDER BY c.rank ASC, t.rank ASC, p.rank ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$current_session_id]);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($ae_msg): ?><div class="alert alert-success py-2"><?php echo aev($ae_msg); ?></div><?php endif; ?>
        <?php if ($ae_err): ?><div class="alert alert-danger py-2"><?php echo aev($ae_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=result_assign_exams&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
            <span class="text-muted small">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> item.</span>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Srl.</th>
                        <th>Class</th>
                        <th>Exam Term</th>
                        <th>Exam Part</th>
                        <th>Subjects Assigned</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo aev($row['class_name']); ?></td>
                            <td><?php echo aev($row['term_name']); ?></td>
                            <td><?php echo aev($row['part_name']); ?></td>
                            <td><span class="badge bg-primary"><?php echo (int) $row['subject_count']; ?></span></td>
                            <td class="text-nowrap">
                                <a href="index.php?page=result_assign_exams&view=add&class_id=<?php echo (int) $row['class_id']; ?>&term_id=<?php echo (int) $row['term_id']; ?>&part_id=<?php echo (int) $row['part_id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <form method="POST" action="index.php?page=result_assign_exams" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="class_id" value="<?php echo (int) $row['class_id']; ?>">
                                    <input type="hidden" name="term_id" value="<?php echo (int) $row['term_id']; ?>">
                                    <input type="hidden" name="part_id" value="<?php echo (int) $row['part_id']; ?>">
                                    <button type="submit" name="delete_assign" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No data found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
function ynToggle3(btn) {
    var hidden = document.getElementById(btn.getAttribute('data-target'));
    var isYes = hidden.value === '1';
    hidden.value = isYes ? '0' : '1';
    btn.classList.toggle('yn-yes3', !isYes);
    btn.classList.toggle('yn-no3', isYes);
    btn.textContent = isYes ? 'No' : 'Yes';
}

(function () {
    var elClass = document.getElementById('ae_class');
    var elTerm  = document.getElementById('ae_term');
    var elPart  = document.getElementById('ae_part');
    var elWrap  = document.getElementById('ae_result_wrap');

    if (!elClass || !elTerm || !elPart || !elWrap) return; // view=add না হলে (List view) এগুলো থাকে না

    function aeAjaxUrl(action, extra) {
        var p = new URLSearchParams(extra || {});
        return 'ajax/result_assign_exams_ajax.php?action=' + action + '&' + p.toString();
    }

    function clearTable() {
        elWrap.innerHTML = '<div class="text-center text-muted py-4">উপরে Class, Exam Term এবং Exam Part সিলেক্ট করুন — তাহলে ঐ ক্লাসের সব Subject লিস্ট এখানে দেখাবে।</div>';
    }

    // তিনটা ফিল্ডই সিলেক্ট হলে Subject টেবিল AJAX দিয়ে লোড হবে (পেজ রিলোড ছাড়াই)
    function loadTable() {
        var classId = elClass.value, termId = elTerm.value, partId = elPart.value;
        if (classId == '0' || termId == '0' || partId == '0') {
            clearTable();
            return;
        }

        elWrap.innerHTML = '<div class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin me-2"></i>লোড হচ্ছে...</div>';

        fetch(aeAjaxUrl('table', { class_id: classId, term_id: termId, part_id: partId }))
            .then(function (r) { return r.text(); })
            .then(function (html) { elWrap.innerHTML = html; })
            .catch(function () {
                elWrap.innerHTML = '<div class="text-center text-danger py-4">ডাটা লোড করতে সমস্যা হয়েছে, আবার চেষ্টা করুন।</div>';
            });
    }

    elClass.addEventListener('change', loadTable);
    elTerm.addEventListener('change', loadTable);
    elPart.addEventListener('change', loadTable);
})();
</script>
