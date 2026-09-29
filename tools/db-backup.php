<?php
declare(strict_types=1);

require_once __DIR__ . '/db-transfer-common.php';

try {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('Run this script from the local PHP CLI only.');
    transferPassphrase(); // Fail before reading a potentially large database snapshot.
    if ($argc > 2) throw new RuntimeException('Usage: php tools/db-backup.php [backup-name]');
    $pdo = transferPdo();
    $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $unsupported = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()")
        ->fetchColumn()
        + (int)$pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()")
        ->fetchColumn()
        + (int)$pdo->query("SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()")
        ->fetchColumn();
    if ($unsupported !== 0) throw new RuntimeException('This database contains views, routines, or events. Export them separately before relying on this backup tool.');

    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    if (!$tables) throw new RuntimeException('The configured database has no base tables; refusing to create an empty backup.');
    $tables = transferOrderedTables($pdo, $tables);
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    $archive = ['format' => 1, 'database' => $dbName, 'created_at' => gmdate('c'), 'tables' => [], 'triggers' => []];
    foreach ($tables as $table) {
        $quoted = transferQuoteIdentifier((string)$table);
        $ddlRow = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);
        if (!$ddlRow || !isset($ddlRow[1])) throw new RuntimeException('Could not read table schema: ' . $table);
        $columns = $pdo->query('SHOW COLUMNS FROM ' . $quoted)->fetchAll();
        $rows = $pdo->query('SELECT * FROM ' . $quoted)->fetchAll(PDO::FETCH_NUM);
        $archive['tables'][] = ['name' => $table, 'create_sql' => $ddlRow[1], 'columns' => array_column($columns, 'Field'), 'rows' => $rows];
    }
    $triggerNames = $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($triggerNames as $name) {
        $row = $pdo->query('SHOW CREATE TRIGGER ' . transferQuoteIdentifier((string)$name))->fetch(PDO::FETCH_ASSOC);
        $createSql = $row['SQL Original Statement'] ?? $row['Create Trigger'] ?? null;
        if (!is_string($createSql) || $createSql === '') throw new RuntimeException('Could not read trigger schema: ' . $name);
        $archive['triggers'][] = transferRemoveDefiner($createSql);
    }
    $pdo->commit();
    $json = json_encode($archive, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    $payload = transferEncrypt($json);
    $name = $argc === 2 ? $argv[1] : 'lager-' . gmdate('Ymd-His') . '.dgbk';
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}\.dgbk$/D', $name)) throw new RuntimeException('Backup name must contain only letters, numbers, dots, underscores, and dashes, and end in .dgbk.');
    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Could not create storage/backups.');
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    $handle = @fopen($path, 'xb');
    if ($handle === false) throw new RuntimeException('Backup already exists or cannot be created. No existing file was overwritten.');
    try {
        if (fwrite($handle, $payload) !== strlen($payload)) throw new RuntimeException('Backup file write was incomplete.');
        fflush($handle);
    } catch (Throwable $e) {
        fclose($handle);
        @unlink($path);
        throw $e;
    }
    fclose($handle);
    echo 'Encrypted backup created: ' . $path . PHP_EOL;
    echo 'Tables: ' . count($archive['tables']) . '; triggers: ' . count($archive['triggers']) . '; rows: ' . array_sum(array_map(static fn(array $t): int => count($t['rows']), $archive['tables'])) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
