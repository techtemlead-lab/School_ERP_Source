"""Local Docker HTTP regression: approvals, printing, role/branch and visitor isolation."""
from pathlib import Path
import datetime, hashlib, hmac, http.cookiejar, json, re, secrets, subprocess
import urllib.error, urllib.parse, urllib.request

ROOT = Path(__file__).resolve().parents[1]
DC = ['docker', 'compose', '--env-file', 'deployment/.env.local', '-f', 'deployment/compose.local.yml']
env = dict(line.split('=', 1) for line in (ROOT / 'deployment/.env.local').read_text().splitlines() if '=' in line)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Browser:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())

    def get(self, path, data=None):
        request = urllib.request.Request('http://127.0.0.1:8088' + path, headers={'Host': 'demo.schoolmng.localhost:8088'}, data=urllib.parse.urlencode(data).encode() if data is not None else None)
        try:
            response = self.opener.open(request, timeout=60)
        except urllib.error.HTTPError as error:
            response = error
        return response.code, response.read()


def check(ok, description):
    assert ok, description
    print('PASS', description, flush=True)


def php(code):
    guard = "<?php if(getenv('APP_ENV')!=='local')exit(91);require 'config/runtime.php';require 'config/demo_seed.php';"
    result = subprocess.run(DC + ['exec', '-T', 'worker', 'php'], input=(guard + code).encode(), capture_output=True, cwd=ROOT, timeout=120)
    assert result.returncode == 0, result.stderr.decode(errors='replace')[-1200:]
    return result.stdout


def token(raw):
    return re.search(rb'name="csrf_token"[^>]*value="([^"]+)"', raw)[1].decode()


b = Browser()
check(b.get('/admin/')[0] == 200, 'demo admin login')
visitor = next(cookie.value for cookie in b.jar if cookie.name == 'erp_demo_visitor')
token_hash = hmac.new(env['LOCAL_TOKEN_SECRET'].encode(), visitor.encode(), hashlib.sha256).hexdigest()
name = php("$p=new PDO('mysql:host=db;dbname=school_demo_control','root',getenv('DEMO_PROVISION_PASS'));$q=$p->prepare('SELECT db_name FROM demo_sandboxes WHERE token_hash=?');$q->execute([" + json.dumps(token_hash) + "]);echo $q->fetchColumn();").decode()
assert re.fullmatch(r'ds_[a-f0-9]{32}', name)
connect = "$p=new PDO('mysql:host=db;dbname=" + name + ";charset=utf8mb4','root',getenv('DEMO_PROVISION_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);require 'config/lesson_plan_helpers.php';lp_ensure_tables($p);"


def sql(code):
    return php(connect + code)

sql("$p->exec('UPDATE academic_sections SET branch_id=1 WHERE branch_id IS NULL');")


marker = 'CI Lesson ' + secrets.token_hex(8)
check(b.get('/teacher/')[0] == 200, 'teacher login while admin session remains signed in')
path = '/teacher/index.php?page=lesson_plan'
status, raw = b.get(path + '&action=form')
check(status == 200 and b'lpClassSelect' in raw and b'PUBLIC DEMO' in raw, 'teacher form and existing demo banner')
fields = {'csrf_token': token(raw), 'lp_id': '0', 'class_id': '1', 'section_id': '1', 'subject_id': '1', 'title': marker, 'lesson_date': datetime.date.today().isoformat(), 'duration_minutes': '45', 'objectives': '<script>alert(1)</script>', 'save_draft': '1'}
check(b.get(path, {**fields, 'csrf_token': 'invalid'})[0] == 302, 'invalid CSRF form rejected')
check(sql("echo $p->query('SELECT COUNT(*) FROM elearning_lesson_plans WHERE title=' . $p->quote(" + json.dumps(marker) + "))->fetchColumn();") == b'0', 'invalid CSRF cannot create data')
check(b.get(path, fields)[0] == 302, 'teacher saves draft')
row = json.loads(sql("echo json_encode($p->query('SELECT * FROM elearning_lesson_plans WHERE title=' . $p->quote(" + json.dumps(marker) + "))->fetch());"))
assert row, re.findall(rb'<div class="alert[^>]*>(.*?)</div>', b.get(path + '&action=form')[1], re.S)
plan_id = row['id']
check(row['status'] == 'Draft' and row['teacher_id'] == 1 and row['branch_id'] == 1, 'teacher draft cannot be auto-approved by simultaneous admin session')
check(b.get('/lesson_plan_print.php?id=' + str(plan_id) + '&portal=teacher')[0] == 200, 'teacher can print own draft with shared ERP session')
check(b.get('/lesson_plan_print.php?id=' + str(plan_id) + '&portal=admin')[0] == 404, 'admin cannot access private teacher draft')
fields['lp_id'] = str(plan_id)
fields.pop('save_draft')
fields['save_submit'] = '1'
check(b.get(path, fields)[0] == 302, 'teacher submits for approval')
check(sql('echo $p->query("SELECT status FROM elearning_lesson_plans WHERE id=' + str(plan_id) + '")->fetchColumn();') == b'Pending', 'submitted plan awaits admin approval')
check(b.get('/student/')[0] == 200, 'student login in same visitor sandbox')
student_path = '/student/index.php?page=lesson_plan'
check(marker.encode() not in b.get(student_path)[1], 'students cannot view pending plans')
check(b.get('/lesson_plan_print.php?id=' + str(plan_id) + '&portal=student')[0] == 404, 'students cannot print pending plans')
check(b.get(path, {'csrf_token': fields['csrf_token'], 'lp_id': str(plan_id), 'approve_lp': '1'})[0] == 200, 'teacher cannot execute an admin approval')
check(sql('echo $p->query("SELECT status FROM elearning_lesson_plans WHERE id=' + str(plan_id) + '")->fetchColumn();') == b'Pending', 'forged approval leaves pending state unchanged')
admin_path = '/admin/index.php?page=lesson_plan'
status, raw = b.get(admin_path + '&action=view&id=' + str(plan_id))
check(status == 200 and marker.encode() in raw, 'admin sees submitted plan despite teacher login')
check(b.get(admin_path, {'csrf_token': token(raw), 'lp_id': str(plan_id), 'approve_lp': '1'})[0] == 302, 'admin approves plan')
check(marker.encode() in b.get(student_path)[1], 'approved plan appears for matching student')
status, raw = b.get('/lesson_plan_print.php?id=' + str(plan_id) + '&portal=student')
check(status == 200 and b'Student Copy' in raw and b'&lt;script&gt;' in raw and b'<script>alert(1)' not in raw, 'student print is available and user content is escaped')
check(b.get(path, fields)[0] == 302, 'teacher resubmits edited approved plan')
check(marker.encode() not in b.get(student_path)[1], 'edited approval is withdrawn until reviewed again')
status, raw = b.get(admin_path + '&action=view&id=' + str(plan_id))
check(b.get(admin_path, {'csrf_token': token(raw), 'lp_id': str(plan_id), 'reject_lp': '1', 'admin_remarks': 'Add an assessment.'})[0] == 302, 'admin rejects with feedback')
check(b'Add an assessment.' in b.get(path + '&action=view&id=' + str(plan_id))[1], 'teacher sees rejection feedback')

# Only this disposable visitor database receives adversarial branch/class fixtures.
fixture = json.loads(sql("demo_insert($p,'branches',array_merge($p->query('SELECT * FROM branches WHERE id=1')->fetch(),['id'=>900001,'branch_name'=>'CI Other Campus','short_name'=>'CI Other','status'=>'Active']));demo_insert($p,'academic_sections',array_merge($p->query('SELECT * FROM academic_sections WHERE id=1')->fetch(),['id'=>900001,'class_id'=>1,'section_name'=>'CI Other Campus','branch_id'=>900001]));demo_insert($p,'academic_sections',array_merge($p->query('SELECT * FROM academic_sections WHERE id=1')->fetch(),['id'=>900002,'class_id'=>1,'section_name'=>'CI Other Section','branch_id'=>1]));$ids=[];foreach([['branch_id'=>900001,'teacher_id'=>1,'section_id'=>900001],['teacher_id'=>2],['class_id'=>2,'subject_id'=>4,'section_id'=>2],['section_id'=>900002],['session_id'=>999999]] as $extra){$ids[]=demo_insert($p,'elearning_lesson_plans',array_merge(['branch_id'=>1,'session_id'=>1,'class_id'=>1,'section_id'=>1,'subject_id'=>1,'teacher_id'=>1,'title'=>'CI private fixture','status'=>'Approved'],$extra));}echo json_encode($ids);"))
for other_id in fixture:
    check(b.get('/lesson_plan_print.php?id=' + str(other_id) + '&portal=student')[0] == (200 if other_id == fixture[1] else 404), 'student print enforces branch, class, section and session for fixture ' + str(fixture.index(other_id)))
for other_id in fixture[:2]:
    check(b.get('/lesson_plan_print.php?id=' + str(other_id) + '&portal=teacher')[0] == 404, 'teacher cannot print another branch or teacher plan')
fields['lp_id'] = str(fixture[0])
check(b.get(path, fields)[0] == 403, 'teacher cannot edit a plan from another branch')
status, raw = b.get(admin_path + '&action=form')
check(b.get(admin_path, {'csrf_token': token(raw), 'lp_id': str(fixture[0]), 'reject_lp': '1', 'admin_remarks': 'forged'})[0] == 302, 'scoped admin review processed safely')
check(sql('echo $p->query("SELECT status FROM elearning_lesson_plans WHERE id=' + str(fixture[0]) + '")->fetchColumn();') == b'Approved', 'branch admin scope cannot review another branch')
check(b.get('/admin/lesson_plan.php')[0] == 404 and b.get('/student/lesson_plan.php')[0] in (403, 404), 'direct controller access is denied')
other = Browser()
check(other.get('/teacher/')[0] == 200, 'second private demo visitor opens')
check(marker.encode() not in other.get('/teacher/index.php?page=lesson_plan')[1], 'another visitor cannot read changed lesson plans')
check(marker.encode() not in other.get('/lesson_plan_print.php?id=' + str(plan_id) + '&portal=teacher')[1], 'another visitor cannot print the private plan even if record IDs coincide')
check(b.get('/teacher/index.php', {'change_password': '1', 'new_password': 'forbidden'})[0] == 403, 'demo password lock remains enforced')

# The same authorization function used by standalone printing must enforce real
# account restrictions. CLI is never treated as a public-demo request.
for role, table, key in [('teacher', 'employees', 'teacher_id'), ('student', 'students', 'student_pk')]:
    sql("$p->exec('UPDATE " + table + " SET must_change_password=1 WHERE id=1');")
    try:
        output = sql("$_SESSION=['" + role + "_logged'=>true,'" + key + "'=>1];lp_require_role($p," + json.dumps(role) + ");echo 'UNEXPECTED_ACCESS';")
        check(b'UNEXPECTED_ACCESS' not in output and b'change its initial password' in output, role + ' initial-password requirement also protects standalone printing')
    finally:
        sql("$p->exec('UPDATE " + table + " SET must_change_password=0 WHERE id=1');")
sql("require 'config/permissions.php';perm_ensure_tables($p);$p->exec('UPDATE system_users SET permissions_restricted=1 WHERE id=1');")
try:
    output = sql("require 'config/permissions.php';$_SESSION=['admin_logged'=>true,'admin_user'=>'demo_staff','is_super_admin'=>false,'system_user_id'=>1];lp_require_role($p,'admin');echo 'UNEXPECTED_ACCESS';")
    check(b'UNEXPECTED_ACCESS' not in output and b'do not have permission' in output, 'restricted admin without lesson-plan permission cannot print')
finally:
    sql("$p->exec('UPDATE system_users SET permissions_restricted=0 WHERE id=1');")
print('Lesson-plan approval, print and access regression checks completed.', flush=True)
