<?php

use Bachs\Whmcs\Infrastructure\Whmcs\TokenAdapter;

class NativeCardApi
{
    public $rows = array();
    public $queries = array();
    public $creates = 0;
    public $crash = false;
    public $hide = false;

    public function call($command, $params)
    {
        same('GetPayMethods', $command);
        $this->queries[] = $params;
        return array('result' => 'success', 'paymethods' => $this->hide ? array() : $this->rows);
    }
}

// The official helper returns true, not a PayMethod model or its ID.
function createCardPayMethod($clientId, $gateway, $last4, $expiry, $brand, $start, $issue, $remoteToken)
{
    $api = $GLOBALS['bachs_native_card_test_api'];
    ++$api->creates;
    $api->rows[] = array('id' => 701, 'type' => 'RemoteCreditCard', 'gateway_name' => $gateway, 'remote_token' => $remoteToken,
        'card_last_four' => $last4, 'expiry_date' => $expiry, 'card_type' => $brand);
    if ($api->crash) {
        throw new \Bachs\Whmcs\Domain\Failure('simulated_native_helper_crash');
    }
    return true;
}

function nativeCardFixture($app)
{
    $api = new NativeCardApi();
    $GLOBALS['bachs_native_card_test_api'] = $api;
    $method = $app->store->insert('payment_methods', $app->settings->activeScope()['id'], 'synthetic-native-method',
        array('last4' => '4242', 'brand' => 'Visa', 'expiry_month' => 12, 'expiry_year' => 2034), 'active', array('client_id' => 1));
    return array($api, $method, new TokenAdapter($app->settings, '9.1.0', $api));
}

test('Native boolean enrollment maps the Pay Method ID through client-scoped GetPayMethods', function () {
    list($a) = environment();
    list($api, $method, $adapter) = nativeCardFixture($a);
    $adapter->enroll($method);
    $fresh = $a->store->get('payment_methods', $method['id']);
    same('completed', $fresh['data']['native_enrollment']);
    same(701, $fresh['data']['pay_method_id']);
    same(array('clientid' => 1), $api->queries[0]);
    same($method['id'], $api->rows[0]['remote_token']);
    $adapter->enroll($method);
    same(1, $api->creates);
});

test('Interrupted native enrollment finds the existing token without creating a duplicate', function () {
    list($a) = environment();
    list($api, $method, $adapter) = nativeCardFixture($a);
    $api->crash = true;
    fails('simulated_native_helper_crash', function () use ($adapter, $method) { $adapter->enroll($method); });
    $adapter->enroll($method);
    same(1, $api->creates);
    same(701, $a->store->get('payment_methods', $method['id'])['data']['pay_method_id']);
});

test('Unknown native creation remains in review and never retries the helper blindly', function () {
    list($a) = environment();
    list($api, $method, $adapter) = nativeCardFixture($a);
    $api->hide = true;
    fails('native_card_enrollment_needs_review', function () use ($adapter, $method) { $adapter->enroll($method); });
    fails('native_card_enrollment_needs_review', function () use ($adapter, $method) { $adapter->enroll($method); });
    same(1, $api->creates);
    $api->hide = false;
    $adapter->enroll($method);
    same(701, $a->store->get('payment_methods', $method['id'])['data']['pay_method_id']);
});

test('Conflicting native mappings and revoked methods cannot enroll', function () {
    list($a) = environment();
    list($api, $method, $adapter) = nativeCardFixture($a);
    $row = array('id' => 701, 'type' => 'RemoteCreditCard', 'gateway_name' => 'bachs_card', 'remote_token' => $method['id']);
    $api->rows = array($row, array_merge($row, array('id' => 702)));
    fails('native_card_mapping_conflict', function () use ($adapter, $method) { $adapter->enroll($method); });
    same(0, $api->creates);
    $a->store->update('payment_methods', $method['id'], array(), 'revoked');
    fails('saved_method_access_denied', function () use ($adapter, $method) { $adapter->enroll($method); });
    same(0, $api->creates);
});
