<?php
// admin/attendance_manual.php - Attendance Module > Student Attendance > Add Student Attendance (স্ক্রিনশট অনুযায়ী)
// টিচার/অ্যাডমিন হাতে করে ক্লাস-ভিত্তিক Present/Absent মার্ক করবে এখান থেকে
// (ডিভাইস পাঞ্চ ছাড়া, ম্যানুয়ালি)। ডেটা যায় student_daily_attendance টেবিলে।

function ma_esc($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// প্রয়োজনীয় টেবিল না থাকলে নিজে থেকেই তৈরি করে নেবে (ExonHost শেয়ার্ড হোস্টিং-এ
// আলাদা করে phpMyAdmin দিয়ে SQL রান করার ঝামেলা এড়াতে)।
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS student_daily_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        class_id INT NULL,
        section_id INT NULL,
        shift_id INT NULL,
        attendance_date DATE NOT NULL,
        status ENUM('Present','Absent') NOT NULL DEFAULT 'Absent',
        marked_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_student_date (student_id, attendance_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$ma_msg = $_SESSION['ma_msg'] ?? '';
$ma_err = $_SESSION['ma_err'] ?? '';
unset($_SESSION['ma_msg'], $_SESSION['ma_err']);

// -----------------------------------------------------------
// Save Handler
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ma_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $p_date    = trim($_POST['attendance_date'] ?? date('Y-m-d'));
        $p_class   = (int) ($_POST['class_id'] ?? 0);
        $p_shift   = (int) ($_POST['shift_id'] ?? 0);
        $p_section = (int) ($_POST['section_id'] ?? 0);
        $statuses  = (array) ($_POST['status'] ?? []); // [student_id => 'Present'|'Absent']

        if (!$p_class || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $p_date)) {
            $_SESSION['ma_err'] = 'Day ও Class অবশ্যই সিলেক্ট করতে হবে।';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO student_daily_attendance (student_id, class_id, section_id, shift_id, attendance_date, status, marked_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), class_id = VALUES(class_id),
                     section_id = VALUES(section_id), shift_id = VALUES(shift_id), marked_by = VALUES(marked_by)"
            );
            $marked_by = $_SESSION['admin_user'] ?? 'admin';
            $count = 0;
            foreach ($statuses as $sid => $st) {
                $sid = (int) $sid;
                $st  = ($st === 'Present') ? 'Present' : 'Absent';
                if ($sid > 0) {
                    $stmt->execute([$sid, $p_class ?: null, $p_section ?: null, $p_shift ?: null, $p_date, $st, $marked_by]);
                    $count++;
                }
            }
            sec_log_activity($pdo, 'Attendance', 'Update', "শিক্ষার্থীদের ম্যানুয়াল অ্যাটেন্ডেন্স সেভ করা হয়েছে: {$count} জন ({$p_date}, Class ID: {$p_class})");
            $_SESSION['ma_msg'] = $count . " জন শিক্ষার্থীর অ্যাটেন্ডেন্স সেভ করা হয়েছে ({$p_date})।";
        }
    }
    header('Location: index.php?page=attendance_manual&date=' . urlencode($p_date ?? date('Y-m-d')) . '&f_class=' . (int) ($p_class ?? 0) . '&f_shift=' . (int) ($p_shift ?? 0) . '&f_section=' . (int) ($p_section ?? 0));
    exit();
}

// -----------------------------------------------------------
// Filters
// -----------------------------------------------------------
$f_date    = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) { $f_date = date('Y-m-d'); }
$f_class   = (int) ($_GET['f_class'] ?? 0);
$f_shift   = (int) ($_GET['f_shift'] ?? 0);
$f_section = (int) ($_GET['f_section'] ?? 0);

$classes_list  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts_list   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections_list = $pdo->query("SELECT id, section_name, class_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();

// -----------------------------------------------------------
// Students List (নির্দিষ্ট ক্লাস/শিফট/সেকশনের বর্তমান শিক্ষার্থীরা) + সেদিনের আগে থেকে সেভ করা status (থাকলে)
// -----------------------------------------------------------
$students = [];
if ($f_class > 0) {
    $sql = "SELECT s.id, s.student_id, s.roll_no, s.name_en, s.photo_path, s.guardian_name, s.guardian_phone,
                   sda.status AS saved_status
            FROM students s
            LEFT JOIN student_daily_attendance sda ON sda.student_id = s.id AND sda.attendance_date = ?
            WHERE s.class_id = ?";
    $params = [$f_date, $f_class];
    if ($f_shift > 0)   { $sql .= " AND s.shift_id = ?";   $params[] = $f_shift; }
    if ($f_section > 0) { $sql .= " AND s.section_id = ?"; $params[] = $f_section; }
    $bf_where = []; $bf_params = [];
    branch_apply_filter($bf_where, $bf_params, 's.branch_id');
    if ($bf_where) { $sql .= ' AND ' . implode(' AND ', $bf_where); $params = array_merge($params, $bf_params); }
    $sql .= " ORDER BY (s.roll_no + 0) ASC, s.roll_no ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
}
?>
<style>
    .attendance-toggle-btn {
        border: none; color: #fff; font-weight: 600; font-size: 12.5px;
        padding: 5px 16px; border-radius: 4px; cursor: pointer; min-width: 90px;
    }
    .attendance-toggle-btn.status-absent { background: #ef4444; }
    .attendance-toggle-btn.status-present { background: #10b981; }
    .attendance-avatar {
        width: 32px; height: 32px; border-radius: 50%; object-fit: cover;
        margin-right: 8px; border: 1px solid #e2e8f0; background: #f1f5f9;
    }
</style>

<?php if ($ma_msg): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-check-circle me-2"></i> <?php echo ma_esc($ma_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if ($ma_err): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo ma_esc($ma_err); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Filter Bar -->
<div class="card-custom" id="filterCard">
    <div class="d-flex justify-content-end gap-2 mb-3">
        <button type="button" class="btn btn-sm btn-light border" title="Collapse" onclick="toggleFilterBox()"><i class="fa-solid fa-chevron-up" id="filterChevron"></i></button>
        <a href="index.php?page=attendance_manual" class="btn btn-sm btn-danger" title="Reset"><i class="fa-solid fa-xmark"></i></a>
    </div>

    <form method="GET" id="filterForm">
        <input type="hidden" name="page" value="attendance_manual">
        <div class="row g-3" id="filterFields">
            <div class="col-6 col-md-3">
                <label class="form-label fw-bold small">Day <span class="text-danger">*</span></label>
                <input type="date" name="date" class="form-control" value="<?php echo ma_esc($f_date); ?>" max="<?php echo date('Y-m-d'); ?>" onchange="document.getElementById('filterForm').submit()">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-bold small">Class <span class="text-danger">*</span></label>
                <select name="f_class" class="form-select" onchange="document.getElementById('filterForm').submit()" required>
                    <option value="">-- Select --</option>
                    <?php foreach ($classes_list as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class === (int) $c['id'] ? 'selected' : ''; ?>>
                            <?php echo ma_esc($c['class_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-bold small">Shift</label>
                <select name="f_shift" class="form-select" onchange="document.getElementById('filterForm').submit()">
                    <option value="0">-- All --</option>
                    <?php foreach ($shifts_list as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_shift === (int) $s['id'] ? 'selected' : ''; ?>>
                            <?php echo ma_esc($s['shift_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-bold small">Section</label>
                <select name="f_section" class="form-select" onchange="document.getElementById('filterForm').submit()">
                    <option value="0">-- All --</option>
                    <?php foreach ($sections_list as $sec): if ($f_class && (int) $sec['class_id'] !== $f_class) continue; ?>
                        <option value="<?php echo (int) $sec['id']; ?>" <?php echo $f_section === (int) $sec['id'] ? 'selected' : ''; ?>>
                            <?php echo ma_esc($sec['section_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>
</div>

<!-- Students List -->
<div class="card-custom">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h6 class="fw-bold text-dark m-0"><i class="fa-solid fa-child-reaching"></i> Students List for Attendance</h6>
    </div>

    <?php if ($f_class <= 0): ?>
        <div class="alert alert-light border text-center text-muted py-4 mb-0">
            <i class="fa-solid fa-arrow-up"></i> অ্যাটেন্ডেন্স নিতে উপরে থেকে আগে <b>Class</b> সিলেক্ট করুন।
        </div>
    <?php else: ?>
        <form method="POST" action="index.php?page=attendance_manual" id="attendanceForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="attendance_date" value="<?php echo ma_esc($f_date); ?>">
            <input type="hidden" name="class_id" value="<?php echo (int) $f_class; ?>">
            <input type="hidden" name="shift_id" value="<?php echo (int) $f_shift; ?>">
            <input type="hidden" name="section_id" value="<?php echo (int) $f_section; ?>">

            <div class="table-responsive">
                <table class="table table-bordered align-middle m-0" style="font-size: 13.5px;">
                    <thead class="table-light">
                        <tr>
                            <th style="width:50px;">Sl</th>
                            <th>Student ID</th>
                            <th>Student Name</th>
                            <th style="width:70px;">Roll</th>
                            <th>Guardian's Name</th>
                            <th>Guardian's Mobile</th>
                            <th style="width:120px;">
                                <button type="button" class="attendance-toggle-btn status-absent" id="allToggleBtn" onclick="toggleAll()">All Absent</button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($students): $sl = 1; foreach ($students as $st): $status = $st['saved_status'] ?: 'Absent'; ?>
                            <tr>
                                <td><?php echo $sl++; ?></td>
                                <td><?php echo ma_esc($st['student_id']); ?></td>
                                <td>
                                    <img src="<?php echo $st['photo_path'] ? '../' . ma_esc($st['photo_path']) : 'data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2728%27 height=%2728%27%3E%3Crect width=%2728%27 height=%2728%27 fill=%27%23e2e8f0%27/%3E%3C/svg%3E'; ?>" class="attendance-avatar" alt="">
                                    <?php echo ma_esc($st['name_en']); ?>
                                </td>
                                <td><?php echo ma_esc($st['roll_no']); ?></td>
                                <td><?php echo ma_esc($st['guardian_name']); ?></td>
                                <td><?php echo ma_esc($st['guardian_phone']); ?></td>
                                <td>
                                    <input type="hidden" name="status[<?php echo (int) $st['id']; ?>]" class="status-input" value="<?php echo $status; ?>">
                                    <button type="button" class="attendance-toggle-btn row-toggle-btn status-<?php echo strtolower($status); ?>" onclick="toggleRow(this)">
                                        <?php echo $status; ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">এই ক্লাস/শিফট/সেকশনে কোনো শিক্ষার্থী পাওয়া যায়নি</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($students): ?>
                <div class="d-flex justify-content-end mt-3">
                    <button type="submit" name="save_attendance" class="btn btn-success fw-bold px-4">
                        <i class="fa-solid fa-floppy-disk"></i> Save Attendance
                    </button>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>

<script>
function toggleFilterBox() {
    var fields = document.getElementById('filterFields');
    var icon = document.getElementById('filterChevron');
    var collapsed = fields.style.display === 'none';
    fields.style.display = collapsed ? '' : 'none';
    icon.className = collapsed ? 'fa-solid fa-chevron-up' : 'fa-solid fa-chevron-down';
}

function setBtnState(btn, status) {
    var input = btn.closest('tr').querySelector('.status-input');
    input.value = status;
    btn.textContent = status;
    btn.classList.remove('status-present', 'status-absent');
    btn.classList.add(status === 'Present' ? 'status-present' : 'status-absent');
}

function toggleRow(btn) {
    var input = btn.closest('tr').querySelector('.status-input');
    var next = input.value === 'Present' ? 'Absent' : 'Present';
    setBtnState(btn, next);
}

function toggleAll() {
    var allBtn = document.getElementById('allToggleBtn');
    var markPresent = allBtn.textContent.trim() === 'All Present';
    document.querySelectorAll('.row-toggle-btn').forEach(function (btn) {
        setBtnState(btn, markPresent ? 'Present' : 'Absent');
    });
    allBtn.textContent = markPresent ? 'All Absent' : 'All Present';
    allBtn.classList.remove('status-present', 'status-absent');
    allBtn.classList.add(markPresent ? 'status-present' : 'status-absent');
}
</script>
