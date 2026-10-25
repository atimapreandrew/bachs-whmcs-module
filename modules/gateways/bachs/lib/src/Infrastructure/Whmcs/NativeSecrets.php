<?php

namespace Bachs\Whmcs\Infrastructure\Whmcs;

class NativeSecrets implements \Bachs\Whmcs\Domain\SecretStore
{
    private $api;

    public function __construct(LocalApi $api)
    {
        $this->api = $api;
    }

    public function seal($plaintext)
    {
        $response = $this->api->call('EncryptPassword', array('password2' => $plaintext));
        if (!isset($response['password']) || !is_string($response['password'])) {
            throw new \Bachs\Whmcs\Domain\Failure('native_encryption_failed');
        }
        return 'whmcs:' . $response['password'];
    }

    public function open($ciphertext)
    {
        if (substr($ciphertext, 0, 6) !== 'whmcs:') {
            throw new \Bachs\Whmcs\Domain\Failure('encryption_backend_mismatch');
        }
        $response = $this->api->call('DecryptPassword', array('password2' => substr($ciphertext, 6)));
        if (!isset($response['password']) || !is_string($response['password'])) {
            throw new \Bachs\Whmcs\Domain\Failure('native_decryption_failed');
        }
        return $response['password'];
    }
}
