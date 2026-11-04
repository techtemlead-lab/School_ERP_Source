<?php
// admin/result_formative_settings.php - Result Module > Exam Settings > Formative / Class Test Settings
// Formative (Class Test) মার্ক এন্ট্রির সেটিংস:
//   ১) Setting  : Class/Subject/Term অনুযায়ী Formative-এর Full Mark, কোন Exam Part-এ মার্ক যাবে,
//                 মোড (Component = উপস্থিতি/আচরণ/মনোযোগ... মিলিয়ে, Direct = সরাসরি একটাই মার্ক),
//                 একাধিক Class Test থাকলে মিলানোর নিয়ম (Average / Best N / Sum) — ফলাফল সবসময় Full Mark-এর বেশি হবে না।
//   ২) Components: প্রতিটার ওজন % (সক্রিয়গুলোর যোগফল ১০০% এর বেশি হবে না), উৎস (Manual / Attendance অটো)।
//   ৩) Class Tests: যত খুশি Class Test বানিয়ে এক বা একাধিক Term-এর সাথে assign করা যাবে।
$fs_err = $_SESSION['fs_err'] ?? '';
$fs_msg = $_SESSION['fs_msg'] ?? '';
unset($_SESSION['fs_err'], $_SESSION['fs_msg']);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS formative_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        class_id INT NOT NULL,
        subject_id INT NOT NULL DEFAULT 0,
        term_id INT NOT NULL DEFAULT 0,
        part_id INT NOT NULL DEFAULT 0,
        mode VARCHAR(20) NOT NULL DEFAULT 'Component',
        full_mark DECIMAL(6,2) NOT NULL DEFAULT 10,
        aggregate_method VARCHAR(20) NOT NULL DEFAULT 'Average',
        best_n INT NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_scope (session_id, class_id, subject_id, term_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS formative_components (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        class_id INT NOT NULL,
        subject_id INT NOT NULL DEFAULT 0,
        name VARCHAR(100) NOT NULL,
        weight_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
        source VARCHAR(20) NOT NULL DEFAULT 'Manual',
        rank INT NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        KEY idx_scope (session_id, class_id, subject_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS formative_tests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        class_id INT NOT NULL,
        subject_id INT NOT NULL DEFAULT 0,
        test_name VARCHAR(100) NOT NULL,
        max_score DECIMAL(6,2) NOT NULL DEFAULT 10,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        KEY idx_scope (session_id, class_id, subject_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS formative_test_terms (
        test_id INT NOT NULL,
        term_id INT NOT NULL,
        PRIMARY KEY (test_id, term_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$sid = $current_session ? (int) $current_session['id'] : 0;

function fsv($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function fs_num($v): string { return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'); }

$class_id   = (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
$subject_id = (int) ($_POST['subject_id'] ?? $_GET['subject_id'] ?? 0); // 0 = সব Subject (ডিফল্ট)
$back = 'index.php?page=result_formative_settings&class_id=' . $class_id . '&subject_id=' . $subject_id;

$modes = ['Component' => 'Component (উপস্থিতি/আচরণ/মনোযোগ... মিলিয়ে)', 'Direct' => 'Direct (শিক্ষক সরাসরি একটাই মার্ক দেবেন)'];
$aggs  = ['Average' => 'Average (গড়)', 'Best N' => 'Best N (সেরা N টা)', 'Sum' => 'Sum (যোগফল)'];
$sources = ['Manual' => 'Manual (শিক্ষক দেবেন)', 'Attendance' => 'Attendance (অটো)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['fs_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: ' . $back); exit();
    }
    if ($class_id <= 0) {
        $_SESSION['fs_err'] = 'আগে Class সিলেক্ট করুন।';
        header('Location: ' . $back); exit();
    }
    $act = $_POST['action'] ?? '';

    if ($act === 'save_setting') {
        $term_id = (int) ($_POST['term_id'] ?? 0);
        $part_id = (int) ($_POST['part_id'] ?? 0);
        $mode = isset($modes[$_POST['mode'] ?? '']) ? $_POST['mode'] : 'Component';
        $full = (float) ($_POST['full_mark'] ?? 0);
        $agg  = isset($aggs[$_POST['aggregate_method'] ?? '']) ? $_POST['aggregate_method'] : 'Average';
        $best = max(1, (int) ($_POST['best_n'] ?? 1));
        if ($full <= 0 || $full > 1000) {
            $_SESSION['fs_err'] = 'Formative Full Mark ০ এর বেশি হতে হবে।';
        } elseif ($part_id <= 0) {
            $_SESSION['fs_err'] = 'কোন Exam Part-এ Formative মার্ক যাবে সেটা সিলেক্ট করুন।';
        } else {
            $pdo->prepare(
                "INSERT INTO formative_settings (session_id, class_id, subject_id, term_id, part_id, mode, full_mark, aggregate_method, best_n)
                 VALUES (?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE part_id=VALUES(part_id), mode=VALUES(mode), full_mark=VALUES(full_mark),
                   aggregate_method=VALUES(aggregate_method), best_n=VALUES(best_n)"
            )->execute([$sid, $class_id, $subject_id, $term_id, $part_id, $mode, $full, $agg, $best]);
            $_SESSION['fs_msg'] = 'সেটিং সেভ হয়েছে।';
        }
    } elseif ($act === 'delete_setting') {
        $pdo->prepare("DELETE FROM formative_settings WHERE id=? AND session_id=? AND class_id=?")->execute([(int) ($_POST['id'] ?? 0), $sid, $class_id]);
        $_SESSION['fs_msg'] = 'সেটিং মুছে ফেলা হয়েছে।';
    } elseif ($act === 'save_component') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $w = (float) ($_POST['weight_percent'] ?? 0);
        $src = isset($sources[$_POST['source'] ?? '']) ? $_POST['source'] : 'Manual';
        $rank = (int) ($_POST['rank'] ?? 1);
        $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
        $ot = $pdo->prepare("SELECT COALESCE(SUM(weight_percent),0) FROM formative_components WHERE session_id=? AND class_id=? AND subject_id=? AND status='Active' AND id<>?");
        $ot->execute([$sid, $class_id, $subject_id, $id]);
        $others = (float) $ot->fetchColumn();
        if ($name === '') {
            $_SESSION['fs_err'] = 'Component-এর নাম দিন।';
        } elseif ($w <= 0 || $w > 100) {
            $_SESSION['fs_err'] = 'ওজন (%) ০ থেকে বড় এবং সর্বোচ্চ ১০০ হতে হবে।';
        } elseif ($status === 'Active' && $others + $w > 100.0001) {
            $_SESSION['fs_err'] = 'সেভ হয়নি — সক্রিয় Component-এর ওজনের যোগফল ' . fs_num($others + $w) . '% হয়ে যাচ্ছে (সর্বোচ্চ ১০০%)।';
        } elseif ($id > 0) {
            $pdo->prepare("UPDATE formative_components SET name=?, weight_percent=?, source=?, rank=?, status=? WHERE id=? AND session_id=? AND class_id=?")
                ->execute([$name, $w, $src, $rank, $status, $id, $sid, $class_id]);
            $_SESSION['fs_msg'] = 'Component আপডেট হয়েছে।';
        } else {
            $pdo->prepare("INSERT INTO formative_components (session_id, class_id, subject_id, name, weight_percent, source, rank, status) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$sid, $class_id, $subject_id, $name, $w, $src, $rank, $status]);
            $_SESSION['fs_msg'] = 'Component যোগ হয়েছে।';
        }
    } elseif ($act === 'delete_component') {
        $pdo->prepare("DELETE FROM formative_components WHERE id=? AND session_id=? AND class_id=?")->execute([(int) ($_POST['id'] ?? 0), $sid, $class_id]);
        $_SESSION['fs_msg'] = 'Component মুছে ফেলা হয়েছে।';
    } elseif ($act === 'save_test') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['test_name'] ?? '');
        $max = (float) ($_POST['max_score'] ?? 0);
        $term_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['term_ids'] ?? [])))));
        if ($name === '' || $max <= 0) {
            $_SESSION['fs_err'] = 'Class Test-এর নাম ও সর্বোচ্চ নম্বর দিন।';
        } elseif (!$term_ids) {
            $_SESSION['fs_err'] = 'অন্তত একটা Term সিলেক্ট করুন।';
        } else {
            if ($id > 0) {
                $pdo->prepare("UPDATE formative_tests SET test_name=?, max_score=? WHERE id=? AND session_id=? AND class_id=?")->execute([$name, $max, $id, $sid, $class_id]);
            } else {
                $pdo->prepare("INSERT INTO formative_tests (session_id, class_id, subject_id, test_name, max_score) VALUES (?,?,?,?,?)")->execute([$sid, $class_id, $subject_id, $name, $max]);
                $id = (int) $pdo->lastInsertId();
            }
            $pdo->prepare("DELETE FROM formative_test_terms WHERE test_id=?")->execute([$id]);
            $ins = $pdo->prepare("INSERT IGNORE INTO formative_test_terms (test_id, term_id) VALUES (?,?)");
            foreach ($term_ids as $t) { $ins->execute([$id, $t]); }
            $_SESSION['fs_msg'] = 'Class Test সেভ হয়েছে।';
        }
    } elseif ($act === 'delete_test') {
        $tid = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM formative_test_terms WHERE test_id IN (SELECT id FROM formative_tests WHERE id=? AND session_id=? AND class_id=?)")->execute([$tid, $sid, $class_id]);
        $pdo->prepare("DELETE FROM formative_tests WHERE id=? AND session_id=? AND class_id=?")->execute([$tid, $sid, $class_id]);
        $_SESSION['fs_msg'] = 'Class Test মুছে ফেলা হয়েছে।';
    }
    header('Location: ' . $back); exit();
}

$classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status='Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$tq = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id=? AND status='Active' ORDER BY rank ASC");
$tq->execute([$sid]); $terms = $tq->fetchAll();
$term_names = array_column($terms, 'term_name', 'id');
$pq = $pdo->prepare("SELECT id, part_name FROM exam_parts WHERE session_id=? AND status='Active' ORDER BY rank ASC");
$pq->execute([$sid]); $parts = $pq->fetchAll();
$part_names = array_column($parts, 'part_name', 'id');
$subjects = [];
if ($class_id > 0) {
    $sq = $pdo->prepare("SELECT id, subject_name FROM academic_subjects WHERE class_id=? AND status='Active' ORDER BY sort_order ASC, id ASC");
    $sq->execute([$class_id]); $subjects = $sq->fetchAll();
}
$subj_names = array_column($subjects, 'subject_name', 'id');

$settings = $components = $tests = [];
$w_total = 0.0;
if ($class_id > 0) {
    $q = $pdo->prepare("SELECT * FROM formative_settings WHERE session_id=? AND class_id=? AND subject_id=? ORDER BY term_id ASC");
    $q->execute([$sid, $class_id, $subject_id]); $settings = $q->fetchAll();
    $q = $pdo->prepare("SELECT * FROM formative_components WHERE session_id=? AND class_id=? AND subject_id=? ORDER BY rank ASC, id ASC");
    $q->execute([$sid, $class_id, $subject_id]); $components = $q->fetchAll();
    foreach ($components as $c) { if ($c['status'] === 'Active') { $w_total += (float) $c['weight_percent']; } }
    $q = $pdo->prepare("SELECT * FROM formative_tests WHERE session_id=? AND class_id=? AND subject_id=? ORDER BY id ASC");
    $q->execute([$sid, $class_id, $subject_id]); $tests = $q->fetchAll();
    $tt = $pdo->prepare("SELECT term_id FROM formative_test_terms WHERE test_id=?");
    foreach ($tests as &$t) { $tt->execute([$t['id']]); $t['terms'] = array_map('intval', $tt->fetchAll(PDO::FETCH_COLUMN)); } unset($t);
}
$edit_comp = null; $edit_test = null;
if (!empty($_GET['edit_component'])) { foreach ($components as $c) { if ((int) $c['id'] === (int) $_GET['edit_component']) { $edit_comp = $c; } } }
if (!empty($_GET['edit_test'])) { foreach ($tests as $t) { if ((int) $t['id'] === (int) $_GET['edit_test']) { $edit_test = $t; } } }
?>
<div class="card shadow-sm border-0 mb-4"><div class="card-body">
    <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-clipboard-check"></i> Formative / Class Test Settings</h4>
    <p class="text-muted small mb-3">Class Test বা Formative মার্কের ওজন ও নিয়ম এখানে ঠিক হবে। ফলাফল সবসময় নির্ধারিত Formative Full Mark-এর বেশি হবে না।</p>
    <?php if ($fs_err): ?><div class="alert alert-danger py-2"><?php echo fsv($fs_err); ?></div><?php endif; ?>
    <?php if ($fs_msg): ?><div class="alert alert-success py-2"><?php echo fsv($fs_msg); ?></div><?php endif; ?>

    <form method="GET" action="index.php" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="page" value="result_formative_settings">
        <div class="col-md-4"><label class="form-label fw-semibold">Class</label>
            <select name="class_id" class="form-select" onchange="this.form.submit()">
                <option value="0">— Class সিলেক্ট করুন —</option>
                <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $class_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo fsv($c['class_name']); ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-4"><label class="form-label fw-semibold">Subject</label>
            <select name="subject_id" class="form-select" onchange="this.form.submit()">
                <option value="0">সব Subject (ডিফল্ট)</option>
                <?php foreach ($subjects as $s): ?><option value="<?php echo (int) $s['id']; ?>" <?php echo $subject_id === (int) $s['id'] ? 'selected' : ''; ?>><?php echo fsv($s['subject_name']); ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-4 text-muted small">নির্দিষ্ট Subject-এর সেটিং থাকলে সেটা কাজ করবে, না থাকলে "সব Subject" এর ডিফল্ট কাজ করবে।</div>
    </form>
</div></div>

<?php if ($class_id > 0): ?>
<div class="card shadow-sm border-0 mb-4"><div class="card-body">
    <h5 class="fw-bold">১) মূল সেটিং</h5>
    <form method="POST" action="<?php echo fsv($back); ?>" class="row g-2 align-items-end">
        <?php echo csrf_field(); ?><input type="hidden" name="action" value="save_setting">
        <div class="col-md-3"><label class="form-label">Exam Term</label>
            <select name="term_id" class="form-select"><option value="0">সব Term</option>
                <?php foreach ($terms as $t): ?><option value="<?php echo (int) $t['id']; ?>"><?php echo fsv($t['term_name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">মার্ক যাবে যে Exam Part-এ</label>
            <select name="part_id" class="form-select" required><option value="">— সিলেক্ট —</option>
                <?php foreach ($parts as $p): ?><option value="<?php echo (int) $p['id']; ?>"><?php echo fsv($p['part_name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">মোড</label>
            <select name="mode" class="form-select"><?php foreach ($modes as $k => $v): ?><option value="<?php echo fsv($k); ?>"><?php echo fsv($v); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">Formative Full Mark</label>
            <input type="number" step="0.01" min="0.01" name="full_mark" class="form-control" value="10" required></div>
        <div class="col-md-4"><label class="form-label">একাধিক Class Test থাকলে</label>
            <select name="aggregate_method" class="form-select"><?php foreach ($aggs as $k => $v): ?><option value="<?php echo fsv($k); ?>"><?php echo fsv($v); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Best N (সংখ্যা)</label>
            <input type="number" min="1" name="best_n" class="form-control" value="1"></div>
        <div class="col-md-6 text-end"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> সেভ</button></div>
    </form>
    <div class="table-responsive mt-3"><table class="table table-bordered table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Term</th><th>Exam Part</th><th>মোড</th><th>Full Mark</th><th>মিলানোর নিয়ম</th><th style="width:70px;"></th></tr></thead>
        <tbody>
        <?php if ($settings): foreach ($settings as $s): ?>
            <tr><td><?php echo (int) $s['term_id'] ? fsv($term_names[$s['term_id']] ?? '#' . $s['term_id']) : 'সব Term'; ?></td>
                <td><?php echo fsv($part_names[$s['part_id']] ?? '—'); ?></td>
                <td><?php echo fsv($s['mode']); ?></td><td><?php echo fs_num($s['full_mark']); ?></td>
                <td><?php echo fsv($s['aggregate_method']); ?><?php echo $s['aggregate_method'] === 'Best N' ? ' (' . (int) $s['best_n'] . ')' : ''; ?></td>
                <td><form method="POST" action="<?php echo fsv($back); ?>" onsubmit="return confirm('মুছে ফেলবেন?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_setting"><input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button></form></td></tr>
        <?php endforeach; else: ?><tr><td colspan="6" class="text-center text-muted">এই Class/Subject-এর জন্য এখনো কোনো সেটিং নেই।</td></tr><?php endif; ?>
        </tbody></table></div>
</div></div>

<div class="card shadow-sm border-0 mb-4"><div class="card-body">
    <h5 class="fw-bold">২) Component (উপস্থিতি, আচরণ, মনোযোগ, পারদর্শিতা ইত্যাদি)
        <span class="badge <?php echo abs($w_total - 100) < 0.0001 ? 'bg-success' : 'bg-warning text-dark'; ?> ms-2">সক্রিয়গুলোর যোগফল: <?php echo fs_num($w_total); ?>%</span></h5>
    <?php if ($w_total > 0 && abs($w_total - 100) >= 0.0001): ?><div class="small text-warning mb-2">মার্ক এন্ট্রির সময় যোগফল ১০০% হওয়া দরকার হবে।</div><?php endif; ?>
    <form method="POST" action="<?php echo fsv($back); ?>" class="row g-2 align-items-end mb-3">
        <?php echo csrf_field(); ?><input type="hidden" name="action" value="save_component"><input type="hidden" name="id" value="<?php echo (int) ($edit_comp['id'] ?? 0); ?>">
        <div class="col-md-3"><label class="form-label">নাম</label><input type="text" name="name" class="form-control" required value="<?php echo fsv($edit_comp['name'] ?? ''); ?>" placeholder="যেমন: উপস্থিতি"></div>
        <div class="col-md-2"><label class="form-label">ওজন (%)</label><input type="number" step="0.01" min="0.01" max="100" name="weight_percent" class="form-control" required value="<?php echo fsv($edit_comp['weight_percent'] ?? ''); ?>"></div>
        <div class="col-md-3"><label class="form-label">উৎস</label>
            <select name="source" class="form-select"><?php foreach ($sources as $k => $v): ?><option value="<?php echo fsv($k); ?>" <?php echo ($edit_comp['source'] ?? 'Manual') === $k ? 'selected' : ''; ?>><?php echo fsv($v); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1"><label class="form-label">Rank</label><input type="number" name="rank" class="form-control" value="<?php echo (int) ($edit_comp['rank'] ?? count($components) + 1); ?>"></div>
        <div class="col-md-1"><label class="form-label">Status</label>
            <select name="status" class="form-select"><option>Active</option><option <?php echo ($edit_comp['status'] ?? '') === 'Inactive' ? 'selected' : ''; ?>>Inactive</option></select></div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><?php echo $edit_comp ? 'আপডেট' : 'যোগ করুন'; ?></button></div>
    </form>
    <div class="table-responsive"><table class="table table-bordered table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>#</th><th>নাম</th><th>ওজন %</th><th>উৎস</th><th>Status</th><th style="width:110px;"></th></tr></thead><tbody>
        <?php if ($components): foreach ($components as $i => $c): ?>
            <tr><td><?php echo $i + 1; ?></td><td><?php echo fsv($c['name']); ?></td><td><?php echo fs_num($c['weight_percent']); ?></td><td><?php echo fsv($c['source']); ?></td><td><?php echo fsv($c['status']); ?></td>
                <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?php echo fsv($back . '&edit_component=' . (int) $c['id']); ?>"><i class="fa-solid fa-pen"></i></a>
                <form method="POST" action="<?php echo fsv($back); ?>" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_component"><input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button></form></td></tr>
        <?php endforeach; else: ?><tr><td colspan="6" class="text-center text-muted">কোনো Component নেই (Direct মোড হলে লাগবে না)।</td></tr><?php endif; ?>
        </tbody></table></div>
</div></div>

<div class="card shadow-sm border-0 mb-4"><div class="card-body">
    <h5 class="fw-bold">৩) Class Test (এক বা একাধিক Term-এর সাথে assign)</h5>
    <form method="POST" action="<?php echo fsv($back); ?>" class="row g-2 align-items-end mb-3">
        <?php echo csrf_field(); ?><input type="hidden" name="action" value="save_test"><input type="hidden" name="id" value="<?php echo (int) ($edit_test['id'] ?? 0); ?>">
        <div class="col-md-3"><label class="form-label">Class Test নাম</label><input type="text" name="test_name" class="form-control" required value="<?php echo fsv($edit_test['test_name'] ?? ''); ?>" placeholder="যেমন: Class Test 1"></div>
        <div class="col-md-2"><label class="form-label">সর্বোচ্চ নম্বর</label><input type="number" step="0.01" min="0.01" name="max_score" class="form-control" required value="<?php echo fsv($edit_test['max_score'] ?? 10); ?>"></div>
        <div class="col-md-5"><label class="form-label d-block">Term (একাধিক সিলেক্ট করা যাবে)</label>
            <?php foreach ($terms as $t): ?><label class="me-3"><input type="checkbox" name="term_ids[]" value="<?php echo (int) $t['id']; ?>" <?php echo in_array((int) $t['id'], $edit_test['terms'] ?? [], true) ? 'checked' : ''; ?>> <?php echo fsv($t['term_name']); ?></label><?php endforeach; ?></div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><?php echo $edit_test ? 'আপডেট' : 'যোগ করুন'; ?></button></div>
    </form>
    <div class="table-responsive"><table class="table table-bordered table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>#</th><th>Class Test</th><th>সর্বোচ্চ</th><th>Term</th><th style="width:110px;"></th></tr></thead><tbody>
        <?php if ($tests): foreach ($tests as $i => $t): ?>
            <tr><td><?php echo $i + 1; ?></td><td><?php echo fsv($t['test_name']); ?></td><td><?php echo fs_num($t['max_score']); ?></td>
                <td><?php echo fsv(implode(', ', array_map(function ($id) use ($term_names) { return $term_names[$id] ?? '#' . $id; }, $t['terms']))); ?></td>
                <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?php echo fsv($back . '&edit_test=' . (int) $t['id']); ?>"><i class="fa-solid fa-pen"></i></a>
                <form method="POST" action="<?php echo fsv($back); ?>" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_test"><input type="hidden" name="id" value="<?php echo (int) $t['id']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button></form></td></tr>
        <?php endforeach; else: ?><tr><td colspan="5" class="text-center text-muted">কোনো Class Test নেই (একটাই সরাসরি মার্ক হলে লাগবে না)।</td></tr><?php endif; ?>
        </tbody></table></div>
</div></div>
<?php endif; ?>
