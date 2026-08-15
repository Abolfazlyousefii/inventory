# Commission Phase 5 — Financial close and settlements

## Domain boundaries

- `CommissionLedgerEntry` remains the calculation truth.
- `CommissionDocument` is the reviewed financial decision and becomes immutable after finalization.
- `CommissionSettlement` is the seller/period liability snapshot created from a finalized document.
- `CommissionPayment` is the immutable payment history; cached paid/remaining values are derived from active rows.
- `CommissionAdjustment` is a signed, reasoned manual or system adjustment and is reviewed independently in a document.

All money is integer Rial. Existing `Currency` and `JalaliDate` helpers are used by the UI.

## State machine

`open → review → closed → paid` is enforced by `CommissionPeriodWorkflowService` with row locks and database transactions. Reverse transitions are not available.

- Review requires a clean period and no critical calculation warning. Missing rates remain visible warnings.
- Close requires every seller with financial activity to have a finalized, non-stale document with no pending invoice, correction, or adjustment.
- Close creates one settlement per finalized seller document, persists the period summary snapshot, and changes status atomically.
- Paid requires every positive settlement to be fully paid, every negative settlement to have its carry-forward, and exact document/settlement/payment reconciliation.

## Negative and zero balances

A reviewed zero document creates a zero settlement for audit. A negative settlement is retained historically and creates one idempotent, system-approved `carry_forward` adjustment in the first later open/review period. Positive unpaid liabilities are never carried into commission totals.

## Payments

Partial payment is supported. Recording locks the settlement, rejects zero/negative amounts and overpayment, and supports a request idempotency key. Payment rows are never edited or deleted. A recorded row may be voided with a mandatory reason while the period is not paid; the original row remains and cached totals are rebuilt.

## Reconciliation

`php artisan commissions:audit-settlements` is dry-run by default. It checks document/settlement totals, payment sums, overpayment, paid-period consistency, and negative carry-forwards. `--apply` only repairs deterministic cached payment amount/status fields; it never guesses historical financial amounts.

## Safe rollout

1. Review and deploy application code.
2. Back up the production database.
3. Run the new migration in the approved deployment window.
4. Run both commission audit commands without `--apply`.
5. Resolve reported historical gaps before enabling close/payment permissions.

No production migration, period close, settlement creation, payment, adjustment, or reconciliation apply was run during implementation.
