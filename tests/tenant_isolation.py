# Local-only regression for a web runtime that never receives platform credentials.
from pathlib import Path
import subprocess,urllib.request,urllib.error,time,re,http.cookiejar,urllib.parse
root=Path(__file__).resolve().parents[1];dc=['docker','compose','--env-file','deployment/.env.local','-f','deployment/compose.local.yml']
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*a,**k):return None
op=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect(),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def get(path,host='boundfixture.schoolmng.localhost',data=None):
 req=urllib.request.Request('http://127.0.0.1:8089'+path,headers={'Host':host},data=urllib.parse.urlencode(data).encode() if data else None)
 try:r=op.open(req,timeout=15)
 except urllib.error.HTTPError as e:r=e
 return r.code,r.read()
subprocess.run(dc+['up','-d','isolated'],cwd=root,check=True,capture_output=True)
try:
 for n in range(30):
  try:
   if get('/health.php')[0]==200:break
  except (OSError,urllib.error.URLError):pass
  time.sleep(1)
 assert get('/health.php')[0]==200
 assert get('/')[0]==200
 assert get('/master/')[0]==404
 assert get('/','schoolmng.localhost')[0]==421
 assert get('/','demo.schoolmng.localhost')[0]==421
 assert get('/','other.schoolmng.localhost')[0]==421
 php="""<?php if(getenv('APP_ENV')!=='local')exit(91);require 'config/bound_tenant.php';$t=erp_bound_tenant();
foreach(['MASTER_DB_PASS','ERP_PROVISION_PASS','DEMO_PROVISION_PASS','DEMO_TOKEN_SECRET'] as $key)if(getenv($key)!==false)throw new RuntimeException('Other runtime secret exposed');
$p=new PDO('mysql:host='.$t['db_host'].';dbname='.$t['db_name'],$t['db_user'],$t['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$p->query('SELECT COUNT(*) FROM students');
foreach(['SELECT * FROM school_master_db.tenants','SELECT * FROM school_demo_control.demo_allocator','SELECT * FROM mysql.user','CREATE DATABASE escaped_school','DROP DATABASE sc_boundfixture'] as $sql){try{$p->query($sql);throw new RuntimeException('Cross-boundary SQL unexpectedly allowed');}catch(PDOException $e){}}
echo 'PASS school SQL scope and no platform/demo secrets in the school runtime';"""
 r=subprocess.run(dc+['exec','-T','isolated','php'],input=php.encode(),capture_output=True,cwd=root,timeout=30);assert r.returncode==0,r.stderr.decode()[-300:];print(r.stdout.decode())
 env=dict(l.split('=',1) for l in (root/'deployment/.env.local').read_text().splitlines() if '=' in l)
 status,raw=get('/admin/');csrf=re.search(rb'name="csrf_token"[^>]*value="([^"]+)"',raw)[1].decode();captcha=''.join(x.decode() for x in re.findall(rb'<text[^>]*>([0-9])</text>',raw))
 login_status,login_raw=get('/admin/',data={'login':'1','csrf_token':csrf,'username':'bound_owner','password':env['LOCAL_ADMIN_PASSWORD'],'verification_code':captcha,'branch_id':'1'})
 assert login_status==302,(login_status,re.findall(rb'<div class="alert[^>]*>(.*?)</div>',login_raw,re.S))
 assert get('/admin/index.php?page=dashboard')[0]==200
 print('PASS bound school website/admin login, host spoofing denial and master portal exclusion')
finally:subprocess.run(dc+['stop','isolated'],cwd=root,check=True,capture_output=True)
