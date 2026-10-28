# Central inventory count freeze

Administrators open `/admin/inventory-count-freeze` from the dashboard widget,
select categories (descendants included) or all products, inspect the preview,
acknowledge reservation release, and enable counting. The initial installation
is disabled. Disabling never recreates a reservation or approves an invoice.

## Transaction boundary

`inventory_count_freezes` contains a single permanent row. Stock operations read
it using a shared lock inside their existing transaction; activation and ending
use an exclusive lock. An optimistic version prevents an old administrator tab
from ending a newer count. Scope checks are server-side and also apply to admins.
`InventoryCountFreezeService` is container-scoped; its trusted internal and
stocktake contexts restore their depth in `finally`, including on rollback.

Activation atomically releases classified, unconsumed ERP reservations, retains
the reservation rows with `stocktake_freeze` reason, and returns affected
unconverted orders to draft. A mixed-category order is released as a whole;
items, prices and ownership remain. Invoice-linked and invalid official rows
stop the entire activation. Ambiguous historical rows use the existing stock-neutral
archive service: their quantities are never interpreted as new available stock.
Stock/reserved projections use the existing reservation transaction machinery.
Start/end actions and released reservation/order IDs are recorded in activity logs.

Bundles containing scoped components are included in the blocked scope. Unsold
remaining allocations of affected automatic bundles return to ordinary central
stock before counting; consumed allocations are not restored. Reallocation after
counting is an explicit operation. Active Site holds stop activation for their
scope: operators must resolve the external order using its existing payment and
hold workflow. The switch never cancels/refunds a paid external order.

## Enforcement

- WarehouseStockService change/set and direct WarehouseStock model mutations.
- Product/variant quantity changes, deletion and structural changes via observers.
- New reservations, official finance approval and invoice item quantity mutations.
- Collection receive/start/complete/edit, bundle allocation reserve/restore/edit,
  Site stock hold reserve/release/consume, and raw-write inventory repair commands.
- Draft reservation synchronization filters counted products; drafts can still
  be saved without freezing their stock. Existing finance docs require resubmission.
- StockCountDocumentService finalize is the trusted stocktake write exception.
- Other warehouses' physical movements and unselected products continue normally.

No HTTP field grants a bypass. No broad existing permissions are modified.
The switch protects application workflows; arbitrary privileged database SQL,
out-of-band imports, and external systems must not write central balances during
counting. Do not run repair scripts that bypass application services/observers.

## Rollout and recovery

Apply only the two `2026_10_28_*inventory_count_freeze*` migrations, then deploy
the control page and guards. Existing inventory tables are not altered. Start in
disabled mode; verify administrator access and non-administrator 403 responses.
For rollback, end counting through the service before reverting code; retain
the audit table and released reservation rows. Never automatically restore old
reservations, as stocktaking may already have changed physical quantities.

Focused coverage lives in `tests/Feature/InventoryCountFreezeTest.php`, alongside
existing reservation, finance, stock-count lifecycle and bundle regressions.
The final focused run passes 69 tests / 423 assertions, including 16 dedicated
freeze scenarios. Browser QA covers production read-only preview, category
search, a 390px mobile viewport, dark/light themes, and no console errors. A
read-only two-transaction MySQL check confirms the exclusive control lock waits
for an existing shared workflow lock (4.39 seconds), without changing state.

The wider repository suite is not green: the 3,798-test run reports 80 errors,
164 failures, one warning and two skipped tests. No freeze test failed in that
run. A representative set of 29 failures was reproduced unchanged with all
feature hooks removed against the baseline source. These existing failures are
not evidence of a completed audit or a guarantee for the entire inventory system.
