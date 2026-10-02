<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockMatrixApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach (['stock_lots', 'products', 'role_permissions', 'permissions', 'users', 'roles', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_central_matrix_aggregates_lots_and_returns_all_active_locations(): void
    {
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $erebuni = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $inactive = Branch::query()->create(['name' => 'Inactive', 'code' => 'OFF', 'active' => false]);
        $role = $this->roleWithStockView();
        $actor = User::query()->create([
            'name' => 'Central storekeeper', 'email' => 'central@example.test', 'password' => 'password',
            'role_id' => $role->id, 'branch_id' => $central->id, 'active' => true,
        ]);
        $this->actingAs($actor, 'sanctum');

        $product = Product::query()->create(['code' => 'DIS-055', 'name' => 'Disinfectant', 'unit' => 'լիտր', 'active' => true]);
        Product::query()->create(['code' => 'DIS-OFF', 'name' => 'Inactive product', 'unit' => 'հատ', 'active' => false]);
        $this->lot((int) $product->id, 0, 2.5);
        $this->lot((int) $product->id, 0, 1.5);
        $this->lot((int) $product->id, (int) $erebuni->id, 7);
        $this->lot((int) $product->id, (int) $inactive->id, 99);

        $this->getJson('/api/stock/matrix')
            ->assertOk()
            ->assertJsonPath('locations.0.id', 0)
            ->assertJsonPath('locations.0.name', 'Կենտրոնական պահեստ')
            ->assertJsonPath('locations.1.id', $erebuni->id)
            ->assertJsonMissing(['name' => 'Inactive'])
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.quantities.0', 4)
            ->assertJsonPath('data.0.quantities.'.$erebuni->id, 7)
            ->assertJsonPath('data.0.total', 11);
    }

    public function test_branch_matrix_only_exposes_its_location_and_search_pagination(): void
    {
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $erebuni = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $gyumri = Branch::query()->create(['name' => 'Gyumri', 'code' => 'GYUM', 'active' => true]);
        $role = $this->roleWithStockView();
        $actor = User::query()->create([
            'name' => 'Branch storekeeper', 'email' => 'branch@example.test', 'password' => 'password',
            'role_id' => $role->id, 'branch_id' => $erebuni->id, 'active' => true,
        ]);
        $this->actingAs($actor, 'sanctum');

        for ($index = 1; $index <= 6; $index++) {
            $product = Product::query()->create([
                'code' => sprintf('ITEM-%02d', $index), 'name' => sprintf('Item %02d', $index), 'unit' => 'հատ', 'active' => true,
            ]);
            $this->lot((int) $product->id, (int) $central->id, 100 + $index);
            $this->lot((int) $product->id, (int) $erebuni->id, $index);
            $this->lot((int) $product->id, (int) $gyumri->id, 200 + $index);
        }

        $this->getJson('/api/stock/matrix?per_page=5&page=2&search=ITEM-')
            ->assertOk()
            ->assertJsonCount(1, 'locations')
            ->assertJsonPath('locations.0.id', $erebuni->id)
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonPath('pagination.total', 6)
            ->assertJsonPath('data.0.quantities.'.$erebuni->id, 6)
            ->assertJsonPath('data.0.total', 6)
            ->assertJsonMissingPath('data.0.quantities.'.$central->id)
            ->assertJsonMissingPath('data.0.quantities.'.$gyumri->id);
    }

    public function test_matrix_export_includes_each_product_once_across_chunks_with_names_out_of_id_order(): void
    {
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $actor = new User(['active' => true, 'branch_id' => $central->id]);
        $actor->setRelation('branch', $central);
        $codes = [];
        for ($index = 1; $index <= 501; $index++) {
            $code = sprintf('EXPORT-%03d', $index);
            $codes[] = $code;
            Product::query()->create([
                'code' => $code, 'name' => sprintf('Product %03d', 502 - $index), 'unit' => 'հատ', 'active' => true,
            ]);
        }
        Product::query()->create(['code' => 'OTHER', 'name' => 'Other', 'unit' => 'հատ', 'active' => true]);
        Product::query()->create(['code' => 'EXPORT-OFF', 'name' => 'Inactive', 'unit' => 'հատ', 'active' => false]);
        $exported = [];

        app(StockService::class)->eachMatrixExportRow($actor, 'EXPORT-', function (array $row) use (&$exported): void {
            $exported[] = $row[0];
        });

        self::assertCount(501, $exported);
        self::assertSame($codes, array_values(array_unique($exported)));
    }

    private function roleWithStockView(): Role
    {
        $role = Role::query()->create(['name' => 'stock_test', 'title' => 'Stock access']);
        $permission = Permission::query()->create(['code' => 'stock.view', 'title' => 'View stock', 'module' => 'stock']);
        $role->permissions()->attach($permission);

        return $role;
    }

    private function lot(int $productId, int $locationId, float $qty): void
    {
        StockLot::query()->create(['product_id' => $productId, 'location_id' => $locationId, 'lot_no' => 'LOT-'.bin2hex(random_bytes(3)), 'qty' => $qty]);
    }

    private function createSchema(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->boolean('active')->default(true);
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('title');
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('module');
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('unit');
            $table->boolean('active')->default(true);
        });
        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->string('lot_no');
            $table->decimal('qty', 12, 3)->default(0);
        });
    }
}
