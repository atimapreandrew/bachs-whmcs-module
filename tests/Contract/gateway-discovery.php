<?php

test('WHMCS discovers both gateways and registers hooks without the runtime or database', function () {
    $root = dirname(dirname(__DIR__));
    $directory = sys_get_temp_dir() . '/bachs-discovery-' . bin2hex(random_bytes(8));
    mkdir($directory);
    foreach (array('bachs.php', 'bachs_card.php') as $name) {
        copy($root . '/modules/gateways/' . $name, $directory . '/' . $name);
    }
    copy($root . '/includes/hooks/bachs.php', $directory . '/hooks.php');
    $script = <<<'PHP'
<?php
define('WHMCS', true);
$hooks = array();
function add_hook($name, $priority, $callback) { $GLOBALS['hooks'][] = $name; }
require __DIR__ . '/bachs.php';
require __DIR__ . '/bachs_card.php';
require __DIR__ . '/hooks.php';
echo json_encode(array('main' => bachs_config(), 'card' => bachs_card_config(),
    'main_meta' => bachs_MetaData(), 'card_meta' => bachs_card_MetaData(),
    'runtime_loaded' => class_exists('Bachs\Whmcs\Infrastructure\Whmcs\Runtime', false), 'hooks' => $hooks));
PHP;
    file_put_contents($directory . '/discover.php', $script);
    try {
        $output = array(); $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($directory . '/discover.php') . ' 2>&1', $output, $exit);
        same(0, $exit);
        $data = json_decode(implode("\n", $output), true);
        same('Bachs', $data['main']['FriendlyName']['Value']);
        same('Bachs saved card', $data['card']['FriendlyName']['Value']);
        same(false, $data['runtime_loaded']);
        truth(in_array('AdminAreaFooterOutput', $data['hooks'], true));
        truth(in_array('ClientAreaFooterOutput', $data['hooks'], true));
        foreach ($data['main'] as $key => $field) {
            truth(in_array($field['Type'], array('System', 'text', 'password', 'yesno', 'dropdown', 'radio', 'textarea'), true));
        }
        foreach (array('sandboxApiKey', 'sandboxSigningSecret', 'liveApiKey', 'liveSigningSecret') as $key) {
            same('password', $data['main'][$key]['Type']);
        }
        foreach (array_keys(\Bachs\Whmcs\Application\PaymentOptions::currencies()) as $code) {
            same('yesno', $data['main']['accept_' . $code]['Type']);
        }
        foreach (array_keys(\Bachs\Whmcs\Application\PaymentOptions::methods()) as $code) {
            same('yesno', $data['main']['offer_' . $code]['Type']);
        }
    } finally {
        foreach (glob($directory . '/*') as $file) { unlink($file); }
        rmdir($directory);
    }
});

test('The source module contains its runtime and assets without a build or addon', function () {
    $root = dirname(dirname(__DIR__));
    foreach (array('modules/gateways/bachs/lib/autoload.php', 'modules/gateways/bachs/lib/src/Application/Checkout.php',
        'modules/gateways/bachs/assets/bachs.js', 'modules/gateways/callback/bachs.php', 'includes/hooks/bachs.php') as $path) {
        truth(is_file($root . '/' . $path));
    }
    same(false, is_file($root . '/modules/addons/bachs_admin/bachs_admin.php'));
    same(false, is_file($root . '/modules/gateways/bachs/assets/bachs.css'));
    foreach (array('bachs', 'bachs_card') as $module) {
        $metadata = json_decode(file_get_contents($root . '/modules/gateways/' . $module . '/whmcs.json'), true);
        same('whmcs-gateways', $metadata['type']);
        same('gateway', $metadata['category']);
        same($module, $metadata['name']);
    }
});

test('The runtime rejects WHMCS 7 and loads on WHMCS 8 and later', function () {
    $bootstrap = dirname(dirname(__DIR__)) . '/modules/gateways/bachs/bootstrap.php';
    foreach (array('7.10.3' => 'rejected', '8.0.0' => 'loaded', '8.13.0' => 'loaded', '9.1.0' => 'loaded') as $version => $expected) {
        $code = "define('WHMCS', true); define('WHMCS_VERSION', " . var_export($version, true) . "); try { require " . var_export($bootstrap, true) . "; echo 'loaded'; } catch (RuntimeException \$e) { echo strpos(\$e->getMessage(), 'WHMCS 8.0') !== false ? 'rejected' : 'unexpected'; }";
        $output = array(); $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $exit);
        same(0, $exit);
        same($expected, implode("\n", $output));
    }
});
