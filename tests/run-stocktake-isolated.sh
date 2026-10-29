#!/usr/bin/env bash
# Run stocktake tests from a pinned snapshot, with Pest bootstrapped from
# THAT snapshot rather than from the external vendor/bin executable.
set -euo pipefail

ROOT="$(pwd -P)"
if [ "$#" -ge 1 ]; then ROOT="$(cd "$1" && pwd -P)"; fi
COMMIT="HEAD"
if [ "$#" -ge 2 ]; then COMMIT="$2"; fi
test -f "$ROOT/artisan" || { echo "STOP: not Laravel root"; exit 1; }
test -f "$ROOT/vendor/autoload.php" || { echo "STOP: missing vendor autoload"; exit 1; }
php -r 'if (!extension_loaded("pdo_sqlite")) {fwrite(STDERR, "STOP: pdo_sqlite missing\n");exit(1);}'

git -C "$ROOT" cat-file -e "$COMMIT^{commit}"
SNAPSHOT="$(mktemp -d /tmp/inventory-stocktake-snapshot-XXXXXXXX)"
chmod 700 "$SNAPSHOT"
cleanup() {
  case "$SNAPSHOT" in
    /tmp/inventory-stocktake-snapshot-*) rm -rf -- "$SNAPSHOT";;
    *) echo "Refusing unsafe cleanup path" >&2;;
  esac
}
trap cleanup EXIT

git -C "$ROOT" archive "$COMMIT" | tar -xf - -C "$SNAPSHOT"
# The Vite build directory is intentionally gitignored. Blade HTTP tests
# still need its manifest even though no browser assets are executed here.
if [ ! -f "$ROOT/public/build/manifest.json" ]; then
  echo "STOP: missing Vite manifest in the project root" >&2
  echo "Build frontend assets in a safe environment before running HTTP tests." >&2
  exit 1
fi
mkdir -p "$SNAPSHOT/public/build"
cp -a "$ROOT/public/build/." "$SNAPSHOT/public/build/"
test -f "$SNAPSHOT/public/build/manifest.json"
test -f "$SNAPSHOT/tests/run.php"
test -f "$SNAPSHOT/phpunit.xml"

export INVENTORY_TEST_VENDOR_AUTOLOAD="$ROOT/vendor/autoload.php"
export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:
export APP_BASE_PATH="$SNAPSHOT"

cd "$SNAPSHOT"
echo "Testing pinned snapshot $(git -C "$ROOT" rev-parse "$COMMIT")"
echo "Pest bootstrap from $SNAPSHOT/tests/run.php"
echo "Database: SQLite in memory"

php tests/run.php --configuration "$SNAPSHOT/phpunit.xml" \
  "$SNAPSHOT/tests/Feature/StocktakeCategoryWorkspaceTest.php" \
  "$SNAPSHOT/tests/Feature/StockCountLifecycleSafetyTest.php" \
  "$SNAPSHOT/tests/Feature/InventoryCountFreezeTest.php"

echo "STOCKTAKE_ISOLATED_TESTS_PASSED"
