# Editing a sales return awaiting warehouse receipt

Pending sales returns can be revised using the existing return-edit permission. Customer, source type and source invoice remain fixed. A reason and a SHA-256 review token are required. Editing and receipt synchronization run in one transaction, with document/customer/invoice/receipt locks in the same order as warehouse confirmation.

The warehouse form submits its own receipt token. A stale review is rejected before ledger, commission or stock effects. Receipt line IDs and source snapshots are refreshed together. Removing all central lines cancels and preserves the old receipt, then follows the existing non-central return finalization policy. Finalized documents continue to use the existing applied-adjustment workflow.

Revisions retain before/after document and item snapshots, actor and reason. The return detail displays these revisions. Existing materialized new products are reused. Internal return caps exclude the edited document while counting other pending and applied returns; the cap is rechecked under locks.

No migration or production data cleanup is required. Already-open warehouse forms without tokens must be refreshed once after deployment. Old draft edit/cancel requests cannot overwrite a return that has entered the warehouse queue.

Validation: PendingSalesReturnEditTest covers stale edits/reviews, confirmation once, exact revised stock, internal caps, cancelled receipts, required reasons, immutable sources, rollback on audit failure, preserved product identities and rendered forms/history. Local browser checks cover mobile/desktop source locks and form tokens. Older queue architecture checks refer to migration files missing from this checkout; these are unrelated to this change.
