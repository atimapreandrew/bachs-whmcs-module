<?php

namespace Bachs\Whmcs\Infrastructure\Whmcs;

use Bachs\Whmcs\Application\Engine;
use Bachs\Whmcs\Application\Settings;
use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Infrastructure\Storage\Store;
use Bachs\Whmcs\Infrastructure\SystemClock;

class Runtime
{
    private static $app;
    private static $configuration;
    private static $synchronized = false;
    public static function app($synchronize = true)
    {
        if (self::$app) {
            if ($synchronize && !self::$synchronized && self::$configuration !== null) {
                (new GatewayConfiguration(self::$app))->synchronize(self::$configuration);
                self::$synchronized = true;
            }
            return self::$app;
        }
        if (!defined('WHMCS') || !class_exists('WHMCS\\Database\\Capsule')) {
            throw new Failure('whmcs_bootstrap_required');
        }
        $root = defined('ROOTDIR') ? ROOTDIR : dirname(dirname(dirname(dirname(dirname(dirname(dirname(__DIR__)))))));
        $pdo = \WHMCS\Database\Capsule::connection()->getPdo();
        \Bachs\Whmcs\Infrastructure\Storage\Schema::ensure($pdo);
        $store = new Store($pdo, new SystemClock());
        require_once $root . '/includes/gatewayfunctions.php';
        $configuration = getGatewayVariables('bachs');
        if (!is_array($configuration)) {
            $configuration = array();
        }
        $api = new LocalApi();
        $secrets = new NativeSecrets($api);
        $settings = new Settings($store, $secrets);
        $bridge = new Bridge($api, $settings, $pdo, $root);
        self::$app = new Engine($store, $settings, $bridge);
        self::$configuration = $configuration;
        if ($synchronize) {
            (new GatewayConfiguration(self::$app))->synchronize($configuration);
            self::$synchronized = true;
        }
        return self::$app;
    }
}
