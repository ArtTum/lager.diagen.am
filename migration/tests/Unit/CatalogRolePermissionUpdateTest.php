<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Repositories\CatalogRepository;
use App\Services\CatalogService;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CatalogRolePermissionUpdateTest extends TestCase
{
    public function test_service_rejects_role_creation_without_roles_edit(): void
    {
        $actor = new User(['active' => true, 'branch_id' => 0]);
        $role = new Role(['name' => 'role_creator']);
        $role->setRelation('permissions', collect([
            new Permission(['code' => 'roles.create']),
        ]));
        $actor->setRelation('role', $role);

        $this->expectException(HttpException::class);
        (new CatalogService(new CatalogRepository, app(PermissionService::class)))
            ->createRole($actor, '127.0.0.1', ['title' => 'Restricted role', 'permissions' => []]);
    }

    public function test_service_rejects_role_permission_changes_without_roles_edit(): void
    {
        $actor = new User(['active' => true, 'branch_id' => 0]);
        $role = new Role(['name' => 'viewer']);
        $role->setRelation('permissions', collect());
        $actor->setRelation('role', $role);

        $this->expectException(HttpException::class);
        (new CatalogService(new CatalogRepository, app(PermissionService::class)))
            ->updateRolePermissions($actor, '127.0.0.1', 1, ['permissions' => []]);
    }

    public function test_role_permissions_update_persists_through_eloquent_models(): void
    {
        $this->createTables();

        try {
            $view = Permission::query()->create(['code' => 'stock.view', 'title' => 'View stock', 'module' => 'stock']);
            $edit = Permission::query()->create(['code' => 'roles.edit', 'title' => 'Edit roles', 'module' => 'roles']);
            $role = Role::query()->create(['name' => 'custom_stock', 'title' => 'Stock staff']);
            $role->permissions()->attach($view->id);
            $actor = new User(['id' => 1, 'active' => true, 'branch_id' => 0]);
            $actor->setRelation('role', new Role(['name' => 'admin']));

            $result = (new CatalogService(new CatalogRepository, app(PermissionService::class)))
                ->updateRolePermissions($actor, '127.0.0.1', (int) $role->id, ['permissions' => ['stock.view', 'roles.edit']]);

            self::assertSame(['roles.edit', 'stock.view'], $result['permissions']);
            self::assertSame(['roles.edit', 'stock.view'], $role->fresh()->permissions()->orderBy('code')->pluck('code')->all());
            self::assertSame(1, Schema::hasTable('audit_logs') ? AuditLog::query()->count() : 0);
        } finally {
            Schema::dropIfExists('audit_logs');
            Schema::dropIfExists('role_permissions');
            Schema::dropIfExists('permissions');
            Schema::dropIfExists('roles');
        }
    }

    public function test_role_editor_preserves_existing_permissions_outside_their_scope(): void
    {
        $this->createTables();

        try {
            $view = Permission::query()->create(['code' => 'stock.view', 'title' => 'View stock', 'module' => 'stock']);
            $cost = Permission::query()->create(['code' => 'purchases.view', 'title' => 'View costs', 'module' => 'purchases']);
            $role = Role::query()->create(['name' => 'custom_stock', 'title' => 'Stock staff']);
            $role->permissions()->attach([$view->id, $cost->id]);

            $actorRole = new Role(['name' => 'stock_editor']);
            $actorRole->setRelation('permissions', collect([
                new Permission(['code' => 'roles.edit']),
                new Permission(['code' => 'stock.view']),
            ]));
            $actor = new User(['id' => 1, 'active' => true, 'branch_id' => null]);
            $actor->setRelation('role', $actorRole);

            $result = (new CatalogService(new CatalogRepository, app(PermissionService::class)))
                ->updateRolePermissions($actor, '127.0.0.1', (int) $role->id, ['permissions' => ['stock.view']]);

            self::assertSame(['purchases.view', 'stock.view'], $result['permissions']);
            self::assertSame(['purchases.view', 'stock.view'], $role->fresh()->permissions()->orderBy('code')->pluck('code')->all());
        } finally {
            Schema::dropIfExists('audit_logs');
            Schema::dropIfExists('role_permissions');
            Schema::dropIfExists('permissions');
            Schema::dropIfExists('roles');
        }
    }

    public function test_legacy_unknown_grants_are_hidden_from_the_editor_but_preserved_in_storage(): void
    {
        $this->createTables();

        try {
            $view = Permission::query()->create(['code' => 'stock.view', 'title' => 'View stock', 'module' => 'stock']);
            $edit = Permission::query()->create(['code' => 'roles.edit', 'title' => 'Edit roles', 'module' => 'roles']);
            $legacy = Permission::query()->create(['code' => 'legacy.custom_action', 'title' => 'Legacy permission', 'module' => 'legacy']);
            $role = Role::query()->create(['name' => 'custom_stock', 'title' => 'Stock staff']);
            $role->permissions()->attach([$view->id, $edit->id, $legacy->id]);
            $actor = new User(['id' => 1, 'active' => true, 'branch_id' => 0]);
            $actor->setRelation('role', new Role(['name' => 'admin']));

            $result = (new CatalogService(new CatalogRepository, app(PermissionService::class)))
                ->updateRolePermissions($actor, '127.0.0.1', (int) $role->id, ['permissions' => ['stock.view', 'roles.edit']]);

            self::assertSame(['roles.edit', 'stock.view'], $result['permissions']);
            self::assertSame(['legacy.custom_action', 'roles.edit', 'stock.view'], $role->fresh()->permissions()->orderBy('code')->pluck('code')->all());
        } finally {
            Schema::dropIfExists('audit_logs');
            Schema::dropIfExists('role_permissions');
            Schema::dropIfExists('permissions');
            Schema::dropIfExists('roles');
        }
    }

    private function createTables(): void
    {
        Schema::create('roles', function ($table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('title');
        });
        Schema::create('permissions', function ($table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('module');
        });
        Schema::create('role_permissions', function ($table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);
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
}
