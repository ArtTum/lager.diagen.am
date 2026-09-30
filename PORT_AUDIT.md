# Laravel/Vue port audit — 30 requirements

**Snapshot:** 2026-09-30  
**Scope:** Laravel/Vue code, automated tests, and local data-preservation rehearsal, compared with the legacy PHP 30-point audit in `../docs/requirements-audit-2026-09-29.md`.

This is a migration audit, not a production acceptance certificate. A feature being present in code or covered by unit/API tests does not prove the new UI has been accepted by users on staging. The production gates are listed in [DEPLOYMENT.md](DEPLOYMENT.md).

| # | Requirement | Laravel/Vue evidence | Current verification and remaining work |
|---:|---|---|---|
| 1 | Supplier-to-use-to-report workflow | `PurchasingWorkflowApiTest::test_supplier_purchase_to_branch_consumption_and_stock_report_work_as_one_api_lifecycle` | A single Laravel API scenario now creates and approves a supplier order, receives a LOT, submits and approves a branch request, ships/receives/closes it, consumes stock, and verifies both central and branch balances in the report. End-to-end Vue UI acceptance against restored staging data remains. |
| 2 | Module and data relationships | Eloquent models, import/upgrade migrations, `audit_legacy_schema.php`, `verify_legacy_upgrade.php` | Local source schema audit reports 24 tables, 214 columns, 50 FKs and no orphans. Staging comparison still remains. |
| 3 | Required application sections | Vue router, sidebar groups, page/API routes | SPA shell and route coverage are present. Role-by-role browser acceptance on Laravel staging remains. |
| 4 | Dashboard metrics | `DashboardRepository`, `DashboardRepositoryTest`, dashboard API | Local automated summary test passes. Staging values need comparison against the restored source data. |
| 5 | Receiving, LOT, barcode, issue, return, inventory and immutable movements | Purchasing/stock/return/inventory APIs, label and reversal services/tests | Software paths are implemented and covered across workflow tests. Physical scanner/printer operation and UI acceptance remain. |
| 6 | Supplier card and history | Supplier service/repository, `SupplierHistoryPricingTest`, `SupplierAuditHistoryTest` | Pricing visibility, pagination, and audit redaction tests pass. Real supplier data completion remains an organization task. |
| 7 | Product card, codes, thresholds and LOT metadata | Catalog requests/service, `CatalogMetadataPersistenceTest`, barcode search and label tests | Metadata persistence and barcode behavior are tested. Staging catalog comparison remains. |
| 8 | On-hand, reserved and available stock by LOT | Stock, request and transfer services/repositories | Reservation, receipt and consumption behavior is covered by workflow tests. Full restored-data balance reconciliation remains. |
| 9 | Receipts only against approved documents | Purchasing API/service and `PurchasingWorkflowApiTest` | Approved-order and multi-LOT receipt path passes automated tests. Staging data comparison remains. |
| 10 | Issue reasons and insufficient-stock rejection | `StockConsumptionApiTest`, return and movement reversal tests | FEFO consumption, reserved stock and excess-quantity rejection are tested. Staging UI acceptance remains. |
| 11 | Separate branch records and virtual warehouses | Branch model/catalog, branch scope checks, schema migration | Branch-scoped access rules have automated tests. Staging role/browser acceptance remains. |
| 12 | Branch stock visibility and central view | Stock matrix/list APIs, branch-scoped repositories and export scope tests | Server-side location scoping and cost hiding have tests. Staging role comparison remains. |
| 13 | Request suggestions and urgency/reason | Request API/service and `StockRequestWorkflowApiTest` | Suggestions use branch stock and recent internal usage in tests. Staging workflow acceptance remains. |
| 14 | Request lifecycle and statuses | `StockRequestService`, `StockRequestWorkflowTest`, `StockRequestWorkflowApiTest` | Draft-to-review/approval/dispatch/receipt/close flow has automated coverage. Staging UI acceptance remains. |
| 15 | Full/partial approval and rejection reasons | Request workflow tests and validation requests | Reservation, partial fulfillment and rejection rules have tests. Staging role acceptance remains. |
| 16 | Dispatch document with sender/receiver and dates | Dispatch-document API and `StockRequestDispatchDocumentTest` | Visibility and pre-shipment restrictions are tested. Printing/PDF rendering on staging remains. |
| 17 | FEFO selection | `StockLotMatchingTest`, stock consumption and return workflow tests | LOT ordering and expired-stock rejection are covered. Staging data acceptance remains. |
| 18 | Central-plus-branch stock matrix | `/api/stock/matrix`, Vue stock matrix view, `StockMatrixApiTest` | API regression tests verify central totals from multiple LOTs, active locations, branch-only visibility, search, and pagination. Staging balance reconciliation remains. |
| 19 | MIN/OPT/MAX notifications | Notification repository/service and `NotificationRepositoryTest`, `NotificationServiceTest` | Branch/central scoping and exact MIN, between-limits, MAX, and over-MAX cases are automated. Products without expiry control are excluded from expiry notices. Staging UI acceptance remains. |
| 20 | Movement filters, balances, exports and pagination | Movement API/repository, `MovementExportTest`, and Vue movement page | A combined date/product/category/supplier/actor/type/LOT/branch/search scenario passes. Filter choices are scoped to movements visible to the actor; supplier names, IDs, options, and filtering require `suppliers.view`. Branch and central scopes and cost visibility are tested. Staging UI/data acceptance remains. |
| 21 | Inventory count, reason, independent approval and act | `InventoryWorkflowApiTest`, `InventoryActTest` | Count, independent approval, stock adjustment and act scope are tested. Staging print acceptance remains. |
| 22 | Expiry windows (180/90/60/30/7/expired) | Expiry page query and `ExpiryPageQueryTest` | Automated query checks cover inclusive today-through-7/30/60/90/180 boundaries and expired-before-today semantics; expiry-disabled products are excluded. Staging acceptance remains. |
| 23 | Branch-to-central and central-to-supplier returns | Return API/service and `ReturnWorkflowApiTest` | Both directions, FEFO supplier return and excess quantity checks are covered. Staging acceptance remains. |
| 24 | Branch-to-branch transfer lifecycle | Transfer API/service and `TransferWorkflowApiTest` | Separate approval, sender, receiver and expired-stock restrictions are tested. Staging role acceptance remains. |
| 25 | Reports, filters, CSV/XLSX/PDF | Report service/repository, tabular export tests and Vue reports view | CSV output content and combined filters have automated checks; supplier filters/columns follow supplier or purchasing visibility. PDF is browser print-to-PDF, not a server-generated PDF endpoint. Report-by-report staging acceptance and physical print rendering remain. |
| 26 | Role/location-scoped notifications and sound | `NotificationApiScopeTest`, notification service/repository tests, Vue notifications view, `notification-audio.test.js` | Authenticated API checks verify distinct branch stock notifications and per-user read isolation across same-branch and other-branch users. AudioContext resume, tone generation, unsupported browser, disabled-state, and pending-disable behavior have automated checks. Actual browser/device audio and staging notification acceptance remain. |
| 27 | Six roles and permissions | `LagerAccessSeederTest`, `AuthPermissionApiTest`, permission service and route-coverage tests | Six role maps and authorization boundaries are automated. Vue refreshes the auth context on focus and every 45 seconds, then reloads or redirects when access/scope changes. Full role-by-role staging browser acceptance remains. |
| 28 | Immutable audit trail | Audit model/migrations, permission tests and upgrade rehearsal | Upgrade rehearsal confirmed trigger protections and preserved trigger definitions. Staging DB behavior remains. |
| 29 | Supplier/product/branch/workflow/report data links | Eloquent models, repositories, foreign keys and upgrade backfills | Local schema/upgrade rehearsal found no missing columns or orphan rows and preserved business-table hashes. External CRM synchronization is not specified by the legacy requirement. |
| 30 | Management answers in one or two steps | Dashboard, reports and product trace APIs/views | Summary/reporting capabilities exist and dashboard/report tests pass. A user acceptance run against the ten questions on Laravel staging remains. |

## Overall result

- **Implemented in code:** the required modules and workflow APIs are present; API route coverage requires authentication, an active account, and an explicit catalogued permission.
- **Automated evidence:** Laravel PHP suite, Vue permission/router tests, production build, schema auditor, and isolated upgrade rehearsal are recorded in the current task results and deployment runbook.
- **Not yet proven:** acceptance in a browser against a restored staging database, production backup/restore and off-host retention, hardware scanning/printing, and live domain cutover. Therefore this port is **not certified 30/30 complete** yet.

## Latest local checks

- `php artisan test --compact` — 138 passed, 1,837 assertions (includes legacy password-hash compatibility, module parity, and frontend permission parity).
- `npm run test:js` — 12 passed, including notification audio lifecycle/failure handling, notification-session isolation, and changed-permission context checks.
- `npm run build` — passed (111 modules transformed).
- `composer validate --strict` — passed.
- PHP syntax check — 138 application/bootstrap/config/migration/route files passed.
- `php tools/audit_legacy_schema.php` — `ready_for_migrations`; only the two expected optional foreign keys are absent, with no orphan rows or column mismatches.
- `php tools/verify_legacy_upgrade.php` — passed on its isolated local QA database; legacy receipt quantities, role grants, foreign keys, immutable audit/corrections, and idempotence were checked.
- `php tools/rehearse_local_upgrade.php` — passed using a temporary clone of the configured local Lager DB: 26 tables/578 rows copied, pending migrations 000005/000006 applied to the clone, all 23 non-permission business tables retained identical row hashes, all four trigger definitions matched, and both expected optional foreign keys were restored. The clone was confirmed removed; the source DB remained untouched.

## Additional expiry/threshold regression coverage — 2026-09-30

- Expiry listing and notification queries now consistently ignore products with expiry control disabled, matching the expiry report query.
- Added exact inclusive boundary checks for 7/30/60/90/180-day expiry filters and expired-before-today behavior.
- Added MIN, between-limits, exact-MAX, and over-MAX notification boundary checks.
- Re-ran the complete PHP suite: 121 passed, 1,346 assertions.

## Stock matrix API regression coverage — 2026-09-30

- Added central and branch API coverage for LOT aggregation, active-location visibility, search, pagination, and branch quantity isolation.
- Re-ran the complete PHP suite after the matrix tests: 123 passed, 1,365 assertions.

## Movement scope and filter privacy — 2026-09-30

- Added a combined movement-filter regression scenario with individual near-match records for the filter dimensions and checked central/branch visibility.
- Scoped product, category, actor, supplier, and movement-type filter options to the actor's visible movement history.
- Hid supplier names, IDs, filter options, and export columns unless the actor has `suppliers.view`; forged supplier filters are ignored without that permission.
- `php artisan test --compact --filter=MovementExportTest` — 2 passed, 24 assertions.

## Notification audio automated coverage — 2026-09-30

- Extracted notification tone playback into an injectable AudioContext helper and covered resume/play, disabled/unsupported audio, and suppressing a pending tone after sound is disabled.
- `npm run test:js` — 12 passed; `npm run build` — passed (111 modules transformed).
- Automated AudioContext tests do not establish that a physical browser/device produced audible sound; staging/browser acceptance remains required.

## Permission architecture review against Swift Rent ERP — 2026-09-30

- Reviewed the reference ERP's tenant permission service, user-group models, middleware, route declarations, and FormRequest validation pattern.
- Kept the transferable design: one centralized permission decision service, model/repository-backed permission data, explicit route middleware, and shared server/UI capability maps. Adapted the reference's group capability names (`can_view`, `can_add`, `can_edit`, `can_delete`) to Lager's action codes (`module.view`, `module.create`, `module.edit`, `module.delete`) because the role/permission schema and inventory workflow actions differ.
- Preserved Lager-specific safeguards that the reference pattern does not supply: inactive-account denial, branch capability allow-list, central-only actions, hiding cost/supplier fields and filter options, and preventing users from granting permissions or assigning roles above their own scope.
- Audited the application layers: controllers contain no inline `validate()` calls, and controllers/services/repositories contain no `DB::table()` query-builder access; validation lives in FormRequest classes and persistence reads/writes use Eloquent models/repositories. `DB::transaction()` in services remains appropriate for atomic workflows.
- Added an API route-to-action permission contract test: each protected route must require the exact permission matching its module and action, not merely any catalogued permission. Route authentication/active-user protection remains checked separately.
- Verified `ApiPermissionCoverageTest`: 4 passed, 588 assertions. Also verified `PermissionServiceTest`, `CatalogOptionPermissionTest`, `CatalogServicePermissionOptionsTest`, `ReportServiceTest`, and `ReportRepositoryCompatibilityTest`: 26 passed, 93 assertions. The full PHP suite passed: 130 tests, 1,597 assertions; Vue/JS suite: 12 passed.

## Upgrade rehearsal against the configured source data — 2026-09-30

- The configured local MySQL database recorded migrations 000001–000004; 000005 and 000006 were pending. Did not apply pending migrations to the source database.
- Added `tools/rehearse_local_upgrade.php`, restricted to local MySQL and the expected `diagen_lager` database. It copies schema, rows, and trigger bodies into a uniquely named temporary database, applies pending Laravel migrations there, hashes source tables, and removes the copy in `finally`.
- Rehearsal passed with 26 tables and 578 rows copied. Before migration, every table hash matched the source. After migration, all 23 business tables outside the intentionally changed permission/grant/migration ledger tables retained identical row hashes; all four trigger definitions matched, both optional foreign keys were present, and cleanup confirmed no temporary QA database remained.
- This verifies the migration against the current configured local dataset only. Production backup restoration, staging browser acceptance, and production migration/cutover remain separate gates.
- A follow-up `--keep` rehearsal added six synthetic role accounts to a temporary clone and started Laravel at `127.0.0.1:8096`. The in-app browser rejected opening the local address with `ERR_BLOCKED_BY_CLIENT` before rendering the app; no browser login or alternate browser surface was used. The server was stopped and the exact clone removed with `drop_local_port_qa_database.php`; the source remains unchanged. Browser acceptance is still unverified.

## Exact standard-role grant regression — 2026-09-30

- Added a regression check that compares every seeded permission for all six standard roles against its intended full permission set, so both missing and unexpected grants fail the test. Existing checks continue to cover effective branch scope, central-only actions, login maps, and preservation of organization-customized grants.
- `php artisan test` — 131 passed, 1,603 assertions.
- `npm run test:js` — 12 passed.
- `npm run build` — passed (111 modules transformed).

## Laravel architecture regression checks — 2026-09-30

- Added `ArchitectureLayeringTest` to enforce the requested Laravel layering: API controllers inject application services, keep validation out of controller methods, and controllers/services/repositories do not introduce `DB::table()` shortcuts.
- Focused architecture checks — 2 passed, 102 assertions.
- Full PHP suite after adding the architecture guard: 133 passed, 1,705 assertions.

## Current source-data and deployment access checks — 2026-09-30

- Re-ran `php tools/audit_legacy_schema.php` against the configured local source database in read-only mode: 24 tables, 214 columns, 50 foreign keys, no missing tables/columns or type mismatches, and no orphan rows. Only the two documented optional foreign keys remain pending for migration.
- Re-ran `php ../tools/supplier-data-audit.php`: 4 active suppliers remain incomplete. Missing fields include tax number/address/contact/phone/email/contract number/payment terms on one record, bank information on all four, and contract start/end dates on all four. This report exposes counts only; real values must come from verified supplier documents.
- `composer validate --strict` passed.
- A read-only SSH probe to the user-provided host `104.248.33.127` reached the server but authentication failed with `Permission denied (publickey,password)`. No remote files or services were changed. Deployment/staging acceptance cannot proceed until an authorized SSH key or credential is available.

## Cross-module API lifecycle regression — 2026-09-30

- Added one integrated Laravel feature scenario spanning supplier purchase creation/approval, multi-step receipt into a central LOT, branch request review/approval, collection, shipment, branch receipt, closure, branch consumption, and the stock-by-location report.
- Assertions verify the request reaches `closed`; supplier, approved purchase, receipt and LOT references survive distribution; expiry and LOT identity are retained at the branch; quantities reconcile after consumption; movement history contains receipt/out/in/consumption records; and the report returns matching location balances.
- Full PHP suite after strengthening cross-module persistence checks: 134 passed, 1,734 assertions.

## Notification API branch/user isolation — 2026-09-30

- Added `NotificationApiScopeTest`: two branch-specific stock alerts are queried through the authenticated API; each branch sees only its local quantity/threshold notice.
- Marking a notice read for one account does not mark the same notice read for another account at that branch or for a different branch. The test verifies the persisted composite user/notice key records.
- Full PHP suite after notification isolation coverage: 135 passed, 1,753 assertions.

## Legacy upgrade revalidation — 2026-09-30

- Re-ran `php tools/verify_legacy_upgrade.php`: passed. The isolated run preserved legacy purchase received quantities, backfilled four receipt items and one transfer item, preserved existing export grants, kept eight legacy foreign keys and the normalized return index, blocked orphaned-FK migrations before partial application, retained immutable audit/correction triggers, and produced no duplicates on a second run.
- Re-ran `php tools/rehearse_local_upgrade.php` against the configured `diagen_lager` source using its guarded temporary clone: 26 tables / 578 rows copied; both pending migrations applied to the clone; 23 business-table hashes and all 4 trigger definitions matched; two optional foreign keys were restored; the clone was removed automatically. No browser QA accounts were added and the source database was not migrated.
- The rehearsal now compares permission records and grants by stable natural keys as well: all 90 existing permission definitions and 261 grants matched; the only additions were the 15 export capabilities and 72 export grants derived from those roles' existing view rights. Unexpected/missing grants fail the rehearsal.

## Legacy password compatibility — 2026-09-30

- Compared the legacy login path (`password_verify` against PHP `password_hash(..., PASSWORD_DEFAULT)`) with Laravel login and its hashed model attribute.
- A read-only summary of the configured local source database found 11 password hashes: all are bcrypt cost 10. Only algorithm/cost counts were reported; no hash values were printed.
- Added a regression test using a PHP-generated `PASSWORD_DEFAULT` hash. It initially failed because Laravel's configured bcrypt cost is higher than the legacy hash cost; the strict hashed cast rejected the imported hash before login.
- User password assignment preserves only the observed bcrypt/cost 10 hash format during migration/import while still hashing all other input. After a correct login, Laravel upgrades an outdated hash to the configured current cost. Failed login attempts do not rewrite the hash.
- `php artisan test --filter=legacy_php_password_default` — passed (7 assertions); full `php artisan test` — 137 passed, 1,762 assertions; Pint check passed for the changed PHP files.
- This proves PHP `PASSWORD_DEFAULT` hashes generated by the tested PHP runtime can be used and upgraded by the Laravel app. It does not replace staging login verification against the restored production user records.

## Data-upgrade revalidation after the authentication change — 2026-09-30

- `php tools/verify_legacy_upgrade.php` — passed: received purchase quantities and receipt/transfer backfills preserved, 8 existing foreign keys preserved, 7 optional links set to `ON DELETE SET NULL`, purchase-order deletion restricted, orphan preflight blocks partial application, immutable audit/correction rows remain protected, and a second run created no duplicates.
- `php tools/rehearse_local_upgrade.php` — passed on a guarded temporary clone: 26 tables / 578 rows, all 23 unchanged business-table hashes preserved, 90 permission definitions and 261 grants preserved, only the expected 15 export permissions and 72 grants added, all 4 triggers preserved, and 2 optional foreign keys restored. The temporary DB was removed; no browser QA users were created.
- `php tools/audit_legacy_schema.php` immediately afterward still reported the source database read-only and unchanged: 24 tables, 214 columns, 50 foreign keys, no missing tables/columns, no type mismatches, and no orphan rows. The two documented optional foreign keys remain pending on the source by design.
- Re-ran the read-only SSH probe to `104.248.33.127` in batch mode; the host responds but rejects the available key (`Permission denied (publickey,password)`). No remote command or file change was performed. Staging remains gated on an authorized SSH credential.
- Re-ran Vue verification after the backend change: `npm run test:js` — 12 passed; `npm run build` — passed with 111 modules transformed.

## Legacy page/module parity check — 2026-09-30

- Added `LegacyModuleParityTest`, which reads the legacy PHP module catalog and asserts every module has its Vue route and matching Laravel data/API route.
- The same test verifies each frontend route retains the legacy module's view permission, including the dedicated dashboard, supplier, and dispatch-document routes.
- The initial run surfaced the dedicated API routes used by dispatch documents, notifications, and reports; the mapping now checks those routes explicitly rather than assuming all modules use the generic page-data endpoint.
- `php artisan test --filter=LegacyModuleParityTest` — passed (75 assertions); full `php artisan test --compact` — 138 passed, 1,837 assertions; Pint passed.

## Public domain endpoint probe — 2026-09-30

- Read-only DNS lookup for `lager-diagen.govista.am` returned the user-provided server IP `104.248.33.127`.
- Read-only HTTPS request to `/up` failed TLS validation with `RemoteCertificateNameMismatch`; the presented certificate does not match the requested hostname.
- Read-only HTTP request to `/up` returned `404`.
- The domain is therefore not currently proven to serve the Laravel release or a valid hostname-matched HTTPS endpoint. SSH authentication also remains unavailable, so no server configuration was changed.

