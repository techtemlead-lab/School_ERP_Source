<?php
// config/subject_rank.php — বিষয়ের ক্রম (Rank): academic_subjects.sort_order
// সেলফ-হিলিং: কলাম না থাকলে যোগ করে এবং আগের ক্রম (id অনুযায়ী) প্রতি Class-এ ১,২,৩… বসিয়ে দেয়।
// সব জায়গায় অর্ডার: ORDER BY sort_order ASC, id ASC
if (!function_exists('subject_rank_ensure')) {
function subject_rank_ensure(PDO $pdo): void
{
try {
        if ($pdo->query("SHOW TABLES LIKE 'academic_subjects'")->fetchColumn() === false) return; // টেবিল হলে আবার চেষ্টা হবে
        if ($pdo->query("SHOW COLUMNS FROM academic_subjects LIKE 'sort_order'")->fetch()) return;
        $pdo->exec("ALTER TABLE academic_subjects ADD COLUMN sort_order INT NOT NULL DEFAULT 0");
        subject_rank_normalize($pdo, null);
    } catch (Throwable $e) { /* পরের রিকোয়েস্টে আবার চেষ্টা */  }
}
}
if (!function_exists('subject_rank_normalize')) {
// $class_id null হলে সব Class; প্রতি Class-এ (sort_order, id) ক্রমে ১..n বসায়
function subject_rank_normalize(PDO $pdo, ?int $class_id): void
{
    $sql = "SELECT id, class_id FROM academic_subjects" . ($class_id !== null ? " WHERE class_id = " . (int) $class_id : "") . " ORDER BY class_id ASC, sort_order ASC, id ASC";
    $upd = $pdo->prepare("UPDATE academic_subjects SET sort_order = ? WHERE id = ?");
    $n = 0; $prev = false;
    foreach ($pdo->query($sql)->fetchAll() as $r) {
        if ($prev !== $r['class_id']) { $prev = $r['class_id']; $n = 0; }
        $upd->execute([++$n, (int) $r['id']]);
    }
}
}
if (!function_exists('subject_rank_next')) {
function subject_rank_next(PDO $pdo, $class_id): int
{
    $s = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM academic_subjects WHERE class_id <=> ?");
    $s->execute([$class_id]);
    return (int) $s->fetchColumn() + 1;
}
}
