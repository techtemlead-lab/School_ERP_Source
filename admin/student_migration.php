<?php
// admin/student_migration.php - Student Migration Process (Bulk Batch Migration)
// ধাপ ১ (config): Migrated From (Session/Class/Shift/Section) + Migrated To criteria বাছাই
// ধাপ ২ (review): মিলে যাওয়া সব শিক্ষার্থীর লিস্ট + auto Roll No (এডিটযোগ্য)
// ধাপ ৩ (confirm): সবাইকে একসাথে migrate — পুরনো রেকর্ড archive, নতুন রেকর্ড current

// students.is_current/promoted_from_id কলাম দুটো পুরনো ইনস্টলেশনে না থাকতে পারে
// (student_promote.php-এর সাথে একই self-heal প্যাটার্ন) — না থাকলে নিচের UPDATE/INSERT
// "Unknown column" Fatal error দিয়ে পুরো Bulk Migration ব্যর্থ করত।
$mg_cols = $pdo->query("SHOW COLUMNS FROM students")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('is_current', $mg_cols, true)) {
    $pdo->exec("ALTER TABLE students ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 1");
}
if (!in_array('promoted_from_id', $mg_cols, true)) {
    $pdo->exec("ALTER TABLE students ADD COLUMN promoted_from_id INT NULL DEFAULT NULL AFTER is_current");
}
// students.student_id-এর UNIQUE constraint সরিয়ে non-unique INDEX করা — student_promote.php-এর
// সাথে হুবহু একই কারণে ও একই প্যাটার্নে (দেখুন সেই ফাইলের কমেন্ট) — নাহলে Bulk Migration-ও
// প্রতিটা ছাত্রের জন্য "Duplicate entry" এরর দিয়ে ব্যর্থ হতো।
$mg_idx = $pdo->query(
    "SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND INDEX_NAME = 'uniq_student_id' LIMIT 1"
)->fetchColumn();
if ($mg_idx !== false && (int) $mg_idx === 0) {
    $pdo->exec("ALTER TABLE students DROP INDEX uniq_student_id, ADD INDEX idx_student_id (student_id)");
}

$mg_err = $_SESSION['mg_err'] ?? '';
$mg_msg = $_SESSION['mg_msg'] ?? '';
unset($_SESSION['mg_err'], $_SESSION['mg_msg']);

function mgv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes ORDER BY rank ASC, class_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id FROM academic_sections ORDER BY section_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts ORDER BY shift_name ASC")->fetchAll();

// -----------------------------------------------------------
// ধাপ ১ → ধাপ ২: "Review Migration" — criteria সেশনে রেখে review পেজে যাওয়া
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_migration'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['mg_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=student_migration');
        exit();
    }

    $from_session = (int) ($_POST['from_session_id'] ?? 0);
    $from_class   = (int) ($_POST['from_class_id'] ?? 0);
    $from_shift   = (int) ($_POST['from_shift_id'] ?? 0);
    $from_section = (int) ($_POST['from_section_id'] ?? 0);
    $to_session   = (int) ($_POST['to_session_id'] ?? 0);
    $to_class     = (int) ($_POST['to_class_id'] ?? 0);
    $to_shift     = (int) ($_POST['to_shift_id'] ?? 0);
    $to_section   = (int) ($_POST['to_section_id'] ?? 0);

    if (!$from_session || !$from_class || !$from_shift || !$from_section || !$to_session || !$to_class || !$to_shift || !$to_section) {
        $_SESSION['mg_err'] = 'Migrated From ও Migrated To — দুই পাশের সবগুলো ফিল্ডই আবশ্যক।';
        header('Location: index.php?page=student_migration');
        exit();
    }

    // Migrated From ও Migrated To যদি হুবহু একই Session+Class+Shift+Section হয়, তাহলে মাইগ্রেশনের
    // কোনো অর্থ নেই (একই ক্লাস থেকে একই ক্লাসে) — এরর দেখিয়ে আটকে দেওয়া হচ্ছে
    if ($from_session === $to_session && $from_class === $to_class && $from_shift === $to_shift && $from_section === $to_section) {
        $_SESSION['mg_err'] = 'Migrated From ও Migrated To একই Session/Class/Shift/Section হতে পারবে না — অন্তত একটা আলাদা করে সিলেক্ট করুন।';
        header('Location: index.php?page=student_migration');
        exit();
    }

    $_SESSION['mg_criteria'] = [
        'from_session_id' => $from_session, 'from_class_id' => $from_class, 'from_shift_id' => $from_shift, 'from_section_id' => $from_section,
        'to_session_id'   => $to_session,   'to_class_id'   => $to_class,   'to_shift_id'   => $to_shift,   'to_section_id'   => $to_section,
    ];
    header('Location: index.php?page=student_migration&view=review');
    exit();
}

// -----------------------------------------------------------
// ধাপ ৩: "Confirm Migration" — আসল migrate কাজ সম্পন্ন করা
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_migration'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['mg_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=student_migration');
        exit();
    }

    $to_session = (int) ($_POST['to_session_id'] ?? 0);
    $to_class   = (int) ($_POST['to_class_id'] ?? 0);
    $to_shift   = (int) ($_POST['to_shift_id'] ?? 0);
    $to_section = (int) ($_POST['to_section_id'] ?? 0);
    $student_ids = $_POST['student_id'] ?? [];   // পুরনো রো-এর id গুলো
    $roll_nos    = $_POST['roll_no'] ?? [];       // সংশ্লিষ্ট নতুন Roll No (এডিট করা থাকতে পারে)

    if (!$to_session || !$to_class || !$to_shift || !$to_section || !$student_ids) {
        $_SESSION['mg_err'] = 'মাইগ্রেশনের তথ্য অসম্পূর্ণ, আবার চেষ্টা করুন।';
        header('Location: index.php?page=student_migration');
        exit();
    }

    $migrated_count = 0;
    $skipped_count = 0;

    try {
        $pdo->beginTransaction();

        foreach ($student_ids as $idx => $old_id) {
            $old_id = (int) $old_id;
            $new_roll = trim($roll_nos[$idx] ?? '');
            if (!$old_id || $new_roll === '') continue;

            $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND is_current = 1");
            $stmt->execute([$old_id]);
            $old = $stmt->fetch();
            if (!$old) { $skipped_count++; continue; }

            // ডুপ্লিকেট migration ঠেকানো (একই student_id + নতুন session আগে থেকে থাকলে স্কিপ)
            $chk = $pdo->prepare("SELECT id FROM students WHERE student_id = ? AND session_id = ?");
            $chk->execute([$old['student_id'], $to_session]);
            if ($chk->fetch()) { $skipped_count++; continue; }

            $pdo->prepare("UPDATE students SET is_current = 0 WHERE id = ?")->execute([$old_id]);

            $new_data = $old;
            unset($new_data['id'], $new_data['created_at'], $new_data['updated_at']);
            $new_data['session_id']       = $to_session;
            $new_data['class_id']         = $to_class;
            $new_data['shift_id']         = $to_shift;
            $new_data['section_id']       = $to_section;
            $new_data['roll_no']          = $new_roll;
            $new_data['is_current']       = 1;
            $new_data['promoted_from_id'] = $old_id;

            $cols = implode(', ', array_keys($new_data));
            $placeholders = implode(', ', array_fill(0, count($new_data), '?'));
            $pdo->prepare("INSERT INTO students ($cols) VALUES ($placeholders)")->execute(array_values($new_data));

            $migrated_count++;
        }

        $pdo->commit();
        $_SESSION['mg_msg'] = "$migrated_count জন শিক্ষার্থীকে সফলভাবে migrate করা হয়েছে।" . ($skipped_count ? " ($skipped_count জন স্কিপ হয়েছে — আগে থেকেই migrate করা ছিল)" : '');
        sec_log_activity($pdo, 'Student', 'Update', "{$migrated_count} জন শিক্ষার্থীকে নতুন Session/Class-এ Migrate করা হয়েছে" . ($skipped_count ? " ({$skipped_count} জন স্কিপ)" : '') . "।");
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Bulk migration failed: ' . $e->getMessage());
        $_SESSION['mg_err'] = 'মাইগ্রেশন ব্যর্থ হয়েছে, আবার চেষ্টা করুন।';
    }

    unset($_SESSION['mg_criteria']);
    header('Location: index.php?page=student_migration');
    exit();
}

$view = $_GET['view'] ?? 'config';
?>

<?php if ($view === 'review' && !empty($_SESSION['mg_criteria'])): ?>
    <?php
    $cr = $_SESSION['mg_criteria'];

    $stmt = $pdo->prepare(
        "SELECT students.*, academic_classes.class_name FROM students
         LEFT JOIN academic_classes ON academic_classes.id = students.class_id
         WHERE students.is_current = 1 AND students.session_id = ? AND students.class_id = ? AND students.shift_id = ? AND students.section_id = ?
         ORDER BY students.roll_no ASC, students.name_en ASC"
    );
    $stmt->execute([$cr['from_session_id'], $cr['from_class_id'], $cr['from_shift_id'], $cr['from_section_id']]);
    $students = $stmt->fetchAll();

    $to_session_row = array_values(array_filter($sessions, fn($s) => (int) $s['id'] === (int) $cr['to_session_id']))[0] ?? null;
    $to_class_row   = array_values(array_filter($classes, fn($c) => (int) $c['id'] === (int) $cr['to_class_id']))[0] ?? null;
    ?>
    <div class="card-custom">
        <h5 class="fw-bold mb-3">Review Migration</h5>
        <p class="text-muted small">
            গন্তব্য: <strong><?php echo mgv($to_session_row['session_name'] ?? ''); ?></strong> সেশনের
            <strong><?php echo mgv($to_class_row['class_name'] ?? ''); ?></strong> ক্লাসে —
            মোট <strong><?php echo count($students); ?></strong> জন শিক্ষার্থী পাওয়া গেছে।
            নিচে Roll No স্বয়ংক্রিয়ভাবে বসানো হয়েছে (বর্তমান রোল অনুযায়ী ক্রমানুসারে) — চাইলে ম্যানুয়ালি বদলে দিতে পারেন।
        </p>

        <?php if (!$students): ?>
            <div class="alert alert-warning">এই Session/Class/Shift/Section-এ কোনো active শিক্ষার্থী পাওয়া যায়নি।</div>
            <a href="index.php?page=student_migration" class="btn btn-secondary">Back</a>
        <?php else: ?>
            <form method="POST" action="index.php?page=student_migration">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="to_session_id" value="<?php echo (int) $cr['to_session_id']; ?>">
                <input type="hidden" name="to_class_id" value="<?php echo (int) $cr['to_class_id']; ?>">
                <input type="hidden" name="to_shift_id" value="<?php echo (int) $cr['to_shift_id']; ?>">
                <input type="hidden" name="to_section_id" value="<?php echo (int) $cr['to_section_id']; ?>">

                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-dark">
                            <tr><th>Student ID</th><th>Name</th><th>Current Class</th><th>Current Roll</th><th>নতুন Roll No</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $i => $st): ?>
                                <tr>
                                    <td><?php echo mgv($st['student_id']); ?></td>
                                    <td><?php echo mgv($st['name_en']); ?></td>
                                    <td><?php echo mgv($st['class_name'] ?? '-'); ?></td>
                                    <td><?php echo mgv($st['roll_no']); ?></td>
                                    <td style="max-width:120px;">
                                        <input type="hidden" name="student_id[]" value="<?php echo (int) $st['id']; ?>">
                                        <input type="text" name="roll_no[]" class="form-control form-control-sm" value="<?php echo $i + 1; ?>" required>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="text-center mt-3">
                    <button type="submit" name="confirm_migration" class="btn btn-success px-5 fw-bold" onclick="return confirm('নিশ্চিত? এই সবাইকে migrate করা হবে।');">Confirm Migration</button>
                    <a href="index.php?page=student_migration" class="btn btn-secondary px-4 ms-2">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="card-custom">
        <?php if ($mg_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($mg_msg); ?></div><?php endif; ?>
        <?php if ($mg_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($mg_err); ?></div><?php endif; ?>

        <form method="POST" action="index.php?page=student_migration" onsubmit="return migCheckSameClass()">
            <?php echo csrf_field(); ?>
            <div class="row">
                <!-- Migrated From -->
                <div class="col-md-6">
                    <fieldset class="border rounded p-4" style="border-color:#38bdf8 !important;">
                        <legend class="float-none w-auto px-2 text-info fs-6">Migrated From</legend>
                        <div class="row mb-3 align-items-center">
                            <label class="col-sm-4 col-form-label fw-bold">Session <span class="text-danger">*</span></label>
                            <div class="col-sm-8">
                                <select name="from_session_id" class="form-select" required>
                                    <option value="">Select Session</option>
                                    <?php foreach ($sessions as $s): ?><option value="<?php echo (int) $s['id']; ?>"><?php echo mgv($s['session_name']); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3 align-items-center">
                            <label class="col-sm-4 col-form-label fw-bold">Class <span class="text-danger">*</span></label>
                            <div class="col-sm-8">
                                <select name="from_class_id" id="fromClassSelect" class="form-select" required onchange="filterFromSections()">
                                    <option value="">Select Class</option>
                                    <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo mgv($c['class_name']); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3 align-items-center">
                            <label class="col-sm-4 col-form-label fw-bold">Shift <span class="text-danger">*</span></label>
                            <div class="col-sm-8">
                                <select name="from_shift_id" class="form-select" required>
                                    <option value="">Select Shift</option>
                                    <?php foreach ($shifts as $sh): ?><option value="<?php echo (int) $sh['id']; ?>"><?php echo mgv($sh['shift_name']); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3 align-items-center">
                            <label class="col-sm-4 col-form-label fw-bold">Section <span class="text-danger">*</span></label>
                            <div class="col-sm-8">
                                <select name="from_section_id" id="fromSectionSelect" class="form-select" required disabled>
                                    <option value="">Select Class first</option>
                                    <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" hidden><?php echo mgv($sec['section_name']); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </fieldset>
                </div>

                <!-- Migrated To -->
                <div class="col-md-6">
                    <fieldset class="border rounded p-4" style="border-color:#38bdf8 !important;">
                        <legend class="float-none w-auto px-2 text-info fs-6">Migrated To</legend>
                        <div class="row mb-3 align-items-center">
                            <label class="col-sm-5 col-form-label fw-bold">Migrated Session <span class="text-danger">*</span></label>
                            <div class="col-sm-7">
                                <select name="to_session_id" class="form-select" required>
                                    <option value="">Select Migrated Session</option>
                                    <?php foreach ($sessions as $s): ?><option value="<?php echo (int) $s['id']; ?>"><?php echo mgv($s['session_name']); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3 align-items-center">
                            <label class="col-sm-5 col-form-label fw-bold">Migrated Class <span class="text-danger">*</span></label>
                            <div class="col-sm-7">
                                <select name="to_class_id" id="toClassSelect" class="form-select" required onchange="filterToSections()">
                                    <option value="">Select Migrated Class</option>
                                    <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo mgv($c['class_name']); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3 align-items-center">
                            <label class="col-sm-5 col-form-label fw-bold">Migrated Shift <span class="text-danger">*</span></label>
                            <div class="col-sm-7">
                                <select name="to_shift_id" class="form-select" required>
                                    <option value="">Select Migrated Shift</option>
                                    <?php foreach ($shifts as $sh): ?><option value="<?php echo (int) $sh['id']; ?>"><?php echo mgv($sh['shift_name']); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3 align-items-center">
                            <label class="col-sm-5 col-form-label fw-bold">Migrated Section <span class="text-danger">*</span></label>
                            <div class="col-sm-7">
                                <select name="to_section_id" id="toSectionSelect" class="form-select" required disabled>
                                    <option value="">Select Class first</option>
                                    <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" hidden><?php echo mgv($sec['section_name']); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </fieldset>
                </div>
            </div>

            <div class="text-center mt-4">
                <button type="submit" name="review_migration" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;">Review Migration</button>
            </div>
        </form>
    </div>

    <script>
        function filterFromSections() {
            const classId = document.getElementById('fromClassSelect').value;
            const select = document.getElementById('fromSectionSelect');
            select.disabled = !classId;
            Array.from(select.options).forEach(opt => {
                if (!opt.value) { opt.hidden = false; return; }
                opt.hidden = !classId || opt.dataset.class !== classId;
            });
            select.options[0].textContent = classId ? 'Select Section' : 'Select Class first';
            if (select.value && Array.from(select.options).find(o => o.value === select.value)?.hidden) {
                select.value = '';
            }
        }
        function filterToSections() {
            const classId = document.getElementById('toClassSelect').value;
            const select = document.getElementById('toSectionSelect');
            select.disabled = !classId;
            Array.from(select.options).forEach(opt => {
                if (!opt.value) { opt.hidden = false; return; }
                opt.hidden = !classId || opt.dataset.class !== classId;
            });
            select.options[0].textContent = classId ? 'Select Migrated Section' : 'Select Class first';
            if (select.value && Array.from(select.options).find(o => o.value === select.value)?.hidden) {
                select.value = '';
            }
        }
        filterFromSections();
        filterToSections();

        // Migrated From ও Migrated To হুবহু একই (Session+Class+Shift+Section) হলে Submit করার আগেই আটকে দেওয়া হয়
        function migCheckSameClass() {
            const f = document.querySelector('[name="from_session_id"]').value;
            const fc = document.getElementById('fromClassSelect').value;
            const fsh = document.querySelector('[name="from_shift_id"]').value;
            const fsec = document.getElementById('fromSectionSelect').value;
            const t = document.querySelector('[name="to_session_id"]').value;
            const tc = document.getElementById('toClassSelect').value;
            const tsh = document.querySelector('[name="to_shift_id"]').value;
            const tsec = document.getElementById('toSectionSelect').value;

            if (f && fc && fsh && fsec && f === t && fc === tc && fsh === tsh && fsec === tsec) {
                alert('Migrated From ও Migrated To একই Session/Class/Shift/Section হতে পারবে না — অন্তত একটা আলাদা করে সিলেক্ট করুন।');
                return false;
            }
            return true;
        }
    </script>
<?php endif; ?>
