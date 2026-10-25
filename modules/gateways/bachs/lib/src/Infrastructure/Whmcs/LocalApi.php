<?php

namespace Bachs\Whmcs\Infrastructure\Whmcs;

use Bachs\Whmcs\Domain\Failure;

class LocalApi
{
    public function call($command, array $params = array())
    {
        if (!function_exists('localAPI')) {
            throw new Failure('whmcs_api_unavailable');
        }
        $result = localAPI($command, $params);
        if (!is_array($result) || !isset($result['result']) || $result['result'] !== 'success') {
            throw new Failure('whmcs_' . strtolower($command) . '_failed');
        }
        return $result;
    }
}
