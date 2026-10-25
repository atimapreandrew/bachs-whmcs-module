<?php

namespace Bachs\Whmcs\Infrastructure\Whmcs;

/** Creates and reconciles native WHMCS Pay Methods using local opaque tokens. */
class TokenAdapter
{
    private $settings;
    private $api;

    public function __construct($settings, $api)
    {
        $this->settings = $settings;
        $this->api = $api;
    }

    public function enroll($method)
    {
        $name = 'native_method:' . $method['id'];
        $lease = $this->settings->store->acquire($name);
        try {
            $fresh = $this->settings->store->get('payment_methods', $method['id']);
            $this->enrollLocked($fresh);
        } finally {
            $this->settings->store->release($name, $lease);
        }
    }

    private function enrollLocked($method)
    {
        if (!$method || $method['state'] !== 'active') {
            throw new \Bachs\Whmcs\Domain\Failure('saved_method_access_denied');
        }
        if (isset($method['data']['native_enrollment']) && $method['data']['native_enrollment'] === 'completed' && !empty($method['data']['pay_method_id'])) {
            return;
        }
        $existing = $this->find($method);
        if ($existing) {
            $this->complete($method, $existing);
            return;
        }
        if (!empty($method['data']['native_enrollment'])) {
            throw new \Bachs\Whmcs\Domain\Failure('native_card_enrollment_needs_review');
        }
        if (!function_exists('createCardPayMethod')) {
            throw new \Bachs\Whmcs\Domain\Failure('native_card_helper_unavailable');
        }
        // Persist before calling the official remote-input helper. An interrupted enrollment
        // requires review, rather than silently creating a second native Pay Method.
        $this->settings->store->update('payment_methods', $method['id'], array('native_enrollment' => 'in_progress'));
        $result = createCardPayMethod(
            (int)$method['client_id'],
            'bachs_card',
            $method['data']['last4'],
            sprintf('%02d%02d', $method['data']['expiry_month'], $method['data']['expiry_year'] % 100),
            $method['data']['brand'],
            null,
            null,
            $method['id']
        );
        if ($result !== true) {
            throw new \Bachs\Whmcs\Domain\Failure('native_card_enrollment_needs_review');
        }
        $id = $this->find($method);
        if (!$id) {
            throw new \Bachs\Whmcs\Domain\Failure('native_card_enrollment_needs_review');
        }
        $this->complete($method, $id);
    }

    private function find($method)
    {
        $result = $this->api->call('GetPayMethods', array('clientid' => (int)$method['client_id']));
        if (!isset($result['paymethods']) || !is_array($result['paymethods'])) {
            throw new \Bachs\Whmcs\Domain\Failure('native_pay_methods_contract_mismatch');
        }
        $id = null;
        foreach ($result['paymethods'] as $native) {
            if (isset($native['gateway_name'], $native['remote_token'], $native['id']) &&
                $native['gateway_name'] === 'bachs_card' && $native['remote_token'] === $method['id']) {
                if ($id !== null || empty($native['id']) || !isset($native['type']) || $native['type'] !== 'RemoteCreditCard') {
                    throw new \Bachs\Whmcs\Domain\Failure('native_card_mapping_conflict');
                }
                $id = (int)$native['id'];
            }
        }
        return $id;
    }

    private function complete($method, $id)
    {
        $this->settings->store->update('payment_methods', $method['id'], array('native_enrollment' => 'completed', 'pay_method_id' => $id));
    }
}
