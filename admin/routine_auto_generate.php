<?php
// admin/routine_auto_generate.php - Smart Routine Creator (Auto-generate)
// Class + Shift + Section বেছে, সাবজেক্ট-ভিত্তিক periods/week আর সময়-সেটিংস দিয়ে
// স্বয়ংক্রিয়ভাবে সপ্তাহের রুটিন বসিয়ে দেয়। একই routines টেবিলে সেভ হয়, তাই
// General Routine / Dynamic Routine পেজেও একই ডাটা দেখা যাবে।
//
// Flow: Step 1 (সেটিংস ফর্ম) -> generate_preview (server-side বসানো, DB-তে সেভ হয় না)
//       -> Step 2 (প্রিভিউ) -> confirm_save (DB-তে বসানো)
//
// Teacher assignment auto-detect হয় teacher_subject_assignments টেবিল থেকে
// (Result Module > Assign Subject to Teacher পেজে যা সেট করা হয়)।
// Conflict-check: একজন টিচার একই দিনে/সময়ে স্কুলের অন্য কোনো ক্লাস/সেকশনে আগে থেকে
// বসানো থাকলে সেই স্লট এড়িয়ে যাওয়া হয় (routines টেবিলের বিপরীতে চেক করে)।
//
// সীমাবদ্ধতা (v1): "আগের রুটিন মুছে নতুনটা বসাও" আনচেক রেখে regenerate করলে আগের
// ম্যানুয়াল এন্ট্রির সাথে সময় ওভারল্যাপ হতে পারে — তাই ডিফল্ট ও প্রস্তাবিত ব্যবহার হলো
// checked রেখেই চালানো।

$ag_err = $_SESSION['ag_err'] ?? '';
$ag_msg = $_SESSION['ag_msg'] ?? '';
unset($_SESSION['ag_err'], $_SESSION['ag_msg']);

function agv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি (routine_general.php/routine_view.php/routine_dynamic.php একই টেবিল শেয়ার করে)
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS routines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NULL,
        shift_id INT NULL,
        section_id INT NOT NULL,
        routine_days VARCHAR(100) NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        has_break TINYINT(1) NOT NULL DEFAULT 0,
        subject_id INT NOT NULL,
        teacher_id INT NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        branch_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_lookup (class_id, shift_id, section_id),
        KEY idx_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
branch_ensure_column($pdo, 'routines');

$week_days = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
$teachers = $pdo->query("SELECT id, name FROM employees WHERE type = 'Teachers' AND status = 'Active' ORDER BY name ASC")->fetchAll();

$sel_class   = (int) ($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$sel_shift   = (int) ($_GET['shift_id'] ?? $_POST['shift_id'] ?? 0);
$sel_section = (int) ($_GET['section_id'] ?? $_POST['section_id'] ?? 0);

$subjects = [];
if ($sel_class) {
    $sq = $pdo->prepare("SELECT id, subject_name FROM academic_subjects WHERE class_id = ? AND status = 'Active' ORDER BY sort_order ASC, id ASC");
    $sq->execute([$sel_class]);
    $subjects = $sq->fetchAll();
}

// এই class-এ কোন সাবজেক্টে কোন টিচার এসাইন করা আছে (Result Module > Assign Subject to Teacher থেকে) — ফর্মে অটো-সিলেক্ট করার জন্য
$assigned_teachers = [];
if ($sel_class) {
    $at = $pdo->prepare(
        "SELECT tsa.subject_id, tsa.employee_id, e.name AS teacher_name
         FROM teacher_subject_assignments tsa
         JOIN employees e ON e.id = tsa.employee_id
         WHERE tsa.class_id = ?"
    );
    $at->execute([$sel_class]);
    foreach ($at->fetchAll() as $r) {
        if (!isset($assigned_teachers[$r['subject_id']])) {
            $assigned_teachers[$r['subject_id']] = ['id' => $r['employee_id'], 'name' => $r['teacher_name']];
        }
    }
}

// -----------------------------------------------------------
// পিরিয়ড/সময়-স্লট বানানো — প্রতি পিরিয়ড ৪০ মিনিট ফিক্সড, break_after নম্বর পিরিয়ডের পর ব্রেক
// -----------------------------------------------------------
function ag_build_slots(string $start_time, int $periods_per_day, int $break_after, int $break_minutes): array
{
    $slots = [];
    $cursor = strtotime($start_time ?: '08:00');
    for ($p = 1; $p <= $periods_per_day; $p++) {
        $s = $cursor;
        $e = $cursor + 40 * 60;
        $slots[$p] = ['start' => date('H:i:s', $s), 'end' => date('H:i:s', $e)];
        $cursor = $e;
        if ($break_after > 0 && $p === $break_after && $break_minutes > 0) {
            $cursor += $break_minutes * 60;
        }
    }
    return $slots;
}

// -----------------------------------------------------------
// STEP 2: Preview জেনারেট করা (সরাসরি DB-তে সেভ হয় না, শুধু স্ক্রিনে দেখানো হয়)
// -----------------------------------------------------------
$preview = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_preview'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ag_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (!$sel_class || !$sel_shift || !$sel_section) {
        $_SESSION['ag_err'] = 'Class, Shift, Section — তিনটাই বাছাই করুন।';
    } else {
        $start_time      = trim($_POST['start_time'] ?? '08:00');
        $periods_per_day = max(1, (int) ($_POST['periods_per_day'] ?? 6));
        $break_after     = (int) ($_POST['break_after'] ?? 0);
        $break_minutes   = (int) ($_POST['break_minutes'] ?? 0);
        $work_days       = array_values(array_intersect($week_days, (array) ($_POST['work_days'] ?? [])));

        $subj_ids  = (array) ($_POST['subject_id'] ?? []);
        $subj_ppw  = (array) ($_POST['periods_per_week'] ?? []);
        $subj_tchr = (array) ($_POST['teacher_id'] ?? []);

        if (!$work_days) {
            $_SESSION['ag_err'] = 'অন্তত একটা কর্মদিবস বাছাই করুন।';
        } else {
            $plan = [];
            foreach ($subj_ids as $i => $sid) {
                $sid = (int) $sid;
                $ppw = max(0, (int) ($subj_ppw[$i] ?? 0));
                $tid = (int) ($subj_tchr[$i] ?? 0);
                if ($sid && $ppw && $tid) {
                    $plan[] = ['subject_id' => $sid, 'periods_per_week' => $ppw, 'teacher_id' => $tid];
                }
            }

            if (!$plan) {
                $_SESSION['ag_err'] = 'অন্তত একটা সাবজেক্টে Periods/Week আর Teacher দিন।';
            } else {
                $slots = ag_build_slots($start_time, $periods_per_day, $break_after, $break_minutes);

                // স্কুলের অন্য সব ক্লাস/সেকশনে (এই class/shift/section বাদে) এই টিচারদের
                // আগে থেকে বসানো পিরিয়ড লোড করা হচ্ছে — conflict এড়ানোর জন্য
                $teacher_ids = array_values(array_unique(array_column($plan, 'teacher_id')));
                $busy = []; // busy[teacher_id][day] = [[start,end], ...]
                if ($teacher_ids) {
                    $in = implode(',', array_fill(0, count($teacher_ids), '?'));
                    $bq = $pdo->prepare(
                        "SELECT teacher_id, routine_days, start_time, end_time FROM routines
                         WHERE status = 'Active' AND teacher_id IN ($in)
                         AND NOT (class_id = ? AND shift_id = ? AND section_id = ?)"
                    );
                    $bq->execute(array_merge($teacher_ids, [$sel_class, $sel_shift, $sel_section]));
                    foreach ($bq->fetchAll() as $row) {
                        if (!$row['start_time'] || !$row['end_time']) continue;
                        foreach (explode(',', (string) $row['routine_days']) as $d) {
                            $d = trim($d);
                            if ($d !== '') $busy[$row['teacher_id']][$d][] = [$row['start_time'], $row['end_time']];
                        }
                    }
                }
                $overlaps = function ($s1, $e1, $s2, $e2) { return $s1 < $e2 && $s2 < $e1; };

                $subject_names = [];
                foreach ($subjects as $s) { $subject_names[$s['id']] = $s['subject_name']; }
                $teacher_names = [];
                foreach ($teachers as $t) { $teacher_names[$t['id']] = $t['name']; }

                $grid = [];
                foreach ($work_days as $d) { $grid[$d] = []; }
                $unplaced = [];

                foreach ($plan as $item) {
                    $sid  = $item['subject_id'];
                    $tid  = $item['teacher_id'];
                    $need = $item['periods_per_week'];
                    $placed = 0;
                    $attempt = 0;
                    $max_attempts = max(1, $need) * count($work_days) * $periods_per_day + count($work_days);

                    while ($placed < $need && $attempt < $max_attempts) {
                        $day = $work_days[$attempt % count($work_days)];
                        $attempt++;

                        // একই দিনে একই সাবজেক্ট আগে থেকে থাকলে — অন্য দিনে জায়গা থাকা পর্যন্ত এড়িয়ে যাওয়া
                        $already_today = false;
                        foreach ($grid[$day] as $g) { if ($g['subject_id'] === $sid) { $already_today = true; break; } }
                        if ($already_today && $placed < count($work_days)) continue;

                        for ($p = 1; $p <= $periods_per_day; $p++) {
                            if (isset($grid[$day][$p])) continue; // সেকশনের এই স্লট আগে থেকেই ভরা
                            $st = $slots[$p]['start']; $et = $slots[$p]['end'];

                            $clash = false;
                            foreach (($busy[$tid][$day] ?? []) as [$bs, $be]) {
                                if ($overlaps($st, $et, $bs, $be)) { $clash = true; break; }
                            }
                            if ($clash) continue;

                            $grid[$day][$p] = [
                                'subject_id' => $sid, 'subject_name' => $subject_names[$sid] ?? '',
                                'teacher_id' => $tid, 'teacher_name' => $teacher_names[$tid] ?? '',
                                'start' => $st, 'end' => $et,
                            ];
                            $busy[$tid][$day][] = [$st, $et];
                            $placed++;
                            break;
                        }
                    }

                    if ($placed < $need) {
                        $unplaced[] = ($subject_names[$sid] ?? $sid) . ' — ' . ($need - $placed) . ' টা পিরিয়ড বসানো যায়নি (স্লট/টিচার খালি নেই)';
                    }
                }

                $preview = [
                    'grid' => $grid, 'unplaced' => $unplaced, 'work_days' => $work_days,
                    'periods_per_day' => $periods_per_day,
                ];
                $_SESSION['ag_preview'] = $preview + ['class_id' => $sel_class, 'shift_id' => $sel_shift, 'section_id' => $sel_section];
            }
        }
    }
}

// -----------------------------------------------------------
// STEP 3: Preview কনফার্ম করে DB-তে সেভ করা
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_save'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ag_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (empty($_SESSION['ag_preview'])) {
        $_SESSION['ag_err'] = 'প্রথমে Preview জেনারেট করুন।';
    } else {
        $pv = $_SESSION['ag_preview'];
        $pdo->beginTransaction();
        try {
            if (($_POST['clear_existing'] ?? '') === 'YES') {
                $del = $pdo->prepare(
                    "DELETE FROM routines WHERE class_id = ? AND shift_id = ? AND section_id = ?" .
                    (branch_current_id() !== null ? " AND (branch_id = ? OR branch_id IS NULL)" : "")
                );
                $del_params = [$pv['class_id'], $pv['shift_id'], $pv['section_id']];
                if (branch_current_id() !== null) { $del_params[] = branch_current_id(); }
                $del->execute($del_params);
            }

            $ins = $pdo->prepare(
                "INSERT INTO routines (class_id, shift_id, section_id, routine_days, start_time, end_time, has_break, subject_id, teacher_id, status, branch_id) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 'Active', ?)"
            );
            $count = 0;
            foreach ($pv['grid'] as $day => $periods) {
                foreach ($periods as $p) {
                    $ins->execute([$pv['class_id'], $pv['shift_id'], $pv['section_id'], $day, $p['start'], $p['end'], $p['subject_id'], $p['teacher_id'], branch_id_for_insert()]);
                    $count++;
                }
            }
            $pdo->commit();
            unset($_SESSION['ag_preview']);
            sec_log_activity($pdo, 'Routine', 'Create', "ক্লাস আইডি {$pv['class_id']}, শিফট আইডি {$pv['shift_id']}, সেকশন আইডি {$pv['section_id']} এর Auto Generated Routine সেভ করা হয়েছে ({$count} টা পিরিয়ড)");
            $_SESSION['ag_msg'] = "রুটিন তৈরি হয়ে গেছে — মোট {$count} টা পিরিয়ড বসানো হয়েছে।";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $_SESSION['ag_err'] = 'সেভ করতে সমস্যা হয়েছে, আবার চেষ্টা করুন।';
        }
        header('Location: index.php?page=routine_auto_generate&class_id=' . $pv['class_id'] . '&shift_id=' . $pv['shift_id'] . '&section_id=' . $pv['section_id']);
        exit();
    }
}
?>
<div class="card-custom">
    <?php if ($ag_msg): ?><div class="alert alert-success py-2"><?php echo agv($ag_msg); ?></div><?php endif; ?>
    <?php if ($ag_err): ?><div class="alert alert-danger py-2"><?php echo agv($ag_err); ?></div><?php endif; ?>

    <form method="GET" action="index.php" class="row g-2 mb-3">
        <input type="hidden" name="page" value="routine_auto_generate">
        <div class="col-md-4">
            <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
            <select name="class_id" id="agClassSelect" class="form-select" onchange="this.form.submit()">
                <option value="">Select Class</option>
                <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $sel_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo agv($c['class_name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-bold">Shift <span class="text-danger">*</span></label>
            <select name="shift_id" class="form-select" onchange="this.form.submit()">
                <option value="">Select Shift</option>
                <?php foreach ($shifts as $sh): ?><option value="<?php echo (int) $sh['id']; ?>" <?php echo $sel_shift === (int) $sh['id'] ? 'selected' : ''; ?>><?php echo agv($sh['shift_name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-bold">Section <span class="text-danger">*</span></label>
            <select name="section_id" id="agSectionSelect" class="form-select" <?php echo $sel_class ? '' : 'disabled'; ?> onchange="this.form.submit()">
                <option value=""><?php echo $sel_class ? 'Select Section' : 'Select Class first'; ?></option>
                <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" <?php echo $sel_section === (int) $sec['id'] ? 'selected' : ''; ?> <?php echo (!$sel_class || (int) $sec['class_id'] !== $sel_class) ? 'hidden' : ''; ?>><?php echo agv($sec['section_name']); ?></option><?php endforeach; ?>
            </select>
        </div>
    </form>
    <script>
        (function () {
            const classId = document.getElementById('agClassSelect').value;
            const select = document.getElementById('agSectionSelect');
            select.disabled = !classId;
            Array.from(select.options).forEach(opt => {
                if (!opt.value) { opt.hidden = false; return; }
                opt.hidden = !classId || opt.dataset.class !== classId;
            });
        })();
    </script>

    <?php if (!$sel_class || !$sel_shift || !$sel_section): ?>
        <p class="text-muted">উপরে Class, Shift ও Section — তিনটাই বাছাই করুন।</p>
    <?php elseif (!$subjects): ?>
        <p class="text-muted">এই Class-এ কোনো Active সাবজেক্ট পাওয়া যায়নি — আগে Academic Module থেকে সাবজেক্ট যোগ করুন।</p>
    <?php elseif ($preview): ?>
        <!-- ================= STEP 2: Preview ================= -->
        <h6 class="fw-bold mb-3">প্রিভিউ — কনফার্ম করার আগে দেখে নিন</h6>
        <?php if ($preview['unplaced']): ?>
            <div class="alert alert-warning py-2">
                <strong>কিছু পিরিয়ড বসানো যায়নি:</strong>
                <ul class="mb-0"><?php foreach ($preview['unplaced'] as $u): ?><li><?php echo agv($u); ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <table class="table table-bordered align-middle">
            <thead class="table-light"><tr><th style="width:140px;">Day</th><th>Periods</th></tr></thead>
            <tbody>
                <?php foreach ($preview['work_days'] as $day): ?>
                    <tr>
                        <td class="fw-bold"><?php echo $day; ?></td>
                        <td>
                            <div class="d-flex flex-wrap gap-2">
                                <?php for ($p = 1; $p <= $preview['periods_per_day']; $p++): ?>
                                    <?php if (isset($preview['grid'][$day][$p])): $e = $preview['grid'][$day][$p]; ?>
                                        <div class="border rounded px-2 py-1 small" style="background:#f8fafc;">
                                            <strong><?php echo agv($e['subject_name']); ?></strong> — <?php echo agv($e['teacher_name']); ?><br>
                                            <span class="text-muted"><?php echo date('h:i A', strtotime($e['start'])); ?> - <?php echo date('h:i A', strtotime($e['end'])); ?></span>
                                        </div>
                                    <?php else: ?>
                                        <div class="border rounded px-2 py-1 small text-muted">খালি</div>
                                    <?php endif; ?>
                                <?php endfor; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <form method="POST" action="index.php?page=routine_auto_generate&class_id=<?php echo $sel_class; ?>&shift_id=<?php echo $sel_shift; ?>&section_id=<?php echo $sel_section; ?>" class="d-flex align-items-center flex-wrap gap-3" onsubmit="return confirm('এই রুটিন সেভ করে দিবেন?');">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
            <input type="hidden" name="shift_id" value="<?php echo $sel_shift; ?>">
            <input type="hidden" name="section_id" value="<?php echo $sel_section; ?>">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" name="clear_existing" id="agClearExisting" value="YES" checked>
                <label class="form-check-label" for="agClearExisting">এই Class/Section-এর আগের রুটিন মুছে নতুনটা বসাও</label>
            </div>
            <button type="submit" name="confirm_save" class="btn btn-success">রুটিন সেভ করো</button>
            <a href="index.php?page=routine_auto_generate&class_id=<?php echo $sel_class; ?>&shift_id=<?php echo $sel_shift; ?>&section_id=<?php echo $sel_section; ?>" class="btn btn-outline-secondary">বাতিল / আবার সেট করো</a>
        </form>

    <?php else: ?>
        <!-- ================= STEP 1: সেটিংস ফর্ম ================= -->
        <form method="POST" action="index.php?page=routine_auto_generate&class_id=<?php echo $sel_class; ?>&shift_id=<?php echo $sel_shift; ?>&section_id=<?php echo $sel_section; ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
            <input type="hidden" name="shift_id" value="<?php echo $sel_shift; ?>">
            <input type="hidden" name="section_id" value="<?php echo $sel_section; ?>">

            <h6 class="fw-bold mb-2">সময়-সেটিংস (প্রতি পিরিয়ড ৪০ মিনিট ফিক্সড)</h6>
            <div class="row mb-3">
                <div class="col-md-3">
                    <label class="form-label">দিনের প্রথম পিরিয়ড শুরু</label>
                    <input type="time" name="start_time" class="form-control" value="08:00" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">প্রতিদিন মোট পিরিয়ড</label>
                    <input type="number" name="periods_per_day" class="form-control" value="6" min="1" max="12" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">ব্রেক — কত নম্বর পিরিয়ডের পর</label>
                    <input type="number" name="break_after" class="form-control" value="3" min="0" max="12">
                    <div class="form-text">০ দিলে ব্রেক থাকবে না</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">ব্রেকের সময় (মিনিট)</label>
                    <input type="number" name="break_minutes" class="form-control" value="20" min="0" max="120">
                </div>
            </div>

            <h6 class="fw-bold mb-2">কর্মদিবস</h6>
            <div class="d-flex flex-wrap gap-3 mb-4">
                <?php foreach ($week_days as $d): ?>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="work_days[]" id="agDay<?php echo $d; ?>" value="<?php echo $d; ?>" <?php echo $d !== 'Friday' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="agDay<?php echo $d; ?>"><?php echo $d; ?></label>
                    </div>
                <?php endforeach; ?>
            </div>

            <h6 class="fw-bold mb-2">সাবজেক্ট-ভিত্তিক Periods/Week ও Teacher</h6>
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr><th>Subject</th><th style="width:160px;">Periods/Week</th><th style="width:260px;">Teacher</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($subjects as $s): ?>
                        <?php $at = $assigned_teachers[$s['id']] ?? null; ?>
                        <tr>
                            <td>
                                <?php echo agv($s['subject_name']); ?>
                                <input type="hidden" name="subject_id[]" value="<?php echo (int) $s['id']; ?>">
                            </td>
                            <td><input type="number" name="periods_per_week[]" class="form-control form-control-sm" value="0" min="0" max="20"></td>
                            <td>
                                <select name="teacher_id[]" class="form-select form-select-sm">
                                    <option value="">Select Teacher</option>
                                    <?php foreach ($teachers as $t): ?>
                                        <option value="<?php echo (int) $t['id']; ?>" <?php echo ($at && (int) $at['id'] === (int) $t['id']) ? 'selected' : ''; ?>><?php echo agv($t['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($at): ?><div class="form-text">Assign Subject to Teacher থেকে অটো-সিলেক্ট হয়েছে</div><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <button type="submit" name="generate_preview" class="btn btn-success"><i class="fa-solid fa-wand-magic-sparkles"></i> প্রিভিউ জেনারেট করো</button>
        </form>
    <?php endif; ?>
</div>
