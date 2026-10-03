<?php
// admin/promo_import_contacts.php - Promotional Module > Import Contacts (CSV থেকে বাল্ক মোবাইল নাম্বার আমদানি)

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS promo_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_name VARCHAR(150) NOT NULL,
        image_path VARCHAR(255) NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS promo_contacts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        mobile VARCHAR(20) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_group (group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// -----------------------------------------------------------
// Sample CSV ডাউনলোড (index.php এর output buffer এর মধ্যে থেকেও কাজ করে,
// কারণ শুরুতেই ob_end_clean() করে হেডার পাঠানো হচ্ছে)
// -----------------------------------------------------------
if (($_GET['action'] ?? '') === 'sample_csv') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sample_contacts.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['mobile']);
    fputcsv($out, ['01717xxxxxx']);
    fputcsv($out, ['01625xxxxxx']);
    fclose($out);
    exit();
}

function pic_esc($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

function pic_normalize_mobile(string $num): ?string
{
    $num = preg_replace('/[^0-9]/', '', $num);
    if ($num === '') return null;
    if (strlen($num) === 11 && str_starts_with($num, '01')) { return $num; }
    if (strlen($num) === 13 && str_starts_with($num, '880')) { return substr($num, 2); }
    return strlen($num) === 11 ? $num : null;
}

$pic_err = $_SESSION['pic_err'] ?? '';
$pic_msg = $_SESSION['pic_msg'] ?? '';
unset($_SESSION['pic_err'], $_SESSION['pic_msg']);

// -----------------------------------------------------------
// POST: CSV Import
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_contacts'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['pic_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=promo_import_contacts');
        exit();
    }

    $group_id = (int) ($_POST['group_id'] ?? 0);
    $status   = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    if ($group_id <= 0) {
        $_SESSION['pic_err'] = 'Group Name সিলেক্ট করা আবশ্যক।';
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['pic_err'] = 'একটা CSV ফাইল আপলোড করুন।';
    } else {
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $_SESSION['pic_err'] = 'শুধুমাত্র .csv ফাইল আপলোড করা যাবে।';
        } else {
            $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
            $added = 0; $skipped = 0; $first = true;
            $stmt = $pdo->prepare("INSERT INTO promo_contacts (group_id, mobile, status) VALUES (?, ?, ?)");
            while (($row = fgetcsv($fh)) !== false) {
                $raw = trim($row[0] ?? '');
                // প্রথম সারি হেডার ("mobile") হলে বাদ দেওয়া
                if ($first) {
                    $first = false;
                    if (strtolower($raw) === 'mobile') { continue; }
                }
                $n = pic_normalize_mobile($raw);
                if ($n === null) { if ($raw !== '') $skipped++; continue; }
                $stmt->execute([$group_id, $n, $status]);
                $added++;
            }
            fclose($fh);
            $_SESSION['pic_msg'] = "{$added} টি Contact ইমপোর্ট করা হয়েছে।" . ($skipped > 0 ? " {$skipped} টি সারি সঠিক ফরম্যাটে না থাকায় বাদ পড়েছে।" : '');
            if ($added > 0) {
                sec_log_activity($pdo, 'Promotional', 'Import', "CSV থেকে {$added} টি Contact ইমপোর্ট করা হয়েছে (Group ID: {$group_id})");
            }
        }
    }

    header('Location: index.php?page=promo_import_contacts');
    exit();
}

$groups = $pdo->query("SELECT id, group_name FROM promo_groups WHERE status = 'Active' ORDER BY group_name ASC")->fetchAll();
?>

<div class="card-custom">
    <?php if ($pic_msg): ?><div class="alert alert-success py-2"><?php echo pic_esc($pic_msg); ?></div><?php endif; ?>
    <?php if ($pic_err): ?><div class="alert alert-danger py-2"><?php echo pic_esc($pic_err); ?></div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <a href="index.php?page=promo_contacts" class="d-inline-block bg-warning bg-opacity-75 p-2 text-decoration-none" style="width:40px;border-radius:4px;" title="My Contacts এ ফিরে যান">
            <i class="fa-solid fa-gift text-white"></i>
        </a>
    </div>

    <?php if (!$groups): ?>
        <div class="alert alert-warning">প্রথমে <a href="index.php?page=promo_groups&view=add">একটা Group তৈরি করুন</a>, তারপর এখান থেকে CSV ইমপোর্ট করা যাবে।</div>
    <?php else: ?>
    <form method="POST" action="index.php?page=promo_import_contacts" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Group Name <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="group_id" class="form-select" required>
                    <option value="">Select My Group</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?php echo (int) $g['id']; ?>"><?php echo pic_esc($g['group_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-1 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Import <span class="text-danger">*</span></label>
            <div class="col-sm-6 d-flex align-items-center gap-2">
                <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                <a href="index.php?page=promo_import_contacts&action=sample_csv" class="btn btn-warning text-nowrap fw-bold">Download Sample File</a>
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-sm-6 offset-sm-3"><small class="text-muted fst-italic">Please Upload CSV File — প্রথম কলামে মোবাইল নাম্বার (হেডার row: mobile)</small></div>
        </div>

        <div class="row mb-4 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Status <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <select name="status" class="form-select">
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
        </div>

        <hr>
        <div class="row">
            <div class="col-sm-6 offset-sm-3">
                <button type="submit" name="import_contacts" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;">Create</button>
                <a href="index.php?page=promo_contacts" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>
