# Local-only security, provisioning and real-school upload regression checks.
from pathlib import Path
import secrets,json,re,time,subprocess,http.cookiejar,urllib.request,urllib.error,urllib.parse,io,zipfile
root=Path(__file__).resolve().parents[1]
compose=['docker','compose','--env-file','deployment/.env.local','-f','deployment/compose.local.yml']
env=dict(l.split('=',1) for l in (root/'deployment/.env.local').read_text().splitlines() if '=' in l)
def php(code,service='main'):
 guard="<?php if(getenv('APP_ENV')!=='local')exit(91);"
 r=subprocess.run(compose+['exec','-T',service,'php'],input=(guard+code).encode(),capture_output=True,cwd=root,timeout=120)
 assert r.returncode==0,r.stderr.decode(errors='replace')[-1000:]
 return r.stdout
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*a,**k):return None
class Browser:
 def __init__(self,port=8087,host='schoolmng.localhost'):
  self.port,self.host=port,host;self.op=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect())
 def get(self,path,data=None,headers=None):
  hdr={'Host':self.host+':'+str(self.port)};hdr.update(headers or {})
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
  req=urllib.request.Request('http://127.0.0.1:'+str(self.port)+path,data=data,headers=hdr)
  try:r=self.op.open(req,timeout=120)
  except urllib.error.HTTPError as e:r=e
  return r.code,r.read(),r.headers
def token(raw):return re.search(rb'name="csrf_token"[^>]*value="([^"]+)"',raw)[1].decode()
def captcha(raw):return ''.join(x.decode() for x in re.findall(rb'<text[^>]*>([0-9])</text>',raw))
def login(browser,username,password):
 s,raw,h=browser.get('/master/login.php')
 assert browser.get('/master/login.php',{'csrf_token':token(raw),'login':'1','username':username,'password':password})[0]==302
def check(ok,text):
 assert ok,text
 print('PASS',text)
uids=[];tenant_id=None;code='citest'+secrets.token_hex(5);school_password=secrets.token_hex(16)
try:
 for role in ['viewer','admin']:
  name='regression_'+secrets.token_hex(6);pw=secrets.token_hex(16)
  code_php="require 'config/master_db.php';require 'master/_access.php';ensureUserMenuPermissionsTable($pdo_master);$pdo_master->prepare('INSERT INTO master_users(name,username,password,role,is_active) VALUES(?,?,?,?,1)')->execute(['Temporary regression user',"+json.dumps(name)+",password_hash("+json.dumps(pw)+",PASSWORD_DEFAULT),"+json.dumps(role)+"]);$id=(int)$pdo_master->lastInsertId();"
  if role=='admin':code_php+="foreach(['create_tenant','client_list','deploy','settings','trash','domain_tracker'] as $m)$pdo_master->prepare('INSERT INTO user_menu_permissions(user_id,menu_key,can_view,can_add,can_edit) VALUES(?,?,1,0,0)')->execute([$id,$m]);"
  uid=int(php(code_php+"echo $id;"));uids.append(uid)
  b=Browser();login(b,name,pw);s,raw,h=b.get('/master/change_password.php');csrf=token(raw)
  for path in ['/master/create_tenant.php','/master/deploy.php','/master/settings.php']:
   check(b.get(path)[0]==403,role+' cannot access '+path)
  for path in ['/master/toggle_status.php','/master/delete_tenant.php','/master/purge_tenant.php','/master/domain_save.php']:
   check(b.get(path,{'csrf_token':csrf,'tenant_id':'0','id':'0','new_status':'Suspended'})[0]==403,role+' direct write is denied '+path)
 owner=Browser();login(owner,'owner',env['LOCAL_ADMIN_PASSWORD'])
 s,raw,h=owner.get('/master/create_tenant.php');check(s==200,'authorized school setup form')
 status,raw,h=owner.get('/master/create_tenant.php',{'csrf_token':token(raw),'school_name':'CI Fictional School','school_code':code,'plan':'Trial','db_mode':'auto','super_admin_username':'ci_school_owner','super_admin_password':school_password,'contact_name':'CI Test','contact_phone':'','contact_email':'office@example.invalid'})
 check(status==200 and b'School setup queued' in raw,'automatic provisioning queued')
 tenant=None
 for i in range(30):
  value=php("require 'config/master_db.php';$q=$pdo_master->prepare('SELECT * FROM tenants WHERE school_code=?');$q->execute(["+json.dumps(code)+"]);echo json_encode($q->fetch());")
  tenant=json.loads(value)
  if tenant:break
  time.sleep(2)
 check(bool(tenant),'CLI worker provisions a container-compatible school account');tenant_id=tenant['id']
 connect="$p=new PDO('mysql:host=db;dbname="+tenant['db_name']+";charset=utf8mb4',"+json.dumps(tenant['db_user'])+","+json.dumps(tenant['db_pass'])+",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);"
 result=php("require 'config/provisioning.php';"+connect+"try{erp_install_empty_school($p,[]);echo 'UNSAFE';}catch(RuntimeException $e){echo 'SAFE';}")
 check(result==b'SAFE','existing school cannot be initialized again')
 school=Browser(host=code+'.schoolmng.localhost')
 s,raw,h=school.get('/admin/')
 fields={'csrf_token':token(raw),'login':'1','username':'ci_school_owner','password':school_password,'verification_code':captcha(raw),'branch_id':'1'}
 check(school.get('/admin/',fields)[0]==302,'new real school administrator login')
 s,raw,h=school.get('/admin/index.php?page=website_notice&view=add');csrf=token(raw)
 boundary='ERPRegression'+secrets.token_hex(6)
 fields={'csrf_token':csrf,'save_notice':'1','id':'0','title':'CI private school upload','notice_date':'2026-10-04','body':'<p>Fictional test data</p>','branch_id':'1'}
 chunks=[('--'+boundary+'\r\nContent-Disposition: form-data; name="'+k+'"\r\n\r\n'+v+'\r\n').encode() for k,v in fields.items()]
 chunks.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="notice_attachment"; filename="ci.pdf"\r\nContent-Type: application/pdf\r\n\r\n%PDF-1.4\n%%EOF\r\n--'+boundary+'--\r\n').encode())
 check(school.get('/admin/index.php?page=website_notice',b''.join(chunks),{'Content-Type':'multipart/form-data; boundary='+boundary})[0] in [302,303],'real school can upload with read-only source')
 path=php(connect+"echo $p->query(\"SELECT attachment_path FROM notices WHERE title='CI private school upload' ORDER BY id DESC LIMIT 1\")->fetchColumn();").decode()
 check(path.startswith('uploads/'+code+'/') and school.get('/'+path)[0]==200,'real school upload is persistent and correctly scoped')
 demo=Browser(8088,'demo.schoolmng.localhost');check(demo.get('/admin/')[0]==200,'demo admin opens')
 s,raw,h=demo.get('/admin/index.php?page=transport_gps_settings')
 check(demo.get('/admin/index.php?page=transport_gps_settings',{'csrf_token':token(raw),'save_gps_settings':'1','base_url':'https://gps.example.invalid','password':''})[0]==403,'GPS configuration with blank password is blocked')
 for page in ['database_reset','live_class_settings','device_settings','whatsapp_settings']:
  check(demo.get('/admin/index.php?page='+page,{'csrf_token':'unused','password':''})[0]==403,page+' write is blocked')
 s,raw,h=demo.get('/admin/index.php?page=result_bulk_pdf_export&session_id=1&class_id=1&term_id=1&part_id=1&export=1')
 check(s==200 and raw[:2]==b'PK','bulk result PDF ZIP downloads')
 with zipfile.ZipFile(io.BytesIO(raw)) as z:
  check(len(z.namelist())==48 and all(z.read(n).startswith(b'%PDF') for n in z.namelist()),'48 valid student result PDFs generated')
finally:
 for uid in uids:
  php("require 'config/master_db.php';$pdo_master->prepare('DELETE FROM user_menu_permissions WHERE user_id=?')->execute(["+str(uid)+"]);$pdo_master->prepare('DELETE FROM master_users WHERE id=?')->execute(["+str(uid)+"]);")
 if tenant_id:
  assert re.fullmatch(r'sc_citest[a-f0-9]{10}',tenant['db_name'])
  php("require 'config/provisioning.php';$p=new PDO('mysql:host=db;dbname=school_master_db','root',getenv('ERP_PROVISION_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$p->prepare('DELETE FROM tenants WHERE id=? AND school_code=?')->execute(["+str(tenant_id)+","+json.dumps(code)+"]);$p->exec('DROP DATABASE '.demo_ident("+json.dumps(tenant['db_name'])+"));$p->exec('DROP USER '.$p->quote("+json.dumps(tenant['db_user'])+").\"@'%'\");$p->prepare('DELETE FROM provision_jobs WHERE school_code=?')->execute(["+json.dumps(code)+"]);",'provisioner')
print('Production blocker regression checks completed.')
