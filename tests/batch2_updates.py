"""Local-only HTTP checks for scholarship accounting and confidential question files."""
from pathlib import Path
import datetime, hashlib, hmac, http.cookiejar, io, json, re, secrets, subprocess, time, zipfile
import urllib.error, urllib.parse, urllib.request

ROOT = Path(__file__).resolve().parents[1]
DC = ['docker', 'compose', '--env-file', 'deployment/.env.local', '-f', 'deployment/compose.local.yml']
env = dict(line.split('=', 1) for line in (ROOT / 'deployment/.env.local').read_text().splitlines() if '=' in line)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs): return None

class Browser:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
    def get(self, path, data=None, headers=None):
        headers = {'Host': 'demo.schoolmng.localhost:8088', **(headers or {})}
        if isinstance(data, dict): data = urllib.parse.urlencode(data).encode()
        request = urllib.request.Request('http://127.0.0.1:8088' + path, headers=headers, data=data)
        for attempt in range(31):
            try: response = self.opener.open(request, timeout=60)
            except urllib.error.HTTPError as error: response = error
            raw = response.read()
            if response.code != 503 or b'All demo sessions are busy' not in raw or attempt == 30:
                return response.code, raw
            # This test creates more visitors than the initial pool. Wait for
            # the real background worker's normal 30-second replenishment.
            time.sleep(2)

def check(ok, description):
    assert ok, description
    print('PASS', description, flush=True)

def php(code):
    guard = "<?php if(getenv('APP_ENV')!=='local')exit(91);require 'config/runtime.php';require 'config/demo_seed.php';"
    r = subprocess.run(DC + ['exec', '-T', 'worker', 'php'], input=(guard + code).encode(), capture_output=True, cwd=ROOT, timeout=120)
    assert r.returncode == 0, r.stderr.decode(errors='replace')[-1200:]
    return r.stdout

def token(raw): return re.search(rb'name="csrf_token"[^>]*value="([^"]+)"', raw)[1].decode()

def multipart(fields, filename, content):
    boundary = 'Batch2' + secrets.token_hex(10)
    chunks = [('--' + boundary + '\r\nContent-Disposition: form-data; name="' + k + '"\r\n\r\n' + str(v) + '\r\n').encode() for k, v in fields.items()]
    chunks += [('--' + boundary + '\r\nContent-Disposition: form-data; name="question_file"; filename="' + filename + '"\r\nContent-Type: application/octet-stream\r\n\r\n').encode() + content + b'\r\n', ('--' + boundary + '--\r\n').encode()]
    return b''.join(chunks), {'Content-Type': 'multipart/form-data; boundary=' + boundary}

b = Browser()
check(b.get('/admin/')[0] == 200, 'demo admin one-click login')
visitor = next(c.value for c in b.jar if c.name == 'erp_demo_visitor')
digest = hmac.new(env['LOCAL_TOKEN_SECRET'].encode(), visitor.encode(), hashlib.sha256).hexdigest()
db = php("$p=new PDO('mysql:host=db;dbname=school_demo_control','root',getenv('DEMO_PROVISION_PASS'));$q=$p->prepare('SELECT db_name FROM demo_sandboxes WHERE token_hash=?');$q->execute([" + json.dumps(digest) + "]);echo $q->fetchColumn();").decode()
assert re.fullmatch(r'ds_[a-f0-9]{32}', db)
connect = "$p=new PDO('mysql:host=db;dbname=" + db + ";charset=utf8mb4','root',getenv('DEMO_PROVISION_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);require 'config/portal_access.php';require 'config/teacher_question_helpers.php';require 'config/scholarship_application_helpers.php';tq_ensure_tables($p);sa_ensure_tables($p);"
def sql(code): return php(connect + code)

check(b.get('/teacher/')[0] == 200, 'teacher login alongside admin')
tp = '/teacher/index.php?page=teacher_questions'
ap = '/admin/index.php?page=teacher_questions'
marker = 'Batch2 confidential ' + secrets.token_hex(6)
raw = b.get(tp + '&view=form')[1]
fields = {'csrf_token': token(raw), 'save_question': '1', 'class_id': '1', 'subject_id': '1', 'title': marker, 'question_text': '<script>private exam text</script>', 'full_marks': '100', 'duration_minutes': '60', 'exam_date': datetime.date.today().isoformat()}
pdf = b'%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n'
data, hdr = multipart({**fields, 'csrf_token': 'invalid'}, 'paper.pdf', pdf)
check(b.get(tp, data, hdr)[0] == 302, 'invalid question CSRF rejected')
check(sql('$q=$p->prepare("SELECT COUNT(*) FROM teacher_question_papers WHERE title=?");$q->execute([' + json.dumps(marker) + ']);echo $q->fetchColumn();') == b'0', 'invalid CSRF created no question')
data, hdr = multipart(fields, 'paper.pdf', pdf)
check(b.get(tp, data, hdr)[0] == 302, 'teacher uploads confidential PDF')
q = json.loads(sql('$q=$p->prepare("SELECT * FROM teacher_question_papers WHERE title=?");$q->execute([' + json.dumps(marker) + ']);echo json_encode($q->fetch());'))
qid = q['id']
check(q['status'] == 'Pending' and q['branch_id'] == 1, 'question awaiting review in own branch')
link = '/teacher_question_file.php?id=' + str(qid)
check(b.get(link + '&portal=teacher')[1] == pdf, 'owner teacher can download original PDF')
check(b.get(link + '&portal=admin')[1] == pdf, 'authorized admin can download original PDF')
check(b.get(link)[0] == 403, 'ambiguous multi-portal download rejected')
private_path = 'uploads/demo/' + db + '/teacher_questions/' + q['file_path']
check(b.get('/' + private_path)[0] in (403, 404), 'direct uploaded question URL denied')
check(b.get('/demo/file.php?path=' + urllib.parse.quote(private_path))[0] == 404, 'general demo file endpoint cannot expose questions')
check(Browser().get(link + '&portal=teacher')[0] == 403, 'anonymous question download denied')
check(b'&lt;script&gt;private exam text&lt;/script&gt;' in b.get(tp + '&view=detail&id=' + str(qid))[1], 'typed questions escaped')
review = {'csrf_token': token(b.get(ap + '&view=detail&id=' + str(qid))[1]), 'q_id': str(qid), 'approve_question': '1'}
check(b.get(ap, review)[0] == 302, 'admin approves teacher question')
check(sql('echo $p->query("SELECT status FROM teacher_question_papers WHERE id=' + str(qid) + '")->fetchColumn();') == b'Approved', 'review uses admin route despite teacher session')
data, hdr = multipart({**fields, 'q_id': str(qid), 'title': marker + ' forbidden'}, 'replacement.pdf', pdf)
b.get(tp, data, hdr)
check(sql('echo $p->query("SELECT title FROM teacher_question_papers WHERE id=' + str(qid) + '")->fetchColumn();') == marker.encode(), 'approved question cannot be edited')
b.get(tp, {'csrf_token': fields['csrf_token'], 'q_id': str(qid), 'delete_question': '1'})
check(b.get(link + '&portal=teacher')[1] == pdf, 'teacher cannot delete approved question or its attachment')
b.get(ap, {'csrf_token': review['csrf_token'], 'q_id': str(qid), 'reject_question': '1', 'admin_remarks': 'Add marks distribution.'})
check(b'Add marks distribution.' in b.get(tp + '&view=detail&id=' + str(qid))[1], 'teacher sees question rejection feedback')
data, hdr = multipart({**fields, 'q_id': str(qid)}, 'resubmitted.pdf', pdf)
b.get(tp, data, hdr)
check(sql('echo $p->query("SELECT status FROM teacher_question_papers WHERE id=' + str(qid) + '")->fetchColumn();') == b'Pending', 'rejected question resubmits for review')
check(b.get('/' + private_path)[0] in (403, 404), 'old attachment remains inaccessible after replacement')
# Read the replacement filename for subsequent ownership fixtures.
q = json.loads(sql('echo json_encode($p->query("SELECT * FROM teacher_question_papers WHERE id=' + str(qid) + '")->fetch());'))
for filename, content in [('evil.php', b'<?php echo 1;'), ('fake.pdf', b'<html>fake</html>'), ('fake.docx', b'PKnot-a-word-document')]:
    data, hdr = multipart({**fields, 'title': marker + filename}, filename, content)
    b.get(tp, data, hdr)
    check(sql('$q=$p->prepare("SELECT COUNT(*) FROM teacher_question_papers WHERE title=?");$q->execute([' + json.dumps(marker + filename) + ']);echo $q->fetchColumn();') == b'0', 'invalid upload rejected: ' + filename)
word = io.BytesIO()
with zipfile.ZipFile(word, 'w') as z:
    z.writestr('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>')
    z.writestr('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>')
data, hdr = multipart({**fields, 'title': marker + ' Word'}, 'paper.docx', word.getvalue())
b.get(tp, data, hdr)
check(sql('$q=$p->prepare("SELECT COUNT(*) FROM teacher_question_papers WHERE title=? AND file_ext=\'docx\'");$q->execute([' + json.dumps(marker + ' Word') + ']);echo $q->fetchColumn();') == b'1', 'Word document upload accepted')

check(b.get('/student/')[0] == 200, 'student login alongside admin and teacher')
check(b.get(link + '&portal=student')[0] == 403, 'student cannot open confidential question')
sp = '/student/index.php?page=scholarship_apply'
sap = '/admin/index.php?page=scholarship_applications'
# Only this disposable visitor's seeded student application is removed to test submission.
sql("$p->exec('DELETE FROM scholarship_applications WHERE student_id=1');")
raw = b.get(sp + '&view=form')[1]
app_fields = {'csrf_token': token(raw), 'submit_application': '1', 'applicant_name': 'Fictional CI Guardian', 'applicant_relation': 'Guardian', 'child_order': '1', 'monthly_income': '12000', 'family_members': '5', 'earning_members': '1', 'present_address': 'Fictional Rangpur', 'permanent_address': 'Fictional Rangpur village', 'mobile': '01700000001', 'reason': 'CI fictional support'}
b.get(sp, {**app_fields, 'csrf_token': 'invalid'})
check(sql('echo $p->query("SELECT COUNT(*) FROM scholarship_applications WHERE student_id=1")->fetchColumn();') == b'0', 'invalid scholarship CSRF creates no application')
check(b.get(sp, app_fields)[0] == 302, 'student submits guardian scholarship application')
b.get(sp, app_fields)
check(sql('echo $p->query("SELECT COUNT(*) FROM scholarship_applications WHERE student_id=1")->fetchColumn();') == b'1', 'duplicate active application prevented')
app_id = int(sql('echo $p->query("SELECT id FROM scholarship_applications WHERE student_id=1")->fetchColumn();'))
b.get(sp, {'csrf_token': app_fields['csrf_token'], 'withdraw': '1', 'app_id': str(app_id)})
check(sql('echo $p->query("SELECT COUNT(*) FROM scholarship_applications WHERE student_id=1")->fetchColumn();') == b'0', 'student can withdraw own pending application')
b.get(sp, app_fields)
app_id = int(sql('echo $p->query("SELECT id FROM scholarship_applications WHERE student_id=1")->fetchColumn();'))
app_link = '/scholarship_application_print.php?id=' + str(app_id)
check(b.get(app_link + '&portal=student')[0] == 200, 'student prints own application')
check(b.get(app_link + '&portal=admin')[0] == 200, 'admin prints application with correct portal')
other_app = int(sql('echo $p->query("SELECT id FROM scholarship_applications WHERE student_id=2 LIMIT 1")->fetchColumn();'))
check(b.get('/scholarship_application_print.php?id=' + str(other_app) + '&portal=student')[0] == 404, 'student cannot print another family application')
raw = b.get(sap + '&view=detail&id=' + str(app_id))[1]
approval = {'csrf_token': token(raw), 'app_id': str(app_id), 'approve_app': '1', 'scholarship_type_id': '1', 'approved_amount': '500', 'settle_mode': 'Cash'}
check(b.get(sap, approval)[0] == 302, 'admin approves scholarship with cash mode')
award = json.loads(sql('echo json_encode($p->query("SELECT award_id,status FROM scholarship_applications WHERE id=' + str(app_id) + '")->fetch());'))
award_id = award['award_id']
check(award['status'] == 'Approved' and award_id, 'approval creates pending award')
b.get(sp, {'csrf_token': app_fields['csrf_token'], 'withdraw': '1', 'app_id': str(app_id)})
check(sql('echo $p->query("SELECT status FROM scholarship_applications WHERE id=' + str(app_id) + '")->fetchColumn();') == b'Approved', 'student cannot withdraw an approved application')
b.get(sap, approval)
check(sql('echo $p->query("SELECT COUNT(*) FROM scholarship_awards WHERE remarks LIKE \'%SA-%' + str(app_id).zfill(5) + '\'")->fetchColumn();') == b'1', 'repeated approval does not create another award')
dp = '/admin/index.php?page=scholarship_disbursement'
raw = b.get(dp + '&award_id=' + str(award_id) + '&mode=cash')[1]
check(b'name="adjust_amount"' in raw and b'value="0.00"' in raw, 'cash handoff defaults to zero fee adjustment')
payment = {'csrf_token': token(raw), 'confirm_disbursement': '1', 'award_id': str(award_id), 'adjust_amount': '0', 'payment_method': 'Cash'}
check(b.get(dp, payment)[0] == 302, 'cash scholarship disbursement succeeds')
b.get(dp, payment)
d = json.loads(sql('echo json_encode($p->query("SELECT COUNT(*) n,MAX(id) id,SUM(cash_amount) cash,SUM(adjusted_amount) adjusted FROM scholarship_disbursements WHERE award_id=' + str(award_id) + '")->fetch());'))
check(d['n'] == 1 and float(d['cash']) == 500 and float(d['adjusted']) == 0, 'duplicate disbursement prevented and totals correct')
receipt = '/scholarship_receipt_print.php?id=' + str(d['id'])
check(b.get(receipt + '&portal=student')[0] == 200 and b'Student Copy' in b.get(receipt + '&portal=student')[1], 'student prints own scholarship receipt')
check(b.get(receipt + '&portal=admin')[0] == 200 and b'Office Copy' in b.get(receipt + '&portal=admin')[1], 'admin prints office scholarship receipt')
check(b.get('/scholarship_receipt_print.php?app=' + str(app_id) + '&portal=student')[0] == 200, 'receipt can also be opened from application')

# Fee settlement uses the existing accounting model, and its sum matches receipt lines.
fee_award = int(sql("echo demo_insert($p,'scholarship_awards',['student_id'=>2,'scholarship_type_id'=>1,'session_id'=>1,'class_id'=>2,'amount'=>250,'status'=>'Pending','remarks'=>'CI fee accounting']);"))
raw = b.get(dp + '&award_id=' + str(fee_award) + '&mode=fee')[1]
check(b.get(dp, {**payment, 'csrf_token': token(raw), 'award_id': str(fee_award), 'adjust_amount': '250'})[0] == 302, 'fee adjustment disbursement succeeds')
totals = json.loads(sql('echo json_encode($p->query("SELECT d.id,d.total_amount,d.adjusted_amount,d.cash_amount,COALESCE(SUM(l.amount),0) line_total,COALESCE(SUM(fc.amount),0) collected FROM scholarship_disbursements d LEFT JOIN scholarship_disbursement_lines l ON l.disbursement_id=d.id LEFT JOIN finance_fee_collections fc ON fc.id=l.finance_collection_id WHERE d.award_id=' + str(fee_award) + ' GROUP BY d.id")->fetch());'))
check(float(totals['adjusted_amount']) > 0 and float(totals['adjusted_amount']) == float(totals['line_total']) == float(totals['collected']) and float(totals['total_amount']) == float(totals['adjusted_amount']) + float(totals['cash_amount']), 'fee collection, receipt lines and adjusted/cash totals agree')
check(b.get('/scholarship_receipt_print.php?id=' + str(totals['id']) + '&portal=student')[0] == 404, 'student cannot print another student receipt')

# Adversarial branch/owner records only in this visitor's database.
fixtures = json.loads(sql("demo_insert($p,'branches',array_merge($p->query('SELECT * FROM branches WHERE id=1')->fetch(),['id'=>900010,'branch_name'=>'CI Other Branch','short_name'=>'CI Other']));$student=$p->query('SELECT * FROM students WHERE id=2')->fetch();$student['id']=900010;$student['student_id']='CI-PRIVATE';$student['branch_id']=900010;demo_insert($p,'students',$student);$a=demo_insert($p,'scholarship_applications',['student_id'=>900010,'session_id'=>1,'class_id'=>2,'applicant_name'=>'CI Foreign Family']);$q=demo_insert($p,'teacher_question_papers',['branch_id'=>900010,'teacher_id'=>1,'class_id'=>1,'subject_id'=>1,'title'=>'CI Foreign Question','file_path'=>" + json.dumps(q['file_path']) + ",'file_name'=>'paper.pdf','file_ext'=>'pdf']);$other=demo_insert($p,'teacher_question_papers',['branch_id'=>1,'teacher_id'=>2,'class_id'=>1,'subject_id'=>1,'title'=>'CI Other Teacher','file_path'=>" + json.dumps(q['file_path']) + ",'file_name'=>'paper.pdf','file_ext'=>'pdf']);echo json_encode([$a,$q,$other]);"))
foreign_app, foreign_question, other_teacher = fixtures
check(b.get('/scholarship_application_print.php?id=' + str(foreign_app) + '&portal=admin')[0] == 404, 'branch admin cannot print another branch application')
check(b'CI Foreign Family' not in b.get(sap + '&view=detail&id=' + str(foreign_app))[1], 'branch admin cannot read foreign application details')
b.get(sap, {**approval, 'app_id': str(foreign_app)})
check(sql('echo $p->query("SELECT status FROM scholarship_applications WHERE id=' + str(foreign_app) + '")->fetchColumn();') == b'Pending', 'branch admin cannot approve foreign application')
for qid_other in [foreign_question, other_teacher]:
    check(b.get('/teacher_question_file.php?id=' + str(qid_other) + '&portal=teacher')[0] == 404, 'teacher cannot download foreign branch/owner question')
check(b.get('/teacher_question_file.php?id=' + str(foreign_question) + '&portal=admin')[0] == 404, 'branch admin cannot download another branch question')
b.get(ap, {**review, 'q_id': str(foreign_question)})
check(sql('echo $p->query("SELECT status FROM teacher_question_papers WHERE id=' + str(foreign_question) + '")->fetchColumn();') == b'Pending', 'branch admin cannot review foreign question')
other = Browser(); other.get('/teacher/')
check(other.get(link + '&portal=teacher')[1] != pdf, 'another demo visitor cannot download private file')
for path in ['/admin/teacher_questions.php', '/admin/scholarship_applications.php', '/student/scholarship_apply.php', '/admin/finance_voucher_print.php', '/admin/scholarship_disbursement.php']:
    check(b.get(path)[0] in (403, 404), 'direct controller blocked: ' + path)
check(Browser().get(app_link + '&portal=student')[0] == 403, 'anonymous application printing denied')
check(Browser().get(receipt + '&portal=admin')[0] == 403, 'anonymous receipt printing denied')

for role, table, key in [('teacher', 'employees', 'teacher_id'), ('student', 'students', 'student_pk')]:
    sql("$p->exec('UPDATE " + table + " SET must_change_password=1 WHERE id=1');")
    try:
        output = sql("$_SESSION=['" + role + "_logged'=>true,'" + key + "'=>1];erp_portal_require_role($p," + json.dumps(role) + ",'teacher_questions');echo 'UNEXPECTED_ACCESS';")
        check(b'UNEXPECTED_ACCESS' not in output, role + ' initial-password requirement protects new endpoints')
    finally: sql("$p->exec('UPDATE " + table + " SET must_change_password=0 WHERE id=1');")
sql("perm_ensure_tables($p);$p->exec('UPDATE system_users SET permissions_restricted=1 WHERE id=1');")
try:
    for permission in ['teacher_questions', 'scholarship_applications', 'scholarship_disbursement']:
        output = sql("$_SESSION=['admin_logged'=>true,'admin_user'=>'demo_staff','is_super_admin'=>false,'system_user_id'=>1];erp_portal_require_role($p,'admin'," + json.dumps(permission) + ");echo 'UNEXPECTED_ACCESS';")
        check(b'UNEXPECTED_ACCESS' not in output, 'restricted admin requires permission: ' + permission)
finally: sql("$p->exec('UPDATE system_users SET permissions_restricted=0 WHERE id=1');")
print('Batch 2 scholarship, accounting, print and confidential-file checks completed.', flush=True)
