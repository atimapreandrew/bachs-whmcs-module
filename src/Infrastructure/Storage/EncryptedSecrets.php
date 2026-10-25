<?php

namespace Bachs\Whmcs\Infrastructure\Storage;

use Bachs\Whmcs\Domain\Failure;

/** Encrypt-then-MAC, with independent subkeys and a key supplied outside the database. */
class EncryptedSecrets implements \Bachs\Whmcs\Domain\SecretStore
{
    private $encryption;
    private $authentication;

    public function __construct($key)
    {
        if (!is_string($key) || strlen($key) < 32) {
            throw new Failure('encryption_key_required');
        }
        $this->encryption = hash_hmac('sha256', 'bachs-encryption-v1', $key, true);
        $this->authentication = hash_hmac('sha256', 'bachs-authentication-v1', $key, true);
    }

    public function seal($plaintext)
    {
        $strong = false;
        $iv = openssl_random_pseudo_bytes(16, $strong);
        if (!$strong) {
            throw new Failure('secure_random_unavailable');
        }
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $this->encryption, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new Failure('encryption_failed');
        }
        $payload = $iv . $ciphertext;
        return 'v1:' . base64_encode(hash_hmac('sha256', $payload, $this->authentication, true) . $payload);
    }

    public function open($ciphertext)
    {
        if (substr($ciphertext, 0, 3) !== 'v1:') {
            throw new Failure('invalid_ciphertext');
        }
        $data = base64_decode(substr($ciphertext, 3), true);
        if ($data === false || strlen($data) < 64) {
            throw new Failure('invalid_ciphertext');
        }
        $mac = substr($data, 0, 32);
        $payload = substr($data, 32);
        if (!hash_equals(hash_hmac('sha256', $payload, $this->authentication, true), $mac)) {
            throw new Failure('invalid_ciphertext');
        }
        $plain = openssl_decrypt(substr($payload, 16), 'aes-256-cbc', $this->encryption, OPENSSL_RAW_DATA, substr($payload, 0, 16));
        if ($plain === false) {
            throw new Failure('invalid_ciphertext');
        }
        return $plain;
    }
}
