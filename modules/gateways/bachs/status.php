<?php

define('CLIENTAREA', true);
require_once dirname(dirname(dirname(__DIR__))) . '/init.php';
require_once __DIR__ . '/bootstrap.php';
try {
    $controller = new \Bachs\Whmcs\Http\ClientController(\Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app());
    $controller->status();
} catch (Throwable $e) {
    if (isset($controller)) {
        $controller->fail($e);
    } else {
        http_response_code(503);
        header('Content-Type: application/json');
        echo '{"error":"unavailable"}';
    }
}
