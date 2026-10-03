<?php
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_features.php';

$pageTitle = 'Demo Requests & Leads';
$pageIcon  = '📬';
$activeKey = 'demo_requests';

// Status change action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'New';
    $notes = trim($_POST['admin_notes'] ?? '');
    if ($id > 0) {
        $stmt = $pdo_master->prepare("UPDATE demo_requests SET status = ?, admin_notes = ? WHERE id = ?");
        $stmt->execute([$status, $notes, $id]);
        $_SESSION['dash_msg'] = '✅ Demo request status updated.';
        header('Location: demo_requests.php');
        exit;
    }
}

// Fetch all demo requests
$requests = $pdo_master->query("SELECT * FROM demo_requests ORDER BY id DESC")->fetchAll();

require_once __DIR__ . '/_layout_top.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card-custom p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0 text-white">ওয়েবসাইট থেকে আসা ডেমো রিকোয়েস্ট তালিকা (<?php echo count($requests); ?>)</h5>
                <span class="badge bg-primary">Landing Page Leads</span>
            </div>
            
            <?php if (empty($requests)): ?>
                <div class="alert alert-secondary text-center py-4">এখনো কোনো ডেমো রিকোয়েস্ট জমা পড়েনি।</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>প্রতিষ্ঠানের নাম</th>
                                <th>যোগাযোগকারী</th>
                                <th>মোবাইল নম্বর</th>
                                <th>শিক্ষার্থী সাইজ</th>
                                <th>পছন্দের তারিখ</th>
                                <th>স্ট্যাটাস</th>
                                <th>আবেদনের সময়</th>
                                <th>অ্যাকশন</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $r): ?>
                                <tr>
                                    <td><?php echo $r['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($r['school_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($r['contact_name']); ?><br><small class="text-muted"><?php echo htmlspecialchars($r['email'] ?? ''); ?></small></td>
                                    <td>
                                        <a href="tel:<?php echo htmlspecialchars($r['phone']); ?>" class="text-success font-monospace text-decoration-none">
                                            📞 <?php echo htmlspecialchars($r['phone']); ?>
                                        </a>
                                    </td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($r['student_count_range'] ?? 'N/A'); ?></span></td>
                                    <td><?php echo htmlspecialchars($r['preferred_date'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php 
                                        $badgeClass = 'bg-secondary';
                                        if ($r['status'] === 'New') $badgeClass = 'bg-danger';
                                        elseif ($r['status'] === 'Contacted') $badgeClass = 'bg-warning text-dark';
                                        elseif ($r['status'] === 'Converted') $badgeClass = 'bg-success';
                                        ?>
                                        <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($r['status']); ?></span>
                                    </td>
                                    <td><small class="text-muted"><?php echo date('d M Y, h:i A', strtotime($r['created_at'])); ?></small></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-info" data-bs-toggle="collapse" data-bs-target="#editRow<?php echo $r['id']; ?>">
                                            স্ট্যাটাস বদলান
                                        </button>
                                    </td>
                                </tr>
                                <tr class="collapse" id="editRow<?php echo $r['id']; ?>">
                                    <td colspan="9" class="bg-dark p-3">
                                        <form method="POST" class="d-flex gap-3 align-items-center">
                                            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                            <input type="hidden" name="update_status" value="1">
                                            <label class="text-muted small">স্ট্যাটাস:</label>
                                            <select name="status" class="form-select form-select-sm w-auto">
                                                <option value="New" <?php echo $r['status'] === 'New' ? 'selected' : ''; ?>>New</option>
                                                <option value="Contacted" <?php echo $r['status'] === 'Contacted' ? 'selected' : ''; ?>>Contacted</option>
                                                <option value="Demo Scheduled" <?php echo $r['status'] === 'Demo Scheduled' ? 'selected' : ''; ?>>Demo Scheduled</option>
                                                <option value="Converted" <?php echo $r['status'] === 'Converted' ? 'selected' : ''; ?>>Converted</option>
                                                <option value="Declined" <?php echo $r['status'] === 'Declined' ? 'selected' : ''; ?>>Declined</option>
                                            </select>
                                            <input type="text" name="admin_notes" value="<?php echo htmlspecialchars($r['admin_notes'] ?? ''); ?>" placeholder="মন্তব্য বা নোট লিখুন..." class="form-control form-control-sm">
                                            <button type="submit" class="btn btn-sm btn-success text-nowrap">Save</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/_layout_bottom.php';
