<?php

namespace Bachs\Whmcs\Infrastructure\Bachs;

use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Domain\Ids;

class Client implements \Bachs\Whmcs\Domain\ProviderClient
{
    private $environment;
    private $key;
    private $account;
    private $transport;
    public $lastRequestId = '';

    public function __construct($environment, $key, $account = '', $transport = null)
    {
        if (
            !in_array($environment, array('sandbox', 'live'), true) || !is_string($key) ||
            !preg_match('/^sk_' . $environment . '_[A-Za-z0-9_-]+$/D', $key)
        ) {
            throw new Failure('credential_environment_mismatch');
        }
        $this->environment = $environment;
        $this->key = $key;
        $this->account = $account === '' ? '' : Ids::opaque($account);
        $this->transport = $transport === null ? new CurlTransport() : $transport;
    }

    public function request($method, $path, $body = null, $idempotencyKey = null)
    {
        if (
            !in_array($method, array('GET','POST','PATCH','PUT','DELETE'), true) ||
            !preg_match('#^/v1/[A-Za-z0-9_/?=&%.:-]+$#D', $path) || strpos($path, '..') !== false
        ) {
            throw new Failure('invalid_api_request');
        }
        $headers = array('Authorization: Bearer ' . $this->key, 'Accept: application/json', 'Content-Type: application/json', 'User-Agent: Bachs-WHMCS/1.0.0');
        if ($this->account !== '') {
            $headers[] = 'X-Account-Id: ' . $this->account;
        }
        if ($method !== 'GET' && !$idempotencyKey) {
            throw new Failure('durable_operation_required');
        }
        if ($idempotencyKey) {
            $headers[] = 'Idempotency-Key: ' . Ids::opaque($idempotencyKey);
        }
        $encoded = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new Failure('json_encoding_failed');
        }
        $origin = $this->environment === 'sandbox' ? 'https://sandbox-api.bachs.io' : 'https://api.bachs.io';
        $result = $this->transport->send($method, $origin . $path, $headers, $encoded);
        return $this->parse($result, $method !== 'GET');
    }

    private function parse($result, $write)
    {
        $status = $result['status'];
        $headers = $result['headers'];
        $requestId = isset($headers['x-request-id']) ? $headers['x-request-id'] : '';
        $this->lastRequestId = $requestId;
        $body = json_decode($result['body'], true, 64, JSON_BIGINT_AS_STRING);
        if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE) {
            throw new ApiFailure('malformed_response', $status, $requestId, 30, $write);
        }
        if ($status < 200 || $status >= 300) {
            $code = isset($body['error_code']) && is_string($body['error_code']) ? $body['error_code'] : 'http_error';
            $retry = isset($headers['retry-after']) ? $headers['retry-after'] : 30;
            if (is_string($retry) && !ctype_digit($retry)) {
                $parsed = strtotime($retry);
                $retry = $parsed ? max(1, $parsed - time()) : 30;
            }
            $confirmedRejection = in_array($status, array(401,403), true) || (in_array($status, array(400,422), true) && $code === 'VALIDATION_ERROR');
            throw new ApiFailure($code, $status, $requestId, $retry, $write && !$confirmedRejection);
        }
        return $body;
    }
}
