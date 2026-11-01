# Hall dashboard verification — 2026-11-01

Implemented in the separate local `main` checkout at
`E:/laragon/www/inventory-hall-main`. The original `dev` checkout is unchanged.
This records the pre-release local checks. The later authorized production release
is documented in `hall-management-release-2026-11-01.md`.

## Focused checks

- 24 backend feature tests, 90 assertions, pass. Coverage includes all four queue
  mappings, >120 active records, conversion deduplication/stable identity, raw rial
  amounts, line count versus units, total age, collapsed-stage timing, reentry,
  unknown historical times, recent nonempty drafts, financial daily totals,
  duplicate cheque metadata, cancellation, read-only SQL, guest/old-cookie denial,
  correct access, missing configuration, wrong key, and locking the display.
  The independent timestamp recorder is checked for commit/rollback, captured
  scalar values surviving subsequent model saves, collapsed internal statuses,
  conversion identity, deletion metadata, real Eloquent events without a worker,
  missing table, and recording failure after a successful business commit.
- 11 JavaScript core tests pass: duration formatting including multiple days,
  conversion movement, invoice edits, initial-load silence, honest exit labels,
  invalid/duplicate snapshots, raw amounts/counts, monotonic timing, malformed
  financial totals, and invalid event timestamps.
- Final regression selection: 78 PHP tests / 387 assertions passed, including
  finance persistence/edit/finalization, autosave identity, submitted visibility,
  and large preinvoice payloads alongside both new dashboard test classes.
- Changed PHP files lint successfully; browser JavaScript parses; Blade compiles.
  Original routes and customer tracking controller/view/assets have no diff.

## Browser checks

Actual page `/hall` was unlocked normally against the isolated local copy of the
operational database. It returned 54 active documents with no console warnings or
errors. A separate read-only CLI measurement returned the same 54 documents in
336 ms with 10 SELECT queries and zero operational mutations. This is one local
measurement, not a production load-test guarantee. With the empty new metadata
table installed, a later measurement returned the same documents in 372 ms with
14 SELECT queries and zero mutations. Only the new additive metadata migration
was run on the separately named local snapshot, behind a database-name/port guard.

Synthetic fixtures on a separate loopback-only server verified:

- `order:6` moved from finance to warehouse. Counts changed 9→8 and 8→9,
  one card remained, the event described the movement, the stage clock reset,
  and total age was retained. The detail dialog showed the exact rial amount
  and separate line/unit counts.
- A 503 response retained the last valid cards and financial amounts while showing
  a visible stale-data warning. Normal data recovered automatically.
- An empty snapshot showed zero active documents and all four empty states.
- A 403 response cleared the private screen and navigated to unlock; no metric or
  invoice elements remained on the resulting page.
- At 1920×1080 and 1280×720 there was no document overflow or clock clipping.
  At 390×844 the columns stacked with no horizontal overflow. Queues independently
  rotate rather than shrinking every card when more orders exist.

Legacy active records without reliable queue-entry history deliberately display
`—`; their total creation age remains visible. The local operational snapshot
predates today, so today's metric totals can be zero without implying live sales
are zero.

## Whole-repository test result

Before the final timestamp-recorder addition, the full suite produced a completed
report: 3401 passed, 375 failed, 2 existing
skips, 31045 assertions, 635.37 seconds. The dashboard class included at test
discovery had 11 tests / 51 assertions / 0 failures / 0 errors. The final 24-test
backend selection passed after the recorder and additional regressions were added.

This does **not** establish a green whole-project release. Example failures in
AppShellLayoutTest, ApplicationNameAndNotificationFrontendTest, model reflection,
CustomerInvoicePrintBalanceTest and InventorySyncSafetyTest were also recorded in
the earlier dev audit. The remaining failures have not all been individually
classified or reproduced on the unchanged main baseline during this task.

Raw full-suite output/JUnit remain ignored under `storage/logs/hall-full-suite.*`.
After the completed report, the still-running console wrapper and the temporary
synthetic browser server were stopped. The local review server on port 8001 is
kept available. Production publication was subsequently authorized and completed;
see the separate release record for the merged-baseline and live checks.
