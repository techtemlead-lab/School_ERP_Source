<?php
// admin/academic_subject_import.php - Academic Module > Subject > Import Subject (Excel/CSV/TXT)
//
// academic_class_import.php এর একই প্যাটার্ন: (১) আপলোড -> পার্স -> ভ্যালিডেট -> প্রিভিউ
// (২) Confirm করলে শুধু ভ্যালিড রো গুলো academic_subjects টেবিলে insert হয়।
// CSV/TXT/XLSX পার্সিং লজিক config/import_helpers.php তে শেয়ার্ড।

require_once __DIR__ . '/../config/import_helpers.php';

// academic_subjects / academic_classes / academic_groups টেবিল এখনো তৈরি না হলে
// (একদম নতুন tenant) নিশ্চিত করে নেওয়া
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS academic_classes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        medium_id INT NULL,
        class_name VARCHAR(100) NOT NULL,
        rank INT NOT NULL DEFAULT 0,
        class_code VARCHAR(5) NULL,
        has_group TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS academic_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_name VARCHAR(100) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS academic_subjects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NULL,
        subject_name VARCHAR(150) NOT NULL,
        combined_with_id INT NULL,
        subject_code VARCHAR(20) NULL,
        full_marks INT NOT NULL DEFAULT 100,
        subject_type ENUM('Compulsory','Optional','Continuous Assessment') NOT NULL DEFAULT 'Compulsory',
        group_id INT NULL,
        optional_count INT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$imp_msg = $_SESSION['subj_imp_msg'] ?? '';
$imp_err = $_SESSION['subj_imp_err'] ?? '';
unset($_SESSION['subj_imp_msg'], $_SESSION['subj_imp_err']);

function siv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$subj_import_field_aliases = [
    'class'         => ['class', 'class name', 'ক্লাস'],
    'subject_name'  => ['subject name', 'subject', 'নাম', 'বিষয়'],
    'subject_code'  => ['subject code', 'code', 'বিষয় কোড'],
    'full_marks'    => ['full marks', 'marks', 'পূর্ণমান'],
    'subject_type'  => ['subject type', 'type', 'ধরন'],
    'group'         => ['group', 'group name', 'গ্রুপ'],
    'optional_count' => ['optional count', 'optional count (gpa>2)', 'ঐচ্ছিক সংখ্যা'],
    'status'        => ['status', 'স্ট্যাটাস'],
];

$import_tmp_dir = tenant_upload_abs(__DIR__ . '/..', 'tmp');
if (!is_dir($import_tmp_dir)) { mkdir($import_tmp_dir, 0755, true); }
import_cleanup_old_temp($import_tmp_dir);

// -----------------------------------------------------------
// স্যাম্পল CSV টেমপ্লেট ডাউনলোড
// -----------------------------------------------------------
if (isset($_GET['sample'])) {
    while (ob_get_level() > 0) { ob_end_clean(); } // sidebar/header ইতিমধ্যে যা buffer-এ জমেছে তা ফেলে দাও
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="subject_import_sample.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Class', 'Subject Name', 'Subject Code', 'Full Marks', 'Subject Type', 'Group', 'Optional Count (GPA>2)', 'Status']);
    fputcsv($out, ['Class Nine', 'Bangla 1st Paper', '101', '100', 'Compulsory', '', '', 'Active']);
    fputcsv($out, ['Class Nine', 'Higher Math', '126', '100', 'Optional', 'Science', '', 'Active']);
    fclose($out);
    exit();
}

// -----------------------------------------------------------
// STEP 1: আপলোড -> পার্স -> ভ্যালিডেট -> প্রিভিউ
// -----------------------------------------------------------
$preview_rows = null;
$preview_token = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_file'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['subj_imp_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করুন।';
        header('Location: index.php?page=academic_subject_import');
        exit();
    }
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['subj_imp_err'] = 'ফাইল আপলোড করা যায়নি, আবার চেষ্টা করুন।';
        header('Location: index.php?page=academic_subject_import');
        exit();
    }

    $file = $_FILES['import_file'];
    if ($file['size'] > 5 * 1024 * 1024) {
        $_SESSION['subj_imp_err'] = 'ফাইলের সাইজ সর্বোচ্চ 5MB হতে হবে।';
        header('Location: index.php?page=academic_subject_import');
        exit();
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
        $_SESSION['subj_imp_err'] = 'শুধু .csv, .txt অথবা .xlsx ফাইল আপলোড করা যাবে। (পুরনো .xls হলে Excel থেকে .xlsx/.csv তে "Save As" করে আবার চেষ্টা করুন।)';
        header('Location: index.php?page=academic_subject_import');
        exit();
    }

    try {
        $rawRows = import_parse_file($file['tmp_name'], $ext);
    } catch (Exception $e) {
        $_SESSION['subj_imp_err'] = $e->getMessage();
        header('Location: index.php?page=academic_subject_import');
        exit();
    }

    if (count($rawRows) < 2) {
        $_SESSION['subj_imp_err'] = 'ফাইলে হেডার সারি বাদে অন্তত ১টা ডাটা সারি থাকতে হবে।';
        header('Location: index.php?page=academic_subject_import');
        exit();
    }

    $headerRow = array_shift($rawRows);
    $colMap = import_map_headers($headerRow, $subj_import_field_aliases);

    if (!in_array('subject_name', $colMap, true)) {
        $_SESSION['subj_imp_err'] = 'ফাইলে অন্তত Subject Name কলাম থাকা আবশ্যক (কলামের নাম মিলছে না — স্যাম্পল টেমপ্লেট দেখে ঠিক করুন)।';
        header('Location: index.php?page=academic_subject_import');
        exit();
    }

    $classes_lk = [];
    foreach ($pdo->query("SELECT id, class_name FROM academic_classes WHERE status='Active'") as $r) {
        $classes_lk[mb_strtolower(trim($r['class_name']))] = $r['id'];
    }
    $groups_lk = [];
    foreach ($pdo->query("SELECT id, group_name FROM academic_groups WHERE status='Active'") as $r) {
        $groups_lk[mb_strtolower(trim($r['group_name']))] = $r['id'];
    }

    // ডুপ্লিকেট চেক: একই class এর মধ্যে একই subject_code আগে থেকে থাকলে
    $existing_codes = [];
    foreach ($pdo->query("SELECT class_id, subject_code FROM academic_subjects WHERE subject_code IS NOT NULL AND subject_code <> ''") as $r) {
        $existing_codes[$r['class_id'] . '|' . $r['subject_code']] = true;
    }
    $seen_in_file = [];

    $validated = [];
    foreach ($rawRows as $rowIdx => $raw) {
        if (!array_filter($raw, fn($v) => trim((string) $v) !== '')) continue;

        $row = [];
        foreach ($colMap as $idx => $field) { $row[$field] = trim((string) ($raw[$idx] ?? '')); }

        $errors = [];

        $subject_name = $row['subject_name'] ?? '';
        if ($subject_name === '') $errors[] = 'বিষয়ের নাম নেই';

        $classRaw = $row['class'] ?? '';
        $class_id = null;
        if ($classRaw !== '') {
            $class_id = $classes_lk[mb_strtolower($classRaw)] ?? null;
            if (!$class_id) $errors[] = "Class '{$classRaw}' খুঁজে পাওয়া যায়নি (Academic Module > All Class List এ আগে থেকে যোগ করে নিন)";
        } else {
            $errors[] = 'Class কলাম খালি';
        }

        $subject_code = trim($row['subject_code'] ?? '');
        if ($subject_code !== '' && $class_id) {
            $key = $class_id . '|' . $subject_code;
            if (isset($existing_codes[$key]) || isset($seen_in_file[$key])) {
                $errors[] = "Subject Code '{$subject_code}' এই Class-এ আগে থেকেই আছে (ডুপ্লিকেট)";
            }
        }

        $full_marks = trim($row['full_marks'] ?? '') !== '' ? (int) $row['full_marks'] : 100;

        $typeRaw = mb_strtolower($row['subject_type'] ?? '');
        $subject_type = match ($typeRaw) {
            'optional' => 'Optional',
            'continuous assessment', 'ca' => 'Continuous Assessment',
            default => 'Compulsory',
        };

        $groupRaw = $row['group'] ?? '';
        $group_id = $groupRaw !== '' ? ($groups_lk[mb_strtolower($groupRaw)] ?? null) : null;
        if ($groupRaw !== '' && !$group_id) {
            $errors[] = "Group '{$groupRaw}' খুঁজে পাওয়া যায়নি (Academic Module > All Group List এ আগে থেকে যোগ করে নিন)";
        }

        $optional_count = trim($row['optional_count'] ?? '') !== '' ? (int) $row['optional_count'] : null;

        $statusRaw = mb_strtolower($row['status'] ?? '');
        $status = $statusRaw === 'inactive' ? 'Inactive' : 'Active';

        $resolved = [
            'class_id' => $class_id, 'class_label' => $classRaw,
            'subject_name' => $subject_name, 'subject_code' => $subject_code, 'full_marks' => $full_marks,
            'subject_type' => $subject_type, 'group_id' => $group_id, 'group_label' => $groupRaw,
            'optional_count' => $optional_count, 'status' => $status,
        ];

        if ($subject_code !== '' && $class_id) { $seen_in_file[$class_id . '|' . $subject_code] = true; }

        $validated[] = ['row_no' => $rowIdx + 2, 'data' => $resolved, 'errors' => $errors, 'ok' => empty($errors)];
    }

    if (!$validated) {
        $_SESSION['subj_imp_err'] = 'ফাইলে কোনো বৈধ ডাটা সারি পাওয়া যায়নি।';
        header('Location: index.php?page=academic_subject_import');
        exit();
    }

    $token = bin2hex(random_bytes(12));
    file_put_contents($import_tmp_dir . 'import_subj_' . $token . '.json', json_encode($validated, JSON_UNESCAPED_UNICODE));

    $preview_rows = $validated;
    $preview_token = $token;
}

// -----------------------------------------------------------
// STEP 2: Confirm — শুধু ভ্যালিড রো insert
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['subj_imp_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করুন।';
        header('Location: index.php?page=academic_subject_import');
        exit();
    }

    $token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
    $tmpFile = $import_tmp_dir . 'import_subj_' . $token . '.json';
    if ($token === '' || !is_file($tmpFile)) {
        $_SESSION['subj_imp_err'] = 'প্রিভিউ ডাটার মেয়াদ শেষ হয়ে গেছে (১ ঘন্টা), আবার ফাইল আপলোড করুন।';
        header('Location: index.php?page=academic_subject_import');
        exit();
    }

    $rows = json_decode(file_get_contents($tmpFile), true) ?: [];
    $imported = 0;
    $skipped = 0;

    $stmt = $pdo->prepare(
        "INSERT INTO academic_subjects (class_id, subject_name, subject_code, full_marks, subject_type, group_id, optional_count, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            if (empty($r['ok'])) { $skipped++; continue; }
            $d = $r['data'];

            $stmt->execute([
                $d['class_id'], $d['subject_name'], $d['subject_code'] ?: null, $d['full_marks'],
                $d['subject_type'], $d['group_id'] ?: null, $d['optional_count'], $d['status'],
            ]);
            $imported++;
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['subj_imp_err'] = 'Import করার সময় সমস্যা হয়েছে: ' . $e->getMessage();
        header('Location: index.php?page=academic_subject_import');
        exit();
    }

    @unlink($tmpFile);
    if ($imported > 0) {
        sec_log_activity($pdo, 'Academic', 'Import', "Subject Import করা হয়েছে: {$imported} টি (skipped: {$skipped})");
    }
    $_SESSION['subj_imp_msg'] = "{$imported} টি Subject সফলভাবে import করা হয়েছে।" . ($skipped ? " {$skipped} টি সারি এরর থাকায় বাদ দেওয়া হয়েছে।" : '');
    header('Location: index.php?page=academic_subject_import');
    exit();
}

$ok_count = $preview_rows ? count(array_filter($preview_rows, fn($r) => $r['ok'])) : 0;
$err_count = $preview_rows ? count($preview_rows) - $ok_count : 0;
?>

<div class="card-custom">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-file-import"></i> Academic Module &gt; Subject &gt; Import Subject</h4>
        <a href="index.php?page=academic_subject" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Subject লিস্টে ফিরে যান</a>
    </div>

    <?php if ($imp_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($imp_msg); ?></div><?php endif; ?>
    <?php if ($imp_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($imp_err); ?></div><?php endif; ?>

    <?php if (!$preview_rows): ?>
        <div class="row">
            <div class="col-md-6">
                <div class="card p-3 bg-light">
                    <h6 class="fw-bold mb-3">ফাইল আপলোড করুন</h6>
                    <form method="POST" action="index.php?page=academic_subject_import" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label">Excel (.xlsx) / CSV / TXT ফাইল সিলেক্ট করুন</label>
                            <input type="file" name="import_file" class="form-control" accept=".xlsx,.csv,.txt" required>
                            <small class="text-muted d-block mt-1">সর্বোচ্চ 5MB। পুরনো .xls ফরম্যাট হলে আগে .xlsx বা .csv তে সেভ করে নিন।</small>
                        </div>
                        <button type="submit" name="upload_file" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-upload"></i> Upload &amp; Preview
                        </button>
                        <a href="index.php?page=academic_subject_import&sample=1" class="btn btn-outline-secondary btn-sm">
                            <i class="fa-solid fa-download"></i> স্যাম্পল টেমপ্লেট ডাউনলোড
                        </a>
                    </form>
                </div>
            </div>
            <div class="col-md-6">
                <div class="alert alert-info small">
                    <b>নিয়মাবলী:</b>
                    <ul class="mb-0 ps-3">
                        <li>প্রথম সারি অবশ্যই হেডার (কলামের নাম) হতে হবে।</li>
                        <li>আবশ্যক কলাম: <b>Class, Subject Name</b> — বাকিগুলো ঐচ্ছিক।</li>
                        <li><b>Class</b> অবশ্যই Academic Module &gt; All Class List এ আগে থেকে তৈরি করা নামের সাথে মিলতে হবে।</li>
                        <li><b>Group</b> দিলে Academic Module &gt; All Group List এ আগে থেকে তৈরি করা নামের সাথে মিলতে হবে।</li>
                        <li><b>Subject Code</b> দিলে একই Class-এ ডুপ্লিকেট Code থাকলে সেই সারি এরর দেখাবে।</li>
                        <li><b>Full Marks</b> খালি রাখলে ডিফল্ট 100 বসবে। <b>Subject Type</b> খালি বা না মিললে ডিফল্ট 'Compulsory' বসবে।</li>
                        <li><b>Status</b> কলামে Active / Inactive দিন, খালি থাকলে ডিফল্ট 'Active' বসবে।</li>
                        <li>Upload করার পর প্রথমে প্রিভিউ দেখাবে — Confirm করলে শুধু ঠিক সারিগুলোই সেভ হবে।</li>
                    </ul>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <span class="badge bg-success">ঠিক আছে: <?php echo $ok_count; ?></span>
                <span class="badge bg-danger">এরর: <?php echo $err_count; ?></span>
                <span class="text-muted small ms-2">মোট <?php echo count($preview_rows); ?> সারি পাওয়া গেছে</span>
            </div>
            <div>
                <a href="index.php?page=academic_subject_import" class="btn btn-outline-secondary btn-sm">বাতিল করুন</a>
                <form method="POST" action="index.php?page=academic_subject_import" class="d-inline">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($preview_token); ?>">
                    <button type="submit" name="confirm_import" class="btn btn-success btn-sm" <?php echo $ok_count === 0 ? 'disabled' : ''; ?>>
                        <i class="fa-solid fa-check"></i> Confirm Import (<?php echo $ok_count; ?> টি)
                    </button>
                </form>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>সারি</th><th>স্ট্যাটাস</th><th>Class</th><th>Subject Name</th><th>Code</th><th>Marks</th><th>Type</th><th>Group</th><th>বিস্তারিত</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview_rows as $r): $d = $r['data']; ?>
                        <tr class="<?php echo $r['ok'] ? '' : 'table-danger'; ?>">
                            <td><?php echo (int) $r['row_no']; ?></td>
                            <td><?php echo $r['ok'] ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">Error</span>'; ?></td>
                            <td><?php echo $d['class_id'] ? '✓ ' . siv($d['class_label']) : '<span class="text-muted">—</span>'; ?></td>
                            <td><?php echo siv($d['subject_name']); ?></td>
                            <td><?php echo siv($d['subject_code']); ?></td>
                            <td><?php echo (int) $d['full_marks']; ?></td>
                            <td><?php echo siv($d['subject_type']); ?></td>
                            <td><?php echo $d['group_id'] ? siv($d['group_label']) : '<span class="text-muted">—</span>'; ?></td>
                            <td class="small text-danger"><?php echo siv(implode(', ', $r['errors'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
