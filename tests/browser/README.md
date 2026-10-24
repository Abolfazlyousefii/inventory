# Personnel voucher browser regression

These checks intercept every HTTP request and never submit a real voucher.
Generate HTML fixtures using the isolated PHPUnit database, then run the browser checks:

```powershell
$env:VOUCHER_BROWSER_FIXTURES = "$PWD/storage/app/voucher-browser-fixtures"
php tests/run.php --configuration phpunit.xml tests/Feature/PersonnelVoucherManagementTest.php
node tests/browser/personnel-voucher.cjs $env:VOUCHER_BROWSER_FIXTURES
```

Install/provide Playwright and Chrome. `PLAYWRIGHT_MODULE` can point to an existing
Playwright module; `VOUCHER_PUBLIC_FALLBACK` optionally provides shared public assets
when running in a Git worktree without installed dependencies. The script checks
request failures/retry, stale responses, quantity limits, required receiver,
restored items, root categories without children, row reindexing, and overflow at
390, 768 and 1366 pixels. Screenshots are saved beside the fixtures.

## Large bundle regression

Set `BUNDLE_BROWSER_FIXTURE` to an HTML file in `storage/app/`, run
`tests/Feature/LargeProductBundleTest.php` with the isolated PHPUnit runner, then:

```
node tests/browser/large-bundle.cjs <fixture.html>
```

This selects all 300 variants, checks totals, checks deselection/reselection,
and verifies that recipe and allocation snapshots use one complete JSON field.
All requests are intercepted; no products or warehouse stock change on a live server.
