# Code guide

The module connects WHMCS invoices to Bachs payments. WHMCS owns billing dates and invoices. Bachs collects the money. Local records connect the two systems and help recover interrupted requests.

## Folders

| Path | Purpose |
|---|---|
| `modules/gateways/bachs.php` | Hosted payment gateway and refund entry point |
| `modules/gateways/bachs_card.php` | Optional saved-card gateway |
| `modules/gateways/callback/bachs.php` | Signed notification receiver |
| `modules/gateways/bachs/` | Client endpoints, bootstrap, and CLI worker |
| `modules/addons/bachs_admin/` | Addon activation and staff/client pages |
| `includes/hooks/bachs.php` | WHMCS cron and client-deletion hooks |
| `src/Application/` | Checkout, verification, saved cards, refunds, and jobs |
| `src/Domain/` | Exact money helpers, identities, and small interfaces |
| `src/Infrastructure/Bachs/` | Fixed-host HTTP client and signature checking |
| `src/Infrastructure/Whmcs/` | Native permissions, API, accounting, and version differences |
| `src/Infrastructure/Storage/` | Module tables, encryption, database locks, and queue |
| `src/Http/` | Request checks, page rendering, and safe messages |
| `resources/` | CSS, JavaScript, and the client template |
| `tests/` | Fake provider/native adapters, integration tests, and client behavior tests |
| `build/` | Source checks and the installation ZIP builder |

## Checkout and confirmation

1. A logged-in client submits an invoice payment request.
2. `Checkout` checks the fresh invoice and reuses or blocks an earlier unfinished attempt.
3. `Operations` stores the encrypted request and a stable retry key before contacting Bachs.
4. The client opens the returned hosted checkout URL.
5. `Webhooks` verifies a notification and saves it with a background job before acknowledging it.
6. `Worker` calls `PaymentRecognition`, which retrieves the saved checkout and its payment from Bachs.
7. `PaymentRecognition` checks ownership, source, currency, actual value, and the current invoice.
8. `Bridge` finds an existing matching WHMCS transaction or records the payment through native helpers. The local record then stores its accounting link.

The browser never marks an invoice paid. A callback and cron job can run at the same time. Database leases, provider-payment identity, native invoice locks, and a stable transaction ID protect the accounting step. A restart after native posting recovers the existing transaction.

## Important records

All module tables start with `mod_bachs_`. The module never writes directly to WHMCS financial tables.

| Table group | Stored purpose |
|---|---|
| `settings`, `merchant_scopes` | Configuration and the original merchant/environment identity |
| `customers`, `payment_methods`, `consents` | Billing-account ownership, encrypted card tokens, and permission to charge |
| `attempts`, `operations` | The intended payment and the saved provider request/result |
| `payments`, `allocations` | Verified receipts and their WHMCS invoice accounting links |
| `webhook_events`, `jobs`, `locks` | Verified notifications and recoverable background work |
| `refunds` | Provider refund status and separate native accounting status |
| `reconciliation_items`, `audit_log` | Cases needing review and records of staff actions |
| `schema_migrations` | Completed module schema setup |

A scope means one installation, environment, and merchant context. Old operations retain their original scope when new-payment settings change. Never reassign a payment or token by matching email, amount, or payer text.

## States and recovery

Payment attempts move from `creating` to `open` or `pending`, then `posted` after verified native accounting. Failures can become `failed`, `expired_verified`, or `cancelled_verified`. `review` requires investigation. `outcome_unknown` means the provider may have accepted a request; a fresh replacement is blocked.

Jobs use expiring leases and bounded retries. Dead jobs stay stopped until an authorized replay or recheck. Replaying an event repeats verification against the same identities; it does not create a new charge.

Refunds track provider and accounting status separately. Provider success leads to `accounting_pending`. Staff use the native Record Only workflow. `RefundAdapter` detects the exact outgoing transaction before closing the task. It does not insert an automatic refund transaction.

## Saved cards

`SavedMethods` binds a token only after explicit consent and a matching verified session/customer. Provider tokens are encrypted. Native WHMCS stores a local method identifier. WHMCS creates the renewal invoice and calls the optional capture adapter; Bachs does not control a second billing schedule.

## Packaging

`build/package.py` copies only installable files into `dist/whmcs-root/`, places shared PHP under the gateway's private `lib/` directory, and creates one ZIP. The manifest records file hashes and the source commit when available. The runtime checks component hashes to detect mixed or incomplete uploads.

Tests, development tools, private settings, credentials, and local logs do not ship. Run `make package` from the source checkout. Upload the resulting installation ZIP contents, not the source tree.
