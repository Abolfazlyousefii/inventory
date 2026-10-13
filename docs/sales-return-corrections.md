# Sales-return corrections after warehouse receipt

## Workflow

Draft and pending warehouse returns retain their existing editing workflow, receipt-version checks and source locks. A received/applied return cannot be directly edited or voided, including through the former service entry points. The former applied edit form now submits a correction request without inventory, ledger or commission effects.

A request captures the complete before snapshot, a proposed snapshot, reason, requester and SHA-256 source version. Only one unresolved request per document is permitted, under the document lock. Warehouse review precedes finance approval. Requester, warehouse reviewer and finance approver must be separate users. Warehouse/finance routes require the same existing page access as receiving inventory/financial approval; warranty access alone cannot approve. Requester can withdraw an unresolved request; the reviewer for its current stage can reject it.

Finance approval rechecks the document version, source invoice return cap and proposed calculation. It applies only net stock deltas grouped by warehouse/variant, and only the debit/credit difference. Stock, ledger, document effective values, revision, approval and commission outbox are one transaction. Insufficient free stock rolls back the entire operation. Repeated final approval is a no-op. Stock quantities already represent free stock; reserved quantities must not be subtracted again.

Historical receipt records and receipt item links are never changed, removed, or requeued. Original return item IDs remain available; removed effective lines have zero quantity/refund instead of being deleted. The correction and revision retain the complete original state, while the normal document/report display reflects approved effective values. Original document number, customer/source, created/applied dates, return reason and commission policy stay fixed. New products require a separate return document; previously materialized products can be corrected using their existing variant. At least one positive line must remain. Direct cancellation after receipt is deliberately blocked; full cancellation needs a separate authorized correction design.

## Screens

`/sales-return-corrections` provides before/proposed comparison, notes and review history, with links from returns, inbound queue and financial approval. The stock ledger labels correction movements and customer statements include the independently referenced ledger delta through their existing generic ledger display.

## Deployment and rollback

Migration `2026_10_13_160000_create_sales_return_corrections_table.php` only adds an empty table; it does not rewrite historic returns, stocks or customer balances. Deploy by Git pull, migrate only this path, then clear route/view caches. Verify existing pending and applied screens and both review stages using read-only requests. Do not approve a real correction as a deployment test.

If rollout must be disabled, block new requests/approvals while preserving correction history. Do not revert to the old destructive applied-edit service and do not drop the table after real requests have been recorded. Any schema rollback after use requires a forward migration preserving the audit records.

## Validation

The workflow suite exercises pending edit/receipt stale checks, requester and stage restrictions, duplicate requests, final-approval idempotency, insufficient stock rollback, quantity and price deltas, healthy/damaged warehouse moves, invoice caps, preserved received receipt links, request form and review UI. Browser checks use intercepted requests and rendered fixture HTML at 390/1280px; no production POSTs.
