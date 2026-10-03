<?php
// admin/academic_subject.php - Subject List + Add/Edit (স্ক্রিনশট অনুযায়ী)
$sub_err = $_SESSION['sub_err'] ?? '';
$sub_msg = $_SESSION['sub_msg'] ?? '';
unset($_SESSION['sub_err'], $_SESSION['sub_msg']);

// optional_count কলাম না থাকলে (পুরনো ডাটাবেস) যোগ করা
try {
    if (!$pdo->query("SHOW COLUMNS FROM academic_subjects LIKE 'optional_count'")->fetch()) {
        $pdo->exec("ALTER TABLE academic_subjects ADD COLUMN optional_count INT NULL");
    }
} catch (PDOException $e) { /* কলাম থাকলে/চেক ব্যর্থ হলে চুপচাপ এগোনো */ }

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sub_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=academic_subject');
        exit();
    }

    if (isset($_POST['save_subject'])) {
        $id               = (int) ($_POST['id'] ?? 0);
        $class_ids        = array_map('intval', $_POST['class_ids'] ?? []); // Add ভিউতে একাধিক Class সিলেক্ট করা যায়
        $subject_name     = trim($_POST['subject_name'] ?? '');
        $combined_with_id = (int) ($_POST['combined_with_id'] ?? 0);
        $group_name_sel   = trim($_POST['group_name'] ?? '');           // Edit ভিউ: একটামাত্র Group
        // Add ভিউ: Group চেকবক্স (একাধিক)। '__all__' বা কিছু না এলে = সব Group
        $group_names_sel  = array_values(array_unique(array_filter(array_map('trim', (array) ($_POST['group_names'] ?? [])), 'strlen')));
        $group_all        = empty($group_names_sel) || in_array('__all__', $group_names_sel, true);
        $full_marks       = (int) ($_POST['full_marks'] ?? 100);
        $subject_type     = $_POST['subject_type'] ?? 'Compulsory';
        $status           = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
        // Optional subject-এ GP থেকে যত বাদ যাবে (ডিফল্ট ২)। Optional না হলে NULL।
        $optional_deduct  = max(0, min(10, (int) (($_POST['optional_count'] ?? '') !== '' ? $_POST['optional_count'] : 2)));

        // Group প্রতিটা Class-এর নিজস্ব আলাদা row (Nine-এর "Science" আর Ten-এর "Science"
        // আলাদা group_id) — তাই একটামাত্র group_id সব Class-এ বসানো ভুল। এর বদলে Group Name
        // দিয়ে প্রতিটা Class-এর নিজের group_id আলাদাভাবে বের করা হচ্ছে।
        $group_lookup = $pdo->prepare("SELECT id FROM academic_groups WHERE class_id=? AND group_name=? AND status='Active'");
        $resolve_group_id = function (int $cid) use ($group_lookup, $group_name_sel) {
            if ($group_name_sel === '') return null;
            $group_lookup->execute([$cid, $group_name_sel]);
            $gid = $group_lookup->fetchColumn();
            return $gid ? (int) $gid : null;
        };

        if (!in_array($subject_type, ['Compulsory', 'Optional', 'Continuous Assessment'], true)) {
            $subject_type = 'Compulsory';
        }
        $optional_param = $subject_type === 'Optional' ? $optional_deduct : null;

        if (empty($class_ids) || $subject_name === '') {
            $_SESSION['sub_err'] = 'Class Name, Subject Name — এই ফিল্ডগুলো আবশ্যক।';
        } else {
            if ($id > 0) {
                // Edit — শুধু একটা Class-এর সাথেই সম্পর্কিত, তাই প্রথম সিলেক্টেড class_id নেওয়া হচ্ছে
                $class_id = $class_ids[0];
                $combined_param = $combined_with_id > 0 ? $combined_with_id : null;
                $group_param = $resolve_group_id($class_id);
                $stmt = $pdo->prepare(
                    "UPDATE academic_subjects SET class_id=?, subject_name=?, combined_with_id=?, group_id=?, full_marks=?, subject_type=?, optional_count=?, status=? WHERE id=?"
                );
                $stmt->execute([$class_id, $subject_name, $combined_param, $group_param, $full_marks, $subject_type, $optional_param, $status, $id]);
                sec_log_activity($pdo, 'Academic', 'Update', "Subject আপডেট করা হয়েছে: {$subject_name}");
                $_SESSION['sub_msg'] = 'Subject আপডেট করা হয়েছে।';
            } else {
                // Add — একই Subject একাধিক Class-এ একসাথে তৈরি হবে
                // Combined With শুধুমাত্র একটামাত্র Class সিলেক্ট করলেই প্রযোজ্য (একাধিক Class জুড়ে অর্থপূর্ণ না)
                $combined_param = (count($class_ids) === 1 && $combined_with_id > 0) ? $combined_with_id : null;

                // Group Wise Create: যে Class-এ Group আছে (Has Group = Yes) সেখানে "সব Group" সিলেক্ট থাকলে
                // ওই Class-এর প্রতিটা Active Group-এর জন্য আলাদা Subject row তৈরি হয় (Nine/Ten-এর Science,
                // Arts, Commerce সবগুলোতে)। নির্দিষ্ট Group টিক দিলে শুধু সেই নামের Group-এ বসে।
                $has_group_map = [];
                foreach ($pdo->query("SELECT id, has_group FROM academic_classes")->fetchAll() as $hc) {
                    $has_group_map[(int) $hc['id']] = (int) $hc['has_group'];
                }
                $grp_of_class = $pdo->prepare("SELECT id, group_name FROM academic_groups WHERE class_id=? AND status='Active' ORDER BY id ASC");

                // প্রথমে সব (class, group) টার্গেট বের করা হয়
                $targets = [];
                $group_missing = 0;
                foreach (array_unique($class_ids) as $cid) {
                    if ($cid <= 0) continue;
                    if (empty($has_group_map[$cid])) { $targets[] = [$cid, null]; continue; } // Group ছাড়া Class
                    $grp_of_class->execute([$cid]);
                    $cls_groups = $grp_of_class->fetchAll();
                    if (!$cls_groups) { $group_missing++; continue; }
                    if ($group_all) {
                        foreach ($cls_groups as $cg) $targets[] = [$cid, (int) $cg['id']];
                    } else {
                        $found = false;
                        foreach ($cls_groups as $cg) {
                            if (in_array($cg['group_name'], $group_names_sel, true)) { $targets[] = [$cid, (int) $cg['id']]; $found = true; }
                        }
                        if (!$found) $group_missing++;
                    }
                }
                // Combined With শুধু একটামাত্র টার্গেট (এক Class + এক Group) হলেই প্রযোজ্য
                if (count($targets) !== 1) $combined_param = null;

                $chk = $pdo->prepare("SELECT id FROM academic_subjects WHERE class_id=? AND subject_name=? AND (group_id <=> ?)");
                $ins = $pdo->prepare(
                    "INSERT INTO academic_subjects (class_id, subject_name, combined_with_id, group_id, full_marks, subject_type, optional_count, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $created = 0;
                $skipped = 0;
                foreach ($targets as [$cid, $gid]) {
                    $chk->execute([$cid, $subject_name, $gid]);
                    if ($chk->fetch()) { $skipped++; continue; } // আগে থেকেই থাকলে বাদ
                    $ins->execute([$cid, $subject_name, $combined_param, $gid, $full_marks, $subject_type, $optional_param, $status]);
                    $created++;
                }
                if ($created > 0) {
                    sec_log_activity($pdo, 'Academic', 'Create', "নতুন Subject যোগ করা হয়েছে: {$subject_name} ({$created} টি Class/Group-এ)");
                    $_SESSION['sub_msg'] = "{$created}টি Class/Group-এ নতুন Subject যোগ করা হয়েছে।"
                        . ($skipped > 0 ? " ({$skipped}টিতে আগে থেকেই ছিল, বাদ দেওয়া হয়েছে।)" : '')
                        . ($group_missing > 0 ? " ({$group_missing}টি Class-এ মিলে যাওয়া কোনো Group পাওয়া যায়নি, বাদ দেওয়া হয়েছে।)" : '');
                } else {
                    $_SESSION['sub_err'] = $group_missing > 0 && $skipped === 0 ? 'নির্বাচিত Class-এ মিলে যাওয়া কোনো Group পাওয়া যায়নি।' : 'নির্বাচিত সব Class/Group-এই এই নামের Subject আগে থেকে আছে।';
                }
            }
        }
    } elseif (isset($_POST['delete_subject'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_stmt = $pdo->prepare("SELECT subject_name FROM academic_subjects WHERE id = ?");
        $del_stmt->execute([$id]);
        $del_name = $del_stmt->fetchColumn();
        $pdo->prepare("DELETE FROM academic_subjects WHERE id = ?")->execute([$id]);
        sec_log_activity($pdo, 'Academic', 'Delete', "Subject মুছে ফেলা হয়েছে: " . ($del_name ?: "#$id"));
        $_SESSION['sub_msg'] = 'Subject মুছে ফেলা হয়েছে।';
    }

    header('Location: index.php?page=academic_subject');
    exit();
}

$view = $_GET['view'] ?? 'list';
$classes = $pdo->query("SELECT id, class_name, has_group FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$all_subjects = $pdo->query("SELECT id, subject_name, class_id FROM academic_subjects ORDER BY subject_name ASC")->fetchAll();
$groups = $pdo->query("SELECT id, group_name, class_id FROM academic_groups WHERE status = 'Active' ORDER BY group_name ASC")->fetchAll();
// Group ড্রপডাউনে নামের ভিত্তিতে ইউনিক লিস্ট — প্রতিটা নামের সাথে কোন কোন Class-এ এই নামের
// Group আছে তার তালিকা রাখা হচ্ছে (JS ফিল্টারিং আর মাল্টি-ক্লাস রেজোলিউশনের জন্য)
$group_names = [];
foreach ($groups as $g) {
    $gn = $g['group_name'];
    if (!isset($group_names[$gn])) $group_names[$gn] = [];
    $group_names[$gn][] = (int) $g['class_id'];
}

function sjv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    // ------------------- ADD / EDIT FORM VIEW -------------------
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT academic_subjects.*, academic_groups.group_name AS current_group_name FROM academic_subjects LEFT JOIN academic_groups ON academic_groups.id = academic_subjects.group_id WHERE academic_subjects.id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: [
        'id' => 0, 'class_id' => '', 'subject_name' => '', 'combined_with_id' => '', 'current_group_name' => '',
        'full_marks' => 100, 'subject_type' => 'Compulsory', 'status' => 'Active', 'optional_count' => null,
    ];
    // ডান পাশের প্যানেলের জন্য: Class অনুযায়ী আগে থেকে তৈরি Subject-গুলো (Group সহ)
    $subj_by_class = [];
    if ($view === 'add') {
        $ps = $pdo->query("SELECT s.class_id, s.subject_name, s.subject_type, s.status, g.group_name
                           FROM academic_subjects s LEFT JOIN academic_groups g ON g.id = s.group_id
                           ORDER BY s.class_id ASC, s.group_id ASC, s.id ASC");
        foreach ($ps->fetchAll() as $pr) {
            $subj_by_class[(int) $pr['class_id']][] = [
                'n' => $pr['subject_name'], 't' => $pr['subject_type'],
                'g' => $pr['group_name'] ?? '', 'a' => $pr['status'] === 'Active' ? 1 : 0,
            ];
        }
    }
    $class_name_map = [];
    foreach ($classes as $c) { $class_name_map[(int) $c['id']] = $c['class_name']; }
    ?>
    <div class="card-custom">
        <?php if ($sub_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($sub_err); ?></div><?php endif; ?>

        <a href="index.php?page=academic_subject" class="d-inline-block bg-warning bg-opacity-75 p-2 mb-4 text-decoration-none" style="width:40px;border-radius:4px;" title="তালিকায় ফিরে যান (Back to List)">
            <i class="fa-solid fa-file-lines text-white"></i>
        </a>

        <div class="subj-layout">
        <div class="subj-layout-main">
        <form method="POST" action="index.php?page=academic_subject" id="subjForm" autocomplete="off" <?php echo $view === 'add' ? 'onsubmit="return document.querySelectorAll(\'.subject-class-input:checked\').length > 0 || (alert(\'অন্তত একটা Class সিলেক্ট করুন।\'), false);"' : ''; ?>>
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row mb-3">
                <label class="col-sm-3 col-form-label fw-bold text-end pt-0">Class Name <span class="text-danger">*</span></label>
                <div class="col-sm-7">
                    <?php if ($view === 'add'): ?>
                        <!-- Add ভিউতে একাধিক Class সিলেক্ট করা যায় — মাল্টি-সিলেক্ট ড্রপডাউন; একই Subject একসাথে সব ক-টা Class-এ তৈরি হবে -->
                        <div class="ms-wrap" id="classMs">
                            <button type="button" class="form-select ms-toggle" id="classMsBtn"><span class="ms-label text-muted">Select Class</span></button>
                            <div class="ms-menu" id="classMsMenu">
                                <div class="ms-actions">
                                    <button type="button" id="subjSelectAllBtn" class="btn btn-sm btn-link p-0 text-decoration-none">সব সিলেক্ট করো</button>
                                    <button type="button" id="subjClearAllBtn" class="btn btn-sm btn-link p-0 text-decoration-none text-muted">ক্লিয়ার করো</button>
                                    <span class="text-muted small ms-auto"><b id="subjSelectedCount">0</b>টি সিলেক্টেড</span>
                                </div>
                                <?php foreach ($classes as $c): ?>
                                    <label class="ms-item" for="subjClass<?php echo (int) $c['id']; ?>">
                                        <input type="checkbox" name="class_ids[]" id="subjClass<?php echo (int) $c['id']; ?>" value="<?php echo (int) $c['id']; ?>" data-has-group="<?php echo (int) $c['has_group']; ?>" data-label="<?php echo sjv($c['class_name']); ?>" class="form-check-input subject-class-input">
                                        <span><?php echo sjv($c['class_name']); ?></span>
                                        <?php if ((int) $c['has_group'] === 1): ?><span class="badge bg-light text-secondary border ms-auto">Group</span><?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                                <div class="ms-footer"><button type="button" class="btn btn-sm btn-primary px-4 ms-ok" style="background-color:#1e3a8a;">OK</button></div>
                            </div>
                        </div>
                        <style>
                            .ms-wrap { position: relative; }
                            .ms-toggle { text-align: left; cursor: pointer; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
                            .ms-menu {
                                display: none; position: absolute; z-index: 1050; left: 0; right: 0; top: calc(100% + 4px);
                                background: #fff; border: 1px solid #d7dae2; border-radius: 8px; padding: 6px 0;
                                box-shadow: 0 8px 24px rgba(15,23,42,.14); max-height: 300px; overflow-y: auto;
                            }
                            .ms-wrap.open .ms-menu { display: block; }
                            .ms-actions { display: flex; align-items: center; gap: 14px; padding: 6px 14px 8px; border-bottom: 1px solid #eef0f4; margin-bottom: 4px; }
                            .ms-item { display: flex; align-items: center; gap: 10px; padding: 7px 14px; margin: 0; cursor: pointer; font-size: 14px; user-select: none; }
                            .ms-item:hover { background: #f1f5ff; }
                            .ms-item input { margin: 0; cursor: pointer; }
                            .ms-item.ms-all { font-weight: 700; border-bottom: 1px solid #eef0f4; margin-bottom: 4px; }
                        </style>
                    <?php else: ?>
                        <!-- Edit ভিউতে একটা Subject একটাই Class-এর সাথে সম্পর্কিত, তাই single select -->
                        <select name="class_ids[]" id="classSelect" class="form-select" required onchange="filterCombinedSubjects(); filterGroupOptions();">
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>" data-has-group="<?php echo (int) $c['has_group']; ?>" <?php echo (int) $d['class_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo sjv($c['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <?php if (!$classes): ?><small class="text-danger d-block mt-1">প্রথমে "All Class List" থেকে একটা Class যোগ করুন।</small><?php endif; ?>
                </div>
            </div>

            <div class="row mb-3 align-items-center" id="subjectGroupRow" style="display:none;">
                <label class="col-sm-3 col-form-label fw-bold text-end">Group</label>
                <div class="col-sm-7">
                    <?php if ($view === 'add'): ?>
                        <!-- Add: "No Group" নেই — মাল্টি-সিলেক্ট ড্রপডাউন, ডিফল্টে সব Group; প্রতিটা Group-এর জন্য আলাদা Subject তৈরি হবে -->
                        <div class="ms-wrap" id="groupMs">
                            <button type="button" class="form-select ms-toggle" id="groupMsBtn"><span class="ms-label">সব Group (All Groups)</span></button>
                            <div class="ms-menu" id="groupChecks">
                                <label class="ms-item ms-all" for="grpAll">
                                    <input class="form-check-input" type="checkbox" name="group_names[]" value="__all__" id="grpAll" checked>
                                    <span>সব Group (All Groups)</span>
                                </label>
                                <?php $gi = 0; foreach ($group_names as $gname => $gclassIds): $gi++; ?>
                                    <label class="ms-item grp-item-wrap" for="grpItem<?php echo $gi; ?>" data-classes="<?php echo sjv(implode(',', $gclassIds)); ?>">
                                        <input class="form-check-input grp-item" type="checkbox" name="group_names[]" value="<?php echo sjv($gname); ?>" id="grpItem<?php echo $gi; ?>" data-label="<?php echo sjv($gname); ?>" checked disabled>
                                        <span><?php echo sjv($gname); ?></span>
                                    </label>
                                <?php endforeach; ?>
                                <div class="ms-footer"><button type="button" class="btn btn-sm btn-primary px-4 ms-ok" style="background-color:#1e3a8a;">OK</button></div>
                            </div>
                        </div>
                        <small class="text-muted d-block mt-1">Group-ওয়ালা Class সিলেক্ট করলে সেই Class-এর সব Group-এ একসাথে Subject তৈরি হবে। নির্দিষ্ট Group চাইলে "সব Group" থেকে টিক তুলে বেছে নিন।</small>
                    <?php else: ?>
                        <select name="group_name" id="groupSelect" class="form-select">
                            <option value="">No Group (All Students)</option>
                            <?php foreach ($group_names as $gname => $gclassIds): ?>
                                <option value="<?php echo sjv($gname); ?>" data-classes="<?php echo sjv(implode(',', $gclassIds)); ?>" <?php echo ((string) $d['current_group_name'] === (string) $gname) ? 'selected' : ''; ?>><?php echo sjv($gname); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Subject Name <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="subject_name" class="form-control" value="<?php echo sjv($d['subject_name']); ?>" placeholder="e.g. Bangla 1st Paper" required></div>
            </div>

            <div class="row mb-3 align-items-center" id="combinedWithRow">
                <label class="col-sm-3 col-form-label fw-bold text-end">Combined With</label>
                <div class="col-sm-6">
                    <select name="combined_with_id" id="combinedSelect" class="form-select">
                        <option value="">Select Subject</option>
                        <?php foreach ($all_subjects as $s): if ((int) $s['id'] === (int) $d['id']) continue; ?>
                            <option value="<?php echo (int) $s['id']; ?>" data-class="<?php echo (int) $s['class_id']; ?>" <?php echo (int) $d['combined_with_id'] === (int) $s['id'] ? 'selected' : ''; ?>><?php echo sjv($s['subject_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted" id="combinedHelpText">একই ক্লাসের ২টা পার্ট (যেমন: বাংলা ১ম পত্র + ২য় পত্র) একসাথে যুক্ত করতে চাইলে বেছে নিন।</small>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Full Marks <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="number" name="full_marks" class="form-control" value="<?php echo (int) $d['full_marks']; ?>" min="1" required></div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Subject Type</label>
                <div class="col-sm-6 d-flex gap-4 align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="subject_type" id="typeCompulsory" value="Compulsory" <?php echo $d['subject_type'] === 'Compulsory' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="typeCompulsory">Compulsory</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="subject_type" id="typeOptional" value="Optional" <?php echo $d['subject_type'] === 'Optional' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="typeOptional">Optional</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="subject_type" id="typeCA" value="Continuous Assessment" <?php echo $d['subject_type'] === 'Continuous Assessment' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="typeCA">Continuous Assessment</label>
                    </div>
                </div>
            </div>

            <!-- Optional (৪র্থ) Subject সেটিং — শুধু Subject Type = Optional হলে দেখায় -->
            <div class="row mb-3" id="optionalRow" style="display:none;">
                <label class="col-sm-3 col-form-label fw-bold text-end">Optional Count</label>
                <div class="col-sm-7">
                    <div class="input-group" style="max-width:320px;">
                        <span class="input-group-text">GP &minus;</span>
                        <input type="number" name="optional_count" id="optionalDeduct" class="form-control" min="0" max="10" step="1" value="<?php echo $d['optional_count'] !== null ? (int) $d['optional_count'] : 2; ?>">
                        <span class="input-group-text">বাদ যাবে</span>
                    </div>
                    <small class="text-muted d-block mt-1">ডিফল্ট ২ (বাংলাদেশের নিয়ম): এই Subject-এ যত GP পাবে, তার থেকে <b id="optDeductTxt">২</b> বাদ দিয়ে বাকিটা মোট GPA-তে যোগ হবে। কম-বেশি করতে চাইলে সংখ্যাটা বদলান।</small>
                </div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Status <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="status" class="form-select">
                        <option value="Active" <?php echo $d['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $d['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_subject" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=academic_subject" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
        </div><!-- /.subj-layout-main -->
        <?php if ($view === 'add'): ?>
        <!-- ডান পাশ: সিলেক্ট করা Class-গুলোর আগে তৈরি Subject (Class অনুযায়ী) -->
        <aside class="subj-side" id="subjSide" style="display:none;">
            <div class="subj-side-head"><i class="fa-solid fa-book-open"></i> তৈরি করা Subject-সমূহ</div>
            <div id="subjSideBody" class="subj-side-body"></div>
        </aside>
        <?php endif; ?>
        </div><!-- /.subj-layout -->
        <style>
            .subj-layout { display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap; }
            .subj-layout-main { flex: 1 1 560px; min-width: 0; }
            .subj-side { flex: 0 0 340px; max-width: 100%; position: sticky; top: 12px; border: 1px solid #e3e7ef; border-radius: 10px; background: #fafbfe; overflow: hidden; }
            .subj-side-head { padding: 10px 14px; font-weight: 700; color: #1e3a8a; border-bottom: 1px solid #e3e7ef; background: #f1f5ff; }
            .subj-side-body { max-height: 70vh; overflow-y: auto; padding: 8px 12px 12px; }
            .subj-side-empty { color: #8a93a6; font-size: 13.5px; padding: 14px 4px; }
            .ss-class { margin-top: 10px; }
            .ss-class-name { display: flex; justify-content: space-between; align-items: center; font-weight: 700; font-size: 14px; color: #0f172a; padding: 4px 0; border-bottom: 2px solid #1e3a8a; }
            .ss-class-name .badge { font-weight: 600; }
            .ss-group { font-size: 12px; font-weight: 700; color: #64748b; margin: 8px 0 2px; text-transform: uppercase; letter-spacing: .3px; }
            .ss-item { display: flex; align-items: center; gap: 6px; font-size: 13.5px; padding: 3px 0 3px 6px; border-bottom: 1px dashed #e6e9f0; }
            .ss-item.ss-off { color: #9aa3b5; text-decoration: line-through; }
            .ss-item.ss-dup { background: #fff1f0; color: #b42318; font-weight: 700; border-radius: 4px; }
            .ss-none { font-size: 13px; color: #9aa3b5; padding: 4px 6px; }
            .ms-footer { position: sticky; bottom: 0; background: #fff; border-top: 1px solid #eef0f4; padding: 8px 12px 4px; margin-top: 4px; text-align: right; }
        </style>
    </div>

    <script>
        // Subject Type = Optional হলে Optional Count সেটিং দেখানো + লাইভ Preview
        (function () {
            const row = document.getElementById('optionalRow');
            const inp = document.getElementById('optionalDeduct');
            const txt = document.getElementById('optDeductTxt');
            const bn = n => String(n).replace(/[0-9]/g, d => '০১২৩৪৫৬৭৮৯'[d]);
            function render() {
                const isOpt = document.getElementById('typeOptional').checked;
                row.style.display = isOpt ? '' : 'none';
                inp.disabled = !isOpt; // Optional না হলে সাবমিটে যাবে না
                const ded = Math.max(0, Math.min(10, parseInt(inp.value, 10) || 0));
                txt.textContent = bn(ded);
            }
            document.querySelectorAll('input[name="subject_type"]').forEach(r => r.addEventListener('change', render));
            inp.addEventListener('input', render);
            render();
        })();

        // "Combined With" ড্রপডাউনে শুধু একই Class-এর subject-গুলোই দেখানো হয়
        function filterCombinedSubjects() {
            <?php if ($view === 'edit'): ?>
                const classId = document.getElementById('classSelect').value;
            <?php else: ?>
                const checked = document.querySelectorAll('.subject-class-input:checked');
                const classId = checked.length === 1 ? checked[0].value : null;
            <?php endif; ?>
            const select = document.getElementById('combinedSelect');
            Array.from(select.options).forEach(opt => {
                if (!opt.value) { opt.hidden = false; return; }
                opt.hidden = classId && opt.dataset.class !== classId;
            });
        }
        filterCombinedSubjects();

        // Group ফিল্ড শুধু তখনই দেখানো হয় যখন সিলেক্ট করা Class(গুলো)-এর Has Group = Yes
        <?php if ($view === 'edit'): ?>
        function filterGroupOptions() {
            const sel = document.getElementById('classSelect');
            const opt = sel.options[sel.selectedIndex];
            const hasGroup = opt && opt.dataset.hasGroup === '1';
            const classIds = opt && opt.value ? [opt.value] : [];
            const row = document.getElementById('subjectGroupRow');
            const groupSelect = document.getElementById('groupSelect');
            row.style.display = hasGroup ? '' : 'none';
            if (!hasGroup) { groupSelect.value = ''; }
            Array.from(groupSelect.options).forEach(o => {
                if (!o.value) { o.hidden = false; return; }
                const optClasses = (o.dataset.classes || '').split(',').filter(Boolean);
                o.hidden = classIds.length > 0 && !classIds.some(cid => optClasses.includes(cid));
            });
        }
        <?php else: ?>
        // Add ভিউ: Group চেকবক্স — "সব Group" টিক থাকলে বাকিগুলো টিক+লক থাকে (শুধু __all__ সাবমিট হয়)
        function visibleGroupItems() {
            return Array.from(document.querySelectorAll('#groupChecks .grp-item-wrap')).filter(w => w.style.display !== 'none');
        }
        function effectiveGroupCount() {
            const hasGroup = document.getElementById('subjectGroupRow').style.display !== 'none';
            if (!hasGroup) return 1;
            const items = visibleGroupItems();
            if (document.getElementById('grpAll').checked) return items.length;
            return items.filter(w => w.querySelector('.grp-item').checked).length;
        }
        function filterGroupOptions() {
            const checked = Array.from(document.querySelectorAll('.subject-class-input:checked'));
            const hasGroup = checked.length > 0 && checked.every(i => i.dataset.hasGroup === '1');
            const classIds = checked.map(i => i.value);
            const row = document.getElementById('subjectGroupRow');
            const allBox = document.getElementById('grpAll');
            row.style.display = hasGroup ? '' : 'none';
            // Row লুকানো থাকলে কোনো Group ইনপুটই সাবমিট হবে না (Group ছাড়া Class)
            document.querySelectorAll('#groupChecks input').forEach(i => { i.disabled = !hasGroup || (i.classList.contains('grp-item') && allBox.checked); });
            document.querySelectorAll('#groupChecks .grp-item-wrap').forEach(w => {
                const wc = (w.dataset.classes || '').split(',').filter(Boolean);
                w.style.display = (classIds.length === 0 || classIds.some(cid => wc.includes(cid))) ? '' : 'none';
            });
        }
        (function () {
            const allBox = document.getElementById('grpAll');
            const items = () => Array.from(document.querySelectorAll('#groupChecks .grp-item'));
            allBox.addEventListener('change', function () {
                items().forEach(i => { i.checked = allBox.checked; i.disabled = allBox.checked; });
                if (typeof refreshCombinedState === 'function') refreshCombinedState();
            });
            items().forEach(i => i.addEventListener('change', function () {
                const vis = items().filter(x => x.closest('.grp-item-wrap').style.display !== 'none');
                if (vis.length && vis.every(x => x.checked)) { allBox.checked = true; items().forEach(x => { x.disabled = true; }); }
                if (typeof refreshCombinedState === 'function') refreshCombinedState();
            }));
            document.getElementById('subjForm').addEventListener('submit', function (e) {
                const rowVisible = document.getElementById('subjectGroupRow').style.display !== 'none';
                if (rowVisible && !allBox.checked && !items().some(i => i.checked && !i.disabled && i.closest('.grp-item-wrap').style.display !== 'none')) {
                    e.preventDefault(); alert('অন্তত একটা Group সিলেক্ট করুন।');
                }
            });
        })();
        <?php endif; ?>
        filterGroupOptions();

        <?php if ($view === 'add'): ?>
        // ------------------- Add ভিউ: Class পিল-পিকার -------------------
        (function () {
            const inputs = Array.from(document.querySelectorAll('.subject-class-input'));
            const countEl = document.getElementById('subjSelectedCount');
            const combinedRow = document.getElementById('combinedWithRow');
            const combinedHelp = document.getElementById('combinedHelpText');

            function refresh() {
                const checked = inputs.filter(i => i.checked);
                countEl.textContent = checked.length;
                filterCombinedSubjects();
                filterGroupOptions();
                refreshCombinedState();
                if (typeof updateMsLabels === 'function') updateMsLabels();
                if (typeof renderSide === 'function') renderSide();
            }
            // একাধিক Class বা একাধিক Group-এ একসাথে বানালে Combined With অর্থহীন — তখন disable/হালকা করে দেওয়া হয়
            window.refreshCombinedState = function () {
                const checked = inputs.filter(i => i.checked);
                if (checked.length === 1 && effectiveGroupCount() <= 1) {
                    combinedRow.classList.remove('opacity-50');
                    document.getElementById('combinedSelect').disabled = false;
                    combinedHelp.textContent = 'একই ক্লাসের ২টা পার্ট (যেমন: বাংলা ১ম পত্র + ২য় পত্র) একসাথে যুক্ত করতে চাইলে বেছে নিন।';
                } else {
                    combinedRow.classList.add('opacity-50');
                    document.getElementById('combinedSelect').disabled = true;
                    document.getElementById('combinedSelect').value = '';
                    combinedHelp.textContent = 'একাধিক Class বা একাধিক Group সিলেক্ট করা থাকলে Combined With প্রযোজ্য না — শুধু একটা Class ও একটা Group সিলেক্ট করলে এটা কাজ করবে।';
                }
            };
            inputs.forEach(i => i.addEventListener('change', refresh));

            // ---- মাল্টি-সিলেক্ট ড্রপডাউন: খোলা/বন্ধ + বাটনের লেখা ----
            const msWraps = Array.from(document.querySelectorAll('.ms-wrap'));
            msWraps.forEach(w => {
                w.querySelector('.ms-toggle').addEventListener('click', function (e) {
                    e.stopPropagation();
                    const willOpen = !w.classList.contains('open');
                    msWraps.forEach(x => x.classList.remove('open'));
                    if (willOpen) w.classList.add('open');
                });
                w.querySelector('.ms-menu').addEventListener('click', e => e.stopPropagation());
                w.querySelector('.ms-ok').addEventListener('click', () => w.classList.remove('open'));
            });
            document.addEventListener('click', () => msWraps.forEach(x => x.classList.remove('open')));
            document.addEventListener('keydown', e => { if (e.key === 'Escape') msWraps.forEach(x => x.classList.remove('open')); });

            function summarize(labels, emptyText) {
                if (labels.length === 0) return [emptyText, true];
                if (labels.length <= 3) return [labels.join(', '), false];
                return [labels.slice(0, 2).join(', ') + ' +' + (labels.length - 2) + ' আরও (' + labels.length + 'টি)', false];
            }
            function updateMsLabels() {
                const cl = inputs.filter(i => i.checked).map(i => i.dataset.label);
                const [ct, cEmpty] = summarize(cl, 'Select Class');
                const cEl = document.querySelector('#classMsBtn .ms-label');
                cEl.textContent = ct; cEl.classList.toggle('text-muted', cEmpty);

                const gEl = document.querySelector('#groupMsBtn .ms-label');
                if (document.getElementById('grpAll').checked) {
                    gEl.textContent = 'সব Group (All Groups)'; gEl.classList.remove('text-muted');
                } else {
                    const gl = Array.from(document.querySelectorAll('#groupChecks .grp-item'))
                        .filter(i => i.checked && i.closest('.grp-item-wrap').style.display !== 'none').map(i => i.dataset.label);
                    const [gt, gEmpty] = summarize(gl, 'Select Group');
                    gEl.textContent = gt; gEl.classList.toggle('text-muted', gEmpty);
                }
            }
            document.getElementById('subjForm').addEventListener('change', updateMsLabels);

            // ---- ডান পাশের প্যানেল: সিলেক্ট করা Class-গুলোর আগে তৈরি Subject (Class অনুযায়ী) ----
            const SUBJ_BY_CLASS = <?php echo json_encode($subj_by_class, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
            const CLASS_NAMES = <?php echo json_encode($class_name_map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
            const sideBody = document.getElementById('subjSideBody');
            const nameInput = document.querySelector('#subjForm input[name="subject_name"]');
            function el(tag, cls, text) { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
            function renderSide() {
                const typed = nameInput.value.trim().toLowerCase();
                const checked = inputs.filter(i => i.checked);
                sideBody.textContent = '';
                // Class সিলেক্ট না থাকলে প্যানেলটাই দেখানো হয় না
                document.getElementById('subjSide').style.display = checked.length ? '' : 'none';
                if (!checked.length) { return; }
                checked.forEach(inp => {
                    const cid = inp.value, list = SUBJ_BY_CLASS[cid] || [];
                    const box = el('div', 'ss-class');
                    const head = el('div', 'ss-class-name');
                    head.appendChild(el('span', '', CLASS_NAMES[cid] || ('Class #' + cid)));
                    head.appendChild(el('span', 'badge bg-primary', list.length + ' টি'));
                    box.appendChild(head);
                    if (!list.length) {
                        box.appendChild(el('div', 'ss-none', 'এখনো কোনো Subject নেই।'));
                    } else {
                        let lastGroup = null;
                        list.forEach(sb => {
                            const g = sb.g || '';
                            if (g !== lastGroup) { box.appendChild(el('div', 'ss-group', g ? g : 'No Group')); lastGroup = g; }
                            const row = el('div', 'ss-item' + (sb.a ? '' : ' ss-off') + (typed && sb.n.trim().toLowerCase() === typed ? ' ss-dup' : ''));
                            row.appendChild(el('span', '', sb.n));
                            if (sb.t === 'Optional') row.appendChild(el('span', 'badge bg-warning text-dark ms-auto', 'Optional'));
                            else if (sb.t === 'Continuous Assessment') row.appendChild(el('span', 'badge bg-info text-dark ms-auto', 'CA'));
                            box.appendChild(row);
                        });
                    }
                    sideBody.appendChild(box);
                });
            }
            document.getElementById('subjForm').addEventListener('change', renderSide);
            nameInput.addEventListener('input', renderSide);

            document.getElementById('subjSelectAllBtn').addEventListener('click', function () {
                inputs.forEach(i => { i.checked = true; });
                refresh();
            });
            document.getElementById('subjClearAllBtn').addEventListener('click', function () {
                inputs.forEach(i => { i.checked = false; });
                refresh();
            });
            refresh();
            updateMsLabels();
            renderSide();
        })();
        <?php endif; ?>
    </script>

<?php else: ?>
    <?php
    // ------------------- LIST VIEW -------------------
    $f_class  = $_GET['f_class'] ?? 'All';
    $f_name   = trim($_GET['f_name'] ?? '');
    $f_group  = $_GET['f_group'] ?? 'All';
    $f_type   = $_GET['f_type'] ?? 'All';
    $f_status = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_class !== 'All') { $where[] = 'academic_subjects.class_id = ?'; $params[] = (int) $f_class; }
    if ($f_name !== '')  { $where[] = 'academic_subjects.subject_name LIKE ?'; $params[] = "%$f_name%"; }
    if ($f_group !== 'All') {
        // Group নামের ভিত্তিতে ফিল্টার — Nine-এর Science আর Ten-এর Science দুটোই একসাথে আসবে
        if ($f_group === 'None') { $where[] = 'academic_subjects.group_id IS NULL'; }
        else { $where[] = 'academic_groups.group_name = ?'; $params[] = $f_group; }
    }
    if ($f_type !== 'All')   { $where[] = 'academic_subjects.subject_type = ?'; $params[] = $f_type; }
    if ($f_status !== 'All') { $where[] = 'academic_subjects.status = ?'; $params[] = $f_status; }

    $sql = "SELECT academic_subjects.*, academic_classes.class_name, academic_groups.group_name FROM academic_subjects
            LEFT JOIN academic_classes ON academic_classes.id = academic_subjects.class_id
            LEFT JOIN academic_groups ON academic_groups.id = academic_subjects.group_id";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    // Class অনুযায়ী সিরিয়াল (Class-এর rank অনুযায়ী: Three, Four, Five...), প্রতিটা Class-এর ভেতরে যে ক্রমে সাবজেক্ট যোগ হয়েছে সেই ক্রমে
    // Class → Group (No Group আগে, তারপর Group তৈরির ক্রমে) → Subject যোগ হওয়ার ক্রমে
    $sql .= " ORDER BY academic_classes.rank ASC, academic_classes.class_name ASC, academic_subjects.class_id ASC, academic_subjects.group_id ASC, academic_subjects.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($sub_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($sub_msg); ?></div><?php endif; ?>
        <?php if ($sub_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($sub_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-start mb-3">
            <a href="index.php?page=academic_subject&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
            <div class="text-end">
                <a href="index.php?page=academic_subject_import" class="btn btn-outline-primary btn-sm">
                    <i class="fa-solid fa-file-import"></i> Import Subject
                </a>
                <div class="text-muted small mt-1">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> items.</div>
            </div>
        </div>

        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="academic_subject">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>Class Name</th>
                            <th>Subject Name</th>
                            <th>Full Marks</th>
                            <th>Group</th>
                            <th>Subject Type</th>
                            <th>Optional Count (GP &minus; বাদ)</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <tr>
                            <th></th>
                            <th>
                                <select name="f_class" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All">All</option>
                                    <?php foreach ($classes as $c): ?>
                                        <option value="<?php echo (int) $c['id']; ?>" <?php echo (string) $f_class === (string) $c['id'] ? 'selected' : ''; ?>><?php echo sjv($c['class_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th><input type="text" name="f_name" class="form-control form-control-sm" value="<?php echo sjv($f_name); ?>"></th>
                            <th></th>
                            <th>
                                <select name="f_group" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All">All</option>
                                    <option value="None" <?php echo $f_group === 'None' ? 'selected' : ''; ?>>No Group</option>
                                    <?php foreach (array_keys($group_names) as $gname): ?>
                                        <option value="<?php echo sjv($gname); ?>" <?php echo (string) $f_group === (string) $gname ? 'selected' : ''; ?>><?php echo sjv($gname); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th>
                                <select name="f_type" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All" <?php echo $f_type === 'All' ? 'selected' : ''; ?>>All</option>
                                    <option value="Compulsory" <?php echo $f_type === 'Compulsory' ? 'selected' : ''; ?>>Compulsory</option>
                                    <option value="Optional" <?php echo $f_type === 'Optional' ? 'selected' : ''; ?>>Optional</option>
                                    <option value="Continuous Assessment" <?php echo $f_type === 'Continuous Assessment' ? 'selected' : ''; ?>>Continuous Assessment</option>
                                </select>
                            </th>
                            <th></th>
                            <th>
                                <select name="f_status" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All" <?php echo $f_status === 'All' ? 'selected' : ''; ?>>All</option>
                                    <option value="Active" <?php echo $f_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo $f_status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </th>
                            <th><button type="submit" class="btn btn-sm btn-outline-primary">Filter</button></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows): $srl = 1; $prev_key = null;
                            // প্রতিটা (Class + Group)-এর subject count আগে বের করা, হেডার সারিতে দেখানোর জন্য
                            $grp_counts = [];
                            foreach ($rows as $r0) { $k0 = (int) $r0['class_id'] . '|' . (int) $r0['group_id']; $grp_counts[$k0] = ($grp_counts[$k0] ?? 0) + 1; }
                            foreach ($rows as $row):
                                $cur_key = (int) $row['class_id'] . '|' . (int) $row['group_id'];
                                if ($cur_key !== $prev_key): $prev_key = $cur_key; ?>
                            <tr class="table-secondary">
                                <td colspan="9" class="fw-bold">
                                    <i class="fa-solid fa-layer-group me-1"></i>
                                    <?php echo sjv($row['class_name'] ?? '-'); ?> &mdash; <?php echo $row['group_name'] ? sjv($row['group_name']) : 'No Group (সব শিক্ষার্থী)'; ?>
                                    <span class="badge bg-dark ms-2"><?php echo (int) $grp_counts[$cur_key]; ?> Subject</span>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td><?php echo sjv($row['class_name'] ?? '-'); ?></td>
                                <td><?php echo sjv($row['subject_name']); ?></td>
                                <td><?php echo (int) $row['full_marks']; ?></td>
                                <td><?php echo $row['group_name'] ? sjv($row['group_name']) : 'No Group'; ?></td>
                                <td><?php echo sjv($row['subject_type']); ?></td>
                                <td><?php echo ($row['subject_type'] === 'Optional') ? 'GP − ' . ($row['optional_count'] !== null ? (int) $row['optional_count'] : 2) : '-'; ?></td>
                                <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=academic_subject&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                    <a href="index.php?page=academic_subject&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="sjDeleteRow(<?php echo (int) $row['id']; ?>)"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="9" class="text-center text-muted py-3">No data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <!-- ডিলিটের জন্য আলাদা, ফিল্টার ফর্মের বাইরে একটা শেয়ার্ড হিডেন ফর্ম — টেবিলের
             সারি-ভিত্তিক ফর্ম আগে ফিল্টার ফর্মের ভেতরে নেস্টেড থাকায় ব্রাউজার সেটাকে
             ইনভ্যালিড HTML ধরে নিয়ে বাদ দিয়ে দিচ্ছিল, তাই ডিলিট কাজ করছিল না — সেটার ফিক্স -->
        <form method="POST" action="index.php?page=academic_subject" id="sjDeleteForm" class="d-none">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" id="sjDeleteId">
            <input type="hidden" name="delete_subject" value="1">
        </form>
        <script>
            function sjDeleteRow(id) {
                if (!confirm('মুছে ফেলবেন?')) return;
                document.getElementById('sjDeleteId').value = id;
                document.getElementById('sjDeleteForm').submit();
            }
        </script>
    </div>
<?php endif; ?>
