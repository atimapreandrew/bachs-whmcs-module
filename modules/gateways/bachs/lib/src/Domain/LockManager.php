<?php

namespace Bachs\Whmcs\Domain;

interface LockManager
{
    public function acquire($name, $seconds = 120);
    public function release($name, $token);
}
