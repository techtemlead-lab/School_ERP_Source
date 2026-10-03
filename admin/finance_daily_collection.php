<?php
// admin/finance_daily_collection.php - Finance Module > Finance Reports > Daily Collection
// একটা তারিখ-রেঞ্জ (From - To) এবং ঐচ্ছিক "Received By" দিয়ে Student's Fee Collection
// Report — প্রতিটা Folio (এক কালেকশন এন্ট্রি) এক রো হিসেবে, মোট Amount সহ।

function dcv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function dc_money($n): string { return number_format((float) $n, 2); }

// টেবিল না থাকলে অটো তৈরি (finance_fees_collection.php / finance_quick_collection.php এর সাথে শেয়ার্ড)
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

// -----------------------------------------------------------
// ফিল্টার — Transaction Date From/To (ডিফল্ট আজকের তারিখ) + Received By
// -----------------------------------------------------------
// প্রথমবার পেজে ঢুকলেই (কোনো ফিল্টার না দিয়ে) আজকের কালেকশন অটো দেখানোর জন্য —
// date_from/date_to ডিফল্ট আজকের তারিখ, তাই $searched সবসময় true রাখা হলো
$searched   = true;
$date_from  = trim($_GET['date_from'] ?? date('Y-m-d'));
$date_to    = trim($_GET['date_to'] ?? date('Y-m-d'));
$f_received = trim($_GET['received_by'] ?? '');

// From > To হলে অদল-বদল করে নেওয়া
if ($date_from !== '' && $date_to !== '' && strtotime($date_from) > strtotime($date_to)) {
    [$date_from, $date_to] = [$date_to, $date_from];
}

$received_by_options = $pdo->query(
    "SELECT DISTINCT collected_by FROM finance_fee_collections WHERE collected_by IS NOT NULL AND collected_by <> '' ORDER BY collected_by ASC"
)->fetchAll(PDO::FETCH_COLUMN);

$rows = [];
$grand_total = 0.0;

if ($searched && $date_from !== '' && $date_to !== '') {
    $where = ["fc.folio_no IS NOT NULL", "fc.folio_no <> ''", "DATE(fc.collected_at) BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];
    if ($f_received !== '') { $where[] = 'fc.collected_by = ?'; $params[] = $f_received; }
    branch_apply_filter($where, $params, 's.branch_id');
    $where_sql = implode(' AND ', $where);

    $sql = "SELECT fc.folio_no, MIN(fc.student_id) AS student_id, MIN(fc.collected_at) AS collected_at,
                   MIN(fc.collected_by) AS collected_by, SUM(fc.amount) AS total_amount
            FROM finance_fee_collections fc
            LEFT JOIN students s ON s.id = fc.student_id
            WHERE $where_sql
            GROUP BY fc.folio_no
            ORDER BY MIN(fc.collected_at) ASC, MIN(fc.id) ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $folio_rows = $stmt->fetchAll();

    $student_ids = array_unique(array_filter(array_column($folio_rows, 'student_id')));
    $student_map = [];
    if ($student_ids) {
        $in = implode(',', array_map('intval', $student_ids));
        $sr = $pdo->query(
            "SELECT s.id, s.name_en, s.roll_no, c.class_name, sh.shift_name, sec.section_name
             FROM students s
             LEFT JOIN academic_classes c ON c.id = s.class_id
             LEFT JOIN academic_shifts sh ON sh.id = s.shift_id
             LEFT JOIN academic_sections sec ON sec.id = s.section_id
             WHERE s.id IN ($in)"
        );
        foreach ($sr->fetchAll() as $r) { $student_map[(int) $r['id']] = $r; }
    }

    foreach ($folio_rows as $fr) {
        $st = $student_map[(int) $fr['student_id']] ?? [];
        $rows[] = [
            'name'        => $st['name_en'] ?? '—',
            'class'       => $st['class_name'] ?? '—',
            'roll'        => $st['roll_no'] ?? '—',
            'shift'       => $st['shift_name'] ?? '—',
            'section'     => $st['section_name'] ?? '—',
            'folio_no'    => $fr['folio_no'],
            'amount'      => (float) $fr['total_amount'],
            'received_by' => $fr['collected_by'] ?: '—',
        ];
        $grand_total += (float) $fr['total_amount'];
    }
}

$inst = $pdo->query("SELECT institute_name, phone_number FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) { $inst = ['institute_name' => 'School Name', 'phone_number' => '']; }
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
        .dc-filter-card, footer { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; }
        .content-body { padding: 0 !important; margin: 0 !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
        .dc-report-wrap { max-width: 100% !important; box-shadow: none !important; border: none !important; }
        .dc-report-wrap .table-responsive { overflow: visible !important; }
        .dc-report-wrap table { width: 100% !important; font-size: 12px; }
        .dc-report-wrap th, .dc-report-wrap td { padding: 4px 6px !important; }
        .dc-report-wrap a { color: #000 !important; text-decoration: none !important; }
        .dc-report-wrap thead { display: table-header-group; }
        .dc-report-wrap tr { page-break-inside: avoid; }
    }
</style>

<div class="card-custom mb-3 dc-filter-card">
    <form method="GET" action="index.php">
        <input type="hidden" name="page" value="finance_daily_collection">
        <input type="hidden" name="search" value="1">
        <div class="row g-3 align-items-end">
            <div class="col-sm-3">
                <label class="form-label fw-bold">Transaction Date (From) <span class="text-danger">*</span></label>
                <input type="date" name="date_from" class="form-control" required value="<?php echo dcv($date_from); ?>">
            </div>
            <div class="col-sm-3">
                <label class="form-label fw-bold">Transaction Date (To) <span class="text-danger">*</span></label>
                <input type="date" name="date_to" class="form-control" required value="<?php echo dcv($date_to); ?>">
            </div>
            <div class="col-sm-3">
                <label class="form-label fw-bold">Received By</label>
                <select name="received_by" class="form-select">
                    <option value="">Select Received By</option>
                    <?php foreach ($received_by_options as $rb): ?>
                        <option value="<?php echo dcv($rb); ?>" <?php echo $f_received === $rb ? 'selected' : ''; ?>><?php echo dcv($rb); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-3">
                <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            </div>
        </div>
    </form>
</div>

<?php if ($searched): ?>
<div class="card-custom">
    <div class="d-flex justify-content-end mb-3 no-print">
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>

    <div class="dc-report-wrap" style="max-width:1000px;margin:0 auto;">
        <div class="text-center mb-3">
            <h4 class="fw-bold text-primary mb-1"><?php echo dcv($inst['institute_name']); ?></h4>
            <?php if ($branch && !empty($branch['address'])): ?>
                <div class="small text-muted">(<?php echo dcv($branch['address']); ?><?php echo $branch['city'] ? ', ' . dcv($branch['city']) : ''; ?><?php echo $branch['zip_code'] ? '-' . dcv($branch['zip_code']) : ''; ?>)</div>
            <?php endif; ?>
            <?php if (!empty($inst['phone_number']) || !empty($branch['phone'])): ?>
                <div class="small">Phone: <?php echo dcv($inst['phone_number'] ?: $branch['phone']); ?></div>
            <?php endif; ?>
            <h5 class="text-secondary mt-3 mb-1">Student's Daily Fee Collection Report</h5>
            <div class="fw-bold small">
                Transaction Date :
                <?php echo $date_from === $date_to ? dcv(date('d M Y', strtotime($date_from))) : dcv(date('d M Y', strtotime($date_from)) . ' to ' . date('d M Y', strtotime($date_to))); ?>
                <?php if ($f_received !== ''): ?> &middot; Received By : <?php echo dcv($f_received); ?><?php endif; ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px;">Srl.</th>
                        <th>Name</th>
                        <th style="width:70px;">Class</th>
                        <th style="width:60px;">Roll</th>
                        <th style="width:90px;">Shift</th>
                        <th style="width:90px;">Section</th>
                        <th style="width:110px;">Folio No.</th>
                        <th style="width:110px;" class="text-end">Amount</th>
                        <th style="width:100px;">Received By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): $srl = 1; foreach ($rows as $r): ?>
                        <tr>
                            <td class="text-primary fw-bold"><?php echo $srl++; ?></td>
                            <td><?php echo dcv($r['name']); ?></td>
                            <td><?php echo dcv($r['class']); ?></td>
                            <td><?php echo dcv($r['roll']); ?></td>
                            <td><?php echo dcv($r['shift']); ?></td>
                            <td><?php echo dcv($r['section']); ?></td>
                            <td>
                                <a href="print_money_receipt.php?folio_no=<?php echo urlencode($r['folio_no']); ?>&back=finance_daily_collection" target="_blank" class="fw-bold text-decoration-none" title="Money Receipt দেখুন"><?php echo dcv($r['folio_no']); ?></a>
                            </td>
                            <td class="text-end"><?php echo dc_money($r['amount']); ?></td>
                            <td><?php echo dcv($r['received_by']); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="9" class="text-center text-muted py-3">এই তারিখের মধ্যে কোনো ফি কালেকশন পাওয়া যায়নি।</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($rows): ?>
                    <tfoot>
                        <tr>
                            <td colspan="7" class="text-end fw-bold">Total</td>
                            <td class="text-end fw-bold"><?php echo dc_money($grand_total); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
