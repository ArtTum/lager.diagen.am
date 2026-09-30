# Production cutover preparation

The legacy PHP app remains the active application. This runbook prepares the Laravel/Vue release for `lager-diagen.govista.am`; it does not change DNS, the live document root, or the production database.

## Release layout

Keep the current PHP release intact for rollback. Deploy the Laravel project as a separate release directory under `/var/domains/` and set the web server document root to that release's `migration/public` directory. Do not expose the Laravel project root, `.env`, `vendor`, database backups, or `storage` as the web root. The included `public/.htaccess` routes Vue history paths and API requests through Laravel.

## Environment

Use `.env.production.example` as a key list, not as a production `.env` file. Set the actual database credentials in the server-side `.env`; generate a unique application key on the server with `php artisan key:generate --force`. Keep `APP_DEBUG=false`, `APP_ENV=production`, HTTPS `APP_URL`, `SESSION_SECURE_COOKIE=true`, and `APP_TIMEZONE=Asia/Yerevan`. Do not copy local `.env`, tokens, or passwords to the release.

Use PHP 8.2 or newer with the Composer-required extensions enabled, including `pdo_mysql` for the configured MySQL/MariaDB connection. Keep `CORS_ALLOWED_ORIGINS` restricted to the production app origin; the production example sets it to `https://lager-diagen.govista.am`. The Vue app and `/api` should share that origin, so no separate frontend host is required.

The Laravel app uses bearer-token API authentication. Keep the SPA and `/api` on the same production origin. Build Vue assets before enabling the release:

```sh
npm ci
npm run build
composer install --no-dev --optimize-autoloader --no-interaction
```

Ensure `storage` and `bootstrap/cache` are writable by the PHP-FPM/Apache service account. Do not run demo-data seeders against the existing installation.

## Data-preserving cutover gates

1. Take a full backup of the current production database and verify it by restoring to a separate empty QA database. The repository's root-level `tools/db-backup.php` reads the root `.env`, creates an encrypted `.dgbk` file under `storage/backups/`, and requires `DIAGEN_BACKUP_PASSPHRASE` (at least 16 characters) from the local process environment. Set that variable through the host's secret manager; do not put its value in shell history, a command argument, `.env`, or chat. The backup command refuses databases with views, routines, or events, so export those separately before treating the backup as complete. Copy the encrypted file to approved off-host storage and keep its passphrase separately.

   To validate the file, provision a separate empty QA database named `diagen_restore_<unique_suffix>` on the same MySQL/MariaDB server and run the root-level `tools/db-restore.php <backup-file> <qa-database>` with the same passphrase in the process environment. The restore tool rejects non-empty targets and refuses the configured source database. It verifies table row counts and recreates triggers; independently compare table names/counts and inspect critical roles, grants, inventory balances, receipts, transfers, and audit rows against the source. Keep this QA database isolated and remove it after the review.
2. Deploy the release without changing the live document root. Review `php artisan migrate:status` and the migration SQL against the server's exact MySQL/MariaDB version. Run the read-only `php tools/audit_legacy_schema.php`: `ready_for_migrations` is acceptable only when the listed differences are the two optional foreign keys added by migration `000006` and the orphan count is zero; stop for any other mismatch.
3. Run `php tools/verify_legacy_upgrade.php` only against a local MySQL/MariaDB instance; it refuses remote hosts and creates/drops its own uniquely named QA database.
   For the configured local `diagen_lager` dataset, `php tools/rehearse_local_upgrade.php` also copies the complete current schema, rows, and trigger bodies into an isolated QA database, applies pending migrations there, verifies business-table hashes and triggers, then removes the temporary database. It refuses non-local MySQL hosts and any database name other than `diagen_lager`; it does not migrate the source database. The optional `--keep` mode also creates synthetic `example.test` users for six-role browser QA and retains the real-data clone temporarily; after QA, remove that exact database with `php tools/drop_local_port_qa_database.php <database-name>`.
4. On staging, point Laravel at a restored database copy, run migrations, then sign in with existing imported accounts for admin, central storekeeper, finance, and branch roles. Confirm legacy `password_hash(PASSWORD_DEFAULT)` credentials work (the app upgrades the hash after a successful login), then exercise their workflows. Verify counts, role grants, receipt and transfer history, stock, audit records, and exports against the source backup.
5. Only after the restored-copy checks pass, apply production migrations with `php artisan migrate --force`. Do not run `LagerAccessSeeder` on existing business data; it is intended for a new installation and may initialize empty standard roles.
6. Configure the virtual host with a TLS certificate valid for `lager-diagen.govista.am` and point its document root to the Laravel release's `public` directory. Warm Laravel caches, then verify TLS hostname validation and `https://lager-diagen.govista.am/up`, login, page access by role, and a read-only stock/report sample. Do not bypass certificate validation during acceptance.
7. Keep the previous release and pre-cutover backup until acceptance. Rollback means switching the document root back to the old release; do not restore a database backup over live writes without an explicit recovery decision.

## Local data-preservation rehearsal — 2026-09-30

The pending migrations were applied to an isolated restore of the configured local Lager database, never to the source database. The encrypted backup restored 26 tables, 578 rows, and 4 triggers. An independent comparison before and after the migrations found:

- All 23 business tables outside `permissions`, `role_permissions`, and Laravel's `migrations` table retained identical row counts and content hashes.
- Existing permission records and role grants were preserved. The only changes were export permission codes backed by real export routes and grants derived from roles' existing `*.view` access; `dashboard`, `suppliers`, and `notifications` do not receive synthetic export permissions.
- The two intended foreign keys were restored with `SET NULL` and `RESTRICT` behavior; no orphan rows were found.
- All 4 trigger definitions remained unchanged, and the read-only schema auditor returned `passed` on the migrated copy.

The temporary restore database and encrypted backup file were removed after verification. The source database still reports `ready_for_migrations`, and migrations `000005` and `000006` remain pending there. This rehearsal verifies the local backup, restore, and migration path; it does not replace off-host backup retention, production credentials, staging browser acceptance, or the production cutover checklist above.

After the permission catalog was tightened to expose `*.export` only for modules with real export routes, the complete rehearsal was repeated with the current migration files. A fresh random passphrase existed only in the local process environment. The encrypted backup restored 26 tables, 578 rows, and 4 triggers into a newly created isolated QA database; migrations `000005` and `000006` then completed there. The read-only schema auditor returned `passed` for all 24 source tables, 214 columns, and 50 foreign keys with no type mismatches or orphan rows. Independent content hashes matched for all 23 business tables, and all 4 trigger definitions matched. The QA database, encrypted backup, and temporary passphrase were removed after verification.

The configured local source database remains unchanged and still has migrations `2026_09_30_000005_add_export_permissions` and `2026_09_30_000006_restore_legacy_optional_foreign_keys` pending. This rehearsal proves the latest local backup/restore/migration path; it does not prove production TLS, proxy, DNS, concurrent-user load, physical scanner/printer operation, off-host backup retention, or production restore readiness. Production cutover remains gated on a production backup restored and compared on staging, external backup retention, and role-based browser acceptance.

The separate live-source rehearsal command, `php tools/rehearse_local_upgrade.php`, was also run against the configured source dataset. It copied all 26 current tables/578 rows, applied migrations 000005 and 000006 to the copy, verified hashes on 23 unchanged business tables and all 4 existing triggers, restored both optional foreign keys, and removed the temporary copy. No production or configured source migration was applied.

The rehearsal also compares `permissions` and `role_permissions` by stable code/role names instead of skipping their data checks. On the current source copy, all 90 existing permission definitions and 261 existing role grants were preserved; the only changes were the expected 15 export permissions and 72 export grants derived from existing view access. Any missing or unexpected permission definition/grant fails the rehearsal.

A follow-up `--keep` rehearsal created six synthetic role accounts in a second isolated clone and started Laravel on `127.0.0.1:8096` for browser acceptance. The in-app browser rejected opening that local address with `ERR_BLOCKED_BY_CLIENT` before rendering the Laravel page. No browser login was performed and no alternate browser surface was used to work around the block. The PHP server was stopped, the exact temporary clone was removed with `drop_local_port_qa_database.php`, and the source DB remained unchanged. This browser acceptance gate remains open.
