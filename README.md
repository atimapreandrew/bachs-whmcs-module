# Bachs for WHMCS

A payment module for WHMCS invoices, with hosted checkout, payment confirmation, refunds, and optional saved-card billing.

Built by [Andrew Atimapre](https://github.com/atimapreandrew). Free to use, change, and share under the [MIT license](LICENSE.txt), including for commercial use. This is an independent project, not an official Bachs or WHMCS module. Each business uses its own Bachs account; there is no developer payment fee.

> **Release candidate:** local tests pass. Real Bachs payments, licensed WHMCS, and browser themes still need testing. Start on a separate test site.

## Features

- Hosted checkout or an overlay with a redirect fallback.
- Signed webhooks, server-side payment checks, and duplicate protection.
- Background checks for pending payments, even after the client leaves.
- Full and eligible partial refund tracking, with WHMCS Record Only accounting.
- Optional saved cards and automatic collection of WHMCS renewal invoices, with client consent.
- Staff payment records, settings, review tools, and logs.

WHMCS controls products and billing dates. Merchant payouts, split payments, virtual accounts, and Bachs-managed subscriptions are outside this module.

## Installation

1. Download the installation ZIP from [Releases](https://github.com/atimapreandrew/bachs-whmcs-module/releases), or [build it from source](CONTRIBUTING.md).
2. Extract it into your WHMCS root.
3. Activate the **Bachs** addon and allow the staff roles that need access.
4. Connect your Bachs account, save the webhook secret, and register the callback URL in Bachs.
5. Run WHMCS cron, activate the **Bachs** payment gateway, and test sandbox payments before selecting Live.

Setup is managed in the addon. No private JSON settings file or separate Node/Composer service is required. See the [installation guide](docs/installation.md) for requirements, webhooks, saved cards, refunds, and upgrades.

## Requirements and limits

- Targets WHMCS 7.0–9.1 on the PHP version supported by that WHMCS release. Licensed compatibility has not yet been verified.
- Requires HTTPS, cURL, OpenSSL, JSON, PDO MySQL, and InnoDB/utf8mb4 support.
- Automatic native card billing requires WHMCS 7.9 or later and is off by default.
- Refunds require a native WHMCS **Record Only** step after Bachs confirms success. NGN bank-transfer refunds are unsupported.
- USD and NGN are enabled by default. Test other currencies and converted payments before enabling them. Add-funds and mass-payment invoices are unsupported.

## Support and development

Read [Contributing](CONTRIBUTING.md), the [developer guide](docs/development.md), and [Security](SECURITY.md). Report ordinary bugs in [GitHub Issues](https://github.com/atimapreandrew/bachs-whmcs-module/issues) with versions and reproduction steps. Keep API keys, customer records, card tokens, and raw webhooks private.
