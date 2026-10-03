<?php
// admin/academic_section.php - Section List + Add/Edit (স্ক্রিনশট অনুযায়ী)
$sec_err = $_SESSION['sec_err'] ?? '';
$sec_msg = $_SESSION['sec_msg'] ?? '';
unset($_SESSION['sec_err'], $_SESSION['sec_msg']);

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sec_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=academic_section');
        exit();
    }

    if (isset($_POST['save_section'])) {
        $id           = (int) ($_POST['id'] ?? 0);
        $class_ids    = array_map('intval', $_POST['class_ids'] ?? []); // Add ভিউতে একাধিক Class সিলেক্ট করা যায়
        $shift_id     = (int) ($_POST['shift_id'] ?? 0);
        $section_name = trim($_POST['section_name'] ?? '');
        $status       = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if (empty($class_ids) || $shift_id <= 0 || $section_name === '') {
            $_SESSION['sec_err'] = 'Class Name, Shift Name, Name — এই ফিল্ডগুলো আবশ্যক।';
        } else {
            if ($id > 0) {
                // Edit — শুধু একটা Class-এর সাথেই সম্পর্কিত, তাই প্রথম সিলেক্টেড class_id নেওয়া হচ্ছে
                $class_id = $class_ids[0];
                $stmt = $pdo->prepare("UPDATE academic_sections SET class_id=?, shift_id=?, section_name=?, status=? WHERE id=?");
                $stmt->execute([$class_id, $shift_id, $section_name, $status, $id]);
                sec_log_activity($pdo, 'Academic', 'Update', "Section আপডেট করা হয়েছে: {$section_name}");
                $_SESSION['sec_msg'] = 'Section আপডেট করা হয়েছে।';
            } else {
                // Add — একই নামের Section একাধিক Class-এ একসাথে তৈরি হবে (নিজের branch-এর মধ্যেই)
                $sec_branch_id = branch_id_for_insert();
                $chk = $pdo->prepare("SELECT id FROM academic_sections WHERE class_id=? AND shift_id=? AND section_name=? AND (branch_id <=> ?)");
                $ins = $pdo->prepare("INSERT INTO academic_sections (class_id, shift_id, section_name, status, branch_id) VALUES (?, ?, ?, ?, ?)");
                $created = 0;
                $skipped = 0;
                foreach (array_unique($class_ids) as $cid) {
                    if ($cid <= 0) continue;
                    $chk->execute([$cid, $shift_id, $section_name, $sec_branch_id]);
                    if ($chk->fetch()) { $skipped++; continue; } // আগে থেকেই থাকলে বাদ
                    $ins->execute([$cid, $shift_id, $section_name, $status, $sec_branch_id]);
                    $created++;
                }
                if ($created > 0) {
                    sec_log_activity($pdo, 'Academic', 'Create', "নতুন Section যোগ করা হয়েছে: {$section_name} ({$created} টি Class-এ)");
                    $_SESSION['sec_msg'] = "{$created}টি Class-এ নতুন Section যোগ করা হয়েছে।" . ($skipped > 0 ? " ({$skipped}টি Class-এ আগে থেকেই ছিল, বাদ দেওয়া হয়েছে।)" : '');
                } else {
                    $_SESSION['sec_err'] = 'নির্বাচিত সব Class-এই এই নামের Section আগে থেকে আছে।';
                }
            }
        }
    } elseif (isset($_POST['delete_section'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_stmt = $pdo->prepare("SELECT section_name FROM academic_sections WHERE id = ?");
        $del_stmt->execute([$id]);
        $del_name = $del_stmt->fetchColumn();
        $pdo->prepare("DELETE FROM academic_sections WHERE id = ?")->execute([$id]);
        sec_log_activity($pdo, 'Academic', 'Delete', "Section মুছে ফেলা হয়েছে: " . ($del_name ?: "#$id"));
        $_SESSION['sec_msg'] = 'Section মুছে ফেলা হয়েছে।';
    }

    header('Location: index.php?page=academic_section');
    exit();
}

$view = $_GET['view'] ?? 'list';
$classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts_where = [];
$shifts_params = [];
branch_apply_filter($shifts_where, $shifts_params, 'branch_id'); // নিজের branch-এর Shift-ই দেখাবে
$shifts_sql = "SELECT id, shift_name FROM academic_shifts WHERE status = 'Active'" . ($shifts_where ? ' AND ' . implode(' AND ', $shifts_where) : '') . " ORDER BY shift_name ASC";
$shifts_stmt = $pdo->prepare($shifts_sql);
$shifts_stmt->execute($shifts_params);
$shifts = $shifts_stmt->fetchAll();

function scv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    // ------------------- ADD / EDIT FORM VIEW -------------------
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM academic_sections WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: ['id' => 0, 'class_id' => '', 'shift_id' => '', 'section_name' => '', 'status' => 'Active'];
    ?>
    <div class="card-custom">
        <?php if ($sec_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($sec_err); ?></div><?php endif; ?>

        <a href="index.php?page=academic_section" class="d-inline-block bg-warning bg-opacity-75 p-2 mb-4 text-decoration-none" style="width:40px;border-radius:4px;" title="তালিকায় ফিরে যান (Back to List)">
            <i class="fa-solid fa-file-lines text-white"></i>
        </a>

        <form method="POST" action="index.php?page=academic_section" <?php echo $view === 'add' ? 'onsubmit="return document.getElementById(\'secClassMulti\').selectedOptions.length > 0 || (alert(\'অন্তত একটা Class সিলেক্ট করুন।\'), false);"' : ''; ?>>
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row mb-3">
                <label class="col-sm-3 col-form-label fw-bold text-end pt-0">Class Name <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <?php if ($view === 'add'): ?>
                        <!-- Add ভিউতে একাধিক Class সিলেক্ট করা যায় (multi-select) — একই Section একসাথে সব ক-টা Class-এ তৈরি হবে -->
                        <select name="class_ids[]" id="secClassMulti" class="form-select" multiple size="8">
                            <?php foreach ($classes as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>"><?php echo scv($c['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="mt-1">
                            <button type="button" id="secSelectAllBtn" class="btn btn-sm btn-outline-secondary">সব সিলেক্ট করো</button>
                            <button type="button" id="secClearAllBtn" class="btn btn-sm btn-outline-secondary">ক্লিয়ার করো</button>
                        </div>
                        <small class="text-muted">Ctrl (Windows) / Cmd (Mac) চেপে ধরে একাধিক Class ক্লিক করে সিলেক্ট করুন — অথবা উপরের "সব সিলেক্ট করো" বাটন ব্যবহার করুন।</small>
                    <?php else: ?>
                        <!-- Edit ভিউতে একটা Section একটাই Class-এর সাথে সম্পর্কিত, তাই single select -->
                        <select name="class_ids[]" class="form-select" required>
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>" <?php echo (int) $d['class_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo scv($c['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <?php if (!$classes): ?><small class="text-danger d-block">প্রথমে "All Class List" থেকে একটা Class যোগ করুন।</small><?php endif; ?>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Shift Name <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="shift_id" class="form-select" required>
                        <option value="">Select Shift</option>
                        <?php foreach ($shifts as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo (int) $d['shift_id'] === (int) $s['id'] ? 'selected' : ''; ?>><?php echo scv($s['shift_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$shifts): ?><small class="text-danger">প্রথমে "All Shift List" থেকে একটা Shift যোগ করুন।</small><?php endif; ?>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Name <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="section_name" class="form-control" value="<?php echo scv($d['section_name']); ?>" placeholder="e.g. A / Morning / Rose" required></div>
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
                    <button type="submit" name="save_section" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=academic_section" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

    <?php if ($view === 'add'): ?>
    <script>
        (function () {
            var multi = document.getElementById('secClassMulti');
            document.getElementById('secSelectAllBtn').addEventListener('click', function () {
                Array.from(multi.options).forEach(function (o) { o.selected = true; });
            });
            document.getElementById('secClearAllBtn').addEventListener('click', function () {
                Array.from(multi.options).forEach(function (o) { o.selected = false; });
            });
        })();
    </script>
    <?php endif; ?>

<?php else: ?>
    <?php
    // ------------------- LIST VIEW -------------------
    $f_class  = $_GET['f_class'] ?? 'All';
    $f_shift  = $_GET['f_shift'] ?? 'All';
    $f_name   = trim($_GET['f_name'] ?? '');
    $f_status = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_class !== 'All') { $where[] = 'academic_sections.class_id = ?'; $params[] = (int) $f_class; }
    if ($f_shift !== 'All') { $where[] = 'academic_sections.shift_id = ?'; $params[] = (int) $f_shift; }
    if ($f_name !== '')     { $where[] = 'section_name LIKE ?'; $params[] = "%$f_name%"; }
    if ($f_status !== 'All') { $where[] = 'status = ?'; $params[] = $f_status; }
    branch_apply_filter($where, $params, 'academic_sections.branch_id'); // লগইন করা branch অনুযায়ী Section ফিল্টার

    $sql = "SELECT academic_sections.*, academic_classes.class_name, academic_shifts.shift_name FROM academic_sections
            LEFT JOIN academic_classes ON academic_classes.id = academic_sections.class_id
            LEFT JOIN academic_shifts ON academic_shifts.id = academic_sections.shift_id";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY academic_sections.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($sec_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($sec_msg); ?></div><?php endif; ?>
        <?php if ($sec_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($sec_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=academic_section&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
            <span class="text-muted small">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> items.</span>
        </div>

        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="academic_section">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>Class Name</th>
                            <th>Shift Name</th>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <tr>
                            <th></th>
                            <th>
                                <select name="f_class" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All">All</option>
                                    <?php foreach ($classes as $c): ?>
                                        <option value="<?php echo (int) $c['id']; ?>" <?php echo (string) $f_class === (string) $c['id'] ? 'selected' : ''; ?>><?php echo scv($c['class_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th>
                                <select name="f_shift" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All">All</option>
                                    <?php foreach ($shifts as $s): ?>
                                        <option value="<?php echo (int) $s['id']; ?>" <?php echo (string) $f_shift === (string) $s['id'] ? 'selected' : ''; ?>><?php echo scv($s['shift_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th><input type="text" name="f_name" class="form-control form-control-sm" value="<?php echo scv($f_name); ?>"></th>
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
                        <?php if ($rows): $srl = 1; foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td><?php echo scv($row['class_name'] ?? '-'); ?></td>
                                <td><?php echo scv($row['shift_name'] ?? '-'); ?></td>
                                <td><?php echo scv($row['section_name']); ?></td>
                                <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=academic_section&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                    <a href="index.php?page=academic_section&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="secDeleteRow(<?php echo (int) $row['id']; ?>)"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">No data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <!-- ডিলিটের জন্য আলাদা, ফিল্টার ফর্মের বাইরে একটা শেয়ার্ড হিডেন ফর্ম — সারি-ভিত্তিক
             ফর্ম আগে ফিল্টার ফর্মের ভেতরে নেস্টেড থাকায় ব্রাউজার সেটাকে ইনভ্যালিড HTML
             ধরে নিয়ে বাদ দিয়ে দিচ্ছিল, তাই ডিলিট কাজ করছিল না — সেটার ফিক্স -->
        <form method="POST" action="index.php?page=academic_section" id="secDeleteForm" class="d-none">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" id="secDeleteId">
            <input type="hidden" name="delete_section" value="1">
        </form>
        <script>
            function secDeleteRow(id) {
                if (!confirm('মুছে ফেলবেন?')) return;
                document.getElementById('secDeleteId').value = id;
                document.getElementById('secDeleteForm').submit();
            }
        </script>
    </div>
<?php endif; ?>
