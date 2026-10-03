<?php
declare(strict_types=1);
if (!defined('ERP_ENV_LOADED')) {
    define('ERP_ENV_LOADED', true);
    $file = dirname(__DIR__) . '/.env';
    if (getenv('ERP_ROLE') !== 'demo' && is_file($file)) foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2); $key = trim($key);
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key) || getenv($key) !== false) continue;
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value,-1) === '"') || ($value[0] === "'" && substr($value,-1) === "'"))) $value = substr($value,1,-1);
        putenv($key . '=' . $value);
    }
}
function erp_env(string $key, string $default = ''): string {
    $value = getenv($key); return $value === false ? $default : $value;
}
