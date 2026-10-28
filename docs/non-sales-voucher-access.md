# Non-sales warehouse voucher access

The `Org:warehouse.non_sales_vouchers` role marks a restricted warehouse operator.
It is assigned to Mojtaba Arab Ahmadi (user 111) with `page.warehouse.issues`,
`page.sales.returns`, `page.assets` and the asset page's explicit action permissions.
The existing statement read-only role is retained. Other users' grants are unchanged.

The role allows personnel issues, between-warehouse transfers, scrap and customer
returns through the existing workflows. It denies purchase and sales-voucher
routes, including direct URLs, raw submissions and editing/deleting an existing
sales transfer. Generic voucher creation is denied; operators use the dedicated
section forms. The shared voucher archive/output report excludes disallowed types.
Showroom's dedicated section is not granted by this profile.

Administrator access and ordinary warehouse roles retain their existing behavior.
Return approval, count freeze and inventory validations are not bypassed.
Production permission assignment is transactional, backed up, and audited as
`permissions.mojtaba_non_sales_vouchers_granted`.

Tests cover allowed sections/lookups and asset action gates, forbidden sales routes,
forged document type, direct access to sales documents and administrator compatibility.
The pre-existing PageAccessMiddlewareTest "retained legacy direct permissions"
failure was reproduced against the preceding PageAccessCatalog version unchanged.
