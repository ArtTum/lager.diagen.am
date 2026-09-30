<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PermissionServiceTest extends TestCase
{
    #[DataProvider('centralOnlyCapabilities')]
    public function test_branch_scoped_admin_cannot_use_central_only_capabilities(string $code): void
    {
        $user = new User(['branch_id' => 2, 'active' => true]);
        $user->setRelation('role', new Role(['name' => 'admin']));
        $user->setRelation('branch', new Branch(['code' => 'EREB']));

        self::assertFalse(app(PermissionService::class)->allows($user, $code));
    }

    public function test_branch_role_keeps_legacy_branch_grants_but_cannot_access_global_modules(): void
    {
        $role = new Role(['name' => 'branch']);
        $role->setRelation('permissions', collect([
            new Permission(['code' => 'reports.view']),
            new Permission(['code' => 'stock.create']),
            new Permission(['code' => 'requests.create']),
            new Permission(['code' => 'purchases.view']),
            new Permission(['code' => 'suppliers.view']),
            new Permission(['code' => 'audit.view']),
            new Permission(['code' => 'products.edit']),
        ]));
        $user = new User(['branch_id' => 2, 'active' => true]);
        $user->setRelation('role', $role);
        $user->setRelation('branch', new Branch(['code' => 'EREB']));

        $service = app(PermissionService::class);
        $map = $service->mapFor($user);

        self::assertTrue($service->allows($user, 'reports.view'));
        self::assertTrue($service->allows($user, 'stock.create'));
        self::assertArrayNotHasKey('purchases.view', $map);
        self::assertArrayNotHasKey('suppliers.view', $map);
        self::assertArrayNotHasKey('audit.view', $map);
        self::assertArrayNotHasKey('products.edit', $map);
        self::assertFalse($service->allows($user, 'purchases.view'));
        self::assertFalse($service->allows($user, 'audit.view'));
    }

    public function test_branch_role_cannot_manually_adjust_stock_even_if_granted_by_mistake(): void
    {
        $role = new Role(['name' => 'branch']);
        $role->setRelation('permissions', collect([
            new Permission(['code' => 'stock.edit']),
            new Permission(['code' => 'stock.create']),
        ]));
        $user = new User(['branch_id' => 2, 'active' => true]);
        $user->setRelation('role', $role);
        $user->setRelation('branch', new Branch(['code' => 'EREB']));

        $service = app(PermissionService::class);

        self::assertFalse($service->allows($user, 'stock.edit'));
        self::assertArrayNotHasKey('stock.edit', $service->mapFor($user));
        self::assertTrue($service->allows($user, 'stock.create'));
    }

    public function test_admin_can_only_use_permissions_registered_in_the_catalog(): void
    {
        Schema::create('permissions', function ($table): void {
            $table->id();
            $table->string('code')->unique();
        });

        try {
            Permission::query()->create(['code' => 'stock.view']);
            $user = new User(['branch_id' => 0, 'active' => true]);
            $user->setRelation('role', new Role(['name' => 'admin']));
            $service = app(PermissionService::class);

            self::assertTrue($service->allows($user, 'stock.view'));
            self::assertFalse($service->allows($user, 'permission.that.does.not.exist'));
            self::assertSame(['stock.view' => true], $service->mapFor($user));
        } finally {
            Schema::dropIfExists('permissions');
        }
    }

    public function test_stale_permission_codes_are_not_authorized_or_exposed_to_the_ui(): void
    {
        $role = new Role(['name' => 'branch']);
        $role->setRelation('permissions', collect([
            new Permission(['code' => 'stock.view']),
            new Permission(['code' => 'stock.adjust']),
        ]));
        $user = new User(['branch_id' => 2, 'active' => true]);
        $user->setRelation('role', $role);
        $user->setRelation('branch', new Branch(['code' => 'EREB']));

        $service = app(PermissionService::class);

        self::assertTrue($service->allows($user, 'stock.view'));
        self::assertFalse($service->allows($user, 'stock.adjust'));
        self::assertSame(['stock.view' => true], $service->mapFor($user));
    }

    public function test_role_editor_may_preserve_existing_grants_outside_its_scope(): void
    {
        $user = new User(['branch_id' => 2, 'active' => true]);
        $role = new Role(['name' => 'branch_manager']);
        $role->setRelation('permissions', collect([new Permission(['code' => 'stock.view'])]));
        $user->setRelation('role', $role);
        $user->setRelation('branch', new Branch(['code' => 'EREB']));

        app(PermissionService::class)->assertAssignable(
            $user,
            ['stock.view', 'transfers.approve'],
            ['transfers.approve'],
        );

        self::assertTrue($user->hasPermissionCode('stock.view'));
    }

    public function test_role_editor_cannot_add_grants_outside_its_scope(): void
    {
        $user = new User(['branch_id' => 2, 'active' => true]);
        $role = new Role(['name' => 'branch_manager']);
        $role->setRelation('permissions', collect());
        $user->setRelation('role', $role);
        $user->setRelation('branch', new Branch(['code' => 'EREB']));

        $this->expectException(ValidationException::class);
        app(PermissionService::class)->assertAssignable($user, ['transfers.approve']);
    }

    public function test_actor_can_assign_only_roles_within_its_own_permission_scope(): void
    {
        $actor = new User(['branch_id' => 2, 'active' => true]);
        $actorRole = new Role(['name' => 'branch_manager']);
        $actorRole->setRelation('permissions', collect([
            new Permission(['code' => 'stock.view']),
            new Permission(['code' => 'users.view']),
        ]));
        $actor->setRelation('role', $actorRole);
        $actor->setRelation('branch', new Branch(['code' => 'EREB']));

        $allowedRole = new Role(['name' => 'stock_staff']);
        $allowedRole->setRelation('permissions', collect([new Permission(['code' => 'stock.view'])]));
        $widerRole = new Role(['name' => 'stock_admin']);
        $widerRole->setRelation('permissions', collect([
            new Permission(['code' => 'stock.view']),
            new Permission(['code' => 'stock.adjust']),
        ]));

        $service = app(PermissionService::class);
        self::assertTrue($service->canAssignRole($actor, $allowedRole));
        self::assertFalse($service->canAssignRole($actor, $widerRole));
    }

    public static function centralOnlyCapabilities(): array
    {
        return [
            'inventory approval' => ['inventory.approve'],
            'request approval' => ['requests.approve'],
            'transfer approval' => ['transfers.approve'],
            'purchase approval' => ['purchases.approve'],
            'central receipt creation' => ['receipts.create'],
        ];
    }
}
