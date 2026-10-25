<?php

use Bachs\Whmcs\Application\Checkout;
use Bachs\Whmcs\Application\PaymentRecognition;
use Bachs\Whmcs\Application\SavedMethods;
use Bachs\Whmcs\Application\Worker;
use Bachs\Whmcs\Infrastructure\Bachs\ApiFailure;

function captureFixture()
{
    list($a, $p, $w, $scope, $clock) = environment();
    $a->settings->set('saved_methods', true);
    $a->settings->set('automatic_payments', true);
    $w->invoices[42]['gateway'] = 'bachs_card';
    $consent = $a->store->insert('consents', $scope['id'], 'card-consent', array(), 'active', array('client_id' => 1));
    $method = $a->store->insert('payment_methods', $scope['id'], 'test-method', array('consent_id' => $consent['id'],
        'token' => $a->settings->secrets->seal('pm_synthetic'), 'currency' => 'USD', 'customer_id' => 'cust_synthetic'), 'active', array('client_id' => 1));
    return array($a, $p, $w, $scope, $clock, $method);
}

test('Rejected setup and capture requests become failed and permit a fresh attempt', function () {
    foreach (array('setup', 'capture') as $purpose) {
        list($a, $p, $w, $scope, $clock, $method) = captureFixture();
        $path = $purpose === 'setup' ? '/v1/checkout-sessions' : '/v1/charges';
        $p->responses['POST ' . $path] = new ApiFailure('CARD_DECLINED', 422);
        fails('CARD_DECLINED', function () use ($a, $purpose, $method) {
            $cards = new SavedMethods($a);
            if ($purpose === 'setup') { $cards->setup(1, 'user:21'); } else { $cards->capture(42, $method['id']); }
        });
        $attempt = $a->store->listing('attempts')[0];
        same('failed', $attempt['state']);
        unset($p->responses['POST ' . $path]);
        $cards = new SavedMethods($a);
        if ($purpose === 'setup') { $cards->setup(1, 'user:21'); } else { $cards->capture(42, $method['id']); }
        same(2, $p->writes($path));
        same(2, $a->store->count('attempts'));
    }
});

test('An uncertain card charge stays pending and is never sent a second time', function () {
    list($a, $p, $w, $scope, $clock, $method) = captureFixture();
    $p->failNextWrite = true;
    fails('transport_failure', function () use ($a, $method) { (new SavedMethods($a))->capture(42, $method['id']); });
    same('pending', (new SavedMethods($a))->capture(42, $method['id'])['status']);
    (new Worker($a))->run(10, 5);
    same(1, $p->writes('/v1/charges'));
    same(0, $w->posts);
});

test('Explicit idempotency backoff resumes the same checkout body and key', function () {
    list($a, $p, $w, $scope, $clock) = environment();
    $p->responses['POST /v1/checkout-sessions'] = new ApiFailure('IDEMPOTENCY_IN_PROGRESS', 409, '', 30);
    fails('IDEMPOTENCY_IN_PROGRESS', function () use ($a) { beginPayment($a); });
    (new Worker($a))->run(10, 5);
    same(1, $p->writes('/v1/checkout-sessions'));
    $clock->time += 120;
    unset($p->responses['POST /v1/checkout-sessions']);
    (new Worker($a))->run(10, 5);
    $calls = array_values(array_filter($p->calls, function ($c) { return $c[0] === 'POST' && $c[1] === '/v1/checkout-sessions'; }));
    same(2, count($calls));
    same($calls[0][2], $calls[1][2]);
    same($calls[0][3], $calls[1][3]);
    same(1, $a->store->count('attempts'));
    same('open', $a->store->listing('attempts')[0]['state']);
});

test('A changed invoice prevents idempotency retry from sending an old checkout', function () {
    list($a, $p, $w, $scope, $clock) = environment();
    $p->responses['POST /v1/checkout-sessions'] = new ApiFailure('IDEMPOTENCY_IN_PROGRESS', 409, '', 30);
    fails('IDEMPOTENCY_IN_PROGRESS', function () use ($a) { beginPayment($a); });
    $w->invoices[42]['status'] = 'Cancelled';
    $clock->time += 120;
    unset($p->responses['POST /v1/checkout-sessions']);
    (new Worker($a))->run(10, 5);
    same(1, $p->writes('/v1/checkout-sessions'));
    same(0, $w->posts);
});

test('Revoking a card blocks a deferred idempotency retry', function () {
    list($a, $p, $w, $scope, $clock, $method) = captureFixture();
    $p->responses['POST /v1/charges'] = new ApiFailure('IDEMPOTENCY_IN_PROGRESS', 409, '', 30);
    fails('IDEMPOTENCY_IN_PROGRESS', function () use ($a, $method) { (new SavedMethods($a))->capture(42, $method['id']); });
    (new SavedMethods($a))->revoke($method['id'], 1);
    unset($p->responses['POST /v1/charges']);
    $clock->time += 120;
    (new Worker($a))->run(10, 5);
    same(1, $p->writes('/v1/charges'));
    same(0, $w->posts);
});

test('Worker recovers a crash between a definitive rejection and attempt update', function () {
    list($a, $p) = environment();
    $p->responses['POST /v1/checkout-sessions'] = new ApiFailure('INVALID_REQUEST', 422);
    fails('INVALID_REQUEST', function () use ($a) { beginPayment($a); });
    $attempt = $a->store->listing('attempts')[0];
    $a->store->update('attempts', $attempt['id'], array(), 'creating');
    (new Worker($a))->run(10, 5);
    same('failed', $a->store->get('attempts', $attempt['id'])['state']);
    same(1, $p->writes('/v1/checkout-sessions'));
});

test('Authorized job replay resets its retry budget while regular scheduling preserves exhaustion', function () {
    list($a, $p, $w, $scope) = environment();
    $job = $a->queue->enqueue('event', $scope['id'], 'test');
    $a->store->update('jobs', $job['id'], array('tries' => 12, 'error_code' => 'old_error'), 'dead');
    $a->job('event', $scope['id'], 'test');
    same(12, $a->store->get('jobs', $job['id'])['data']['tries']);
    $a->queue->wake($job, true);
    $replay = $a->queue->claim();
    same(0, $replay['data']['tries']);
    same(null, $replay['data']['error_code']);
    $a->queue->finish($replay, 30, 'temporary_error');
    same('retry', $a->store->get('jobs', $job['id'])['state']);
    same(1, $a->store->get('jobs', $job['id'])['data']['tries']);
});

test('WHMCS Payment Pending status does not change an otherwise identical invoice snapshot', function () {
    list($a, $p, $w) = environment();
    $at = beginPayment($a);
    $p->succeed($at);
    $w->invoices[42]['status'] = 'Payment Pending';
    same('posted', (new PaymentRecognition($a))->reconcile($at['id']));
    same(1, $w->posts);
});

test('Cancelled invoices still hold verified money for review', function () {
    list($a, $p, $w) = environment();
    $at = beginPayment($a);
    $p->succeed($at);
    $w->invoices[42]['status'] = 'Cancelled';
    same('review', (new PaymentRecognition($a))->reconcile($at['id']));
    same(0, $w->posts);
});

foreach (array('currency', 'checkout', 'expiry') as $case) {
    test('Card enrollment rejects unmatched consent or invalid expiry: ' . $case, function () use ($case) {
        list($a, $p, $w, $scope) = environment();
        $a->settings->set('saved_methods', true);
        $at = (new Checkout($a))->start(42, 1, true, 'user:21');
        $at = $a->store->get('attempts', $at['attempt_id']);
        $p->succeed($at);
        $data = array('payment_method_id' => 'pm_synthetic', 'customer' => array('customer_id' => 'cust_synthetic'),
            'currency' => $case === 'currency' ? 'NGN' : 'USD', 'card_last4' => '4242',
            'card_exp_month' => $case === 'expiry' ? 13 : 12, 'card_exp_year' => 2034,
            'checkout_id' => $case === 'checkout' ? 'unrelated_checkout' : $at['data']['checkout_id']);
        list($raw, $headers) = signedEvent($a, $scope, 'payment_method.saved', $data);
        $webhooks = new \Bachs\Whmcs\Application\Webhooks($a);
        $id = $webhooks->ingest('sandbox', $raw, $headers);
        fails($case === 'expiry' ? 'saved_method_contract_mismatch' : 'saved_method_consent_missing', function () use ($webhooks, $id) { $webhooks->process($id); });
        same(0, count($w->enrolled));
        same(0, $a->store->count('payment_methods'));
    });
}

test('Malformed client form arrays fail before a provider request', function () {
    list($a, $p) = environment();
    $old = $_POST;
    try {
        $_POST = array('invoice_id' => array('42'));
        fails('invalid_input', function () use ($a) { (new \Bachs\Whmcs\Http\ClientController($a))->checkout(); });
        same(0, count($p->calls));
    } finally { $_POST = $old; }
});

test('Corrupt confirmation payloads stay unused and cannot submit an action', function () {
    list($a, $p, $w, $scope) = environment();
    $confirmations = new \Bachs\Whmcs\Application\Confirmations($a);
    $preview = $confirmations->preview($scope, 'admin:10', 'refund', array('amount' => '10.00'));
    $row = $a->store->find('settings', $scope['id'], 'confirmation:' . hash('sha256', $preview['confirmation']));
    $a->store->update('settings', $row['id'], array('payload' => $a->settings->secrets->seal('null')));
    fails('confirmation_invalid_or_expired', function () use ($confirmations, $scope, $preview) {
        $confirmations->consume($scope, 'admin:10', 'refund', $preview['confirmation']);
    });
    same('preview', $a->store->get('settings', $row['id'])['state']);
    same(0, count($p->calls));
});

test('A malformed sweep item does not hide subsequent provider payments', function () {
    list($a, $p, $w, $scope) = environment();
    $p->responses['GET /v1/payments?limit=50&offset=0'] = array('items' => array(
        array('id' => array('invalid')), array('id' => 'later_payment', 'reference' => 'unmatched_reference')),
        'pagination' => array('has_more' => false));
    (new Worker($a))->run(10, 5);
    $items = $a->store->listing('reconciliation_items');
    same(2, count($items));
    $types = array_column(array_column($items, 'data'), 'type');
    truth(in_array('sweep_item_invalid', $types, true));
    truth(in_array('unmatched_provider_payment', $types, true));
});

test('Sweeps reach older refunded payments even when the previous newest ID is unchanged', function () {
    list($a, $p, $w, $scope, $clock) = environment();
    $at = beginPayment($a);
    $pid = $p->succeed($at);
    (new PaymentRecognition($a))->reconcile($at['id']);
    $a->settings->set('payment_highwater_' . $scope['id'], 'newest_payment');
    $p->responses['GET /v1/payments?limit=50&offset=0'] = array('items' => array(array('id' => 'newest_payment')), 'pagination' => array('has_more' => true));
    (new Worker($a))->run(10, 5);
    same(1, $a->settings->get('payment_cursor_' . $scope['id']));
    $p->responses['GET /v1/payments?limit=50&offset=1'] = array('items' => array(array('id' => $pid, 'status' => 'refunded')), 'pagination' => array('has_more' => false));
    $p->refunds['ref_older'] = array('refund_id' => 'ref_older', 'charge_id' => $pid, 'reference' => 'external_older',
        'status' => 'success', 'requested_amount' => '100.00', 'refunded_amount' => '100.00');
    $clock->time += 301;
    (new Worker($a))->run(10, 5);
    same(0, $a->settings->get('payment_cursor_' . $scope['id']));
    same(1, $a->store->count('refunds'));
    same('ref_older', $a->store->listing('refunds')[0]['data']['refund_id']);
    same('accounting_pending', $a->store->listing('refunds')[0]['state']);
});
