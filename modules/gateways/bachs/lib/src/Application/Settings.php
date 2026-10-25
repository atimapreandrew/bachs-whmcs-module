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
        if (isset(PaymentOptions::currencies()[$currency])) {
            return 2;
        }
        // Compare crypto exactly at the maximum supported decimal scale. This
        // accepts provider strings without rounding them to fiat precision.
        if (in_array($currency, PaymentOptions::crypto(), true)) {
            return 18;
        }
        throw new Failure('collection_precision_unknown');
    }

    public function precision($currency)
    {
        $precision = $this->invoicePrecision($currency);
        $allowed = $this->get('currencies', array_keys(PaymentOptions::currencies()));
        if (!in_array($currency, $allowed, true)) {
            throw new Failure('currency_not_enabled');
        }
        return $precision;
    }

    public function invoicePrecision($currency)
    {
        // Bachs API amounts use major-unit decimal strings, including CFA/RWF.
        $known = array_keys(PaymentOptions::currencies());
        if (!in_array($currency, $known, true)) {
            throw new Failure('currency_precision_gate');
        }
        return 2;
    }

    public function methods()
    {
        $selected = $this->get('methods', array());
        $mode = $this->get('methods_mode', $selected ? 'selected' : 'all');
        $methods = $mode === 'all' ? array_keys(PaymentOptions::methods()) : $selected;
        if (!$methods) {
            throw new Failure('payment_methods_required');
        }
        return $methods;
    }

    public function cardMethods($currency)
    {
        $card = $currency . '_CARD';
        if (!in_array($card, array('USD_CARD', 'NGN_CARD'), true) || !in_array($card, $this->methods(), true)) {
            throw new Failure('card_method_not_enabled');
        }
        return array($card);
    }

    public function checkoutMethods($currency, $save)
    {
        if ($save) {
            return $this->cardMethods($currency);
        }
        $methods = $this->methods();
        if ($currency === 'USD' && $this->get('adaptive_allocation', true)) {
            return $methods;
        }
        $eligible = array();
        foreach ($methods as $method) {
            if (PaymentOptions::methodCurrency($method) === $currency) {
                $eligible[] = $method;
            }
        }
        if (!$eligible) {
            throw new Failure('invoice_payment_method_unavailable');
        }
        return $eligible;
    }
}
