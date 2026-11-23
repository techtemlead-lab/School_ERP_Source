<?php
// admin/library_reports.php - Library Module > Reports
require_once __DIR__ . '/../config/table_print.php';
function lv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$tab = $_GET['tab'] ?? 'overdue';
$today = date('Y-m-d');
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');

$lr_titles = [
    'overdue' => 'Overdue Books Report',
    'history' => 'Book Return History',
    'fine'    => 'Library Fine Collection Report',
    'popular' => 'Most Popular Books (Top 20)',
];
$lr_title = $lr_titles[$tab] ?? 'Library Report';
$lr_sub   = $tab === 'history' ? ('Period: ' . $from . ' to ' . $to) : ('As of: ' . $today);
?>
<?php echo tprint_page_css('portrait', 11); ?>

<ul class="nav nav-tabs mb-3 no-print">
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'overdue' ? 'active' : ''; ?>" href="index.php?page=library_reports&tab=overdue">Overdue Books</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'history' ? 'active' : ''; ?>" href="index.php?page=library_reports&tab=history">Return History</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'fine' ? 'active' : ''; ?>" href="index.php?page=library_reports&tab=fine">Fine Collection</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'popular' ? 'active' : ''; ?>" href="index.php?page=library_reports&tab=popular">Most Popular Books</a></li>
</ul>

<div class="card-custom">
    <?php echo tprint_header_html($pdo, $lr_title, $lr_sub); ?>
    <?php echo tprint_controls(['orient' => 'portrait']); ?>

<?php if ($tab === 'overdue'): ?>
    <?php
    $lr_where = ["li.status = 'Issued'", "li.due_date < '$today'"]; $lr_params = [];
    branch_apply_filter($lr_where, $lr_params, 'students.branch_id');
    $stmt = $pdo->prepare("SELECT li.*, lb.title, lb.book_code, students.student_id AS stu_code, students.name_en,
                                 academic_classes.class_name, academic_sections.section_name,
                                 DATEDIFF('$today', li.due_date) AS days_late
                          FROM library_issues li
                          JOIN library_books lb ON lb.id = li.book_id
                          JOIN students ON students.id = li.student_id
                          LEFT JOIN academic_classes ON academic_classes.id = students.class_id
                          LEFT JOIN academic_sections ON academic_sections.id = students.section_id
                          WHERE " . implode(' AND ', $lr_where) . "
                          ORDER BY days_late DESC");
    $stmt->execute($lr_params);
    $rows = $stmt->fetchAll();
    $settings = $pdo->query("SELECT * FROM library_settings WHERE id=1")->fetch();
    $fine_per_day = $settings ? (float) $settings['fine_per_day'] : 5;
    ?>
    <h5 class="fw-normal mb-3">মেয়াদ উত্তীর্ণ বইসমূহ (<?php echo count($rows); ?>)</h5>
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light"><tr><th>Student</th><th>Class</th><th>Book</th><th>Due Date</th><th>Days Late</th><th>Est. Fine</th></tr></thead>
            <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo lv($r['name_en']); ?> <span class="text-muted small">(<?php echo lv($r['stu_code']); ?>)</span></td>
                        <td><?php echo lv($r['class_name']); ?> <?php echo $r['section_name'] ? '/ ' . lv($r['section_name']) : ''; ?></td>
                        <td><?php echo lv($r['title']); ?> <span class="text-muted small">(<?php echo lv($r['book_code']); ?>)</span></td>
                        <td><?php echo lv($r['due_date']); ?></td>
                        <td><span class="badge bg-danger"><?php echo (int) $r['days_late']; ?> দিন</span></td>
                        <td class="fw-bold">৳<?php echo number_format((int) $r['days_late'] * $fine_per_day, 2); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">কোনো Overdue বই নেই — চমৎকার!</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab === 'history'): ?>
    <?php
    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to'] ?? date('Y-m-d');
    $lrh_where = ["li.status = 'Returned'", 'li.return_date BETWEEN ? AND ?']; $lrh_params = [$from, $to];
    branch_apply_filter($lrh_where, $lrh_params, 'students.branch_id');
    $stmt = $pdo->prepare("SELECT li.*, lb.title, lb.book_code, students.student_id AS stu_code, students.name_en
                            FROM library_issues li
                            JOIN library_books lb ON lb.id = li.book_id
                            JOIN students ON students.id = li.student_id
                            WHERE " . implode(' AND ', $lrh_where) . "
                            ORDER BY li.return_date DESC");
    $stmt->execute($lrh_params);
    $rows = $stmt->fetchAll();
    ?>
    <form method="GET" action="index.php" class="row g-2 mb-3 no-print">
        <input type="hidden" name="page" value="library_reports"><input type="hidden" name="tab" value="history">
        <div class="col-md-3"><label class="form-label small">From</label><input type="date" name="from" class="form-control" value="<?php echo lv($from); ?>"></div>
        <div class="col-md-3"><label class="form-label small">To</label><input type="date" name="to" class="form-control" value="<?php echo lv($to); ?>"></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-outline-primary w-100">Filter</button></div>
    </form>
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light"><tr><th>Student</th><th>Book</th><th>Issue Date</th><th>Return Date</th><th>Fine</th><th>Fine Paid</th></tr></thead>
            <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo lv($r['name_en']); ?> <span class="text-muted small">(<?php echo lv($r['stu_code']); ?>)</span></td>
                        <td><?php echo lv($r['title']); ?> <span class="text-muted small">(<?php echo lv($r['book_code']); ?>)</span></td>
                        <td><?php echo lv($r['issue_date']); ?></td>
                        <td><?php echo lv($r['return_date']); ?></td>
                        <td><?php echo (float) $r['fine_amount'] > 0 ? '৳' . number_format((float) $r['fine_amount'], 2) : '-'; ?></td>
                        <td><?php echo (float) $r['fine_amount'] == 0 ? '<span class="text-muted">N/A</span>' : ($r['fine_paid'] ? '<span class="badge bg-success">Paid</span>' : '<span class="badge bg-danger">Due</span>'); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">এই সময়সীমায় কোনো রিটার্ন রেকর্ড নেই</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab === 'fine'): ?>
    <?php
    $lt_where = ['li.fine_amount > 0']; $lt_params = [];
    branch_apply_filter($lt_where, $lt_params, 'students.branch_id');
    $lt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN li.fine_paid = 1 THEN li.fine_amount END),0) AS collected,
                                COALESCE(SUM(CASE WHEN li.fine_paid = 0 THEN li.fine_amount END),0) AS due
                         FROM library_issues li JOIN students ON students.id = li.student_id
                         WHERE " . implode(' AND ', $lt_where));
    $lt->execute($lt_params);
    $lt_row = $lt->fetch();
    $total_collected = (float) $lt_row['collected'];
    $total_due       = (float) $lt_row['due'];
    ?>
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="top-stat-box" style="background:#10b981;">
                <div><h3>৳<?php echo number_format($total_collected, 2); ?></h3><p>মোট আদায়কৃত জরিমানা</p></div>
                <div class="top-stat-icon"><i class="fa-solid fa-sack-dollar"></i></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="top-stat-box" style="background:#dc2626;">
                <div><h3>৳<?php echo number_format($total_due, 2); ?></h3><p>বাকি থাকা জরিমানা</p></div>
                <div class="top-stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            </div>
        </div>
    </div>
    <?php
    $lf_where = ['li.fine_amount > 0']; $lf_params = [];
    branch_apply_filter($lf_where, $lf_params, 'students.branch_id');
    $stmt = $pdo->prepare("SELECT li.*, lb.title, students.student_id AS stu_code, students.name_en
                          FROM library_issues li JOIN library_books lb ON lb.id=li.book_id JOIN students ON students.id=li.student_id
                          WHERE " . implode(' AND ', $lf_where) . " ORDER BY li.return_date DESC LIMIT 50");
    $stmt->execute($lf_params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light"><tr><th>Student</th><th>Book</th><th>Return Date</th><th>Fine</th><th>Status</th></tr></thead>
            <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo lv($r['name_en']); ?> (<?php echo lv($r['stu_code']); ?>)</td>
                        <td><?php echo lv($r['title']); ?></td>
                        <td><?php echo lv($r['return_date']); ?></td>
                        <td>৳<?php echo number_format((float) $r['fine_amount'], 2); ?></td>
                        <td><?php echo $r['fine_paid'] ? '<span class="badge bg-success">Paid</span>' : '<span class="badge bg-danger">Due</span>'; ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">কোনো জরিমানার রেকর্ড নেই</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab === 'popular'): ?>
    <?php
    $lp_where = ['1=1']; $lp_params = [];
    branch_apply_filter($lp_where, $lp_params, 'students.branch_id');
    $stmt = $pdo->prepare("SELECT lb.title, lb.book_code, lb.author, COUNT(li.id) AS issue_count
                          FROM library_issues li JOIN library_books lb ON lb.id = li.book_id
                          JOIN students ON students.id = li.student_id
                          WHERE " . implode(' AND ', $lp_where) . "
                          GROUP BY li.book_id ORDER BY issue_count DESC LIMIT 20");
    $stmt->execute($lp_params);
    $rows = $stmt->fetchAll();
    ?>
    <h5 class="fw-normal mb-3">সবচেয়ে বেশি পঠিত বই (Top 20)</h5>
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light"><tr><th>Srl.</th><th>Book</th><th>Author</th><th>Times Issued</th></tr></thead>
            <tbody>
                <?php if ($rows): $srl = 1; foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo $srl++; ?></td>
                        <td><?php echo lv($r['title']); ?> <span class="text-muted small">(<?php echo lv($r['book_code']); ?>)</span></td>
                        <td><?php echo lv($r['author']); ?></td>
                        <td><span class="badge bg-primary"><?php echo (int) $r['issue_count']; ?></span></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">এখনো কোনো বই ইস্যু হয়নি</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

</div>
