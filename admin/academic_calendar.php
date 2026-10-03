<?php
// admin/academic_calendar.php - Academic Module > Academic Calendar (Holidays / Off Days)
// এখানে যোগ করা holiday date-range গুলো পুরো সিস্টেমের সব জায়গায় ব্যবহার হবে —
// যেমন Result Module > Exam Settings > Working Days এর Total Working Days ক্যালকুলেশন।
$ac_err = $_SESSION['ac_err'] ?? '';
$ac_msg = $_SESSION['ac_msg'] ?? '';
unset($_SESSION['ac_err'], $_SESSION['ac_msg']);

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি (result_working_days.php এর সাথে একই টেবিল শেয়ার করা হয়)
// ---------------------------------------------------------------
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

$holiday_types = ['General', 'Government', 'Religious', 'Vacation', 'Emergency'];

function acv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ac_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=academic_calendar');
        exit();
    }

    if (isset($_POST['save_holiday'])) {
        $id      = (int) ($_POST['id'] ?? 0);
        $name    = trim($_POST['holiday_name'] ?? '');
        $start   = trim($_POST['start_date'] ?? '');
        $end     = trim($_POST['end_date'] ?? '');
        $type    = in_array($_POST['holiday_type'] ?? '', $holiday_types, true) ? $_POST['holiday_type'] : 'General';
        $remarks = trim($_POST['remarks'] ?? '');
        $status  = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if ($name === '' || $start === '' || $end === '' || strtotime($end) < strtotime($start)) {
            $_SESSION['ac_err'] = 'Holiday Name, Start Date, End Date সঠিকভাবে দিন (End Date অবশ্যই Start Date এর পরে বা সমান হতে হবে)।';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE academic_holidays SET holiday_name=?, start_date=?, end_date=?, holiday_type=?, remarks=?, status=? WHERE id=?");
                $stmt->execute([$name, $start, $end, $type, $remarks ?: null, $status, $id]);
                sec_log_activity($pdo, 'Academic', 'Update', "Academic Calendar Holiday আপডেট করা হয়েছে: {$name} ({$start} - {$end})");
                $_SESSION['ac_msg'] = 'Academic Calendar Holiday আপডেট করা হয়েছে।';
            } else {
                $stmt = $pdo->prepare("INSERT INTO academic_holidays (holiday_name, start_date, end_date, holiday_type, remarks, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $start, $end, $type, $remarks ?: null, $status]);
                sec_log_activity($pdo, 'Academic', 'Create', "নতুন Holiday যোগ করা হয়েছে: {$name} ({$start} - {$end})");
                $_SESSION['ac_msg'] = 'নতুন Holiday যোগ করা হয়েছে। এখন থেকে সিস্টেমের সব Working Days ক্যালকুলেশনে এই দিনগুলো বাদ যাবে।';
            }
        }
    } elseif (isset($_POST['delete_holiday'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_stmt = $pdo->prepare("SELECT holiday_name FROM academic_holidays WHERE id = ?");
        $del_stmt->execute([$id]);
        $del_name = $del_stmt->fetchColumn();
        $pdo->prepare("DELETE FROM academic_holidays WHERE id = ?")->execute([$id]);
        sec_log_activity($pdo, 'Academic', 'Delete', "Holiday মুছে ফেলা হয়েছে: " . ($del_name ?: "#$id"));
        $_SESSION['ac_msg'] = 'Holiday মুছে ফেলা হয়েছে।';
    }

    header('Location: index.php?page=academic_calendar');
    exit();
}

$view = $_GET['view'] ?? 'list';
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM academic_holidays WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: [
        'id' => 0, 'holiday_name' => '', 'start_date' => '', 'end_date' => '',
        'holiday_type' => 'General', 'remarks' => '', 'status' => 'Active',
    ];
    ?>
    <div class="card-custom">
        <?php if ($ac_err): ?><div class="alert alert-danger py-2"><?php echo acv($ac_err); ?></div><?php endif; ?>

        <a href="index.php?page=academic_calendar" class="d-inline-block bg-warning bg-opacity-75 p-2 mb-4 text-decoration-none" style="width:40px;border-radius:4px;" title="তালিকায় ফিরে যান (Back to List)">
            <i class="fa-solid fa-calendar-days text-white"></i>
        </a>

        <form method="POST" action="index.php?page=academic_calendar">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Holiday Name <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="holiday_name" class="form-control" value="<?php echo acv($d['holiday_name']); ?>" placeholder="যেমন: Winter Vacation" required></div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Start Date <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="date" name="start_date" class="form-control" value="<?php echo acv($d['start_date']); ?>" required></div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">End Date <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="date" name="end_date" class="form-control" value="<?php echo acv($d['end_date']); ?>" required></div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Holiday Type</label>
                <div class="col-sm-6">
                    <select name="holiday_type" class="form-select">
                        <?php foreach ($holiday_types as $ht): ?>
                            <option value="<?php echo acv($ht); ?>" <?php echo $d['holiday_type'] === $ht ? 'selected' : ''; ?>><?php echo acv($ht); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Remarks</label>
                <div class="col-sm-6"><input type="text" name="remarks" class="form-control" value="<?php echo acv($d['remarks']); ?>"></div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Status</label>
                <div class="col-sm-6">
                    <select name="status" class="form-select">
                        <option value="Active" <?php echo $d['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $d['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <div class="alert alert-info small mb-4">
                <i class="fa-solid fa-circle-info"></i>
                এই ক্যালেন্ডার এন্ট্রি স্বয়ংক্রিয়ভাবে <b>Result Module &gt; Exam Settings &gt; Working Days</b> সহ সিস্টেমের যেখানেই
                Off Day হিসাব করা হয় সব জায়গায় প্রযোজ্য হবে।
            </div>

            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_holiday" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=academic_calendar" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

<?php else: ?>
    <?php
    $display = ($_GET['display'] ?? 'list') === 'calendar' ? 'calendar' : 'list';

    $f_name = trim($_GET['f_name'] ?? '');
    $f_type = $_GET['f_type'] ?? 'All';
    $f_status = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_name !== '') { $where[] = 'holiday_name LIKE ?'; $params[] = "%$f_name%"; }
    if ($f_type !== 'All') { $where[] = 'holiday_type = ?'; $params[] = $f_type; }
    if ($f_status !== 'All') { $where[] = 'status = ?'; $params[] = $f_status; }

    $sql = "SELECT * FROM academic_holidays";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY start_date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($ac_msg): ?><div class="alert alert-success py-2"><?php echo acv($ac_msg); ?></div><?php endif; ?>
        <?php if ($ac_err): ?><div class="alert alert-danger py-2"><?php echo acv($ac_err); ?></div><?php endif; ?>

        <div class="alert alert-info small">
            <i class="fa-solid fa-circle-info"></i>
            এখানে যোগ করা প্রতিটা Holiday তারিখ-রেঞ্জ পুরো সিস্টেমের সব জায়গায় (Working Days ক্যালকুলেশনসহ) অটো প্রযোজ্য হবে।
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div class="btn-group" role="group">
                <a href="index.php?page=academic_calendar&display=list" class="btn <?php echo $display === 'list' ? 'btn-primary' : 'btn-outline-primary'; ?> fw-bold" style="<?php echo $display === 'list' ? 'background-color:#1e3a8a;border-color:#1e3a8a;' : ''; ?>"><i class="fa-solid fa-list"></i> List View</a>
                <a href="index.php?page=academic_calendar&display=calendar" class="btn <?php echo $display === 'calendar' ? 'btn-primary' : 'btn-outline-primary'; ?> fw-bold" style="<?php echo $display === 'calendar' ? 'background-color:#1e3a8a;border-color:#1e3a8a;' : ''; ?>"><i class="fa-solid fa-calendar-days"></i> Calendar View</a>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php?page=academic_calendar&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
                <a href="index.php?page=print_academic_calendar" target="_blank" class="btn btn-outline-danger fw-bold"><i class="fa-solid fa-file-pdf"></i> Download PDF</a>
            </div>
        </div>

    <?php if ($display === 'calendar'):
        // =====================================================================
        // DYNAMIC DRAG & DROP CALENDAR VIEW
        // =====================================================================
        $cal_year  = (int) ($_GET['cal_year'] ?? date('Y'));
        $cal_month = (int) ($_GET['cal_month'] ?? date('n'));
        if ($cal_month < 1) { $cal_month = 12; $cal_year--; }
        if ($cal_month > 12) { $cal_month = 1; $cal_year++; }

        $first_ts = mktime(0, 0, 0, $cal_month, 1, $cal_year);
        $days_in_month = (int) date('t', $first_ts);
        $start_wday = (int) date('w', $first_ts);
        $month_label = date('F Y', $first_ts);

        $range_start = date('Y-m-d', strtotime("$cal_year-$cal_month-01 -7 days"));
        $range_end   = date('Y-m-d', strtotime("$cal_year-$cal_month-01 +1 month +7 days"));
        $hstmt = $pdo->prepare("SELECT * FROM academic_holidays WHERE status='Active' AND start_date <= ? AND end_date >= ? ORDER BY start_date ASC");
        $hstmt->execute([$range_end, $range_start]);
        $cal_holidays = $hstmt->fetchAll();

        $type_colors = ['General' => '#1e3a8a', 'Government' => '#0f766e', 'Religious' => '#a16207', 'Vacation' => '#7c3aed', 'Emergency' => '#dc2626'];

        $day_map = [];
        foreach ($cal_holidays as $h) {
            $t = strtotime($h['start_date']); $e = strtotime($h['end_date']);
            while ($t <= $e) { $day_map[date('Y-m-d', $t)] = $h; $t += 86400; }
        }

        $prev_m = $cal_month - 1; $prev_y = $cal_year; if ($prev_m < 1) { $prev_m = 12; $prev_y--; }
        $next_m = $cal_month + 1; $next_y = $cal_year; if ($next_m > 12) { $next_m = 1; $next_y++; }
        ?>

        <div class="alert alert-info small">
            <i class="fa-solid fa-hand-pointer"></i>
            খালি তারিখে ক্লিক করে নতুন Holiday যোগ করুন। কোনো Holiday বারে ক্লিক করলে এডিট/ডিলিট করা যাবে।
            বারের দুই প্রান্তের <b>● হ্যান্ডেল</b> টেনে ধরে days বাড়ানো/কমানো যাবে, আর মাঝখান ধরে টেনে পুরো তারিখ সরানো যাবে।
        </div>

        <div id="acCalWrap">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a data-cal-nav="1" href="index.php?page=academic_calendar&display=calendar&cal_year=<?php echo $prev_y; ?>&cal_month=<?php echo $prev_m; ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-chevron-left"></i></a>
            <h5 class="mb-0 fw-bold" style="color:#1e3a8a;"><?php echo $month_label; ?></h5>
            <a data-cal-nav="1" href="index.php?page=academic_calendar&display=calendar&cal_year=<?php echo $next_y; ?>&cal_month=<?php echo $next_m; ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-chevron-right"></i></a>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered ac-cal-table" id="acCalTable">
                <thead class="table-light">
                    <tr>
                        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $wd): ?><th class="text-center"><?php echo $wd; ?></th><?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $day = 1; $cell = 0;
                    echo '<tr>';
                    for ($i = 0; $i < $start_wday; $i++) { echo '<td class="ac-empty-cell"></td>'; $cell++; }
                    while ($day <= $days_in_month) {
                        if ($cell === 7) { echo '</tr><tr>'; $cell = 0; }
                        $ymd = sprintf('%04d-%02d-%02d', $cal_year, $cal_month, $day);
                        $h = $day_map[$ymd] ?? null;
                        $is_today = $ymd === date('Y-m-d') ? ' ac-today' : '';
                        if ($h) {
                            $color = $type_colors[$h['holiday_type']] ?? '#1e3a8a';
                            $is_start = $ymd === $h['start_date'];
                            $is_end = $ymd === $h['end_date'];
                            echo '<td class="ac-day-cell ac-hol-cell' . $is_today . '" data-date="' . $ymd . '" data-holiday-id="' . (int) $h['id'] . '">';
                            echo '<div class="ac-hol-bar" style="background:' . $color . ';" data-holiday-id="' . (int) $h['id'] . '" data-name="' . acv($h['holiday_name']) . '" data-type="' . acv($h['holiday_type']) . '" data-start="' . $h['start_date'] . '" data-end="' . $h['end_date'] . '">';
                            if ($is_start) echo '<span class="ac-handle ac-handle-start" title="Start তারিখ টানুন">●</span>';
                            echo '<span class="ac-day-num">' . $day . '</span>';
                            if ($is_end) echo '<span class="ac-handle ac-handle-end" title="End তারিখ টানুন">●</span>';
                            echo '</div></td>';
                        } else {
                            echo '<td class="ac-day-cell' . $is_today . '" data-date="' . $ymd . '"><span class="ac-day-num">' . $day . '</span></td>';
                        }
                        $day++; $cell++;
                    }
                    while ($cell < 7 && $cell !== 0) { echo '<td class="ac-empty-cell"></td>'; $cell++; }
                    echo '</tr>';
                    ?>
                </tbody>
            </table>
        </div>
        </div><!-- /#acCalWrap -->

        <div class="pac-legend d-flex flex-wrap gap-3 small mt-2">
            <?php foreach ($type_colors as $type => $color): ?>
                <span><span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:<?php echo $color; ?>;margin-right:4px;vertical-align:middle;"></span><?php echo acv($type); ?></span>
            <?php endforeach; ?>
        </div>

        <!-- ========== Add / Edit Holiday Modal ========== -->
        <div class="modal fade" id="acHolidayModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold" id="acModalTitle">Add Holiday</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="acFormId" value="">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Holiday Name</label>
                            <input type="text" id="acFormName" class="form-control" placeholder="যেমন: Winter Vacation">
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label fw-bold">Start Date</label>
                                <input type="date" id="acFormStart" class="form-control">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label fw-bold">End Date</label>
                                <input type="date" id="acFormEnd" class="form-control">
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-bold">Holiday Type</label>
                            <select id="acFormType" class="form-select">
                                <?php foreach (array_keys($type_colors) as $t): ?><option value="<?php echo acv($t); ?>"><?php echo acv($t); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div id="acFormMsg" class="text-danger small"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-danger d-none" id="acDeleteBtn"><i class="fa-solid fa-trash"></i> Delete</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary fw-bold" id="acSaveBtn" style="background-color:#1e3a8a;border-color:#1e3a8a;">Save</button>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .ac-cal-table td { height: 68px; width: 14.28%; vertical-align: top; padding: 2px !important; position: relative; }
            .ac-empty-cell { background: #fafafa; }
            .ac-day-cell { cursor: pointer; }
            .ac-day-cell:hover:not(.ac-hol-cell) { background: #f1f5ff; }
            .ac-day-num { display: block; font-size: 12px; padding: 3px 5px; }
            .ac-today .ac-day-num { font-weight: 800; color: #1e3a8a; }
            .ac-hol-bar { height: 100%; min-height: 60px; color: #fff; position: relative; touch-action: none; cursor: grab; border-radius: 3px; }
            .ac-hol-bar .ac-day-num { color: #fff; }
            .ac-handle { position: absolute; top: 2px; font-size: 16px; line-height: 1; cursor: ew-resize; touch-action: none; padding: 2px 4px; }
            .ac-handle-start { left: 0; }
            .ac-handle-end { right: 0; }
            .ac-drag-target { outline: 2px dashed #16a34a !important; outline-offset: -2px; }
        </style>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const CSRF = '<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8"); ?>';
            const AJAX_URL = 'ajax/calendar_holiday_ajax.php';

            function postAjax(action, data) {
                const fd = new FormData();
                fd.append('action', action);
                fd.append('csrf_token', CSRF);
                Object.keys(data).forEach(k => fd.append(k, data[k]));
                return fetch(AJAX_URL, { method: 'POST', body: fd }).then(r => r.json());
            }

            const modalEl = document.getElementById('acHolidayModal');
            const modal = new bootstrap.Modal(modalEl);
            const formId = document.getElementById('acFormId');
            const formName = document.getElementById('acFormName');
            const formStart = document.getElementById('acFormStart');
            const formEnd = document.getElementById('acFormEnd');
            const formType = document.getElementById('acFormType');
            const formMsg = document.getElementById('acFormMsg');
            const deleteBtn = document.getElementById('acDeleteBtn');
            const saveBtn = document.getElementById('acSaveBtn');
            const modalTitle = document.getElementById('acModalTitle');

            function openAddModal(dateStr) {
                formId.value = '';
                formName.value = '';
                formStart.value = dateStr;
                formEnd.value = dateStr;
                formType.value = 'General';
                formMsg.textContent = '';
                deleteBtn.classList.add('d-none');
                modalTitle.textContent = 'Add Holiday';
                modal.show();
            }

            function openEditModal(bar) {
                formId.value = bar.dataset.holidayId;
                formName.value = bar.dataset.name;
                formStart.value = bar.dataset.start;
                formEnd.value = bar.dataset.end;
                formType.value = bar.dataset.type;
                formMsg.textContent = '';
                deleteBtn.classList.remove('d-none');
                modalTitle.textContent = 'Edit Holiday';
                modal.show();
            }

            // ------------------------------------------------------------------
            // মাস বদল (prev/next) পেজ রিলোড ছাড়া: নতুন মাসের HTML fetch করে শুধু
            // #acCalWrap বদলে দেওয়া হয়, তারপর ইভেন্টগুলো নতুন করে বাইন্ড হয়।
            // Save/Delete/Drag-এর পরেও একই পদ্ধতিতে বর্তমান মাস রিফ্রেশ হয়।
            // ------------------------------------------------------------------
            const calWrap = document.getElementById('acCalWrap');
            let calLoadSeq = 0;

            function loadCalendar(url, push) {
                const seq = ++calLoadSeq;
                calWrap.style.opacity = '0.5';
                calWrap.style.pointerEvents = 'none';
                return fetch(url, { credentials: 'same-origin' })
                    .then(r => r.text())
                    .then(html => {
                        if (seq !== calLoadSeq) return;
                        const fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('acCalWrap');
                        if (!fresh) { location.href = url; return; } // সেশন শেষ ইত্যাদি হলে স্বাভাবিক লোড
                        calWrap.innerHTML = fresh.innerHTML;
                        calWrap.style.opacity = '';
                        calWrap.style.pointerEvents = '';
                        if (push) history.pushState({ acCal: 1 }, '', url);
                        bindCalendarEvents();
                    })
                    .catch(() => { location.href = url; });
            }
            function refreshCalendar() { return loadCalendar(location.href, false); }

            calWrap.addEventListener('click', function (e) {
                const a = e.target.closest('a[data-cal-nav]');
                if (!a) return;
                e.preventDefault();
                loadCalendar(a.href, true);
            });
            window.addEventListener('popstate', function () { loadCalendar(location.href, false); });

            function bindCalendarEvents() {
                document.querySelectorAll('.ac-day-cell:not(.ac-hol-cell)').forEach(cell => {
                    cell.addEventListener('click', () => openAddModal(cell.dataset.date));
                });

                document.querySelectorAll('.ac-hol-bar').forEach(bar => {
                    bar.addEventListener('click', function (e) {
                        if (e.target.classList.contains('ac-handle')) return; // হ্যান্ডেল ড্র্যাগ ক্লিক থেকে আলাদা
                        if (bar.dataset.dragged === '1') { bar.dataset.dragged = '0'; return; }
                        openEditModal(bar);
                    });
                });

                document.querySelectorAll('.ac-handle-start').forEach(h => h.addEventListener('pointerdown', e => onPointerDown(e, 'start')));
                document.querySelectorAll('.ac-handle-end').forEach(h => h.addEventListener('pointerdown', e => onPointerDown(e, 'end')));
                document.querySelectorAll('.ac-hol-bar').forEach(bar => bar.addEventListener('pointerdown', e => {
                    if (e.target.classList.contains('ac-handle')) return;
                    onPointerDown(e, 'move');
                }));
            }

            saveBtn.addEventListener('click', function () {
                const name = formName.value.trim();
                const start = formStart.value;
                const end = formEnd.value;
                if (!name || !start || !end || end < start) {
                    formMsg.textContent = 'Holiday Name ও সঠিক তারিখ দিন (End Date অবশ্যই Start Date এর পরে বা সমান)।';
                    return;
                }
                const payload = { holiday_name: name, start_date: start, end_date: end, holiday_type: formType.value };
                if (formId.value) payload.id = formId.value;
                postAjax('save', payload).then(res => {
                    if (!res.success) { formMsg.textContent = res.message || 'সমস্যা হয়েছে।'; return; }
                    modal.hide();
                    refreshCalendar();
                });
            });

            deleteBtn.addEventListener('click', function () {
                if (!confirm('এই Holiday মুছে ফেলবেন?')) return;
                postAjax('delete', { id: formId.value }).then(res => { if (res.success) { modal.hide(); refreshCalendar(); } });
            });

            // ================= ড্র্যাগ (রিসাইজ + মুভ) - Pointer Events (মাউস + টাচ দুটোতেই কাজ করে) =================
            let dragState = null;

            function cellUnderPoint(x, y) {
                const el = document.elementFromPoint(x, y);
                if (!el) return null;
                return el.closest('.ac-day-cell');
            }

            function clearTargets() {
                document.querySelectorAll('.ac-drag-target').forEach(c => c.classList.remove('ac-drag-target'));
            }

            function onPointerDown(e, mode) {
                const bar = e.target.closest('.ac-hol-bar');
                if (!bar) return;
                e.preventDefault();
                e.stopPropagation();
                dragState = {
                    mode: mode, // 'start' | 'end' | 'move'
                    id: bar.dataset.holidayId,
                    origStart: bar.dataset.start,
                    origEnd: bar.dataset.end,
                    anchorDate: bar.closest('.ac-day-cell').dataset.date,
                    bar: bar,
                    moved: false,
                };
                document.addEventListener('pointermove', onPointerMove);
                document.addEventListener('pointerup', onPointerUp);
            }

            function onPointerMove(e) {
                if (!dragState) return;
                const cell = cellUnderPoint(e.clientX, e.clientY);
                clearTargets();
                if (cell) { cell.classList.add('ac-drag-target'); dragState.targetDate = cell.dataset.date; dragState.moved = true; }
            }

            function addDays(dateStr, n) {
                const d = new Date(dateStr + 'T00:00:00');
                d.setDate(d.getDate() + n);
                return d.toISOString().slice(0, 10);
            }
            function diffDays(a, b) {
                return Math.round((new Date(b + 'T00:00:00') - new Date(a + 'T00:00:00')) / 86400000);
            }

            function onPointerUp() {
                document.removeEventListener('pointermove', onPointerMove);
                document.removeEventListener('pointerup', onPointerUp);
                clearTargets();
                if (!dragState) return;
                const st = dragState;
                dragState = null;
                if (!st.moved || !st.targetDate) return;
                st.bar.dataset.dragged = '1';

                let newStart = st.origStart, newEnd = st.origEnd;
                if (st.mode === 'start') {
                    newStart = st.targetDate;
                    if (newStart > newEnd) newStart = newEnd;
                } else if (st.mode === 'end') {
                    newEnd = st.targetDate;
                    if (newEnd < newStart) newEnd = newStart;
                } else if (st.mode === 'move') {
                    const delta = diffDays(st.anchorDate, st.targetDate);
                    if (delta === 0) return;
                    newStart = addDays(st.origStart, delta);
                    newEnd = addDays(st.origEnd, delta);
                }
                if (newStart === st.origStart && newEnd === st.origEnd) return;

                postAjax('update_dates', { id: st.id, start_date: newStart, end_date: newEnd }).then(res => {
                    if (res.success) refreshCalendar();
                    else alert(res.message || 'তারিখ আপডেট করা যায়নি।');
                });
            }

            bindCalendarEvents();
        });
        </script>

    <?php else: ?>
            <!-- সারিওয়ারি Delete বাটন আগে প্রতিটা নিজের ছোট্ট &lt;form&gt;-এ মোড়ানো ছিল, আর সেই ফর্মগুলো
         থাকত নিচের টেবিলটা মোড়ানো GET ফিল্টার ফর্মের ভেতরে — অর্থাৎ ফর্মের ভেতরে ফর্ম। HTML-এ
         নেস্টেড &lt;form&gt; বৈধ না, তাই ব্রাউজার ভেতরের ফর্মটাকে বাতিল করে তার ইনপুট/বাটনগুলো
         বাইরের GET ফিল্টার ফর্মেই বসিয়ে দিত। ফলে Delete-এ ক্লিক করলে আসল POST ডিলিট রিকোয়েস্টের
         বদলে ফিল্টার ফর্মটাই (GET, action=index.php) সাবমিট হয়ে যেত — কিছুই ডিলিট হতো না।
         সমাধানঃ একটামাত্র শেয়ার্ড হিডেন ফর্ম, GET ফিল্টার ফর্মের বাইরে রাখা — প্রতিটা Delete
         বাটন এখন আলাদা ফর্ম না বানিয়ে শুধু এটাকেই JS দিয়ে সাবমিট করে। -->
    <form method="POST" id="wcRowActionForm" style="display:none">
        <input type="hidden" name="csrf_token" id="wcRowActionCsrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" id="wcRowActionId" value="">
    </form>
    <script>
    function wcRowAction(url, field, id, confirmMsg) {
        if (confirmMsg && !window.confirm(confirmMsg)) return false;
        var f = document.getElementById('wcRowActionForm');
        f.setAttribute('action', url);
        document.getElementById('wcRowActionId').value = id;
        var old = f.querySelector('input[data-dyn-field]');
        if (old) old.parentNode.removeChild(old);
        var fld = document.createElement('input');
        fld.type = 'hidden'; fld.name = field; fld.value = '1'; fld.setAttribute('data-dyn-field', '1');
        f.appendChild(fld);
        f.submit();
        return false;
    }
    </script>
<form method="GET" action="index.php">
            <input type="hidden" name="page" value="academic_calendar">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>Holiday Name</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Days</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <tr>
                            <th></th>
                            <th><input type="text" name="f_name" class="form-control form-control-sm" value="<?php echo acv($f_name); ?>"></th>
                            <th></th><th></th><th></th>
                            <th>
                                <select name="f_type" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All" <?php echo $f_type === 'All' ? 'selected' : ''; ?>>All</option>
                                    <?php foreach ($holiday_types as $ht): ?>
                                        <option value="<?php echo acv($ht); ?>" <?php echo $f_type === $ht ? 'selected' : ''; ?>><?php echo acv($ht); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
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
                        <?php if ($rows): $srl = 1; foreach ($rows as $row):
                            $csrf = htmlspecialchars(csrf_token());
                            $days = (int) ((strtotime($row['end_date']) - strtotime($row['start_date'])) / 86400) + 1;
                        ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td><?php echo acv($row['holiday_name']); ?><?php if ($row['remarks']): ?><br><small class="text-muted"><?php echo acv($row['remarks']); ?></small><?php endif; ?></td>
                                <td><?php echo acv($row['start_date']); ?></td>
                                <td><?php echo acv($row['end_date']); ?></td>
                                <td><?php echo $days; ?></td>
                                <td><span class="badge bg-info text-dark"><?php echo acv($row['holiday_type']); ?></span></td>
                                <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=academic_calendar&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=academic_calendar', 'delete_holiday', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="8" class="text-center text-muted py-3">No data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    <?php endif; ?>
    </div>
<?php endif; ?>
