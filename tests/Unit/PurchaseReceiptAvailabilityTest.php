<?php

namespace Tests\Unit;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Repositories\PageDataRepository;
use App\Services\PageDataService;
use App\Support\WorkflowStatus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseReceiptAvailabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('suppliers', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
        });
        Schema::create('purchase_orders', static function (Blueprint $table): void {
            $table->id();
            $table->string('order_no');
            $table->unsignedBigInteger('supplier_id');
            $table->string('status');
            $table->date('ordered_on');
            $table->date('expected_on')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('purchase_order_items', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('product_id')->default(1);
            $table->decimal('ordered_qty', 12, 3);
            $table->decimal('received_qty', 12, 3);
            $table->decimal('unit_cost', 14, 2)->default(0);
        });
    }

    protected function tearDown(): void
    {
        foreach (['purchase_order_items', 'purchase_orders', 'suppliers'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_purchase_page_returns_boolean_remaining_quantity_metadata_without_changing_statuses(): void
    {
        $expected = $this->orders();
        $screen = $this->service()->page('purchases', 0, [], $this->actor());

        self::assertCount(count($expected), $screen['data']);
        foreach ($screen['data'] as $row) {
            self::assertIsBool($row->has_remaining_items);
            self::assertSame($expected[$row->order_no]['remaining'], $row->has_remaining_items, $row->order_no);
            self::assertSame($expected[$row->order_no]['status'], $row->status);
            self::assertSame($expected[$row->order_no]['remaining'], $row->toArray()['has_remaining_items']);
            self::assertArrayNotHasKey('items', $row->toArray());
        }
        self::assertSame(5, PurchaseOrder::query()->count());
        self::assertSame(7, PurchaseOrderItem::query()->count());
    }

    public function test_remaining_quantity_metadata_does_not_become_an_exported_column(): void
    {
        $expected = $this->orders();
        $export = $this->service()->export('purchases', 0, [], $this->actor());
        $keys = array_keys($export['columns']);
        $rows = iterator_to_array($export['rows']);

        self::assertSame(['order_no', 'supplier', 'status', 'ordered_on', 'expected_on', 'created_at'], $keys);
        self::assertCount(count($expected), $rows);
        foreach ($rows as $row) {
            self::assertCount(count($keys), $row);
            $values = array_combine($keys, $row);
            self::assertArrayNotHasKey('has_remaining_items', $values);
            self::assertSame(WorkflowStatus::label('purchases', $expected[$values['order_no']]['status']), $values['status']);
        }

        [$productColumns] = (new PageDataRepository)->definition('products', 0);
        self::assertSame('Ապրանքի տեսակ', $productColumns['category']);
    }

    private function orders(): array
    {
        $supplier = Supplier::query()->create(['name' => 'Supplier', 'active' => true]);
        $cases = [
            'FULL' => ['approved', false, [['3.000', '3.000'], ['2.000', '2.000']]],
            'EMPTY' => ['approved', false, []],
            'PENDING' => ['pending', true, [['2.000', '0.000']]],
            'PARTIAL' => ['approved', true, [['7.000', '7.000'], ['10.000', '4.000']]],
            'RESIDUAL' => ['approved', true, [['1.000', '0.999'], ['2.000', '2.000']]],
        ];
        $expected = [];
        foreach ($cases as $number => [$status, $remaining, $items]) {
            $order = PurchaseOrder::query()->create([
                'order_no' => $number, 'supplier_id' => $supplier->id, 'status' => $status, 'ordered_on' => '2026-10-03',
            ]);
            foreach ($items as [$ordered, $received]) {
                PurchaseOrderItem::query()->create([
                    'purchase_order_id' => $order->id, 'ordered_qty' => $ordered, 'received_qty' => $received,
                ]);
            }
            $expected[$number] = ['status' => $status, 'remaining' => $remaining];
        }

        return $expected;
    }

    private function service(): PageDataService
    {
        return new PageDataService(new PageDataRepository);
    }

    private function actor(): User
    {
        $actor = $this->createMock(User::class);
        $actor->method('hasPermissionCode')->willReturn(true);
        $actor->method('currentLocationId')->willReturn(0);

        return $actor;
    }
}
