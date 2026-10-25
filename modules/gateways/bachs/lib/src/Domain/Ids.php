<?php

namespace Bachs\Whmcs\Domain;

final class Ids
{
    public static function random()
    {
        return bin2hex(random_bytes(24));
    }

    public static function opaque($id)
    {
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_.:-]{1,255}$/D', $id)) {
            throw new Failure('invalid_resource_id');
        }
        return $id;
    }

    public static function transaction($scope, $payment, $kind = 'pay')
    {
        return 'bachs_' . $kind . '_' . substr(hash('sha256', $scope . ':' . $payment), 0, 48);
    }
}
