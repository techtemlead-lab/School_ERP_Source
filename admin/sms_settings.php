<?php
// admin/sms_settings.php - Settings (Global Settings > Settings)
require_once __DIR__ . '/../config/sms.php';
require_once __DIR__ . '/../config/home_messages.php';
sms_ensure_schema($pdo);
hm_ensure_schema($pdo);

$sms_msg = $_SESSION['sms_msg'] ?? '';
$sms_err = $_SESSION['sms_err'] ?? '';
unset($_SESSION['sms_msg'], $_SESSION['sms_err']);

// কোন সেকশন দেখানো হবে — সাবমেনু থেকে ?tab=home / sms_toggle / content / api আসবে
require_once __DIR__ . '/../config/sms.php'; // sms_template_registry() ইত্যাদি
$allowed_tabs = ['home', 'sms_toggle', 'content', 'api'];
$active_tab = $_GET['tab'] ?? 'home';
if (!in_array($active_tab, $allowed_tabs, true)) {
    $active_tab = 'home';
}

// -----------------------------------------------------------
// ফিল্ড গ্রুপ — প্রতিটা সেকশন আলাদা ফর্ম, আলাদা Update বাটন,
// শুধু নিজের কলামগুলোই আপডেট করে — বাকি ডেটা টাচ হয় না
// -----------------------------------------------------------
$home_toggle_fields = ['show_teachers_home', 'show_teachers_mobile', 'show_teachers_address', 'show_teachers_email', 'show_count_home', 'show_video_home', 'show_gallery_home', 'show_notices_home', 'show_events_home', 'show_section_filter'];
$sms_toggle_fields  = ['sms_admission', 'sms_congratulation', 'sms_punch_in', 'sms_punch_out', 'sms_add_employee', 'sms_absent', 'sms_due', 'sms_fee_collection'];
$content_fields     = ['content_admission', 'content_congratulation', 'content_teacher_add', 'content_punch_in', 'content_punch_out', 'content_absent', 'content_due', 'content_fee_collection'];
$api_fields         = ['api_provider', 'api_method', 'api_url', 'api_token', 'api_user', 'api_sender_id', 'balance_api_url', 'sms_buy'];

// সব টগল ফিল্ড এক জায়গায় (settings লোড করার সময় ব্যবহার হয়)
$all_toggle_fields = array_merge($home_toggle_fields, $sms_toggle_fields);

// একটা সেকশন সেভ করার জেনেরিক হ্যান্ডলার
function save_sms_section(PDO $pdo, array $toggle_fields, array $text_fields, bool $has_api_enabled = false, string $section_label = 'SMS Settings'): void
{
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sms_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        return;
    }
    $set_parts = [];
    $params = [];
    foreach ($toggle_fields as $f) {
        $set_parts[] = "$f = ?";
        $params[] = isset($_POST[$f]) ? 1 : 0;
    }
    foreach ($text_fields as $f) {
        $set_parts[] = "$f = ?";
        $val = trim($_POST[$f] ?? '');
        // টোকেন/URL-এ কোনো ফাঁকা স্পেস বা নতুন লাইন থাকে না (কপি-পেস্টে ঢুকে গেলে গেটওয়ে টোকেন চিনতে পারে না)
        if (in_array($f, ['api_token', 'api_url', 'balance_api_url'], true)) { $val = preg_replace('/\s+/', '', $val); }
        $params[] = $val;
    }
    if ($has_api_enabled) {
        $set_parts[] = "api_enabled = ?";
        $params[] = isset($_POST['api_enabled']) ? 1 : 0;
    }
    $sql = "UPDATE sms_settings SET " . implode(', ', $set_parts) . " WHERE id = 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $_SESSION['sms_msg'] = 'Settings সফলভাবে সেভ হয়েছে!';
    sec_log_activity($pdo, 'SMS', 'Update', "{$section_label} আপডেট করা হয়েছে।");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_role_action'])) {
    // পদবি যোগ/মোছা — পেজ রিলোড ছাড়া (fetch) কাজ করে, JSON ফেরত দেয়
    while (ob_get_level() > 0) { ob_end_clean(); } // admin/index.php আগেই HTML বাফার করে রাখে, সেটা ফেলে দিয়ে শুধু JSON
    header('Content-Type: application/json; charset=utf-8');
    $resp = ['ok' => false, 'msg' => 'অনুরোধ সম্পূর্ণ করা যায়নি।'];
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $resp['msg'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif ($_POST['ajax_role_action'] === 'add') {
        $key = hm_add_role($pdo, (string) ($_POST['label'] ?? ''));
        if ($key === null) {
            $resp['msg'] = 'পদবিটি যোগ করা যায়নি — নাম খালি, ১০০ অক্ষরের বেশি, অথবা এই নামে আগে থেকেই আছে।';
        } else {
            $rs = hm_get_role_settings($pdo);
            $resp = ['ok' => true, 'key' => $key, 'label' => hm_role_text(hm_roles()[$key]), 'order' => (int) $rs[$key]['sort_order'], 'msg' => 'নতুন পদবি যোগ হয়েছে।'];
            sec_log_activity($pdo, 'Website', 'Create', 'Message পদবি যোগ করা হয়েছে: ' . $resp['label']);
        }
    } elseif ($_POST['ajax_role_action'] === 'delete') {
        if (hm_delete_role($pdo, (string) ($_POST['role_key'] ?? ''))) {
            $resp = ['ok' => true, 'msg' => 'পদবি মুছে ফেলা হয়েছে।'];
            sec_log_activity($pdo, 'Website', 'Delete', 'Message পদবি মুছে ফেলা হয়েছে।');
        } else {
            $resp['msg'] = 'শুধু নিজের যোগ করা পদবি মোছা যায়।';
        }
    }
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_home_settings']) || isset($_POST['delete_role'])) {
        // ড্রপডাউনে "below_hero"/"above_hero" ছাড়া অন্য কিছু (বা কিছুই না) এলে যেন
        // ডাটাবেজে ভুল/খালি ভ্যালু সেভ না হয়ে যায় — নাহলে সাইটে অ্যাসাম্পশন ভেঙে যাবে
        $_POST['breaking_news_position'] = ($_POST['breaking_news_position'] ?? '') === 'below_hero' ? 'below_hero' : 'above_hero';
        save_sms_section($pdo, $home_toggle_fields, ['breaking_news_position'], false, 'Home Page Settings');
        // বাণী (Message Corner) — কোন পদবির বাণী দেখাবে + কোনটা আগে (CSRF ঠিক থাকলেই সেভ)
        if (csrf_verify($_POST['csrf_token'] ?? null)) {
            hm_save_role_settings(
                $pdo,
                is_array($_POST['role_show'] ?? null) ? $_POST['role_show'] : [],
                is_array($_POST['role_order'] ?? null) ? $_POST['role_order'] : []
            );
            // নতুন পদবি যোগ (নাম লেখা থাকলে)
            $new_role = trim($_POST['new_role_name'] ?? '');
            if ($new_role !== '') {
                if (hm_add_role($pdo, $new_role) === null) {
                    $_SESSION['sms_err'] = 'পদবিটি যোগ করা যায়নি — নাম খালি, ১০০ অক্ষরের বেশি, অথবা এই নামে আগে থেকেই আছে।';
                    unset($_SESSION['sms_msg']);
                }
            }
            // কাস্টম পদবি মোছা
            if (!empty($_POST['delete_role'])) {
                hm_delete_role($pdo, (string) $_POST['delete_role']);
            }
        }
        header('Location: index.php?page=sms_settings&tab=home');
        exit();
    }
    if (isset($_POST['save_sms_toggle_settings'])) {
        save_sms_section($pdo, $sms_toggle_fields, [], false, 'SMS Settings (On/Off)');
        header('Location: index.php?page=sms_settings&tab=sms_toggle');
        exit();
    }
    if (isset($_POST['save_sms_content_settings'])) {
        // প্রতিটা ইভেন্টের আলাদা টেমপ্লেট (tpl[event_key]) — আগের ৮টা sms_settings.content_* কলামে, নতুনগুলো sms_templates টেবিলে
        $tpl_post = is_array($_POST['tpl'] ?? null) ? $_POST['tpl'] : [];
        $en_post  = is_array($_POST['tpl_enabled'] ?? null) ? $_POST['tpl_enabled'] : [];
        foreach (sms_template_registry() as $tk => $tdef) {
            if ($tdef['legacy'] !== null) {
                // ফাঁকা সেভ করলে ডিফল্ট টেক্সটই ব্যবহার হবে (sms_template_get()) — তাই এখানে যা এলো তাই রাখা
                $_POST[$tdef['legacy']] = (string) ($tpl_post[$tk] ?? '');
            }
        }
        save_sms_section($pdo, [], $content_fields, false, 'SMS Template Settings');
        if (csrf_verify($_POST['csrf_token'] ?? null)) {
            foreach (sms_template_registry() as $tk => $tdef) {
                if ($tdef['legacy'] === null && array_key_exists($tk, $tpl_post)) {
                    sms_template_save_new($pdo, $tk, (string) $tpl_post[$tk], isset($en_post[$tk]));
                }
            }
        }
        header('Location: index.php?page=sms_settings&tab=content');
        exit();
    }
    if (isset($_POST['save_sms_api_settings'])) {
        save_sms_section($pdo, [], $api_fields, true, 'SMS Gateway Settings');
        header('Location: index.php?page=sms_settings&tab=api');
        exit();
    }
    if (isset($_POST['check_sms_balance'])) {
        if (!csrf_verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['sms_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        } else {
            $result = sms_check_balance($pdo);
            if (!empty($result['simulated'])) {
                $_SESSION['sms_err'] = 'ব্যালেন্স আসল নয় — এই সার্ভারে SMS/Integration বন্ধ আছে (ERP_INTEGRATIONS_ENABLED=0), তাই গেটওয়ে থেকে আসলে ব্যালেন্স আনা হয়নি। সার্ভারের Administrator-কে চালু করতে বলুন।';
            } elseif ($result['success']) {
                $_SESSION['sms_msg'] = 'বর্তমান SMS ব্যালেন্সঃ ' . $result['balance'];
            } else {
                $_SESSION['sms_err'] = 'ব্যালেন্স চেক করা যায়নি — ' . $result['response'];
            }
        }
        header('Location: index.php?page=sms_settings&tab=api');
        exit();
    }
}

// -----------------------------------------------------------
// Test SMS পাঠানো (API ঠিকমতো কানেক্ট হচ্ছে কিনা চেক করতে)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_sms'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sms_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $test_phone = trim($_POST['test_phone'] ?? '');
        if ($test_phone === '') {
            $_SESSION['sms_err'] = 'Test SMS পাঠাতে একটা মোবাইল নম্বর দিন।';
        } else {
            $result = send_sms($pdo, $test_phone, 'This is a test SMS from School ERP.');
            if (!empty($result['simulated'])) {
                $_SESSION['sms_err'] = 'Test SMS আসলে পাঠানো হয়নি — এই সার্ভারে SMS/Integration বন্ধ আছে (ERP_INTEGRATIONS_ENABLED=0)। সার্ভারের Administrator-কে চালু করতে বলুন, তারপর আবার টেস্ট করুন।';
            } elseif ($result['success']) {
                $_SESSION['sms_msg'] = 'Test SMS পাঠানো হয়েছে। গেটওয়ে রেসপন্সঃ ' . $result['response'];
                sec_log_activity($pdo, 'SMS', 'Send', "Test SMS পাঠানো হয়েছে: {$test_phone}");
            } else {
                $_SESSION['sms_err'] = 'Test SMS পাঠানো যায়নি — ' . $result['response'];
            }
        }
    }
    header('Location: index.php?page=sms_settings&tab=api');
    exit();
}

$settings = $pdo->query("SELECT * FROM sms_settings WHERE id = 1")->fetch();
if (!$settings) {
    $settings = array_fill_keys(array_merge($all_toggle_fields, $content_fields, $api_fields, ['api_enabled']), '');
}

function sv($val): string { return htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// একটা On/Off টগল সুইচ রেন্ডার করার হেল্পার (স্ক্রিনশটের ধাঁচে — Yes নীল, No লাল)
function render_toggle(string $name, string $label, $checked): void
{
    $checked_attr = $checked ? 'checked' : '';
    echo '<div class="d-flex align-items-center gap-2 mb-3">
        <div class="form-check form-switch m-0">
            <input class="form-check-input sms-toggle" type="checkbox" role="switch" name="' . $name . '" id="' . $name . '" ' . $checked_attr . ' style="width:2.5em;height:1.3em;">
        </div>
        <label for="' . $name . '" class="mb-0 small">' . htmlspecialchars($label) . '</label>
    </div>';
}
?>

<?php if ($sms_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($sms_msg); ?></div><?php endif; ?>
<?php if ($sms_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($sms_err); ?></div><?php endif; ?>

<?php if ($active_tab === 'home'): ?>
<!-- ============================================================ -->
<!-- 1) Home Page Settings                                          -->
<!-- ============================================================ -->
<div class="card-custom mb-4" id="homeTab">
    <h4 class="fw-bold mb-4 text-dark"><i class="fa-solid fa-house"></i> Home Page Settings</h4>
    <form method="POST" action="index.php?page=sms_settings&tab=home">
        <?php echo csrf_field(); ?>
        <div class="row mb-2">
            <div class="col-md-6">
                <?php
                render_toggle('show_teachers_home', 'Show Teachers On Home Page', $settings['show_teachers_home']);
                render_toggle('show_teachers_address', 'Show Teachers Address', $settings['show_teachers_address']);
                render_toggle('show_section_filter', 'Show School / College Section Filter (Teachers & Staffs)', $settings['show_section_filter'] ?? 0);
                render_toggle('show_count_home', 'Show Count On Home page', $settings['show_count_home']);
                render_toggle('show_video_home', 'Show Video Section On Home Page', $settings['show_video_home'] ?? 1);
                render_toggle('show_notices_home', 'Show Notice On Home Page', $settings['show_notices_home'] ?? 1);
                ?>
            </div>
            <div class="col-md-6">
                <?php
                render_toggle('show_teachers_mobile', 'Show Teachers Mobile', $settings['show_teachers_mobile']);
                render_toggle('show_teachers_email', 'Show Teachers Email', $settings['show_teachers_email']);
                render_toggle('show_gallery_home', 'Show Photo Gallery On Home Page', $settings['show_gallery_home'] ?? 1);
                render_toggle('show_events_home', 'Show Event On Home Page', $settings['show_events_home'] ?? 1);
                ?>
            </div>
        </div>
        <hr>
        <div class="row mb-2">
            <div class="col-md-6">
                <label class="fw-bold small mb-2 d-block">Breaking News Bar Position</label>
                <select name="breaking_news_position" class="form-select">
                    <option value="above_hero" <?php echo ($settings['breaking_news_position'] ?? 'above_hero') === 'above_hero' ? 'selected' : ''; ?>>হিরো/স্লাইডার সেকশনের উপরে</option>
                    <option value="below_hero" <?php echo ($settings['breaking_news_position'] ?? 'above_hero') === 'below_hero' ? 'selected' : ''; ?>>হিরো/স্লাইডার সেকশনের নিচে</option>
                </select>
                <small class="text-muted">হোমপেজে Breaking News (Website Module &gt; Breaking News থেকে যোগ করা) কোথায় দেখাবে সেটা এখান থেকে ঠিক করা যাবে। সেভ করার পর ব্রাউজারে হার্ড রিফ্রেশ (Ctrl+Shift+R / মোবাইলে ব্রাউজার হিস্ট্রি-ডাটা ক্লিয়ার) দিয়ে দেখুন — পুরনো ক্যাশড পেজ থাকলে বদল বোঝা যাবে না।</small>
            </div>
        </div>
        <hr>
        <!-- বাণী (Message Corner) — কোন কোন পদবির বাণী হোমপেজে দেখাবে + দেখানোর ক্রম -->
        <?php $hm_role_settings = hm_get_role_settings($pdo); ?>
        <h6 class="fw-bold mb-1">হোমপেজে বাণী (Message) — কোন পদবির বাণী দেখাবে</h6>
        <p class="text-muted small mb-3">
            Website Module &gt; Message Corner-এ যোগ করা বাণী এখানে পদবি অনুযায়ী On/Off করা যাবে।
            <b>Order</b> ঘরে ছোট সংখ্যা মানে আগে দেখাবে (১ = সবার আগে)। হোমপেজে সর্বোচ্চ ৩টা বাণী দেখায়, বাকিগুলো "সকল" বাটনের পেজে থাকে।
        </p>
        <div class="table-responsive mb-3" style="max-width:640px;">
            <table class="table table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr><th style="width:90px;" class="text-center">দেখাবে</th><th>পদবি</th><th style="width:120px;" class="text-center">Order</th></tr>
                </thead>
                <tbody id="hmRoleBody">
                <?php $hm_all_roles = hm_roles(); foreach ($hm_role_settings as $rk => $rs): $rdef = $hm_all_roles[$rk]; ?>
                    <tr>
                        <td class="text-center">
                            <div class="form-check form-switch d-inline-block m-0">
                                <input class="form-check-input sms-toggle" type="checkbox" role="switch" name="role_show[<?php echo $rk; ?>]" id="role_show_<?php echo $rk; ?>" value="1" <?php echo $rs['is_shown'] ? 'checked' : ''; ?> style="width:2.5em;height:1.3em;">
                            </div>
                        </td>
                        <td><label for="role_show_<?php echo $rk; ?>" class="mb-0"><?php echo htmlspecialchars(hm_role_text($rdef)); ?></label>
                            <?php if (!empty($rdef['custom'])): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger float-end py-0 px-2 hm-del-role" data-key="<?php echo htmlspecialchars($rk); ?>" title="মুছুন"><i class="fa-solid fa-trash"></i></button>
                            <?php endif; ?></td>
                        <td><input type="number" min="1" max="999" name="role_order[<?php echo $rk; ?>]" class="form-control form-control-sm text-center" value="<?php echo (int) $rs['sort_order']; ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="d-flex gap-2 mb-3" style="max-width:640px;">
            <input type="text" id="hmNewRole" class="form-control" maxlength="100" placeholder="নতুন পদবি যোগ করুন — যেমনঃ সভাপতি, সম্পাদক">
            <button type="button" id="hmAddRole" class="btn btn-success text-nowrap"><i class="fa-solid fa-plus"></i> Add New</button>
        </div>
        <div id="hmRoleMsg" class="small mb-3" style="max-width:640px;display:none;"></div>
        <script>
        // পদবি যোগ/মোছা — পেজ রিলোড ছাড়া। অন্য সারিতে যা বদলানো হয়েছে (On/Off, Order) সেগুলো হারায় না;
        // সেগুলো আগের মতোই নিচের Update বাটনে সেভ হয়।
        (function () {
            var body = document.getElementById('hmRoleBody'), inp = document.getElementById('hmNewRole'),
                btn = document.getElementById('hmAddRole'), msg = document.getElementById('hmRoleMsg');
            var form = inp.form, url = form.getAttribute('action');
            function say(text, ok) {
                msg.style.display = 'block'; msg.textContent = text; msg.className = 'small mb-3 ' + (ok ? 'text-success' : 'text-danger');
            }
            function post(data) {
                data.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
                return fetch(url, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); });
            }
            function addRow(key, label, order) {
                var tr = document.createElement('tr');
                var td1 = document.createElement('td'); td1.className = 'text-center';
                var sw = document.createElement('div'); sw.className = 'form-check form-switch d-inline-block m-0';
                var cb = document.createElement('input'); cb.type = 'checkbox'; cb.className = 'form-check-input sms-toggle';
                cb.setAttribute('role', 'switch'); cb.name = 'role_show[' + key + ']'; cb.id = 'role_show_' + key; cb.value = '1'; cb.checked = true;
                cb.style.cssText = 'width:2.5em;height:1.3em;';
                sw.appendChild(cb); td1.appendChild(sw);
                var td2 = document.createElement('td');
                var lb = document.createElement('label'); lb.className = 'mb-0'; lb.htmlFor = cb.id; lb.textContent = label;
                var del = document.createElement('button'); del.type = 'button'; del.className = 'btn btn-sm btn-outline-danger float-end py-0 px-2 hm-del-role';
                del.setAttribute('data-key', key); del.title = 'মুছুন'; del.innerHTML = '<i class="fa-solid fa-trash"></i>';
                td2.appendChild(lb); td2.appendChild(del);
                var td3 = document.createElement('td');
                var num = document.createElement('input'); num.type = 'number'; num.min = 1; num.max = 999; num.name = 'role_order[' + key + ']';
                num.className = 'form-control form-control-sm text-center'; num.value = order;
                td3.appendChild(num);
                tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3);
                // "অন্যান্য" সারির ঠিক আগে বসাও (সার্ভারেও এই ক্রমই)
                var other = body.querySelector('input[name="role_order[other]"]');
                var otherRow = other ? other.closest('tr') : null;
                if (otherRow) body.insertBefore(tr, otherRow); else body.appendChild(tr);
            }
            function doAdd() {
                var label = inp.value.trim();
                if (!label) { say('পদবির নাম লিখুন।', false); inp.focus(); return; }
                btn.disabled = true;
                var d = new FormData(); d.append('ajax_role_action', 'add'); d.append('label', label);
                post(d).then(function (r) {
                    if (r.ok) { addRow(r.key, r.label, r.order); inp.value = ''; say(r.msg, true); } else { say(r.msg, false); }
                }).catch(function () { say('সংযোগে সমস্যা হয়েছে, আবার চেষ্টা করুন।', false); })
                  .then(function () { btn.disabled = false; inp.focus(); });
            }
            btn.addEventListener('click', doAdd);
            inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doAdd(); } });
            body.addEventListener('click', function (e) {
                var b = e.target.closest('.hm-del-role'); if (!b) return;
                if (!confirm("এই পদবি মুছে ফেলবেন? এই পদবির বাণীগুলো 'অন্যান্য'-তে চলে যাবে।")) return;
                var d = new FormData(); d.append('ajax_role_action', 'delete'); d.append('role_key', b.getAttribute('data-key'));
                post(d).then(function (r) {
                    if (r.ok) { b.closest('tr').remove(); say(r.msg, true); } else { say(r.msg, false); }
                }).catch(function () { say('সংযোগে সমস্যা হয়েছে, আবার চেষ্টা করুন।', false); });
            });
        })();
        </script>
        <div class="text-end">
            <button type="submit" name="save_home_settings" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;">Update</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($active_tab === 'sms_toggle'): ?>
<!-- ============================================================ -->
<!-- 2) SMS Settings (On/Off)                                       -->
<!-- ============================================================ -->
<div class="card-custom mb-4" id="smsToggleTab">
    <h4 class="fw-bold mb-4 text-dark"><i class="fa-solid fa-comment-sms"></i> SMS Settings (On/Off)</h4>
    <form method="POST" action="index.php?page=sms_settings&tab=sms_toggle">
        <?php echo csrf_field(); ?>
        <div class="row mb-2">
            <div class="col-md-6">
                <?php
                render_toggle('sms_admission', 'Students Admission SMS', $settings['sms_admission']);
                render_toggle('sms_punch_in', 'Students Punch In SMS', $settings['sms_punch_in']);
                render_toggle('sms_punch_out', 'Students Punch Out SMS', $settings['sms_punch_out']);
                render_toggle('sms_due', 'Due SMS', $settings['sms_due']);
                ?>
            </div>
            <div class="col-md-6">
                <?php
                render_toggle('sms_congratulation', 'Students Congratulation SMS ?', $settings['sms_congratulation']);
                render_toggle('sms_add_employee', 'SMS When Add Employee', $settings['sms_add_employee']);
                render_toggle('sms_absent', 'Students Absent SMS', $settings['sms_absent']);
                render_toggle('sms_fee_collection', 'Students Fee Collection SMS', $settings['sms_fee_collection']);
                ?>
            </div>
        </div>
        <div class="text-end">
            <button type="submit" name="save_sms_toggle_settings" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;">Update</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($active_tab === 'content'): ?>
<!-- ============================================================ -->
<!-- 3) SMS Template Settings — যেখান থেকে SMS যায় প্রতিটার আলাদা টেমপ্লেট -->
<!-- ============================================================ -->
<?php
$tpl_all = sms_templates_load_all($pdo);
$tpl_groups = [];
foreach (sms_template_registry() as $tk => $tdef) { $tpl_groups[$tdef['group']][$tk] = $tdef; }
?>
<style>
    .tpl-card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; margin-bottom: 14px; background: #fff; }
    .tpl-card .tpl-where { font-size: 12px; color: #64748b; margin-bottom: 8px; }
    .tpl-chip { display: inline-block; font-family: monospace; font-size: 12px; background: #eef2ff; color: #1e3a8a; border: 1px solid #c7d2fe; border-radius: 4px; padding: 1px 7px; margin: 0 4px 4px 0; cursor: pointer; }
    .tpl-chip:hover { background: #dbe4ff; }
    .tpl-count { font-size: 12px; color: #64748b; }
</style>
<div class="card-custom mb-4" id="contentTab">
    <h4 class="fw-bold mb-2 text-dark"><i class="fa-solid fa-file-lines"></i> SMS Template Settings</h4>
    <p class="text-muted small mb-3">
        সিস্টেমের যে যে জায়গা থেকে SMS যায় — প্রতিটার জন্য আলাদা টেমপ্লেট। প্লেসহোল্ডারে (নীল ট্যাগ) ক্লিক করলে কার্সরের জায়গায় বসে যাবে।
        টেমপ্লেট ফাঁকা রেখে সেভ করলে ডিফল্ট টেক্সট ব্যবহার হয়।
    </p>
    <form method="POST" action="index.php?page=sms_settings&tab=content">
        <?php echo csrf_field(); ?>
        <?php foreach ($tpl_groups as $group_label => $items): ?>
            <h6 class="fw-bold mt-4 mb-2 text-primary"><?php echo sv($group_label); ?></h6>
            <?php foreach ($items as $tk => $tdef): $cur = $tpl_all[$tk]; ?>
            <div class="tpl-card">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <label class="fw-bold small mb-1" for="tpl_<?php echo sv($tk); ?>"><?php echo sv($tdef['title']); ?></label>
                    <?php if ($tdef['legacy'] === null): ?>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" role="switch" name="tpl_enabled[<?php echo sv($tk); ?>]" id="tplen_<?php echo sv($tk); ?>" <?php echo $cur['enabled'] ? 'checked' : ''; ?>>
                            <label class="form-check-label small" for="tplen_<?php echo sv($tk); ?>">এই SMS চালু</label>
                        </div>
                    <?php else: ?>
                        <span class="small text-muted">On/Off: <a href="index.php?page=sms_settings&tab=sms_toggle">SMS Settings (On/Off)</a> ট্যাবে</span>
                    <?php endif; ?>
                </div>
                <div class="tpl-where"><i class="fa-solid fa-location-dot"></i> <?php echo sv($tdef['where']); ?></div>
                <textarea name="tpl[<?php echo sv($tk); ?>]" id="tpl_<?php echo sv($tk); ?>" class="form-control tpl-text" rows="3" data-default="<?php echo sv($tdef['default']); ?>" placeholder="<?php echo sv($tdef['default']); ?>"><?php echo $cur['is_default'] ? '' : sv($cur['content']); ?></textarea>
                <div class="d-flex justify-content-between align-items-center mt-1 flex-wrap gap-2">
                    <div>
                        <?php foreach ($tdef['placeholders'] as $ph => $ph_desc): ?>
                            <span class="tpl-chip" data-target="tpl_<?php echo sv($tk); ?>" data-ph="{<?php echo sv($ph); ?>}" title="<?php echo sv($ph_desc); ?>">{<?php echo sv($ph); ?>}</span>
                        <?php endforeach; ?>
                    </div>
                    <div class="tpl-count"><span class="tpl-len">0</span> অক্ষর · <button type="button" class="btn btn-link btn-sm p-0 tpl-reset" data-target="tpl_<?php echo sv($tk); ?>">ডিফল্ট দিন</button></div>
                </div>
                <?php if ($cur['is_default']): ?><small class="text-muted">এখন ডিফল্ট টেক্সট ব্যবহার হচ্ছে (ওপরের ঘরে ধূসর লেখা)।</small><?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <small class="text-muted d-block mb-3">
            <code>{RECEIPT_LINK}</code> (Fee Collection) টেমপ্লেটে না বসালেও মেসেজের শেষে অটো যোগ হয়ে যায় — অভিভাবক ক্লিক করলে লগইন ছাড়াই Money Receipt দেখতে পান।
            Promo/Bulk SMS ও Students/Employees SMS পেজে মেসেজ ওই পেজেই লেখা হয়, তাই এখানে আলাদা টেমপ্লেট নেই।
        </small>
        <div class="text-end">
            <button type="submit" name="save_sms_content_settings" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;">Update</button>
        </div>
    </form>
</div>
<script>
(function () {
    function updLen(t) {
        var card = t.closest('.tpl-card'); if (!card) return;
        var txt = t.value !== '' ? t.value : (t.getAttribute('data-default') || '');
        card.querySelector('.tpl-len').textContent = txt.length;
    }
    document.querySelectorAll('.tpl-text').forEach(function (t) { updLen(t); t.addEventListener('input', function () { updLen(t); }); });
    document.querySelectorAll('.tpl-chip').forEach(function (c) {
        c.addEventListener('click', function () {
            var t = document.getElementById(c.getAttribute('data-target')); if (!t) return;
            var ph = c.getAttribute('data-ph');
            var s = t.selectionStart == null ? t.value.length : t.selectionStart, e = t.selectionEnd == null ? s : t.selectionEnd;
            t.value = t.value.slice(0, s) + ph + t.value.slice(e);
            t.focus(); t.selectionStart = t.selectionEnd = s + ph.length; updLen(t);
        });
    });
    document.querySelectorAll('.tpl-reset').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = document.getElementById(b.getAttribute('data-target')); if (!t) return;
            t.value = t.getAttribute('data-default') || ''; updLen(t);
        });
    });
})();
</script>
<?php endif; ?>

<?php if ($active_tab === 'api'): ?>
<!-- ============================================================ -->
<!-- 4) SMS Gateway Settings                                        -->
<!-- ============================================================ -->
<div class="card-custom mb-4" id="apiTab">
    <h4 class="fw-bold mb-4 text-dark"><i class="fa-solid fa-satellite-dish"></i> SMS Gateway Settings</h4>
    <form method="POST" action="index.php?page=sms_settings&tab=api">
        <?php echo csrf_field(); ?>
        <div class="row mb-2">
            <div class="col-md-3 mb-3">
                <label class="fw-bold small mb-1">Provider</label>
                <select name="api_provider" class="form-select">
                    <?php foreach (['GreenWeb', 'BulkSMSBD', 'Alpha SMS', 'Other'] as $p): ?>
                        <option value="<?php echo $p; ?>" <?php echo ($settings['api_provider'] ?? '') === $p ? 'selected' : ''; ?>><?php echo $p; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 mb-3">
                <label class="fw-bold small mb-1">Method</label>
                <select name="api_method" class="form-select">
                    <?php foreach (['GET', 'POST'] as $m): ?>
                        <option value="<?php echo $m; ?>" <?php echo ($settings['api_method'] ?? 'GET') === $m ? 'selected' : ''; ?>><?php echo $m; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 mb-3">
                <label class="fw-bold small mb-1">Sender ID <span class="text-muted fw-normal">(ঐচ্ছিক)</span></label>
                <input type="text" name="api_sender_id" class="form-control" value="<?php echo sv($settings['api_sender_id'] ?? ''); ?>">
            </div>
            <div class="col-md-3 mb-3 d-flex align-items-end">
                <?php render_toggle('api_enabled', 'SMS Gateway সচল রাখো (Enable)', $settings['api_enabled'] ?? 0); ?>
            </div>
            <div class="col-md-9 mb-3">
                <label class="fw-bold small mb-1">API URL <span class="text-danger">*</span></label>
                <input type="text" name="api_url" class="form-control" placeholder="https://your-gateway.com/api.php?token={token}&to={phone}&message={message}" value="<?php echo sv($settings['api_url'] ?? ''); ?>">
                <div class="form-text">
                    আপনার গেটওয়ের ডকুমেন্টেশন থেকে URL কপি করে <code>{token}</code>, <code>{user}</code>,
                    <code>{sender_id}</code>, <code>{phone}</code>, <code>{message}</code> — এই প্লেসহোল্ডার
                    দিয়ে আসল ভ্যালুর জায়গাগুলো রিপ্লেস করে বসান। যেকোনো গেটওয়ে (GreenWeb, BulkSMSBD, Alpha SMS ইত্যাদি) সাপোর্ট করবে।
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <label class="fw-bold small mb-1">Token / API Key</label>
                <input type="text" name="api_token" class="form-control" value="<?php echo sv($settings['api_token'] ?? ''); ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="fw-bold small mb-1">User <span class="text-muted fw-normal">(লাগলে)</span></label>
                <input type="text" name="api_user" class="form-control" value="<?php echo sv($settings['api_user'] ?? ''); ?>">
            </div>
            <div class="col-md-9 mb-3">
                <label class="fw-bold small mb-1">Balance Check API URL <span class="text-muted fw-normal">(ঐচ্ছিক)</span></label>
                <input type="text" name="balance_api_url" class="form-control" placeholder="http://api.greenweb.com.bd/g_api.php?token={token}&balance&rate&json" value="<?php echo sv($settings['balance_api_url'] ?? ''); ?>">
                <div class="form-text">GreenWeb-এর জন্যঃ <code>http://api.greenweb.com.bd/g_api.php?token={token}&amp;balance&amp;rate&amp;json</code> — <code>&amp;rate</code> ছাড়া শুধু টাকার ব্যালেন্স আসবে, সাথে দিলে টাকা ÷ রেট করে বাকি SMS সংখ্যা বের করে দেখানো হয়। <code>{token}</code> এখানে অক্ষরে অক্ষরে এভাবেই থাকবে — নিজের টোকেন এখানে বসাবেন না, সেটা নিচের Token ফিল্ডে বসান।</div>
            </div>
            <div class="col-md-3 mb-3">
                <label class="fw-bold small mb-1">Total SMS Buy <span class="text-muted fw-normal">(ম্যানুয়াল)</span></label>
                <input type="number" name="sms_buy" class="form-control" value="<?php echo sv($settings['sms_buy'] ?? 0); ?>">
                <div class="form-text">রিচার্জ করার পর মোট কেনা SMS সংখ্যা এখানে আপডেট করুন — গেটওয়ে এটা আলাদাভাবে দেয় না।</div>
            </div>
        </div>
        <div class="alert alert-light border small">
            <i class="fa-solid fa-circle-info text-primary"></i>
            উদাহরণঃ<br>
            <b>GreenWeb</b> — <code>https://api.greenweb.com.bd/api.php?token={token}&to={phone}&message={message}</code><br>
            <b>BulkSMSBD</b> — <code>http://bulksmsbd.net/api/smsapi?api_key={token}&type=text&number={phone}&senderid={sender_id}&message={message}</code><br>
            পরে অন্য গেটওয়েতে সুইচ করলে শুধু এই URL ফিল্ডটা বদলালেই হবে, কোনো কোড টাচ করা লাগবে না।
        </div>
        <div class="text-end">
            <button type="submit" name="save_sms_api_settings" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;">Update</button>
        </div>
    </form>

    <!-- Balance Check -->
    <hr class="my-4">
    <h6 class="fw-bold text-primary mb-3"><i class="fa-solid fa-wallet"></i> SMS ব্যালেন্স</h6>
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <div>
            <div class="fw-bold" style="font-size: 1.4rem;"><?php echo (int) ($settings['sms_balance'] ?? 0); ?> <span class="small text-muted fw-normal">SMS</span></div>
            <div class="small text-muted">
                টাকা ব্যালেন্সঃ <?php echo number_format((float) ($settings['sms_balance_taka'] ?? 0), 2); ?> টাকা ·
                সর্বশেষ চেক করা হয়েছে:
                <?php echo !empty($settings['balance_checked_at']) ? htmlspecialchars($settings['balance_checked_at']) : 'এখনো চেক করা হয়নি'; ?>
            </div>
        </div>
        <form method="POST" action="index.php?page=sms_settings&tab=api">
            <?php echo csrf_field(); ?>
            <button type="submit" name="check_sms_balance" class="btn btn-outline-primary fw-bold"><i class="fa-solid fa-rotate"></i> Check Balance Now</button>
        </form>
    </div>

    <!-- Test SMS -->
    <hr class="my-4">
    <h6 class="fw-bold text-primary mb-3"><i class="fa-solid fa-vial"></i> Test SMS পাঠান</h6>
    <form method="POST" action="index.php?page=sms_settings&tab=api" class="row g-2 align-items-center">
        <?php echo csrf_field(); ?>
        <div class="col-sm-4">
            <input type="tel" name="test_phone" class="form-control phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="১১ ডিজিটের মোবাইল নম্বর দিন" placeholder="01XXXXXXXXX" required>
        </div>
        <div class="col-sm-3">
            <button type="submit" name="send_test_sms" class="btn btn-outline-primary fw-bold w-100">Send Test SMS</button>
        </div>
    </form>
</div>
<?php endif; ?>

<style>
/* Yes/No স্টাইল টগল সুইচ (স্ক্রিনশটের মতো নীল/লাল) */
.sms-toggle {
    background-color: #ef4444;
    border-color: #ef4444;
}
.sms-toggle:checked {
    background-color: #1e3a8a;
    border-color: #1e3a8a;
}
</style>
<script>
// ফোন নম্বর ফিল্ড (.phone-11 ক্লাস): টাইপ করার সাথে সাথেই সংখ্যা ছাড়া অন্য কিছু নেয় না, ১১ ডিজিটের বেশি হলে কেটে দেয়
document.querySelectorAll('.phone-11').forEach(function (input) {
    // প্রতিটা ফিল্ডের ঠিক পরে একটা এরর মেসেজ বসানো হচ্ছে (আগে থেকে না থাকলে)
    let errEl = input.nextElementSibling;
    if (!errEl || !errEl.classList.contains('phone11-err')) {
        errEl = document.createElement('div');
        errEl.className = 'small text-danger mt-1 d-none phone11-err';
        errEl.textContent = 'মোবাইল নম্বর অবশ্যই ১১ ডিজিটের হতে হবে।';
        input.insertAdjacentElement('afterend', errEl);
    }

    function checkPhone11() {
        let digits = input.value.replace(/\D/g, '');
        if (digits.length > 11) digits = digits.slice(0, 11);
        input.value = digits;
        // খালি রাখা যাবে (আবশ্যক না হলে) — কিন্তু কিছু লিখলে অবশ্যই ১১ ডিজিট হতে হবে,
        // ১১ ডিজিট হয়ে গেলেই মেসেজ সাথে সাথে (রিলোড ছাড়া) সরে যাবে
        const invalid = digits.length > 0 && digits.length !== 11;
        errEl.classList.toggle('d-none', !invalid);
        input.classList.toggle('is-invalid', invalid);
    }
    input.addEventListener('input', checkPhone11);
    input.addEventListener('blur', checkPhone11);
});
</script>
