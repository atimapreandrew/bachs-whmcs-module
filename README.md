# Bachs for WHMCS

A WHMCS payment gateway for invoice payments, signed payment confirmation, refunds and optional saved-card billing.

Built by [Andrew Atimapre](https://github.com/atimapreandrew). Free to use, change and share under the [MIT license](LICENSE.txt), including for commercial use. This is an independent project, not an official Bachs or WHMCS module. Each business uses its own Bachs account; there is no developer payment fee.

## Install

1. Download the installation ZIP from [Releases](https://github.com/atimapreandrew/bachs-whmcs-module/releases), or download the source.
2. Upload the `modules/` and `includes/` folders into your WHMCS root. Keep their folder structure.
3. Open **Payment Gateways → All Payment Gateways**, find **Bachs** and activate it. Recent WHMCS versions also list payment gateways under **Apps & Integrations**.
4. Enter your API key and webhook signing secret in the native **Bachs** gateway form. Select the environment, currencies and payment methods, then save.
5. Register the callback in the installation guide, keep WHMCS system cron running every five minutes, and enable **Accept new payments** and WHMCS's **Show on Order Form** after the first completed run.

All payment settings use WHMCS's normal Payment Gateways interface. The client pages use the active WHMCS theme. No custom stylesheet, Composer install or frontend build is required.

For payment history, refund tools and diagnostics, activate the optional **Bachs Payments** addon, assign staff access and open **Addons → Bachs Payments**. The addon has no payment settings, and disabling it does not stop the gateways, callbacks or cron.

Read the [installation guide](docs/installation.md) for requirements, callback setup and saved cards.

## Features

- Hosted checkout or an overlay with a redirect fallback.
- All 11 Bachs invoice currencies, with native admin switches.
- Cards, NGN bank transfer, mobile money and crypto, with payment-method switches.
- Signed webhooks, server-side checks, duplicate protection and background reconciliation.
- Full and eligible partial refunds, followed by WHMCS Record Only accounting.
- Optional saved cards and automatic collection of WHMCS renewal invoices, with client consent.

WHMCS controls invoices, products and renewal dates. Bachs handles collection. Merchant payouts, split payments, virtual accounts and Bachs-managed subscriptions are outside this gateway.

## Currencies and methods

Invoice currencies: **USD, NGN, GHS, KES, MWK, RWF, TZS, UGX, XAF, XOF and ZMW**. Enable the currencies you accept in the gateway settings and configure matching codes in WHMCS.

Payment methods include USD/NGN cards, NGN bank transfer, nine mobile-money corridors and crypto. Supported crypto assets are USDT on Tron, BNB Chain, Ethereum and Solana; USDC on BNB Chain; ETH on Ethereum; SOL on Solana; and BNB on BNB Chain. Individual crypto assets and networks follow your Bachs account settings. Card wallets appear where Bachs supports them.

**USD checkout conversion** lets clients pay a USD invoice using eligible local currencies or crypto, while WHMCS records the original USD amount. Enable adaptive pricing in Bachs too. Other invoice currencies collect in their own currency. Bachs applies account availability and payment minimums. See its [currency guide](https://docs.bachs.io/guides/checkout/any-currency-checkout) and [method guide](https://docs.bachs.io/guides/payments/payment-method-support).

## Requirements and limits

- Requires WHMCS 8.0 or later, on a PHP version supported by your installed WHMCS release. See the developer guide for tested environments.
- Requires HTTPS, cURL, OpenSSL, JSON, PDO MySQL and InnoDB/utf8mb4.
- Optional native saved-card billing uses **Bachs saved card**. Its settings are shared with **Bachs**.
- Refund requests run asynchronously. Record the external refund in WHMCS only after Bachs confirms success. NGN bank-transfer refunds are unsupported.
- Add-funds and mass-payment invoices are unsupported.

## Support

Read [Contributing](CONTRIBUTING.md), the [developer guide](docs/development.md), the [code audit](docs/audit.md) and [Security](SECURITY.md). Report ordinary bugs in [GitHub Issues](https://github.com/atimapreandrew/bachs-whmcs-module/issues), including your versions and reproduction steps. Keep keys, customer records, card tokens and raw webhooks private.
