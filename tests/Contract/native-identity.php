<?php

class NativeClientIdentity
{
    public $id = 1;
    public $canPay = true;
    public function hasPermission($permission) { return $permission === 'invoices' && $this->canPay; }
}

class NativeCurrentUserFixture
{
    public static $client;
    public static $user;
    public function client() { return self::$client; }
    public function user() { return self::$user; }
}

class_alias('NativeCurrentUserFixture', 'WHMCS\Authentication\CurrentUser');

test('WHMCS 8 CurrentUser controls client identity, account access and payment permission', function () {
    $identity = new \Bachs\Whmcs\Infrastructure\Whmcs\Identity(new \Bachs\Whmcs\Infrastructure\Whmcs\LocalApi());
    NativeCurrentUserFixture::$client = new NativeClientIdentity();
    NativeCurrentUserFixture::$user = (object)array('id' => 21);
    same(array('client_id' => 1, 'actor' => 'user:21'), $identity->client(1));
    fails('client_payment_access_denied', function () use ($identity) { $identity->client(2); });
    NativeCurrentUserFixture::$client->canPay = false;
    fails('client_payment_access_denied', function () use ($identity) { $identity->client(); });
    NativeCurrentUserFixture::$client = null;
    $_SESSION['uid'] = 1;
    fails('client_login_required', function () use ($identity) { $identity->client(); });
    unset($_SESSION['uid']);
});
