<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

$connection = $_ENV['DB_CONNECTION'] ?? 'mysql';
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? '3306';
$username = $_ENV['DB_USERNAME'] ?? '';
$password = $_ENV['DB_PASSWORD'] ?? '';
$sourceDatabase = $_ENV['DB_DATABASE'] ?? '';

if ($connection !== 'mysql' || ! in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('This rehearsal is restricted to a local MySQL/MariaDB server.');
}

$targetDatabase = 'diagen_lager_qa_'.bin2hex(random_bytes(6));
$admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$quote = static fn (string $identifier): string => '`'.str_replace('`', '``', $identifier).'`';
$created = false;
$laravelBootstrapped = false;

try {
    $admin->exec('CREATE DATABASE '.$quote($targetDatabase).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $fixture = new PDO("mysql:host={$host};port={$port};dbname={$targetDatabase};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    foreach ([
        'CREATE TABLE users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY)',
        'CREATE TABLE suppliers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY)',
        'CREATE TABLE categories (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL)',
        'CREATE TABLE products (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id BIGINT UNSIGNED NULL)',
        'CREATE TABLE purchase_orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY)',
        'CREATE TABLE purchase_order_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, purchase_order_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL, ordered_qty DECIMAL(12,3) NOT NULL, unit_cost DECIMAL(14,2) NOT NULL)',
        'CREATE TABLE receipts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, receipt_no VARCHAR(40) NOT NULL, purchase_order_id BIGINT UNSIGNED NULL)',
        'CREATE TABLE stock_lots (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id BIGINT UNSIGNED NOT NULL, qty DECIMAL(12,3) NOT NULL)',
        'CREATE TABLE receipt_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, receipt_id BIGINT UNSIGNED NOT NULL, purchase_order_item_id BIGINT UNSIGNED NULL, product_id BIGINT UNSIGNED NOT NULL, lot_id BIGINT UNSIGNED NULL, qty DECIMAL(12,3) NOT NULL, unit_cost DECIMAL(14,2) NOT NULL)',
        'CREATE TABLE movements (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(100) NULL, type VARCHAR(40) NOT NULL, product_id BIGINT UNSIGNED NOT NULL, lot_id BIGINT UNSIGNED NULL, qty DECIMAL(12,3) NOT NULL, unit_cost DECIMAL(14,2) NOT NULL)',
        'CREATE TABLE transfers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id BIGINT UNSIGNED NOT NULL, qty DECIMAL(12,3) NOT NULL)',
        'CREATE TABLE transfer_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, transfer_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL, qty DECIMAL(12,3) NOT NULL)',
        'CREATE TABLE stock_requests (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY)',
        'CREATE TABLE inventory_lines (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY)',
        'CREATE TABLE roles (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, title VARCHAR(120) NOT NULL)',
        'CREATE TABLE permissions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(100) NOT NULL UNIQUE, title VARCHAR(140) NOT NULL, module VARCHAR(80) NOT NULL)',
        'CREATE TABLE role_permissions (role_id BIGINT UNSIGNED NOT NULL, permission_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (role_id, permission_id))',
        'CREATE TABLE returns (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, return_no VARCHAR(40) NOT NULL, UNIQUE KEY legacy_return_no_unique (return_no))',
        'CREATE TABLE audit_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, details VARCHAR(100) NULL)',
        'CREATE TABLE movement_corrections (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, details VARCHAR(100) NULL)',
    ] as $statement) {
        $fixture->exec($statement);
    }

    $fixture->exec('INSERT INTO purchase_order_items (id, purchase_order_id, product_id, ordered_qty, unit_cost) VALUES (1, 10, 20, 10, 5), (2, 11, 21, 8, 7)');
    $fixture->exec('INSERT INTO purchase_orders (id) VALUES (10), (11)');
    $fixture->exec("INSERT INTO receipts (id, receipt_no, purchase_order_id) VALUES (1, 'REC-OLD-1', 10), (2, 'REC-OLD-2', 11), (3, 'REC-OLD-3', NULL)");
    $fixture->exec('INSERT INTO stock_lots (id, product_id, qty) VALUES (1, 20, 3), (2, 21, 2)');
    $fixture->exec("INSERT INTO movements (id, reference, type, product_id, lot_id, qty, unit_cost) VALUES (1, 'REC-OLD-1', 'receipt', 20, 1, 3, 5), (2, 'REC-OLD-2', 'receipt', 21, 2, 2, 7), (3, 'REC-OLD-3', 'receipt', 22, NULL, 6, 9), (4, 'REC-OLD-3', 'receipt', 23, NULL, 8, 12)");
    $fixture->exec('INSERT INTO transfers (id, product_id, qty) VALUES (31, 30, 4)');
    $fixture->exec('INSERT INTO users (id) VALUES (1)');
    $fixture->exec('INSERT INTO suppliers (id) VALUES (1)');
    $fixture->exec("INSERT INTO categories (id, name) VALUES (1, 'Parent'), (2, 'Child')");
    $fixture->exec('INSERT INTO products (id, supplier_id) VALUES (50, 1)');
    $fixture->exec('INSERT INTO stock_requests (id) VALUES (71)');
    $fixture->exec('INSERT INTO inventory_lines (id) VALUES (81)');
    $fixture->exec("INSERT INTO roles (id, name, title) VALUES (1, 'reader', 'Reader'), (2, 'limited', 'Limited')");
    $fixture->exec("INSERT INTO permissions (id, code, title, module) VALUES (1, 'stock.view', 'Stock — View', 'stock'), (2, 'reports.view', 'Reports — View', 'reports'), (3, 'dashboard.view', 'Dashboard — View', 'dashboard'), (4, 'suppliers.view', 'Suppliers — View', 'suppliers'), (5, 'notifications.view', 'Notifications — View', 'notifications')");
    $fixture->exec('INSERT INTO role_permissions (role_id, permission_id) VALUES (1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (2, 1), (2, 3), (2, 4), (2, 5)');
    $fixture->exec("INSERT INTO returns (id, return_no) VALUES (41, 'RET-LEGACY-41')");

    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $laravelBootstrapped = true;
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => $targetDatabase]);
    DB::purge('mysql');

    $legacyMigration = require $root.'/database/migrations/2026_09_30_000004_upgrade_existing_lager_schema.php';
    $exportMigration = require $root.'/database/migrations/2026_09_30_000005_add_export_permissions.php';
    $optionalForeignKeysMigration = require $root.'/database/migrations/2026_09_30_000006_restore_legacy_optional_foreign_keys.php';
    $legacyMigration->up();
    $exportMigration->up();
    $legacyMigration->up();
    $exportMigration->up();
    // Simulate an installation where migration 000004 was already recorded
    // before its optional relationship fixes were added to the source file.
    $fixture->exec('ALTER TABLE categories DROP FOREIGN KEY fk_categories_parent');
    $fixture->exec('ALTER TABLE receipts DROP FOREIGN KEY fk_receipts_purchase_order');
    $fixture->exec('UPDATE categories SET parent_id = 999 WHERE id = 2');
    $blockedCategoryOrphan = false;
    try {
        $optionalForeignKeysMigration->up();
    } catch (RuntimeException $exception) {
        $blockedCategoryOrphan = str_contains($exception->getMessage(), 'categories.parent_id');
    }
    $fixture->exec('UPDATE categories SET parent_id = NULL WHERE id = 2');
    $fixture->exec('UPDATE receipts SET purchase_order_id = 999 WHERE id = 1');
    $blockedReceiptOrphan = false;
    try {
        $optionalForeignKeysMigration->up();
    } catch (RuntimeException $exception) {
        $blockedReceiptOrphan = str_contains($exception->getMessage(), 'receipts.purchase_order_id');
    }
    $fixture->exec('UPDATE receipts SET purchase_order_id = NULL WHERE id = 1');
    $categoryConstraintAfterPreflightFailure = (int) $fixture->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'parent_id' AND REFERENCED_TABLE_NAME = 'categories'")->fetchColumn();
    $receiptConstraintAfterPreflightFailure = (int) $fixture->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receipts' AND COLUMN_NAME = 'purchase_order_id' AND REFERENCED_TABLE_NAME = 'purchase_orders'")->fetchColumn();
    if (! $blockedCategoryOrphan || ! $blockedReceiptOrphan || $categoryConstraintAfterPreflightFailure !== 0 || $receiptConstraintAfterPreflightFailure !== 0) {
        throw new RuntimeException('The optional foreign key preflight did not reject orphan rows before changing the schema.');
    }
    $optionalForeignKeysMigration->up();
    $optionalForeignKeysMigration->up();
    $auditMigration = require $root.'/database/migrations/2026_09_30_000003_ensure_immutable_audit_triggers.php';
    $auditMigration->up();
    $auditMigration->up();

    $fixture->exec('UPDATE categories SET parent_id = 1 WHERE id = 2');
    $fixture->exec('UPDATE receipts SET purchase_order_id = 10 WHERE id = 1');
    $fixture->exec('UPDATE stock_requests SET sent_by = 1, received_by = 1 WHERE id = 71');
    $fixture->exec('UPDATE transfers SET shipped_by = 1, received_by = 1 WHERE id = 31');
    $fixture->exec('UPDATE inventory_lines SET counted_supplier_id = 1 WHERE id = 81');

    $received = $fixture->query('SELECT id, received_qty FROM purchase_order_items ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $receiptItems = (int) $fixture->query('SELECT COUNT(*) FROM receipt_items')->fetchColumn();
    $nullLotReceiptItems = (int) $fixture->query('SELECT COUNT(*) FROM receipt_items WHERE receipt_id = 3 AND lot_id IS NULL')->fetchColumn();
    $nullLotProducts = $fixture->query('SELECT product_id FROM receipt_items WHERE receipt_id = 3 AND lot_id IS NULL ORDER BY product_id')->fetchAll(PDO::FETCH_COLUMN);
    $transferItems = $fixture->query('SELECT transfer_id, product_id, qty FROM transfer_items ORDER BY transfer_id')->fetchAll(PDO::FETCH_ASSOC);
    $exportGrants = $fixture->query("SELECT r.name, p.code FROM role_permissions AS rp JOIN roles AS r ON r.id = rp.role_id JOIN permissions AS p ON p.id = rp.permission_id WHERE p.code LIKE '%.export' ORDER BY r.name, p.code")->fetchAll(PDO::FETCH_ASSOC);
    $expectedExportGrants = [
        ['name' => 'limited', 'code' => 'stock.export'],
        ['name' => 'reader', 'code' => 'reports.export'],
        ['name' => 'reader', 'code' => 'stock.export'],
    ];
    $returnNumberIndexes = array_map('intval', $fixture->query("SELECT DISTINCT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'returns' AND COLUMN_NAME = 'return_no'")->fetchAll(PDO::FETCH_COLUMN));
    $legacyReturnCount = (int) $fixture->query("SELECT COUNT(*) FROM returns WHERE return_no = 'RET-LEGACY-41'")->fetchColumn();
    $foreignKeys = (int) $fixture->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'categories' AND COLUMN_NAME = 'parent_id' AND REFERENCED_TABLE_NAME = 'categories') OR (TABLE_NAME = 'products' AND COLUMN_NAME = 'supplier_id' AND REFERENCED_TABLE_NAME = 'suppliers') OR (TABLE_NAME = 'receipts' AND COLUMN_NAME = 'purchase_order_id' AND REFERENCED_TABLE_NAME = 'purchase_orders') OR (TABLE_NAME = 'stock_requests' AND COLUMN_NAME IN ('sent_by', 'received_by') AND REFERENCED_TABLE_NAME = 'users') OR (TABLE_NAME = 'transfers' AND COLUMN_NAME IN ('shipped_by', 'received_by') AND REFERENCED_TABLE_NAME = 'users') OR (TABLE_NAME = 'inventory_lines' AND COLUMN_NAME = 'counted_supplier_id' AND REFERENCED_TABLE_NAME = 'suppliers'))")->fetchColumn();
    if ((float) $received[1] !== 3.0 || (float) $received[2] !== 2.0 || $receiptItems !== 4 || $nullLotReceiptItems !== 2
        || array_map('intval', $nullLotProducts) !== [22, 23]
        || count($transferItems) !== 1 || (int) $transferItems[0]['transfer_id'] !== 31
        || (int) $transferItems[0]['product_id'] !== 30 || (float) $transferItems[0]['qty'] !== 4.0
        || $exportGrants !== $expectedExportGrants || $returnNumberIndexes !== [1] || $legacyReturnCount !== 1 || $foreignKeys !== 8) {
        throw new RuntimeException('Legacy data or its indexes/role permissions changed unexpectedly during the upgrade rehearsal.');
    }

    $fixture->exec("INSERT INTO audit_logs (id, details) VALUES (51, 'audit sentinel')");
    $fixture->exec("INSERT INTO movement_corrections (id, details) VALUES (61, 'correction sentinel')");
    $blockedAuditUpdate = $blockedAuditDelete = $blockedCorrectionUpdate = $blockedCorrectionDelete = false;
    foreach ([
        ['UPDATE audit_logs SET details = \'changed\' WHERE id = 51', 'blockedAuditUpdate'],
        ['DELETE FROM audit_logs WHERE id = 51', 'blockedAuditDelete'],
        ['UPDATE movement_corrections SET details = \'changed\' WHERE id = 61', 'blockedCorrectionUpdate'],
        ['DELETE FROM movement_corrections WHERE id = 61', 'blockedCorrectionDelete'],
    ] as [$statement, $resultKey]) {
        try {
            $fixture->exec($statement);
        } catch (PDOException) {
            $$resultKey = true;
        }
    }
    if (! $blockedAuditUpdate || ! $blockedAuditDelete || ! $blockedCorrectionUpdate || ! $blockedCorrectionDelete
        || (int) $fixture->query('SELECT COUNT(*) FROM audit_logs WHERE id = 51')->fetchColumn() !== 1
        || (int) $fixture->query('SELECT COUNT(*) FROM movement_corrections WHERE id = 61')->fetchColumn() !== 1) {
        throw new RuntimeException('The rehearsal did not prove audit and movement-correction immutability.');
    }

    $fixture->exec('DELETE FROM categories WHERE id = 1');
    $purchaseOrderDeleteRestricted = false;
    try {
        $fixture->exec('DELETE FROM purchase_orders WHERE id = 10');
    } catch (PDOException) {
        $purchaseOrderDeleteRestricted = true;
    }
    $fixture->exec('DELETE FROM suppliers WHERE id = 1');
    $fixture->exec('DELETE FROM users WHERE id = 1');
    $nullifiedLinks = [
        $fixture->query('SELECT parent_id FROM categories WHERE id = 2')->fetchColumn(),
        $fixture->query('SELECT supplier_id FROM products WHERE id = 50')->fetchColumn(),
        $fixture->query('SELECT sent_by FROM stock_requests WHERE id = 71')->fetchColumn(),
        $fixture->query('SELECT received_by FROM stock_requests WHERE id = 71')->fetchColumn(),
        $fixture->query('SELECT shipped_by FROM transfers WHERE id = 31')->fetchColumn(),
        $fixture->query('SELECT received_by FROM transfers WHERE id = 31')->fetchColumn(),
        $fixture->query('SELECT counted_supplier_id FROM inventory_lines WHERE id = 81')->fetchColumn(),
    ];
    if (! $purchaseOrderDeleteRestricted
        || (int) $fixture->query('SELECT purchase_order_id FROM receipts WHERE id = 1')->fetchColumn() !== 10
        || array_filter($nullifiedLinks, static fn ($value): bool => $value !== false && $value !== null) !== []) {
        throw new RuntimeException('An added legacy relationship did not preserve its SET NULL delete behavior.');
    }

    echo json_encode([
        'result' => 'passed',
        'legacy_purchase_order_received_qty' => $received,
        'backfilled_receipt_items' => $receiptItems,
        'backfilled_null_lot_receipt_items' => $nullLotReceiptItems,
        'backfilled_null_lot_product_ids' => array_map('intval', $nullLotProducts),
        'backfilled_transfer_items' => count($transferItems),
        'preserved_role_export_grants' => $exportGrants,
        'legacy_foreign_keys_preserved' => $foreignKeys,
        'legacy_optional_links_null_on_delete' => count($nullifiedLinks),
        'purchase_order_delete_restricted' => $purchaseOrderDeleteRestricted,
        'orphan_fk_preflight_blocks_partial_migration' => true,
        'legacy_return_unique_index_normalized' => $returnNumberIndexes === [1],
        'audit_and_correction_update_delete_blocked' => true,
        'second_run_created_no_duplicates' => true,
        'source_database' => $sourceDatabase,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    if ($laravelBootstrapped) {
        DB::disconnect('mysql');
    }
    if ($created) {
        $admin->exec('DROP DATABASE '.$quote($targetDatabase));
    }
}
