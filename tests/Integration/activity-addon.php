<?php

test('Companion addon activation and deactivation do not change gateway payment settings', function () {
    if (!defined('WHMCS')) { define('WHMCS', true); }
    require_once dirname(dirname(__DIR__)) . '/modules/addons/bachs_payments/bachs_payments.php';
    list($app, $provider) = environment();
    $oldConfig = isset($GLOBALS['CONFIG']) ? $GLOBALS['CONFIG'] : null;
    try {
        $GLOBALS['CONFIG']['Version'] = '8.0.0';
        same('success', bachs_payments_activate()['status']);
        same('success', bachs_payments_deactivate()['status']);
        same(true, $app->settings->get('enabled'));
        same(false, $app->settings->get('draining', false));
        same(0, count($provider->calls));
        same(array(), bachs_payments_config()['fields']);
        $GLOBALS['CONFIG']['Version'] = '7.10.3';
        same('error', bachs_payments_activate()['status']);
    } finally {
        if ($oldConfig === null) { unset($GLOBALS['CONFIG']); } else { $GLOBALS['CONFIG'] = $oldConfig; }
    }
});

test('Addon navigation, filters and POST actions retain the WHMCS module route', function () {
    list($app) = environment();
    $oldGet = $_GET; $oldPost = $_POST; $oldServer = $_SERVER;
    try {
        $_GET = array('bachs_page' => 'payments'); $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
        $view = new \Bachs\Whmcs\Http\AdminView($app, 'addonmodules.php?module=bachs_payments');
        $html = $view->render();
        truth(strpos($html, 'addonmodules.php?module=bachs_payments&amp;bachs_page=refunds') !== false);
        truth(strpos($html, 'name="module" value="bachs_payments"') !== false);
        same(false, strpos($html, '?module=bachs_payments?') !== false);
        $_GET = array('bachs_page' => 'overview');
        $html = $view->render();
        truth(strpos($html, 'action="addonmodules.php?module=bachs_payments&amp;bachs_page=overview"') !== false);
        same(false, strpos($html, 'fields above') !== false);
    } finally {
        $_GET = $oldGet; $_POST = $oldPost; $_SERVER = $oldServer;
    }
});

test('Payment staff can view the addon without gateway-configuration permission', function () {
    list($app) = environment();
    $app->bridge->identity->denied = array('manage_settings');
    $oldGet = $_GET; $oldPost = $_POST; $oldServer = $_SERVER;
    try {
        $_GET = array('bachs_page' => 'overview'); $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
        $view = new \Bachs\Whmcs\Http\AdminView($app, 'addonmodules.php?module=bachs_payments');
        $html = $view->render();
        truth(strpos($html, 'Environment') !== false);
        same(false, strpos($html, 'name="bachs_action" value="verify"') !== false);
        $app->bridge->identity->denied[] = 'view_payments';
        fails('action_permission_denied', function () use ($view) { $view->render(); });
    } finally {
        $_GET = $oldGet; $_POST = $oldPost; $_SERVER = $oldServer;
    }
});
