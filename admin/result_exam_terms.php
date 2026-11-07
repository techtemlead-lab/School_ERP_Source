<?php
// admin/result_exam_terms.php - Result Module > Exam Settings > Exam Terms (List + Add/Edit)
// স্ক্রিনশট অনুযায়ী, তবে "Admit Card With Routine / Instruction", "Exam Routine With Instruction"
// এবং "Website Result Publish" - এই ৪টা ফিল্ড এখানে রাখা হয়নি; ওগুলো আলাদা
// "Term Publish Settings" পেজ (result_term_settings.php) থেকে সব টার্মের জন্য একসাথে সেট করা যাবে।
$et_err = $_SESSION['et_err'] ?? '';
$et_msg = $_SESSION['et_msg'] ?? '';
unset($_SESSION['et_err'], $_SESSION['et_msg']);

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি (৪টা পাবলিশ-সম্পর্কিত কলামও এখানেই রাখা হলো,
// যাতে result_term_settings.php থেকে একই টেবিল ব্যবহার করা যায়)
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_terms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        term_name VARCHAR(100) NOT NULL,
        rank INT NOT NULL DEFAULT 1,
        is_final_term TINYINT(1) NOT NULL DEFAULT 0,
        is_aggregated_result TINYINT(1) NOT NULL DEFAULT 0,
        admit_card_with_routine TINYINT(1) NOT NULL DEFAULT 0,
        admit_card_with_instruction TINYINT(1) NOT NULL DEFAULT 0,
        exam_routine_with_instruction TINYINT(1) NOT NULL DEFAULT 0,
        website_result_publish TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;
$current_session_name = $current_session['session_name'] ?? '—';

require_once __DIR__ . '/../config/exam_fee_helpers.php';
ef_ensure_columns($pdo);

// Main/Sub টার্ম ও ওয়েট (শতকরা/গড় মডেলের জন্য) — Master Settings-এ যে মডেল চালু, সেই অনুযায়ী ফিল্ড দেখায়
require_once __DIR__ . '/../config/report_card_helpers.php';
rc_pct_ensure_term_columns($pdo);
rc_ensure_formative_weight_column($pdo);
$et_active_model = '';
try { $et_active_model = (string) $pdo->query("SELECT aggregate_result_type FROM exam_result_settings WHERE id = 1")->fetchColumn(); } catch (Throwable $e) { $et_active_model = ''; }
$et_model_on   = rc_pct_is_model($et_active_model);
$et_model_type = $et_model_on ? rc_pct_models()[$et_active_model]['type'] : '';

$et_fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC")->fetchAll();
$et_months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

function etv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['et_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_exam_terms');
        exit();
    }

    if (isset($_POST['save_term'])) {
        $id          = (int) ($_POST['id'] ?? 0);
        $term_name   = trim($_POST['term_name'] ?? '');
        $rank        = (int) ($_POST['rank'] ?? 1);
        $is_final    = ($_POST['is_final_term'] ?? 'NO') === 'YES' ? 1 : 0;
        $is_agg      = ($_POST['is_aggregated_result'] ?? 'NO') === 'YES' ? 1 : 0;
        $status      = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
        $session_id  = $current_session_id;
        $fee_month   = (int) ($_POST['fee_month'] ?? 0);
        $fee_head_id = (int) ($_POST['exam_fee_head_id'] ?? 0);

        // Main/Sub ও ওয়েট (ফর্মে ফিল্ডগুলো দেখানো হলেই আসে; মডেল চালু না থাকলে আগের আচরণই বহাল)
        $has_struct = isset($_POST['term_kind']);
        $parent_id = null; $weight = null; $struct_err = '';
        if ($has_struct && $term_name !== '' && $session_id > 0) {
            $kind = ($_POST['term_kind'] ?? 'main') === 'sub' ? 'sub' : 'main';
            $w_raw = trim((string) ($_POST['weight_pct'] ?? ''));
            $w_in = $w_raw === '' ? null : round((float) $w_raw, 2);
            if ($w_in !== null && ($w_in <= 0 || $w_in > 100)) { $struct_err = 'ওয়েট ০-এর বেশি এবং ১০০-এর মধ্যে হতে হবে।'; }
            if ($struct_err === '' && $kind === 'sub') {
                $pid = (int) ($_POST['parent_term_id'] ?? 0);
                $pq = $pdo->prepare("SELECT id FROM exam_terms WHERE id = ? AND session_id = ? AND parent_term_id IS NULL");
                $pq->execute([$pid, $session_id]);
                if ($et_model_type === 'flat') { $struct_err = 'এই মডেলে সব পরীক্ষা Main টার্ম — Sub টার্ম বানানো যাবে না।'; }
                elseif ($pid <= 0 || $pid === $id || !$pq->fetchColumn()) { $struct_err = 'Sub টার্মের জন্য একটা Main টার্ম (প্যারেন্ট) সিলেক্ট করুন।'; }
                elseif ($id > 0) {
                    $cq = $pdo->prepare("SELECT COUNT(*) FROM exam_terms WHERE parent_term_id = ?");
                    $cq->execute([$id]);
                    if ((int) $cq->fetchColumn() > 0) { $struct_err = 'এই টার্মের নিজের Sub টার্ম আছে — তাই এটাকে Sub বানানো যাবে না।'; }
                }
                if ($struct_err === '' && in_array($et_model_type, ['nested', 'standalone'], true) && $w_in === null) { $struct_err = 'Sub টার্মের ভেতরের ওয়েট (%) দিন।'; }
                if ($struct_err === '') { $parent_id = $pid; $weight = in_array($et_model_type, ['nested', 'standalone'], true) ? $w_in : null; $is_final = 0; $is_agg = 0; }
            } elseif ($struct_err === '') {
                $weight = in_array($et_model_type, ['flat', 'nested'], true) ? $w_in : null; // standalone/গড় মডেলে Main-এর মোটে ওয়েট লাগে না
            }
        }

        if ($term_name === '' || $rank <= 0 || $session_id <= 0) {
            $_SESSION['et_err'] = 'Term Name, Rank আবশ্যক এবং একটা Current Session সেট করা থাকতে হবে।';
        } elseif ($struct_err !== '') {
            $_SESSION['et_err'] = $struct_err;
        } else {
            if ($id > 0) {
                $sql = "UPDATE exam_terms SET term_name=?, rank=?, is_final_term=?, is_aggregated_result=?, status=?, fee_month=?, exam_fee_head_id=?" . ($has_struct ? ", parent_term_id=?, weight_pct=?" : "") . " WHERE id=?";
                $args = [$term_name, $rank, $is_final, $is_agg, $status, $fee_month ?: null, $fee_head_id ?: null];
                if ($has_struct) { $args[] = $parent_id; $args[] = $weight; }
                $args[] = $id;
                $pdo->prepare($sql)->execute($args);
                sec_log_activity($pdo, 'Result', 'Update', "Exam Term আপডেট করা হয়েছে: {$term_name}");
                $_SESSION['et_msg'] = 'Exam Term আপডেট করা হয়েছে।';
                if (array_key_exists('formative_weight_pct', $_POST)) {
                    $fw_raw = trim((string) $_POST['formative_weight_pct']);
                    $fw = ($fw_raw === '' || $parent_id !== null) ? null : max(0.0, min(100.0, round((float) $fw_raw, 2)));
                    if ($fw !== null && $fw <= 0) { $fw = null; }
                    $fid = $id;
                    $pdo->prepare("UPDATE exam_terms SET formative_weight_pct = ? WHERE id = ?")->execute([$fw, $fid]);
                }
            } else {
                $sql = "INSERT INTO exam_terms (session_id, term_name, rank, is_final_term, is_aggregated_result, status, fee_month, exam_fee_head_id" . ($has_struct ? ", parent_term_id, weight_pct" : "") . ") VALUES (?, ?, ?, ?, ?, ?, ?, ?" . ($has_struct ? ", ?, ?" : "") . ")";
                $args = [$session_id, $term_name, $rank, $is_final, $is_agg, $status, $fee_month ?: null, $fee_head_id ?: null];
                if ($has_struct) { $args[] = $parent_id; $args[] = $weight; }
                $pdo->prepare($sql)->execute($args);
                $new_term_id = (int) $pdo->lastInsertId();
                sec_log_activity($pdo, 'Result', 'Create', "নতুন Exam Term তৈরি করা হয়েছে: {$term_name}");
                $_SESSION['et_msg'] = 'নতুন Exam Term যোগ করা হয়েছে।';
                if (array_key_exists('formative_weight_pct', $_POST)) {
                    $fw_raw = trim((string) $_POST['formative_weight_pct']);
                    $fw = ($fw_raw === '' || $parent_id !== null) ? null : max(0.0, min(100.0, round((float) $fw_raw, 2)));
                    if ($fw !== null && $fw <= 0) { $fw = null; }
                    $fid = $new_term_id;
                    $pdo->prepare("UPDATE exam_terms SET formative_weight_pct = ? WHERE id = ?")->execute([$fw, $fid]);
                }
            }
        }
    } elseif (isset($_POST['delete_term'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $sub_cnt = $pdo->prepare("SELECT COUNT(*) FROM exam_terms WHERE parent_term_id = ?");
        $sub_cnt->execute([$id]);
        if ((int) $sub_cnt->fetchColumn() > 0) {
            $_SESSION['et_err'] = 'এই Main টার্মের Sub টার্ম আছে — আগে Sub টার্মগুলো মুছুন বা অন্য Main-এ সরান।';
        } else {
            $pdo->prepare("DELETE FROM exam_terms WHERE id = ?")->execute([$id]);
            sec_log_activity($pdo, 'Result', 'Delete', "Exam Term মুছে ফেলা হয়েছে: আইডি {$id}");
            $_SESSION['et_msg'] = 'Exam Term মুছে ফেলা হয়েছে।';
        }
    }

    header('Location: index.php?page=result_exam_terms');
    exit();
}

$view = $_GET['view'] ?? 'list';
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    // ------------------- ADD / EDIT FORM VIEW -------------------
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM exam_terms WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: [
        'id' => 0, 'term_name' => '', 'rank' => 0, 'is_final_term' => 0,
        'is_aggregated_result' => 0, 'status' => 'Active', 'fee_month' => 0, 'exam_fee_head_id' => 0,
        'parent_term_id' => (int) ($_GET['parent'] ?? 0), 'weight_pct' => '', // ?parent=ID হলে "+ Sub" বাটন থেকে এসেছে — প্যারেন্ট আগে থেকে সিলেক্ট
    ];

    // এই সেশনে এখন পর্যন্ত সর্বোচ্চ Rank কত, হিন্ট হিসেবে দেখানোর জন্য
    $rank_stmt = $pdo->prepare("SELECT COALESCE(MAX(rank), 0) FROM exam_terms WHERE session_id = ?" . ($edit_row ? " AND id != ?" : ""));
    $rank_params = $edit_row ? [$current_session_id, (int) $d['id']] : [$current_session_id];
    $rank_stmt->execute($rank_params);
    $last_rank = (int) $rank_stmt->fetchColumn();
    if (!$edit_row && (int) $d['rank'] === 0) {
        $d['rank'] = $last_rank + 1;
    }
    ?>
    <div class="card-custom">
        <?php if ($et_err): ?><div class="alert alert-danger py-2"><?php echo etv($et_err); ?></div><?php endif; ?>

        <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;">
            <i class="fa-solid fa-file-lines text-white"></i>
        </div>

        <h6 class="text-muted mb-4">Add Exam Term for Session : <?php echo etv($current_session_name); ?></h6>

        <form method="POST" action="index.php?page=result_exam_terms">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Term Name <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="term_name" class="form-control" value="<?php echo etv($d['term_name']); ?>" required></div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Rank <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="number" min="1" name="rank" class="form-control" value="<?php echo (int) $d['rank']; ?>" required></div>
                <div class="col-sm-3"><span class="text-danger small">Last Rank No : <?php echo (int) $last_rank; ?></span></div>
            </div>

            <?php if ($et_model_on):
                // ------- Main / Sub ও ওয়েট (চালু মডেল অনুযায়ী) -------
                $d_parent = (int) ($d['parent_term_id'] ?? 0);
                $d_is_sub = $d_parent > 0;
                $d_has_children = false;
                if ($edit_row) {
                    $hc = $pdo->prepare("SELECT COUNT(*) FROM exam_terms WHERE parent_term_id = ?");
                    $hc->execute([(int) $d['id']]);
                    $d_has_children = (int) $hc->fetchColumn() > 0;
                }
                $mq = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id = ? AND parent_term_id IS NULL AND status = 'Active' AND id != ? ORDER BY rank ASC, id ASC");
                $mq->execute([$current_session_id, (int) $d['id']]);
                $et_main_options = $mq->fetchAll();
                $sub_allowed = in_array($et_model_type, ['nested', 'standalone', 'avg_group'], true) && !$d_has_children;
                if (!$sub_allowed) { $d_is_sub = false; }
            ?>
            <div class="border rounded p-3 mb-4 bg-light" id="etStructBox" data-model-type="<?php echo etv($et_model_type); ?>">
                <div class="small mb-2"><i class="fa-solid fa-sliders"></i> <strong>Result মডেল (চালু আছে):</strong> <?php echo etv(rc_pct_model_label($et_active_model)); ?>
                    <a href="index.php?page=result_settings#templates" class="ms-1">(বদলাতে Master Settings)</a></div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Term Type</label>
                    <div class="col-sm-9 d-flex gap-4 align-items-center flex-wrap">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="term_kind" id="tkMain" value="main" <?php echo !$d_is_sub ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="tkMain"><strong>Main</strong> (যেমন ১ম টার্ম, ২য় টার্ম, ফাইনাল)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="term_kind" id="tkSub" value="sub" <?php echo $d_is_sub ? 'checked' : ''; ?> <?php echo !$sub_allowed ? 'disabled' : ''; ?>>
                            <label class="form-check-label" for="tkSub"><strong>Sub</strong> (মাসিক / ক্লাস টেস্ট — কোনো Main টার্মের ভেতরে)</label>
                        </div>
                    </div>
                    <?php if ($et_model_type === 'flat'): ?><div class="col-sm-9 offset-sm-3 small text-muted">এই মডেলে সব পরীক্ষা (সাময়িক ও মাসিক) Main — প্রত্যেকে সরাসরি মোটে ওয়েট পায়।</div><?php endif; ?>
                    <?php if ($d_has_children): ?><div class="col-sm-9 offset-sm-3 small text-muted">এই Main টার্মের Sub টার্ম আছে, তাই এটাকে Sub বানানো যাবে না।</div><?php endif; ?>
                </div>
                <div class="row mb-3 align-items-center" id="etParentRow" style="<?php echo $d_is_sub ? '' : 'display:none;'; ?>">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Parent (Main) Term <span class="text-danger">*</span></label>
                    <div class="col-sm-6">
                        <select name="parent_term_id" class="form-select">
                            <option value="0">-- Main টার্ম সিলেক্ট করুন --</option>
                            <?php foreach ($et_main_options as $mo): ?>
                                <option value="<?php echo (int) $mo['id']; ?>" <?php echo $d_parent === (int) $mo['id'] ? 'selected' : ''; ?>><?php echo etv($mo['term_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-1 align-items-center" id="etWeightRow">
                    <label class="col-sm-3 col-form-label fw-bold text-end" id="etWeightLabel">ওয়েট (%)</label>
                    <div class="col-sm-3">
                        <div class="input-group"><input type="number" step="0.01" min="0" max="100" name="weight_pct" class="form-control" value="<?php echo etv($d['weight_pct'] ?? ''); ?>"><span class="input-group-text">%</span></div>
                    </div>
                    <div class="col-sm-6 small text-muted" id="etWeightHelp"></div>
                </div>
                <div class="row mt-3 mb-1 align-items-center" id="etFormRow" style="<?php echo $d_is_sub ? 'display:none;' : ''; ?>">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Formative ওয়েট (%)</label>
                    <div class="col-sm-3">
                        <div class="input-group"><input type="number" step="0.01" min="0" max="100" name="formative_weight_pct" class="form-control" value="<?php echo etv($d['formative_weight_pct'] ?? ''); ?>"><span class="input-group-text">%</span></div>
                    </div>
                    <div class="col-sm-6 small text-muted">রিপোর্ট কার্ডে Formative আলাদা কলাম হবে (যেমন ১০)। Monthly = এই টার্মের Sub টার্মগুলোর ওয়েটের যোগফল, Term = ১০০ − Monthly − Formative। ফাঁকা = আগের মতো।</div>
                </div>
            </div>
            <script>
            (function () {
                var box = document.getElementById('etStructBox'); if (!box) return;
                var type = box.getAttribute('data-model-type');
                var kinds = document.querySelectorAll('input[name="term_kind"]');
                function sync() {
                    var sub = document.getElementById('tkSub') && document.getElementById('tkSub').checked;
                    document.getElementById('etParentRow').style.display = sub ? '' : 'none';
                    var fr = document.getElementById('etFormRow'); if (fr) { fr.style.display = sub ? 'none' : ''; }
                    var wRow = document.getElementById('etWeightRow'), lbl = document.getElementById('etWeightLabel'), help = document.getElementById('etWeightHelp');
                    var show = false, text = '', hl = '';
                    if (sub && (type === 'nested' || type === 'standalone')) {
                        show = true; text = 'Main-এর ভেতরে ওয়েট (%)';
                        hl = 'Main টার্মের নিজের পরীক্ষার ওয়েট = ১০০ − এর সব Sub-এর ওয়েটের যোগফল (অটো)।';
                    } else if (!sub && (type === 'flat' || type === 'nested')) {
                        show = true; text = 'মোটে ওয়েট (%)';
                        hl = type === 'flat' ? 'সব পরীক্ষার ওয়েটের যোগফল ১০০ হতে হবে। ফাঁকা = ফাইনাল ফলাফলে ধরা হবে না।' : 'সব Main টার্মের মোটে ওয়েটের যোগফল ১০০ হতে হবে। ফাঁকা = ফাইনাল ফলাফলে ধরা হবে না।';
                    }
                    wRow.style.display = show ? '' : 'none'; lbl.textContent = text; help.textContent = hl;
                    // Sub টার্মে Final/Aggregated প্রযোজ্য নয়
                    ['etFinalRow', 'etAggRow'].forEach(function (id) { var r = document.getElementById(id); if (r) r.style.display = sub ? 'none' : ''; });
                    if (!sub && type === 'avg_group') { help.textContent = ''; }
                }
                kinds.forEach(function (k) { k.addEventListener('change', sync); });
                sync();
            })();
            </script>
            <?php endif; ?>

            <div class="row mb-3 align-items-center" id="etFinalRow">
                <label class="col-sm-3 col-form-label fw-bold text-end">Final Term ? <span class="text-danger">*</span></label>
                <div class="col-sm-6 d-flex gap-4 align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="is_final_term" id="ftYes" value="YES" <?php echo $d['is_final_term'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="ftYes">YES</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="is_final_term" id="ftNo" value="NO" <?php echo !$d['is_final_term'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="ftNo">NO</label>
                    </div>
                </div>
            </div>

            <div class="row mb-4 align-items-center" id="etAggRow">
                <label class="col-sm-3 col-form-label fw-bold text-end">Aggregated Result ? <span class="text-danger">*</span></label>
                <div class="col-sm-6 d-flex gap-4 align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="is_aggregated_result" id="agYes" value="YES" <?php echo $d['is_aggregated_result'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="agYes">YES</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="is_aggregated_result" id="agNo" value="NO" <?php echo !$d['is_aggregated_result'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="agNo">NO</label>
                    </div>
                </div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Status <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="status" class="form-select">
                        <option value="Active" <?php echo $d['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $d['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <div class="alert alert-info small mb-4">
                <i class="fa-solid fa-circle-info"></i>
                Admit Card / Exam Routine / Website Result Publish সংক্রান্ত অপশনগুলো এখন সব টার্মের জন্য একসাথে
                <a href="index.php?page=result_term_settings" class="fw-bold">Term Publish Settings</a> পেজ থেকে সেট করতে হবে।
            </div>

            <hr>
            <h6 class="fw-bold mb-3" style="color:#1e3a8a;"><i class="fa-solid fa-money-bill-wave me-2"></i>Exam Fee (ঐচ্ছিক)</h6>
            <p class="text-muted small">এই Term-এর Seat Plan/Admit Card দেখতে যদি Exam Fee পরিশোধ থাকা জরুরি করতে চান, তাহলে এখানে
                Finance Module-এর কোন Account Head আর কোন মাসের Fee হিসেবে এটা গণ্য হবে তা সিলেক্ট করুন। ফি এমাউন্ট
                <a href="index.php?page=finance_monthly_fees" class="fw-bold">Finance &gt; Monthly Fees Settings</a> থেকে সেট করতে হবে,
                এবং কালেকশন হবে <a href="index.php?page=finance_fees_collection" class="fw-bold">Finance &gt; Fees Collection</a> পেজ থেকে।
                খালি রাখলে এই Term-এ কোনো ফি বাধ্যতামূলক থাকবে না।</p>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Fee Month</label>
                <div class="col-sm-6">
                    <select name="fee_month" class="form-select">
                        <option value="0">-- কোনো ফি লাগবে না --</option>
                        <?php foreach ($et_months as $mn => $mname): ?>
                            <option value="<?php echo $mn; ?>" <?php echo (int) $d['fee_month'] === $mn ? 'selected' : ''; ?>><?php echo $mname; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Exam Fee Account Head</label>
                <div class="col-sm-6">
                    <select name="exam_fee_head_id" class="form-select">
                        <option value="0">-- সিলেক্ট করুন --</option>
                        <?php foreach ($et_fee_heads as $h): ?>
                            <option value="<?php echo (int) $h['id']; ?>" <?php echo (int) $d['exam_fee_head_id'] === (int) $h['id'] ? 'selected' : ''; ?>><?php echo etv(trim($h['ac_code'] . ' ' . $h['ac_head'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$et_fee_heads): ?><span class="text-danger small">কোনো Account Head পাওয়া যায়নি — আগে Finance &gt; Account Head থেকে "Exam Fee" নামে একটা Head তৈরি করুন।</span><?php endif; ?>
                </div>
            </div>

            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_term" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=result_exam_terms" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

<?php else: ?>
    <?php
    // ------------------- LIST VIEW -------------------
    $f_name   = trim($_GET['f_name'] ?? '');
    $f_final  = $_GET['f_final'] ?? 'All';
    $f_status = $_GET['f_status'] ?? 'All';

    $where = ['session_id = ?'];
    $params = [$current_session_id];
    if ($f_name !== '')   { $where[] = 'term_name LIKE ?'; $params[] = "%$f_name%"; }
    if ($f_final !== 'All')  { $where[] = 'is_final_term = ?'; $params[] = $f_final === 'Yes' ? 1 : 0; }
    if ($f_status !== 'All') { $where[] = 'status = ?';        $params[] = $f_status; }

    $sql = "SELECT * FROM exam_terms WHERE " . implode(' AND ', $where) . " ORDER BY rank ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $et_names = [];
    $nq = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id = ?");
    $nq->execute([$current_session_id]);
    foreach ($nq->fetchAll() as $nr) { $et_names[(int) $nr['id']] = $nr['term_name']; }
    if ($et_model_on) { $rows = rc_term_tree_order($rows); }
    ?>
    <div class="card-custom">
        <?php if ($et_msg): ?><div class="alert alert-success py-2"><?php echo etv($et_msg); ?></div><?php endif; ?>
        <?php if ($et_err): ?><div class="alert alert-danger py-2"><?php echo etv($et_err); ?></div><?php endif; ?>

        <?php if ($et_model_on): ?>
            <div class="small mb-2"><i class="fa-solid fa-sliders"></i> <strong>চালু Result মডেল:</strong> <?php echo etv(rc_pct_model_label($et_active_model)); ?>
                <a href="index.php?page=result_settings#templates" class="ms-1">(বদলাতে Master Settings)</a></div>
            <?php echo rc_pct_structure_html(rc_pct_structure($pdo, $current_session_id, $et_active_model), $et_active_model); ?>
        <?php endif; ?>

        <div id="et_result_wrap">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=result_exam_terms&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
            <span class="text-muted small">Session : <?php echo etv($current_session_name); ?> &nbsp;|&nbsp; Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> item.</span>
        </div>

            <!-- সারিওয়ারি Delete বাটন আগে প্রতিটা নিজের ছোট্ট &lt;form&gt;-এ মোড়ানো ছিল, আর সেই ফর্মগুলো
         থাকত নিচের টেবিলটা মোড়ানো GET ফিল্টার ফর্মের ভেতরে — অর্থাৎ ফর্মের ভেতরে ফর্ম। HTML-এ
         নেস্টেড &lt;form&gt; বৈধ না, তাই ব্রাউজার ভেতরের ফর্মটাকে বাতিল করে তার ইনপুট/বাটনগুলো
         বাইরের GET ফিল্টার ফর্মেই বসিয়ে দিত। ফলে Delete-এ ক্লিক করলে আসল POST ডিলিট রিকোয়েস্টের
         বদলে ফিল্টার ফর্মটাই (GET, action=index.php) সাবমিট হয়ে যেত — কিছুই ডিলিট হতো না।
         সমাধানঃ একটামাত্র শেয়ার্ড হিডেন ফর্ম, GET ফিল্টার ফর্মের বাইরে রাখা — প্রতিটা Delete
         বাটন এখন আলাদা ফর্ম না বানিয়ে শুধু এটাকেই JS দিয়ে সাবমিট করে। -->
    <form method="POST" id="wcRowActionForm" style="display:none">
        <input type="hidden" name="csrf_token" id="wcRowActionCsrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" id="wcRowActionId" value="">
    </form>
    <script>
    function wcRowAction(url, field, id, confirmMsg) {
        if (confirmMsg && !window.confirm(confirmMsg)) return false;
        var f = document.getElementById('wcRowActionForm');
        f.setAttribute('action', url);
        document.getElementById('wcRowActionId').value = id;
        var old = f.querySelector('input[data-dyn-field]');
        if (old) old.parentNode.removeChild(old);
        var fld = document.createElement('input');
        fld.type = 'hidden'; fld.name = field; fld.value = '1'; fld.setAttribute('data-dyn-field', '1');
        f.appendChild(fld);
        f.submit();
        return false;
    }
    </script>
<form method="GET" action="index.php" id="et_filter_form">
            <input type="hidden" name="page" value="result_exam_terms">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Srl.</th>
                            <th>Rank</th>
                            <th>Term Name</th>
                            <th>Final Term ?</th>
                            <th>Aggregated Result ?</th>
                            <?php if ($et_model_on): ?><th>Type / Weight</th><?php endif; ?>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <tr>
                            <th></th>
                            <th></th>
                            <th><input type="text" name="f_name" class="form-control form-control-sm" value="<?php echo etv($f_name); ?>"></th>
                            <th>
                                <select name="f_final" id="et_f_final" class="form-select form-select-sm">
                                    <option value="All" <?php echo $f_final === 'All' ? 'selected' : ''; ?>>All</option>
                                    <option value="Yes" <?php echo $f_final === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                    <option value="No" <?php echo $f_final === 'No' ? 'selected' : ''; ?>>No</option>
                                </select>
                            </th>
                            <th></th>
                            <?php if ($et_model_on): ?><th></th><?php endif; ?>
                            <th>
                                <select name="f_status" id="et_f_status" class="form-select form-select-sm">
                                    <option value="All" <?php echo $f_status === 'All' ? 'selected' : ''; ?>>All</option>
                                    <option value="Active" <?php echo $f_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo $f_status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </th>
                            <th><button type="submit" class="btn btn-sm btn-outline-primary">Filter</button></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td><?php echo (int) $row['rank']; ?></td>
                                <td<?php echo !empty($row['depth']) ? ' style="padding-left:28px;"' : ''; ?>><?php echo !empty($row['depth']) ? '<span class="text-muted">↳</span> ' : ''; ?><?php echo etv($row['term_name']); ?></td>
                                <td><?php echo $row['is_final_term'] ? '<span class="badge bg-primary">Yes</span>' : '<span class="text-muted">No</span>'; ?></td>
                                <td><?php echo $row['is_aggregated_result'] ? '<span class="badge bg-primary">Yes</span>' : '<span class="text-muted">No</span>'; ?></td>
                                <?php if ($et_model_on): ?><td><?php echo rc_term_weight_cell_html($row, $et_names, $et_model_type); ?></td><?php endif; ?>
                                <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                <td class="text-nowrap">
                                    <?php if ($et_model_on && empty($row['parent_term_id']) && in_array($et_model_type, ['nested', 'standalone', 'avg_group'], true)): ?>
                                        <a href="index.php?page=result_exam_terms&view=add&parent=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-success" title="এই Main টার্মের ভেতরে Sub (মাসিক/ক্লাস টেস্ট) যোগ করুন"><i class="fa-solid fa-plus"></i> Sub</a>
                                    <?php endif; ?>
                                    <a href="index.php?page=result_exam_terms&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=result_exam_terms', 'delete_term', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="<?php echo $et_model_on ? 8 : 7; ?>" class="text-center text-muted py-3">No data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
        </div>
    </div>
<?php endif; ?>

<script>
(function () {
    var wrap = document.getElementById('et_result_wrap');
    if (!wrap) return;

    function currentParams() {
        var nameEl = wrap.querySelector('input[name="f_name"]');
        var finalEl = wrap.querySelector('#et_f_final');
        var statusEl = wrap.querySelector('#et_f_status');
        return {
            f_name: nameEl ? nameEl.value : '',
            f_final: finalEl ? finalEl.value : 'All',
            f_status: statusEl ? statusEl.value : 'All'
        };
    }

    function loadList() {
        var params = new URLSearchParams(currentParams());
        wrap.style.opacity = '0.6';
        fetch('ajax/result_exam_terms_ajax.php?' + params.toString())
            .then(function (r) {
                if (!r.ok) throw new Error('bad response');
                return r.text();
            })
            .then(function (html) {
                wrap.innerHTML = html;
                wrap.style.opacity = '1';
            })
            .catch(function () {
                wrap.style.opacity = '1';
                wrap.insertAdjacentHTML('afterbegin', '<div class="alert alert-danger py-2">ডাটা লোড করতে সমস্যা হয়েছে, আবার চেষ্টা করুন।</div>');
            });
    }

    // event delegation ব্যবহার করা হয়েছে যাতে AJAX দিয়ে wrap এর ভেতরের select রিপ্লেস হয়ে
    // গেলেও (নতুন select এলিমেন্ট তৈরি হলেও) change ইভেন্ট ধরা পড়ে
    wrap.addEventListener('change', function (e) {
        if (e.target && (e.target.id === 'et_f_final' || e.target.id === 'et_f_status')) {
            loadList();
        }
    });
})();
</script>
