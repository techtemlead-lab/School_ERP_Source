<?php
// academic.php - Academic Module Management
// প্রতিটা sidebar লিংক (All Session/Medium/Shift/Class/Section/Group/Subject) এখন
// শুধু নিজের সেকশনটাই দেখায় — অন্য কোনো ট্যাব/সেকশন এখানে দেখা যায় না।
$academic_err = '';

// সেকশনগুলোর কনফিগারেশন (Session, Medium, Shift, Class, Section, Group বাদে — এগুলোর এখন নিজস্ব রিচ UI আছে, শুধু Subject বাকি)
$academic_sections = [
    'academic_subject' => ['label' => 'Subject',   'table' => 'academic_subjects', 'col' => 'subject_name', 'placeholder' => 'Subject Name (e.g. Math)', 'post_flag' => 'add_subject'],
];

$active_key = array_key_exists($page, $academic_sections) ? $page : 'academic_subject';
$active = $academic_sections[$active_key];

// -----------------------------------------------------------
// Form Submission Handling — prepared statement দিয়ে
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $academic_err = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        if (isset($_POST['add_subject'])) {
            $s_name = trim($_POST['subject_name'] ?? '');
            $c_name = trim($_POST['class_name'] ?? '');
            if ($s_name !== '' && $c_name !== '') {
                $pdo->prepare("INSERT INTO academic_subjects (subject_name, class_name) VALUES (?, ?)")->execute([$s_name, $c_name]);
                sec_log_activity($pdo, 'Academic', 'Create', "নতুন Subject যোগ করা হয়েছে: {$s_name} ({$c_name})");
            }
        }
    }
    // POST/Redirect/GET — refresh করলে ফর্ম আবার সাবমিট হবে না, একই সেকশনে ফিরে যাবে
    header('Location: index.php?page=' . $active_key);
    exit();
}

function academic_fetch_all(PDO $pdo, string $table): array
{
    // $table কখনো ইউজার ইনপুট থেকে আসে না — শুধু $academic_sections কনফিগ থেকে আসে
    return $pdo->query("SELECT * FROM {$table}")->fetchAll();
}
?>

<div class="card-custom">
    <h4 class="fw-bold mb-4 text-dark"><i class="fa-solid fa-graduation-cap"></i> <?php echo htmlspecialchars($active['label']); ?> Management</h4>

    <?php if ($academic_err): ?>
        <div class="alert alert-danger py-2"><?php echo htmlspecialchars($academic_err); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-4">
            <div class="card p-3 bg-light">
                <h6 class="fw-bold mb-3">Add New <?php echo htmlspecialchars($active['label']); ?></h6>
                <form method="POST" action="index.php?page=<?php echo htmlspecialchars($active_key); ?>">
                    <?php echo csrf_field(); ?>

                    <?php if ($active_key === 'academic_subject'): ?>
                        <input type="text" name="subject_name" class="form-control mb-2" placeholder="Subject Name (e.g. Math)" required>
                        <input type="text" name="class_name" class="form-control mb-2" placeholder="Class Name (e.g. Class Six)" required>
                    <?php else: ?>
                        <input type="text" name="<?php echo htmlspecialchars($active['col']); ?>" class="form-control mb-2" placeholder="<?php echo htmlspecialchars($active['placeholder']); ?>" required>
                    <?php endif; ?>

                    <button type="submit" name="<?php echo htmlspecialchars($active['post_flag']); ?>" class="btn btn-primary btn-sm">Add <?php echo htmlspecialchars($active['label']); ?></button>
                </form>
            </div>
        </div>

        <div class="col-md-8">
            <table class="table table-bordered text-center align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th><?php echo htmlspecialchars($active['label']); ?> Name</th>
                        <?php if ($active_key === 'academic_subject'): ?><th>Class Name</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rows = academic_fetch_all($pdo, $active['table']);
                    if ($rows):
                        foreach ($rows as $row): ?>
                            <tr>
                                <td>#<?php echo (int) $row['id']; ?></td>
                                <td><?php echo htmlspecialchars($row[$active['col']]); ?></td>
                                <?php if ($active_key === 'academic_subject'): ?>
                                    <td><?php echo htmlspecialchars($row['class_name']); ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach;
                    else: ?>
                        <tr><td colspan="<?php echo $active_key === 'academic_subject' ? 3 : 2; ?>" class="text-muted py-3">No data found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
