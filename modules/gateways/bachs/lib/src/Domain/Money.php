<?php

namespace Bachs\Whmcs\Domain;

/** Decimal arithmetic never crosses a float or a platform-sized integer. */
final class Money
{
    public static function normalize($value, $precision = 2)
    {
        if (
            !is_string($value) || $precision < 0 || $precision > 18 ||
            !preg_match('/^(0|[1-9][0-9]{0,35})(?:\.([0-9]+))?$/D', $value, $m)
        ) {
            throw new Failure('invalid_decimal');
        }
        $fraction = isset($m[2]) ? $m[2] : '';
        if (strlen($fraction) > $precision) {
            throw new Failure('unsupported_precision');
        }
        return $m[1] . ($precision ? '.' . str_pad($fraction, $precision, '0') : '');
    }

    private static function units($value, $precision)
    {
        $v = ltrim(str_replace('.', '', self::normalize($value, $precision)), '0');
        return $v === '' ? '0' : $v;
    }

    private static function format($units, $precision)
    {
        if (!$precision) {
            return $units;
        }
        $s = str_pad($units, $precision + 1, '0', STR_PAD_LEFT);
        return substr($s, 0, -$precision) . '.' . substr($s, -$precision);
    }

    private static function compareUnits($a, $b)
    {
        if (strlen($a) !== strlen($b)) {
            return strlen($a) > strlen($b) ? 1 : -1;
        }
        $c = strcmp($a, $b);
        return $c === 0 ? 0 : ($c > 0 ? 1 : -1);
    }

    public static function compare($a, $b, $precision = 2)
    {
        return self::compareUnits(self::units($a, $precision), self::units($b, $precision));
    }

    public static function add($a, $b, $precision = 2)
    {
        $a = strrev(self::units($a, $precision));
        $b = strrev(self::units($b, $precision));
        $carry = 0;
        $out = '';
        for ($i = 0; $i < max(strlen($a), strlen($b)); ++$i) {
            $n = ($i < strlen($a) ? (int)$a[$i] : 0) + ($i < strlen($b) ? (int)$b[$i] : 0) + $carry;
            $out .= (string)($n % 10);
            $carry = $n >= 10 ? 1 : 0;
        }
        if ($carry) {
            $out .= '1';
        }
        return self::normalize(self::format(strrev($out), $precision), $precision);
    }

    public static function subtract($a, $b, $precision = 2)
    {
        $au = self::units($a, $precision);
        $bu = self::units($b, $precision);
        if (self::compareUnits($au, $bu) < 0) {
            throw new Failure('negative_result');
        }
        $a = strrev($au);
        $b = strrev($bu);
        $out = '';
        $borrow = 0;
        for ($i = 0; $i < strlen($a); ++$i) {
            $n = (int)$a[$i] - ($i < strlen($b) ? (int)$b[$i] : 0) - $borrow;
            $borrow = $n < 0 ? 1 : 0;
            $out .= (string)($n < 0 ? $n + 10 : $n);
        }
        $out = ltrim(strrev($out), '0');
        return self::format($out === '' ? '0' : $out, $precision);
    }

    public static function positive($value, $precision = 2)
    {
        $value = self::normalize($value, $precision);
        if (self::compare($value, '0', $precision) <= 0) {
            throw new Failure('amount_must_be_positive');
        }
        return $value;
    }
}
