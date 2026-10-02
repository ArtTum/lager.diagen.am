<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Movement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReturnWorkflowApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'audit_logs', 'movements', 'returns', 'request_items', 'stock_requests', 'transfer_items', 'transfers', 'stock_lots', 'suppliers',
            'products', 'role_permissions', 'permissions', 'users', 'roles', 'branches',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_branch_return_moves_stock_to_central_and_rejects_excess_quantity(): void
    {
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $otherBranch = Branch::query()->create(['name' => 'Gyumri', 'code' => 'GYUM', 'active' => true]);
        $product = Product::query()->create([
            'code' => 'RET-001', 'name' => 'Return workflow product', 'unit' => 'հատ',
            'purchase_price' => 100, 'lot_control' => true, 'expiry_control' => true, 'active' => true,
        ]);
        $branchLot = StockLot::query()->create([
            'product_id' => $product->id, 'location_id' => $branch->id, 'lot_no' => 'BRANCH-LOT',
            'expires_on' => now()->addMonths(6)->toDateString(), 'received_on' => now()->subDays(2)->toDateString(),
            'unit_cost' => 100, 'qty' => 5,
        ]);
        $centralLot = StockLot::query()->create([
            'product_id' => $product->id, 'location_id' => 0, 'lot_no' => 'BRANCH-LOT',
            'expires_on' => now()->addMonths(6)->toDateString(), 'received_on' => now()->subDays(10)->toDateString(),
            'unit_cost' => 100, 'qty' => 3,
        ]);
        $actor = $this->branchUser($branch, 10);

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/returns', [
                'direction' => 'branch_to_central',
                'from_location' => $otherBranch->id,
                'reason' => 'Մասնաճյուղի ավելցուկի վերադարձ',
                'items' => [['product_id' => $product->id, 'qty' => 2]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.direction', 'branch_to_central')
            ->assertJsonPath('data.from_location', $branch->id)
            ->assertJsonPath('data.qty', '2.000');

        self::assertEquals(3.0, (float) $branchLot->fresh()->qty);
        self::assertEquals(5.0, (float) $centralLot->fresh()->qty);
        self::assertSame('return_in', Movement::query()->sole()->type);
        self::assertSame(0, (int) Movement::query()->sole()->to_location);
        self::assertSame(1, ProductReturn::query()->count());

        $this->postJson('/api/returns', [
            'direction' => 'branch_to_central',
            'reason' => 'Ավելի շատ ապրանք վերադարձնելու փորձ',
            'items' => [['product_id' => $product->id, 'qty' => 4]],
        ])->assertUnprocessable();

        self::assertEquals(3.0, (float) $branchLot->fresh()->qty);
        self::assertEquals(5.0, (float) $centralLot->fresh()->qty);
        self::assertSame(1, Movement::query()->count());
        self::assertSame(1, ProductReturn::query()->count());
    }

    public function test_supplier_return_uses_product_level_free_quantity_without_over_subtracting_other_lots(): void
    {
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $supplierToReturn = DB::table('suppliers')->insertGetId(['name' => 'Supplier A', 'active' => true]);
        $otherSupplier = DB::table('suppliers')->insertGetId(['name' => 'Supplier B', 'active' => true]);
        $product = Product::query()->create([
            'code' => 'RET-002', 'name' => 'Reserved stock return product', 'unit' => 'հատ',
            'purchase_price' => 100, 'lot_control' => true, 'expiry_control' => true, 'active' => true,
        ]);
        $selectedLot = StockLot::query()->create([
            'product_id' => $product->id, 'location_id' => 0, 'lot_no' => 'SUPPLIER-A-LOT',
            'expires_on' => now()->addMonths(6)->toDateString(), 'received_on' => now()->subDays(2)->toDateString(),
            'supplier_id' => $supplierToReturn, 'unit_cost' => 100, 'qty' => 10,
        ]);
        $otherLot = StockLot::query()->create([
            'product_id' => $product->id, 'location_id' => 0, 'lot_no' => 'SUPPLIER-B-LOT',
            'expires_on' => now()->addMonths(6)->toDateString(), 'received_on' => now()->subDays(1)->toDateString(),
            'supplier_id' => $otherSupplier, 'unit_cost' => 100, 'qty' => 90,
        ]);
        $requestId = DB::table('stock_requests')->insertGetId(['branch_id' => $central->id, 'status' => 'approved']);
        DB::table('request_items')->insert(['request_id' => $requestId, 'product_id' => $product->id, 'approved_qty' => 20]);
        $actor = $this->centralUser($central, 20);

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/returns', [
                'direction' => 'central_to_supplier',
                'supplier_id' => $supplierToReturn,
                'reason' => 'Մատակարարի ապրանքի վերադարձ',
                'items' => [['product_id' => $product->id, 'qty' => 10]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.direction', 'central_to_supplier')
            ->assertJsonPath('data.qty', '10.000');

        self::assertEquals(0.0, (float) $selectedLot->fresh()->qty);
        self::assertEquals(90.0, (float) $otherLot->fresh()->qty);
        self::assertSame(1, ProductReturn::query()->count());
        self::assertSame('return_supplier', Movement::query()->sole()->type);
    }

    #[DataProvider('conflictingLotProvenance')]
    public function test_branch_return_preserves_supplier_and_unit_cost_of_same_numbered_lots(?int $sourceSupplier, ?int $destinationSupplier, float $sourceCost, float $destinationCost): void
    {
        Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $product = Product::query()->create([
            'code' => 'RETURN-PROVENANCE', 'name' => 'Same numbered batches', 'unit' => 'հատ',
            'purchase_price' => 100, 'lot_control' => true, 'expiry_control' => false, 'active' => true,
        ]);
        $attributes = ['product_id' => $product->id, 'lot_no' => 'SHARED-LOT', 'expires_on' => null, 'received_on' => now()->toDateString()];
        $sourceLot = StockLot::query()->create([...$attributes, 'location_id' => $branch->id, 'supplier_id' => $sourceSupplier, 'unit_cost' => $sourceCost, 'qty' => 2]);
        $centralLot = StockLot::query()->create([...$attributes, 'location_id' => 0, 'supplier_id' => $destinationSupplier, 'unit_cost' => $destinationCost, 'qty' => 5]);

        $this->actingAs($this->branchUser($branch, 10), 'sanctum')->postJson('/api/returns', [
            'direction' => 'branch_to_central', 'reason' => 'Return branch surplus',
            'items' => [['product_id' => $product->id, 'qty' => 2]],
        ])->assertCreated();

        self::assertEquals(0.0, (float) $sourceLot->fresh()->qty);
        self::assertEquals(5.0, (float) $centralLot->fresh()->qty);
        $receivedLot = StockLot::query()->where('location_id', 0)->where('id', '<>', $centralLot->id)->sole();
        self::assertSame($sourceSupplier, $receivedLot->supplier_id);
        self::assertSame(number_format($sourceCost, 2, '.', ''), $receivedLot->unit_cost);
        self::assertEquals(2.0, (float) $receivedLot->qty);
        self::assertSame($receivedLot->id, Movement::query()->sole()->lot_id);
    }

    public static function conflictingLotProvenance(): array
    {
        return [
            'different suppliers' => [11, 22, 100.0, 100.0],
            'source supplier absent' => [null, 22, 100.0, 100.0],
            'destination supplier absent' => [11, null, 100.0, 100.0],
            'different costs' => [11, 11, 100.01, 100.02],
        ];
    }

    private function centralUser(Branch $branch, int $id): User
    {
        $role = Role::query()->create(['name' => 'admin', 'title' => 'Համակարգի ադմինիստրատոր']);
        $permission = Permission::query()->create([
            'code' => 'returns.create', 'title' => 'Վերադարձ ստեղծել', 'module' => 'returns',
        ]);
        $role->setRelation('permissions', collect([$permission]));

        $user = new User(['name' => 'Central admin', 'email' => 'central@example.test', 'active' => true, 'branch_id' => $branch->id]);
        $user->setAttribute('id', $id);
        $user->setRelation('branch', $branch);
        $user->setRelation('role', $role);

        return $user;
    }

    private function branchUser(Branch $branch, int $id): User
    {
        $role = Role::query()->create(['name' => 'branch', 'title' => 'Մասնաճյուղի պատասխանատու']);
        $permission = Permission::query()->create([
            'code' => 'returns.create', 'title' => 'Վերադարձ ստեղծել', 'module' => 'returns',
        ]);
        $role->setRelation('permissions', collect([$permission]));

        $user = new User(['name' => 'Branch user', 'email' => 'branch@example.test', 'active' => true, 'branch_id' => $branch->id]);
        $user->setAttribute('id', $id);
        $user->setRelation('branch', $branch);
        $user->setRelation('role', $role);

        return $user;
    }

    private function createSchema(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->boolean('active');
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('title');
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('module');
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('unit');
            $table->decimal('purchase_price', 14, 2)->default(0);
            $table->boolean('lot_control')->default(false);
            $table->boolean('expiry_control')->default(false);
            $table->boolean('active')->default(true);
        });
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
        });
        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->string('lot_no');
            $table->date('expires_on')->nullable();
            $table->date('received_on');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->string('bin_location')->nullable();
            $table->decimal('qty', 12, 3)->default(0);
        });
        Schema::create('transfers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('from_branch');
            $table->unsignedBigInteger('to_branch');
            $table->string('status');
        });
        Schema::create('transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('transfer_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('qty', 12, 3);
        });
        Schema::create('stock_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('status');
        });
        Schema::create('request_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('approved_qty', 12, 3);
        });
        Schema::create('returns', function (Blueprint $table): void {
            $table->id();
            $table->string('return_no');
            $table->string('direction');
            $table->unsignedBigInteger('from_location');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('lot_id');
            $table->decimal('qty', 12, 3);
            $table->text('reason');
            $table->string('status');
            $table->unsignedBigInteger('actor_id');
            $table->dateTime('created_at');
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
            $table->decimal('unit_cost', 14, 4);
            $table->string('reference');
            $table->text('reason');
            $table->unsignedBigInteger('actor_id');
            $table->timestamp('happened_at');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('entity');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
