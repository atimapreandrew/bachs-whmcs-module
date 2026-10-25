<?php

if (!defined('WHMCS')) {
    die('Access denied');
}
require_once __DIR__ . '/bachs/bootstrap.php';

function bachs_card_MetaData()
{
    return array('DisplayName' => 'Bachs saved card', 'APIVersion' => '1.1', 'DisableLocalCreditCardInput' => true, 'TokenisedStorage' => true);
}
function bachs_card_config()
{
    return array('FriendlyName' => array('Type' => 'System', 'Value' => 'Bachs saved card'),
        'setupInfo' => array('FriendlyName' => 'Setup', 'Type' => 'text', 'Size' => '0', 'Description' => 'Activate only after the native-card adapter acceptance checks. Configure shared credentials in Addons > Bachs.'));
}
function bachs_card_capture($params)
{
    try {
        $app = \Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app();
        if (empty($params['gatewayid']) || !is_string($params['gatewayid'])) {
            throw new \Bachs\Whmcs\Domain\Failure('saved_method_required');
        }
        return (new \Bachs\Whmcs\Application\SavedMethods($app))->capture((int)$params['invoiceid'], $params['gatewayid']);
    } catch (Exception $e) {
        $reason = $e instanceof \Bachs\Whmcs\Domain\Failure ? $e->reason : 'capture_error';
        if ($e instanceof \Bachs\Whmcs\Infrastructure\Bachs\ApiFailure && $e->unknown) {
            return array('status' => 'pending', 'rawdata' => array('error_code' => 'collection_outcome_pending'));
        }
        return array('status' => 'declined', 'declinereason' => \Bachs\Whmcs\Http\Messages::error($reason), 'rawdata' => array('error_code' => $reason));
    }
}
function bachs_card_remoteinput($params)
{
    try {
        $app = \Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app();
        $app->bridge->assertAcceptance('native_card', $app->settings->get('environment', 'sandbox'));
        $url = $app->bridge->systemUrl() . '/index.php?m=bachs_admin';
        // No card fields are ever rendered by this gateway. Hosted setup requires explicit consent.
        return '<form action="' . \Bachs\Whmcs\Infrastructure\SafeData::html($url) . '" method="get" target="_top"><input type="hidden" name="m" value="bachs_admin"><button type="submit">Manage cards securely with Bachs</button></form>';
    } catch (Exception $e) {
        return '<p>Saved-card setup is unavailable. Use Bachs hosted invoice payment.</p>';
    }
}
function bachs_card_remoteupdate($params)
{
    return bachs_card_remoteinput($params);
}
function bachs_card_refund($params)
{
    try {
        $app = \Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app();
        $actor = $app->bridge->identity->admin('request_refunds');
        $app->bridge->identity->csrf();
        $payment = null;
        foreach ($app->store->listing('payments', array('invoice_id' => (int)$params['invoiceid']), 500) as $row) {
            if ($row['data']['transaction_id'] === $params['transid'] && $row['data']['gateway'] === 'bachs_card') {
                $payment = $row;
                break;
            }
        }
        if (!$payment) {
            throw new \Bachs\Whmcs\Domain\Failure('original_payment_not_mapped');
        }
        $r = (new \Bachs\Whmcs\Application\Refunds($app))->request($payment['id'], $params['amount'], 'Refund requested through WHMCS', $actor['actor']);
        return array('status' => 'error', 'rawdata' => array('refund_request' => $r['id'], 'message' => 'Refund tracked in Addons > Bachs. Reconcile the existing request; do not resubmit.'));
    } catch (Exception $e) {
        return array('status' => 'error', 'rawdata' => array('message' => 'Review this refund in Addons > Bachs.'));
    }
}
