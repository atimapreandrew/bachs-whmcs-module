<?php

define('CLIENTAREA', true);
require_once dirname(dirname(dirname(__DIR__))) . '/init.php';
require_once __DIR__ . '/bootstrap.php';
try {
    $controller = new \Bachs\Whmcs\Http\ClientController(\Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app());
    $controller->checkout();
} catch (Exception $e) {
    if (isset($controller)) {
        $controller->fail($e);
    } else {
        http_response_code(503);
        echo 'Bachs is temporarily unavailable. Please return to your invoice.';
    }
}
