<?php

if (!defined('WHMCS')) {
    die('Access denied');
}

function bachs_payments_config()
{
    return array('name' => 'Bachs Payments',
        'description' => 'Payment history, refund requests, webhooks and diagnostics. Configure payment settings in the Bachs payment gateway.',
        'version' => '1.0.0', 'author' => 'Andrew Atimapre', 'language' => 'english', 'fields' => array());
}

function bachs_payments_activate()
{
    $version = defined('WHMCS_VERSION') ? WHMCS_VERSION : (isset($GLOBALS['CONFIG']['Version']) ? $GLOBALS['CONFIG']['Version'] : '0');
    if (class_exists('WHMCS\\Config\\Setting')) {
        $nativeVersion = \WHMCS\Config\Setting::getValue('Version');
        if (is_string($nativeVersion) && $nativeVersion !== '') {
            $version = $nativeVersion;
        }
    }
    if (version_compare($version, '8.0', '<')) {
        return array('status' => 'error', 'description' => 'Bachs Payments requires WHMCS 8.0 or later.');
    }
    return array('status' => 'success', 'description' => 'Payment tools enabled. Assign the allowed administrator roles. Payment settings remain in the Bachs payment gateway.');
}

function bachs_payments_deactivate()
{
    return array('status' => 'success', 'description' => 'Payment tools disabled. Bachs gateways, callbacks and background processing continue to operate.');
}

function bachs_payments_output($vars)
{
    try {
        require_once __DIR__ . '/../../gateways/bachs/bootstrap.php';
        (new \Bachs\Whmcs\Infrastructure\Whmcs\Identity())->admin('view_payments');
        $app = \Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app(false);
        $script = '<script src="' . \Bachs\Whmcs\Infrastructure\SafeData::html($app->bridge->systemUrl() . '/modules/gateways/bachs/assets/admin.js') . '" defer></script>';
        $body = (new \Bachs\Whmcs\Http\AdminView($app, $vars['modulelink']))->render();
        echo $body . $script;
    } catch (Throwable $error) {
        // Load the small reporter directly so a broken release loader cannot hide
        // the startup failure behind another autoload exception.
        $reporter = __DIR__ . '/../../gateways/bachs/lib/src/Infrastructure/Diagnostics.php';
        if (is_file($reporter)) {
            require_once $reporter;
            $report = \Bachs\Whmcs\Infrastructure\Diagnostics::report($error, 'addon_open');
            $message = \Bachs\Whmcs\Infrastructure\Diagnostics::message($report);
        } else {
            error_log('Bachs addon startup: missing runtime reporter; ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine());
            $message = 'The Bachs runtime files are missing. Upload the complete installation ZIP.';
        }
        echo '<div class="alert alert-warning" role="alert">' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    }
}
