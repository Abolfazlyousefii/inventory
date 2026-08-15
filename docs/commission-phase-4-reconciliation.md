# Commission Phase 4: Returns and Historical Reconciliation

Phase 4 preserves the sale ledger as immutable calculation history. Returns and immutable-period seller transfers are represented by signed, append-only correction entries.

## Return lineage

Only applied internal returns with an explicit commission effect classification are deterministic. Each return item references its original invoice item, which resolves the active historical sale ledger entry. Base and campaign reversals use that entry's snapshots and never current rates or product prices.

`commercial` returns create negative proportional deltas. `warranty`, `service`, and `replacement` have zero commission effect. Applied edits append only the difference from prior effective entries; voiding an applied return appends a positive counter-entry. Identity keys make every reconciliation idempotent.

The financial date is the stable `applied_at` timestamp. The effect is assigned to the open/review period containing that timestamp. If no eligible period exists, it remains `pending_unassigned_period` with a visible reconciliation warning.

## Seller reassignment

The existing `SalesDocumentSellerReassignmentService` remains the only reassignment entry point. In open/review periods it releases an old seller document claim, marks the period dirty, and lets normal period recalculation supersede the old sale ledger with the new effective seller. Finance approval never transfers.

Closed/paid sale periods are not changed. A deterministic debit for the old seller and credit for the new seller are added to the first correction-eligible period. Multi-hop transfers retain every audit reference. Later returns resolve the positive correction owner before falling back to the original sale entry seller.

## Document review

System corrections have an independent claim identity in `commission_document_corrections`. They enter documents as pending, and finance uses the existing `commissions.review_documents` permission to approve or reject them. Rejection changes only the document decision; the correction ledger remains intact.

## Operational audit

`php artisan commissions:audit-reconciliation` is read-only by default. It reports finalized internal returns without reversal, duplicate identities, wrong-seller active claims, and corrections without a period. `--apply` only reconciles deterministic missing return entries and is idempotent. It was tested only against the isolated test database and was not run against production or the local application database.

The Phase 4 migration is `2026_08_14_000004_create_commission_corrections.php` and must be deployed through the normal migration process.
