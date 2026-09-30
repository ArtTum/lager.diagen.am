<?php

namespace Tests\Unit;

use App\Models\StockLot;
use App\Repositories\StockRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockLotMatchingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->string('lot_no');
            $table->date('expires_on')->nullable();
            $table->date('received_on');
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('qty', 12, 3);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('stock_lots');

        parent::tearDown();
    }

    public function test_matching_lots_distinguishes_expiry_dates_and_null_expiry(): void
    {
        $dated = StockLot::query()->create([
            'product_id' => 1,
            'location_id' => 2,
            'lot_no' => 'LOT-A',
            'expires_on' => '2030-12-31',
            'received_on' => '2026-01-01',
            'unit_cost' => 100,
            'qty' => 1,
        ]);
        $undated = StockLot::query()->create([
            'product_id' => 1,
            'location_id' => 2,
            'lot_no' => 'LOT-A',
            'expires_on' => null,
            'received_on' => '2026-01-01',
            'unit_cost' => 100,
            'qty' => 1,
        ]);
        $repository = new StockRepository;

        self::assertSame($dated->id, $repository->matchingLot(1, 2, 'LOT-A', '2030-12-31')?->id);
        self::assertNull($repository->matchingLot(1, 2, 'LOT-A', '2031-12-31'));
        self::assertSame($undated->id, $repository->matchingLot(1, 2, 'LOT-A', null)?->id);
        self::assertTrue($repository->lotExists(1, 2, 'LOT-A', null));
    }
}
