<?php

test('Signed malformed JSON is durably quarantined without financial handling', function () {
    list($a,$p,$w,$scope) = environment();
    $raw = '{malformed';
    $t = $a->store->clock->now();
    $h = array('x-bachs-signature-v2' => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $raw, 'synthetic_signing_secret_for_tests'));
    $id = (new \Bachs\Whmcs\Application\Webhooks($a))->ingest('sandbox', $raw, $h);
    same('quarantined', $a->store->get('webhook_events', $id)['state']);
    same(0, $w->posts);
});
test('A terminal job remains exhausted until authorized replay', function () {
    list($a,$p,$w,$scope) = environment();
    $job = $a->queue->enqueue('event', $scope['id'], 'test');
    $a->store->update('jobs', $job['id'], array(), 'dead');
    $a->job('event', $scope['id'], 'test');
    same('dead', $a->store->get('jobs', $job['id'])['state']);
    $a->queue->wake($job, true);
    same('ready', $a->store->get('jobs', $job['id'])['state']);
});
test('USD setup collects no money and cannot pay an invoice', function () {
    list($a,$p,$w,$scope) = environment();
    $a->settings->set('saved_methods', true);
    $p->app = null;
    $url = (new \Bachs\Whmcs\Application\SavedMethods($a))->setup(1, 'user:21');
    truth(strpos($url, 'https://checkout.bachs.io/') === 0);
    $at = $a->store->listing('attempts')[0];
    $calls = array_values(array_filter($p->calls, function ($c) {
        return $c[0] === 'POST' && $c[1] === '/v1/checkout-sessions';
    }));
    same(false, isset($calls[0][2]['pricing']));
    same(array('USD_CARD'), $calls[0][2]['payment_method_types']);
    $p->checkouts[$at['data']['checkout_id']]['status'] = 'completed';
    same('terminal', (new \Bachs\Whmcs\Application\PaymentRecognition($a))->reconcile($at['id']));
    same(0, $w->posts);
    same('setup_completed', $a->store->get('attempts', $at['id'])['state']);
});
test('External partial refund never inherits the full invoice amount', function () {
    list($a,$p,$w,$scope) = environment();
    $at = beginPayment($a);
    $pid = $p->succeed($at);
    (new \Bachs\Whmcs\Application\PaymentRecognition($a))->reconcile($at['id']);
    $payment = $a->store->listing('payments')[0];
    $r = (new \Bachs\Whmcs\Application\Refunds($a))->import($scope, $payment, array('refund_id' => 'external_partial','charge_id' => $pid,'reference' => 'external','status' => 'success','requested_amount' => '20.00','refunded_amount' => '20.00'));
    same(null, $r['data']['amount']);
    same('accounting_pending', $r['state']);
    same(1, count($w->transactions));
});
test('Lost checkout response is recovered from the persisted successful operation', function () {
    list($a,$p,$w,$scope) = environment();
    $at = beginPayment($a);
    $a->store->update('attempts', $at['id'], array('checkout_id' => null,'checkout_url' => null), 'creating', array('provider_hash' => ''));
    (new \Bachs\Whmcs\Application\Worker($a))->run(10, 5);
    $recovered = $a->store->get('attempts', $at['id']);
    same($at['data']['checkout_id'], $recovered['data']['checkout_id']);
    same(1, $p->writes('/v1/checkout-sessions'));
});
test('Unknown checkout write is recovered by signed reference plus authenticated resource', function () {
    list($a,$p,$w,$scope) = environment();
    $at = beginPayment($a);
    $pid = $p->succeed($at);
    $a->store->update('attempts', $at['id'], array('checkout_id' => null,'checkout_url' => null), 'outcome_unknown', array('provider_hash' => ''));
    list($raw,$h) = signedEvent($a, $scope, 'collection.succeeded', array('reference' => $at['data']['reference'],'checkout_id' => $at['data']['checkout_id'],'charge_id' => $pid));
    $event = (new \Bachs\Whmcs\Application\Webhooks($a))->ingest('sandbox', $raw, $h);
    (new \Bachs\Whmcs\Application\Webhooks($a))->process($event);
    same(1, $w->posts);
    same(1, $p->writes('/v1/checkout-sessions'));
});
test('Webhook charge naming never replaces the canonical REST payment identity', function () {
    list($a,$p,$w) = environment();
    $at = beginPayment($a);
    $p->succeed($at);
    same('posted', (new \Bachs\Whmcs\Application\PaymentRecognition($a))->reconcile($at['id'], 'untrusted_charge_hint'));
    same(1, $w->posts);
});
test('Unsafe provider text and full card tokens are excluded from admin inspection', function () {
    $d = \Bachs\Whmcs\Http\AdminView::display(array('payment_method_id' => 'pm_sensitive','checkout_url' => 'https://checkout.bachs.io/c/sensitive','nested' => array('message' => 'sk_live_sensitive'),'status' => 'processing'));
    same('[protected]', $d['payment_method_id']);
    same('[protected]', $d['checkout_url']);
    same('[protected]', $d['nested']['message']);
    same('processing', $d['status']);
});
