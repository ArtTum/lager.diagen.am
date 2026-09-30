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
            throw new RuntimeException('Existing Lager schema upgrades currently support MySQL/MariaDB only.');
        }

        $addedReceivedQty = false;
        $this->addMissingColumns('suppliers', [
            'bank_details' => static fn (Blueprint $table) => $table->string('bank_details', 255)->nullable(),
            'contract_start' => static fn (Blueprint $table) => $table->date('contract_start')->nullable(),
            'contract_end' => static fn (Blueprint $table) => $table->date('contract_end')->nullable(),
        ]);
        $this->addMissingColumns('categories', [
            'parent_id' => static fn (Blueprint $table) => $table->unsignedBigInteger('parent_id')->nullable(),
        ]);
        $this->addMissingColumns('products', [
            'barcode' => static fn (Blueprint $table) => $table->string('barcode', 100)->nullable(),
            'subcategory' => static fn (Blueprint $table) => $table->string('subcategory', 120)->nullable(),
            'supplier_id' => static fn (Blueprint $table) => $table->unsignedBigInteger('supplier_id')->nullable(),
            'purchase_price' => static fn (Blueprint $table) => $table->decimal('purchase_price', 14, 2)->default(0),
        ]);
        $this->addMissingColumns('stock_requests', [
            'sent_by' => static fn (Blueprint $table) => $table->unsignedBigInteger('sent_by')->nullable(),
            'received_by' => static fn (Blueprint $table) => $table->unsignedBigInteger('received_by')->nullable(),
        ]);
        $this->addMissingColumns('transfers', [
            'shipped_by' => static fn (Blueprint $table) => $table->unsignedBigInteger('shipped_by')->nullable(),
            'received_by' => static fn (Blueprint $table) => $table->unsignedBigInteger('received_by')->nullable(),
        ]);
        $this->addMissingColumns('inventory_lines', [
            'counted_lot_no' => static fn (Blueprint $table) => $table->string('counted_lot_no', 100)->nullable(),
            'counted_expires_on' => static fn (Blueprint $table) => $table->date('counted_expires_on')->nullable(),
            'counted_supplier_id' => static fn (Blueprint $table) => $table->unsignedBigInteger('counted_supplier_id')->nullable(),
            'counted_bin_location' => static fn (Blueprint $table) => $table->string('counted_bin_location', 100)->nullable(),
            'counted_unit_cost' => static fn (Blueprint $table) => $table->decimal('counted_unit_cost', 14, 2)->nullable(),
        ]);
        $this->addMissingColumns('receipts', [
            'purchase_order_id' => static fn (Blueprint $table) => $table->unsignedBigInteger('purchase_order_id')->nullable(),
        ]);
        $this->addMissingColumns('stock_lots', [
            'purchase_order_id' => static fn (Blueprint $table) => $table->unsignedBigInteger('purchase_order_id')->nullable(),
        ]);
        $addedReceivedQty = ! Schema::hasColumn('purchase_order_items', 'received_qty');
        $this->addMissingColumns('purchase_order_items', [
            'received_qty' => static fn (Blueprint $table) => $table->decimal('received_qty', 12, 3)->default(0),
        ]);

        $this->addForeignKeyIfMissing('categories', 'fk_categories_parent', 'parent_id', 'categories', 'id', 'set null');
        $this->addForeignKeyIfMissing('receipts', 'fk_receipts_purchase_order', 'purchase_order_id', 'purchase_orders', 'id', 'restrict');
        $this->addForeignKeyIfMissing('inventory_lines', 'fk_inventory_lines_counted_supplier', 'counted_supplier_id', 'suppliers', 'id', 'set null');
        $this->addForeignKeyIfMissing('stock_requests', 'fk_stock_requests_sent_by', 'sent_by', 'users', 'id', 'set null');
        $this->addForeignKeyIfMissing('stock_requests', 'fk_stock_requests_received_by', 'received_by', 'users', 'id', 'set null');
        $this->addForeignKeyIfMissing('transfers', 'fk_transfers_shipped_by', 'shipped_by', 'users', 'id', 'set null');
        $this->addForeignKeyIfMissing('transfers', 'fk_transfers_received_by', 'received_by', 'users', 'id', 'set null');
        $this->addForeignKeyIfMissing('products', 'fk_products_supplier', 'supplier_id', 'suppliers', 'id', 'set null');
        if (! $this->hasIndex('products', 'uq_products_barcode') && Schema::hasColumn('products', 'barcode')) {
            Schema::table('products', static function (Blueprint $table): void {
                $table->unique('barcode', 'uq_products_barcode');
            });
        }

        if ($this->hasTableAndColumns('receipt_items', ['receipt_id', 'purchase_order_item_id', 'product_id', 'lot_id', 'qty', 'unit_cost'])
            && $this->hasTableAndColumns('receipts', ['id', 'receipt_no', 'purchase_order_id'])
            && $this->hasTableAndColumns('movements', ['reference', 'type', 'product_id', 'lot_id', 'qty', 'unit_cost'])
            && $this->hasTableAndColumns('purchase_order_items', ['id', 'purchase_order_id', 'product_id'])) {
            DB::statement("INSERT INTO receipt_items(receipt_id, purchase_order_item_id, product_id, lot_id, qty, unit_cost)
                SELECT r.id, i.id, m.product_id, m.lot_id, m.qty, m.unit_cost
                FROM receipts AS r
                JOIN movements AS m ON m.reference = r.receipt_no AND m.type = 'receipt'
                LEFT JOIN purchase_order_items AS i ON i.purchase_order_id = r.purchase_order_id AND i.product_id = m.product_id
                WHERE NOT EXISTS (SELECT 1 FROM receipt_items AS ri WHERE ri.receipt_id = r.id AND ri.product_id = m.product_id AND ri.lot_id <=> m.lot_id)");
        }

        if ($addedReceivedQty && $this->hasTableAndColumns('purchase_order_items', ['id', 'purchase_order_id', 'product_id', 'received_qty'])) {
            if ($this->hasTableAndColumns('receipt_items', ['purchase_order_item_id', 'lot_id', 'qty'])
                && $this->hasTableAndColumns('stock_lots', ['purchase_order_id', 'product_id', 'qty'])) {
                // Receipt movements are the historical record. Use their backfilled
                // detail lines first, then include linked lots that have no detail row.
                DB::statement('UPDATE purchase_order_items AS i SET received_qty =
                    (SELECT COALESCE(SUM(ri.qty), 0) FROM receipt_items AS ri WHERE ri.purchase_order_item_id = i.id)
                    + (SELECT COALESCE(SUM(s.qty), 0) FROM stock_lots AS s
                        WHERE s.purchase_order_id = i.purchase_order_id AND s.product_id = i.product_id
                        AND NOT EXISTS (SELECT 1 FROM receipt_items AS ri WHERE ri.purchase_order_item_id = i.id AND ri.lot_id = s.id))');
            } elseif ($this->hasTableAndColumns('stock_lots', ['purchase_order_id', 'product_id', 'qty'])) {
                DB::statement('UPDATE purchase_order_items AS i SET received_qty =
                    (SELECT COALESCE(SUM(s.qty), 0) FROM stock_lots AS s WHERE s.purchase_order_id = i.purchase_order_id AND s.product_id = i.product_id)');
            }
        }

        if ($this->hasTableAndColumns('transfers', ['id', 'product_id', 'qty'])
            && $this->hasTableAndColumns('transfer_items', ['transfer_id', 'product_id', 'qty'])) {
            DB::statement('INSERT INTO transfer_items(transfer_id, product_id, qty) SELECT t.id, t.product_id, t.qty FROM transfers AS t WHERE NOT EXISTS (SELECT 1 FROM transfer_items AS ti WHERE ti.transfer_id = t.id)');
        }

        $this->normalizeReturnNumberIndex();
    }

    public function down(): void
    {
        throw new RuntimeException('Legacy data upgrades are intentionally retained. Restore a verified database backup for rollback.');
    }

    /** @param array<string, Closure(Blueprint): mixed> $columns */
    private function addMissingColumns(string $tableName, array $columns): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }
        foreach ($columns as $columnName => $definition) {
            if (Schema::hasColumn($tableName, $columnName)) {
                continue;
            }
            Schema::table($tableName, static function (Blueprint $table) use ($definition): void {
                $definition($table);
            });
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
            } elseif ($onDelete === 'cascade') {
                $foreign->cascadeOnDelete();
            } else {
                $foreign->restrictOnDelete();
            }
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        $index = DB::selectOne('SELECT COUNT(*) AS aggregate FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$table, $name]);

        return (int) ($index->aggregate ?? 0) > 0;
    }

    /** @param list<string> $columns */
    private function hasTableAndColumns(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeReturnNumberIndex(): void
    {
        if (! Schema::hasColumn('returns', 'return_no')) {
            return;
        }
        $indexes = DB::select("SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'returns' GROUP BY INDEX_NAME, NON_UNIQUE");
        $uniqueName = null;
        $hasRegularIndex = false;
        foreach ($indexes as $index) {
            if ($index->columns_list !== 'return_no') {
                continue;
            }
            if ((int) $index->NON_UNIQUE === 0) {
                $uniqueName = (string) $index->INDEX_NAME;
            } else {
                $hasRegularIndex = true;
            }
        }
        if ($uniqueName !== null) {
            DB::statement('ALTER TABLE `returns` DROP INDEX `'.str_replace('`', '``', $uniqueName).'`');
        }
        if (! $hasRegularIndex) {
            Schema::table('returns', static function (Blueprint $table): void {
                $table->index('return_no', 'return_no');
            });
        }
    }
};
