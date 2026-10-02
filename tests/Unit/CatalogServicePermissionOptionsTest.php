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

class CatalogServicePermissionOptionsTest extends TestCase
{
    public function test_user_options_hide_roles_the_actor_cannot_assign(): void
    {
        $actorRole = new Role(['name' => 'branch_manager']);
        $actorRole->setRelation('permissions', collect([new Permission(['code' => 'users.view'])]));
        $actor = new User(['active' => true, 'branch_id' => 0]);
        $actor->setRelation('role', $actorRole);

        $allowed = new Role(['name' => 'branch_staff', 'title' => 'Branch staff']);
        $allowed->setAttribute('id', 1);
        $allowed->setRelation('permissions', collect([
            new Permission(['code' => 'users.view']),
            new Permission(['code' => 'legacy.retired_action']),
        ]));
        $forbidden = new Role(['name' => 'branch_admin', 'title' => 'Branch admin']);
        $forbidden->setAttribute('id', 2);
        $forbidden->setRelation('permissions', collect([
            new Permission(['code' => 'users.view']),
            new Permission(['code' => 'stock.edit']),
        ]));

        $catalog = $this->createMock(CatalogRepository::class);
        $catalog->method('options')->with('users', false)->willReturn([
            'branches' => collect(),
            'roles' => collect([$allowed, $forbidden]),
        ]);

        $options = (new CatalogService($catalog, app(PermissionService::class)))->options('users', $actor);

        self::assertSame([1], $options['roles']->pluck('id')->all());
    }

    public function test_role_editor_only_receives_permissions_the_actor_can_assign(): void
    {
        $role = new Role(['name' => 'branch_manager']);
        $role->setRelation('permissions', collect([
            new Permission(['code' => 'stock.view']),
            // Even if misconfigured on the role, branch-only users cannot delegate this.
            new Permission(['code' => 'transfers.approve']),
        ]));
        $actor = new User(['active' => true, 'branch_id' => 2]);
        $actor->setRelation('role', $role);
        $actor->setRelation('branch', new Branch(['code' => 'EREB']));

        $catalog = $this->createMock(CatalogRepository::class);
        $catalog->expects(self::once())
            ->method('options')
            ->with('roles', false)
            ->willReturn(['branches' => collect([
                (object) ['id' => 2, 'name' => 'Erebuni'],
                (object) ['id' => 3, 'name' => 'Gyumri'],
            ]), 'permissions' => collect([
                new Permission(['code' => 'stock.view']),
                new Permission(['code' => 'transfers.approve']),
                new Permission(['code' => 'purchases.view']),
            ])]);

        $options = (new CatalogService($catalog, app(PermissionService::class)))->options('roles', $actor);

        self::assertSame(['stock.view'], $options['permissions']->pluck('code')->all());
        self::assertSame([2], $options['branches']->pluck('id')->all());
    }
}
