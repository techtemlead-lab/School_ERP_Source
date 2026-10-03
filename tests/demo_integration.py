"""Local HTTP regression checks. Never target a production server."""
import io,json,re,urllib.request,urllib.error,urllib.parse,http.cookiejar,zipfile,uuid,datetime
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs): return None
class Browser:
 def __init__(self,port=8088,host='demo.schoolmng.localhost'):
  self.port,self.host=port,host
  self.jar=http.cookiejar.CookieJar()
  self.opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(self.jar),NoRedirect())
 def get(self,path,data=None,headers=None):
  hdr={'Host':self.host+':'+str(self.port)}
  hdr.update(headers or {})
  if isinstance(data,dict):data=urllib.parse.urlencode(data).encode();hdr['Content-Type']='application/x-www-form-urlencoded'
  req=urllib.request.Request('http://127.0.0.1:'+str(self.port)+path,data=data,headers=hdr)
  try: r=self.opener.open(req,timeout=45)
  except urllib.error.HTTPError as e:r=e
  return r.code,r.read(),r.headers
def check(ok,name):
 if not ok:raise AssertionError(name)
 print('PASS',name)
def export(b):
 status,raw,h=b.get('/demo/export.php');check(status==200 and raw[:2]==b'PK','ZIP export downloads')
 with zipfile.ZipFile(io.BytesIO(raw)) as z:
  records={n:json.loads(z.read(n)) for n in z.namelist() if n.endswith('.json')}
  for rows in records.values():
   for row in rows:check(not any(re.search('password|token|secret|api_key',k,re.I) for k in row),'export excludes credentials') if any(re.search('password|token|secret|api_key',k,re.I) for k in row) else None
  return records
def csrf(raw):
 m=re.search(rb'name="csrf_token"[^>]*value="([^"]+)"',raw);check(bool(m),'CSRF field available');return m[1].decode()
def multipart(fields,filename,content):
 boundary='DemoTest'+uuid.uuid4().hex;chunks=[]
 for key,value in fields.items():chunks.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+key+'"\r\n\r\n'+str(value)+'\r\n').encode())
 chunks.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="notice_attachment"; filename="'+filename+'"\r\nContent-Type: application/octet-stream\r\n\r\n').encode()+content+b'\r\n')
 chunks.append(('--'+boundary+'--\r\n').encode())
 return b''.join(chunks),{'Content-Type':'multipart/form-data; boundary='+boundary}
a,b=Browser(),Browser()
for visitor in [a,b]:
 status,raw,h=visitor.get('/admin/');check(status==200 and b'PUBLIC DEMO' in raw,'one-click admin and reset notice')
check(export(a)['students.json'][0]['student_id']=='DEMO-S001','seeded student profile')
marker='Private demo notice '+uuid.uuid4().hex
status,raw,h=a.get('/admin/index.php?page=website_notice&view=add')
fields={'csrf_token':csrf(raw),'save_notice':'1','id':'0','title':marker,'notice_date':datetime.date.today().isoformat(),'body':'<p>Only visitor A should see this.</p>','branch_id':'1'}
upload=b'%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n'
data,headers=multipart(fields,'demo-test.pdf',upload)
status,raw,h=a.get('/admin/index.php?page=website_notice',data,headers)
check(status in [302,303],'demo notice saved')
ea,eb=export(a),export(b)
notice=next((n for n in ea['notices.json'] if n['title']==marker),None)
check(bool(notice),'visitor A sees own saved data')
check(not any(n['title']==marker for n in eb['notices.json']),'visitor B cannot see visitor A edits')
path='/'+notice['attachment_path']
check(a.get(path)[0]==200,'own uploaded PDF downloads')
check(b.get(path)[0]==404,'other visitor cannot download private upload')
data,headers=multipart(fields,'evil.php',b'<?php echo "not allowed";')
check(a.get('/admin/index.php?page=website_notice',data,headers)[0]==413,'executable upload rejected')
data,headers=multipart(fields,'oversize.pdf',upload+b'x'*(2*1024*1024))
check(a.get('/admin/index.php?page=website_notice',data,headers)[0]==413,'oversize upload rejected')
for role in ['admin','teacher','student']:
 check(a.get('/'+role+'/')[0]==200,'one-click '+role)
 check(a.get('/'+role+'/index.php',{'change_password':'1','new_password':'blocked'})[0]==403,'password changes blocked for '+role)
 check(a.get('/'+role+'/index.php',{'login':'1','password':'blocked'})[0]==403,'manual password inputs blocked for '+role)
roledata=export(a)
check(set(roledata)=={'student_profile.json','my_attendance.json','my_payments.json'} and len(roledata['student_profile.json'])==1,'student export is role scoped')
a.get('/teacher/')
check(set(export(a))=={'teacher_profile.json','my_routines.json','my_homework.json'},'teacher export is role scoped')
check(a.get('/admin/index.php?page=security_settings',{'save':'1'})[0]==403,'security settings locked')
check(a.get('/master/login.php')[0]==404,'master panel unavailable on demo')
check(a.get('/config/demo.php')[0]==403,'configuration files unavailable')
check(a.get('/sql/schema.sql')[0]==403,'SQL files unavailable')
check(a.get('/student/fee_payment_init.php')[0]==403,'online payments blocked')
check(a.get('/',headers={'Host':'schoolmng.localhost'})[0]==421,'demo runtime refuses primary host')
main=Browser(8087,'schoolmng.localhost')
check(main.get('/')[0]==200,'main marketing page')
check(main.get('/admin/')[0]==302,'main admin redirects to master')
check(main.get('/teacher/')[0]==404,'school roles unavailable on primary host')
status,raw,h=main.get('/demo/')
check(status==200 and b'Enter as demo admin' in raw,'public demo guide')
fields={'csrf_token':csrf(raw),'name':'Fictional Test Owner','school':'Integration Test School','email':'test@example.invalid','phone':'+8801700000000','students':'250','plan':'Standard','needs':'Fictional local regression test','consent':'1'}
check(main.get('/demo/request.php',fields)[0]==303,'dedicated demo request submitted')
check(b'has been saved' in main.get('/demo/')[1],'request confirmation shown')
status,raw,h=main.get('/master/login.php');check(status==200,'master login page')
print('HTTP integration checks completed.')
