<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('The inventory schema migration currently supports MySQL/MariaDB only.');
        }

        $coreTables = ['roles', 'users', 'products', 'stock_lots', 'movements'];
        $existingCoreTables = array_filter($coreTables, static fn (string $table): bool => Schema::hasTable($table));
        if (count($existingCoreTables) === count($coreTables)) {
            // Existing PHP installations already have their business schema.
            // The production schema audit must run before this migration is applied.
            return;
        }
        if ($existingCoreTables !== []) {
            throw new RuntimeException('A partial Lager schema exists. Refusing to import the initial schema over it.');
        }

        $schemaFile = base_path('database/schema.sql');
        $schema = file_get_contents($schemaFile);
        if ($schema === false) {
            throw new RuntimeException('Could not load the reviewed legacy inventory schema.');
        }

        foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
            DB::unprepared($statement);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('The imported inventory schema is intentionally not auto-dropped. Restore a verified database backup for rollback.');
    }
};
