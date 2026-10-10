<?php
// admin/result_working_days.php - Result Module > Exam Settings > Working Days
// স্ক্রিনশট অনুযায়ী ফর্ম (Exam Term, Classes, Class Start Date, Class End Date, Total Working Days)।
// Total Working Days ফিল্ডটা ম্যানুয়ালি লিখতে হয় না — Branch এর Weekly Holiday (যেমন Friday)
// আর নিচে "Academic Holidays / Calendar" এ যোগ করা ছুটির দিনগুলো বাদ দিয়ে অটো ক্যালকুলেট হয়ে বসে।
$wd_err = $_SESSION['wd_err'] ?? '';
$wd_msg = $_SESSION['wd_msg'] ?? '';
unset($_SESSION['wd_err'], $_SESSION['wd_msg']);

// exam_terms টেবিল এখনো তৈরি না হয়ে থাকতে পারে (Result Module > Exam Settings > Exam Terms
// পেজ কখনো ভিজিট না করা হলে) — অন্য কোনো Result পেজ থেকে সরাসরি এলেও যেন এরর না হয়,
// তাই এখানেও নিরাপদে auto-create করা হচ্ছে (result_exam_terms.php এর সাথে একই স্ট্রাকচার)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_terms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        term_name VARCHAR(100) NOT NULL,
        rank INT NOT NULL DEFAULT 1,
        is_final_term TINYINT(1) NOT NULL DEFAULT 0,
        is_aggregated_result TINYINT(1) NOT NULL DEFAULT 0,
        admit_card_with_routine TINYINT(1) NOT NULL DEFAULT 0,
        admit_card_with_instruction TINYINT(1) NOT NULL DEFAULT 0,
        exam_routine_with_instruction TINYINT(1) NOT NULL DEFAULT 0,
        website_result_publish TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_working_days (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        term_id INT NOT NULL,
        class_id INT NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        total_working_days INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_wd (term_id, class_id),
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// Academic Module > Academic Calendar এ ম্যানেজ করা হয় (academic_calendar.php)।
// এখানে শুধু READ করা হয় - ক্যালকুলেশনের জন্য।
// (টেবিলটা যদি Academic Calendar পেজ আগে থেকে ভিজিট না করা থাকে সেক্ষেত্রেও এখানে অটো তৈরি হবে)
require_once __DIR__ . '/../config/academic_calendar_helpers.php';
ac_ensure_tables($pdo);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS academic_holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        holiday_name VARCHAR(150) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        holiday_type VARCHAR(30) NOT NULL DEFAULT 'General',
        remarks VARCHAR(255) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

function wdv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// ব্রাঞ্চের সাপ্তাহিক ছুটি (যেমন "Friday" বা "Friday,Saturday")
$branch_row = $pdo->query("SELECT weekly_holidays FROM branches WHERE status='Active' ORDER BY id ASC LIMIT 1")->fetch();
$weekly_holidays = array_filter(array_map('trim', explode(',', $branch_row['weekly_holidays'] ?? 'Friday')));

// ---------------------------------------------------------------
// Working Days ক্যালকুলেশন হেল্পার - Weekly Holiday + Academic Holidays বাদ দিয়ে হিসাব
// ---------------------------------------------------------------
function calculate_working_days(PDO $pdo, string $start, string $end, array $weekly_holidays): int
{
    $s = strtotime($start);
    $e = strtotime($end);
    if (!$s || !$e || $e < $s) return 0;

    // সংশ্লিষ্ট রেঞ্জের একাডেমিক হলিডে ডেট গুলো একটা সেট এ নেওয়া হলো
    // শুধু "ছুটি ধরা হবে" Category-র এন্ট্রি বাদ যাবে (Exam/Event ইত্যাদি কাজের দিন থেকে যাবে)
    $holiday_ranges = ac_holiday_ranges($pdo, $start, $end);

    $total = 0;
    for ($t = $s; $t <= $e; $t += 86400) {
        $day_name = date('l', $t);
        if (in_array($day_name, $weekly_holidays, true)) continue;

        $ymd = date('Y-m-d', $t);
        $is_holiday = false;
        foreach ($holiday_ranges as $hr) {
            if ($ymd >= $hr['start_date'] && $ymd <= $hr['end_date']) { $is_holiday = true; break; }
        }
        if ($is_holiday) continue;

        $total++;
    }
    return $total;
}

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['wd_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_working_days');
        exit();
    }

    if (isset($_POST['save_working_days'])) {
        $term_id     = (int) ($_POST['term_id'] ?? 0);
        $class_ids   = $_POST['class_id'] ?? [];
        $start_date  = trim($_POST['start_date'] ?? '');
        $end_date    = trim($_POST['end_date'] ?? '');

        if ($term_id <= 0 || !$class_ids || $start_date === '' || $end_date === '' || strtotime($end_date) < strtotime($start_date)) {
            $_SESSION['wd_err'] = 'Exam Term, Classes, Start Date, End Date সঠিকভাবে দিন (End Date অবশ্যই Start Date এর পরে হতে হবে)।';
        } else {
            $total = calculate_working_days($pdo, $start_date, $end_date, $weekly_holidays);
            $stmt = $pdo->prepare(
                "INSERT INTO exam_working_days (session_id, term_id, class_id, start_date, end_date, total_working_days, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'Active')
                 ON DUPLICATE KEY UPDATE start_date=VALUES(start_date), end_date=VALUES(end_date), total_working_days=VALUES(total_working_days), status='Active'"
            );
            foreach ($class_ids as $cid) {
                $stmt->execute([$current_session_id, $term_id, (int) $cid, $start_date, $end_date, $total]);
            }
            sec_log_activity($pdo, 'Result', 'Update', "টার্ম আইডি {$term_id} এর Working Days সেভ করা হয়েছে ({$total} দিন)");
            $_SESSION['wd_msg'] = "Working Days সেভ হয়েছে। মোট Working Days : $total দিন।";
        }
    } elseif (isset($_POST['delete_working_days'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM exam_working_days WHERE id = ?")->execute([$id]);
        sec_log_activity($pdo, 'Result', 'Delete', "Working Days এন্ট্রি মুছে ফেলা হয়েছে: আইডি {$id}");
        $_SESSION['wd_msg'] = 'Working Days এন্ট্রি মুছে ফেলা হয়েছে।';
    }

    header('Location: index.php?page=result_working_days');
    exit();
}

$view = $_GET['view'] ?? 'list';
$terms_stmt = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id=? AND status='Active' ORDER BY rank ASC");
$terms_stmt->execute([$current_session_id]);
$terms = $terms_stmt->fetchAll();
$classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status='Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
?>

<?php if ($view === 'add'): ?>
    <?php
    $sel_term  = (int) ($_GET['term_id'] ?? 0);
    $sel_class = array_map('intval', (array) ($_GET['class_id'] ?? []));
    $start_date = trim($_GET['start_date'] ?? '');
    $end_date   = trim($_GET['end_date'] ?? '');
    $preview_total = null;
    if ($start_date !== '' && $end_date !== '' && strtotime($end_date) >= strtotime($start_date)) {
        $preview_total = calculate_working_days($pdo, $start_date, $end_date, $weekly_holidays);
    }
    ?>
    <div class="card-custom">
        <?php if ($wd_err): ?><div class="alert alert-danger py-2"><?php echo wdv($wd_err); ?></div><?php endif; ?>
        <?php if ($wd_msg): ?><div class="alert alert-success py-2"><?php echo wdv($wd_msg); ?></div><?php endif; ?>

        <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;">
            <i class="fa-solid fa-file-lines text-white"></i>
        </div>

        <div class="alert alert-info small">
            <i class="fa-solid fa-circle-info"></i>
            Total Working Days অটো ক্যালকুলেট হয় — Branch এর Weekly Holiday (<?php echo wdv(implode(', ', $weekly_holidays) ?: 'সেট করা নেই'); ?>)
            এবং <a href="index.php?page=academic_calendar" class="fw-bold">Academic Module &gt; Academic Calendar</a> এ যোগ করা ছুটির দিনগুলো বাদ দিয়ে।
        </div>

        <!-- এই ফর্মটা GET - শুধু প্রথমবার পেজ ওপেন/এডিট লিংক থেকে prefill করার জন্য।
             Term/Classes/Date বদলালে এখন আর submit হয় না — নিচের JS AJAX দিয়ে লাইভ প্রিভিউ আনে। -->
        <form method="GET" action="index.php" id="wdFilterForm">
            <input type="hidden" name="page" value="result_working_days">
            <input type="hidden" name="view" value="add">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Exam Term <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="term_id" id="wd_term" class="form-select">
                        <option value="0">Select Exam Term</option>
                        <?php foreach ($terms as $t): ?>
                            <option value="<?php echo (int) $t['id']; ?>" <?php echo $sel_term === (int) $t['id'] ? 'selected' : ''; ?>><?php echo wdv($t['term_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-4 align-items-start">
                <label class="col-sm-3 col-form-label fw-bold text-end">Classes <span class="text-danger">*</span></label>
                <div class="col-sm-8">
                    <div class="wd-class-box border rounded p-2">
                        <div class="form-check mb-2 pb-2 border-bottom">
                            <input class="form-check-input" type="checkbox" id="wdSelectAllClasses" <?php echo ($classes && count($sel_class) === count($classes)) ? 'checked' : ''; ?> onclick="wdToggleAllClasses(this)">
                            <label class="form-check-label fw-bold" for="wdSelectAllClasses">সব ক্লাস সিলেক্ট করুন (Select All)</label>
                        </div>
                        <div class="row">
                            <?php foreach ($classes as $c): $cid = (int) $c['id']; ?>
                                <div class="col-sm-4 col-6">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input wd-class-chk" type="checkbox" name="class_id[]" value="<?php echo $cid; ?>" id="wdClass_<?php echo $cid; ?>" <?php echo in_array($cid, $sel_class, true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="wdClass_<?php echo $cid; ?>"><?php echo wdv($c['class_name']); ?></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <small class="text-muted">Academic Module &gt; Class লিস্ট থেকে ক্লাসগুলো আসছে। একাধিক ক্লাস টিক দিয়ে একসাথে সিলেক্ট করা যাবে।</small>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Class Start Date <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="date" name="start_date" id="wd_start_date" class="form-control" value="<?php echo wdv($start_date); ?>" required></div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Class End Date <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="date" name="end_date" id="wd_end_date" class="form-control" value="<?php echo wdv($end_date); ?>" required></div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Total Working Days <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="text" id="wd_total_display" class="form-control fw-bold" value="<?php echo $preview_total === null ? '—' : $preview_total . ' Days'; ?>" readonly style="background:#eef2ff;">
                </div>
                <div class="col-sm-3"><span class="text-muted small">Auto Calculated</span></div>
            </div>
        </form>

        <!-- এই ফর্মটা POST - উপরের ফর্মের বর্তমান সিলেকশন JS দিয়ে hidden field-এ সিঙ্ক হয়ে সেভ করে -->
        <form method="POST" action="index.php?page=result_working_days" id="wdSaveForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="term_id" id="wd_hidden_term" value="<?php echo $sel_term; ?>">
            <span id="wd_hidden_classes"><?php foreach ($sel_class as $cid): ?><input type="hidden" name="class_id[]" value="<?php echo $cid; ?>"><?php endforeach; ?></span>
            <input type="hidden" name="start_date" id="wd_hidden_start" value="<?php echo wdv($start_date); ?>">
            <input type="hidden" name="end_date" id="wd_hidden_end" value="<?php echo wdv($end_date); ?>">

            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_working_days" id="wd_save_btn" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;" <?php echo (!$sel_term || !$sel_class || $preview_total === null) ? 'disabled' : ''; ?>>Create</button>
                    <a href="index.php?page=result_working_days" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>

        <!-- ================= Academic Holidays (এখন Academic Module থেকে ম্যানেজ করা হয়) ================= -->
        <h5 class="section-heading3 mt-5" id="academicHolidays"><i class="fa-solid fa-calendar-xmark"></i> Academic Holidays (Off Days)</h5>
        <p class="text-muted small">
            Holiday এখন থেকে
            <a href="index.php?page=academic_calendar" class="fw-bold">Academic Module &gt; Academic Calendar</a>
            থেকে ম্যানেজ করা হয় — সেখানে যোগ করা প্রতিটা তারিখ-রেঞ্জ এই Working Days ক্যালকুলেশনে অটো বাদ যাবে।
        </p>

        <?php $ac_cats_wd = ac_categories($pdo, true); $holidays = array_values(array_filter($pdo->query("SELECT * FROM academic_holidays WHERE status='Active' ORDER BY start_date ASC")->fetchAll(), fn($h) => ac_is_holiday_type($ac_cats_wd, $h['holiday_type']))); ?>
        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
                <thead class="table-light"><tr><th>Holiday Name</th><th>Start Date</th><th>End Date</th><th>Days</th><th>Type</th></tr></thead>
                <tbody>
                    <?php if ($holidays): foreach ($holidays as $h): $days = (int) ((strtotime($h['end_date']) - strtotime($h['start_date'])) / 86400) + 1; ?>
                        <tr>
                            <td><?php echo wdv($h['holiday_name']); ?></td>
                            <td><?php echo wdv($h['start_date']); ?></td>
                            <td><?php echo wdv($h['end_date']); ?></td>
                            <td><?php echo $days; ?></td>
                            <td><span class="badge bg-info text-dark"><?php echo wdv($h['holiday_type'] ?? 'General'); ?></span></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5" class="text-center text-muted py-2">এখনো কোনো Holiday যোগ করা হয়নি — <a href="index.php?page=academic_calendar&view=add">Academic Calendar থেকে যোগ করুন</a></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>
    <?php
    $sql = "SELECT ewd.*, t.term_name, c.class_name FROM exam_working_days ewd
            LEFT JOIN exam_terms t ON t.id = ewd.term_id
            LEFT JOIN academic_classes c ON c.id = ewd.class_id
            WHERE ewd.session_id = ?
            ORDER BY t.rank ASC, c.rank ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$current_session_id]);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($wd_msg): ?><div class="alert alert-success py-2"><?php echo wdv($wd_msg); ?></div><?php endif; ?>
        <?php if ($wd_err): ?><div class="alert alert-danger py-2"><?php echo wdv($wd_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=result_working_days&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
            <span class="text-muted small">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> item.</span>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Srl.</th>
                        <th>Exam Term</th>
                        <th>Class</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Total Working Days</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo wdv($row['term_name']); ?></td>
                            <td><?php echo wdv($row['class_name']); ?></td>
                            <td><?php echo wdv($row['start_date']); ?></td>
                            <td><?php echo wdv($row['end_date']); ?></td>
                            <td><span class="badge bg-primary"><?php echo (int) $row['total_working_days']; ?> Days</span></td>
                            <td class="text-nowrap">
                                <a href="index.php?page=result_working_days&view=add&term_id=<?php echo (int) $row['term_id']; ?>&class_id[]=<?php echo (int) $row['class_id']; ?>&start_date=<?php echo wdv($row['start_date']); ?>&end_date=<?php echo wdv($row['end_date']); ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <form method="POST" action="index.php?page=result_working_days" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" name="delete_working_days" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" class="text-center text-muted py-3">No data found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<style>
.section-heading3 { color: #1e3a8a; font-weight: 700; border-bottom: 2px solid #e9ecef; padding-bottom: 8px; margin: 10px 0 14px; }
.wd-class-box { max-height: 260px; overflow-y: auto; background: #fff; }
</style>

<script>
// "সব ক্লাস সিলেক্ট করুন" চেকবক্স চাপলে সব ক্লাস চেকবক্স টিক/আনটিক হবে এবং AJAX দিয়ে Total Working Days রিক্যালকুলেট হবে (পেজ রিলোড ছাড়াই)
function wdToggleAllClasses(master) {
    document.querySelectorAll('.wd-class-chk').forEach(function (chk) { chk.checked = master.checked; });
    wdLoadPreview();
}

(function () {
    var elTerm  = document.getElementById('wd_term');
    var elStart = document.getElementById('wd_start_date');
    var elEnd   = document.getElementById('wd_end_date');
    var elTotal = document.getElementById('wd_total_display');
    var elSaveBtn = document.getElementById('wd_save_btn');

    var hTerm    = document.getElementById('wd_hidden_term');
    var hClasses = document.getElementById('wd_hidden_classes');
    var hStart   = document.getElementById('wd_hidden_start');
    var hEnd     = document.getElementById('wd_hidden_end');

    if (!elTerm) return; // "list" view এ এই ফর্ম নেই

    window.wdLoadPreview = function () {
        var termId   = elTerm.value;
        var classIds = Array.prototype.map.call(document.querySelectorAll('.wd-class-chk:checked'), function (c) { return c.value; });
        var start    = elStart.value;
        var end      = elEnd.value;

        // POST ফর্মের hidden field গুলো সবসময় বর্তমান সিলেকশনের সাথে সিঙ্ক রাখা হচ্ছে
        hTerm.value = termId;
        hStart.value = start;
        hEnd.value = end;
        hClasses.innerHTML = '';
        classIds.forEach(function (cid) {
            var inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'class_id[]'; inp.value = cid;
            hClasses.appendChild(inp);
        });

        if (termId == '0' || !classIds.length || !start || !end) {
            elTotal.value = '—';
            elSaveBtn.disabled = true;
            return;
        }

        var p = new URLSearchParams();
        p.set('action', 'preview');
        p.set('term_id', termId);
        p.set('start_date', start);
        p.set('end_date', end);
        classIds.forEach(function (cid) { p.append('class_id[]', cid); });

        fetch('ajax/result_working_days_ajax.php?' + p.toString())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.valid) {
                    elTotal.value = '—';
                    elSaveBtn.disabled = true;
                    return;
                }
                elTotal.value = data.total + ' Days';
                elSaveBtn.disabled = false;
            })
            .catch(function () {
                elTotal.value = '—';
                elSaveBtn.disabled = true;
            });
    };

    elTerm.addEventListener('change', wdLoadPreview);
    elStart.addEventListener('change', wdLoadPreview);
    elEnd.addEventListener('change', wdLoadPreview);
    document.querySelectorAll('.wd-class-chk').forEach(function (chk) {
        chk.addEventListener('change', wdLoadPreview);
    });
})();
</script>
