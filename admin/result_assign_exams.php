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
            // মোট নম্বর যাচাই: একই Class + Term-এর সব Part-এর Full Mark (Exam) যোগ করলে Subject-এর Full Mark ছাড়াতে পারবে না
            // (Written + MCQ + Practical তিনটা মিলে, অথবা একটা/দুটো মিলেও হতে পারে — সমান হওয়া বাধ্যতামূলক না, বেশি হওয়া নিষেধ)
            $oth_stmt = $pdo->prepare("SELECT COALESCE(SUM(full_mark_exam),0) FROM exam_assign_subjects WHERE class_id=? AND term_id=? AND subject_id=? AND part_id<>? AND status='Active'");
            $nm_stmt = $pdo->prepare("SELECT subject_name FROM academic_subjects WHERE id=?");
            $over = [];
            foreach ($subject_ids as $sid0) {
                $sid0 = (int) $sid0;
                $fms = (float) ($_POST['full_mark_subject'][$sid0] ?? 100);
                $fme = (float) ($_POST['full_mark_exam'][$sid0] ?? 0);
                $oth_stmt->execute([$class_id, $term_id, $sid0, $part_id]);
                $oth = (float) $oth_stmt->fetchColumn();
                if ($fme + $oth > $fms + 0.0001) {
                    $nm_stmt->execute([$sid0]);
                    $over[] = ($nm_stmt->fetchColumn() ?: "#$sid0") . ": এই Part {$fme} + অন্য Part {$oth} = " . ($fme + $oth) . " (Full Mark {$fms}-এর বেশি)";
                }
            }
            if ($over) {
                $_SESSION['ae_err'] = 'সেভ হয়নি — Full Mark-এর চেয়ে বেশি নম্বর দেওয়া যাবে না: ' . implode(' | ', $over);
                header('Location: index.php?page=result_assign_exams&view=add&class_id=' . $class_id . '&term_id=' . $term_id . '&part_id=' . $part_id);
                exit();
            }
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
        $sstmt = $pdo->prepare("SELECT id, subject_name, subject_code, full_marks FROM academic_subjects WHERE class_id=? AND status='Active' ORDER BY sort_order ASC, id ASC");
        $sstmt->execute([$sel_class]);
        $subjects = $sstmt->fetchAll();
    }
    if ($sel_class > 0 && $sel_term > 0 && $sel_part > 0) {
        $estmt = $pdo->prepare("SELECT * FROM exam_assign_subjects WHERE class_id=? AND term_id=? AND part_id=?");
        $estmt->execute([$sel_class, $sel_term, $sel_part]);
        foreach ($estmt->fetchAll() as $er) { $existing[(int) $er['subject_id']] = $er; }
    }
    // অন্য Part-এ (একই Class + Term) প্রতিটা Subject-এর যে Full Mark (Exam) আগে সেভ আছে — মোট নম্বর সীমা যাচাইয়ের জন্য
    // ---- অন্য Class থেকে Assign কপি (Subject নাম/কোড মিলিয়ে) ----
    $copy_from = (int) ($_GET['copy_from'] ?? 0);
    $copy_src_list = []; $copy_default = 0; $copy_note = '';
    if ($sel_class > 0 && $sel_term > 0 && $sel_part > 0) {
        $cl = $pdo->prepare("SELECT DISTINCT eas.class_id, c.class_name, c.rank FROM exam_assign_subjects eas JOIN academic_classes c ON c.id = eas.class_id
                             WHERE eas.session_id=? AND eas.term_id=? AND eas.part_id=? AND eas.class_id<>? ORDER BY c.rank ASC");
        $cl->execute([$current_session_id, $sel_term, $sel_part, $sel_class]);
        $copy_src_list = $cl->fetchAll();
        $rk = $pdo->prepare("SELECT rank FROM academic_classes WHERE id=?");
        $rk->execute([$sel_class]);
        $my_rank = (int) $rk->fetchColumn();
        foreach ($copy_src_list as $cs0) { if ((int) $cs0['rank'] < $my_rank) { $copy_default = (int) $cs0['class_id']; } }
        if (!$copy_default && $copy_src_list) { $copy_default = (int) $copy_src_list[0]['class_id']; }
        if ($copy_from > 0 && $subjects) {
            $cs = $pdo->prepare("SELECT eas.*, s.subject_name, s.subject_code FROM exam_assign_subjects eas JOIN academic_subjects s ON s.id = eas.subject_id
                                 WHERE eas.session_id=? AND eas.class_id=? AND eas.term_id=? AND eas.part_id=?");
            $cs->execute([$current_session_id, $copy_from, $sel_term, $sel_part]);
            $by_name = []; $by_code = [];
            foreach ($cs->fetchAll() as $r0) {
                $by_name[mb_strtolower(trim((string) $r0['subject_name']))] = $r0;
                if (trim((string) $r0['subject_code']) !== '') { $by_code[mb_strtolower(trim((string) $r0['subject_code']))] = $r0; }
            }
            $matched = 0; $missed = [];
            foreach ($subjects as $sub0) {
                $k1 = mb_strtolower(trim((string) $sub0['subject_name']));
                $k2 = mb_strtolower(trim((string) $sub0['subject_code']));
                $hit = $by_name[$k1] ?? ($k2 !== '' ? ($by_code[$k2] ?? null) : null);
                if ($hit) { $existing[(int) $sub0['id']] = $hit; $matched++; } else { $missed[] = $sub0['subject_name']; }
            }
            $copy_note = $matched . ' টি Subject এর Assign কপি হয়েছে।' . ($missed ? ' মিলেনি (ডিফল্ট মান বসেছে): ' . implode(', ', $missed) . '।' : '') . ' প্রয়োজনে Edit করে "Create" চাপুন।';
        }
    }
    $others = []; $others_txt = [];
    if ($sel_class > 0 && $sel_term > 0 && $sel_part > 0) {
        $ostmt = $pdo->prepare("SELECT eas.subject_id, eas.full_mark_exam, p.part_name FROM exam_assign_subjects eas LEFT JOIN exam_parts p ON p.id = eas.part_id
                                WHERE eas.class_id=? AND eas.term_id=? AND eas.part_id<>? AND eas.status='Active'");
        $ostmt->execute([$sel_class, $sel_term, $sel_part]);
        foreach ($ostmt->fetchAll() as $orow) {
            $k = (int) $orow['subject_id'];
            $others[$k] = ($others[$k] ?? 0) + (float) $orow['full_mark_exam'];
            $others_txt[$k][] = ($orow['part_name'] ?: 'Part') . ' ' . (float) $orow['full_mark_exam'];
        }
    }
    ?>
    <div class="card-custom">
        <?php if ($ae_err): ?><div class="alert alert-danger py-2"><?php echo aev($ae_err); ?></div><?php endif; ?>
        <?php if ($ae_msg): ?><div class="alert alert-success py-2"><?php echo aev($ae_msg); ?></div><?php endif; ?>

        <a href="index.php?page=result_assign_exams" class="bg-warning bg-opacity-75 p-2 mb-4 d-block text-center" style="width:40px;border-radius:4px;text-decoration:none;" title="Assign List">
            <i class="fa-solid fa-envelope text-white"></i>
        </a>

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

        <?php if ($copy_src_list): ?>
        <form method="GET" action="index.php" class="row g-2 align-items-center justify-content-center mb-3">
            <input type="hidden" name="page" value="result_assign_exams">
            <input type="hidden" name="view" value="add">
            <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
            <input type="hidden" name="term_id" value="<?php echo $sel_term; ?>">
            <input type="hidden" name="part_id" value="<?php echo $sel_part; ?>">
            <div class="col-auto fw-bold">Copy from Class</div>
            <div class="col-auto">
                <select name="copy_from" class="form-select form-select-sm">
                    <?php foreach ($copy_src_list as $cs1): $cid1 = (int) $cs1['class_id']; ?>
                        <option value="<?php echo $cid1; ?>" <?php echo ($copy_from ?: $copy_default) === $cid1 ? 'selected' : ''; ?>><?php echo aev($cs1['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto"><button type="submit" class="btn btn-warning btn-sm fw-bold"><i class="fa-solid fa-copy me-1"></i> Copy</button></div>
        </form>
        <?php if ($copy_note): ?><div class="alert alert-info py-2"><?php echo aev($copy_note); ?></div><?php endif; ?>
        <?php endif; ?>

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
                                $oth_sum = (float) ($others[$sid] ?? 0);
                                $full_mark_exam         = $ex ? $ex['full_mark_exam']        : max(0, (float) $sub['full_marks'] - $oth_sum); // নতুন হলে বাকি নম্বরটুকু ডিফল্ট
                                $pass_mark_exam         = $ex ? $ex['pass_mark_exam']         : round($full_mark_exam * 0.33);
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
                                    <td style="max-width:90px;"><input type="number" step="0.01" min="0" name="full_mark_subject[<?php echo $sid; ?>]" class="form-control form-control-sm ae-fm" data-sid="<?php echo $sid; ?>" value="<?php echo aev($full_mark_subject); ?>"></td>
                                    <td style="max-width:100px;"><input type="number" step="0.01" name="mark_for_term_percent[<?php echo $sid; ?>]" class="form-control form-control-sm" value="<?php echo aev($mark_for_term_percent); ?>"></td>
                                    <td style="max-width:130px;">
                                        <input type="number" step="0.01" min="0" name="full_mark_exam[<?php echo $sid; ?>]" class="form-control form-control-sm ae-fe" data-sid="<?php echo $sid; ?>" data-others="<?php echo aev($oth_sum); ?>" value="<?php echo aev($full_mark_exam); ?>">
                                        <?php if ($oth_sum > 0): ?><div class="small text-muted ae-oth" title="<?php echo aev(implode(', ', $others_txt[$sid] ?? [])); ?>">অন্য Part: <?php echo aev(rtrim(rtrim(number_format($oth_sum, 2, '.', ''), '0'), '.')); ?></div><?php endif; ?>
                                        <div class="small text-danger fw-bold ae-err" style="display:none"></div>
                                    </td>
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
                        <div id="aeTotalWarn" class="alert alert-danger py-2 mb-3" style="display:none"></div>
                        <button type="submit" id="aeSaveBtn" name="save_assign" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Create</button>
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
    $f_class = (int) ($_GET['f_class'] ?? 0);
    $f_term  = (int) ($_GET['f_term'] ?? 0);
    $f_part  = (int) ($_GET['f_part'] ?? 0);
    $f_sql = '';
    $f_params = [];
    if ($f_class > 0) { $f_sql .= ' AND eas.class_id = ?'; $f_params[] = $f_class; }
    if ($f_term > 0)  { $f_sql .= ' AND eas.term_id = ?';  $f_params[] = $f_term; }
    if ($f_part > 0)  { $f_sql .= ' AND eas.part_id = ?';  $f_params[] = $f_part; }
    $sql = "SELECT eas.class_id, eas.term_id, eas.part_id,
                   c.class_name, t.term_name, p.part_name,
                   COUNT(*) AS subject_count
            FROM exam_assign_subjects eas
            LEFT JOIN academic_classes c ON c.id = eas.class_id
            LEFT JOIN exam_terms t ON t.id = eas.term_id
            LEFT JOIN exam_parts p ON p.id = eas.part_id
            INNER JOIN academic_subjects asb ON asb.id = eas.subject_id
            WHERE eas.session_id = ?" . $f_sql . "
            GROUP BY eas.class_id, eas.term_id, eas.part_id
            ORDER BY c.rank ASC, t.rank ASC, p.rank ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$current_session_id], $f_params));
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($ae_msg): ?><div class="alert alert-success py-2"><?php echo aev($ae_msg); ?></div><?php endif; ?>
        <?php if ($ae_err): ?><div class="alert alert-danger py-2"><?php echo aev($ae_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <a href="index.php?page=result_assign_exams&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
            <span class="text-muted small">Showing <?php echo $rows ? 1 : 0; ?>-<?php echo count($rows); ?> of <?php echo count($rows); ?> item.</span>
        </div>

        <form method="GET" action="index.php" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="page" value="result_assign_exams">
            <div class="col-md-3 col-sm-6">
                <label class="form-label small fw-bold mb-1">Class</label>
                <select name="f_class" class="form-select form-select-sm">
                    <option value="0">All</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo aev($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-sm-6">
                <label class="form-label small fw-bold mb-1">Exam Term</label>
                <select name="f_term" class="form-select form-select-sm">
                    <option value="0">All</option>
                    <?php foreach ($terms as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php echo $f_term === (int) $t['id'] ? 'selected' : ''; ?>><?php echo aev($t['term_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-sm-6">
                <label class="form-label small fw-bold mb-1">Exam Part</label>
                <select name="f_part" class="form-select form-select-sm">
                    <option value="0">All</option>
                    <?php foreach ($parts as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php echo $f_part === (int) $p['id'] ? 'selected' : ''; ?>><?php echo aev($p['part_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-sm-6 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm px-3"><i class="fa-solid fa-filter me-1"></i> Filter</button>
                <a href="index.php?page=result_assign_exams" class="btn btn-outline-secondary btn-sm px-3">Reset</a>
            </div>
        </form>

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

        // পেজ রিলোড করে নেওয়া হয়, যাতে "Copy from Class" অপশন ঠিকভাবে আসে
        window.location.href = 'index.php?page=result_assign_exams&view=add&class_id=' + encodeURIComponent(classId) + '&term_id=' + encodeURIComponent(termId) + '&part_id=' + encodeURIComponent(partId);
    }

    elClass.addEventListener('change', loadTable);
    elTerm.addEventListener('change', loadTable);
    elPart.addEventListener('change', loadTable);
})();

// ---- মোট নম্বর যাচাই (লাইভ): এই Part + অন্য Part-এর যোগফল Subject-এর Full Mark-এর বেশি হলে লাল দেখাবে ও Create বন্ধ থাকবে ----
(function () {
    function num(v) { var n = parseFloat(v); return isNaN(n) ? 0 : n; }
    function fmt(n) { return (Math.round(n * 100) / 100).toString(); }
    function checkAll(root) {
        var wrap = document.getElementById('ae_result_wrap') || document;
        var bad = [];
        wrap.querySelectorAll('input.ae-fe').forEach(function (fe) {
            var sid = fe.getAttribute('data-sid'), tr = fe.closest('tr');
            var fm = tr.querySelector('input.ae-fm'), err = tr.querySelector('.ae-err');
            var oth = num(fe.getAttribute('data-others')), mine = num(fe.value), full = num(fm.value);
            var total = mine + oth, over = total > full + 0.0001;
            fe.classList.toggle('is-invalid', over);
            fm.classList.toggle('is-invalid', over);
            if (err) {
                err.style.display = over ? 'block' : 'none';
                err.textContent = over ? ('মোট ' + fmt(total) + ' > Full Mark ' + fmt(full) + (oth > 0 ? ' (অন্য Part ' + fmt(oth) + ' + এই Part ' + fmt(mine) + ')' : '')) : '';
            }
            if (over) { bad.push((tr.cells[0].textContent || '').trim().replace(/\s+/g, ' ') + ' — ' + fmt(total) + ' / ' + fmt(full)); }
        });
        var w = document.getElementById('aeTotalWarn'), btn = document.getElementById('aeSaveBtn');
        if (w) { w.style.display = bad.length ? 'block' : 'none'; w.innerHTML = bad.length ? '<b>Full Mark-এর চেয়ে বেশি নম্বর দেওয়া যাবে না:</b><br>' + bad.map(function (x) { return '• ' + x.replace(/</g, '&lt;'); }).join('<br>') : ''; }
        if (btn) { btn.disabled = bad.length > 0; }
        return bad.length === 0;
    }
    document.addEventListener('input', function (e) { if (e.target.matches && e.target.matches('input.ae-fe, input.ae-fm')) { checkAll(); } });
    document.addEventListener('submit', function (e) { if (e.target.querySelector && e.target.querySelector('input.ae-fe') && !checkAll()) { e.preventDefault(); } });
    // AJAX দিয়ে টেবিল বসলে/পেজ লোডে একবার চেক
    new MutationObserver(function (ms) {
        // শুধু নতুন Subject টেবিল বসলে (নিজের বানানো টেক্সট বদলে নয়) — নইলে অসীম লুপ হয়
        for (var i = 0; i < ms.length; i++) { for (var j = 0; j < ms[i].addedNodes.length; j++) { var n = ms[i].addedNodes[j]; if (n.nodeType === 1 && (n.matches('input.ae-fe') || n.querySelector('input.ae-fe'))) { checkAll(); return; } } }
    }).observe(document.body, {childList: true, subtree: true});
    checkAll();
})();
</script>
