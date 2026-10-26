<?php

namespace Bachs\Whmcs\Http;

use Bachs\Whmcs\Domain\Failure;

class Input
{
    public static function text($source, $key, $default = '', $max = 1000)
    {
        $v = isset($source[$key]) ? $source[$key] : $default;
        if (!is_string($v) || strlen($v) > $max || strpos($v, "\0") !== false || preg_match('//u', $v) !== 1) {
            throw new Failure('invalid_input');
        }
        return $v;
    }

    public static function number($source, $key)
    {
        $v = self::text($source, $key);
        if (!preg_match('/^[0-9]{1,10}$/D', $v)) {
            throw new Failure('invalid_number');
        } return (int)$v;
    }

    public static function checked($source, $key)
    {
        $value = isset($source[$key]) ? $source[$key] : false;
        if (in_array($value, array(false, 0, '0', ''), true)) { return false; }
        if (in_array($value, array(true, 1, '1', 'on'), true)) { return true; }
        throw new Failure('invalid_input');
    }
}
