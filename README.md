# Lager Laravel + Vue

The Laravel API and Vue single-page application are now the active project in this repository. The production server still needs a deliberate cutover before it serves this release.

## Keep the existing data

Configure this Laravel app to use the same MySQL/MariaDB database as the existing Lager installation. The first migration imports the reviewed legacy schema with `CREATE TABLE IF NOT EXISTS`; the follow-up migration adds missing legacy columns and constraints and backfills receipt and transfer line records. It does not copy records from a separate database and its rollback intentionally does not drop operational tables.

The legacy application uses `Asia/Yerevan`; Laravel keeps the same timezone through `APP_TIMEZONE` so date boundaries and audit timestamps remain consistent. See [DEPLOYMENT.md](DEPLOYMENT.md) for the reversible production cutover gates and [.env.production.example](.env.production.example) for production-only configuration keys.

Before running migrations against an existing installation, take and verify a database backup. From the repository root, run:

```powershell
php artisan migrate --force
```

The schema and backfill migrations are MySQL/MariaDB-specific. Do not point them at production until the backup has been verified and the generated SQL has been reviewed for that database version.

## Roles and permissions

The access model uses normalized `roles`, `permissions`, and `role_permissions` records. Permission codes are action-specific (`stock.view`, `requests.approve`, `reports.export`) and are checked by the API middleware and domain permission service. Branch scope is enforced server-side, including for manually crafted requests. The Vue app uses the same permission map only to show or hide controls; it is not treated as an authorization boundary.

Exports have a separate `*.export` grant, following the reference ERP's `can_export` capability. The export-permission upgrade grants that capability only to roles that already had the corresponding `*.view` grant, preserving their existing behavior while making future role changes explicit. The migration does not rewrite other configured role grants.

## Application architecture

The Laravel code follows the reference ERP's separation of input validation, authorization, and business work, adapted to Lager's inventory rules:

- `app/Http/Requests/Api` owns input validation. Keep request payload rules out of controller methods.
- `app/Http/Controllers/Api` translates HTTP input to service calls and formats responses; it should not contain workflow logic or data queries.
- `app/Services` owns workflow decisions, branch scope, state transitions, and transactions.
- `app/Repositories` owns Eloquent reads and writes through the corresponding models and relationships. Do not add `DB::table()` application queries.
- `app/Models` defines table mappings, relationships, and casts.
- `app/Support/PermissionCatalog` is the single source for permission modules, actions, central-only capabilities, and branch-allowed capabilities. `LagerAccessSeeder` builds permission rows from that catalog.
- `PermissionService` plus `RequirePermission` enforce the server-side `module.action` capability map. Vue uses that map for presentation only. Keep branch restrictions in server-side permission and workflow checks.
- `tests/Feature/ApiPermissionCoverageTest.php` checks every API route for authentication, active-account enforcement, and an explicit permission, while separately checking the throttled login and authenticated session endpoints.

The Swift ERP uses permission groups for rental-specific records. Lager keeps its `module.action` codes because supplier, LOT, stock request, inventory, transfer, and expiry operations need separate capabilities. Reuse the centralized permission-service pattern; do not copy rental permission keys into this application.

## New empty installation

After creating an empty database and configuring `.env`, run migrations and seed the standard permission catalog and default role matrix:

```powershell
php artisan migrate --force
php artisan db:seed --class="Database\Seeders\LagerAccessSeeder"
php artisan lager:admin:create
```

The initial admin command prompts for the administrator name, email and a confirmed password of at least 14 characters. It refuses to run once any user exists, and it never prints or stores a default password. The role seeder creates the standard permission records but leaves any role with existing permission grants unchanged.

Do not load sample data into a production database. Use a separate development database for any demo records.

## Verification

From the repository root:

```powershell
php tools/audit_legacy_schema.php
php tools/verify_legacy_upgrade.php
php artisan test
npm run test:js
npm run build
```

`audit_legacy_schema.php` is a read-only MySQL/MariaDB preflight. It compares source tables, columns, column types, foreign-key delete rules, and orphan counts with the configured database. `ready_for_migrations` means the only missing foreign keys are the two added by migration `000006`, with no orphan rows; `passed` means the database schema is fully aligned. Any other mismatch returns `failed` and must be resolved before migration.

`verify_legacy_upgrade.php` is an opt-in local MySQL/MariaDB rehearsal. It creates a uniquely named synthetic QA database, runs the legacy receipt/transfer backfill, return index normalization, export-permission upgrade, optional foreign-key upgrade, and immutable audit triggers, then checks historical quantities, multiple products without LOT numbers, foreign-key delete behavior, orphan preflight, existing role grants, idempotence, and blocked audit/correction updates and deletes. It drops only the database it created, refuses non-local database hosts, and does not change the configured source database.

The production cutover still requires environment-specific verification: backup/restore, HTTPS and reverse proxy configuration, queue/WebSocket processes where enabled, and role-based browser acceptance against a staging database.
