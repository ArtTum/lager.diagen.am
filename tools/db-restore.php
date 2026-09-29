<?php
declare(strict_types=1);

require_once __DIR__ . '/db-transfer-common.php';

function cleanupRestoreTarget(PDO $pdo): void
{
    $triggers = $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($triggers as $trigger) $pdo->exec('DROP TRIGGER IF EXISTS ' . transferQuoteIdentifier((string)$trigger));
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) $pdo->exec('DROP TABLE IF EXISTS ' . transferQuoteIdentifier((string)$table));
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

try {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('Run this script from the local PHP CLI only.');
    if ($argc !== 3) throw new RuntimeException('Usage: php tools/db-restore.php <backup-file.dgbk> <empty-diagen_restore_database>');
    $sourceDatabase = (string)(transferSettings()['DB_DATABASE'] ?? 'diagen_lager');
    $targetDatabase = $argv[2];
    if (!preg_match('/^diagen_restore_[A-Za-z0-9_]{1,48}$/D', $targetDatabase)) throw new RuntimeException('Restore target must start with diagen_restore_ and contain only letters, numbers, and underscores.');
    if (strcasecmp($sourceDatabase, $targetDatabase) === 0) throw new RuntimeException('Restore target cannot be the configured live database.');
    $backupPath = realpath($argv[1]);
    $backupRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups');
    if ($backupPath === false || $backupRoot === false || !str_starts_with(strtolower($backupPath), strtolower($backupRoot . DIRECTORY_SEPARATOR)) || !is_file($backupPath)) {
        throw new RuntimeException('Backup file must be inside the project storage/backups directory.');
    }
    if (filesize($backupPath) > 1024 * 1024 * 1024) throw new RuntimeException('Backup is over the 1 GiB safety limit.');
    $pdo = transferPdo($targetDatabase);
    $objects = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")
        ->fetchColumn()
        + (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()")
        ->fetchColumn()
        + (int)$pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()")
        ->fetchColumn()
        + (int)$pdo->query("SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()")
        ->fetchColumn();
    if ($objects !== 0) throw new RuntimeException('Restore target is not empty. No changes were made.');
    $payload = file_get_contents($backupPath);
    if (!is_string($payload)) throw new RuntimeException('Could not read the backup file.');
    $archive = json_decode(transferDecrypt($payload), true, 512, JSON_THROW_ON_ERROR);
    if (($archive['format'] ?? null) !== 1 || !is_array($archive['tables'] ?? null) || !$archive['tables']) throw new RuntimeException('Backup manifest is missing or unsupported.');

    $expected = [];
    foreach ($archive['tables'] as $table) {
        if (!is_array($table) || !is_string($table['name'] ?? null) || !is_string($table['create_sql'] ?? null) || !is_array($table['columns'] ?? null) || !is_array($table['rows'] ?? null)) throw new RuntimeException('Backup contains an invalid table entry.');
        $tableName = $table['name'];
        transferQuoteIdentifier($tableName);
        if (!preg_match('/^CREATE TABLE `?' . preg_quote($tableName, '/') . '`?\b/i', $table['create_sql'])) throw new RuntimeException('Backup table DDL name does not match manifest.');
        if (isset($expected[$tableName])) throw new RuntimeException('Backup contains a duplicate table.');
        foreach ($table['columns'] as $column) transferQuoteIdentifier((string)$column);
        $expected[$tableName] = count($table['rows']);
    }
    foreach ($archive['triggers'] ?? [] as $triggerSql) if (!is_string($triggerSql) || !preg_match('/^CREATE\s+TRIGGER\b/i', ltrim($triggerSql))) throw new RuntimeException('Backup contains an invalid trigger definition.');

    try {
        foreach ($archive['tables'] as $table) $pdo->exec($table['create_sql']);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($archive['tables'] as $table) {
            if (!$table['rows']) continue;
            $quotedColumns = array_map(static fn(string $c): string => transferQuoteIdentifier($c), $table['columns']);
            $sql = 'INSERT INTO ' . transferQuoteIdentifier($table['name']) . ' (' . implode(',', $quotedColumns) . ') VALUES (' . implode(',', array_fill(0, count($quotedColumns), '?')) . ')';
            $insert = $pdo->prepare($sql);
            foreach ($table['rows'] as $row) {
                if (!is_array($row) || count($row) !== count($quotedColumns)) throw new RuntimeException('Backup row does not match table columns: ' . $table['name']);
                $insert->execute(array_values($row));
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        foreach ($archive['triggers'] ?? [] as $triggerSql) $pdo->exec($triggerSql);
        foreach ($expected as $tableName => $count) {
            $actual = (int)$pdo->query('SELECT COUNT(*) FROM ' . transferQuoteIdentifier($tableName))->fetchColumn();
            if ($actual !== $count) throw new RuntimeException('Restored row count mismatch for ' . $tableName . '.');
        }
    } catch (Throwable $e) {
        try { cleanupRestoreTarget($pdo); } catch (Throwable $cleanupError) { fwrite(STDERR, 'Cleanup warning: ' . $cleanupError->getMessage() . PHP_EOL); }
        throw $e;
    }
    echo 'Restore verified in isolated database ' . $targetDatabase . PHP_EOL;
    echo 'Tables: ' . count($expected) . '; triggers: ' . count($archive['triggers'] ?? []) . '; rows: ' . array_sum($expected) . PHP_EOL;
    echo 'This did not switch the application configuration. Review the restored data before any production cutover.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Restore failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
