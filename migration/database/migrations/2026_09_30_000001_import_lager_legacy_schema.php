<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('The inventory schema migration currently supports MySQL/MariaDB only.');
        }
        $schemaFile = base_path('../database/schema.sql');
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
