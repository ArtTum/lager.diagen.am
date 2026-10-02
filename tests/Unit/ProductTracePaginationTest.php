<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\ProductTraceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductTracePaginationTest extends TestCase
{
    private array $tables = ['request_items', 'stock_requests', 'movements', 'stock_lots', 'users', 'branches', 'products', 'suppliers'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_product_trace_lists_paginate_all_lots_movements_and_requests(): void
    {
        DB::table('products')->insert(['id' => 1, 'code' => 'DIS-055', 'name' => 'Reagent', 'unit' => 'հատ', 'active' => true]);
        DB::table('branches')->insert(['id' => 1, 'name' => 'Branch']);

        for ($id = 1; $id <= 12; $id++) {
            DB::table('stock_lots')->insert([
                'id' => $id, 'product_id' => 1, 'location_id' => 0, 'supplier_id' => null, 'lot_no' => 'LOT-'.$id,
                'expires_on' => null, 'received_on' => '2026-09-01', 'qty' => 1, 'unit_cost' => 10, 'bin_location' => null,
            ]);
            DB::table('movements')->insert([
                'id' => $id, 'product_id' => 1, 'from_location' => $id === 1 ? null : 0, 'to_location' => $id === 1 ? 0 : null, 'lot_id' => $id,
                'movement_no' => 'MOVE-'.$id, 'type' => $id === 1 ? 'receipt' : 'consumption', 'qty' => 1, 'reference' => null,
                'reason' => null, 'happened_at' => '2026-09-01 10:00:00', 'actor_id' => null,
            ]);
            DB::table('stock_requests')->insert([
                'id' => $id, 'branch_id' => 1, 'request_no' => 'REQ-'.$id, 'created_at' => '2026-09-01 10:00:00',
                'status' => 'received', 'urgency' => 'normal',
            ]);
            DB::table('request_items')->insert([
                'id' => $id, 'request_id' => $id, 'product_id' => 1, 'requested_qty' => 1, 'approved_qty' => 1,
            ]);
        }

        $actor = $this->createMock(User::class);
        $actor->method('currentLocationId')->willReturn(0);
        $actor->method('hasPermissionCode')->willReturn(false);

        $trace = app(ProductTraceService::class)->show($actor, 1, [
            'lots_page' => 2,
            'movements_page' => 2,
            'requests_page' => 2,
        ]);

        foreach (['lots', 'movements', 'requests'] as $list) {
            self::assertSame(12, $trace[$list]->total());
            self::assertSame(2, $trace[$list]->currentPage());
            self::assertCount(2, $trace[$list]->items());
        }
        self::assertArrayNotHasKey('unit_cost', $trace['lots']->items()[0]->toArray());
        $movements = collect($trace['movements']->items())->keyBy('movement_no');
        self::assertSame('Կենտրոնական պահեստ', $movements['MOVE-2']->from_name);
        self::assertSame('Դուրս', $movements['MOVE-2']->to_name);
        self::assertSame('Դրսից', $movements['MOVE-1']->from_name);
        self::assertSame('Կենտրոնական պահեստ', $movements['MOVE-1']->to_name);
    }

    private function createSchema(): void
    {
        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('suppliers', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('unit');
            $table->boolean('active');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->decimal('purchase_price', 12, 2)->default(0);
        });
        Schema::create('branches', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('stock_lots', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('lot_no');
            $table->date('expires_on')->nullable();
            $table->date('received_on');
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 12, 2);
            $table->string('bin_location')->nullable();
        });
        Schema::create('movements', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('from_location')->nullable();
            $table->unsignedBigInteger('to_location')->nullable();
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->string('movement_no');
            $table->string('type');
            $table->decimal('qty', 12, 3);
            $table->string('reference')->nullable();
            $table->text('reason')->nullable();
            $table->dateTime('happened_at');
            $table->unsignedBigInteger('actor_id')->nullable();
        });
        Schema::create('stock_requests', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('request_no');
            $table->dateTime('created_at');
            $table->string('status');
            $table->string('urgency');
        });
        Schema::create('request_items', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('requested_qty', 12, 3);
            $table->decimal('approved_qty', 12, 3);
        });
    }
}
