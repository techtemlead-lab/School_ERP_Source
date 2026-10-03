<?php
require_once __DIR__.'/_guard.php';
require_once __DIR__.'/../config/demo_requests.php';
if (!in_array($_SESSION['master_role']??'',['superadmin','admin'],true)) erp_stop(403,'This page is available to ERP administrators.');
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST' && csrf_verify($_POST['csrf_token']??null) && in_array($_POST['status']??'',['New','Contacted','Closed'],true)) {
    $pdo_master->prepare('UPDATE demo_requests SET status=? WHERE id=?')->execute([$_POST['status'],(int)($_POST['id']??0)]);
    header('Location: demo_requests.php');exit;
}
$rows=$pdo_master->query('SELECT * FROM demo_requests ORDER BY id DESC LIMIT 200')->fetchAll();
$pageTitle='Dedicated demo requests';$activeKey='demo_requests';require __DIR__.'/_layout_top.php';
?>
<div class="card-custom p-4"><p>Latest 200 enquiries. Contact details are visible only to authorised ERP administrators.</p><div class="table-responsive"><table class="table"><thead><tr><th>School / contact</th><th>Requirements</th><th>Status</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><strong><?= erp_e($row['school_name']) ?></strong><br><?= erp_e($row['contact_name']) ?><br><?= erp_e($row['email']) ?><br><?= erp_e($row['phone']) ?><br><small><?= erp_e($row['created_at']) ?></small></td><td><?= (int)$row['student_count'] ?> students · <?= erp_e($row['plan']?:'Help choose') ?><p style="white-space:pre-wrap"><?= erp_e($row['requirements']) ?></p></td><td><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><select name="status" class="form-select"><?php foreach(['New','Contacted','Closed'] as $status): ?><option <?= $row['status']===$status?'selected':'' ?>><?= $status ?></option><?php endforeach; ?></select><button class="btn btn-primary btn-sm mt-2">Save</button></form></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="3">No requests yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php require __DIR__.'/_layout_bottom.php'; ?>
