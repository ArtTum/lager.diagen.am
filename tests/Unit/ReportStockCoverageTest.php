<?php

namespace Tests\Unit;

use App\Repositories\ReportRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReportStockCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
        });
        foreach (['categories', 'suppliers'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });
        }
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('unit')->default('հատ');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->boolean('active')->default(true);
            $table->decimal('min_qty', 12, 3)->default(10);
            $table->decimal('optimal_qty', 12, 3)->default(20);
            $table->decimal('max_qty', 12, 3)->default(30);
        });
        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 14, 2)->default(1);
        });
        Schema::create('stock_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
        });
        Schema::create('request_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('approved_qty', 12, 3);
        });
        Schema::create('transfers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('from_branch');
            $table->string('status');
        });
        Schema::create('transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('transfer_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('qty', 12, 3);
        });
    }

    protected function tearDown(): void
    {
        foreach (['transfer_items', 'transfers', 'request_items', 'stock_requests', 'stock_lots', 'products', 'suppliers', 'categories', 'branches'] as $name) {
            Schema::dropIfExists($name);
        }
        parent::tearDown();
    }

    public function test_low_stock_report_keeps_products_whose_existing_lots_are_depleted(): void
    {
        DB::table('products')->insert([
            ['id' => 1, 'code' => 'DEPLETED', 'name' => 'Depleted', 'active' => true],
            ['id' => 2, 'code' => 'NEVER-STOCKED', 'name' => 'Never stocked', 'active' => true],
            ['id' => 3, 'code' => 'SUFFICIENT', 'name' => 'Sufficient', 'active' => true],
            ['id' => 4, 'code' => 'INACTIVE', 'name' => 'Inactive', 'active' => false],
        ]);
        DB::table('stock_lots')->insert([
            ['product_id' => 1, 'location_id' => 0, 'qty' => 0],
            ['product_id' => 1, 'location_id' => 0, 'qty' => 0],
            ['product_id' => 3, 'location_id' => 0, 'qty' => 0],
            ['product_id' => 3, 'location_id' => 0, 'qty' => 15],
            ['product_id' => 4, 'location_id' => 0, 'qty' => 0],
        ]);

        $rows = (new ReportRepository)->query('low_stock', '', '', 0)->get()->keyBy('code');

        self::assertEqualsCanonicalizing(['DEPLETED', 'NEVER-STOCKED'], $rows->keys()->all());
        self::assertSame(0.0, (float) $rows['DEPLETED']->quantity);
        self::assertSame('Կենտրոնական պահեստ', $rows['DEPLETED']->branch_name);
    }
}
