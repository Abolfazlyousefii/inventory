# Showroom warehouse vouchers

The active `showroom` warehouse is a separate inventory location. The voucher hub
links to `/vouchers/section/showroom`, with its own archive and creation form.
The archive includes an expandable, paginated current-showroom-stock table.
The existing `WarehouseTransfer::TYPE_SHOWROOM` and atomic `createTransfer` stock
movement path are reused. Only central-to-active-showroom transfers are accepted
for this voucher type, even if warehouse IDs are forged. Stock goes down in
central and up in showroom; central sellable projections remain central-only.
The alternate InventoryProductsSyncService export also reads central stock only,
so showroom stock is excluded from website saleable quantities.
No invoice, customer ledger or commission document is created.

The creation form uses existing warehouse-authorized category/product/variant
lookups. It does not preload the entire product/variant catalogue. Dates are
Jalali; variant labels and central availability are fetched on selection. A new
UUID per form is backed by the `warehouse_transfer_submissions` primary key and
the actor lock. Identical retries reuse the original transfer; changing quantity
under the same token is rejected. Deleted transfers leave their token tombstone,
so an old browser retry cannot recreate them.

Existing warehouse-issue permissions apply; no user receives extra access.
The central inventory count freeze also blocks these transfers. Failed or
insufficient-stock submissions roll back the document, all stock lines and token.
Editing and return transfers remain in their existing warehouse workflows.

## Installation

Apply only `2026_10_28_010001_add_showroom_warehouse_support.php` and
`2026_10_28_010002_define_showroom_warehouse.php`. The first appends the showroom
enum value and creates a small submission table. The second defines the warehouse
idempotently with zero stock. No existing warehouse identity or stock is replaced.
Do not shrink the enum or delete a populated showroom on rollback; retain the
warehouse and submission history and use a forward corrective migration.

Verification: eight dedicated scenarios cover entry/form, actual transfer and
identical retry, changed retry payload, multi-line rollback, forged destination,
count freeze, permission denial and central-only website stock export. The combined showroom/personnel/freeze/
reservation/return regression run passes 51 tests and 269 assertions. Browser QA
checks live product/variant lookups, independent row indices after add/remove,
a 390px viewport and dark/light themes. The repository-wide check still stops
on the previously reproduced AppShellLayout sidebar assertion; no global green
test-suite claim is made.
