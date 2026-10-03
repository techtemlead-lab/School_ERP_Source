import pathlib,re,urllib.request,urllib.parse,urllib.error,http.cookiejar
root=pathlib.Path(__file__).resolve().parents[1]
env=dict(line.split('=',1) for line in (root/'deployment/.env.local').read_text().splitlines() if '=' in line)
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*a,**k):return None
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect())
def get(path,data=None):
 req=urllib.request.Request('http://127.0.0.1:8087'+path,data=urllib.parse.urlencode(data).encode() if data else None,headers={'Host':'schoolmng.localhost:8087'})
 try:r=opener.open(req)
 except urllib.error.HTTPError as e:r=e
 return r.code,r.read()
status,raw=get('/master/login.php');token=re.search(rb'name="csrf_token"[^>]*value="([^"]+)"',raw)[1].decode()
status,raw=get('/master/login.php',{'csrf_token':token,'login':'1','username':'owner','password':env['LOCAL_ADMIN_PASSWORD']})
assert status==302,'Master authentication failed'
status,raw=get('/master/demo_requests.php')
assert status==200 and b'Integration Test School' in raw,'Request not visible to master'
assert b'Fictional Test Owner' in raw,'Request details missing'
print('PASS real master login and dedicated demo request inbox')
