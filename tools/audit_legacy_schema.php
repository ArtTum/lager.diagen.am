<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::getDriverName() !== 'mysql') {
    fwrite(STDERR, "This read-only schema audit supports MySQL/MariaDB only.\n");
    exit(2);
}

$schemaPath = base_path('database/schema.sql');
$schema = file_get_contents($schemaPath);
if ($schema === false) {
    fwrite(STDERR, "Could not load the reviewed legacy schema at {$schemaPath}.\n");
    exit(2);
}

$tables = [];
$expectedColumns = [];
$expectedForeignKeys = [];
$tableDefinitions = preg_match_all(
    '/CREATE TABLE IF NOT EXISTS\s+(\w+)\s*\((.*?)\)\s*ENGINE=/is',
    $schema,
    $tableMatches,
    PREG_SET_ORDER,
);

if ($tableDefinitions === false || $tableDefinitions === 0) {
    fwrite(STDERR, "No source-schema table definitions were found.\n");
    exit(2);
}

foreach ($tableMatches as $tableMatch) {
    $table = strtolower($tableMatch[1]);
    $definition = $tableMatch[2];
    $tables[] = $table;

    preg_match_all(
        '/(?:^|,)\s*(\w+)\s+(BIGINT|INT|VARCHAR|CHAR|DECIMAL|TINYINT|DATETIME|DATE|TIMESTAMP|TEXT|ENUM|JSON|BOOLEAN)(?:\s*\(([^)]*)\))?(?:\s+(UNSIGNED))?/i',
        $definition,
        $columnMatches,
        PREG_SET_ORDER,
    );

    foreach ($columnMatches as $columnMatch) {
        $column = strtolower($columnMatch[1]);
        $type = strtolower($columnMatch[2]);
        $length = isset($columnMatch[3]) && $columnMatch[3] !== '' ? preg_replace('/\s+/', '', $columnMatch[3]) : null;
        $unsigned = ! empty($columnMatch[4]);

        if ($length !== null) {
            $type .= '('.$length.')';
        } elseif (in_array($type, ['bigint', 'int', 'tinyint'], true)) {
            $type .= match ($type) {
                'bigint' => '(20)',
                'int' => '(11)',
                'tinyint' => '(4)',
            };
        }
        if ($unsigned) {
            $type .= ' unsigned';
        }
        $expectedColumns[$table][$column] = $type;
    }

    preg_match_all(
        '/FOREIGN KEY\s*\((\w+)\)\s*REFERENCES\s*(\w+)\s*\((\w+)\)(?:\s+ON DELETE\s+(CASCADE|SET NULL|RESTRICT))?/i',
        $definition,
        $foreignKeyMatches,
        PREG_SET_ORDER,
    );

    foreach ($foreignKeyMatches as $foreignKeyMatch) {
        $expectedForeignKeys[] = [
            'table' => $table,
            'column' => strtolower($foreignKeyMatch[1]),
            'referenced_table' => strtolower($foreignKeyMatch[2]),
            'referenced_column' => strtolower($foreignKeyMatch[3]),
            'delete_rule' => strtolower($foreignKeyMatch[4] ?? 'restrict'),
        ];
    }
}

$actualColumns = [];
foreach (DB::select('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()') as $column) {
    $actualColumns[strtolower($column->TABLE_NAME)][strtolower($column->COLUMN_NAME)] = [
        'type' => strtolower($column->COLUMN_TYPE),
        'data_type' => strtolower($column->DATA_TYPE),
    ];
}

$missingTables = array_values(array_diff($tables, array_keys($actualColumns)));
$missingColumns = [];
$typeMismatches = [];
foreach ($expectedColumns as $table => $columns) {
    foreach ($columns as $column => $expectedType) {
        $actual = $actualColumns[$table][$column] ?? null;
        if ($actual === null) {
            $missingColumns[] = ['table' => $table, 'column' => $column];

            continue;
        }

        // MariaDB implements its JSON alias using LONGTEXT.
        $matchesJsonAlias = $expectedType === 'json' && $actual['data_type'] === 'longtext';
        if (! $matchesJsonAlias && $actual['type'] !== $expectedType) {
            $typeMismatches[] = [
                'table' => $table,
                'column' => $column,
                'expected' => $expectedType,
                'actual' => $actual['type'],
            ];
        }
    }
}

$actualForeignKeys = [];
foreach (DB::select('SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
    FROM information_schema.KEY_COLUMN_USAGE AS k
    JOIN information_schema.REFERENTIAL_CONSTRAINTS AS r
      ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
    WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL') as $foreignKey) {
    $key = strtolower($foreignKey->TABLE_NAME.'.'.$foreignKey->COLUMN_NAME.'>'.$foreignKey->REFERENCED_TABLE_NAME.'.'.$foreignKey->REFERENCED_COLUMN_NAME);
    $actualForeignKeys[$key] = strtolower($foreignKey->DELETE_RULE);
}

$plannedMissingKeys = [
    'categories.parent_id>categories.id',
    'receipts.purchase_order_id>purchase_orders.id',
];
$missingForeignKeys = [];
$foreignKeyMismatches = [];
$orphanCounts = [];
foreach ($expectedForeignKeys as $foreignKey) {
    $key = $foreignKey['table'].'.'.$foreignKey['column'].'>'.$foreignKey['referenced_table'].'.'.$foreignKey['referenced_column'];
    $actualRule = $actualForeignKeys[$key] ?? null;
    if ($actualRule === null) {
        $missingForeignKeys[] = $key;

        if (isset($actualColumns[$foreignKey['table']][$foreignKey['column']])
            && isset($actualColumns[$foreignKey['referenced_table']][$foreignKey['referenced_column']])) {
            $orphans = DB::table($foreignKey['table'].' as child')
                ->leftJoin($foreignKey['referenced_table'].' as parent', 'parent.'.$foreignKey['referenced_column'], '=', 'child.'.$foreignKey['column'])
                ->whereNotNull('child.'.$foreignKey['column'])
                ->whereNull('parent.'.$foreignKey['referenced_column'])
                ->count();
            if ($orphans > 0) {
                $orphanCounts[$key] = $orphans;
            }
        }

        continue;
    }

    $expectedRule = $foreignKey['delete_rule'];
    $actualRule = $actualRule === 'no action' ? 'restrict' : $actualRule;
    if ($actualRule !== $expectedRule) {
        $foreignKeyMismatches[] = ['key' => $key, 'expected' => $expectedRule, 'actual' => $actualRule];
    }
}

$unexpectedMissingKeys = array_values(array_diff($missingForeignKeys, $plannedMissingKeys));
$hasBlockingIssues = $missingTables !== [] || $missingColumns !== [] || $typeMismatches !== []
    || $foreignKeyMismatches !== [] || $unexpectedMissingKeys !== [] || $orphanCounts !== [];
$status = $hasBlockingIssues ? 'failed' : ($missingForeignKeys === [] ? 'passed' : 'ready_for_migrations');
$report = [
    'status' => $status,
    'database' => DB::connection()->getDatabaseName(),
    'source_tables' => count($tables),
    'source_columns' => array_sum(array_map('count', $expectedColumns)),
    'source_foreign_keys' => count($expectedForeignKeys),
    'missing_tables' => $missingTables,
    'missing_columns' => $missingColumns,
    'column_type_mismatches' => $typeMismatches,
    'missing_foreign_keys' => $missingForeignKeys,
    'foreign_key_delete_rule_mismatches' => $foreignKeyMismatches,
    'orphan_row_counts' => $orphanCounts,
    'read_only' => true,
];

fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
exit($status === 'failed' ? 1 : 0);
