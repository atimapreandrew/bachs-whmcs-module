<?php

namespace Bachs\Whmcs\Infrastructure\Bachs;

use Bachs\Whmcs\Domain\Failure;

class SignatureVerifier
{
    private $clock;

    public function __construct(\Bachs\Whmcs\Domain\Clock $clock)
    {
        $this->clock = $clock;
    }

    public function verify($raw, array $headers, array $secrets)
    {
        if (!is_string($raw)) { throw new Failure('invalid_signature'); }
        if (strlen($raw) > 1048576) {
            throw new Failure('webhook_too_large');
        }
        $headers = array_change_key_case($headers, CASE_LOWER);
        $timestamp = null;
        $signatures = array();
        if (isset($headers['x-bachs-signature-v2'])) {
            if (!is_string($headers['x-bachs-signature-v2']) || strlen($headers['x-bachs-signature-v2']) > 2048) {
                throw new Failure('invalid_signature');
            }
            foreach (explode(',', $headers['x-bachs-signature-v2']) as $part) {
                $pair = explode('=', trim($part), 2);
                if (count($pair) !== 2) {
                    throw new Failure('invalid_signature');
                }
                if ($pair[0] === 't') {
                    if ($timestamp !== null || !preg_match('/^[0-9]{1,10}$/D', $pair[1])) {
                        throw new Failure('invalid_signature');
                    }
                    $timestamp = $pair[1];
                } elseif ($pair[0] === 'v1') {
                    if (!preg_match('/^[a-fA-F0-9]{64}$/D', $pair[1])) {
                        throw new Failure('invalid_signature');
                    }
                    $signatures[] = strtolower($pair[1]);
                } else {
                    throw new Failure('unsupported_signature_scheme');
                }
            }
        }
        if ($timestamp === null || !$signatures || count($signatures) > 8 || abs($this->clock->now() - (int)$timestamp) > 300) {
            throw new Failure('invalid_signature');
        }
        $matched = 0;
        foreach ($secrets as $secret) {
            if (!is_string($secret) || $secret === '') {
                continue;
            }
            $expected = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);
            foreach ($signatures as $signature) {
                $matched |= (int)hash_equals($expected, $signature);
            }
        }
        if (!$matched) {
            throw new Failure('invalid_signature');
        }
        return array('timestamp' => (int)$timestamp, 'scheme' => 'v2');
    }
}
