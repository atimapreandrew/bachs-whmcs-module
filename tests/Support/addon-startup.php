<?php

// Isolated WHMCS core contracts. Exercise the real addon, runtime, schema,
// identity and renderer without the test suite's injected Engine instance.
class StartupSetting
{
    public static function getValue($key)
    {
        if ($key === 'Version') { return '8.0.0'; }
        if ($key === 'SystemURL' && $GLOBALS['mode'] !== 'missing_url') { return 'https://billing.example.invalid'; }
        return null;
    }
}
class StartupAdmin
{
    public $id = 12;
    public $roleId = 1;
    public $disabled = false;
    public function hasPermission($permission) { return $GLOBALS['mode'] !== 'denied'; }
}
class StartupCurrentUser
{
    public function admin() { return new StartupAdmin(); }
    public function isAuthenticatedAdmin() { return true; }
}
class StartupConnection
{
    public function getPdo()
    {
        if (in_array($GLOBALS['mode'], array('unexpected', 'logger_failure'), true)) {
            throw new RuntimeException('private SQL password=sk_live_private');
        }
        return $GLOBALS['pdo'];
    }
}
class StartupCapsule
{
    public static $connections = 0;
    public static function connection() { ++self::$connections; return new StartupConnection(); }
}
class StartupDeniedPdo extends PDO
{
    #[\ReturnTypeWillChange]
    public function exec($sql)
    {
        if (strpos($sql, 'CREATE') === 0) {
            $error = new PDOException('CREATE denied; password=sk_live_private');
            $error->errorInfo = array('42000', 1142, 'private SQL and credentials');
            throw $error;
        }
        return parent::exec($sql);
    }
}
class_alias('StartupSetting', 'WHMCS\Config\Setting');
class_alias('StartupCurrentUser', 'WHMCS\Authentication\CurrentUser');
class_alias('StartupCapsule', 'WHMCS\Database\Capsule');
function generate_token($format) { return 'native-csrf-fixture'; }
function getGatewayVariables($gateway)
{
    return $GLOBALS['mode'] === 'configured'
        ? array('type' => 'Invoices', 'enabled' => 'on', 'environment' => 'live', 'liveApiKey' => 'sk_live_fixture')
        : array();
}
function logActivity($message)
{
    if ($GLOBALS['mode'] === 'logger_failure') { throw new RuntimeException('Logger unavailable'); }
    $GLOBALS['logs'][] = $message;
}
function localAPI($command, $arguments = array())
{
    ++$GLOBALS['api_calls'];
    throw new RuntimeException('Opening the addon must not call localAPI: ' . $command);
}
$GLOBALS['mode'] = isset($argv[1]) ? $argv[1] : 'empty';
$GLOBALS['logs'] = array();
$GLOBALS['api_calls'] = 0;
$GLOBALS['pdo'] = $GLOBALS['mode'] === 'database_denied' ? new StartupDeniedPdo('sqlite::memory:') : new PDO('sqlite::memory:');
unset($GLOBALS['CONFIG']);
$_GET = $_POST = array();
$_SERVER['REQUEST_METHOD'] = 'GET';
define('WHMCS', true);
$directory = sys_get_temp_dir() . '/bachs-startup-' . bin2hex(random_bytes(8));
mkdir($directory . '/includes', 0700, true);
file_put_contents($directory . '/includes/gatewayfunctions.php', '<?php // Native contract supplied above.');
define('ROOTDIR', $directory);
ini_set('error_log', $directory . '/errors.log');
set_error_handler(function ($level, $message, $file, $line) {
    if (error_reporting() & $level) { throw new ErrorException($message, 0, $level, $file, $line); }
    return false;
});
try {
    $sourceRoot = isset($argv[2]) ? $argv[2] : dirname(dirname(__DIR__));
    require $sourceRoot . '/modules/addons/bachs_payments/bachs_payments.php';
    $activation = bachs_payments_activate();
    ob_start();
    bachs_payments_output(array('modulelink' => 'addonmodules.php?module=bachs_payments'));
    $html = ob_get_clean();
    echo json_encode(array('activation' => $activation['status'], 'html' => $html,
        'logs' => $GLOBALS['logs'], 'fallback_log' => is_file($directory . '/errors.log') ? file_get_contents($directory . '/errors.log') : '',
        'api_calls' => $GLOBALS['api_calls'], 'database_connections' => StartupCapsule::$connections));
} finally {
    if (is_file($directory . '/errors.log')) { unlink($directory . '/errors.log'); }
    unlink($directory . '/includes/gatewayfunctions.php');
    rmdir($directory . '/includes');
    rmdir($directory);
}
