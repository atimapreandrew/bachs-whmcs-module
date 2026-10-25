# Security

This module has local security tests but has not had an independent security audit. Real Bachs and licensed WHMCS acceptance tests are still required before production use.

## Report a problem privately

Use [GitHub's private vulnerability report](https://github.com/atimapreandrew/bachs-whmcs-module/security/advisories/new) if it is available. If it is not enabled, open an issue asking the maintainer for a private reporting channel. Do not include vulnerability details in that public request. No separate security email address is published.

Never post API keys, signing secrets, full card tokens, customer data, or raw webhook bodies in an issue or pull request.

## Main controls

- The API client uses fixed Bachs hosts, HTTPS certificate checks, TLS 1.2, no redirects, bounded timeouts, and a response-size limit.
- Webhooks are checked against the exact received bytes, a timestamp, and the correct merchant/environment. Invalid V2 signatures cannot fall back to legacy verification.
- Client actions require login, billing-account permission, and native WHMCS CSRF checks. Staff actions require addon access and the matching native/module permission.
- Money stays in exact decimal strings. A browser result, missing amount, underpayment, or unknown provider state cannot mark an invoice paid.
- Provider writes are saved before sending. Unknown results are reviewed instead of retried as a new charge. Saved requests keep their original merchant and retry key.
- Unique payment identities, invoice locks, stable native transaction IDs, and transaction lookup protect against duplicate accounting.
- Keys, card tokens, saved requests, and checkout URLs are encrypted. The customer portal URL is never stored. Record views and exports hide sensitive fields.
- Public pages escape text. Database values use bound parameters. Signed notifications are size limited and queued before acknowledgment.

## Server setup

Keep the private test profile and any dedicated encryption key outside the web root. Protect both with server access controls and secure backups. Web PHP and cron must use the same configuration. Database encryption does not protect against a server compromise that exposes the key too.

Block web access to `modules/gateways/bachs/lib/` and `modules/gateways/bachs/bin/`. Apache rules ship in the installation ZIP; configure the equivalent for other servers. The worker also rejects non-CLI requests. Do not add a public HTTP cron endpoint.

Keep WHMCS, PHP, cURL/OpenSSL, certificates, and the web server patched. A legacy syntax target does not permit disabling HTTPS, signatures, permissions, or encryption. Test version changes before enabling payments again.

Check worker gaps, signature failures, and review items. The callback uses `REMOTE_ADDR` for rate limits and does not trust arbitrary forwarded headers. Set proxy/WAF rules to allow legitimate provider bursts without collecting attack payloads.

Hosted card entry keeps raw card details out of this module. It does not establish the merchant's PCI compliance by itself.
