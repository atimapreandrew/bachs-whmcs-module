<?php

namespace Bachs\Whmcs\Domain;

interface RefundFinalizer
{
    public function finalizeRefund($refund, $payment);
}
