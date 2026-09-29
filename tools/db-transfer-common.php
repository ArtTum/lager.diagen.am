<?php
declare(strict_types=1);

function transferSettings(): array
{
    $settings = [];
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($path)) {
        throw new RuntimeException('Project .env file was not found.');
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $settings[trim($key)] = trim(trim($value), "\"'");
    }
    return $settings;
}

function transferPdo(?string $database = null): PDO
{
    $env = transferSettings();
    $database ??= $env['DB_DATABASE'] ?? 'diagen_lager';
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $database)) throw new RuntimeException('Unsafe database name.');
    return new PDO(
        'mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['DB_PORT'] ?? '3306') . ';dbname=' . $database . ';charset=utf8mb4',
        $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
}

function transferPassphrase(): string
{
    $passphrase = getenv('DIAGEN_BACKUP_PASSPHRASE');
    if (!is_string($passphrase) || strlen($passphrase) < 16) {
        throw new RuntimeException('Set DIAGEN_BACKUP_PASSPHRASE to a unique passphrase of at least 16 characters in this local terminal.');
    }
    return $passphrase;
}

function transferKey(string $passphrase, string $salt): string
{
    return hash_pbkdf2('sha256', $passphrase, $salt, 600000, 32, true);
}

function transferEncrypt(string $plain): string
{
    $salt = random_bytes(16);
    $iv = random_bytes(12);
    $tag = '';
    $compressed = gzencode($plain, 6, ZLIB_ENCODING_GZIP);
    if ($compressed === false) throw new RuntimeException('Could not compress backup data.');
    $ciphertext = openssl_encrypt($compressed, 'aes-256-gcm', transferKey(transferPassphrase(), $salt), OPENSSL_RAW_DATA, $iv, $tag, 'DIAGEN-DB-BACKUP-v1', 16);
    if ($ciphertext === false || strlen($tag) !== 16) throw new RuntimeException('Could not encrypt backup data.');
    return "DGBK1\0" . $salt . $iv . $tag . $ciphertext;
}

function transferDecrypt(string $payload): string
{
    if (strlen($payload) < 50 || substr($payload, 0, 6) !== "DGBK1\0") throw new RuntimeException('Unsupported or damaged backup file.');
    $salt = substr($payload, 6, 16);
    $iv = substr($payload, 22, 12);
    $tag = substr($payload, 34, 16);
    $ciphertext = substr($payload, 50);
    $compressed = openssl_decrypt($ciphertext, 'aes-256-gcm', transferKey(transferPassphrase(), $salt), OPENSSL_RAW_DATA, $iv, $tag, 'DIAGEN-DB-BACKUP-v1');
    if ($compressed === false) throw new RuntimeException('Backup authentication failed: passphrase is wrong or the file was changed.');
    $plain = gzdecode($compressed);
    if ($plain === false) throw new RuntimeException('Backup decompression failed.');
    return $plain;
}

function transferQuoteIdentifier(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $name)) throw new RuntimeException('Unexpected SQL identifier in database metadata.');
    return '`' . $name . '`';
}

function transferOrderedTables(PDO $pdo, array $tables): array
{
    $available = array_fill_keys($tables, true);
    $dependencies = array_fill_keys($tables, []);
    $rows = $pdo->query("SELECT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll();
    foreach ($rows as $row) {
        $table = (string)$row['TABLE_NAME'];
        $dependency = (string)$row['REFERENCED_TABLE_NAME'];
        if ($table !== $dependency && isset($available[$table], $available[$dependency])) $dependencies[$table][$dependency] = true;
    }
    $ordered = [];
    while ($dependencies) {
        $ready = [];
        foreach ($dependencies as $table => $needs) if (!$needs) $ready[] = $table;
        if (!$ready) throw new RuntimeException('Database has cyclic table dependencies; refusing an incomplete backup/restore order.');
        foreach ($ready as $table) {
            $ordered[] = $table;
            unset($dependencies[$table]);
        }
        foreach ($dependencies as &$needs) foreach ($ready as $table) unset($needs[$table]);
        unset($needs);
    }
    return $ordered;
}

function transferRemoveDefiner(string $sql): string
{
    return preg_replace('/\sDEFINER\s*=\s*`?[^`\s@]+`?@`?[^`\s]+`?/i', '', $sql, 1) ?? $sql;
}
