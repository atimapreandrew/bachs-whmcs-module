<?php

namespace Bachs\Whmcs\Infrastructure\Bachs;

class ApiFailure extends \Bachs\Whmcs\Domain\Failure
{
    public $httpStatus;
    public $requestId;
    public $retryAfter;
    public $unknown;

    public function __construct($code, $status = 0, $requestId = '', $retryAfter = 30, $unknown = false)
    {
        parent::__construct($code);
        $this->httpStatus = $status;
        $this->requestId = substr($requestId, 0, 128);
        $this->retryAfter = max(1, min(3600, (int)$retryAfter));
        $this->unknown = $unknown;
    }
}
