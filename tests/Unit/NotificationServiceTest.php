<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\AuditLog;
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
            (object) ['id' => 1, 'request_no' => 'ՊՀ-EREB-01', 'branch_name' => 'Էրեբունի', 'created_at' => now()],
        ]));
        $repository->shouldReceive('shippedRequests')->once()->with(7)->andReturn(collect());
        $repository->shouldReceive('requestEvents')->once()->with(7, 42)->andReturn(collect());
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
        $repository->shouldReceive('pendingRequests')->once()->with(0)->andReturn(collect());
        $repository->shouldReceive('activeInventories')->once()->with(0, true)->andReturn(collect());
        $repository->shouldNotReceive('pendingTransfers');
        $repository->shouldReceive('shippedRequests')->once()->with(0)->andReturn(collect());
        $repository->shouldReceive('requestEvents')->once()->with(0, 42)->andReturn(collect());
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
        $repository->shouldReceive('pendingRequests')->once()->with(0)->andReturn(collect());
        $repository->shouldReceive('shippedRequests')->once()->with(0)->andReturn(collect());
        $repository->shouldReceive('requestEvents')->once()->with(0, 42)->andReturn(collect());
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
        $eventKey = sha1('stock_requests|event|80');
        $repository = Mockery::mock(NotificationRepository::class);
        $repository->shouldReceive('pendingRequests')->once()->with(7)->andReturn(collect());
        $repository->shouldReceive('shippedRequests')->once()->with(7)->andReturn(collect());
        $repository->shouldReceive('requestEvents')->once()->with(7, 42)->andReturn(collect([
            $this->event($status, $requestNumber, $reason),
        ]));
        $repository->shouldReceive('readKeys')->once()->with(42, [$eventKey, $legacyKey])->andReturn([$legacyKey]);

        $result = (new NotificationService($repository))->index($this->branchUser(7, 42, ['requests.view']));

        self::assertCount(1, $result['data']);
        self::assertSame($expectedTitle, $result['data'][0]['title']);
        self::assertSame($requestNumber.' · Էրեբունի · '.$expectedDetail, $result['data'][0]['detail']);
        self::assertSame($eventKey, $result['data'][0]['key']);
        self::assertTrue($result['data'][0]['read']);
        self::assertSame(0, $result['unread_count']);
    }

    public static function updatedRequestNotifications(): array
    {
        return [
            'full approval' => ['approved', '', 'Պահանջագիրը հաստատվել է', 'Հաստատված է', 'Պահանջագիրը հաստատվել է'],
            'partial approval' => ['partially_approved', '', 'Պահանջագիրը մասնակի է հաստատվել', 'Մասնակի է հաստատված', 'Պահանջագիրը հաստատվել է'],
            'receipt confirmed' => ['received', '', 'Պահանջագրի ստացումը հաստատվել է', 'Ստացումը հաստատված է', 'Պահանջագրի ընթացքը թարմացվել է'],
            'rejection without reason' => ['rejected', '', 'Պահանջագիրը մերժվել է', 'Մերժված է', 'Պահանջագիրը մերժվել է'],
            'rejection with reason' => ['rejected', 'Ապրանքի պաշարը չի բավարարում։', 'Պահանջագիրը մերժվել է', 'Ապրանքի պաշարը չի բավարարում։', 'Պահանջագիրը մերժվել է'],
        ];
    }

    public function test_partial_approval_event_can_be_marked_read_with_its_stable_event_key(): void
    {
        $legacyKey = sha1('Պահանջագիրը հաստատվել է|ՊՀ-EREB-PARTIAL · partially_approved|/requests');
        $eventKey = sha1('stock_requests|event|80');
        $repository = Mockery::mock(NotificationRepository::class);
        $repository->shouldReceive('pendingRequests')->once()->with(7)->andReturn(collect());
        $repository->shouldReceive('shippedRequests')->once()->with(7)->andReturn(collect());
        $repository->shouldReceive('requestEvents')->once()->with(7, 42)->andReturn(collect([
            $this->event('partially_approved', 'ՊՀ-EREB-PARTIAL'),
        ]));
        $repository->shouldReceive('readKeys')->once()->with(42, [$eventKey, $legacyKey])->andReturn([]);
        $repository->shouldReceive('markRead')->once()->with(42, $eventKey);
        $repository->shouldReceive('markRead')->once()->with(42, $legacyKey);

        (new NotificationService($repository))->markRead($this->branchUser(7, 42, ['requests.view']), $eventKey);
    }

    public function test_new_requests_precede_stock_alerts_and_survive_the_feed_limit_for_central_viewers_without_approval(): void
    {
        $repository = Mockery::mock(NotificationRepository::class);
        $repository->shouldReceive('currentStock')->with(0)->andReturn(collect(range(1, 350))->map(fn ($i) => (object) [
            'code' => 'P'.$i, 'name' => 'Product '.$i, 'qty' => 0, 'min_qty' => 5, 'max_qty' => 10,
        ]));
        $repository->shouldReceive('requestEvents')->with(0, 42)->andReturn(collect());
        $repository->shouldReceive('pendingRequests')->with(0)->andReturn(collect([
            (object) ['id' => 1, 'request_no' => 'NEW-EREB', 'branch_name' => 'Էրեբունի', 'created_at' => now()],
        ]));
        $repository->shouldReceive('shippedRequests')->with(0)->andReturn(collect());
        $repository->shouldReceive('readKeys')->andReturn([]);
        $result = (new NotificationService($repository))->index($this->branchUser(0, 42, ['stock.view', 'requests.view']));
        self::assertCount(300, $result['data']);
        self::assertSame('/requests', $result['data'][0]['link']);
        self::assertStringContainsString('NEW-EREB', $result['data'][0]['detail']);
    }

    private function event(string $status, string $number, string $reason = ''): AuditLog
    {
        $event = new AuditLog(['entity_id' => 1, 'after_data' => ['status' => $status, 'rejection_reason' => $reason], 'created_at' => now()]);
        $event->forceFill(['id' => 80, 'request_no' => $number, 'branch_name' => 'Էրեբունի', 'rejection_reason' => $reason]);
        return $event;
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
