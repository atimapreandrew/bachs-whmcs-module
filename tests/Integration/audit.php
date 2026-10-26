<?php

use Bachs\Whmcs\Application\Checkout;
use Bachs\Whmcs\Application\PaymentRecognition;
use Bachs\Whmcs\Application\Refunds;
use Bachs\Whmcs\Application\SavedMethods;
use Bachs\Whmcs\Infrastructure\Bachs\ApiFailure;

function auditPayment()
{
    list($a, $p, $w, $scope, $clock) = environment();
    $attempt = beginPayment($a);
    $p->succeed($attempt);
    (new PaymentRecognition($a))->reconcile($attempt['id']);
    return array($a, $p, $w, $scope, $clock, $a->store->listing('payments')[0], $attempt);
}

function auditCard()
{
    list($a, $p, $w, $scope, $clock) = environment();
    $a->settings->set('saved_methods', true);
    $a->settings->set('automatic_payments', true);
    $w->invoices[42]['gateway'] = 'bachs_card';
    $consent = $a->store->insert('consents', $scope['id'], 'audit-consent', array(), 'active', array('client_id' => 1));
    $method = $a->store->insert('payment_methods', $scope['id'], 'audit-method', array('consent_id' => $consent['id'],
        'token' => $a->settings->secrets->seal('pm_synthetic'), 'currency' => 'USD', 'customer_id' => 'cust_synthetic',
        'expiry_month' => 12, 'expiry_year' => 2034, 'last4' => '4242', 'brand' => 'Visa'), 'active', array('client_id' => 1));
    return array($a, $p, $w, $scope, $clock, $method, $consent);
}

test('A delayed checkout creation response cannot reopen an already posted payment', function () {
    list($a, $p, $w, $scope, $clock, $payment, $attempt) = auditPayment();
    $response = $p->checkouts[$attempt['data']['checkout_id']];
    $response['status'] = 'open';
    $result = (new Checkout($a))->accept($attempt, $response);
    same('posted', $result['state']);
    same('posted', $a->store->get('attempts', $attempt['id'])['state']);
    same(1, $w->posts);
});

test('A stale state transition cannot overwrite posted accounting or its data', function () {
    list($a, $p, $w, $scope, $clock, $payment, $attempt) = auditPayment();
    $a->store->update('attempts', $attempt['id'], array('payment_id' => 'wrong'), 'pending', array(), array('creating'));
    $fresh = $a->store->get('attempts', $attempt['id']);
    same('posted', $fresh['state']);
    same($payment['data']['payment_id'], $fresh['data']['payment_id']);
});

foreach (array('invalid_bearer', 'excessive_fee') as $case) {
    test('Unproven payment fees are held before native posting: ' . $case, function () use ($case) {
        list($a, $p, $w) = environment();
        $attempt = beginPayment($a); $id = $p->succeed($attempt);
        if ($case === 'invalid_bearer') { $p->payments[$id]['fee_paid_by'] = 'unrecognized'; }
        else { $p->payments[$id]['fees']['amount'] = '101.00'; }
        same('review', (new PaymentRecognition($a))->reconcile($attempt['id']));
        same(0, $w->posts);
    });
}

test('A definitive refund rejection permits a new authorized request', function () {
    list($a, $p, $w, $scope, $clock, $payment) = auditPayment();
    $p->responses['POST /v1/refunds'] = new ApiFailure('VALIDATION_ERROR', 400);
    fails('VALIDATION_ERROR', function () use ($a, $payment) { (new Refunds($a))->request($payment['id'], '100.00', 'Audit rejection', 'admin:10'); });
    same('provider_failed', $a->store->listing('refunds')[0]['state']);
    unset($p->responses['POST /v1/refunds']);
    $result = (new Refunds($a))->request($payment['id'], '100.00', 'Audit corrected refund', 'admin:10');
    same('processing', $result['state']);
    same(2, $p->writes('/v1/refunds'));
    same(1, count($w->transactions));
});

test('Refund reconciliation recovers a rejected operation after an interrupted attempt update', function () {
    list($a, $p, $w, $scope, $clock, $payment) = auditPayment();
    $p->responses['POST /v1/refunds'] = new ApiFailure('VALIDATION_ERROR', 400);
    fails('VALIDATION_ERROR', function () use ($a, $payment) { (new Refunds($a))->request($payment['id'], '100.00', 'Audit rejection', 'admin:10'); });
    $refund = $a->store->listing('refunds')[0];
    $a->store->update('refunds', $refund['id'], array(), 'submitting');
    same('terminal', (new Refunds($a))->reconcile($refund['id']));
    same('provider_failed', $a->store->get('refunds', $refund['id'])['state']);
    same(1, $p->writes('/v1/refunds'));
});

test('Lost refund responses use the persisted result before a lagging provider lookup', function () {
    list($a, $p, $w, $scope, $clock, $payment) = auditPayment();
    $refund = (new Refunds($a))->request($payment['id'], '100.00', 'Audit response loss', 'admin:10');
    $a->store->update('refunds', $refund['id'], array('refund_id' => null), 'submitting', array('provider_hash' => ''));
    $p->responses['GET /v1/refunds/by-charge/' . $payment['data']['payment_id']] = new ApiFailure('NOT_FOUND', 404);
    same('pending', (new Refunds($a))->reconcile($refund['id']));
    same($refund['data']['refund_id'], $a->store->get('refunds', $refund['id'])['data']['refund_id']);
    same('processing', $a->store->get('refunds', $refund['id'])['state']);
    same(1, $p->writes('/v1/refunds'));
});

foreach (array(null, '1.00', '0.00') as $returned) {
    test('Refund success requires proof of the actual returned amount: ' . var_export($returned, true), function () use ($returned) {
        list($a, $p, $w, $scope, $clock, $payment) = auditPayment();
        $refund = (new Refunds($a))->request($payment['id'], '100.00', 'Audit incomplete settlement', 'admin:10');
        $resource = $p->refunds[$refund['data']['refund_id']];
        $resource['status'] = 'success'; $resource['refunded_amount'] = $returned;
        $result = (new Refunds($a))->accept($refund, $resource);
        same('review', $result['state']);
        same('held', $result['data']['accounting_state']);
        same(1, count($w->transactions));
    });
}

test('A refund cannot change provider identity once it has been bound', function () {
    list($a, $p, $w, $scope, $clock, $payment) = auditPayment();
    $refund = (new Refunds($a))->request($payment['id'], '100.00', 'Audit identity', 'admin:10');
    $resource = $p->refunds[$refund['data']['refund_id']]; $resource['refund_id'] = 'different_refund';
    fails('refund_identity_mismatch', function () use ($a, $refund, $resource) { (new Refunds($a))->accept($refund, $resource); });
});

test('Completed external refund accounting cannot be remapped to another amount', function () {
    list($a, $p, $w, $scope, $clock, $payment) = auditPayment();
    $refund = (new Refunds($a))->import($scope, $payment, array('refund_id' => 'external_audit', 'charge_id' => $payment['data']['payment_id'],
        'reference' => 'external_audit', 'status' => 'success', 'requested_amount' => '50.00', 'refunded_amount' => '50.00'));
    $a->store->update('refunds', $refund['id'], array('amount' => '50.00', 'accounting_state' => 'completed'), 'completed');
    fails('refund_mapping_already_fixed', function () use ($a, $refund) { (new Refunds($a))->mapAccounting($refund['id'], '25.00', str_repeat('evidence ', 4), 'admin:10'); });
    same('completed', $a->store->get('refunds', $refund['id'])['state']);
});

test('A saved card cannot borrow another client or merchant consent', function () {
    list($a, $p, $w, $scope, $clock, $method, $consent) = auditCard();
    $a->store->update('consents', $consent['id'], array(), null, array('client_id' => 2));
    fails('automatic_payment_consent_revoked', function () use ($a, $method, $scope) { (new SavedMethods($a))->owned($method['id'], $scope['id'], 1); });
});

test('Expired saved cards are rejected before a provider charge is sent', function () {
    list($a, $p, $w, $scope, $clock, $method) = auditCard();
    $a->store->update('payment_methods', $method['id'], array('expiry_year' => 2020));
    fails('saved_card_expired', function () use ($a, $method) { (new SavedMethods($a))->capture(42, $method['id']); });
    same(0, $p->writes('/v1/charges'));
});

test('Deleting a native Pay Method revokes module consent and blocks deferred charges', function () {
    require_once dirname(dirname(__DIR__)) . '/modules/gateways/bachs_card.php';
    list($a, $p, $w, $scope, $clock, $method, $consent) = auditCard();
    $runtime = new ReflectionProperty(\Bachs\Whmcs\Infrastructure\Whmcs\Runtime::class, 'app');
    $runtime->setAccessible(true); $old = $runtime->getValue(); $runtime->setValue(null, $a);
    try {
        $result = bachs_card_storeremote(array('action' => 'delete', 'gatewayid' => $method['id'], 'clientdetails' => array('userid' => 1)));
        same('success', $result['status']);
        same('revoked', $a->store->get('payment_methods', $method['id'])['state']);
        same('revoked', $a->store->get('consents', $consent['id'])['state']);
        fails('saved_method_access_denied', function () use ($a, $method) { (new SavedMethods($a))->capture(42, $method['id']); });
        same(0, count($p->calls));
        same('error', bachs_card_storeremote(array('action' => 'create'))['status']);
    } finally { $runtime->setValue(null, $old); }
});

test('Exhausted jobs can be retried only through an authorized scoped CSRF action', function () {
    list($a, $p, $w, $scope) = environment();
    $job = $a->queue->enqueue('sweep', $scope['id'], $scope['id']);
    $a->store->update('jobs', $job['id'], array('tries' => 12), 'dead');
    $input = array('action' => 'retry_job', 'scope_id' => $scope['id'], 'target' => $job['id']);
    $actions = new \Bachs\Whmcs\Http\AdminActions($a);
    $w->identity->denied = array('manage_webhooks');
    fails('action_permission_denied', function () use ($actions, $input) { $actions->run($input); });
    same('dead', $a->store->get('jobs', $job['id'])['state']);
    $w->identity->denied = array(); $w->identity->validCsrf = false;
    fails('csrf_required', function () use ($actions, $input) { $actions->run($input); });
    $w->identity->validCsrf = true; $actions->run($input);
    $fresh = $a->store->get('jobs', $job['id']);
    same('ready', $fresh['state']); same(0, $fresh['data']['tries']); same($scope['id'], $fresh['data']['aggregate']);
    same(0, count($p->calls));
});

test('Consent form values must be explicit scalars before any provider request', function () {
    list($a, $p) = environment(); $post = $_POST;
    try {
        foreach (array('false', array('1')) as $invalid) {
            $_POST = array('invoice_id' => '42', 'save_method' => $invalid);
            fails('invalid_input', function () use ($a) { (new \Bachs\Whmcs\Http\ClientController($a))->checkout(); });
        }
        same(0, count($p->calls));
    } finally { $_POST = $post; }
});

test('A permission failure does not hide the client error notice', function () {
    list($a, $p, $w) = environment();
    $w->identity->clientId = 2;
    $session = isset($_SESSION) ? $_SESSION : array(); $get = $_GET;
    try {
        $_GET = array();
        $_SESSION['bachs_notice'] = '<strong>Payment request</strong><p>Permission unavailable.</p>';
        $w->identity = new class extends FakeIdentity {
            public function client($id = null) { throw new \Bachs\Whmcs\Domain\Failure('client_payment_access_denied'); }
        };
        $html = \Bachs\Whmcs\Http\ClientView::render($a, array('filename' => 'clientarea'));
        truth(strpos($html, 'Permission unavailable.') !== false);
        same(false, isset($_SESSION['bachs_notice']));
        same(0, count($p->calls));
    } finally { $_SESSION = $session; $_GET = $get; }
});

test('Signed-in users denied payment access are not misreported as logged out on return', function () {
    list($a, $p, $w) = environment(); $server = $_SERVER;
    $w->identity = new class extends FakeIdentity {
        public function client($id = null) { throw new \Bachs\Whmcs\Domain\Failure('client_payment_access_denied'); }
    };
    try {
        $_SERVER['PATH_INFO'] = '/' . str_repeat('a', 48) . '/' . str_repeat('b', 48);
        fails('client_payment_access_denied', function () use ($a) { (new \Bachs\Whmcs\Http\ClientController($a))->returnPage(); });
    } finally { $_SERVER = $server; }
});

test('Customer mapping recovers a successful create even after billing details change', function () {
    list($a, $p, $w, $scope) = environment();
    $first = $w->customer(1); $id = $a->customers->resolve($scope, $first, $p);
    $a->store->run("DELETE FROM mod_bachs_customers WHERE state='active'");
    $first['name'] = 'Changed Name'; $first['email'] = 'changed@example.invalid';
    same($id, $a->customers->resolve($scope, $first, $p));
    same(1, $p->writes('/v1/customers'));
});

test('Corrupt operation bodies and mismatched merchant scopes cannot reach the provider', function () {
    list($a, $p, $w, $scope) = environment();
    $operation = $a->operations->prepare($scope, 'audit-op', 'POST', '/v1/charges', array('amount' => '1.00'));
    $other = $scope; $other['id'] = str_repeat('b', 48);
    fails('operation_scope_mismatch', function () use ($a, $p, $operation, $other) { $a->operations->send($operation, $other, $p); });
    $a->store->update('operations', $operation['id'], array('request' => $a->settings->secrets->seal('{invalid')));
    fails('operation_body_corrupt', function () use ($a, $p, $operation, $scope) { $a->operations->send($operation, $scope, $p); });
    same(0, count($p->calls));
    same('prepared', $a->store->get('operations', $operation['id'])['state']);
});

test('UTF-8 merchant labels and diagnostics retain valid encoding when truncated', function () {
    $redacted = \Bachs\Whmcs\Infrastructure\SafeData::redact(array('reference' => str_repeat('é', 200)));
    truth(json_encode($redacted) !== false);
    $short = \Bachs\Whmcs\Infrastructure\SafeData::shortText(str_repeat('é', 100), 127);
    truth(strlen($short) <= 127); same(1, preg_match('//u', $short));
});

class AuditFailingSecrets implements \Bachs\Whmcs\Domain\SecretStore
{
    private $delegate;
    public function __construct($delegate) { $this->delegate = $delegate; }
    public function seal($value) { throw new \Bachs\Whmcs\Domain\Failure('native_encryption_failed'); }
    public function open($value) { return $this->delegate->open($value); }
}

test('Credential rotation failure preserves the old key and matching generation', function () {
    list($a, $p, $w, $scope) = environment();
    $p->responses['GET /v1/accounts/me'] = array('id' => 'acct_synthetic', 'name' => 'Merchant');
    $connection = new \Bachs\Whmcs\Application\Connection($a, function () use ($p) { return $p; });
    $scope = $connection->connect('sandbox', 'sk_sandbox_old', 'admin:10');
    $oldGeneration = $scope['data']['generation'];
    $a->settings->secrets = new AuditFailingSecrets($a->settings->secrets);
    fails('native_encryption_failed', function () use ($connection) { $connection->connect('sandbox', 'sk_sandbox_new', 'admin:10'); });
    same($oldGeneration, $a->settings->scope($scope['id'])['data']['generation']);
    same('sk_sandbox_old', $a->settings->secret('key_' . $scope['id']));
});

test('A database failure during key rotation rolls back the scope fingerprint and secret together', function () {
    list($a, $p, $w, $scope) = environment();
    $p->responses['GET /v1/accounts/me'] = array('id' => 'acct_synthetic', 'name' => 'Merchant');
    $connection = new \Bachs\Whmcs\Application\Connection($a, function () use ($p) { return $p; });
    $scope = $connection->connect('sandbox', 'sk_sandbox_old', 'admin:10');
    $keyRow = $a->store->find('settings', '', 'key_' . $scope['id']);
    $a->store->pdo->exec("CREATE TRIGGER fail_key_update BEFORE UPDATE ON mod_bachs_settings WHEN OLD.id='" . $keyRow['id'] . "' BEGIN SELECT RAISE(ABORT, 'injected database failure'); END");
    $caught = false;
    try { $connection->connect('sandbox', 'sk_sandbox_new', 'admin:10'); } catch (PDOException $error) { $caught = true; }
    same(true, $caught);
    same($scope['data']['generation'], $a->settings->scope($scope['id'])['data']['generation']);
    same('sk_sandbox_old', $a->settings->secret('key_' . $scope['id']));
});

test('Malformed signed event identities are durably quarantined instead of repeatedly crashing', function () {
    list($a, $p, $w, $scope) = environment();
    $raw = json_encode(array('id' => array('invalid'), 'type' => 'collection.succeeded', 'organization_id' => $scope['data']['organization'], 'data' => array()));
    $time = $a->store->clock->now();
    $headers = array('X-Bachs-Signature-V2' => 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $raw, 'synthetic_signing_secret_for_tests'));
    $service = new \Bachs\Whmcs\Application\Webhooks($a);
    $id = $service->ingest('sandbox', $raw, $headers);
    same('quarantined', $a->store->get('webhook_events', $id)['state']);
    fails('authenticated_event_required', function () use ($service, $id) { $service->process($id); });
    same(0, count($p->calls)); same(0, $w->posts);
});

test('Jobs have a paginated native admin view with permission-checked retry controls', function () {
    list($a, $p, $w, $scope) = environment();
    $job = $a->queue->enqueue('sweep', $scope['id'], $scope['id']);
    $a->store->update('jobs', $job['id'], array('tries' => 12), 'dead');
    $get = $_GET; $post = $_POST; $server = $_SERVER;
    try {
        $_GET = array('bachs_page' => 'jobs'); $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
        $html = (new \Bachs\Whmcs\Http\AdminView($a, 'addonmodules.php?module=bachs_payments'))->render();
        truth(strpos($html, 'Retry existing job') !== false);
        truth(strpos($html, 'Page 1') !== false);
        $w->identity->denied = array('manage_webhooks');
        $html = (new \Bachs\Whmcs\Http\AdminView($a, 'addonmodules.php?module=bachs_payments'))->render();
        same(false, strpos($html, 'Retry existing job') !== false);
    } finally { $_GET = $get; $_POST = $post; $_SERVER = $server; }
});

test('A rejected customer create can be corrected without replacing an uncertain operation', function () {
    list($a, $p, $w, $scope) = environment();
    $p->responses['POST /v1/customers'] = new ApiFailure('VALIDATION_ERROR', 400);
    fails('VALIDATION_ERROR', function () use ($a, $p, $w, $scope) { $a->customers->resolve($scope, $w->customer(1), $p); });
    unset($p->responses['POST /v1/customers']);
    $client = $w->customer(1); $client['name'] = 'Corrected billing name';
    same('cust_synthetic', $a->customers->resolve($scope, $client, $p));
    same(2, $p->writes('/v1/customers'));
    list($a, $p, $w, $scope) = environment(); $p->failNextWrite = true;
    fails('transport_failure', function () use ($a, $p, $w, $scope) { $a->customers->resolve($scope, $w->customer(1), $p); });
    fails('operation_requires_reconciliation', function () use ($a, $p, $w, $scope) { $a->customers->resolve($scope, $w->customer(1), $p); });
    same(1, $p->writes('/v1/customers'));
});

test('Refund preview excludes payer contact and bank data from the confirmation page', function () {
    list($a, $p, $w, $scope, $clock, $payment) = auditPayment();
    $p->payments[$payment['data']['payment_id']]['customer'] = array('name' => 'Private payer', 'email' => 'private@example.invalid');
    $p->payments[$payment['data']['payment_id']]['payment_method_details'] = array('bank_transfer' => array('sender_account_number' => 'private_account'));
    $result = (new \Bachs\Whmcs\Http\AdminActions($a))->run(array('action' => 'refund_preview', 'scope_id' => $scope['id'],
        'target' => $payment['id'], 'amount' => '100.00', 'reason' => 'Requested refund'));
    $json = json_encode($result);
    same(false, strpos($json, 'private@example.invalid') !== false);
    same(false, strpos($json, 'private_account') !== false);
    truth(strpos($json, '100.00') !== false);
});

test('An interrupted checkout with no durable request can release the invoice safely', function () {
    list($a, $p, $w, $scope) = environment();
    $invoice = $w->invoice(42);
    $attempt = $a->store->insert('attempts', $scope['id'], 'orphan-checkout', array('purpose' => 'invoice', 'reference' => 'orphan-checkout',
        'snapshot' => $a->snapshot($invoice), 'amount' => '100.00', 'currency' => 'USD', 'gateway' => 'bachs'), 'creating', array('invoice_id' => 42, 'client_id' => 1));
    (new \Bachs\Whmcs\Application\Worker($a))->run(10, 5);
    same('failed', $a->store->get('attempts', $attempt['id'])['state']);
    same(0, $p->writes('/v1/checkout-sessions'));
    $result = (new Checkout($a))->start(42, 1);
    same('open', $result['state']); same(1, $p->writes('/v1/checkout-sessions'));
});

test('A live initiation lease prevents the worker from prematurely failing a preparation', function () {
    list($a, $p, $w, $scope) = environment();
    $attempt = $a->store->insert('attempts', $scope['id'], 'active-checkout', array('purpose' => 'invoice'), 'creating', array('invoice_id' => 42, 'client_id' => 1));
    $lease = $a->store->acquire('invoice:42');
    (new \Bachs\Whmcs\Application\Worker($a))->run(10, 5);
    same('creating', $a->store->get('attempts', $attempt['id'])['state']);
    same(0, $p->writes('/v1/checkout-sessions'));
    $a->store->release('invoice:42', $lease);
});

test('Worker reconnects a persisted checkout operation after the attempt-link write was lost', function () {
    list($a, $p) = environment();
    $at = beginPayment($a);
    $a->store->update('attempts', $at['id'], array('operation_id' => null, 'checkout_id' => null, 'checkout_url' => null), 'creating', array('provider_hash' => ''));
    (new \Bachs\Whmcs\Application\Worker($a))->run(10, 5);
    $fresh = $a->store->get('attempts', $at['id']);
    same($at['data']['operation_id'], $fresh['data']['operation_id']);
    same($at['data']['checkout_id'], $fresh['data']['checkout_id']);
    same(1, $p->writes('/v1/checkout-sessions'));
});

test('An unsubmitted refund interrupted before operation preparation can be requested again', function () {
    list($a, $p, $w, $scope, $clock, $payment) = auditPayment();
    $refund = $a->store->insert('refunds', $scope['id'], 'orphan-refund', array('payment_id' => $payment['id'], 'charge_id' => $payment['data']['payment_id'],
        'amount' => '100.00', 'currency' => 'USD', 'full' => true, 'reference' => 'orphan-refund'), 'requested', array('invoice_id' => 42, 'client_id' => 1));
    $a->store->put('settings', $scope['id'], 'refund_owner:' . $payment['id'], array('refund_id' => $refund['id']), 'refund_owner');
    same('terminal', (new Refunds($a))->reconcile($refund['id']));
    same('provider_failed', $a->store->get('refunds', $refund['id'])['state']);
    same(0, $p->writes('/v1/refunds'));
    same('processing', (new Refunds($a))->request($payment['id'], '100.00', 'New reviewed request', 'admin:10')['state']);
});

test('A prepared refund resumes the original reviewed body and idempotency key', function () {
    list($a, $p, $w, $scope, $clock, $payment) = auditPayment();
    $refund = $a->store->insert('refunds', $scope['id'], 'prepared-refund', array('payment_id' => $payment['id'], 'charge_id' => $payment['data']['payment_id'],
        'amount' => '100.00', 'currency' => 'USD', 'full' => true, 'reference' => 'prepared-refund'), 'requested', array('invoice_id' => 42, 'client_id' => 1));
    $identity = 'refund:' . $refund['id'];
    $body = array('charge_id' => $payment['data']['payment_id'], 'reference' => 'prepared-refund', 'fee_bearer' => 'org',
        'idempotency_key' => \Bachs\Whmcs\Application\Operations::key($scope['id'], $identity), 'reason' => 'Reviewed before interruption');
    $operation = $a->operations->prepare($scope, $identity, 'POST', '/v1/refunds', $body);
    (new Refunds($a))->reconcile($refund['id']);
    same('processing', $a->store->get('refunds', $refund['id'])['state']);
    same($operation['id'], $a->store->get('refunds', $refund['id'])['data']['operation_id']);
    $calls = array_values(array_filter($p->calls, function ($call) { return $call[0] === 'POST' && $call[1] === '/v1/refunds'; }));
    same(1, count($calls)); same($body, $calls[0][2]); same($body['idempotency_key'], $calls[0][3]);
});

test('A corrupt saved token cannot leave a new invoice attempt blocking future payments', function () {
    list($a, $p, $w, $scope, $clock, $method) = auditCard();
    $a->store->update('payment_methods', $method['id'], array('token' => 'invalid-encryption'));
    fails('invalid_ciphertext', function () use ($a, $method) { (new SavedMethods($a))->capture(42, $method['id']); });
    same(0, $a->store->count('attempts')); same(0, count($p->calls));
});

test('A late processing refund response cannot erase already proven settlement', function () {
    list($a, $p, $w, $scope, $clock, $payment) = auditPayment();
    $service = new Refunds($a);
    $refund = $service->request($payment['id'], '100.00', 'Audit late response', 'admin:10');
    $resource = $p->refunds[$refund['data']['refund_id']];
    $success = $resource; $success['status'] = 'success'; $success['refunded_amount'] = '100.00';
    $service->accept($refund, $success);
    $result = $service->accept($refund, $resource);
    same('accounting_pending', $result['state']); same('success', $result['data']['provider_state']);
});

test('A repeated saved-card event can recover native enrollment after the original consent window', function () {
    list($a, $p, $w, $scope, $clock) = environment();
    $a->settings->set('saved_methods', true);
    $result = (new Checkout($a))->start(42, 1, true, 'user:21');
    $attempt = $a->store->get('attempts', $result['attempt_id']); $p->succeed($attempt);
    $data = array('payment_method_id' => 'pm_audit', 'customer' => array('customer_id' => 'cust_synthetic'), 'currency' => 'USD',
        'card_last4' => '4242', 'card_exp_month' => 12, 'card_exp_year' => 2034, 'checkout_id' => $attempt['data']['checkout_id']);
    list($raw, $headers) = signedEvent($a, $scope, 'payment_method.saved', $data, 'evt_audit_card');
    $hooks = new \Bachs\Whmcs\Application\Webhooks($a); $hooks->process($hooks->ingest('sandbox', $raw, $headers));
    $clock->time += 90000;
    list($raw, $headers) = signedEvent($a, $scope, 'payment_method.saved', $data, 'evt_audit_card_redelivery');
    $hooks->process($hooks->ingest('sandbox', $raw, $headers));
    same(1, $a->store->count('payment_methods')); same(2, count($w->enrolled));
});

test('An older module cannot silently run against a newer database schema', function () {
    list($a) = environment();
    $row = $a->store->find('schema_migrations', '', 'schema-1');
    $a->store->update('schema_migrations', $row['id'], array('version' => 2));
    fails('module_schema_newer', function () use ($a) { \Bachs\Whmcs\Infrastructure\Storage\Schema::ensure($a->store->pdo); });
    same(2, $a->store->get('schema_migrations', $row['id'])['data']['version']);
});

test('A mixed incoming and outgoing transaction cannot finalize refund accounting', function () {
    list($a, $p, $w, $scope, $clock, $payment) = auditPayment();
    $refund = (new Refunds($a))->request($payment['id'], '100.00', 'Native refund linkage audit', 'admin:10');
    $transid = \Bachs\Whmcs\Domain\Ids::transaction($scope['id'], $refund['data']['refund_id'], 'refund');
    $w->transactions[$transid] = array('id' => 2, 'invoiceid' => 42, 'userid' => 1, 'gateway' => 'bachs',
        'amountin' => '1.00', 'amountout' => '100.00', 'refundid' => $payment['data']['native_id']);
    fails('native_refund_linkage_conflict', function () use ($w, $refund, $payment) {
        (new \Bachs\Whmcs\Infrastructure\Whmcs\RefundAdapter($w))->finalize($refund, $payment);
    });
});

test('A malformed signature header value is rejected without a PHP type error', function () {
    fails('invalid_signature', function () {
        (new \Bachs\Whmcs\Infrastructure\Bachs\SignatureVerifier(new TestClock()))->verify('{}', array('X-Bachs-Signature-V2' => array('invalid')), array('test'));
    });
});

test('A stale scope cannot send a persisted operation under a newly rotated API key', function () {
    list($a, $p, $w, $scope) = environment();
    $a->settings->saveSecret('key_' . $scope['id'], 'sk_sandbox_old');
    $oldScope = $a->store->update('merchant_scopes', $scope['id'], array('generation' => hash('sha256', 'sk_sandbox_old')));
    truth($a->settings->provider($oldScope) instanceof \Bachs\Whmcs\Infrastructure\Bachs\Client);
    $a->settings->saveSecret('key_' . $scope['id'], 'sk_sandbox_new');
    $a->store->update('merchant_scopes', $scope['id'], array('generation' => hash('sha256', 'sk_sandbox_new')));
    fails('credential_rotated_reconcile', function () use ($a, $oldScope) { $a->settings->provider($oldScope); });
    truth($a->settings->provider($a->settings->scope($scope['id'])) instanceof \Bachs\Whmcs\Infrastructure\Bachs\Client);
});

test('Auth rejection of an in-flight retry does not permit a replacement charge', function () {
    list($a, $p, $w, $scope, $clock, $method) = auditCard();
    $p->responses['POST /v1/charges'] = new ApiFailure('IDEMPOTENCY_IN_PROGRESS', 409, '', 30);
    fails('IDEMPOTENCY_IN_PROGRESS', function () use ($a, $method) { (new SavedMethods($a))->capture(42, $method['id']); });
    $attempt = $a->store->listing('attempts')[0];
    $clock->time += 60;
    $p->responses['POST /v1/charges'] = new ApiFailure('UNAUTHORIZED', 401);
    (new \Bachs\Whmcs\Application\Worker($a))->run(10, 5);
    same('outcome_unknown', $a->store->get('operations', $attempt['data']['operation_id'])['state']);
    same('pending', (new SavedMethods($a))->capture(42, $method['id'])['status']);
    same(1, $a->store->count('attempts'));
    same(2, $p->writes('/v1/charges')); // Original and same-key retry; no new operation.
    same(0, $w->posts);
});

test('A failed refund with returned funds cannot authorize another full refund', function () {
    list($a, $p, $w, $scope, $clock, $payment) = auditPayment();
    $service = new Refunds($a);
    $refund = $service->request($payment['id'], '100.00', 'Audit partial failure', 'admin:10');
    $resource = $p->refunds[$refund['data']['refund_id']]; $resource['status'] = 'failed'; $resource['refunded_amount'] = '20.00';
    $result = $service->accept($refund, $resource);
    same('review', $result['state']); same('held', $result['data']['accounting_state']);
    same($refund['id'], $service->request($payment['id'], '100.00', 'Do not refund twice', 'admin:10')['id']);
    same(1, $p->writes('/v1/refunds'));
});

test('Dashboard refunds with partial failed settlement are imported for review before any new request', function () {
    list($a, $p, $w, $scope, $clock, $payment) = auditPayment();
    $p->refunds['external_partial_failure'] = array('refund_id' => 'external_partial_failure', 'charge_id' => $payment['data']['payment_id'],
        'reference' => 'external_partial_failure', 'status' => 'failed', 'requested_amount' => '100.00', 'refunded_amount' => '20.00');
    $result = (new Refunds($a))->request($payment['id'], '100.00', 'Audit partial external failure', 'admin:10');
    same('review', $result['state']); same('held', $result['data']['accounting_state']);
    same(0, $p->writes('/v1/refunds'));
});

class AuditInterleavingStatement extends PDOStatement
{
    public static $hook;
    protected function __construct() {}
    public function execute(?array $params = null): bool
    {
        if (self::$hook && strpos($this->queryString, 'UPDATE mod_bachs_attempts SET') === 0) {
            $hook = self::$hook; self::$hook = null; $hook();
        }
        return parent::execute($params);
    }
}

foreach (array('scheduling', 'merged_data') as $case) {
    test('Concurrent record writes retain freshly saved payment fields: ' . $case, function () use ($case) {
        list($a, $p) = environment(); $attempt = beginPayment($a);
        $a->store->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, array(AuditInterleavingStatement::class));
        AuditInterleavingStatement::$hook = function () use ($a, $attempt) {
            $a->store->update('attempts', $attempt['id'], array('payment_id' => 'newly_verified_payment'));
        };
        try {
            if ($case === 'scheduling') { $a->store->update('attempts', $attempt['id'], array(), null, array('next_run' => 100)); }
            else { $a->store->update('attempts', $attempt['id'], array('worker_note' => 'resumed')); }
            $fresh = $a->store->get('attempts', $attempt['id']);
            same('newly_verified_payment', $fresh['data']['payment_id']);
            if ($case === 'merged_data') { same('resumed', $fresh['data']['worker_note']); }
            else { same(100, (int)$fresh['next_run']); }
        } finally { AuditInterleavingStatement::$hook = null; }
    });
}
