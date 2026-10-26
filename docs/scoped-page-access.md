# Scoped finance and warehouse access

Product definition (`products.create`, `products.store`) now belongs to
`page.products.definition`, independently of browsing `page.products`. Creation
helpers are explicitly linked to the definition page. Existing catalogue browsing,
seller preinvoice workflows and owned-invoice viewing keep their existing guards.

`ScopedPageAccessService` replaces grants only for selected pages. It removes
scoped legacy action grants and inherited/direct page grants, then assigns each
page through a dedicated `Org:<page>` role to approved users. Unrelated permissions
and roles remain intact. Administrator aliases retain their existing bypass.
No administrator identity is assigned by this service.

All changes and the before-image of affected permission pivots are saved in one
transaction with an activity log. Validation failure or audit failure rolls back
the operation; permission caches are cleared even after failure. The deployment
operator also saves a private full ACL snapshot before applying the reviewed plan.
No inventory, return, invoice or accounting business records are modified.

Validation: dedicated scope, definition, linked-page, administrator and preinvoice
regressions pass (13 tests, 269 assertions). The broader access suite has existing
failures in PageAccessArchitectureTest, PageAccessMiddlewareTest and
UserPermissionManagementTest; the same failures were reproduced against the
pre-change catalog. These are not evidence that all historical access behavior is
correct, and remain outside the selected page-grant adjustment.
