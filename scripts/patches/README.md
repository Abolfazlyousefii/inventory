`site-direct-inventory-count-freeze.patch` targets the separate Site application,
not this Inventory repository. Apply from the Site root with
`git apply --unidiff-zero --check <patch>` followed by the same command without
`--check`, after verifying the original file and taking a backup.

Target: `app/Services/Inventory/DirectInventoryStockHold.php`.
Original SHA256: `2e87d0b237c32b01515776ee82b37d1f8eda3acdb8839efc46ad54b8a1d7ccbd`.
Patched SHA256 (LF): `66def6bacbc8af167c1724523e9822ce0bbed56ac914be097a672ddb5d3ae415`.
Deployed SHA256 (CRLF): `efb21d13c5e6fb02787665582194034b98db163d9c2d22763648a368c3e1446b`.

The patch shares Inventory's count boundary before reserving, releasing or
expiring Site holds, and checks selected categories, descendants, snapshots and
bundle components. Payment confirmation remains a status-only operation.
Validated in a disposable MySQL schema with 12 checks, including two-process
contention against exclusive count activation; no production stock was changed.
