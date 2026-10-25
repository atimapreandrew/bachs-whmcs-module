# Changelog

## 1.0.0 release candidate

Initial payment integration for WHMCS:

- Hosted/overlay invoice checkout and a separate optional saved-card gateway.
- Signed webhook handling, background checks, duplicate protection, and recovery after interrupted native posting.
- Full and eligible partial refund tracking, with native Record Only accounting checks.
- Explicit card-saving consent, local opt-out, provider card management, and USD zero-charge setup.
- Staff payment settings, refund records, review items, diagnostics, and audit history.
- One installation ZIP for all version targets, with file integrity checks.

Prepared for public use with MIT licensing, author credit, plain-English guides, contributor notes, and local test/build commands. Old planning material and unused helpers were removed from the public source.

The module is limited to WHMCS payments. Merchant payout tools, Connect/split transfers, virtual-account creation, dispute-evidence tools, and Bachs-managed subscriptions are excluded. Existing database history is preserved.

Local tests pass. Real provider payments, licensed WHMCS, older PHP runtimes, MySQL/MariaDB, and rendered browser/themes remain unverified. See [test status](docs/test-report.md).
