# Bachs for WHMCS

Accept Bachs payments for WHMCS invoices, confirm payments on the server, and manage refunds and saved cards.

**Built by [Andrew Atimapre](https://github.com/atimapreandrew).** Released under the [MIT license](LICENSE.txt). Anyone can use, change, or share this module, including for commercial use.

This is an independent community project. It is not an official Bachs or WHMCS module. Each business uses its own Bachs account and API keys. There is no developer payment fee.

> **Release candidate:** the local tests pass, but real Bachs payments and a licensed WHMCS installation have not been tested. Start on a separate test installation. Do not treat this version as production verified.

## What it does

- Lets clients pay an invoice through Bachs hosted checkout or an overlay.
- Receives signed payment notifications and checks the payment directly with Bachs.
- Records a verified payment in WHMCS and protects against duplicate entries.
- Checks pending payments in the background, even if the client closes the browser.
- Tracks full and eligible partial refunds. After Bachs confirms a refund, staff record it in WHMCS using **Record Only**, then recheck it in the module.
- Offers optional saved cards, client consent, card management, and a separate gateway for WHMCS renewal collection.
- Gives staff payment records, refund status, webhook tools, review items, logs, and settings.

WHMCS controls products, renewal invoices, and billing dates. This module does not manage merchant payouts, split payments, virtual accounts, dispute evidence, or a second subscription system.

## Before you install

- A licensed WHMCS test installation with HTTPS.
- A Bachs account, a sandbox API key, and a webhook signing secret.
- PHP with cURL, OpenSSL, JSON, and PDO MySQL. Use the PHP version supported by your WHMCS release.
- A MySQL or MariaDB database with InnoDB and utf8mb4 support.
- A working cron job and a private server file for the installation's test settings.

The source targets WHMCS 7.0 through 9.1 using one package. **Those versions are targets, not verified compatibility claims.** Read the [compatibility notes](docs/compatibility.md), especially before enabling saved-card collection.

## Install

Use the installation ZIP, not GitHub's source-code ZIP. If no release ZIP is available, build it from source:

```sh
git clone https://github.com/atimapreandrew/bachs-whmcs-module.git
cd bachs-whmcs-module
make package
```

Building needs PHP 8.2 or newer with PDO SQLite, SQLite3, and pcntl, plus Node.js, Python 3.9 or newer, and Make. These tools are for development; they are not needed on the WHMCS server. Windows users can build with WSL.

The command creates `dist/bachs-whmcs-1.0.0.zip` and `dist/SHA256SUMS`.

1. Back up WHMCS and its database.
2. Extract the installation ZIP into the WHMCS root.
3. Activate the **Bachs** addon and allow the staff roles that need access.
4. Open **Addons > Bachs**. Connect the sandbox account and configure signed webhooks.
5. Set up the private test settings and background worker.
6. Activate the **Bachs** payment gateway. Leave **Convert To For Processing** disabled.
7. Test checkout, payment confirmation, duplicate notifications, and refund accounting before enabling live payments.

Follow the [installation guide](docs/installation.md) for the full steps, server rules, private settings, and cron command. New payments are disabled until setup checks pass. The optional `bachs_card` gateway needs its own saved-card tests before activation.

## Important limits

- Refunds finish in two steps: confirmation at Bachs, then native WHMCS **Record Only** accounting. Automatic refund accounting is not enabled.
- NGN bank-transfer refunds are not supported. Partial refunds need a verified currency match.
- Saved-card charging requires consent and the same currency as the invoice. Native card enrollment starts at WHMCS 7.9 and still needs installation testing.
- Some currencies and payment methods depend on Bachs account approval and additional tests.
- Browser themes, old PHP versions, MySQL/MariaDB behavior, and the full WHMCS version range still need real installation testing.

See [feature status](docs/capabilities.md) and [test results](docs/test-report.md) for details.

## Documentation

| Guide | What it covers |
|---|---|
| [Installation](docs/installation.md) | Server setup, API keys, webhooks, test settings, and cron |
| [Payment workflows](docs/workflows.md) | Checkout, renewals, saved cards, and refunds |
| [Troubleshooting](docs/operations.md) | Pending payments, failed jobs, and accounting repair |
| [Compatibility](docs/compatibility.md) | Version targets and what has actually been tested |
| [Upgrade and removal](docs/upgrading.md) | Backups, upgrades, rollback, and pending payments |
| [Security](SECURITY.md) and [privacy](docs/privacy.md) | Secrets, access controls, and stored payment data |
| [Contributing](CONTRIBUTING.md) and [code guide](docs/architecture.md) | Local setup, code layout, and change rules |
| [API notes](docs/provider-contract.md) | Provider field choices and unresolved API questions |

## Help and contributions

Report bugs in [GitHub Issues](https://github.com/atimapreandrew/bachs-whmcs-module/issues). Include the module, WHMCS, and PHP versions, the safe support reference, and steps to reproduce the problem. Remove customer details and payment secrets first.

Report security problems privately using the process in [SECURITY.md](SECURITY.md). Please do not put API keys, card tokens, or customer records in an issue or pull request.
