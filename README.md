# Bachs for WHMCS

A payment module for WHMCS invoices, with hosted checkout, payment confirmation, refunds, and optional saved-card billing.

Built by [Andrew Atimapre](https://github.com/atimapreandrew). Free to use, change, and share under the [MIT license](LICENSE.txt), including for commercial use. This is an independent project, not an official Bachs or WHMCS module. Each business uses its own Bachs account; there is no developer payment fee.

## Features

- Hosted checkout or an overlay with a redirect fallback.
- All 11 Bachs invoice currencies, with currency switches in the WHMCS admin.
- Cards, NGN bank transfer, mobile money and crypto, with an admin choice of payment methods.
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

- Targets WHMCS 7.0–9.1 on the PHP version supported by that WHMCS release. See the developer guide for tested environments.
- Requires HTTPS, cURL, OpenSSL, JSON, PDO MySQL, and InnoDB/utf8mb4 support.
- Automatic native card billing requires WHMCS 7.9 or later and is off by default.
- Refunds require a native WHMCS **Record Only** step after Bachs confirms success. NGN bank-transfer refunds are unsupported.
- Invoice currencies: USD, NGN, GHS, KES, MWK, RWF, TZS, UGX, XAF, XOF and ZMW. Add-funds and mass-payment invoices are unsupported.

## Currencies and payment methods

Choose accepted invoice currencies and payment methods in **Addons > Bachs > Connection & settings**. Use all available methods or select individual card, bank-transfer, mobile-money and crypto options. Bachs determines availability for your account and each checkout.

Crypto payments support USDT on Tron, BNB Chain, Ethereum and Solana; USDC on BNB Chain; ETH on Ethereum; SOL on Solana; and BNB on BNB Chain. Crypto assets are collection methods for USD invoices with conversion enabled; invoices use the fiat currencies listed above. Other invoice currencies collect in their own currency. Individual crypto asset/network settings are managed in Bachs. Apple Pay and Google Pay appear through cards where available.

Currency and method coverage follows the [Bachs currency list](https://docs.bachs.io/guides/checkout/any-currency-checkout) and [payment method guide](https://docs.bachs.io/guides/payments/payment-method-support).

## Support and development

Read [Contributing](CONTRIBUTING.md), the [developer guide](docs/development.md), and [Security](SECURITY.md). Report ordinary bugs in [GitHub Issues](https://github.com/atimapreandrew/bachs-whmcs-module/issues) with versions and reproduction steps. Keep API keys, customer records, card tokens, and raw webhooks private.
