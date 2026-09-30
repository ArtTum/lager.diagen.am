<?php

namespace Tests\Unit;

use App\Http\Requests\Api\StoreReceiptRequest;
use App\Models\Branch;
use App\Models\Movement;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\User;
use App\Repositories\PurchasingRepository;
use App\Services\PurchasingService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PurchasingWorkflowTest extends TestCase
{
    public function test_purchase_order_can_be_approved_and_received_across_multiple_lots(): void
    {
        $this->createTables();

        try {
            $central = Branch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'active' => true]);
            $branch = Branch::query()->create(['name' => 'Erebuni', 'code' => 'EREB', 'active' => true]);
            $supplier = Supplier::query()->create(['name' => 'Test supplier', 'active' => true]);
            $product = Product::query()->create([
                'code' => 'QA-LOT-001', 'name' => 'Expiry controlled item', 'unit' => 'հատ',
                'purchase_price' => 100, 'lot_control' => true, 'expiry_control' => true, 'active' => true,
            ]);
            $centralActor = $this->user(20, $central, 'admin');
            $branchActor = $this->user(21, $branch, 'branch');
            $service = new PurchasingService(new PurchasingRepository);

            $created = $service->createOrder($centralActor, '127.0.0.1', [
                'supplier_id' => $supplier->id,
                'ordered_on' => now()->toDateString(),
                'expected_on' => null,
                'note' => 'Integration test order',
                'items' => [[
                    'product_id' => $product->id,
                    'qty' => 10,
                    'unit_cost' => 100,
                ]],
            ]);
            $orderId = (int) $created['id'];
            $orderItem = PurchaseOrderItem::query()->where('purchase_order_id', $orderId)->firstOrFail();

            self::assertSame('pending', $created['status']);
            $service->approve($orderId, $centralActor, '127.0.0.1');
            self::assertSame('approved', PurchaseOrder::query()->findOrFail($orderId)->status);

            $partialReceipt = [
                'purchase_order_id' => $orderId,
                'received_on' => now()->toDateString(),
                'invoice_no' => 'INV-001',
                'contract_no' => '',
                'note' => '',
                // A single purchase-order line may be delivered in different LOTs.
                'items' => [
                    ['purchase_order_item_id' => $orderItem->id, 'qty' => 3, 'lot_no' => 'LOT-A', 'expires_on' => now()->addMonths(6)->toDateString(), 'bin_location' => 'A-01-01'],
                    ['purchase_order_item_id' => $orderItem->id, 'qty' => 2, 'lot_no' => 'LOT-B', 'expires_on' => now()->addMonths(12)->toDateString(), 'bin_location' => 'A-01-02'],
                ],
            ];
            self::assertTrue(Validator::make($partialReceipt, (new StoreReceiptRequest)->rules())->passes());

            try {
                $service->receive($branchActor, '127.0.0.1', $partialReceipt);
                self::fail('A branch user must not post a central supplier receipt.');
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->getStatusCode());
                self::assertSame(0, Receipt::query()->count());
            }

            $first = $service->receive($centralActor, '127.0.0.1', $partialReceipt);
            self::assertNotEmpty($first['receipt_no']);
            self::assertEquals(5.0, (float) $orderItem->fresh()->received_qty);
            self::assertEquals(5.0, (float) StockLot::query()->where('purchase_order_id', $orderId)->sum('qty'));
            self::assertSame(['LOT-A', 'LOT-B'], StockLot::query()->orderBy('id')->pluck('lot_no')->all());
            self::assertSame(2, ReceiptItem::query()->where('receipt_id', $first['id'])->count());

            try {
                $service->receive($centralActor, '127.0.0.1', [
                    ...$partialReceipt,
                    'items' => [
                        ['purchase_order_item_id' => $orderItem->id, 'qty' => 3, 'lot_no' => 'LOT-TOO-MUCH-A', 'expires_on' => now()->addMonths(6)->toDateString()],
                        ['purchase_order_item_id' => $orderItem->id, 'qty' => 3, 'lot_no' => 'LOT-TOO-MUCH-B', 'expires_on' => now()->addMonths(12)->toDateString()],
                    ],
                ]);
                self::fail('Combined quantities across repeated purchase-order lines must be checked.');
            } catch (ValidationException) {
                self::assertSame(1, Receipt::query()->where('purchase_order_id', $orderId)->count());
                self::assertSame(2, Movement::query()->count());
                self::assertSame(2, StockLot::query()->count());
                self::assertEquals(5.0, (float) $orderItem->fresh()->received_qty);
            }

            $service->receive($centralActor, '127.0.0.1', [
                'purchase_order_id' => $orderId,
                'received_on' => now()->toDateString(),
                'invoice_no' => 'INV-002',
                'items' => [[
                    'purchase_order_item_id' => $orderItem->id,
                    'qty' => 5,
                    'lot_no' => 'LOT-C',
                    'expires_on' => now()->addMonths(18)->toDateString(),
                ]],
            ]);

            self::assertEquals(10.0, (float) $orderItem->fresh()->received_qty);
            self::assertEquals(10.0, (float) StockLot::query()->where('purchase_order_id', $orderId)->sum('qty'));
            self::assertSame(2, Receipt::query()->where('purchase_order_id', $orderId)->count());
            self::assertSame(3, ReceiptItem::query()->count());
            self::assertSame(3, Movement::query()->count());
            self::assertSame(['receipt', 'receipt', 'receipt'], Movement::query()->orderBy('id')->pluck('type')->all());

            try {
                $service->receive($centralActor, '127.0.0.1', [
                    'purchase_order_id' => $orderId,
                    'received_on' => now()->toDateString(),
                    'items' => [[
                        'purchase_order_item_id' => $orderItem->id,
                        'qty' => 0.001,
                        'lot_no' => 'LOT-EXCESS',
                        'expires_on' => now()->addMonths(18)->toDateString(),
                    ]],
                ]);
                self::fail('The system must reject receipt above the ordered quantity.');
            } catch (ValidationException) {
                self::assertSame(2, Receipt::query()->where('purchase_order_id', $orderId)->count());
                self::assertSame(3, Movement::query()->count());
                self::assertSame(3, StockLot::query()->count());
            }
        } finally {
            foreach (['audit_logs', 'movements', 'receipt_items', 'receipts', 'stock_lots', 'purchase_order_items', 'purchase_orders', 'products', 'suppliers', 'branches'] as $table) {
                Schema::dropIfExists($table);
            }
        }
    }

    private function user(int $id, Branch $branch, string $roleName): User
    {
        $user = new User(['active' => true, 'branch_id' => $branch->id]);
        $user->setAttribute('id', $id);
        $user->setRelation('branch', $branch);
        $user->setRelation('role', new Role(['name' => $roleName]));

        return $user;
    }

    private function createTables(): void
    {
        Schema::create('branches', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->boolean('active');
        });
        Schema::create('suppliers', function ($table): void {
            $table->id();
            $table->string('name');
            $table->boolean('active');
        });
        Schema::create('products', function ($table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('unit');
            $table->decimal('purchase_price', 14, 2);
            $table->boolean('lot_control');
            $table->boolean('expiry_control');
            $table->boolean('active');
        });
        Schema::create('purchase_orders', function ($table): void {
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
        Schema::create('purchase_order_items', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('ordered_qty', 12, 3);
            $table->decimal('received_qty', 12, 3);
            $table->decimal('unit_cost', 14, 2);
        });
        Schema::create('receipts', function ($table): void {
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
        Schema::create('receipt_items', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('receipt_id');
            $table->unsignedBigInteger('purchase_order_item_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 14, 2);
        });
        Schema::create('stock_lots', function ($table): void {
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
        Schema::create('movements', function ($table): void {
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
        Schema::create('audit_logs', function ($table): void {
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
