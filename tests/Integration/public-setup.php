<?php

use Bachs\Whmcs\Application\Connection;
use Bachs\Whmcs\Application\PaymentRecognition;
use Bachs\Whmcs\Application\SavedMethods;

test('A normal addon setup can collect without a private acceptance file', function () {
    list($app, $provider, $native) = environment();
    $app->settings->set('enabled', false);
    $app->settings->set('last_worker', $app->store->clock->now());
    (new Connection($app))->save(array('environment' => 'sandbox', 'enabled' => true, 'test_installation' => true), 'admin:10');
    $attempt = beginPayment($app);
    $provider->succeed($attempt);
    same('posted', (new PaymentRecognition($app))->reconcile($attempt['id']));
    same(1, $native->posts);
});

test('Sandbox activation cannot skip explicit installation confirmation', function () {
    list($app, $provider) = environment();
    $app->settings->set('enabled', false);
    $app->settings->set('last_worker', $app->store->clock->now());
    fails('sandbox_installation_confirmation_required', function () use ($app) {
        (new Connection($app))->save(array('environment' => 'sandbox', 'enabled' => true), 'admin:10');
    });
    same(false, $app->settings->get('enabled'));
    same(0, count($provider->calls));
});

test('Changing sandbox confirmation before posting retains the receipt for review', function () {
    list($app, $provider, $native) = environment();
    $attempt = beginPayment($app);
    $provider->succeed($attempt);
    $app->settings->set('test_installation', false);
    same('review', (new PaymentRecognition($app))->reconcile($attempt['id']));
    same(0, $native->posts);
    same(1, $app->store->count('payments'));
});

test('Automatic card charges remain disabled until the merchant enables them', function () {
    list($app, $provider, $native) = environment();
    $app->settings->set('saved_methods', true);
    $native->invoices[42]['gateway'] = 'bachs_card';
    fails('automatic_payments_disabled', function () use ($app) {
        (new SavedMethods($app))->capture(42, 'unknown_method');
    });
    same(0, $provider->writes('/v1/charges'));
});

test('The native card version boundary cannot be bypassed by a saved setting', function () {
    list($app, $provider, $native) = environment();
    $app->settings->set('saved_methods', true);
    $app->settings->set('automatic_payments', true);
    $native->version = '7.8.3';
    $native->invoices[42]['gateway'] = 'bachs_card';
    fails('native_card_version_not_supported', function () use ($app) {
        (new SavedMethods($app))->capture(42, 'unknown_method');
    });
    same(0, $provider->writes('/v1/charges'));
});

test('A valid merchant connection does not stop accepting payments after seven days', function () {
    list($app, $provider, $native, $scope, $clock) = environment();
    $clock->time += 8 * 86400;
    $attempt = beginPayment($app);
    same('100.00', $attempt['data']['amount']);
    same(1, $provider->writes('/v1/checkout-sessions'));
});

test('Unknown converted currency precision holds the actual receipt without posting', function () {
    list($app, $provider, $native) = environment();
    $app->settings->set('adaptive_allocation', true);
    $attempt = beginPayment($app);
    $paymentId = $provider->succeed($attempt);
    $provider->payments[$paymentId]['currency'] = 'UNCONFIGURED_ASSET';
    same('review', (new PaymentRecognition($app))->reconcile($attempt['id']));
    same(0, $native->posts);
});

test('Converted precision settings reject malformed input before saving payment policy', function () {
    list($app) = environment();
    fails('invalid_collection_precision', function () use ($app) {
        (new Connection($app))->save(array('collection_precision' => 'USDT_TRC20=99'), 'admin:10');
    });
    same(true, $app->settings->get('enabled'));
    (new Connection($app))->save(array('collection_precision' => "USDT_TRC20=6\nRWF=0"), 'admin:10');
    same(6, $app->settings->collectionPrecision('USDT_TRC20'));
    same(0, $app->settings->collectionPrecision('RWF'));
});

test('Removed API webhook registration cannot be triggered by an old form', function () {
    list($app, $provider) = environment();
    foreach (array('webhook_preview', 'webhook_confirm', 'roles') as $action) {
        fails('unknown_admin_action', function () use ($app, $action) {
            (new \Bachs\Whmcs\Http\AdminActions($app))->run(array('action' => $action));
        });
    }
    same(0, count($provider->calls));
});

test('The native card entry point uses normal addon settings and safe hosted management', function () {
    if (!defined('WHMCS')) {
        define('WHMCS', true);
    }
    require_once dirname(dirname(__DIR__)) . '/modules/gateways/bachs_card.php';
    list($app) = environment();
    $runtime = new ReflectionProperty(\Bachs\Whmcs\Infrastructure\Whmcs\Runtime::class, 'app');
    $runtime->setAccessible(true);
    $previous = $runtime->getValue();
    $runtime->setValue(null, $app);
    try {
        truth(strpos(bachs_card_remoteinput(array()), 'unavailable') !== false);
        $app->settings->set('saved_methods', true);
        $app->settings->set('automatic_payments', true);
        $form = bachs_card_remoteinput(array());
        truth(strpos($form, 'Continue to Bachs') !== false);
        truth(strpos($form, 'name="step" value="review"') !== false);
        same(false, strpos($form, 'api_key') !== false);
    } finally {
        $runtime->setValue(null, $previous);
    }
});
