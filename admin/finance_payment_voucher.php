<?php
// admin/finance_payment_voucher.php - Finance Module > Accounts Voucher > Payment Voucher (-)
// ক্যাশ/ব্যাংক থেকে টাকা পেমেন্ট (ব্যয়) এন্ট্রি করার ভাউচার। Voucher No অটো-জেনারেট হয় (এডিটযোগ্য),
// একাধিক Particulars (A/C Head) লাইন যোগ করা যায়, প্রতিটার Amount + Remarks সহ।
// A/C Head ড্রপডাউনে শুধু finance_head.php তে বানানো Payments ক্যাটাগরির লিফ (Has Child = No) হেডগুলো আসে।

function pvv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function pv_money($n): string { return number_format((float) $n, 2); }

$pv_err = $_SESSION['pv_err'] ?? '';
$pv_msg = $_SESSION['pv_msg'] ?? '';
unset($_SESSION['pv_err'], $_SESSION['pv_msg']);

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_vouchers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        voucher_no VARCHAR(50) NOT NULL,
        voucher_type VARCHAR(20) NOT NULL,
        transaction_date DATE NOT NULL,
        payment_type VARCHAR(10) NOT NULL DEFAULT 'Cash',
        description TEXT NULL,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_voucher_no (voucher_no),
        KEY idx_type (voucher_type), KEY idx_date (transaction_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_voucher_details (
        id INT AUTO_INCREMENT PRIMARY KEY,
        voucher_id INT NOT NULL,
        ac_head_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        remarks VARCHAR(255) NULL,
        KEY idx_voucher (voucher_id), KEY idx_head (ac_head_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// পুরনো ডাটাবেজে finance_vouchers টেবিল থাকলেও নতুন কলাম না থাকতে পারে — নিরাপদে যোগ করা হচ্ছে
function pv_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $chk = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $chk->execute([$table, $column]);
    if (!$chk->fetch()) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
pv_ensure_column($pdo, 'finance_vouchers', 'cheque_no', "VARCHAR(60) NULL AFTER payment_type");
pv_ensure_column($pdo, 'finance_vouchers', 'party_name', "VARCHAR(150) NULL AFTER cheque_no");
branch_ensure_column($pdo, 'finance_vouchers');

// ---------------------------------------------------------------
// পরবর্তী Voucher No জেনারেট করার হেল্পার — PV-YYYYMMDD-### (আজকের তারিখ ভিত্তিক সিরিয়াল)
// ---------------------------------------------------------------
function pv_next_voucher_no(PDO $pdo): string
{
    $prefix = 'PV-' . date('Ymd') . '-';
    $stmt = $pdo->prepare("SELECT voucher_no FROM finance_vouchers WHERE voucher_no LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last) {
        $parts = explode('-', $last);
        $next = ((int) end($parts)) + 1;
    }
    return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
}

// ---------------------------------------------------------------
// Particulars ড্রপডাউনের জন্য A/C Head লিস্ট — শুধু Payments ক্যাটাগরি, Active, লিফ (Has Child = No)
// ---------------------------------------------------------------
$payment_heads = $pdo->query(
    "SELECT id, ac_code, ac_head, ac_type FROM finance_account_heads
     WHERE ac_category = 'Payments' AND status = 'Active' AND has_child = 'No'
     ORDER BY ac_head ASC"
)->fetchAll();

// -----------------------------------------------------------
// POST Handler — Create Voucher
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment_voucher'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['pv_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=finance_payment_voucher');
        exit();
    }

    $voucher_no   = trim($_POST['voucher_no'] ?? '');
    $trans_date   = trim($_POST['transaction_date'] ?? '');
    $payment_type = trim($_POST['payment_type'] ?? 'Cash');
    $cheque_no    = trim($_POST['cheque_no'] ?? '');
    $party_name   = trim($_POST['party_name'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $head_ids     = $_POST['ac_head_id'] ?? [];
    $amounts      = $_POST['amount'] ?? [];
    $remarks_arr  = $_POST['remarks'] ?? [];

    $valid_head_ids = array_column($payment_heads, 'id');

    // ---- ভ্যালিডেশন ----
    if ($voucher_no === '' || $trans_date === '') {
        $_SESSION['pv_err'] = 'Voucher No এবং Transaction Date আবশ্যক।';
    } elseif (!in_array($payment_type, ['Cash', 'Bank'], true)) {
        $_SESSION['pv_err'] = 'সঠিক Payment Type সিলেক্ট করুন।';
    } else {
        // পার্টিকুলার্স লাইনগুলো ক্লিন করা — খালি সারি বাদ, ভ্যালিড head + amount > 0 রাখা
        $lines = [];
        foreach ($head_ids as $i => $hid) {
            $hid = (int) $hid;
            $amt = (float) ($amounts[$i] ?? 0);
            $rmk = trim($remarks_arr[$i] ?? '');
            if ($hid > 0 && $amt > 0) {
                $lines[] = ['ac_head_id' => $hid, 'amount' => $amt, 'remarks' => $rmk];
            }
        }

        if (empty($lines)) {
            $_SESSION['pv_err'] = 'কমপক্ষে একটা Particulars লাইনে A/C Head এবং Amount দিতে হবে।';
        } else {
            $invalid = false;
            foreach ($lines as $ln) {
                if (!in_array($ln['ac_head_id'], $valid_head_ids, true)) { $invalid = true; break; }
            }
            if ($invalid) {
                $_SESSION['pv_err'] = 'একটা বা একাধিক A/C Head সঠিক না। পেজ রিলোড করে আবার চেষ্টা করুন।';
            } else {
                $dup_stmt = $pdo->prepare("SELECT id FROM finance_vouchers WHERE voucher_no = ? LIMIT 1");
                $dup_stmt->execute([$voucher_no]);
                if ($dup_stmt->fetch()) {
                    $_SESSION['pv_err'] = 'এই Voucher No ইতিমধ্যে ব্যবহৃত হয়েছে, অন্য একটা নম্বর দিন।';
                } else {
                    $total = 0.0;
                    foreach ($lines as $ln) { $total += $ln['amount']; }

                    try {
                        $pdo->beginTransaction();

                        $ins = $pdo->prepare(
                            "INSERT INTO finance_vouchers (voucher_no, voucher_type, transaction_date, payment_type, cheque_no, party_name, description, total_amount, created_by, branch_id)
                             VALUES (?, 'Payment', ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $ins->execute([$voucher_no, $trans_date, $payment_type, $cheque_no ?: null, $party_name ?: null, $description, $total, $_SESSION['admin_user'] ?? '', branch_id_for_insert()]);
                        $voucher_id = (int) $pdo->lastInsertId();

                        $insd = $pdo->prepare(
                            "INSERT INTO finance_voucher_details (voucher_id, ac_head_id, amount, remarks) VALUES (?, ?, ?, ?)"
                        );
                        foreach ($lines as $ln) {
                            $insd->execute([$voucher_id, $ln['ac_head_id'], $ln['amount'], $ln['remarks']]);
                        }

                        $pdo->commit();
                        sec_log_activity($pdo, 'Finance', 'Create', "নতুন Payment Voucher তৈরি করা হয়েছে: #{$voucher_no}, পরিমাণ ৳" . pv_money($total) . ($party_name !== '' ? ", Paid To: {$party_name}" : ''));
                        $_SESSION['pv_msg'] = 'Payment Voucher #' . $voucher_no . ' সফলভাবে তৈরি করা হয়েছে। মোট পরিমাণ: ৳' . pv_money($total);
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        error_log('Payment Voucher save failed: ' . $e->getMessage());
                        $_SESSION['pv_err'] = 'ভাউচার সেভ করতে সমস্যা হয়েছে, আবার চেষ্টা করুন।';
                    }
                }
            }
        }
    }

    header('Location: index.php?page=finance_payment_voucher');
    exit();
}

$next_voucher_no = pv_next_voucher_no($pdo);
?>
<div class="card-custom">
    <?php if ($pv_msg): ?><div class="alert alert-success py-2"><i class="fa-solid fa-check-circle me-1"></i> <?php echo pvv($pv_msg); ?></div><?php endif; ?>
    <?php if ($pv_err): ?><div class="alert alert-danger py-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> <?php echo pvv($pv_err); ?></div><?php endif; ?>

    <?php if (empty($payment_heads)): ?>
        <div class="alert alert-warning py-2">
            কোনো <strong>Payments</strong> ক্যাটাগরির A/C Head পাওয়া যায়নি। ভাউচার তৈরি করার আগে
            <a href="index.php?page=finance_head&view=add">Finance Module &raquo; Accounts Head</a> থেকে
            Category = <strong>Payments</strong>, Has Child = <strong>No</strong> দিয়ে অন্তত একটা A/C Head তৈরি করুন।
        </div>
    <?php endif; ?>

    <form method="POST" action="index.php?page=finance_payment_voucher" id="pvForm">
        <?php echo csrf_field(); ?>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Voucher No <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <input type="text" name="voucher_no" class="form-control" required value="<?php echo pvv($next_voucher_no); ?>">
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Transaction Date <span class="text-danger">*</span></label>
            <div class="col-sm-6">
                <input type="date" name="transaction_date" class="form-control" required value="<?php echo pvv(date('Y-m-d')); ?>">
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Payment Type</label>
            <div class="col-sm-6">
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="payment_type" id="pvPayCash" value="Cash" checked>
                    <label class="form-check-label" for="pvPayCash">Cash</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="payment_type" id="pvPayBank" value="Bank">
                    <label class="form-check-label" for="pvPayBank">Bank</label>
                </div>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Cheque / Deposit Slip No</label>
            <div class="col-sm-6">
                <input type="text" name="cheque_no" class="form-control" placeholder="Bank পেমেন্টের ক্ষেত্রে (ঐচ্ছিক)">
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label fw-bold text-end">Paid To</label>
            <div class="col-sm-6">
                <input type="text" name="party_name" class="form-control" placeholder="কাকে টাকা পেমেন্ট করা হলো (ঐচ্ছিক)">
            </div>
        </div>

        <div class="row mb-3">
            <label class="col-sm-3 col-form-label fw-bold text-end pt-0"></label>
            <div class="col-sm-9">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-2" id="pvItemsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Particulars</th>
                                <th style="width:160px;" class="text-end">Amount</th>
                                <th style="width:180px;">Remarks</th>
                                <th style="width:70px;" class="text-center">Remove</th>
                            </tr>
                        </thead>
                        <tbody id="pvItemsBody">
                            <?php // একটা ফাঁকা টেমপ্লেট রো ?>
                            <tr class="pv-item-row">
                                <td>
                                    <select name="ac_head_id[]" class="form-select pv-head-select">
                                        <option value="">Select A/C Head</option>
                                        <?php foreach ($payment_heads as $head): ?>
                                            <option value="<?php echo (int) $head['id']; ?>">
                                                <?php echo pvv($head['ac_head']); ?> (<?php echo pvv($head['ac_code']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="number" name="amount[]" class="form-control text-end pv-amount" min="0" step="0.01" placeholder="0.00"></td>
                                <td><input type="text" name="remarks[]" class="form-control" placeholder="Remarks"></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-danger pv-remove-row" title="Remove"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="text-end fw-bold">Total</td>
                                <td class="text-end fw-bold" id="pvTotalAmount">0.00</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="pvAddRowBtn"><i class="fa-solid fa-plus"></i> Add Row</button>
            </div>
        </div>

        <div class="row mb-3">
            <label class="col-sm-3 col-form-label fw-bold text-end">Description</label>
            <div class="col-sm-9">
                <textarea name="description" class="form-control" rows="4"></textarea>
            </div>
        </div>

        <div class="row">
            <div class="col-sm-9 offset-sm-3">
                <button type="submit" name="save_payment_voucher" class="btn btn-primary px-4" <?php echo empty($payment_heads) ? 'disabled' : ''; ?>>Create</button>
                <a href="index.php?page=dashboard" class="btn btn-danger px-4">Cancel</a>
            </div>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const body = document.getElementById('pvItemsBody');
    const addBtn = document.getElementById('pvAddRowBtn');
    const totalCell = document.getElementById('pvTotalAmount');
    const form = document.getElementById('pvForm');

    function recalcTotal() {
        let total = 0;
        body.querySelectorAll('.pv-amount').forEach(inp => {
            total += parseFloat(inp.value) || 0;
        });
        totalCell.textContent = total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function bindRow(row) {
        row.querySelector('.pv-amount').addEventListener('input', recalcTotal);
        const removeBtn = row.querySelector('.pv-remove-row');
        removeBtn.addEventListener('click', function () {
            if (body.querySelectorAll('.pv-item-row').length > 1) {
                row.remove();
                recalcTotal();
            } else {
                // শেষ রো একদম মুছে না ফেলে ইনপুট ক্লিয়ার করে দেওয়া
                row.querySelector('.pv-head-select').value = '';
                row.querySelector('.pv-amount').value = '';
                row.querySelector('input[name="remarks[]"]').value = '';
                recalcTotal();
            }
        });
    }

    body.querySelectorAll('.pv-item-row').forEach(bindRow);

    addBtn.addEventListener('click', function () {
        const firstRow = body.querySelector('.pv-item-row');
        const newRow = firstRow.cloneNode(true);
        newRow.querySelectorAll('input').forEach(inp => inp.value = '');
        newRow.querySelector('.pv-head-select').value = '';
        body.appendChild(newRow);
        bindRow(newRow);
    });

    form.addEventListener('submit', function (e) {
        let hasValidLine = false;
        body.querySelectorAll('.pv-item-row').forEach(row => {
            const head = row.querySelector('.pv-head-select').value;
            const amt = parseFloat(row.querySelector('.pv-amount').value) || 0;
            if (head && amt > 0) { hasValidLine = true; }
        });
        if (!hasValidLine) {
            e.preventDefault();
            alert('কমপক্ষে একটা Particulars লাইনে A/C Head এবং Amount দিন।');
        }
    });
});
</script>
