<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Movement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchasingWorkflowApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'audit_logs', 'movements', 'receipt_items', 'receipts', 'stock_lots',
            'purchase_order_items', 'purchase_orders', 'products', 'suppliers',
            'role_permissions', 'permissions', 'roles', 'branches', 'users', 'stock_requests',
            'request_items', 'transfers', 'transfer_items', 'categories',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_purchase_order_approval_and_multi_lot_receipt_work_through_the_api(): void
    {
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $supplier = Supplier::query()->create(['name' => 'QA supplier', 'active' => true]);
        $product = Product::query()->create([
            'code' => 'API-QA-001',
            'name' => 'Expiry controlled item',
            'unit' => 'հատ',
            'purchase_price' => 100,
            'lot_control' => true,
            'expiry_control' => true,
            'active' => true,
        ]);
        $actor = $this->centralActor($central);
        $this->actingAs($actor, 'sanctum');

        $orderResponse = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'ordered_on' => now()->toDateString(),
            'expected_on' => null,
            'note' => 'API workflow test',
            'items' => [['product_id' => $product->id, 'qty' => 5, 'unit_cost' => 100]],
        ]);

        $orderResponse->assertCreated()->assertJsonPath('data.status', 'pending');
        $orderId = (int) $orderResponse->json('data.id');
        $orderItemId = (int) PurchaseOrderItem::query()
            ->where('purchase_order_id', $orderId)
            ->value('id');

        $this->postJson("/api/purchases/{$orderId}/approve")
            ->assertOk()
            ->assertJsonPath('message', 'Գնման պատվերը հաստատվեց։');

        $receiptResponse = $this->postJson('/api/receipts', [
            'purchase_order_id' => $orderId,
            'received_on' => now()->toDateString(),
            'invoice_no' => 'QA-INV-1',
            'items' => [
                ['purchase_order_item_id' => $orderItemId, 'qty' => 3, 'lot_no' => 'LOT-A', 'expires_on' => now()->addMonths(6)->toDateString()],
                ['purchase_order_item_id' => $orderItemId, 'qty' => 2, 'lot_no' => 'LOT-B', 'expires_on' => now()->addMonths(12)->toDateString()],
            ],
        ]);

        $receiptResponse->assertCreated()->assertJsonPath('data.id', 1);
        self::assertSame('approved', PurchaseOrder::query()->findOrFail($orderId)->status);
        self::assertEquals(5.0, (float) PurchaseOrderItem::query()->findOrFail($orderItemId)->received_qty);
        self::assertSame(['LOT-A', 'LOT-B'], StockLot::query()->orderBy('id')->pluck('lot_no')->all());
        self::assertSame(2, ReceiptItem::query()->count());
        self::assertSame(2, Movement::query()->count());

        $this->postJson('/api/receipts', [
            'purchase_order_id' => $orderId,
            'received_on' => now()->toDateString(),
            'items' => [[
                'purchase_order_item_id' => $orderItemId,
                'qty' => 0.001,
                'lot_no' => 'LOT-EXCESS',
                'expires_on' => now()->addMonths(6)->toDateString(),
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        self::assertSame(1, Receipt::query()->where('purchase_order_id', $orderId)->count());
        self::assertSame(2, StockLot::query()->count());
        self::assertSame(2, Movement::query()->count());
    }

    public function test_supplier_purchase_to_branch_consumption_and_stock_report_work_as_one_api_lifecycle(): void
    {
        $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $category = Category::query()->create(['name' => 'Reagents']);
        $supplier = Supplier::query()->create(['name' => 'Verified QA supplier', 'active' => true]);
        $product = Product::query()->create([
            'code' => 'E2E-LOT-001', 'name' => 'Lifecycle reagent', 'unit' => 'հատ', 'purchase_price' => 100,
            'category_id' => $category->id, 'supplier_id' => $supplier->id, 'min_qty' => 0, 'optimal_qty' => 5, 'max_qty' => 20,
            'lot_control' => true, 'expiry_control' => true, 'active' => true,
        ]);
        $admin = $this->persistedActor($central, 'admin', 'lifecycle-admin@example.test', [
            'purchases.create', 'purchases.approve', 'receipts.create', 'requests.view', 'requests.approve', 'requests.edit', 'reports.view',
        ]);
        $branchUser = $this->persistedActor($branch, 'branch', 'lifecycle-branch@example.test', [
            'requests.create', 'requests.view', 'requests.edit', 'stock.create',
        ]);

        $this->actingAs($admin, 'sanctum');
        $order = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'ordered_on' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'qty' => 5, 'unit_cost' => 100]],
        ])->assertCreated()->assertJsonPath('data.status', 'pending');
        $orderId = (int) $order->json('data.id');
        $orderItemId = (int) PurchaseOrderItem::query()->where('purchase_order_id', $orderId)->value('id');
        $this->postJson("/api/purchases/{$orderId}/approve")->assertOk();
        $this->postJson('/api/receipts', [
            'purchase_order_id' => $orderId,
            'received_on' => now()->toDateString(),
            'invoice_no' => 'E2E-INVOICE-1',
            'items' => [[
                'purchase_order_item_id' => $orderItemId,
                'qty' => 5,
                'lot_no' => 'E2E-LOT-A',
                'expires_on' => now()->addMonths(8)->toDateString(),
            ]],
        ])->assertCreated();

        $this->actingAs($branchUser, 'sanctum');
        $request = $this->postJson('/api/requests', [
            'branch_id' => $branch->id, 'urgency' => 'normal', 'reason' => 'Lifecycle verification', 'submit_mode' => 'send',
            'items' => [['product_id' => $product->id, 'qty' => 3, 'note' => '']],
        ])->assertCreated()->assertJsonPath('data.status', 'sent');
        $requestId = (int) $request->json('data.id');
        $requestItemId = (int) StockRequestItem::query()->where('request_id', $requestId)->value('id');

        $this->actingAs($admin, 'sanctum');
        $this->postJson("/api/requests/{$requestId}/review", ['decision' => 'start_review'])->assertOk();
        $this->postJson("/api/requests/{$requestId}/review", [
            'decision' => 'approve', 'approved' => [$requestItemId => 3],
        ])->assertOk();
        $this->postJson("/api/requests/{$requestId}/collect")->assertOk();
        $this->postJson("/api/requests/{$requestId}/ready")->assertOk();
        $this->postJson("/api/requests/{$requestId}/ship")->assertOk();

        $this->actingAs($branchUser, 'sanctum');
        $this->postJson("/api/requests/{$requestId}/receive")->assertOk();
        $this->postJson("/api/requests/{$requestId}/close")->assertOk();
        $this->postJson('/api/stock/consume', [
            'product_id' => $product->id, 'qty' => 1, 'issue_type' => 'usage',
        ])->assertOk();

        self::assertSame('closed', StockRequest::query()->findOrFail($requestId)->status);
        $centralLot = StockLot::query()->where('location_id', 0)->firstOrFail();
        $branchLot = StockLot::query()->where('location_id', $branch->id)->firstOrFail();
        self::assertEquals(5.0, (float) PurchaseOrderItem::query()->findOrFail($orderItemId)->received_qty);
        self::assertSame((int) $supplier->id, (int) $centralLot->supplier_id);
        self::assertSame($orderId, (int) $centralLot->purchase_order_id);
        self::assertSame('E2E-LOT-A', $centralLot->lot_no);
        self::assertEquals(2.0, (float) $centralLot->qty);
        self::assertSame($centralLot->lot_no, $branchLot->lot_no);
        self::assertSame($centralLot->expires_on->toDateString(), $branchLot->expires_on->toDateString());
        self::assertSame((int) $supplier->id, (int) $branchLot->supplier_id);
        self::assertEquals(2.0, (float) $branchLot->qty);
        self::assertSame(['receipt', 'branch_out', 'branch_in', 'consumption'], Movement::query()->orderBy('id')->pluck('type')->all());

        $this->actingAs($admin, 'sanctum');
        $report = $this->getJson('/api/reports?report_type=stock_by_location&product_id='.$product->id)->assertOk();
        $report->assertJsonPath('report_type', 'stock_by_location');
        $reported = collect($report->json('data'))->keyBy('branch_name');
        self::assertEquals(2.0, (float) $reported['Կենտրոնական պահեստ']['quantity']);
        self::assertEquals(2.0, (float) $reported['Erebuni']['quantity']);
    }

    public function test_branch_role_cannot_approve_a_purchase_through_the_api_even_if_granted(): void
    {
        $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
        $role = Role::query()->create(['name' => 'branch', 'title' => 'Մասնաճյուղի պատասխանատու']);
        $permission = Permission::query()->create([
            'code' => 'purchases.approve',
            'title' => 'Գնում — Հաստատել',
            'module' => 'purchases',
        ]);
        $role->permissions()->attach($permission);

        $actor = new User(['active' => true, 'branch_id' => $branch->id]);
        $actor->setAttribute('id', 2);
        $actor->setRelation('branch', $branch);
        $actor->setRelation('role', $role);

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/purchases/1/approve')
            ->assertForbidden();
    }

    private function centralActor(Branch $branch): User
    {
        $role = Role::query()->create(['name' => 'admin', 'title' => 'Համակարգի ադմինիստրատոր']);
        foreach (['purchases.create', 'purchases.approve', 'receipts.create'] as $code) {
            Permission::query()->create(['code' => $code, 'title' => $code, 'module' => explode('.', $code)[0]]);
        }

        $actor = new User(['active' => true, 'branch_id' => $branch->id]);
        $actor->setAttribute('id', 1);
        $actor->setRelation('branch', $branch);
        $actor->setRelation('role', $role);

        return $actor;
    }

    /** @param list<string> $permissions */
    private function persistedActor(Branch $branch, string $roleName, string $email, array $permissions): User
    {
        $role = Role::query()->create(['name' => $roleName, 'title' => $roleName]);
        foreach ($permissions as $code) {
            $permission = Permission::query()->firstOrCreate(
                ['code' => $code],
                ['title' => $code, 'module' => explode('.', $code)[0]],
            );
            $role->permissions()->attach($permission);
        }

        return User::query()->create([
            'name' => $roleName,
            'email' => $email,
            'password' => 'test-password',
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'active' => true,
        ]);
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
            $table->string('password');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('branch_id');
            $table->boolean('active');
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('active');
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('unit');
            $table->decimal('purchase_price', 14, 2);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->decimal('min_qty', 12, 3)->default(0);
            $table->decimal('optimal_qty', 12, 3)->default(0);
            $table->decimal('max_qty', 12, 3)->default(0);
            $table->boolean('lot_control');
            $table->boolean('expiry_control');
            $table->boolean('active');
        });
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_no');
            $table->unsignedBigInteger('supplier_id');
            $table->string('status');
            $table->date('ordered_on');
            $table->date('expected_on')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->text('note')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('ordered_qty', 12, 3);
            $table->decimal('received_qty', 12, 3);
            $table->decimal('unit_cost', 14, 2);
        });
        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_no');
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->unsignedBigInteger('supplier_id');
            $table->string('invoice_no')->nullable();
            $table->string('contract_no')->nullable();
            $table->unsignedBigInteger('received_by');
            $table->date('received_on');
            $table->text('note')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('receipt_id');
            $table->unsignedBigInteger('purchase_order_item_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 14, 2);
        });
        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->unsignedBigInteger('location_id');
            $table->string('lot_no');
            $table->date('expires_on')->nullable();
            $table->date('received_on');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->decimal('unit_cost', 14, 2);
            $table->string('bin_location')->nullable();
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
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id');
            $table->string('action');
            $table->string('entity');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('ip_address')->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }
}
