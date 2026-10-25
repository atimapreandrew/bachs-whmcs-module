<?php

use Bachs\Whmcs\Application\Checkout;
use Bachs\Whmcs\Application\Connection;
use Bachs\Whmcs\Application\PaymentOptions;
use Bachs\Whmcs\Application\PaymentRecognition;
use Bachs\Whmcs\Application\SavedMethods;
use Bachs\Whmcs\Http\CardInput;
use Bachs\Whmcs\Http\ClientController;

foreach (array_keys(PaymentOptions::currencies()) as $currency) {
    test('Invoice collection and native allocation in ' . $currency, function () use ($currency) {
        list($a, $p, $w) = environment();
        $w->invoices[42]['currency'] = $currency;
        $at = beginPayment($a);
        $id = $p->succeed($at);
        $p->payments[$id]['payment_method'] = in_array($currency, array('USD', 'NGN'), true) ? 'CARD' : 'MOMO_' . $currency;
        same('posted', (new PaymentRecognition($a))->reconcile($at['id']));
        same('100.00', array_values($w->transactions)[0]['amountin']);
    });
}

foreach (PaymentOptions::crypto() as $currency) {
    test('Exact crypto collection allocates the original USD invoice: ' . $currency, function () use ($currency) {
        list($a, $p, $w) = environment();
        $a->settings->set('adaptive_allocation', true);
        $at = beginPayment($a);
        $id = $p->succeed($at);
        $p->payments[$id]['payment_method'] = 'CRYPTO';
        $p->payments[$id]['currency'] = $currency;
        $p->payments[$id]['amount'] = '0.123456';
        $p->payments[$id]['amount_paid'] = '0.123456';
        $p->payments[$id]['amount_remaining'] = '0';
        same('posted', (new PaymentRecognition($a))->reconcile($at['id']));
        same('100.00', array_values($w->transactions)[0]['amountin']);
    });
}

foreach (array_keys(PaymentOptions::methods()) as $method) {
    test('Admin selection restricts checkout to ' . $method, function () use ($method) {
        list($a, $p) = environment();
        (new Connection($a))->save(array('methods_mode' => 'selected', 'methods' => array($method), 'test_installation' => true, 'adaptive_allocation' => true), 'admin:10');
        $a->settings->set('enabled', true);
        $at = beginPayment($a);
        $op = $a->store->get('operations', $at['data']['operation_id']);
        $body = json_decode($a->settings->secrets->open($op['data']['request']), true);
        same(array($method), $body['payment_method_types']);
        same(false, isset($body['adaptive_pricing']));
    });
}

test('Empty method or currency selection cannot silently enable everything', function () {
    list($a) = environment();
    fails('payment_methods_required', function () use ($a) {
        (new Connection($a))->save(array('methods_mode' => 'selected'), 'admin:10');
    });
    fails('currency_precision_gate', function () use ($a) {
        (new Connection($a))->save(array('currencies_present' => '1'), 'admin:10');
    });
    fails('method_restriction_not_supported', function () use ($a) {
        (new Connection($a))->save(array('methods' => array('ARBITRARY_RAIL')), 'admin:10');
    });
});

test('Crypto receipts retain all 18 decimal places and reject a one-unit short payment', function () {
    foreach (array('0.123456789123456789' => 'posted', '0.123456789123456788' => 'review') as $paid => $expected) {
        list($a, $p, $w) = environment();
        $a->settings->set('adaptive_allocation', true);
        $at = beginPayment($a);
        $id = $p->succeed($at);
        $p->payments[$id]['payment_method'] = 'CRYPTO';
        $p->payments[$id]['currency'] = 'ETH_ETH';
        $p->payments[$id]['amount'] = '0.123456789123456789';
        $p->payments[$id]['amount_paid'] = $paid;
        $p->payments[$id]['amount_remaining'] = '0';
        same($expected, (new PaymentRecognition($a))->reconcile($at['id']));
        same($expected === 'posted' ? 1 : 0, $w->posts);
    }
});

test('Disabled invoice currency blocks initiation while an existing receipt can finish', function () {
    list($a, $p, $w) = environment();
    $at = beginPayment($a);
    $p->succeed($at);
    $a->settings->set('currencies', array('NGN'));
    fails('currency_not_enabled', function () use ($a) { beginPayment($a); });
    same('posted', (new PaymentRecognition($a))->reconcile($at['id']));
    same(1, $w->posts);
});

test('A receipt outside the frozen checkout method selection needs review', function () {
    list($a, $p, $w) = environment();
    $a->settings->set('methods', array('USD_CARD'));
    $at = beginPayment($a);
    $id = $p->succeed($at);
    $p->payments[$id]['payment_method'] = 'CRYPTO';
    same('review', (new PaymentRecognition($a))->reconcile($at['id']));
    same(0, $w->posts);
});

test('Card saving restricts collection to the enabled invoice card corridor', function () {
    list($a, $p) = environment();
    $a->settings->set('saved_methods', true);
    $r = (new Checkout($a))->start(42, 1, true, 'user:21');
    $at = $a->store->get('attempts', $r['attempt_id']);
    $op = $a->store->get('operations', $at['data']['operation_id']);
    $body = json_decode($a->settings->secrets->open($op['data']['request']), true);
    same(array('USD_CARD'), $body['payment_method_types']);
    same('USD', $body['billing_currency']);
});

test('Disabling the USD card corridor blocks setup before a provider write', function () {
    list($a, $p) = environment();
    $a->settings->set('saved_methods', true);
    $a->settings->set('methods', array('NGN_CARD'));
    fails('card_method_not_enabled', function () use ($a) { (new SavedMethods($a))->setup(1, 'user:21'); });
    same(0, count($p->calls));
});

function cardForm($app, $params = array())
{
    $app->settings->set('saved_methods', true);
    $app->settings->set('automatic_payments', true);
    $form = CardInput::render($app, $params);
    preg_match('/name="confirmation" value="([a-f0-9]+)"/', $form, $match);
    return array($form, $match[1]);
}

test('Native card input preserves invoice context without a provider write during rendering', function () {
    list($a, $p, $w) = environment();
    $w->invoices[42]['gateway'] = 'bachs_card';
    list($form, $intent) = cardForm($a, array('invoiceid' => 42, 'amount' => '100.00'));
    truth(strpos($form, 'name="step" value="review"') !== false);
    same(false, strpos($form, 'target="_top"') !== false);
    same(0, count($p->calls));
    $_POST = array('confirmation' => $intent, 'step' => 'review');
    ob_start(); (new ClientController($a))->card(); $review = ob_get_clean();
    truth(strpos($review, 'Pay invoice #42') !== false);
    truth(strpos($review, 'name="consent"') !== false);
    truth(strpos($review, 'target="_top"') !== false);
    same(0, count($p->calls));
    $_POST = array('confirmation' => $intent, 'consent' => '1');
    (new ClientController($a))->card();
    $attempt = $a->store->listing('attempts')[0];
    same(42, (int)$attempt['invoice_id']);
    same('bachs_card', $attempt['data']['gateway']);
    same(true, $attempt['data']['save']);
    same(1, $p->writes('/v1/checkout-sessions'));
    fails('confirmation_already_used', function () use ($a) { (new ClientController($a))->card(); });
});

test('Card input rejects changed invoices, missing consent and cross-client access', function () {
    list($a, $p, $w) = environment();
    $w->invoices[42]['gateway'] = 'bachs_card';
    list($form, $intent) = cardForm($a, array('invoiceid' => 42, 'amount' => '100.00'));
    $_POST = array('confirmation' => $intent);
    fails('automatic_payment_consent_required', function () use ($a) { (new ClientController($a))->card(); });
    $a->bridge->identity->validCsrf = false;
    $_POST['consent'] = '1';
    fails('csrf_required', function () use ($a) { (new ClientController($a))->card(); });
    $a->bridge->identity->validCsrf = true;
    $w->invoices[42]['balance'] = '99.00';
    fails('invoice_changed_during_payment', function () use ($a) { (new ClientController($a))->card(); });
    $w->invoices[42]['client_id'] = 2;
    fails('invoice_access_denied', function () use ($a) { CardInput::render($a, array('invoiceid' => 42, 'amount' => '99.00')); });
    same(0, count($p->calls));
});

test('Native add-only setup and its return/status do not read invoice zero', function () {
    list($a, $p, $w) = environment();
    list($form, $intent) = cardForm($a);
    $_POST = array('confirmation' => $intent, 'consent' => '1');
    (new ClientController($a))->card();
    $at = $a->store->listing('attempts')[0];
    same('setup', $at['data']['purpose']);
    same(0, $w->posts);
    $_GET = array('attempt_id' => $at['id']);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['PATH_INFO'] = '/' . $at['id'] . '/' . $a->settings->secrets->open($at['data']['return_state']);
    ob_start(); (new ClientController($a))->status(); $status = json_decode(ob_get_clean(), true);
    same('open', $status['state']);
    ob_start(); (new ClientController($a))->returnPage(); $html = ob_get_clean();
    truth(strpos($html, 'Card setup status') !== false);
    truth(strpos($html, 'Return to payment settings') !== false);
    $a->bridge->identity->clientId = 2;
    fails('invoice_access_denied', function () use ($a) { (new ClientController($a))->status(); });
    $_GET = array(); $_SERVER['PATH_INFO'] = ''; $_SERVER['REQUEST_METHOD'] = 'POST';
});

test('Native gateway refunds cannot initiate money movement through a failed native return', function () {
    if (!defined('WHMCS')) { define('WHMCS', true); }
    require_once dirname(dirname(__DIR__)) . '/modules/gateways/bachs.php';
    require_once dirname(dirname(__DIR__)) . '/modules/gateways/bachs_card.php';
    same(false, function_exists('bachs_refund'));
    same(false, function_exists('bachs_card_refund'));
    same(true, function_exists('bachs_card_nolocalcc'));
    $update = bachs_card_remoteupdate(array('paymethodid' => 7, 'gatewayid' => 'local-method'));
    truth(strpos($update, 'add a new payment method') !== false);
    same(false, strpos($update, '<form') !== false);
});

test('Enabling native billing enrolls earlier consented cards through the worker without a charge', function () {
    list($a, $p, $w, $scope) = environment();
    $consent = $a->store->insert('consents', $scope['id'], 'synthetic-consent', array(), 'active', array('client_id' => 1));
    $method = $a->store->insert('payment_methods', $scope['id'], 'synthetic-earlier-card', array('consent_id' => $consent['id']), 'active', array('client_id' => 1));
    (new \Bachs\Whmcs\Application\Worker($a))->run();
    same(array(), $w->enrolled);
    $a->settings->set('saved_methods', true);
    $a->settings->set('automatic_payments', true);
    (new \Bachs\Whmcs\Application\Worker($a))->run();
    same(array($method['id']), $w->enrolled);
    same(0, $p->writes('/v1/charges'));
});

test('A local-currency invoice cannot be converted by changing the USD conversion setting', function () {
    list($a, $p, $w) = environment();
    $a->settings->set('adaptive_allocation', true);
    $w->invoices[42]['currency'] = 'GHS';
    $at = beginPayment($a);
    same('same_currency', $at['data']['allocation_policy']);
    same(array('MOMO_GHS'), $at['data']['allowed_methods']);
    $op = $a->store->get('operations', $at['data']['operation_id']);
    $body = json_decode($a->settings->secrets->open($op['data']['request']), true);
    same('GHS', $body['billing_currency']);
    same(false, isset($body['adaptive_pricing']));
    $id = $p->succeed($at);
    $p->payments[$id]['payment_method'] = 'CRYPTO';
    $p->payments[$id]['currency'] = 'ETH_ETH';
    same('review', (new PaymentRecognition($a))->reconcile($at['id']));
    same(0, $w->posts);
});
