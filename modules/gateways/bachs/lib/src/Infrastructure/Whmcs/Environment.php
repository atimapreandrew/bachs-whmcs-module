<?php

namespace Bachs\Whmcs\Infrastructure\Whmcs;

use Bachs\Whmcs\Domain\Failure;

/** Read native settings in admin, client and cron contexts without assuming globals. */
final class Environment
{
    public static function setting($key)
    {
        if (class_exists('WHMCS\\Config\\Setting')) {
            $value = \WHMCS\Config\Setting::getValue($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        $value = isset($GLOBALS['CONFIG'][$key]) ? $GLOBALS['CONFIG'][$key] : null;
        return is_string($value) ? $value : '';
    }

    public static function version()
    {
        $version = defined('WHMCS_VERSION') && is_string(WHMCS_VERSION) ? WHMCS_VERSION : self::setting('Version');
        if ($version === '') {
            throw new Failure('whmcs_version_unavailable');
        }
        return $version;
    }
}
