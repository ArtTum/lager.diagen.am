<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Repositories\NotificationRepository;
use App\Services\NotificationService;
use Mockery;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    public function test_branch_notifications_are_scoped_to_its_own_location(): void
    {
        $repository = Mockery::mock(NotificationRepository::class);
        $repository->shouldNotReceive('pendingTransfers');
        $repository->shouldReceive('pendingRequests')->once()->with(7)->andReturn(collect([
            (object) ['request_no' => 'ՊՀ-EREB-01', 'branch_name' => 'Էրեբունի'],
        ]));
        $repository->shouldReceive('shippedRequests')->once()->with(7)->andReturn(collect());
        $repository->shouldReceive('recentlyUpdatedRequests')->once()->with(7)->andReturn(collect());
        $repository->shouldReceive('activeInventories')->once()->with(7, false)->andReturn(collect());
        $repository->shouldReceive('incomingTransfers')->once()->with(7)->andReturn(collect());
        $repository->shouldReceive('readKeys')->once()->withArgs(fn (int $userId, array $keys): bool => $userId === 42 && count($keys) === 1)->andReturn([]);

        $service = new NotificationService($repository);
        $result = $service->index($this->branchUser(7, 42, [
            'requests.view', 'inventory.view', 'transfers.view',
        ]));

        self::assertSame(1, $result['unread_count']);
        self::assertSame('ՊՀ-EREB-01 · Էրեբունի · ստուգման սպասող', $result['data'][0]['detail']);
        self::assertSame('/requests', $result['data'][0]['link']);
    }

    public function test_central_inventory_approval_permission_controls_cross_branch_notifications(): void
    {
        $repository = Mockery::mock(NotificationRepository::class);
        $repository->shouldNotReceive('pendingRequests');
        $repository->shouldReceive('activeInventories')->once()->with(0, true)->andReturn(collect());
        $repository->shouldNotReceive('pendingTransfers');
        $repository->shouldReceive('shippedRequests')->once()->with(0)->andReturn(collect());
        $repository->shouldReceive('recentlyUpdatedRequests')->once()->with(0)->andReturn(collect());
        $repository->shouldReceive('incomingTransfers')->once()->with(0)->andReturn(collect());
        $repository->shouldReceive('readKeys')->once()->with(42, [])->andReturn([]);

        $service = new NotificationService($repository);
        $service->index($this->branchUser(0, 42, [
            'inventory.view', 'inventory.approve', 'requests.view', 'transfers.view',
        ]));
    }

    public function test_central_pending_transfer_notifications_require_approval_permission(): void
    {
        $repository = Mockery::mock(NotificationRepository::class);
        $repository->shouldNotReceive('pendingTransfers');
        $repository->shouldNotReceive('pendingRequests');
        $repository->shouldReceive('shippedRequests')->once()->with(0)->andReturn(collect());
        $repository->shouldReceive('recentlyUpdatedRequests')->once()->with(0)->andReturn(collect());
        $repository->shouldReceive('incomingTransfers')->once()->with(0)->andReturn(collect());
        $repository->shouldReceive('readKeys')->once()->with(42, [])->andReturn([]);

        $service = new NotificationService($repository);
        $result = $service->index($this->branchUser(0, 42, ['requests.view', 'transfers.view']));

        self::assertSame([], $result['data']);
        self::assertSame(0, $result['unread_count']);
    }

    /** @param list<string> $permissions */
    private function branchUser(int $branchId, int $userId, array $permissions): User
    {
        $role = new Role(['name' => 'branch']);
        $role->setRelation('permissions', collect(array_map(
            static fn (string $code): Permission => new Permission(['code' => $code]),
            $permissions,
        )));

        $user = new User(['branch_id' => $branchId, 'active' => true]);
        $user->setAttribute('id', $userId);
        $user->setRelation('role', $role);
        $user->setRelation('branch', new Branch(['code' => $branchId === 0 ? 'CENTRAL' : 'EREB']));

        return $user;
    }
}
