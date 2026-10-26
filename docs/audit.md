# Code audit — 26 October 2026

This review covered the gateway entries, native WHMCS settings and permissions, checkout, saved cards, webhooks, payment posting, refunds, background jobs, storage, client/admin controls and installation package. It kept WHMCS in charge of invoices and renewal dates. No payout, subscription-management or other merchant features were added.

## Issues fixed

| Area | Problem found | Result |
|---|---|---|
| Payment state | A delayed checkout response could reopen a posted payment. | Conditional state changes preserve posted and completed records. Payment recognition is serialized. |
| Concurrent storage | Scheduling and metadata updates could discard details written by another process. | Scheduling leaves JSON untouched; JSON changes compare the original bytes and merge again after a conflict. |
| Key rotation | Encryption or database failure could split the saved key from its fingerprint. | The key is encrypted first, then the scope and key are committed together. |
| Key rotation during a request | An old scope could load a new API key and change the provider's retry identity. | The key must match the scope fingerprint before a provider client is created. |
| In-flight retries | Rejection of a retry could incorrectly mark the original in-flight operation as safe to replace. | The original outcome remains unknown until it is reconciled. |
| Interrupted preparation | An invoice or refund could remain blocked when the process stopped before linking its request. | Recovery finds the original durable request. Only a preparation with no request can be marked unsubmitted. |
| Customer mapping | Changed billing details could prevent recovery of a successful customer creation. | The original frozen request and cached result are reused. Definite rejection permits a corrected request; an unknown outcome does not. |
| Payment fees | Unknown fee bearers and fees larger than the payment could reach native accounting. | These receipts are held for review before posting. |
| Rejected refunds | Definite rejection was recorded as an unknown outcome. | It becomes a failed request, allowing a new authorized request. |
| Lost refund responses | Reconciliation ignored a saved successful response when the provider lookup lagged. | The saved response restores the original refund identity. |
| Prepared refunds | An interrupted, reviewed request could remain unsent indefinitely. | It resumes with the same body and retry key after fresh payment checks. Unknown writes are not resubmitted. |
| Refund settlement | A success status could be presented as ready for accounting without proof of the returned amount. | The requested and returned amounts must agree. Missing, partial or malformed settlement stays in review. |
| Partially failed refunds | A failed refund that had returned some money could permit another full refund. | It stays in review and blocks a replacement request. |
| Refund identity and accounting | A refund could change provider ID or have its completed accounting amount remapped. | Its provider ID and accounting amount stay fixed. Native refund matching also requires zero incoming value and the original-payment link. |
| Late refund responses | An older processing response could erase proven success. | Confirmed settlement is preserved and the contradictory response is flagged. |
| Saved cards | Consent ownership and card expiry were not fully checked. | Consent must belong to the same client and merchant. Expired cards are blocked before charging. |
| Native card deletion | Removing a WHMCS Pay Method did not revoke local consent for deferred work. | The native delete handler revokes the local method and its consent. |
| Card token errors | A corrupt encrypted token could leave a new invoice attempt stuck. | The token is checked before an attempt is created. |
| Card event recovery | A repeated event could fail after the original consent window. An exact checkout could be hidden by the consent-list limit. | Known methods use their recorded consent; an event with a checkout ID finds that checkout directly. |
| Webhooks | Malformed signed identities and header types could cause repeated exceptions. | Invalid signed envelopes are quarantined; invalid headers are rejected. Quarantined events cannot process money. |
| Background jobs | Exhausted jobs had no complete admin list or direct retry action. | A paginated native admin page exposes permission- and CSRF-checked retry of the existing job. |
| Client errors and consent | Permission errors could hide their own notice. Non-scalar consent values could be treated as approval. | Safe notices remain visible; consent requires an explicit valid form value. |
| Client return and portal | A denied user could be told to log in again; portal creation lacked a request limit. | Return handling distinguishes denied access from logged-out sessions; portal requests are limited. |
| Text and privacy | Byte truncation could break UTF-8 JSON. Refund previews included unnecessary payer contact/bank data. | Text truncation preserves encoding; previews include only allowed payment fields. |
| Deployment and diagnostics | An older module could accept a newer schema, and some callback failures lacked a useful reference. | Newer schemas are rejected clearly; private callback diagnostics include a reference. |

## Validation

- **269 PHP tests, 953 assertions and 7 client behavior tests pass.**
- The audit added 45 PHP regression/control tests. Forty-two regression scenarios were also run against the earlier release, commit `0840461a43c2f96dc629ef1a1a22331e5300ed1f`, and failed there as expected.
- Fault tests cover failed encryption, rolled-back key writes, lost request links/responses, interleaved database writes, late responses and failed refunds with partial settlement.
- The existing eight-process test still confirms one native posting for one receipt.
- Gateway discovery, WHMCS version boundaries, addon startup, native permission contracts, syntax, documentation links, secret scanning, package hashes and incomplete-upload checks remain part of release validation.

The local environment uses PHP 8.4.2, SQLite and provider/native fixtures. The JavaScript tests use a simulated DOM. These checks do not establish live Bachs acceptance, licensed WHMCS behavior, MySQL/MariaDB execution or rendered theme/browser compatibility. Those need a real installation and provider testing. This is a code audit, not an independent security certification.

## Contracts checked

- [WHMCS authentication](https://developers.whmcs.com/advanced/authentication) and [user permissions](https://developers.whmcs.com/api-reference/getuserpermissions).
- [WHMCS tokenised storage](https://developers.whmcs.com/payment-gateways/tokenised-remote-storage), including native method deletion.
- [Bachs idempotency](https://docs.bachs.io/guides/idempotency): key scope, unchanged request bodies, in-flight responses and uncertain writes.
- [Bachs payment fields](https://docs.bachs.io/api-reference/payments/object) and [refund fields](https://docs.bachs.io/api-reference/refunds/object), including actual refunded amounts.
