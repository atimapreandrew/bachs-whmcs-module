<?php

namespace Bachs\Whmcs\Infrastructure\Whmcs;

use Bachs\Whmcs\Application\Connection;
use Bachs\Whmcs\Application\PaymentOptions;
use Bachs\Whmcs\Domain\Failure;

/** Native WHMCS gateway fields are the source of configuration for web and cron. */
class GatewayConfiguration
{
    private $app;
    private $connection;

    public function __construct($app, $connection = null)
    {
        $this->app = $app;
        $this->connection = $connection ? $connection : new Connection($app);
    }

    public static function checked($value)
    {
        return in_array($value, array(true, 1, '1', 'on', 'yes'), true);
    }

    public function synchronize(array $values, $force = false)
    {
        $a = $this->app;
        $keys = array('type', 'convertto', 'environment', 'sandboxApiKey', 'sandboxSigningSecret', 'liveApiKey', 'liveSigningSecret',
            'enabled', 'testInstallation', 'maintenance', 'checkoutMode', 'buttonLabel', 'savedMethods', 'automaticPayments',
            'usdConversion', 'expiryMinutes', 'retentionDays');
        foreach (array_keys(PaymentOptions::currencies()) as $code) {
            $keys[] = 'accept_' . $code;
        }
        foreach (array_keys(PaymentOptions::methods()) as $code) {
            $keys[] = 'offer_' . $code;
        }
        $values = array_intersect_key($values, array_flip($keys));
        ksort($values);
        $hash = hash('sha256', json_encode($values));
        if (!$force && $a->settings->get('native_configuration_hash') === $hash &&
            (!$a->settings->get('configuration_error') || $a->store->clock->now() - $a->settings->get('native_configuration_at', 0) < 300)) {
            return;
        }
        // Serialize configuration changes from the native gateway fields.
        $lease = $a->store->acquire('native_configuration');
        try {
            $a->settings->set('enabled', false);
            $a->settings->set('automatic_payments', false);
            $a->settings->set('native_configuration_at', $a->store->clock->now());
            $a->settings->set('configuration_error', '');
            $a->settings->set('configuration_error_reference', '');
            if (empty($values['type'])) {
                $a->settings->set('draining', true);
                throw new Failure('native_gateway_inactive');
            }
            $input = $this->input($values);
            $this->connection->save($input, 'system:native_gateway_settings');
            $environment = $input['environment'];
            $key = $this->text($values, $environment . 'ApiKey');
            if ($key === '') {
                throw new Failure('api_key_required');
            }
            $scopeId = $a->settings->get('scope_' . $environment);
            $scope = $scopeId ? $a->settings->scope($scopeId) : null;
            if (!$scope || $scope['data']['generation'] !== hash('sha256', $key) || $force) {
                $scope = $this->connection->connect($environment, $key, 'system:native_gateway_settings');
            }
            foreach (array('sandbox', 'live') as $env) {
                $id = $a->settings->get('scope_' . $env);
                $secret = $this->text($values, $env . 'SigningSecret');
                if ($id && $secret !== '') {
                    $this->connection->signing($a->settings->scope($id), $secret, 'system:native_gateway_settings');
                }
            }
            if ($this->text($values, $environment . 'SigningSecret') === '') {
                throw new Failure('webhook_secret_required');
            }
            if (!empty($values['convertto'])) {
                throw new Failure('native_currency_conversion_gate');
            }
            if (self::checked(isset($values['enabled']) ? $values['enabled'] : false)) {
                $a->settings->assertEnvironment($environment);
                $a->settings->set('enabled', true);
            }
            $a->settings->set('native_configuration_hash', $hash);
        } catch (\Throwable $error) {
            $a->settings->set('enabled', false);
            $a->settings->set('automatic_payments', false);
            $a->settings->set('configuration_error', $error instanceof Failure ? $error->reason : 'configuration_failed');
            $a->settings->set('native_configuration_hash', $hash);
            if (!$error instanceof Failure) {
                $report = \Bachs\Whmcs\Infrastructure\Diagnostics::report($error, 'configuration_apply');
                $a->settings->set('configuration_error_reference', $report['reference']);
            }
        } finally {
            $a->store->release('native_configuration', $lease);
        }
    }

    private function text($values, $key, $default = '')
    {
        $value = isset($values[$key]) ? $values[$key] : $default;
        if (!is_string($value) || strlen($value) > 8192) {
            throw new Failure('invalid_gateway_configuration');
        }
        return $value;
    }

    public function input(array $values)
    {
        $currencies = array();
        foreach (array_keys(PaymentOptions::currencies()) as $code) {
            if (self::checked(isset($values['accept_' . $code]) ? $values['accept_' . $code] : false)) {
                $currencies[] = $code;
            }
        }
        $methods = array();
        foreach (array_keys(PaymentOptions::methods()) as $code) {
            if (self::checked(isset($values['offer_' . $code]) ? $values['offer_' . $code] : false)) {
                $methods[] = $code;
            }
        }
        $input = array('environment' => $this->text($values, 'environment', 'sandbox'),
            'checkout_mode' => $this->text($values, 'checkoutMode', 'redirect'),
            'button_label' => $this->text($values, 'buttonLabel', 'Pay with Bachs'),
            'currencies' => $currencies, 'methods_mode' => 'selected', 'methods' => $methods, 'enabled' => false);
        foreach (array('test_installation' => 'testInstallation', 'saved_methods' => 'savedMethods', 'automatic_payments' => 'automaticPayments',
            'draining' => 'maintenance', 'adaptive_allocation' => 'usdConversion') as $local => $native) {
            $input[$local] = self::checked(isset($values[$native]) ? $values[$native] : false);
        }
        foreach (array('expiry_minutes' => array('expiryMinutes', 60, 1, 1440), 'sensitive_retention_days' => array('retentionDays', 30, 1, 365)) as $local => $rule) {
            $value = $this->text($values, $rule[0], (string)$rule[1]);
            if (!preg_match('/^[0-9]{1,4}$/D', $value) || (int)$value < $rule[2] || (int)$value > $rule[3]) {
                throw new Failure('invalid_gateway_configuration');
            }
            $input[$local] = (int)$value;
        }
        return $input;
    }

}
