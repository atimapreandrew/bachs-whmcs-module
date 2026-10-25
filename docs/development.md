# Developer guide

## Code map

| Folder | Purpose |
|---|---|
| `modules/gateways/bachs.php` | Hosted gateway entry, metadata and native configuration fields |
| `modules/gateways/bachs_card.php` | Optional native saved-card gateway |
| `modules/gateways/callback/bachs.php` | Signed Bachs webhook receiver |
| `modules/gateways/bachs/lib/src/` | Application, domain, HTTP and provider/native/storage adapters |
| `modules/gateways/bachs/assets/` | Checkout and admin behavior scripts; no custom CSS |
| `modules/gateways/bachs/bin/` | Private optional CLI worker |
| `includes/hooks/bachs.php` | Native client-page output, cron and cleanup |
| `modules/addons/bachs_payments/` | Optional admin activity, refunds and diagnostics |
| `tests/` and `build/` | Local checks and release packaging |

## WHMCS integration

The entry files declare functions without loading the application. `_config()` returns only native field definitions; it makes no database or provider calls. This lets WHMCS discover and activate the gateway before the runtime, keys or tables exist. Both gateways include `whmcs.json` metadata for Apps & Integrations.

Runtime loads `getGatewayVariables('bachs')` from WHMCS and synchronizes the saved native fields through GatewayConfiguration. Configuration hashes prevent repeated provider checks. Failed checks disable new collections and retry at a bounded interval. The addon does not supply payment configuration. There is no import handler or custom role system. Schema::ensure installs the module tables once when needed; ordinary runtime requests only check the schema version.

The optional Bachs Payments addon renders activity through its native `_output()` handler. It checks the authenticated native administrator before opening the database. Page loading uses `Runtime::app(false)` to read local status without applying configuration or contacting Bachs; an explicit verification action applies the saved gateway fields. Native `Setting` and `CurrentUser` APIs provide installation values and permissions in admin, client and cron contexts. There is no admin footer injection on Payment Gateways. All settings remain in the gateway's native `_config()` fields. Addon navigation and forms use WHMCS's supplied `modulelink`, and filters preserve its module parameter. WHMCS controls addon role access; the application also checks native financial permissions and CSRF before protected actions. Addon activation and deactivation do not change gateway configuration.

ClientAreaFooterOutput adds payment status and card consent controls to the existing invoice and Pay Methods pages. Provider returns redirect into those pages. Remote-input review follows the native iframe POST and then uses WHMCS's Pay Methods page for explicit consent. No standalone branded shell or theme template changes are required.

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

Definitively rejected requests become failed attempts. Requests that were never sent, or have an explicit idempotency retry response, can resume with their original body and key after rechecking the invoice, settings, merchant and consent. Unknown financial writes are never automatically resubmitted. Authorized replay resets the job retry budget; ordinary scheduling leaves exhausted jobs alone. Receipt scans continue through older pages so they can find missed refund updates, and isolate malformed items for review. WHMCS's Unpaid and Payment Pending states have the same payable invoice snapshot; changed amounts or cancelled invoices still block posting.

Unexpected failures receive a short reference in the Activity Log, with a private PHP log fallback. Diagnostics record safe error codes, exception classes, source locations and numeric database error codes. They omit raw exception messages, SQL, traces, credentials and payloads. Bootstrap failures are caught by the addon, client, callback and worker entry points.

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

The native `_refund` functions are omitted: WHMCS has no pending refund result in that contract. Requests instead start in the Bachs Payments addon, where asynchronous provider state and subsequent native Record Only accounting remain explicit.

WHMCS references: [native configuration fields](https://developers.whmcs.com/payment-gateways/configuration), [module metadata](https://developers.whmcs.com/advanced/json-file), [output hooks](https://developers.whmcs.com/hooks-reference/output), [gateway installation](https://developers.whmcs.com/payment-gateways/installation-activation), [callbacks](https://developers.whmcs.com/payment-gateways/callbacks), [refunds](https://developers.whmcs.com/payment-gateways/refunds), and [remote input](https://developers.whmcs.com/payment-gateways/remote-input-gateway).

## Test status

The latest local run has 217 passing PHP tests with 771 assertions and 6 passing client behavior tests. It uses provider/native fixtures, PHP 8.4.2, and SQLite. An eight-process test checks duplicate posting. An isolated startup harness exercises the actual addon, schema, runtime and renderer against documented native contracts, including absent globals, incomplete settings, permission denial, database denial and failed activity logging. Package checks also open the assembled addon and confirm that a mixed upload produces a useful diagnostic. JavaScript uses a small simulated DOM.

Requires WHMCS 8.0 or later. Runtime PHP targets 7.2 syntax so the early WHMCS 8 releases can run it; use the PHP and ionCube versions required by your actual WHMCS release. WHMCS 7, pre-8 client authentication, old API administrator configuration and obsolete webhook signature headers are unsupported.

Real Bachs sandbox transactions, licensed WHMCS, MySQL/MariaDB and rendered themes remain unverified. Local tests use a modern PHP executable and do not establish compatibility with every supported PHP/WHMCS combination.

Before claiming production support, test each advertised version/theme and payment method, native posting/provisioning once, consent and capture retries, refunds/Record Only linkage, callbacks plus cron, interrupted workers, migrations, upgrades, and real browser behavior. Keep customer evidence and resource IDs private.

## Packaging

`make package` runs local checks, assembles `dist/whmcs-root/`, and creates one ZIP plus checksums. Runtime PHP already lives under the private gateway library, so the source module can be uploaded directly. The package adds release documentation and an integrity manifest. File/class hashes detect mixed gateway uploads. The optional addon is excluded from the gateway bootstrap requirements; packaging checks verify the gateway still loads when it is omitted. Tests, development tools, logs, and local backups do not ship.

Build published packages from a clean committed checkout. The manifest records the commit and source fingerprint; an uncommitted source tree must not be labeled as an exact committed release. Local outputs remain in ignored folders.
