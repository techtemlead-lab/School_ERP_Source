<?php
// admin/result_question_list.php - Result Module > Question Management > Question List
// qms-webhook.php দিয়ে বাইরের QMS থেকে আসা প্রশ্নগুলোর লিস্ট।

require_once __DIR__ . '/../config/qms_helpers.php';
require_once __DIR__ . '/../config/table_print.php';
qms_ensure_tables($pdo);

function qlv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$view = $_GET['view'] ?? 'list';

if ($view === 'view') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM qms_questions WHERE id = ?");
    $stmt->execute([$id]);
    $q = $stmt->fetch();
    ?>
    <?php echo tprint_page_css('portrait', 11); ?>
    <div class="card-custom">
        <?php if (!$q): ?>
            <div class="alert alert-warning">প্রশ্নটি পাওয়া যায়নি।</div>
        <?php else: ?>
            <?php echo tprint_header_html($pdo, 'Question Details', trim(($q['subject'] ?? '') . ($q['class_name'] ? ' | Class: ' . $q['class_name'] : ''))); ?>
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print">
                <a href="index.php?page=result_question_list" class="btn btn-sm btn-outline-secondary">&larr; Back to List</a>
                <?php echo tprint_controls(['orient' => 'portrait', 'wrap' => false]); ?>
            </div>
            <table class="table table-bordered">
                <tr><th style="width:220px;">Subject</th><td><?php echo qlv($q['subject']); ?></td></tr>
                <tr><th>Class</th><td><?php echo qlv($q['class_name']); ?></td></tr>
                <tr><th>Question Type</th><td><?php echo qlv($q['question_type']); ?></td></tr>
                <tr><th>Question</th><td><?php echo nl2br(qlv($q['question_text'])); ?></td></tr>
                <tr><th>Options</th><td><pre class="mb-0"><?php echo qlv($q['options_json']); ?></pre></td></tr>
                <tr><th>Correct Answer</th><td><?php echo qlv($q['correct_answer']); ?></td></tr>
                <tr><th>Marks</th><td><?php echo qlv($q['marks']); ?></td></tr>
                <tr><th>Received At</th><td><?php echo qlv($q['received_at']); ?></td></tr>
                <tr><th>Raw Payload</th><td><pre class="mb-0" style="white-space:pre-wrap;"><?php echo qlv($q['raw_payload']); ?></pre></td></tr>
            </table>
        <?php endif; ?>
    </div>
    <?php
    return;
}

// ---------------- LIST VIEW ----------------
$search  = trim($_GET['search'] ?? '');
$subject = trim($_GET['subject'] ?? '');

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(question_text LIKE ? OR external_question_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($subject !== '') {
    $where[] = "subject = ?";
    $params[] = $subject;
}
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$is_print_all = tprint_is_print_all();
$per_page = 25;
$page_no = max(1, (int) ($_GET['p'] ?? 1));
$offset = $is_print_all ? 0 : (($page_no - 1) * $per_page);
$query_limit = $is_print_all ? 2000 : $per_page;

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM qms_questions $where_sql");
$count_stmt->execute($params);
$total = (int) $count_stmt->fetchColumn();

$list_stmt = $pdo->prepare("SELECT * FROM qms_questions $where_sql ORDER BY id DESC LIMIT $query_limit OFFSET $offset");
$list_stmt->execute($params);
$rows = $list_stmt->fetchAll();

$subjects = $pdo->query("SELECT DISTINCT subject FROM qms_questions WHERE subject IS NOT NULL AND subject != '' ORDER BY subject")->fetchAll(PDO::FETCH_COLUMN);
$total_pages = max(1, (int) ceil($total / $per_page));
$ql_parts = [];
if ($search !== '')  { $ql_parts[] = 'Search: ' . $search; }
if ($subject !== '') { $ql_parts[] = 'Subject: ' . $subject; }
$ql_parts[] = 'Total: ' . $total;
$ql_sub = implode(' | ', $ql_parts);
?>
<?php echo tprint_page_css('landscape', 11); ?>

<div class="card-custom">
    <?php echo tprint_header_html($pdo, 'Question List', $ql_sub); ?>
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 no-print">
        <form method="GET" action="index.php" class="row g-2 align-items-center mb-0">
            <input type="hidden" name="page" value="result_question_list">
            <div class="col-auto">
                <input type="text" name="search" class="form-control" placeholder="Search question / ID" value="<?php echo qlv($search); ?>">
            </div>
            <div class="col-auto">
                <select name="subject" class="form-select">
                    <option value="">All Subjects</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?php echo qlv($s); ?>" <?php echo $subject === $s ? 'selected' : ''; ?>><?php echo qlv($s); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">Filter</button>
            </div>
        </form>
        <?php echo tprint_controls([
            'orient'        => 'landscape',
            'wrap'          => false,
            'print_all_url' => tprint_all_url(),
            'total_count'   => $total,
            'shown_count'   => count($rows),
            'is_print_all'  => $is_print_all,
        ]); ?>
    </div>

    <?php if (!$rows): ?>
        <div class="alert alert-info">এখনো কোনো প্রশ্ন আসেনি। QMS সিস্টেম থেকে প্রশ্ন Generate হলে এখানে চলে আসবে।</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Question</th>
                        <th>Subject</th>
                        <th>Class</th>
                        <th>Type</th>
                        <th>Marks</th>
                        <th>Received At</th>
                        <th class="no-print"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo (int) $r['id']; ?></td>
                            <td><?php echo qlv(mb_strimwidth((string) $r['question_text'], 0, 80, '...')); ?></td>
                            <td><?php echo qlv($r['subject']); ?></td>
                            <td><?php echo qlv($r['class_name']); ?></td>
                            <td><?php echo qlv($r['question_type']); ?></td>
                            <td><?php echo qlv($r['marks']); ?></td>
                            <td><?php echo qlv($r['received_at']); ?></td>
                            <td class="no-print"><a href="index.php?page=result_question_list&view=view&id=<?php echo (int) $r['id']; ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (!$is_print_all && $total_pages > 1): ?>
            <nav class="no-print">
                <ul class="pagination pagination-sm">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo $i === $page_no ? 'active' : ''; ?>">
                            <a class="page-link" href="index.php?page=result_question_list&p=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&subject=<?php echo urlencode($subject); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
