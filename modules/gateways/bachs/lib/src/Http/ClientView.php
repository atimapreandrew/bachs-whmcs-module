<?php

namespace Bachs\Whmcs\Http;

use Bachs\Whmcs\Infrastructure\SafeData;
use Bachs\Whmcs\Application\Confirmations;

/** Adds ordinary WHMCS controls to the existing invoice and Pay Methods pages. */
class ClientView
{
    public static function methodsUrl($app)
    {
        return $app->bridge->systemUrl() . '/index.php?rp=/account/paymentmethods';
    }

    public static function isMethodsPage($vars)
    {
        return (isset($_GET['rp']) && is_string($_GET['rp']) && trim($_GET['rp'], '/') === 'account/paymentmethods') ||
            (isset($vars['templatefile']) && $vars['templatefile'] === 'account-paymentmethods');
    }

    public static function render($app, $vars)
    {
        $h = function ($value) { return SafeData::html($value); };
        $content = '';
        if (!empty($_SESSION['bachs_notice'])) {
            $content .= '<div class="alert alert-info" role="status">' . $_SESSION['bachs_notice'] . '</div>';
            unset($_SESSION['bachs_notice']);
        }
        try {
            $actor = $app->bridge->identity->client();
        } catch (\Bachs\Whmcs\Domain\Failure $error) {
            if ($content !== '' && in_array($error->reason, array('client_login_required','client_payment_access_denied','client_permissions_unavailable'), true)) {
                return '<div id="bachs-client-controls">' . $content . '</div>';
            }
            throw $error;
        }
        $root = $app->bridge->systemUrl() . '/modules/gateways/bachs';
        $attemptId = Input::text($_GET, 'bachs_attempt', '', 48);
        if ($attemptId !== '') {
            $attempt = $app->store->get('attempts', $attemptId);
            if ($attempt && (int)$attempt['client_id'] === (int)$actor['client_id']) {
                $content .= '<div class="alert alert-info" role="status" data-bachs-return data-attempt="' . $h($attemptId) . '" data-status-url="' . $h($root . '/status.php') . '"><span data-bachs-status>' . $h(Messages::state($attempt['state'])) . '</span></div>';
            }
        }
        $intent = Input::text($_GET, 'bachs_card_intent', '', 48);
        if ($intent !== '') {
            $context = (new Confirmations($app))->inspect($app->settings->activeScope(), $actor['actor'], 'card_input', $intent);
            if ((int)$context['body']['client_id'] === (int)$actor['client_id']) {
                $content .= CardInput::consentForm($app, $context['body'], $intent);
            }
        } elseif (self::isMethodsPage($vars)) {
            $token = $app->bridge->identity->token();
            $content .= '<h3>Bachs saved cards</h3>';
            foreach ($app->store->listing('payment_methods', array('client_id' => $actor['client_id'], 'state' => 'active'), 100) as $method) {
                $content .= '<form class="form-group" method="post" action="' . $h($root . '/manage.php') . '">'
                    . '<input type="hidden" name="token" value="' . $h($token) . '"><input type="hidden" name="action" value="revoke"><input type="hidden" name="method_id" value="' . $h($method['id']) . '">'
                    . '<p>' . $h($method['data']['brand'] . ' ending ' . $method['data']['last4']) . '</p><button class="btn btn-default" type="submit">Turn off automatic payments for this card</button></form>';
            }
            $canSetup = $app->settings->get('saved_methods', false) && $app->settings->get('enabled', false) && !$app->settings->get('draining', false);
            if ($canSetup && in_array('USD_CARD', $app->settings->methods(), true)) {
                $content .= '<form class="form-group" method="post" action="' . $h($root . '/manage.php') . '"><input type="hidden" name="token" value="' . $h($token) . '"><input type="hidden" name="action" value="setup">'
                    . '<div class="checkbox"><label><input type="checkbox" name="consent" value="1" required> ' . $h(\Bachs\Whmcs\Application\Checkout::consentText()) . '</label></div>'
                    . '<button class="btn btn-primary" type="submit">Add a USD card at Bachs</button><p class="text-muted">No payment is collected. NGN cards can be saved during an invoice payment.</p></form>';
            }
            $scope = $app->settings->activeScope();
            if ($app->store->find('customers', $scope['id'], 'client:' . $actor['client_id'])) {
                $content .= '<form method="post" action="' . $h($root . '/manage.php') . '"><input type="hidden" name="token" value="' . $h($token) . '"><input type="hidden" name="action" value="portal"><button class="btn btn-default" type="submit">Manage cards at Bachs</button></form>';
            }
        }
        return $content ? '<div id="bachs-client-controls">' . $content . '</div><script src="' . $h($root . '/assets/bachs.js') . '" defer></script>' : '';
    }
}
