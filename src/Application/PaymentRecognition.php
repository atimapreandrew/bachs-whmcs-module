<?php

namespace Bachs\Whmcs\Application;

use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Domain\Ids;
use Bachs\Whmcs\Domain\Money;
use Bachs\Whmcs\Infrastructure\SafeData;

/** Every receipt path converges here. No event/browser status is posting evidence. */
class PaymentRecognition
{
    private $app;

    public function __construct(Engine $app)
    {
        $this->app = $app;
    }

    public function reconcile($attemptId, $paymentHint = null)
    {
        $a = $this->app;
        $attempt = $a->store->get('attempts', $attemptId);
        if (!$attempt) {
            throw new Failure('attempt_not_found');
        }
        if ($attempt['state'] === 'posted') {
            return 'posted';
        }
        $scope = $a->settings->scope($attempt['scope']);
        $provider = $a->provider($scope);
        $d = $attempt['data'];
        if (!isset($d['purpose']) || !in_array($d['purpose'], array('invoice','capture','setup'), true)) {
            return $this->hold($attempt, 'unsupported_payment_purpose');
        }
        $paymentId = isset($d['payment_id']) ? $d['payment_id'] : null;
        $checkout = null;
        if (isset($d['checkout_id'])) {
            $checkout = $provider->request('GET', '/v1/checkout-sessions/' . rawurlencode(Ids::opaque($d['checkout_id'])));
            if (!isset($checkout['checkout_id'], $checkout['reference']) || $checkout['checkout_id'] !== $d['checkout_id'] || $checkout['reference'] !== $d['reference']) {
                return $this->hold($attempt, 'checkout_identity_mismatch');
            }
            if ($d['purpose'] === 'setup') {
                if (!isset($checkout['customer']['id']) || $checkout['customer']['id'] !== $d['customer_id']) {
                    return $this->hold($attempt, 'setup_customer_mismatch');
                }
                if (isset($checkout['status']) && $checkout['status'] === 'completed') {
                    $a->store->update('attempts', $attemptId, array(), 'setup_completed');
                    return 'terminal';
                }
                if (isset($checkout['status']) && in_array($checkout['status'], array('expired','cancelled'), true)) {
                    $a->store->update('attempts', $attemptId, array(), $checkout['status'] === 'expired' ? 'expired_verified' : 'cancelled_verified');
                    return 'terminal';
                }
                return 'pending';
            }
            if (isset($checkout['charge']['payment_id'])) {
                $paymentId = Ids::opaque($checkout['charge']['payment_id']);
            }
            if (!$paymentId) {
                if (isset($checkout['status']) && in_array($checkout['status'], array('expired', 'cancelled'), true)) {
                    $a->store->update('attempts', $attemptId, array(), $checkout['status'] === 'expired' ? 'expired_verified' : 'cancelled_verified');
                    return 'terminal';
                }
                if (isset($checkout['status']) && $checkout['status'] === 'completed') {
                    return $this->hold($attempt, 'completion_without_payment');
                }
                return 'pending';
            }
        } elseif (!$paymentId && $paymentHint) {
            // Response-loss recovery requires a local persisted operation AND authenticated retrieval.
            if (empty($d['operation_id'])) {
                return $this->hold($attempt, 'missing_operation');
            }
            $paymentId = Ids::opaque($paymentHint);
        }
        if (!$paymentId) {
            return 'pending';
        }
        // A webhook charge_id is a trigger, not a substitute for the canonical
        // payment_id retrieved through our saved checkout. Never rewrite prefixes.
        if ($paymentHint && !$checkout && $paymentId !== $paymentHint) {
            return $this->hold($attempt, 'payment_identity_mismatch');
        }
        $payment = $provider->request('GET', '/v1/payments/' . rawurlencode($paymentId));
        if (!isset($payment['payment_id'], $payment['status']) || $payment['payment_id'] !== $paymentId) {
            return $this->hold($attempt, 'payment_identity_mismatch');
        }
        if (!isset($payment['reference']) || $payment['reference'] !== $d['reference']) {
            return $this->hold($attempt, 'payment_reference_mismatch');
        }
        if (!isset($payment['billing_reason']) || $payment['billing_reason'] !== 'purchase') {
            return $this->hold($attempt, 'payment_source_not_invoice');
        }
        if ($checkout && (!isset($payment['checkout_id']) || $payment['checkout_id'] !== $d['checkout_id'])) {
            return $this->hold($attempt, 'checkout_payment_mismatch');
        }
        if ($checkout && (!isset($checkout['customer']['id']) || $checkout['customer']['id'] !== $d['customer_id'])) {
            return $this->hold($attempt, 'payment_customer_mismatch');
        }
        if (isset($payment['customer']['id']) && $payment['customer']['id'] !== $d['customer_id']) {
            return $this->hold($attempt, 'payment_customer_mismatch');
        }
        $virtual = isset($payment['payment_method_details']['bank_transfer']['virtual_account']['type']) ? $payment['payment_method_details']['bank_transfer']['virtual_account']['type'] : null;
        if ($virtual === 'permanent') {
            return $this->hold($attempt, 'permanent_deposit_not_invoice');
        }
        if (in_array($payment['status'], array('created','processing','open','incomplete'), true)) {
            return 'pending';
        }
        if (in_array($payment['status'], array('failed','expired','cancelled'), true)) {
            // Only documented terminal REST states, never a collection.failed event alone.
            $a->store->update('attempts', $attemptId, array('payment_id' => $paymentId), 'failed');
            return 'terminal';
        }
        $receipt = $a->store->insert('payments', $scope['id'], $paymentId, array('payment_id' => $paymentId,
            'attempt_id' => $attemptId, 'transaction_id' => Ids::transaction($scope['id'], $paymentId),
            'gateway' => $d['gateway'], 'verification' => SafeData::redact($payment), 'amount' => $d['amount'],
            'currency' => $d['currency'], 'method' => isset($payment['payment_method']) ? $payment['payment_method'] : '',
            'accounting_owner' => 'module'), 'verified', array('invoice_id' => (int)$attempt['invoice_id'], 'client_id' => (int)$attempt['client_id'], 'provider_hash' => hash('sha256', $paymentId)));
        if ($receipt['data']['attempt_id'] !== $attemptId) {
            return $this->hold($attempt, 'payment_already_claimed');
        }
        if ($receipt['state'] === 'posted') {
            $a->store->update('attempts', $attemptId, array(), 'posted');
            return 'posted';
        }
        if ($payment['status'] !== 'succeeded') {
            return $this->hold($attempt, 'payment_' . (preg_match('/^[a-z_]{1,40}$/D', $payment['status']) ? $payment['status'] : 'unknown'), $receipt);
        }
        try {
            $precision = $a->settings->invoicePrecision($d['currency']);
            if (!isset($payment['currency'], $payment['amount'], $payment['amount_paid'])) {
                return $this->hold($attempt, 'collection_currency_mismatch', $receipt);
            }
            $converted = $payment['currency'] !== $d['currency'];
            if (isset($d['allowed_methods'])) {
                $corridor = isset($payment['payment_method']) ? $payment['payment_method'] : '';
                if ($corridor === 'CARD') {
                    $corridor = $payment['currency'] . '_CARD';
                }
                if (!in_array($corridor, $d['allowed_methods'], true)) {
                    return $this->hold($attempt, 'collection_method_mismatch', $receipt);
                }
            }
            $collectionPrecision = $precision;
            if ($converted) {
                if (!$checkout || $d['currency'] !== 'USD' || !isset($d['allocation_policy']) || $d['allocation_policy'] !== 'verified_checkout_base_v1') {
                    return $this->hold($attempt, 'collection_currency_mismatch', $receipt);
                }
                $collectionPrecision = $a->settings->collectionPrecision($payment['currency']);
                if (!isset($payment['amount_remaining']) || Money::compare($payment['amount_remaining'], '0', $collectionPrecision) !== 0) {
                    return $this->hold($attempt, 'converted_receipt_incomplete', $receipt);
                }
            }
            $gross = Money::positive($payment['amount'], $collectionPrecision);
            $paid = Money::positive($payment['amount_paid'], $collectionPrecision);
            if (Money::compare($paid, $gross, $collectionPrecision) !== 0) {
                return $this->hold($attempt, 'actual_amount_mismatch', $receipt);
            }
            $expectedGross = $d['amount'];
            $fee = '0.00';
            if (!$converted && isset($payment['fee_paid_by']) && $payment['fee_paid_by'] === 'customer') {
                if (!isset($payment['fees']['amount'], $payment['fees']['currency']) || $payment['fees']['currency'] !== $d['currency']) {
                    return $this->hold($attempt, 'fee_currency_unproven', $receipt);
                }
                $expectedGross = Money::add($d['amount'], $payment['fees']['amount'], $precision);
            } elseif (!$converted && isset($payment['fees']['currency'], $payment['fees']['amount']) && $payment['fees']['currency'] === $d['currency']) {
                $fee = Money::normalize($payment['fees']['amount'], $precision);
            }
            if (!$converted && Money::compare($gross, $expectedGross, $precision) !== 0) {
                return $this->hold($attempt, 'invoice_amount_mismatch', $receipt);
            }
            if ($checkout && (!isset($checkout['amount'], $checkout['currency']) || $checkout['currency'] !== $d['currency'] || Money::compare($checkout['amount'], $d['amount'], $precision) !== 0)) {
                return $this->hold($attempt, 'checkout_base_price_mismatch', $receipt);
            }
        } catch (Failure $e) {
            return $this->hold($attempt, $e->reason, $receipt);
        }
        $lockName = 'invoice:' . $attempt['invoice_id'];
        $lease = $a->store->acquire($lockName);
        $nativeLock = null;
        try {
            // Adapter adds a connection-bound MySQL advisory lock around native posting.
            $nativeLock = $a->bridge->postingLock((int)$attempt['invoice_id']);
            $native = $a->bridge->transaction($receipt['data']['transaction_id']);
            if ($native) {
                $this->validateNative($native, $receipt, $attempt);
                return $this->posted($attempt, $receipt, $native);
            }
            $invoice = $a->bridge->invoice($attempt['invoice_id']);
            if ($a->snapshot($invoice) !== $d['snapshot']) {
                return $this->hold($attempt, 'invoice_changed_during_payment', $receipt);
            }
            try {
                $a->settings->assertEnvironment($scope['data']['environment']);
            } catch (Failure $error) {
                return $this->hold($attempt, $error->reason, $receipt);
            }
            $a->store->update('payments', $receipt['id'], array('fee' => $fee, 'collection_amount' => $paid, 'collection_currency' => $payment['currency']), 'posting');
            $a->bridge->post($attempt['invoice_id'], $receipt['data']['transaction_id'], Money::positive($d['amount'], $precision), $fee, $d['gateway']);
            $native = $a->bridge->transaction($receipt['data']['transaction_id']);
            if (!$native) {
                throw new Failure('native_payment_not_found_after_post');
            }
            $this->validateNative($native, $receipt, $attempt);
            return $this->posted($attempt, $receipt, $native);
        } finally {
            if ($nativeLock !== null) {
                $a->bridge->releasePostingLock($nativeLock);
            }
            $a->store->release($lockName, $lease);
        }
    }

    private function validateNative($native, $receipt, $attempt)
    {
        if (
            (int)$native['invoiceid'] !== (int)$attempt['invoice_id'] || (int)$native['userid'] !== (int)$attempt['client_id'] ||
            $native['gateway'] !== $attempt['data']['gateway'] || Money::compare($native['amountin'], $attempt['data']['amount']) !== 0 || Money::compare($native['amountout'], '0') !== 0
        ) {
            $this->hold($attempt, 'native_transaction_conflict', $receipt);
            throw new Failure('native_transaction_conflict');
        }
    }

    private function posted($attempt, $receipt, $native)
    {
        $a = $this->app;
        $a->store->insert('allocations', $receipt['scope'], $receipt['id'], array('payment_id' => $receipt['id'], 'amount' => $attempt['data']['amount'],
            'currency' => $attempt['data']['currency'], 'transaction_id' => $receipt['data']['transaction_id'], 'native_id' => $native['id']), 'posted', array('invoice_id' => (int)$attempt['invoice_id'], 'client_id' => (int)$attempt['client_id']));
        $a->store->update('payments', $receipt['id'], array('native_id' => $native['id']), 'posted');
        $a->store->update('attempts', $attempt['id'], array('payment_id' => $receipt['data']['payment_id']), 'posted');
        return 'posted';
    }

    private function hold($attempt, $reason, $receipt = null)
    {
        $a = $this->app;
        $a->store->exception($attempt['scope'], $reason, $attempt['id'], array('payment_id' => $receipt ? $receipt['data']['payment_id'] : null));
        if ($attempt['state'] !== 'posted') {
            $a->store->update('attempts', $attempt['id'], array('reason_code' => $reason), 'review');
        }
        if ($receipt && $receipt['state'] !== 'posted') {
            $a->store->update('payments', $receipt['id'], array('reason_code' => $reason), 'review');
        }
        return 'review';
    }
}
