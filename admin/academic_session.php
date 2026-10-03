<?php
// admin/academic_session.php - Session List + Add/Edit (স্ক্রিনশট অনুযায়ী)
$sess_err = $_SESSION['sess_err'] ?? '';
$sess_msg = $_SESSION['sess_msg'] ?? '';
$sess_field_errors = $_SESSION['sess_field_errors'] ?? [];
$sess_old = $_SESSION['sess_old'] ?? null;
unset($_SESSION['sess_err'], $_SESSION['sess_msg'], $_SESSION['sess_field_errors'], $_SESSION['sess_old']);

$months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sess_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=academic_session');
        exit();
    }

    if (isset($_POST['save_session'])) {
        $id            = (int) ($_POST['id'] ?? 0);
        $name          = trim($_POST['session_name'] ?? '');
        $short_code    = trim($_POST['short_code'] ?? '');
        $is_fiscal     = ($_POST['is_fiscal_year'] ?? 'No') === 'Yes' ? 1 : 0;
        $start_month   = trim($_POST['start_month'] ?? 'January');
        $end_month     = trim($_POST['end_month'] ?? 'December');
        $is_current    = ($_POST['is_current_session'] ?? 'No') === 'Yes' ? 1 : 0;
        $status        = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        $back_url = $id > 0 ? "index.php?page=academic_session&view=edit&id=$id" : "index.php?page=academic_session&view=add";

        $field_errors = [];
        if ($name === '') {
            $field_errors['session_name'] = 'Name আবশ্যক।';
        }
        if ($short_code === '') {
            $field_errors['short_code'] = 'Short Code আবশ্যক।';
        } elseif (!preg_match('/^\d{2}$/', $short_code)) {
            $field_errors['short_code'] = 'Short Code অবশ্যই ২ ডিজিটের সংখ্যা হতে হবে (যেমন: 26) — Student ID অটো-জেনারেশনে ব্যবহার হয়।';
        }

        // Duplicate check — Short Code গ্লোবালি ইউনিক হতে হবে, কারণ Student ID
        // জেনারেট হয় এই Code দিয়েই, দুইটা Session একই Code শেয়ার করলে
        // ID কলাইড করবে
        if ($short_code !== '' && !isset($field_errors['short_code'])) {
            $chk = $pdo->prepare("SELECT id FROM academic_sessions WHERE short_code = ? " . ($id > 0 ? "AND id != ?" : ""));
            $chk->execute($id > 0 ? [$short_code, $id] : [$short_code]);
            if ($chk->fetch()) {
                $field_errors['short_code'] = 'এই Code দিয়ে একটা Session আগে থেকেই আছে।';
            }
        }

        if ($field_errors) {
            $_SESSION['sess_field_errors'] = $field_errors;
            $_SESSION['sess_old'] = $_POST;
            header("Location: $back_url");
            exit();
        }

        // একটার বেশি "Current Session = Yes" যেন না থাকে
        if ($is_current) {
            $pdo->exec("UPDATE academic_sessions SET is_current_session = 0");
        }

        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE academic_sessions SET session_name=?, short_code=?, is_fiscal_year=?, start_month=?, end_month=?, is_current_session=?, status=? WHERE id=?"
            );
            $stmt->execute([$name, $short_code, $is_fiscal, $start_month, $end_month, $is_current, $status, $id]);
            sec_log_activity($pdo, 'Academic', 'Update', "Session আপডেট করা হয়েছে: {$name} ({$short_code})");
            $_SESSION['sess_msg'] = 'Session আপডেট করা হয়েছে।';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO academic_sessions (session_name, short_code, is_fiscal_year, start_month, end_month, is_current_session, status) VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$name, $short_code, $is_fiscal, $start_month, $end_month, $is_current, $status]);
            sec_log_activity($pdo, 'Academic', 'Create', "নতুন Session যোগ করা হয়েছে: {$name} ({$short_code})");
            $_SESSION['sess_msg'] = 'নতুন Session যোগ করা হয়েছে।';
        }
    } elseif (isset($_POST['delete_session'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_stmt = $pdo->prepare("SELECT session_name FROM academic_sessions WHERE id = ?");
        $del_stmt->execute([$id]);
        $del_name = $del_stmt->fetchColumn();
        $pdo->prepare("DELETE FROM academic_sessions WHERE id = ?")->execute([$id]);
        sec_log_activity($pdo, 'Academic', 'Delete', "Session মুছে ফেলা হয়েছে: " . ($del_name ?: "#$id"));
        $_SESSION['sess_msg'] = 'Session মুছে ফেলা হয়েছে।';
    }

    header('Location: index.php?page=academic_session');
    exit();
}

$view = $_GET['view'] ?? 'list';

function sv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    // ------------------- ADD / EDIT FORM VIEW -------------------
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM academic_sessions WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: [
        'id' => 0, 'session_name' => '', 'short_code' => '', 'is_fiscal_year' => 0,
        'start_month' => 'January', 'end_month' => 'December', 'is_current_session' => 0, 'status' => 'Active',
    ];

    if ($sess_old) {
        $d = [
            'id'                 => (int) ($sess_old['id'] ?? $d['id']),
            'session_name'       => $sess_old['session_name'] ?? $d['session_name'],
            'short_code'         => $sess_old['short_code'] ?? $d['short_code'],
            'is_fiscal_year'     => ($sess_old['is_fiscal_year'] ?? 'No') === 'Yes' ? 1 : 0,
            'start_month'        => $sess_old['start_month'] ?? $d['start_month'],
            'end_month'          => $sess_old['end_month'] ?? $d['end_month'],
            'is_current_session' => ($sess_old['is_current_session'] ?? 'No') === 'Yes' ? 1 : 0,
            'status'             => $sess_old['status'] ?? $d['status'],
        ];
    }
    ?>
    <div class="card-custom">
        <?php if ($sess_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($sess_err); ?></div><?php endif; ?>

        <a href="index.php?page=academic_session" class="d-inline-block bg-warning bg-opacity-75 p-2 mb-4 text-decoration-none" style="width:40px;border-radius:4px;" title="তালিকায় ফিরে যান (Back to List)">
            <i class="fa-solid fa-file-lines text-white"></i>
        </a>

        <form method="POST" action="index.php?page=academic_session">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Name <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="text" name="session_name" class="form-control<?php echo !empty($sess_field_errors['session_name']) ? ' is-invalid' : ''; ?>" value="<?php echo sv($d['session_name']); ?>" required>
                    <?php if (!empty($sess_field_errors['session_name'])): ?><div class="small text-danger mt-1"><?php echo htmlspecialchars($sess_field_errors['session_name']); ?></div><?php endif; ?>
                </div>
            </div>

            <div class="row mb-1 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Short Code <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="text" name="short_code" class="form-control<?php echo !empty($sess_field_errors['short_code']) ? ' is-invalid' : ''; ?>" value="<?php echo sv($d['short_code']); ?>" maxlength="2" placeholder="যেমন: 26" required>
                    <?php if (!empty($sess_field_errors['short_code'])): ?><div class="small text-danger mt-1"><?php echo htmlspecialchars($sess_field_errors['short_code']); ?></div><?php endif; ?>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-sm-6 offset-sm-3"><small class="text-muted fst-italic">Examples : (For 2017 = 17, 2018 = 18, 2018-2019 : 1819)</small></div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Fiscal Year ? <span class="text-danger">*</span></label>
                <div class="col-sm-6 d-flex gap-4 align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="is_fiscal_year" id="fyYes" value="Yes" <?php echo $d['is_fiscal_year'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="fyYes">Yes</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="is_fiscal_year" id="fyNo" value="No" <?php echo !$d['is_fiscal_year'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="fyNo">No</label>
                    </div>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Starting Month</label>
                <div class="col-sm-6">
                    <select name="start_month" class="form-select">
                        <?php foreach ($months as $m): ?>
                            <option value="<?php echo $m; ?>" <?php echo $d['start_month'] === $m ? 'selected' : ''; ?>><?php echo $m; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">End Month</label>
                <div class="col-sm-6">
                    <select name="end_month" class="form-select">
                        <?php foreach ($months as $m): ?>
                            <option value="<?php echo $m; ?>" <?php echo $d['end_month'] === $m ? 'selected' : ''; ?>><?php echo $m; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Current Session ? <span class="text-danger">*</span></label>
                <div class="col-sm-6 d-flex gap-4 align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="is_current_session" id="csYes" value="Yes" <?php echo $d['is_current_session'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="csYes">Yes</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="is_current_session" id="csNo" value="No" <?php echo !$d['is_current_session'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="csNo">No</label>
                    </div>
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
                    <button type="submit" name="save_session" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=academic_session" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

<?php else: ?>
    <?php
    // ------------------- LIST VIEW -------------------
    $f_name    = trim($_GET['f_name'] ?? '');
    $f_code    = trim($_GET['f_code'] ?? '');
    $f_current = $_GET['f_current'] ?? 'All';
    $f_fiscal  = $_GET['f_fiscal'] ?? 'All';
    $f_status  = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_name !== '')  { $where[] = 'session_name LIKE ?'; $params[] = "%$f_name%"; }
    if ($f_code !== '')  { $where[] = 'short_code LIKE ?';   $params[] = "%$f_code%"; }
    if ($f_current !== 'All') { $where[] = 'is_current_session = ?'; $params[] = $f_current === 'Yes' ? 1 : 0; }
    if ($f_fiscal !== 'All')  { $where[] = 'is_fiscal_year = ?';     $params[] = $f_fiscal === 'Yes' ? 1 : 0; }
    if ($f_status !== 'All')  { $where[] = 'status = ?';             $params[] = $f_status; }

    $sql = "SELECT * FROM academic_sessions";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($sess_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($sess_msg); ?></div><?php endif; ?>
        <?php if ($sess_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($sess_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=academic_session&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
            <span class="text-muted small">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> item.</span>
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
            <input type="hidden" name="page" value="academic_session">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>Name</th>
                            <th>Short Code</th>
                            <th>Current Session ?</th>
                            <th>Fiscal Year ?</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <tr>
                            <th></th>
                            <th><input type="text" name="f_name" class="form-control form-control-sm" value="<?php echo sv($f_name); ?>"></th>
                            <th><input type="text" name="f_code" class="form-control form-control-sm" value="<?php echo sv($f_code); ?>"></th>
                            <th>
                                <select name="f_current" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All" <?php echo $f_current === 'All' ? 'selected' : ''; ?>>All</option>
                                    <option value="Yes" <?php echo $f_current === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                    <option value="No" <?php echo $f_current === 'No' ? 'selected' : ''; ?>>No</option>
                                </select>
                            </th>
                            <th>
                                <select name="f_fiscal" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="All" <?php echo $f_fiscal === 'All' ? 'selected' : ''; ?>>All</option>
                                    <option value="Yes" <?php echo $f_fiscal === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                    <option value="No" <?php echo $f_fiscal === 'No' ? 'selected' : ''; ?>>No</option>
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
                        <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td><?php echo sv($row['session_name']); ?></td>
                                <td><?php echo sv($row['short_code']); ?></td>
                                <td><?php echo $row['is_current_session'] ? '<span class="badge bg-primary">Yes</span>' : '<span class="text-muted">No</span>'; ?></td>
                                <td><?php echo $row['is_fiscal_year'] ? '<span class="badge bg-primary">Yes</span>' : '<span class="text-muted">No</span>'; ?></td>
                                <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=academic_session&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                    <a href="index.php?page=academic_session&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=academic_session', 'delete_session', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">No data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
<?php endif; ?>
