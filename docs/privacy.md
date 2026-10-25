# Payment data handling

The module sends the billing client's name/email for provider customer creation, plus invoice amount/currency, opaque references and payment metadata. It does not synchronize service credentials, passwords, tickets or unrelated client fields. Bachs hosts card/3DS/OTP entry and customer card management.

Local records retain scope, invoice/client linkage, immutable operation identities, verification evidence, amounts/currencies and native accounting linkage. API/signing secrets, provider card tokens, recoverable operation requests and resumable checkout URLs are encrypted. Portal URLs are never stored. Webhook records retain selected processing fields and a raw-body digest rather than full payloads or payer/bank data.

Clients see only their own method display metadata and payment status. Authorized staff see payment operations; refund/export actions require separate permissions. Diagnostic exports omit credentials, tokens, bearer URLs, raw payloads and payer details.

Cleanup removes expired action confirmations and rate counters and clears terminal checkout resumption credentials after the configured interval. Financial identities, mappings, audit and consent/revocation history are retained. Client deletion revokes and removes local token credentials while retaining essential financial linkage. It does not delete provider-held history.

No automatic destructive purge occurs. Deactivation retains pending-payment/refund records for draining. Tables from the earlier broader candidate are left untouched if present; this release does not read or export them. Establish the merchant's financial retention policy before any separately authorized deletion.
