<?php

namespace Bachs\Whmcs\Application;

use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Domain\Ids;
use Bachs\Whmcs\Domain\Scope;
use Bachs\Whmcs\Infrastructure\Bachs\Client;

class Connection
{
    private $app;
    private $factory;

    public function __construct(Engine $app, $factory = null)
    {
        $this->app = $app;
        $this->factory = $factory;
    }

    public function connect($environment, $key, $actor)
    {
        $a = $this->app;
        $account = '';
        if ($key === '') {
            $id = $a->settings->get('scope_' . $environment);
            if (!$id) {
                throw new Failure('api_key_required');
            }
            $key = $a->settings->secret('key_' . $id);
        }
        $provider = $this->factory ? call_user_func($this->factory, $environment, $key) : new Client($environment, $key, $account);
        $me = $provider->request('GET', '/v1/accounts/me');
        if (empty($me['id'])) {
            throw new Failure('merchant_contract_mismatch');
        }
        $organization = Ids::opaque($me['id']);
        $oldId = $a->settings->get('scope_' . $environment);
        $old = $oldId ? $a->settings->scope($oldId) : null;
        if ($old && ($old['data']['organization'] !== $organization || $old['data']['account'] !== $account)) {
            throw new Failure('merchant_migration_confirmation_required');
        }
        $installation = $a->settings->get('installation_id');
        if (!$installation) {
            $installation = Ids::random();
            $a->settings->set('installation_id', $installation);
        }
        $scopeKey = Scope::id($installation, $environment, $organization, $account);
        $generation = hash('sha256', $key);
        // Encrypt before changing the credential fingerprint. A failed encryption
        // or database write must leave the old scope and key together.
        $sealed = $a->settings->secrets->seal($key);
        $name = isset($me['name']) ? \Bachs\Whmcs\Infrastructure\SafeData::shortText($me['name'], 128) : 'Merchant';
        return $a->store->atomic(function () use ($a, $scopeKey, $installation, $environment, $organization, $account, $generation, $sealed, $name, $actor) {
            $scope = $a->store->put('merchant_scopes', '', $scopeKey, array('installation' => $installation,
                'environment' => $environment, 'organization' => $organization, 'account' => $account, 'generation' => $generation,
                'verified_at' => $a->store->clock->now(), 'name' => $name), 'bound');
            $a->settings->set('key_' . $scope['id'], $sealed);
            $a->settings->set('scope_' . $environment, $scope['id']);
            $a->store->audit($actor, 'merchant_connection_verified', $scope['id'], $scope['id'], array('environment' => $environment));
            return $scope;
        });
    }

    public function save(array $input, $actor)
    {
        $a = $this->app;
        $environment = isset($input['environment']) ? $input['environment'] : 'sandbox';
        if (!in_array($environment, array('sandbox','live'), true)) {
            throw new Failure('invalid_environment');
        }
        $mode = isset($input['checkout_mode']) ? $input['checkout_mode'] : 'redirect';
        if (!in_array($mode, array('redirect','overlay'), true)) {
            throw new Failure('invalid_checkout_mode');
        }
        $methods = isset($input['methods']) ? $input['methods'] : array();
        $known = array_keys(PaymentOptions::methods());
        if (!is_array($methods) || array_diff($methods, $known)) {
            throw new Failure('method_restriction_not_supported');
        }
        $methodsMode = isset($input['methods_mode']) ? $input['methods_mode'] : ($methods ? 'selected' : 'all');
        if (!in_array($methodsMode, array('all', 'selected'), true) || ($methodsMode === 'selected' && !$methods)) {
            throw new Failure('payment_methods_required');
        }
        $currencies = isset($input['currencies']) ? $input['currencies'] : (isset($input['currencies_present']) ? array() : array_keys(PaymentOptions::currencies()));
        if (!is_array($currencies) || !$currencies || array_diff($currencies, array_keys(PaymentOptions::currencies()))) {
            throw new Failure('currency_precision_gate');
        }
        if (isset($input['button_label']) && !is_string($input['button_label'])) {
            throw new Failure('invalid_button_label');
        }
        $label = isset($input['button_label']) ? trim($input['button_label']) : 'Pay with Bachs';
        if (strlen($label) < 3 || strlen($label) > 80) {
            throw new Failure('invalid_button_label');
        }
        $enabled = !empty($input['enabled']);
        if ($enabled) {
            $id = $a->settings->get('scope_' . $environment);
            if (!$id) {
                throw new Failure('merchant_not_connected');
            }
            if ($a->settings->secret('signing_' . $id) === '') {
                throw new Failure('webhook_secret_required');
            }
            if ($a->store->clock->now() - $a->settings->get('last_worker', 0) > 900) {
                throw new Failure('worker_not_healthy');
            }
            if ($environment === 'sandbox' && empty($input['test_installation'])) {
                throw new Failure('sandbox_installation_confirmation_required');
            }
        }
        foreach (
            array('environment' => $environment, 'checkout_mode' => $mode, 'methods' => array_values(array_unique($methods)), 'methods_mode' => $methodsMode,
            'currencies' => array_values($currencies), 'button_label' => $label, 'enabled' => $enabled,
            'saved_methods' => !empty($input['saved_methods']), 'draining' => !empty($input['draining']),
            'adaptive_allocation' => !empty($input['adaptive_allocation']),
            'automatic_payments' => !empty($input['automatic_payments']) && !empty($input['saved_methods']),
            'test_installation' => !empty($input['test_installation']),
            'expiry_minutes' => max(1, min(1440, isset($input['expiry_minutes']) ? (int)$input['expiry_minutes'] : 60))) as $key => $value
        ) {
            $a->settings->set($key, $value);
        }
        foreach (array('worker_batch' => array(50,1,100), 'worker_seconds' => array(20,1,50), 'sensitive_retention_days' => array(30,1,365)) as $key => $bounds) {
            $value = isset($input[$key]) ? (int)$input[$key] : $bounds[0];
            $a->settings->set($key, max($bounds[1], min($bounds[2], $value)));
        }
        $a->store->audit($actor, 'settings_saved', '', 'settings');
    }

    public function signing($scope, $secret, $actor)
    {
        if ($secret === '') {
            return;
        }
        if (!is_string($secret) || strlen($secret) < 16 || strlen($secret) > 512 || preg_match('/[\x00-\x20]/', $secret)) {
            throw new Failure('invalid_signing_secret');
        }
        $a = $this->app;
        $previous = $a->settings->secret('signing_' . $scope['id']);
        if ($previous !== '' && hash_equals($previous, $secret)) {
            return;
        }
        if ($previous !== '' && $previous !== $secret) {
            $a->settings->saveSecret('previous_signing_' . $scope['id'], $previous);
            $a->settings->set('previous_expiry_' . $scope['id'], $a->store->clock->now() + 86400);
        }
        $a->settings->saveSecret('signing_' . $scope['id'], $secret);
        $a->store->audit($actor, 'webhook_secret_saved', $scope['id'], $scope['id']);
    }
}
