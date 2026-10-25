<?php

namespace Bachs\Whmcs\Application;

use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Domain\Ids;
use Bachs\Whmcs\Domain\Money;
use Bachs\Whmcs\Infrastructure\Bachs\ApiFailure;
use Bachs\Whmcs\Infrastructure\SafeData;

/** Tracks provider refunds separately from the matching WHMCS accounting entry. */
class Refunds
{
    private $app;

    public function __construct(Engine $app)
    {
        $this->app = $app;
    }

    public function request($paymentId, $amount, $reason, $actor, $address = '', $network = '')
    {
        $a = $this->app;
        $payment = $a->store->get('payments', $paymentId);
        if (!$payment || $payment['state'] !== 'posted') {
            throw new Failure('posted_payment_required');
        }
        if (!is_string($reason) || strlen(trim($reason)) < 3 || strlen($reason) > 500) {
            throw new Failure('refund_reason_required');
        }
        $scope = $a->settings->scope($payment['scope']);
        $provider = $a->provider($scope);
        $a->bridge->assertAcceptance('refunds', $scope['data']['environment']);
        $lock = 'refund:' . $paymentId;
        $lease = $a->store->acquire($lock);
        try {
            $original = $provider->request('GET', '/v1/payments/' . rawurlencode($payment['data']['payment_id']));
            if (!isset($original['payment_id']) || $original['payment_id'] !== $payment['data']['payment_id']) {
                throw new Failure('refund_payment_mismatch');
            }
            $owner = $a->store->find('settings', $scope['id'], 'refund_owner:' . $paymentId);
            if ($owner) {
                $existing = $a->store->get('refunds', $owner['data']['refund_id']);
                if (!$existing) {
                    throw new Failure('refund_ownership_record_corrupt');
                }
                if ($existing['state'] !== 'provider_failed') {
                    if (!isset($existing['data']['amount']) || Money::compare($amount, $existing['data']['amount']) !== 0) {
                        throw new Failure('existing_refund_amount_conflict');
                    }
                    return $existing;
                }
            }
            foreach ($a->store->listing('refunds', array('scope' => $scope['id'], 'invoice_id' => (int)$payment['invoice_id']), 500) as $existing) {
                if ($existing['data']['payment_id'] === $paymentId && $existing['state'] !== 'provider_failed') {
                    if (!isset($existing['data']['amount']) || Money::compare($amount, $existing['data']['amount']) !== 0) {
                        throw new Failure('existing_refund_amount_conflict');
                    }
                    return $existing;
                }
            }
            $external = $this->byCharge($scope, $payment['data']['payment_id']);
            if ($external && isset($external['status']) && $external['status'] !== 'failed') {
                return $this->import($scope, $payment, $external);
            }
            if (!isset($original['is_refundable']) || $original['is_refundable'] !== true) {
                throw new Failure('payment_not_refundable');
            }
            if (!isset($original['payment_method']) || $original['payment_method'] === 'NGN_BANK_TRANSFER') {
                throw new Failure('refund_rail_not_supported');
            }
            $amount = Money::positive($amount);
            if (Money::compare($amount, $payment['data']['amount']) > 0) {
                throw new Failure('refund_exceeds_allocation');
            }
            $full = Money::compare($amount, $payment['data']['amount']) === 0;
            $settlementCurrency = isset($payment['data']['settlement_currency']) ? $payment['data']['settlement_currency'] : null;
            if (!$full && $settlementCurrency !== $payment['data']['currency']) {
                throw new Failure('partial_refund_currency_unproven');
            }
            if (!$full && isset($original['fee_paid_by']) && $original['fee_paid_by'] === 'customer') {
                throw new Failure('partial_customer_fee_mapping_gate');
            }
            $crypto = $original['payment_method'] === 'CRYPTO' || preg_match('/^(USDT|USDC|BNB|ETH|SOL)_/', $original['payment_method']);
            if ($crypto) {
                // Bind an explicitly confirmed address to the exact asset/network returned by the provider.
                if ($network !== $original['currency'] || !preg_match('/^[A-Za-z0-9_:.-]{20,255}$/D', $address)) {
                    throw new Failure('verified_crypto_address_required');
                }
            }
            $requestId = Ids::random();
            $refund = $a->store->insert(
                'refunds',
                $scope['id'],
                $requestId,
                array('payment_id' => $paymentId,
                'charge_id' => $payment['data']['payment_id'], 'amount' => $amount, 'currency' => $payment['data']['currency'],
                'settlement_currency' => $settlementCurrency, 'full' => $full, 'reference' => 'wr_' . $requestId,
                'reason' => $a->settings->secrets->seal(trim($reason)), 'actor' => $actor, 'accounting_owner' => 'module',
                'provider_state' => 'requested', 'accounting_state' => 'not_started'),
                'requested',
                array('invoice_id' => (int)$payment['invoice_id'], 'client_id' => (int)$payment['client_id'])
            );
            $a->store->put('settings', $scope['id'], 'refund_owner:' . $paymentId, array('refund_id' => $refund['id']), 'refund_owner');
            $identity = 'refund:' . $refund['id'];
            $body = array('charge_id' => $payment['data']['payment_id'], 'reference' => $refund['data']['reference'],
                'fee_bearer' => 'org', 'reason' => trim($reason), 'idempotency_key' => Operations::key($scope['id'], $identity));
            if (!$full) {
                $body['amount'] = $amount;
            }
            if ($crypto) {
                $body['refund_address'] = $address;
            }
            $op = $a->operations->prepare($scope, $identity, 'POST', '/v1/refunds', $body);
            $a->store->update('refunds', $refund['id'], array('operation_id' => $op['id']), 'submitting');
            $a->job('refund', $scope['id'], $refund['id']);
            $a->store->audit($actor, 'refund_requested', $scope['id'], $refund['id'], array('amount' => $amount, 'currency' => $payment['data']['currency']));
            try {
                $response = $a->operations->send($op, $scope, $provider);
                return $this->accept($refund, $response);
            } catch (\Exception $e) {
                $a->store->update('refunds', $refund['id'], array('provider_state' => 'outcome_unknown'), 'outcome_unknown');
                throw $e;
            }
        } finally {
            $a->store->release($lock, $lease);
        }
    }

    public function byCharge($scope, $charge)
    {
        try {
            return $this->app->provider($scope)->request('GET', '/v1/refunds/by-charge/' . rawurlencode(Ids::opaque($charge)));
        } catch (ApiFailure $e) {
            if ($e->httpStatus === 404) {
                return null;
            } throw $e;
        }
    }

    public function accept($refund, $resource)
    {
        $a = $this->app;
        $d = $refund['data'];
        if (!isset($resource['refund_id'], $resource['charge_id'], $resource['status']) || $resource['charge_id'] !== $d['charge_id']) {
            throw new Failure('refund_identity_mismatch');
        }
        if (empty($d['external']) && (!isset($resource['reference']) || $resource['reference'] !== $d['reference'])) {
            throw new Failure('refund_reference_mismatch');
        }
        if (!$d['full'] && (!isset($resource['requested_amount']) || Money::compare($resource['requested_amount'], $d['amount']) !== 0)) {
            throw new Failure('refund_amount_conflict');
        }
        $claim = $a->store->insert('settings', $refund['scope'], 'refund_identity:' . $resource['refund_id'], array('refund_id' => $refund['id']), 'refund_identity');
        if ($claim['data']['refund_id'] !== $refund['id']) {
            $canonical = $a->store->get('refunds', $claim['data']['refund_id']);
            if (!$canonical || $canonical['data']['charge_id'] !== $d['charge_id']) {
                throw new Failure('refund_identity_collision');
            }
            $a->store->update('refunds', $refund['id'], array('canonical_refund' => $canonical['id']), 'duplicate', array('provider_hash' => ''));
            $a->store->put('settings', $refund['scope'], 'refund_owner:' . $d['payment_id'], array('refund_id' => $canonical['id']), 'refund_owner');
            return $canonical;
        }
        $state = $resource['status'] === 'success' ? 'accounting_pending' : ($resource['status'] === 'failed' ? 'provider_failed' : ($resource['status'] === 'processing' ? 'processing' : 'review'));
        if ($refund['state'] === 'completed') {
            return $refund;
        }
        $refund = $a->store->update('refunds', $refund['id'], array('refund_id' => Ids::opaque($resource['refund_id']),
            'provider_state' => $resource['status'], 'provider' => SafeData::redact($resource),
            'accounting_state' => $state === 'accounting_pending' ? 'pending' : 'not_started'), $state, array('provider_hash' => hash('sha256', $resource['refund_id'])));
        if ($state === 'accounting_pending' && isset($refund['data']['amount'])) {
            $this->finalize($refund);
        }
        return $a->store->get('refunds', $refund['id']);
    }

    public function reconcile($refundId)
    {
        $a = $this->app;
        $refund = $a->store->get('refunds', $refundId);
        if (!$refund) {
            throw new Failure('refund_not_found');
        }
        if (in_array($refund['state'], array('completed','provider_failed','duplicate'), true)) {
            return 'terminal';
        }
        $scope = $a->settings->scope($refund['scope']);
        $resource = isset($refund['data']['refund_id']) ? $a->provider($scope)->request('GET', '/v1/refunds/' . rawurlencode($refund['data']['refund_id'])) : $this->byCharge($scope, $refund['data']['charge_id']);
        if (!$resource) {
            $a->store->exception($scope['id'], 'refund_outcome_unknown', $refundId);
            return 'pending';
        }
        $result = $this->accept($refund, $resource);
        return in_array($result['state'], array('completed','provider_failed'), true) ? 'terminal' : 'pending';
    }

    private function finalize($refund)
    {
        $a = $this->app;
        $payment = $a->store->get('payments', $refund['data']['payment_id']);
        if (!$payment) {
            throw new Failure('refund_original_unmapped');
        }
        $native = $a->bridge->finalizeRefund($refund, $payment);
        if ($native) {
            $a->store->update('refunds', $refund['id'], array('native_id' => $native['id'], 'accounting_state' => 'completed'), 'completed');
        } else {
            $a->store->exception($refund['scope'], 'manual_refund_accounting', $refund['id'], array('amount' => $refund['data']['amount'],
                'currency' => $refund['data']['currency'], 'invoice_id' => (int)$refund['invoice_id'], 'transaction_id' => Ids::transaction($refund['scope'], $refund['data']['refund_id'], 'refund')));
        }
    }

    public function import($scope, $payment, $resource)
    {
        if (!isset($resource['refund_id'])) {
            throw new Failure('refund_contract_mismatch');
        }
        $existing = $this->app->store->byProvider('refunds', $scope['id'], $resource['refund_id']);
        if ($existing) {
            return $this->accept($existing, $resource);
        }
        $owner = $this->app->store->find('settings', $scope['id'], 'refund_owner:' . $payment['id']);
        if ($owner) {
            $local = $this->app->store->get('refunds', $owner['data']['refund_id']);
            if ($local && isset($resource['reference']) && $resource['reference'] === $local['data']['reference']) {
                return $this->accept($local, $resource);
            }
        }
        $refund = $this->app->store->insert(
            'refunds',
            $scope['id'],
            'external:' . $resource['refund_id'],
            array('payment_id' => $payment['id'],
            'charge_id' => $payment['data']['payment_id'], 'full' => true, 'external' => true, 'amount' => null,
            'currency' => $payment['data']['currency'], 'reference' => isset($resource['reference']) ? $resource['reference'] : '',
            'accounting_owner' => 'manual_review', 'provider_state' => 'unknown', 'accounting_state' => 'pending'),
            'processing',
            array('invoice_id' => (int)$payment['invoice_id'], 'client_id' => (int)$payment['client_id'])
        );
        $this->app->store->exception($scope['id'], 'external_refund_allocation_review', $refund['id']);
        return $this->accept($refund, $resource);
    }

    public function mapAccounting($refundId, $amount, $evidence, $actor)
    {
        $a = $this->app;
        $refund = $a->store->get('refunds', $refundId);
        if (!$refund || empty($refund['data']['external']) || $refund['data']['provider_state'] !== 'success') {
            throw new Failure('external_successful_refund_required');
        }
        $payment = $a->store->get('payments', $refund['data']['payment_id']);
        $amount = Money::positive($amount);
        if (!$payment || Money::compare($amount, $payment['data']['amount']) > 0 || strlen(trim($evidence)) < 20) {
            throw new Failure('verified_refund_mapping_required');
        }
        $a->store->update('refunds', $refundId, array('amount' => $amount, 'mapping_evidence' => $a->settings->secrets->seal($evidence), 'mapped_by' => $actor), 'accounting_pending');
        $a->store->audit($actor, 'external_refund_accounting_mapped', $refund['scope'], $refundId, array('amount' => $amount, 'currency' => $payment['data']['currency']));
        $a->job('refund', $refund['scope'], $refundId);
    }

    public function event($scope, $data)
    {
        $a = $this->app;
        if (empty($data['refund_id'])) {
            throw new Failure('refund_event_missing_id');
        }
        $resource = $a->provider($scope)->request('GET', '/v1/refunds/' . rawurlencode(Ids::opaque($data['refund_id'])));
        $local = $a->store->byProvider('refunds', $scope['id'], $data['refund_id']);
        if ($local) {
            $this->accept($local, $resource);
            return;
        }
        $payment = isset($resource['charge_id']) ? $a->store->byProvider('payments', $scope['id'], $resource['charge_id']) : null;
        if ($payment) {
            $this->import($scope, $payment, $resource);
        } else {
            $a->store->exception($scope['id'], 'unmapped_refund', $data['refund_id']);
        }
    }
}
