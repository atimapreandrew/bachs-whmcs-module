<?php

namespace Bachs\Whmcs\Domain;

interface PaymentPoster
{
    public function transaction($id);
    public function post($invoice, $transaction, $amount, $fee, $gateway);
}
