<?php

namespace Bachs\Whmcs\Application;

use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Domain\Ids;
use Bachs\Whmcs\Infrastructure\Bachs\SignatureVerifier;

/** Authenticates notifications, stores them, then queues work without trusting their payment status. */
class Webhooks
{
    private $app;
    public static $events = array('checkout.completed','checkout.expired','collection.succeeded','collection.failed',
        'collection.underpaid','payment_method.saved','refund.created','refund.paid','refund.failed');

    public function __construct(Engine $app)
    {
        $this->app = $app;
    }

    public function ingest($environment, $raw, array $headers)
    {
        $a = $this->app;
        if (!in_array($environment, array('live','sandbox'), true)) {
            throw new Failure('invalid_environment');
        }
        if (strlen($raw) > 1048576) {
            throw new Failure('webhook_too_large');
        }
        $verified = array();
        $verifier = new SignatureVerifier($a->store->clock);
        foreach ($a->store->listing('merchant_scopes', array(), 500) as $scope) {
            if ($scope['data']['environment'] !== $environment) {
                continue;
            }
            $secrets = array($a->settings->secret('signing_' . $scope['id']));
            if ($a->settings->get('previous_expiry_' . $scope['id'], 0) > $a->store->clock->now()) {
                $secrets[] = $a->settings->secret('previous_signing_' . $scope['id']);
            }
            try {
                $meta = $verifier->verify($raw, $headers, $secrets, (bool)$a->settings->get('legacy_signatures', false));
                $verified[] = array($scope, $meta);
            } catch (Failure $e) {
                if (!in_array($e->reason, array('invalid_signature','unsupported_signature_scheme'), true)) {
                    throw $e;
                }
            }
        }
        if (!$verified) {
            throw new Failure('invalid_signature');
        }
        $event = json_decode($raw, true, 48, JSON_BIGINT_AS_STRING);
        if (!is_array($event) || !isset($event['id'], $event['type'], $event['organization_id']) || !is_string($event['type'])) {
            // Authentication succeeded, but attribution cannot be established. Retain a
            // digest-only quarantine under the signing scope; never process its money.
            $signingScope = $verified[0][0];
            $row = $a->store->insert(
                'webhook_events',
                $signingScope['id'],
                'malformed:' . hash('sha256', $raw),
                array('type' => 'malformed', 'body_hash' => hash('sha256', $raw)),
                'quarantined'
            );
            $a->store->exception($signingScope['id'], 'malformed_signed_event', $row['id']);
            return $row['id'];
        }
        Ids::opaque($event['id']);
        Ids::opaque($event['organization_id']);
        $match = null;
        $metadata = null;
        foreach ($verified as $candidate) {
            $scope = $candidate[0];
            $acting = $scope['data']['account'];
            $organization = $acting ? $acting : $scope['data']['organization'];
            if ($event['organization_id'] !== $organization) {
                continue;
            }
            if ($acting && isset($event['account']) && $event['account'] !== $acting) {
                continue;
            }
            if (!$acting && !empty($event['account'])) {
                continue;
            }
            $match = $scope;
            $metadata = $candidate[1];
            break;
        }
        if (!$match) {
            throw new Failure('event_scope_mismatch');
        }
        $data = isset($event['data']) && is_array($event['data']) ? $event['data'] : array();
        $normalized = array();
        foreach (array('checkout_id','charge_id','payment_id','refund_id','invoice_id','reference','status','currency','type') as $k) {
            if (isset($data[$k]) && is_string($data[$k]) && strlen($data[$k]) <= 255) {
                $normalized[$k] = $data[$k];
            }
        }
        if ($event['type'] === 'payment_method.saved') {
            $customerId = isset($data['customer']['customer_id']) ? $data['customer']['customer_id'] : null;
            if (isset($data['payment_method_id']) && is_string($data['payment_method_id']) && $customerId) {
                $normalized['token'] = $a->settings->secrets->seal(Ids::opaque($data['payment_method_id']));
                $normalized['token_hash'] = hash('sha256', $match['id'] . ':' . $data['payment_method_id']);
                $normalized['customer_id'] = Ids::opaque($customerId);
                foreach (array('card_brand','card_last4','card_exp_month','card_exp_year','is_default') as $k) {
                    if (isset($data[$k]) && is_scalar($data[$k])) {
                        $normalized[$k] = substr((string)$data[$k], 0, 32);
                    }
                }
            }
        }
        $state = isset($event['data']) && is_array($event['data']) ? 'received' : 'quarantined';
        $scopeId = $match['id'];
        return $a->store->atomic(function () use ($a, $scopeId, $event, $normalized, $metadata, $raw, $state) {
            $row = $a->store->insert('webhook_events', $scopeId, $event['id'], array('event_id' => $event['id'],
                'type' => substr($event['type'], 0, 100), 'provider_time' => isset($event['created_at']) ? substr((string)$event['created_at'], 0, 40) : null,
                'body_hash' => hash('sha256', $raw), 'signature' => $metadata, 'payload' => $normalized), $state);
            if ($row['data']['body_hash'] !== hash('sha256', $raw)) {
                throw new Failure('event_identity_collision');
            }
            if ($state === 'received') {
                $job = $a->queue->enqueue('event', $scopeId, $row['id']);
                $a->queue->wake($job);
            } else {
                $a->store->exception($scopeId, 'malformed_signed_event', $row['id']);
            }
            return $row['id'];
        });
    }

    public function process($eventId)
    {
        $a = $this->app;
        $event = $a->store->get('webhook_events', $eventId);
        if (!$event) {
            throw new Failure('event_not_found');
        }
        if ($event['state'] === 'processed') {
            return;
        }
        $type = $event['data']['type'];
        $d = $event['data']['payload'];
        $scope = $a->settings->scope($event['scope']);
        if ($type === 'payment_method.saved') {
            (new SavedMethods($a))->saved($event);
        } elseif (strpos($type, 'refund.') === 0) {
            (new Refunds($a))->event($scope, $d);
        } elseif (strpos($type, 'collection.') === 0 || strpos($type, 'checkout.') === 0) {
            $attempt = null;
            if (!empty($d['checkout_id'])) {
                $attempt = $a->store->byProvider('attempts', $scope['id'], $d['checkout_id']);
            }
            if (!$attempt && !empty($d['reference'])) {
                // A reference index is used for scalable lookup; no email/amount matching.
                $ref = $a->store->find('settings', $scope['id'], 'attempt_reference:' . $d['reference']);
                if ($ref) {
                    $attempt = $a->store->get('attempts', $ref['data']['attempt_id']);
                }
                if ($attempt && empty($attempt['data']['checkout_id']) && !empty($d['checkout_id'])) {
                    $resource = $a->provider($scope)->request('GET', '/v1/checkout-sessions/' . rawurlencode(Ids::opaque($d['checkout_id'])));
                    if (!isset($resource['reference'], $resource['metadata']['attempt_id']) || $resource['reference'] !== $attempt['data']['reference'] || $resource['metadata']['attempt_id'] !== $attempt['id']) {
                        throw new Failure('response_loss_correlation_failed');
                    }
                    $attempt = $a->store->update('attempts', $attempt['id'], array('checkout_id' => $d['checkout_id']), 'pending', array('provider_hash' => hash('sha256', $d['checkout_id'])));
                }
            }
            if (!$attempt) {
                $a->store->exception($scope['id'], 'unallocated_collection', $event['id'], array('event_id' => $event['data']['event_id']));
            } else {
                $hint = !empty($d['payment_id']) ? $d['payment_id'] : (!empty($d['charge_id']) ? $d['charge_id'] : null);
                $result = (new PaymentRecognition($a))->reconcile($attempt['id'], $hint);
                if ($result === 'pending') {
                    $a->job('attempt', $scope['id'], $attempt['id']);
                }
            }
        } else {
            $a->store->update('webhook_events', $eventId, array('reason_code' => 'unhandled_authenticated_event'), 'unhandled');
            return;
        }
        $a->store->update('webhook_events', $eventId, array('processed_at' => $a->store->clock->now()), 'processed');
    }
}
