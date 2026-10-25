# WHMCS payment workflows

## Invoice payment

The Bachs gateway displays the fresh invoice balance. Rendering the invoice creates no provider session. An authorized POST checks billing-account ownership, status, gateway, currency and current balance. An unresolved attempt blocks a competing charge; a matching open attempt can be resumed.

The scoped provider customer, immutable invoice intent and encrypted operation are stored before checkout creation. A native form receives a redirect; overlay mode uses the same approved session URL for its fallback. Sensitive payment entry stays at Bachs.

Signed ingress authenticates raw bytes, timestamp and merchant scope, then persists the event and queue job before acknowledging. The worker retrieves the saved checkout and canonical payment. Only an exact verified receipt for the current invoice can be posted through WHMCS payment helpers. Deterministic native transaction lookup recovers a crash after posting without posting twice. The browser reads local status and cannot mark an invoice paid.

Changed, canceled, reassigned or already-paid invoices enter review with the receipt retained. Underpayments, excess payments, unknown states, setup completion and permanent deposits do not silently settle the original invoice. A review annotation does not create an accounting entry.

## WHMCS renewals and saved cards

WHMCS generates renewal invoices and controls collection timing. There is no parallel Bachs subscription schedule or product catalog. Eligible invoice opt-in records versioned consent to save a card and collect WHMCS renewal invoices. USD zero-charge setup is available separately; NGN cards are saved during invoice payment.

A signed saved-method event must match a verified customer and an explicitly consenting completed session. Tokens are encrypted; clients can disable local automatic collection and open the Bachs portal. No provider token is used as a public identifier or selected by email/default-card guessing.

The optional `bachs_card` gateway charges a specifically owned, active, consenting method against a fresh same-currency invoice. Its native callback returns pending while the shared reconciler owns accounting. Activate it only after the exact WHMCS version's native enrollment/capture/retry tests pass. Older targets retain module-owned card controls without claiming native enrollment support.

## Refunds

Open a payment in the addon, enter amount/reason and review the original provider payment and refund restrictions. Crypto refunds require a verified destination and network. Confirm the fixed preview. Unsupported rails and unproven partial settlement/invoice mappings are rejected.

One nonfailed refund can own a payment. Processing, unknown, provider-paid and native-accounting states are distinct. Lost responses are recovered through the original charge; staff must not submit a replacement request. Dashboard-created refunds are imported once. An external partial refund starts without a guessed invoice allocation and requires an authorized amount/evidence mapping.

After provider success, use WHMCS **Manual Refund Processed Externally / Record Only** with the displayed amount, currency, deterministic transaction ID and original transaction linkage. Recheck afterward: the module detects the exact native outgoing transaction and closes the task. The gateway refund callback returns a tracked non-success message while this asynchronous workflow is incomplete. Automatic outgoing ledger posting is not enabled.

## Administration

The addon contains only connection/settings, payments, refunds, saved methods, signed webhooks, reconciliation, diagnostics and audit. Refund and export permissions require explicit module role grants in addition to WHMCS native permissions and addon access. Replay and recheck reuse stored verified identities; they cannot bypass amount/ownership checks or create a new charge.
