<?php

namespace Tests\Unit;

use App\Repositories\PageDataRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductBarcodeSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach (['products', 'categories', 'suppliers'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_product_list_matches_an_exact_barcode_or_internal_code(): void
    {
        DB::table('products')->insert([
            ['code' => 'DIS-055', 'barcode' => '123456789', 'name' => 'First', 'category_id' => null, 'supplier_id' => null, 'unit' => 'հատ', 'min_qty' => 0, 'active' => true],
            ['code' => 'NEE-021', 'barcode' => '987654321', 'name' => 'Second', 'category_id' => null, 'supplier_id' => null, 'unit' => 'հատ', 'min_qty' => 0, 'active' => true],
            ['code' => 'OLD-001', 'barcode' => '1234567890', 'name' => 'Inactive', 'category_id' => null, 'supplier_id' => null, 'unit' => 'հատ', 'min_qty' => 0, 'active' => false],
        ]);

        $repository = app(PageDataRepository::class);
        [, $byBarcode] = $repository->definition('products', 0, ['barcode' => '123456789']);
        [, $byCode] = $repository->definition('products', 0, ['barcode' => 'NEE-021']);
        [, $byPartialCode] = $repository->definition('products', 0, ['barcode' => '123456']);

        self::assertSame(['DIS-055'], $byBarcode->pluck('products.code')->all());
        self::assertSame(['NEE-021'], $byCode->pluck('products.code')->all());
        self::assertSame([], $byPartialCode->pluck('products.code')->all());
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('suppliers');
        Schema::create('categories', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('suppliers', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('barcode')->nullable();
            $table->string('name');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('unit');
            $table->decimal('min_qty', 12, 3)->default(0);
            $table->boolean('active')->default(true);
        });
    }
}
