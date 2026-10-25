<?php

namespace Bachs\Whmcs\Http;

class Messages
{
    public static function error($code)
    {
        $messages = array('gateway_disabled' => 'Bachs is temporarily unavailable for this invoice.',
            'invoice_not_payable' => 'This invoice is no longer payable. Return to your invoice for its current status.',
            'existing_attempt_requires_reconciliation' => 'An earlier checkout is being confirmed. Recheck this invoice before starting another payment.',
            'operation_in_progress' => 'Your payment request is already being processed. Please recheck shortly.',
            'client_login_required' => 'Sign in to your billing account to continue.',
            'client_payment_access_denied' => 'You do not have permission to manage payments for this billing account.',
            'invoice_access_denied' => 'You do not have access to this invoice.',
            'currency_not_enabled' => 'Bachs is unavailable for this invoice currency. Choose another payment method.',
            'payment_methods_required' => 'Select at least one payment method or choose all available methods.',
            'card_method_not_enabled' => 'Card payments in this currency are disabled. Choose another payment method.',
            'invoice_payment_method_unavailable' => 'No enabled Bachs payment method can collect this invoice currency. Choose another payment method.',
            'automatic_payment_consent_required' => 'Confirm your consent to save this card and collect future WHMCS invoices.',
            'invoice_changed_during_payment' => 'This invoice has changed. Open it again to continue with the current amount.',
            'sandbox_installation_confirmation_required' => 'Confirm this is a separate test installation before enabling sandbox payments.',
            'automatic_payments_disabled' => 'Automatic card payments are disabled in Bachs settings.',
            'whmcs_version_not_supported' => 'Bachs requires WHMCS 8.0 or later.',
            'native_currency_conversion_gate' => 'Turn off Convert To For Processing for the Bachs gateway.',
            'special_invoice_not_supported' => 'Bachs does not support add-funds or mass-payment invoices.',
            'partial_refund_currency_unproven' => 'The settlement currency has not been verified for a partial refund. Refresh reconciliation or request a full refund.',
            'payment_not_refundable' => 'Bachs does not currently allow a refund of this payment.',
            'refund_rail_not_supported' => 'This payment method requires an externally processed reimbursement.',
            'confirmation_invalid_or_expired' => 'This confirmation expired. Review the current payment details again.',
            'merchant_migration_confirmation_required' => 'This key belongs to a different merchant. Restore the original key and contact the maintainer before changing merchants.',
            'worker_not_healthy' => 'Run the Bachs CLI worker or WHMCS cron, then enable payments.',
            'merchant_not_connected' => 'Save your Bachs API key in Payment Gateways > Bachs.',
            'api_key_required' => 'Save the secret API key for the selected environment in Payment Gateways > Bachs.',
            'invalid_gateway_configuration' => 'Review the Bachs gateway settings. Use whole numbers within the ranges shown.',
            'configuration_failed' => 'Bachs could not apply the saved gateway settings. Verify the connection and check the WHMCS activity log.',
            'webhook_secret_required' => 'Save the webhook signing secret in Payment Gateways > Bachs before enabling payments.');
        if (isset($messages[$code])) {
            return $messages[$code];
        }
        return 'The request could not be completed. Recheck its status before trying again. Support reference: ' . preg_replace('/[^a-zA-Z0-9_]/', '', $code);
    }

    public static function state($state)
    {
        $map = array('posted' => 'Payment received.', 'setup_completed' => 'Card setup completed. Your saved card will appear after confirmation.', 'open' => 'Complete your payment in Bachs.',
            'pending' => 'Confirming your payment…', 'creating' => 'Preparing secure checkout…',
            'outcome_unknown' => 'Your payment is still being confirmed. You can return to this invoice later.',
            'review' => 'Your payment needs a review. Please contact billing support.',
            'failed' => 'Payment was not completed. Please try again or choose another method.',
            'expired_verified' => 'This checkout has expired.', 'cancelled_verified' => 'Checkout closed. You can continue when ready.');
        return isset($map[$state]) ? $map[$state] : 'Confirming your payment…';
    }
}
