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
            'role_permissions', 'permissions', 'users', 'roles', 'branches',
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
