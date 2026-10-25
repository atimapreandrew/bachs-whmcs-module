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
        'setupInfo' => array('FriendlyName' => 'Setup', 'Type' => 'text', 'Size' => '0', 'Description' => 'Enable optional automatic card payments in Addons > Bachs and test them before live use. Shared credentials are managed in the addon.'));
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
// WHMCS remote-input gateways declare that local card fields are not used.
function bachs_card_nolocalcc()
{
}
function bachs_card_remoteinput($params)
{
    try {
        return \Bachs\Whmcs\Http\CardInput::render(\Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app(), $params);
    } catch (Exception $e) {
        return '<p>Saved-card setup is unavailable. Use Bachs hosted invoice payment.</p>';
    }
}
function bachs_card_remoteupdate($params)
{
    // Bachs does not document editing an existing card token in place.
    return '<div class="alert alert-info" role="status">To change a Bachs card, add a new payment method, then remove the old one. Existing cards cannot be edited here.</div>';
}
