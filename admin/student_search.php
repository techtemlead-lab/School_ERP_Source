<?php
// admin/student_search.php - Current + Archive Student Search (একই ফাইল, is_current দিয়ে আলাদা)
require_once __DIR__ . '/../config/table_print.php';
$is_archive = ($page === 'archive_student_search');
$mode_label = $is_archive ? 'Archive' : 'Current';
$search_page_slug = $is_archive ? 'archive_student_search' : 'current_student_search';

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes ORDER BY rank ASC, class_name ASC")->fetchAll();
$sections = $pdo->query(
    "SELECT academic_sections.id, academic_sections.section_name, academic_sections.class_id, academic_classes.class_name
     FROM academic_sections
     LEFT JOIN academic_classes ON academic_classes.id = academic_sections.class_id
     ORDER BY academic_sections.section_name ASC"
)->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts ORDER BY shift_name ASC")->fetchAll();

function ssv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// Search Filters
// -----------------------------------------------------------
$did_search = isset($_GET['do_search']);

$f_session  = $_GET['f_session'] ?? '';
$f_class    = $_GET['f_class'] ?? '';
$f_shift    = $_GET['f_shift'] ?? '';
$f_section  = $_GET['f_section'] ?? '';
$f_gender   = $_GET['f_gender'] ?? '';
$f_blood    = $_GET['f_blood'] ?? '';
$f_phone    = trim($_GET['f_phone'] ?? '');
$f_sid      = trim($_GET['f_sid'] ?? '');
$f_religion = $_GET['f_religion'] ?? '';
$f_dob      = trim($_GET['f_dob'] ?? '');
$f_status   = $_GET['f_status'] ?? ($is_archive ? '' : 'Active');

$is_print_all = tprint_is_print_all();
$rows = [];
$total_matched = 0;
$ss_sub = '';
if ($did_search) {
    $where = ['students.is_current = ?'];
    $params = [$is_archive ? 0 : 1];

    if ($f_session !== '')  { $where[] = 'students.session_id = ?'; $params[] = (int) $f_session; }
    if ($f_class !== '')    { $where[] = 'students.class_id = ?'; $params[] = (int) $f_class; }
    if ($f_shift !== '')    { $where[] = 'students.shift_id = ?'; $params[] = (int) $f_shift; }
    if ($f_section !== '')  { $where[] = 'students.section_id = ?'; $params[] = (int) $f_section; }
    if ($f_gender !== '')   { $where[] = 'students.gender = ?'; $params[] = $f_gender; }
    if ($f_blood !== '')    { $where[] = 'students.blood_group = ?'; $params[] = $f_blood; }
    if ($f_phone !== '')    { $where[] = 'students.guardian_phone LIKE ?'; $params[] = "%$f_phone%"; }
    if ($f_sid !== '')      { $where[] = 'students.student_id LIKE ?'; $params[] = "%$f_sid%"; }
    if ($f_religion !== '') { $where[] = 'students.religion = ?'; $params[] = $f_religion; }
    if ($f_dob !== '')      { $where[] = 'students.dob = ?'; $params[] = $f_dob; }
    if ($f_status !== '')   { $where[] = 'students.status = ?'; $params[] = $f_status; }
    branch_apply_filter($where, $params, 'students.branch_id'); // লগইন করা branch অনুযায়ী স্টুডেন্ট ফিল্টার

    $where_sql = implode(' AND ', $where);
    $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE $where_sql");
    $cnt_stmt->execute($params);
    $total_matched = (int) $cnt_stmt->fetchColumn();

    $limit = $is_print_all ? 5000 : 100;
    $sql = "SELECT students.*, academic_sessions.session_name, academic_classes.class_name,
                   academic_shifts.shift_name, academic_sections.section_name
            FROM students
            LEFT JOIN academic_sessions ON academic_sessions.id = students.session_id
            LEFT JOIN academic_classes ON academic_classes.id = students.class_id
            LEFT JOIN academic_shifts ON academic_shifts.id = students.shift_id
            LEFT JOIN academic_sections ON academic_sections.id = students.section_id
            WHERE $where_sql
            ORDER BY students.class_id ASC, students.section_id ASC, CAST(students.roll_no AS UNSIGNED) ASC, students.id ASC LIMIT $limit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $ss_find = function (array $list, $id, string $col): string {
        foreach ($list as $it) { if ((string) $it['id'] === (string) $id) { return (string) $it[$col]; } }
        return '';
    };
    $ss_parts = [];
    if ($f_session !== '')  { $ss_parts[] = 'Session: ' . ($ss_find($sessions, $f_session, 'session_name') ?: $f_session); }
    if ($f_class !== '')    { $ss_parts[] = 'Class: ' . ($ss_find($classes, $f_class, 'class_name') ?: $f_class); }
    if ($f_shift !== '')    { $ss_parts[] = 'Shift: ' . ($ss_find($shifts, $f_shift, 'shift_name') ?: $f_shift); }
    if ($f_section !== '')  { $ss_parts[] = 'Section: ' . ($ss_find($sections, $f_section, 'section_name') ?: $f_section); }
    if ($f_gender !== '')   { $ss_parts[] = 'Gender: ' . $f_gender; }
    if ($f_blood !== '')    { $ss_parts[] = 'Blood: ' . $f_blood; }
    if ($f_religion !== '') { $ss_parts[] = 'Religion: ' . $f_religion; }
    if ($f_phone !== '')    { $ss_parts[] = 'Phone: ' . $f_phone; }
    if ($f_sid !== '')      { $ss_parts[] = 'ID: ' . $f_sid; }
    if ($f_dob !== '')      { $ss_parts[] = 'DOB: ' . $f_dob; }
    if ($f_status !== '')   { $ss_parts[] = 'Status: ' . $f_status; }
    $ss_parts[] = 'Total: ' . $total_matched;
    $ss_sub = implode(' | ', $ss_parts);
}
?>
<?php echo tprint_page_css('portrait', 11); ?>

<div class="card-custom mb-3 no-print">
    <h5 class="fw-normal text-dark mb-4">Student Search (<?php echo $mode_label; ?>)</h5>

    <form method="GET" action="index.php">
        <input type="hidden" name="page" value="<?php echo $search_page_slug; ?>">
        <input type="hidden" name="do_search" value="1">

        <div class="row">
            <div class="col-md-4">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label text-end">Session</label>
                    <div class="col-sm-8">
                        <select name="f_session" class="form-select">
                            <option value="">Select Session</option>
                            <?php foreach ($sessions as $s): ?>
                                <option value="<?php echo (int) $s['id']; ?>" <?php echo (string) $f_session === (string) $s['id'] ? 'selected' : ''; ?>><?php echo ssv($s['session_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label text-end">Section Name</label>
                    <div class="col-sm-8">
                        <select name="f_section" id="searchSectionSelect" class="form-select">
                            <option value="">Select Section</option>
                            <?php foreach ($sections as $sec): ?>
                                <option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" <?php echo (string) $f_section === (string) $sec['id'] ? 'selected' : ''; ?>><?php echo ssv($sec['section_name']); ?><?php echo $sec['class_name'] ? ' (' . ssv($sec['class_name']) . ')' : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label text-end">Guardian Phone</label>
                    <div class="col-sm-8"><input type="tel" name="f_phone" class="form-control phone-11" inputmode="numeric" maxlength="11" value="<?php echo ssv($f_phone); ?>"></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label text-end">Date of Birth</label>
                    <div class="col-sm-8"><input type="text" name="f_dob" id="searchDobFilter" class="form-control" placeholder="yyyy-mm-dd" autocomplete="off" value="<?php echo ssv($f_dob); ?>"></div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label text-end">Class Name</label>
                    <div class="col-sm-8">
                        <select name="f_class" id="searchClassSelect" class="form-select" onchange="filterSearchSections()">
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>" <?php echo (string) $f_class === (string) $c['id'] ? 'selected' : ''; ?>><?php echo ssv($c['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label text-end">Gender</label>
                    <div class="col-sm-8">
                        <select name="f_gender" class="form-select">
                            <option value="">Select Gender</option>
                            <option value="Male" <?php echo $f_gender === 'Male' ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo $f_gender === 'Female' ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label text-end">Student ID</label>
                    <div class="col-sm-8"><input type="text" name="f_sid" class="form-control" value="<?php echo ssv($f_sid); ?>"></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label text-end">Religion</label>
                    <div class="col-sm-8">
                        <select name="f_religion" class="form-select">
                            <option value="">Select religion</option>
                            <?php foreach (['Islam', 'Hinduism', 'Buddhism', 'Christianity', 'Others'] as $rl): ?>
                                <option value="<?php echo $rl; ?>" <?php echo $f_religion === $rl ? 'selected' : ''; ?>><?php echo $rl; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label text-end">Shift</label>
                    <div class="col-sm-8">
                        <select name="f_shift" class="form-select">
                            <option value="">Select Shift</option>
                            <?php foreach ($shifts as $sh): ?>
                                <option value="<?php echo (int) $sh['id']; ?>" <?php echo (string) $f_shift === (string) $sh['id'] ? 'selected' : ''; ?>><?php echo ssv($sh['shift_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label text-end">Blood Group</label>
                    <div class="col-sm-8">
                        <select name="f_blood" class="form-select">
                            <option value="">Select Blood Group</option>
                            <?php foreach (['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'] as $bg): ?>
                                <option value="<?php echo $bg; ?>" <?php echo $f_blood === $bg ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label text-end">Status</label>
                    <div class="col-sm-8">
                        <select name="f_status" class="form-select">
                            <option value="">All</option>
                            <option value="Pending" <?php echo $f_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Active" <?php echo $f_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo $f_status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="Transfer" <?php echo $f_status === 'Transfer' ? 'selected' : ''; ?>>Transfer</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-end">
            <button type="submit" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;">Search</button>
        </div>
    </form>
</div>

<div class="card-custom">
    <?php if (!$did_search): ?>
        <p class="text-muted mb-0">উপরের ফর্ম পূরণ করে Search বাটনে ক্লিক করুন।</p>
    <?php elseif (!$rows): ?>
        <p class="text-muted mb-0">No data exists !</p>
    <?php else: ?>
        <?php echo tprint_header_html($pdo, 'Student Search (' . $mode_label . ')', $ss_sub); ?>
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2 no-print">
            <span class="text-muted small">Showing <?php echo count($rows); ?> of <?php echo $total_matched; ?> students</span>
            <?php echo tprint_controls([
                'orient'        => 'portrait',
                'wrap'          => false,
                'print_all_url' => tprint_all_url(),
                'total_count'   => $total_matched,
                'shown_count'   => count($rows),
                'is_print_all'  => $is_print_all,
            ]); ?>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-dark">
                    <tr><th>Student ID</th><th>Name</th><th>Session</th><th>Class</th><th>Section</th><th>Shift</th><th>Guardian Phone</th><th>Status</th><th class="text-end no-print">Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo ssv($row['student_id']); ?></td>
                            <td><?php echo ssv($row['name_en']); ?></td>
                            <td><?php echo ssv($row['session_name'] ?? '-'); ?></td>
                            <td><?php echo ssv($row['class_name'] ?? '-'); ?></td>
                            <td><?php echo ssv($row['section_name'] ?? '-'); ?></td>
                            <td><?php echo ssv($row['shift_name'] ?? '-'); ?></td>
                            <td><?php echo ssv($row['guardian_phone']); ?></td>
                            <td><?php
                                $ss_status_badges = ['Active' => 'success', 'Pending' => 'warning', 'Transfer' => 'info', 'Inactive' => 'secondary'];
                                $ss_badge_class = $ss_status_badges[$row['status']] ?? 'secondary';
                                echo '<span class="badge bg-' . $ss_badge_class . '">' . ssv($row['status']) . '</span>';
                            ?></td>
                            <td class="text-end no-print">
                                <a href="index.php?page=student_admission&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="View/Edit"><i class="fa-solid fa-pen"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<link href="https://cdn.jsdelivr.net/npm/air-datepicker@3.6.0/air-datepicker.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/air-datepicker@3.6.0/air-datepicker.js"></script>
<script>
    function filterSearchSections() {
        const classId = document.getElementById('searchClassSelect').value;
        const select = document.getElementById('searchSectionSelect');
        Array.from(select.options).forEach(opt => {
            if (!opt.value) { opt.hidden = false; return; }
            opt.hidden = classId && opt.dataset.class !== classId;
        });
    }
    filterSearchSections();

    // Date of Birth ফিল্টারেও একই ভালো ক্যালেন্ডার — ব্রাউজারের ডিফল্ট date
    // picker-এর বদলে (Student Admission ফর্মের মতোই)
    (function () {
        const el = document.getElementById('searchDobFilter');
        if (!el || typeof AirDatepicker === 'undefined') return;
        const enLocale = {
            days: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
            daysShort: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            daysMin: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'],
            months: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            monthsShort: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            today: 'Today', clear: 'Clear', dateFormat: 'yyyy-MM-dd', timeFormat: 'hh:mm aa', firstDay: 0,
        };
        new AirDatepicker('#searchDobFilter', {
            locale: enLocale,
            dateFormat: 'yyyy-MM-dd',
            maxDate: new Date(),
            startDate: el.value ? new Date(el.value) : new Date(2015, 0, 1),
            autoClose: true,
        });
    })();

// ফোন নম্বর ফিল্ড (.phone-11 ক্লাস): টাইপ করার সাথে সাথেই সংখ্যা ছাড়া অন্য কিছু নেয় না,
// ১১ ডিজিটের বেশি হলে কেটে দেয়, ১১ ডিজিট না হওয়া পর্যন্ত নিচে লাল মেসেজ দেখায় (রিলোড ছাড়া),
// ১১ ডিজিট হয়ে গেলেই মেসেজ সাথে সাথে সরে যায়
document.querySelectorAll('.phone-11').forEach(function (input) {
    let errEl = input.nextElementSibling;
    if (!errEl || !errEl.classList.contains('phone11-err')) {
        errEl = document.createElement('div');
        errEl.className = 'small text-danger mt-1 d-none phone11-err';
        errEl.textContent = 'মোবাইল নম্বর অবশ্যই ১১ ডিজিটের হতে হবে।';
        input.insertAdjacentElement('afterend', errEl);
    }
    function checkPhone11() {
        let digits = input.value.replace(/\D/g, '');
        if (digits.length > 11) digits = digits.slice(0, 11);
        input.value = digits;
        const invalid = digits.length > 0 && digits.length !== 11;
        errEl.classList.toggle('d-none', !invalid);
        input.classList.toggle('is-invalid', invalid);
    }
    input.addEventListener('input', checkPhone11);
    input.addEventListener('blur', checkPhone11);
});
</script>
