<?php

use Bachs\Whmcs\Application\Checkout;
use Bachs\Whmcs\Application\PaymentRecognition;
use Bachs\Whmcs\Application\Webhooks;
use Bachs\Whmcs\Application\Worker;
use Bachs\Whmcs\Application\Confirmations;
use Bachs\Whmcs\Application\Refunds;
use Bachs\Whmcs\Application\SavedMethods;
use Bachs\Whmcs\Infrastructure\Bachs\ApiFailure;
use Bachs\Whmcs\Domain\Ids;

test('Schema activation is repeatable and leaves financial identities intact', function () {
    list($a) = environment();
    $a->store->insert('payments', 's', 'p', array('amount' => '1.00'), 'posted');
    \Bachs\Whmcs\Infrastructure\Storage\Schema::migrate($a->store->pdo);
    same(1, $a->store->count('payments'));
});
test('Checkout persists before sending, freezes invoice amount and reuses two-tab attempt', function () {
    list($a,$p) = environment();
    $attempt = beginPayment($a);
    $second = (new Checkout($a))->start(42, 1);
    same($attempt['id'], $second['attempt_id']);
    same(1, $p->writes('/v1/checkout-sessions'));
    same(1, $p->writes('/v1/customers'));
    truth(strpos($a->store->get('attempts', $attempt['id'])['data']['checkout_url'], 'https://') === false);
    same('100.00', $attempt['data']['amount']);
});
test('Current unpaid balance after credits is the checkout obligation', function () {
    list($a,$p,$w) = environment();
    $w->invoices[42]['credit'] = '20.00';
    $w->invoices[42]['balance'] = '30.00';
    $at = beginPayment($a);
    same('30.00', $at['data']['amount']);
});
test('Checkout denies cross-client invoice IDs and paid invoices', function () {
    list($a,$p,$w) = environment();
    fails('invoice_access_denied', function () use ($a) {
        (new Checkout($a))->start(42, 2);
    });
    $w->invoices[42]['status'] = 'Paid';
    fails('invoice_not_payable', function () use ($a) {
        beginPayment($a);
    });
    same(0, $p->writes('/v1/checkout-sessions'));
});
test('Sandbox collection requires explicit test-installation confirmation', function () {
    list($a,$p,$w) = environment();
    $a->settings->set('test_installation', false);
    fails('sandbox_installation_confirmation_required', function () use ($a) {
        beginPayment($a);
    });
});
test('Disabled payment settings stop new collections', function () {
    list($a,$p,$w) = environment();
    $a->settings->set('enabled', false);
    fails('gateway_disabled', function () use ($a) {
        beginPayment($a);
    });
    same(0, $p->writes('/v1/checkout-sessions'));
});
test('Persisted unknown checkout outcome blocks fresh charge creation', function () {
    list($a,$p) = environment();
    $a->customers->resolve($a->settings->activeScope(), $a->bridge->customer(1), $p);
    $p->failNextWrite = true;
    fails('transport_failure', function () use ($a) {
        beginPayment($a);
    });
    fails('existing_attempt_requires_reconciliation', function () use ($a) {
        beginPayment($a);
    });
    same(1, $p->writes('/v1/checkout-sessions'));
    same(1, $a->store->count('operations', 'outcome_unknown'));
});
test('Idempotency body changes and expired window never produce a replacement write', function () {
    list($a,$p,$w,$scope,$clock) = environment();
    $op = $a->operations->prepare($scope, 'test', 'POST', '/v1/customers', array('email' => 'one@example.invalid'));
    fails('operation_conflict', function () use ($a, $scope) {
        $a->operations->prepare($scope, 'test', 'POST', '/v1/customers', array('email' => 'two@example.invalid'));
    });
    $clock->time += 82801;
    fails('idempotency_window_expired', function () use ($a, $op, $scope, $p) {
        $a->operations->send($op, $scope, $p);
    });
    same(0, $p->writes('/v1/customers'));
});
test('Credential rotation blocks retry of a frozen operation', function () {
    list($a,$p,$w,$scope) = environment();
    $op = $a->operations->prepare($scope, 'test', 'POST', '/v1/customers', array('email' => 'a@example.invalid'));
    $scope['data']['generation'] = 'rotated';
    fails('credential_rotated_reconcile', function () use ($a, $p, $scope, $op) {
        $a->operations->send($op, $scope, $p);
    });
});
test('Successful verified receipt posts one native payment, even across different event IDs', function () {
    list($a,$p,$w,$scope) = environment();
    $at = beginPayment($a);
    $pid = $p->succeed($at);
    foreach (array('evt_one','evt_two') as $eventId) {
        list($raw,$headers) = signedEvent($a, $scope, 'collection.succeeded', array('checkout_id' => $at['data']['checkout_id'],'charge_id' => $pid,'reference' => $at['data']['reference']), $eventId);
        $event = (new Webhooks($a))->ingest('sandbox', $raw, $headers);
        (new Webhooks($a))->process($event);
    }
    same(1, $w->posts);
    same(1, $a->store->count('payments', 'posted'));
    same(1, $a->store->count('allocations', 'posted'));
    same('Paid', $w->invoices[42]['status']);
});
test('Duplicate signed delivery has one durable inbox and one job', function () {
    list($a,$p,$w,$scope) = environment();
    list($raw,$h) = signedEvent($a, $scope, 'unknown.synthetic', array('status' => 'unknown'));
    $one = (new Webhooks($a))->ingest('sandbox', $raw, $h);
    $two = (new Webhooks($a))->ingest('sandbox', $raw, $h);
    same($one, $two);
    same(1, $a->store->count('webhook_events'));
    same(1, $a->store->count('jobs'));
});
test('Payment posting recovers a crash after native transaction insertion', function () {
    list($a,$p,$w) = environment();
    $at = beginPayment($a);
    $p->succeed($at);
    $w->crashAfterPost = true;
    fails('simulated_crash_after_native_insert', function () use ($a, $at) {
        (new PaymentRecognition($a))->reconcile($at['id']);
    });
    same('posted', (new PaymentRecognition($a))->reconcile($at['id']));
    same(1, $w->posts);
    same(1, $a->store->count('payments', 'posted'));
});
foreach (array('balance','client_id','currency','gateway','items_hash','status') as $field) {
    test('Invoice mutation during checkout held for review: ' . $field, function () use ($field) {
        list($a,$p,$w) = environment();
        $at = beginPayment($a);
        $p->succeed($at);
        $values = array('balance' => '99.00','client_id' => 2,'currency' => 'NGN','gateway' => 'paypal','items_hash' => 'changed','status' => 'Cancelled');
        $w->invoices[42][$field] = $values[$field];
        same('review', (new PaymentRecognition($a))->reconcile($at['id']));
        same(0, $w->posts);
        same(1, $a->store->count('payments', 'review'));
    });
}
foreach (array('accepted','underpaid','overpaid','refunded','auto_refunded','future_state') as $state) {
    test('Nonstandard or unknown terminal state cannot pay full invoice: ' . $state, function () use ($state) {
        list($a,$p,$w) = environment();
        $at = beginPayment($a);
        $pid = $p->succeed($at);
        $p->payments[$pid]['status'] = $state;
        same('review', (new PaymentRecognition($a))->reconcile($at['id']));
        same(0, $w->posts);
    });
}
test('Setup or free checkout completion cannot settle an invoice', function () {
    list($a,$p,$w) = environment();
    $at = beginPayment($a);
    $p->checkouts[$at['data']['checkout_id']]['status'] = 'completed';
    same('review', (new PaymentRecognition($a))->reconcile($at['id']));
    same(0, $w->posts);
});
test('Permanent virtual-account receipts never enter invoice allocation', function () {
    list($a,$p,$w,$scope) = environment();
    list($raw,$h) = signedEvent($a, $scope, 'collection.succeeded', array('charge_id' => 'permanent_deposit'));
    $id = (new Webhooks($a))->ingest('sandbox', $raw, $h);
    (new Webhooks($a))->process($id);
    same(0, $w->posts);
    same(1, $a->store->count('reconciliation_items', 'review'));
});
test('Collection fees are separate from invoice credit', function () {
    list($a,$p,$w) = environment();
    $at = beginPayment($a);
    $pid = $p->succeed($at);
    $p->payments[$pid]['fee_paid_by'] = 'customer';
    $p->payments[$pid]['amount'] = '102.00';
    $p->payments[$pid]['amount_paid'] = '102.00';
    same('posted', (new PaymentRecognition($a))->reconcile($at['id']));
    same('100.00', array_values($w->transactions)[0]['amountin']);
    same('0.00', $a->store->listing('payments')[0]['data']['fee']);
});
test('Cross-currency provider fees are not sent as a native fee', function () {
    list($a,$p) = environment();
    $at = beginPayment($a);
    $pid = $p->succeed($at);
    $p->payments[$pid]['fees']['currency'] = 'NGN';
    same('posted', (new PaymentRecognition($a))->reconcile($at['id']));
    same('0.00', $a->store->listing('payments')[0]['data']['fee']);
});
test('Actual underpayment does not settle full obligation', function () {
    list($a,$p,$w) = environment();
    $at = beginPayment($a);
    $pid = $p->succeed($at);
    $p->payments[$pid]['amount_paid'] = '99.00';
    same('review', (new PaymentRecognition($a))->reconcile($at['id']));
    same(0, $w->posts);
});
test('Provider customer identity mismatch cannot reassign a payment', function () {
    list($a,$p,$w) = environment();
    $at = beginPayment($a);
    $p->succeed($at);
    $p->checkouts[$at['data']['checkout_id']]['customer']['id'] = 'other_customer';
    same('review', (new PaymentRecognition($a))->reconcile($at['id']));
    same(0, $w->posts);
});
test('Customer returned for another billing account cannot be mapped twice', function () {
    list($a,$p,$w,$scope) = environment();
    $a->customers->resolve($scope, $w->customer(1), $p);
    fails('customer_mapping_conflict', function () use ($a, $p, $w, $scope) {
        $a->customers->resolve($scope, $w->customer(2), $p);
    });
});
test('Authentic late checkout payment still reconciles after expiry', function () {
    list($a,$p,$w) = environment();
    $at = beginPayment($a);
    $p->checkouts[$at['data']['checkout_id']]['status'] = 'expired';
    same('terminal', (new PaymentRecognition($a))->reconcile($at['id']));
    $p->succeed($at);
    same('posted', (new PaymentRecognition($a))->reconcile($at['id']));
    same(1, $w->posts);
});
test('Active environment change cannot redirect old-attempt verification', function () {
    list($a,$p) = environment();
    $at = beginPayment($a);
    $p->succeed($at);
    $a->settings->set('environment', 'live');
    same('posted', (new PaymentRecognition($a))->reconcile($at['id']));
});
test('Webhook signatures and organization are both mandatory', function () {
    list($a,$p,$w,$scope) = environment();
    list($raw,$h) = signedEvent($a, $scope, 'collection.succeeded', array());
    fails('invalid_signature', function () use ($a, $raw) {
        (new Webhooks($a))->ingest('sandbox', $raw, array());
    });
    $raw = str_replace('acct_synthetic', 'acct_other', $raw);
    $t = $a->store->clock->now();
    $h = array('x-bachs-signature-v2' => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $raw, 'synthetic_signing_secret_for_tests'));
    fails('event_scope_mismatch', function () use ($a, $raw, $h) {
        (new Webhooks($a))->ingest('sandbox', $raw, $h);
    });
    same(0, $a->store->count('webhook_events'));
});
test('Webhook previous secret expires instead of remaining valid indefinitely', function () {
    list($a,$p,$w,$scope,$clock) = environment();
    $a->settings->saveSecret('previous_signing_' . $scope['id'], 'synthetic_signing_secret_for_tests');
    $a->settings->set('previous_expiry_' . $scope['id'], $clock->now() + 10);
    $a->settings->saveSecret('signing_' . $scope['id'], 'new_signing_secret');
    list($raw,$h) = signedEvent($a, $scope, 'synthetic.unknown', array());
    (new Webhooks($a))->ingest('sandbox', $raw, $h);
    $clock->time += 11;
    list($raw,$h) = signedEvent($a, $scope, 'synthetic.unknown', array(), 'evt_after');
    fails('invalid_signature', function () use ($a, $raw, $h) {
        (new Webhooks($a))->ingest('sandbox', $raw, $h);
    });
});
test('Queue leases prevent two workers claiming one job and recover killed workers', function () {
    list($a,$p,$w,$scope,$clock) = environment();
    $a->queue->enqueue('event', $scope['id'], 'e');
    $one = $a->queue->claim();
    same(null, $a->queue->claim());
    $clock->time += 181;
    $two = $a->queue->claim();
    same($one['id'], $two['id']);
    truth($one['lease_token'] !== $two['lease_token']);
    $a->queue->finish($one);
    same('running', $a->store->get('jobs', $one['id'])['state']);
    $a->queue->finish($two);
    same('done', $a->store->get('jobs', $one['id'])['state']);
});
test('Worker processes an acknowledged payment without browser return', function () {
    list($a,$p,$w,$scope) = environment();
    $at = beginPayment($a);
    $pid = $p->succeed($at);
    list($raw,$h) = signedEvent($a, $scope, 'collection.succeeded', array('checkout_id' => $at['data']['checkout_id'],'charge_id' => $pid));
    (new Webhooks($a))->ingest('sandbox', $raw, $h);
    (new Worker($a))->run(20, 10);
    same(1, $w->posts);
    same(1, $a->store->count('payments', 'posted'));
});
test('Admin handler checks per-action authority and native CSRF before mutating', function () {
    list($a) = environment();
    $a->bridge->identity->denied = array('request_refunds');
    fails('action_permission_denied', function () use ($a) {
        (new \Bachs\Whmcs\Http\AdminActions($a))->run(array('action' => 'refund_preview'));
    });
    $a->bridge->identity->validCsrf = false;
    fails('csrf_required', function () use ($a) {
        (new \Bachs\Whmcs\Http\AdminActions($a))->run(array('action' => 'settings'));
    });
});
test('Confirmations bind actor, scope, action and expiry; consumption is single use', function () {
    list($a,$p,$w,$scope,$clock) = environment();
    $s = new Confirmations($a);
    $r = $s->preview($scope, 'admin:1', 'refund', array('amount' => '1.00'));
    fails('confirmation_invalid_or_expired', function () use ($s, $scope, $r) {
        $s->consume($scope, 'admin:2', 'refund', $r['confirmation']);
    });
    same('1.00', $s->consume($scope, 'admin:1', 'refund', $r['confirmation'])['body']['amount']);
    fails('confirmation_already_used', function () use ($s, $scope, $r) {
        $s->consume($scope, 'admin:1', 'refund', $r['confirmation']);
    });
    $r = $s->preview($scope, 'admin:1', 'refund', array());
    $clock->time += 301;
    fails('confirmation_invalid_or_expired', function () use ($s, $scope, $r) {
        $s->consume($scope, 'admin:1', 'refund', $r['confirmation']);
    });
});
test('Asynchronous refund acceptance never inserts outgoing native accounting', function () {
    list($a,$p,$w) = environment();
    $at = beginPayment($a);
    $p->succeed($at);
    (new PaymentRecognition($a))->reconcile($at['id']);
    $payment = $a->store->listing('payments')[0];
    $r = (new Refunds($a))->request($payment['id'], '100.00', 'Synthetic refund test', 'admin:10');
    same('processing', $r['state']);
    $again = (new Refunds($a))->request($payment['id'], '100.00', 'Synthetic refund test', 'admin:10');
    same($r['id'], $again['id']);
    same(1, $p->writes('/v1/refunds'));
    $p->refunds[$r['data']['refund_id']]['status'] = 'success';
    $p->refunds[$r['data']['refund_id']]['refunded_amount'] = '100.00';
    (new Refunds($a))->reconcile($r['id']);
    same('accounting_pending', $a->store->get('refunds', $r['id'])['state']);
    same(1, count($w->transactions));
    truth($a->store->count('reconciliation_items', 'review') >= 1);
});
test('Partial refund requires proven settlement mapping and both idempotency mechanisms', function () {
    list($a,$p,$w) = environment();
    $at = beginPayment($a);
    $p->succeed($at);
    (new PaymentRecognition($a))->reconcile($at['id']);
    $payment = $a->store->listing('payments')[0];
    fails('partial_refund_currency_unproven', function () use ($a, $payment) {
        (new Refunds($a))->request($payment['id'], '50.00', 'Partial refund test', 'admin:10');
    });
    $a->store->update('payments', $payment['id'], array('settlement_currency' => 'USD'));
    $r = (new Refunds($a))->request($payment['id'], '50.00', 'Partial refund test', 'admin:10');
    $calls = array_values(array_filter($p->calls, function ($call) {
        return $call[0] === 'POST' && $call[1] === '/v1/refunds';
    }));
    same($calls[0][3], $calls[0][2]['idempotency_key']);
    same('50.00', $calls[0][2]['amount']);
    fails('existing_refund_amount_conflict', function () use ($a, $payment) {
        (new Refunds($a))->request($payment['id'], '25.00', 'Another refund test', 'admin:10');
    });
    same(1, $p->writes('/v1/refunds'));
});
test('Refund timeout is recovered by original charge and never resubmitted', function () {
    list($a,$p,$w) = environment();
    $at = beginPayment($a);
    $pid = $p->succeed($at);
    (new PaymentRecognition($a))->reconcile($at['id']);
    $payment = $a->store->listing('payments')[0];
    $p->failNextWrite = true;
    fails('transport_failure', function () use ($a, $payment) {
        (new Refunds($a))->request($payment['id'], '100.00', 'Refund timeout test', 'admin:10');
    });
    $r = $a->store->listing('refunds')[0];
    $p->refunds['ref_recovered'] = array('refund_id' => 'ref_recovered','charge_id' => $pid,'reference' => $r['data']['reference'],'status' => 'processing','requested_amount' => '100.00');
    (new Refunds($a))->reconcile($r['id']);
    same('ref_recovered', $a->store->get('refunds', $r['id'])['data']['refund_id']);
    same(1, $p->writes('/v1/refunds'));
});
test('NGN bank-transfer refunds remain unavailable', function () {
    list($a,$p) = environment();
    $at = beginPayment($a);
    $pid = $p->succeed($at);
    (new PaymentRecognition($a))->reconcile($at['id']);
    $p->payments[$pid]['payment_method'] = 'NGN_BANK_TRANSFER';
    $payment = $a->store->listing('payments')[0];
    fails('refund_rail_not_supported', function () use ($a, $payment) {
        (new Refunds($a))->request($payment['id'], '100.00', 'Refund rail test', 'admin:10');
    });
});
test('Provider token event without consent cannot enroll a card', function () {
    list($a,$p,$w,$scope) = environment();
    $at = beginPayment($a);
    $p->succeed($at);
    list($raw,$h) = signedEvent($a, $scope, 'payment_method.saved', array('payment_method_id' => 'pm_synthetic','customer' => array('customer_id' => 'cust_synthetic'),'currency' => 'USD','card_last4' => '4242'));
    $event = (new Webhooks($a))->ingest('sandbox', $raw, $h);
    fails('saved_method_consent_missing', function () use ($a, $event) {
        (new Webhooks($a))->process($event);
    });
    same(0, $a->store->count('payment_methods'));
});
test('Saving consent, encrypted token enrollment, revocation and pending capture have one owner', function () {
    list($a,$p,$w,$scope) = environment();
    $a->settings->set('saved_methods', true);
    $w->invoices[42]['gateway'] = 'bachs_card';
    $r = (new Checkout($a))->start(42, 1, true, 'user:21', 'bachs_card');
    $at = $a->store->get('attempts', $r['attempt_id']);
    $p->succeed($at);
    (new PaymentRecognition($a))->reconcile($at['id']);
    list($raw,$h) = signedEvent($a, $scope, 'payment_method.saved', array('payment_method_id' => 'pm_synthetic','customer' => array('customer_id' => 'cust_synthetic'),'currency' => 'USD','card_brand' => 'visa','card_last4' => '4242','card_exp_month' => 12,'card_exp_year' => 2034));
    $event = (new Webhooks($a))->ingest('sandbox', $raw, $h);
    (new Webhooks($a))->process($event);
    $method = $a->store->listing('payment_methods')[0];
    truth(strpos($method['data']['token'], 'pm_synthetic') === false);
    $w->invoices[43] = $w->invoices[42];
    $w->invoices[43]['invoice_id'] = 43;
    $w->invoices[43]['status'] = 'Unpaid';
    $w->invoices[43]['balance'] = '100.00';
    $a->settings->set('automatic_payments', true);
    $first = (new SavedMethods($a))->capture(43, $method['id']);
    $second = (new SavedMethods($a))->capture(43, $method['id']);
    same('pending', $first['status']);
    same('pending', $second['status']);
    same(1, $p->writes('/v1/charges'));
    (new SavedMethods($a))->revoke($method['id'], 1);
    fails('saved_method_access_denied', function () use ($a, $scope, $method) {
        (new SavedMethods($a))->owned($method['id'], $scope['id'], 1);
    });
});
test('Portal session URL remains ephemeral and token ownership is not inferred from email', function () {
    list($a,$p,$w,$scope) = environment();
    $a->customers->resolve($scope, $w->customer(1), $p);
    same('https://portal.bachs.io/s/synthetic_bearer', (new SavedMethods($a))->portal(1));
    $rows = $a->store->listing('operations');
    truth(strpos(json_encode($rows), 'synthetic_bearer') === false);
    fails('customer_not_mapped', function () use ($a) {
        (new SavedMethods($a))->portal(2);
    });
});
test('Deactivation drains existing receipts but prevents new collections', function () {
    list($a,$p) = environment();
    $at = beginPayment($a);
    $p->succeed($at);
    $a->settings->set('draining', true);
    fails('gateway_disabled', function () use ($a) {
        beginPayment($a);
    });
    same('posted', (new PaymentRecognition($a))->reconcile($at['id']));
});
