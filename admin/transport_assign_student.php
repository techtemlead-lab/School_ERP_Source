<?php
// admin/transport_assign_student.php - Transport Module > Assign Student to Route/Stop
$ta_err = $_SESSION['ta_err'] ?? '';
$ta_msg = $_SESSION['ta_msg'] ?? '';
unset($_SESSION['ta_err'], $_SESSION['ta_msg']);

require_once __DIR__ . '/transport_common.php';

// -----------------------------------------------------------
// POST: Assign / Unassign
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ta_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=transport_assign_student');
        exit();
    }

    if (isset($_POST['assign_student'])) {
        $student_pk   = (int) ($_POST['student_pk'] ?? 0);
        $route_id     = (int) ($_POST['route_id'] ?? 0);
        $stop_id      = (int) ($_POST['stop_id'] ?? 0);
        $fee_override = trim($_POST['fee_override'] ?? '') !== '' ? (float) $_POST['fee_override'] : null;
        $remarks      = trim($_POST['remarks'] ?? '');

        $check = $pdo->prepare("SELECT id FROM transport_student_assign WHERE student_id = ? AND status = 'Active'");
        $check->execute([$student_pk]);
        $existing = $check->fetch();

        if (!$route_id || !$stop_id) {
            $_SESSION['ta_err'] = 'Route ও Stop নির্বাচন করুন।';
        } elseif ($existing) {
            $_SESSION['ta_err'] = 'এই শিক্ষার্থী ইতিমধ্যে একটি রুটে সক্রিয় আছে। আগে সেটি Unassign করুন, তারপর নতুন রুট দিন।';
        } else {
            $token = bin2hex(random_bytes(16)); // Parent Tracking Link এর জন্য ইউনিক টোকেন
            $stmt = $pdo->prepare("INSERT INTO transport_student_assign (student_id, route_id, stop_id, fee_override, tracking_token, assigned_date, remarks) VALUES (?,?,?,?,?,CURDATE(),?)");
            $stmt->execute([$student_pk, $route_id, $stop_id, $fee_override, $token, $remarks]);
            $_SESSION['ta_msg'] = 'শিক্ষার্থীকে ট্রান্সপোর্ট রুটে যুক্ত করা হয়েছে। নিচে "Parent Tracking Link" থেকে লিংকটি অভিভাবককে শেয়ার করতে পারেন।';
            $ta_stu_name = $pdo->prepare("SELECT name_en FROM students WHERE id = ?");
            $ta_stu_name->execute([$student_pk]);
            sec_log_activity($pdo, 'Transport', 'Create', "শিক্ষার্থীকে ট্রান্সপোর্ট রুটে যুক্ত করা হয়েছে: " . ($ta_stu_name->fetchColumn() ?: "#$student_pk"));
        }

        header('Location: index.php?page=transport_assign_student&student_pk=' . $student_pk);
        exit();
    } elseif (isset($_POST['unassign_student'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $student_pk = (int) ($_POST['student_pk'] ?? 0);
        $pdo->prepare("UPDATE transport_student_assign SET status='Inactive' WHERE id = ?")->execute([$id]);
        $_SESSION['ta_msg'] = 'শিক্ষার্থীকে রুট থেকে সরিয়ে নেওয়া হয়েছে।';
        sec_log_activity($pdo, 'Transport', 'Update', "শিক্ষার্থীকে ট্রান্সপোর্ট রুট থেকে সরিয়ে নেওয়া হয়েছে (Assign #{$id})।");
        header('Location: index.php?page=transport_assign_student&student_pk=' . $student_pk);
        exit();
    }
}

// -----------------------------------------------------------
// Student Search
// -----------------------------------------------------------
$q         = trim($_GET['q'] ?? '');
$f_class   = $_GET['f_class'] ?? '';
$f_section = $_GET['f_section'] ?? '';
$classes   = $pdo->query("SELECT id, class_name FROM academic_classes ORDER BY rank ASC, class_name ASC")->fetchAll();
$sections  = $pdo->query("SELECT s.id, s.section_name, s.class_id, c.class_name FROM academic_sections s LEFT JOIN academic_classes c ON c.id = s.class_id ORDER BY s.section_name ASC")->fetchAll();

$search_rows = [];
if ($q !== '' || $f_class !== '' || $f_section !== '') {
    $where = ["students.status = 'Active'"];
    $params = [];
    if ($q !== '') {
        $where[] = '(students.name_en LIKE ? OR students.name_bn LIKE ? OR students.student_id LIKE ? OR students.roll_no LIKE ?)';
        $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
    }
    if ($f_class !== '')   { $where[] = 'students.class_id = ?'; $params[] = (int) $f_class; }
    if ($f_section !== '') { $where[] = 'students.section_id = ?'; $params[] = (int) $f_section; }
    branch_apply_filter($where, $params, 'students.branch_id');

    $sql = "SELECT students.id, students.student_id, students.name_en, students.name_bn, students.roll_no,
                   academic_classes.class_name, academic_sections.section_name,
                   (SELECT COUNT(*) FROM transport_student_assign tsa WHERE tsa.student_id = students.id AND tsa.status='Active') AS has_transport
            FROM students
            LEFT JOIN academic_classes ON academic_classes.id = students.class_id
            LEFT JOIN academic_sections ON academic_sections.id = students.section_id
            WHERE " . implode(' AND ', $where) . " ORDER BY students.name_en ASC LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $search_rows = $stmt->fetchAll();
}

// -----------------------------------------------------------
// Selected Student
// -----------------------------------------------------------
$student_pk = (int) ($_GET['student_pk'] ?? 0);
$selected_student = null;
$active_assign = null;
if ($student_pk > 0) {
    $stmt = $pdo->prepare("SELECT students.*, academic_classes.class_name, academic_sections.section_name
                            FROM students
                            LEFT JOIN academic_classes ON academic_classes.id = students.class_id
                            LEFT JOIN academic_sections ON academic_sections.id = students.section_id
                            WHERE students.id = ?");
    $stmt->execute([$student_pk]);
    $selected_student = $stmt->fetch();

    $astmt = $pdo->prepare("SELECT tsa.*, tr.route_no, tr.route_name, tr.monthly_fee, ts.stop_name
                             FROM transport_student_assign tsa
                             JOIN transport_routes tr ON tr.id = tsa.route_id
                             JOIN transport_stops ts ON ts.id = tsa.stop_id
                             WHERE tsa.student_id = ? AND tsa.status = 'Active'");
    $astmt->execute([$student_pk]);
    $active_assign = $astmt->fetch();
}

// Student/Mixed — উভয় ক্যাটাগরির রুটে ছাত্র এসাইন করা যাবে
$routes = $pdo->query("SELECT id, route_no, route_name, monthly_fee, route_category FROM transport_routes WHERE status='Active' AND route_category IN ('Student','Mixed') ORDER BY route_name ASC")->fetchAll();

// track.php এর সম্পূর্ণ URL বানানোর জন্য বর্তমান ডোমেইন বের করা (admin/ ফোল্ডারের এক লেভেল উপরে track.php থাকে)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$base_path = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/'); // /admin থেকে এক ধাপ উপরে
$track_base_url = $scheme . '://' . $host . $base_path . '/track.php?token=';
?>

<div class="card-custom mb-3">
    <?php if ($ta_msg): ?><div class="alert alert-success py-2"><?php echo lv($ta_msg); ?></div><?php endif; ?>
    <?php if ($ta_err): ?><div class="alert alert-danger py-2"><?php echo lv($ta_err); ?></div><?php endif; ?>

    <h5 class="fw-normal text-dark mb-3"><i class="fa-solid fa-magnifying-glass text-primary me-1"></i> Student খুঁজুন (Student ID / Name / Roll)</h5>
    <form method="GET" action="index.php" class="row g-2">
        <input type="hidden" name="page" value="transport_assign_student">
        <div class="col-md-4"><input type="text" name="q" class="form-control" placeholder="Student ID / Name / Roll No" value="<?php echo lv($q); ?>"></div>
        <div class="col-md-3">
            <select name="f_class" class="form-select" id="transClassSelect">
                <option value="">All Classes</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>" <?php echo (string) $f_class === (string) $c['id'] ? 'selected' : ''; ?>><?php echo lv($c['class_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select name="f_section" class="form-select" id="transSectionSelect">
                <option value="">All Sections</option>
                <?php foreach ($sections as $s): ?>
                    <option value="<?php echo (int) $s['id']; ?>" data-class="<?php echo (int) $s['class_id']; ?>" <?php echo (string) $f_section === (string) $s['id'] ? 'selected' : ''; ?>><?php echo lv($s['section_name']); ?><?php echo !empty($s['class_name']) ? ' (' . lv($s['class_name']) . ')' : ''; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-search"></i> Search</button></div>
    </form>

    <?php if ($search_rows): ?>
        <div class="table-responsive mt-3">
            <table class="table table-bordered align-middle table-sm">
                <thead class="table-light"><tr><th>Student ID</th><th>Name</th><th>Class/Section</th><th>Transport</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($search_rows as $s): ?>
                        <tr class="<?php echo $s['id'] == $student_pk ? 'table-success' : ''; ?>">
                            <td><?php echo lv($s['student_id']); ?></td>
                            <td><?php echo lv($s['name_en']); ?></td>
                            <td><?php echo lv($s['class_name']); ?> <?php echo $s['section_name'] ? '/ ' . lv($s['section_name']) : ''; ?></td>
                            <td><?php echo $s['has_transport'] ? '<span class="badge bg-success">Assigned</span>' : '<span class="badge bg-secondary">Not Assigned</span>'; ?></td>
                            <td><a href="index.php?page=transport_assign_student&student_pk=<?php echo (int) $s['id']; ?>" class="btn btn-sm btn-success">Select <i class="fa-solid fa-arrow-right"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($q !== '' || $f_class !== '' || $f_section !== ''): ?>
        <p class="text-muted mt-3 mb-0">কোনো শিক্ষার্থী পাওয়া যায়নি।</p>
    <?php endif; ?>
</div>

<?php if ($selected_student): ?>
<div class="row">
    <div class="col-md-5">
        <div class="card-custom">
            <h6 class="fw-bold text-primary mb-3"><i class="fa-solid fa-id-card me-1"></i> Selected Student</h6>
            <table class="table table-borderless table-sm mb-0">
                <tr><td class="text-muted" style="width:40%;">Student ID</td><td class="fw-bold"><?php echo lv($selected_student['student_id']); ?></td></tr>
                <tr><td class="text-muted">Name</td><td class="fw-bold"><?php echo lv($selected_student['name_en']); ?></td></tr>
                <tr><td class="text-muted">Class / Section</td><td><?php echo lv($selected_student['class_name']); ?> <?php echo $selected_student['section_name'] ? '/ ' . lv($selected_student['section_name']) : ''; ?></td></tr>
                <tr><td class="text-muted">Roll No</td><td><?php echo lv($selected_student['roll_no']); ?></td></tr>
            </table>

            <?php if ($active_assign): ?>
                <hr>
                <div class="alert alert-info py-2 mb-2">
                    <strong>বর্তমান রুট:</strong> <?php echo $active_assign['route_no'] ? lv($active_assign['route_no']) . ' - ' : ''; ?><?php echo lv($active_assign['route_name']); ?><br>
                    <strong>স্টপেজ:</strong> <?php echo lv($active_assign['stop_name']); ?><br>
                    <strong>মাসিক ফি:</strong> ৳<?php echo number_format((float) ($active_assign['fee_override'] ?? $active_assign['monthly_fee']), 2); ?>
                </div>

                <?php if (!empty($active_assign['tracking_token'])): $track_url = $track_base_url . $active_assign['tracking_token']; ?>
                <div class="mb-2">
                    <label class="form-label small fw-bold mb-1"><i class="fa-solid fa-location-dot text-danger"></i> Parent Tracking Link</label>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" id="trackLinkInput" value="<?php echo lv($track_url); ?>" readonly>
                        <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('trackLinkInput').value); this.innerText='Copied!'; setTimeout(()=>this.innerText='Copy', 1500);">Copy</button>
                    </div>
                    <div class="form-text">এই লিংকটি SMS/WhatsApp করে অভিভাবককে দিন — শুধু এই ছাত্রের বাসের লাইভ অবস্থান দেখাবে</div>
                </div>
                <?php endif; ?>

                <form method="POST" action="index.php?page=transport_assign_student" onsubmit="return confirm('এই শিক্ষার্থীকে রুট থেকে সরিয়ে নেবেন?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $active_assign['id']; ?>">
                    <input type="hidden" name="student_pk" value="<?php echo (int) $selected_student['id']; ?>">
                    <button type="submit" name="unassign_student" class="btn btn-outline-danger w-100"><i class="fa-solid fa-xmark"></i> Unassign from Route</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card-custom">
            <h6 class="fw-bold text-success mb-3"><i class="fa-solid fa-route me-1"></i> Assign to Route</h6>

            <?php if ($active_assign): ?>
                <div class="alert alert-warning py-2">এই শিক্ষার্থী ইতিমধ্যে একটি রুটে সক্রিয় আছে। নতুন রুট দিতে হলে আগে Unassign করুন।</div>
            <?php elseif (!$routes): ?>
                <div class="alert alert-warning py-2">এখনো কোনো "Student" বা "Mixed" ক্যাটাগরির রুট তৈরি করা হয়নি। Route Management এ গিয়ে একটা রুট বানান।</div>
            <?php else: ?>
                <form method="POST" action="index.php?page=transport_assign_student">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="student_pk" value="<?php echo (int) $selected_student['id']; ?>">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Route <span class="text-danger">*</span></label>
                        <select name="route_id" id="assignRouteSelect" class="form-select" required>
                            <option value="">-- Route নির্বাচন করুন --</option>
                            <?php foreach ($routes as $r): ?>
                                <option value="<?php echo (int) $r['id']; ?>">
                                    <?php echo $r['route_no'] ? lv($r['route_no']) . ' — ' : ''; ?><?php echo lv($r['route_name']); ?> — ৳<?php echo number_format((float) $r['monthly_fee'], 2); ?>/month
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Stop <span class="text-danger">*</span></label>
                        <select name="stop_id" id="assignStopSelect" class="form-select" required>
                            <option value="">-- আগে Route নির্বাচন করুন --</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Fee Override (ঐচ্ছিক, ৳)</label>
                        <input type="number" step="0.01" min="0" name="fee_override" class="form-control" placeholder="খালি রাখলে Route এর ডিফল্ট ফি প্রযোজ্য হবে">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Remarks</label>
                        <input type="text" name="remarks" class="form-control">
                    </div>

                    <button type="submit" name="assign_student" class="btn btn-success px-4 fw-bold"><i class="fa-solid fa-check"></i> Assign to Route</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const routeStopsData = <?php
    $all_stops_by_route = [];
    $stops_all = $pdo->query("SELECT id, route_id, stop_name FROM transport_stops ORDER BY stop_order ASC")->fetchAll();
    foreach ($stops_all as $st) { $all_stops_by_route[$st['route_id']][] = ['id' => $st['id'], 'name' => $st['stop_name']]; }
    echo json_encode($all_stops_by_route, JSON_UNESCAPED_UNICODE);
?>;

document.getElementById('assignRouteSelect')?.addEventListener('change', function () {
    const stopSelect = document.getElementById('assignStopSelect');
    const stops = routeStopsData[this.value] || [];
    stopSelect.innerHTML = '<option value="">-- Stop নির্বাচন করুন --</option>';
    stops.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = s.name;
        stopSelect.appendChild(opt);
    });
    if (stops.length === 0) stopSelect.innerHTML = '<option value="">এই রুটে কোনো স্টপেজ যোগ করা নেই</option>';
});
</script>
<?php endif; ?>

<script>
document.getElementById('transClassSelect')?.addEventListener('change', function () {
    const classId = this.value;
    const sectionSelect = document.getElementById('transSectionSelect');
    Array.from(sectionSelect.options).forEach(opt => {
        if (!opt.value) { opt.hidden = false; return; }
        opt.hidden = classId && opt.getAttribute('data-class') !== classId;
    });
    sectionSelect.value = '';
});
</script>
