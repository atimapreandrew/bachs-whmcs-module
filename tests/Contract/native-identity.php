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
    public static $admin;
    public static $authenticatedAdmin = true;
    public function client() { return self::$client; }
    public function user() { return self::$user; }
    public function admin() { return self::$admin; }
    public function isAuthenticatedAdmin() { return self::$authenticatedAdmin; }
}

class NativeAdminIdentity
{
    public $id = 10;
    public $roleId = 2;
    public $disabled = false;
    public $permissions = array('List Transactions');
    public function hasPermission($permission) { return in_array($permission, $this->permissions, true); }
}

class_alias('NativeCurrentUserFixture', 'WHMCS\Authentication\CurrentUser');

test('WHMCS 8 CurrentUser controls client identity, account access and payment permission', function () {
    $identity = new \Bachs\Whmcs\Infrastructure\Whmcs\Identity();
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

test('Native administrator authentication and role permissions protect addon access', function () {
    $identity = new \Bachs\Whmcs\Infrastructure\Whmcs\Identity();
    NativeCurrentUserFixture::$admin = new NativeAdminIdentity();
    same(array('id' => 10, 'role_id' => 2, 'actor' => 'admin:10'), $identity->admin('view_payments'));
    fails('action_permission_denied', function () use ($identity) { $identity->admin('request_refunds'); });
    NativeCurrentUserFixture::$admin->disabled = true;
    fails('admin_access_denied', function () use ($identity) { $identity->admin('view_payments'); });
    NativeCurrentUserFixture::$admin->disabled = false;
    NativeCurrentUserFixture::$authenticatedAdmin = false;
    fails('admin_access_denied', function () use ($identity) { $identity->admin('view_payments'); });
    NativeCurrentUserFixture::$authenticatedAdmin = true;
    NativeCurrentUserFixture::$admin = null;
    fails('admin_access_denied', function () use ($identity) { $identity->admin('view_payments'); });
});
