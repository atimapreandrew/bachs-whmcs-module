<?php

if (!defined('WHMCS')) {
    die('Access denied');
}
require_once __DIR__ . '/../../gateways/bachs/bootstrap.php';

function bachs_admin_config()
{
    return array('name' => 'Bachs', 'description' => 'Merchant checkout, reconciliation and payment operations. Independent integration.',
        'version' => '1.0.0', 'author' => 'Andrew Atimapre', 'language' => 'english',
        'fields' => array('apiAdmin' => array('FriendlyName' => 'Local API execution identity', 'Type' => 'text', 'Size' => '30',
            'Description' => 'Active WHMCS administrator username. Required for WHMCS 7.0–7.1 callbacks/cron. Use a dedicated least-privilege identity; no password is stored.')));
}
function bachs_admin_activate()
{
    try {
        foreach (array('curl','openssl','json','pdo_mysql') as $ext) {
            if (!extension_loaded($ext)) {
                throw new RuntimeException('Missing required extension: ' . $ext);
            }
        }
        $pdo = \WHMCS\Database\Capsule::connection()->getPdo();
        \Bachs\Whmcs\Infrastructure\Storage\Schema::migrate($pdo);
        $app = \Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app();
        $executionIdentity = \WHMCS\Database\Capsule::table('tbladdonmodules')->where('module', 'bachs_admin')->where('setting', 'apiAdmin')->value('value');
        if ($executionIdentity) {
            $app->settings->set('api_admin', (string)$executionIdentity);
        }
        if (!$app->settings->get('installation_id')) {
            $app->settings->set('installation_id', \Bachs\Whmcs\Domain\Ids::random());
        }
        $probe = \Bachs\Whmcs\Domain\Ids::random();
        if (!hash_equals($probe, $app->settings->secrets->open($app->settings->secrets->seal($probe)))) {
            throw new RuntimeException('Encryption round trip failed');
        }
        return array('status' => 'success', 'description' => 'Bachs installed. Assign addon role access, connect your merchant account, configure signed webhooks and complete sandbox acceptance. New collections start disabled.');
    } catch (Exception $e) {
        return array('status' => 'error', 'description' => 'Bachs activation could not complete. Check PHP extensions, private encryption configuration and the legacy API execution identity. Financial records were preserved.');
    }
}
function bachs_admin_upgrade($vars)
{
    \Bachs\Whmcs\Infrastructure\Storage\Schema::migrate(\WHMCS\Database\Capsule::connection()->getPdo());
}
function bachs_admin_deactivate()
{
    try {
        $app = \Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app();
        $app->settings->set('enabled', false);
        $app->settings->set('draining', true);
        $app->store->audit('system', 'addon_draining', '', 'installation');
        return array('status' => 'success', 'description' => 'New collections disabled. Financial history, callbacks and worker reconciliation are preserved. Keep the module files and CLI worker installed until pending payments and refunds are resolved.');
    } catch (Exception $e) {
        return array('status' => 'error', 'description' => 'Could not enter draining mode. Keep the module installed and review pending operations.');
    }
}
function bachs_admin_output($vars)
{
    try {
        echo (new \Bachs\Whmcs\Http\AdminView(\Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app(), $vars['modulelink']))->render();
    } catch (Exception $e) {
        echo '<div role="alert">' . \Bachs\Whmcs\Infrastructure\SafeData::html(\Bachs\Whmcs\Http\Messages::error($e instanceof \Bachs\Whmcs\Domain\Failure ? $e->reason : 'admin_unavailable')) . '</div>';
    }
}
function bachs_admin_clientarea($vars)
{
    try {
        $app = \Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app();
        $actor = $app->bridge->identity->client();
        $methods = array();
        foreach ($app->store->listing('payment_methods', array('client_id' => $actor['client_id']), 100) as $row) {
            $methods[] = array('id' => $row['id'], 'state' => $row['state'], 'brand' => $row['data']['brand'],
                'last4' => $row['data']['last4'], 'expiry_month' => $row['data']['expiry_month'], 'expiry_year' => $row['data']['expiry_year']);
        }
        return array('pagetitle' => 'Bachs payment settings', 'breadcrumb' => array('index.php?m=bachs_admin' => 'Bachs payment settings'),
            'templatefile' => 'client', 'requirelogin' => true, 'forcessl' => true,
            'vars' => array('bachsMethods' => $methods, 'bachsToken' => $app->bridge->identity->token(),
                'bachsRoot' => $app->bridge->systemUrl() . '/modules/gateways/bachs',
                'bachsCanSetup' => $app->settings->get('saved_methods', false) && $app->settings->get('enabled', false),
                'bachsSandbox' => $app->settings->get('environment', 'sandbox') === 'sandbox', 'bachsConsent' => \Bachs\Whmcs\Application\Checkout::consentText()));
    } catch (Exception $e) {
        return array('pagetitle' => 'Bachs payment settings', 'templatefile' => 'client', 'requirelogin' => true, 'forcessl' => true,
            'vars' => array('bachsError' => \Bachs\Whmcs\Http\Messages::error($e instanceof \Bachs\Whmcs\Domain\Failure ? $e->reason : 'unavailable')));
    }
}
