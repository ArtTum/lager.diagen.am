<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Repositories\NotificationRepository;
use App\Services\NotificationService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('updatedRequestNotifications')]
    public function test_request_notifications_use_clear_labels_and_preserve_existing_read_identity(
        string $status,
        string $reason,
        string $expectedTitle,
        string $expectedDetail,
        string $legacyTitle,
    ): void {
        $requestNumber = 'ՊՀ-EREB-UPDATED';
        $legacyKey = sha1($legacyTitle.'|'.$requestNumber.' · '.($reason ?: $status).'|/requests');
        $repository = Mockery::mock(NotificationRepository::class);
        $repository->shouldReceive('pendingRequests')->once()->with(7)->andReturn(collect());
        $repository->shouldReceive('shippedRequests')->once()->with(7)->andReturn(collect());
        $repository->shouldReceive('recentlyUpdatedRequests')->once()->with(7)->andReturn(collect([
            (object) ['request_no' => $requestNumber, 'status' => $status, 'rejection_reason' => $reason],
        ]));
        $repository->shouldReceive('readKeys')->once()->with(42, [$legacyKey])->andReturn([$legacyKey]);

        $result = (new NotificationService($repository))->index($this->branchUser(7, 42, ['requests.view']));

        self::assertCount(1, $result['data']);
        self::assertSame($expectedTitle, $result['data'][0]['title']);
        self::assertSame($requestNumber.' · '.$expectedDetail, $result['data'][0]['detail']);
        self::assertSame($legacyKey, $result['data'][0]['key']);
        self::assertTrue($result['data'][0]['read']);
        self::assertSame(0, $result['unread_count']);
    }

    public static function updatedRequestNotifications(): array
    {
        return [
            'full approval' => ['approved', '', 'Պահանջագիրը հաստատվել է', 'Հաստատված է', 'Պահանջագիրը հաստատվել է'],
            'partial approval' => ['partially_approved', '', 'Պահանջագիրը մասնակի է հաստատվել', 'Մասնակի է հաստատված', 'Պահանջագիրը հաստատվել է'],
            'receipt confirmed' => ['received', '', 'Պահանջագրի ընթացքը թարմացվել է', 'Ստացումը հաստատված է', 'Պահանջագրի ընթացքը թարմացվել է'],
            'rejection without reason' => ['rejected', '', 'Պահանջագիրը մերժվել է', 'Մերժված է', 'Պահանջագիրը մերժվել է'],
            'rejection with reason' => ['rejected', 'Ապրանքի պաշարը չի բավարարում։', 'Պահանջագիրը մերժվել է', 'Ապրանքի պաշարը չի բավարարում։', 'Պահանջագիրը մերժվել է'],
        ];
    }

    public function test_partial_approval_notification_accepts_the_legacy_key_when_marking_read(): void
    {
        $legacyKey = sha1('Պահանջագիրը հաստատվել է|ՊՀ-EREB-PARTIAL · partially_approved|/requests');
        $repository = Mockery::mock(NotificationRepository::class);
        $repository->shouldReceive('pendingRequests')->once()->with(7)->andReturn(collect());
        $repository->shouldReceive('shippedRequests')->once()->with(7)->andReturn(collect());
        $repository->shouldReceive('recentlyUpdatedRequests')->once()->with(7)->andReturn(collect([
            (object) ['request_no' => 'ՊՀ-EREB-PARTIAL', 'status' => 'partially_approved', 'rejection_reason' => ''],
        ]));
        $repository->shouldReceive('readKeys')->once()->with(42, [$legacyKey])->andReturn([]);
        $repository->shouldReceive('markRead')->once()->with(42, $legacyKey);

        (new NotificationService($repository))->markRead($this->branchUser(7, 42, ['requests.view']), $legacyKey);
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
