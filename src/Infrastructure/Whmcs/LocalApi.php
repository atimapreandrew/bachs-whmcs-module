<?php

namespace Bachs\Whmcs\Infrastructure\Whmcs;

use Bachs\Whmcs\Domain\Failure;

class LocalApi
{
    private $username;

    public function __construct($username = '')
    {
        $this->username = $username;
    }

    public function call($command, array $params = array(), $username = null)
    {
        if (!function_exists('localAPI')) {
            throw new Failure('whmcs_api_unavailable');
        }
        $admin = $username === null ? $this->username : $username;
        $version = defined('WHMCS_VERSION') ? WHMCS_VERSION : (isset($GLOBALS['CONFIG']['Version']) ? $GLOBALS['CONFIG']['Version'] : '0');
        if (version_compare($version, '7.2', '<') && $admin === '') {
            throw new Failure('legacy_api_admin_required');
        }
        if ($admin !== '') {
            $row = \WHMCS\Database\Capsule::table('tbladmins')->where('username', $admin)->where('disabled', 0)->first(array('id'));
            if (!$row) {
                throw new Failure('configured_api_admin_inactive');
            }
        }
        $result = localAPI($command, $params, $admin === '' ? null : $admin);
        if (!is_array($result) || !isset($result['result']) || $result['result'] !== 'success') {
            throw new Failure('whmcs_' . strtolower($command) . '_failed');
        }
        return $result;
    }
}
