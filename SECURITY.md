# Security and privacy

Report security problems through [GitHub private reporting](https://github.com/atimapreandrew/bachs-whmcs-module/security/advisories/new) if enabled. Otherwise, open an issue asking for a private contact channel without posting vulnerability details. Never include keys, card tokens, customer records, or raw webhooks in a public issue.

## Payment controls

The module checks HTTPS certificates, fixed API hosts, signed webhook bytes/timestamps, merchant scope, invoice ownership, exact money, and native permissions. Protected actions use WHMCS CSRF checks. Unknown writes are retained for review, and duplicate accounting is guarded by stored identities, locks, and native transaction lookup.

Raw card/OTP entry stays at Bachs. Saved methods require client consent and ownership checks. Card saving and automatic collection start disabled. Sandbox payments require explicit confirmation that the installation is a test site.

API/signing keys, provider card tokens, saved requests, and resumable checkout URLs are encrypted. Portal URLs are never stored. Staff access follows native WHMCS permissions. The optional activity addon also uses WHMCS addon role access. Configuration is stored only in native gateway fields. Record views and diagnostic exports hide sensitive fields.

## Stored data

Customer creation sends the billing client's name/email and payment details needed by Bachs. The module does not sync service passwords, tickets, or unrelated profile fields. Local records retain invoice/client links, payment identities, amounts, statuses, consent, and accounting/audit evidence. Webhook records keep selected fields and a body digest rather than the full payload.

Cleanup removes expired confirmations/rate counters and old terminal checkout links. Financial history is retained. Client deletion revokes local card credentials without deleting provider financial records. Deactivation does not purge data or cancel/refund external money automatically. Set retention rules before any separately authorized deletion.

## Server care

Protect the private library, CLI folder, logs and WHMCS encryption configuration. Keep web and cron configuration consistent, back up keys, and patch WHMCS/PHP/OpenSSL/cURL. Do not disable signatures or certificate checks to fix an integration error. Hosted card entry does not establish PCI compliance by itself.

This release has local tests but no independent security audit or verified live WHMCS/Bachs acceptance. Test on a separate site before production use.

Bachs and WHMCS are separate products under their own terms. No third-party runtime library is bundled. Overlay checkout loads Bachs's own SDK only when needed. Product names identify integration targets and imply no endorsement.
