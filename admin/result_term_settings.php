<?php
// admin/result_term_settings.php - Result Module > Exam Settings > Term Publish Settings
// এই পেজ থেকে "Admit Card With Routine", "Admit Card With Instruction",
// "Exam Routine With Instruction" এবং "Website Result Publish" - এই ৪টা অপশন
// প্রতিটা Exam Term এর জন্য আলাদা আলাদা না গিয়ে, একই পেজ থেকে সব টার্মের জন্য
// একসাথে সেট করা যাবে।
$ts_err = $_SESSION['ts_err'] ?? '';
$ts_msg = $_SESSION['ts_msg'] ?? '';
unset($_SESSION['ts_err'], $_SESSION['ts_msg']);

// exam_terms টেবিল না থাকলে অটো তৈরি (result_exam_terms.php এর সাথে একই টেবিল শেয়ার করা হয়)
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

function tsv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$publish_fields = [
    'admit_card_with_routine'       => 'Admit Card With Routine',
    'admit_card_with_instruction'   => 'Admit Card With Instruction',
    'exam_routine_with_instruction' => 'Exam Routine With Instruction',
    'website_result_publish'        => 'Website Result Publish',
];

// -----------------------------------------------------------
// POST Handler - সব টার্মের সব টগল একসাথে সেভ
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ts_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_term_settings');
        exit();
    }

    if (isset($_POST['save_publish_settings'])) {
        $term_ids = $_POST['term_id'] ?? [];
        $stmt = $pdo->prepare(
            "UPDATE exam_terms SET admit_card_with_routine=?, admit_card_with_instruction=?,
             exam_routine_with_instruction=?, website_result_publish=? WHERE id=?"
        );
        foreach ($term_ids as $tid) {
            $tid = (int) $tid;
            $vals = [];
            foreach ($publish_fields as $key => $label) {
                $vals[] = (isset($_POST[$key][$tid]) && $_POST[$key][$tid] === '1') ? 1 : 0;
            }
            $vals[] = $tid;
            $stmt->execute($vals);
        }
        sec_log_activity($pdo, 'Result', 'Update', 'Term Publish Settings আপডেট করা হয়েছে');
        $_SESSION['ts_msg'] = 'Term Publish Settings সফলভাবে সেভ হয়েছে।';
    }

    header('Location: index.php?page=result_term_settings');
    exit();
}

// সেশন ফিল্টার (ডিফল্ট: Current Session)
$sessions = $pdo->query("SELECT id, session_name, is_current_session FROM academic_sessions ORDER BY session_name DESC")->fetchAll();
$current_session = $pdo->query("SELECT id, session_name FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$default_session_id = $current_session ? (int) $current_session['id'] : ($sessions[0]['id'] ?? 0);
$f_session = (int) ($_GET['session_id'] ?? $default_session_id);

$stmt = $pdo->prepare("SELECT * FROM exam_terms WHERE session_id = ? ORDER BY rank ASC");
$stmt->execute([$f_session]);
$terms = $stmt->fetchAll();
?>

<style>
.yn-btn2 { border: none; border-radius: 4px; color: #fff; font-weight: 700; font-size: 12px; padding: 4px 14px; min-width: 54px; }
.yn-yes2 { background-color: #1e3a8a; }
.yn-no2  { background-color: #e0533f; }
</style>

<div class="card-custom">
    <?php if ($ts_msg): ?><div class="alert alert-success py-2"><?php echo tsv($ts_msg); ?></div><?php endif; ?>
    <?php if ($ts_err): ?><div class="alert alert-danger py-2"><?php echo tsv($ts_err); ?></div><?php endif; ?>

    <div class="alert alert-info small">
        <i class="fa-solid fa-circle-info"></i>
        এখান থেকে সব Exam Term এর জন্য Admit Card / Exam Routine / Website Result Publish অপশন একসাথে সেট করা যাবে।
        নতুন Term যোগ করতে <a href="index.php?page=result_exam_terms&view=add" class="fw-bold">Exam Terms</a> পেজে যান।
    </div>

    <div id="ts_result_wrap">
    <form method="GET" action="index.php" class="row mb-4 align-items-center" id="ts_filter_form">
        <input type="hidden" name="page" value="result_term_settings">
        <label class="col-auto col-form-label fw-bold">Session</label>
        <div class="col-auto">
            <select name="session_id" id="ts_session_id" class="form-select">
                <?php foreach ($sessions as $s): ?>
                    <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>>
                        <?php echo tsv($s['session_name']); ?><?php echo $s['is_current_session'] ? ' (Current)' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if (!$terms): ?>
        <div class="text-center text-muted py-4">
            এই সেশনে কোনো Exam Term পাওয়া যায়নি। প্রথমে
            <a href="index.php?page=result_exam_terms&view=add">Exam Term</a> তৈরি করুন।
        </div>
    <?php else: ?>
        <form method="POST" action="index.php?page=result_term_settings">
            <?php echo csrf_field(); ?>
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Rank</th>
                            <th>Term Name</th>
                            <?php foreach ($publish_fields as $label): ?>
                                <th class="text-center"><?php echo tsv($label); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($terms as $t): $tid = (int) $t['id']; ?>
                            <tr>
                                <td><?php echo (int) $t['rank']; ?></td>
                                <td><?php echo tsv($t['term_name']); ?></td>
                                <input type="hidden" name="term_id[]" value="<?php echo $tid; ?>">
                                <?php foreach ($publish_fields as $key => $label):
                                    $is_yes = (int) $t[$key] === 1;
                                    $hid = $key . '_' . $tid;
                                ?>
                                    <td class="text-center">
                                        <input type="hidden" name="<?php echo $key; ?>[<?php echo $tid; ?>]" id="<?php echo $hid; ?>" value="<?php echo $is_yes ? '1' : '0'; ?>">
                                        <button type="button" class="yn-btn2 <?php echo $is_yes ? 'yn-yes2' : 'yn-no2'; ?>" data-target="<?php echo $hid; ?>" onclick="ynToggle2(this)"><?php echo $is_yes ? 'Yes' : 'No'; ?></button>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_publish_settings" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Update</button>
                    <a href="index.php?page=dashboard" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    <?php endif; ?>
    </div>
</div>

<script>
function ynToggle2(btn) {
    var hidden = document.getElementById(btn.getAttribute('data-target'));
    var isYes = hidden.value === '1';
    hidden.value = isYes ? '0' : '1';
    btn.classList.toggle('yn-yes2', !isYes);
    btn.classList.toggle('yn-no2', isYes);
    btn.textContent = isYes ? 'No' : 'Yes';
}

(function () {
    var wrap = document.getElementById('ts_result_wrap');
    if (!wrap) return;

    function loadList() {
        var sessionEl = wrap.querySelector('#ts_session_id');
        var params = new URLSearchParams({ session_id: sessionEl ? sessionEl.value : '' });
        wrap.style.opacity = '0.6';
        fetch('ajax/result_term_settings_ajax.php?' + params.toString())
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
        if (e.target && e.target.id === 'ts_session_id') {
            loadList();
        }
    });
})();
</script>
