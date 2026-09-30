<?php

namespace Tests\Unit;

use App\Services\SupplierService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplierHistoryPricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach (['receipt_items', 'receipts', 'purchase_order_items', 'purchase_orders', 'stock_lots', 'branches', 'products', 'categories', 'users', 'suppliers'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_supplier_product_history_shows_latest_received_price_only_to_financial_view(): void
    {
        DB::table('suppliers')->insert(['id' => 1, 'name' => 'Supplier A']);
        DB::table('products')->insert(['id' => 1, 'code' => 'DIS-055', 'name' => 'Reagent', 'unit' => 'հատ', 'active' => true]);
        DB::table('receipts')->insert([
            ['id' => 1, 'supplier_id' => 1, 'receipt_no' => 'REC-1', 'received_on' => '2026-08-01', 'received_by' => null],
            ['id' => 2, 'supplier_id' => 1, 'receipt_no' => 'REC-2', 'received_on' => '2026-09-01', 'received_by' => null],
        ]);
        DB::table('receipt_items')->insert([
            ['id' => 1, 'receipt_id' => 1, 'product_id' => 1, 'lot_id' => null, 'qty' => 4, 'unit_cost' => 100],
            ['id' => 2, 'receipt_id' => 2, 'product_id' => 1, 'lot_id' => null, 'qty' => 6, 'unit_cost' => 125],
        ]);

        $service = app(SupplierService::class);
        $financial = $service->history(1, true, 0);
        $general = $service->history(1, false, 0);

        self::assertSame('2026-09-01', $financial['products']->first()->last_delivery_on);
        self::assertSame(125.0, (float) $financial['products']->first()->latest_unit_cost);
        self::assertTrue($financial['show_prices']);
        self::assertFalse($general['show_prices']);
        self::assertSame('2026-09-01', $general['products']->first()->last_delivery_on);
        self::assertArrayNotHasKey('latest_unit_cost', $general['products']->first()->getAttributes());
        self::assertArrayNotHasKey('unit_cost', $general['receipts']->first()->lines->first());
    }

    public function test_supplier_receipt_and_lot_history_are_paginated_without_truncating_totals(): void
    {
        DB::table('suppliers')->insert(['id' => 1, 'name' => 'Supplier A']);
        DB::table('products')->insert(['id' => 1, 'code' => 'DIS-055', 'name' => 'Reagent', 'unit' => 'հատ', 'active' => true]);

        for ($id = 1; $id <= 12; $id++) {
            DB::table('receipts')->insert([
                'id' => $id,
                'supplier_id' => 1,
                'receipt_no' => 'REC-'.$id,
                'received_on' => sprintf('2026-09-%02d', $id),
                'received_by' => null,
            ]);
            DB::table('receipt_items')->insert([
                'id' => $id,
                'receipt_id' => $id,
                'product_id' => 1,
                'lot_id' => null,
                'qty' => 1,
                'unit_cost' => 100 + $id,
            ]);
            DB::table('stock_lots')->insert([
                'id' => $id,
                'product_id' => 1,
                'location_id' => 0,
                'supplier_id' => 1,
                'lot_no' => 'LOT-'.$id,
                'received_on' => sprintf('2026-09-%02d', $id),
                'expires_on' => null,
                'qty' => 1,
                'unit_cost' => 100 + $id,
                'bin_location' => null,
            ]);
        }

        $history = app(SupplierService::class)->history(1, false, 0, [
            'receipts_page' => 2,
            'lots_page' => 2,
        ]);

        self::assertSame(12, $history['receipts']->total());
        self::assertSame(2, $history['receipts']->currentPage());
        self::assertCount(2, $history['receipts']->items());
        self::assertArrayNotHasKey('unit_cost', $history['receipts']->items()[0]->lines->first());
        self::assertSame(12, $history['lots']->total());
        self::assertSame(2, $history['lots']->currentPage());
        self::assertCount(2, $history['lots']->items());
        self::assertArrayNotHasKey('unit_cost', $history['lots']->items()[0]->toArray());
        self::assertSame('Կենտրոնական պահեստ', $history['lots']->items()[0]->branch);
    }

    private function createSchema(): void
    {
        foreach (['receipt_items', 'receipts', 'purchase_order_items', 'purchase_orders', 'stock_lots', 'branches', 'products', 'categories', 'users', 'suppliers'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('suppliers', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('categories', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('branches', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('unit');
            $table->boolean('active');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
        });
        Schema::create('purchase_orders', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
        });
        Schema::create('purchase_order_items', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('unit_cost', 12, 2);
        });
        Schema::create('receipts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->string('receipt_no');
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->string('invoice_no')->nullable();
            $table->string('contract_no')->nullable();
            $table->date('received_on');
            $table->dateTime('created_at')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
        });
        Schema::create('receipt_items', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('receipt_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 12, 2);
        });
        Schema::create('stock_lots', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('lot_no');
            $table->date('received_on');
            $table->date('expires_on')->nullable();
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 12, 2);
            $table->string('bin_location')->nullable();
        });
    }
}
