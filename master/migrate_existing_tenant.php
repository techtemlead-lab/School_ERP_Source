<?php
require_once __DIR__ . '/_guard.php';
masterRequireAccess($pdo_master, 'migrate_tenant', 'add');
if(erp_env('APP_ENV')==='production')erp_stop(403,'Existing-school imports require the private operator migration workflow and an isolated runtime.');

/**
 * master/migrate_existing_tenant.php
 * -----------------------------------------------------------
 * এই স্ক্রিপ্টটা তোমার EXISTING single-school ডাটাকে (যেটা এখন
 * config/db.php তে হার্ডকোড করা ছিল) SaaS এর প্রথম tenant হিসেবে
 * রেজিস্টার করে দেয়। এটা শুধু ★একবার★ রান করার জন্য।
 *
 * এটা যা করে:
 *   ১) master DB এর tenants টেবিলে একটা row বানায় (তোমার পুরনো DB
 *      credential দিয়ে) — নতুন কোনো DB তৈরি করে না, পুরনো DB-ই থেকে যায়
 *   ২) uploads/students, uploads/institute, uploads/website,
 *      uploads/branch, uploads/employees, uploads/certificate_templates,
 *      uploads/id_card_templates, uploads/admissions ফোল্ডারগুলো
 *      uploads/{school_code}/... এ সরিয়ে নেয়
 *   ৩) সেই স্কুলের নিজের DB-তে গিয়ে *_path কলামগুলোতে নতুন prefix বসিয়ে দেয়
 *      (idempotent — যেগুলোর prefix আগে থেকেই ঠিক আছে সেগুলো স্কিপ হয়)
 *
 * ⚠️ রান করার আগে পুরো প্রজেক্ট ফোল্ডার + DB এর একটা ব্যাকআপ নিয়ে রাখো।
 */

$done = false;
$log = [];
$errors = [];

// শুধু GET এ ফর্ম দেখাবে, POST এ আসল কাজ হবে
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করো।';
    } else {
        $school_name = trim($_POST['school_name'] ?? '');
        $school_code = strtolower(preg_replace('/[^a-z0-9]+/', '', trim($_POST['school_code'] ?? '')));
        $db_host     = trim($_POST['db_host'] ?? 'localhost');
        $db_name     = trim($_POST['db_name'] ?? '');
        $db_user     = trim($_POST['db_user'] ?? '');
        $db_pass     = trim($_POST['db_pass'] ?? '');
        $project_root = rtrim(trim($_POST['project_root'] ?? (__DIR__ . '/..')), '/'); // যেখানে uploads/ ফোল্ডার আছে
        $confirm     = isset($_POST['confirm']);

        if ($school_name === '' || $school_code === '' || $db_name === '' || $db_user === '' || $db_pass === '') {
            $errors[] = 'সব ফিল্ড পূরণ করতে হবে।';
        }
        if (!$confirm) {
            $errors[] = 'নিচের চেকবক্সে টিক দিয়ে নিশ্চিত করো যে ব্যাকআপ নেওয়া আছে।';
        }

        // school_code আগে থেকে থাকলে
        if (!$errors) {
            $chk = $pdo_master->prepare('SELECT id FROM tenants WHERE school_code = ?');
            $chk->execute([$school_code]);
            if ($chk->fetch()) {
                $errors[] = 'এই school_code ইতিমধ্যে ব্যবহৃত হয়েছে।';
            }
        }

        // স্কুলের নিজের DB তে কানেক্ট টেস্ট
        $tenant_pdo = null;
        if (!$errors) {
            try {
                $tenant_pdo = new PDO(
                    "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
                    $db_user, $db_pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
                );
            } catch (PDOException $e) {
                $errors[] = 'এই DB credential দিয়ে কানেক্ট করা যায়নি: ' . $e->getMessage();
            }
        }

        // ধাপ ১: uploads ফোল্ডার সরানো
        if (!$errors) {
            $modules = ['students', 'institute', 'website', 'branch', 'employees', 'certificate_templates', 'id_card_templates', 'admissions'];
            $old_base = $project_root . '/uploads/';
            $new_base = $project_root . '/uploads/' . $school_code . '/';

            if (!is_dir($new_base)) {
                @mkdir($new_base, 0755, true);
            }

            foreach ($modules as $mod) {
                $old_dir = $old_base . $mod;
                $new_dir = $new_base . $mod;
                if (is_dir($old_dir)) {
                    if (!is_dir($new_dir)) { @mkdir($new_dir, 0755, true); }
                    $files = array_diff(scandir($old_dir), ['.', '..']);
                    $moved = 0;
                    foreach ($files as $f) {
                        if (@rename($old_dir . '/' . $f, $new_dir . '/' . $f)) { $moved++; }
                    }
                    $log[] = "📁 uploads/{$mod}/ → uploads/{$school_code}/{$mod}/ — {$moved}টা ফাইল সরানো হয়েছে";
                } else {
                    $log[] = "⏭ uploads/{$mod}/ পাওয়া যায়নি, স্কিপ করা হলো";
                }
            }
        }

        // ধাপ ২: DB কলামগুলো আপডেট (idempotent — আগে থেকে নতুন prefix থাকলে ছোঁয়া হবে না)
        if (!$errors && $tenant_pdo) {
            $col_map = [
                'admissions'               => ['photo_path' => 'students', 'marksheet_path' => 'students'],
                'institute_settings'       => ['favicon_path' => 'institute', 'banner_path' => 'institute', 'logo_path' => 'institute'],
                'sliders'                  => ['image_path' => 'website'],
                'gallery_items'            => ['image_path' => 'website'],
                'branches'                 => ['signature_path' => 'branch'],
                'students'                 => ['photo_path' => 'students', 'father_photo_path' => 'students', 'mother_photo_path' => 'students'],
                'employees'                => ['photo_path' => 'employees'],
                'transfer_certificate_config' => ['custom_template_path' => 'certificate_templates'],
                'id_card_templates'        => ['image_path' => 'id_card_templates'],
                'id_card_settings'         => ['front_template_path' => 'id_card_templates', 'back_template_path' => 'id_card_templates'],
            ];

            foreach ($col_map as $table => $cols) {
                // টেবিল না থাকলে স্কিপ (কিছু টেবিল প্রথম ব্যবহারের আগ পর্যন্ত তৈরিই হয় না)
                $exists = $tenant_pdo->query("SHOW TABLES LIKE " . $tenant_pdo->quote($table))->fetch();
                if (!$exists) { $log[] = "⏭ টেবিল `{$table}` এখনো তৈরি হয়নি, স্কিপ করা হলো"; continue; }

                foreach ($cols as $col => $mod) {
                    $old_prefix = "uploads/{$mod}/";
                    $new_prefix = "uploads/{$school_code}/{$mod}/";
                    try {
                        $stmt = $tenant_pdo->prepare(
                            "UPDATE `{$table}` SET `{$col}` = CONCAT(?, SUBSTRING(`{$col}`, LENGTH(?) + 1))
                             WHERE `{$col}` LIKE CONCAT(?, '%') AND `{$col}` NOT LIKE CONCAT(?, '%')"
                        );
                        $stmt->execute([$new_prefix, $old_prefix, $old_prefix, $new_prefix]);
                        $n = $stmt->rowCount();
                        if ($n > 0) { $log[] = "✏️ `{$table}`.`{$col}` — {$n}টা row আপডেট হয়েছে"; }
                    } catch (PDOException $e) {
                        $log[] = "⚠️ `{$table}`.`{$col}` আপডেট করা যায়নি: " . $e->getMessage();
                    }
                }
            }
        }

        // ধাপ ৩: master DB তে রেজিস্টার
        if (!$errors) {
            $stmt = $pdo_master->prepare(
                'INSERT INTO tenants (school_name, school_code, subdomain, db_host, db_name, db_user, db_pass, plan, status)
                 VALUES (?,?,?,?,?,?,?,\'Standard\',\'Active\')'
            );
            $stmt->execute([$school_name, $school_code, $school_code, $db_host, $db_name, $db_user, $db_pass]);
            logActivity($pdo_master, (int) $pdo_master->lastInsertId(), 'tenant_migrated', $school_name);
            $log[] = "✅ Master DB তে tenant হিসেবে রেজিস্টার হয়ে গেছে (school_code: {$school_code})";
            $done = true;
        }
    }
}
$pageTitle = 'পুরনো স্কুল মাইগ্রেট করো';
$pageIcon  = '🔁';
$activeKey = 'migrate_tenant';
require_once __DIR__ . '/_layout_top.php';
?>
    <style>pre.log { background: var(--sidebar-bg); color: #a7f3d0; padding: 16px; border-radius: 8px; font-size: 13px; max-height: 400px; overflow:auto; border:1px solid var(--sidebar-border); }</style>
    <div style="max-width: 720px;">

        <?php if ($done): ?>
            <div class="alert alert-success"><b>✅ মাইগ্রেশন সম্পন্ন হয়েছে!</b></div>
            <pre class="log"><?php echo htmlspecialchars(implode("\n", $log)); ?></pre>
            <div class="alert alert-warning mt-3">
                এখন <code>?school=<?php echo htmlspecialchars($school_code); ?></code> দিয়ে টেস্ট করো, সব ছবি/লোগো ঠিকমতো দেখাচ্ছে কিনা চেক করো।
                সব ঠিক থাকলে পুরনো খালি <code>uploads/students/</code>, <code>uploads/institute/</code> ইত্যাদি ফোল্ডার মুছে ফেলতে পারো।
            </div>
            <a href="dashboard.php" class="btn btn-primary">Dashboard এ যাও</a>
        <?php else: ?>

            <?php foreach ($errors as $err): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div>
            <?php endforeach; ?>
            <?php if ($log): ?>
                <pre class="log mb-3"><?php echo htmlspecialchars(implode("\n", $log)); ?></pre>
            <?php endif; ?>

            <div class="alert alert-danger">
                ⚠️ এটা একবারই রান করার স্ক্রিপ্ট এবং ফাইল সরায় + DB আপডেট করে — রান করার আগে
                পুরো প্রজেক্ট ফোল্ডার আর DB এর একটা full backup নিয়ে রাখো।
            </div>

            <div class="card-custom p-4">
                <form method="POST">
                    <?php echo csrf_field(); ?>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">স্কুলের নাম *</label>
                        <input type="text" name="school_name" class="form-control" required
                               value="<?php echo htmlspecialchars($_POST['school_name'] ?? 'Your School Name'); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">School Code (subdomain হবে, শুধু ছোট হাতের অক্ষর/সংখ্যা) *</label>
                        <input type="text" name="school_code" class="form-control" required
                               placeholder="school1" value="<?php echo htmlspecialchars($_POST['school_code'] ?? ''); ?>">
                    </div>

                    <hr>
                    <h6 class="fw-bold mb-3">এই স্কুলের বর্তমান (পুরনো) DB credential — config/db.php থেকে কপি করো</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">DB Host</label>
                            <input type="text" name="db_host" class="form-control" value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">DB Name *</label>
                            <input type="text" name="db_name" class="form-control" required value="<?php echo htmlspecialchars($_POST['db_name'] ?? 'your_db_name'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">DB User *</label>
                            <input type="text" name="db_user" class="form-control" required value="<?php echo htmlspecialchars($_POST['db_user'] ?? 'your_db_name'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">DB Password *</label>
                            <div class="input-group">
                                <input type="password" id="pw_migrate_db_pass" name="db_pass" class="form-control" required value="<?php echo htmlspecialchars($_POST['db_pass'] ?? ''); ?>">
                                <button type="button" class="btn btn-outline-secondary" onclick="const f=document.getElementById('pw_migrate_db_pass'); f.type = f.type==='password' ? 'text' : 'password';">👁</button>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Project Root ফোল্ডারের সার্ভার পাথ</label>
                        <input type="text" name="project_root" class="form-control" value="<?php echo htmlspecialchars($_POST['project_root'] ?? realpath(__DIR__ . '/..')); ?>">
                        <small class="text-muted">যেখানে uploads/ ফোল্ডার আছে (এই master/ ফোল্ডারের এক ধাপ উপরে হলে খালি রেখে দাও)</small>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="confirm" id="confirm" required>
                        <label class="form-check-label small fw-bold" for="confirm">আমি প্রজেক্ট ফোল্ডার ও DB এর ব্যাকআপ নিয়ে রেখেছি</label>
                    </div>

                    <button type="submit" class="btn btn-danger fw-bold w-100 py-2">মাইগ্রেশন রান করো</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
