<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/init.php';
require_once dirname(__DIR__) . '/bootstrap.php';
try {
    $app = \Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app();
    $options = getopt('', array('limit::','seconds::','cleanup'));
    $worker = new \Bachs\Whmcs\Application\Worker($app);
    if (isset($options['cleanup'])) {
        $worker->cleanup();
    }
    echo json_encode($worker->run(isset($options['limit']) ? (int)$options['limit'] : null, isset($options['seconds']) ? (int)$options['seconds'] : null)) . PHP_EOL;
} catch (Exception $e) {
    fwrite(STDERR, 'Bachs worker failed: ' . ($e instanceof \Bachs\Whmcs\Domain\Failure ? $e->reason : 'internal_error') . PHP_EOL);
    exit(1);
}
