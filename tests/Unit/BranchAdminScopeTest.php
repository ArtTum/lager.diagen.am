<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Movement;
use App\Models\Role;
use App\Models\StockRequest;
use App\Models\Transfer;
use App\Models\User;
use App\Repositories\CatalogRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\MovementRepository;
use App\Repositories\PurchasingRepository;
use App\Repositories\ReturnRepository;
use App\Repositories\StockRepository;
use App\Repositories\StockRequestRepository;
use App\Repositories\TransferRepository;
use App\Services\CatalogService;
use App\Services\InventoryService;
use App\Services\MovementService;
use App\Services\PermissionService;
use App\Services\PurchasingService;
use App\Services\ReturnService;
use App\Services\StockRequestService;
use App\Services\TransferService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BranchAdminScopeTest extends TestCase
{
    public function test_branch_admin_cannot_approve_central_transfer(): void
    {
        $this->expectForbidden(fn () => (new TransferService($this->createMock(TransferRepository::class)))
            ->approve(1, $this->branchAdmin(), '127.0.0.1'));
    }

    public function test_branch_admin_cannot_ship_a_transfer_from_another_branch(): void
    {
        $transfers = $this->createMock(TransferRepository::class);
        $transfers->method('lock')->with(1)->willReturn(new Transfer([
            'id' => 1, 'status' => 'approved', 'from_branch' => 3, 'to_branch' => 4,
        ]));

        $this->expectForbidden(fn () => (new TransferService($transfers))->ship(1, $this->branchAdmin(), '127.0.0.1'));
    }

    public function test_branch_admin_cannot_receive_a_transfer_sent_to_another_branch(): void
    {
        $transfers = $this->createMock(TransferRepository::class);
        $transfers->method('lock')->with(1)->willReturn(new Transfer([
            'id' => 1, 'status' => 'shipped', 'from_branch' => 3, 'to_branch' => 4,
        ]));
        $transfers->method('stockLocation')->with(4)->willReturn(4);

        $this->expectForbidden(fn () => (new TransferService($transfers))->receive(1, $this->branchAdmin(), '127.0.0.1'));
    }

    public function test_central_non_admin_cannot_receive_a_transfer_for_another_branch(): void
    {
        $transfers = $this->createMock(TransferRepository::class);
        $transfers->method('lock')->with(1)->willReturn(new Transfer([
            'id' => 1, 'status' => 'shipped', 'from_branch' => 2, 'to_branch' => 3,
        ]));
        $transfers->method('stockLocation')->with(3)->willReturn(3);
        $actor = new User(['id' => 11, 'active' => true, 'branch_id' => null]);
        $actor->setRelation('role', new Role(['name' => 'storekeeper']));

        $this->expectForbidden(fn () => (new TransferService($transfers))->receive(1, $actor, '127.0.0.1'));
    }

    public function test_branch_admin_cannot_review_central_request(): void
    {
        $this->expectForbidden(fn () => (new StockRequestService($this->createMock(StockRequestRepository::class)))
            ->review(1, $this->branchAdmin(), '127.0.0.1', ['decision' => 'start_review']));
    }

    public function test_central_non_admin_cannot_cancel_a_branch_request(): void
    {
        $requests = $this->createMock(StockRequestRepository::class);
        $requests->method('lock')->with(1)->willReturn(new StockRequest([
            'id' => 1, 'branch_id' => 2, 'status' => 'sent', 'requested_by' => 10,
        ]));
        $actor = new User(['id' => 11, 'active' => true, 'branch_id' => null]);
        $actor->setRelation('role', new Role(['name' => 'storekeeper']));

        $this->expectForbidden(fn () => (new StockRequestService($requests))->transition(1, 'cancel', $actor, '127.0.0.1'));
    }

    public function test_central_non_admin_cannot_receive_a_branch_request(): void
    {
        $requests = $this->createMock(StockRequestRepository::class);
        $requests->method('lock')->with(1)->willReturn(new StockRequest([
            'id' => 1, 'branch_id' => 2, 'status' => 'shipped', 'requested_by' => 10,
        ]));
        $actor = new User(['id' => 11, 'active' => true, 'branch_id' => null]);
        $actor->setRelation('role', new Role(['name' => 'storekeeper']));

        $this->expectForbidden(fn () => (new StockRequestService($requests))->transition(1, 'receive', $actor, '127.0.0.1'));
    }

    public function test_branch_admin_cannot_approve_central_purchase(): void
    {
        $this->expectForbidden(fn () => (new PurchasingService($this->createMock(PurchasingRepository::class)))
            ->approve(1, $this->branchAdmin(), '127.0.0.1'));
    }

    public function test_branch_admin_cannot_start_inventory_at_another_location(): void
    {
        $service = new InventoryService(
            $this->createMock(InventoryRepository::class),
            $this->createMock(StockRepository::class),
        );

        $this->expectForbidden(fn () => $service->start($this->branchAdmin(), '127.0.0.1', ['location_id' => 1]));
    }

    public function test_branch_admin_cannot_return_stock_to_supplier_from_central_location(): void
    {
        $service = new ReturnService(
            $this->createMock(ReturnRepository::class),
            $this->createMock(StockRepository::class),
            $this->createMock(TransferRepository::class),
        );

        $this->expectForbidden(fn () => $service->create($this->branchAdmin(), '127.0.0.1', [
            'direction' => 'central_to_supplier',
        ]));
    }

    public function test_branch_admin_cannot_reverse_another_branch_movement(): void
    {
        $movements = $this->createMock(MovementRepository::class);
        $movements->method('locked')->with(1)->willReturn(new Movement([
            'id' => 1, 'type' => 'consumption', 'from_location' => 1, 'to_location' => null,
            'product_id' => 1, 'lot_id' => 1, 'qty' => 1, 'unit_cost' => 0,
        ]));
        $movements->method('correctionExists')->with(1)->willReturn(false);
        $service = new MovementService(
            $movements,
            $this->createMock(StockRepository::class),
            $this->createMock(TransferRepository::class),
        );

        $this->expectForbidden(fn () => $service->reverse($this->branchAdmin(), '127.0.0.1', 1, ['reason' => 'wrong branch']));
    }

    public function test_branch_admin_cannot_edit_another_branch_user(): void
    {
        $catalog = $this->createMock(CatalogRepository::class);
        $catalog->expects(self::once())->method('find')->with('users', 3)
            ->willReturn(new User(['id' => 3, 'branch_id' => 1]));
        $service = new CatalogService($catalog, app(PermissionService::class));

        $this->expectForbidden(fn () => $service->update($this->branchAdmin(), '127.0.0.1', 'users', 3, ['name' => 'Other branch']));
    }

    public function test_branch_admin_cannot_create_a_new_branch(): void
    {
        $catalog = $this->createMock(CatalogRepository::class);
        $catalog->expects(self::never())->method('create');
        $service = new CatalogService($catalog, app(PermissionService::class));

        $this->expectForbidden(fn () => $service->store($this->branchAdmin(), '127.0.0.1', 'branches', ['name' => 'New branch']));
    }

    public function test_branch_admin_cannot_deactivate_its_own_branch(): void
    {
        $catalog = $this->createMock(CatalogRepository::class);
        $branch = new Branch(['code' => 'EREB', 'active' => true]);
        $branch->setAttribute('id', 2);
        $catalog->expects(self::once())->method('find')->with('branches', 2)->willReturn($branch);
        $service = new CatalogService($catalog, app(PermissionService::class));

        $this->expectException(ValidationException::class);
        $service->deactivate($this->branchAdmin(), '127.0.0.1', 'branches', 2);
    }

    private function branchAdmin(): User
    {
        $user = new User(['id' => 10, 'active' => true, 'branch_id' => 2]);
        $user->setRelation('role', new Role(['name' => 'admin']));
        $user->setRelation('branch', new Branch(['code' => 'EREB']));

        return $user;
    }

    private function expectForbidden(callable $action): void
    {
        try {
            $action();
            self::fail('Expected the branch-scoped administrator to be forbidden.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }
}
