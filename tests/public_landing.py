import urllib.request,urllib.parse,http.cookiejar,re,json,pathlib
root=pathlib.Path(__file__).resolve().parents[1]
jar=http.cookiejar.CookieJar()
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(jar))
def get(path):
 req=urllib.request.Request('http://127.0.0.1:8087'+path,headers={'Host':'schoolmng.localhost:8087'})
 with opener.open(req) as response:return response.read().decode(),response.headers
bn,headers=get('/?lang=bn')
assert '<html lang="bn">' in bn and 'কম কাগজপত্র।' in bn and 'বেশি অগ্রগতি।' in bn
assert 'Less paperwork.' not in bn and 'Student lifecycle' not in bn
assert 'private, no-store' in headers['Cache-Control']
assert any(c.name=='erp_public_lang' and c.value=='bn' for c in jar)
guide,_=get('/demo/?plan=Standard')
assert '<html lang="bn">' in guide and 'ব্যক্তিগত ডেমো চান?' in guide
assert '<option value="Standard" selected>স্ট্যান্ডার্ড</option>' in guide
for path,phrase in [('/privacy.php','গোপনীয়তা'),('/policy.php','দর্শকদের আলাদা ডেমো'),('/terms.php','দায়িত্বশীলভাবে ERP পরীক্ষা করুন')]:
 html,_=get(path);assert '<html lang="bn">' in html and phrase in html
en,_=get('/?lang=en');assert '<html lang="en">' in en and 'Less paperwork.' in en
assert len(re.findall(r'data-colour-theme="[^"]+"',en))==14
select=re.search(r'<select id="background-theme">(.*?)</select>',en,re.S)[1]
assert len(re.findall('<option ',select))==14
assert all('value="'+mode+'"' in en for mode in ['day','night','system'])
assert len(json.loads((root/'landing/bn.json').read_text(encoding='utf-8')))>200
print('PASS server-rendered Bangla/English, persistent language, translated guide/policies, stable form values, 14 themes, 14 backgrounds and 3 appearance modes')
