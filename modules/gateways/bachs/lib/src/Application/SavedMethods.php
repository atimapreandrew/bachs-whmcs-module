<?php

namespace Bachs\Whmcs\Application;

use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Domain\Ids;
use Bachs\Whmcs\Domain\Money;
use Bachs\Whmcs\Infrastructure\Bachs\Urls;

/** Binds encrypted card tokens to a billing client and their recorded consent. */
class SavedMethods implements \Bachs\Whmcs\Domain\TokenStore
{
    private $app;

    public function __construct(Engine $app)
    {
        $this->app = $app;
    }

    public function saved($event)
    {
        $a = $this->app;
        $d = $event['data']['payload'];
        if (!isset($d['token'], $d['token_hash'], $d['customer_id'], $d['currency'], $d['card_last4']) || !preg_match('/^[0-9]{4}$/D', $d['card_last4'])) {
            throw new Failure('saved_method_contract_mismatch');
        }
        $customer = $a->store->byProvider('customers', $event['scope'], $d['customer_id']);
        if (!$customer || $customer['state'] !== 'active') {
            throw new Failure('saved_method_customer_unmapped');
        }
        $consents = $a->store->listing('consents', array('scope' => $event['scope'], 'client_id' => $customer['client_id'], 'state' => 'active'), 50);
        $consent = null;
        foreach ($consents as $candidate) {
            if ($candidate['data']['customer_id'] !== $d['customer_id']) {
                continue;
            }
            if (isset($candidate['data']['method_fingerprint']) && $candidate['data']['method_fingerprint'] !== $d['token_hash']) {
                continue;
            }
            $attempt = $a->store->get('attempts', $candidate['data']['attempt_id']);
            if (!$attempt || empty($attempt['data']['checkout_id'])) {
                continue;
            }
            $resource = $a->provider($a->settings->scope($event['scope']))->request('GET', '/v1/checkout-sessions/' . rawurlencode($attempt['data']['checkout_id']));
            if (
                isset($resource['status'], $resource['customer']['id']) && $resource['status'] === 'completed' && $resource['customer']['id'] === $d['customer_id'] &&
                $candidate['created_at'] <= $event['created_at'] && $event['created_at'] - $candidate['created_at'] <= 86400
            ) {
                $consent = $candidate;
                break;
            }
        }
        if (!$consent) {
            throw new Failure('saved_method_consent_missing');
        }
        $claim = $a->store->insert('settings', $event['scope'], 'consent_method:' . $consent['id'], array('token_hash' => $d['token_hash']), 'consent_method');
        if ($claim['data']['token_hash'] !== $d['token_hash']) {
            throw new Failure('consent_already_associated_with_another_method');
        }
        $existing = $a->store->find('payment_methods', $event['scope'], $d['token_hash']);
        if ($existing && $existing['state'] === 'revoked') {
            return;
        }
        $method = $a->store->insert('payment_methods', $event['scope'], $d['token_hash'], array('token' => $d['token'], 'customer_id' => $d['customer_id'],
            'consent_id' => $consent['id'], 'brand' => isset($d['card_brand']) ? substr($d['card_brand'], 0, 32) : 'Card', 'last4' => $d['card_last4'],
            'expiry_month' => isset($d['card_exp_month']) ? (int)$d['card_exp_month'] : 0,
            'expiry_year' => isset($d['card_exp_year']) ? (int)$d['card_exp_year'] : 0, 'currency' => $d['currency']), 'active', array('client_id' => (int)$customer['client_id']));
        $a->store->update('consents', $consent['id'], array('method_fingerprint' => $d['token_hash']));
        // Native token is a local opaque row ID; the provider credential is never returned to the browser.
        $a->bridge->enrollMethod($method);
    }

    public function owned($methodId, $scope, $clientId)
    {
        $method = $this->app->store->get('payment_methods', $methodId);
        if (!$method || $method['scope'] !== $scope || (int)$method['client_id'] !== (int)$clientId || $method['state'] !== 'active') {
            throw new Failure('saved_method_access_denied');
        }
        $consent = $this->app->store->get('consents', $method['data']['consent_id']);
        if (!$consent || $consent['state'] !== 'active') {
            throw new Failure('automatic_payment_consent_revoked');
        }
        return $method;
    }

    public function revoke($methodId, $clientId)
    {
        $a = $this->app;
        $method = $a->store->get('payment_methods', $methodId);
        if (!$method || (int)$method['client_id'] !== (int)$clientId) {
            throw new Failure('saved_method_access_denied');
        }
        $a->store->update('payment_methods', $methodId, array('revoked_at' => $a->store->clock->now()), 'revoked');
        $a->store->update('consents', $method['data']['consent_id'], array('revoked_at' => $a->store->clock->now()), 'revoked');
        $a->store->audit('client:' . $clientId, 'automatic_payments_disabled', $method['scope'], $methodId);
    }

    public function portal($clientId)
    {
        $a = $this->app;
        $scope = $a->settings->activeScope();
        $customer = $a->store->find('customers', $scope['id'], 'client:' . $clientId);
        if (!$customer || $customer['state'] !== 'active') {
            throw new Failure('customer_not_mapped');
        }
        // Portal URLs are deliberately excluded from operation response persistence.
        $op = $a->operations->prepare($scope, 'portal:' . Ids::random(), 'POST', '/v1/customers/' . rawurlencode($customer['data']['customer_id']) . '/portal-sessions', null);
        $a->store->update('operations', $op['id'], array(), 'sending');
        try {
            $response = $a->provider($scope)->request('POST', $op['data']['path'], null, $op['data']['idempotency_key']);
            if (!isset($response['url'])) {
                throw new Failure('portal_contract_mismatch');
            }
            $url = Urls::hosted($response['url'], 'portal');
            $a->store->update('operations', $op['id'], array(), 'succeeded');
            return $url;
        } catch (\Throwable $e) {
            $a->store->update('operations', $op['id'], array(), 'outcome_unknown');
            throw $e;
        }
    }

    public function setup($clientId, $actor)
    {
        $a = $this->app;
        $scope = $a->settings->activeScope();
        if (!$a->settings->get('saved_methods', false) || !$a->settings->get('enabled', false) || $a->settings->get('draining', false)) {
            throw new Failure('saving_not_enabled');
        }
        $a->settings->assertEnvironment($scope['data']['environment']);
        $a->settings->cardMethods('USD');
        $lock = 'card_setup:' . $scope['id'] . ':' . (int)$clientId;
        $lease = $a->store->acquire($lock);
        try {
            foreach ($a->store->unfinishedAttempts(0, $clientId, $scope['id']) as $old) {
                if (!in_array($old['state'], array('setup_completed','failed','expired_verified','cancelled_verified'), true)) {
                    if ($old['state'] === 'open' && $old['data']['expires_at'] > $a->store->clock->now() && !empty($old['data']['checkout_url'])) {
                        return (new Checkout($a))->presentation($old)['checkout_url'];
                    }
                    $a->job('attempt', $scope['id'], $old['id']);
                    throw new Failure('existing_attempt_requires_reconciliation');
                }
            }
            $customerId = $a->customers->resolve($scope, $a->bridge->customer($clientId), $a->provider($scope));
            $reference = 'wsetup_' . Ids::random();
            $state = Ids::random();
            $attempt = $a->store->insert('attempts', $scope['id'], $reference, array('reference' => $reference, 'purpose' => 'setup', 'customer_id' => $customerId,
                'amount' => '0.00', 'currency' => 'USD', 'gateway' => 'bachs_card', 'save' => true,
                'state_hash' => hash('sha256', $state), 'return_state' => $a->settings->secrets->seal($state),
                'expires_at' => $a->store->clock->now() + 3600), 'creating', array('client_id' => (int)$clientId));
            $consent = $a->store->insert('consents', $scope['id'], $attempt['id'], array('actor' => $actor, 'attempt_id' => $attempt['id'],
                'customer_id' => $customerId, 'version' => '1', 'text' => Checkout::consentText(), 'purpose' => 'whmcs_invoice_renewals', 'billing_authority' => 'whmcs'), 'active', array('client_id' => (int)$clientId));
            $a->store->update('attempts', $attempt['id'], array('consent_id' => $consent['id']));
            $return = $a->bridge->systemUrl() . '/modules/gateways/bachs/return.php/' . $attempt['id'] . '/' . $state;
            $op = $a->operations->prepare(
                $scope,
                'setup:' . $attempt['id'],
                'POST',
                '/v1/checkout-sessions',
                array('customer' => array('customer_id' => $customerId), 'save_payment_method' => true, 'payment_method_types' => array('USD_CARD'),
                'reference' => $reference,
                'success_url' => $return,
                'cancel_url' => $return . '?cancel=1',
                'metadata' => array('integration' => 'whmcs',
                'attempt_id' => $attempt['id']))
            );
            $a->store->update('attempts', $attempt['id'], array('operation_id' => $op['id']));
            $a->store->insert('settings', $scope['id'], 'attempt_reference:' . $reference, array('attempt_id' => $attempt['id']), 'index');
            $a->job('attempt', $scope['id'], $attempt['id']);
            $r = $a->operations->send($op, $scope, $a->provider($scope));
            return (new Checkout($a))->presentation((new Checkout($a))->accept($attempt, $r))['checkout_url'];
        } finally {
            $a->store->release($lock, $lease);
        }
    }

    public function capture($invoiceId, $methodId)
    {
        $a = $this->app;
        $scope = $a->settings->activeScope();
        $lock = 'invoice:' . (int)$invoiceId;
        $lease = $a->store->acquire($lock);
        try {
            $invoice = $a->bridge->invoice($invoiceId);
            $a->ready($scope, $invoice, true);
            $a->settings->cardMethods($invoice['currency']);
            if ($invoice['gateway'] !== 'bachs_card' || !$a->settings->get('saved_methods', false)) {
                throw new Failure('capture_not_enabled');
            }
            $method = $this->owned($methodId, $scope['id'], $invoice['client_id']);
            if ($method['data']['currency'] !== $invoice['currency']) {
                throw new Failure('saved_card_currency_gate');
            }
            foreach ($a->store->unfinishedAttempts($invoiceId) as $existing) {
                if (!in_array($existing['state'], array('failed','expired_verified','cancelled_verified','posted'), true)) {
                    $a->job('attempt', $existing['scope'], $existing['id']);
                    return array('status' => 'pending', 'rawdata' => array('attempt_id' => $existing['id']));
                }
            }
            $reference = 'whmcs_' . Ids::random();
            $attempt = $a->store->insert('attempts', $scope['id'], $reference, array('reference' => $reference,
                'invoice' => $invoice, 'snapshot' => $a->snapshot($invoice), 'amount' => Money::positive($invoice['balance']),
                'currency' => $invoice['currency'], 'customer_id' => $method['data']['customer_id'], 'purpose' => 'capture',
                'gateway' => 'bachs_card', 'method_id' => $methodId, 'accounting_owner' => 'module'), 'creating', array('invoice_id' => (int)$invoiceId, 'client_id' => (int)$invoice['client_id']));
            $a->store->insert('settings', $scope['id'], 'attempt_reference:' . $reference, array('attempt_id' => $attempt['id']), 'index');
            $op = $a->operations->prepare($scope, 'capture:' . $attempt['id'], 'POST', '/v1/charges', array('customer' => $method['data']['customer_id'],
                'payment_method' => $a->settings->secrets->open($method['data']['token']), 'amount' => $attempt['data']['amount'], 'currency' => $invoice['currency'],
                'reference' => $reference, 'description' => 'WHMCS invoice ' . (int)$invoiceId,
                'metadata' => array('integration' => 'whmcs', 'attempt_id' => $attempt['id'])));
            $a->store->update('attempts', $attempt['id'], array('operation_id' => $op['id']));
            $a->job('attempt', $scope['id'], $attempt['id']);
            $this->owned($methodId, $scope['id'], $invoice['client_id']);
            try {
                $response = $a->operations->send($op, $scope, $a->provider($scope));
            } catch (\Throwable $e) {
                $a->store->update('attempts', $attempt['id'], array(), 'outcome_unknown');
                throw $e;
            }
            if (!isset($response['payment_id'])) {
                throw new Failure('capture_contract_mismatch');
            }
            $a->store->update('attempts', $attempt['id'], array('payment_id' => Ids::opaque($response['payment_id'])), 'pending');
            // Consistently asynchronous: WHMCS never receives a success that could double-post.
            return array('status' => 'pending', 'rawdata' => array('attempt_id' => $attempt['id']));
        } finally {
            $a->store->release($lock, $lease);
        }
    }
}
