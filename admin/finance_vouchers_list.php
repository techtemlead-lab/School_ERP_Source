<?php
// admin/finance_vouchers_list.php - Finance Module > Accounts Voucher > Vouchers List (+/-)
// Receive + Payment — দুই ধরনের ভাউচারের কম্বাইন্ড লিস্ট। উপরে প্রতিটা কলামের নিচে
// লাইভ (client-side) ফিল্টার বক্স আছে — টাইপ করার সাথে সাথে টেবিল ফিল্টার হয়ে যায়।

function vlv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$vl_err = $_SESSION['vl_err'] ?? '';
$vl_msg = $_SESSION['vl_msg'] ?? '';
unset($_SESSION['vl_err'], $_SESSION['vl_msg']);

// টেবিল না থাকলে অটো তৈরি (ফ্রেশ ইনস্টলে এই পেজে প্রথম ঢুকলেও যাতে এরর না দেখায়)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_vouchers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        voucher_no VARCHAR(50) NOT NULL,
        voucher_type VARCHAR(20) NOT NULL,
        transaction_date DATE NOT NULL,
        payment_type VARCHAR(10) NOT NULL DEFAULT 'Cash',
        cheque_no VARCHAR(60) NULL,
        party_name VARCHAR(150) NULL,
        description TEXT NULL,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_voucher_no (voucher_no),
        KEY idx_type (voucher_type), KEY idx_date (transaction_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// -----------------------------------------------------------
// Delete Handler
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_voucher'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['vl_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $vl_del_stmt = $pdo->prepare("SELECT voucher_no, voucher_type, total_amount FROM finance_vouchers WHERE id = ?");
            $vl_del_stmt->execute([$id]);
            $vl_del_row = $vl_del_stmt->fetch();
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM finance_voucher_details WHERE voucher_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM finance_vouchers WHERE id = ?")->execute([$id]);
            $pdo->commit();
            if ($vl_del_row) {
                sec_log_activity($pdo, 'Finance', 'Delete', "{$vl_del_row['voucher_type']} Voucher মুছে ফেলা হয়েছে: #{$vl_del_row['voucher_no']}, পরিমাণ ৳" . number_format((float) $vl_del_row['total_amount'], 2));
            }
            $_SESSION['vl_msg'] = 'ভাউচার মুছে ফেলা হয়েছে।';
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Voucher delete failed: ' . $e->getMessage());
            $_SESSION['vl_err'] = 'ভাউচার মুছতে সমস্যা হয়েছে, আবার চেষ্টা করুন।';
        }
    }
    header('Location: index.php?page=finance_vouchers_list');
    exit();
}

$fvl_where = []; $fvl_params = [];
branch_apply_filter($fvl_where, $fvl_params);
$fvl_where_sql = $fvl_where ? (' WHERE ' . implode(' AND ', $fvl_where)) : '';
$fvl_stmt = $pdo->prepare("SELECT * FROM finance_vouchers$fvl_where_sql ORDER BY transaction_date DESC, id DESC");
$fvl_stmt->execute($fvl_params);
$rows = $fvl_stmt->fetchAll();
$total_count = count($rows);

// প্রতিটা ভাউচারের A/C Head নাম (একাধিক হলে কমা দিয়ে)
$fvl_heads = [];
if ($rows) {
    $fvl_ids = implode(',', array_map('intval', array_column($rows, 'id')));
    $fvl_hq = $pdo->query(
        "SELECT vd.voucher_id, fh.ac_code, fh.ac_head
         FROM finance_voucher_details vd
         LEFT JOIN finance_account_heads fh ON fh.id = vd.ac_head_id
         WHERE vd.voucher_id IN ($fvl_ids)
         ORDER BY vd.id ASC"
    );
    foreach ($fvl_hq->fetchAll() as $hr) {
        $fvl_heads[(int) $hr['voucher_id']][] = trim(($hr['ac_code'] ?? '') . ' - ' . ($hr['ac_head'] ?? '—'));
    }
}
$csrf = htmlspecialchars(csrf_token());
?>
<div class="card-custom">
    <?php if ($vl_msg): ?><div class="alert alert-success py-2"><?php echo vlv($vl_msg); ?></div><?php endif; ?>
    <?php if ($vl_err): ?><div class="alert alert-danger py-2"><?php echo vlv($vl_err); ?></div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <a href="index.php?page=finance_receive_voucher" class="btn btn-success btn-sm"><i class="fa-solid fa-plus"></i> Receive Voucher</a>
            <a href="index.php?page=finance_payment_voucher" class="btn btn-danger btn-sm"><i class="fa-solid fa-plus"></i> Payment Voucher</a>
        </div>
        <span class="text-muted small" id="vlShowingText">Showing 1-<?php echo $total_count; ?> of <?php echo $total_count; ?> items.</span>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered align-middle" id="vlTable">
            <thead class="table-light">
                <tr>
                    <th style="width:50px;">Srl.</th>
                    <th>Voucher Type</th>
                    <th>Transaction Date</th>
                    <th>Voucher No</th>
                    <th>Cheque / Deposit Slip</th>
                    <th>Pay Or Receive</th>
                    <th>Description</th>
                    <th>A/C Head</th>
                    <th class="text-end">Amount</th>
                    <th style="width:90px;">Actions</th>
                </tr>
                <tr class="vl-filter-row">
                    <th></th>
                    <th>
                        <select class="form-select form-select-sm vl-filter" data-col="1">
                            <option value="">All</option>
                            <option value="Receive Voucher">Receive</option>
                            <option value="Payment Voucher">Payment</option>
                        </select>
                    </th>
                    <th><input type="text" class="form-control form-control-sm vl-filter" data-col="2" placeholder="Search"></th>
                    <th><input type="text" class="form-control form-control-sm vl-filter" data-col="3" placeholder="Search"></th>
                    <th><input type="text" class="form-control form-control-sm vl-filter" data-col="4" placeholder="Search"></th>
                    <th><input type="text" class="form-control form-control-sm vl-filter" data-col="5" placeholder="Search"></th>
                    <th><input type="text" class="form-control form-control-sm vl-filter" data-col="6" placeholder="Search"></th>
                    <th><input type="text" class="form-control form-control-sm vl-filter" data-col="7" placeholder="Search"></th>
                    <th><input type="text" class="form-control form-control-sm vl-filter" data-col="8" placeholder="Search"></th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="vlTableBody">
                <?php if ($rows): $srl = 1; foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo $srl++; ?></td>
                        <td>
                            <?php if ($row['voucher_type'] === 'Receive'): ?>
                                <span class="badge bg-success">Receive Voucher</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Payment Voucher</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo vlv(date('d F Y', strtotime($row['transaction_date']))); ?></td>
                        <td class="fw-bold text-primary"><?php echo vlv($row['voucher_no']); ?></td>
                        <td><?php echo vlv($row['cheque_no'] ?: '—'); ?></td>
                        <td><?php echo vlv($row['party_name'] ?: '—'); ?></td>
                        <td><?php echo nl2br(vlv($row['description'])); ?></td>
                        <td><?php echo vlv(implode(', ', array_unique($fvl_heads[(int) $row['id']] ?? [])) ?: '—'); ?></td>
                        <td class="text-end fw-bold <?php echo $row['voucher_type'] === 'Receive' ? 'text-success' : 'text-danger'; ?>"><?php echo number_format((float) $row['total_amount'], 2); ?></td>
                        <td class="text-nowrap">
                            <a href="index.php?page=finance_voucher_print&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Print" target="_blank"><i class="fa-solid fa-print"></i></a>
                            <form method="POST" action="index.php?page=finance_vouchers_list" class="d-inline" onsubmit="return confirm('এই ভাউচারটা মুছে ফেলবেন?');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                <button type="submit" name="delete_voucher" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="10" class="text-center text-muted py-3">No data found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const filters = Array.from(document.querySelectorAll('.vl-filter'));
    const rows = Array.from(document.querySelectorAll('#vlTableBody tr')).filter(r => r.children.length > 1);
    const showingText = document.getElementById('vlShowingText');
    const totalCount = rows.length;

    function applyFilters() {
        let visible = 0;
        rows.forEach(row => {
            const cells = row.children;
            let match = true;
            filters.forEach(f => {
                const col = parseInt(f.dataset.col, 10);
                const val = f.value.trim().toLowerCase();
                if (!val) return;
                const cellText = (cells[col] ? cells[col].textContent : '').toLowerCase();
                if (!cellText.includes(val)) match = false;
            });
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        showingText.textContent = 'Showing ' + (visible ? '1-' + visible : '0') + ' of ' + totalCount + ' items.';
    }

    filters.forEach(f => f.addEventListener('input', applyFilters));
    filters.forEach(f => f.addEventListener('change', applyFilters));
});
</script>
