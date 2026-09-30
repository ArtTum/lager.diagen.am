<?php

namespace Tests\Unit;

use App\Repositories\PageDataRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\ReportRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CentralLocationLabelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        DB::connection()->getPdo()->sqliteCreateFunction('DATEDIFF', static fn (string $later, string $earlier): int => (int) ((strtotime($later) - strtotime($earlier)) / 86400));
    }

    protected function tearDown(): void
    {
        foreach (['inventory_lines', 'inventory_sessions', 'users', 'movements', 'stock_lots', 'products', 'categories', 'suppliers', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_central_location_zero_is_named_in_page_lists(): void
    {
        $repository = new PageDataRepository;

        [, $expiryQuery] = $repository->definition('expiry', 0);
        [, $movementQuery] = $repository->definition('movements', 0);
        [, $inventoryQuery] = $repository->definition('inventory', 0);
        $reportMovement = (new ReportRepository)->query('movements', now()->subDay()->toDateString(), now()->addDay()->toDateString(), null)->first();
        $notifications = new NotificationRepository;
        $expiringLot = $notifications->expiringLots(0)->first();
        $activeInventory = $notifications->activeInventories(0, false)->first();

        self::assertSame('Կենտրոնական պահեստ', $expiryQuery->first()->location);
        self::assertSame('Կենտրոնական պահեստ', $movementQuery->first()->from_branch);
        self::assertSame('Կենտրոնական պահեստ', $inventoryQuery->first()->location);
        self::assertSame('Կենտրոնական պահեստ', $reportMovement->from_name);
        self::assertSame('Կենտրոնական պահեստ', $expiringLot->branch_name);
        self::assertSame('Կենտրոնական պահեստ', $activeInventory->branch_name);
    }

    private function createSchema(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('unit');
        });
        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('lot_no');
            $table->date('expires_on')->nullable();
            $table->date('received_on')->nullable();
            $table->string('bin_location')->nullable();
            $table->decimal('qty', 12, 3);
        });
        Schema::create('movements', function (Blueprint $table): void {
            $table->id();
            $table->string('movement_no');
            $table->string('type');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->unsignedBigInteger('from_location')->nullable();
            $table->unsignedBigInteger('to_location')->nullable();
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->string('reference')->nullable();
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->dateTime('happened_at');
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('inventory_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('inventory_no');
            $table->unsignedBigInteger('location_id');
            $table->string('status');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('closed_at')->nullable();
        });
        Schema::create('inventory_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('counted_qty')->nullable();
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->string('address')->nullable();
            $table->string('manager')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedBigInteger('category_id')->nullable();
            $table->decimal('min_qty', 12, 3)->default(0);
            $table->boolean('expiry_control')->default(false);
            $table->boolean('active')->default(true);
        });

        BranchFixture::query()->create(['id' => 1, 'name' => 'Կենտրոնական պահեստ', 'code' => 'CENTRAL']);
        ProductFixture::query()->create(['id' => 1, 'code' => 'CENTRAL-1', 'name' => 'Կենտրոնական ապրանք', 'unit' => 'հատ', 'expiry_control' => true, 'active' => true]);
        StockLotFixture::query()->create(['id' => 1, 'product_id' => 1, 'location_id' => 0, 'lot_no' => 'CENTRAL-LOT', 'expires_on' => now()->addDays(20)->toDateString(), 'qty' => 4]);
        UserFixture::query()->create(['id' => 1, 'name' => 'Կենտրոնի օգտատեր']);
        MovementFixture::query()->create(['id' => 1, 'movement_no' => 'MOVE-CENTRAL', 'type' => 'receipt', 'product_id' => 1, 'lot_id' => 1, 'from_location' => 0, 'to_location' => 2, 'qty' => 4, 'unit_cost' => 25, 'reference' => 'CENTRAL', 'reason' => 'Մուտք', 'actor_id' => 1, 'happened_at' => now()]);
        InventoryFixture::query()->create(['id' => 1, 'inventory_no' => 'INV-CENTRAL', 'location_id' => 0, 'status' => 'open', 'started_at' => now()]);
        InventoryLineFixture::query()->create(['session_id' => 1, 'counted_qty' => null]);
    }
}

class BranchFixture extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'branches';
    public $timestamps = false;
    protected $guarded = [];
}

class ProductFixture extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'products';
    public $timestamps = false;
    protected $guarded = [];
}

class StockLotFixture extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'stock_lots';
    public $timestamps = false;
    protected $guarded = [];
}

class MovementFixture extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'movements';
    public $timestamps = false;
    protected $guarded = [];
}

class InventoryFixture extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'inventory_sessions';
    public $timestamps = false;
    protected $guarded = [];
}

class InventoryLineFixture extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'inventory_lines';
    public $timestamps = false;
    protected $guarded = [];
}

class UserFixture extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'users';
    public $timestamps = false;
    protected $guarded = [];
}
