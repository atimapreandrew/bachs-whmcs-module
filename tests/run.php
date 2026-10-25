<?php

// Keep HTTP controller headers valid while collecting console test output.
ob_start();
require __DIR__ . '/bootstrap.php';
$passed = 0;
$failed = 0;
$assertions = 0;
function test($name, $callback)
{
    global $passed, $failed;
    try {
        call_user_func($callback);
        ++$passed;
        echo 'PASS ' . $name . PHP_EOL;
    } catch (Throwable $e) {
        ++$failed;
        echo 'FAIL ' . $name . ': ' . $e->getMessage() . PHP_EOL;
    }
}
function same($expected, $actual)
{
    global $assertions;
    ++$assertions;
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function truth($actual)
{
    same(true, (bool)$actual);
}
function fails($code, $callback)
{
    global $assertions;
    ++$assertions;
    try {
        call_user_func($callback);
    } catch (\Bachs\Whmcs\Domain\Failure $e) {
        if ($e->reason !== $code) {
            throw new RuntimeException('Expected ' . $code . ', got ' . $e->reason);
        }
        return;
    }
    throw new RuntimeException('Expected failure: ' . $code);
}
foreach (array('Unit','Contract','Integration') as $suite) {
    foreach (glob(__DIR__ . '/' . $suite . '/*.php') as $file) {
        require $file;
    }
}
echo PHP_EOL . $passed . ' tests passed, ' . $failed . ' failed; ' . $assertions . ' assertions.' . PHP_EOL;
if (getenv('BACHS_TEST_REPORT')) {
    file_put_contents(getenv('BACHS_TEST_REPORT'), json_encode(array('passed' => $passed, 'failed' => $failed, 'assertions' => $assertions, 'php' => PHP_VERSION, 'database' => 'SQLite ' . SQLite3::version()['versionString'], 'type' => 'synthetic, not licensed WHMCS/provider acceptance'), JSON_PRETTY_PRINT) . PHP_EOL);
}
exit($failed ? 1 : 0);
