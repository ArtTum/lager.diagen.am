<?php

declare(strict_types=1);

use App\Support\PermissionCatalog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
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
$keepForBrowserQa = in_array('--keep', $argv, true);
$unexpectedArguments = array_values(array_diff(array_slice($argv, 1), ['--keep']));
if ($unexpectedArguments !== []) {
    throw new InvalidArgumentException('Supported option: --keep (retain the upgraded clone briefly for local browser QA).');
}

if ($connection !== 'mysql' || ! in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('This rehearsal is restricted to a local MySQL/MariaDB server.');
}
if ($sourceDatabase !== 'diagen_lager') {
    throw new RuntimeException('This rehearsal only accepts the configured diagen_lager source database.');
}

$quote = static fn (string $identifier): string => '`'.str_replace('`', '``', $identifier).'`';
$targetDatabase = 'diagen_lager_portqa_'.bin2hex(random_bytes(6));
$admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$source = new PDO("mysql:host={$host};port={$port};dbname={$sourceDatabase};charset=utf8mb4", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$created = false;
$laravelBootstrapped = false;
$resultSummary = null;

$primaryKeyColumns = static function (PDO $pdo, string $database, string $table): array {
    $statement = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? ORDER BY ORDINAL_POSITION');
    $statement->execute([$database, $table, 'PRIMARY']);

    return $statement->fetchAll(PDO::FETCH_COLUMN);
};

$tableHash = static function (PDO $pdo, string $database, string $table) use ($quote, $primaryKeyColumns): string {
    $primaryKey = $primaryKeyColumns($pdo, $database, $table);
    if ($primaryKey === []) {
        throw new RuntimeException("Cannot produce a stable row hash for {$table}: no primary key.");
    }

    $orderBy = implode(', ', array_map($quote, $primaryKey));
    $rows = $pdo->query('SELECT * FROM '.$quote($database).'.'.$quote($table).' ORDER BY '.$orderBy);
    $hash = hash_init('sha256');
    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        $serialized = serialize($row);
        hash_update($hash, pack('N', strlen($serialized)).$serialized);
    }

    return hash_final($hash);
};

$permissionRows = static function (PDO $pdo, string $database) use ($quote): array {
    return $pdo->query('SELECT code, title, module FROM '.$quote($database).'.'.$quote('permissions').' ORDER BY code')->fetchAll(PDO::FETCH_ASSOC);
};

$roleGrantRows = static function (PDO $pdo, string $database) use ($quote): array {
    return $pdo->query('SELECT r.name AS role_name, p.code AS permission_code FROM '.$quote($database).'.'.$quote('role_permissions').' AS rp'
        .' JOIN '.$quote($database).'.'.$quote('roles').' AS r ON r.id = rp.role_id'
        .' JOIN '.$quote($database).'.'.$quote('permissions').' AS p ON p.id = rp.permission_id'
        .' ORDER BY r.name, p.code')->fetchAll(PDO::FETCH_ASSOC);
};

$triggerSignatures = static function (PDO $pdo, string $database) use ($quote): array {
    $triggers = $pdo->query('SHOW TRIGGERS FROM '.$quote($database))->fetchAll(PDO::FETCH_ASSOC);
    $signatures = [];
    foreach ($triggers as $trigger) {
        $signatures[$trigger['Trigger']] = implode('|', [
            $trigger['Table'], $trigger['Timing'], $trigger['Event'], trim($trigger['Statement']),
        ]);
    }
    ksort($signatures);

    return $signatures;
};

try {
    $tables = $source->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '.$source->quote($sourceDatabase).' AND TABLE_TYPE = "BASE TABLE" ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
    if ($tables === [] || ! in_array('migrations', $tables, true)) {
        throw new RuntimeException('The source database does not contain the expected Laravel migration ledger.');
    }

    $applied = $source->query('SELECT migration FROM '.$quote($sourceDatabase).'.migrations')->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        '2026_09_30_000001_import_lager_legacy_schema',
        '2026_09_30_000002_create_personal_access_tokens_table',
        '2026_09_30_000003_ensure_immutable_audit_triggers',
        '2026_09_30_000004_upgrade_existing_lager_schema',
    ] as $requiredMigration) {
        if (! in_array($requiredMigration, $applied, true)) {
            throw new RuntimeException("Expected source migration is not recorded: {$requiredMigration}.");
        }
    }
    foreach ([
        '2026_09_30_000005_add_export_permissions',
        '2026_09_30_000006_restore_legacy_optional_foreign_keys',
    ] as $pendingMigration) {
        if (in_array($pendingMigration, $applied, true)) {
            throw new RuntimeException("Expected pending migration is already recorded: {$pendingMigration}.");
        }
    }

    $sourceHashes = [];
    $sourceRows = 0;
    foreach ($tables as $table) {
        $sourceHashes[$table] = $tableHash($source, $sourceDatabase, $table);
        $sourceRows += (int) $source->query('SELECT COUNT(*) FROM '.$quote($sourceDatabase).'.'.$quote($table))->fetchColumn();
    }
    $sourcePermissions = $permissionRows($source, $sourceDatabase);
    $sourceRoleGrants = $roleGrantRows($source, $sourceDatabase);
    $sourceTriggers = $triggerSignatures($source, $sourceDatabase);

    $admin->exec('CREATE DATABASE '.$quote($targetDatabase).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $admin->exec('USE '.$quote($targetDatabase));
    $admin->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $table) {
        $createTable = $source->query('SHOW CREATE TABLE '.$quote($sourceDatabase).'.'.$quote($table))->fetch(PDO::FETCH_NUM)[1] ?? null;
        if (! is_string($createTable)) {
            throw new RuntimeException("Could not read the source table definition for {$table}.");
        }
        $admin->exec($createTable);
        $admin->exec('INSERT INTO '.$quote($targetDatabase).'.'.$quote($table).' SELECT * FROM '.$quote($sourceDatabase).'.'.$quote($table));
    }
    $admin->exec('SET FOREIGN_KEY_CHECKS = 1');

    $target = new PDO("mysql:host={$host};port={$port};dbname={$targetDatabase};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Recreate each source trigger's exact event and body without preserving a server-specific DEFINER.
    foreach ($source->query('SHOW TRIGGERS FROM '.$quote($sourceDatabase))->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
        $target->exec('CREATE TRIGGER '.$quote($trigger['Trigger'])
            .' '.$trigger['Timing'].' '.$trigger['Event'].' ON '.$quote($trigger['Table'])
            .' FOR EACH ROW '.$trigger['Statement']);
    }

    foreach ($tables as $table) {
        if (! hash_equals($sourceHashes[$table], $tableHash($target, $targetDatabase, $table))) {
            throw new RuntimeException("The source copy verification failed for table {$table}.");
        }
    }

    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $laravelBootstrapped = true;
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => $targetDatabase]);
    DB::purge('mysql');

    $exitCode = Artisan::call('migrate', ['--force' => true, '--database' => 'mysql']);
    if ($exitCode !== 0) {
        throw new RuntimeException("Migrations failed in the isolated source-data copy:\n".Artisan::output());
    }

    $afterMigrations = $target->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        '2026_09_30_000005_add_export_permissions',
        '2026_09_30_000006_restore_legacy_optional_foreign_keys',
    ] as $migration) {
        if (! in_array($migration, $afterMigrations, true)) {
            throw new RuntimeException("Upgrade rehearsal did not record {$migration}.");
        }
    }

    $intentionalDataTables = ['migrations', 'permissions', 'role_permissions'];
    $unchangedTableCount = 0;
    foreach ($tables as $table) {
        if (in_array($table, $intentionalDataTables, true)) {
            continue;
        }
        if (! hash_equals($sourceHashes[$table], $tableHash($target, $targetDatabase, $table))) {
            throw new RuntimeException("A business table's row data changed during upgrade: {$table}.");
        }
        $unchangedTableCount++;
    }

    // The export migration intentionally adds capability codes/grants. Prove
    // all existing natural permission keys and role grants survive, and allow
    // only the corresponding additions derived from each role's existing view grant.
    $exportModules = collect(PermissionCatalog::moduleActions())
        ->filter(static fn (array $actions): bool => in_array('export', $actions, true))
        ->keys()->all();
    $expectedPermissions = [];
    foreach ($sourcePermissions as $permission) {
        $expectedPermissions[$permission['code']] = $permission;
    }
    foreach ($exportModules as $module) {
        $viewCode = $module.'.view';
        if (! isset($expectedPermissions[$viewCode])) {
            continue;
        }
        $exportCode = $module.'.export';
        $expectedPermissions[$exportCode] ??= [
            'code' => $exportCode,
            'title' => explode(' — ', $expectedPermissions[$viewCode]['title'], 2)[0].' — Արտահանել',
            'module' => $module,
        ];
    }
    ksort($expectedPermissions);
    $actualPermissions = [];
    foreach ($permissionRows($target, $targetDatabase) as $permission) {
        $actualPermissions[$permission['code']] = $permission;
    }
    ksort($actualPermissions);
    if ($expectedPermissions !== $actualPermissions) {
        throw new RuntimeException('Permission codes, titles, modules, or intentional export additions differ from the expected upgrade result.');
    }

    $expectedRoleGrants = [];
    foreach ($sourceRoleGrants as $grant) {
        $expectedRoleGrants[$grant['role_name'].'|'.$grant['permission_code']] = $grant;
        if (preg_match('/^([^.]+)\.view$/', $grant['permission_code'], $matches) === 1
            && in_array($matches[1], $exportModules, true)) {
            $exportCode = $matches[1].'.export';
            $expectedRoleGrants[$grant['role_name'].'|'.$exportCode] = [
                'role_name' => $grant['role_name'], 'permission_code' => $exportCode,
            ];
        }
    }
    ksort($expectedRoleGrants);
    $actualRoleGrants = [];
    foreach ($roleGrantRows($target, $targetDatabase) as $grant) {
        $actualRoleGrants[$grant['role_name'].'|'.$grant['permission_code']] = $grant;
    }
    ksort($actualRoleGrants);
    if ($expectedRoleGrants !== $actualRoleGrants) {
        throw new RuntimeException('Existing role grants changed or an unexpected role permission was added during upgrade.');
    }

    $derivedExportGrants = count($actualRoleGrants) - count($sourceRoleGrants);
    $addedPermissionCount = count($actualPermissions) - count($sourcePermissions);

    $targetTriggers = $triggerSignatures($target, $targetDatabase);
    if ($sourceTriggers !== $targetTriggers) {
        throw new RuntimeException('Source trigger definitions changed during upgrade.');
    }

    $optionalForeignKeys = (int) $target->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'categories' AND COLUMN_NAME = 'parent_id' AND REFERENCED_TABLE_NAME = 'categories') OR (TABLE_NAME = 'receipts' AND COLUMN_NAME = 'purchase_order_id' AND REFERENCED_TABLE_NAME = 'purchase_orders'))")->fetchColumn();
    if ($optionalForeignKeys !== 2) {
        throw new RuntimeException('Both expected optional foreign keys were not restored.');
    }

    $browserQaUsers = [];
    if ($keepForBrowserQa) {
        $roles = $target->query('SELECT name, id FROM roles WHERE name IN ("admin", "manager", "storekeeper", "branch", "finance", "viewer")')->fetchAll(PDO::FETCH_KEY_PAIR);
        $centralBranch = $target->query('SELECT id FROM branches WHERE code = "CENTRAL" AND active = 1 LIMIT 1')->fetchColumn();
        $branch = $target->query('SELECT id FROM branches WHERE code <> "CENTRAL" AND active = 1 ORDER BY id LIMIT 1')->fetchColumn();
        if (count($roles) !== 6 || $centralBranch === false || $branch === false) {
            throw new RuntimeException('Browser QA account bootstrap requires the six standard roles and active central/branch records.');
        }

        $suffix = substr($targetDatabase, -12);
        $qaPassword = 'LocalOnly-'.$suffix.'!';
        $insertUser = $target->prepare('INSERT INTO users (name, email, password, role_id, branch_id, active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())');
        foreach (['admin', 'manager', 'storekeeper', 'branch', 'finance', 'viewer'] as $roleName) {
            $email = 'qa-'.$roleName.'-'.$suffix.'@example.test';
            $insertUser->execute([
                'QA '.ucfirst($roleName),
                $email,
                password_hash($qaPassword, PASSWORD_BCRYPT),
                (int) $roles[$roleName],
                (int) ($roleName === 'branch' ? $branch : $centralBranch),
            ]);
            $browserQaUsers[$roleName] = $email;
        }
    }

    $resultSummary = [
        'result' => 'passed',
        'source_database' => $sourceDatabase,
        'source_copy_tables' => count($tables),
        'source_copy_rows' => $sourceRows,
        'business_tables_hash_verified_after_upgrade' => $unchangedTableCount,
        'permission_records_preserved' => count($sourcePermissions),
        'intentional_export_permissions_added' => $addedPermissionCount,
        'existing_role_grants_preserved' => count($sourceRoleGrants),
        'intentional_export_role_grants_added' => $derivedExportGrants,
        'pending_migrations_applied' => [
            '2026_09_30_000005_add_export_permissions',
            '2026_09_30_000006_restore_legacy_optional_foreign_keys',
        ],
        'trigger_definitions_preserved' => count($sourceTriggers),
        'optional_foreign_keys_restored' => $optionalForeignKeys,
        'browser_qa_users' => $browserQaUsers,
        'browser_qa_password' => $keepForBrowserQa ? $qaPassword : null,
    ];
} finally {
    if ($laravelBootstrapped) {
        DB::disconnect('mysql');
    }
    if ($created && (! $keepForBrowserQa || $resultSummary === null)) {
        $admin->exec('DROP DATABASE '.$quote($targetDatabase));
    }
}

$remainingQaDatabase = $admin->query('SHOW DATABASES LIKE '.$admin->quote('diagen_lager_portqa_%'))->fetchColumn();
if (! $keepForBrowserQa && $remainingQaDatabase !== false) {
    throw new RuntimeException('A temporary port QA database remains after cleanup.');
}
$resultSummary['qa_database_removed_after_rehearsal'] = ! $keepForBrowserQa;
$resultSummary['qa_database_retained_for_browser_testing'] = $keepForBrowserQa;
echo json_encode($resultSummary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
