# Upgrade or remove the module

## Upgrade

1. Back up WHMCS, the database, module files, and private configuration.
2. Stop new payments with draining mode. Keep the callback and worker running for existing payments and refunds.
3. Check pending requests, unknown results, and refunds waiting for accounting. Preserve their original keys and records.
4. Upload the whole new installation package. The file manifest detects incomplete or mixed uploads.
5. Run the addon upgrade step. Schema version 1 creates missing module tables/indexes and can resume interrupted setup. It does not drop existing financial data.
6. Run the worker, check diagnostics, and repeat the tests needed for the changed runtime or behavior. Confirm that old payment identities and native accounting links still match.

A WHMCS or PHP version change invalidates the private test profile until reviewed. Keep the encryption key and storage method unchanged unless you have a tested migration.

The package manifest records the source commit when built from Git and always records a source-file fingerprint. Build public release packages from a clean committed checkout so the source can be identified.

## Earlier development builds

An early, broader development build contained merchant-platform tools. The current module does not create their tables or run those operations. Existing tables are left intact. Old flags cannot restore removed actions.

If that build created a Bachs subscription, payout, or transfer, resolve it in the Bachs dashboard before upgrading. This module does not cancel those resources. Do not start a competing WHMCS collection while an old external subscription is active.

After backing up old files, remove its obsolete private `lib/src/Application/Connect.php`, `Finance.php`, `Disputes.php`, and `Subscriptions.php` files. The new package omits them and its manifest blocks loading unmatched leftovers. Keep financial database history.

## Roll back

Restore a complete compatible package. Do not drop tables or replace the encryption key to fix a file upload. There is no earlier public schema release to roll back to. Future releases must state whether an older version can read their schema before recommending a file-only rollback.

## Deactivate and finish pending work

Deactivation stops new checkout/capture and enables draining mode. It retains payment history, callback handling, the CLI worker, and the saved API execution identity. Keep the standalone CLI cron running if normal addon settings are removed.

Review every pending checkout/charge, unknown write, and provider-paid refund awaiting native accounting. Finish each case or document who will handle it. Deactivation does not refund money.

## Remove files

Once outstanding work has an owner and is complete, back up records and remove only this module's files. Remove its webhook endpoint only after no remaining payment needs it. Remove the cron entry last.

Module tables are preserved by default. There is no purge button or core-table deletion. Any permanent deletion should be a separate decision based on retention needs and verified backups. Never remove another integration's endpoint or WHMCS core data.
