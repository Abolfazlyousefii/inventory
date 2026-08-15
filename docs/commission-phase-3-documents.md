# Commission Phase 3: Financial Documents

The commission ledger remains the calculation source of truth. A commission document stores an invoice-level financial-review snapshot and never resolves rates or campaigns itself.

## Data model

- `commission_documents`: one draft document per seller and commission period; number is derived from the concurrency-safe auto-increment id.
- `commission_document_items`: one historical row per document and invoice. Only `active_invoice_id` is unique. Pending and approved rows claim the invoice; rejected and removed rows release it.
- `commission_document_events`: immutable, queryable business-event history with actor, reason, item, and minimal metadata.

The Phase 3 migration is `2026_08_14_000003_create_commission_documents_tables.php`. It must be applied through the normal deployment process; no production migration was run while implementing this phase.

## Workflow

Document creation refuses a dirty source period, enforces seller/period uniqueness, and imports valid unclaimed ledger invoices. Manual add supports historical outside-period invoices only with a reason and always reads the historical source period's active ledger rows.

Add/reactivate, approve, reject, remove, candidate refresh, and calculation refresh are service operations. Claim mutations use database transactions, row locks, and the database unique constraint. Rejected and removed snapshots are not mutated. When an approved active snapshot changes, refresh replaces it from the ledger and returns it to pending review.

## Authorization

- `page.commercial.commissions`: page and read access
- `commissions.manage_documents`: create, notes, add/remove, candidates and calculation refresh
- `commissions.review_documents`: approve and reject
- `commissions.print_documents`: read-only print route

The earlier `SellerSalesDocument` subsystem remains independent and unchanged.
