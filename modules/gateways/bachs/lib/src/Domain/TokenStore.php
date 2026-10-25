<?php

namespace Bachs\Whmcs\Domain;

interface TokenStore
{
    public function owned($methodId, $scope, $clientId);
    public function revoke($methodId, $clientId);
}
