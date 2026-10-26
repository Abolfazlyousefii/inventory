# Read-only account statement access

`page.finance.account_statements_readonly` grants only statement listing,
statement detail and read-only source documents. It does not grant the general
financial report page. The associated local role is
`Org:finance.account_statements_readonly`.

For a non-administrator with this role, `AccountStatementAccess` overrides broad
inherited finance grants: payment creation/edit/delete, manual balance adjustment
and alternate invoice-payment/cheque creation routes are denied. The permission
catalog and User permission helper apply the same constraint; the payment
controller also rejects direct calls. The statement view omits payment and balance
mutation controls, including the hidden payment modal. Administrator aliases keep
their explicit full access.

This does not disable stock or accounting effects produced by authorized business
workflows such as sales returns. It restricts manual payment/account mutations.
The shipping exception is a separate membership in `Org:warehouse.shipping`; it
does not grant collection, receiving or other warehouse pages.

Verification: 29 focused statement chronology/date, read-only, warehouse-boundary
and administrator tests pass (280 assertions), including forged payment create,
edit and delete requests, unchanged ledger/payment records, and the inherited
permission case. Broader historical statement/payment tests contain five failures
also reproduced against the pre-change files; those unrelated baseline failures
were not changed as part of this access adjustment.
