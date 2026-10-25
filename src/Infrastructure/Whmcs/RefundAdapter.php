<?php

namespace Bachs\Whmcs\Infrastructure\Whmcs;

use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Domain\Ids;
use Bachs\Whmcs\Domain\Money;

class RefundAdapter
{
    private $bridge;

    public function __construct($bridge)
    {
        $this->bridge = $bridge;
    }

    public function finalize($refund, $payment)
    {
        if (!isset($refund['data']['amount'])) {
            return null;
        }
        $transid = Ids::transaction($refund['scope'], $refund['data']['refund_id'], 'refund');
        $existing = $this->bridge->transaction($transid);
        if ($existing) {
            if (
                (int)$existing['invoiceid'] !== (int)$refund['invoice_id'] || (int)$existing['userid'] !== (int)$refund['client_id'] ||
                $existing['gateway'] !== $payment['data']['gateway'] || Money::compare($existing['amountout'], $refund['data']['amount']) !== 0 ||
                (int)$existing['refundid'] !== (int)$payment['data']['native_id']
            ) {
                throw new Failure('native_refund_linkage_conflict');
            }
            return $existing;
        }
        // No automatic AddTransaction/UpdateInvoice parity is claimed for any untested version.
        // Record-only manual refunds must use the displayed deterministic ID, amount and original linkage.
        return null;
    }
}
