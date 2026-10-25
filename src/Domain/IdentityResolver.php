<?php

namespace Bachs\Whmcs\Domain;

interface IdentityResolver
{
    public function client($clientId);
    public function admin($capability);
    public function csrf();
}
