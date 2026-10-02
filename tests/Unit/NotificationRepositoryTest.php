<?php

namespace Tests\Unit;

use App\Repositories\NotificationRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('branches', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
        });
        Schema::create('inventory_sessions', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('inventory_no');
            $table->unsignedBigInteger('location_id');
            $table->string('status');
            $table->dateTime('started_at')->nullable();
        });
        Schema::create('products', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('code');
            $table->string('name');
            $table->string('unit')->default('հատ');
            $table->boolean('expiry_control')->default(false);
            $table->boolean('active')->default(true);
            $table->decimal('min_qty', 12, 3)->default(0);
            $table->decimal('max_qty', 12, 3)->default(0);
        });
        Schema::create('suppliers', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
        });
        Schema::create('stock_lots', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->string('lot_no')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->decimal('qty', 12, 3);
            $table->date('expires_on')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('inventory_sessions');
        Schema::dropIfExists('stock_lots');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('products');
        Schema::dropIfExists('branches');

        parent::tearDown();
    }

    public function test_central_approver_sees_counted_inventory_sessions_from_every_branch(): void
    {
        DB::table('branches')->insert([
            ['id' => 7, 'name' => 'Erebuni'],
            ['id' => 8, 'name' => 'Gyumri'],
        ]);
        DB::table('inventory_sessions')->insert([
            ['id' => 1, 'inventory_no' => 'INV-EREB-OPEN', 'location_id' => 7, 'status' => 'open', 'started_at' => '2026-09-28 10:00:00'],
            ['id' => 2, 'inventory_no' => 'INV-GYUM-COUNTED', 'location_id' => 8, 'status' => 'counted', 'started_at' => '2026-09-29 10:00:00'],
            ['id' => 3, 'inventory_no' => 'INV-EREB-CLOSED', 'location_id' => 7, 'status' => 'closed', 'started_at' => '2026-09-27 10:00:00'],
            ['id' => 4, 'inventory_no' => 'INV-CENTRAL-OPEN', 'location_id' => 0, 'status' => 'open', 'started_at' => '2026-09-30 10:00:00'],
        ]);

        $sessions = (new NotificationRepository)->activeInventories(0, true);

        self::assertSame(['INV-GYUM-COUNTED'], $sessions->pluck('inventory_no')->all());
        self::assertSame(['Gyumri'], $sessions->pluck('branch_name')->all());
    }

    public function test_central_viewer_without_approval_only_sees_open_central_inventory(): void
    {
        DB::table('branches')->insert([
            ['id' => 7, 'name' => 'Erebuni'],
        ]);
        DB::table('inventory_sessions')->insert([
            ['id' => 1, 'inventory_no' => 'INV-EREB-OPEN', 'location_id' => 7, 'status' => 'open', 'started_at' => '2026-09-28 10:00:00'],
            ['id' => 2, 'inventory_no' => 'INV-CENTRAL-OPEN', 'location_id' => 0, 'status' => 'open', 'started_at' => '2026-09-29 10:00:00'],
            ['id' => 3, 'inventory_no' => 'INV-EREB-COUNTED', 'location_id' => 7, 'status' => 'counted', 'started_at' => '2026-09-30 10:00:00'],
        ]);

        $sessions = (new NotificationRepository)->activeInventories(0, false);

        self::assertSame(['INV-CENTRAL-OPEN'], $sessions->pluck('inventory_no')->all());
    }

    public function test_branch_notifications_only_include_its_own_unclosed_inventory_sessions(): void
    {
        DB::table('branches')->insert([
            ['id' => 7, 'name' => 'Erebuni'],
            ['id' => 8, 'name' => 'Gyumri'],
        ]);
        DB::table('inventory_sessions')->insert([
            ['id' => 1, 'inventory_no' => 'INV-EREB-OPEN', 'location_id' => 7, 'status' => 'open', 'started_at' => '2026-09-28 10:00:00'],
            ['id' => 2, 'inventory_no' => 'INV-GYUM-COUNTED', 'location_id' => 8, 'status' => 'counted', 'started_at' => '2026-09-29 10:00:00'],
        ]);

        $sessions = (new NotificationRepository)->activeInventories(7, false);

        self::assertSame(['INV-EREB-OPEN'], $sessions->pluck('inventory_no')->all());
        self::assertSame(['Erebuni'], $sessions->pluck('branch_name')->all());
    }

    public function test_expiry_notifications_ignore_products_without_expiry_control(): void
    {
        DB::table('products')->insert([
            ['id' => 1, 'code' => 'EXP-ON', 'name' => 'Tracked product', 'expiry_control' => true],
            ['id' => 2, 'code' => 'EXP-OFF', 'name' => 'Untracked product', 'expiry_control' => false],
        ]);
        DB::table('stock_lots')->insert([
            ['id' => 1, 'product_id' => 1, 'location_id' => 0, 'lot_no' => 'LOT-ON', 'qty' => 4, 'expires_on' => now()->addDays(30)->toDateString()],
            ['id' => 2, 'product_id' => 2, 'location_id' => 0, 'lot_no' => 'LOT-OFF', 'qty' => 6, 'expires_on' => now()->addDays(30)->toDateString()],
        ]);

        $lots = (new NotificationRepository)->expiringLots(0);

        self::assertSame(['LOT-ON'], $lots->pluck('lot_no')->all());
        self::assertSame(['EXP-ON'], $lots->pluck('code')->all());
    }

    public function test_stock_threshold_notifications_trigger_at_min_and_strictly_above_max(): void
    {
        DB::table('products')->insert([
            ['id' => 1, 'code' => 'AT-MIN', 'name' => 'At minimum', 'expiry_control' => false, 'active' => true, 'min_qty' => 5, 'max_qty' => 10],
            ['id' => 2, 'code' => 'BETWEEN', 'name' => 'Between limits', 'expiry_control' => false, 'active' => true, 'min_qty' => 5, 'max_qty' => 10],
            ['id' => 3, 'code' => 'AT-MAX', 'name' => 'At maximum', 'expiry_control' => false, 'active' => true, 'min_qty' => 5, 'max_qty' => 10],
            ['id' => 4, 'code' => 'OVER-MAX', 'name' => 'Over maximum', 'expiry_control' => false, 'active' => true, 'min_qty' => 5, 'max_qty' => 10],
        ]);
        DB::table('stock_lots')->insert([
            ['id' => 1, 'product_id' => 1, 'location_id' => 0, 'lot_no' => 'A', 'qty' => 5, 'expires_on' => null],
            ['id' => 2, 'product_id' => 2, 'location_id' => 0, 'lot_no' => 'B', 'qty' => 7, 'expires_on' => null],
            ['id' => 3, 'product_id' => 3, 'location_id' => 0, 'lot_no' => 'C', 'qty' => 10, 'expires_on' => null],
            ['id' => 4, 'product_id' => 4, 'location_id' => 0, 'lot_no' => 'D', 'qty' => 10.001, 'expires_on' => null],
        ]);

        $rows = (new NotificationRepository)->currentStock(0);

        self::assertSame(['AT-MIN', 'OVER-MAX'], $rows->pluck('code')->all());
        self::assertEquals([5.0, 10.001], $rows->pluck('qty')->map(static fn ($qty): float => (float) $qty)->all());
    }
}
