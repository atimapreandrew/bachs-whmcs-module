<?php
require_once dirname(__DIR__) . '/autoload.php';

use Bachs\Whmcs\Application\Engine;
use Bachs\Whmcs\Application\Settings;
use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Domain\Money;
use Bachs\Whmcs\Infrastructure\Bachs\ApiFailure;
use Bachs\Whmcs\Infrastructure\Storage\EncryptedSecrets;
use Bachs\Whmcs\Infrastructure\Storage\Schema;
use Bachs\Whmcs\Infrastructure\Storage\Store;

class TestClock implements \Bachs\Whmcs\Domain\Clock
{
    public $time = 1792926000;
    public function now() { return $this->time; }
}
class FakeIdentity
{
    public $clientId = 1; public $denied = array(); public $validCsrf = true;
    public function client($id = null) { if ($id !== null && $id !== $this->clientId) { throw new Failure('client_payment_access_denied'); } return array('client_id' => $this->clientId, 'actor' => 'user:21'); }
    public function admin($cap) { if (in_array($cap, $this->denied, true)) { throw new Failure('action_permission_denied'); } return array('id' => 10, 'role_id' => 1, 'actor' => 'admin:10'); }
    public function csrf() { if (!$this->validCsrf) { throw new Failure('csrf_required'); } }
    public function token() { return 'synthetic-csrf'; }
}
class FakeWhmcs implements \Bachs\Whmcs\Domain\InvoiceReader, \Bachs\Whmcs\Domain\PaymentPoster, \Bachs\Whmcs\Domain\RefundFinalizer
{
    public $invoices = array(); public $transactions = array(); public $posts = 0; public $crashAfterPost = false;
    public $identity; public $version = '9.1.0'; public $sandbox = true; public $accept = true;
    public function __construct() { $this->identity = new FakeIdentity(); $this->invoices[42] = array('invoice_id' => 42, 'client_id' => 1, 'status' => 'Unpaid', 'balance' => '100.00', 'total' => '100.00', 'credit' => '0.00', 'currency' => 'USD', 'gateway' => 'bachs', 'items_hash' => 'fixed', 'due_date' => '2026-10-25'); }
    public function invoice($id) { if (!isset($this->invoices[$id])) { throw new Failure('invoice_not_found'); } return $this->invoices[$id]; }
    public function customer($id) { return array('client_id' => $id, 'email' => 'synthetic' . $id . '@example.invalid', 'name' => 'Synthetic Client'); }
    public function systemUrl() { return 'https://billing.example.invalid/subdir'; }
    public function assertAcceptance($feature, $environment) { if (!$this->accept) { throw new Failure('installation_acceptance_required'); } }
    public function sandboxInvoice($id) { return $this->sandbox; }
    public function collectionPrecision($currency) { return $currency === 'USDT_TRC20' ? 6 : 2; }
    public function assertGateway($gateway) { if (!in_array($gateway, array('bachs','bachs_card'), true)) { throw new Failure('gateway_not_bachs'); } }
    public function transaction($id) { return isset($this->transactions[$id]) ? $this->transactions[$id] : null; }
    public function postingLock($id) { return 'fake'; }
    public function releasePostingLock($id) {}
    public function post($invoice, $transaction, $amount, $fee, $gateway)
    {
        ++$this->posts;
        $this->transactions[$transaction] = array('id' => count($this->transactions) + 1, 'invoiceid' => $invoice, 'userid' => $this->invoices[$invoice]['client_id'], 'gateway' => $gateway, 'amountin' => $amount, 'amountout' => '0.00', 'transid' => $transaction, 'refundid' => 0);
        $this->invoices[$invoice]['balance'] = Money::subtract($this->invoices[$invoice]['balance'], $amount);
        if (Money::compare($this->invoices[$invoice]['balance'], '0') === 0) { $this->invoices[$invoice]['status'] = 'Paid'; }
        if ($this->crashAfterPost) { $this->crashAfterPost = false; throw new Failure('simulated_crash_after_native_insert'); }
    }
    public function enrollMethod($method) {}
    public function finalizeRefund($refund, $payment) { return null; }
}
class FakeProvider implements \Bachs\Whmcs\Domain\ProviderClient
{
    public $calls = array(); public $checkouts = array(); public $payments = array(); public $refunds = array(); public $responses = array();
    public $failNextWrite = false; public $chargeState = 'processing'; public $customerId = 'cust_synthetic'; public $lastRequestId = 'req_synthetic'; public $app;
    public function request($method, $path, $body = null, $idempotencyKey = null)
    {
        $this->calls[] = array($method, $path, $body, $idempotencyKey);
        if ($method !== 'GET' && $this->failNextWrite) { $this->failNextWrite = false; throw new ApiFailure('transport_failure', 0, '', 30, true); }
        if (isset($this->responses[$method . ' ' . $path])) { $r = $this->responses[$method . ' ' . $path]; if ($r instanceof Exception) { throw $r; } return $r; }
        if ($method === 'POST' && $path === '/v1/customers') { return array('customer_id' => $this->customerId); }
        if ($method === 'POST' && $path === '/v1/checkout-sessions') {
            if ($this->app && !$this->app->store->find('operations', $this->app->settings->activeScope()['id'], 'checkout:' . $body['metadata']['attempt_id'])) { throw new Failure('test_missing_persist_before_send'); }
            $id = 'chk_' . substr(hash('sha256', $idempotencyKey), 0, 16);
            $this->checkouts[$id] = array('checkout_id' => $id, 'checkout_url' => 'https://checkout.bachs.io/c/synthetic_' . $id,
                'status' => 'open', 'reference' => $body['reference'], 'amount' => isset($body['pricing']) ? $body['pricing']['amount'] : '0.00',
                'currency' => isset($body['pricing']) ? $body['pricing']['currency'] : 'USD', 'customer' => array('id' => $body['customer']['customer_id']), 'metadata' => $body['metadata']);
            return $this->checkouts[$id];
        }
        if ($method === 'GET' && strpos($path, '/v1/checkout-sessions/') === 0) { $id = substr($path, strlen('/v1/checkout-sessions/')); if (isset($this->checkouts[$id])) { return $this->checkouts[$id]; } }
        if ($method === 'POST' && $path === '/v1/charges') {
            $id = 'pay_' . substr(hash('sha256', $idempotencyKey), 0, 16);
            return $this->payments[$id] = array('payment_id' => $id, 'status' => $this->chargeState, 'reference' => $body['reference'], 'amount' => $body['amount'],
                'currency' => $body['currency'], 'amount_paid' => $this->chargeState === 'succeeded' ? $body['amount'] : '0.00', 'billing_reason' => 'purchase', 'payment_method' => 'USD_CARD');
        }
        if ($method === 'GET' && strpos($path, '/v1/payments/') === 0) { $id = substr($path, strlen('/v1/payments/')); if (isset($this->payments[$id])) { return $this->payments[$id]; } }
        if ($method === 'GET' && strpos($path, '/v1/payments?') === 0) { return array('items' => array(), 'pagination' => array('has_more' => false)); }
        if ($method === 'GET' && strpos($path, '/v1/refunds/by-charge/') === 0) { $charge = substr($path, strlen('/v1/refunds/by-charge/')); foreach ($this->refunds as $r) { if ($r['charge_id'] === $charge) { return $r; } } throw new ApiFailure('NOT_FOUND', 404); }
        if ($method === 'POST' && $path === '/v1/refunds') {
            $id = 'ref_' . substr(hash('sha256', $idempotencyKey), 0, 16);
            return $this->refunds[$id] = array('refund_id' => $id, 'charge_id' => $body['charge_id'], 'reference' => $body['reference'], 'status' => 'processing', 'requested_amount' => isset($body['amount']) ? $body['amount'] : '100.00', 'refunded_amount' => null);
        }
        if ($method === 'GET' && strpos($path, '/v1/refunds/') === 0) { $id = substr($path, strlen('/v1/refunds/')); if (isset($this->refunds[$id])) { return $this->refunds[$id]; } }
        if ($method === 'POST' && strpos($path, '/portal-sessions') !== false) { return array('id' => 'psn_synthetic', 'url' => 'https://portal.bachs.io/s/synthetic_bearer'); }
        throw new Failure('unexpected_synthetic_provider_call');
    }
    public function succeed($attempt)
    {
        $id = 'opaque.synthetic.payment'; $d = $attempt['data'];
        $this->checkouts[$d['checkout_id']]['status'] = 'completed';
        $this->checkouts[$d['checkout_id']]['payment_status'] = 'succeeded';
        $this->checkouts[$d['checkout_id']]['charge'] = array('payment_id' => $id);
        $this->payments[$id] = array('payment_id' => $id, 'status' => 'succeeded', 'billing_reason' => 'purchase', 'checkout_id' => $d['checkout_id'],
            'reference' => $d['reference'], 'amount' => $d['amount'], 'amount_paid' => $d['amount'], 'currency' => $d['currency'],
            'is_refundable' => true, 'payment_method' => 'USD_CARD', 'customer' => array('id' => $d['customer_id']), 'fees' => array('amount' => '2.00','currency' => $d['currency']), 'fee_paid_by' => 'merchant');
        return $id;
    }
    public function writes($path) { return count(array_filter($this->calls, function ($c) use ($path) { return $c[0] !== 'GET' && $c[1] === $path; })); }
}
function environment($database = 'sqlite::memory:')
{
    $pdo = new PDO($database); $pdo->exec('PRAGMA busy_timeout=5000'); Schema::migrate($pdo);
    $clock = new TestClock(); $store = new Store($pdo, $clock); $vault = new EncryptedSecrets(str_repeat('test-only-key-', 3));
    $settings = new Settings($store, $vault); $bridge = new FakeWhmcs(); $provider = new FakeProvider();
    $scope = $store->insert('merchant_scopes', '', 'synthetic_scope', array('installation' => 'synthetic_installation', 'environment' => 'sandbox', 'organization' => 'acct_synthetic', 'account' => '', 'generation' => 'generation1', 'verified_at' => $clock->now(), 'name' => 'Synthetic Merchant'), 'bound');
    $settings->set('scope_sandbox', $scope['id']); $settings->set('environment', 'sandbox'); $settings->set('enabled', true);
    $settings->saveSecret('signing_' . $scope['id'], 'synthetic_signing_secret_for_tests');
    $app = new Engine($store, $settings, $bridge, function ($s) use ($provider) { return $provider; }); $provider->app = $app;
    return array($app, $provider, $bridge, $scope, $clock);
}
function beginPayment($app)
{
    $r = (new \Bachs\Whmcs\Application\Checkout($app))->start(42, 1);
    return $app->store->get('attempts', $r['attempt_id']);
}
function signedEvent($app, $scope, $type, $data, $id = 'evt_synthetic')
{
    $body = json_encode(array('id' => $id, 'type' => $type, 'organization_id' => $scope['data']['organization'], 'created_at' => gmdate('c', $app->store->clock->now()), 'data' => $data));
    $t = $app->store->clock->now(); return array($body, array('X-Bachs-Signature-V2' => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, 'synthetic_signing_secret_for_tests')));
}
