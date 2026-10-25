<?php

if (!defined('WHMCS')) {
    http_response_code(403);
    exit('Access denied');
}
if (version_compare(PHP_VERSION, '7.2.0', '<')) {
    throw new RuntimeException('Bachs requires PHP 7.2 or later on a supported WHMCS release.');
}
$bachsWhmcsVersion = defined('WHMCS_VERSION') ? WHMCS_VERSION : (isset($GLOBALS['CONFIG']['Version']) ? $GLOBALS['CONFIG']['Version'] : null);
if ($bachsWhmcsVersion === null && class_exists('WHMCS\\Config\\Setting')) {
    $bachsWhmcsVersion = \WHMCS\Config\Setting::getValue('Version');
}
if ($bachsWhmcsVersion !== null && version_compare($bachsWhmcsVersion, '8.0', '<')) {
    throw new RuntimeException('Bachs requires WHMCS 8.0 or later.');
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
    throw new RuntimeException('Bachs runtime library is missing. Upload the complete modules and includes folders.');
}
unset($bachsPrivateLoader);
unset($bachsManifestPath, $bachsManifest, $bachsRoot, $bachsComponent, $bachsHash);
unset($bachsWhmcsVersion);
