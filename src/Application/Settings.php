<?php

namespace Bachs\Whmcs\Application;

use Bachs\Whmcs\Domain\Failure;

class Settings
{
    public $store;
    public $secrets;

    public function __construct($store, $secrets)
    {
        $this->store = $store;
        $this->secrets = $secrets;
    }

    public function get($key, $default = null)
    {
        $row = $this->store->find('settings', '', $key);
        return $row ? $row['data']['value'] : $default;
    }

    public function set($key, $value)
    {
        $old = $this->store->find('settings', '', $key);
        $this->store->put('settings', '', $key, array('value' => $value, 'type' => gettype($value), 'revision' => $old && isset($old['data']['revision']) ? (int)$old['data']['revision'] + 1 : 1), 'saved');
    }

    public function secret($key)
    {
        $value = $this->get($key);
        return $value ? $this->secrets->open($value) : '';
    }

    public function saveSecret($key, $value)
    {
        if ($value !== '') {
            $this->set($key, $this->secrets->seal($value));
        }
    }

    public function activeScope()
    {
        $environment = $this->get('environment', 'sandbox');
        $id = $this->get('scope_' . $environment);
        $scope = $id ? $this->store->get('merchant_scopes', $id) : null;
        if (!$scope) {
            throw new Failure('merchant_not_connected');
        }
        return $scope;
    }

    public function scope($id)
    {
        $scope = $this->store->get('merchant_scopes', $id);
        if (!$scope) {
            throw new Failure('scope_not_found');
        }
        return $scope;
    }

    public function provider($scope)
    {
        return new \Bachs\Whmcs\Infrastructure\Bachs\Client($scope['data']['environment'], $this->secret('key_' . $scope['id']), $scope['data']['account']);
    }

    public function assertEnvironment($environment)
    {
        if (!in_array($environment, array('sandbox', 'live'), true)) {
            throw new Failure('invalid_environment');
        }
        if ($environment === 'sandbox' && !$this->get('test_installation', false)) {
            throw new Failure('sandbox_installation_confirmation_required');
        }
    }

    public function collectionPrecision($currency)
    {
        $custom = $this->get('collection_precision', array());
        if (isset($custom[$currency]) && is_int($custom[$currency]) && $custom[$currency] >= 0 && $custom[$currency] <= 18) {
            return $custom[$currency];
        }
        if (in_array($currency, array('USD', 'NGN', 'GHS', 'KES', 'MWK', 'TZS', 'UGX', 'ZMW'), true)) {
            return 2;
        }
        throw new Failure('collection_precision_unknown');
    }

    public function precision($currency)
    {
        // Only supported two-decimal invoice currencies can enter native accounting.
        $known = array('USD','NGN','GHS','KES','MWK','TZS','UGX','ZMW');
        if (!in_array($currency, $known, true)) {
            throw new Failure('currency_precision_gate');
        }
        $allowed = $this->get('currencies', array('USD','NGN'));
        if (!in_array($currency, $allowed, true)) {
            throw new Failure('currency_not_enabled');
        }
        return 2;
    }
}
