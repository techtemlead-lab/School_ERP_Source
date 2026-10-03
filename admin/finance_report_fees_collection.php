<?php
// admin/finance_report_fees_collection.php - Finance Module > Finance Reports > Fees Collection
// একটা Session + ঐচ্ছিক Class + Date Range (Start-End) দিয়ে ফিল্টার করে, প্রতিটা Class-ভিত্তিক
// মোট ফি কালেকশন একটা প্রিন্টযোগ্য রিপোর্ট আকারে দেখায়।

function fcv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function fc_money($n): string { return number_format((float) $n, 2); }

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

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$current_session = $pdo->query("SELECT id FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();

// -----------------------------------------------------------
// ফিল্টার — Session (আবশ্যক) + Class (ঐচ্ছিক) + Start/End Date (আবশ্যক)
// -----------------------------------------------------------
$searched   = isset($_GET['search']);
$f_session  = (int) ($_GET['f_session'] ?? ($current_session['id'] ?? 0));
$f_class    = (int) ($_GET['f_class'] ?? 0);
$f_start    = trim($_GET['f_start'] ?? date('Y-m-d'));
$f_end      = trim($_GET['f_end'] ?? date('Y-m-d'));

// Start > End হলে অদল-বদল করে নেওয়া, যাতে উল্টো দিলেও রিপোর্ট না ভাঙে
if ($f_start !== '' && $f_end !== '' && strtotime($f_start) > strtotime($f_end)) {
    [$f_start, $f_end] = [$f_end, $f_start];
}

$rows = [];
$grand_total = 0.0;
$session_name = '';

if ($searched && $f_session > 0 && $f_start !== '' && $f_end !== '') {
    $where = ['fc.session_id = ?', 'DATE(fc.collected_at) BETWEEN ? AND ?'];
    $params = [$f_session, $f_start, $f_end];
    if ($f_class > 0) { $where[] = 's.class_id = ?'; $params[] = $f_class; }
    branch_apply_filter($where, $params, 's.branch_id');
    $where_sql = implode(' AND ', $where);

    $sql = "SELECT c.id AS class_id, c.class_name, SUM(fc.amount) AS total_amount
            FROM finance_fee_collections fc
            LEFT JOIN students s ON s.id = fc.student_id
            LEFT JOIN academic_classes c ON c.id = s.class_id
            WHERE $where_sql
            GROUP BY c.id, c.class_name
            ORDER BY c.rank ASC, c.class_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as $r) { $grand_total += (float) $r['total_amount']; }

    foreach ($sessions as $s) { if ((int) $s['id'] === $f_session) { $session_name = $s['session_name']; break; } }
}

$inst = $pdo->query("SELECT institute_name, phone_number, logo_path FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) { $inst = ['institute_name' => 'School Name', 'phone_number' => '', 'logo_path' => null]; }
$fr_bid2 = branch_current_id();
if ($fr_bid2 !== null) {
    $branch_stmt2 = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $branch_stmt2->execute([$fr_bid2]);
    $branch = $branch_stmt2->fetch();
} else {
    $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
}
?>
<style>
    @media print {
        @page { size: A4 portrait; margin: 10mm; }
        html, body { background: #fff !important; }
        .no-print, .sidebar, .sticky-top-area, .top-navbar, .breadcrumb-bar,
        .fc-filter-card, footer { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; }
        .content-body { padding: 0 !important; margin: 0 !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
        .fc-report-wrap { max-width: 100% !important; box-shadow: none !important; border: none !important; }
        .fc-report-wrap .table-responsive { overflow: visible !important; }
        .fc-report-wrap table { width: 100% !important; font-size: 12px; }
        .fc-report-wrap th, .fc-report-wrap td { padding: 4px 6px !important; }
        .fc-report-wrap a { color: #000 !important; text-decoration: none !important; }
        .fc-report-wrap thead { display: table-header-group; }
        .fc-report-wrap tr { page-break-inside: avoid; }
    }
</style>

<div class="card-custom mb-3 fc-filter-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0 text-secondary">Student's Fee Collection Report</h6>
        <div>
            <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-bs-toggle="collapse" data-bs-target="#fcFilterBody"><i class="fa-solid fa-chevron-up"></i></button>
            <a href="index.php?page=finance_report_fees_collection" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i></a>
        </div>
    </div>

    <div id="fcFilterBody" class="collapse show">
        <form method="GET" action="index.php" class="row g-3">
            <input type="hidden" name="page" value="finance_report_fees_collection">
            <input type="hidden" name="search" value="1">
            <div class="col-sm-4">
                <label class="form-label fw-bold">Session <span class="text-danger">*</span></label>
                <select name="f_session" class="form-select" required>
                    <option value="">Select Session</option>
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>><?php echo fcv($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-4">
                <label class="form-label fw-bold">Class</label>
                <select name="f_class" class="form-select">
                    <option value="0">Select Class</option>
                    <?php foreach ($classes as $cl): ?>
                        <option value="<?php echo (int) $cl['id']; ?>" <?php echo $f_class === (int) $cl['id'] ? 'selected' : ''; ?>><?php echo fcv($cl['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-bold">Start Date <span class="text-danger">*</span></label>
                <input type="date" name="f_start" class="form-control" required value="<?php echo fcv($f_start); ?>">
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-bold">End Date <span class="text-danger">*</span></label>
                <input type="date" name="f_end" class="form-control" required value="<?php echo fcv($f_end); ?>">
            </div>
            <div class="col-12 text-end">
                <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            </div>
        </form>
    </div>
</div>

<?php if ($searched): ?>
<div class="card-custom">
    <div class="d-flex justify-content-end mb-3 no-print">
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>

    <div class="fc-report-wrap" style="max-width:900px;margin:0 auto;">
        <div class="text-center mb-3">
            <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo fcv($inst['logo_path']); ?>" style="max-width:60px;max-height:60px;"><br><?php endif; ?>
            <h4 class="fw-bold text-primary mb-1"><?php echo fcv($inst['institute_name']); ?></h4>
            <?php if ($branch && !empty($branch['address'])): ?>
                <div class="small text-muted">(<?php echo fcv($branch['address']); ?><?php echo $branch['city'] ? ', ' . fcv($branch['city']) : ''; ?><?php echo $branch['zip_code'] ? '-' . fcv($branch['zip_code']) : ''; ?>)</div>
            <?php endif; ?>
            <?php if (!empty($inst['phone_number']) || !empty($branch['phone'])): ?>
                <div class="small">Phone: <?php echo fcv($inst['phone_number'] ?: $branch['phone']); ?></div>
            <?php endif; ?>
            <?php if (!empty($branch['email']) || !empty($inst['email'] ?? '')): ?>
                <div class="small text-primary"><?php echo fcv($branch['email'] ?? ''); ?></div>
            <?php endif; ?>
            <h5 class="text-secondary mt-3 mb-1">Fee Collection Report<?php echo $session_name !== '' ? ' - ' . fcv($session_name) : ''; ?></h5>
            <div class="fw-bold small">
                Date From <?php echo fcv(date('d M Y', strtotime($f_start))); ?> To <?php echo fcv(date('d M Y', strtotime($f_end))); ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:60px;">Srl.</th>
                        <th>Class</th>
                        <th style="width:160px;" class="text-end">Total Collection</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): $srl = 1; foreach ($rows as $r): ?>
                        <tr>
                            <td class="text-primary fw-bold"><?php echo $srl++; ?></td>
                            <td><?php echo fcv($r['class_name'] ?: '—'); ?></td>
                            <td class="text-end"><?php echo fc_money($r['total_amount']); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">এই তারিখের মধ্যে কোনো ফি কালেকশন পাওয়া যায়নি।</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($rows): ?>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="text-end fw-bold">Total</td>
                            <td class="text-end fw-bold"><?php echo fc_money($grand_total); ?></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
