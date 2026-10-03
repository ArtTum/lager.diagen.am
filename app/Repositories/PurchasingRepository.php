<?php

namespace App\Repositories;

use App\Models\AuditLog;
use App\Models\Movement;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\StockLot;
use App\Models\Supplier;

class PurchasingRepository
{
    public function options(bool $forReceipts): array
    {
        $data = [
            'suppliers' => Supplier::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()->where('active', true)->orderBy('name')->get(['id', 'code', 'name', 'unit', 'expiry_control']),
        ];
        if ($forReceipts) {
            $data['orders'] = PurchaseOrder::query()->with(['supplier', 'items' => fn ($query) => $query->with('product')->whereColumn('received_qty', '<', 'ordered_qty')])
                ->where('status', 'approved')->whereHas('items', fn ($query) => $query->whereColumn('received_qty', '<', 'ordered_qty'))
                ->orderByDesc('id')->get()->map(static function (PurchaseOrder $order): array {
                    return [
                        'id' => $order->id,
                        'order_no' => $order->order_no,
                        'supplier' => $order->supplier?->name,
                        'supplier_id' => $order->supplier_id,
                        'items' => $order->items->map(static fn (PurchaseOrderItem $item): array => [
                            'id' => $item->id, 'product_id' => $item->product_id, 'code' => $item->product?->code,
                            'name' => $item->product?->name, 'unit' => $item->product?->unit, 'expiry_control' => (bool) $item->product?->expiry_control,
                            'ordered_qty' => (float) $item->ordered_qty, 'received_qty' => (float) $item->received_qty, 'unit_cost' => (float) $item->unit_cost,
                        ])->values()->all(),
                    ];
                })->values();
        }

        return $data;
    }

    public function activeSupplier(int $id): bool
    {
        return Supplier::query()->whereKey($id)->where('active', true)->exists();
    }

    public function activeProduct(int $id): bool
    {
        return Product::query()->whereKey($id)->where('active', true)->exists();
    }

    public function createOrder(array $attributes): PurchaseOrder
    {
        return PurchaseOrder::query()->create($attributes);
    }

    public function createOrderItems(int $orderId, array $items): void
    {
        foreach ($items as $line) {
            PurchaseOrderItem::query()->create(['purchase_order_id' => $orderId, 'product_id' => $line['product_id'],
                'ordered_qty' => $line['qty'], 'received_qty' => 0, 'unit_cost' => $line['unit_cost']]);
        }
    }

    public function lockOrder(int $id): ?PurchaseOrder
    {
        return PurchaseOrder::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function updateOrder(int $id, array $attributes): int
    {
        return PurchaseOrder::query()->whereKey($id)->update($attributes);
    }

    public function order(int $id): ?PurchaseOrder
    {
        return PurchaseOrder::query()->find($id);
    }

    public function orderItemForUpdate(int $id, int $orderId): ?PurchaseOrderItem
    {
        return PurchaseOrderItem::query()->with('product')->whereKey($id)->where('purchase_order_id', $orderId)->lockForUpdate()->first();
    }

    public function incrementReceived(int $id, float $qty): int
    {
        return PurchaseOrderItem::query()->whereKey($id)->increment('received_qty', $qty);
    }

    public function createReceipt(array $attributes): Receipt
    {
        return Receipt::query()->create($attributes);
    }

    public function createReceiptItem(array $attributes): ReceiptItem
    {
        return ReceiptItem::query()->create($attributes);
    }

    public function createLot(array $attributes): StockLot
    {
        return StockLot::query()->create($attributes);
    }

    public function movement(int $actor, int $product, int $lot, float $qty, float $cost, string $reference): void
    {
        Movement::query()->create(['movement_no' => 'ՇԱՐԺ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))), 'type' => 'receipt',
            'product_id' => $product, 'lot_id' => $lot, 'from_location' => null, 'to_location' => 0, 'qty' => $qty, 'unit_cost' => $cost,
            'reference' => $reference, 'reason' => 'Հաստատված գնման պատվերից ապրանքի ընդունում', 'actor_id' => $actor, 'happened_at' => now(), 'created_at' => now()]);
    }

    public function audit(int $actor, string $action, string $entity, int $id, ?array $before, ?array $after, ?string $ip): void
    {
        AuditLog::query()->create(['actor_id' => $actor, 'action' => $action, 'entity' => $entity, 'entity_id' => $id,
            'before_data' => $before, 'after_data' => $after, 'ip_address' => $ip, 'created_at' => now()]);
    }
}
