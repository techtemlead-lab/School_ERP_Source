<?php
declare(strict_types=1);
require_once __DIR__.'/runtime.php';
/** One tenant runtime receives only its own SQL credentials and read-only profile. */
function erp_bound_tenant(): array {
 if(erp_env('ERP_ROLE')!=='tenant')throw new RuntimeException('Not a bound school runtime');
 $file=erp_env('ERP_TENANT_PROFILE','/run/erp/tenant.json');
 try {$profile=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);}catch(Throwable $e){erp_stop(503,'School configuration is temporarily unavailable.');}
 $code=erp_env('ERP_TENANT_CODE');
 if(!is_array($profile)||($profile['school_code']??'')!==$code)erp_stop(503,'School configuration is temporarily unavailable.');
 if(!empty($profile['deleted_at']))erp_stop(404,'School is unavailable.');
 if(($profile['status']??'')!=='Active')erp_stop(403,'This school subscription is unavailable. Contact the ERP team.');
 $profile['db_host']=erp_env('TENANT_DB_HOST');$profile['db_name']=erp_env('TENANT_DB_NAME');
 $profile['db_user']=erp_env('TENANT_DB_USER');$profile['db_pass']=erp_env('TENANT_DB_PASS');
 if(!$profile['db_host']||!$profile['db_name']||!$profile['db_user']||!$profile['db_pass'])erp_stop(503,'School database configuration is unavailable.');
 return $profile;
}
