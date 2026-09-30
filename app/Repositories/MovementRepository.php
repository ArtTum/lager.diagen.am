<?php

namespace App\Repositories;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Movement;
use App\Models\MovementCorrection;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\ReceiptItem;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class MovementRepository
{
    public function query(bool $includeSupplier = true): Builder
    {
        return Movement::query()->leftJoin('products as p', 'p.id', '=', 'movements.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')->leftJoin('stock_lots as l', 'l.id', '=', 'movements.lot_id')
            ->leftJoin('branches as f', 'f.id', '=', 'movements.from_location')->leftJoin('branches as t', 't.id', '=', 'movements.to_location')
            ->leftJoin('suppliers as s', 's.id', '=', 'l.supplier_id')->leftJoin('users as u', 'u.id', '=', 'movements.actor_id')
            ->leftJoin('movement_corrections as mc', 'mc.movement_id', '=', 'movements.id')
            ->select('movements.id', 'movements.movement_no', 'movements.type', 'movements.product_id', 'p.code as product_code', 'p.name as product', 'c.name as category',
                'movements.lot_id', 'l.lot_no', 'movements.from_location', 'movements.to_location',
                'movements.qty', 'movements.unit_cost', 'movements.reference', 'movements.reason',
                'movements.actor_id', 'u.name as actor', 'movements.happened_at', 'mc.correction_no')
            ->selectRaw($includeSupplier ? 's.name as supplier, l.supplier_id as supplier_id' : 'NULL as supplier, NULL as supplier_id')
            ->selectRaw("CASE WHEN movements.from_location = 0 THEN 'Կենտրոնական պահեստ' WHEN movements.from_location IS NULL THEN 'Դրսից' ELSE COALESCE(f.name, 'Անհայտ պահեստ') END as from_branch")
            ->selectRaw("CASE WHEN movements.to_location = 0 THEN 'Կենտրոնական պահեստ' WHEN movements.to_location IS NULL THEN 'Դուրս' ELSE COALESCE(t.name, 'Անհայտ պահեստ') END as to_branch");
    }

    public function filters(int $locationId, bool $includeSuppliers): array
    {
        $visibleMovements = static fn () => Movement::query()->when($locationId > 0, fn ($query) => $query->where(
            fn ($scope) => $scope->where('from_location', $locationId)->orWhere('to_location', $locationId),
        ));
        $productIds = static fn () => $visibleMovements()->select('product_id')->distinct();
        $lotIds = static fn () => $visibleMovements()->whereNotNull('lot_id')->select('lot_id')->distinct();

        return ['products' => Product::query()->whereIn('id', $productIds())->orderBy('name')->get(['id', 'code', 'name']),
            'categories' => Category::query()->whereIn('id', Product::query()->whereIn('id', $productIds())->select('category_id'))
                ->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'suppliers' => $includeSuppliers
                ? Supplier::query()->whereIn('id', StockLot::query()->whereIn('id', $lotIds())->whereNotNull('supplier_id')->select('supplier_id'))
                    ->orderBy('name')->get(['id', 'name'])
                : collect(),
            'users' => User::query()->where('active', true)->whereIn('id', $visibleMovements()->whereNotNull('actor_id')->select('actor_id'))
                ->orderBy('name')->get(['id', 'name']),
            'types' => $visibleMovements()->select('type')->distinct()->orderBy('type')->pluck('type')];
    }

    public function locked(int $id): ?Movement
    {
        return Movement::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function correctionExists(int $id): bool
    {
        return MovementCorrection::query()->where('movement_id', $id)->exists();
    }

    public function lockedLot(int $id, int $productId, int $location): ?StockLot
    {
        return StockLot::query()->whereKey($id)->where('product_id', $productId)->where('location_id', $location)->lockForUpdate()->first();
    }

    public function lotQuantity(int $id): float
    {
        return (float) StockLot::query()->whereKey($id)->value('qty');
    }

    public function increaseLot(int $id, float $quantity): void
    {
        StockLot::query()->whereKey($id)->increment('qty', $quantity);
    }

    public function decreaseLot(int $id, float $quantity): int
    {
        return StockLot::query()->whereKey($id)->where('qty', '>=', $quantity)->decrement('qty', $quantity);
    }

    public function receiptOrderItem(int $lotId, string $receiptNo, int $productId): ?PurchaseOrderItem
    {
        $receiptItem = ReceiptItem::query()->with('orderItem')->where('lot_id', $lotId)->where('product_id', $productId)
            ->whereHas('receipt', fn ($query) => $query->where('receipt_no', $receiptNo))->lockForUpdate()->first();

        return $receiptItem?->orderItem;
    }

    public function reduceReceivedQuantity(PurchaseOrderItem $item, float $quantity): int
    {
        return PurchaseOrderItem::query()->whereKey($item->id)->where('received_qty', '>=', $quantity)->decrement('received_qty', $quantity);
    }

    public function createCorrection(array $data): MovementCorrection
    {
        return MovementCorrection::query()->create($data);
    }
}
