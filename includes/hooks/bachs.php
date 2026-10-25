<?php

if (!defined('WHMCS')) {
    die('Access denied');
}
require_once __DIR__ . '/../../modules/gateways/bachs/bootstrap.php';
add_hook('AdminAreaPage', 1, function ($vars) {
    if (isset($_GET['module']) && $_GET['module'] === 'bachs_admin' && !headers_sent()) {
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
    }
    return array();
});
add_hook('AfterCronJob', 1, function ($vars) {
    try {
        (new \Bachs\Whmcs\Application\Worker(\Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app()))->run();
    } catch (Exception $e) {
        if (function_exists('logActivity')) {
            logActivity('Bachs worker needs attention. Check Addons > Bachs > Diagnostics.');
        }
    }
});
add_hook('DailyCronJob', 1, function ($vars) {
    try {
        (new \Bachs\Whmcs\Application\Worker(\Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app()))->cleanup();
    } catch (Exception $e) {
    /* Worker diagnostics retains pending records. */
    }
});
add_hook('ClientDelete', 1, function ($vars) {
    try {
        $app = \Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app();
        $id = (int)$vars['userid'];
        foreach ($app->store->listing('payment_methods', array('client_id' => $id), 500) as $method) {
            $app->store->update('payment_methods', $method['id'], array('token' => null, 'revoked_at' => time()), 'revoked');
        }
        $app->store->audit('system', 'client_tokens_revoked', '', (string)$id);
    } catch (Exception $e) {
        if (function_exists('logActivity')) {
            logActivity('Bachs client token cleanup requires review.');
        }
    }
});
