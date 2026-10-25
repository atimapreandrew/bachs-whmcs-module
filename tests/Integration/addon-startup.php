<?php

function addonStartup($mode)
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Support/addon-startup.php') . ' ' . escapeshellarg($mode);
    $lines = array(); $status = 0;
    exec($command . ' 2>&1', $lines, $status);
    same(0, $status);
    $result = json_decode(implode("\n", $lines), true);
    if (!is_array($result)) { throw new RuntimeException(implode("\n", $lines)); }
    return $result;
}

test('Real addon startup reads native WHMCS settings without global CONFIG', function () {
    foreach (array('empty', 'configured') as $mode) {
        $result = addonStartup($mode);
        same('success', $result['activation']);
        truth(strpos($result['html'], 'bachs-payment-tools') !== false);
        truth(strpos($result['html'], 'Not connected') !== false);
        same(false, strpos($result['html'], 'internal_error') !== false);
        same(array(), $result['logs']);
        same(0, $result['api_calls']);
    }
});

test('Addon permission denial precedes database creation and connection checks', function () {
    $result = addonStartup('denied');
    truth(strpos($result['html'], 'does not have permission') !== false);
    same(0, $result['database_connections']);
    same(0, $result['api_calls']);
});

test('Addon database errors show a safe diagnostic reference without secret SQL', function () {
    $result = addonStartup('database_denied');
    truth(strpos($result['html'], 'database user cannot create or access') !== false);
    truth(preg_match('/Reference: [a-f0-9]{12}/', $result['html']));
    truth(strpos($result['logs'][0], 'SQLSTATE=42000; driver=1142') !== false);
    $output = json_encode($result);
    same(false, strpos($output, 'sk_live_private') !== false);
    same(false, strpos($output, 'private SQL') !== false);
    same(false, strpos($result['html'], 'bachs-payment-tools') !== false);
});

test('Missing HTTPS System URL produces one clear addon startup error', function () {
    $result = addonStartup('missing_url');
    truth(strpos($result['html'], 'Set an HTTPS System URL') !== false);
    same(false, strpos($result['html'], 'bachs-payment-tools') !== false);
    same(1, count($result['logs']));
});

test('Unexpected addon errors and unavailable activity logging keep diagnostics private', function () {
    foreach (array('unexpected', 'logger_failure') as $mode) {
        $result = addonStartup($mode);
        truth(preg_match('/Reference: [a-f0-9]{12}/', $result['html']));
        $log = $mode === 'unexpected' ? $result['logs'][0] : $result['fallback_log'];
        truth(strpos($log, 'unexpected_error') !== false);
        truth(strpos($log, 'RuntimeException') !== false);
        same(false, strpos(json_encode($result), 'sk_live_private') !== false);
        same(false, strpos(json_encode($result), 'private SQL') !== false);
    }
});
