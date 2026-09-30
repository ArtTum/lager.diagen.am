<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Repositories\CatalogRepository;
use App\Services\CatalogService;
use App\Services\PermissionService;
use Tests\TestCase;

class CatalogOptionPermissionTest extends TestCase
{
    public function test_branch_operational_options_do_not_disclose_supplier_catalog_without_permission(): void
    {
        $repository = $this->createMock(CatalogRepository::class);
        $repository->expects(self::once())->method('options')->with('stock', false)->willReturn([
            'suppliers' => collect([(object) ['id' => 1, 'name' => 'Private supplier']]),
            'branches' => collect([(object) ['id' => 2], (object) ['id' => 3]]),
        ]);
        $actor = $this->actor(2, 'EREB', ['stock.view']);

        $options = (new CatalogService($repository, app(PermissionService::class)))->options('stock', $actor);

        self::assertCount(0, $options['suppliers']);
        self::assertSame([2], $options['branches']->pluck('id')->all());
    }

    public function test_purchase_or_supplier_view_keeps_supplier_options_available(): void
    {
        $repository = $this->createMock(CatalogRepository::class);
        $repository->expects(self::once())->method('options')->with('stock', true)->willReturn([
            'suppliers' => collect([(object) ['id' => 1, 'name' => 'Permitted supplier']]),
            'branches' => collect(),
        ]);
        $actor = $this->actor(0, 'CENTRAL', ['purchases.view']);

        $options = (new CatalogService($repository, app(PermissionService::class)))->options('stock', $actor);

        self::assertSame('Permitted supplier', $options['suppliers']->sole()->name);
    }

    /** @param list<string> $codes */
    private function actor(int $location, string $branchCode, array $codes): User
    {
        $role = new Role(['name' => 'options_test']);
        $role->setRelation('permissions', collect(array_map(
            static fn (string $code): Permission => new Permission(['code' => $code]),
            $codes,
        )));
        $actor = new User(['active' => true, 'branch_id' => $location]);
        $actor->setRelation('role', $role);
        $actor->setRelation('branch', new Branch(['code' => $branchCode]));

        return $actor;
    }
}
