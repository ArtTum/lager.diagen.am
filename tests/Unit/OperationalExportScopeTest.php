<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\InventorySession;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\StockLot;
use App\Models\User;
use App\Repositories\InventoryRepository;
use App\Repositories\ReturnRepository;
use App\Repositories\StockRepository;
use App\Repositories\TransferRepository;
use App\Services\InventoryService;
use App\Services\ReturnService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OperationalExportScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('branches', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
        });
        Schema::create('users', function ($table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('products', function ($table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('unit');
        });
        Schema::create('suppliers', function ($table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('stock_lots', function ($table): void {
            $table->id();
            $table->string('lot_no');
            $table->date('expires_on')->nullable();
        });
        Schema::create('returns', function ($table): void {
            $table->id();
            $table->string('return_no');
            $table->string('direction');
            $table->unsignedBigInteger('from_location');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->decimal('qty', 12, 3);
            $table->text('reason');
            $table->string('status');
            $table->unsignedBigInteger('actor_id');
            $table->dateTime('created_at');
        });
        Schema::create('inventory_sessions', function ($table): void {
            $table->id();
            $table->string('inventory_no');
            $table->unsignedBigInteger('location_id');
            $table->string('status');
            $table->unsignedBigInteger('started_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('closed_at')->nullable();
        });
        Schema::create('inventory_lines', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->decimal('counted_qty', 12, 3)->nullable();
        });
    }

    protected function tearDown(): void
    {
        foreach (['inventory_lines', 'inventory_sessions', 'returns', 'stock_lots', 'suppliers', 'products', 'users', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_return_export_is_limited_to_the_actor_branch(): void
    {
        $product = Product::query()->create(['code' => 'MED-1', 'name' => 'Medicine', 'unit' => 'հատ']);
        $branchA = Branch::query()->create(['name' => 'Branch A', 'code' => 'A']);
        $branchB = Branch::query()->create(['name' => 'Branch B', 'code' => 'B']);
        $lot = StockLot::query()->create(['lot_no' => 'LOT-1', 'expires_on' => null]);
        foreach ([$branchA, $branchB] as $branch) {
            ProductReturn::query()->create(['return_no' => 'RET-'.$branch->id, 'direction' => 'branch_to_central', 'from_location' => $branch->id,
                'product_id' => $product->id, 'lot_id' => $lot->id, 'qty' => 1, 'reason' => 'Adjustment', 'status' => 'completed', 'actor_id' => 1, 'created_at' => now()]);
        }
        $service = new ReturnService(new ReturnRepository, $this->createMock(StockRepository::class), $this->createMock(TransferRepository::class));

        $rows = iterator_to_array($service->export($this->actor(2), [])['rows']);

        self::assertCount(1, $rows);
        self::assertSame('RET-'.$branchB->id, $rows[0][0]);
        self::assertSame('Branch B', $rows[0][2]);
    }

    public function test_inventory_export_is_limited_to_the_actor_location(): void
    {
        $branchA = Branch::query()->create(['name' => 'Branch A', 'code' => 'A']);
        $branchB = Branch::query()->create(['name' => 'Branch B', 'code' => 'B']);
        foreach ([$branchA, $branchB] as $branch) {
            InventorySession::query()->create(['inventory_no' => 'INV-'.$branch->id, 'location_id' => $branch->id, 'status' => 'closed']);
        }
        $service = new InventoryService(new InventoryRepository, $this->createMock(StockRepository::class));

        $rows = iterator_to_array($service->export($this->actor(2), [])['rows']);

        self::assertCount(1, $rows);
        self::assertSame('INV-'.$branchB->id, $rows[0][0]);
        self::assertSame('Branch B', $rows[0][1]);
        self::assertSame('Գույքագրումն ավարտված է', $rows[0][2]);
    }

    public function test_inventory_export_uses_shared_labels_and_preserves_unknown_codes(): void
    {
        $branch = Branch::query()->create(['name' => 'Branch A', 'code' => 'A']);
        $statuses = ['open', 'counted', 'closed', 'future_phase'];
        foreach ($statuses as $status) {
            InventorySession::query()->create(['inventory_no' => 'INV-'.$status, 'location_id' => $branch->id, 'status' => $status]);
        }
        $service = new InventoryService(new InventoryRepository, $this->createMock(StockRepository::class));
        $catalog = json_decode(file_get_contents(resource_path('js/workflowStatuses.json')), true, 512, JSON_THROW_ON_ERROR);

        $rows = iterator_to_array($service->export($this->actor((int) $branch->id), [])['rows']);
        $exported = array_column($rows, 2, 0);

        foreach ($statuses as $status) {
            self::assertSame($catalog['inventory'][$status]['label'] ?? $status, $exported['INV-'.$status]);
        }
        self::assertSame($statuses, InventorySession::query()->orderBy('id')->pluck('status')->all());
    }

    private function actor(int $location): User
    {
        $actor = $this->createMock(User::class);
        $actor->method('currentLocationId')->willReturn($location);

        return $actor;
    }
}
