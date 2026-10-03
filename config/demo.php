<?php
declare(strict_types=1);
require_once __DIR__ . '/runtime.php';
function demo_hour(?int $time=null): int { return intdiv($time ?? time(),3600); }
function demo_reset_at(?int $time=null): int { return (demo_hour($time)+1)*3600; }
function demo_control(): PDO {
    return new PDO('mysql:host='.erp_env('DEMO_DB_HOST','db').';dbname='.erp_env('DEMO_CONTROL_DB','school_demo_control').';charset=utf8mb4',
        erp_env('DEMO_CONTROL_USER'),erp_env('DEMO_CONTROL_PASS'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function demo_token_hash(string $token): string {
    $secret=erp_env('DEMO_TOKEN_SECRET');
    if (strlen($secret)<32) throw new RuntimeException('DEMO_TOKEN_SECRET must contain at least 32 characters');
    return hash_hmac('sha256',$token,$secret);
}
function demo_allocated_sandbox(PDO $control,string $hash,int $hour): ?array {
    $stmt=$control->prepare('SELECT * FROM demo_sandboxes WHERE token_hash=? AND hour_key=? AND ready=1');
    $stmt->execute([$hash,$hour]); return $stmt->fetch() ?: null;
}
function demo_sandbox(): array {
    erp_session_start(); $hour=demo_hour();
    if (($_SESSION['demo_hour'] ?? null)!==$hour) {
        $stale=isset($_SESSION['demo_hour']);
        $_SESSION=['demo_hour'=>$hour]; session_regenerate_id(true);
        if ($stale && (($_SERVER['REQUEST_METHOD']??'GET')!=='GET' || isset($_GET['delete']) || isset($_GET['reset']))) erp_stop(409,'The demo has reset. Reload before making further changes.');
    }
    $token=$_COOKIE['erp_demo_visitor'] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/',$token)) {
        $token=bin2hex(random_bytes(32));
        setcookie('erp_demo_visitor',$token,['expires'=>demo_reset_at(),'path'=>'/','secure'=>erp_https(),'httponly'=>true,'samesite'=>'Lax']);
        $_COOKIE['erp_demo_visitor']=$token;
    }
    $control=demo_control();$hash=demo_token_hash($token);
    $sandbox=demo_allocated_sandbox($control,$hash,$hour);
    if (!$sandbox) {
        $control->beginTransaction();
        try {
            $control->query('SELECT id FROM demo_allocator WHERE id=1 FOR UPDATE')->fetch();
            $sandbox=demo_allocated_sandbox($control,$hash,$hour);
            if (!$sandbox) {
                $ipHash=demo_token_hash('ip:'.($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                $stmt=$control->prepare('SELECT COUNT(*) FROM demo_sandboxes WHERE ip_hash=? AND hour_key=?');$stmt->execute([$ipHash,$hour]);
                if ((int)$stmt->fetchColumn()>=(int)erp_env('DEMO_IP_LIMIT','10')) throw new RuntimeException('Demo session limit reached. Please try again after the next hourly reset.');
                $stmt=$control->prepare('SELECT * FROM demo_sandboxes WHERE hour_key=? AND token_hash IS NULL AND ready=1 ORDER BY id LIMIT 1 FOR UPDATE');
                $stmt->execute([$hour]);$sandbox=$stmt->fetch();
                if (!$sandbox) throw new RuntimeException('All demo sessions are busy. Please try again in a few minutes or request a dedicated demo.');
                $control->prepare('UPDATE demo_sandboxes SET token_hash=?,ip_hash=? WHERE id=?')->execute([$hash,$ipHash,$sandbox['id']]);
            }
            $control->commit();
        } catch (Throwable $e) { $control->rollBack();throw $e; }
    }
    if (!preg_match('/^ds_[a-f0-9]{32}$/',$sandbox['db_name']) || demo_hour()!==$hour) throw new RuntimeException('The demo has reset. Reload the page for a fresh session.');
    $GLOBALS['demo_sandbox']=$sandbox;return $sandbox;
}
function demo_guard_request(): void {
    $path=parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH) ?: '/';
    $page=(string)($_GET['page'] ?? basename($path,'.php'));
    $sensitive=['users_list','user_permissions','sms_gateway','sms_settings','whatsapp_settings','admission_payment_settings','whatsapp_api','device_settings','security_settings','security_active_sessions','youtube_settings','live_class_settings','gps_settings','transport_gps_settings','database_reset','traccar_test','traccar_devices','device_sync','live_class_youtube_connect'];
    $write=($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET' || (bool)array_intersect(['delete','reset','action','disconnect','sync','test'],array_keys($_GET));
    if ($write && in_array($page,$sensitive,true)) erp_stop(403,'Account, security and integration settings are read-only in the public demo.');
    if ($write) {
        $scan=function(array $data) use (&$scan): void {
            foreach ($data as $key=>$value) {
                if (is_array($value)) { $scan($value);continue; }
                if (preg_match('/password|passwd|reset_pass|change_pass/i',(string)$key) && (string)$value!=='') erp_stop(403,'Password changes are disabled for all public demo accounts. Use the one-click portal links.');
            }
        };$scan($_POST);$scan($_GET);
    }
    $used=0;$directory=dirname(__DIR__).'/uploads/demo/'.$GLOBALS['demo_sandbox']['db_name'];
    if (is_dir($directory)) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS)) as $file) $used+=$file->getSize();
    $inspect=function(array $file) use (&$inspect,&$used): void {
        if (is_array($file['name'] ?? null)) {
            foreach ($file['name'] as $key=>$name) $inspect(['name'=>$name,'tmp_name'=>$file['tmp_name'][$key],'error'=>$file['error'][$key],'size'=>$file['size'][$key]]);
            return;
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return;
        if (($file['error'] ?? 0)!==UPLOAD_ERR_OK) erp_stop(413,'The upload could not be accepted.');
        $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
        $allowed=['jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'webp'=>['image/webp'],'pdf'=>['application/pdf'],'csv'=>['text/plain','text/csv','application/csv'],'txt'=>['text/plain'],'xlsx'=>['application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']];
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$used+=(int)$file['size'];
        if (!isset($allowed[$ext]) || !in_array($mime,$allowed[$ext],true) || $file['size']>2*1024*1024 || $used>20*1024*1024) erp_stop(413,'Use JPG, PNG, WebP, PDF, CSV, TXT or XLSX files up to 2 MB. A demo session allows 20 MB of uploads.');
    };foreach ($_FILES as $file) $inspect($file);
}
function demo_auto_login(PDO $pdo): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET') return;
    $path=parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH) ?: '/';
    if (!preg_match('#^/(admin|teacher|student)(?:/|/index\.php)?$#',$path,$match) || isset($_GET['page']) || isset($_GET['logout'])) return;
    $role=$match[1];
    if ($role==='admin' && empty($_SESSION['admin_logged'])) {
        require_once __DIR__.'/security_helpers.php';session_regenerate_id(true);
        $_SESSION['admin_logged']=true;$_SESSION['admin_user']='demo_admin';$_SESSION['is_super_admin']=true;$_SESSION['user_role']='Super Admin';
        $_SESSION['active_branch_id']=1;$_SESSION['institute_branch']='Shapla Model School & College';
        sec_start_session($pdo,'demo_admin','Super Admin');
    } elseif ($role==='teacher' && empty($_SESSION['teacher_logged'])) {
        $row=$pdo->query("SELECT * FROM employees WHERE employee_id='DEMO-T001'")->fetch();
        if (!$row) erp_stop(503,'The demo teacher was removed in this session. Wait for the hourly reset.');
        session_regenerate_id(true);$_SESSION['teacher_logged']=true;$_SESSION['teacher_id']=(int)$row['id'];
        $_SESSION['teacher_employee_id']=$row['employee_id'];$_SESSION['teacher_name']=$row['name'];$_SESSION['teacher_photo']=$row['photo_path'];
    } elseif ($role==='student' && empty($_SESSION['student_logged'])) {
        $row=$pdo->query("SELECT * FROM students WHERE student_id='DEMO-S001'")->fetch();
        if (!$row) erp_stop(503,'The demo student was removed in this session. Wait for the hourly reset.');
        session_regenerate_id(true);$_SESSION['student_logged']=true;$_SESSION['student_pk']=(int)$row['id'];$_SESSION['student_code']=$row['student_id'];$_SESSION['student_name']=$row['name_en'];
    }
}
function demo_prepare(): array {
    try {
        $row=demo_sandbox();demo_guard_request();
        if (preg_match('#^/(admin|teacher|student)(?:/|$)#',parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH),$role)) $_SESSION['demo_active_role']=$role[1];
        return ['school_code'=>$row['db_name'],'school_name'=>'Shapla Model School & College','status'=>'Active','db_host'=>erp_env('DEMO_DB_HOST','db'),'db_name'=>$row['db_name'],'db_user'=>$row['db_user'],'db_pass'=>$row['db_pass'],'is_demo'=>true];
    } catch (Throwable $e) { error_log('Demo allocation failed: '.$e->getMessage());erp_stop(503,$e instanceof RuntimeException ? $e->getMessage() : 'The public demo is temporarily unavailable. Please try again shortly.'); }
}
