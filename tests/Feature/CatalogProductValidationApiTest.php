<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CatalogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogProductValidationApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('barcode')->nullable();
        });
        $role = new Role(['name' => 'catalog_editor']);
        $role->setRelation('permissions', collect(array_map(
            static fn (string $code): Permission => new Permission(['code' => $code]),
            ['products.create', 'products.edit', 'purchases.view'],
        )));
        $actor = new User(['active' => true]);
        $actor->setRelation('role', $role);
        $this->actingAs($actor, 'sanctum');
        $service = $this->createMock(CatalogService::class);
        $service->expects(self::never())->method('store');
        $service->expects(self::never())->method('update');
        $this->app->instance(CatalogService::class, $service);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('products');
        parent::tearDown();
    }

    public static function invalidNumbers(): array
    {
        $cases = [];
        foreach (['min_qty', 'optimal_qty', 'max_qty'] as $field) {
            foreach (['overflow' => '1000000000', 'precision' => '0.0001', 'exponent' => '1e309'] as $label => $value) {
                $cases[$field.' '.$label] = [$field, $value];
            }
        }
        $cases['price overflow'] = ['purchase_price', '1000000000000'];
        $cases['price precision'] = ['purchase_price', '0.001'];

        return $cases;
    }

    #[DataProvider('invalidNumbers')]
    public function test_create_and_update_reject_unrepresentable_product_numbers(string $field, string $value): void
    {
        $payload = array_replace([
            'code' => 'TEST', 'name' => 'Validation product', 'unit' => 'pcs',
            'purchase_price' => '1.00', 'min_qty' => '0', 'optimal_qty' => '0', 'max_qty' => '0',
        ], [$field => $value]);

        $this->postJson('/api/catalog/products', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->putJson('/api/catalog/products/1', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }
}
