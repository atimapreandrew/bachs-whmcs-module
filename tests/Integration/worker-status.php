<?php

test('An authorized payment check reconciles pending receipts and records who ran it', function () {
    list($a, $p, $w, $scope, $clock) = environment();
    $attempt = beginPayment($a);
    $p->succeed($attempt);
    $a->settings->set('enabled', false);
    $actions = new \Bachs\Whmcs\Http\AdminActions($a);
    $result = $actions->run(array('action' => 'run_checks'));
    same($clock->now(), $a->settings->get('last_worker'));
    same('posted', $a->store->get('attempts', $attempt['id'])['state']);
    same(1, $w->posts);
    same(1, $p->writes('/v1/checkout-sessions'));
    same(0, $p->writes('/v1/charges'));
    same(false, $a->settings->get('enabled'));
    truth(strpos($result['message'], 'Payment checks completed') !== false);
    $audit = $a->store->listing('audit_log', array(), 50);
    $manual = array_values(array_filter($audit, function ($row) { return $row['data']['action'] === 'payment_checks_run'; }));
    same(1, count($manual));
    same('admin:10', $manual[0]['data']['actor']);
    $actions->run(array('action' => 'run_checks'));
    same(1, $w->posts);
    same(1, $p->writes('/v1/checkout-sessions'));
});

test('Manual payment checks require native permission and CSRF but no connected merchant', function () {
    list($a, $p, $w, $scope, $clock) = environment();
    $a->settings->set('scope_sandbox', '');
    $actions = new \Bachs\Whmcs\Http\AdminActions($a);
    $w->identity->denied = array('manage_webhooks');
    fails('action_permission_denied', function () use ($actions) { $actions->run(array('action' => 'run_checks')); });
    same(0, $a->settings->get('last_worker', 0));
    $w->identity->denied = array();
    $w->identity->validCsrf = false;
    fails('csrf_required', function () use ($actions) { $actions->run(array('action' => 'run_checks')); });
    same(0, $a->settings->get('last_worker', 0));
    same(0, count($p->calls));
    $w->identity->validCsrf = true;
    $actions->run(array('action' => 'run_checks'));
    same($clock->now(), $a->settings->get('last_worker'));
    same(0, $p->writes('/v1/checkout-sessions'));
});

test('Status offers a protected payment check and updates its worker health after POST', function () {
    list($a, $p, $w, $scope, $clock) = environment();
    $oldGet = $_GET; $oldPost = $_POST; $oldServer = $_SERVER;
    try {
        $_GET = array('bachs_page' => 'overview'); $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
        $html = (new \Bachs\Whmcs\Http\AdminView($a, 'addonmodules.php?module=bachs_payments'))->render();
        truth(strpos($html, 'Run payment checks') !== false);
        truth(strpos($html, 'Worker needs attention') !== false);
        same(0, $a->settings->get('last_worker', 0));
        same(0, count($p->calls));
        $_POST = array('bachs_action' => 'run_checks'); $_SERVER['REQUEST_METHOD'] = 'POST';
        $html = (new \Bachs\Whmcs\Http\AdminView($a, 'addonmodules.php?module=bachs_payments'))->render();
        truth(strpos($html, 'Payment checks completed') !== false);
        truth(strpos($html, 'Worker recently active') !== false);
        $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
        $clock->time += 900;
        $html = (new \Bachs\Whmcs\Http\AdminView($a, 'addonmodules.php?module=bachs_payments'))->render();
        truth(strpos($html, 'Worker recently active') !== false);
        ++$clock->time;
        $w->identity->denied = array('manage_webhooks');
        $html = (new \Bachs\Whmcs\Http\AdminView($a, 'addonmodules.php?module=bachs_payments'))->render();
        truth(strpos($html, 'Worker needs attention') !== false);
        same(false, strpos($html, 'name="bachs_action" value="run_checks"') !== false);
    } finally { $_GET = $oldGet; $_POST = $oldPost; $_SERVER = $oldServer; }
});

test('A stale worker gives clients an availability message without server instructions', function () {
    list($a) = environment();
    $server = $_SERVER;
    try {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        ob_start();
        (new \Bachs\Whmcs\Http\ClientController($a))->fail(new \Bachs\Whmcs\Domain\Failure('worker_not_healthy'));
        $response = json_decode(ob_get_clean(), true);
        same('worker_not_healthy', $response['error']);
        truth(strpos($response['message'], 'temporarily unavailable') !== false);
        foreach (array('CLI', 'cron', 'enable payments') as $instruction) {
            same(false, strpos($response['message'], $instruction) !== false);
        }
    } finally { $_SERVER = $server; http_response_code(200); }
});
