<?php

define('CLIENTAREA', true);
require_once dirname(dirname(dirname(__DIR__))) . '/init.php';
try {
    require_once __DIR__ . '/bootstrap.php';
    $controller = new \Bachs\Whmcs\Http\ClientController(\Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app());
    $controller->card();
} catch (Throwable $e) {
    if (isset($controller)) {
        $controller->fail($e);
    } else {
        require_once __DIR__ . '/lib/src/Infrastructure/Diagnostics.php';
        \Bachs\Whmcs\Infrastructure\Diagnostics::unavailable($e, 'client_startup', (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false));
    }
}
