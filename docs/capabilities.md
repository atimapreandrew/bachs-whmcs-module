# Payment integration scope and acceptance

This release is limited to collecting and reconciling WHMCS invoice payments. WHMCS owns products, renewal dates, invoice generation and billing schedules. Bachs processes the hosted payment or an explicitly authorized saved-card charge. No capability is currently production verified.

| Area | Included | Remaining acceptance |
|---|---|---|
| Invoice checkout | Hosted redirect, optional overlay with redirect fallback, exact outstanding invoice balance, scoped customer mapping, method restrictions and session reuse | Real merchant methods/currencies, browser returns and licensed WHMCS accounting |
| Payment confirmation | Signed webhook inbox, canonical provider retrieval, exact amount/currency/source/customer checks, native payment posting, duplicate protection and crash recovery | Real signed deliveries, native provisioning once, MySQL concurrency |
| Currency handling | USD/NGN defaults; configurable GHS/KES/MWK/TZS/UGX/ZMW; optional verified USD-base hosted conversion | Each enabled invoice/collection currency needs wire-precision evidence; RWF/XAF/XOF invoice pricing remains disabled |
| Payment methods | Provider-hosted eligible cards, bank transfer, mobile money, crypto and wallets | Availability depends on merchant capabilities and session eligibility; no local card/OTP form |
| Saved cards and renewal capture | Explicit consent, encrypted tokens, local opt-out, provider portal, USD zero-charge setup and separate `bachs_card` adapter | Native enrollment helper starts at WHMCS 7.9; exact-version pending/retry/accounting lifecycle must pass. Off-session charges require the same invoice currency |
| Refund requests | Full and eligible partial refunds, persisted asynchronous status, original-charge recovery, crypto destination/network input and dashboard refund discovery | NGN bank-transfer refunds unsupported; unproven partial currency mappings are rejected |
| Refund accounting | Exact outgoing transaction/linkage detection and a deterministic Record Only task | Provider success requires the native Manual Refund Processed Externally / Record Only action and recheck. Automatic native refund posting is not enabled |
| Payment administration | Connection/settings, payments, refunds, saved methods, webhooks/replay, reconciliation, worker diagnostics and audit | Native access controls and explicit refund/export role grants apply |
| Distribution | One ZIP, version-aware bridges, no runtime Composer/Node/Redis dependency, component integrity and resumable module-table activation | Real PHP/MySQL/WHMCS/theme matrix remains unrun |

Merchant payouts, balance dashboards, payout schedules/destinations, virtual-account creation, Connect accounts/splits/transfers, dispute-evidence submission, and Bachs-native product/subscription management have been removed. The original build specification remains as a historical input; this narrower payment-only scope supersedes its merchant-platform sections.

Signed events outside the payment integration are retained as unhandled. Unmatched or permanent-account receipts remain unallocated for review and cannot automatically pay an invoice. Existing database history is preserved without creating the removed feature tables on fresh installation.
