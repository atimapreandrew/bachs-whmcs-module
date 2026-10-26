<?php

if (!defined('WHMCS')) {
    die('Access denied');
}

function bachs_card_MetaData()
{
    return array('DisplayName' => 'Bachs saved card', 'APIVersion' => '1.1', 'DisableLocalCreditCardInput' => true, 'TokenisedStorage' => true);
}
function bachs_card_config()
{
    return array('FriendlyName' => array('Type' => 'System', 'Value' => 'Bachs saved card'));
}
function bachs_card_capture($params)
{
    try {
        require_once __DIR__ . '/bachs/bootstrap.php';
        $app = \Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app();
        if (empty($params['gatewayid']) || !is_string($params['gatewayid'])) {
            throw new \Bachs\Whmcs\Domain\Failure('saved_method_required');
        }
        return (new \Bachs\Whmcs\Application\SavedMethods($app))->capture((int)$params['invoiceid'], $params['gatewayid']);
    } catch (Throwable $e) {
        $reason = $e instanceof \Bachs\Whmcs\Domain\Failure ? $e->reason : 'capture_error';
        if ($e instanceof \Bachs\Whmcs\Infrastructure\Bachs\ApiFailure && ($e->unknown || $e->reason === 'IDEMPOTENCY_IN_PROGRESS')) {
            return array('status' => 'pending', 'rawdata' => array('error_code' => 'collection_outcome_pending'));
        }
        $reference = null;
        if (!$e instanceof \Bachs\Whmcs\Domain\Failure) {
            $reporter = __DIR__ . '/bachs/lib/src/Infrastructure/Diagnostics.php';
            if (is_file($reporter)) {
                require_once $reporter;
                $report = \Bachs\Whmcs\Infrastructure\Diagnostics::report($e, 'card_capture');
                $reference = $report['reference'];
            }
        }
        try {
            $message = \Bachs\Whmcs\Http\Messages::error($reason);
        } catch (Throwable $ignored) {
            $message = 'Bachs could not collect this payment. Please contact billing support.';
        }
        return array('status' => 'declined', 'declinereason' => $message, 'rawdata' => array('error_code' => $reason, 'reference' => $reference));
    }
}
// WHMCS remote-input gateways declare that local card fields are not used.
function bachs_card_nolocalcc()
{
}
function bachs_card_remoteinput($params)
{
    try {
        require_once __DIR__ . '/bachs/bootstrap.php';
        return \Bachs\Whmcs\Http\CardInput::render(\Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app(), $params);
    } catch (Throwable $e) {
        $reporter = __DIR__ . '/bachs/lib/src/Infrastructure/Diagnostics.php';
        if (is_file($reporter)) {
            require_once $reporter;
            \Bachs\Whmcs\Infrastructure\Diagnostics::report($e, 'card_input');
        }
        return '<p>Saved-card setup is unavailable. Use Bachs hosted invoice payment.</p>';
    }
}
function bachs_card_remoteupdate($params)
{
    // Bachs does not document editing an existing card token in place.
    return '<div class="alert alert-info" role="status">To change a Bachs card, add a new payment method, then remove the old one. Existing cards cannot be edited here.</div>';
}

// WHMCS calls storeremote when a native remote Pay Method is removed.
// Card entry and replacement continue through the hosted remote-input flow.
function bachs_card_storeremote($params)
{
    if (!isset($params['action']) || $params['action'] !== 'delete') {
        return array('status' => 'error', 'rawdata' => array('error_code' => 'use_hosted_card_entry'));
    }
    try {
        require_once __DIR__ . '/bachs/bootstrap.php';
        $app = \Bachs\Whmcs\Infrastructure\Whmcs\Runtime::app(false);
        $token = isset($params['gatewayid']) && is_string($params['gatewayid']) ? $params['gatewayid'] : '';
        $clientId = isset($params['clientdetails']['userid']) ? $params['clientdetails']['userid'] : null;
        if (!is_scalar($clientId) || !ctype_digit((string)$clientId) || (int)$clientId < 1 || !preg_match('/^[a-f0-9]{48}$/D', $token)) {
            throw new \Bachs\Whmcs\Domain\Failure('saved_method_access_denied');
        }
        (new \Bachs\Whmcs\Application\SavedMethods($app))->revoke($token, (int)$clientId);
        return array('status' => 'success', 'rawdata' => array('local_consent' => 'revoked'));
    } catch (Throwable $error) {
        $reporter = __DIR__ . '/bachs/lib/src/Infrastructure/Diagnostics.php';
        if (is_file($reporter)) {
            require_once $reporter;
            \Bachs\Whmcs\Infrastructure\Diagnostics::report($error, 'native_card_delete');
        }
        return array('status' => 'error', 'rawdata' => array('error_code' => 'card_removal_failed'));
    }
}
