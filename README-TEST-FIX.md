# Commission Phase 2 test fix

Replace only:

`tests/Feature/CommissionIncrementalSyncTest.php`

Changes:
- `DatabaseMigrations` -> `DatabaseTruncation`
  - keeps real transaction commits available for `DB::afterCommit()`
  - avoids rolling back every migration in teardown, which was triggering the old SQLite `warehouse_inbound_receipt_items.reason` index/drop-column incompatibility
- makes category/product fixture names unique inside the same test
- updates the historical product-name assertion to match the unique fixture name

No production service, observer, migration, commission formula, or ledger code is changed by this patch.

Run:
```bash
php artisan test --filter=CommissionIncrementalSyncTest
```

If green:
```bash
php artisan test --filter=CommissionCalculationEngineTest
php artisan test --filter=CommissionRateCoverageRegressionTest
php artisan test --filter=CommissionHistoricalRateRepairTest
php artisan test --filter=CommissionPilotHardeningTest
php artisan test
```
