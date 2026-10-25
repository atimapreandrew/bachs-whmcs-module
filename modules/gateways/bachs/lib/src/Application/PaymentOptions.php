<?php

namespace Bachs\Whmcs\Application;

/** Checkout corridors and pricing currencies documented by Bachs. */
final class PaymentOptions
{
    public static function currencies()
    {
        return array('USD' => 'US dollar', 'NGN' => 'Nigerian naira', 'GHS' => 'Ghanaian cedi',
            'KES' => 'Kenyan shilling', 'MWK' => 'Malawian kwacha', 'RWF' => 'Rwandan franc',
            'TZS' => 'Tanzanian shilling', 'UGX' => 'Ugandan shilling', 'XAF' => 'Central African CFA franc',
            'XOF' => 'West African CFA franc', 'ZMW' => 'Zambian kwacha');
    }

    public static function methods()
    {
        return array('USD_CARD' => 'USD cards', 'NGN_CARD' => 'NGN cards', 'NGN_BANK_TRANSFER' => 'NGN bank transfer',
            'MOMO_GHS' => 'Ghana mobile money', 'MOMO_KES' => 'Kenya mobile money', 'MOMO_MWK' => 'Malawi mobile money',
            'MOMO_RWF' => 'Rwanda mobile money', 'MOMO_TZS' => 'Tanzania mobile money', 'MOMO_UGX' => 'Uganda mobile money',
            'MOMO_XAF' => 'Central African mobile money (XAF)', 'MOMO_XOF' => 'West African mobile money (XOF)',
            'MOMO_ZMW' => 'Zambia mobile money', 'CRYPTO' => 'Crypto');
    }

    public static function crypto()
    {
        return array('USDT_TRC20', 'USDT_BEP20', 'USDT_ERC20', 'USDT_SOL', 'USDC_BEP20', 'ETH_ETH', 'SOL_SOL', 'BNB_BEP20');
    }

    public static function methodCurrency($method)
    {
        if ($method === 'USD_CARD') {
            return 'USD';
        }
        if ($method === 'NGN_CARD' || $method === 'NGN_BANK_TRANSFER') {
            return 'NGN';
        }
        return substr($method, 0, 5) === 'MOMO_' ? substr($method, 5) : null;
    }
}
