<?php

namespace Bachs\Whmcs\Application;

use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Domain\Ids;
use Bachs\Whmcs\Domain\Scope;
use Bachs\Whmcs\Infrastructure\Bachs\Client;

class Connection
{
    private $app;

    public function __construct(Engine $app)
    {
        $this->app = $app;
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
        $provider = new Client($environment, $key, $account);
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
        $scope = $a->store->find('merchant_scopes', '', $scopeKey);
        $generation = hash('sha256', $key);
        if ($scope && $scope['data']['generation'] !== $generation) {
            $a->settings->saveSecret('generation_' . $scope['id'] . '_' . $scope['data']['generation'], $a->settings->secret('key_' . $scope['id']));
        }
        $methods = $provider->request('GET', '/v1/payment-methods');
        $checkout = $provider->request('GET', '/v1/accounts/checkout/settings');
        $scope = $a->store->put('merchant_scopes', '', $scopeKey, array('installation' => $installation,
            'environment' => $environment, 'organization' => $organization, 'account' => $account, 'generation' => $generation,
            'verified_at' => $a->store->clock->now(), 'name' => isset($me['name']) ? substr($me['name'], 0, 128) : 'Merchant',
            'enabled_methods' => isset($me['enabled_payment_methods']) ? $me['enabled_payment_methods'] : array(),
            'capabilities' => isset($me['capabilities']) ? $me['capabilities'] : array(),
            'methods' => $a->settings->secrets->seal(json_encode($methods)),
            'checkout_settings' => $a->settings->secrets->seal(json_encode($checkout))), 'bound');
        $a->settings->saveSecret('key_' . $scope['id'], $key);
        $a->settings->set('scope_' . $environment, $scope['id']);
        $a->store->audit($actor, 'merchant_connection_verified', $scope['id'], $scope['id'], array('environment' => $environment));
        return $scope;
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
        $known = array('USD_CARD','NGN_CARD','NGN_BANK_TRANSFER','MOMO_GHS','MOMO_KES','MOMO_TZS','MOMO_UGX','MOMO_XAF','MOMO_XOF','MOMO_RWF','MOMO_MWK','MOMO_ZMW','CRYPTO');
        if (!is_array($methods) || array_diff($methods, $known)) {
            throw new Failure('method_restriction_not_supported');
        }
        $currencies = isset($input['currencies']) ? $input['currencies'] : array('USD','NGN');
        if (!is_array($currencies) || !$currencies || array_diff($currencies, array('USD','NGN','GHS','KES','MWK','TZS','UGX','ZMW'))) {
            throw new Failure('currency_precision_gate');
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
            $a->bridge->assertAcceptance('collection', $environment);
        }
        foreach (
            array('environment' => $environment, 'checkout_mode' => $mode, 'methods' => array_values($methods),
            'currencies' => array_values($currencies), 'button_label' => $label, 'enabled' => $enabled,
            'saved_methods' => !empty($input['saved_methods']), 'draining' => !empty($input['draining']),
            'adaptive_allocation' => !empty($input['adaptive_allocation']),
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
        if ($previous !== '' && $previous !== $secret) {
            $a->settings->saveSecret('previous_signing_' . $scope['id'], $previous);
            $a->settings->set('previous_expiry_' . $scope['id'], $a->store->clock->now() + 86400);
        }
        $a->settings->saveSecret('signing_' . $scope['id'], $secret);
        $a->store->audit($actor, 'webhook_secret_saved', $scope['id'], $scope['id']);
    }

    public function webhookPreview($scope, $actor)
    {
        $body = array('url' => $this->app->bridge->systemUrl() . '/modules/gateways/callback/bachs.php?environment=' . $scope['data']['environment'],
            'name' => 'WHMCS ' . substr($scope['data']['installation'], 0, 8), 'event_types' => Webhooks::$events, 'event_source' => 'account');
        return (new Confirmations($this->app))->preview($scope, $actor, 'webhook_create', $body);
    }

    public function createWebhook($scope, $actor, $confirmation)
    {
        $a = $this->app;
        $p = (new Confirmations($a))->consume($scope, $actor, 'webhook_create', $confirmation);
        $op = $a->operations->prepare($scope, 'webhook_endpoint:' . $scope['id'], 'POST', '/v1/webhooks/endpoints', $p['body']);
        $r = $a->operations->send($op, $scope, $a->provider($scope));
        if (!isset($r['endpoint_id'], $r['signing_secret'])) {
            throw new Failure('webhook_endpoint_contract_mismatch');
        }
        $this->signing($scope, $r['signing_secret'], $actor);
        $a->settings->set('endpoint_' . $scope['id'], $r['endpoint_id']);
        return array('endpoint_id' => $r['endpoint_id']);
    }
}
