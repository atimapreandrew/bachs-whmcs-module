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

function bachs_hook_report($error, $context)
{
    $reporter = __DIR__ . '/../../modules/gateways/bachs/lib/src/Infrastructure/Diagnostics.php';
    if (is_file($reporter)) {
        require_once $reporter;
        \Bachs\Whmcs\Infrastructure\Diagnostics::report($error, $context);
    }
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

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    $file = isset($vars['filename']) ? basename($vars['filename'], '.php') : '';
    if (!in_array($file, array('viewinvoice', 'clientarea', 'index'), true)) {
        return '';
    }
    $methodsPage = (isset($_GET['rp']) && is_string($_GET['rp']) && trim($_GET['rp'], '/') === 'account/paymentmethods')
        || (isset($vars['templatefile']) && $vars['templatefile'] === 'account-paymentmethods');
    if ($file !== 'viewinvoice' && !$methodsPage) { return ''; }
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
        if (!$error instanceof \Bachs\Whmcs\Domain\Failure) {
            bachs_hook_report($error, 'client_footer');
        }
        return '';
    }
});

add_hook('AfterCronJob', 1, function ($vars) {
    try {
        // Bootstrap first: PHP resolves a constructor's class before its arguments.
        $app = bachs_hook_app();
        (new \Bachs\Whmcs\Application\Worker($app))->run();
    } catch (Throwable $error) {
        bachs_hook_report($error, 'cron_worker');
    }
});

add_hook('DailyCronJob', 1, function ($vars) {
    try {
        $app = bachs_hook_app();
        (new \Bachs\Whmcs\Application\Worker($app))->cleanup();
    } catch (Throwable $error) {
        bachs_hook_report($error, 'cron_cleanup');
    }
});

add_hook('ClientDelete', 1, function ($vars) {
    try {
        $app = bachs_hook_app();
        $id = (int)$vars['userid'];
        $cursor = '';
        do {
            $rows = $app->store->run('SELECT id FROM mod_bachs_payment_methods WHERE client_id=? AND id>? ORDER BY id LIMIT 100', array($id, $cursor))->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $method = $app->store->get('payment_methods', $row['id']);
                $app->store->update('payment_methods', $method['id'], array('token' => null, 'revoked_at' => time()), 'revoked');
                $consent = $app->store->get('consents', $method['data']['consent_id']);
                if ($consent) {
                    $app->store->update('consents', $consent['id'], array('revoked_at' => time()), 'revoked');
                }
                $cursor = $row['id'];
            }
        } while (count($rows) === 100);
        $app->store->audit('system', 'client_tokens_revoked', '', (string)$id);
    } catch (Throwable $error) {
        bachs_hook_report($error, 'client_delete');
    }
});
