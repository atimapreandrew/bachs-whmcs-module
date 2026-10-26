<?php

namespace Bachs\Whmcs\Http;

use Bachs\Whmcs\Infrastructure\SafeData;

class GatewayView
{
    public static function render($app, $params, $gateway = 'bachs')
    {
        $root = $app->bridge->systemUrl() . '/modules/gateways/bachs';
        if (!$app->settings->get('enabled', false) || $app->settings->get('draining', false)) {
            return '<div class="alert alert-info" role="status">Bachs is temporarily unavailable for this invoice.</div>';
        }
        $token = $app->bridge->identity->token();
        $id = (int)$params['invoiceid'];
        $invoice = $app->bridge->invoice($id);
        $app->settings->precision($invoice['currency']);
        $app->settings->checkoutMethods($invoice['currency'], false);
        if ($invoice['status'] === 'Paid' || \Bachs\Whmcs\Domain\Money::compare($invoice['balance'], '0') === 0) {
            return '<p>There is no payment due on this invoice.</p>';
        }
        $label = $app->settings->get('button_label', 'Pay with Bachs');
        $sandbox = $app->settings->get('environment', 'sandbox') === 'sandbox';
        $save = $app->settings->get('saved_methods', false) && in_array($invoice['currency'], array('USD','NGN'), true);
        if ($save && !in_array($invoice['currency'] . '_CARD', $app->settings->methods(), true)) {
            $save = false;
        }
        $s = '<div data-bachs-payment data-status-url="' . SafeData::html($root . '/status.php') . '" aria-label="Bachs secure checkout">';
        if ($sandbox) {
            $s .= '<div class="alert alert-warning" role="status">Bachs sandbox — test payment</div>';
        }
        $s .= '<form method="post" action="' . SafeData::html($root . '/checkout.php') . '" data-bachs-form><input type="hidden" name="token" value="' . SafeData::html($token) . '"><input type="hidden" name="invoice_id" value="' . $id . '"><input type="hidden" name="gateway" value="' . $gateway . '">';
        if ($save) {
            $s .= '<div class="checkbox"><label><input type="checkbox" name="save_method" value="1"> ' . SafeData::html(\Bachs\Whmcs\Application\Checkout::consentText()) . '</label></div>';
        }
        $s .= '<button class="btn btn-primary" type="submit">' . SafeData::html($label) . '</button></form>';
        $s .= '<p class="text-info" data-bachs-status role="status" aria-live="polite"></p><p data-bachs-fallback-container hidden><a class="btn btn-default" data-bachs-fallback hidden rel="noreferrer">Continue to secure checkout ↗</a></p></div>';
        $s .= self::script($app);
        return $s;
    }

    public static function script($app)
    {
        static $revision;
        if ($revision === null) {
            $file = dirname(dirname(dirname(__DIR__))) . '/assets/bachs.js';
            $revision = is_file($file) ? substr(hash_file('sha256', $file), 0, 12) : '1.0.0';
        }
        return '<script src="' . SafeData::html($app->bridge->systemUrl() . '/modules/gateways/bachs/assets/bachs.js?v=' . $revision) . '" defer></script>';
    }
}
