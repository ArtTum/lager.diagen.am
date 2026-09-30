<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Movement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockConsumptionApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'audit_logs', 'movements', 'stock_lots', 'transfer_items', 'transfers', 'products',
            'role_permissions', 'permissions', 'users', 'roles', 'branches',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_branch_consumption_uses_fefo_and_rejects_reserved_or_excess_quantity(): void
    {
        Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $product = Product::query()->create([
            'code' => 'USE-001', 'name' => 'FEFO consumption product', 'unit' => 'հատ',
            'purchase_price' => 100, 'lot_control' => true, 'expiry_control' => true, 'active' => true,
        ]);
        $nearLot = $this->lot($product, (int) $branch->id, 'NEAR', 2, now()->addMonths(2)->toDateString());
        $farLot = $this->lot($product, (int) $branch->id, 'FAR', 4, now()->addMonths(5)->toDateString());
        $reservation = Transfer::query()->create([
            'transfer_no' => 'TR-RESERVED', 'from_branch' => $branch->id, 'to_branch' => 1, 'status' => 'approved',
        ]);
        TransferItem::query()->create(['transfer_id' => $reservation->id, 'product_id' => $product->id, 'qty' => 2]);
        $actor = $this->branchUser($branch, 10);
        $this->actingAs($actor, 'sanctum');

        $this->postJson('/api/stock/consume', [
            'product_id' => $product->id, 'qty' => 3, 'location_id' => $branch->id, 'issue_type' => 'usage',
        ])->assertOk();

        self::assertEquals(0.0, (float) $nearLot->fresh()->qty);
        self::assertEquals(3.0, (float) $farLot->fresh()->qty);
        self::assertSame(['NEAR', 'FAR'], Movement::query()->orderBy('id')->get()->map(fn (Movement $movement) => $movement->lot->lot_no)->all());
        self::assertSame((int) $branch->id, (int) Movement::query()->firstOrFail()->from_location);
        self::assertSame('usage', Movement::query()->firstOrFail()->reason);

        $this->postJson('/api/stock/consume', [
            'product_id' => $product->id, 'qty' => 2, 'issue_type' => 'usage',
        ])->assertUnprocessable();

        $reservation->update(['status' => 'cancelled']);
        $this->postJson('/api/stock/consume', [
            'product_id' => $product->id, 'qty' => 4, 'issue_type' => 'usage',
        ])->assertUnprocessable();

        self::assertEquals(0.0, (float) $nearLot->fresh()->qty);
        self::assertEquals(3.0, (float) $farLot->fresh()->qty);
        self::assertSame(2, Movement::query()->count());
    }

    private function lot(Product $product, int $location, string $lotNo, float $quantity, string $expiresOn): StockLot
    {
        return StockLot::query()->create([
            'product_id' => $product->id, 'location_id' => $location, 'lot_no' => $lotNo,
            'expires_on' => $expiresOn, 'received_on' => now()->subDays(2)->toDateString(),
            'unit_cost' => 100, 'qty' => $quantity,
        ]);
    }

    private function branchUser(Branch $branch, int $id): User
    {
        $role = Role::query()->create(['name' => 'branch', 'title' => 'Մասնաճյուղի պատասխանատու']);
        $permission = Permission::query()->create([
            'code' => 'stock.create', 'title' => 'Պաշարի ելք', 'module' => 'stock',
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
        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->string('lot_no');
            $table->date('expires_on')->nullable();
            $table->date('received_on');
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('qty', 12, 3)->default(0);
        });
        Schema::create('transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_no');
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
            $table->string('reference')->nullable();
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
