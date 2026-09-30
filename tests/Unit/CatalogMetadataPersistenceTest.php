<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogMetadataPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('suppliers', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('tax_id')->nullable();
            $table->string('address')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('bank_details')->nullable();
            $table->string('contract_no')->nullable();
            $table->date('contract_start')->nullable();
            $table->date('contract_end')->nullable();
            $table->string('payment_terms')->nullable();
            $table->unsignedInteger('delivery_days')->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('barcode')->nullable();
            $table->string('name');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('subcategory')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->decimal('purchase_price', 14, 2)->default(0);
            $table->string('manufacturer')->nullable();
            $table->string('unit')->default('հատ');
            $table->string('package')->nullable();
            $table->decimal('min_qty', 12, 3)->default(0);
            $table->decimal('optimal_qty', 12, 3)->default(0);
            $table->decimal('max_qty', 12, 3)->default(0);
            $table->string('storage_conditions')->nullable();
            $table->boolean('refrigerated')->default(false);
            $table->boolean('lot_control')->default(true);
            $table->boolean('expiry_control')->default(true);
            $table->boolean('active')->default(true);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('suppliers');

        parent::tearDown();
    }

    public function test_supplier_contract_and_vendor_metadata_survive_eloquent_persistence(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Diagen Supplier',
            'tax_id' => '01234567',
            'address' => 'Yerevan',
            'contact_name' => 'Contact Person',
            'phone' => '+37410000101',
            'email' => 'supplier@example.test',
            'bank_details' => 'Bank details',
            'contract_no' => 'CON-2026-01',
            'contract_start' => '2026-01-01',
            'contract_end' => '2026-12-31',
            'payment_terms' => '30 days',
            'delivery_days' => 4,
            'active' => true,
        ])->fresh();

        self::assertSame('01234567', $supplier->tax_id);
        self::assertSame('Bank details', $supplier->bank_details);
        self::assertSame('CON-2026-01', $supplier->contract_no);
        self::assertSame('2026-01-01', $supplier->contract_start->format('Y-m-d'));
        self::assertSame('2026-12-31', $supplier->contract_end->format('Y-m-d'));
        self::assertSame('30 days', $supplier->payment_terms);
        self::assertSame(4, $supplier->delivery_days);
        self::assertTrue($supplier->active);
    }

    public function test_product_storage_lot_expiry_and_stock_threshold_metadata_survive_eloquent_persistence(): void
    {
        $product = Product::query()->create([
            'code' => 'META-001',
            'barcode' => '1234567890',
            'name' => 'Refrigerated reagent',
            'subcategory' => 'Reagents',
            'purchase_price' => 1250.50,
            'manufacturer' => 'Manufacturer',
            'unit' => 'հատ',
            'package' => 'box of 10',
            'min_qty' => 5,
            'optimal_qty' => 20,
            'max_qty' => 40,
            'storage_conditions' => '+2–8°C',
            'refrigerated' => true,
            'lot_control' => true,
            'expiry_control' => true,
            'active' => true,
        ])->fresh();

        self::assertSame('1234567890', $product->barcode);
        self::assertSame('Reagents', $product->subcategory);
        self::assertSame('Manufacturer', $product->manufacturer);
        self::assertSame('box of 10', $product->package);
        self::assertSame('1250.50', $product->purchase_price);
        self::assertSame('5.000', $product->min_qty);
        self::assertSame('20.000', $product->optimal_qty);
        self::assertSame('40.000', $product->max_qty);
        self::assertSame('+2–8°C', $product->storage_conditions);
        self::assertTrue($product->refrigerated);
        self::assertTrue($product->lot_control);
        self::assertTrue($product->expiry_control);
    }
}
