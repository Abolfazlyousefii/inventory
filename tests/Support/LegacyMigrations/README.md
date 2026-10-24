# Test-only historical schema

These 145 files are the unchanged migrations deleted by commit
`e2cbf9f6a69439e49cdb32cc6661d1e4b9380620`, recovered from its parent.
Files still present in `database/migrations` were not copied.

`Tests\TestCase` registers this directory only after verifying the application
is testing against an in-memory SQLite database. Production application startup
does not register it. Do **not** copy these files into production migrations or
run them against an existing operational database.

The current migrations and this historical baseline together allow a fresh
test database to be built. This is a test fixture, not a production database
repair or a substitute for future forward-only migrations.

Run from the repository root:

```sh
php tests/run.php --configuration phpunit.xml
```

An isolated checkout can optionally set `INVENTORY_TEST_VENDOR_AUTOLOAD` to an
existing Composer `vendor/autoload.php`. The bootstrap keeps application and
test classes bound to this checkout; shared dependencies do not select another
checkout's tests. The test application always refuses an operational database.
