# Installation

Requires WHMCS 8.0 or later. Use the PHP version supported by your WHMCS release, with cURL, OpenSSL, JSON and PDO MySQL available to both web requests and cron. The database needs InnoDB and utf8mb4. Set the WHMCS System URL to an HTTPS address.

## Upload and activate

1. Upload the supplied `modules/` and `includes/` folders into the WHMCS root.
2. Confirm that `modules/gateways/bachs.php`, `modules/gateways/bachs/lib/autoload.php` and `includes/hooks/bachs.php` exist. Upload the whole folders, not just the gateway PHP file.
3. Open WHMCS's **Payment Gateways** interface and activate **Bachs**. Recent versions also expose the list through **Apps & Integrations → All Payment Gateways**.
4. Open **Manage Existing Gateways** and configure **Bachs** with the native WHMCS fields.
5. Keep **Convert To For Processing** disabled. Use Bachs's own USD conversion setting when needed.

The source folders are directly installable; no build step is needed on the WHMCS server. The release ZIP also includes checksums and a file-integrity manifest. The gateway creates its own tables on first runtime use. The gateway runs independently of the optional activity addon. There is no import step or separate settings file.

## Gateway settings

Select **Sandbox** or **Live** and enter the matching secret API key and webhook signing secret. WHMCS stores these as password fields. Sandbox payments also require **Separate test installation**. Use sandbox only on a separate test site.

Choose the accepted invoice currencies and payment methods with the checkboxes. At least one currency and one method must be selected. Add the same currency codes in WHMCS's currency settings. Bachs applies account approval, availability and payment minimums.

Choose hosted checkout or overlay checkout. You can also set the button text, checkout expiry, completed-link retention and maintenance mode. Maintenance blocks new payments while pending financial work finishes.

Save the native form first. If the activity addon is enabled, open **Addons → Bachs Payments → Status** and use **Verify saved gateway settings** to check the stored configuration. Configure webhooks and run cron before enabling **Accept new payments**. Check WHMCS's **Show on Order Form** when you want clients to use the gateway.

## Optional payment activity addon

Activate **Bachs Payments** under WHMCS's **Addon Modules** settings and select the administrator roles that should have access. Open **Addons → Bachs Payments** for payment records, refund requests, webhook checks, review items and diagnostics.

The addon uses native WHMCS controls. It has no API keys, currency switches or checkout settings; those stay in **Payment Gateways → Bachs**. Deactivating the addon only removes its admin pages. Gateway payments, callbacks and background processing continue.

Opening the addon does not contact Bachs or verify credentials. It can open before you finish gateway setup. Use **Verify saved gateway settings** when you want to check the saved connection.

## Webhooks

The optional addon shows callback URLs at **Addons → Bachs Payments → Webhooks and jobs**. You can also use the URLs below. Copy the callback for the environment, including any WHMCS subdirectory:

```text
https://billing.example.com/modules/gateways/callback/bachs.php?environment=sandbox
https://billing.example.com/modules/gateways/callback/bachs.php?environment=live
```

Register the callback in Bachs with event source `account`. Subscribe to the events shown on that page, then save the matching signing secret in the native gateway form. Keys and secrets never belong in callback URLs.

A delivery is acknowledged after it is authenticated and queued. The worker checks the actual provider payment before posting a native transaction. Changing a signing secret retains the previous local secret for up to 24 hours; follow Bachs's rotation process and verify a signed delivery afterward.

## Background processing

The included hook uses the existing WHMCS cron. Run cron after configuring the gateway, then check worker status under **Addons → Bachs Payments → Status** when the addon is enabled. New invoice collection waits for a healthy worker.

For faster checks, run the optional CLI worker every minute using your actual paths:

```cron
* * * * * /path/to/php /path/to/whmcs/modules/gateways/bachs/bin/worker.php --limit=50 --seconds=20 >> /private/logs/bachs-worker.log 2>&1
```

Keep logs private. Database leases coordinate multiple workers. Do not add a public HTTP cron endpoint.

## Saved cards and renewals

Enable **Save cards** in the main **Bachs** gateway. USD cards can be added without payment; NGN cards can be saved during an invoice payment. Clients manage them from WHMCS's normal **Payment Methods** page. Card details are entered at Bachs.

For automatic collection, activate the optional **Bachs saved card** gateway and enable **Automatic card payments** in the main Bachs settings. Use Bachs saved card for the invoices that should use automatic billing. The worker enrolls active, consented cards as native WHMCS Pay Methods.

WHMCS creates renewal invoices and controls collection dates. Each charge requires an owned active method, consent and the same invoice currency. Disabling a card method blocks new charges through that method. To replace a card, add a new Pay Method and remove the old one. Existing card tokens cannot be edited in place.

## Currencies and conversion

The invoice currencies are USD, NGN, GHS, KES, MWK, RWF, TZS, UGX, XAF, XOF and ZMW. Methods include USD/NGN cards, NGN bank transfer and mobile money for the other nine currencies.

The **Crypto** switch controls crypto collection for USD invoices. Its supported assets are USDT_TRC20, USDT_BEP20, USDT_ERC20, USDT_SOL, USDC_BEP20, ETH_ETH, SOL_SOL and BNB_BEP20. Individual assets and networks are selected in Bachs. Crypto codes are collection assets, not invoice pricing currencies.

Enable **USD checkout conversion** and adaptive pricing in Bachs to let clients pay USD invoices through eligible local currencies or crypto. The module verifies the payment and allocates the original USD invoice amount. Other invoice currencies collect in their own currency. Card saving always uses the invoice's USD or NGN card method.

## Refunds

Open **Addons → Bachs Payments → Payments**, inspect the payment, enter the refund amount and reason, and review the request before confirming. Some crypto refunds need a destination and network. Bachs permits only one nonfailed refund per payment, so a partial refund can prevent refunding the remainder later.

Requests are asynchronous. Recheck an existing request after a timeout. After Bachs confirms success, use **Manual Refund Processed Externally / Record Only** on the WHMCS invoice with the displayed amount, transaction ID and original-payment link. Recheck the refund to finish the accounting task.

The native gateway does not expose a synchronous refund button. NGN bank-transfer refunds are unsupported. Partial refunds require a verified invoice-currency mapping.

## Staff access and server settings

Payment settings require WHMCS's **Configure Payment Gateways** permission. The addon uses WHMCS's own addon role access and requires **List Transactions**. Refund actions also require **Refund Invoice Payments**, and diagnostic export requires **Client Data Export**. Staff who only manage payment activity do not need permission to edit gateway credentials.

Block web access to `modules/gateways/bachs/lib/` and `modules/gateways/bachs/bin/`. Apache rules are included. Other web servers need equivalent rules. Keep the callback and client endpoints reachable.

The module uses WHMCS encryption. Back up the WHMCS encryption configuration together with the database. No separate module encryption key is needed.

## Troubleshooting

- **Gateway missing:** confirm the entry file is directly at `modules/gateways/bachs.php`, refresh All Payment Gateways, and check the WHMCS activity log. The gateway list does not need API credentials or module tables to discover Bachs.
- **Addon will not open:** check the displayed reference in **Utilities → Logs → Activity Log**. The message identifies missing files, database permissions, staff access or an invalid System URL. Upload the complete ZIP when files do not match. The database user needs permission to create the module tables and indexes on first use.
- **Settings not applied:** save the native gateway form, then verify the saved configuration. Correct the message shown under Addons > Bachs Payments > Status.
- **No payment recorded:** check signed deliveries, worker health and the review list. A browser return does not prove payment.
- **Unknown outcome:** keep the existing request identity and recheck it before trying another payment.
- **Signature rejected:** check the environment, secret, server clock, callback URL and proxy rules.
- **Incomplete upload:** reinstall the complete folders. Packaged releases detect mixed files.

Unexpected failures include a short reference in the Activity Log or private PHP/worker log if activity logging is unavailable. These entries include the error type and source location, without exception messages, SQL, keys or tokens. Include the reference and your WHMCS/PHP versions when reporting a problem.

For later updates, back up the installation, enable maintenance, upload the complete folders and verify the gateway. Keep callbacks and cron running while financial work is pending.
