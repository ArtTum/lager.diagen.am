<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Repositories\CatalogRepository;
use App\Services\CatalogService;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogCategoryManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('categories', function ($table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
        });
        Schema::create('products', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('category_id')->nullable();
        });
        Schema::create('audit_logs', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('entity');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        parent::tearDown();
    }

    public function test_category_create_and_delete_are_audited_and_allow_parent_groups(): void
    {
        $service = $this->service();
        $actor = $this->actor();
        $parent = $service->createCategory($actor, '127.0.0.1', ['name' => 'Լաբորատոր նյութեր']);
        $child = $service->createCategory($actor, '127.0.0.1', ['name' => 'Ռեագենտներ', 'parent_id' => $parent->id]);

        self::assertSame((int) $parent->id, (int) $child->parent_id);
        self::assertSame('Ռեագենտներ', $child->name);
        try {
            $service->deleteCategory($actor, '127.0.0.1', (int) $parent->id);
            self::fail('A category with a child must be retained.');
        } catch (ValidationException) {
            self::assertDatabaseHas('categories', ['id' => $parent->id]);
        }

        $service->deleteCategory($actor, '127.0.0.1', (int) $child->id);
        self::assertDatabaseMissing('categories', ['id' => $child->id]);
        self::assertSame(3, AuditLog::query()->count());
    }

    public function test_category_with_assigned_products_cannot_be_deleted(): void
    {
        $category = Category::query()->create(['name' => 'Բժշկական նյութեր']);
        Product::query()->create(['category_id' => $category->id]);

        try {
            $this->service()->deleteCategory($this->actor(), '127.0.0.1', (int) $category->id);
            self::fail('A category used by products must be retained.');
        } catch (ValidationException) {
            self::assertDatabaseHas('categories', ['id' => $category->id]);
            self::assertSame(0, AuditLog::query()->count());
        }
    }

    private function service(): CatalogService
    {
        return new CatalogService(new CatalogRepository, app(PermissionService::class));
    }

    private function actor(): User
    {
        return (new User)->forceFill(['id' => 9, 'active' => true, 'branch_id' => 0]);
    }
}
