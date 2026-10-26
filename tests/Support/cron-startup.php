<?php

// Start like the WHMCS system cron: no Bachs loader, addon or injected runtime.
class CronSetting
{
    public static function getValue($key) { return $key === 'Version' ? '8.0.0' : null; }
}
class CronConnection
{
    public function getPdo()
    {
        if ($GLOBALS['database_failure']) { throw new RuntimeException('private database password=sk_live_private'); }
        return $GLOBALS['pdo'];
    }
}
class CronCapsule
{
    public static $connections = 0;
    public static function connection() { ++self::$connections; return new CronConnection(); }
}
class_alias('CronSetting', 'WHMCS\Config\Setting');
class_alias('CronCapsule', 'WHMCS\Database\Capsule');
function add_hook($name, $priority, $callback) { $GLOBALS['hooks'][$name] = $callback; }
function getGatewayVariables($gateway) { return array(); }
function logActivity($message) { $GLOBALS['logs'][] = $message; }
function localAPI($command, $arguments = array())
{
    ++$GLOBALS['api_calls'];
    throw new RuntimeException('An unconfigured cron must not call localAPI.');
}
$hook = isset($argv[1]) ? $argv[1] : 'AfterCronJob';
$sourceRoot = isset($argv[2]) ? $argv[2] : dirname(dirname(__DIR__));
$GLOBALS['database_failure'] = isset($argv[3]) && $argv[3] === 'database_failure';
$GLOBALS['hooks'] = $GLOBALS['logs'] = array();
$GLOBALS['api_calls'] = 0;
$GLOBALS['pdo'] = new PDO('sqlite::memory:');
define('WHMCS', true);
$directory = sys_get_temp_dir() . '/bachs-cron-' . bin2hex(random_bytes(8));
mkdir($directory . '/includes', 0700, true);
file_put_contents($directory . '/includes/gatewayfunctions.php', '<?php // Native contracts supplied above.');
define('ROOTDIR', $directory);
set_error_handler(function ($level, $message, $file, $line) {
    if (error_reporting() & $level) { throw new ErrorException($message, 0, $level, $file, $line); }
    return false;
});
try {
    require $sourceRoot . '/includes/hooks/bachs.php';
    $loadedBefore = class_exists('Bachs\Whmcs\Application\Worker', false);
    call_user_func($GLOBALS['hooks'][$hook], array());
    $loadedAfter = class_exists('Bachs\Whmcs\Application\Worker', false);
    $app = $loadedAfter && !$GLOBALS['database_failure']
        ? \Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app(false) : null;
    echo json_encode(array('loaded_before' => $loadedBefore, 'loaded_after' => $loadedAfter,
        'last_worker' => $app ? $app->settings->get('last_worker', 0) : 0,
        'cleanup_cursor' => $app ? $app->settings->get('retention_cursor') : null,
        'logs' => $GLOBALS['logs'], 'api_calls' => $GLOBALS['api_calls'],
        'database_connections' => CronCapsule::$connections));
} finally {
    unlink($directory . '/includes/gatewayfunctions.php');
    rmdir($directory . '/includes');
    rmdir($directory);
}
