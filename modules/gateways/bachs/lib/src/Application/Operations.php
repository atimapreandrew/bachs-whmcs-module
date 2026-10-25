<?php

namespace Bachs\Whmcs\Application;

use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Infrastructure\Bachs\ApiFailure;

/** Saves each provider write before sending it and keeps its original request and retry key. */
class Operations
{
    private $store;
    private $secrets;

    public function __construct($store, $secrets)
    {
        $this->store = $store;
        $this->secrets = $secrets;
    }

    public static function key($scopeId, $identity)
    {
        return 'bw_' . hash('sha256', $scopeId . ':' . $identity);
    }

    public function prepare($scope, $identity, $method, $path, $body)
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new Failure('invalid_operation_body');
        }
        $row = $this->store->insert('operations', $scope['id'], $identity, array('method' => $method, 'path' => $path,
            'request' => $this->secrets->seal($json), 'body_hash' => hash('sha256', $json),
            'generation' => $scope['data']['generation'], 'idempotency_key' => self::key($scope['id'], $identity)), 'prepared');
        if ($row['data']['body_hash'] !== hash('sha256', $json) || $row['data']['method'] !== $method || $row['data']['path'] !== $path) {
            throw new Failure('operation_conflict');
        }
        return $row;
    }

    public function send($operation, $scope, $provider)
    {
        $lock = 'operation:' . $operation['id'];
        $token = $this->store->acquire($lock);
        try {
            $operation = $this->store->get('operations', $operation['id']);
            $data = $operation['data'];
            if ($operation['state'] === 'succeeded') {
                return json_decode($this->secrets->open($data['response']), true);
            }
            if (!in_array($operation['state'], array('prepared', 'retryable'), true)) {
                throw new Failure('operation_requires_reconciliation');
            }
            if ($data['generation'] !== $scope['data']['generation']) {
                throw new Failure('credential_rotated_reconcile');
            }
            if ($this->store->clock->now() - $operation['created_at'] > 82800) {
                throw new Failure('idempotency_window_expired');
            }
            if ($operation['next_run'] > $this->store->clock->now()) {
                throw new Failure('provider_backoff');
            }
            $this->store->update('operations', $operation['id'], array(), 'sending');
            try {
                $response = $provider->request($data['method'], $data['path'], json_decode($this->secrets->open($data['request']), true), $data['idempotency_key']);
                $this->store->update('operations', $operation['id'], array('response' => $this->secrets->seal(json_encode($response)),
                    'request_id' => isset($provider->lastRequestId) ? $provider->lastRequestId : ''), 'succeeded');
                return $response;
            } catch (ApiFailure $e) {
                $state = $e->unknown ? 'outcome_unknown' : 'rejected';
                if ($e->reason === 'IDEMPOTENCY_IN_PROGRESS') {
                    $state = 'retryable';
                }
                if ($e->reason === 'IDEMPOTENCY_CONFLICT') {
                    $state = 'conflict';
                }
                $this->store->update(
                    'operations',
                    $operation['id'],
                    array('error_code' => $e->reason, 'request_id' => $e->requestId),
                    $state,
                    array('next_run' => $this->store->clock->now() + $e->retryAfter)
                );
                if ($state !== 'rejected') {
                    $this->store->exception($scope['id'], $state, $operation['id'], array('error_code' => $e->reason));
                }
                throw $e;
            } catch (\Throwable $e) {
                $this->store->update('operations', $operation['id'], array('error_code' => 'write_interrupted'), 'outcome_unknown');
                $this->store->exception($scope['id'], 'outcome_unknown', $operation['id']);
                throw $e;
            }
        } finally {
            $this->store->release($lock, $token);
        }
    }
}
