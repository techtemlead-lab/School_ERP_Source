<?php
// Every master handler is authorized before it can render or mutate records.
$route=basename($_SERVER['SCRIPT_NAME'] ?? '');
$write=($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET';
$rules=[
 'create_tenant.php'=>['create_tenant','add'], 'provisioning.php'=>['create_tenant','add'],
 'edit_tenant.php'=>['client_list','edit'], 'toggle_status.php'=>['client_list','edit'],
 'delete_tenant.php'=>['client_list','edit'], 'restore_tenant.php'=>['trash','edit'],
 'purge_tenant.php'=>['trash','edit'], 'deploy.php'=>['deploy','edit'],
 'migrate_existing_tenant.php'=>['migrate_tenant','add'],
 'domain_save.php'=>['domain_tracker','edit'], 'domain_delete.php'=>['domain_tracker','edit'],
 'settings.php'=>['settings','edit'],
 'billing.php'=>['billing',$write?'edit':'view'],
 'domain_tracker.php'=>['domain_tracker',$write?'edit':'view'],
 'client_list.php'=>['client_list',$write?'edit':'view'],
 'trash.php'=>['trash',$write?'edit':'view'], 'activity.php'=>['activity','view'],
 'dashboard.php'=>['dashboard','view'], 'invoice_view.php'=>['billing','view'],
 'invoice_template.php'=>['billing','view'],
];
if(isset($rules[$route])) masterRequireAccess($pdo_master,...$rules[$route]);
elseif($write && !in_array($route,['change_password.php','logout.php','auto_logout.php','demo_requests.php','users.php'],true)) {
    if(($_SESSION['master_role']??'')!=='superadmin')erp_stop(403,'This master action requires a superadmin.');
}
