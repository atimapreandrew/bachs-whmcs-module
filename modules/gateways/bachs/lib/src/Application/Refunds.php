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
        $a->settings->assertEnvironment($scope['data']['environment']);
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
            if ($external && isset($external['status']) && ($external['status'] !== 'failed' || !$this->failedWithoutSettlement($external))) {
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
            } catch (\Throwable $e) {
                $saved = $a->store->get('operations', $op['id']);
                $state = $saved['state'] === 'rejected' ? 'provider_failed' : ($saved['state'] === 'succeeded' ? 'review' : 'outcome_unknown');
                $a->store->update('refunds', $refund['id'], array('provider_state' => $state, 'error_code' => $e instanceof Failure ? $e->reason : 'refund_interrupted'), $state,
                    array(), array('requested','submitting','outcome_unknown','processing','review'));
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
        $lock = 'refund_record:' . $refund['id'];
        $lease = $this->app->store->acquire($lock);
        try {
            $current = $this->app->store->get('refunds', $refund['id']);
            if (!$current) { throw new Failure('refund_not_found'); }
            return $this->acceptLocked($current, $resource);
        } finally {
            $this->app->store->release($lock, $lease);
        }
    }

    private function acceptLocked($refund, $resource)
    {
        $a = $this->app;
        $d = $refund['data'];
        if (!isset($resource['refund_id'], $resource['charge_id'], $resource['status']) || !is_string($resource['status']) || $resource['charge_id'] !== $d['charge_id']) {
            throw new Failure('refund_identity_mismatch');
        }
        Ids::opaque($resource['refund_id']);
        if (isset($d['refund_id']) && $d['refund_id'] !== $resource['refund_id']) {
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
        if (isset($d['provider_state']) && $d['provider_state'] === 'success' && $resource['status'] !== 'success') {
            $a->store->exception($refund['scope'], 'refund_terminal_state_changed', $refund['id']);
            return $refund;
        }
        if ($resource['status'] === 'failed' && !$this->failedWithoutSettlement($resource)) {
            $a->store->exception($refund['scope'], 'failed_refund_has_settlement', $refund['id']);
            return $a->store->update('refunds', $refund['id'], array('refund_id' => $resource['refund_id'], 'provider_state' => 'failed',
                'provider' => SafeData::redact($resource), 'accounting_state' => 'held', 'error_code' => 'failed_refund_has_settlement'), 'review',
                array('provider_hash' => hash('sha256', $resource['refund_id'])));
        }
        if ($resource['status'] === 'success') {
            try {
                if (!isset($resource['requested_amount'], $resource['refunded_amount'])
                    || Money::compare(Money::positive($resource['requested_amount'], 18), Money::positive($resource['refunded_amount'], 18), 18) !== 0
                    || (empty($d['external']) && isset($resource['fee_bearer']) && $resource['fee_bearer'] !== 'org')) {
                    throw new Failure('refund_settlement_unproven');
                }
            } catch (Failure $error) {
                $a->store->exception($refund['scope'], 'refund_settlement_unproven', $refund['id']);
                return $a->store->update('refunds', $refund['id'], array('refund_id' => $resource['refund_id'], 'provider_state' => 'success',
                    'provider' => SafeData::redact($resource), 'accounting_state' => 'held', 'error_code' => 'refund_settlement_unproven'), 'review',
                    array('provider_hash' => hash('sha256', $resource['refund_id'])));
            }
        }
        $refund = $a->store->update('refunds', $refund['id'], array('refund_id' => Ids::opaque($resource['refund_id']),
            'provider_state' => $resource['status'], 'provider' => SafeData::redact($resource),
            'accounting_state' => $state === 'accounting_pending' ? 'pending' : 'not_started'), $state, array('provider_hash' => hash('sha256', $resource['refund_id'])));
        if ($state === 'accounting_pending' && isset($refund['data']['amount'])) {
            $this->finalize($refund);
        }
        return $a->store->get('refunds', $refund['id']);
    }

    private function failedWithoutSettlement($resource)
    {
        if (!isset($resource['refunded_amount'])) { return true; }
        try {
            return Money::compare($resource['refunded_amount'], '0', 18) === 0;
        } catch (Failure $error) {
            return false;
        }
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
        $lock = 'refund:' . $refund['data']['payment_id'];
        $lease = $a->store->acquire($lock);
        try {
            return $this->reconcileLocked($a->store->get('refunds', $refundId));
        } finally {
            $a->store->release($lock, $lease);
        }
    }

    private function reconcileLocked($refund)
    {
        $a = $this->app;
        $refundId = $refund['id'];
        if (in_array($refund['state'], array('completed','provider_failed','duplicate'), true)) { return 'terminal'; }
        $scope = $a->settings->scope($refund['scope']);
        $operation = !empty($refund['data']['operation_id']) ? $a->store->get('operations', $refund['data']['operation_id']) : null;
        if (!$operation && empty($refund['data']['operation_id'])) {
            $operation = $a->store->find('operations', $refund['scope'], 'refund:' . $refundId);
            if ($operation) {
                $refund = $a->store->update('refunds', $refundId, array('operation_id' => $operation['id']));
            } elseif ($refund['state'] === 'requested') {
                $a->store->update('refunds', $refundId, array('provider_state' => 'not_submitted', 'error_code' => 'preparation_interrupted'), 'provider_failed');
                return 'terminal';
            }
        }
        if (empty($refund['data']['refund_id']) && $operation) {
            if ($operation['state'] === 'rejected') {
                $a->store->update('refunds', $refundId, array('provider_state' => 'rejected', 'accounting_state' => 'not_started'), 'provider_failed');
                return 'terminal';
            }
            if ($operation['state'] === 'succeeded' && isset($operation['data']['response'])) {
                $resource = json_decode($a->settings->secrets->open($operation['data']['response']), true);
                $result = $this->accept($refund, $resource);
                return in_array($result['state'], array('completed','provider_failed'), true) ? 'terminal' : 'pending';
            }
        }
        $resource = isset($refund['data']['refund_id']) ? $a->provider($scope)->request('GET', '/v1/refunds/' . rawurlencode($refund['data']['refund_id'])) : $this->byCharge($scope, $refund['data']['charge_id']);
        if (!$resource) {
            if ($operation && in_array($operation['state'], array('prepared','retryable'), true)) {
                $a->settings->assertEnvironment($scope['data']['environment']);
                $payment = $a->store->get('payments', $refund['data']['payment_id']);
                if (!$payment || $payment['state'] !== 'posted' || $payment['scope'] !== $refund['scope']) {
                    throw new Failure('posted_payment_required');
                }
                $original = $a->provider($scope)->request('GET', '/v1/payments/' . rawurlencode($refund['data']['charge_id']));
                if (!isset($original['payment_id'], $original['is_refundable']) || $original['payment_id'] !== $refund['data']['charge_id'] || $original['is_refundable'] !== true) {
                    throw new Failure('payment_not_refundable');
                }
                try {
                    $resource = $a->operations->send($operation, $scope, $a->provider($scope));
                } catch (\Throwable $error) {
                    if ($a->store->get('operations', $operation['id'])['state'] === 'rejected') {
                        $a->store->update('refunds', $refundId, array('provider_state' => 'rejected'), 'provider_failed');
                        return 'terminal';
                    }
                    throw $error;
                }
                $result = $this->accept($refund, $resource);
                return in_array($result['state'], array('completed','provider_failed'), true) ? 'terminal' : 'pending';
            }
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
        if ($payment['state'] !== 'posted') { throw new Failure('posted_payment_required'); }
        if (!isset($resource['refund_id'], $resource['charge_id']) || $payment['scope'] !== $scope['id'] || $resource['charge_id'] !== $payment['data']['payment_id']) {
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
        $lock = 'refund_record:' . $refundId;
        $lease = $this->app->store->acquire($lock);
        try {
            return $this->mapAccountingLocked($refundId, $amount, $evidence, $actor);
        } finally {
            $this->app->store->release($lock, $lease);
        }
    }

    private function mapAccountingLocked($refundId, $amount, $evidence, $actor)
    {
        $a = $this->app;
        $refund = $a->store->get('refunds', $refundId);
        if (!$refund || empty($refund['data']['external']) || $refund['data']['provider_state'] !== 'success') {
            throw new Failure('external_successful_refund_required');
        }
        $payment = $a->store->get('payments', $refund['data']['payment_id']);
        $amount = Money::positive($amount);
        if ($refund['state'] === 'completed' || $refund['state'] === 'duplicate' ||
            (isset($refund['data']['amount']) && Money::compare($amount, $refund['data']['amount']) !== 0)) {
            throw new Failure('refund_mapping_already_fixed');
        }
        if (!$payment || $payment['state'] !== 'posted' || $payment['scope'] !== $refund['scope'] || Money::compare($amount, $payment['data']['amount']) > 0 || strlen(trim($evidence)) < 20) {
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
