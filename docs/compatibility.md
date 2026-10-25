# Compatibility

## Targets

One installation ZIP targets WHMCS 7.0 through 9.1. The shared runtime uses PHP 5.6 syntax and chooses version-specific identity and card behavior.

| WHMCS target | Important differences |
|---|---|
| 7.0–7.1 | Needs an explicitly configured local API admin identity for callbacks and cron |
| 7.2–7.8 | Uses the legacy client/contact permission path; native remote-card enrollment is unavailable |
| 7.9–7.10 | Can use the native card helper after installation tests |
| 8.0–8.13 | Checks the signed-in user and active billing account separately |
| 9.0–9.1 | Needs native invoice/refund accounting tests, including applicable invoice immutability rules |

These are implementation targets. **No licensed WHMCS version has been verified yet.** Always use the PHP, ionCube, and database versions supported by the chosen WHMCS release. The module's syntax target does not change WHMCS's server requirements.

## What has been tested

The recorded local run uses PHP 8.4.2 on macOS and PDO SQLite. It covers syntax checks, selected old-PHP token checks, fake provider/native flows, exact money, signatures, retry recovery, and an eight-process duplicate-payment test. JavaScript tests use a small simulated DOM.

No real Bachs sandbox transaction, licensed WHMCS installation, MySQL/MariaDB server, or rendered browser/theme run has been verified. Older PHP executables were not used. A token check is not an actual PHP 5.6 runtime test. GitHub Actions is configured for local tests on PHP 8.2, 8.3, and 8.4; see its actual runs before claiming a pass.

## Before claiming support

Test the latest patch of every minor version you advertise, on its supported PHP/ionCube/database combination. Record the exact versions, gateway, client/admin themes, scenarios, and results privately. Test native posting once, callbacks plus cron, saved-card retries, refunds, upgrades, and failures.

Only advertise themes that have been checked in a real browser. The source includes fixtures, but they do not establish a rendered-layout pass. See [test evidence and the acceptance plan](test-report.md) and [machine-readable status](compatibility.json).

Reference: [WHMCS 9.1 release notes](https://docs.whmcs.com/releases/9-1/9-1-release-notes/).
