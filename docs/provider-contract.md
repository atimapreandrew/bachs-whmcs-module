# API notes

The implementation was checked against official Bachs and WHMCS documentation on 2026-10-25. Local API objects are fake test data. They do not prove that a real merchant account accepts every request or returns the expected fields.

## Requests used by this module

| Flow | Decision | Official reference |
|---|---|---|
| API connection | Fixed sandbox/live hosts, Bearer authentication, top-level JSON resources, exact decimal strings in major units | [Authentication](https://docs.bachs.io/authentication), [API standards](https://docs.bachs.io/api-reference/api-standards) |
| Invoice checkout | `POST /v1/checkout-sessions` with invoice `pricing`, `customer.customer_id`, a stable reference, optional `payment_method_types`, and `save_payment_method` | [Create checkout](https://docs.bachs.io/api-reference/payments/create-checkout-session) |
| Confirmation | Retrieve the saved checkout, follow its `charge.payment_id`, then retrieve that payment. Never rewrite an ID prefix or trust browser metadata as payment proof | [Retrieve checkout](https://docs.bachs.io/api-reference/checkout-sessions/get-checkout-session), [payment object](https://docs.bachs.io/api-reference/payments/object) |
| Saved-card charge | Explicit owned customer/method, amount, currency, and reference. HTTP creation alone is not a completed payment | [Create charge](https://docs.bachs.io/api-reference/payments/create-a-charge) |
| Card management | Request a portal session with no body. Validate the returned `url` and never store it | [Portal session](https://docs.bachs.io/api-reference/customer-sessions/create-a-customer-portal-session) |
| Refund | Original `charge_id`, stable reference, `fee_bearer: org`, reason, body `idempotency_key`, and matching HTTP key. Full refunds omit amount. `success`, `processing`, and `failed` are separate states | [Create refund](https://docs.bachs.io/api-reference/refunds/create-refund) |

## Limits that need real tests

- **Methods and precision:** test each enabled method and invoice currency. RWF/XAF/XOF invoice pricing stays disabled. Optional converted payments need verified decimal precision for every collection asset/currency.
- **Payment states:** only a verified `succeeded` receipt can pay an invoice. Accepted, underpaid, overpaid, refunded, and unknown states need review. Open or incomplete payments stay pending.
- **Payment identity:** use the provider's saved IDs and exact resource links. Event `charge_id` is a reason to check, not a replacement for the payment ID returned by the saved checkout.
- **Refunds:** NGN bank-transfer refunds are unavailable. Partial refunds need proven settlement/invoice mapping. An imported partial refund starts without a guessed native amount.
- **Cards:** no invented list/delete endpoint is used. The client portal manages provider cards, and the module can disable local collection. Off-session charging requires the invoice currency; provider-side deletion synchronization is not claimed.
- **Overlay:** uses `Bachs.Initialize` and `Bachs.Checkout.open({checkoutUrl})`, with the same session as a redirect fallback. Browser behavior still needs a real sandbox test.
- **Unknown writes:** keep the saved request and retry key. Recover a dropped checkout response from stored success or authenticated checkout/reference evidence. No lookup endpoint is invented.
- **Unmatched deposits:** permanent-account and other unmatched receipts stay unallocated. They cannot automatically pay a hosting invoice.
- **Account approval:** API access and payment methods depend on the merchant's environment and provider permissions. Connection checks do not grant live eligibility.

## Signing-secret replacement

The [webhook guide](https://docs.bachs.io/guides/webhooks/overview) describes an overlap window. The [rotation endpoint](https://docs.bachs.io/api-reference/webhooks/rotate-an-endpoints-signing-secret) describes immediate invalidation and does not show a returned secret. Automatic API rotation is withheld until that difference is resolved. Manual encrypted replacement keeps the previous local secret for at most 24 hours; it does not promise provider overlap.

## WHMCS accounting

Incoming payments use native helpers after exact invoice/customer/currency/value checks. The bridge finds the transaction through GetTransactions, then reads its exact decimal and linkage fields from the native ledger. Core financial tables are never written directly.

Refund creation and accounting finish at different times. The gateway never returns success just because Bachs accepted a refund. Staff use native **Record Only** after provider success; the adapter then checks the exact outgoing transaction and original payment link. Automatic refund accounting remains disabled until version-specific parity is proved.

Native card enrollment uses the official remote-input helper on WHMCS 7.9 or newer, after installation tests. Earlier targets retain module-owned controls. Real legacy and current WHMCS accounting behavior still needs testing.

WHMCS references: [hosted gateways](https://developers.whmcs.com/payment-gateways/third-party-gateway), [callbacks](https://developers.whmcs.com/payment-gateways/callbacks), [refunds](https://developers.whmcs.com/payment-gateways/refunds), [remote input](https://developers.whmcs.com/payment-gateways/remote-input-gateway), and the [official helper example](https://github.com/WHMCS/sample-remote-input-gateway/blob/master/modules/gateways/callback/remoteinputgateway.php).
