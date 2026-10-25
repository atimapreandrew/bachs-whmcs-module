<?php

use Bachs\Whmcs\Application\Connection;
use Bachs\Whmcs\Application\PaymentOptions;
use Bachs\Whmcs\Infrastructure\Whmcs\GatewayConfiguration;

class NativeSettingsConnection
{
    public $connections = 0;
    public $fail = false;
    private $app;
    private $connection;
    public function __construct($app)
    {
        $this->app = $app;
        $this->connection = new Connection($app);
    }
    public function save($input, $actor) { $this->connection->save($input, $actor); }
    public function signing($scope, $secret, $actor) { $this->connection->signing($scope, $secret, $actor); }
    public function connect($environment, $key, $actor)
    {
        ++$this->connections;
        if ($this->fail) { throw new \Bachs\Whmcs\Domain\Failure('provider_unavailable'); }
        $scope = $this->app->settings->activeScope();
        $scope = $this->app->store->update('merchant_scopes', $scope['id'], array('generation' => hash('sha256', $key)));
        $this->app->settings->saveSecret('key_' . $scope['id'], $key);
        return $scope;
    }
}

function nativeSettings()
{
    if (!defined('WHMCS')) { define('WHMCS', true); }
    require_once dirname(dirname(__DIR__)) . '/modules/gateways/bachs.php';
    $values = array('type' => 'Invoices');
    foreach (bachs_config() as $key => $field) {
        if ($key !== 'FriendlyName') {
            $values[$key] = isset($field['Default']) ? $field['Default'] : '';
        }
    }
    $values['enabled'] = 'on';
    $values['testInstallation'] = 'on';
    $values['sandboxApiKey'] = 'sk_sandbox_native';
    $values['sandboxSigningSecret'] = 'synthetic-native-signing-secret';
    return $values;
}

test('Native gateway fields configure checkout, currencies, methods and encrypted credentials', function () {
    list($a, $p, $w, $scope) = environment();
    $connection = new NativeSettingsConnection($a);
    $service = new GatewayConfiguration($a, $connection);
    $values = nativeSettings();
    $service->synchronize($values);
    same('', $a->settings->get('configuration_error'));
    same(true, $a->settings->get('enabled'));
    same(array_keys(PaymentOptions::currencies()), $a->settings->get('currencies'));
    same(array_keys(PaymentOptions::methods()), $a->settings->methods());
    same($values['sandboxApiKey'], $a->settings->secret('key_' . $scope['id']));
    same($values['sandboxSigningSecret'], $a->settings->secret('signing_' . $scope['id']));
    same(false, strpos(json_encode($a->store->listing('settings', array(), 500)), $values['sandboxApiKey']) !== false);
    $a->settings->set('last_worker', $a->store->clock->now());
    $at = beginPayment($a); $p->succeed($at);
    same('posted', (new \Bachs\Whmcs\Application\PaymentRecognition($a))->reconcile($at['id']));
    same(1, $w->posts);
    $service->synchronize(array_merge($values, array('invoiceid' => 500, 'clientdetails' => array('email' => 'ignored@example.invalid'))));
    same(1, $connection->connections);
});

test('Native checkbox changes restrict new payments without falling back to addon settings', function () {
    list($a) = environment();
    $values = nativeSettings();
    $values['accept_USD'] = '';
    $values['offer_USD_CARD'] = '';
    (new GatewayConfiguration($a, new NativeSettingsConnection($a)))->synchronize($values);
    same(false, in_array('USD', $a->settings->get('currencies'), true));
    same(false, in_array('USD_CARD', $a->settings->methods(), true));
    fails('currency_not_enabled', function () use ($a) { $a->settings->precision('USD'); });
});

foreach (array('missing_key', 'missing_secret', 'empty_methods', 'sandbox_confirmation', 'bad_number', 'native_conversion') as $case) {
    test('Incomplete native settings block collections: ' . $case, function () use ($case) {
        list($a, $p) = environment();
        $values = nativeSettings();
        if ($case === 'missing_key') { $values['sandboxApiKey'] = ''; }
        if ($case === 'missing_secret') { $values['sandboxSigningSecret'] = ''; }
        if ($case === 'empty_methods') { foreach (array_keys(PaymentOptions::methods()) as $code) { $values['offer_' . $code] = ''; } }
        if ($case === 'sandbox_confirmation') { $values['testInstallation'] = ''; }
        if ($case === 'bad_number') { $values['expiryMinutes'] = '0'; }
        if ($case === 'native_conversion') { $values['convertto'] = 2; }
        (new GatewayConfiguration($a, new NativeSettingsConnection($a)))->synchronize($values);
        same(false, $a->settings->get('enabled'));
        truth($a->settings->get('configuration_error'));
        fails('gateway_disabled', function () use ($a) { beginPayment($a); });
        same(0, $p->writes('/v1/checkout-sessions'));
    });
}

test('A failed native credential check is bounded and can recover on a later check', function () {
    list($a, $p, $w, $scope, $clock) = environment();
    $connection = new NativeSettingsConnection($a); $connection->fail = true;
    $service = new GatewayConfiguration($a, $connection); $values = nativeSettings();
    $service->synchronize($values); $service->synchronize($values);
    same(1, $connection->connections);
    same(false, $a->settings->get('enabled'));
    $clock->time += 301; $connection->fail = false;
    $service->synchronize($values);
    same(2, $connection->connections);
    same(true, $a->settings->get('enabled'));
});

test('Native gateway deactivation blocks new payments without a provider mutation', function () {
    list($a, $p) = environment();
    $connection = new NativeSettingsConnection($a);
    (new GatewayConfiguration($a, $connection))->synchronize(array());
    same(false, $a->settings->get('enabled'));
    same(true, $a->settings->get('draining'));
    same(0, $connection->connections);
    same(0, count($p->calls));
});

test('Native configured checkout waits for cron health while the worker can initialize normally', function () {
    list($a) = environment();
    (new GatewayConfiguration($a, new NativeSettingsConnection($a)))->synchronize(nativeSettings());
    fails('worker_not_healthy', function () use ($a) { beginPayment($a); });
    (new \Bachs\Whmcs\Application\Worker($a))->run();
    same('open', beginPayment($a)['state']);
});

test('Gateway runtime installs module tables without addon activation', function () {
    $pdo = new PDO('sqlite::memory:');
    \Bachs\Whmcs\Infrastructure\Storage\Schema::ensure($pdo);
    \Bachs\Whmcs\Infrastructure\Storage\Schema::ensure($pdo);
    $count = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE 'mod_bachs_%'")->fetchColumn();
    same(count(\Bachs\Whmcs\Infrastructure\Storage\Schema::$tables), (int)$count);
});

test('Addon activity uses native controls and never treats a gateway settings save as a module action', function () {
    list($a, $p) = environment();
    $oldGet = $_GET; $oldPost = $_POST; $oldServer = $_SERVER;
    try {
        $_GET = array('bachs_page' => 'overview');
        $_POST = array('action' => 'save', 'sandboxApiKey' => 'must-not-be-rendered');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $a->bridge->identity->validCsrf = false;
        $html = (new \Bachs\Whmcs\Http\AdminView($a, 'addonmodules.php?module=bachs_payments'))->render();
        truth(strpos($html, 'class="nav nav-tabs"') !== false);
        truth(strpos($html, 'class="btn btn-primary"') !== false);
        truth(strpos($html, 'name="bachs_action" value="verify"') !== false);
        foreach (array('must-not-be-rendered', 'configgateways.php?bachs_page=', 'bachs.css', 'bachs-wordmark', 'bachs-nav') as $unwanted) {
            same(false, strpos($html, $unwanted) !== false);
        }
        $_POST = array('bachs_action' => 'verify');
        $html = (new \Bachs\Whmcs\Http\AdminView($a, 'addonmodules.php?module=bachs_payments'))->render();
        truth(strpos($html, 'csrf_required') !== false);
        same(0, count($p->calls));
    } finally {
        $_GET = $oldGet; $_POST = $oldPost; $_SERVER = $oldServer;
    }
});

test('Removed addon settings and import actions are not available', function () {
    list($a, $p) = environment();
    foreach (array('connect', 'settings', 'signing', 'import_settings') as $action) {
        fails('unknown_admin_action', function () use ($a, $action) {
            (new \Bachs\Whmcs\Http\AdminActions($a))->run(array('action' => $action));
        });
    }
    same(0, count($p->calls));
});
