<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Movement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockRequestWorkflowApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'audit_logs', 'movements', 'transfer_items', 'transfers', 'request_items',
            'stock_requests', 'stock_lots', 'products', 'role_permissions', 'permissions',
            'roles', 'branches', 'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_branch_request_moves_through_review_dispatch_receipt_and_close_via_api(): void
    {
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $product = Product::query()->create([
            'code' => 'API-REQ-001',
            'name' => 'Request workflow item',
            'unit' => 'հատ',
            'purchase_price' => 100,
            'lot_control' => true,
            'expiry_control' => true,
            'active' => true,
        ]);
        $centralLot = StockLot::query()->create([
            'product_id' => $product->id,
            'location_id' => 0,
            'lot_no' => 'CENTRAL-LOT',
            'expires_on' => now()->addMonths(6)->toDateString(),
            'received_on' => now()->subDays(2)->toDateString(),
            'unit_cost' => 100,
            'qty' => 5,
        ]);
        $branchLot = StockLot::query()->create([
            'product_id' => $product->id,
            'location_id' => $branch->id,
            'lot_no' => 'CENTRAL-LOT',
            'expires_on' => $centralLot->expires_on,
            'received_on' => now()->subDay()->toDateString(),
            'unit_cost' => 100,
            'qty' => 4,
        ]);
        $branchActor = $this->user($branch, 'branch', 10, ['requests.create', 'requests.edit', 'requests.view']);
        $centralActor = $this->user($central, 'admin', 20, ['requests.approve', 'requests.edit', 'requests.view']);
        $centralWorker = $this->user($central, 'storekeeper', 21, ['requests.edit', 'requests.view']);

        $this->actingAs($branchActor, 'sanctum');
        $create = $this->postJson('/api/requests', [
            'branch_id' => $branch->id,
            'urgency' => 'high',
            'reason' => 'Կլինիկական պաշարի համալրում',
            'submit_mode' => 'send',
            'items' => [['product_id' => $product->id, 'qty' => 2, 'note' => '']],
        ]);
        $create->assertCreated()->assertJsonPath('data.status', 'sent');
        $requestId = (int) $create->json('data.id');
        $itemId = (int) StockRequestItem::query()->where('request_id', $requestId)->value('id');

        $this->actingAs($centralWorker, 'sanctum');
        $this->postJson("/api/requests/{$requestId}/cancel")->assertForbidden();
        self::assertSame('sent', StockRequest::query()->findOrFail($requestId)->status);

        $this->actingAs($branchActor, 'sanctum');
        $this->postJson("/api/requests/{$requestId}/review", ['decision' => 'start_review'])
            ->assertForbidden();
        self::assertSame('sent', StockRequest::query()->findOrFail($requestId)->status);

        $this->actingAs($centralActor, 'sanctum');
        $this->getJson("/api/requests/{$requestId}")->assertOk()
            ->assertJsonPath('data.items.0.central_free_qty', 5)
            ->assertJsonPath('data.items.0.branch_current_qty', 4)
            ->assertJsonPath('data.items.0.branch_monthly_average', 0);
        $this->postJson("/api/requests/{$requestId}/review", ['decision' => 'start_review'])->assertOk();
        $this->postJson("/api/requests/{$requestId}/review", [
            'decision' => 'approve',
            'approved' => [$itemId => 2],
        ])->assertOk();
        $this->postJson("/api/requests/{$requestId}/collect")->assertOk();
        $this->postJson("/api/requests/{$requestId}/ready")->assertOk();
        $this->postJson("/api/requests/{$requestId}/ship")->assertOk();

        self::assertSame('shipped', StockRequest::query()->findOrFail($requestId)->status);
        self::assertEquals(3.0, (float) $centralLot->fresh()->qty);
        self::assertSame(['branch_out'], Movement::query()->pluck('type')->all());

        $this->actingAs($centralWorker, 'sanctum');
        $this->postJson("/api/requests/{$requestId}/receive")->assertForbidden();
        self::assertSame('shipped', StockRequest::query()->findOrFail($requestId)->status);

        $this->actingAs($branchActor, 'sanctum');
        $this->postJson("/api/requests/{$requestId}/receive")->assertOk();

        $this->actingAs($centralWorker, 'sanctum');
        $this->postJson("/api/requests/{$requestId}/close")->assertForbidden();
        self::assertSame('received', StockRequest::query()->findOrFail($requestId)->status);

        $this->actingAs($branchActor, 'sanctum');
        $this->postJson("/api/requests/{$requestId}/close")->assertOk();

        self::assertSame('closed', StockRequest::query()->findOrFail($requestId)->status);
        self::assertEquals(6.0, (float) $branchLot->fresh()->qty);
        self::assertSame(2, StockLot::query()->count(), 'Receipt should merge into the matching branch LOT instead of inserting a duplicate.');
        self::assertSame(['branch_out', 'branch_in'], Movement::query()->orderBy('id')->pluck('type')->all());
        self::assertSame(8, AuditLog::query()->count());
    }

    public function test_branch_request_suggestions_use_only_active_branch_stock_and_recent_internal_use(): void
    {
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $otherBranch = Branch::query()->create(['name' => 'Gyumri', 'code' => 'GYUM', 'active' => true]);
        $product = Product::query()->create([
            'code' => 'API-SUG-001', 'name' => 'Suggestion item', 'unit' => 'հատ', 'purchase_price' => 100,
            'optimal_qty' => 10, 'lot_control' => true, 'expiry_control' => true, 'active' => true,
        ]);
        foreach ([
            [$branch->id, now()->addDays(10)->toDateString(), 4],
            [$branch->id, null, 1],
            [$branch->id, now()->subDay()->toDateString(), 80],
            [$otherBranch->id, now()->addDays(10)->toDateString(), 30],
        ] as [$location, $expiresOn, $qty]) {
            StockLot::query()->create([
                'product_id' => $product->id, 'location_id' => $location, 'lot_no' => 'SUG-'.$location.'-'.$qty,
                'expires_on' => $expiresOn, 'received_on' => now()->subDays(2)->toDateString(), 'unit_cost' => 100, 'qty' => $qty,
            ]);
        }
        $activeLot = StockLot::query()->where('location_id', $branch->id)->where('qty', 4)->firstOrFail();
        foreach ([
            ['consumption', 'Ներքին օգտագործում', $branch->id, now()->subDays(20), 9],
            ['consumption', 'Ներքին օգտագործում', $branch->id, now()->subDays(100), 90],
            ['branch_in', 'Ներքին օգտագործում', $branch->id, now()->subDays(10), 40],
            ['consumption', 'Այլ պատճառ', $branch->id, now()->subDays(10), 50],
            ['consumption', 'Ներքին օգտագործում', $otherBranch->id, now()->subDays(10), 70],
        ] as [$type, $reason, $location, $happenedAt, $qty]) {
            Movement::query()->create([
                'movement_no' => 'SUG-'.bin2hex(random_bytes(3)), 'type' => $type, 'product_id' => $product->id,
                'lot_id' => $activeLot->id, 'from_location' => $location, 'to_location' => null, 'qty' => $qty,
                'unit_cost' => 100, 'reference' => 'SUG-TEST', 'reason' => $reason, 'actor_id' => 10,
                'happened_at' => $happenedAt, 'created_at' => $happenedAt,
            ]);
        }
        $branchActor = $this->user($branch, 'branch', 10, ['requests.create']);

        $response = $this->actingAs($branchActor, 'sanctum')->getJson('/api/requests/suggestions?branch_id='.$otherBranch->id);

        $response->assertOk()
            ->assertJsonPath('data.branch', $branch->id)
            ->assertJsonPath('data.items.'.$product->id.'.current', 5)
            ->assertJsonPath('data.items.'.$product->id.'.suggested', 5)
            ->assertJsonPath('data.items.'.$product->id.'.average', 3);
    }

    public function test_approval_reserves_free_central_stock_across_other_open_requests(): void
    {
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $product = Product::query()->create([
            'code' => 'API-RES-001', 'name' => 'Reserved stock product', 'unit' => 'հատ', 'purchase_price' => 100,
            'lot_control' => true, 'expiry_control' => true, 'active' => true,
        ]);
        StockLot::query()->create([
            'product_id' => $product->id, 'location_id' => 0, 'lot_no' => 'RESERVE-LOT',
            'expires_on' => now()->addMonths(6)->toDateString(), 'received_on' => now()->subDays(2)->toDateString(),
            'unit_cost' => 100, 'qty' => 5,
        ]);
        $branchActor = $this->user($branch, 'branch', 10, ['requests.create', 'requests.view']);
        $centralActor = $this->user($central, 'admin', 20, ['requests.approve', 'requests.view']);

        $this->actingAs($branchActor, 'sanctum');
        $first = $this->postJson('/api/requests', [
            'branch_id' => $branch->id,
            'urgency' => 'normal',
            'reason' => 'Առաջին պահանջագիր',
            'submit_mode' => 'send',
            'items' => [['product_id' => $product->id, 'qty' => 4]],
        ])->assertCreated();
        $firstId = (int) $first->json('data.id');
        $firstItemId = (int) StockRequestItem::query()->where('request_id', $firstId)->value('id');

        $this->actingAs($centralActor, 'sanctum');
        $this->postJson("/api/requests/{$firstId}/review", ['decision' => 'approve', 'approved' => [$firstItemId => 4]])->assertOk();

        $this->actingAs($branchActor, 'sanctum');
        $second = $this->postJson('/api/requests', [
            'branch_id' => $branch->id,
            'urgency' => 'normal',
            'reason' => 'Երկրորդ պահանջագիր',
            'submit_mode' => 'send',
            'items' => [['product_id' => $product->id, 'qty' => 2]],
        ])->assertCreated();
        $secondId = (int) $second->json('data.id');
        $secondItemId = (int) StockRequestItem::query()->where('request_id', $secondId)->value('id');

        $this->actingAs($centralActor, 'sanctum');
        $this->postJson("/api/requests/{$secondId}/review", ['decision' => 'approve', 'approved' => [$secondItemId => 2]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approved');

        self::assertSame('sent', StockRequest::query()->findOrFail($secondId)->status);
        self::assertEquals(0.0, (float) StockRequestItem::query()->findOrFail($secondItemId)->approved_qty);
        self::assertEquals(5.0, (float) StockLot::query()->where('location_id', 0)->value('qty'));
    }

    public function test_request_suggestions_require_the_create_permission(): void
    {
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $viewer = $this->user($branch, 'branch', 10, ['requests.view']);

        $this->actingAs($viewer, 'sanctum')->getJson('/api/requests/suggestions?branch_id='.$branch->id)->assertForbidden();
    }

    public function test_branch_scoped_admin_cannot_manage_requests_from_another_branch(): void
    {
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $otherBranch = Branch::query()->create(['name' => 'Gyumri', 'code' => 'GYUM', 'active' => true]);
        $product = Product::query()->create([
            'code' => 'API-ADMIN-SCOPE', 'name' => 'Scope item', 'unit' => 'հատ', 'purchase_price' => 100,
            'lot_control' => true, 'expiry_control' => true, 'active' => true,
        ]);
        $requestOwner = $this->user($branch, 'branch', 10, ['requests.create']);
        $branchAdmin = $this->user($otherBranch, 'admin', 20, ['requests.edit', 'requests.view']);

        $draft = StockRequest::query()->create([
            'request_no' => 'SCOPE-DRAFT', 'branch_id' => $branch->id, 'requested_by' => $requestOwner->id,
            'status' => 'draft', 'urgency' => 'normal', 'reason' => 'Draft', 'created_at' => now(),
        ]);
        StockRequestItem::query()->create([
            'request_id' => $draft->id, 'product_id' => $product->id, 'requested_qty' => 1, 'approved_qty' => 0,
        ]);
        $sent = StockRequest::query()->create([
            'request_no' => 'SCOPE-SENT', 'branch_id' => $branch->id, 'requested_by' => $requestOwner->id,
            'status' => 'sent', 'urgency' => 'normal', 'reason' => 'Sent', 'created_at' => now(),
        ]);
        $shipped = StockRequest::query()->create([
            'request_no' => 'SCOPE-SHIPPED', 'branch_id' => $branch->id, 'requested_by' => $requestOwner->id,
            'status' => 'shipped', 'urgency' => 'normal', 'reason' => 'Shipped', 'created_at' => now(),
        ]);
        $received = StockRequest::query()->create([
            'request_no' => 'SCOPE-RECEIVED', 'branch_id' => $branch->id, 'requested_by' => $requestOwner->id,
            'status' => 'received', 'urgency' => 'normal', 'reason' => 'Received', 'created_at' => now(),
        ]);

        $this->actingAs($branchAdmin, 'sanctum');
        $this->putJson("/api/requests/{$draft->id}/draft", [
            'urgency' => 'urgent', 'reason' => 'Cross-branch update attempt', 'submit_mode' => 'send',
            'items' => [['product_id' => $product->id, 'qty' => 2]],
        ])->assertForbidden();
        $this->postJson("/api/requests/{$sent->id}/cancel")->assertForbidden();
        $this->postJson("/api/requests/{$shipped->id}/receive")->assertForbidden();
        $this->postJson("/api/requests/{$received->id}/close")->assertForbidden();

        self::assertSame('draft', $draft->fresh()->status);
        self::assertSame('sent', $sent->fresh()->status);
        self::assertSame('shipped', $shipped->fresh()->status);
        self::assertSame('received', $received->fresh()->status);
        self::assertEquals(1.0, (float) $draft->items()->firstOrFail()->requested_qty);
    }

    public function test_central_admin_can_close_a_received_branch_request(): void
    {
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $owner = $this->user($branch, 'branch', 10, ['requests.create']);
        $centralAdmin = $this->user($central, 'admin', 20, ['requests.edit']);
        $request = StockRequest::query()->create([
            'request_no' => 'CENTRAL-ADMIN-CLOSE', 'branch_id' => $branch->id, 'requested_by' => $owner->id,
            'status' => 'received', 'urgency' => 'normal', 'reason' => 'Received', 'created_at' => now(),
        ]);

        $this->actingAs($centralAdmin, 'sanctum')
            ->postJson("/api/requests/{$request->id}/close")
            ->assertOk();

        self::assertSame('closed', $request->fresh()->status);
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
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
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
            $table->decimal('optimal_qty', 12, 3)->default(0);
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
        Schema::create('stock_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_no');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('requested_by');
            $table->string('status');
            $table->string('urgency');
            $table->text('reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('request_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('requested_qty', 12, 3);
            $table->decimal('approved_qty', 12, 3);
            $table->string('note')->nullable();
        });
        Schema::create('transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
            $table->unsignedBigInteger('from_branch');
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
