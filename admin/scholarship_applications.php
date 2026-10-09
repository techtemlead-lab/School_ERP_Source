<?php
// admin/scholarship_applications.php - Finance Module > Scholarship & Welfare > Scholarship Applications
// Student Portal থেকে অভিভাবকদের জমা দেওয়া শিক্ষাবৃত্তি আবেদন এখানে যাচাই হয়।
//   Approve : স্কলারশিপ টাইপ + পরিমাণ + প্রদানের ধরন (ফি-র বকেয়ার সাথে সমন্বয় / হাতে নগদ) ঠিক করা হয় →
//             বিদ্যমান "Assign Scholarship"-এর মতো একটা Pending Award তৈরি হয় → "Process Disbursement"
//             পেজে গিয়ে চূড়ান্ত বণ্টন (ফি সমন্বয় ও/অথবা নগদ + রিসিপ্ট) নিশ্চিত করতে হয়।
//   Reject  : মন্তব্যসহ বাতিল, শিক্ষার্থী নিজের প্যানেলে কারণ দেখে।
require_once __DIR__ . '/../config/portal_access.php';
erp_portal_controller_role(['admin']);
erp_portal_require_role($pdo, 'admin', 'scholarship_applications');
[$sap_scope, $sap_params] = erp_portal_student_scope($pdo, 'admin', 'student_id');
require_once __DIR__ . '/../config/scholarship_application_helpers.php';
sa_ensure_tables($pdo);

$sap_msg = $_SESSION['sap_msg'] ?? '';
$sap_err = $_SESSION['sap_err'] ?? '';
unset($_SESSION['sap_msg'], $_SESSION['sap_err']);
$sap_admin = (string) ($_SESSION['admin_user'] ?? 'Admin');
$sap_base  = 'index.php?page=scholarship_applications';

// -----------------------------------------------------------------
// POST
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['app_id'] ?? 0);
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sap_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: ' . $sap_base);
        exit();
    }

    if (isset($_POST['approve_app'])) {
        $type_id = (int) ($_POST['scholarship_type_id'] ?? 0);
        $amount  = round((float) ($_POST['approved_amount'] ?? 0), 2);
        $mode    = ($_POST['settle_mode'] ?? '') === 'Cash' ? 'Cash' : 'Fee';
        $remarks = mb_substr(trim($_POST['admin_remarks'] ?? ''), 0, 1000);

        $app_stmt = $pdo->prepare("SELECT * FROM scholarship_applications WHERE id = ? AND status = 'Pending' AND $sap_scope LIMIT 1");
        $app_stmt->execute(array_merge([$id], $sap_params));
        $app = $app_stmt->fetch();
        $type_ok = $pdo->prepare("SELECT 1 FROM scholarship_types WHERE id = ? AND status = 'Active'");
        $type_ok->execute([$type_id]);

        if (!$app) {
            $_SESSION['sap_err'] = 'আবেদনটি পাওয়া যায়নি বা আগেই সিদ্ধান্ত নেওয়া হয়েছে।';
            header('Location: ' . $sap_base);
            exit();
        }
        if (!$type_ok->fetchColumn() || $amount <= 0 || $amount > 10000000) {
            $_SESSION['sap_err'] = 'সঠিক Scholarship Type ও পরিমাণ (০-র বেশি) দিন।';
            header('Location: ' . $sap_base . '&view=detail&id=' . $id);
            exit();
        }
        $session_id = (int) ($app['session_id'] ?: 0);
        if (!$session_id) {
            $session_id = (int) $pdo->query("SELECT id FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetchColumn();
        }
        $class_id = (int) $app['class_id'];
        if (!$session_id || !$class_id) {
            $_SESSION['sap_err'] = 'শিক্ষার্থীর Session বা Class সেট করা নেই — আগে স্টুডেন্ট প্রোফাইল ঠিক করুন।';
            header('Location: ' . $sap_base . '&view=detail&id=' . $id);
            exit();
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO scholarship_awards (student_id, scholarship_type_id, session_id, class_id, amount, status, remarks, created_by)
                 VALUES (?, ?, ?, ?, ?, 'Pending', ?, ?)"
            )->execute([(int) $app['student_id'], $type_id, $session_id, $class_id, $amount, 'আবেদন ' . sa_app_no($app), $sap_admin]);
            $award_id = (int) $pdo->lastInsertId();

            $upd = $pdo->prepare(
                "UPDATE scholarship_applications SET status='Approved', scholarship_type_id=?, approved_amount=?, settle_mode=?,
                        admin_remarks=?, award_id=?, reviewed_by=?, reviewed_at=NOW()
                 WHERE id=? AND status='Pending' AND $sap_scope"
            );
            $upd->execute(array_merge([$type_id, $amount, $mode, $remarks ?: null, $award_id, $sap_admin, $id], $sap_params));
            if (!$upd->rowCount()) { throw new RuntimeException('application already decided'); }
            $pdo->commit();

            sec_log_activity($pdo, 'Scholarship', 'Approve', 'শিক্ষাবৃত্তির আবেদন অনুমোদিত: ' . sa_app_no($app) . ' - ৳' . number_format($amount, 2) . ' - ' . sa_mode_label($mode));
            $_SESSION['sd_msg'] = 'আবেদন অনুমোদিত হয়েছে। এখন নিচে বিতরণ নিশ্চিত করুন (' . sa_mode_label($mode) . ')।';
            header('Location: index.php?page=scholarship_disbursement&award_id=' . $award_id . '&mode=' . strtolower($mode));
            exit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Scholarship application approve failed: ' . $e->getMessage());
            $_SESSION['sap_err'] = 'অনুমোদন করতে সমস্যা হয়েছে, আবার চেষ্টা করুন।';
            header('Location: ' . $sap_base . '&view=detail&id=' . $id);
            exit();
        }
    }

    if (isset($_POST['reject_app'])) {
        $remarks = mb_substr(trim($_POST['admin_remarks'] ?? ''), 0, 1000);
        if ($remarks === '') {
            $_SESSION['sap_err'] = 'বাতিল করার কারণ লিখুন — শিক্ষার্থী এটা দেখতে পাবে।';
            header('Location: ' . $sap_base . '&view=detail&id=' . $id);
            exit();
        }
        $upd = $pdo->prepare("UPDATE scholarship_applications SET status='Rejected', admin_remarks=?, reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status='Pending' AND $sap_scope");
        $upd->execute(array_merge([$remarks, $sap_admin, $id], $sap_params));
        if ($upd->rowCount()) {
            $_SESSION['sap_msg'] = 'আবেদন বাতিল করা হয়েছে।';
            sec_log_activity($pdo, 'Scholarship', 'Reject', "শিক্ষাবৃত্তির আবেদন বাতিল: #{$id}");
        } else {
            $_SESSION['sap_err'] = 'আবেদনটি আগেই সিদ্ধান্ত নেওয়া হয়েছে বা পাওয়া যায়নি।';
        }
        header('Location: ' . $sap_base);
        exit();
    }
}

$sap_view = $_GET['view'] ?? 'list';

// =================================================================
// বিস্তারিত + সিদ্ধান্ত
// =================================================================
if ($sap_view === 'detail') {
    $id = (int) ($_GET['id'] ?? 0);
    [$detail_scope, $detail_params] = erp_portal_student_scope($pdo, 'admin', 'a.student_id');
    $stmt = $pdo->prepare(sa_select_sql() . " WHERE a.id = ? AND $detail_scope LIMIT 1");
    $stmt->execute(array_merge([$id], $detail_params));
    $a = $stmt->fetch();
    if (!$a) { echo '<div class="alert alert-danger">আবেদন পাওয়া যায়নি।</div>'; return; }
    $m = sa_status_meta($a);
    $children = sa_children($a['children_json']);
    $types = $pdo->query("SELECT id, name, category FROM scholarship_types WHERE status='Active' ORDER BY name")->fetchAll();
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="fw-bold mb-0"><?php echo sa_e(sa_app_no($a)); ?> <span class="badge <?php echo $m['badge']; ?> ms-2"><?php echo sa_e($m['label']); ?></span></h5>
        <div class="d-flex gap-2">
            <a href="../scholarship_application_print.php?portal=admin&amp;id=<?php echo (int) $a['id']; ?>" target="_blank" class="btn btn-sm btn-dark"><i class="fa-solid fa-print me-1"></i> Print</a>
            <a href="<?php echo $sap_base; ?>" class="btn btn-sm btn-secondary"><i class="fa-solid fa-arrow-left me-1"></i> ফিরে যান</a>
        </div>
    </div>
    <?php if ($sap_err): ?><div class="alert alert-danger py-2"><?php echo sa_e($sap_err); ?></div><?php endif; ?>

    <div class="card-custom mb-3">
        <div class="row g-2">
            <div class="col-md-4"><b>শিক্ষার্থী:</b> <?php echo sa_e($a['name_en']); ?> (<?php echo sa_e($a['student_code']); ?>)</div>
            <div class="col-md-2"><b>রোল:</b> <?php echo sa_e($a['roll_no']); ?></div>
            <div class="col-md-3"><b>শ্রেণি:</b> <?php echo sa_e($a['class_name'] ?? '—'); ?><?php echo $a['shift_name'] ? ' (' . sa_e($a['shift_name']) . ')' : ''; ?></div>
            <div class="col-md-3"><b>শিক্ষাবর্ষ:</b> <?php echo sa_e($a['session_name'] ?? '—'); ?></div>
            <div class="col-md-4"><b>আবেদনকারী:</b> <?php echo sa_e($a['applicant_name']); ?> (<?php echo sa_e(sa_relation_label($a['applicant_relation'])); ?>)</div>
            <div class="col-md-2"><b>সন্তান:</b> <?php echo (int) $a['child_order'] >= 4 ? '৪র্থ+' : ['', '১ম', '২য়', '৩য়'][(int) $a['child_order']]; ?></div>
            <div class="col-md-3"><b>পেশা:</b> <?php echo sa_e($a['occupation'] ?: '—'); ?></div>
            <div class="col-md-3"><b>মোবাইল:</b> <?php echo sa_e($a['mobile']); ?></div>
            <div class="col-md-4"><b>মাসিক আয়:</b> ৳<?php echo number_format((float) $a['monthly_income'], 2); ?></div>
            <div class="col-md-4"><b>মোট সদস্য:</b> <?php echo (int) $a['family_members']; ?> জন · <b>উপার্জনক্ষম:</b> <?php echo (int) $a['earning_members']; ?> জন</div>
            <div class="col-md-4"><b>হোয়াটসঅ্যাপ / ইমেইল:</b> <?php echo sa_e(trim(($a['whatsapp'] ?: '—') . ' / ' . ($a['email'] ?: '—'))); ?></div>
            <div class="col-md-6"><b>বর্তমান ঠিকানা:</b> <?php echo sa_e($a['present_address']); ?></div>
            <div class="col-md-6"><b>স্থায়ী ঠিকানা:</b> <?php echo sa_e($a['permanent_address']); ?></div>
            <?php if (trim((string) $a['reason']) !== ''): ?>
                <div class="col-12"><b>অতিরিক্ত বক্তব্য:</b> <?php echo nl2br(sa_e($a['reason'])); ?></div>
            <?php endif; ?>
        </div>
        <?php if ($children): ?>
        <div class="table-responsive mt-3">
            <table class="table table-bordered table-sm mb-0">
                <thead class="table-light"><tr><th>#</th><th>লিঙ্গ</th><th>বয়স</th><th>অধ্যয়নরত প্রতিষ্ঠান</th><th>শ্রেণি</th></tr></thead>
                <tbody>
                <?php foreach ($children as $i => $c): ?>
                    <tr><td><?php echo $i + 1; ?></td><td><?php echo sa_e($c['gender'] === 'Male' ? 'ছেলে' : ($c['gender'] === 'Female' ? 'মেয়ে' : $c['gender'])); ?></td><td><?php echo sa_e($c['age']); ?></td><td><?php echo sa_e($c['inst']); ?></td><td><?php echo sa_e($c['class']); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($a['status'] === 'Pending'): ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <form method="POST" class="card-custom">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="app_id" value="<?php echo (int) $a['id']; ?>">
                <h6 class="fw-bold text-success"><i class="fa-solid fa-check me-1"></i>অনুমোদন</h6>
                <?php if (!$types): ?>
                    <div class="alert alert-warning py-2 small">কোনো Active স্কলারশিপ টাইপ নেই। আগে <a href="index.php?page=scholarship_types&view=add">Scholarship Types</a> থেকে একটা তৈরি করুন (যেমন "স্কুল শিক্ষাবৃত্তি")।</div>
                <?php endif; ?>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Scholarship Type</label>
                        <select name="scholarship_type_id" class="form-select form-select-sm" required>
                            <option value="">-- নির্বাচন করুন --</option>
                            <?php foreach ($types as $t): ?>
                                <option value="<?php echo (int) $t['id']; ?>"><?php echo sa_e($t['name'] . ' (' . sch_category_label($t['category']) . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">অনুমোদিত পরিমাণ (৳)</label>
                        <input type="number" name="approved_amount" min="1" step="0.01" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">বৃত্তির টাকা কীভাবে যাবে?</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="settle_mode" id="smFee" value="Fee" checked>
                            <label class="form-check-label" for="smFee"><b>ফি-র বকেয়ার সাথে সমন্বয়</b> — আগে Admission, তারপর মাস অনুক্রমে বকেয়া কাটবে, বাড়তি থাকলে নগদ</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="settle_mode" id="smCash" value="Cash">
                            <label class="form-check-label" for="smCash"><b>হাতে নগদ</b> — পুরো টাকা অভিভাবককে (Cash/bKash/Nagad/Bank) দেওয়া হবে</label>
                        </div>
                        <div class="small text-muted mt-1">অনুমোদনের পর "Process Disbursement" পেজ খুলবে, সেখানে চূড়ান্ত পরিমাণ ঠিক করে নিশ্চিত করলে হিসাব ও রিসিপ্ট তৈরি হবে।</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">মন্তব্য (ঐচ্ছিক — শিক্ষার্থী দেখবে)</label>
                        <input type="text" name="admin_remarks" maxlength="1000" class="form-control form-control-sm">
                    </div>
                </div>
                <button type="submit" name="approve_app" class="btn btn-success btn-sm mt-3" onclick="return confirm('আবেদনটি অনুমোদন করতে চান?');">Approve ও পরের ধাপে যান</button>
            </form>
        </div>
        <div class="col-lg-5">
            <form method="POST" class="card-custom">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="app_id" value="<?php echo (int) $a['id']; ?>">
                <h6 class="fw-bold text-danger"><i class="fa-solid fa-xmark me-1"></i>বাতিল</h6>
                <textarea name="admin_remarks" rows="3" maxlength="1000" class="form-control form-control-sm" placeholder="বাতিলের কারণ (বাধ্যতামূলক — শিক্ষার্থী দেখবে)" required></textarea>
                <button type="submit" name="reject_app" class="btn btn-danger btn-sm mt-3" onclick="return confirm('আবেদনটি বাতিল করতে চান?');">Reject</button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <div class="card-custom">
        <b>সিদ্ধান্ত:</b> <?php echo sa_e($m['label']); ?> · <?php echo sa_e($a['reviewed_by'] ?? ''); ?> · <?php echo $a['reviewed_at'] ? sa_e(date('d M Y, h:i A', strtotime($a['reviewed_at']))) : ''; ?>
        <?php if ($a['status'] === 'Approved'): ?>
            <br><b><?php echo sa_e($a['type_name'] ?? ''); ?></b> — ৳<?php echo number_format((float) $a['approved_amount'], 2); ?> — <?php echo sa_e(sa_mode_label($a['settle_mode'])); ?>
            <?php if (($a['award_status'] ?? '') === 'Disbursed'): ?>
                <br>সমন্বয় ৳<?php echo number_format((float) $a['adjusted_amount'], 2); ?> · নগদ ৳<?php echo number_format((float) $a['cash_amount'], 2); ?> · রিসিপ্ট <?php echo sa_e($a['disb_receipt']); ?>
                <a href="../scholarship_receipt_print.php?portal=admin&amp;app=<?php echo (int) $a['id']; ?>" target="_blank" class="btn btn-sm btn-dark ms-1 py-0"><i class="fa-solid fa-print me-1"></i> Money Receipt</a>
            <?php elseif (($a['award_status'] ?? '') === 'Pending'): ?>
                <br><a href="index.php?page=scholarship_disbursement&award_id=<?php echo (int) $a['award_id']; ?>&mode=<?php echo strtolower((string) $a['settle_mode']); ?>" class="btn btn-sm btn-success mt-2">বিতরণ নিশ্চিত করুন (Process Disbursement)</a>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (trim((string) $a['admin_remarks']) !== ''): ?><br><b>মন্তব্য:</b> <?php echo nl2br(sa_e($a['admin_remarks'])); ?><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php
    return;
}

// =================================================================
// তালিকা
// =================================================================
$tab = $_GET['status'] ?? 'Pending';
if (!in_array($tab, ['Pending', 'Approved', 'Rejected', 'all'], true)) { $tab = 'Pending'; }

[$list_scope, $params] = erp_portal_student_scope($pdo, 'admin', 'a.student_id');
$where = [$list_scope];
$where_sql = implode(' AND ', $where);

$cnt = $pdo->prepare("SELECT a.status, COUNT(*) n FROM scholarship_applications a LEFT JOIN students st ON st.id = a.student_id WHERE $where_sql GROUP BY a.status");
$cnt->execute($params);
$counts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
foreach ($cnt->fetchAll() as $r) { $counts[$r['status']] = (int) $r['n']; }
$counts['all'] = array_sum($counts);

$list_where = $where_sql; $list_params = $params;
if ($tab !== 'all') { $list_where .= ' AND a.status = ?'; $list_params[] = $tab; }
$stmt = $pdo->prepare(sa_select_sql() . " WHERE $list_where ORDER BY a.id DESC LIMIT 300");
$stmt->execute($list_params);
$list = $stmt->fetchAll();
$tab_labels = ['Pending' => 'Pending', 'Approved' => 'Approved', 'Rejected' => 'Rejected', 'all' => 'সব'];
?>
<?php if ($sap_msg): ?><div class="alert alert-success py-2"><?php echo sa_e($sap_msg); ?></div><?php endif; ?>
<?php if ($sap_err): ?><div class="alert alert-danger py-2"><?php echo sa_e($sap_err); ?></div><?php endif; ?>

<h5 class="fw-bold mb-3"><i class="fa-solid fa-hand-holding-dollar me-2"></i>Scholarship Applications</h5>
<ul class="nav nav-pills mb-3 gap-1">
    <?php foreach ($tab_labels as $k => $l): ?>
        <li class="nav-item"><a class="nav-link py-1 px-3 <?php echo $tab === $k ? 'active' : 'bg-white border'; ?>" href="<?php echo $sap_base; ?>&status=<?php echo urlencode($k); ?>"><?php echo sa_e($l); ?> <span class="badge bg-secondary ms-1"><?php echo (int) $counts[$k]; ?></span></a></li>
    <?php endforeach; ?>
</ul>
<div class="card-custom">
    <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle">
            <thead class="table-light"><tr><th>আবেদন নং</th><th>শিক্ষার্থী</th><th>শ্রেণি / সেশন</th><th>আবেদনকারী</th><th class="text-end">মাসিক আয়</th><th>সদস্য</th><th>তারিখ</th><th>অবস্থা</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($list as $a): $m = sa_status_meta($a); ?>
                <tr>
                    <td><?php echo sa_e(sa_app_no($a)); ?></td>
                    <td><?php echo sa_e($a['student_code'] . ' - ' . $a['name_en'] . ' (Roll ' . $a['roll_no'] . ')'); ?></td>
                    <td><?php echo sa_e(($a['class_name'] ?? '—') . ' / ' . ($a['session_name'] ?? '—')); ?></td>
                    <td><?php echo sa_e($a['applicant_name']); ?></td>
                    <td class="text-end"><?php echo number_format((float) $a['monthly_income'], 0); ?></td>
                    <td><?php echo (int) $a['family_members']; ?>/<?php echo (int) $a['earning_members']; ?></td>
                    <td class="text-nowrap"><?php echo sa_e(date('d M Y', strtotime($a['applied_at']))); ?></td>
                    <td><span class="badge <?php echo $m['badge']; ?>"><?php echo sa_e($m['label']); ?></span></td>
                    <td><a href="<?php echo $sap_base; ?>&view=detail&id=<?php echo (int) $a['id']; ?>" class="btn btn-sm btn-outline-primary"><?php echo $a['status'] === 'Pending' ? 'রিভিউ' : 'দেখুন'; ?></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$list): ?><tr><td colspan="9" class="text-center text-muted py-3">কোনো আবেদন নেই।</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
