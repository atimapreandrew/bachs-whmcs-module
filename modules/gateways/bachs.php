<?php

if (!defined('WHMCS')) {
    die('Access denied');
}
require_once __DIR__ . '/bachs/bootstrap.php';

function bachs_MetaData()
{
    return array('DisplayName' => 'Bachs', 'APIVersion' => '1.1', 'DisableLocalCreditCardInput' => true, 'TokenisedStorage' => false);
}
function bachs_config()
{
    return array('FriendlyName' => array('Type' => 'System', 'Value' => 'Bachs'),
        'setupInfo' => array('FriendlyName' => 'Setup', 'Type' => 'text', 'Size' => '0',
            'Description' => 'Configure credentials, webhooks and payment policy in Addons > Bachs. Leave Convert To For Processing disabled.'));
}
function bachs_link($params)
{
    try {
        return \Bachs\Whmcs\Http\GatewayView::render(\Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app(), $params);
    } catch (Exception $e) {
        return '<p role="status">Bachs is temporarily unavailable for this invoice. Please contact billing support.</p>';
    }
}
function bachs_refund($params)
{
    try {
        $app = \Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app();
        $actor = $app->bridge->identity->admin('request_refunds');
        $app->bridge->identity->csrf();
        $payment = null;
        foreach ($app->store->listing('payments', array('invoice_id' => (int)$params['invoiceid']), 500) as $row) {
            if ($row['data']['transaction_id'] === $params['transid'] && $row['data']['gateway'] === 'bachs') {
                $payment = $row;
                break;
            }
        }
        if (!$payment) {
            throw new \Bachs\Whmcs\Domain\Failure('original_payment_not_mapped');
        }
        $refund = (new \Bachs\Whmcs\Application\Refunds($app))->request($payment['id'], $params['amount'], 'Refund requested through WHMCS', $actor['actor']);
        return array('status' => 'error', 'rawdata' => array('refund_request' => $refund['id'], 'message' => 'Asynchronous refund tracked in Addons > Bachs > Refunds. Recheck the existing request. Use Record Only after provider success; do not resubmit.'));
    } catch (Exception $e) {
        return array('status' => 'error', 'rawdata' => array('message' => \Bachs\Whmcs\Http\Messages::error($e instanceof \Bachs\Whmcs\Domain\Failure ? $e->reason : 'refund_error')));
    }
}
