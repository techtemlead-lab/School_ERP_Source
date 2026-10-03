<?php
declare(strict_types=1);
require_once __DIR__ . '/env.php';
date_default_timezone_set('Asia/Dhaka');
function erp_host(): string { return strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))); }
function erp_primary_host(): string { return strtolower(erp_env('ERP_PRIMARY_HOST','schoolmng.techteamlead.com')); }
function erp_demo_host(): string { return strtolower(erp_env('ERP_DEMO_HOST','demo.schoolmng.techteamlead.com')); }
function erp_is_demo(): bool { return PHP_SAPI !== 'cli' && erp_host() === erp_demo_host(); }
function erp_primary_url(): string { return rtrim(erp_env('ERP_PRIMARY_URL','https://' . erp_primary_host()),'/'); }
function erp_demo_url(): string { return rtrim(erp_env('ERP_DEMO_URL','https://' . erp_demo_host()),'/'); }
function erp_e($value): string { return htmlspecialchars((string) $value,ENT_QUOTES,'UTF-8'); }
function erp_https(): bool { return erp_env('ERP_HTTPS') === '1' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'); }
function erp_session_start(): void {
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) return;
    session_name('ERP_' . substr(hash('sha256',erp_host()),0,16));
    ini_set('session.use_strict_mode','1');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>erp_https(),'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
function erp_stop(int $status,string $message): never {
    http_response_code($status); header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>School ERP</title><body style="font:16px system-ui;background:#f4f7f6;color:#12332d;margin:0;padding:8vw"><main style="max-width:640px;margin:auto"><h1>School ERP</h1><p>' . erp_e($message) . '</p><a href="' . erp_e(erp_primary_url().'/demo/') . '">Demo information</a></main></body></html>'; exit;
}
if (PHP_SAPI !== 'cli') {
    if (erp_env('ERP_ROLE') === 'demo' && erp_host() !== erp_demo_host()) erp_stop(421,'Incorrect demo host.');
    if (erp_env('ERP_ROLE') === 'main' && erp_host() === erp_demo_host()) erp_stop(421,'Use the separate demo runtime.');
    $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH) ?: '/');
    if (preg_match('#^/master(?:/|$)#',$path) && erp_host() !== erp_primary_host()) erp_stop(404,'The master panel is available only on the ERP main domain.');
    if (erp_host() === erp_primary_host()) {
        if (preg_match('#^/admin(?:/|$)#',$path)) { header('Location: '.erp_primary_url().'/master/'); exit; }
        if (preg_match('#^/(student|teacher)(?:/|$)#',$path)) erp_stop(404,'Open a school domain to use its portal.');
    }
    if (erp_is_demo()) {
        header('Cache-Control: private, no-store'); header('X-Robots-Tag: noindex, nofollow');
        if (preg_match('#^/(config|sql|cron|tests|deployment)(?:/|$)#',$path) || preg_match('#(oauth|webhook|payment_init|payment_callback)#i',$path)) erp_stop(403,'External connections and online payments are disabled in the public demo.');
    }
}
