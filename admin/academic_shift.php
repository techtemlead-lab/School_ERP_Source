<?php
// admin/academic_shift.php - Shift List + Add/Edit (স্ক্রিনশট অনুযায়ী)
$sh_err = $_SESSION['sh_err'] ?? '';
$sh_msg = $_SESSION['sh_msg'] ?? '';
unset($_SESSION['sh_err'], $_SESSION['sh_msg']);

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sh_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=academic_shift');
        exit();
    }

    if (isset($_POST['save_shift'])) {
        $id           = (int) ($_POST['id'] ?? 0);
        $medium_id    = (int) ($_POST['medium_id'] ?? 0);
        $shift_name   = trim($_POST['shift_name'] ?? '');
        $start_time   = trim($_POST['start_time'] ?? '');
        $end_time     = trim($_POST['end_time'] ?? '');
        $green_limit  = (int) ($_POST['green_limit'] ?? 0);
        $orange_limit = (int) ($_POST['orange_limit'] ?? 0);
        $red_limit    = (int) ($_POST['red_limit'] ?? 0);
        $status       = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if ($medium_id <= 0 || $shift_name === '' || $start_time === '' || $end_time === '') {
            $_SESSION['sh_err'] = 'Medium, Shift Name, Start Time, End Time — এই ফিল্ডগুলো আবশ্যক।';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE academic_shifts SET medium_id=?, shift_name=?, start_time=?, end_time=?, green_limit=?, orange_limit=?, red_limit=?, status=? WHERE id=?"
                );
                $stmt->execute([$medium_id, $shift_name, $start_time, $end_time, $green_limit, $orange_limit, $red_limit, $status, $id]);
                sec_log_activity($pdo, 'Academic', 'Update', "Shift আপডেট করা হয়েছে: {$shift_name}");
                $_SESSION['sh_msg'] = 'Shift আপডেট করা হয়েছে।';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO academic_shifts (medium_id, shift_name, start_time, end_time, green_limit, orange_limit, red_limit, status, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([$medium_id, $shift_name, $start_time, $end_time, $green_limit, $orange_limit, $red_limit, $status, branch_id_for_insert()]);
                sec_log_activity($pdo, 'Academic', 'Create', "নতুন Shift যোগ করা হয়েছে: {$shift_name}");
                $_SESSION['sh_msg'] = 'নতুন Shift যোগ করা হয়েছে।';
            }
        }
    } elseif (isset($_POST['delete_shift'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_stmt = $pdo->prepare("SELECT shift_name FROM academic_shifts WHERE id = ?");
        $del_stmt->execute([$id]);
        $del_name = $del_stmt->fetchColumn();
        $pdo->prepare("DELETE FROM academic_shifts WHERE id = ?")->execute([$id]);
        sec_log_activity($pdo, 'Academic', 'Delete', "Shift মুছে ফেলা হয়েছে: " . ($del_name ?: "#$id"));
        $_SESSION['sh_msg'] = 'Shift মুছে ফেলা হয়েছে।';
    }

    header('Location: index.php?page=academic_shift');
    exit();
}

$view = $_GET['view'] ?? 'list';
$mediums = $pdo->query("SELECT id, medium_name FROM academic_mediums WHERE status = 'Active' ORDER BY medium_name ASC")->fetchAll();

function shv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function fmt_time($t): string { return $t ? date('h:i A', strtotime($t)) : '-'; }
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    // ------------------- ADD / EDIT FORM VIEW -------------------
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM academic_shifts WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: [
        'id' => 0, 'medium_id' => '', 'shift_name' => '', 'start_time' => '', 'end_time' => '',
        'green_limit' => 5, 'orange_limit' => 10, 'red_limit' => 15, 'status' => 'Active',
    ];
    ?>
    <div class="card-custom">
        <?php if ($sh_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($sh_err); ?></div><?php endif; ?>

        <a href="index.php?page=academic_shift" class="d-inline-block bg-warning bg-opacity-75 p-2 mb-4 text-decoration-none" style="width:40px;border-radius:4px;" title="তালিকায় ফিরে যান (Back to List)">
            <i class="fa-solid fa-file-lines text-white"></i>
        </a>

        <form method="POST" action="index.php?page=academic_shift" onsubmit="return validateShTimesBeforeSubmit()">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Medium Name <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="medium_id" class="form-select" required>
                        <option value="">Select Medium</option>
                        <?php foreach ($mediums as $m): ?>
                            <option value="<?php echo (int) $m['id']; ?>" <?php echo (int) $d['medium_id'] === (int) $m['id'] ? 'selected' : ''; ?>><?php echo shv($m['medium_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$mediums): ?><small class="text-danger">প্রথমে "All Medium" থেকে একটা Medium যোগ করুন।</small><?php endif; ?>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Shift Name <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="shift_name" class="form-control" value="<?php echo shv($d['shift_name']); ?>" placeholder="e.g. Morning / Day" required></div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Start Time <span class="text-danger">*</span></label>
                <div class="col-sm-6" style="position:relative;">
                    <div class="input-group">
                        <button type="button" class="btn btn-outline-secondary sh-time-trigger" onclick="openShTimePicker('start')" tabindex="-1"><i class="fa-solid fa-calendar-days"></i></button>
                        <input type="text" id="startTimeDisplay" class="form-control" placeholder="Type e.g. 10:30 AM, or click the icon" onclick="openShTimePicker('start')" onblur="parseShTimeInput('start')" required>
                    </div>
                    <input type="hidden" name="start_time" id="startTimeValue" value="<?php echo shv($d['start_time']); ?>">
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">End Time <span class="text-danger">*</span></label>
                <div class="col-sm-6" style="position:relative;">
                    <div class="input-group">
                        <button type="button" class="btn btn-outline-secondary sh-time-trigger" onclick="openShTimePicker('end')" tabindex="-1"><i class="fa-solid fa-calendar-days"></i></button>
                        <input type="text" id="endTimeDisplay" class="form-control" placeholder="Type e.g. 05:00 PM, or click the icon" onclick="openShTimePicker('end')" onblur="parseShTimeInput('end')" required>
                    </div>
                    <input type="hidden" name="end_time" id="endTimeValue" value="<?php echo shv($d['end_time']); ?>">
                </div>
            </div>

            <!-- ================= Choose Time Popup ================= -->
            <div id="shTimePickerPopup" style="display:none; position:absolute; z-index:50; width:280px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; box-shadow:0 6px 16px rgba(0,0,0,0.15);">
                <div style="background:#e9ecef; padding:8px 12px; font-weight:bold; text-align:center; border-bottom:1px solid #ddd; border-radius:6px 6px 0 0;">Choose Time</div>
                <div style="padding:14px;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <strong>Time</strong>
                        <span id="shTimeDisplay" style="text-decoration:underline; font-size:16px;">00:00:00</span>
                    </div>
                    <div class="mb-2">
                        <label class="d-block small fw-bold mb-1">Hour</label>
                        <input type="range" id="shHourSlider" min="0" max="23" value="0" class="form-range" oninput="updateShTimeDisplay()">
                    </div>
                    <div class="mb-3">
                        <label class="d-block small fw-bold mb-1">Minute</label>
                        <input type="range" id="shMinuteSlider" min="0" max="59" value="0" class="form-range" oninput="updateShTimeDisplay()">
                    </div>
                    <div class="d-flex justify-content-between">
                        <button type="button" class="btn btn-light btn-sm" onclick="setShTimeNow()">Now</button>
                        <button type="button" class="btn btn-dark btn-sm" onclick="commitShTime()">Done</button>
                    </div>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Green Limit <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="number" name="green_limit" class="form-control" value="<?php echo (int) $d['green_limit']; ?>" min="0" required></div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Orange Limit <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="number" name="orange_limit" class="form-control" value="<?php echo (int) $d['orange_limit']; ?>" min="0" required></div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Red Limit <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="number" name="red_limit" class="form-control" value="<?php echo (int) $d['red_limit']; ?>" min="0" required></div>
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
                    <button type="submit" name="save_shift" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=academic_shift" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

    <script>
        let shActiveField = null;

        function openShTimePicker(field) {
            shActiveField = field;
            const popup = document.getElementById('shTimePickerPopup');
            const anchor = document.getElementById(field + 'TimeDisplay');
            const rect = anchor.getBoundingClientRect();

            popup.style.display = 'block';
            popup.style.top = (rect.bottom + window.scrollY + 4) + 'px';
            popup.style.left = (rect.left + window.scrollX) + 'px';

            let h = 0, m = 0;
            const typed = parseShTimeString(anchor.value);
            if (typed) {
                h = typed.h; m = typed.m;
            } else {
                const hidden = document.getElementById(field + 'TimeValue');
                if (hidden.value) {
                    const parts = hidden.value.split(':');
                    h = parseInt(parts[0], 10) || 0;
                    m = parseInt(parts[1], 10) || 0;
                }
            }
            document.getElementById('shHourSlider').value = h;
            document.getElementById('shMinuteSlider').value = m;
            updateShTimeDisplay();
        }

        function parseShTimeString(raw) {
            raw = (raw || '').trim();
            if (!raw) return null;
            const match = raw.match(/^(\d{1,2})[:.](\d{2})\s*(AM|PM|am|pm)?$/);
            if (!match) return null;

            let hour = parseInt(match[1], 10);
            const minute = parseInt(match[2], 10);
            const meridiem = match[3] ? match[3].toUpperCase() : null;
            if (minute > 59 || hour > 23 || (meridiem && hour > 12) || hour < 0) return null;

            if (meridiem === 'PM' && hour !== 12) hour += 12;
            if (meridiem === 'AM' && hour === 12) hour = 0;
            return { h: hour, m: minute };
        }

        function updateShTimeDisplay() {
            const h = String(document.getElementById('shHourSlider').value).padStart(2, '0');
            const m = String(document.getElementById('shMinuteSlider').value).padStart(2, '0');
            document.getElementById('shTimeDisplay').textContent = h + ':' + m + ':00';
        }

        function setShTimeNow() {
            const now = new Date();
            document.getElementById('shHourSlider').value = now.getHours();
            document.getElementById('shMinuteSlider').value = now.getMinutes();
            updateShTimeDisplay();
        }

        function formatSh12Hour(h, m) {
            let hour = parseInt(h, 10);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            hour = hour % 12;
            if (hour === 0) hour = 12;
            return hour + ':' + m + ' ' + ampm;
        }

        function commitShTime() {
            const h = String(document.getElementById('shHourSlider').value).padStart(2, '0');
            const m = String(document.getElementById('shMinuteSlider').value).padStart(2, '0');
            document.getElementById(shActiveField + 'TimeValue').value = h + ':' + m;
            document.getElementById(shActiveField + 'TimeDisplay').value = formatSh12Hour(h, m);
            document.getElementById('shTimePickerPopup').style.display = 'none';
        }

        function parseShTimeInput(field) {
            const input = document.getElementById(field + 'TimeDisplay');
            const hidden = document.getElementById(field + 'TimeValue');
            const raw = input.value.trim();

            if (raw === '') { hidden.value = ''; return; }

            const match = raw.match(/^(\d{1,2})[:.](\d{2})\s*(AM|PM|am|pm)?$/);
            if (!match) {
                hidden.value = '';
                return;
            }

            let hour = parseInt(match[1], 10);
            const minute = parseInt(match[2], 10);
            const meridiem = match[3] ? match[3].toUpperCase() : null;

            if (minute > 59 || hour > 23 || (meridiem && hour > 12) || hour < 0) {
                hidden.value = '';
                return;
            }

            if (meridiem === 'PM' && hour !== 12) hour += 12;
            if (meridiem === 'AM' && hour === 12) hour = 0;

            const hh = String(hour).padStart(2, '0');
            const mm = String(minute).padStart(2, '0');
            hidden.value = hh + ':' + mm;
            input.value = formatSh12Hour(hh, mm);
        }

        function validateShTimesBeforeSubmit() {
            for (const field of ['start', 'end']) {
                const display = document.getElementById(field + 'TimeDisplay');
                const hidden = document.getElementById(field + 'TimeValue');
                if (display.value.trim() !== '' && hidden.value === '') {
                    alert((field === 'start' ? 'Start Time' : 'End Time') + '-এর ফরম্যাট ঠিক নেই। উদাহরণ: 10:30 AM অথবা 17:30');
                    display.focus();
                    return false;
                }
            }
            return true;
        }

        document.addEventListener('click', function (e) {
            const popup = document.getElementById('shTimePickerPopup');
            if (popup && popup.style.display === 'block' && !popup.contains(e.target) && !e.target.closest('.sh-time-trigger') && !e.target.id.includes('TimeDisplay')) {
                popup.style.display = 'none';
            }
        });

        // এডিটের সময় আগে থেকে সেভ করা Time থাকলে ডিসপ্লে বক্সে ফরম্যাট করে বসানো
        (function initShTimeDisplays() {
            ['start', 'end'].forEach(field => {
                const hidden = document.getElementById(field + 'TimeValue');
                if (hidden && hidden.value) {
                    const [h, m] = hidden.value.split(':');
                    document.getElementById(field + 'TimeDisplay').value = formatSh12Hour(h, m);
                }
            });
        })();
    </script>

<?php else: ?>
    <?php
    // ------------------- LIST VIEW -------------------
    $f_medium = $_GET['f_medium'] ?? 'All';
    $f_name   = trim($_GET['f_name'] ?? '');
    $f_start  = trim($_GET['f_start'] ?? '');
    $f_end    = trim($_GET['f_end'] ?? '');
    $f_status = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_medium !== 'All') { $where[] = 'academic_shifts.medium_id = ?'; $params[] = (int) $f_medium; }
    if ($f_name !== '')  { $where[] = 'shift_name LIKE ?'; $params[] = "%$f_name%"; }
    if ($f_start !== '') { $where[] = 'start_time LIKE ?'; $params[] = "%$f_start%"; }
    if ($f_end !== '')   { $where[] = 'end_time LIKE ?';   $params[] = "%$f_end%"; }
    if ($f_status !== 'All') { $where[] = 'status = ?'; $params[] = $f_status; }
    branch_apply_filter($where, $params, 'academic_shifts.branch_id'); // লগইন করা branch অনুযায়ী Shift ফিল্টার

    $sql = "SELECT academic_shifts.*, academic_mediums.medium_name FROM academic_shifts
            LEFT JOIN academic_mediums ON academic_mediums.id = academic_shifts.medium_id";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY academic_shifts.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($sh_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($sh_msg); ?></div><?php endif; ?>
        <?php if ($sh_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($sh_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=academic_shift&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
            <span class="text-muted small">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> items.</span>
        </div>

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
            <input type="hidden" name="page" value="academic_shift">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>Medium Name</th>
                            <th>Shift Name</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Green Limit</th>
                            <th>Orange Limit</th>
                            <th>Red Limit</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <tr>
                            <th></th>
                            <th>
                                <select name="f_medium" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All">All</option>
                                    <?php foreach ($mediums as $m): ?>
                                        <option value="<?php echo (int) $m['id']; ?>" <?php echo (string) $f_medium === (string) $m['id'] ? 'selected' : ''; ?>><?php echo shv($m['medium_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th><input type="text" name="f_name" class="form-control form-control-sm" value="<?php echo shv($f_name); ?>"></th>
                            <th><input type="text" name="f_start" class="form-control form-control-sm" value="<?php echo shv($f_start); ?>"></th>
                            <th><input type="text" name="f_end" class="form-control form-control-sm" value="<?php echo shv($f_end); ?>"></th>
                            <th></th><th></th><th></th>
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
                        <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td><?php echo shv($row['medium_name'] ?? '-'); ?></td>
                                <td><?php echo shv($row['shift_name']); ?></td>
                                <td><?php echo fmt_time($row['start_time']); ?></td>
                                <td><?php echo fmt_time($row['end_time']); ?></td>
                                <td><?php echo (int) $row['green_limit']; ?></td>
                                <td><?php echo (int) $row['orange_limit']; ?></td>
                                <td><?php echo (int) $row['red_limit']; ?></td>
                                <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=academic_shift&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                    <a href="index.php?page=academic_shift&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=academic_shift', 'delete_shift', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="10" class="text-center text-muted py-3">No data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
<?php endif; ?>
