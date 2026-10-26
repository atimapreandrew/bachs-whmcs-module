<?php

class NativeClientIdentity
{
    public $id = 1;
    public $ownerId = 21;
    public function isOwnedBy($user) { return (int)$user->id === $this->ownerId; }
    public function hasPermission($permission)
    {
        throw new RuntimeException('Client account permission stub must not be called.');
    }
}

class NativePermissionsApi extends \Bachs\Whmcs\Infrastructure\Whmcs\LocalApi
{
    public $calls = array();
    public $response = array('result' => 'success', 'permissions' => array('permission' => array('invoices')));
    public $error;
    public function call($command, array $params = array())
    {
        $this->calls[] = array($command, $params);
        if ($this->error) { throw $this->error; }
        return $this->response;
    }
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

test('WHMCS owners can pay without calling the Client permission stub', function () {
    $api = new NativePermissionsApi();
    $identity = new \Bachs\Whmcs\Infrastructure\Whmcs\Identity($api);
    NativeCurrentUserFixture::$client = new NativeClientIdentity();
    NativeCurrentUserFixture::$user = (object)array('id' => 21);
    same(array('client_id' => 1, 'actor' => 'user:21'), $identity->client(1));
    same(array(), $api->calls);
    fails('client_payment_access_denied', function () use ($identity) { $identity->client(2); });
    NativeCurrentUserFixture::$client = null;
    $_SESSION['uid'] = 1;
    fails('client_login_required', function () use ($identity) { $identity->client(); });
    unset($_SESSION['uid']);
});

test('Invited users require explicit invoice permission for the selected client', function () {
    $api = new NativePermissionsApi();
    $identity = new \Bachs\Whmcs\Infrastructure\Whmcs\Identity($api);
    NativeCurrentUserFixture::$client = new NativeClientIdentity();
    NativeCurrentUserFixture::$user = (object)array('id' => 22);
    foreach (array('invoices, tickets', array('invoices'), array('permission' => array('invoices')), array('invoices' => true)) as $permissions) {
        $api->response['permissions'] = $permissions;
        same(array('client_id' => 1, 'actor' => 'user:22'), $identity->client(1));
    }
    same(array('GetUserPermissions', array('user_id' => 22, 'client_id' => 1)), $api->calls[0]);
    foreach (array('', 'tickets', 'manageinvoices', array('tickets'), array('invoices' => false), array('invoices' => 'false'), array('permission' => array('tickets'))) as $permissions) {
        $api->response['permissions'] = $permissions;
        fails('client_payment_access_denied', function () use ($identity) { $identity->client(); });
    }
    $calls = count($api->calls);
    fails('client_payment_access_denied', function () use ($identity) { $identity->client(2); });
    same($calls, count($api->calls));
    NativeCurrentUserFixture::$client = null;
    NativeCurrentUserFixture::$user = null;
});

test('Missing or unavailable user permissions never grant billing access', function () {
    $api = new NativePermissionsApi();
    $identity = new \Bachs\Whmcs\Infrastructure\Whmcs\Identity($api);
    NativeCurrentUserFixture::$client = new NativeClientIdentity();
    NativeCurrentUserFixture::$user = (object)array('id' => 22);
    foreach (array(array('result' => 'success'), array('result' => 'success', 'permissions' => null), array('permissions' => false)) as $response) {
        $api->response = $response;
        fails('client_permissions_unavailable', function () use ($identity) { $identity->client(); });
    }
    $api->error = new \Bachs\Whmcs\Domain\Failure('whmcs_getuserpermissions_failed');
    fails('client_permissions_unavailable', function () use ($identity) { $identity->client(); });
    NativeCurrentUserFixture::$client = null;
    NativeCurrentUserFixture::$user = null;
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
