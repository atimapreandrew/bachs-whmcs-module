# Test status

## Recorded local checks

| Check | Result | Meaning |
|---|---|---|
| PHP tests | 113 passed, 305 assertions | Exact money, signatures, permissions, checkout, duplicate protection, posting recovery, refunds, consent, jobs, and scope checks against fake provider/WHMCS adapters |
| Client JavaScript tests | 6 passed | Duplicate-click protection, blocked-SDK fallback, pending status, focus return, URL checks, and native form fallback in a small simulated DOM |
| Source checks | Passed | Current PHP/JavaScript syntax and selected source security rules |
| Documentation checks | Passed | Local Markdown links and a public-source secret-pattern scan |
| Old-PHP token check | Passed | Selected modern-only syntax is absent; this is not a real PHP 5.6 execution test |
| Installation ZIP checks | Passed | File paths/hashes, required files, private-file exclusions, packaged loading, and rejection of an incomplete upload |

The recorded run used PHP 8.4.2 on macOS, PDO SQLite, and Node.js 25.7.0. An eight-process SQLite test checks that one receipt cannot be posted twice. [test-results.json](test-results.json) records the PHP test totals. These are local results, not provider or licensed WHMCS acceptance.

GitHub Actions is configured to run local checks on PHP 8.2, 8.3, and 8.4. A configured workflow is not evidence that those hosted runs have passed. Read the actual Actions results after publication.

## Still required before production use

1. **Bachs sandbox:** real checkout for every enabled method/currency, success/decline, pending/expiry, missing browser return, fees, converted currency precision, real signed deliveries, tampered events, retries, duplicate delivery, and signing-secret replacement.
2. **Native WHMCS payments:** supported PHP/ionCube/database versions, exactly one native transaction and service delivery, partial balances/credits, changed invoices, special invoice types, client/contact/account permissions, callback/cron races, and recovery before/after native insertion.
3. **Saved cards:** real consent, setup, enrollment, opt-out, portal access, off-session success/decline/authentication requirements, pending retries, and manual/capture races on each claimed native adapter.
4. **Refunds:** full/eligible partial/failed refunds, lost-response recovery, dashboard-created refunds, one-refund protection, verified invoice-currency mapping, native Record Only detection/linkage, balances, and applicable invoice immutability/credit-note rules. Automatic refund posting is not enabled.
5. **Database and runtime:** MySQL/MariaDB schema and unique indexes, transaction/advisory locks, killed workers, old PHP executables, OpenSSL randomness/TLS, and WHMCS encryption.
6. **Browser and themes:** real desktop/mobile views, advertised client/admin themes, keyboard/screen reader behavior, no JavaScript, blocked SDK/CSP, slow/lost network, refresh/back, multiple tabs, login loss, and return URL path/query handling. Rendered browser checks have not been run.
7. **Installation and operations:** custom admin folders, WHMCS subdirectories, separate CLI PHP, proxies/WAF, draining, partial uploads, interrupted setup, key/environment changes, dead jobs, cleanup, and client deletion.

Keep real resource IDs and customer evidence private. Publish only safe summaries with exact versions and scenarios. Do not label fake provider results as sandbox results or a syntax target as tested compatibility.

## Release status

This is a release candidate under the MIT license. It is ready for source review and controlled testing. No real provider payment or refund has been executed, no licensed WHMCS combination is verified, and an independent security review is still recommended before a production release.
