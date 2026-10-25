<?php

namespace Bachs\Whmcs\Infrastructure;

class SystemClock implements \Bachs\Whmcs\Domain\Clock
{
    public function now()
    {
        return time();
    }
}
