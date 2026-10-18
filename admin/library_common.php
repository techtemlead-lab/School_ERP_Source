<?php
// admin/library_common.php - Library মডিউলের শেয়ার্ড হেল্পার (branch scope, valid date, row lock)
if (!function_exists('lib_valid_date')) {
    function lib_valid_date($d): bool {
        $d = (string) $d;
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d;
    }
}
if (!function_exists('lib_student_scoped')) {
    // শিক্ষার্থী বর্তমান branch-এর (বা shared) হলে row ফেরত দেয়; $lock=true হলে FOR UPDATE
    function lib_student_scoped(PDO $pdo, int $id, bool $lock = false) {
        $where = ['students.id = ?']; $params = [$id];
        branch_apply_filter($where, $params, 'students.branch_id');
        $st = $pdo->prepare("SELECT * FROM students WHERE " . implode(' AND ', $where) . ($lock ? ' FOR UPDATE' : ''));
        $st->execute($params);
        return $st->fetch();
    }
}
if (!function_exists('lib_issue_scoped')) {
    // ইস্যু রেকর্ড (শিক্ষার্থীর branch দিয়ে scope), FOR UPDATE
    function lib_issue_scoped(PDO $pdo, int $issue_id) {
        $where = ['li.id = ?']; $params = [$issue_id];
        branch_apply_filter($where, $params, 'students.branch_id');
        $st = $pdo->prepare("SELECT li.*, lb.title, lb.book_code, lb.price AS book_price, students.name_en
                             FROM library_issues li
                             JOIN students ON students.id = li.student_id
                             LEFT JOIN library_books lb ON lb.id = li.book_id
                             WHERE " . implode(' AND ', $where) . " FOR UPDATE");
        $st->execute($params);
        return $st->fetch();
    }
}
