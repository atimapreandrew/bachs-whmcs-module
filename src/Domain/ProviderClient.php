<?php

namespace Bachs\Whmcs\Domain;

interface ProviderClient
{
    public function request($method, $path, $body = null, $idempotencyKey = null);
}
