# Payment recovery runbook

Identify the original environment, merchant scope, attempt/operation and native transaction first. A browser return or HTTP creation is not a payment receipt. Never delete an uncertain operation to permit another charge.

| Symptom | Safe next action |
|---|---|
| No signed delivery | Check exact callback environment/subdirectory, Bachs delivery status, WAF/HTTPS reachability and subscribed event names. Keep signature verification enabled. |
| Signature rejected | Check signing secret, environment, raw-body preservation, server clock and previous-secret expiry. Invalid V2 cannot downgrade to legacy. |
| Checkout creation timed out | Retain the unknown operation and its key/body. Recover from its stored successful response or authenticated checkout/reference evidence; do not initiate a replacement. |
| Browser closed | Run the worker. Webhook and periodic provider reconciliation do not require a browser return. |
| Receipt needs review | Inspect actual amount/currency/customer/source and the current invoice. Preserve the receipt; resolve accounting through a reviewed native action. A review note does not allocate money. |
| Worker unhealthy | Check the CLI interpreter, private configuration, execution identity and recent queue errors. Preserve job/operation identities. |
| Dead job | Resolve its reason, then use authorized Replay/Recheck. Exhausted jobs are not automatically revived. |
| Refund processing or unknown | Recheck the existing request. Original-charge lookup recovers provider creation without a second refund write. |
| Refund accounting pending | Verify provider success, then perform the displayed native Record Only task and recheck. External partial refunds need a verified amount mapping first. |
| Permanent/unmatched receipt | Keep it unallocated. Verify availability/ownership with Bachs; never match an invoice by narration, email or amount alone. |
| Partial package copy | Restore the complete matching package and run addon upgrade. Component/class integrity must remain enabled. |

The queue uses token-bound expiring leases and bounded backoff/jitter. A killed worker can be reclaimed; a stale worker cannot finalize another worker's lease. Payment-list reconciliation retains pagination/high-water overlap. Old exceptions can be explicitly rechecked without scanning all history every minute.

Manual signing-secret replacement retains an encrypted previous local secret for 24 hours. The provider rotation guide and endpoint reference conflict about overlap/returned secrets, so automatic API rotation is withheld. Verify a real signed delivery after using the provider's reviewed rotation procedure.

Incoming repair first searches the deterministic native transaction. After a crash following insertion, recheck validates invoice/client/gateway/amount and restores local linkage. Outgoing repair detects the exact Record Only transaction and original refund linkage. Do not directly write WHMCS tables or create another outgoing transaction merely to satisfy the module.
