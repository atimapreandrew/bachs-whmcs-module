<?php

function add_hook($name, $priority, $callback) { $GLOBALS['bachs_test_hooks'][$name] = $callback; }

function cronStartup($hook, $failure = '')
{
    $root = dirname(dirname(__DIR__));
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Support/cron-startup.php')
        . ' ' . escapeshellarg($hook) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($failure);
    $lines = array(); $status = 0;
    exec($command . ' 2>&1', $lines, $status);
    same(0, $status);
    $result = json_decode(implode("\n", $lines), true);
    if (!is_array($result)) { throw new RuntimeException(implode("\n", $lines)); }
    return $result;
}

test('WHMCS five-minute cron loads Bachs before creating its worker in a fresh process', function () {
    $started = time();
    $result = cronStartup('AfterCronJob');
    same(false, $result['loaded_before']);
    same(true, $result['loaded_after']);
    truth($result['last_worker'] >= $started);
    same(array(), $result['logs']);
    same(0, $result['api_calls']);
    same(1, $result['database_connections']);
});

test('WHMCS daily cleanup can be the first Bachs hook in a fresh process', function () {
    $result = cronStartup('DailyCronJob');
    same(false, $result['loaded_before']);
    same(true, $result['loaded_after']);
    same('', $result['cleanup_cursor']);
    same(0, $result['last_worker']);
    same(array(), $result['logs']);
    same(0, $result['api_calls']);
});

test('Cron startup failure keeps the heartbeat stale and reports a private reference', function () {
    $result = cronStartup('AfterCronJob', 'database_failure');
    same(1, $result['database_connections']);
    same(0, $result['last_worker']);
    same(1, count($result['logs']));
    truth(preg_match('/Bachs \[[a-f0-9]{12}\] cron_worker:/', $result['logs'][0]));
    same(false, strpos(json_encode($result), 'sk_live_private') !== false);
    same(false, strpos(json_encode($result), 'private database') !== false);
});

test('WHMCS client deletion clears every saved token and consent beyond the first page', function () {
    require_once dirname(dirname(__DIR__)) . '/includes/hooks/bachs.php';
    list($a, $p, $w, $scope) = environment();
    $at = beginPayment($a);
    $consent = $a->store->insert('consents', $scope['id'], 'deleted-client-consent', array(), 'active', array('client_id' => 1));
    for ($index = 0; $index < 501; ++$index) {
        $a->store->insert('payment_methods', $scope['id'], 'deleted-method-' . $index,
            array('token' => 'encrypted-fixture', 'consent_id' => $consent['id']), 'active', array('client_id' => 1));
    }
    $runtime = new ReflectionProperty(\Bachs\Whmcs\Infrastructure\Whmcs\Runtime::class, 'app');
    $runtime->setAccessible(true);
    $previous = $runtime->getValue();
    $runtime->setValue(null, $a);
    try {
        call_user_func($GLOBALS['bachs_test_hooks']['ClientDelete'], array('userid' => 1));
        same(0, $a->store->count('payment_methods', 'active'));
        same(501, $a->store->count('payment_methods', 'revoked'));
        same('revoked', $a->store->get('consents', $consent['id'])['state']);
        $retained = $a->store->run('SELECT data FROM mod_bachs_payment_methods')->fetchAll(PDO::FETCH_COLUMN);
        same(0, count(array_filter($retained, function ($data) { return json_decode($data, true)['token'] !== null; })));
        truth($a->store->get('attempts', $at['id']) !== null);
        same(1, $p->writes('/v1/checkout-sessions'));
    } finally { $runtime->setValue(null, $previous); }
});

test('Unexpected client failures use HTTP 500 and a private reference', function () {
    list($a) = environment();
    $server = $_SERVER;
    $previousLog = ini_get('error_log');
    $log = tempnam(sys_get_temp_dir(), 'bachs-client-error-');
    ini_set('error_log', $log);
    try {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        ob_start();
        (new \Bachs\Whmcs\Http\ClientController($a))->fail(new RuntimeException('private SQL password=sk_live_private'));
        $response = json_decode(ob_get_clean(), true);
        same(500, http_response_code());
        truth(preg_match('/^[a-f0-9]{12}$/D', $response['reference']));
        truth(strpos(file_get_contents($log), $response['reference']) !== false);
        same(false, strpos(file_get_contents($log) . json_encode($response), 'sk_live_private') !== false);
        same(false, strpos(file_get_contents($log) . json_encode($response), 'private SQL') !== false);
    } finally {
        $_SERVER = $server;
        ini_set('error_log', $previousLog);
        unlink($log);
        http_response_code(200);
    }
});
