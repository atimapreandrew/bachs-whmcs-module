<?php

if (!defined('WHMCS')) {
    die('Access denied');
}

// Register hooks without loading payment code during WHMCS gateway discovery.
function bachs_hook_app()
{
    require_once __DIR__ . '/../../modules/gateways/bachs/bootstrap.php';
    return \Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app();
}

function bachs_hook_active()
{
    $root = defined('ROOTDIR') ? ROOTDIR : dirname(dirname(__DIR__));
    require_once $root . '/includes/gatewayfunctions.php';
    $settings = getGatewayVariables('bachs');
    return is_array($settings) && !empty($settings['type']);
}

function bachs_hook_place($id, $selectors)
{
    // Only placement changes. Colours, typography and controls come from WHMCS.
    return '<script>(function(){var el=document.getElementById(' . json_encode($id) . ');var target=document.querySelector(' . json_encode($selectors) . ');if(el&&target){target.appendChild(el);}})();</script>';
}

add_hook('AdminAreaFooterOutput', 1, function ($vars) {
    $file = isset($vars['filename']) ? basename($vars['filename'], '.php') : '';
    if ($file !== 'configgateways' || empty($_SESSION['adminid'])) {
        return '';
    }
    try {
        if (!bachs_hook_active()) {
            return '';
        }
        $app = bachs_hook_app();
        $html = (new \Bachs\Whmcs\Http\AdminView($app, 'configgateways.php'))->render();
        return $html . bachs_hook_place('bachs-payment-tools', '#contentarea, #content, main')
            . '<script src="' . \Bachs\Whmcs\Infrastructure\SafeData::html($app->bridge->systemUrl() . '/modules/gateways/bachs/assets/admin.js') . '" defer></script>';
    } catch (Throwable $error) {
        return '<div class="alert alert-warning" role="alert">Bachs payment tools could not load. Check the complete module upload and the WHMCS activity log.</div>';
    }
});

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    $file = isset($vars['filename']) ? basename($vars['filename'], '.php') : '';
    if (!in_array($file, array('viewinvoice', 'clientarea', 'index'), true)) {
        return '';
    }
    try {
        if (!bachs_hook_active()) {
            return '';
        }
        $app = bachs_hook_app();
        if ($file !== 'viewinvoice' && !\Bachs\Whmcs\Http\ClientView::isMethodsPage($vars)) {
            return '';
        }
        return \Bachs\Whmcs\Http\ClientView::render($app, $vars)
            . bachs_hook_place('bachs-client-controls', '#main-body .main-content, .invoice-container, main, #contentarea');
    } catch (Throwable $error) {
        return '';
    }
});

add_hook('AfterCronJob', 1, function ($vars) {
    try {
        (new \Bachs\Whmcs\Application\Worker(bachs_hook_app()))->run();
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity('Bachs background processing needs attention. Check Payment Gateways > Bachs payment activity.');
        }
    }
});

add_hook('DailyCronJob', 1, function ($vars) {
    try {
        (new \Bachs\Whmcs\Application\Worker(bachs_hook_app()))->cleanup();
    } catch (Throwable $error) {
        // Pending financial records are preserved when cleanup is unavailable.
    }
});

add_hook('ClientDelete', 1, function ($vars) {
    try {
        $app = bachs_hook_app();
        $id = (int)$vars['userid'];
        foreach ($app->store->listing('payment_methods', array('client_id' => $id), 500) as $method) {
            $app->store->update('payment_methods', $method['id'], array('token' => null, 'revoked_at' => time()), 'revoked');
        }
        $app->store->audit('system', 'client_tokens_revoked', '', (string)$id);
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity('Bachs client token cleanup requires review.');
        }
    }
});
