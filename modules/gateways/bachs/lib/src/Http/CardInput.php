<?php

namespace Bachs\Whmcs\Http;

use Bachs\Whmcs\Application\Confirmations;
use Bachs\Whmcs\Application\Checkout;
use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Domain\Money;
use Bachs\Whmcs\Infrastructure\SafeData;

/** Binds native remote-input requests to the authenticated billing account. */
class CardInput
{
    public static function render($app, $params)
    {
        $app->assertCardBilling();
        $scope = $app->settings->activeScope();
        $app->settings->assertEnvironment($scope['data']['environment']);
        $actor = $app->bridge->identity->client();
        if (isset($params['clientdetails']['userid']) && (int)$params['clientdetails']['userid'] !== (int)$actor['client_id']) {
            throw new Failure('invoice_access_denied');
        }
        $id = !empty($params['invoiceid']) ? (int)$params['invoiceid'] : 0;
        $body = array('invoice_id' => $id, 'client_id' => (int)$actor['client_id']);
        if ($id) {
            $invoice = $app->bridge->invoice($id);
            if ((int)$invoice['client_id'] !== (int)$actor['client_id'] || $invoice['gateway'] !== 'bachs_card') {
                throw new Failure('invoice_access_denied');
            }
            $app->ready($scope, $invoice, true);
            $app->settings->cardMethods($invoice['currency']);
            if (!isset($params['amount']) || Money::compare((string)$params['amount'], $invoice['balance']) !== 0) {
                throw new Failure('invoice_changed_during_payment');
            }
            $body['snapshot'] = $app->snapshot($invoice);
        } else {
            $app->settings->cardMethods('USD');
        }
        $intent = (new Confirmations($app))->preview($scope, $actor['actor'], 'card_input', $body, array(), 900);
        // WHMCS automatically posts this form into its own iframe. The first
        // request renders consent there; it must never start a charge or setup.
        return self::form($app, $intent['confirmation'], 'review')
            . '<noscript><button type="submit">Continue to Bachs</button></noscript></form>';
    }

    public static function consentForm($app, $context, $confirmation)
    {
        $h = function ($value) { return SafeData::html($value); };
        $label = $context['invoice_id'] ? 'Pay invoice #' . (int)$context['invoice_id'] . ' and save card' : 'Save a card';
        return self::form($app, $confirmation, 'confirm')
            . '<h1>' . $h($label) . '</h1><p>Enter your card securely at Bachs.</p>'
            . '<div class="checkbox"><label><input type="checkbox" name="consent" value="1" required> ' . $h(Checkout::consentText()) . '</label></div>'
            . '<p><button class="btn btn-primary" type="submit">Continue to Bachs</button></p></form>';
    }

    private static function form($app, $confirmation, $step)
    {
        return '<form method="post" action="' . SafeData::html($app->bridge->systemUrl() . '/modules/gateways/bachs/card.php') . '"' . ($step === 'confirm' ? ' target="_top"' : '') . '>'
            . '<input type="hidden" name="token" value="' . SafeData::html($app->bridge->identity->token()) . '">'
            . '<input type="hidden" name="step" value="' . $step . '">'
            . '<input type="hidden" name="confirmation" value="' . SafeData::html($confirmation) . '">';
    }
}
