# Navasan CNY/free-plan implementation handoff

**Status:** changes-only development patch; **not a production-validated release**. No server changes, new migration execution, or live API-key test were performed by this patch author. Do not enable automated repricing before isolated acceptance testing. Based on the supplied Persian technical task and operator CLI output, 11 November 2026.

### What the operator's server output already proves (CLI, not authenticated web)

- Git HEAD is `2cf3b18a304570c6fd40340fe8a7d26b1b8b8a37`, matching the document's reviewed commit.
- `CNY_PRICING_ENABLED=false`, `CNY_ALLOW_SAMPLE=false`, `CNY_REPRICE_SCHEDULE_ENABLED=false`, and `CNY_MAX_RATE_AGE_MINUTES=30` are effective for that **CLI runtime**.
- Both base yuan migrations `2026_11_09_210000` and `2026_11_09_220000` show **Ran**.
- `purchaseCnyEnabled` appears in the deployed Blade file. The disabled feature flag explains why it is not rendered on new-purchase forms in that configuration; actual browser/web runtime was not inspected.
- `schedule:list` does not display CNY jobs while their flags are disabled (expected).
- **Several unrelated migrations show Pending. Do not execute unscoped `php artisan migrate` on this production database.** Scope the new migration only, after review, backup and staging trial.
- Interactive Tinker worked after clearing PHP CLI `disable_functions` for that invocation; the hosting environment restricted `shell_exec`. The new CNY scheduler jobs deliberately invoke `Artisan::call()` **in-process** rather than spawning subprocesses.
- The separately provided `inv public .zip` contains the original `public/js/cny-pricing.js` and `public/css/cny-purchase.css`. Both are unchanged by this patch; the JS test file passes 3/3 with that asset.

## 1. Production diagnosis: read-only first

From the *actual* Laravel release root, by an authorized operator:

```bash
pwd
# If this production release is managed by git:
git rev-parse HEAD
git status --short
grep -n 'purchaseCnyEnabled\|cnyEnabled' resources/views/purchases/create.blade.php
test -f public/js/cny-pricing.js && echo 'JS exists'
test -f public/css/cny-purchase.css && echo 'CSS exists'
php artisan config:show cny_pricing
php artisan migrate:status
php artisan route:list --name=purchases.create
php artisan schedule:list
```

Check `CNY_PRICING_ENABLED` from **the web runtime**, not only PHP CLI. Inspect `#purchaseCnyEnabled` with an authorized signed-in user (no real purchase submission). Compare release SHA, deployed migrations and config/view caches; **do not run** `config:clear`, `migrate`, `git pull`, or `git reset` as part of this inspection. Capture evidence without secrets or financial data. The initial backend ZIP omitted the public yuan assets; the separately supplied public ZIP contains them. These assets have not been changed in this patch. Confirm they are served by the authenticated web release before activation.

## 2. Decisions for the selected free plan

- Symbol: `cny` (NOT `cny_hav`). API response value must be verified with an actual safe keyed sample and the provider's agreement: is it **IRR or toman per CNY**? The default `NAVASAN_RESPONSE_UNIT=unverified` intentionally prevents API calls until verified.
- Navasan officially documents an **HTTP** `latest` endpoint; this implementation **only permits HTTPS** to prevent leaking `api_key` in transit. Confirm HTTPS with the provider and a safe connection test before enabling. If TLS is unavailable, **do not transmit the key**; arrange a provider-approved encrypted transport instead. No insecure HTTP fallback.
- Free plan: 120 requests/month with rate updates approximately every 2 hours as described on the provider site at time of writing. The scheduled fetch is **3/day** at **00:05 / 08:05 / 16:05 Asia/Tehran**, with a **hard ceiling of 90 requests in any rolling 31 days** in the database. Failed calls also count. No automatic retries. This is *not* real-time exchange-rate pricing.
- Set max CNY rate age to 660 minutes (11 hours) for the free cadence after verifying the business agrees. The original code's default is 30 minutes. If a quote is too old, fail closed; never assign a new fetch timestamp to an unchanged source quote.
- The rate-jump guard defaults to 15% from the prior **accepted** Navasan quote; abnormal jumps are rejected and require manual commercial review. This is a conservative configurable default, **not** a confirmed commercial policy. Avoid silently loosening this setting.
- Existing catalog repricing threshold remains **10,000 IRR per CNY (1,000 toman)** versus the last applied rate. Product cost basis, old purchases, inventory, supplier ledger and sales history must not change.

## 3. Minimal environment configuration (examples only; never commit real secrets)

Keep these **disabled** during staging diagnostics:

```dotenv
CNY_PRICING_ENABLED=false
CNY_ALLOW_SAMPLE=false
CNY_PRODUCTION_MODE=true
CNY_REPRICE_SCHEDULE_ENABLED=false
CNY_MAX_RATE_AGE_MINUTES=660
NAVASAN_ENABLED=false
NAVASAN_FETCH_SCHEDULE_ENABLED=false
NAVASAN_API_KEY=
NAVASAN_RESPONSE_UNIT=unverified
NAVASAN_MAX_CHANGE_PERCENT=15
NAVASAN_ROLLING_31_DAY_LIMIT=90
NAVASAN_MAX_QUOTE_AGE_MINUTES=660
```

**Before any new migration:** rehearse on staging with a test database and backup. Your production CLI reports unrelated pending migrations; do not run the general `php artisan migrate`. If approved for the exact new file only, use a reviewed *path-scoped* migration (on staging first):

```bash
php artisan migrate --path=database/migrations/2026_11_11_100000_add_navasan_rate_fetch_support.php --force
```

After code review, backup, verified HTTPS + currency unit, verified tables and **staging** tests: add the API key in an approved server secret channel, set `NAVASAN_RESPONSE_UNIT=rial` **only if the live response truly uses IRR/CNY** (otherwise `toman`). For supervised staging tests only, enable `NAVASAN_ENABLED=true` with both schedules still false; run `php artisan cny:fetch-rate` **once**, then `php artisan cny:navasan-status` read-only. `cny:fetch-rate` **never reprices**. The API key is never printed.

After explicit commercial approval, enable the existing CNY purchase feature and `CNY_REPRICE_SCHEDULE_ENABLED=true` (reprice every 5 minutes from stored quotes). Separately set `NAVASAN_FETCH_SCHEDULE_ENABLED=true` for scheduled fetch. Confirm the real cron/scheduler executes and synchronization reaches the customer-facing Site. Use release-window configuration cache regeneration **only after checking the real web runtime**.

## 4. Schema and security

The additive migration `2026_11_11_100000_add_navasan_rate_fetch_support.php` adds nullable `symbol`, `fetched_at`, a source-quote unique index, a shared DB guard row and request attempt audit records. It does not overwrite historic quotes, purchases or prices. **Test migration and rollback on an isolated copy after verified backup; schema rollback deliberately refuses to remove imported Navasan history**; never drop production rate history as a rollback strategy.

`CnyPricingService::activate()` now always checks the whole-product price version even when a client submits `uniform_product_prices=0`. `CnyPricingService::currentRate()` now disallows sample rates when Navasan is enabled or the environment is neither local nor testing. On production set `CNY_PRODUCTION_MODE=true` and `CNY_ALLOW_SAMPLE=false` regardless of `APP_ENV`. Local sample tests must explicitly set `CNY_PRODUCTION_MODE=false`.

## 5. Validation and acceptance

Run in an **isolated test database**, with dependencies installed:

```bash
php artisan test tests/Unit/CnyPriceCalculatorTest.php
php artisan test tests/Feature/CnyPurchasePricingTest.php
php artisan test tests/Feature/NavasanRateFetcherTest.php
node --test tests/Js/cny-pricing.test.cjs
```

Dependency-free parser smoke test: `php -d zend.assertions=1 -d assert.exception=1 tests/standalone/navasan-quote-parser.php`.

**Local validation already performed on the supplied files:** all changed PHP files passed `php -l`, the standalone parser script passed, and the original yuan calculator JS test passed **3/3** using the public ZIP's unchanged asset. Full Laravel/Pest tests and a database migration/rollback exercise were **not** run: the uploaded backend snapshot lacks a full runnable Laravel root (`artisan`, `vendor/`, installed test dependencies). Syntax and standalone tests do not certify application behavior. The real keyed Navasan exchange rate and Site sync were not tested.

Check both directions of rate change and the 1,000-toman threshold; duplicate quote; unauthorized/sample or invalid quote; zero, outdated/future values, anomalous change, HTTP 429, timeout; concurrency; discount_price behavior on the external Site; and that history/stock/ledgers are untouched. The Site discount policy still requires business approval. The supplied ZIP also omits the repository CI workflow, so `main` CI cannot be modified/validated here.

## 6. Staged release and rollback

1. Authorized operator captures release SHA, baseline prices, table schemas, backup and restore rehearsal.
2. Deploy reviewed code + additive migration in a controlled window, with **all new flags disabled**.
3. Verify the CLI/web config, status command, allowed HTTPS endpoint, real response unit and one supervised fetched quote; check audit/status and logs for key leaks.
4. Run the pricing/UI regression suite; verify product/variant prices and Site sync *including discounted variants* on staging.
5. Enable schedules and monitor only after sign-off. If a problem occurs, **disable both schedules and Navasan fetch**, preserve rate history and purchases, investigate before restoring code. Do not run a destructive rollback of historic data.

### Explicitly unresolved without production access / a real key

Authenticated web-runtime checkbox/cache (only CLI flags were shown), HTTPS support at the Navasan API host, exact keyed `cny` response shape and IRR/toman unit, real scheduler execution (schedule:list is not execution), current external Site pricing/discount behavior, actual merchant discount policy, and successful staging/production end-to-end synchronization. None of these can be proven by the local development patch. **Do not send API keys, `.env`, or production financial records in chat.**
