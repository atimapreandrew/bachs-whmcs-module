# Installation

Use a separate licensed WHMCS test site first. Check that web PHP and cron PHP meet WHMCS's requirements and provide cURL, OpenSSL, JSON, and PDO MySQL. The database needs InnoDB and utf8mb4, and the WHMCS System URL must use HTTPS.

## Install the module

1. Back up WHMCS and its database.
2. Extract the installation ZIP into the WHMCS root. Keep its folder structure.
3. Activate **Bachs** under addon modules and allow the staff roles that need access.
4. Open **Addons > Bachs > Connection & settings**. Select Sandbox, enter your sandbox secret API key, and verify it.
5. On your test site, confirm **This is a separate WHMCS test installation**. Sandbox collection stays blocked until this is confirmed.
6. Set up webhooks below, run cron once, then enable new payments.
7. Activate the **Bachs** payment gateway. Leave **Convert To For Processing** disabled.

The addon creates only its own tables. Credentials are encrypted using WHMCS's encryption functions. Blank secret fields keep the saved values. No private JSON file is needed, and PHP/WHMCS upgrades do not require a version certificate.

On WHMCS 7.0–7.1, set the addon's **Local API execution identity** to a specific active admin username for callbacks and cron. No admin password is stored. If encryption is needed before that identity is saved, configure an optional dedicated key as described under server settings.

## Webhooks

In the addon's **Webhooks** page, copy the URL for the selected environment, including any WHMCS subdirectory. Register it in the Bachs dashboard with event source `account`. Subscribe to the event names shown by the module, then save the signing secret in the addon.

API keys and signing secrets stay out of callback URLs. An accepted event is queued for checking; the native invoice transaction confirms that accounting finished. When replacing a secret, the old local value is accepted for up to 24 hours. Follow Bachs's current rotation process and check a real signed delivery afterward.

## Background processing

The included hook uses your existing WHMCS cron to check pending payments and refunds. Run cron once after connecting Bachs so the module can confirm that background processing works.

For faster checks, you can also run the CLI worker every minute using your real paths:

```cron
* * * * * /path/to/php /path/to/whmcs/modules/gateways/bachs/bin/worker.php --limit=50 --seconds=20 >> /private/logs/bachs-worker.log 2>&1
```

Keep logs private. Multiple workers use database leases to avoid duplicate work. Do not add a public HTTP cron endpoint.

## Saved cards and renewals

Card saving and automatic billing are separate options and start disabled. To use them, enable card saving, test customer consent and card management, then enable automatic card payments and activate `bachs_card`. Native automatic billing requires WHMCS 7.9 or later.

WHMCS still creates invoices and controls renewal dates. Each charge requires an active method owned by that client, recorded consent, and the same invoice currency. Clients can turn off local automatic payments and manage their provider cards through Bachs. USD cards can be added without a charge; NGN cards are saved during an invoice payment.

## Refunds

Open a recorded payment, enter the amount and reason, review the original payment, and confirm. Some crypto refunds also need a verified destination and network. Only one nonfailed refund is allowed per payment; a partial refund can prevent a later refund of the remainder.

Refunds are asynchronous. Recheck the existing request after a timeout instead of submitting another. Once Bachs confirms success, use **Manual Refund Processed Externally / Record Only** on the WHMCS invoice with the displayed amount, transaction ID, and original-payment link. Recheck in Bachs to close the accounting task. The module does not automatically insert outgoing refund accounting.

NGN bank-transfer refunds are unsupported. Partial currency mappings must be verified. Imported dashboard partial refunds start without a guessed invoice amount and need a reviewed mapping before native recording.

## Live payments and advanced settings

Test actual checkout, failure, expiry, duplicate notifications, cron recovery, refunds, and your themes. Enter and verify the live key, register the live callback/secret, select Live, and enable payments. Never test sandbox payments on ordinary production invoices.

Advanced settings control allowed invoice currencies, checkout methods, expiry, and saved-link retention. Leave method restrictions empty for Bachs's eligible selection. USD/NGN are the defaults. RWF/XAF/XOF invoice pricing, add-funds, and mass-payment invoices are unsupported.

Converted USD payments are optional and off by default. Enable them only after checking real receipts. For assets outside the known two-decimal fiat currencies, enter the verified precision as `CODE=decimal places`, one per line. Unknown precision, incomplete collection, or a changed invoice sends the receipt to review instead of marking it paid.

Staff access uses normal WHMCS addon access and native role permissions. Refunds require **Refund Invoice Payments**; diagnostic export requires **Client Data Export**. There is no second module role list.

## Server settings

Block web access to `modules/gateways/bachs/lib/` and `modules/gateways/bachs/bin/`. Apache rules are included. Other servers need equivalent rules for the exact installation path. Keep the callback and client endpoints reachable.

An optional `BACHS_ENCRYPTION_KEY` can replace native WHMCS encryption. Use a securely generated value of at least 32 bytes, outside the web root, with the same environment for web and cron PHP. Back it up. Do not switch encryption methods or replace an existing key without a tested migration.

## Troubleshooting and upgrades

- **No payment recorded:** check the signed webhook, worker health, provider receipt, and review items. A browser return is not proof of payment.
- **Unknown request result:** keep its original identity and recheck. Do not delete it to create a replacement.
- **Signature rejected:** check the secret, environment, server clock, exact callback URL, and proxy/WAF rules.
- **Dead job:** fix its cause, then use authorized Replay/Recheck. Exhausted jobs do not restart automatically.
- **Review needed:** inspect the actual amount, currency, customer, source, and current invoice. A review note does not allocate money.
- **Incomplete upload:** reinstall the complete package. Keep the file integrity check enabled.

Before upgrading, back up files/data, stop new payments with maintenance mode, and keep callbacks and cron running. Upload the whole package, run the addon upgrade step, and test again. Preserve original keys and pending records. If moving from an old development build, do not enable automatic billing while any separately created Bachs subscription remains active.

Deactivation stops new collections and preserves financial history. Finish or hand over pending payments and refund accounting before removing files, the module's webhook endpoint, and finally its cron entry. Module tables are kept by default; no destructive purge is included.
