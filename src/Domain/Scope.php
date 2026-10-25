<?php

namespace Bachs\Whmcs\Domain;

final class Scope
{
    public static function id($installation, $environment, $organization, $account = '')
    {
        if (!in_array($environment, array('sandbox', 'live'), true)) {
            throw new Failure('invalid_environment');
        }
        return hash('sha256', implode('|', array(Ids::opaque($installation), $environment, Ids::opaque($organization), $account ? Ids::opaque($account) : '')));
    }
}
