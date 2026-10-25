<?php

if (!defined('WHMCS')) {
    http_response_code(403);
    exit('Access denied');
}
if (version_compare(PHP_VERSION, '5.6.0', '<')) {
    throw new RuntimeException('Bachs requires PHP 5.6 or later.');
}
$bachsManifestPath = __DIR__ . '/lib/release.json';
if (is_file($bachsManifestPath)) {
    $bachsManifest = json_decode(file_get_contents($bachsManifestPath), true);
    if (!is_array($bachsManifest) || !isset($bachsManifest['version'], $bachsManifest['components']) || $bachsManifest['version'] !== '1.0.0') {
        throw new RuntimeException('Bachs component version mismatch. Reinstall the complete package.');
    }
    $bachsRoot = dirname(dirname(dirname(__DIR__)));
    foreach ($bachsManifest['components'] as $bachsComponent => $bachsHash) {
        if (!is_file($bachsRoot . '/' . $bachsComponent) || !hash_equals($bachsHash, hash_file('sha256', $bachsRoot . '/' . $bachsComponent))) {
            throw new RuntimeException('Bachs partial deployment detected. Reinstall the complete package.');
        }
    }
}
$bachsPrivateLoader = __DIR__ . '/lib/autoload.php';
if (is_file($bachsPrivateLoader)) {
    require_once $bachsPrivateLoader;
} else {
    require_once dirname(dirname(dirname(__DIR__))) . '/autoload.php';
}
unset($bachsPrivateLoader);
unset($bachsManifestPath, $bachsManifest, $bachsRoot, $bachsComponent, $bachsHash);
