# Developer guide

## Code map

| Folder | Purpose |
|---|---|
| `modules/gateways/` | Hosted and optional card gateways, callback, client endpoints, CLI worker |
| `modules/addons/bachs_admin/` | Activation and staff/client pages |
| `includes/hooks/` | WHMCS cron and client cleanup hooks |
| `src/Application/` | Checkout, verification, refunds, saved cards, and jobs |
| `src/Domain/` | Exact money, IDs, and small adapter interfaces |
| `src/Infrastructure/` | Bachs HTTP/signatures, WHMCS adapters, storage, and encryption |
| `src/Http/` | Request checks, pages, and safe messages |
| `resources/` | CSS, JavaScript, and the client template |
| `tests/` and `build/` | Local checks and installation packaging |

## Payment flow

1. An authorized client POST reads the current invoice and acquires its initiation lock.
2. Checkout reuses a matching open attempt or blocks a conflicting unfinished attempt.
3. The encrypted provider request and stable retry key are saved before sending.
4. A signed webhook is saved and queued before acknowledgment.
5. The worker retrieves the stored checkout and its canonical provider payment.
6. PaymentRecognition checks ownership, source, actual amount/currency, and the fresh invoice.
7. The WHMCS bridge finds an existing transaction or posts once through native helpers.

Every incoming payment path uses the same recognition service. The browser only reads local status. A scope identifies the installation, merchant, and environment; pending operations retain their original scope when new-payment settings change.

All tables start with `mod_bachs_`. Attempts store the intended payment; operations store requests and outcomes; payments/allocations link verified receipts to native accounting. Other tables hold customers, encrypted methods, consent, refunds, events, jobs, locks, settings, review items, migrations, and audit. Core financial tables are never written directly.

## Recovery rules

Payment attempts move through creating/open/pending to posted, or to a verified terminal failure. Review means the receipt needs investigation. Unknown means the provider may have accepted a write; a replacement charge is blocked.

Database leases and native posting locks protect concurrent work. Stable native transaction IDs recover a crash after posting without posting twice. Job retries are bounded; replay keeps the original financial identities.

Provider refund status and native accounting status remain separate. RefundAdapter only detects an exact outgoing Record Only transaction and original-payment linkage. It does not invent automatic accounting parity.

## Provider API choices

Use the current [Bachs docs](https://docs.bachs.io/llms.txt) before changing a field or endpoint.

- Fixed live/sandbox hosts, Bearer authentication, verified TLS, no redirects, bounded requests/responses, decimal strings in major units.
- [Checkout](https://docs.bachs.io/guides/checkout/checkout-sessions) uses invoice pricing, a scoped customer, reference, and optional exact method restrictions.
- [Verification](https://docs.bachs.io/api-reference/checkout-sessions/get-checkout-session) follows the saved checkout's `charge.payment_id` to the [payment object](https://docs.bachs.io/api-reference/payments/object). Do not rewrite ID prefixes or use event/browser metadata as accounting proof.
- [Saved-card charges](https://docs.bachs.io/api-reference/payments/create-a-charge) use an explicit owned method, currency, value, and reference. HTTP creation is not financial success.
- [Refunds](https://docs.bachs.io/api-reference/refunds/create-refund) retain the original charge/reference and matching body/HTTP retry key. Full refunds omit amount. Success, processing, and failure are distinct.
- Portal URLs are short-lived credentials and are never stored. Card deletion is managed by the provider portal; no undocumented API is invented.
- Signing-secret rotation stays manual because the provider's guide and endpoint reference differ about overlap and returned secrets.

PaymentOptions holds the documented 11 fiat pricing currencies, 13 method corridors and eight crypto collection assets. Invoice amounts use two-decimal major-unit strings. Crypto comparisons keep up to 18 decimal places without rounding. The checkout freezes its allowed methods and conversion policy; receipt verification maps `CARD` plus its currency back to the original corridor. Method restrictions are explicit in every invoice checkout request. Same-currency checkout uses `billing_currency` and only corridors that collect that currency. USD conversion also requires adaptive pricing in the Bachs account; the create-session API does not document a per-session `adaptive_pricing` field. Bachs controls individual crypto assets on the merchant account; the module does not change account-wide settings.

CardInput binds native invoice/setup forms to the signed-in actor and merchant scope with a short-lived, single-use confirmation and CSRF. It stores no card fields and performs no provider writes during rendering. WHMCS auto-posts the initial form into its iframe to display consent; only the client's second POST consumes the intent and opens hosted checkout. New-card invoice checkout uses the fresh invoice snapshot; add-only setup never reads an invoice. Remote updates explicitly explain how to replace a card. TokenAdapter serializes native enrollment, treats `createCardPayMethod` as a boolean helper, and retrieves the actual ID through client-scoped `GetPayMethods`, matching the gateway and local opaque token. An interrupted creation is reconciled before any retry. When automatic billing is enabled later, the worker also enrolls earlier active, consented cards from the current merchant scope without charging them.

The native `_refund` functions are omitted: WHMCS has no pending refund result in that contract. Requests instead start in the addon, where asynchronous provider state and subsequent native Record Only accounting remain explicit.

WHMCS references: [gateway installation](https://developers.whmcs.com/payment-gateways/installation-activation), [callbacks](https://developers.whmcs.com/payment-gateways/callbacks), [refunds](https://developers.whmcs.com/payment-gateways/refunds), and [remote input](https://developers.whmcs.com/payment-gateways/remote-input-gateway).

## Test status

The latest local run has 171 passing PHP tests with 475 assertions and 6 passing client behavior tests. It uses fake provider/native adapters, PHP 8.4.2, and SQLite. An eight-process test checks duplicate posting. JavaScript uses a small simulated DOM.

Real Bachs sandbox transactions, licensed WHMCS, MySQL/MariaDB, older PHP executables, and rendered themes remain unverified. A PHP 5.6 token check is not a real legacy runtime test. Targets are WHMCS 7.0–9.1 with native card enrollment from 7.9; always use the PHP/ionCube/database versions supported by WHMCS.

Before claiming production support, test each advertised version/theme and payment method, native posting/provisioning once, consent and capture retries, refunds/Record Only linkage, callbacks plus cron, interrupted workers, migrations, upgrades, and real browser behavior. Keep customer evidence and resource IDs private.

## Packaging

`make package` runs local checks, assembles `dist/whmcs-root/`, and creates one ZIP plus checksums. Shared PHP is placed under the private gateway library. File/class hashes detect mixed uploads. Tests, development tools, logs, and local backups do not ship.

Build published packages from a clean committed checkout. The manifest records the commit and source fingerprint; an uncommitted source tree must not be labeled as an exact committed release. Local outputs remain in ignored folders.
