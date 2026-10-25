# Install and test Bachs for WHMCS

Use a separate licensed WHMCS test site first. Real Bachs payments, native accounting, browser themes, and the claimed version range have not yet been verified for this release.

## 1. Check the server

Use the PHP version supported by your WHMCS release. Both web PHP and cron PHP need cURL, OpenSSL, JSON, and PDO MySQL. The server needs HTTPS, a working certificate store, TLS 1.2, secure random bytes, and MySQL/MariaDB with InnoDB and utf8mb4.

The module targets WHMCS 7.0 through 9.1 with one ZIP. A PHP 5.6 syntax target does not mean a newer WHMCS release can run on PHP 5.6. See [compatibility](compatibility.md).

## 2. Upload and activate

1. Back up WHMCS and its database.
2. Extract `bachs-whmcs-1.0.0.zip` into the WHMCS root. It adds the `bachs` and `bachs_card` gateway files, the `bachs/` gateway folder, the callback, the `bachs_admin` addon, and the Bachs hook.
3. Block web access to `modules/gateways/bachs/lib/` and `modules/gateways/bachs/bin/`. Apache rules are included. For Nginx or another server, add equivalent rules for the exact WHMCS path. Keep the callback and client endpoints reachable.
4. Activate **Bachs** under addon modules and allow the staff roles that need access. Activation creates the module's own tables and checks encryption. It does not change core financial tables.
5. Activate **Bachs** under Payment Gateways. Leave **Convert To For Processing** disabled. Credentials and payment settings are managed in the addon.

On WHMCS 7.0 and 7.1, set the addon's **Local API execution identity** to a specific active admin username. Callbacks and cron need that identity without a browser session. No admin password is stored. Grant only the needed API permissions, including GetInvoice, GetClientsDetails, GetCurrencies, GetTransactions, and encryption. For first activation on those versions, configure a dedicated encryption key as described below if the API identity is not yet saved.

## 3. Protect secrets

By default, the module uses WHMCS EncryptPassword/DecryptPassword. You can instead set `BACHS_ENCRYPTION_KEY` in the web and cron service environments. Use a securely generated random value of at least 32 bytes and keep it outside the web root. Back it up safely.

Use the same encryption setup for web requests and cron. Do not replace the key or switch storage methods after saving credentials without a planned data migration. Losing the key can make stored credentials unreadable.

Enter API keys and signing secrets only in the authenticated addon or private server settings. Blank secret inputs keep the saved value. Saved values are never shown in the browser. Do not commit private settings or paste keys into issues.

## 4. Create the private test settings

The module requires a private JSON file, called an acceptance profile. It states the exact WHMCS/PHP versions and which payment paths the site owner has tested. It prevents an untested setup from silently accepting live payments.

1. Copy `docs/acceptance.example.json` to a private location outside the WHMCS web root.
2. Set `whmcs_version` and `php_version` to the exact installed versions. Web and cron PHP must match the profile.
3. On a separate test site, set `isolated_staging` to `true`.
4. After checking these setup steps, set `features.sandbox.collection` to `true` to begin sandbox tests. Keep every live entry `false`.
5. Set the service environment variable `BACHS_ACCEPTANCE_FILE` to the file's absolute path for web PHP and cron.
6. Enable other sandbox entries only when testing their specific flow. Enable live entries only after the related sandbox and native accounting checks pass.

Keep a private record of test dates, resource IDs, versions, and ledger results. A WHMCS or PHP upgrade needs a fresh review and an updated profile. Never mark an ordinary commercial invoice as a sandbox test. If using `nonfulfilling_test_invoices` instead of a separate staging site, WHMCS must prevent real service delivery for those invoice IDs.

`collection_precision` is needed only for optional converted hosted payments. It maps each tested collection currency/asset to its verified number of decimal places. Leave converted payments disabled until those values are confirmed.

## 5. Connect Bachs

Open **Addons > Bachs > Connection & settings**. Choose Sandbox, enter the sandbox secret key, and verify the connection. This reads the merchant identity, available methods, and checkout settings; it does not move money.

Use only the permissions required for customer, checkout, payment, portal, and optional refund access. Endpoint registration also needs webhook-management access. Check the current names in Bachs when creating the key. Payout, platform, dispute, and subscription-management permissions are unnecessary.

Each installation uses its own merchant account. A different merchant key cannot silently replace the original merchant. Switching Sandbox/Live affects new payments; existing attempts keep their original merchant and environment.

## 6. Set up webhooks

Copy the environment-specific callback URL from **Webhooks**. Keep any WHMCS subdirectory and the `environment` query value. Register it in Bachs with `event_source: account`, subscribe to the displayed payment/refund events, and save its signing secret in the addon.

You can also preview and register this module's endpoint through the API if the key has permission. The signing secret is saved encrypted. Manual setup does not need webhook-management API access.

Automatic signing-secret rotation is not included because the provider's references disagree about its behavior. Follow the provider's current process and save a replacement secret manually. The module accepts the previous local secret for at most 24 hours; this does not guarantee how long Bachs continues using it.

An accepted webhook means the event was saved for processing. Check the native invoice transaction to confirm that accounting finished.

## 7. Run the worker

Run the worker once with the correct PHP executable and the same private settings used by web PHP. Then add a cron entry, using your real paths:

```cron
* * * * * /path/to/php /path/to/whmcs/modules/gateways/bachs/bin/worker.php --limit=50 --seconds=20 >> /private/logs/bachs-worker.log 2>&1
```

Keep logs outside the public web root. Do not put secrets in cron arguments. `AfterCronJob` also processes a small batch of work, and `DailyCronJob` runs cleanup. Job leases allow both paths to run safely. The loop time is a budget; an in-flight network request can take longer. Network requests have separate timeouts.

Check **Diagnostics** for recent worker activity. Enable new payments only after the merchant connection, signing secret, worker, and private profile are valid.

## 8. Test and enable live payments

Use the [test plan](test-report.md). Start with one sandbox invoice. Confirm that it receives exactly one native WHMCS transaction even without a browser return. Test duplicate events, failed/expired payments, pending recovery, refunds, and the themes you intend to support.

Choose allowed invoice currencies and any hosted method restrictions. No selected restrictions means Bachs chooses the eligible methods. Test each enabled method and currency. Defaults are USD and NGN.

Verify live account permissions separately, save the live signing secret, complete the live profile entries, check cron, and select Live for new payments. Any real-money test needs the merchant's authorization.

The separate `bachs_card` gateway is optional. Enable it only after testing consent, native method enrollment, renewal capture, pending retries, decline, and accounting on the exact WHMCS version. Native enrollment starts at WHMCS 7.9. Read [payment workflows](workflows.md) for saved-card and refund steps.
