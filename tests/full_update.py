"""Disposable local checks for the full academic update and login presentation."""
from pathlib import Path
import hashlib, hmac, http.cookiejar, json, re, subprocess, time
import urllib.error, urllib.parse, urllib.request
ROOT = Path(__file__).resolve().parents[1]
DC = ['docker', 'compose', '--env-file', 'deployment/.env.local', '-f', 'deployment/compose.local.yml']
ENV = dict(line.split('=', 1) for line in (ROOT / 'deployment/.env.local').read_text().splitlines() if '=' in line)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs): return None
class Browser:
    def __init__(self, port=8088, host='demo.schoolmng.localhost'):
        self.port, self.host = port, host
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
    def get(self, path, data=None):
        req = urllib.request.Request('http://127.0.0.1:' + str(self.port) + path, headers={'Host': self.host}, data=urllib.parse.urlencode(data).encode() if data is not None else None)
        for attempt in range(31):
            try: r = self.op.open(req, timeout=60)
            except urllib.error.HTTPError as e: r = e
            raw = r.read()
            if r.code != 503 or b'All demo sessions are busy' not in raw or attempt == 30: return r.code, raw
            time.sleep(2)
def check(ok, description):
    assert ok, description
    print('PASS', description, flush=True)
def php(code):
    guard = "<?php if(getenv('APP_ENV')!=='local')exit(91);require 'config/runtime.php';"
    r = subprocess.run(DC + ['exec', '-T', 'worker', 'php'], input=(guard + code).encode(), capture_output=True, cwd=ROOT, timeout=120)
    assert r.returncode == 0, r.stderr.decode(errors='replace')[-1200:]
    return r.stdout
def csrf(raw): return re.search(rb'name="csrf_token"[^>]*value="([^"]+)"', raw)[1].decode()

b = Browser()
check(b.get('/admin/')[0] == 200, 'updated demo admin one-click login')
visitor = next(c.value for c in b.jar if c.name == 'erp_demo_visitor')
digest = hmac.new(ENV['LOCAL_TOKEN_SECRET'].encode(), visitor.encode(), hashlib.sha256).hexdigest()
db = php("$p=new PDO('mysql:host=db;dbname=school_demo_control','root',getenv('DEMO_PROVISION_PASS'));$q=$p->prepare('SELECT db_name FROM demo_sandboxes WHERE token_hash=?');$q->execute(["+json.dumps(digest)+"]);echo $q->fetchColumn();").decode()
assert re.fullmatch(r'ds_[a-f0-9]{32}', db)
connect = "$p=new PDO('mysql:host=db;dbname="+db+";charset=utf8mb4','root',getenv('DEMO_PROVISION_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);require 'config/academic_update_schema.php';erp_academic_update_schema($p);"
php(connect)
pages = ['academic_subject','academic_subject_import','routine_general','routine_dynamic','routine_view','routine_auto_generate','result_assign_exams','result_assign_subject_teacher','result_attendance_exam','result_attendance_subject','result_exam_eligibility','result_marks_entry_exam','result_marks_entry_subject','result_students_report_card','result_students_merit_list','result_tabulation_sheet','result_working_days','result_exam_routine','result_marks_pending','result_subject_analysis','result_student_progress','result_section_comparison','result_performer_alert','result_settings','result_bulk_pdf_export']
for page in pages:
    status, raw = b.get('/admin/index.php?page=' + page)
    check(status == 200 and b'Fatal error' not in raw and 'পেজটি লোড করতে সমস্যা হয়েছে।'.encode() not in raw and b'Uncaught' not in raw, 'updated admin page: ' + page)
criteria = '&class_id=1&shift_id=1&section_id=1&term_id=1&part_id=1&subject_id=1'
for page in ['result_exam_eligibility','result_attendance_exam','result_attendance_subject','result_marks_entry_exam','result_marks_entry_subject','routine_view','routine_dynamic']:
    status, raw = b.get('/admin/index.php?page=' + page + criteria)
    check(status == 200 and b'Fatal error' not in raw and 'পেজটি লোড করতে সমস্যা হয়েছে।'.encode() not in raw, 'populated academic page: ' + page)
for page in ['result_exam_eligibility','result_attendance_exam','result_marks_entry_exam','result_marks_entry_subject']:
    status, raw = b.get('/admin/ajax/' + page + '_ajax.php?action=table' + criteria)
    assert status == 200 and b'Fatal error' not in raw and len(raw) > 100, (page, status, raw[:180])
    print('PASS shared AJAX grid: ' + page, flush=True)

# Exercise forged IDs, group/religion rules and actual persistence in this visitor only.
result = json.loads(php(connect + """
require 'config/exam_att_grid.php';require 'config/report_card_helpers.php';
$_SESSION=['is_super_admin'=>true,'active_branch_id'=>1];
$p->exec("UPDATE students SET branch_id=2 WHERE id=6");
$p->exec("UPDATE academic_subjects SET religion='Hinduism' WHERE id=2");
ee_save($p,1,1,1,1,1,[1,6],[1,2,4],[1=>[1=>'1',2=>'1',4=>'1'],6=>[1=>'1']]);
$bad=(int)$p->query('SELECT COUNT(*) FROM exam_eligibility WHERE student_id=1 AND subject_id=4')->fetchColumn();
$foreign=(int)$p->query('SELECT is_eligible FROM exam_eligibility WHERE student_id=6 AND subject_id=1 AND term_id=1')->fetchColumn();
$religion=(int)$p->query('SELECT is_eligible FROM exam_eligibility WHERE student_id=1 AND subject_id=2 AND term_id=1')->fetchColumn();
ea_save($p,1,1,1,1,1,1,[1,6],[1,2,4],[1=>[1=>'1',2=>'1',4=>'1'],6=>[1=>'1']]);
$gate=ea_mark_states($p,1,1,1,1,1,[1,6],[1,2]);
$defs=rc_get_subject_defs($p,1,1,1);
$excluded=rc_excluded_subjects($p,$defs,1,1);
echo json_encode(['bad'=>$bad,'foreign'=>$foreign,'religion'=>$religion,'gate'=>$gate['state'],'excluded'=>!empty($excluded[2]),'group'=>ee_applicable(['group_id'=>9],['group_id'=>8]),'religion_match'=>sr_applicable('Islam','Islam'),'religion_deny'=>sr_applicable('Hinduism','Islam')]);
"""))
check(result['bad'] == 0 and result['foreign'] == 1, 'forged foreign-class/branch eligibility IDs cause no writes')
check(result['religion'] == 0 and result['excluded'], 'religion mismatch blocked and excluded from report totals')
check(result['gate']['1']['1'] == 'open' and result['gate']['1']['2'] == 'norel' and '6' not in result['gate'], 'marks gate requires own branch plus eligibility and present attendance')
check(not result['group'] and result['religion_match'] and not result['religion_deny'], 'group and religion applicability rules')
before_mark = php(connect+"echo $p->query('SELECT marks_obtained FROM exam_marks WHERE student_id=6 AND subject_id=1 AND term_id=1 AND part_id=1')->fetchColumn();")
status, raw = b.get('/admin/index.php?page=result_marks_entry_exam' + criteria)
check(b.get('/admin/index.php?page=result_marks_entry_exam', {'save_marks':'1','csrf_token':csrf(raw),'class_id':'1','shift_id':'1','section_id':'1','term_id':'1','part_id':'1','marks[1][6]':'99'})[0] == 302, 'marks POST handles forged foreign branch ID')
check(php(connect+"echo $p->query('SELECT marks_obtained FROM exam_marks WHERE student_id=6 AND subject_id=1 AND term_id=1 AND part_id=1')->fetchColumn();") == before_mark, 'foreign branch mark unchanged after forged POST')
php(connect+"$p->exec('UPDATE routines SET branch_id=2 WHERE id=1');")
check(b.get('/admin/index.php?page=routine_general&view=edit&id=1')[0] == 404, 'foreign branch routine edit denied')
status, raw = b.get('/admin/index.php?page=routine_dynamic' + criteria)
check(b.get('/admin/index.php?page=routine_dynamic', {'delete_routine':'1','id':'1','csrf_token':csrf(raw),'class_id':'1','shift_id':'1','section_id':'1'})[0] == 404, 'foreign branch routine delete denied')
for role, pages in [('teacher',['routine_view','result_marks_entry_subject','lesson_plan','teacher_questions']),('student',['class_routine','exam_routine','result','dashboard','lesson_plan','scholarship_apply'])]:
    check(b.get('/'+role+'/')[0] == 200, 'preserved ' + role + ' demo login')
    for page in pages:
        status, raw = b.get('/'+role+'/index.php?page='+page)
        check(status == 200 and b'Fatal error' not in raw and 'পেজটি লোড করতে সমস্যা হয়েছে।'.encode() not in raw, role + ' academic page: ' + page)
status, raw = b.get('/admin/index.php?page=result_marks_entry_subject' + criteria)
check(status == 200 and b"return 'ajax/result_marks_entry_subject_ajax.php?portal=admin" in raw, 'admin marks page keeps admin context with simultaneous teacher/student logins')
check(b.get('/admin/ajax/result_marks_entry_subject_ajax.php?portal=admin&action=table' + criteria)[0] == 200, 'explicit admin AJAX context works with simultaneous portal logins')
check(b.get('/admin/ajax/result_marks_entry_subject_ajax.php?portal=teacher&action=table' + criteria)[0] == 200, 'explicit teacher AJAX context works with simultaneous portal logins')
check(b.get('/admin/ajax/result_marks_entry_subject_ajax.php?action=table' + criteria)[0] == 403, 'ambiguous AJAX portal context denied')
check(Browser().get('/admin/ajax/result_exam_eligibility_ajax.php?action=table'+criteria)[0] == 403, 'anonymous result AJAX denied')

# Real portal login presentation on the disposable isolated school runtime.
php("$p=new PDO('mysql:host=db;dbname=sc_boundfixture','root',getenv('DEMO_PROVISION_PASS'));require 'config/academic_update_schema.php';erp_academic_update_schema($p);")
subprocess.run(DC+['up','-d','isolated'], cwd=ROOT, check=True, capture_output=True)
try:
    school = Browser(8089, 'boundfixture.schoolmng.localhost')
    for attempt in range(30):
        try:
            if school.get('/health.php')[0] == 200: break
        except (OSError, urllib.error.URLError): pass
        time.sleep(1)
    for path in ['/admin/','/teacher/login.php','/student/login.php']:
        status, raw = school.get(path)
        check(status == 200 and b'lg-shell' in raw and b'captchaBox' in raw and b'name="csrf_token"' in raw, 'new login UI retains captcha and CSRF: ' + path)
    status, raw = school.get('/admin/')
    captcha = ''.join(x.decode() for x in re.findall(rb'<text[^>]*>([0-9])</text>',raw))
    check(school.get('/admin/', {'login':'1','csrf_token':csrf(raw),'username':'bound_owner','password':ENV['LOCAL_ADMIN_PASSWORD'],'verification_code':captcha,'branch_id':'1'})[0] == 302, 'existing isolated school admin logs in through new design')
finally:
    subprocess.run(DC+['stop','isolated'],cwd=ROOT,check=True,capture_output=True)
