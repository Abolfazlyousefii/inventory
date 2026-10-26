# Sales return editing and inventory safeguards

New edits use `SalesReturnCorrectionService::updateDirect`/`voidDirect`; they do
not create a warehouse/finance approval chain. Existing correction requests and
approval endpoints remain unchanged for historical compatibility. A pending old
request blocks a direct change until explicitly resolved; deployment does not
approve, reject, delete, or migrate business records.

## Access and stock effects

- Applied return-only documents: the existing returns access permits direct
  editing/voiding with a reason and document version. Financial-only historical
  returns remain financial-only: no warehouse stock is invented or reversed.
- Pending central receipts: existing versioned editing updates the same receipt.
  Cancellation additionally cancels the pending receipt, and requires a reason,
  matching version, zero receipt stock effects and zero net posted customer credit.
- Received central documents: warranty cannot edit/void. The received-return
  endpoints require existing warehouse receiving access. Read-only form helpers
  are explicitly shared with that page; no write access to unrelated returns or
  configuration pages is granted.
- Central history remains protected after reclassification into a return
  warehouse. Checking the current destination alone would allow a bypass.
- Direct inventory edits reconcile traceable net stock movements against current
  document quantities. Missing/inconsistent historical central stock evidence or
  inconsistent net customer credit blocks the edit, instead of guessing a repair.
- For mixed documents, financial-only return groups do not acquire stock simply
  because another line has a physical central receipt. Existing tracked stock
  groups and newly received physical groups use only their required differences.

## Atomicity and history

The lock order is document, customer/invoice, then deterministic variant/stock
order. All stock deltas, customer ledger differences, item updates, correction
snapshots, revision history and commission outbox staging occur in one database
transaction. Negative free stock is rejected; reservations are not released to
make a correction fit. Failures roll back the whole edit. Repeating a stale save
does not apply another delta; voiding an already cancelled document is a no-op.

Original documents/items are not physically deleted. Removed lines remain with
zero quantity for historical receipt references. Item matching prefers the same
variant/destination/condition before using an unambiguous variant match. Original
creation dates and source customer/invoice remain fixed. Return reason and
commission policy remain immutable on applied edits, including legacy null values.

## Requeueing a financial-only return to central

The existing credit is reversed once, while central stock remains unchanged.
The same document is queued for receiving and its receipt uses
`revision-direct-<correction-id>` as operation key. Subsequent receiving posts a
new receipt-referenced credit, preserving rather than overwriting the original.
Changing the pending document back to return-only uses that same separate credit
reference. Cancelling the pending document leaves its net credit zero.

The health report includes signed correction ledger effects and receipt credits,
including a cancelled revision receipt that finalized as financial-only. It does
not demand stock movements for intentionally financial-only return groups.

## Verification

`SalesReturnDirectWorkflowTest` covers non-stock edits, version checks, idempotent
voids, access separation, shortages, ledger rollback, requeue/receive, requeue back
to returns, pending cancellation and retained central history. Existing pending,
inbound, correction and applied-void suites remain regression coverage. No schema
migration or production stock adjustment is part of this deployment.
