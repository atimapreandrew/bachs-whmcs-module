<?php

if (!defined('WHMCS') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Access denied');
}

spl_autoload_register(function ($class) {
    $prefix = 'Bachs\\Whmcs\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $file = __DIR__ . '/src/' . $relative;
    if (!is_file($file)) {
        return;
    }
    static $manifest;
    if ($manifest === null) {
        $manifest = is_file(__DIR__ . '/release.json') ? json_decode(file_get_contents(__DIR__ . '/release.json'), true) : false;
    }
    if ($manifest !== false && (!isset($manifest['classes'][$relative]) || !hash_equals($manifest['classes'][$relative], hash_file('sha256', $file)))) {
        throw new RuntimeException('Bachs component integrity mismatch. Reinstall the complete package.');
    }
    require_once $file;
});
