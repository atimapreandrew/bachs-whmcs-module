<?php

namespace Bachs\Whmcs\Infrastructure\Whmcs;

/** Isolated native token contract; legacy versions retain module-owned controls. */
class TokenAdapter
{
    private $settings;
    private $version;

    public function __construct($settings, $version)
    {
        $this->settings = $settings;
        $this->version = $version;
    }

    public function enroll($method)
    {
        if (version_compare($this->version, '7.9', '<')) {
            return;
        }
        if (!empty($method['data']['native_enrollment'])) {
            return;
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
        $this->settings->store->update('payment_methods', $method['id'], array('native_enrollment' => 'completed',
            'pay_method_id' => is_object($result) && isset($result->id) ? (int)$result->id : null));
    }
}
