<?php
/**
 * config/accounts_helpers.php
 * -----------------------------------------------------------
 * "Accounts Module" — প্রতিষ্ঠানের পূর্ণ Double-Entry Accounting (Finance Module থেকে সম্পূর্ণ
 * আলাদা — Finance Module আগের মতোই Student Fee Collection-এর জন্য থাকবে, ভাঙা হয়নি)।
 *
 * এখানে একটা রিয়েল Chart of Accounts (Asset/Liability/Equity/Income/Expense classification)
 * আছে, এবং প্রতিটা লেনদেন Journal Entry হিসেবে যায় যেখানে Debit-এর সমষ্টি = Credit-এর সমষ্টি
 * (ac_post_journal() ফাংশনে এটা enforce করা হয়) — তাই Trial Balance সবসময় স্বয়ংক্রিয়ভাবে
 * ব্যালেন্স করবে, এবং Balance Sheet-এ Assets = Liabilities + Equity সঠিকভাবে বসবে।
 *
 * Vendor Bill তৈরি হলে Dr [Expense/Asset Account] + Cr Accounts Payable — একটা জার্নাল
 * অটো-পোস্ট হয়। Vendor Payment হলে Dr Accounts Payable + Cr Cash/Bank — আরেকটা জার্নাল।
 * Journal/Receipt/Payment/Contra Voucher পেজ থেকে ম্যানুয়ালি করলেও একই ac_post_journal()
 * ব্যবহার হয়, তাই পুরো সিস্টেমে ডাটা কনসিস্টেন্ট থাকে।
 */

if (!function_exists('ac_ensure_tables')) {
    function ac_ensure_tables(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS accounts_chart (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(20) NOT NULL,
                name VARCHAR(150) NOT NULL,
                account_type ENUM('Asset','Liability','Equity','Income','Expense') NOT NULL,
                parent_id INT NULL,
                is_group TINYINT(1) NOT NULL DEFAULT 0,
                opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
                opening_balance_type ENUM('Dr','Cr') NOT NULL DEFAULT 'Dr',
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'Active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_code (code),
                KEY idx_type (account_type), KEY idx_parent (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS accounts_journals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                journal_no VARCHAR(30) NOT NULL,
                journal_type ENUM('Journal','Receipt','Payment','Contra') NOT NULL DEFAULT 'Journal',
                journal_date DATE NOT NULL,
                narration VARCHAR(500) NULL,
                reference_type VARCHAR(50) NULL,
                reference_id INT NULL,
                created_by VARCHAR(100) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_journal_no (journal_no),
                KEY idx_date (journal_date), KEY idx_type (journal_type),
                KEY idx_ref (reference_type, reference_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS accounts_journal_lines (
                id INT AUTO_INCREMENT PRIMARY KEY,
                journal_id INT NOT NULL,
                account_id INT NOT NULL,
                debit DECIMAL(14,2) NOT NULL DEFAULT 0,
                credit DECIMAL(14,2) NOT NULL DEFAULT 0,
                remarks VARCHAR(255) NULL,
                KEY idx_journal (journal_id), KEY idx_account (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS accounts_vendors (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                phone VARCHAR(30) NULL,
                email VARCHAR(150) NULL,
                address VARCHAR(255) NULL,
                opening_due DECIMAL(14,2) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'Active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS accounts_vendor_bills (
                id INT AUTO_INCREMENT PRIMARY KEY,
                vendor_id INT NOT NULL,
                bill_no VARCHAR(50) NOT NULL,
                bill_date DATE NOT NULL,
                expense_account_id INT NOT NULL,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                description VARCHAR(255) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Unpaid',
                journal_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_vendor (vendor_id), KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS accounts_vendor_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                vendor_id INT NOT NULL,
                bill_id INT NULL,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                payment_date DATE NOT NULL,
                payment_account_id INT NOT NULL,
                journal_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_vendor (vendor_id), KEY idx_bill (bill_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        ac_seed_default_accounts($pdo);
    }
}

/** প্রথমবার ব্যবহার করলে একটা স্ট্যান্ডার্ড Chart of Accounts অটো-তৈরি হয়ে যায়, যাতে ইউজারকে
 *  খালি হাতে শুরু করতে না হয় — পরে চাইলে নিজে যেকোনো Account যুক্ত/এডিট করতে পারবে */
if (!function_exists('ac_seed_default_accounts')) {
    function ac_seed_default_accounts(PDO $pdo): void
    {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM accounts_chart")->fetchColumn();
        if ($count > 0) return;

        $defaults = [
            // code, name, type, is_system
            ['1000', 'Cash in Hand', 'Asset', 1],
            ['1001', 'Bank Account', 'Asset', 1],
            ['1002', 'Accounts Receivable (Student Dues)', 'Asset', 1],
            ['2000', 'Accounts Payable', 'Liability', 1],
            ['3000', "Owner's Capital", 'Equity', 1],
            ['3001', 'Retained Earnings', 'Equity', 1],
            ['4000', 'Fee Income', 'Income', 0],
            ['4001', 'Other Income', 'Income', 0],
            ['5000', 'Salary Expense', 'Expense', 0],
            ['5001', 'Office Expense', 'Expense', 0],
            ['5002', 'Utility Expense', 'Expense', 0],
            ['5003', 'Other Expense', 'Expense', 0],
        ];
        $stmt = $pdo->prepare(
            "INSERT INTO accounts_chart (code, name, account_type, is_system, status) VALUES (?, ?, ?, ?, 'Active')"
        );
        foreach ($defaults as $d) {
            $stmt->execute([$d[0], $d[1], $d[2], $d[3]]);
        }
    }
}

/** Account Type-এর "স্বাভাবিক পক্ষ" (normal balance side) — Asset/Expense বাড়লে Debit,
 *  Liability/Equity/Income বাড়লে Credit। এটা দিয়ে ব্যালেন্স সাইন ঠিক করা হয়। */
if (!function_exists('ac_normal_side')) {
    function ac_normal_side(string $account_type): string
    {
        return in_array($account_type, ['Asset', 'Expense'], true) ? 'Dr' : 'Cr';
    }
}

/** Voucher/Journal নাম্বার জেনারেট করা — প্রিফিক্স অনুযায়ী (JV/RV/PV/CV)-YYYYMMDD-### */
if (!function_exists('ac_next_journal_no')) {
    function ac_next_journal_no(PDO $pdo, string $prefix): string
    {
        $today = date('Ymd');
        $like = $prefix . '-' . $today . '-%';
        $stmt = $pdo->prepare("SELECT journal_no FROM accounts_journals WHERE journal_no LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$like]);
        $last = $stmt->fetchColumn();
        $seq = 1;
        if ($last) {
            $parts = explode('-', $last);
            $seq = ((int) end($parts)) + 1;
        }
        return sprintf('%s-%s-%03d', $prefix, $today, $seq);
    }
}

/**
 * একটা সম্পূর্ণ Journal Entry পোস্ট করে (Debit = Credit মিলতেই হবে, না মিললে Exception)।
 * $lines = [['account_id' => 5, 'debit' => 500, 'credit' => 0, 'remarks' => '...'], ...]
 * রিটার্ন করে নতুন journal_id।
 */
if (!function_exists('ac_post_journal')) {
    function ac_post_journal(
        PDO $pdo,
        string $journal_type,
        string $journal_date,
        string $narration,
        array $lines,
        string $created_by,
        ?string $reference_type = null,
        ?int $reference_id = null
    ): int {
        ac_ensure_tables($pdo);

        $total_dr = 0.0;
        $total_cr = 0.0;
        foreach ($lines as $l) {
            $total_dr += (float) ($l['debit'] ?? 0);
            $total_cr += (float) ($l['credit'] ?? 0);
        }
        if (round($total_dr, 2) !== round($total_cr, 2)) {
            throw new RuntimeException('Debit ও Credit-এর সমষ্টি সমান নয় — জার্নাল পোস্ট করা যাবে না।');
        }
        if (round($total_dr, 2) <= 0) {
            throw new RuntimeException('জার্নালের মোট পরিমাণ ০ এর বেশি হতে হবে।');
        }

        $prefix_map = ['Journal' => 'JV', 'Receipt' => 'RV', 'Payment' => 'PV', 'Contra' => 'CV'];
        $prefix = $prefix_map[$journal_type] ?? 'JV';

        $pdo->beginTransaction();
        try {
            $journal_no = ac_next_journal_no($pdo, $prefix);
            $pdo->prepare(
                "INSERT INTO accounts_journals (journal_no, journal_type, journal_date, narration, reference_type, reference_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([$journal_no, $journal_type, $journal_date, $narration ?: null, $reference_type, $reference_id, $created_by]);
            $journal_id = (int) $pdo->lastInsertId();

            $line_stmt = $pdo->prepare(
                "INSERT INTO accounts_journal_lines (journal_id, account_id, debit, credit, remarks) VALUES (?, ?, ?, ?, ?)"
            );
            foreach ($lines as $l) {
                $debit = (float) ($l['debit'] ?? 0);
                $credit = (float) ($l['credit'] ?? 0);
                if ($debit <= 0 && $credit <= 0) continue;
                $line_stmt->execute([$journal_id, (int) $l['account_id'], $debit, $credit, $l['remarks'] ?? null]);
            }

            $pdo->commit();
            return $journal_id;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

/** একটা Account-এর ব্যালেন্স — opening balance + সব জার্নাল লাইন মিলিয়ে, normal side অনুযায়ী
 *  সাইন ঠিক করে রিটার্ন করে (positive = normal side-এ ব্যালেন্স আছে, negative = বিপরীত পক্ষে) */
if (!function_exists('ac_account_balance')) {
    function ac_account_balance(PDO $pdo, int $account_id, ?string $as_of_date = null): float
    {
        $acc_stmt = $pdo->prepare("SELECT account_type, opening_balance, opening_balance_type FROM accounts_chart WHERE id = ?");
        $acc_stmt->execute([$account_id]);
        $acc = $acc_stmt->fetch();
        if (!$acc) return 0.0;

        $normal = ac_normal_side($acc['account_type']);
        $opening = (float) $acc['opening_balance'] * ($acc['opening_balance_type'] === $normal ? 1 : -1);

        $sql = "SELECT COALESCE(SUM(l.debit),0) AS dr, COALESCE(SUM(l.credit),0) AS cr
                FROM accounts_journal_lines l JOIN accounts_journals j ON j.id = l.journal_id
                WHERE l.account_id = ?";
        $params = [$account_id];
        if ($as_of_date) {
            $sql .= " AND j.journal_date <= ?";
            $params[] = $as_of_date;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        $dr = (float) ($row['dr'] ?? 0);
        $cr = (float) ($row['cr'] ?? 0);

        $movement = $normal === 'Dr' ? ($dr - $cr) : ($cr - $dr);
        return $opening + $movement;
    }
}
