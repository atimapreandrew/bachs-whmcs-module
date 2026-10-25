<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/init.php';
try {
    require_once dirname(__DIR__) . '/bootstrap.php';
    $app = \Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app();
    $options = getopt('', array('limit::','seconds::','cleanup'));
    $worker = new \Bachs\Whmcs\Application\Worker($app);
    if (isset($options['cleanup'])) {
        $worker->cleanup();
    }
    echo json_encode($worker->run(isset($options['limit']) ? (int)$options['limit'] : null, isset($options['seconds']) ? (int)$options['seconds'] : null)) . PHP_EOL;
} catch (Throwable $e) {
    require_once dirname(__DIR__) . '/lib/src/Infrastructure/Diagnostics.php';
    $report = \Bachs\Whmcs\Infrastructure\Diagnostics::report($e, 'worker_startup');
    fwrite(STDERR, \Bachs\Whmcs\Infrastructure\Diagnostics::message($report) . PHP_EOL);
    exit(1);
}
