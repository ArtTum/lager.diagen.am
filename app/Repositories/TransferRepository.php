<?php

namespace App\Repositories;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Movement;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockRequestItem;
use App\Models\Transfer;
use App\Models\TransferItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TransferRepository
{
    public function activeBranches(array $ids): bool
    {
        return Branch::query()->whereIn('id', $ids)->where('active', true)->count() === count(array_unique($ids));
    }

    public function activeProduct(int $id): bool
    {
        return Product::query()->whereKey($id)->where('active', true)->exists();
    }

    public function create(array $attributes): Transfer
    {
        return Transfer::query()->create($attributes);
    }

    public function createItems(int $transferId, array $items): void
    {
        foreach ($items as $item) {
            TransferItem::query()->create(['transfer_id' => $transferId, 'product_id' => $item['product_id'], 'qty' => $item['qty']]);
        }
    }

    public function lock(int $id): ?Transfer
    {
        return Transfer::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function items(int $transferId, bool $lock = false): Collection
    {
        $query = TransferItem::query()->with('product')->where('transfer_id', $transferId)->orderBy('product_id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    public function update(int $id, array $attributes): int
    {
        return Transfer::query()->whereKey($id)->update($attributes);
    }

    public function stockLocation(int $branchId): int
    {
        $branch = Branch::query()->find($branchId);
        abort_unless($branch, 422, 'Պահեստը չի գտնվել։');

        return $branch->code === 'CENTRAL' ? 0 : $branchId;
    }

    public function freeStock(int $branchId, int $productId, int $excludeTransfer): float
    {
        $location = $this->stockLocation($branchId);
        $lots = StockLot::query()->where('product_id', $productId)->where('location_id', $location)->where('qty', '>', 0)
            ->where(fn (Builder $query) => $query->whereNull('expires_on')->orWhere('expires_on', '>=', now()->toDateString()))
            ->lockForUpdate()->get(['qty']);
        $physical = (float) $lots->sum(static fn (StockLot $lot): float => (float) $lot->qty);
        $reservedTransfers = (float) TransferItem::query()->join('transfers as t', 't.id', '=', 'transfer_items.transfer_id')
            ->where('t.status', 'approved')->where('t.from_branch', $branchId)->where('transfer_items.product_id', $productId)
            ->where('t.id', '<>', $excludeTransfer)->sum('transfer_items.qty');
        $reservedRequests = 0.0;
        if ($location === 0) {
            $reservedRequests = (float) StockRequestItem::query()->join('stock_requests as r', 'r.id', '=', 'request_items.request_id')
                ->whereIn('r.status', ['approved', 'partially_approved', 'collecting', 'ready_to_ship'])
                ->where('request_items.product_id', $productId)->sum('request_items.approved_qty');
        }

        return max(0, $physical - $reservedTransfers - $reservedRequests);
    }

    public function availableLots(int $productId, int $location): Collection
    {
        return StockLot::query()->where('product_id', $productId)->where('location_id', $location)->where('qty', '>', 0)
            ->where(fn (Builder $query) => $query->whereNull('expires_on')->orWhere('expires_on', '>=', now()->toDateString()))
            ->orderByRaw('(expires_on IS NULL), expires_on, received_on, id')->lockForUpdate()->get();
    }

    public function decreaseLot(int $id, float $qty): int
    {
        return StockLot::query()->whereKey($id)->where('qty', '>=', $qty)->decrement('qty', $qty);
    }

    public function sentLines(string $reference, int $destination): Collection
    {
        return Movement::query()->where('reference', $reference)->where('type', 'transfer_sent')->where('to_location', $destination)
            ->select('lot_id', 'product_id')->selectRaw('SUM(qty) as qty')->selectRaw('MAX(unit_cost) as unit_cost')
            ->groupBy('lot_id', 'product_id')->get();
    }

    public function lot(int $id): ?StockLot
    {
        return StockLot::query()->find($id);
    }

    public function matchingLot(int $product, int $location, string $lotNo, ?string $expiresOn, ?int $supplierId, float $unitCost): ?StockLot
    {
        return StockLot::query()->where('product_id', $product)->where('location_id', $location)->where('lot_no', $lotNo)
            ->where('supplier_id', $supplierId)->where('unit_cost', number_format($unitCost, 2, '.', ''))
            ->when(
                $expiresOn === null,
                fn ($query) => $query->whereNull('expires_on'),
                fn ($query) => $query->whereDate('expires_on', $expiresOn),
            )->lockForUpdate()->first();
    }

    public function addLot(array $attributes): StockLot
    {
        return StockLot::query()->create($attributes);
    }

    public function increaseLot(int $id, float $qty): int
    {
        return StockLot::query()->whereKey($id)->increment('qty', $qty);
    }

    public function movement(int $actor, string $type, int $product, int $lot, ?int $from, ?int $to, float $qty, float $cost, string $reference, string $reason): void
    {
        Movement::query()->create(['movement_no' => 'ՇԱՐԺ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))), 'type' => $type,
            'product_id' => $product, 'lot_id' => $lot, 'from_location' => $from, 'to_location' => $to, 'qty' => $qty, 'unit_cost' => $cost,
            'reference' => $reference, 'reason' => $reason, 'actor_id' => $actor, 'happened_at' => now(), 'created_at' => now()]);
    }

    public function audit(int $actor, string $action, int $id, ?array $before, ?array $after, ?string $ip): void
    {
        AuditLog::query()->create(['actor_id' => $actor, 'action' => $action, 'entity' => 'transfers', 'entity_id' => $id,
            'before_data' => $before, 'after_data' => $after, 'ip_address' => $ip, 'created_at' => now()]);
    }
}
