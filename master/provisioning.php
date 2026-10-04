<?php
require_once __DIR__.'/_guard.php';
masterRequireAccess($pdo_master,'create_tenant','add');
require_once __DIR__.'/../config/provisioning.php';erp_provision_schema($pdo_master);
$jobs=$pdo_master->query('SELECT id,school_code,status,error,created_at,updated_at FROM provision_jobs ORDER BY id DESC LIMIT 100')->fetchAll();
$pageTitle='School setup status';$pageIcon='';$activeKey='create_tenant';
require __DIR__.'/_layout_top.php';
?>
<div class="card-custom p-4"><h2>School setup</h2><p>New school databases are prepared by the provisioning worker. Existing school data is never initialized again.</p><p>A completed setup prepares the school account and records. The operator must configure a dedicated school runtime, private upload/session storage, and verified DNS/HTTPS before sharing the portal address. Database preparation alone does not publish the school.</p><a href="create_tenant.php">Create a school</a>
<table class="table"><thead><tr><th>Job</th><th>School</th><th>Status</th><th>Updated</th><th>Details</th></tr></thead><tbody>
<?php foreach($jobs as $job): ?><tr><?php foreach(['id','school_code','status','updated_at','error'] as $key): ?><td><?php echo erp_e($job[$key]); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div>
<?php require __DIR__.'/_layout_bottom.php'; ?>
