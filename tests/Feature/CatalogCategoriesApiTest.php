<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogCategoriesApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('categories', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('parent_id')->nullable();
        });
        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->string('unit')->default('հատ');
            $table->boolean('expiry_control')->default(false);
            $table->boolean('active')->default(true);
        });
        Schema::create('branches', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->boolean('active')->default(true);
        });
        Schema::create('roles', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('title');
        });
        Schema::create('audit_logs', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('entity');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        foreach (['audit_logs', 'roles', 'branches', 'products', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_categories_require_authentication_and_an_active_account(): void
    {
        $this->getJson('/api/categories')->assertUnauthorized();
        $this->actingAs($this->actor(['products.view'], active: false), 'sanctum');
        $this->getJson('/api/categories')->assertUnauthorized();
    }

    public function test_view_permission_grants_read_only_access_without_granting_category_writes(): void
    {
        $this->actingAs($this->actor(['products.create']), 'sanctum');
        $this->getJson('/api/categories')->assertForbidden();

        $this->actingAs($this->actor(['products.view']), 'sanctum');
        $this->getJson('/api/categories')->assertOk()->assertExactJson(['data' => []]);
        $this->postJson('/api/categories', ['name' => 'Unauthorized create'])->assertForbidden();
        $this->deleteJson('/api/categories/1')->assertForbidden();
        self::assertDatabaseCount('categories', 0);

        $this->actingAs($this->actor(['products.view'], location: 17), 'sanctum');
        $this->getJson('/api/categories')->assertForbidden();
    }

    public function test_list_has_sorted_parent_projection_and_counts_inactive_assigned_products(): void
    {
        $parent = Category::query()->create(['name' => 'Zeta']);
        $child = Category::query()->create(['name' => 'Alpha', 'parent_id' => $parent->id]);
        $unused = Category::query()->create(['name' => 'Beta']);
        Product::query()->create(['category_id' => $child->id, 'active' => true]);
        Product::query()->create(['category_id' => $child->id, 'active' => false]);
        Product::query()->create(['category_id' => $parent->id, 'active' => true]);
        Product::query()->create(['category_id' => null, 'active' => false]);
        $this->actingAs($this->actor(['products.view']), 'sanctum');

        $response = $this->getJson('/api/categories')->assertOk();

        self::assertSame(['data' => [
            ['id' => (int) $child->id, 'name' => 'Alpha', 'parent_id' => (int) $parent->id,
                'parent' => ['id' => (int) $parent->id, 'name' => 'Zeta'], 'products_count' => 2, 'children_count' => 0],
            ['id' => (int) $unused->id, 'name' => 'Beta', 'parent_id' => null, 'parent' => null, 'products_count' => 0, 'children_count' => 0],
            ['id' => (int) $parent->id, 'name' => 'Zeta', 'parent_id' => null, 'parent' => null, 'products_count' => 1, 'children_count' => 1],
        ]], $response->json());
    }

    public function test_created_categories_are_listed_and_selectable_while_duplicate_names_are_rejected(): void
    {
        $this->actingAs($this->actor(['products.view', 'products.create']), 'sanctum');
        $parentId = $this->postJson('/api/categories', ['name' => 'Parent'])->assertCreated()->json('data.id');
        $childId = $this->postJson('/api/categories', ['name' => '  Child  ', 'parent_id' => $parentId])
            ->assertCreated()->assertJsonPath('data.name', 'Child')->json('data.id');

        $this->getJson('/api/categories')->assertOk()
            ->assertJsonPath('data.0.id', $childId)
            ->assertJsonPath('data.0.parent', ['id' => $parentId, 'name' => 'Parent'])
            ->assertJsonPath('data.1.children_count', 1);
        $options = $this->getJson('/api/catalog/products/options')->assertOk()->json('data.categories');
        self::assertSame(['id' => $childId, 'name' => 'Child', 'parent_id' => $parentId], collect($options)->firstWhere('id', $childId));

        $this->postJson('/api/categories', ['name' => ' Child '])->assertUnprocessable()->assertJsonValidationErrors('name');
        self::assertDatabaseCount('categories', 2);
        self::assertDatabaseCount('audit_logs', 2);
    }

    public function test_delete_keeps_categories_used_by_inactive_products_or_child_categories(): void
    {
        $parent = Category::query()->create(['name' => 'Parent']);
        $used = Category::query()->create(['name' => 'Used', 'parent_id' => $parent->id]);
        $unused = Category::query()->create(['name' => 'Unused']);
        Product::query()->create(['category_id' => $used->id, 'active' => false]);
        $this->actingAs($this->actor(['products.view', 'products.delete']), 'sanctum');

        $this->deleteJson('/api/categories/'.$used->id)->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->deleteJson('/api/categories/'.$parent->id)->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->deleteJson('/api/categories/'.$unused->id)->assertOk();

        $rows = $this->getJson('/api/categories')->assertOk()->json('data');
        self::assertSame([(int) $parent->id, (int) $used->id], array_column($rows, 'id'));
        self::assertSame(1, $rows[0]['children_count']);
        self::assertSame(1, $rows[1]['products_count']);
        self::assertDatabaseMissing('categories', ['id' => $unused->id]);
        self::assertDatabaseCount('audit_logs', 1);
    }

    private function actor(array $permissions, bool $active = true, int $location = 0): User
    {
        $role = new Role(['name' => 'category_viewer']);
        $role->setRelation('permissions', collect(array_map(static fn ($code) => new Permission(['code' => $code]), $permissions)));
        $actor = (new User)->forceFill(['id' => 9, 'active' => $active, 'branch_id' => $location]);
        $actor->setRelation('role', $role);
        $actor->setRelation('branch', $location > 0 ? (new Branch)->forceFill(['id' => $location, 'name' => 'Branch', 'code' => 'BRANCH']) : null);

        return $actor;
    }
}
