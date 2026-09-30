<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Repositories\CatalogRepository;
use App\Services\CatalogService;
use App\Services\PermissionService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogUserRoleAssignmentTest extends TestCase
{
    public function test_store_rejects_a_role_with_grants_outside_actor_scope(): void
    {
        $actorRole = new Role(['name' => 'branch_manager']);
        $actorRole->setRelation('permissions', collect([new Permission(['code' => 'users.view'])]));
        $actor = new User(['active' => true, 'branch_id' => 2]);
        $actor->setRelation('role', $actorRole);
        $actor->setRelation('branch', new Branch(['code' => 'EREB']));

        $targetRole = new Role(['name' => 'central_approver']);
        $targetRole->setRelation('permissions', collect([
            new Permission(['code' => 'users.view']),
            new Permission(['code' => 'stock.adjust']),
        ]));

        $catalog = $this->createMock(CatalogRepository::class);
        $catalog->expects(self::once())->method('find')->with('roles', 7)->willReturn($targetRole);
        $catalog->expects(self::never())->method('create');

        $this->expectException(ValidationException::class);
        (new CatalogService($catalog, app(PermissionService::class)))->store($actor, '127.0.0.1', 'users', [
            'branch_id' => 2,
            'role_id' => 7,
        ]);
    }
}
