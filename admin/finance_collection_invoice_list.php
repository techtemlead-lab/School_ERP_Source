<?php
// admin/finance_collection_invoice_list.php - Finance Module > Students Accounts > Money Receipt List
// (আগের নাম ছিল "Manage Fees Collections / Print Invoice")
// প্রতিটা Folio (একটা কালেকশন ট্রানজেকশনের সবগুলো Fee Head একসাথে) একটা রো হিসেবে লিস্টে
// দেখায়, ফিল্টার (Folio/Class/Roll/Date) করা যায়, প্রিন্ট-করা-যায়-এমন Invoice দেখা যায়,
// আর দরকার হলে পুরো Folio Void (Delete) করা যায়।
require_once __DIR__ . '/../config/table_print.php';

function civ($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function ci_money($n): string { return number_format((float) $n, 2); }
// একটা মান JS-এ বসানোর জন্য নিরাপদভাবে এনকোড করে — json_encode() দিয়ে JS-এর জন্য কোট/ব্যাকস্ল্যাশ
// এস্কেপ হয়, কিন্তু সেই JSON স্ট্রিং যদি এখানকার মতো ডাবল-কোটেড HTML attribute (onclick="...")
// এর ভেতরে বসে, তাহলে JSON-এর নিজের ডাবল-কোটগুলো attribute-টাকে সময়ের আগেই বন্ধ করে দেবে —
// তাই htmlspecialchars() দিয়ে সেগুলো আবার &quot;/&#039; করে দেওয়া হচ্ছে, ব্রাউজার attribute
// পার্স করার সময় যেটা আবার আসল " এ ফিরিয়ে JS-কে দেবে
function civ_js($val): string { return htmlspecialchars(json_encode($val), ENT_QUOTES, 'UTF-8'); }

$ci_msg = $_SESSION['ci_msg'] ?? '';
$ci_err = $_SESSION['ci_err'] ?? '';
unset($_SESSION['ci_msg'], $_SESSION['ci_err']);

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি (বাকি Finance/Collection পেজগুলোর সাথে শেয়ার্ড)
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_fee_collections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        session_id INT NOT NULL,
        period_type ENUM('Admission','Monthly') NOT NULL,
        month TINYINT NOT NULL DEFAULT 0,
        ac_head_id INT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash',
        trx_id VARCHAR(100) NULL,
        folio_no VARCHAR(50) NULL,
        receipt_no VARCHAR(50) NULL,
        collected_by VARCHAR(100) NULL,
        collected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'Paid',
        KEY idx_lookup (student_id, session_id, period_type, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$ci_cols = $pdo->query("SHOW COLUMNS FROM finance_fee_collections")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('ac_head_id', $ci_cols, true)) { $pdo->exec("ALTER TABLE finance_fee_collections ADD COLUMN ac_head_id INT NULL AFTER month"); }
if (!in_array('folio_no', $ci_cols, true)) { $pdo->exec("ALTER TABLE finance_fee_collections ADD COLUMN folio_no VARCHAR(50) NULL AFTER trx_id"); }

$MONTHS = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];
$PERIODS = [0 => 'Admission'] + $MONTHS;

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_name = $current_session['session_name'] ?? '—';

// -----------------------------------------------------------
// POST Handler - পুরো Folio Void (সবগুলো লাইন-আইটেম মুছে ফেলা)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_folio'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ci_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=finance_collection_invoice_list');
        exit();
    }
    $folio_no = trim($_POST['folio_no'] ?? '');
    if ($folio_no !== '') {
        $void_sum_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_fee_collections WHERE folio_no = ?");
        $void_sum_stmt->execute([$folio_no]);
        $void_amount = (float) $void_sum_stmt->fetchColumn();
        $pdo->prepare("DELETE FROM finance_fee_collections WHERE folio_no = ?")->execute([$folio_no]);
        sec_log_activity($pdo, 'Finance', 'Delete', "Fee Collection Folio Void করা হয়েছে: Folio {$folio_no}, পরিমাণ ৳" . number_format($void_amount, 2));
        $_SESSION['ci_msg'] = 'Folio ' . $folio_no . ' এর সবগুলো এন্ট্রি Void (মুছে ফেলা) করা হয়েছে।';
    }
    header('Location: index.php?page=finance_collection_invoice_list' . (isset($_POST['back_qs']) ? '&' . $_POST['back_qs'] : ''));
    exit();
}

// পুরনো "Print Invoice" ভিউটা সরিয়ে ফেলা হয়েছে — এখন এই পেজ থেকে শুধু Money
// Receipt-ই প্রিন্ট হয় (print_money_receipt.php), তাই আর দুইরকম প্রিন্ট নেই।

// -----------------------------------------------------------
// List + Filters (GET)
// -----------------------------------------------------------
$classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();

$f_folio = trim($_GET['f_folio'] ?? '');
$f_class = (int) ($_GET['f_class'] ?? 0);
$f_roll  = trim($_GET['f_roll'] ?? '');
$f_student = trim($_GET['f_student'] ?? '');
$f_date  = trim($_GET['f_date'] ?? '');
$is_print_all = tprint_is_print_all();
$page_no = max(1, (int) ($_GET['pg'] ?? 1));
$per_page = 50;

$where = ["fc.folio_no IS NOT NULL", "fc.folio_no <> ''"];
$params = [];
if ($f_folio !== '') { $where[] = 'fc.folio_no LIKE ?'; $params[] = '%' . $f_folio . '%'; }
if ($f_class > 0) { $where[] = 's.class_id = ?'; $params[] = $f_class; }
if ($f_roll !== '') { $where[] = 's.roll_no LIKE ?'; $params[] = '%' . $f_roll . '%'; }
// Student সার্চ: Student ID, নাম অথবা Roll — তিনটার যেকোনো একটা মিললেই হবে
if ($f_student !== '') { $where[] = '(s.student_id LIKE ? OR s.name_en LIKE ? OR s.roll_no LIKE ?)'; $like_st = '%' . $f_student . '%'; array_push($params, $like_st, $like_st, $like_st); }
if ($f_date !== '') { $where[] = 'DATE(fc.collected_at) = ?'; $params[] = $f_date; }
branch_apply_filter($where, $params, 's.branch_id');
$where_sql = implode(' AND ', $where);

$count_sql = "SELECT COUNT(*) FROM (
                SELECT fc.folio_no
                FROM finance_fee_collections fc
                LEFT JOIN students s ON s.id = fc.student_id
                WHERE $where_sql
                GROUP BY fc.folio_no
              ) t";
$cstmt = $pdo->prepare($count_sql);
$cstmt->execute($params);
$total_items = (int) $cstmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_items / $per_page));
$page_no = min($page_no, $total_pages);
$offset = $is_print_all ? 0 : (($page_no - 1) * $per_page);
$query_limit = $is_print_all ? 5000 : $per_page;

$list_sql = "SELECT fc.folio_no, MIN(fc.student_id) AS student_id, MIN(fc.collected_at) AS collection_date, SUM(fc.amount) AS total_amount
             FROM finance_fee_collections fc
             LEFT JOIN students s ON s.id = fc.student_id
             WHERE $where_sql
             GROUP BY fc.folio_no
             ORDER BY MAX(fc.collected_at) DESC, MAX(fc.id) DESC
             LIMIT $query_limit OFFSET $offset";
$lstmt = $pdo->prepare($list_sql);
$lstmt->execute($params);
$folios = $lstmt->fetchAll();

// প্রতিটা ফলিওর Student/Class তথ্য এক কোয়েরিতে লোড
$student_ids = array_unique(array_filter(array_column($folios, 'student_id')));
$student_map = [];
if ($student_ids) {
    $in = implode(',', array_map('intval', $student_ids));
    $sr = $pdo->query("SELECT s.id, s.student_id AS student_code, s.name_en, s.roll_no, s.photo_path, c.class_name
                        FROM students s LEFT JOIN academic_classes c ON c.id = s.class_id
                        WHERE s.id IN ($in)");
    foreach ($sr->fetchAll() as $r) { $student_map[(int) $r['id']] = $r; }
}

$showing_from = $total_items ? $offset + 1 : 0;
$showing_to = $is_print_all ? count($folios) : min($offset + $per_page, $total_items);

$ci_parts = ['Session: ' . $current_session_name];
if ($f_folio !== '') { $ci_parts[] = 'Folio: ' . $f_folio; }
if ($f_class > 0) {
    foreach ($classes as $cl) { if ((int) $cl['id'] === $f_class) { $ci_parts[] = 'Class: ' . $cl['class_name']; break; } }
}
if ($f_student !== '') { $ci_parts[] = 'Student: ' . $f_student; }
if ($f_roll !== '') { $ci_parts[] = 'Roll: ' . $f_roll; }
if ($f_date !== '') { $ci_parts[] = 'Date: ' . $f_date; }
$ci_parts[] = 'Total Receipts: ' . $total_items;
$ci_sub = implode(' | ', $ci_parts);
?>
<?php echo tprint_page_css('landscape', 10); ?>

<div class="card-custom mb-3">
    <?php echo tprint_header_html($pdo, 'Money Receipt List', $ci_sub); ?>
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2 no-print">
        <a href="index.php?page=finance_fees_collection" class="btn btn-sm btn-success"><i class="fa-solid fa-plus"></i></a>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="text-info fw-bold me-2">Current Session : <?php echo civ($current_session_name); ?></span>
            <?php echo tprint_controls([
                'orient'        => 'landscape',
                'wrap'          => false,
                'print_all_url' => tprint_all_url(),
                'total_count'   => $total_items,
                'shown_count'   => count($folios),
                'is_print_all'  => $is_print_all,
            ]); ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#ciFilterBody"><i class="fa-solid fa-chevron-up"></i></button>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="window.location='index.php?page=finance_collection_invoice_list'"><i class="fa-solid fa-xmark"></i></button>
        </div>
    </div>

    <?php if ($ci_msg): ?><div class="alert alert-success py-2"><?php echo civ($ci_msg); ?></div><?php endif; ?>
    <?php if ($ci_err): ?><div class="alert alert-danger py-2"><?php echo civ($ci_err); ?></div><?php endif; ?>

    <div id="ciFilterBody" class="collapse show">
        <div class="text-end text-muted small mb-2 no-print">Showing <?php echo $showing_from; ?>-<?php echo $showing_to; ?> of <?php echo $total_items; ?> items.</div>

        <!-- Void বাটন আগে প্রতিটা রো-তে নিজের &lt;form&gt;-এ মোড়ানো ছিল, আর সেটা এই GET ফিল্টার
             ফর্মের ভেতরেই বসানো ছিল — ফর্মের ভেতরে ফর্ম, যেটা HTML-এ বৈধ না। ব্রাউজার ভেতরের
             ফর্মটা বাতিল করে তার ইনপুট/বাটন বাইরের GET ফর্মেই বসিয়ে দিত, ফলে Void ক্লিক করলে
             আসল POST রিকোয়েস্টের বদলে ফিল্টার ফর্মটাই (GET) সাবমিট হয়ে যেত — কিছুই ডিলিট হতো
             না। সমাধানঃ একটামাত্র শেয়ার্ড হিডেন ফর্ম, এই GET ফর্মের বাইরে — Void বাটন এখন
             আলাদা ফর্ম না বানিয়ে শুধু এটাকেই JS দিয়ে সাবমিট করে। -->
        <form method="POST" id="ciRowActionForm" style="display:none">
            <input type="hidden" name="csrf_token" id="ciRowActionCsrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        </form>
        <script>
        function ciRowAction(url, fields, confirmMsg) {
            if (confirmMsg && !window.confirm(confirmMsg)) return false;
            var f = document.getElementById('ciRowActionForm');
            f.setAttribute('action', url);
            var old = f.querySelectorAll('input[data-dyn-field]');
            old.forEach(function (el) { el.parentNode.removeChild(el); });
            Object.keys(fields).forEach(function (k) {
                var el = document.createElement('input');
                el.type = 'hidden'; el.name = k; el.value = fields[k]; el.setAttribute('data-dyn-field', '1');
                f.appendChild(el);
            });
            f.submit();
            return false;
        }
        </script>
        <form method="GET" action="index.php" id="ciFilterForm">
            <input type="hidden" name="page" value="finance_collection_invoice_list">
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:50px;">Sl.</th>
                            <th>Folio Number</th>
                            <th>Class</th>
                            <th>Student</th>
                            <th style="width:90px;">Roll No.</th>
                            <th style="width:190px;">Collection Date &amp; Time</th>
                            <th class="no-print" style="width:100px;">Actions</th>
                        </tr>
                        <tr class="no-print">
                            <th></th>
                            <th><input type="text" name="f_folio" class="form-control form-control-sm" value="<?php echo civ($f_folio); ?>" onchange="document.getElementById('ciFilterForm').submit()"></th>
                            <th>
                                <select name="f_class" class="form-select form-select-sm" onchange="document.getElementById('ciFilterForm').submit()">
                                    <option value="0">All</option>
                                    <?php foreach ($classes as $cl): ?>
                                        <option value="<?php echo (int) $cl['id']; ?>" <?php echo $f_class === (int) $cl['id'] ? 'selected' : ''; ?>><?php echo civ($cl['class_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th><input type="text" name="f_student" class="form-control form-control-sm" placeholder="ID / Name / Roll" value="<?php echo civ($f_student); ?>" onchange="document.getElementById('ciFilterForm').submit()"></th>
                            <th><input type="text" name="f_roll" class="form-control form-control-sm" value="<?php echo civ($f_roll); ?>" onchange="document.getElementById('ciFilterForm').submit()"></th>
                            <th><input type="date" name="f_date" class="form-control form-control-sm" value="<?php echo civ($f_date); ?>" onchange="document.getElementById('ciFilterForm').submit()"></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($folios): $srl = $offset + 1; foreach ($folios as $f): $sid = (int) $f['student_id']; $sinfo = $student_map[$sid] ?? null; $qs = 'f_folio=' . urlencode($f_folio) . '&f_class=' . $f_class . '&f_roll=' . urlencode($f_roll) . '&f_student=' . urlencode($f_student) . '&f_date=' . urlencode($f_date) . '&pg=' . $page_no; $csrf = htmlspecialchars(csrf_token()); ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td class="fw-bold text-primary"><?php echo civ($f['folio_no']); ?></td>
                                <td><?php echo civ($sinfo['class_name'] ?? '—'); ?></td>
                                <td>
                                    <img src="<?php echo $sinfo && $sinfo['photo_path'] ? '../' . civ($sinfo['photo_path']) : 'data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2728%27 height=%2728%27%3E%3Crect width=%2728%27 height=%2728%27 fill=%27%23e2e8f0%27/%3E%3C/svg%3E'; ?>" style="width:24px;height:24px;border-radius:50%;object-fit:cover;margin-right:6px;">
                                    <?php echo civ($sinfo ? $sinfo['student_code'] . ' - ' . $sinfo['name_en'] . ' - ' . $sinfo['roll_no'] : '—'); ?>
                                </td>
                                <td><?php echo civ($sinfo['roll_no'] ?? '—'); ?></td>
                                <td><?php echo civ(date('d F Y, h:i A', strtotime($f['collection_date']))); ?></td>
                                <td class="text-nowrap no-print">
                                    <a href="print_money_receipt.php?folio_no=<?php echo urlencode($f['folio_no']); ?>&back=finance_collection_invoice_list" target="_blank" class="btn btn-sm btn-outline-primary" title="Print Money Receipt"><i class="fa-solid fa-print"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return ciRowAction('index.php?page=finance_collection_invoice_list', {folio_no: <?php echo civ_js($f['folio_no']); ?>, back_qs: <?php echo civ_js($qs); ?>, delete_folio: '1'}, <?php echo civ_js('এই পুরো Folio (' . $f['folio_no'] . ') Void করবেন? সব লাইন-আইটেম মুছে যাবে।'); ?>);"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">No data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <?php if (!$is_print_all && $total_pages > 1): ?>
            <nav class="mt-3 no-print">
                <ul class="pagination pagination-sm justify-content-end">
                    <?php for ($pgn = 1; $pgn <= $total_pages; $pgn++): ?>
                        <li class="page-item <?php echo $pgn === $page_no ? 'active' : ''; ?>">
                            <a class="page-link" href="index.php?page=finance_collection_invoice_list&f_folio=<?php echo urlencode($f_folio); ?>&f_class=<?php echo $f_class; ?>&f_roll=<?php echo urlencode($f_roll); ?>&f_student=<?php echo urlencode($f_student); ?>&f_date=<?php echo urlencode($f_date); ?>&pg=<?php echo $pgn; ?>"><?php echo $pgn; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>
