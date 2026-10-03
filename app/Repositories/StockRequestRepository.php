<?php

namespace App\Repositories;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Movement;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\TransferItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StockRequestRepository
{
    public function paginateVisible(int $location, int $perPage = 15): mixed
    {
        return StockRequest::query()->with('branch')->when($location > 0, fn (Builder $query) => $query->where('branch_id', $location))
            ->orderByDesc('id')->paginate($perPage);
    }

    public function lock(int $id): ?StockRequest
    {
        return StockRequest::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function findWithItems(int $id): ?StockRequest
    {
        $request = StockRequest::query()->with(['branch', 'requester:id,name', 'sender:id,name', 'receiver:id,name'])->find($id);
        if (! $request) {
            return null;
        }
        $request->setAttribute('branch_name', $request->branch?->name);
        $request->setRelation('items', $this->items($id));

        return $request;
    }

    public function items(int $requestId, bool $lock = false): Collection
    {
        $query = StockRequestItem::query()->with('product')->where('request_id', $requestId)->orderBy('product_id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->map(static function (StockRequestItem $item): StockRequestItem {
            $item->setAttribute('code', $item->product?->code);
            $item->setAttribute('name', $item->product?->name);
            $item->setAttribute('unit', $item->product?->unit);
            $item->setAttribute('expiry_control', $item->product?->expiry_control);
            $item->unsetRelation('product');

            return $item;
        });
    }

    public function create(array $attributes): StockRequest
    {
        return StockRequest::query()->create($attributes);
    }

    public function update(int $id, array $attributes): int
    {
        return StockRequest::query()->whereKey($id)->update($attributes);
    }

    public function replaceItems(int $id, array $items): void
    {
        StockRequestItem::query()->where('request_id', $id)->delete();
        foreach ($items as $item) {
            StockRequestItem::query()->create([
                'request_id' => $id,
                'product_id' => $item['product_id'],
                'requested_qty' => $item['qty'],
                'approved_qty' => 0,
                'note' => trim($item['note'] ?? ''),
            ]);
        }
    }

    public function updateItem(int $itemId, array $attributes): int
    {
        return StockRequestItem::query()->whereKey($itemId)->update($attributes);
    }

    public function branchIsActive(int $branchId): bool
    {
        return Branch::query()->whereKey($branchId)->where('active', true)->where('code', '<>', 'CENTRAL')->exists();
    }

    public function productIsActive(int $productId): bool
    {
        return Product::query()->whereKey($productId)->where('active', true)->exists();
    }

    /** @return array<int, array{current: float, suggested: float, average: float}> */
    public function suggestionsForBranch(int $branchId, ?array $productIds = null): array
    {
        $today = now()->toDateString();
        $since = now()->subDays(90)->startOfDay();
        $products = Product::query()->when(
            $productIds === null,
            fn (Builder $query) => $query->where('active', true),
            fn (Builder $query) => $query->whereIn('id', $productIds),
        )
            ->select(['id', 'optimal_qty'])
            ->addSelect([
                'current_qty' => StockLot::query()->selectRaw('COALESCE(SUM(qty), 0)')
                    ->whereColumn('stock_lots.product_id', 'products.id')
                    ->where('location_id', $branchId)->where('qty', '>', 0)
                    ->where(fn (Builder $query) => $query->whereNull('expires_on')->orWhere('expires_on', '>=', $today)),
                'consumed_qty' => Movement::query()->selectRaw('COALESCE(SUM(qty), 0)')
                    ->whereColumn('movements.product_id', 'products.id')
                    ->where('type', 'consumption')->whereIn('reason', ['usage', 'Ներքին օգտագործում'])
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('movement_corrections')
                        ->whereColumn('movement_corrections.movement_id', 'movements.id'))
                    ->where('from_location', $branchId)->where('happened_at', '>=', $since),
            ])->get();

        $suggestions = [];
        foreach ($products as $product) {
            $current = (float) $product->current_qty;
            $suggestions[(int) $product->id] = [
                'current' => $current,
                'suggested' => max(0, (float) $product->optimal_qty - $current),
                'average' => (float) $product->consumed_qty / 3,
            ];
        }

        return $suggestions;
    }

    public function centralFreeStock(int $productId, int $excludeRequest): float
    {
        $lots = StockLot::query()->where('product_id', $productId)->where('location_id', 0)->where('qty', '>', 0)
            ->where(fn (Builder $query) => $query->whereNull('expires_on')->orWhere('expires_on', '>=', now()->toDateString()))
            ->lockForUpdate()->get(['qty']);
        $physical = (float) $lots->sum(static fn (StockLot $lot): float => (float) $lot->qty);
        $requests = (float) StockRequestItem::query()->join('stock_requests as r', 'r.id', '=', 'request_items.request_id')
            ->where('product_id', $productId)->where('r.id', '<>', $excludeRequest)
            ->whereIn('r.status', ['approved', 'partially_approved', 'collecting', 'ready_to_ship'])->sum('approved_qty');
        $centralId = (int) Branch::query()->where('code', 'CENTRAL')->value('id');
        $transfers = (float) TransferItem::query()->join('transfers as t', 't.id', '=', 'transfer_items.transfer_id')
            ->where('transfer_items.product_id', $productId)->where('t.from_branch', $centralId)->where('t.status', 'approved')->sum('transfer_items.qty');

        return max(0, $physical - $requests - $transfers);
    }

    public function lockCentralLots(int $productId): Collection
    {
        return StockLot::query()->where('product_id', $productId)->where('location_id', 0)->where('qty', '>', 0)
            ->where(fn (Builder $query) => $query->whereNull('expires_on')->orWhere('expires_on', '>=', now()->toDateString()))
            ->orderByRaw('(expires_on IS NULL), expires_on, received_on, id')->lockForUpdate()->get();
    }

    public function matchingLot(int $product, int $location, string $lot, ?string $expiresOn, ?int $supplierId, float $unitCost, bool $lock = true): ?StockLot
    {
        $query = StockLot::query()->where('product_id', $product)->where('location_id', $location)->where('lot_no', $lot)
            ->where('supplier_id', $supplierId)->where('unit_cost', number_format($unitCost, 2, '.', ''))
            ->when(
                $expiresOn === null,
                fn ($query) => $query->whereNull('expires_on'),
                fn ($query) => $query->whereDate('expires_on', $expiresOn),
            );
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function addLot(array $attributes): StockLot
    {
        return StockLot::query()->create($attributes);
    }

    public function increaseLot(int $id, float $quantity): int
    {
        return StockLot::query()->whereKey($id)->increment('qty', $quantity);
    }

    public function decreaseLot(int $id, float $quantity): int
    {
        return StockLot::query()->whereKey($id)->where('qty', '>=', $quantity)->decrement('qty', $quantity);
    }

    public function movementsForReceipt(string $reference, int $location): Collection
    {
        return Movement::query()->where('reference', $reference)->where('type', 'branch_out')->where('to_location', $location)
            ->select('lot_id', 'product_id')->selectRaw('SUM(qty) as qty')->selectRaw('MAX(unit_cost) as unit_cost')
            ->groupBy('lot_id', 'product_id')->get();
    }

    public function lotById(int $id): ?StockLot
    {
        return StockLot::query()->find($id);
    }

    public function audit(int $actor, string $action, int $id, ?array $before, ?array $after, ?string $ip): void
    {
        AuditLog::query()->create(['actor_id' => $actor, 'action' => $action, 'entity' => 'stock_requests', 'entity_id' => $id,
            'before_data' => $before, 'after_data' => $after, 'ip_address' => $ip, 'created_at' => now()]);
    }

    public function movement(int $actor, string $type, int $product, int $lot, ?int $from, ?int $to, float $qty, float $cost, string $reference, string $reason): void
    {
        Movement::query()->create(['movement_no' => 'ՇԱՐԺ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))), 'type' => $type,
            'product_id' => $product, 'lot_id' => $lot, 'from_location' => $from, 'to_location' => $to, 'qty' => $qty, 'unit_cost' => $cost,
            'reference' => $reference, 'reason' => $reason, 'actor_id' => $actor, 'happened_at' => now(), 'created_at' => now()]);
    }
}
