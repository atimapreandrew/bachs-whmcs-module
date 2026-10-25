<?php

class NativeEncryptionApi extends \Bachs\Whmcs\Infrastructure\Whmcs\LocalApi
{
    public $calls = array();
    public $invalid = false;
    public function call($command, array $params = array(), $username = null)
    {
        $this->calls[] = array($command, $params);
        if ($this->invalid) { return array('result' => 'success'); }
        return array('result' => 'success', 'password' => $command === 'EncryptPassword' ? 'native-ciphertext' : 'sensitive-value');
    }
}

test('Secrets use the native WHMCS encryption and decryption contracts', function () {
    $api = new NativeEncryptionApi();
    $secrets = new \Bachs\Whmcs\Infrastructure\Whmcs\NativeSecrets($api);
    same('whmcs:native-ciphertext', $secrets->seal('sensitive-value'));
    same('sensitive-value', $secrets->open('whmcs:native-ciphertext'));
    same(array('EncryptPassword', array('password2' => 'sensitive-value')), $api->calls[0]);
    same(array('DecryptPassword', array('password2' => 'native-ciphertext')), $api->calls[1]);
});

test('Invalid native encryption responses never become stored credentials', function () {
    $api = new NativeEncryptionApi(); $api->invalid = true;
    $secrets = new \Bachs\Whmcs\Infrastructure\Whmcs\NativeSecrets($api);
    fails('native_encryption_failed', function () use ($secrets) { $secrets->seal('value'); });
    fails('native_decryption_failed', function () use ($secrets) { $secrets->open('whmcs:broken'); });
});

test('Unsupported stored credential formats do not fall back to another encryption backend', function () {
    $api = new NativeEncryptionApi();
    $secrets = new \Bachs\Whmcs\Infrastructure\Whmcs\NativeSecrets($api);
    fails('encryption_backend_mismatch', function () use ($secrets) { $secrets->open('old-format'); });
    same(array(), $api->calls);
});
