<?php

namespace Bachs\Whmcs\Infrastructure\Whmcs;

use Bachs\Whmcs\Domain\Failure;

class LocalApi
{
    public function call($command, array $params = array(), $username = null)
    {
        if (!function_exists('localAPI')) {
            throw new Failure('whmcs_api_unavailable');
        }
        $admin = $username === null ? '' : $username;
        if ($admin !== '') {
            $row = \WHMCS\Database\Capsule::table('tbladmins')->where('username', $admin)->where('disabled', 0)->first(array('id'));
            if (!$row) {
                throw new Failure('admin_access_denied');
            }
        }
        $result = localAPI($command, $params, $admin === '' ? null : $admin);
        if (!is_array($result) || !isset($result['result']) || $result['result'] !== 'success') {
            throw new Failure('whmcs_' . strtolower($command) . '_failed');
        }
        return $result;
    }
}
