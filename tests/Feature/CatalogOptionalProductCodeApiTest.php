<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogOptionalProductCodeApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('barcode')->nullable()->unique();
            $table->string('name');
            $table->string('unit');
            $table->decimal('purchase_price', 14, 2)->default(0);
            $table->decimal('min_qty', 12, 3)->default(0);
            $table->decimal('optimal_qty', 12, 3)->default(0);
            $table->decimal('max_qty', 12, 3)->default(0);
            $table->boolean('active')->default(true);
        });
        Product::query()->create(['code' => 'EXISTING', 'name' => 'Existing product', 'unit' => 'pcs']);
        $migration = require base_path('database/migrations/2026_10_09_000001_make_product_code_nullable.php');
        $migration->up();

        Schema::create('audit_logs', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id');
            $table->string('action');
            $table->string('entity');
            $table->unsignedBigInteger('entity_id');
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at');
        });
        $role = new Role(['name' => 'catalog_editor']);
        $role->setRelation('permissions', collect(array_map(
            static fn (string $code): Permission => new Permission(['code' => $code]),
            ['products.create', 'products.edit', 'purchases.view'],
        )));
        $actor = new User(['active' => true]);
        $actor->id = 1;
        $actor->setRelation('role', $role);
        $this->actingAs($actor, 'sanctum');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('products');
        parent::tearDown();
    }

    public static function emptyCodes(): array
    {
        return ['omitted' => [[]], 'empty' => [['code' => '']], 'whitespace' => [['code' => '   ']], 'null' => [['code' => null]]];
    }

    #[DataProvider('emptyCodes')]
    public function test_multiple_products_can_be_created_without_internal_codes(array $code): void
    {
        for ($i = 0; $i < 2; $i++) {
            $id = $this->postJson('/api/catalog/products', $this->payload($code))
                ->assertCreated()->assertJsonPath('data.code', null)->json('data.id');
            $this->assertDatabaseHas('products', ['id' => $id, 'code' => null]);
        }
        $this->assertDatabaseHas('products', ['id' => 1, 'code' => 'EXISTING']);
    }

    #[DataProvider('emptyCodes')]
    public function test_editing_allows_clearing_a_code_and_preserves_it_when_omitted(array $code): void
    {
        $expected = array_key_exists('code', $code) ? null : 'EXISTING';
        $this->putJson('/api/catalog/products/1', $this->payload($code))
            ->assertOk()->assertJsonPath('data.code', $expected);
        $this->assertDatabaseHas('products', ['id' => 1, 'code' => $expected]);
    }

    public function test_supplied_codes_remain_unique_and_limited_to_eighty_characters(): void
    {
        $id = $this->postJson('/api/catalog/products', $this->payload(['code' => 'NEW']))
            ->assertCreated()->json('data.id');
        foreach (['EXISTING', str_repeat('X', 81)] as $code) {
            $this->postJson('/api/catalog/products', $this->payload(['code' => $code]))
                ->assertUnprocessable()->assertJsonValidationErrors('code');
            $this->putJson('/api/catalog/products/'.$id, $this->payload(['code' => $code]))
                ->assertUnprocessable()->assertJsonValidationErrors('code');
        }
        $this->putJson('/api/catalog/products/'.$id, $this->payload(['code' => 'NEW']))->assertOk();
    }

    private function payload(array $code): array
    {
        return array_replace([
            'name' => 'Product without a code', 'unit' => 'pcs', 'purchase_price' => 0,
            'min_qty' => 0, 'optimal_qty' => 0, 'max_qty' => 0,
        ], $code);
    }
}
