# Unified Person Account Ledger

The Persons list is the entry point for a shared account statement. A person may have a customer role, a supplier role, or both.

## Identity

Customer mobile numbers and supplier phone numbers are compared with App\Support\PhoneNormalizer so Persian/Arabic digits, punctuation, and Iranian +98 / 0098 prefixes resolve to the same person key. The same key is used by the list, edit action, and account statement route.

## Account sign convention

- Debit increases the amount the person owes the business.
- Credit increases the amount the business owes the person.
- Current balance = opening balance + effective debits - effective credits.
- A positive balance is shown as debtor; a negative balance is shown as creditor.

For a person with both roles, the customer opening balance is used once. Customer and supplier ledger rows are combined.

## Duplicate purchase prevention

Some customer-origin purchases are mirrored into both customer_ledgers and supplier_ledgers for compatibility with the existing customer account statement. In the unified person statement, the customer-ledger copy is canonical and the matching supplier-ledger purchase row is omitted. Supplier purchase rows without a customer-ledger copy remain visible.

## Purchase returns

New purchase returns create/update one supplier-ledger debit in the same database transaction as the return document. A soft-deleted return remains visible for audit but is excluded from the current balance.

Older active purchase returns that predate supplier-ledger synchronization are included from their stored document totals as a read-only fallback. This avoids rewriting historical return documents or creating duplicate financial rows.

Return amounts for new documents are based on the original purchase row after item discounts and a proportional allocation of invoice-level discounts. Partial returns use cumulative integer proration so separate returns add up exactly to the original net line amount.

## Supplier payments and receipts

A manual supplier payment is stored as a `SupplierAccountTransaction` source document and creates one linked supplier-ledger debit. A receipt from the supplier creates one linked supplier-ledger credit. Both operations run in one database transaction and write an ActivityLog entry.

The submission token is unique and makes browser retries idempotent: replaying the same token does not create a second source document or ledger row. The transaction stores its date, method, external reference, note, and creator. The unified statement uses the transaction date for chronology.

- Payment to supplier: debit; reduces the amount owed by the business.
- Receipt from supplier: credit; reduces an amount owed by the person to the business.
- Read-only account-statement users cannot see or submit these controls; the write route is denied server-side as well.
