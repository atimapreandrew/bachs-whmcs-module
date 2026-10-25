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
