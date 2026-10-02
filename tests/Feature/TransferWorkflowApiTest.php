<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Movement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransferWorkflowApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'audit_logs', 'movements', 'request_items', 'stock_requests', 'transfer_items',
            'transfers', 'stock_lots', 'products', 'role_permissions', 'permissions',
            'roles', 'branches',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_branch_to_branch_transfer_requires_central_approval_then_sender_and_receiver_steps(): void
    {
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $source = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $destination = Branch::query()->create(['name' => 'Shengavit', 'code' => 'SHENG', 'active' => true]);
        $product = Product::query()->create([
            'code' => 'API-XFER-001',
            'name' => 'Transfer workflow item',
            'unit' => 'հատ',
            'purchase_price' => 250,
            'lot_control' => true,
            'expiry_control' => true,
            'active' => true,
        ]);
        $sourceLot = StockLot::query()->create([
            'product_id' => $product->id,
            'location_id' => $source->id,
            'lot_no' => 'SOURCE-LOT',
            'expires_on' => now()->addMonths(8)->toDateString(),
            'received_on' => now()->subDays(4)->toDateString(),
            'unit_cost' => 250,
            'qty' => 3,
        ]);
        $destinationLot = StockLot::query()->create([
            'product_id' => $product->id,
            'location_id' => $destination->id,
            'lot_no' => 'SOURCE-LOT',
            'expires_on' => $sourceLot->expires_on,
            'received_on' => now()->subDay()->toDateString(),
            'unit_cost' => 250,
            'qty' => 6,
        ]);
        $sender = $this->user($source, 'branch', 10, ['transfers.create', 'transfers.edit', 'transfers.view']);
        $approver = $this->user($central, 'admin', 20, ['transfers.approve', 'transfers.view']);
        $centralWorker = $this->user($central, 'storekeeper', 21, ['transfers.edit', 'transfers.view']);
        $receiver = $this->user($destination, 'branch', 30, ['transfers.edit', 'transfers.view']);

        $this->actingAs($sender, 'sanctum');
        $create = $this->postJson('/api/transfers', [
            'from_branch' => $source->id,
            'to_branch' => $destination->id,
            'reason' => 'Մասնաճյուղերի միջև պաշարի վերաբաշխում',
            'items' => [['product_id' => $product->id, 'qty' => 2]],
        ]);
        $create->assertCreated()->assertJsonPath('data.status', 'pending');
        $transferId = (int) $create->json('data.id');

        $this->actingAs($approver, 'sanctum');
        $this->postJson("/api/transfers/{$transferId}/approve")->assertOk();
        self::assertSame('approved', Transfer::query()->findOrFail($transferId)->status);

        $this->actingAs($sender, 'sanctum');
        $this->postJson("/api/transfers/{$transferId}/ship")->assertOk();
        self::assertSame('shipped', Transfer::query()->findOrFail($transferId)->status);
        self::assertEquals(1.0, (float) $sourceLot->fresh()->qty);
        self::assertSame(['transfer_sent'], Movement::query()->pluck('type')->all());

        $this->postJson("/api/transfers/{$transferId}/receive")->assertForbidden();
        self::assertSame('shipped', Transfer::query()->findOrFail($transferId)->status);

        $this->actingAs($centralWorker, 'sanctum');
        $this->postJson("/api/transfers/{$transferId}/receive")->assertForbidden();
        self::assertSame('shipped', Transfer::query()->findOrFail($transferId)->status);

        $this->actingAs($receiver, 'sanctum');
        $this->postJson("/api/transfers/{$transferId}/receive")->assertOk();

        self::assertSame('completed', Transfer::query()->findOrFail($transferId)->status);
        self::assertEquals(8.0, (float) $destinationLot->fresh()->qty);
        self::assertSame(2, StockLot::query()->count(), 'Receipt should increase the matching destination LOT instead of inserting a duplicate.');
        self::assertSame(['transfer_sent', 'branch_transfer'], Movement::query()->orderBy('id')->pluck('type')->all());
        self::assertSame(4, AuditLog::query()->count());
    }

    public function test_transfer_with_only_expired_stock_cannot_be_approved(): void
    {
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $source = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $destination = Branch::query()->create(['name' => 'Shengavit', 'code' => 'SHENG', 'active' => true]);
        $product = Product::query()->create([
            'code' => 'API-XFER-EXPIRED',
            'name' => 'Expired-only transfer item',
            'unit' => 'հատ',
            'purchase_price' => 250,
            'lot_control' => true,
            'expiry_control' => true,
            'active' => true,
        ]);
        $expiredLot = StockLot::query()->create([
            'product_id' => $product->id,
            'location_id' => $source->id,
            'lot_no' => 'EXPIRED-LOT',
            'expires_on' => now()->subDay()->toDateString(),
            'received_on' => now()->subMonths(3)->toDateString(),
            'unit_cost' => 250,
            'qty' => 2,
        ]);
        $sender = $this->user($source, 'branch', 10, ['transfers.create', 'transfers.edit', 'transfers.view']);
        $approver = $this->user($central, 'admin', 20, ['transfers.approve', 'transfers.view']);

        $this->actingAs($sender, 'sanctum');
        $create = $this->postJson('/api/transfers', [
            'from_branch' => $source->id,
            'to_branch' => $destination->id,
            'reason' => 'Expired stock must not be shipped',
            'items' => [['product_id' => $product->id, 'qty' => 1]],
        ])->assertCreated();
        $transferId = (int) $create->json('data.id');

        $this->actingAs($approver, 'sanctum');
        $this->postJson("/api/transfers/{$transferId}/approve")
            ->assertOk();

        self::assertSame('stock_shortage', Transfer::query()->findOrFail($transferId)->status);
        self::assertEquals(2.0, (float) $expiredLot->fresh()->qty);
        self::assertSame(0, Movement::query()->count());
    }

    #[DataProvider('conflictingLotProvenance')]
    public function test_transfer_receipt_preserves_supplier_and_unit_cost_of_same_numbered_lots(?int $sourceSupplier, ?int $destinationSupplier, float $sourceCost, float $destinationCost): void
    {
        Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $source = Branch::query()->create(['name' => 'Source', 'code' => 'SRC', 'active' => true]);
        $destination = Branch::query()->create(['name' => 'Destination', 'code' => 'DEST', 'active' => true]);
        $product = Product::query()->create([
            'code' => 'XFER-PROVENANCE', 'name' => 'Same numbered batches', 'unit' => 'հատ',
            'purchase_price' => 100, 'lot_control' => true, 'expiry_control' => false, 'active' => true,
        ]);
        $attributes = ['product_id' => $product->id, 'lot_no' => 'SHARED-LOT', 'expires_on' => null, 'received_on' => now()->toDateString()];
        $sourceLot = StockLot::query()->create([...$attributes, 'location_id' => $source->id, 'supplier_id' => $sourceSupplier, 'unit_cost' => $sourceCost, 'qty' => 0]);
        $destinationLot = StockLot::query()->create([...$attributes, 'location_id' => $destination->id, 'supplier_id' => $destinationSupplier, 'unit_cost' => $destinationCost, 'qty' => 5]);
        $transfer = Transfer::query()->create([
            'transfer_no' => 'XFER-PROVENANCE', 'from_branch' => $source->id, 'to_branch' => $destination->id,
            'product_id' => $product->id, 'qty' => 2, 'status' => 'shipped', 'requested_by' => 10,
        ]);
        Movement::query()->create([
            'movement_no' => 'SENT-PROVENANCE', 'type' => 'transfer_sent', 'product_id' => $product->id, 'lot_id' => $sourceLot->id,
            'from_location' => $source->id, 'to_location' => $destination->id, 'qty' => 2, 'unit_cost' => $sourceCost,
            'reference' => $transfer->transfer_no, 'reason' => 'Shipment', 'actor_id' => 10, 'happened_at' => now(),
        ]);
        $receiver = $this->user($destination, 'receiver', 30, ['transfers.edit']);

        $this->actingAs($receiver, 'sanctum')->postJson("/api/transfers/{$transfer->id}/receive")->assertOk();

        self::assertEquals(5.0, (float) $destinationLot->fresh()->qty);
        $receivedLot = StockLot::query()->where('location_id', $destination->id)->where('id', '<>', $destinationLot->id)->sole();
        self::assertSame($sourceSupplier, $receivedLot->supplier_id);
        self::assertSame(number_format($sourceCost, 2, '.', ''), $receivedLot->unit_cost);
        self::assertEquals(2.0, (float) $receivedLot->qty);
        self::assertSame($receivedLot->id, Movement::query()->where('type', 'branch_transfer')->sole()->lot_id);
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

    private function user(Branch $branch, string $roleName, int $id, array $permissionCodes): User
    {
        $role = Role::query()->create(['name' => $roleName, 'title' => $roleName]);
        foreach ($permissionCodes as $code) {
            $permission = Permission::query()->firstOrCreate([
                'code' => $code,
            ], [
                'title' => $code,
                'module' => explode('.', $code)[0],
            ]);
            $role->permissions()->attach($permission);
        }

        $user = new User(['active' => true, 'branch_id' => $branch->id]);
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
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('unit');
            $table->decimal('purchase_price', 14, 2);
            $table->boolean('lot_control');
            $table->boolean('expiry_control');
            $table->boolean('active');
        });
        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->string('lot_no');
            $table->date('expires_on')->nullable();
            $table->date('received_on');
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('qty', 12, 3);
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('bin_location')->nullable();
            $table->unsignedBigInteger('purchase_order_id')->nullable();
        });
        Schema::create('transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_no');
            $table->unsignedBigInteger('from_branch');
            $table->unsignedBigInteger('to_branch');
            $table->unsignedBigInteger('product_id');
            $table->decimal('qty', 12, 3);
            $table->string('status');
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('shipped_by')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->text('reason')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('transfer_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('qty', 12, 3);
            $table->decimal('received_qty', 12, 3)->nullable();
            $table->string('note')->nullable();
        });
        Schema::create('request_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('approved_qty', 12, 3);
        });
        Schema::create('stock_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('status');
        });
        Schema::create('movements', function (Blueprint $table): void {
            $table->id();
            $table->string('movement_no');
            $table->string('type');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('lot_id');
            $table->unsignedBigInteger('from_location')->nullable();
            $table->unsignedBigInteger('to_location')->nullable();
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 14, 4);
            $table->string('reference');
            $table->string('reason');
            $table->unsignedBigInteger('actor_id');
            $table->dateTime('happened_at');
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id');
            $table->string('action');
            $table->string('entity');
            $table->unsignedBigInteger('entity_id');
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('ip_address')->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }
}
