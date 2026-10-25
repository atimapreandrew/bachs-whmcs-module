<?php

namespace Bachs\Whmcs\Infrastructure\Whmcs;

use Bachs\Whmcs\Application\Engine;
use Bachs\Whmcs\Application\Settings;
use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Infrastructure\Storage\Store;
use Bachs\Whmcs\Infrastructure\Storage\EncryptedSecrets;
use Bachs\Whmcs\Infrastructure\SystemClock;

class Runtime
{
    private static $app;
    public static function app()
    {
        if (self::$app) {
            return self::$app;
        }
        if (!defined('WHMCS') || !class_exists('WHMCS\\Database\\Capsule')) {
            throw new Failure('whmcs_bootstrap_required');
        }
        $root = defined('ROOTDIR') ? ROOTDIR : dirname(dirname(dirname(dirname(__DIR__))));
        $pdo = \WHMCS\Database\Capsule::connection()->getPdo();
        $store = new Store($pdo, new SystemClock());
        $row = \WHMCS\Database\Capsule::table('tbladdonmodules')->where('module', 'bachs_admin')->where('setting', 'apiAdmin')->value('value');
        if (!$row) {
            $savedIdentity = $store->find('settings', '', 'api_admin');
            if ($savedIdentity) {
                $row = $savedIdentity['data']['value'];
            }
        }
        $api = new LocalApi((string)$row);
        $key = getenv('BACHS_ENCRYPTION_KEY');
        $secrets = $key ? new EncryptedSecrets($key) : new NativeSecrets($api);
        $settings = new Settings($store, $secrets);
        $bridge = new Bridge($api, $settings, $pdo, $root);
        self::$app = new Engine($store, $settings, $bridge);
        return self::$app;
    }
}
