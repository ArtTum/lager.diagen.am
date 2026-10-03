<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Movement;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Receipt;
use App\Models\StockLot;
use App\Models\StockRequest;
use App\Models\Transfer;
use App\Repositories\DashboardRepository;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 12:00:00');

        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->boolean('active');
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->boolean('active');
            $table->decimal('min_qty', 12, 3)->default(0);
        });
        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->string('lot_no');
            $table->date('received_on');
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 14, 2);
            $table->date('expires_on')->nullable();
        });
        Schema::create('stock_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('status');
        });
        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();
            $table->date('received_on');
        });
        Schema::create('movements', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->unsignedBigInteger('from_location')->nullable();
            $table->unsignedBigInteger('to_location')->nullable();
            $table->decimal('qty', 12, 3)->default(1);
            $table->dateTime('happened_at');
        });
        Schema::create('movement_corrections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('movement_id');
        });
        Schema::create('returns', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('from_location');
            $table->dateTime('created_at');
        });
        Schema::create('transfers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('from_branch');
            $table->unsignedBigInteger('to_branch');
            $table->string('status');
            $table->dateTime('created_at');
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (['transfers', 'returns', 'movement_corrections', 'movements', 'receipts', 'stock_requests', 'stock_lots', 'products', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_central_summary_includes_stock_risk_today_activity_and_branch_workload(): void
    {
        Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $inactiveBranch = Branch::query()->create(['name' => 'Disabled', 'code' => 'OFF', 'active' => false]);
        $product = Product::query()->create(['active' => true, 'min_qty' => 5]);
        Product::query()->create(['active' => true, 'min_qty' => 0]);
        StockLot::query()->create(['product_id' => $product->id, 'location_id' => 0, 'lot_no' => 'CENTRAL-A', 'received_on' => '2026-09-01', 'qty' => 3, 'unit_cost' => 100, 'expires_on' => '2026-10-05']);
        StockLot::query()->create(['product_id' => $product->id, 'location_id' => 0, 'lot_no' => 'CENTRAL-B', 'received_on' => '2026-09-01', 'qty' => 1, 'unit_cost' => 50, 'expires_on' => '2026-09-29']);
        StockLot::query()->create(['product_id' => $product->id, 'location_id' => $branch->id, 'lot_no' => 'BRANCH-A', 'received_on' => '2026-09-01', 'qty' => 8, 'unit_cost' => 100, 'expires_on' => '2027-01-01']);
        foreach (['sent', 'shipped', 'draft'] as $status) {
            StockRequest::query()->create(['branch_id' => $branch->id, 'status' => $status]);
        }
        StockRequest::query()->create(['branch_id' => $inactiveBranch->id, 'status' => 'sent']);
        Receipt::query()->create(['received_on' => '2026-09-30']);
        Receipt::query()->create(['received_on' => '2026-09-29']);
        Movement::query()->create(['type' => 'branch_out', 'from_location' => 0, 'to_location' => $branch->id, 'happened_at' => '2026-09-30 09:00:00']);
        ProductReturn::query()->create(['from_location' => 0, 'created_at' => '2026-09-30 10:00:00']);
        Transfer::query()->create(['from_branch' => 0, 'to_branch' => $branch->id, 'status' => 'shipped', 'created_at' => '2026-09-30 11:00:00']);

        $summary = (new DashboardRepository)->summary(0, true);

        self::assertSame(1, $summary['products']);
        self::assertEquals(4.0, $summary['units']);
        self::assertEquals(350.0, $summary['stock_value']);
        self::assertSame(1, $summary['low_stock_products']);
        self::assertSame(1, $summary['zero_stock_products']);
        self::assertSame(1, $summary['expired_lots']);
        self::assertSame(1, $summary['expiring_lots']);
        self::assertSame(['receipts' => 1, 'issues' => 1, 'returns' => 1, 'transfers' => 1], $summary['today']);
        self::assertCount(1, $summary['branches']);
        self::assertSame($branch->id, $summary['branches'][0]['branch_id']);
        self::assertSame(3, $summary['branches'][0]['open_requests']);
        self::assertSame(1, $summary['branches'][0]['unapproved_requests']);
        self::assertSame(1, $summary['branches'][0]['awaiting_receipt_requests']);
        self::assertSame(1, $summary['branches'][0]['awaiting_transfer_receipts']);
    }

    public function test_daily_chart_counts_only_the_workflow_phase_that_changes_each_local_stock(): void
    {
        $this->movement('branch_out', 0, 4, '2026-09-25 09:00:00');
        $this->movement('branch_in', 0, 4, '2026-09-26 09:00:00');
        $this->movement('transfer_sent', 4, 5, '2026-09-27 09:00:00');
        $this->movement('branch_transfer', 4, 5, '2026-09-28 09:00:00');
        $this->movement('transfer_sent', 0, 4, '2026-09-29 09:00:00');
        $this->movement('branch_transfer', 0, 4, '2026-09-30 09:00:00');
        $this->movement('return_in', 4, 0, '2026-09-29 10:00:00');
        $this->movement('return_supplier', 4, null, '2026-09-30 10:00:00');
        $this->movement('inventory_adjustment', null, 4, '2026-09-30 10:00:00');
        $this->movement('inventory_adjustment', 4, null, '2026-09-30 10:00:00');
        $this->movement('consumption', 4, null, '2026-09-30 11:00:00');
        $this->movement('receipt', null, 0, '2026-09-30 11:00:00');
        $this->movement('receipt', null, 5, '2026-09-30 11:00:00');
        $this->movement('consumption', 5, null, '2026-09-30 11:00:00');

        $central = (new DashboardRepository)->summary(0)['charts']['activity_daily'];
        $branch = (new DashboardRepository)->summary(4)['charts']['activity_daily'];
        $other = (new DashboardRepository)->summary(5)['charts']['activity_daily'];

        self::assertSame(['receipts' => 1, 'issues' => 1, 'returns' => 1, 'transfers' => 1], array_map('array_sum', $central['series']));
        self::assertSame(['receipts' => 2, 'issues' => 2, 'returns' => 2, 'transfers' => 2], array_map('array_sum', $branch['series']));
        self::assertSame(['receipts' => 1, 'issues' => 1, 'returns' => 0, 'transfers' => 1], array_map('array_sum', $other['series']));
        self::assertSame(0, $this->dailyCount($branch, 'receipts', '2026-09-25'));
        self::assertSame(1, $this->dailyCount($branch, 'receipts', '2026-09-26'));
        self::assertSame(1, $this->dailyCount($branch, 'transfers', '2026-09-27'));
        self::assertSame(0, $this->dailyCount($branch, 'transfers', '2026-09-28'));
        self::assertSame(0, $this->dailyCount($central, 'transfers', '2026-09-30'));
        self::assertSame(1, $this->dailyCount($branch, 'transfers', '2026-09-30'));
    }

    public function test_daily_chart_has_exact_calendar_bounds_and_excludes_reversed_originals_even_when_reversal_is_outside_period(): void
    {
        $this->movement('receipt', null, 4, '2026-09-16 23:59:59');
        $this->movement('receipt', null, 4, '2026-09-17 00:00:00');
        $this->movement('receipt', null, 4, '2026-09-30 23:59:59');
        $this->movement('receipt', null, 4, '2026-10-01 00:00:00');
        $corrected = $this->movement('consumption', 4, null, '2026-09-20 09:00:00');
        DB::table('movement_corrections')->insert(['movement_id' => $corrected->id]);
        $this->movement('movement_reversal', null, 4, '2026-10-01 09:00:00');
        $this->movement('movement_reversal', 4, null, '2026-09-21 09:00:00');
        $this->movement('receipt', null, 4, '2026-09-22 09:00:00', 0);
        $this->movement('unrecognized_type', null, 4, '2026-09-23 09:00:00');

        $chart = (new DashboardRepository)->summary(4)['charts']['activity_daily'];

        self::assertSame('2026-09-17', $chart['from']);
        self::assertSame('2026-09-30', $chart['to']);
        self::assertSame('Asia/Yerevan', $chart['timezone']);
        self::assertSame(array_map(static fn (int $offset): string => Carbon::parse('2026-09-17')->addDays($offset)->toDateString(), range(0, 13)), $chart['dates']);
        self::assertSame(1, $chart['series']['receipts'][0]);
        self::assertSame(1, $chart['series']['receipts'][13]);
        self::assertSame(2, array_sum($chart['series']['receipts']));
        foreach (['issues', 'returns', 'transfers'] as $series) {
            self::assertSame(array_fill(0, 14, 0), $chart['series'][$series]);
        }
        self::assertSame(0, $this->dailyCount($chart, 'receipts', '2026-09-22'));
        self::assertSame(0, $this->dailyCount($chart, 'receipts', '2026-09-23'));
    }

    public function test_stock_chart_partitions_active_products_with_zero_stock_before_low_stock_and_scopes_lots(): void
    {
        $low = Product::query()->create(['active' => true, 'min_qty' => 5]);
        $healthy = Product::query()->create(['active' => true, 'min_qty' => 5]);
        Product::query()->create(['active' => true, 'min_qty' => 5]);
        $depleted = Product::query()->create(['active' => true, 'min_qty' => 0]);
        $inactive = Product::query()->create(['active' => false, 'min_qty' => 5]);
        $otherOnly = Product::query()->create(['active' => true, 'min_qty' => 1]);
        $this->lot($low, 4, 2);
        $this->lot($healthy, 4, 2);
        $this->lot($healthy, 4, 3);
        $this->lot($depleted, 4, 0);
        $this->lot($inactive, 4, 1);
        $this->lot($otherOnly, 5, 20);
        $this->lot($low, 0, 50);

        $branch = (new DashboardRepository)->summary(4);
        $central = (new DashboardRepository)->summary(0);

        self::assertSame(['healthy' => 1, 'low' => 1, 'zero' => 3], $branch['charts']['stock_status']);
        self::assertSame(5, array_sum($branch['charts']['stock_status']));
        self::assertSame(3, $branch['zero_stock_products']);
        self::assertSame(3, $branch['low_stock_products']); // Existing summary includes zero products below MIN.
        self::assertSame(['healthy' => 1, 'low' => 0, 'zero' => 4], $central['charts']['stock_status']);
    }

    public function test_expiry_chart_is_disjoint_at_today_and_ninety_day_boundaries_and_excludes_depleted_or_other_location_lots(): void
    {
        $product = Product::query()->create(['active' => true, 'min_qty' => 0]);
        foreach (['2026-09-29', '2026-09-30', '2026-12-29', '2026-12-30', null] as $expiry) {
            $this->lot($product, 4, 1, $expiry);
        }
        $this->lot($product, 4, 0, '2026-09-29');
        $this->lot($product, 0, 5, '2026-09-29');
        $this->lot($product, 5, 5, '2026-12-29');

        $branch = (new DashboardRepository)->summary(4);

        self::assertSame(['safe' => 1, 'expiring' => 2, 'expired' => 1, 'undated' => 1], $branch['charts']['expiry_status']);
        self::assertSame($branch['expired_lots'], $branch['charts']['expiry_status']['expired']);
        self::assertSame($branch['expiring_lots'], $branch['charts']['expiry_status']['expiring']);
        self::assertSame(5, array_sum($branch['charts']['expiry_status']));
        self::assertSame(['safe' => 0, 'expiring' => 0, 'expired' => 1, 'undated' => 0], (new DashboardRepository)->summary(0)['charts']['expiry_status']);
    }

    public function test_empty_charts_keep_fourteen_dates_and_numeric_zero_buckets(): void
    {
        $charts = (new DashboardRepository)->summary(4)['charts'];

        self::assertCount(14, $charts['activity_daily']['dates']);
        foreach ($charts['activity_daily']['series'] as $series) {
            self::assertSame(array_fill(0, 14, 0), $series);
        }
        self::assertSame(['healthy' => 0, 'low' => 0, 'zero' => 0], $charts['stock_status']);
        self::assertSame(['safe' => 0, 'expiring' => 0, 'expired' => 0, 'undated' => 0], $charts['expiry_status']);
    }

    private function movement(string $type, ?int $from, ?int $to, string $date, float $qty = 1): Movement
    {
        return Movement::query()->create(['type' => $type, 'from_location' => $from, 'to_location' => $to, 'happened_at' => $date, 'qty' => $qty]);
    }

    private function lot(Product $product, int $location, float $qty, ?string $expiry = null): StockLot
    {
        return StockLot::query()->create([
            'product_id' => $product->id, 'location_id' => $location, 'qty' => $qty,
            'unit_cost' => 10, 'lot_no' => 'TEST', 'received_on' => '2026-09-01', 'expires_on' => $expiry,
        ]);
    }

    private function dailyCount(array $chart, string $series, string $date): int
    {
        return $chart['series'][$series][array_search($date, $chart['dates'], true)];
    }
}
