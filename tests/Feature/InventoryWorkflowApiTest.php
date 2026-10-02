<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryLine;
use App\Models\InventorySession;
use App\Models\Movement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryWorkflowApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'audit_logs', 'movements', 'inventory_lines', 'inventory_sessions', 'stock_lots', 'products',
            'suppliers', 'role_permissions', 'permissions', 'users', 'roles', 'branches',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_inventory_snapshot_count_and_independent_approval_adjust_stock_through_api(): void
    {
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $existingProduct = Product::query()->create([
            'code' => 'INV-EXISTING', 'name' => 'Existing LOT item', 'unit' => 'հատ',
            'purchase_price' => 10, 'lot_control' => true, 'expiry_control' => true, 'active' => true,
        ]);
        $unstockedProduct = Product::query()->create([
            'code' => 'INV-NEW', 'name' => 'Unstocked item', 'unit' => 'հատ',
            'purchase_price' => 21, 'lot_control' => true, 'expiry_control' => true, 'active' => true,
        ]);
        $existingLot = StockLot::query()->create([
            'product_id' => $existingProduct->id, 'location_id' => 0, 'lot_no' => 'EXISTING-LOT',
            'expires_on' => now()->addMonths(5)->toDateString(), 'received_on' => now()->subDays(3)->toDateString(),
            'unit_cost' => 10, 'qty' => 5,
        ]);
        $counter = $this->user($central, 'storekeeper', 10, [
            'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.approve',
        ]);
        $approver = $this->user($central, 'admin', 20, ['inventory.approve', 'inventory.view']);

        $this->actingAs($counter, 'sanctum');
        $started = $this->postJson('/api/inventory', ['location_id' => 0, 'note' => 'Ամսական ստուգում'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open');
        $sessionId = (int) $started->json('data.id');
        $lines = InventoryLine::query()->where('session_id', $sessionId)->get()->keyBy('product_id');
        self::assertCount(2, $lines);
        self::assertEquals(5.0, (float) $lines[$existingProduct->id]->expected_qty);
        self::assertEquals(0.0, (float) $lines[$unstockedProduct->id]->expected_qty);

        $this->putJson("/api/inventory/{$sessionId}/count", [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'գույքագրման քանակների ցանկը դաշտը պարտադիր է։');

        $this->putJson("/api/inventory/{$sessionId}/count", [
            'counts' => [
                $lines[$existingProduct->id]->id => ['counted_qty' => 4, 'reason' => 'Մեկ միավոր պակաս է'],
                $lines[$unstockedProduct->id]->id => [
                    'counted_qty' => 2,
                    'reason' => 'Հայտնաբերվել է չգրանցված մնացորդ',
                    'lot_no' => 'FOUND-LOT',
                    'expires_on' => now()->addMonths(9)->toDateString(),
                    'unit_cost' => 999,
                ],
            ],
        ])->assertOk()->assertJsonPath('data.status', 'counted');

        $this->postJson("/api/inventory/{$sessionId}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Գույքագրումը հաստատողը պետք է տարբերվի այն սկսած աշխատակցից։');

        $this->actingAs($approver, 'sanctum')
            ->postJson("/api/inventory/{$sessionId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        self::assertSame('closed', InventorySession::query()->findOrFail($sessionId)->status);
        self::assertEquals(4.0, (float) $existingLot->fresh()->qty);
        $foundLot = StockLot::query()->where('product_id', $unstockedProduct->id)->firstOrFail();
        self::assertSame('FOUND-LOT', $foundLot->lot_no);
        self::assertEquals(2.0, (float) $foundLot->qty);
        self::assertEquals(21.0, (float) $foundLot->unit_cost);
        self::assertSame(2, Movement::query()->where('reference', InventorySession::query()->findOrFail($sessionId)->inventory_no)->count());
        self::assertDatabaseHas('audit_logs', ['entity' => 'inventory_sessions', 'entity_id' => $sessionId, 'action' => 'Գույքագրումը հաստատվեց և փակվեց']);
    }

    public function test_inventory_can_reduce_an_expired_lot_without_creating_new_expired_stock(): void
    {
        [$sessionId, $line, $lot, $approver] = $this->prepareInventory(now()->subDay()->toDateString(), 5);
        $this->putJson("/api/inventory/{$sessionId}/count", [
            'counts' => [$line->id => ['counted_qty' => 4, 'reason' => 'One expired item is missing']],
        ])->assertOk();

        $this->actingAs($approver, 'sanctum')->postJson("/api/inventory/{$sessionId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'closed');

        self::assertEquals(4.0, (float) $lot->fresh()->qty);
        self::assertEquals(1.0, (float) Movement::query()->sole()->qty);
        self::assertSame(0, (int) Movement::query()->sole()->from_location);
        self::assertNull(Movement::query()->sole()->to_location);
    }

    public function test_inventory_cannot_increase_an_expired_lot(): void
    {
        [$sessionId, $line, $lot, $approver] = $this->prepareInventory(now()->subDay()->toDateString(), 5);
        $this->putJson("/api/inventory/{$sessionId}/count", [
            'counts' => [$line->id => ['counted_qty' => 6, 'reason' => 'Another expired item was found']],
        ])->assertOk();

        $this->actingAs($approver, 'sanctum')->postJson("/api/inventory/{$sessionId}/approve")
            ->assertUnprocessable();

        self::assertEquals(5.0, (float) $lot->fresh()->qty);
        self::assertSame('counted', InventorySession::query()->findOrFail($sessionId)->status);
        self::assertSame(0, Movement::query()->count());
    }

    public function test_inventory_rechecks_new_lot_expiry_when_approval_happens_after_counting(): void
    {
        $this->freezeTime();
        [$sessionId, $line, $lot, $approver] = $this->prepareInventory(null, 0);
        $this->putJson("/api/inventory/{$sessionId}/count", [
            'counts' => [$line->id => [
                'counted_qty' => 2, 'reason' => 'Previously unrecorded stock',
                'lot_no' => 'FOUND-TODAY', 'expires_on' => now()->toDateString(),
            ]],
        ])->assertOk();
        $this->travel(1)->days();

        $this->actingAs($approver, 'sanctum')->postJson("/api/inventory/{$sessionId}/approve")
            ->assertUnprocessable()->assertJsonValidationErrors('session');

        self::assertSame('counted', InventorySession::query()->findOrFail($sessionId)->status);
        self::assertSame(0, StockLot::query()->count());
        self::assertSame(0, Movement::query()->count());
    }

    public function test_inventory_approval_rejects_new_stock_lots_received_after_the_snapshot(): void
    {
        [$sessionId, $line, $lot, $approver] = $this->prepareInventory(now()->addMonths(6)->toDateString(), 5);
        $this->putJson("/api/inventory/{$sessionId}/count", [
            'counts' => [$line->id => ['counted_qty' => 4, 'reason' => 'One item is missing']],
        ])->assertOk();
        $newLot = StockLot::query()->create([
            'product_id' => $lot->product_id, 'location_id' => 0, 'lot_no' => 'ARRIVED-AFTER-COUNT',
            'expires_on' => now()->addMonths(6)->toDateString(), 'received_on' => now()->toDateString(),
            'unit_cost' => 10, 'qty' => 2,
        ]);

        $this->actingAs($approver, 'sanctum')->postJson("/api/inventory/{$sessionId}/approve")
            ->assertUnprocessable()->assertJsonValidationErrors('session');

        self::assertSame('counted', InventorySession::query()->findOrFail($sessionId)->status);
        self::assertSame('5.000', $lot->fresh()->qty);
        self::assertSame('2.000', $newLot->fresh()->qty);
        self::assertSame(0, Movement::query()->count());
    }

    public function test_inventory_detail_count_and_printable_act_reject_another_branch_with_matching_permissions(): void
    {
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $otherBranch = Branch::query()->create(['name' => 'Gyumri', 'code' => 'GYUM', 'active' => true]);
        $owner = $this->user($branch, 'inventory_owner', 10, ['inventory.view', 'inventory.edit']);
        $otherViewer = $this->user($otherBranch, 'inventory_viewer', 20, ['inventory.view', 'inventory.edit']);
        $product = Product::query()->create([
            'code' => 'PRIVATE-INVENTORY', 'name' => 'Branch inventory item', 'unit' => 'հատ',
            'purchase_price' => 35, 'active' => true,
        ]);
        $session = InventorySession::query()->create([
            'inventory_no' => 'PRIVATE-OPEN', 'location_id' => $branch->id,
            'status' => 'open', 'started_by' => $owner->id, 'started_at' => now(),
        ]);
        $line = InventoryLine::query()->create([
            'session_id' => $session->id, 'product_id' => $product->id,
            'lot_id' => null, 'expected_qty' => 0, 'counted_unit_cost' => 35,
        ]);
        $closed = InventorySession::query()->create([
            'inventory_no' => 'PRIVATE-CLOSED', 'location_id' => $branch->id,
            'status' => 'closed', 'started_by' => $owner->id, 'started_at' => now(), 'closed_at' => now(),
        ]);

        $this->actingAs($owner, 'sanctum');
        $this->getJson("/api/inventory/{$session->id}")->assertOk()
            ->assertJsonMissingPath('data.session.lines.0.counted_unit_cost')
            ->assertJsonMissingPath('data.session.lines.0.product.purchase_price');
        $this->getJson("/api/inventory/{$closed->id}/act")->assertOk();

        $this->actingAs($otherViewer, 'sanctum');
        $this->getJson("/api/inventory/{$session->id}")->assertForbidden();
        $this->putJson("/api/inventory/{$session->id}/count", [
            'counts' => [$line->id => ['counted_qty' => 0]],
        ])->assertForbidden();
        $this->getJson("/api/inventory/{$closed->id}/act")->assertForbidden();
        $this->getJson("/api/inventory/{$closed->id}/act/pdf")->assertForbidden();

        self::assertSame('open', $session->fresh()->status);
        self::assertNull($line->fresh()->counted_qty);
        self::assertSame(0, Movement::query()->count());
        self::assertSame(0, DB::table('audit_logs')->count());
    }

    private function prepareInventory(?string $expiry, float $quantity): array
    {
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $product = Product::query()->create([
            'code' => 'INV-EXPIRY', 'name' => 'Expiry-controlled item', 'unit' => 'հատ',
            'purchase_price' => 10, 'lot_control' => true, 'expiry_control' => true, 'active' => true,
        ]);
        $lot = $quantity > 0 ? StockLot::query()->create([
            'product_id' => $product->id, 'location_id' => 0, 'lot_no' => 'EXPIRED-LOT',
            'expires_on' => $expiry, 'received_on' => now()->subMonths(2)->toDateString(),
            'unit_cost' => 10, 'qty' => $quantity,
        ]) : null;
        $counter = $this->user($central, 'storekeeper', 10, ['inventory.create', 'inventory.edit']);
        $approver = $this->user($central, 'approver', 20, ['inventory.approve']);
        $sessionId = (int) $this->actingAs($counter, 'sanctum')->postJson('/api/inventory', ['location_id' => 0])
            ->assertCreated()->json('data.id');

        return [$sessionId, InventoryLine::query()->where('session_id', $sessionId)->sole(), $lot, $approver];
    }

    private function user(Branch $branch, string $roleName, int $id, array $permissionCodes): User
    {
        $role = Role::query()->create(['name' => $roleName, 'title' => $roleName]);
        foreach ($permissionCodes as $code) {
            $permission = Permission::query()->firstOrCreate(
                ['code' => $code],
                ['title' => $code, 'module' => explode('.', $code)[0]],
            );
            $role->permissions()->attach($permission);
        }

        $user = new User(['name' => $roleName, 'email' => "{$roleName}-{$id}@example.test", 'active' => true, 'branch_id' => $branch->id]);
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
            $table->boolean('active')->default(true);
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
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->string('bin_location')->nullable();
            $table->decimal('qty', 12, 3)->default(0);
        });
        Schema::create('inventory_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('inventory_no');
            $table->unsignedBigInteger('location_id');
            $table->string('status');
            $table->unsignedBigInteger('started_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('note')->nullable();
        });
        Schema::create('inventory_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->decimal('expected_qty', 12, 3);
            $table->decimal('counted_qty', 12, 3)->nullable();
            $table->string('difference_reason')->nullable();
            $table->string('counted_lot_no')->nullable();
            $table->date('counted_expires_on')->nullable();
            $table->unsignedBigInteger('counted_supplier_id')->nullable();
            $table->string('counted_bin_location')->nullable();
            $table->decimal('counted_unit_cost', 14, 2)->nullable();
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
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->string('reference')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('actor_id');
            $table->timestamp('happened_at')->nullable();
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
