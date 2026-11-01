# Hall management display

The management-only display replaces `/hall` on `main` and was published with user
approval on 2026-11-01; see `hall-management-release-2026-11-01.md`. Customer tracking
keeps its existing controller, view and assets. No inventory, invoice, payment or reservation writes are made by the
dashboard. One additive migration creates `hall_queue_transitions`, containing
only document identity and queue-entry metadata. Existing document tables are not
altered. The observer appends entries after commit without a queue worker; missing
table or recording failures cannot roll back sales or turn successful commits into
HTTP failures. Conversion of a parent is ignored so the new invoice owns the same
workflow's next queue. Internal statuses do not reset the queue clock.

## Access and operation

- Set a private `HALL_MANAGEMENT_ACCESS_KEY` in the deployment environment. Until
  configured separately, the existing `HALL_DISPLAY_ACCESS_KEY` is used. There is
  no hardcoded default key. Rotate a previously shared display key before release.
- Previous `hall_display_access` cookies do not authorize financial data.
- A new purpose-scoped, encrypted, HttpOnly, SameSite=Strict `/hall` cookie lasts
  12 hours. Secure cookies are used for HTTPS/production. Locking clears the cookie.
- Page and API responses are private/no-store/noindex. No names or money are
  included in unauthenticated responses. The client clears private data on 403.
- Polling is sequential, every 15 seconds, with a 10-second timeout. Timers update
  each second against the server's snapshot and monotonic browser elapsed time.
  Hidden tabs stop polling; a visible stale-data warning appears on failures.
- Desktop TV layout supports 1280×720 and 1920×1080, with responsive smaller
  screens, fullscreen, manual browsing, pause, and reduced-motion preferences.

## Definitions

- Four queues: active reservations, finance, warehouse, shipping. Drafts and
  returns to the seller are never active cards. Final check stays in warehouse;
  packing and ready-to-ship are shipping.
- Every displayed preinvoice must have positive, open official reservations,
  no expired open reservation lines, and an unreleased, unexpired holding order.
  The oldest open official reservation must be less than 24 hours old; editing
  the document does not restart that window. Temporary, released and converted
  reservations do not qualify. Existing active invoices have no 24-hour cutoff.
- Pending finance/reserved-waiting-warehouse preinvoices occupy active reservations;
  finance-review statuses occupy finance. Reservation queue time uses the actual
  official reservation creation time. Payload version 2 rejects obsolete draft
  columns. Refresh already-open displays once after this update.
- At desktop widths of 1100px and above (height at least 600px), a left rail
  holds the brand, clock, daily metrics, browsing controls and last event. The
  four queues use the remaining full height: 5 cards per queue at 1280x720 and
  7 at 1920x1080, with independent rotation. At smaller sizes the rail becomes
  normal stacked content and queues adapt to one or two columns. The rail can
  scroll independently on short screens without taking height from the queues.
- `order:<preinvoice-id>` survives conversion to an invoice. Standalone invoice
  keys have a separate namespace. Linked preinvoices are excluded from the feed.
- Amounts are raw integer rials, not multiplied by ten. `item_count` is the number
  of positive-quantity lines; `unit_count` is the sum of those quantities.
- Total time starts at original preinvoice creation when linked. Queue time uses
  the new post-commit queue ledger, with legacy cross-queue history as fallback,
  ignoring internal status changes
  and item edits. Legacy documents without reliable history show `—`; updated_at
  is never presented as an invented queue-entry timestamp. No historical events
  are backfilled. Counters over a day show compact day/hour labels; exact values
  remain available in the document details.
- Active documents are not silently capped at 120. The client rotates each queue
  independently while counts and averages reflect all active records. Averages
  include only documents with a known queue-entry time.
- Sales use document_date (fallback created_at) for the Tehran calendar day and
  exclude cancelled invoices. Cash includes cash/online receipts received today,
  irrespective of invoice issue date. Cheques use received_at (fallback paid_at),
  with latest cheque metadata per payment to avoid duplicate receipt totals.

## Verification

The minimal sidebar now opens read-only sales, receipts, queue-wait and overdue
summaries in a native dialog, computed only from the existing authorized snapshot.
Seller rows are explicitly labelled as open documents, not completed daily sales.
Full seller/receipt, stock-flow and return reports retain their existing ERP
authentication and route permissions, opening separately. No new financial API
or broader Hall-cookie access is introduced.

Cards rotate every 2 seconds with a 520ms slide. Reduced-motion, pause, hover,
keyboard focus and open report/detail dialogs suspend automatic browsing. API
polling stays at 15 seconds; visual browsing never increases server polling.
Report dialogs show the last-success timestamp and connection failures; access
expiry clears their contents together with the dashboard before redirecting.

Run `php vendor/phpunit/phpunit/phpunit tests/Feature/HallManagementDashboardTest.php`
plus `tests/Feature/HallQueueRecorderTest.php`,
and `node --test tests/Js/hall-management-core.test.mjs tests/Js/hall-management-reports.test.mjs`.

Synthetic browser fixtures are under `tests/browser/hall-*`. They bind only to
loopback and use `storage/logs/hall-browser-state.json`, never operational data.
They are not deployment files. Run the state builder with `reset`, `move`, `fail`,
`live`, `empty`, or `forbidden` to verify transitions, errors and access expiry.

## Release boundary

Publication of this release was approved; see the release record. For further
deployments, recheck the host
working tree, compare only the changed files, take private backups and deploy
atomically with a rollback manifest. Do not pull/reset the production checkout.
Invalidate config/view caches using the host's existing safe deployment workflow.
Run only the new `2026_11_01_120000_create_hall_queue_transitions_table.php` migration
after approval and backup; do not run unrelated pending migrations. No historical
queue timestamps are invented or backfilled. Rolling back the display does not
require dropping its metadata table; preserve it for audit/recovery.
Verify `/hall/unlock`, authentication, `/hall/data`, absence of public financial
data, local fonts, and an unrelated customer-tracking page after deployment.
