<?php

use Bachs\Whmcs\Infrastructure\Bachs\Client;
use Bachs\Whmcs\Infrastructure\Bachs\ApiFailure;

class TestTransport
{
    public $calls = array();
    public $response = array('status' => 200, 'headers' => array('x-request-id' => 'req_test'), 'body' => '{"payment_id":"opaque","amount":"12.34"}');
    public function send($method, $url, $headers, $body)
    {
        $this->calls[] = array($method,$url,$headers,$body);
        return $this->response;
    }
}
test('Provider uses fixed environment origin, top-level JSON and acting-account header', function () {
    $t = new TestTransport();
    $c = new Client('sandbox', 'sk_sandbox_synthetic', 'acct_synthetic', $t);
    same('opaque', $c->request('GET', '/v1/payments/opaque')['payment_id']);
    same('https://sandbox-api.bachs.io/v1/payments/opaque', $t->calls[0][1]);
    truth(in_array('X-Account-Id: acct_synthetic', $t->calls[0][2], true));
    same('req_test', $c->lastRequestId);
});
test('Provider writes cannot bypass durable idempotency', function () {
    fails('durable_operation_required', function () {
        (new Client('live', 'sk_live_synthetic', '', new TestTransport()))->request('POST', '/v1/charges', array());
    });
});
test('Provider keys cannot cross environments', function () {
    fails('credential_environment_mismatch', function () {
        new Client('live', 'sk_sandbox_synthetic');
    });
});
foreach (array(401,403,409,429,500) as $status) {
    test('Provider error classification HTTP ' . $status, function () use ($status) {
        $t = new TestTransport();
        $t->response = array('status' => $status,'headers' => array('retry-after' => '42'),'body' => '{"error_code":"TEST_ERROR","detail":"sensitive message is never forwarded"}');
        try {
            (new Client('sandbox', 'sk_sandbox_synthetic', '', $t))->request('POST', '/v1/charges', array('amount' => '1.00'), 'identity');
            throw new RuntimeException('Expected error');
        } catch (ApiFailure $e) {
            same($status, $e->httpStatus);
            same($status >= 500 || in_array($status, array(409,429), true), $e->unknown);
            same(42, $e->retryAfter);
            same('TEST_ERROR', $e->getMessage());
        }
    });
}
test('Malformed successful write is an unknown outcome', function () {
    $t = new TestTransport();
    $t->response['body'] = '<html>unavailable</html>';
    try {
        (new Client('sandbox', 'sk_sandbox_synthetic', '', $t))->request('POST', '/v1/charges', array(), 'identity');
    } catch (ApiFailure $e) {
        same('malformed_response', $e->reason);
        same(true, $e->unknown);
        return;
    } throw new RuntimeException('Expected error');
});
test('API URL traversal and arbitrary hosts rejected', function () {
    fails('invalid_api_request', function () {
        (new Client('sandbox', 'sk_sandbox_synthetic', '', new TestTransport()))->request('GET', '/v1/../secret');
    });
});
