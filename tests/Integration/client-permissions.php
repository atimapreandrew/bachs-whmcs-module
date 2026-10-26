<?php

// Exercise actual native identity resolution in both paths from the reported
// failure. Only the WHMCS CSRF/token helpers are supplied by this test adapter.
class NativePaymentIdentity extends \Bachs\Whmcs\Infrastructure\Whmcs\Identity
{
    public function csrf() {}
    public function token() { return 'native-csrf-fixture'; }
}

foreach (array('owner', 'invited', 'login_as_owner') as $mode) {
    test('Native client identity permits checkout and footer rendering: ' . $mode, function () use ($mode) {
        list($app, $provider, $bridge) = environment();
        $api = new NativePermissionsApi();
        $bridge->identity = new NativePaymentIdentity($api);
        NativeCurrentUserFixture::$client = new NativeClientIdentity();
        NativeCurrentUserFixture::$user = (object)array('id' => $mode === 'invited' ? 22 : 21);
        NativeCurrentUserFixture::$admin = $mode === 'login_as_owner' ? new NativeAdminIdentity() : null;
        $get = $_GET; $post = $_POST; $server = $_SERVER;
        try {
            $_POST = array('invoice_id' => '42', 'gateway' => 'bachs');
            $_SERVER['HTTP_ACCEPT'] = 'application/json';
            $_SERVER['REQUEST_METHOD'] = 'POST';
            ob_start();
            (new \Bachs\Whmcs\Http\ClientController($app))->checkout();
            $response = json_decode(ob_get_clean(), true);
            truth(isset($response['attempt_id']));
            same('open', $response['state']);
            same(1, $provider->writes('/v1/checkout-sessions'));
            $_GET = array('bachs_attempt' => $response['attempt_id']);
            $html = \Bachs\Whmcs\Http\ClientView::render($app, array('filename' => 'viewinvoice'));
            truth(strpos($html, 'data-bachs-return') !== false);
            truth(strpos($html, 'Complete your payment in Bachs.') !== false);
            same($mode === 'invited' ? 2 : 0, count($api->calls));
        } finally {
            $_GET = $get; $_POST = $post; $_SERVER = $server;
            NativeCurrentUserFixture::$client = NativeCurrentUserFixture::$user = NativeCurrentUserFixture::$admin = null;
        }
    });
}

test('An invited user without invoice access cannot create a payment or render client payment details', function () {
    list($app, $provider, $bridge) = environment();
    $api = new NativePermissionsApi();
    $api->response['permissions'] = 'tickets';
    $bridge->identity = new NativePaymentIdentity($api);
    NativeCurrentUserFixture::$client = new NativeClientIdentity();
    NativeCurrentUserFixture::$user = (object)array('id' => 22);
    $post = $_POST;
    try {
        $_POST = array('invoice_id' => '42', 'gateway' => 'bachs');
        fails('client_payment_access_denied', function () use ($app) { (new \Bachs\Whmcs\Http\ClientController($app))->checkout(); });
        fails('client_payment_access_denied', function () use ($app) { \Bachs\Whmcs\Http\ClientView::render($app, array('filename' => 'viewinvoice')); });
        same(0, count($provider->calls));
        same(0, $app->store->count('attempts'));
    } finally {
        $_POST = $post;
        NativeCurrentUserFixture::$client = NativeCurrentUserFixture::$user = null;
    }
});

test('Encoded-core failures log safe method names without arguments or exception text', function () {
    $previousLog = ini_get('error_log');
    $log = tempnam(sys_get_temp_dir(), 'bachs-native-permission-log-');
    ini_set('error_log', $log);
    try {
        try {
            (new NativeClientIdentity())->hasPermission('sk_live_private');
        } catch (RuntimeException $error) {
            $report = \Bachs\Whmcs\Infrastructure\Diagnostics::report($error, 'client_permission_test');
        }
        $entry = file_get_contents($log);
        truth(strpos($entry, $report['reference']) !== false);
        truth(strpos($entry, 'NativeClientIdentity::hasPermission') !== false);
        same(false, strpos($entry, 'sk_live_private') !== false);
        same(false, strpos($entry, 'Client account permission stub') !== false);
        same(false, strpos($entry, dirname(__DIR__)) !== false);
    } finally {
        ini_set('error_log', $previousLog);
        unlink($log);
    }
});
