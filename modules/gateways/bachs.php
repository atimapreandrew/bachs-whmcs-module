<?php

if (!defined('WHMCS')) {
    die('Access denied');
}

function bachs_MetaData()
{
    return array('DisplayName' => 'Bachs', 'APIVersion' => '1.1', 'DisableLocalCreditCardInput' => true, 'TokenisedStorage' => false);
}
function bachs_config()
{
    // Discovery must work before installation, credentials or a database exist.
    $fields = array('FriendlyName' => array('Type' => 'System', 'Value' => 'Bachs'),
        'environment' => array('FriendlyName' => 'Environment', 'Type' => 'dropdown', 'Options' => array('sandbox' => 'Sandbox', 'live' => 'Live'), 'Default' => 'sandbox', 'Description' => 'Leave Convert To For Processing disabled. Callback URLs and payment activity appear below the gateway settings.'),
        'sandboxApiKey' => array('FriendlyName' => 'Sandbox secret API key', 'Type' => 'password', 'Size' => '60'),
        'sandboxSigningSecret' => array('FriendlyName' => 'Sandbox webhook signing secret', 'Type' => 'password', 'Size' => '60'),
        'liveApiKey' => array('FriendlyName' => 'Live secret API key', 'Type' => 'password', 'Size' => '60'),
        'liveSigningSecret' => array('FriendlyName' => 'Live webhook signing secret', 'Type' => 'password', 'Size' => '60'),
        'enabled' => array('FriendlyName' => 'Accept new payments', 'Type' => 'yesno', 'Description' => 'Enable after saving valid keys, registering webhooks and running cron.'),
        'testInstallation' => array('FriendlyName' => 'Separate test installation', 'Type' => 'yesno', 'Description' => 'Required for sandbox payments. Sandbox receipts must not pay ordinary production invoices.'),
        'maintenance' => array('FriendlyName' => 'Maintenance mode', 'Type' => 'yesno', 'Description' => 'Stop new payments while pending payments and refunds finish.'),
        'checkoutMode' => array('FriendlyName' => 'Checkout style', 'Type' => 'dropdown', 'Options' => array('redirect' => 'Hosted checkout', 'overlay' => 'Overlay with redirect fallback'), 'Default' => 'redirect'),
        'buttonLabel' => array('FriendlyName' => 'Payment button text', 'Type' => 'text', 'Size' => '40', 'Default' => 'Pay with Bachs'),
        'savedMethods' => array('FriendlyName' => 'Save cards', 'Type' => 'yesno', 'Description' => 'Let clients save USD or NGN cards with their consent.'),
        'automaticPayments' => array('FriendlyName' => 'Automatic card payments', 'Type' => 'yesno', 'Description' => 'Also enable Save cards and the Bachs saved card gateway.'),
        'usdConversion' => array('FriendlyName' => 'USD checkout conversion', 'Type' => 'yesno', 'Default' => 'on', 'Description' => 'Let clients pay USD invoices in another supported currency, including crypto. Enable adaptive pricing in Bachs too.'),
        'expiryMinutes' => array('FriendlyName' => 'Checkout expiry (minutes)', 'Type' => 'text', 'Size' => '6', 'Default' => '60', 'Description' => '1 to 1440 minutes.'),
        'retentionDays' => array('FriendlyName' => 'Keep completed checkout links (days)', 'Type' => 'text', 'Size' => '6', 'Default' => '30', 'Description' => '1 to 365 days. Financial history is preserved.'));
    $currencies = array('USD', 'NGN', 'GHS', 'KES', 'MWK', 'RWF', 'TZS', 'UGX', 'XAF', 'XOF', 'ZMW');
    foreach ($currencies as $code) {
        $fields['accept_' . $code] = array('FriendlyName' => 'Accept ' . $code . ' invoices', 'Type' => 'yesno', 'Default' => 'on');
    }
    $methods = array('USD_CARD' => 'USD cards', 'NGN_CARD' => 'NGN cards', 'NGN_BANK_TRANSFER' => 'NGN bank transfer',
        'MOMO_GHS' => 'Ghana mobile money', 'MOMO_KES' => 'Kenya mobile money', 'MOMO_MWK' => 'Malawi mobile money',
        'MOMO_RWF' => 'Rwanda mobile money', 'MOMO_TZS' => 'Tanzania mobile money', 'MOMO_UGX' => 'Uganda mobile money',
        'MOMO_XAF' => 'XAF mobile money', 'MOMO_XOF' => 'XOF mobile money', 'MOMO_ZMW' => 'Zambia mobile money', 'CRYPTO' => 'Crypto');
    foreach ($methods as $code => $label) {
        $fields['offer_' . $code] = array('FriendlyName' => $label, 'Type' => 'yesno', 'Default' => 'on');
    }
    $fields['offer_CRYPTO']['Description'] = 'Individual assets and networks follow Bachs checkout settings. Availability and minimums apply to every method.';
    return $fields;
}
function bachs_link($params)
{
    try {
        require_once __DIR__ . '/bachs/bootstrap.php';
        return \Bachs\Whmcs\Http\GatewayView::render(\Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app(), $params);
    } catch (Throwable $e) {
        return '<p role="status">Bachs is temporarily unavailable for this invoice. Please contact billing support.</p>';
    }
}
