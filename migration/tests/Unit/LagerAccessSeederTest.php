<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\PermissionCatalog;
use Database\Seeders\LagerAccessSeeder;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LagerAccessSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('roles', function ($table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('title');
        });
        Schema::create('branches', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('branches');
        parent::tearDown();
    }

    public function test_bootstrap_seeds_the_legacy_role_matrix_and_preserves_existing_custom_grants(): void
    {
        $seeder = new LagerAccessSeeder;
        $seeder->run();

        self::assertSame(count(PermissionCatalog::definitions()), Permission::query()->count());
        self::assertSame(6, Role::query()->count());
        $branch = Role::query()->where('name', 'branch')->firstOrFail();
        self::assertTrue($branch->permissions()->where('code', 'requests.create')->exists());
        self::assertFalse($branch->permissions()->whereIn('code', ['requests.approve', 'transfers.approve', 'inventory.approve', 'stock.edit'])->exists());
        $finance = Role::query()->where('name', 'finance')->firstOrFail();
        self::assertTrue($finance->permissions()->where('code', 'purchases.approve')->exists());
        self::assertTrue($finance->permissions()->where('code', 'reports.export')->exists());
        self::assertFalse($finance->permissions()->where('code', 'stock.create')->exists());
        $viewer = Role::query()->where('name', 'viewer')->firstOrFail();
        self::assertFalse($viewer->permissions()->where('code', 'reports.export')->exists());

        $custom = Permission::query()->where('code', 'roles.create')->firstOrFail();
        $finance->permissions()->sync([$custom->id]);
        $seeder->run();

        self::assertSame([$custom->id], $finance->fresh()->permissions()->pluck('permissions.id')->all());
    }

    public function test_fresh_standard_roles_receive_the_exact_intended_permission_sets(): void
    {
        (new LagerAccessSeeder)->run();

        $all = PermissionCatalog::codes();
        $expected = [
            'admin' => $all,
            'manager' => array_values(array_filter($all, static fn (string $code): bool => ! str_ends_with($code, '.delete')
                && ! in_array($code, ['users.create', 'users.edit', 'roles.create', 'roles.edit'], true))),
            'storekeeper' => [
                'dashboard.view', 'products.view', 'products.export', 'stock.view', 'stock.create', 'stock.export',
                'receipts.view', 'receipts.create', 'purchases.view', 'purchases.create',
                'requests.view', 'requests.create', 'requests.edit', 'requests.export',
                'movements.view', 'movements.edit', 'movements.export',
                'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.export',
                'expiry.view', 'expiry.export', 'returns.view', 'returns.create', 'returns.export',
                'transfers.view', 'transfers.create', 'transfers.edit', 'transfers.export',
                'notifications.view', 'reports.view', 'reports.export',
            ],
            'branch' => PermissionCatalog::branchAllowed(),
            'finance' => [
                'dashboard.view', 'suppliers.view', 'products.view', 'products.export',
                'purchases.view', 'purchases.approve', 'purchases.export',
                'receipts.view', 'receipts.export', 'stock.view', 'stock.export',
                'movements.view', 'movements.export', 'expiry.view', 'expiry.export',
                'returns.view', 'returns.export', 'notifications.view', 'reports.view', 'reports.export',
            ],
            'viewer' => array_values(array_filter($all, static fn (string $code): bool => str_ends_with($code, '.view'))),
        ];

        foreach ($expected as $roleName => $expectedCodes) {
            $actualCodes = Role::query()->where('name', $roleName)->firstOrFail()
                ->permissions()->pluck('code')->all();

            self::assertEqualsCanonicalizing(
                $expectedCodes,
                $actualCodes,
                "The {$roleName} role must have exactly its documented permissions, with no missing or extra grants.",
            );
        }
    }

    public function test_each_seeded_role_exposes_only_its_expected_backend_capabilities(): void
    {
        (new LagerAccessSeeder)->run();
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB']);
        $service = app(PermissionService::class);

        $expectations = [
            'admin' => [0, ['audit.export', 'users.create', 'roles.edit']],
            'manager' => [0, ['dashboard.view', 'purchases.approve', 'reports.export', 'roles.view']],
            'storekeeper' => [0, ['stock.view', 'receipts.create', 'movements.export']],
            'branch' => [$branch->id, ['requests.create', 'stock.create', 'inventory.view', 'reports.export']],
            'finance' => [0, ['purchases.approve', 'reports.export', 'suppliers.view']],
            'viewer' => [0, ['dashboard.view', 'stock.view']],
        ];

        foreach ($expectations as $roleName => [$location, $allowed]) {
            $user = new User(['active' => true, 'branch_id' => $location ?: null]);
            $user->setRelation('role', Role::query()->where('name', $roleName)->firstOrFail()->load('permissions'));
            if ($location) {
                $user->setRelation('branch', $branch);
            }
            $map = $service->mapFor($user);

            foreach ($allowed as $code) {
                self::assertArrayHasKey($code, $map, "{$roleName} should have {$code}");
            }
        }

        $admin = Role::query()->where('name', 'admin')->firstOrFail()->load('permissions');
        $adminUser = new User(['active' => true]);
        $adminUser->setRelation('role', $admin);
        self::assertArrayNotHasKey('audit.delete', $service->mapFor($adminUser));

        $branchRole = Role::query()->where('name', 'branch')->firstOrFail()->load('permissions');
        $branchUser = new User(['active' => true, 'branch_id' => $branch->id]);
        $branchUser->setRelation('branch', $branch);
        $branchUser->setRelation('role', $branchRole);
        $branchMap = $service->mapFor($branchUser);
        foreach (['products.view', 'purchases.approve', 'transfers.approve', 'audit.view'] as $code) {
            self::assertArrayNotHasKey($code, $branchMap, "branch should not have {$code}");
        }

        $viewer = Role::query()->where('name', 'viewer')->firstOrFail()->load('permissions');
        $viewerUser = new User(['active' => true]);
        $viewerUser->setRelation('role', $viewer);
        $viewerMap = $service->mapFor($viewerUser);
        foreach (['stock.create', 'reports.export', 'users.edit'] as $code) {
            self::assertArrayNotHasKey($code, $viewerMap, "viewer should not have {$code}");
        }
    }
}
