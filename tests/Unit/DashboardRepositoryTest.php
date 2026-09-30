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
            $table->dateTime('happened_at');
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
        foreach (['transfers', 'returns', 'movements', 'receipts', 'stock_requests', 'stock_lots', 'products', 'branches'] as $table) {
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
}
