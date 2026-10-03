<?php
// admin/admission_merit.php - Admission Workflow: Step 4 - Merit List & Waiting List
// একটা শ্রেণির 'Result Published' আবেদনকারীদের নম্বর অনুযায়ী র‍্যাংক করে
// সিট সংখ্যা অনুযায়ী Merit List (নির্বাচিত) ও Waiting List (অপেক্ষমান) এ ভাগ করা হয়।
// Pass Marks এর নিচে যারা, তাদের Rejected করা হয়।
$am_msg = $_SESSION['am_msg'] ?? '';
$am_err = $_SESSION['am_err'] ?? '';
unset($_SESSION['am_msg'], $_SESSION['am_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['am_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=admission_merit');
        exit();
    }

    if (isset($_POST['generate'])) {
        $apply_class = trim($_POST['apply_class'] ?? '');
        $seats       = max(0, (int) ($_POST['seats'] ?? 0));
        $pass_marks  = (float) ($_POST['pass_marks'] ?? 0);

        if ($apply_class === '') {
            $_SESSION['am_err'] = 'শ্রেণি নির্বাচন করুন।';
        } elseif ($seats <= 0) {
            $_SESSION['am_err'] = 'সিট সংখ্যা ১ বা তার বেশি দিন।';
        } else {
            // ওই শ্রেণির সব candidate (যাদের রেজাল্ট প্রকাশিত হয়েছে, অথবা আগে মেরিট/ওয়েটিং লিস্টে ছিল — regenerate করার সুযোগ)
            $stmt = $pdo->prepare(
                "SELECT * FROM admissions
                 WHERE apply_class = ? AND status IN ('Result Published', 'Merit List', 'Waiting List')
                 ORDER BY exam_marks DESC, id ASC"
            );
            $stmt->execute([$apply_class]);
            $candidates = $stmt->fetchAll();

            $rank = 0;
            $selected = 0;
            $waiting = 0;
            $rejected = 0;

            $upd = $pdo->prepare("UPDATE admissions SET status = ?, merit_rank = ? WHERE id = ?");
            foreach ($candidates as $c) {
                if ((float) $c['exam_marks'] < $pass_marks) {
                    $upd->execute(['Rejected', null, $c['id']]);
                    $rejected++;
                    continue;
                }
                $rank++;
                if ($rank <= $seats) {
                    $upd->execute(['Merit List', $rank, $c['id']]);
                    $selected++;
                } else {
                    $upd->execute(['Waiting List', $rank, $c['id']]);
                    $waiting++;
                }
            }
            sec_log_activity($pdo, 'Admission', 'Update', "Merit List তৈরি করা হয়েছে: {$apply_class} (Merit: {$selected}, Waiting: {$waiting}, Rejected: {$rejected})");
            $_SESSION['am_msg'] = "মেরিট লিস্ট তৈরি হয়েছে — Merit List: {$selected}, Waiting List: {$waiting}, Rejected (Pass Marks এর নিচে): {$rejected}।";
        }
        header('Location: index.php?page=admission_merit&apply_class=' . urlencode($apply_class));
        exit();
    }

    if (isset($_POST['promote'])) {
        // Waiting List থেকে একজনকে Merit List এ প্রমোট করা (সিট খালি হলে)
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE admissions SET status = 'Merit List' WHERE id = ? AND status = 'Waiting List'");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            sec_log_activity($pdo, 'Admission', 'Update', "আবেদন #{$id} Waiting List থেকে Merit List এ প্রমোট করা হয়েছে");
        }
        $_SESSION['am_msg'] = $stmt->rowCount() > 0 ? "আবেদন #{$id} Waiting List থেকে Merit List এ প্রমোট করা হয়েছে।" : 'প্রমোট করা যায়নি।';
        header('Location: index.php?page=admission_merit&apply_class=' . urlencode($_POST['apply_class'] ?? ''));
        exit();
    }
}

$class_options = $pdo->query("SELECT DISTINCT apply_class FROM admissions WHERE exam_marks IS NOT NULL ORDER BY apply_class ASC")->fetchAll(PDO::FETCH_COLUMN);
$class_filter = $_GET['apply_class'] ?? ($class_options[0] ?? '');

$merit_rows = $waiting_rows = [];
if ($class_filter !== '') {
    $ms = $pdo->prepare("SELECT * FROM admissions WHERE apply_class = ? AND status = 'Merit List' ORDER BY merit_rank ASC");
    $ms->execute([$class_filter]);
    $merit_rows = $ms->fetchAll();

    $ws = $pdo->prepare("SELECT * FROM admissions WHERE apply_class = ? AND status = 'Waiting List' ORDER BY merit_rank ASC");
    $ws->execute([$class_filter]);
    $waiting_rows = $ws->fetchAll();
}

$csrf = htmlspecialchars(csrf_token());
?>

<div class="card-custom mb-3">
    <h4 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-ranking-star"></i> Admission — Merit & Waiting List</h4>

    <?php if ($am_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($am_msg); ?></div><?php endif; ?>
    <?php if ($am_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($am_err); ?></div><?php endif; ?>

    <form method="POST" action="index.php?page=admission_merit" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <div class="col-md-3">
            <label class="form-label mb-1">শ্রেণি *</label>
            <select name="apply_class" class="form-select form-select-sm" required>
                <option value="">-- নির্বাচন করুন --</option>
                <?php foreach ($class_options as $c): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $class_filter === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1">মোট সিট *</label>
            <input type="number" min="1" name="seats" class="form-control form-control-sm" required>
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1">Pass Marks</label>
            <input type="number" step="0.01" name="pass_marks" class="form-control form-control-sm" value="0">
        </div>
        <div class="col-md-3">
            <button type="submit" name="generate" value="1" class="btn btn-primary"><i class="fa-solid fa-list-ol"></i> Merit List তৈরি করুন</button>
        </div>
    </form>
    <small class="text-muted d-block mt-2">নির্বাচিত শ্রেণির সব "Result Published" আবেদনকারীকে নম্বর অনুযায়ী র‍্যাংক করে সিট সংখ্যা অনুযায়ী Merit List/Waiting List এ ভাগ করা হবে। আগের মেরিট/ওয়েটিং লিস্ট থাকলে পুনরায় জেনারেট করলে তা আপডেট হয়ে যাবে।</small>
</div>

<?php if ($class_filter !== ''): ?>
<div class="row">
    <div class="col-md-6">
        <div class="card-custom">
            <h5 class="fw-bold text-success mb-3"><i class="fa-solid fa-check-circle"></i> Merit List — <?php echo htmlspecialchars($class_filter); ?></h5>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                    <thead class="table-dark"><tr><th>Rank</th><th>Roll</th><th>Name</th><th>Marks</th></tr></thead>
                    <tbody>
                        <?php if ($merit_rows): foreach ($merit_rows as $r): ?>
                            <tr>
                                <td><?php echo (int) $r['merit_rank']; ?></td>
                                <td><?php echo htmlspecialchars($r['exam_roll']); ?></td>
                                <td><?php echo htmlspecialchars($r['student_name_bn']); ?></td>
                                <td><?php echo htmlspecialchars($r['exam_marks']); ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">No data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-custom">
            <h5 class="fw-bold text-secondary mb-3"><i class="fa-solid fa-hourglass-half"></i> Waiting List — <?php echo htmlspecialchars($class_filter); ?></h5>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                    <thead class="table-dark"><tr><th>Rank</th><th>Roll</th><th>Name</th><th>Marks</th><th></th></tr></thead>
                    <tbody>
                        <?php if ($waiting_rows): foreach ($waiting_rows as $r): ?>
                            <tr>
                                <td><?php echo (int) $r['merit_rank']; ?></td>
                                <td><?php echo htmlspecialchars($r['exam_roll']); ?></td>
                                <td><?php echo htmlspecialchars($r['student_name_bn']); ?></td>
                                <td><?php echo htmlspecialchars($r['exam_marks']); ?></td>
                                <td>
                                    <form method="POST" action="index.php?page=admission_merit" onsubmit="return confirm('Merit List এ প্রমোট করবেন?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                        <input type="hidden" name="apply_class" value="<?php echo htmlspecialchars($class_filter); ?>">
                                        <button type="submit" name="promote" value="1" class="btn btn-outline-success btn-sm">Promote</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
