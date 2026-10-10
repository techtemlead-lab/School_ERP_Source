<?php
/** Additive academic schema for existing schools and fresh isolated demo pools. */
require_once __DIR__ . '/subject_rank.php';
require_once __DIR__ . '/subject_religion.php';
require_once __DIR__ . '/academic_calendar_helpers.php';
function erp_academic_update_schema(PDO $pdo): void
{
    subject_rank_ensure($pdo);
    sr_ensure($pdo);
    ac_ensure_tables($pdo);
    foreach (['sort_order', 'religion'] as $column) {
        $q = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='academic_subjects' AND COLUMN_NAME=?");
        $q->execute([$column]);
        if (!$q->fetchColumn()) { throw new RuntimeException('Academic schema migration is incomplete.'); }
    }
}
