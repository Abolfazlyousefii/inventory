# Phase 1.1 Restore — Current Main Compatibility

Baseline checked on GitHub:
`da094f050d376cd731a12c8bd034858e49a57734`

Purpose:
Restore the timeline-aware historical commission rate repair command before Production rollout.

Files:
- ADD `app/Console/Commands/RepairMissingCommissionRates.php`
- ADD `app/Services/Commissions/CommissionHistoricalRateRepairService.php`
- REPLACE `app/Services/Commissions/CommissionRateService.php`
- ADD `tests/Feature/CommissionHistoricalRateRepairTest.php`

Why this is required:
The current main branch contains the commission audit command, but the safe historical repair
command/service and `backdateRevision()` capability are not present. Without them, historical
missing-rate gaps must not be repaired by guessing or by backdating the latest active rate.

Local verification:

```powershell
php -l .\app\Console\Commands\RepairMissingCommissionRates.php
php -l .\app\Services\Commissions\CommissionHistoricalRateRepairService.php
php -l .\app\Services\Commissions\CommissionRateService.php

php artisan optimize:clear
php artisan test --filter=CommissionHistoricalRateRepairTest
php artisan test --filter=CommissionRateCoverageRegressionTest
php artisan test --filter=CommissionCalculationEngineTest
php artisan test --filter=CommissionPilotHardeningTest
php artisan test
```

Then confirm command registration:

```powershell
php artisan list | Select-String "commissions:repair-missing-rates"
```

Production rule:
Never apply Local repair decisions to Production. After deploy, first obtain fresh Production
dry-runs. Only plans with `Blocked targets = 0` and `Unresolved items = 0` may be considered
for `--apply`.
