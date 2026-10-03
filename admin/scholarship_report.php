<?php
// admin/scholarship_report.php - Finance Module > Scholarship & Welfare > Scholarship Report
// সব Award/Disbursement-এর একটা ফিল্টারযোগ্য রিপোর্ট — ছেলে/মেয়ে ব্রেকডাউন সহ (সরকারি অডিট/
// DSHE-BANBEIS এ প্রায়ই মেয়েদের উপবৃত্তির আলাদা পরিসংখ্যান চাওয়া হয় বলে এটা এখানে আলাদা দেখানো)।
// CSV Export বাটন দিয়ে এক্সেলে খোলার মতো ফাইল ডাউনলোড করা যায়।
require_once __DIR__ . '/../config/scholarship_helpers.php';
sch_ensure_tables($pdo);

function srv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function sr_money($n): string { return number_format((float) $n, 2); }

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$scholarship_types = $pdo->query("SELECT id, name FROM scholarship_types ORDER BY name ASC")->fetchAll();

$f_session = (int) ($_GET['f_session'] ?? 0);
$f_type = (int) ($_GET['f_type'] ?? 0);
$f_status = trim($_GET['f_status'] ?? '');

$where = ['1=1'];
$params = [];
if ($f_session) { $where[] = 'a.session_id = ?'; $params[] = $f_session; }
if ($f_type) { $where[] = 'a.scholarship_type_id = ?'; $params[] = $f_type; }
if ($f_status !== '' && in_array($f_status, ['Pending', 'Disbursed', 'Cancelled'], true)) { $where[] = 'a.status = ?'; $params[] = $f_status; }

$sql = "SELECT a.*, t.name AS type_name, t.category, s.session_name, c.class_name,
               st.name_en, st.student_id AS student_code, st.roll_no, st.gender,
               d.adjusted_amount, d.cash_amount, d.receipt_no, d.disbursed_at
        FROM scholarship_awards a
        LEFT JOIN scholarship_types t ON t.id = a.scholarship_type_id
        LEFT JOIN academic_sessions s ON s.id = a.session_id
        LEFT JOIN academic_classes c ON c.id = a.class_id
        LEFT JOIN students st ON st.id = a.student_id
        LEFT JOIN scholarship_disbursements d ON d.award_id = a.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ---- CSV Export ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="scholarship_report_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM যাতে Excel-এ বাংলা ঠিকভাবে খোলে
    fputcsv($out, ['Student ID', 'Name', 'Roll', 'Gender', 'Class', 'Session', 'Scholarship', 'Category', 'Award Amount', 'Status', 'Adjusted', 'Cash Given', 'Receipt No', 'Disbursed At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['student_code'], $r['name_en'], $r['roll_no'], $r['gender'], $r['class_name'], $r['session_name'],
            $r['type_name'], sch_category_label($r['category']), $r['amount'], $r['status'],
            $r['adjusted_amount'] ?? '', $r['cash_amount'] ?? '', $r['receipt_no'] ?? '', $r['disbursed_at'] ?? '',
        ]);
    }
    fclose($out);
    exit();
}

// ---- Gender/Category ব্রেকডাউন (শুধু Disbursed) ----
$gender_breakdown = [];
$category_breakdown = [];
$total_awarded = 0.0;
$total_disbursed = 0.0;
foreach ($rows as $r) {
    $total_awarded += (float) $r['amount'];
    if ($r['status'] === 'Disbursed') {
        $g = $r['gender'] ?: 'অজানা';
        $gender_breakdown[$g] = ($gender_breakdown[$g] ?? ['count' => 0, 'amount' => 0.0]);
        $gender_breakdown[$g]['count']++;
        $gender_breakdown[$g]['amount'] += (float) $r['amount'];

        $cat = sch_category_label($r['category'] ?? '');
        $category_breakdown[$cat] = ($category_breakdown[$cat] ?? ['count' => 0, 'amount' => 0.0]);
        $category_breakdown[$cat]['count']++;
        $category_breakdown[$cat]['amount'] += (float) $r['amount'];

        $total_disbursed += (float) $r['amount'];
    }
}
?>
<div class="card-custom mb-3">
    <form method="GET" action="index.php" class="row g-3 align-items-end">
        <input type="hidden" name="page" value="scholarship_report">
        <div class="col-md-3">
            <label class="form-label fw-bold">Session</label>
            <select name="f_session" class="form-select">
                <option value="0">সব Session</option>
                <?php foreach ($sessions as $s): ?>
                    <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>><?php echo srv($s['session_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-bold">Scholarship Type</label>
            <select name="f_type" class="form-select">
                <option value="0">সব টাইপ</option>
                <?php foreach ($scholarship_types as $t): ?>
                    <option value="<?php echo (int) $t['id']; ?>" <?php echo $f_type === (int) $t['id'] ? 'selected' : ''; ?>><?php echo srv($t['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-bold">Status</label>
            <select name="f_status" class="form-select">
                <option value="">সব</option>
                <option value="Pending" <?php echo $f_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="Disbursed" <?php echo $f_status === 'Disbursed' ? 'selected' : ''; ?>>Disbursed</option>
                <option value="Cancelled" <?php echo $f_status === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary px-4">Filter</button>
            <a href="index.php?page=scholarship_report&f_session=<?php echo $f_session; ?>&f_type=<?php echo $f_type; ?>&f_status=<?php echo srv($f_status); ?>&export=csv" class="btn btn-outline-success"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
        </div>
    </form>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card-custom text-center">
            <div class="text-primary fw-bold">Total Awarded</div>
            <div class="fs-4 fw-bold">৳<?php echo sr_money($total_awarded); ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-custom text-center">
            <div class="text-success fw-bold">Total Disbursed</div>
            <div class="fs-4 fw-bold">৳<?php echo sr_money($total_disbursed); ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-custom text-center">
            <div class="text-warning fw-bold">Records</div>
            <div class="fs-4 fw-bold"><?php echo count($rows); ?></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card-custom">
            <h6 class="fw-bold mb-2">ছেলে/মেয়ে ব্রেকডাউন (শুধু Disbursed)</h6>
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light"><tr><th>Gender</th><th class="text-end">সংখ্যা</th><th class="text-end">মোট টাকা</th></tr></thead>
                <tbody>
                    <?php if ($gender_breakdown): foreach ($gender_breakdown as $g => $d): ?>
                        <tr><td><?php echo srv($g); ?></td><td class="text-end"><?php echo (int) $d['count']; ?></td><td class="text-end"><?php echo sr_money($d['amount']); ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="3" class="text-center text-muted">কোনো Disbursed রেকর্ড নেই</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-custom">
            <h6 class="fw-bold mb-2">ধরন অনুযায়ী ব্রেকডাউন (শুধু Disbursed)</h6>
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light"><tr><th>ধরন</th><th class="text-end">সংখ্যা</th><th class="text-end">মোট টাকা</th></tr></thead>
                <tbody>
                    <?php if ($category_breakdown): foreach ($category_breakdown as $cat => $d): ?>
                        <tr><td><?php echo srv($cat); ?></td><td class="text-end"><?php echo (int) $d['count']; ?></td><td class="text-end"><?php echo sr_money($d['amount']); ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="3" class="text-center text-muted">কোনো Disbursed রেকর্ড নেই</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card-custom">
    <h6 class="fw-bold mb-3">বিস্তারিত তালিকা</h6>
    <div class="table-responsive">
        <table class="table table-bordered align-middle" style="font-size:13px;">
            <thead class="table-light">
                <tr><th>Student</th><th>Gender</th><th>Class</th><th>Scholarship</th><th class="text-end">Award</th><th>Status</th><th class="text-end">Adjusted</th><th class="text-end">Cash</th><th>Receipt</th></tr>
            </thead>
            <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo srv(($r['student_code'] ?? '') . ' - ' . ($r['name_en'] ?? '—')); ?></td>
                        <td><?php echo srv($r['gender']); ?></td>
                        <td><?php echo srv($r['class_name']); ?></td>
                        <td><?php echo srv($r['type_name']); ?></td>
                        <td class="text-end fw-bold"><?php echo sr_money($r['amount']); ?></td>
                        <td>
                            <?php $badge = ['Pending' => 'warning', 'Disbursed' => 'success', 'Cancelled' => 'secondary'][$r['status']] ?? 'secondary'; ?>
                            <span class="badge bg-<?php echo $badge; ?>"><?php echo srv($r['status']); ?></span>
                        </td>
                        <td class="text-end"><?php echo $r['adjusted_amount'] !== null ? sr_money($r['adjusted_amount']) : '—'; ?></td>
                        <td class="text-end"><?php echo $r['cash_amount'] !== null ? sr_money($r['cash_amount']) : '—'; ?></td>
                        <td><?php echo srv($r['receipt_no']); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9" class="text-center text-muted py-3">কোনো রেকর্ড পাওয়া যায়নি</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
