<?php

namespace Bachs\Whmcs\Infrastructure;

final class SafeData
{
    public static function redact($data)
    {
        if (!is_array($data)) {
            return '[redacted]';
        }
        $out = array();
        $allow = array('id','attempt_id','payment_id','checkout_id','charge_id','refund_id','reference','status',
            'state','type','event_id','organization_id','account','scope','environment','amount','amount_paid',
            'amount_remaining','currency','requested_amount','refunded_amount','created_at','updated_at',
            'request_id','reason_code','gateway','invoice_id','client_id','error_code','action','target','actor',
            'next_action','fee','fee_currency','settlement_amount','settlement_currency','collection_amount','collection_currency','provider_state','accounting_state',
            'brand','last4','expiry_month','expiry_year','deadline','count','version','duration','body_hash','fingerprint');
        foreach ($data as $k => $v) {
            if (!in_array((string)$k, $allow, true)) {
                continue;
            }
            if (is_array($v)) {
                $out[$k] = self::redact($v);
            } elseif (is_scalar($v) || $v === null) {
                $s = (string)$v;
                if (preg_match('/sk_(live|sandbox)_|whsec_|https?:\/\/|Bearer |pm_[A-Za-z0-9]/i', $s)) {
                    $out[$k] = '[redacted]';
                } else {
                    $out[$k] = is_string($v) ? substr($v, 0, 255) : $v;
                }
            }
        }
        return $out;
    }

    public static function html($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
