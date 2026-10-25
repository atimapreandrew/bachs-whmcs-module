<?php

namespace Bachs\Whmcs\Application;

use Bachs\Whmcs\Domain\Failure;

class Engine
{
    public $store;
    public $settings;
    public $queue;
    public $operations;
    public $customers;
    public $bridge;
    private $factory;

    public function __construct($store, $settings, $bridge, $factory = null)
    {
        $this->store = $store;
        $this->settings = $settings;
        $this->bridge = $bridge;
        $this->factory = $factory;
        $this->queue = new \Bachs\Whmcs\Infrastructure\Storage\Queue($store);
        $this->operations = new Operations($store, $settings->secrets);
        $this->customers = new Customers($store, $this->operations);
    }

    public function provider($scope)
    {
        return $this->factory ? call_user_func($this->factory, $scope) : $this->settings->provider($scope);
    }

    public function assertCardBilling()
    {
        if (!$this->settings->get('automatic_payments', false) || !$this->settings->get('saved_methods', false)) {
            throw new Failure('automatic_payments_disabled');
        }
        if (version_compare($this->bridge->version, '8.0', '<')) {
            throw new Failure('whmcs_version_not_supported');
        }
    }

    public function ready($scope, $invoice, $automaticCard = false)
    {
        if (!$this->settings->get('enabled', false) || $this->settings->get('draining', false)) {
            throw new Failure('gateway_disabled');
        }
        if ($this->settings->get('native_configuration_hash') && $this->store->clock->now() - $this->settings->get('last_worker', 0) > 900) {
            throw new Failure('worker_not_healthy');
        }
        $this->settings->assertEnvironment($scope['data']['environment']);
        if ($automaticCard) {
            $this->assertCardBilling();
        }
        $this->settings->precision($invoice['currency']);
        $this->bridge->assertGateway($invoice['gateway']);
        if (!in_array($invoice['status'], array('Unpaid', 'Payment Pending'), true)) {
            throw new Failure('invoice_not_payable');
        }
        \Bachs\Whmcs\Domain\Money::positive($invoice['balance'], 2);
    }

    public function snapshot($invoice)
    {
        $out = array();
        foreach (array('invoice_id','client_id','currency','balance','total','credit','gateway','status','items_hash') as $k) {
            if (!isset($invoice[$k])) {
                throw new Failure('invoice_contract_mismatch');
            }
            $out[$k] = $invoice[$k];
        }
        if (in_array($out['status'], array('Unpaid', 'Payment Pending'), true)) {
            $out['status'] = 'Payable';
        }
        return hash('sha256', json_encode($out));
    }

    public function job($type, $scope, $aggregate)
    {
        $job = $this->queue->enqueue($type, $scope, $aggregate);
        $this->queue->wake($job);
        return $job;
    }
}
