<?php
// admin/result_lock_control.php - Result Module > Exam Settings > Result Lock/Unlock
// একটা Session+Class+Term-এর রেজাল্ট একবার চূড়ান্ত হয়ে গেলে (Tabulation Sheet/Report Card
// Print/Publish করার পর) Admin এখান থেকে Lock করে দিতে পারেন — Lock করা থাকলে Marks Entry
// (Exam) ও Marks Entry (Subject) দুই পেজেই আর কোনো Teacher/Admin মার্ক সেভ/এডিট করতে পারবে
// না, যতক্ষণ না আবার Unlock করা হয়। প্রতিটা Lock/Unlock action-এর সময়+কে করলো তার ইতিহাস
// result_lock_audit টেবিলে থেকে যায় (accountability-র জন্য)।
//
// তিনভাবে Lock হয়:
//   ১) ম্যানুয়াল Lock — বাটনে ক্লিক করে, যেকোনো সময়।
//   ২) অটো Lock — Class+Term-এর জন্য তারিখ সিলেক্ট করে দিলে সেই তারিখের পর (পরদিন থেকে) নিজে নিজে Lock হয়ে যায়
//      (একটা Term-এর সব Class-এ একসাথেও তারিখ বসানো যায়)।
//   ৩) Unlock সবসময়ই ম্যানুয়ালি করা যায় — Unlock করলে ঐ Class+Term-এর অটো-লক তারিখ মুছে যায়, যাতে আবার নিজে নিজে Lock না হয়;
//      চাইলে নতুন তারিখ দেওয়া যায়।

require_once __DIR__ . '/../config/report_card_helpers.php';
rc_lock_ensure_tables($pdo);

function lcv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

// Lock স্ট্যাটাস + Audit — AJAX (পেজ রিলোড ছাড়া) আর প্রথম লোড দুই জায়গাতেই একই ডেটা
function lc_build_state(PDO $pdo, int $session_id): array {
    $locks = [];
    $audit = [];
    if ($session_id) {
        rc_apply_auto_locks($pdo, $session_id); // তারিখ পার হওয়া Class+Term থাকলে এখনই Lock
        $lq = $pdo->prepare("SELECT class_id, term_id, status, auto_lock_date FROM result_locks WHERE session_id = ?");
        $lq->execute([$session_id]);
        foreach ($lq->fetchAll() as $r) {
            $locks[$r['class_id'] . '_' . $r['term_id']] = [
                'locked' => $r['status'] === 'Locked',
                'date'   => $r['auto_lock_date'] ?: '',
                'date_fmt' => $r['auto_lock_date'] ? date('d M Y', strtotime($r['auto_lock_date'])) : '',
            ];
        }
        $aq = $pdo->prepare(
            "SELECT rla.action, rla.acted_by, rla.acted_at, ac.class_name, et.term_name FROM result_lock_audit rla
             LEFT JOIN academic_classes ac ON ac.id = rla.class_id
             LEFT JOIN exam_terms et ON et.id = rla.term_id
             WHERE rla.session_id = ? ORDER BY rla.acted_at DESC, rla.id DESC LIMIT 30"
        );
        $aq->execute([$session_id]);
        $audit = $aq->fetchAll(PDO::FETCH_ASSOC);
    }
    return ['locks' => $locks, 'audit' => $audit];
}

// -----------------------------------------------------------
// AJAX Handler — Lock/Unlock টগল, অটো-লক তারিখ সেট, স্ট্যাটাস রিফ্রেশ (সবই রিলোড ছাড়া)
// -----------------------------------------------------------
if (isset($_REQUEST['lc_ajax'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'msg' => ''];
    $op = (string) $_REQUEST['lc_ajax'];
    $session_id = (int) ($_REQUEST['session_id'] ?? 0);

    if ($op !== 'state' && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? null))) {
        $out['msg'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        echo json_encode($out, JSON_UNESCAPED_UNICODE); exit();
    }

    if ($op === 'toggle') {
        $class_id = (int) ($_POST['class_id'] ?? 0);
        $term_id  = (int) ($_POST['term_id'] ?? 0);
        $lock_to  = (int) ($_POST['lock_to'] ?? 0) === 1;
        if (!$session_id || !$class_id || !$term_id) {
            $out['msg'] = 'Session, Class ও Term সিলেক্ট করুন।';
        } else {
            $actor = $_SESSION['admin_user'] ?? 'admin';
            rc_set_result_lock($pdo, $session_id, $class_id, $term_id, $lock_to, $actor);
            sec_log_activity($pdo, 'Result', 'Update', ($lock_to ? 'Result Locked' : 'Result Unlocked') . " — Class/Term ID {$class_id}/{$term_id}");
            $out['ok'] = true;
            $out['msg'] = $lock_to ? 'রেজাল্ট Lock করা হয়েছে — এখন থেকে মার্ক এন্ট্রি/এডিট বন্ধ থাকবে।' : 'রেজাল্ট Unlock করা হয়েছে — মার্ক এন্ট্রি/এডিট আবার চালু হলো।';
        }
    } elseif ($op === 'date') {
        $term_id  = (int) ($_POST['term_id'] ?? 0);
        $class_id = (int) ($_POST['class_id'] ?? 0); // 0 = সব Class
        $date     = trim($_POST['auto_lock_date'] ?? '');
        $date_ok  = $date === '' || (bool) DateTime::createFromFormat('Y-m-d', $date);
        if (!$session_id || !$term_id) {
            $out['msg'] = 'Session ও Term সিলেক্ট করুন।';
        } elseif (!$date_ok) {
            $out['msg'] = 'সঠিক তারিখ সিলেক্ট করুন।';
        } else {
            $targets = $class_id > 0
                ? [$class_id]
                : array_map('intval', $pdo->query("SELECT id FROM academic_classes WHERE status = 'Active'")->fetchAll(PDO::FETCH_COLUMN));
            rc_set_result_auto_lock_date($pdo, $session_id, $targets, $term_id, $date);
            sec_log_activity($pdo, 'Result', 'Update', ($date === '' ? 'Auto-lock তারিখ মুছে ফেলা হয়েছে' : "Auto-lock তারিখ সেট: {$date}") . " — Term ID {$term_id}, " . ($class_id > 0 ? "Class ID {$class_id}" : 'সব Class'));
            $out['ok'] = true;
            $out['msg'] = $date === ''
                ? 'অটো-লক তারিখ মুছে ফেলা হয়েছে।'
                : 'অটো-লক তারিখ সেট হয়েছে — ' . date('d M Y', strtotime($date)) . ' তারিখের পর (পরদিন থেকে) ' . ($class_id > 0 ? 'এই Class-এর' : 'সব Class-এর') . ' মার্ক এন্ট্রি অটো Lock হয়ে যাবে।';
        }
    } elseif ($op === 'state') {
        $out['ok'] = true;
    } else {
        $out['msg'] = 'অজানা অ্যাকশন।';
    }
    if ($out['ok'] || $op === 'state') {
        $out += lc_build_state($pdo, $session_id);
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit();
}

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();

// বর্তমান সেশনের সব Class x Term কম্বিনেশনের Lock স্ট্যাটাস — একসাথে টেবিল আকারে
$view_session_id = (int) ($_GET['session_id'] ?? $current_session_id);

$terms = [];
if ($view_session_id) {
    $t = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
    $t->execute([$view_session_id]);
    $terms = $t->fetchAll();
}

$lc_state = lc_build_state($pdo, $view_session_id);
?>
<style>
    .lc-header h4 { color: #1e3a8a; font-weight: bold; }
    .lc-status-badge { padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 700; }
    .lc-locked { background: #fee2e2; color: #991b1b; }
    .lc-unlocked { background: #dcfce7; color: #166534; }
    .lc-table th, .lc-table td { vertical-align: middle; text-align: center; }
</style>

<div id="lcAlert" class="alert py-2 d-none" role="alert"></div>

<div class="card-custom">
    <form method="GET" action="index.php" class="mb-3">
        <input type="hidden" name="page" value="result_lock_control">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold">Session</label>
                <select name="session_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $view_session_id === (int) $s['id'] ? 'selected' : ''; ?>><?php echo lcv($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>

    <?php if (!$terms || !$classes): ?>
        <div class="alert alert-warning">এই সেশনের জন্য কোনো Exam Term বা Class পাওয়া যায়নি।</div>
    <?php else: ?>
        <div class="lc-header mb-2"><h4><i class="fa-solid fa-lock"></i> Class × Term ভিত্তিক Lock স্ট্যাটাস</h4></div>
        <div class="table-responsive">
            <table class="table table-bordered lc-table">
                <thead class="table-light">
                    <tr>
                        <th>Class</th>
                        <?php foreach ($terms as $t): ?>
                            <th>
                                <?php echo lcv($t['term_name']); ?>
                                <div class="input-group input-group-sm mt-1" style="min-width:190px;">
                                    <input type="date" class="form-control lc-bulk-date" data-term="<?php echo (int) $t['id']; ?>" title="এই তারিখের পর সব Class অটো Lock হবে">
                                    <button type="button" class="btn btn-outline-primary lc-bulk-save" data-term="<?php echo (int) $t['id']; ?>" title="সব Class-এ তারিখ বসান"><i class="fa-solid fa-calendar-check"></i> সব</button>
                                </div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classes as $c): ?>
                        <tr>
                            <td class="fw-bold text-start"><?php echo lcv($c['class_name']); ?></td>
                            <?php foreach ($terms as $t): ?>
                                <td class="lc-cell" data-class="<?php echo (int) $c['id']; ?>" data-term="<?php echo (int) $t['id']; ?>"></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="form-text">
            <ul class="mb-0 ps-3">
                <li><strong>ম্যানুয়াল:</strong> Locked/Unlocked বাটনে ক্লিক করলেই টগল হয় — Lock/Unlock যেকোনো সময় নিজে করা যায়। Locked অবস্থায় ঐ Class-এর ঐ Term-এ কেউ মার্ক এন্ট্রি/এডিট করতে পারবে না।</li>
                <li><strong>অটো:</strong> তারিখ সিলেক্ট করে সেভ করলে সেই তারিখের পর (পরদিন থেকে) ঐ Class+Term নিজে নিজে Lock হয়ে যাবে। হেডারের "সব" বাটনে ঐ Term-এর সব Class-এ একসাথে তারিখ বসে।</li>
                <li>অটো-Lock হয়ে যাওয়ার পরও চাইলে ম্যানুয়ালি Unlock করা যাবে। Unlock করলে ঐ Class-এর অটো-লক তারিখ মুছে যায় (আবার নিজে নিজে Lock হবে না) — চাইলে নতুন তারিখ দিন।</li>
            </ul>
        </div>
    <?php endif; ?>
</div>

<div class="card-custom mt-3">
    <h5 class="section-heading"><i class="fa-solid fa-clock-rotate-left"></i> Lock/Unlock History (সর্বশেষ ৩০টা)</h5>
    <p class="text-muted text-center m-0" id="lcAuditEmpty">এখনো কোনো Lock/Unlock action হয়নি।</p>
    <div class="table-responsive" id="lcAuditWrap" style="display:none">
        <table class="table table-bordered lc-table">
            <thead class="table-light"><tr><th>Class</th><th>Term</th><th>Action</th><th>By</th><th>Date/Time</th></tr></thead>
            <tbody id="lcAuditBody"></tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const SESSION_ID = <?php echo (int) $view_session_id; ?>;
    const CSRF = <?php echo json_encode(csrf_token()); ?>;
    let state = <?php echo json_encode($lc_state, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const busy = {};
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    function alertMsg(ok, msg) {
        const el = document.getElementById('lcAlert');
        if (!el || !msg) return;
        el.className = 'alert py-2 ' + (ok ? 'alert-success' : 'alert-danger');
        el.textContent = msg;
        clearTimeout(alertMsg.t);
        alertMsg.t = setTimeout(() => el.classList.add('d-none'), 5000);
    }

    function cellHtml(cid, tid) {
        const st = state.locks[cid + '_' + tid] || {locked: false, date: '', date_fmt: ''};
        const L = st.locked;
        let note = '';
        if (st.date && !L) note = '<div class="small text-warning-emphasis mt-1"><i class="fa-regular fa-clock"></i> ' + esc(st.date_fmt) + ' এর পর অটো Lock</div>';
        else if (st.date && L) note = '<div class="small text-muted mt-1">অটো-লক তারিখ: ' + esc(st.date_fmt) + '</div>';
        return '<button type="button" class="btn btn-sm fw-bold lc-toggle ' + (L ? 'btn-outline-danger' : 'btn-outline-success') + '">' +
            '<span class="lc-status-badge ' + (L ? 'lc-locked' : 'lc-unlocked') + '"><i class="fa-solid ' + (L ? 'fa-lock' : 'fa-lock-open') + '"></i> ' + (L ? 'Locked' : 'Unlocked') + '</span></button>' +
            '<div class="input-group input-group-sm mt-2" style="min-width:170px;">' +
            '<input type="date" class="form-control lc-date" value="' + esc(st.date) + '" title="এই তারিখের পর অটো Lock হবে">' +
            '<button type="button" class="btn btn-outline-secondary lc-date-save" title="অটো-লক তারিখ সেভ (ফাঁকা = মুছে ফেলুন)"><i class="fa-solid fa-floppy-disk"></i></button></div>' + note;
    }

    function render() {
        document.querySelectorAll('.lc-cell').forEach(td => {
            // ইউজার তারিখ টাইপ/বাছাই করার মাঝখানে থাকলে সেই সেল ওভাররাইট করি না
            if (td.contains(document.activeElement) && document.activeElement.classList.contains('lc-date')) return;
            td.innerHTML = cellHtml(td.dataset.class, td.dataset.term);
        });
        const body = document.getElementById('lcAuditBody');
        if (body) {
            document.getElementById('lcAuditEmpty').style.display = state.audit.length ? 'none' : '';
            document.getElementById('lcAuditWrap').style.display = state.audit.length ? '' : 'none';
            body.innerHTML = state.audit.map(a => '<tr><td>' + esc(a.class_name) + '</td><td>' + esc(a.term_name) + '</td><td class="fw-bold ' +
                (['Locked', 'Auto Locked'].includes(a.action) ? 'text-danger' : 'text-success') + '">' + esc(a.action) + '</td><td>' + esc(a.acted_by) + '</td><td>' + esc(a.acted_at) + '</td></tr>').join('');
        }
    }

    function call(op, data) {
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('session_id', SESSION_ID);
        Object.keys(data || {}).forEach(k => fd.append(k, data[k]));
        return fetch('index.php?page=result_lock_control&lc_ajax=' + op, {method: 'POST', body: fd, credentials: 'same-origin'})
            .then(r => r.json());
    }

    function apply(res) {
        alertMsg(res.ok, res.msg);
        if (res.locks) { state.locks = res.locks; state.audit = res.audit || []; render(); }
    }

    document.addEventListener('click', function (e) {
        const tg = e.target.closest('.lc-toggle');
        const sv = e.target.closest('.lc-date-save');
        const bk = e.target.closest('.lc-bulk-save');
        if (tg || sv) {
            const td = (tg || sv).closest('.lc-cell');
            const cid = td.dataset.class, tid = td.dataset.term, key = cid + '_' + tid;
            if (busy[key]) return;
            if (tg) {
                const L = (state.locks[key] || {}).locked;
                if (!confirm(L ? 'রেজাল্ট Unlock করবেন? এরপর আবার মার্ক এডিট করা যাবে।' : 'রেজাল্ট Lock করবেন? এরপর আর মার্ক এডিট করা যাবে না।')) return;
                busy[key] = true; tg.disabled = true;
                call('toggle', {class_id: cid, term_id: tid, lock_to: L ? 0 : 1}).then(apply)
                    .catch(() => alertMsg(false, 'নেটওয়ার্ক সমস্যা — আবার চেষ্টা করুন।')).finally(() => { busy[key] = false; render(); });
            } else {
                busy[key] = true; sv.disabled = true;
                call('date', {class_id: cid, term_id: tid, auto_lock_date: td.querySelector('.lc-date').value}).then(res => { document.activeElement && document.activeElement.blur(); apply(res); })
                    .catch(() => alertMsg(false, 'নেটওয়ার্ক সমস্যা — আবার চেষ্টা করুন।')).finally(() => { busy[key] = false; render(); });
            }
        } else if (bk) {
            const tid = bk.dataset.term;
            const inp = document.querySelector('.lc-bulk-date[data-term="' + tid + '"]');
            if (!confirm('এই Term-এর সব Class-এ এই অটো-লক তারিখ বসাবেন? (ফাঁকা রাখলে সব Class-এর তারিখ মুছে যাবে)')) return;
            bk.disabled = true;
            call('date', {class_id: 0, term_id: tid, auto_lock_date: inp.value}).then(apply)
                .catch(() => alertMsg(false, 'নেটওয়ার্ক সমস্যা — আবার চেষ্টা করুন।')).finally(() => { bk.disabled = false; });
        }
    });

    render();
    // পেজ খোলা থাকলেও তারিখ পার হলে অটো-লক স্ট্যাটাস নিজে নিজে হালনাগাদ হবে (প্রতি ৩০ সেকেন্ডে, রিলোড ছাড়া)
    setInterval(function () {
        if (document.hidden) return;
        fetch('index.php?page=result_lock_control&lc_ajax=state&session_id=' + SESSION_ID, {credentials: 'same-origin'})
            .then(r => r.json()).then(res => { if (res.locks) { state.locks = res.locks; state.audit = res.audit || []; render(); } }).catch(() => {});
    }, 30000);
})();
</script>
