<?php

namespace Bachs\Whmcs\Http;

use Bachs\Whmcs\Infrastructure\SafeData;

class GatewayView
{
    public static function render($app, $params, $gateway = 'bachs')
    {
        $root = $app->bridge->systemUrl() . '/modules/gateways/bachs';
        if (!$app->settings->get('enabled', false) || $app->settings->get('draining', false)) {
            return '<div class="bachs-unavailable" role="status">Bachs is temporarily unavailable for this invoice.</div>';
        }
        $token = $app->bridge->identity->token();
        $id = (int)$params['invoiceid'];
        $invoice = $app->bridge->invoice($id);
        if ($invoice['status'] === 'Paid' || \Bachs\Whmcs\Domain\Money::compare($invoice['balance'], '0') === 0) {
            return '<p>There is no payment due on this invoice.</p>';
        }
        $label = $app->settings->get('button_label', 'Pay with Bachs');
        $sandbox = $app->settings->get('environment', 'sandbox') === 'sandbox';
        $save = $app->settings->get('saved_methods', false) && in_array($invoice['currency'], array('USD','NGN'), true);
        $s = '<link rel="stylesheet" href="' . SafeData::html($root . '/assets/bachs.css') . '">';
        $s .= '<section class="bachs-payment" data-bachs-payment data-status-url="' . SafeData::html($root . '/status.php') . '" aria-label="Bachs secure checkout">';
        $s .= '<div class="bachs-payment-top"><span class="bachs-wordmark">bachs<span aria-hidden="true">↗</span></span>' . ($sandbox ? '<span class="bachs-badge">Sandbox · test payment</span>' : '<span class="bachs-muted">Secure checkout</span>') . '</div>';
        $s .= '<p class="bachs-eyebrow">Amount due</p><p class="bachs-amount"><span>' . SafeData::html($invoice['currency']) . '</span> ' . SafeData::html($invoice['balance']) . '</p>';
        $s .= '<form method="post" action="' . SafeData::html($root . '/checkout.php') . '" data-bachs-form><input type="hidden" name="token" value="' . SafeData::html($token) . '"><input type="hidden" name="invoice_id" value="' . $id . '"><input type="hidden" name="gateway" value="' . $gateway . '">';
        if ($save) {
            $s .= '<label class="bachs-consent"><input type="checkbox" name="save_method" value="1"><span>' . SafeData::html(\Bachs\Whmcs\Application\Checkout::consentText()) . '</span></label>';
        }
        $s .= '<button class="bachs-button" type="submit"><span data-bachs-label>' . SafeData::html($label) . '</span><span aria-hidden="true">↗</span></button></form>';
        $s .= '<p class="bachs-muted">Choose an available payment method at Bachs.</p><p class="bachs-status" data-bachs-status role="status" aria-live="polite"></p><a class="bachs-fallback" data-bachs-fallback hidden rel="noreferrer">Continue to secure checkout ↗</a></section>';
        $s .= '<script src="' . SafeData::html($root . '/assets/bachs.js') . '" defer></script>';
        return $s;
    }
}
