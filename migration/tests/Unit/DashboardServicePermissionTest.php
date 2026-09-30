<?php

namespace Tests\Unit;

use App\Models\User;
use App\Repositories\DashboardRepository;
use App\Services\DashboardService;
use Mockery;
use Tests\TestCase;

class DashboardServicePermissionTest extends TestCase
{
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
        self::assertSame(['issues' => 5], $summary['today']);
    }
}
