<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Legacy optional relationship upgrades currently support MySQL/MariaDB only.');
        }

        // MySQL commits DDL implicitly. Check both relationships first so bad
        // legacy rows cannot leave this migration half-applied.
        $this->assertNoOrphans('categories', 'parent_id', 'categories', 'id');
        $this->assertNoOrphans('receipts', 'purchase_order_id', 'purchase_orders', 'id');

        $this->addForeignKeyIfMissing('categories', 'fk_categories_parent', 'parent_id', 'categories', 'id', 'set null');
        $this->addForeignKeyIfMissing('receipts', 'fk_receipts_purchase_order', 'purchase_order_id', 'purchase_orders', 'id', 'restrict');
    }

    public function down(): void
    {
        throw new RuntimeException('Legacy relationship constraints are intentionally retained. Restore a verified database backup for rollback.');
    }

    private function assertNoOrphans(string $table, string $column, string $referencedTable, string $referencedColumn): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable($referencedTable) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $orphans = DB::table($table.' as child')
            ->leftJoin($referencedTable.' as parent', 'parent.'.$referencedColumn, '=', 'child.'.$column)
            ->whereNotNull('child.'.$column)
            ->whereNull('parent.'.$referencedColumn)
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException("Cannot add {$table}.{$column} foreign key: {$orphans} orphan row(s) reference missing {$referencedTable}.{$referencedColumn} records.");
        }
    }

    private function addForeignKeyIfMissing(string $table, string $name, string $column, string $referencedTable, string $referencedColumn, string $onDelete): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable($referencedTable) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        $existing = DB::selectOne('SELECT COUNT(*) AS aggregate FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?', [$table, $column, $referencedTable, $referencedColumn]);
        if ((int) ($existing->aggregate ?? 0) > 0) {
            return;
        }

        Schema::table($table, static function (Blueprint $blueprint) use ($column, $name, $referencedTable, $referencedColumn, $onDelete): void {
            $foreign = $blueprint->foreign($column, $name)->references($referencedColumn)->on($referencedTable);
            if ($onDelete === 'set null') {
                $foreign->nullOnDelete();
            } else {
                $foreign->restrictOnDelete();
            }
        });
    }
};
