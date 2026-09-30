<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

$targetDatabase = $argv[1] ?? '';
if (! preg_match('/\Adiagen_lager_portqa_[a-f0-9]{12}\z/', $targetDatabase)) {
    throw new InvalidArgumentException('Pass the exact temporary database name emitted by rehearse_local_upgrade.php --keep.');
}

$connection = $_ENV['DB_CONNECTION'] ?? 'mysql';
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? '3306';
$username = $_ENV['DB_USERNAME'] ?? '';
$password = $_ENV['DB_PASSWORD'] ?? '';
$sourceDatabase = $_ENV['DB_DATABASE'] ?? '';
if ($connection !== 'mysql' || ! in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true)
    || $sourceDatabase !== 'diagen_lager' || $targetDatabase === $sourceDatabase) {
    throw new RuntimeException('QA database cleanup is restricted to local MySQL and never targets the source database.');
}

$pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$exists->execute([$targetDatabase]);
if ((int) $exists->fetchColumn() === 0) {
    echo json_encode(['result' => 'already_absent', 'database' => $targetDatabase], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

$pdo->exec('DROP DATABASE `'.$targetDatabase.'`');
echo json_encode(['result' => 'removed', 'database' => $targetDatabase], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
