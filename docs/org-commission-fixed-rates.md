# Fixed administrative commission rates

Each active member has a percentage of the total **net commission of confirmed/finalized seller sales documents fully contained in the selected period**. The existing base calculation is unchanged. Department totals are the sum of their members, so member money and department money always reconcile. No manual share amounts or adjustments are accepted by the document endpoint.

Rates are configured only at `/finance/reports/org-commission/settings`, under the existing administrative commission page access permission. Zero explicitly means no share; a missing rate blocks issuance. The combined active rates must be positive and at most 100%. Rates have four decimal percentage precision; each person's amount rounds to the nearest rial using integer arithmetic.

New documents store the complete settings snapshot (member/department identity, names, roles, rates). Editing a draft's period recalculates money from its original snapshot. Settings changes and member additions/deactivations never change existing document percentages. Legacy confirmed/finalized documents retain all amounts and allocation percentages; no backfill rewrites them.

## Deploy and initialize

1. Back up the five `org_commission_*` tables and scoped source files.
2. Pull the scoped commit, then run `php artisan migrate --path=database/migrations/2026_10_12_200000_add_org_commission_fixed_rates.php --force`.
3. Preview `php artisan org-commission:initialize-rates OC-000004`. The source document records **amounts**, so the initial member percentages are derived as `share_amount / total_seller_commission * 100`, rounded to four decimals. Historical department percentages are not assumed to be individual shares. Their legacy discrepancies are preserved in the old document; new department totals follow members.
4. Apply with `--actor=<administrator-id> --apply` only for the approved source document. The command creates a private backup and logs the initialization. It refuses to overwrite existing rates or omit an active person. It does not create a monthly commission document.
5. Clear route/view cache, render settings/create/old document/print, and verify no stock/customer ledger writes. Check settings/form on mobile and desktop.

For code rollback, revert the scoped application commit and retain the additive nullable columns and private backup. Do not roll back the production migration or delete stored snapshots. No unrelated commission, stock, supplier or customer account data is modified.

Focused regression: `vendor/bin/phpunit tests/Feature/OrgCommissionFixedRatesTest.php` (in-memory SQLite only).
