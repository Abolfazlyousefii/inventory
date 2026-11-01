# Hall management production release — 2026-11-01

The user authorized publication with “برام در هاست پول کن”. The management display
is now live at `https://inv.ariyajanebi.ir/hall`, using the existing display key.
The original local `dev` checkout was not changed.

## Source and deployment

- Production had advanced to `5257ee5b` since the original local work. Local main
  merged that version before publication; there were no dashboard-file conflicts.
- Main release code: `316dd2b9`. Follow-up toolbar suppression: `f4d9659a`.
  The latter disables the existing debug toolbar only for Hall requests.
- Candidate files were verified against Git blob SHA-256 hashes, backed up, linted
  with host PHP 8.4, and replaced with same-directory atomic renames. Dependencies
  were installed before controller/middleware/provider activation. The exact
  candidate tree was staged and fast-forwarded to the corresponding GitHub commit.
- Host tracked source was clean before deployment. Seven unrelated, untracked
  stocktake files were backed up by hash and preserved. No reset, clean, force-push,
  production test invoice, payment, reservation or stock mutation was performed.
- Only `2026_11_01_120000_create_hall_queue_transitions_table.php` was migrated.
  Existing operational tables were not altered and historical times were not filled
  with estimates. Configuration was recached and compiled views cleared.
- The static asset directory needs mode 0755; files are 0644. Its initially
  restrictive directory mode was corrected, and JS, CSS and core module returned
  HTTP 200 with appropriate content types afterward.

## Verification

- Final focused tests: 25 backend tests / 91 assertions passed; 11 JS tests passed.
- On the updated main baseline, the expanded regression selection had 29 passes
  and 49 setup failures: duplicated `products.bundle_quantity_mode` in the SQLite
  test schema. One identical failure was reproduced on unchanged `5257ee5b` in a
  separate checkout. This does not establish a green whole-project suite.
- A one-use, secret-header-protected web check verified the actual document root
  and current controller hash under LiteSpeed PHP 8.4.27. OPcache was disabled in
  that web SAPI. The diagnostic removed itself and subsequently returned HTTP 404.
- Browser login succeeded with the existing key. The dashboard reached its live
  state, loaded all three financial totals and four queue counts, and showed no
  console warnings or errors. The actual document-detail dialog displayed raw rial
  amount, line count and separate unit count correctly.
- A new operational draft acquired a real queue timestamp through the recorder
  during ordinary staff activity; no synthetic production document was created.
- Unauthenticated `/hall/data` returned HTTP 403 with no financial/customer keys.
  `/hall/unlock` returned HTTP 200 without the debug toolbar.
- Original routes and customer-tracking controller/view/assets remain unchanged
  relative to the production baseline for this release.

## Evidence and rollback

Private host backup: `/home/ariyajan/backups/hall-management-20261101-r1`.
Candidate/helper: `/home/ariyajan/releases/hall-management-20261101-r1`.
Toolbar follow-up backup is under the backup's `toolbar-fix` directory.
Private local evidence is under `storage/app/private/hall-management-20261101-r1`;
test reports remain under ignored `storage/logs/hall-*`.

For emergency source rollback, restore the toolbar follow-up first, then the
main release backup, checking the current file hashes before every replacement.
Preserve the queue metadata table and any events already recorded. Recache config
and clear compiled views, verify Hall access, then reconcile Git with a forward
revert rather than resetting or cleaning production. Do not overwrite concurrent
operator edits or remove the unrelated untracked stocktake files.
