<?php

namespace Bachs\Whmcs\Application;

use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Domain\Ids;
use Bachs\Whmcs\Domain\Money;
use Bachs\Whmcs\Infrastructure\Bachs\Urls;

/** Creates one checkout for a fresh invoice and stores enough state to resume it. */
class Checkout
{
    private $app;

    public function __construct(Engine $app)
    {
        $this->app = $app;
    }

    public function start($invoiceId, $clientId, $save = false, $actor = null, $gateway = 'bachs')
    {
        $a = $this->app;
        $scope = $a->settings->activeScope();
        $lock = 'invoice:' . (int)$invoiceId;
        $lease = $a->store->acquire($lock);
        try {
            $invoice = $a->bridge->invoice($invoiceId);
            if ((int)$invoice['client_id'] !== (int)$clientId) {
                throw new Failure('invoice_access_denied');
            }
            if ($invoice['gateway'] !== $gateway) {
                throw new Failure('invoice_gateway_changed');
            }
            $a->ready($scope, $invoice);
            if ($gateway === 'bachs_card') {
                $a->assertCardBilling();
                if (!$save) {
                    throw new Failure('automatic_payment_consent_required');
                }
            }
            $methods = $a->settings->checkoutMethods($invoice['currency'], $save);
            $snapshot = $a->snapshot($invoice);
            foreach ($a->store->unfinishedAttempts($invoiceId) as $attempt) {
                if (!in_array($attempt['state'], array('failed', 'expired_verified', 'posted', 'cancelled_verified'), true)) {
                    if ($attempt['scope'] !== $scope['id'] || $attempt['data']['snapshot'] !== $snapshot) {
                        throw new Failure('existing_attempt_requires_reconciliation');
                    }
                    if ($attempt['state'] === 'open' && $attempt['data']['expires_at'] > $a->store->clock->now() && $attempt['data']['save'] === (bool)$save) {
                        return $this->presentation($attempt);
                    }
                    $a->job('attempt', $attempt['scope'], $attempt['id']);
                    throw new Failure('existing_attempt_requires_reconciliation');
                }
            }
            if ($save && (!$a->settings->get('saved_methods', false) || !in_array($invoice['currency'], array('USD', 'NGN'), true))) {
                throw new Failure('saving_not_enabled');
            }
            $provider = $a->provider($scope);
            $client = $a->bridge->customer($clientId);
            $customerId = $a->customers->resolve($scope, $client, $provider);
            $local = Ids::random();
            $state = Ids::random();
            $amount = Money::positive($invoice['balance'], $a->settings->precision($invoice['currency']));
            $minutes = max(1, min(1440, (int)$a->settings->get('expiry_minutes', 60)));
            $allocationPolicy = 'same_currency';
            if (!$save && $invoice['currency'] === 'USD' && $a->settings->get('adaptive_allocation', true)) {
                $allocationPolicy = 'verified_checkout_base_v1';
            }
            $attempt = $a->store->insert(
                'attempts',
                $scope['id'],
                $local,
                array('reference' => 'whmcs_' . $local,
                'invoice' => $invoice, 'snapshot' => $snapshot, 'amount' => $amount, 'currency' => $invoice['currency'],
                'purpose' => 'invoice', 'gateway' => $gateway, 'customer_id' => $customerId, 'save' => (bool)$save,
                'allocation_policy' => $allocationPolicy, 'allowed_methods' => $methods,
                'state_hash' => hash('sha256', $state), 'return_state' => $a->settings->secrets->seal($state),
                'expires_at' => $a->store->clock->now() + $minutes * 60, 'accounting_owner' => 'module'),
                'creating',
                array('invoice_id' => (int)$invoiceId, 'client_id' => (int)$clientId)
            );
            $a->store->insert('settings', $scope['id'], 'attempt_reference:' . $attempt['data']['reference'], array('attempt_id' => $attempt['id']), 'index');
            if ($save) {
                $consent = $a->store->insert('consents', $scope['id'], $attempt['id'], array('actor' => $actor,
                    'attempt_id' => $attempt['id'], 'customer_id' => $customerId, 'version' => '1',
                    'text' => self::consentText(), 'purpose' => 'whmcs_invoice_renewals', 'billing_authority' => 'whmcs'), 'active', array('client_id' => (int)$clientId));
                $attempt = $a->store->update('attempts', $attempt['id'], array('consent_id' => $consent['id']));
            }
            // PATH_INFO keeps state intact even if a provider appends a fresh ?checkout_id query.
            $return = Urls::system($a->bridge->systemUrl()) . '/modules/gateways/bachs/return.php/' . $attempt['id'] . '/' . $state;
            $body = array('pricing' => array('amount' => $amount, 'currency' => $invoice['currency']),
                'customer' => array('customer_id' => $customerId), 'reference' => $attempt['data']['reference'],
                'success_url' => $return, 'cancel_url' => $return . '?cancel=1',
                'metadata' => array('integration' => 'whmcs', 'installation_id' => $scope['data']['installation'], 'attempt_id' => $attempt['id']),
                'expires_in_minutes' => $minutes, 'payment_method_types' => $methods);
            if ($allocationPolicy === 'same_currency') {
                $body['billing_currency'] = $invoice['currency'];
            }
            if ($save) {
                $body['save_payment_method'] = true;
            }
            $op = $a->operations->prepare($scope, 'checkout:' . $attempt['id'], 'POST', '/v1/checkout-sessions', $body);
            $a->store->update('attempts', $attempt['id'], array('operation_id' => $op['id']));
            $a->job('attempt', $scope['id'], $attempt['id']);
            try {
                $response = $a->operations->send($op, $scope, $provider);
            } catch (\Throwable $e) {
                $saved = $a->store->get('operations', $op['id']);
                $a->store->update('attempts', $attempt['id'], array(), $saved['state'] === 'rejected' ? 'failed' : 'outcome_unknown', array(), array('creating','outcome_unknown'));
                throw $e;
            }
            return $this->presentation($this->accept($attempt, $response));
        } finally {
            $a->store->release($lock, $lease);
        }
    }

    public function accept($attempt, array $response)
    {
        $a = $this->app;
        $current = $a->store->get('attempts', $attempt['id']);
        if (!$current) {
            throw new Failure('attempt_not_found');
        }
        if (in_array($current['state'], array('posted', 'setup_completed', 'failed', 'expired_verified', 'cancelled_verified', 'review'), true)) {
            return $current;
        }
        $attempt = $current;
        if (!isset($response['checkout_id'], $response['checkout_url']) || !isset($response['status']) || $response['status'] !== 'open') {
            throw new Failure('checkout_contract_mismatch');
        }
        $checkout = Ids::opaque($response['checkout_id']);
        $url = Urls::hosted($response['checkout_url']);
        return $a->store->update(
            'attempts',
            $attempt['id'],
            array('checkout_id' => $checkout,
            'checkout_url' => $a->settings->secrets->seal($url),
            'payment_methods' => isset($response['payment_methods']) && is_array($response['payment_methods']) ? $response['payment_methods'] : array()),
            'open',
            array('provider_hash' => hash('sha256', $checkout)),
            array('creating', 'outcome_unknown', 'open', 'pending')
        );
    }

    public function presentation($attempt)
    {
        return array('attempt_id' => $attempt['id'], 'state' => $attempt['state'],
            'checkout_url' => Urls::hosted($this->app->settings->secrets->open($attempt['data']['checkout_url'])),
            'expires_at' => (int)$attempt['data']['expires_at'], 'mode' => $this->app->settings->get('checkout_mode', 'redirect'));
    }

    public static function consentText()
    {
        return 'Save my card and allow automatic collection of my WHMCS renewal invoices when due. I can turn off automatic payments in Bachs payment settings.';
    }
}
