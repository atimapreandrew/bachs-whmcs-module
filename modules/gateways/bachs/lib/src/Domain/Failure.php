<?php

namespace Bachs\Whmcs\Domain;

class Failure extends \RuntimeException
{
    public $reason;

    public function __construct($reason)
    {
        $this->reason = preg_match('/^[a-zA-Z0-9_]{1,100}$/D', $reason) ? $reason : 'internal_error';
        parent::__construct($this->reason);
    }
}
