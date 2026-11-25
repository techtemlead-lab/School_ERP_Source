<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../config/runtime.php';
$code=$argv[1]??'';if(!preg_match('/^[a-z][a-z0-9]{2,29}$/',$code))throw new RuntimeException('School code required');
$p=new PDO('mysql:host='.erp_env('ERP_PROVISION_HOST').';dbname='.erp_env('MASTER_DB_NAME'),erp_env('ERP_PROVISION_USER'),erp_env('ERP_PROVISION_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$hasBranding=(bool)$p->query("SHOW COLUMNS FROM tenants LIKE 'branding_policy_json'")->fetch();
$cols='id,school_code,school_name,subdomain,custom_domain,plan,status,contact_name,contact_phone,contact_email,'.($hasBranding?'branding_policy_json,':'').'trial_ends_at,subscription_ends_at,deleted_at';
$q=$p->prepare("SELECT {$cols} FROM tenants WHERE school_code=?");$q->execute([$code]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('School is not registered');if(!$hasBranding){$row['branding_policy_json']=null;}echo json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
