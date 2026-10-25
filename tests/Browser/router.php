<?php

// Synthetic UI fixture only. Never packaged or loaded by WHMCS runtime.
require dirname(__DIR__) . '/bootstrap.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($path, '/modules/gateways/bachs/assets/') === 0) {
    $file = basename($path);
    $allowed = array('bachs.css' => 'text/css','bachs.js' => 'application/javascript','admin.js' => 'application/javascript');
    if (!isset($allowed[$file])) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $allowed[$file]);
    readfile(dirname(dirname(__DIR__)) . '/resources/assets/' . $file);
    exit;
}
if ($path === '/fixture-checkout') {
    header('Content-Type: application/json');
    echo json_encode(array('attempt_id' => str_repeat('a', 48), 'state' => 'open', 'checkout_url' => 'https://checkout.bachs.io/c/synthetic-browser-fixture', 'mode' => 'overlay'));
    exit;
}
if ($path === '/fixture-status') {
    header('Content-Type: application/json');
    echo json_encode(array('state' => 'pending','message' => 'Confirming your payment…','invoice_paid' => false,'remaining_amount' => '100.00','currency' => 'USD'));
    exit;
}
class BrowserBridge extends FakeWhmcs
{
    public function systemUrl()
    {
        return 'http://127.0.0.1:8765';
    }
}
list($app,$provider,$bridge,$scope) = environment();
$app->bridge = new BrowserBridge();
$app->settings->set('last_worker', $app->store->clock->now() - 60);
$app->settings->set('saved_methods', true);
$app->store->insert('payments', $scope['id'], 'synthetic_payment', array('payment_id' => 'pay_synthetic_example','amount' => '100.00','currency' => 'USD','gateway' => 'bachs','attempt_id' => 'synthetic_attempt','transaction_id' => 'bachs_pay_synthetic'), 'posted', array('invoice_id' => 42,'client_id' => 1));
$app->store->insert('reconciliation_items', $scope['id'], 'synthetic_review', array('type' => 'payment_currency_review','target' => 'pay_synthetic_review'), 'review');
$app->store->insert('webhook_events', $scope['id'], 'evt_synthetic_display', array('event_id' => 'evt_synthetic_display','type' => 'collection.succeeded'), 'processed');
$app->store->insert('refunds', $scope['id'], 'refund_synthetic', array('refund_id' => 'ref_synthetic_example','payment_id' => 'pay_synthetic_example','amount' => '20.00','currency' => 'USD','provider_state' => 'success','accounting_state' => 'pending'), 'accounting_pending', array('invoice_id' => 42,'client_id' => 1));
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bachs · synthetic UI fixture</title><style>body{margin:0;padding:24px;background:#e9eef2;font-family:Arial,sans-serif}.fixture-banner{font:12px Arial;color:#4b6470;margin:0 0 12px}.fixture-wrap{max-width:1350px;margin:auto}@media(max-width:700px){body{padding:8px}}</style></head><body><div class="fixture-wrap"><p class="fixture-banner">SYNTHETIC UI FIXTURE · No real merchant account or WHMCS installation</p>';
if ($path === '/client') {
    $app->bridge->invoices[42]['balance'] = '28450.00';
    $app->bridge->invoices[42]['currency'] = 'NGN';
    $html = \Bachs\Whmcs\Http\GatewayView::render($app, array('invoiceid' => 42));
    $html = str_replace('/modules/gateways/bachs/checkout.php', '/fixture-checkout', $html);
    $html = str_replace('/modules/gateways/bachs/status.php', '/fixture-status', $html);
    echo $html;
} else {
    echo (new \Bachs\Whmcs\Http\AdminView($app, '/admin?module=bachs_admin'))->render();
}
echo '</div></body></html>';
