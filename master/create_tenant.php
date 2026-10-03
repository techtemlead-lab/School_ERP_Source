<?php
require_once __DIR__.'/_guard.php';
require_once __DIR__.'/../config/provisioning.php';
masterRequireAccess($pdo_master,'create_tenant','add');
$errors=[];$success=false;$manual_sql=null;$queued=false;
$school_name=$school_code=$super_admin_username='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 if(!csrf_verify($_POST['csrf_token']??null))$errors[]='Invalid security token. Reload the form.';
 else try {
  $data=erp_tenant_payload($_POST);
  $school_name=$data['school_name'];$school_code=$data['school_code'];$subdomain=$school_code;$super_admin_username=$data['super_admin_username'];
  if(($_POST['db_mode']??'auto')==='auto'){
   $jobId=erp_queue_tenant($pdo_master,$data);$queued=true;$success=true;
   logActivity($pdo_master,null,'tenant_queued',$school_code.' job '.$jobId);
  }else{
   foreach(['db_name','db_user'] as $key)if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{2,63}$/',(string)($data[$key]??'')))throw new RuntimeException('Use a valid database and user name.');
   $data['db_host']=trim((string)($data['db_host']??''));
   if($data['db_host']!==erp_env('ERP_TENANT_DB_HOST',erp_env('MASTER_DB_HOST','db')))throw new RuntimeException('Use the configured school database server.');
   if(strlen((string)($data['db_pass']??''))<12)throw new RuntimeException('Use a database password of at least 12 characters.');
   $q=$pdo_master->prepare('SELECT id FROM tenants WHERE school_code=? OR db_name=? OR custom_domain=?');$q->execute([$school_code,$data['db_name'],$data['custom_domain']]);if($q->fetch())throw new RuntimeException('School or database already registered.');
   $school=new PDO('mysql:host='.$data['db_host'].';dbname='.$data['db_name'].';charset=utf8mb4',$data['db_user'],$data['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
   erp_install_empty_school($school,$data);$id=erp_register_school($pdo_master,$data);logActivity($pdo_master,$id,'tenant_created',$school_name);$success=true;
  }
 }catch(Throwable $e){error_log('School setup request failed: '.get_class($e));$errors[]=$e instanceof RuntimeException && !($e instanceof PDOException)?$e->getMessage():'School setup failed. Verify the database details; existing records were not imported over.';}
}
$pageTitle = 'নতুন স্কুল যোগ করুন';
$pageIcon  = '➕';
$activeKey = 'create_tenant';
require_once __DIR__ . '/_layout_top.php';
?>
    <div style="max-width: 720px;">

        <?php if ($success): ?>
            <div class="alert alert-success">
                <h5>School setup <?php echo $queued?'queued':'complete'; ?></h5>
                <p><?php echo erp_e($school_name); ?></p>
                <p>School address: <code><?php echo erp_e($school_code.'.'.erp_primary_host()); ?></code></p>
                <?php if($queued): ?><a href="provisioning.php">View setup status</a><?php endif; ?>
                <p>DNS and TLS must be configured for the school address before public access.</p>
            </div>
            <a href="create_tenant.php" class="btn btn-primary">আরেকটা স্কুল যোগ করো</a>
            <a href="dashboard.php" class="btn btn-outline-secondary">Dashboard এ যাও</a>
        <?php else: ?>

            <?php foreach ($errors as $err): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div>
            <?php endforeach; ?>
            <?php if ($manual_sql): ?>
                <div class="alert alert-warning">
                    <b>ম্যানুয়ালি রান করার SQL:</b>
                    <pre class="mb-0 mt-2" style="white-space:pre-wrap;"><?php echo htmlspecialchars($manual_sql); ?></pre>
                </div>
            <?php endif; ?>

            <div class="card-custom p-4">
                <form method="POST">
                    <?php echo csrf_field(); ?>

                    <h6 class="fw-bold mb-3">স্কুলের তথ্য</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">স্কুলের নাম *</label>
                            <input type="text" name="school_name" class="form-control" required value="<?php echo htmlspecialchars($_POST['school_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Subdomain Code (শুধু ছোট হাতের অক্ষর/সংখ্যা)</label>
                            <input type="text" name="school_code" class="form-control" placeholder="e.g. school1" value="<?php echo htmlspecialchars($_POST['school_code'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Custom Domain (ঐচ্ছিক)</label>
                            <input type="text" name="custom_domain" class="form-control" placeholder="erp.clientschool.com" value="<?php echo htmlspecialchars($_POST['custom_domain'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Plan</label>
                            <select name="plan" class="form-select">
                                <option>Trial</option><option>Basic</option><option>Standard</option><option>Premium</option>
                            </select>
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3">যোগাযোগ (স্কুলের দায়িত্বশীল)</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">নাম</label>
                            <input type="text" name="contact_name" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">ফোন</label>
                            <input type="tel" name="contact_phone" class="form-control phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="১১ ডিজিটের মোবাইল নম্বর দিন">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">ইমেইল</label>
                            <input type="email" name="contact_email" class="form-control">
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3">Super Admin অ্যাকাউন্ট</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">User ID (Username / Phone / Email) *</label>
                            <input type="text" name="super_admin_username" class="form-control" required placeholder="e.g. 01700000000 বা admin@school.com" value="<?php echo htmlspecialchars($_POST['super_admin_username'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Password *</label>
                            <div class="input-group">
                                <input type="password" id="pw_super_admin" name="super_admin_password" minlength="12" class="form-control" required minlength="6" placeholder="কমপক্ষে ৬ ক্যারেক্টার" value="<?php echo htmlspecialchars($_POST['super_admin_password'] ?? ''); ?>">
                                <button type="button" class="btn btn-outline-secondary" onclick="const f=document.getElementById('pw_super_admin'); f.type = f.type==='password' ? 'text' : 'password';">👁</button>
                            </div>
                        </div>
                        <div class="col-12">
                            <small class="text-muted">এই User ID + Password দিয়েই স্কুলের অ্যাডমিন প্যানেলে (admin/index.php) Super Admin হিসেবে লগইন করা যাবে। এখান থেকেই স্কুলের বাকি সব User (Admin/Teacher/Student ইত্যাদি) তৈরি করা যাবে।</small>
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3">Database তথ্য</h6>
                    <?php $db_mode_val = $db_mode ?? 'manual'; ?>
                    <div class="mb-3">
                        <label class="form-label small fw-bold d-block mb-2">DB তৈরি করার পদ্ধতি</label>
                        <div class="d-flex gap-2 flex-wrap">
                            <input type="radio" name="db_mode" id="db_mode_auto" value="auto" class="btn-check" <?php echo $db_mode_val === 'auto' ? 'checked' : ''; ?>>
                            <label for="db_mode_auto" class="px-3 py-2 rounded" style="cursor:pointer; border:2px solid var(--sidebar-border, #333); background: var(--sidebar-bg, #1a1a1a); font-size: 13px;">
                                ⚙️ <b>Auto</b>
                            </label>

                            <input type="radio" name="db_mode" id="db_mode_manual" value="manual" class="btn-check" <?php echo $db_mode_val !== 'auto' ? 'checked' : ''; ?>>
                            <label for="db_mode_manual" class="px-3 py-2 rounded" style="cursor:pointer; border:2px solid var(--sidebar-border, #333); background: var(--sidebar-bg, #1a1a1a); font-size: 13px;">
                                ✋ <b>Manual</b> <span class="badge bg-success" style="font-size:10px;">শেয়ার্ড হোস্টিং</span>
                            </label>
                        </div>
                        <small class="text-muted d-block mt-1">Auto: স্ক্রিপ্ট নিজে DB+User বানানোর চেষ্টা করবে (VPS-এ কাজ করে)। Manual: cPanel থেকে আগে DB+User বানিয়ে রাখলে শুধু schema import হবে (শেয়ার্ড হোস্টিং-এ প্রযোজ্য)।</small>
                    </div>
                    <style>
                        .btn-check:checked + label { border-color: #7c3aed !important; box-shadow: 0 0 0 1px #7c3aed; }
                    </style>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">DB Host</label>
                            <input type="text" name="db_host" class="form-control" value="<?php echo htmlspecialchars($_POST['db_host'] ?? erp_env('ERP_TENANT_DB_HOST',erp_env('MASTER_DB_HOST','db'))); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">DB Name *</label>
                            <input type="text" name="db_name" class="form-control" placeholder="dbprefix_school1" value="<?php echo htmlspecialchars($_POST['db_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">DB User *</label>
                            <input type="text" name="db_user" class="form-control" placeholder="dbprefix_school1" value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">DB Password *</label>
                            <div class="input-group">
                                <input type="password" id="pw_db_pass" name="db_pass" class="form-control" value="<?php echo htmlspecialchars($_POST['db_pass'] ?? ''); ?>">
                                <button type="button" class="btn btn-outline-secondary" onclick="const f=document.getElementById('pw_db_pass'); f.type = f.type==='password' ? 'text' : 'password';">👁</button>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success fw-bold w-100 py-2">স্কুল তৈরি করো</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
<script>
// ফোন নম্বর ফিল্ড (.phone-11 ক্লাস): টাইপ করার সাথে সাথেই সংখ্যা ছাড়া অন্য কিছু নেয় না, ১১ ডিজিটের বেশি হলে কেটে দেয়
document.querySelectorAll('.phone-11').forEach(function (input) {
    // প্রতিটা ফিল্ডের ঠিক পরে একটা এরর মেসেজ বসানো হচ্ছে (আগে থেকে না থাকলে)
    let errEl = input.nextElementSibling;
    if (!errEl || !errEl.classList.contains('phone11-err')) {
        errEl = document.createElement('div');
        errEl.className = 'small text-danger mt-1 d-none phone11-err';
        errEl.textContent = 'মোবাইল নম্বর অবশ্যই ১১ ডিজিটের হতে হবে।';
        input.insertAdjacentElement('afterend', errEl);
    }

    function checkPhone11() {
        let digits = input.value.replace(/\D/g, '');
        if (digits.length > 11) digits = digits.slice(0, 11);
        input.value = digits;
        // খালি রাখা যাবে (আবশ্যক না হলে) — কিন্তু কিছু লিখলে অবশ্যই ১১ ডিজিট হতে হবে,
        // ১১ ডিজিট হয়ে গেলেই মেসেজ সাথে সাথে (রিলোড ছাড়া) সরে যাবে
        const invalid = digits.length > 0 && digits.length !== 11;
        errEl.classList.toggle('d-none', !invalid);
        input.classList.toggle('is-invalid', invalid);
    }
    input.addEventListener('input', checkPhone11);
    input.addEventListener('blur', checkPhone11);
});
</script>
