<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\User;
use App\Repositories\DashboardRepository;
use App\Services\DashboardService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DashboardServicePermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->boolean('active');
        });
        Branch::query()->insert([
            ['id' => 4, 'name' => 'Էրեբունի', 'code' => 'EREB', 'active' => true],
            ['id' => 5, 'name' => 'Գյումրի', 'code' => 'GYUM', 'active' => true],
            ['id' => 6, 'name' => 'Փակված', 'code' => 'OFF', 'active' => false],
            ['id' => 7, 'name' => 'Կենտրոնական գրառում', 'code' => 'CENTRAL', 'active' => true],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('branches');

        parent::tearDown();
    }

    public function test_dashboard_summary_only_returns_metrics_the_actor_can_view(): void
    {
        $repository = Mockery::mock(DashboardRepository::class);
        $repository->shouldReceive('summary')->once()->with(4, false)->andReturn([
            'products' => 12,
            'units' => 42.5,
            'low_stock_products' => 2,
            'zero_stock_products' => 1,
            'expired_lots' => 3,
            'expiring_lots' => 4,
            'open_requests' => 8,
            'unapproved_requests' => 2,
            'awaiting_receipt_requests' => 1,
            'stock_value' => 500,
            'today' => ['receipts' => 3, 'issues' => 5, 'returns' => 1, 'transfers' => 2],
            'branches' => [['branch' => 'Other branch']],
        ]);

        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('currentLocationId')->once()->andReturn(4);
        $actor->shouldReceive('permissionMap')->once()->andReturn([
            'dashboard.view' => true,
            'requests.view' => true,
            'movements.view' => true,
        ]);

        $summary = (new DashboardService($repository))->summary($actor);

        self::assertSame(['open_requests' => 8, 'unapproved_requests' => 2, 'awaiting_receipt_requests' => 1], array_intersect_key($summary, array_flip([
            'open_requests', 'unapproved_requests', 'awaiting_receipt_requests',
        ])));
        self::assertArrayNotHasKey('products', $summary);
        self::assertArrayNotHasKey('units', $summary);
        self::assertArrayNotHasKey('low_stock_products', $summary);
        self::assertArrayNotHasKey('zero_stock_products', $summary);
        self::assertArrayNotHasKey('expired_lots', $summary);
        self::assertArrayNotHasKey('expiring_lots', $summary);
        self::assertArrayNotHasKey('branches', $summary);
        self::assertArrayNotHasKey('stock_value', $summary);
        self::assertSame(['issues' => 5], $summary['today']);
        self::assertSame(['id' => 4, 'name' => 'Էրեբունի'], $summary['selected_location']);
        self::assertSame([], $summary['location_options']);
        self::assertFalse($summary['can_select_location']);
    }

    public function test_authorized_central_actor_can_select_an_active_branch_without_changing_metric_permissions(): void
    {
        $repository = Mockery::mock(DashboardRepository::class);
        $repository->shouldReceive('summary')->once()->with(5, true)->andReturn([
            'units' => 12.5,
            'stock_value' => 250,
            'open_requests' => 4,
            'expired_lots' => 3,
            'today' => ['receipts' => 1, 'issues' => 2, 'returns' => 3, 'transfers' => 4],
        ]);
        $actor = $this->actor(0, ['branches.view' => true, 'stock.view' => true, 'purchases.view' => true, 'transfers.view' => true]);

        $summary = (new DashboardService($repository))->summary($actor, 5);

        self::assertSame(['id' => 5, 'name' => 'Գյումրի'], $summary['selected_location']);
        self::assertTrue($summary['can_select_location']);
        self::assertSame(12.5, $summary['units']);
        self::assertSame(250, $summary['stock_value']);
        self::assertArrayNotHasKey('open_requests', $summary);
        self::assertArrayNotHasKey('expired_lots', $summary);
        self::assertSame(['transfers' => 4], $summary['today']);
        self::assertSame([0, 5, 4], array_column($summary['location_options'], 'id'));
        self::assertSame('Կենտրոնական պահեստ', $summary['location_options'][0]['name']);
    }

    public function test_default_central_location_and_all_branch_summary_are_preserved(): void
    {
        $repository = Mockery::mock(DashboardRepository::class);
        $branches = [['branch' => 'Էրեբունի', 'stock_units' => 12]];
        $repository->shouldReceive('summary')->once()->with(0, false)->andReturn(['branches' => $branches, 'today' => []]);

        $summary = (new DashboardService($repository))->summary($this->actor(0, ['branches.view' => true, 'stock.view' => true]));

        self::assertSame($branches, $summary['branches']);
        self::assertSame(['id' => 0, 'name' => 'Կենտրոնական պահեստ'], $summary['selected_location']);
        self::assertTrue($summary['can_select_location']);
        self::assertArrayNotHasKey('today', $summary);
        self::assertCount(3, $summary['location_options']);
    }

    public function test_branch_overview_redacts_each_feature_metric_without_its_view_permission(): void
    {
        $identity = ['branch_id' => 4, 'branch' => 'Էրեբունի'];
        $stock = ['stock_units' => 12];
        $requests = ['open_requests' => 4, 'unapproved_requests' => 2, 'awaiting_receipt_requests' => 1];
        $transfers = ['awaiting_transfer_receipts' => 3];
        foreach ([
            [[], []],
            [['stock.view' => true], $stock],
            [['requests.view' => true], $requests],
            [['transfers.view' => true], $transfers],
        ] as [$permissions, $expectedMetrics]) {
            $repository = Mockery::mock(DashboardRepository::class);
            $repository->shouldReceive('summary')->once()->with(0, false)
                ->andReturn(['branches' => [$identity + $stock + $requests + $transfers]]);

            $summary = (new DashboardService($repository))->summary($this->actor(0, ['branches.view' => true] + $permissions));

            self::assertSame([$identity + $expectedMetrics], $summary['branches']);
            self::assertTrue($summary['can_select_location']);
            self::assertCount(3, $summary['location_options']);
        }
    }

    public function test_central_actor_without_branch_permission_can_explicitly_select_own_central_location(): void
    {
        $repository = Mockery::mock(DashboardRepository::class);
        $repository->shouldReceive('summary')->once()->with(0, false)->andReturn(['units' => 12]);

        $summary = (new DashboardService($repository))->summary($this->actor(0, ['stock.view' => true]), 0);

        self::assertSame(12, $summary['units']);
        self::assertSame(['id' => 0, 'name' => 'Կենտրոնական պահեստ'], $summary['selected_location']);
        self::assertFalse($summary['can_select_location']);
        self::assertSame([], $summary['location_options']);
    }

    public function test_scoped_actor_with_branch_permission_can_still_only_select_own_location(): void
    {
        $repository = Mockery::mock(DashboardRepository::class);
        $repository->shouldReceive('summary')->once()->with(4, false)->andReturn(['units' => 12]);

        $summary = (new DashboardService($repository))->summary($this->actor(4, ['branches.view' => true, 'stock.view' => true]), 4);

        self::assertSame(['id' => 4, 'name' => 'Էրեբունի'], $summary['selected_location']);
        self::assertSame(12, $summary['units']);
        self::assertFalse($summary['can_select_location']);
        self::assertSame([], $summary['location_options']);
    }

    public function test_scoped_and_unprivileged_actors_cannot_widen_their_dashboard_scope(): void
    {
        foreach ([[4, ['branches.view' => true], 0], [4, ['branches.view' => true], 5], [4, [], 999], [0, [], 5]] as [$ownLocation, $permissions, $selected]) {
            $repository = Mockery::mock(DashboardRepository::class);
            $repository->shouldNotReceive('summary');

            try {
                (new DashboardService($repository))->summary($this->actor($ownLocation, $permissions), $selected);
                self::fail('Another location must be rejected.');
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_authorized_selection_rejects_missing_inactive_and_central_branch_rows(): void
    {
        foreach ([999, 6, 7, -1] as $selected) {
            $repository = Mockery::mock(DashboardRepository::class);
            $repository->shouldNotReceive('summary');

            try {
                (new DashboardService($repository))->summary($this->actor(0, ['branches.view' => true]), $selected);
                self::fail('An invalid selection must be rejected.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('branch_id', $exception->errors());
                self::assertSame(422, $exception->status);
            }
        }
    }

    private function actor(int $location, array $permissions): User
    {
        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('currentLocationId')->once()->andReturn($location);
        $actor->shouldReceive('permissionMap')->once()->andReturn($permissions);

        return $actor;
    }
}
